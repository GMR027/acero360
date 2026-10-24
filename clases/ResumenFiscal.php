<?php

namespace App;

/**
 * ResumenFiscal — estimación de impuestos por mes (misma cuenta que la hoja «FISCAL» del contador).
 *
 * COMPORTAMIENTO DEFINITIVO (una sola regla para pantallas, cierre, PDF, Excel y CSV):
 *
 *   Entra al cálculo (por el mes de la FECHA de la operación):
 *     · Ingresos: estado «vigente», facturados y COBRADOS AL 100 % (cobros vigentes ≥ total).
 *     · Gastos:   estado «vigente» y estado_pago «pagado».
 *   No entra: factura sin cobro o con pago parcial (PPD/parcialidades) y gasto «por pagar».
 *   Al confirmarse el pago entra completo, en el mes de la fecha de la operación; lo excluido
 *   se declara en 'facturas_sin_pagar[_n]' y 'gastos_no_pagados[_n]'.
 *
 *   El método de pago del CFDI (PUE una sola exhibición, PPD parcialidades, POR por definir) y
 *   el estado de cobro son INFORMATIVOS: no cambian la cuenta, sólo explican por qué una
 *   operación está dentro o fuera. Los archivos adjuntos (XML/PDF/fotos) son respaldo
 *   documental: no modifican importes.
 *
 *   Fórmulas (idénticas a la hoja FISCAL):
 *     IVA facturado   = Σ IVA de las facturas que entran
 *     IVA acreditable = Σ IVA de los gastos que entran
 *     IVA por pagar   = IVA facturado − IVA acreditable
 *     base            = total con IVA − su IVA (el IVA REAL del CFDI; sin inventar el 16 %)
 *     ISR por pagar   = (base facturada − base de gastos) × tasa (32 % por defecto, editable)
 *     Impuestos       = IVA por pagar + ISR por pagar (un negativo es saldo a favor)
 *
 * Equivale a «total / 1.16» cuando la operación es al 16 %, y es exacto con 0 %, 8 % u otra tasa.
 * Es una estimación para planear: NO sustituye a la declaración.
 */
class ResumenFiscal
{
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

        // Cobro total de una factura: sólo si sus cobros vigentes cubren su total.
        $pagada = 'ROUND(i.total * 100) <= COALESCE((SELECT SUM(ROUND(c.importe * 100)) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = \'vigente\'), 0)';

        $facturado = array_fill(1, 12, 0.0);
        $ivaFacturado = array_fill(1, 12, 0.0);
        $consulta = self::$db->prepare(
            "SELECT MONTH(i.fecha) AS mes, SUM(i.total) AS total, SUM(i.iva) AS iva FROM ingresos i
              WHERE i.estado = 'vigente' AND i.estado_facturacion = 'facturada' AND i.contribuyente_id = ? AND YEAR(i.fecha) = ?
                AND $pagada
              GROUP BY MONTH(i.fecha)"
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $facturado[(int) $f['mes']] = (float) $f['total'];
            $ivaFacturado[(int) $f['mes']] = (float) $f['iva'];
        }
        $consulta->close();

        // Fuera del cálculo: facturas ya emitidas pero sin pagar por completo (parcialidades o nada).
        $sinPagarN = array_fill(1, 12, 0);
        $sinPagar  = array_fill(1, 12, 0.0);
        $consulta = self::$db->prepare(
            "SELECT MONTH(i.fecha) AS mes, COUNT(*) AS n, SUM(i.total) AS total FROM ingresos i
              WHERE i.estado = 'vigente' AND i.estado_facturacion = 'facturada' AND i.contribuyente_id = ? AND YEAR(i.fecha) = ?
                AND NOT ($pagada)
              GROUP BY MONTH(i.fecha)"
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $sinPagarN[(int) $f['mes']] = (int) $f['n'];
            $sinPagar[(int) $f['mes']]  = (float) $f['total'];
        }
        $consulta->close();

        $pagos = array_fill(1, 12, 0.0);
        $ivaPagos = array_fill(1, 12, 0.0);
        $consulta = self::$db->prepare(
            "SELECT MONTH(fecha) AS mes, SUM(total) AS total, SUM(iva) AS iva FROM gastos
              WHERE estado = 'vigente' AND estado_pago = 'pagado' AND contribuyente_id = ? AND YEAR(fecha) = ?
              GROUP BY MONTH(fecha)"
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $pagos[(int) $f['mes']] = (float) $f['total'];
            $ivaPagos[(int) $f['mes']] = (float) $f['iva'];
        }
        $consulta->close();

