<?php

namespace App;

/**
 * PagosCompra — pagos (parciales o totales) a proveedores, ligados a una compra.
 *
 * Reglas:
 *   • Un pago reduce el saldo de la compra; NUNCA crea un gasto. El gasto de la compra es uno solo
 *     (ordenes_compra.gasto_id) y solo se le sincroniza el estado de pago.
 *   • Nunca se paga más que el saldo. Reenviar el formulario (mismo token) no duplica el pago.
 *   • Un pago no se borra: se cancela con motivo y queda en la bitácora.
 *   • No se registran pagos en un periodo contable cerrado.
 * Importes en centavos enteros.
 */
class PagosCompra
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    public const ESTADOS = [
        'pendiente' => ['etiqueta' => 'Pendiente de pago', 'clase' => 'badge--pendiente'],
        'parcial'   => ['etiqueta' => 'Pago parcial',      'clase' => 'badge--pendiente'],
        'pagada'    => ['etiqueta' => 'Pagada',            'clase' => 'badge--aprobado'],
    ];

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

    /** Estado de pago a partir del total de la compra y lo pagado (ambos en centavos). */
    public static function estado(int $totalCentavos, int $pagadoCentavos): string
    {
        if ($pagadoCentavos <= 0) {
            return 'pendiente';
        }
        return $pagadoCentavos >= $totalCentavos ? 'pagada' : 'parcial';
    }

    /** Total de la compra (subtotal + IVA capturado) en centavos. */
    public static function totalCompra(array $orden): int
    {
        return Contabilidad::centavos($orden['total_esperado']) + ($orden['iva'] !== null ? Contabilidad::centavos($orden['iva']) : 0);
    }

    /** Suma de pagos vigentes en centavos. */
    public static function pagado(int $ordenId): int
    {
        $consulta = self::$db->prepare("SELECT COALESCE(SUM(ROUND(importe * 100)), 0) FROM pagos_compra WHERE orden_id = ? AND estado = 'vigente'");
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $suma = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        return $suma;
    }

    /** @return array<int, array<string, mixed>> */
    public static function deOrden(int $ordenId): array
    {
        $consulta = self::$db->prepare(
            'SELECT p.*, u.nombre AS usuario_nombre FROM pagos_compra p LEFT JOIN usuarios u ON u.id = p.usuario_id
              WHERE p.orden_id = ? ORDER BY p.fecha, p.id'
        );
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Listado general de pagos con filtros: proveedor, desde, hasta, estado (vigente|cancelado).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listar(array $filtros, int $limite = 100, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare(
            "SELECT p.*, o.folio, o.tipo AS orden_tipo, o.estado_cfdi, pr.nombre AS proveedor_nombre
               FROM pagos_compra p JOIN ordenes_compra o ON o.id = p.orden_id JOIN proveedores pr ON pr.id = o.proveedor_id
              WHERE $donde ORDER BY p.fecha DESC, p.id DESC LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
    private static function condiciones(array $f): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];
        if ((int) ($f['proveedor'] ?? 0) > 0) {
            $donde[] = 'o.proveedor_id = ?';
            $tipos .= 'i';
            $valores[] = (int) $f['proveedor'];
        }
        foreach (['desde' => 'p.fecha >= ?', 'hasta' => 'p.fecha <= ?'] as $clave => $sql) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$clave] ?? ''))) {
                $donde[] = $sql;
                $tipos .= 's';
                $valores[] = $f[$clave];
            }
        }
        if (in_array($f['estado'] ?? '', ['vigente', 'cancelado'], true)) {
            $donde[] = 'p.estado = ?';
            $tipos .= 's';
            $valores[] = $f['estado'];
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Registra un pago. $token (32 hex) hace que reenviar el formulario no duplique el pago.
     */
    public static function registrar(
        int $ordenId,
        string $fecha,
        $importe,
        string $metodo,
        string $referencia,
        string $token,
        int $usuarioId
    ): bool {
        self::$errores = [];

        if ($token !== '' && !preg_match('/^[a-f0-9]{32}$/', $token)) {
            self::$errores[] = 'El formulario de pago no es válido. Vuelve a abrirlo.';
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            self::$errores[] = 'La fecha del pago no es válida.';
            return false;
        }
        $importe = str_replace(',', '.', trim((string) $importe));
        if (!is_numeric($importe) || Contabilidad::centavos($importe) <= 0) {
            self::$errores[] = 'El importe del pago debe ser mayor a cero.';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $consulta = self::$db->prepare('SELECT * FROM ordenes_compra WHERE id = ? FOR UPDATE');
            $consulta->bind_param('i', $ordenId);
            $consulta->execute();
            $orden = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$orden || $orden['estado'] === 'cancelada') {
                self::$db->rollback();
                self::$errores[] = 'No se registran pagos de una compra cancelada o inexistente.';
                return false;
            }
            if (Contabilidad::periodoCerrado((int) $orden['contribuyente_id'], $fecha)) {
                self::$db->rollback();
                self::$errores[] = Contabilidad::mensajePeriodoCerrado($fecha);
                return false;
            }

            $saldo = self::totalCompra($orden) - self::pagado($ordenId);
            $centavos = Contabilidad::centavos($importe);
            if ($centavos > $saldo) {
                self::$db->rollback();
                self::$errores[] = 'El pago excede el saldo pendiente (' . number_format($saldo / 100, 2) . ').';
                return false;
            }

            $monto = number_format($centavos / 100, 2, '.', '');
            $metodo = trim($metodo) ?: null;
            $referencia = trim($referencia) ?: null;
            $tokenBd = $token !== '' ? $token : null;
            $crear = self::$db->prepare(
                'INSERT INTO pagos_compra (orden_id, fecha, importe, metodo, referencia, token, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $crear->bind_param('isssssi', $ordenId, $fecha, $monto, $metodo, $referencia, $tokenBd, $usuarioId);
            try {
                $crear->execute();
            } catch (\mysqli_sql_exception $duplicado) {
                self::$db->rollback();
                self::$errores[] = 'Este pago ya fue registrado; no se volvió a sumar.';
                return false;
            }
            $pagoId = (int) self::$db->insert_id;
            $crear->close();

            Auditoria::registrar('pago_compra', $pagoId, 'crear', $usuarioId, null, ['orden' => $ordenId, 'importe' => $monto]);
            self::sincronizarGasto($ordenId);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[PagosCompra::registrar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar el pago.';
            return false;
        }
    }

    /** Cancela un pago (no se borra). Exige motivo. */
    public static function cancelar(int $pagoId, string $motivo, int $usuarioId): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Escribe el motivo de la cancelación (mínimo 5 caracteres).';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $consulta = self::$db->prepare(
                'SELECT p.*, o.contribuyente_id FROM pagos_compra p JOIN ordenes_compra o ON o.id = p.orden_id WHERE p.id = ? FOR UPDATE'
            );
            $consulta->bind_param('i', $pagoId);
            $consulta->execute();
            $pago = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$pago || $pago['estado'] !== 'vigente') {
                self::$db->rollback();
                self::$errores[] = 'Solo se cancela un pago vigente.';
                return false;
            }
            if (Contabilidad::periodoCerrado((int) $pago['contribuyente_id'], $pago['fecha'])) {
                self::$db->rollback();
                self::$errores[] = Contabilidad::mensajePeriodoCerrado($pago['fecha']);
                return false;
            }

            $actualizar = self::$db->prepare("UPDATE pagos_compra SET estado = 'cancelado', motivo_cancelacion = ? WHERE id = ?");
            $actualizar->bind_param('si', $motivo, $pagoId);
            $actualizar->execute();
            $actualizar->close();

            Auditoria::registrar('pago_compra', $pagoId, 'cancelar', $usuarioId, $motivo, ['orden' => (int) $pago['orden_id'], 'importe' => $pago['importe']]);
            self::sincronizarGasto((int) $pago['orden_id']);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[PagosCompra::cancelar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cancelar el pago.';
            return false;
        }
    }

    /**
     * Refleja en el gasto de la compra (si ya existe) si quedó liquidada. No crea nada ni cambia importes.
     * Debe llamarse dentro de la transacción del pago.
     */
    public static function sincronizarGasto(int $ordenId): void
    {
        $consulta = self::$db->prepare(
            'SELECT o.*, g.estado AS gasto_estado, g.fecha AS gasto_fecha, g.contribuyente_id AS gasto_contribuyente
               FROM ordenes_compra o JOIN gastos g ON g.id = o.gasto_id WHERE o.id = ?'
        );
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $orden = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$orden || $orden['gasto_estado'] !== 'vigente' || Contabilidad::periodoCerrado((int) $orden['gasto_contribuyente'], $orden['gasto_fecha'])) {
            return;
        }

        $total = self::totalCompra($orden);
        $liquidada = $total > 0 && self::pagado($ordenId) >= $total;
        $gastoId = (int) $orden['gasto_id'];

        if ($liquidada) {
            $ultimo = self::$db->query("SELECT fecha, metodo FROM pagos_compra WHERE orden_id = $ordenId AND estado = 'vigente' ORDER BY fecha DESC, id DESC LIMIT 1")->fetch_assoc();
            $metodo = in_array($ultimo['metodo'] ?? '', Contabilidad::METODOS_PAGO, true) ? $ultimo['metodo'] : null;
            $actualizar = self::$db->prepare("UPDATE gastos SET estado_pago = 'pagado', fecha_pago = ?, metodo_pago = COALESCE(?, metodo_pago) WHERE id = ?");
            $actualizar->bind_param('ssi', $ultimo['fecha'], $metodo, $gastoId);
        } else {
            $actualizar = self::$db->prepare("UPDATE gastos SET estado_pago = 'pendiente', fecha_pago = NULL WHERE id = ?");
            $actualizar->bind_param('i', $gastoId);
        }
        $actualizar->execute();
        $actualizar->close();
    }
}
