<?php

namespace App;

/**
 * PreliminarFiscal — el cálculo básico del documento «FISCAL 2026», alimentado
 * con los importes REALES registrados en la base.
 *
 * SIMULADOR aparte del MotorFiscal: aquí no hay catálogos versionados, ni base
 * de flujo de efectivo, ni tarifa progresiva, ni retenciones. Sólo lo que pide
 * el documento, con las facturas que ya existen:
 *
 *   · Ventas  → ingresos vigentes marcados como facturados
 *               (estado_facturacion = 'facturada'). Su mes es el del CFDI
 *               (fecha_facturacion) o, si no la tiene, el de la operación.
 *   · Compras → gastos vigentes con comprobante fiscal
 *               (comprobante_fiscal = 1). Su mes es el del gasto.
 *
 * Cálculo del documento (con importes reales, nada de dividir entre 1.16):
 *   base ventas   = SUM(subtotal − descuento)      base gastos = SUM(subtotal)
 *   iva_facturado = SUM(iva) de ventas             iva_acreditado = SUM(iva) de gastos
 *   iva_por_pagar = iva_facturado − iva_acreditado
 *   isr           = (base ventas − base gastos) × 32 % plano del mes (sin acumular)
 *   impuestos     = iva_por_pagar + isr (puede salir negativo, como en el documento)
 *   Año           = suma de los 12 meses (NO se recalcula sobre el total anual)
 *
 * Las filas con IVA 0 (exentas: agua potable, ciertos servicios) entran con su
 * IVA real 0: suman a la base del ISR y no aportan IVA. Es un preliminar para
 * tener una idea: NO sustituye a la pestaña Impuestos (MotorFiscal) ni al contador.
 */
class PreliminarFiscal
{
    /** Tasa plana de ISR del documento (32 % sobre la utilidad del mes). */
    public const TASA_ISR = 32.0;

    /** @var \mysqli */
    protected static $db;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /**
     * Las 12 columnas del año y su total (suma de los meses, como el documento).
     *
     * Claves de cada mes: ventas, gastos (con IVA) · ventas_base, gastos_base
     * (sin IVA) · iva_facturado, iva_acreditado, iva_por_pagar · isr · impuestos
     · (todas a 2 decimales) y ventas_n, gastos_n (número de facturas).
     *
     * @return array{meses: array<int, array<string, float|int>>, total: array<string, float|int>}
     */
    public static function anual(int $contribuyente, int $anio): array
    {
        // Ventas facturadas por mes: la fecha fiscal es la del CFDI si existe.
        $consulta = self::$db->prepare(
            'SELECT MONTH(COALESCE(i.fecha_facturacion, i.fecha)) AS mes,
                    COUNT(*) AS n,
                    SUM(i.total) AS con_iva,
                    SUM(i.subtotal - i.descuento) AS sin_iva,
                    SUM(i.iva) AS iva
               FROM ingresos i
              WHERE i.estado = \'vigente\' AND i.estado_facturacion = \'facturada\'
                AND i.contribuyente_id = ? AND YEAR(COALESCE(i.fecha_facturacion, i.fecha)) = ?
              GROUP BY mes'
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        $ventas = [];
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $ventas[(int) $f['mes']] = $f;
        }
        $consulta->close();

        // Gastos con comprobante fiscal por mes (la fecha del gasto).
        $consulta = self::$db->prepare(
            'SELECT MONTH(g.fecha) AS mes,
                    COUNT(*) AS n,
                    SUM(g.total) AS con_iva,
                    SUM(g.subtotal) AS sin_iva,
                    SUM(g.iva) AS iva
               FROM gastos g
              WHERE g.estado = \'vigente\' AND g.comprobante_fiscal = 1
                AND g.contribuyente_id = ? AND YEAR(g.fecha) = ?
              GROUP BY mes'
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        $gastos = [];
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $gastos[(int) $f['mes']] = $f;
        }
        $consulta->close();

        $meses = [];
        $total = array_fill_keys(
            ['ventas', 'gastos', 'ventas_base', 'gastos_base', 'iva_facturado', 'iva_acreditado',
                'iva_por_pagar', 'isr', 'impuestos', 'ventas_n', 'gastos_n'], 0
        );

        for ($mes = 1; $mes <= 12; $mes++) {
            $v = $ventas[$mes] ?? null;
            $g = $gastos[$mes] ?? null;

            $fila = [
                'ventas'        => round((float) ($v['con_iva'] ?? 0), 2),
                'gastos'        => round((float) ($g['con_iva'] ?? 0), 2),
                'ventas_base'   => round((float) ($v['sin_iva'] ?? 0), 2),
                'gastos_base'   => round((float) ($g['sin_iva'] ?? 0), 2),
                'iva_facturado' => round((float) ($v['iva'] ?? 0), 2),
                'iva_acreditado'=> round((float) ($g['iva'] ?? 0), 2),
                'ventas_n'      => (int) ($v['n'] ?? 0),
                'gastos_n'      => (int) ($g['n'] ?? 0),
            ];
            // La cadena del documento: IVA por pagar, ISR plano e impuestos del mes.
            $fila['iva_por_pagar'] = round($fila['iva_facturado'] - $fila['iva_acreditado'], 2);
            $fila['isr']           = round(($fila['ventas_base'] - $fila['gastos_base']) * (self::TASA_ISR / 100), 2);
            $fila['impuestos']     = round($fila['iva_por_pagar'] + $fila['isr'], 2);

            $meses[$mes] = $fila;
            foreach ($fila as $clave => $valor) {
                $total[$clave] += $valor;
            }
        }
        foreach ($total as $clave => $valor) {
            $total[$clave] = round($clave === 'ventas_n' || $clave === 'gastos_n' ? (int) $valor : (float) $valor, 2);
        }

        return ['meses' => $meses, 'total' => $total];
    }

