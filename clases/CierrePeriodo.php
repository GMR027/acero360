<?php

namespace App;

/**
 * CierrePeriodo — revisión, cierre y reapertura controlada de un mes contable.
 *
 * Antes de cerrar se ejecutan verificaciones con tres niveles:
 *   • error → bloquea el cierre (datos incompletos o descuadrados que habría que corregir)
 *   • aviso → se puede cerrar solo reconociéndolo expresamente (queda en el resumen)
 *   • info  → solo informativo
 *
 * Al cerrar se guarda una fotografía (resumen contable + estimación fiscal + verificaciones +
 * versiones de reglas) y el mes queda protegido: ningún módulo lo modifica sin pasar por la
 * reapertura, que exige contraseña, motivo y deja bitácora. No se recalcula nada en silencio.
 */
class CierrePeriodo
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

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

    /* --------------------------------------------------------------------
     * VERIFICACIONES
     * ------------------------------------------------------------------ */

    private static function hallazgo(string $codigo, string $nivel, string $titulo, int $cantidad, string $detalle, ?string $enlace = null): array
    {
        return ['codigo' => $codigo, 'nivel' => $nivel, 'titulo' => $titulo, 'cantidad' => $cantidad, 'detalle' => $detalle, 'enlace' => $enlace];
    }

    private static function cuenta(string $sql, string $tipos, array $valores): int
    {
        $consulta = self::$db->prepare($sql);
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        return $n;
    }

    /**
     * Resultado de todas las verificaciones del mes (solo los hallazgos con cantidad > 0).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function verificaciones(int $contribuyente, int $anio, int $mes): array
    {
        $h = [];
        $periodo = sprintf('anio=%d&mes=%d', $anio, $mes);
        $p = [$contribuyente, $anio, $mes];

        $clave = sprintf('%04d-%02d', $anio, $mes);
        if ($clave > date('Y-m')) {
            $h[] = self::hallazgo('periodo_futuro', 'error', 'Periodo futuro', 1, 'No se puede cerrar un mes que todavía no empieza.');
        } elseif ($clave === date('Y-m')) {
            $h[] = self::hallazgo('mes_en_curso', 'aviso', 'El mes aún no termina', 1,
                'Al cerrarlo se bloquean las ventas, cobros y gastos nuevos de este mes hasta reabrirlo.');
        }

        $sinClasificar = self::cuenta(
            "SELECT COUNT(*) FROM ingresos WHERE contribuyente_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ? AND estado = 'vigente' AND estado_facturacion = 'sin_clasificar'",
            'iii', $p
        );
        if ($sinClasificar > 0) {
            $h[] = self::hallazgo('sin_clasificar', 'error', 'Ventas sin clasificar', $sinClasificar,
                'Indica si cada venta está facturada o no facturada. Una venta sin factura adjunta no se clasifica sola.', "/admin/contabilidad/ingresos?$periodo&facturacion=sin_clasificar");
        }

        $duplicadas = self::cuenta(
            "SELECT COUNT(*) FROM (SELECT 1 FROM ingresos WHERE contribuyente_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ? AND estado = 'vigente'
                GROUP BY cliente_id, empresa_id, fecha, total HAVING COUNT(*) > 1) t",
            'iii', $p
        );
        if ($duplicadas > 0) {
            $h[] = self::hallazgo('ventas_duplicadas', 'aviso', 'Posibles ventas duplicadas', $duplicadas,
                'Hay ventas del mismo cliente, negocio, fecha e importe. Confirma que no se capturaron dos veces.', "/admin/contabilidad/ingresos?$periodo");
        }

        $facturasSinAdjuntar = self::cuenta(
            "SELECT COUNT(*) FROM ingresos i WHERE i.contribuyente_id = ? AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? AND i.estado = 'vigente'
                AND i.estado_facturacion = 'facturada'
                AND NOT EXISTS (SELECT 1 FROM documentos d WHERE d.entidad = 'ingreso' AND d.entidad_id = i.id AND d.activo = 1 AND d.tipo IN ('factura_pdf', 'xml'))",
            'iii', $p
        );
        if ($facturasSinAdjuntar > 0) {
            $h[] = self::hallazgo('facturas_sin_adjuntar', 'aviso', 'Facturas pendientes de adjuntar', $facturasSinAdjuntar,
                'Ventas marcadas como facturadas sin PDF ni XML de la factura.', "/admin/contabilidad/ingresos?$periodo&facturacion=facturada");
        }

        $cfdiIncompleto = self::cuenta(
            "SELECT COUNT(*) FROM documentos d
               LEFT JOIN ingresos i ON d.entidad = 'ingreso' AND i.id = d.entidad_id
               LEFT JOIN gastos g ON d.entidad = 'gasto' AND g.id = d.entidad_id
              WHERE d.activo = 1 AND d.tipo = 'xml' AND d.contribuyente_id = ?
                AND ((i.id IS NOT NULL AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ?) OR (g.id IS NOT NULL AND YEAR(g.fecha) = ? AND MONTH(g.fecha) = ?))
                AND (d.estado_validacion <> 'cfdi_con_timbre' OR JSON_LENGTH(COALESCE(JSON_EXTRACT(d.datos_cfdi, '$.alertas'), JSON_ARRAY())) > 0)",
            'iiiii', [$contribuyente, $anio, $mes, $anio, $mes]
        );
        if ($cfdiIncompleto > 0) {
            $h[] = self::hallazgo('cfdi_incompleto', 'aviso', 'CFDI con datos incompletos o inconsistentes', $cfdiIncompleto,
                'XML sin UUID o con RFC/total que no coinciden con la operación.', "/admin/contabilidad/documentos?$periodo");
        }

        $sinComprobante = self::cuenta(
            "SELECT COUNT(*) FROM gastos WHERE contribuyente_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ? AND estado = 'vigente' AND comprobante_fiscal = 0",
            'iii', $p
        );
        if ($sinComprobante > 0) {
            $h[] = self::hallazgo('gastos_sin_comprobante', 'aviso', 'Gastos sin comprobante fiscal', $sinComprobante,
                'La estimación resta todo gasto pagado; el comprobante es respaldo para tu contador (no cambia el cálculo).', "/admin/contabilidad/gastos?$periodo&sin_comprobante=1");
        }

        $porValidar = self::cuenta(
            "SELECT COUNT(*) FROM gastos WHERE contribuyente_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ? AND estado = 'vigente' AND revision_fiscal = 'por_validar'",
            'iii', $p
        );
        if ($porValidar > 0) {
            $h[] = self::hallazgo('gastos_por_validar', 'aviso', 'Gastos con deducibilidad por validar', $porValidar,
                '«Por validar» es informativo: la estimación resta el gasto en cuanto está pagado; la deducción la decide tu contador.', "/admin/contabilidad/gastos?$periodo");
        }

        $porCobrar = self::cuenta(
            "SELECT COUNT(*) FROM ingresos i WHERE i.contribuyente_id = ? AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? AND i.estado = 'vigente'
                AND ROUND(i.total * 100) > COALESCE((SELECT SUM(ROUND(c.importe * 100)) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente'), 0)",
            'iii', $p
        );
        if ($porCobrar > 0) {
            $h[] = self::hallazgo('cobros_pendientes', 'info', 'Ventas con cobro pendiente', $porCobrar,
                'No entran en la estimación de impuestos hasta pagarse al 100 % (parcialidades o sin cobro = sin pagar). Al confirmarse el pago completo, la factura entra en el mes de su fecha.', "/admin/contabilidad/ingresos?$periodo");
        }

        $porPagar = self::cuenta(
            "SELECT COUNT(*) FROM gastos WHERE contribuyente_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ? AND estado = 'vigente' AND estado_pago = 'pendiente'",
            'iii', $p
        );
        if ($porPagar > 0) {
            $h[] = self::hallazgo('pagos_pendientes', 'info', 'Gastos con pago pendiente', $porPagar,
                'Mientras no se paguen no descuentan de impuestos: la deducción y el IVA acreditable se toman cuando el gasto se paga.', "/admin/contabilidad/gastos?$periodo&pago=pendiente");
        }

        // Conciliación.
        $sinIngreso = self::cuenta(
            "SELECT COUNT(*) FROM ventas v JOIN empresas e ON e.id = v.empresa_id
              WHERE e.contribuyente_id = ? AND YEAR(v.fecha) = ? AND MONTH(v.fecha) = ? AND v.estado = 'registrada'
                AND NOT EXISTS (SELECT 1 FROM ingresos i WHERE i.venta_id = v.id)",
            'iii', $p
        );
        $descuadrados = self::cuenta(
            "SELECT COUNT(*) FROM ingresos i JOIN ventas v ON v.id = i.venta_id
              WHERE i.contribuyente_id = ? AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ?
                AND ((i.estado = 'vigente' AND (v.estado <> 'registrada' OR ROUND(i.total * 100) <> ROUND(v.total * 100)))
                  OR (i.estado = 'cancelado' AND v.estado = 'registrada'))",
            'iii', $p
        );
        $sobrecobrados = self::cuenta(
            "SELECT COUNT(*) FROM ingresos i WHERE i.contribuyente_id = ? AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? AND i.estado = 'vigente'
                AND COALESCE((SELECT SUM(ROUND(c.importe * 100)) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente'), 0) > ROUND(i.total * 100)",
            'iii', $p
        );
        $archivosFaltantes = 0;
        $consulta = self::$db->prepare(
            "SELECT d.* FROM documentos d
               LEFT JOIN ingresos i ON d.entidad = 'ingreso' AND i.id = d.entidad_id
               LEFT JOIN gastos g ON d.entidad = 'gasto' AND g.id = d.entidad_id
              WHERE d.activo = 1 AND d.contribuyente_id = ?
                AND ((i.id IS NOT NULL AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ?) OR (g.id IS NOT NULL AND YEAR(g.fecha) = ? AND MONTH(g.fecha) = ?))"
        );
        $consulta->bind_param('iiiii', $contribuyente, $anio, $mes, $anio, $mes);
        $consulta->execute();
        foreach ($consulta->get_result() as $doc) {
            $archivosFaltantes += Archivos::rutaAbsoluta($doc) === null ? 1 : 0;
        }
        $consulta->close();

        $conciliacion = $sinIngreso + $descuadrados + $sobrecobrados + $archivosFaltantes;
        if ($conciliacion > 0) {
            $partes = array_filter([
                $sinIngreso ? "$sinIngreso venta(s) registrada(s) sin ingreso contable" : '',
                $descuadrados ? "$descuadrados ingreso(s) que no cuadran con su venta" : '',
                $sobrecobrados ? "$sobrecobrados ingreso(s) con más cobros que su total" : '',
                $archivosFaltantes ? "$archivosFaltantes documento(s) cuyo archivo no está en el almacén" : '',
            ]);
            $h[] = self::hallazgo('errores_conciliacion', 'error', 'Errores de conciliación', $conciliacion, implode('; ', $partes) . '.', "/admin/contabilidad/ingresos?$periodo");
        }

        // Mes anterior abierto (solo avisa si tuvo movimientos).
        if ($mes > 1) {
            $anteriorAbierto = !Contabilidad::periodoCerrado($contribuyente, sprintf('%04d-%02d-01', $anio, $mes - 1))
                && self::cuenta(
                    "SELECT (SELECT COUNT(*) FROM ingresos WHERE contribuyente_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ?)
                          + (SELECT COUNT(*) FROM gastos WHERE contribuyente_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ?)",
                    'iiiiii', [$contribuyente, $anio, $mes - 1, $contribuyente, $anio, $mes - 1]
                ) > 0;
            if ($anteriorAbierto) {
                $h[] = self::hallazgo('mes_anterior_abierto', 'aviso', 'El mes anterior sigue abierto', 1,
                    'El ISR es acumulado: si el mes anterior cambia después, esta estimación quedaría desactualizada.', "/admin/contabilidad/cierre?anio=$anio&mes=" . ($mes - 1));
            }
        }

        return $h;
    }

    /* --------------------------------------------------------------------
     * ESTADO E HISTORIAL
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null */
    public static function periodo(int $contribuyente, int $anio, int $mes): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT p.*, u.nombre AS cerrado_por_nombre FROM periodos_contables p LEFT JOIN usuarios u ON u.id = p.cerrado_por
              WHERE p.contribuyente_id = ? AND p.anio = ? AND p.mes = ?'
        );
        $consulta->bind_param('iii', $contribuyente, $anio, $mes);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if ($fila && $fila['resumen']) {
            $fila['snapshot'] = json_decode((string) $fila['resumen'], true) ?: [];
        }

        return $fila ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function historial(int $contribuyente, int $anio, int $mes): array
    {
        $consulta = self::$db->prepare(
            'SELECT h.*, u.nombre AS usuario_nombre FROM periodos_historial h
               JOIN periodos_contables p ON p.id = h.periodo_id LEFT JOIN usuarios u ON u.id = h.usuario_id
              WHERE p.contribuyente_id = ? AND p.anio = ? AND p.mes = ? ORDER BY h.id DESC'
        );
        $consulta->bind_param('iii', $contribuyente, $anio, $mes);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /* --------------------------------------------------------------------
     * CERRAR
     * ------------------------------------------------------------------ */

    /**
     * Cierra el mes.
     *
     * @param string[] $reconocidos códigos de los avisos que el usuario acepta expresamente
     */
    public static function cerrar(int $contribuyente, int $anio, int $mes, int $usuarioId, array $reconocidos, string $nota): bool
    {
        self::$errores = [];

        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            self::$errores[] = 'El periodo no es válido.';
            return false;
        }
        try {
            self::$db->begin_transaction();

            $crear = self::$db->prepare('INSERT IGNORE INTO periodos_contables (contribuyente_id, anio, mes) VALUES (?, ?, ?)');
            $crear->bind_param('iii', $contribuyente, $anio, $mes);
            $crear->execute();
            $crear->close();

            $bloqueo = self::$db->prepare('SELECT * FROM periodos_contables WHERE contribuyente_id = ? AND anio = ? AND mes = ? FOR UPDATE');
            $bloqueo->bind_param('iii', $contribuyente, $anio, $mes);
            $bloqueo->execute();
            $periodo = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if ($periodo['estado'] === 'cerrado') {
                self::$db->rollBack();
                self::$errores[] = 'El periodo ya está cerrado.';
                return false;
            }

            $verificaciones = self::verificaciones($contribuyente, $anio, $mes);
            $errores = array_filter($verificaciones, static fn ($v) => $v['nivel'] === 'error');
            if ($errores) {
                self::$db->rollBack();
                foreach ($errores as $e) {
                    self::$errores[] = $e['titulo'] . ' (' . $e['cantidad'] . '): ' . $e['detalle'];
                }
                return false;
            }

            $avisos = array_filter($verificaciones, static fn ($v) => $v['nivel'] === 'aviso');
            $sinReconocer = array_filter($avisos, static fn ($v) => !in_array($v['codigo'], $reconocidos, true));
            if ($sinReconocer) {
                self::$db->rollBack();
                self::$errores[] = 'Para cerrar con avisos debes reconocerlos expresamente: ' . implode('; ', array_column($sinReconocer, 'titulo')) . '.';
                return false;
            }

            $estimacion = ResumenFiscal::anual($contribuyente, $anio)['meses'][$mes];
            $snapshot = [
                'periodo' => sprintf('%04d-%02d', $anio, $mes),
                'cerrado_por' => $usuarioId,
                'cerrado_en' => date('Y-m-d H:i:s'),
                'nota' => trim($nota) ?: null,
                'contabilidad' => Contabilidad::resumenPeriodo($anio, $mes),
                'fiscal' => array_intersect_key($estimacion, array_flip(['facturado', 'pagos', 'iva_por_pagar', 'isr_por_pagar', 'impuestos'])),
                'verificaciones' => $verificaciones,
                'avisos_reconocidos' => array_values(array_intersect($reconocidos, array_column($avisos, 'codigo'))),
            ];
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
            $huella = hash('sha256', $json);

            $periodoId = (int) $periodo['id'];
            $cerrar = self::$db->prepare("UPDATE periodos_contables SET estado = 'cerrado', cerrado_en = NOW(), cerrado_por = ?, resumen = ? WHERE id = ?");
            $cerrar->bind_param('isi', $usuarioId, $json, $periodoId);
            $cerrar->execute();
            $cerrar->close();

            $historial = self::$db->prepare("INSERT INTO periodos_historial (periodo_id, accion, motivo, usuario_id, resumen_sha256) VALUES (?, 'cierre', ?, ?, ?)");
            $motivo = trim($nota) ?: null;
            $historial->bind_param('isis', $periodoId, $motivo, $usuarioId, $huella);
            $historial->execute();
            $historial->close();

            Auditoria::registrar('periodo', $periodoId, 'cierre', $usuarioId, $motivo, [
                'periodo' => $snapshot['periodo'], 'sha256' => $huella, 'avisos_reconocidos' => $snapshot['avisos_reconocidos'],
            ]);

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[CierrePeriodo::cerrar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cerrar el periodo.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * REABRIR (procedimiento controlado)
     * ------------------------------------------------------------------ */

    /** Reabre un mes cerrado: contraseña, motivo obligatorio y bitácora. Los meses posteriores se reabren primero. */
    public static function reabrir(int $contribuyente, int $anio, int $mes, int $usuarioId, string $contrasena, string $motivo): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 10) {
            self::$errores[] = 'Escribe el motivo de la reapertura (mínimo 10 caracteres).';
            return false;
        }
        if (!CatalogoFiscal::autorizar($usuarioId, $contrasena)) {
            Auditoria::registrar('periodo', null, 'reapertura_rechazada', $usuarioId, 'Contraseña de autorización incorrecta', ['periodo' => sprintf('%04d-%02d', $anio, $mes)]);
            self::$errores[] = 'Autorización rechazada: la contraseña no es correcta.';
            return false;
        }

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare("SELECT * FROM periodos_contables WHERE contribuyente_id = ? AND anio = ? AND mes = ? AND estado = 'cerrado' FOR UPDATE");
            $bloqueo->bind_param('iii', $contribuyente, $anio, $mes);
            $bloqueo->execute();
            $periodo = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if (!$periodo) {
                self::$db->rollBack();
                self::$errores[] = 'El periodo no está cerrado.';
                return false;
            }

            $posterior = self::$db->prepare(
                "SELECT anio, mes FROM periodos_contables WHERE contribuyente_id = ? AND estado = 'cerrado'
                    AND (anio > ? OR (anio = ? AND mes > ?)) ORDER BY anio, mes LIMIT 1"
            );
            $posterior->bind_param('iiii', $contribuyente, $anio, $anio, $mes);
            $posterior->execute();
            $despues = $posterior->get_result()->fetch_assoc();
            $posterior->close();
            if ($despues) {
                self::$db->rollBack();
                self::$errores[] = sprintf('Primero reabre el periodo posterior %04d-%02d: el ISR es acumulado y no puede quedar un mes cerrado sobre otro modificado.', $despues['anio'], $despues['mes']);
                return false;
            }

            $periodoId = (int) $periodo['id'];
            $huella = $periodo['resumen'] ? hash('sha256', (string) $periodo['resumen']) : null;

            $abrir = self::$db->prepare("UPDATE periodos_contables SET estado = 'abierto' WHERE id = ?");
            $abrir->bind_param('i', $periodoId);
            $abrir->execute();
            $abrir->close();

            $historial = self::$db->prepare("INSERT INTO periodos_historial (periodo_id, accion, motivo, usuario_id, resumen_sha256) VALUES (?, 'reapertura', ?, ?, ?)");
            $historial->bind_param('isis', $periodoId, $motivo, $usuarioId, $huella);
            $historial->execute();
            $historial->close();

            Auditoria::registrar('periodo', $periodoId, 'reapertura', $usuarioId, $motivo, ['periodo' => sprintf('%04d-%02d', $anio, $mes), 'resumen_previo_sha256' => $huella]);

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[CierrePeriodo::reabrir] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible reabrir el periodo.';
            return false;
        }
    }
}