        // Fuera del cálculo: gastos todavía no pagados (no descuentan hasta confirmar el pago).
        $gastoNoPagadoN = array_fill(1, 12, 0);
        $gastoNoPagado   = array_fill(1, 12, 0.0);
        $consulta = self::$db->prepare(
            "SELECT MONTH(fecha) AS mes, COUNT(*) AS n, SUM(total) AS total FROM gastos
              WHERE estado = 'vigente' AND estado_pago = 'pendiente' AND contribuyente_id = ? AND YEAR(fecha) = ?
              GROUP BY MONTH(fecha)"
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            $gastoNoPagadoN[(int) $f['mes']] = (int) $f['n'];
            $gastoNoPagado[(int) $f['mes']]  = (float) $f['total'];
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
            'iva_acreditado' => 0.0, 'iva_por_pagar' => 0.0, 'isr_por_pagar' => 0.0, 'impuestos' => 0.0, 'real' => 0.0, 'a_pagar_meses' => 0.0,
            'facturas_sin_pagar' => 0.0, 'facturas_sin_pagar_n' => 0, 'gastos_no_pagados' => 0.0, 'gastos_no_pagados_n' => 0];

        for ($m = 1; $m <= 12; $m++) {
            // Base = total con IVA − el IVA REAL de la operación (no se inventa el 16 %).
            $fSin = max(0.0, $facturado[$m] - $ivaFacturado[$m]);
            $pSin = max(0.0, $pagos[$m] - $ivaPagos[$m]);
            $ivaF = $ivaFacturado[$m];
            $ivaA = $ivaPagos[$m];
            $iva  = $ivaF - $ivaA;
            $isr  = ($fSin - $pSin) * $tasa / 100;
            $meses[$m] = [
                'facturado' => $facturado[$m], 'pagos' => $pagos[$m], 'facturado_sin_iva' => $fSin, 'pagos_sin_iva' => $pSin,
                'iva_facturado' => $ivaF, 'iva_acreditado' => $ivaA, 'iva_por_pagar' => $iva, 'isr_por_pagar' => $isr,
                'impuestos' => $iva + $isr, 'real' => $reales[$m] ?? null,
                'facturas_sin_pagar_n' => $sinPagarN[$m], 'facturas_sin_pagar' => $sinPagar[$m],
                'gastos_no_pagados_n' => $gastoNoPagadoN[$m], 'gastos_no_pagados' => $gastoNoPagado[$m],
            ];
            foreach (['facturado', 'pagos', 'facturado_sin_iva', 'pagos_sin_iva', 'iva_facturado', 'iva_acreditado', 'iva_por_pagar',
                'isr_por_pagar', 'facturas_sin_pagar_n', 'facturas_sin_pagar', 'gastos_no_pagados_n', 'gastos_no_pagados'] as $k) {
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
     * El «Detalle» de cada fila añade su estado: las ventas su cobro (Pagada · Pago parcial · Sin pagar) y los
     * gastos su pago (Pagado · Por pagar), más el método del CFDI (PUE/PPD/por definir) cuando existe.
     * totales incluye 'ventas_facturadas_pagadas' y 'gastos_pagados' (lo que entra al cálculo) y el IVA
     * correspondiente ('iva_ventas_pagadas', 'iva_gastos_pagados') junto a los totales registrados.
     *
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, float>}
     */
    public static function movimientos(int $contribuyente, int $anio, int $mes = 0): array
    {
        $filtroMes = $mes > 0 ? ' AND MONTH(%s) = ' . (int) $mes : '';

        // Estado de cobro de cada factura: sólo «Pagada» (al 100 %) entra en el cálculo de impuestos.
        // Los gastos traen su estado de pago: sólo «Pagado» se resta. El método del CFDI
        // (PUE/PPD/POR) es informativo: viaja en el detalle para explicar cada caso.
        $sql = "SELECT i.fecha, 'Venta' AS tipo, i.folio AS referencia,
                       COALESCE(NULLIF(c.nombre_empresa, ''), c.nombre) AS contraparte, e.nombre AS negocio,
                       CASE i.estado_facturacion WHEN 'facturada' THEN 'Facturada' WHEN 'no_facturada' THEN 'No facturada' ELSE 'Pendiente de facturar' END AS detalle,
                       CASE WHEN ROUND(i.total * 100) <= COALESCE(cb.cobrado, 0) THEN 'Pagada'
                            WHEN COALESCE(cb.cobrado, 0) > 0 THEN 'Pago parcial'
                            ELSE 'Sin pagar' END AS cobro,
                       '' AS pago,
                       '' AS metodo,
                       i.estado_facturacion AS facturacion,
                       i.subtotal - i.descuento AS subtotal, i.iva AS iva, i.total AS total
                  FROM ingresos i
                  JOIN clientes c ON c.id = i.cliente_id
                  JOIN empresas e ON e.id = i.empresa_id
                  LEFT JOIN (SELECT ingreso_id, SUM(ROUND(importe * 100)) AS cobrado FROM cobros WHERE estado = 'vigente' GROUP BY ingreso_id) cb ON cb.ingreso_id = i.id
                 WHERE i.estado = 'vigente' AND i.contribuyente_id = ? AND YEAR(i.fecha) = ?" . sprintf($filtroMes, 'i.fecha') . "
                UNION ALL
                SELECT g.fecha, 'Gasto', g.concepto, g.beneficiario, COALESCE(e.nombre, 'Compartido / general'),
                       COALESCE(k.nombre, 'Sin tipo'), '',
                       CASE g.estado_pago WHEN 'pagado' THEN 'Pagado' ELSE 'Por pagar' END,
                       COALESCE(g.metodo_pago_sat, ''),
                       '', g.subtotal, g.iva, g.total
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

        $totales = ['ventas' => 0.0, 'ventas_facturadas' => 0.0, 'ventas_facturadas_pagadas' => 0.0, 'gastos' => 0.0,
            'gastos_pagados' => 0.0, 'iva_ventas' => 0.0, 'iva_gastos' => 0.0,
            'iva_ventas_pagadas' => 0.0, 'iva_gastos_pagados' => 0.0];
        $metodos = ['PUE' => 'PUE · una sola exhibición', 'PPD' => 'PPD · parcialidades', 'POR' => 'Por definir (aún no se decide)'];
        foreach ($filas as &$f) {
            // El estado de cobro/pago y el método del CFDI viajan junto al detalle (pantalla, PDF, Excel y CSV).
            $metodo = $metodos[$f['metodo']] ?? '';
            $f['metodo'] = $metodo;
            $estado = $f['tipo'] === 'Venta' ? $f['cobro'] : $f['pago'];
            $f['detalle'] .= ' · ' . $estado . ($metodo !== '' ? ' · ' . $metodo : '');
            if ($f['tipo'] === 'Venta') {
                $totales['ventas'] += (float) $f['total'];
                $totales['iva_ventas'] += (float) $f['iva'];
                if ($f['facturacion'] === 'facturada') {
                    $totales['ventas_facturadas'] += (float) $f['total'];
                    if ($f['cobro'] === 'Pagada') {
                        $totales['ventas_facturadas_pagadas'] += (float) $f['total'];
                        $totales['iva_ventas_pagadas'] += (float) $f['iva'];
                    }
                }
            } else {
                $totales['gastos'] += (float) $f['total'];
                $totales['iva_gastos'] += (float) $f['iva'];
                if ($f['pago'] === 'Pagado') {
                    $totales['gastos_pagados'] += (float) $f['total'];
                    $totales['iva_gastos_pagados'] += (float) $f['iva'];
                }
            }
        }
        unset($f);

        return ['filas' => $filas, 'totales' => $totales];
    }

    /**
     * Proveedores: compras y pagos del periodo y lo que se les debe hoy (todas las compras vigentes, sin importar
     * el periodo). El total de una compra es subtotal + IVA capturado, igual que en Compras.
     *
     * @return array{proveedores: array<int, array<string, mixed>>, compras: array<int, array<string, mixed>>, pagos: array<int, array<string, mixed>>, totales: array<string, float>}
     */
    public static function proveedores(int $anio, int $mes = 0): array
    {
        $filtroMes = $mes > 0 ? ' AND MONTH(%s) = ' . (int) $mes : '';
        $totalSql  = '(ROUND(o.total_esperado * 100) + COALESCE(ROUND(o.iva * 100), 0)) / 100';
        $pagadoSql = "(SELECT COALESCE(SUM(p.importe), 0) FROM pagos_compra p WHERE p.orden_id = o.id AND p.estado = 'vigente')";

        $consulta = self::$db->prepare(
            "SELECT DATE(o.fecha_solicitud) AS fecha, o.folio, pr.nombre AS proveedor, o.estado, o.estado_cfdi,
                    o.total_esperado AS subtotal, COALESCE(o.iva, 0) AS iva, $totalSql AS total, $pagadoSql AS pagado
               FROM ordenes_compra o JOIN proveedores pr ON pr.id = o.proveedor_id
              WHERE o.estado <> 'cancelada' AND YEAR(o.fecha_solicitud) = ?" . sprintf($filtroMes, 'o.fecha_solicitud') . '
              ORDER BY pr.nombre, o.fecha_solicitud, o.id'
        );
        $consulta->bind_param('i', $anio);
        $consulta->execute();
        $compras = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $consulta = self::$db->prepare(
            "SELECT p.fecha, o.folio, pr.nombre AS proveedor, p.metodo, p.referencia, p.importe
               FROM pagos_compra p JOIN ordenes_compra o ON o.id = p.orden_id JOIN proveedores pr ON pr.id = o.proveedor_id
              WHERE p.estado = 'vigente' AND YEAR(p.fecha) = ?" . sprintf($filtroMes, 'p.fecha') . '
              ORDER BY pr.nombre, p.fecha, p.id'
        );
        $consulta->bind_param('i', $anio);
        $consulta->execute();
        $pagos = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $deuda = self::$db->query(
            "SELECT pr.id, pr.nombre, pr.rfc, COALESCE(SUM($totalSql), 0) AS comprado_total, COALESCE(SUM($pagadoSql), 0) AS pagado_total
               FROM proveedores pr JOIN ordenes_compra o ON o.proveedor_id = pr.id AND o.estado <> 'cancelada'
              GROUP BY pr.id, pr.nombre, pr.rfc"
        )->fetch_all(MYSQLI_ASSOC);

        $porProveedor = [];
        foreach ($deuda as $d) {
            $porProveedor[$d['nombre']] = [
                'proveedor' => $d['nombre'], 'rfc' => (string) $d['rfc'], 'compras' => 0, 'comprado' => 0.0, 'pagado_periodo' => 0.0,
                'saldo' => round((float) $d['comprado_total'] - (float) $d['pagado_total'], 2),
            ];
        }
        foreach ($compras as $c) {
            $porProveedor[$c['proveedor']] ??= ['proveedor' => $c['proveedor'], 'rfc' => '', 'compras' => 0, 'comprado' => 0.0, 'pagado_periodo' => 0.0, 'saldo' => 0.0];
            $porProveedor[$c['proveedor']]['compras']++;
            $porProveedor[$c['proveedor']]['comprado'] += (float) $c['total'];
        }
        foreach ($pagos as $p) {
            $porProveedor[$p['proveedor']] ??= ['proveedor' => $p['proveedor'], 'rfc' => '', 'compras' => 0, 'comprado' => 0.0, 'pagado_periodo' => 0.0, 'saldo' => 0.0];
            $porProveedor[$p['proveedor']]['pagado_periodo'] += (float) $p['importe'];
        }
        // Solo proveedores con movimiento en el periodo o con saldo pendiente.
        $proveedores = array_values(array_filter($porProveedor, static fn ($x) => $x['compras'] > 0 || $x['pagado_periodo'] > 0 || abs($x['saldo']) >= 0.005));
        usort($proveedores, static fn ($a, $b) => [$b['saldo'], $a['proveedor']] <=> [$a['saldo'], $b['proveedor']]);

        $totales = ['comprado' => 0.0, 'pagado_periodo' => 0.0, 'saldo' => 0.0];
        foreach ($proveedores as $x) {
            $totales['comprado'] += $x['comprado'];
            $totales['pagado_periodo'] += $x['pagado_periodo'];
            $totales['saldo'] += $x['saldo'];
        }

        return ['proveedores' => $proveedores, 'compras' => $compras, 'pagos' => $pagos, 'totales' => array_map(static fn ($v) => round($v, 2), $totales)];
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
