<?php

namespace App;

/**
 * PagosGasto — abonos (parciales o totales) de un gasto SUELTO.
 *
 * Un gasto que nació de una compra se paga con PagosCompra (pagos_compra); esta
 * clase es para los gastos capturados directamente en contabilidad, que antes
 * solo podían marcarse como «pagados» de una sola vez.
 *
 * Reglas:
 *   • Un abono reduce el saldo del gasto; NUNCA crea otro gasto ni otro movimiento.
 *   • `gastos.estado_pago` sigue siendo la fuente de verdad para impuestos: solo
 *     pasa a «pagado» cuando la suma de abonos cubre el total. El estado «parcial»
 *     se calcula con la suma, sin cambiar el ENUM.
 *   • Nunca se paga más que el saldo. Reenviar el formulario (mismo token) no
 *     duplica el abono.
 *   • Un abono no se borra: se cancela con motivo y queda en la bitácora.
 *   • No se registran abonos en un periodo contable cerrado.
 * Importes en centavos enteros.
 */
class PagosGasto
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    /** Id del último abono registrado (antes de la auditoría, que también inserta). */
    public static int $ultimoId = 0;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /** Estado de pago a partir del total y lo abonado (ambos en centavos). */
    public static function estado(int $totalCentavos, int $pagadoCentavos): string
    {
        if ($pagadoCentavos <= 0) {
            return 'pendiente';
        }

        return $pagadoCentavos >= $totalCentavos ? 'pagado' : 'parcial';
    }

    /** Suma de abonos vigentes en centavos. */
    public static function pagado(int $gastoId): int
    {
        $consulta = self::$db->prepare(
            "SELECT COALESCE(SUM(ROUND(importe * 100)), 0) FROM pagos_gasto WHERE gasto_id = ? AND estado = 'vigente'"
        );
        $consulta->bind_param('i', $gastoId);
        $consulta->execute();
        $suma = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        return $suma;
    }

    /** Saldo pendiente en centavos (nunca negativo). */
    public static function saldo(int $gastoId): int
    {
        $gasto = self::gasto($gastoId);
        if (!$gasto) {
            return 0;
        }

        return max(0, Contabilidad::centavos($gasto['total']) - self::pagado($gastoId));
    }

    /** @return array<int, array<string, mixed>> */
    public static function deGasto(int $gastoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT p.*, u.nombre AS usuario_nombre FROM pagos_gasto p
               LEFT JOIN usuarios u ON u.id = p.usuario_id
              WHERE p.gasto_id = ? ORDER BY p.fecha, p.id'
        );
        $consulta->bind_param('i', $gastoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Registra un abono. $token (32 hex) hace que reenviar el formulario no lo duplique.
     *
     * @param bool $dentroDeTransaccion true cuando el llamador (p. ej. ComplementosPago)
     *        ya abrió transacción: no abre, confirma ni revierte; devuelve false con
     *        errores para que él decida.
     */
    public static function registrar(
        int $gastoId,
        string $fecha,
        $importe,
        string $metodo,
        string $referencia,
        string $token,
        int $usuarioId,
        bool $dentroDeTransaccion = false
    ): bool {
        self::$errores = [];

        if ($token !== '' && !preg_match('/^[a-f0-9]{32}$/', $token)) {
            self::$errores[] = 'El formulario de pago no es válido. Vuelve a abrirlo.';
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            self::$errores[] = 'La fecha del abono no es válida.';
            return false;
        }
        $importe = str_replace(',', '.', trim((string) $importe));
        if (!is_numeric($importe) || Contabilidad::centavos($importe) <= 0) {
            self::$errores[] = 'El importe del abono debe ser mayor a cero.';
            return false;
        }

        try {
            if (!$dentroDeTransaccion) {
                self::$db->begin_transaction();
            }
            $consulta = self::$db->prepare('SELECT * FROM gastos WHERE id = ? FOR UPDATE');
            $consulta->bind_param('i', $gastoId);
            $consulta->execute();
            $gasto = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$gasto || $gasto['estado'] !== 'vigente') {
                if (!$dentroDeTransaccion) {
                    self::$db->rollback();
                }
                self::$errores[] = 'No se registran abonos de un gasto cancelado o inexistente.';
                return false;
            }
            if (CompraContable::ordenDeGasto($gastoId)) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollback();
                }
                self::$errores[] = 'Este gasto viene de una compra: registra el pago desde la compra.';
                return false;
            }
            if (Contabilidad::periodoCerrado((int) $gasto['contribuyente_id'], $fecha)) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollback();
                }
                self::$errores[] = Contabilidad::mensajePeriodoCerrado($fecha);
                return false;
            }

            $total = Contabilidad::centavos($gasto['total']);
            $pagado = self::pagado($gastoId);
            $saldo = max(0, $total - $pagado);
            $centavos = Contabilidad::centavos($importe);
            if ($centavos > $saldo) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollback();
                }
                self::$errores[] = 'El abono excede el saldo pendiente (' . number_format($saldo / 100, 2) . ').';
                return false;
            }

            $monto = number_format($centavos / 100, 2, '.', '');
            $metodo = trim($metodo) ?: null;
            $referencia = trim($referencia) ?: null;
            $tokenBd = $token !== '' ? $token : null;
            $crear = self::$db->prepare(
                'INSERT INTO pagos_gasto (gasto_id, fecha, importe, metodo, referencia, token, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $crear->bind_param('isssssi', $gastoId, $fecha, $monto, $metodo, $referencia, $tokenBd, $usuarioId);
            try {
                $crear->execute();
            } catch (\mysqli_sql_exception $duplicado) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollback();
                }
                self::$errores[] = 'Este abono ya fue registrado; no se volvió a sumar.';
                return false;
            }
            $pagoId = (int) self::$db->insert_id;
            $crear->close();
            self::$ultimoId = $pagoId;   // antes de la auditoría: su INSERT pisa el insert_id.

            Auditoria::registrar('pago_gasto', $pagoId, 'crear', $usuarioId, null, [
                'gasto' => $gastoId, 'importe' => $monto,
            ]);
            // Si el gasto venía marcado como pagado a mano y no tenía desglose, el
            // complemento solo documenta ese pago: no se reabre.
            self::sincronizar($gastoId, $pagado === 0 && (string) $gasto['estado_pago'] === 'pagado');
            if (!$dentroDeTransaccion) {
                self::$db->commit();
            }
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            if (!$dentroDeTransaccion) {
                self::$db->rollback();
            }
            error_log('[PagosGasto::registrar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar el abono.';
            return false;
        }
    }

    /** Cancela un abono (no se borra). Exige motivo. */
    public static function cancelar(int $pagoId, string $motivo, int $usuarioId, bool $dentroDeTransaccion = false): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Escribe el motivo de la cancelación (mínimo 5 caracteres).';
            return false;
        }

        try {
            if (!$dentroDeTransaccion) {
                self::$db->begin_transaction();
            }
            $consulta = self::$db->prepare(
                'SELECT p.*, g.contribuyente_id FROM pagos_gasto p JOIN gastos g ON g.id = p.gasto_id WHERE p.id = ? FOR UPDATE'
            );
            $consulta->bind_param('i', $pagoId);
            $consulta->execute();
            $pago = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$pago || $pago['estado'] !== 'vigente') {
                if (!$dentroDeTransaccion) {
                    self::$db->rollback();
                }
                self::$errores[] = 'Solo se cancela un abono vigente.';
                return false;
            }
            if (Contabilidad::periodoCerrado((int) $pago['contribuyente_id'], $pago['fecha'])) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollback();
                }
                self::$errores[] = Contabilidad::mensajePeriodoCerrado($pago['fecha']);
                return false;
            }

            $actualizar = self::$db->prepare(
                "UPDATE pagos_gasto SET estado = 'cancelado', motivo_cancelacion = ? WHERE id = ?"
            );
            $actualizar->bind_param('si', $motivo, $pagoId);
            $actualizar->execute();
            $actualizar->close();

            Auditoria::registrar('pago_gasto', $pagoId, 'cancelar', $usuarioId, $motivo, [
                'gasto' => (int) $pago['gasto_id'], 'importe' => $pago['importe'],
            ]);
            self::sincronizar((int) $pago['gasto_id']);
            if (!$dentroDeTransaccion) {
                self::$db->commit();
            }
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            if (!$dentroDeTransaccion) {
                self::$db->rollback();
            }
            error_log('[PagosGasto::cancelar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cancelar el abono.';
            return false;
        }
    }

    /**
     * Refleja en el gasto si quedó pagado. No crea nada ni cambia importes.
     * Debe llamarse dentro de la transacción del abono.
     *
     * `$preservarPagado` se usa cuando el gasto ya estaba pagado a mano y el
     * abono solo documenta una parte (p. ej. una parcialidad de un complemento):
     * no se reabre el gasto por ello.
     */
    public static function sincronizar(int $gastoId, bool $preservarPagado = false): void
    {
        $gasto = self::gasto($gastoId);
        if (!$gasto || (string) $gasto['estado'] !== 'vigente') {
            return;
        }
        /* No se bloquea por el periodo de la FACTURA: el movimiento es el pago,
         * y su fecha ya se validó al registrar o cancelar el abono. Una factura
         * de diciembre puede pagarse en enero con el periodo de diciembre cerrado. */

        $total = Contabilidad::centavos($gasto['total']);
        $pagado = self::pagado($gastoId);
        $liquidado = $total > 0 && $pagado >= $total;

        if ($liquidado || ($preservarPagado && (string) $gasto['estado_pago'] === 'pagado')) {
            $ultimo = self::$db->prepare(
                "SELECT fecha, metodo FROM pagos_gasto WHERE gasto_id = ? AND estado = 'vigente'
                  ORDER BY fecha DESC, id DESC LIMIT 1"
            );
            $ultimo->bind_param('i', $gastoId);
            $ultimo->execute();
            $fila = $ultimo->get_result()->fetch_assoc();
            $ultimo->close();

            $fecha = (string) ($fila['fecha'] ?? ($gasto['fecha_pago'] ?? date('Y-m-d')));
            $metodo = in_array($fila['metodo'] ?? '', Contabilidad::METODOS_PAGO, true) ? $fila['metodo'] : null;
            $actualizar = self::$db->prepare(
                "UPDATE gastos SET estado_pago = 'pagado', fecha_pago = ?, metodo_pago = COALESCE(?, metodo_pago) WHERE id = ?"
            );
            $actualizar->bind_param('ssi', $fecha, $metodo, $gastoId);
        } else {
            $actualizar = self::$db->prepare("UPDATE gastos SET estado_pago = 'pendiente', fecha_pago = NULL WHERE id = ?");
            $actualizar->bind_param('i', $gastoId);
        }
        $actualizar->execute();
        $actualizar->close();
    }

    /** @return array<string, mixed>|null */
    private static function gasto(int $gastoId): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT id, total, estado, estado_pago, fecha, fecha_pago, contribuyente_id FROM gastos WHERE id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $gastoId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }
}
