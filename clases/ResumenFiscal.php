<?php

namespace App;

/**
 * ResumenFiscal — estimación simple de impuestos por mes (hoja «FISCAL» del contador).
 *
 *   Facturado = ventas facturadas (con IVA)        Pagos = todos los gastos registrados (con IVA)
 *   IVA por pagar = IVA facturado − IVA de los pagos
 *   ISR por pagar = (facturado sin IVA − pagos sin IVA) × tasa (32 % por defecto, editable)
 *
 * Es una estimación para planear: NO sustituye al cálculo detallado del motor fiscal ni a la declaración.
 */
class ResumenFiscal
{
    public const IVA = 1.16;
    public const TASA_ISR_DEFECTO = 32.0;

    /** @var \mysqli */
    protected static $db;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function tasaIsr(): float
    {
        $tasa = (float) Configuracion::obtener('isr_tasa_simple', (string) self::TASA_ISR_DEFECTO);

        return ($tasa >= 0 && $tasa <= 100) ? $tasa : self::TASA_ISR_DEFECTO;
    }

    /** @return array<int, float> mes => importe real declarado */
    public static function reales(int $contribuyente, int $anio): array
    {
        $consulta = self::$db->prepare('SELECT mes, importe FROM impuesto_real_mensual WHERE contribuyente_id = ? AND anio = ?');
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        $reales = [];
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $reales[(int) $f['mes']] = (float) $f['importe'];
        }
        $consulta->close();