    /**
     * Clientes del mes: cada venta facturada con sus importes reales
     * (fecha fiscal = la del CFDI si existe), de la más antigua a la más reciente.
     *
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, float|int>}
     */
    public static function clientes(int $contribuyente, int $anio, int $mes): array
    {
        $consulta = self::$db->prepare(
            'SELECT COALESCE(i.fecha_facturacion, i.fecha) AS fecha, i.folio,
                    COALESCE(NULLIF(c.nombre_empresa, \'\'), c.nombre) AS cliente,
                    (i.subtotal - i.descuento) AS sin_iva, i.iva, i.total AS con_iva
               FROM ingresos i JOIN clientes c ON c.id = i.cliente_id
              WHERE i.estado = \'vigente\' AND i.estado_facturacion = \'facturada\'
                AND i.contribuyente_id = ?
                AND YEAR(COALESCE(i.fecha_facturacion, i.fecha)) = ?
                AND MONTH(COALESCE(i.fecha_facturacion, i.fecha)) = ?
              ORDER BY fecha, i.id'
        );
        $consulta->bind_param('iii', $contribuyente, $anio, $mes);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return ['filas' => $filas, 'totales' => self::sumar($filas)];
    }

    /**
     * Proveedores y compras del mes: cada gasto con comprobante fiscal
     * (beneficiario o proveedor), con sus importes reales.
     *
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, float|int>}
     */
    public static function compras(int $contribuyente, int $anio, int $mes): array
    {
        $consulta = self::$db->prepare(
            'SELECT g.fecha, g.beneficiario, g.concepto,
                    g.subtotal AS sin_iva, g.iva, g.total AS con_iva
               FROM gastos g
              WHERE g.estado = \'vigente\' AND g.comprobante_fiscal = 1
                AND g.contribuyente_id = ? AND YEAR(g.fecha) = ? AND MONTH(g.fecha) = ?
              ORDER BY g.fecha, g.id'
        );
        $consulta->bind_param('iii', $contribuyente, $anio, $mes);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return ['filas' => $filas, 'totales' => self::sumar($filas)];
    }

    /** Totales de un listado (n, sin IVA, IVA y con IVA), a 2 decimales. */
    private static function sumar(array $filas): array
    {
        $totales = ['n' => 0, 'sin_iva' => 0.0, 'iva' => 0.0, 'con_iva' => 0.0];
        foreach ($filas as $f) {
            $totales['n']++;
            $totales['sin_iva'] += (float) $f['sin_iva'];
            $totales['iva']     += (float) $f['iva'];
            $totales['con_iva'] += (float) $f['con_iva'];
        }
        $totales['sin_iva'] = round($totales['sin_iva'], 2);
        $totales['iva']     = round($totales['iva'], 2);
        $totales['con_iva'] = round($totales['con_iva'], 2);

        return $totales;
    }
}