        return $reales;
    }

    /** Guarda (o borra, si viene vacío) el impuesto real de un mes. */
    public static function guardarReal(int $contribuyente, int $anio, int $mes, ?float $importe, int $usuarioId): void
    {
        if ($importe === null) {
            $consulta = self::$db->prepare('DELETE FROM impuesto_real_mensual WHERE contribuyente_id = ? AND anio = ? AND mes = ?');
            $consulta->bind_param('iii', $contribuyente, $anio, $mes);
        } else {
            $consulta = self::$db->prepare(
                'INSERT INTO impuesto_real_mensual (contribuyente_id, anio, mes, importe) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE importe = VALUES(importe)'
            );
            $consulta->bind_param('iiid', $contribuyente, $anio, $mes, $importe);
        }
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('impuesto_real', null, 'cambio', $usuarioId, null, ['anio' => $anio, 'mes' => $mes, 'importe' => $importe]);
    }

    /**
     * Las 12 columnas del año y su total.
     *
     * @return array{meses: array<int, array<string, float|null>>, total: array<string, float>, tasa: float}
     */
    public static function anual(int $contribuyente, int $anio): array
    {
        $tasa = self::tasaIsr();

        $facturado = array_fill(1, 12, 0.0);
        $consulta = self::$db->prepare(
            "SELECT MONTH(fecha) AS mes, SUM(total) AS total FROM ingresos
              WHERE estado = 'vigente' AND estado_facturacion = 'facturada' AND contribuyente_id = ? AND YEAR(fecha) = ?
              GROUP BY MONTH(fecha)"
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $facturado[(int) $f['mes']] = (float) $f['total'];
        }
        $consulta->close();

        $pagos = array_fill(1, 12, 0.0);
        $consulta = self::$db->prepare(
            "SELECT MONTH(fecha) AS mes, SUM(total) AS total FROM gastos
              WHERE estado = 'vigente' AND contribuyente_id = ? AND YEAR(fecha) = ?
              GROUP BY MONTH(fecha)"
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $pagos[(int) $f['mes']] = (float) $f['total'];
        }
        $consulta->close();

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) FROM ingresos WHERE estado = 'vigente' AND estado_facturacion = 'sin_clasificar' AND contribuyente_id = ? AND YEAR(fecha) = ?"
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        $sinClasificar = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        $reales = self::reales($contribuyente, $anio);
        $meses = [];
        $total = ['facturado' => 0.0, 'pagos' => 0.0, 'facturado_sin_iva' => 0.0, 'pagos_sin_iva' => 0.0, 'iva_facturado' => 0.0,
            'iva_acreditado' => 0.0, 'iva_por_pagar' => 0.0, 'isr_por_pagar' => 0.0, 'impuestos' => 0.0, 'real' => 0.0, 'a_pagar_meses' => 0.0];

        for ($m = 1; $m <= 12; $m++) {
            $fSin = $facturado[$m] / self::IVA;
            $pSin = $pagos[$m] / self::IVA;
            $ivaF = $facturado[$m] - $fSin;
            $ivaA = $pagos[$m] - $pSin;
            $iva  = $ivaF - $ivaA;
            $isr  = ($fSin - $pSin) * $tasa / 100;
            $meses[$m] = [
                'facturado' => $facturado[$m], 'pagos' => $pagos[$m], 'facturado_sin_iva' => $fSin, 'pagos_sin_iva' => $pSin,
                'iva_facturado' => $ivaF, 'iva_acreditado' => $ivaA, 'iva_por_pagar' => $iva, 'isr_por_pagar' => $isr,
                'impuestos' => $iva + $isr, 'real' => $reales[$m] ?? null,
            ];
            foreach (['facturado', 'pagos', 'facturado_sin_iva', 'pagos_sin_iva', 'iva_facturado', 'iva_acreditado', 'iva_por_pagar', 'isr_por_pagar'] as $k) {
                $total[$k] += $meses[$m][$k];
            }
            $total['impuestos'] += $iva + $isr;
            $total['real'] += $reales[$m] ?? 0.0;
            $total['a_pagar_meses'] += max(0.0, $iva + $isr);
        }

        return ['meses' => $meses, 'total' => $total, 'tasa' => $tasa, 'hay_reales' => $reales !== [], 'sin_clasificar' => $sinClasificar];
    }

    /**
     * Todas las ventas y todos los gastos del periodo (ambos negocios), de la fecha más antigua a la más reciente.
     * $mes = 0 → el año completo. Solo lectura.
     *
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, float>}
     */
    public static function movimientos(int $contribuyente, int $anio, int $mes = 0): array
    {
        $filtroMes = $mes > 0 ? ' AND MONTH(%s) = ' . (int) $mes : '';

        $sql = "SELECT i.fecha, 'Venta' AS tipo, i.folio AS referencia,
                       COALESCE(NULLIF(c.nombre_empresa, ''), c.nombre) AS contraparte, e.nombre AS negocio,
                       CASE i.estado_facturacion WHEN 'facturada' THEN 'Facturada' WHEN 'no_facturada' THEN 'No facturada' ELSE 'Pendiente de facturar' END AS detalle,
                       i.estado_facturacion AS facturacion,
                       i.subtotal - i.descuento AS subtotal, i.iva AS iva, i.total AS total
                  FROM ingresos i
                  JOIN clientes c ON c.id = i.cliente_id
                  JOIN empresas e ON e.id = i.empresa_id
                 WHERE i.estado = 'vigente' AND i.contribuyente_id = ? AND YEAR(i.fecha) = ?" . sprintf($filtroMes, 'i.fecha') . "
                UNION ALL
                SELECT g.fecha, 'Gasto', g.concepto, g.beneficiario, COALESCE(e.nombre, 'Compartido / general'),
                       COALESCE(k.nombre, 'Sin tipo'), '', g.subtotal, g.iva, g.total
                  FROM gastos g
                  LEFT JOIN empresas e ON e.id = g.empresa_id
                  LEFT JOIN categorias_gasto k ON k.id = g.categoria_id
                 WHERE g.estado = 'vigente' AND g.contribuyente_id = ? AND YEAR(g.fecha) = ?" . sprintf($filtroMes, 'g.fecha') . "
                 ORDER BY fecha, tipo, referencia";
        $consulta = self::$db->prepare($sql);
        $consulta->bind_param('iiii', $contribuyente, $anio, $contribuyente, $anio);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $totales = ['ventas' => 0.0, 'ventas_facturadas' => 0.0, 'gastos' => 0.0, 'iva_ventas' => 0.0, 'iva_gastos' => 0.0];
        foreach ($filas as $f) {
            if ($f['tipo'] === 'Venta') {
                $totales['ventas'] += (float) $f['total'];
                $totales['iva_ventas'] += (float) $f['iva'];
                if ($f['facturacion'] === 'facturada') {
                    $totales['ventas_facturadas'] += (float) $f['total'];
                }
            } else {
                $totales['gastos'] += (float) $f['total'];
                $totales['iva_gastos'] += (float) $f['iva'];
            }
        }

        return ['filas' => $filas, 'totales' => $totales];
    }

    /**
     * Impuestos de un mes (o del año si $mes = 0) en la forma que se muestra y exporta.
     *
     * @return array<string, float>
     */
    public static function impuestosDelPeriodo(int $contribuyente, int $anio, int $mes = 0): array
    {
        $r = self::anual($contribuyente, $anio);

        return $mes > 0 ? $r['meses'][$mes] : $r['total'];
    }
}
