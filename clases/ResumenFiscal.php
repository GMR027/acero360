<?php

namespace App;

/**
 * ResumenFiscal — acceso en pantalla a la estimación de impuestos por mes.
 *
 * ADAPTADOR DE MotorFiscal (un solo motor): desde la FASE 7 esta clase NO calcula
 * impuestos por su cuenta. `anual()` traduce mes a mes la salida de
 * `MotorFiscal::calcular()` (catálogos versionados, base de flujo de efectivo) a las
 * claves históricas que usan las pantallas, el PDF, Excel, CSV y el cierre de
 * periodo, de modo que no pueden existir dos motores con cifras distintas para la
 * misma operación. Lo que aquí sólo se mantiene son DATOS y listados:
 *
 *   · reales()/guardarReal()   → lo realmente pagado al SAT (impuesto_real_mensual)
 *   · movimientos()            → listado de ventas y gastos con sus estados
 *   · proveedores()            → compras, pagos y saldos con proveedores
 *   · tasaIsr()                → tasa plana de referencia (ya NO dirige el cálculo:
 *                                con el catálogo activo el ISR usa la tarifa art. 106)
 *
 * Criterio del motor (flujo de efectivo, único método soportado):
 *   · Entra lo COBRADO (facturas al 100 %, con el IVA prorrateado por cada cobro) y los
 *     gastos PAGADOS que cumplan la naturaleza del catálogo. La deducción material la
 *     decide el contador: no se filtra por comprobante ni por revisión fiscal. El mes
 *     es el del cobro/pago efectivo, no el de la operación.
 *   · No entra nada sin pagar o en parcialidades, ni gastos marcados no deducibles o de
 *     naturaleza excluida; lo excluido se declara en 'facturas_excluidas',
 *     'gastos_excluidos' y 'avisos'.
 *   · IVA = IVA cobrado − acreditable − retenciones − saldo a favor − otros ajustes.
 *     ISR = tarifa art. 106 del catálogo sobre el acumulado, contra pagos previos,
 *     retenciones y otros acreditamientos. IVA e ISR no comparten cifras.
 *
 * Es una estimación administrativa: NO sustituye al contador ni a la declaración.
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
     * ADAPTADOR, NO MOTOR: cada mes se traduce de MotorFiscal::calcular() (catálogos
     * versionados, base de flujo de efectivo). Aquí NO hay fórmula propia, para que no
     * puedan existir dos motores con cifras distintas para la misma operación.
     *
     * Cambios de semántica respecto a la versión con cálculo propio:
     *   · facturado/pagos/iva_*  se atribuyen por el MES DEL COBRO y del PAGO EFECTIVO
     *     (con el IVA prorrateado por cobro), no por la fecha de la operación.
     *   · isr_por_pagar          tarifa progresiva del catálogo art. 106 (acumulado,
     *     pagos provisionales anteriores, retenciones y otros acreditamientos) en vez
     *     de una tasa plana configurable.
     *   · iva_por_pagar          cobrado − acreditable − retenciones − saldo a favor
     *     − otros ajustes (antes sólo facturado − gastos).
     *   · Los gastos que no entran (no deducibles, naturaleza excluida)
     *     quedan en 'gastos_excluidos' y sus reglas en 'reglas' para mostrar el porqué.
     *   Si el motor está deshabilitado (sin catálogo activo, sin configuración o régimen
     *   no admitido) 'habilitado' es false con 'avisos': NO se inventa ningún importe.
     *
     * @return array{meses: array<int, array<string, float|null>>, total: array<string, float>, tasa: float}
     */
    public static function anual(int $contribuyente, int $anio): array
    {
        $tasa = self::tasaIsr();   // Sólo informativa: el cálculo usa la tarifa del catálogo.
        $reales = self::reales($contribuyente, $anio);

        // Resumen anual (mes 12) para los totales del año: avisos, exclusiones y reglas.
        $anual = MotorFiscal::calcular($contribuyente, $anio, 12);
        $habilitado = !empty($anual['habilitado']) && isset($anual['por_mes']);
        $avisos = array_values(array_unique((array) ($anual['avisos'] ?? [])));
        $cero = static fn (int $c): float => $c / 100.0;

        $claveMes = [
            'facturado', 'pagos', 'facturado_sin_iva', 'pagos_sin_iva', 'iva_facturado', 'iva_acreditado',
            'iva_por_pagar', 'isr_por_pagar', 'impuestos', 'facturas_sin_pagar_n', 'facturas_sin_pagar',
            'gastos_no_pagados_n', 'gastos_no_pagados', 'iva_retenciones', 'iva_saldo_favor', 'iva_otros_ajustes',
            'isr_retenciones', 'isr_otros_acreditamientos', 'isr_pagos_previos',
        ];
        $total = array_fill_keys(array_merge($claveMes, ['real', 'a_pagar_meses']), 0.0);
        $total['facturas_sin_pagar_n'] = 0;
        $total['gastos_no_pagados_n'] = 0;
        $meses = [];

        for ($m = 1; $m <= 12; $m++) {
            $c = $m === 12 ? $anual : MotorFiscal::calcular($contribuyente, $anio, $m);
            $mesOk = !empty($c['habilitado']) && isset($c['por_mes']);
            $pm = $c['por_mes'] ?? [];

            // Neto = a cargo − saldo a favor (los dos vienen del motor en centavos).
            $ivaNeto = $mesOk ? (int) ($c['iva']['a_cargo'] ?? 0) - (int) ($c['iva']['saldo_a_favor'] ?? 0) : 0;
            $isrNeto = $mesOk ? (int) ($c['isr']['a_pagar'] ?? 0) - (int) ($c['isr']['excedente_acreditable'] ?? 0) : 0;

            $fila = [
                'facturado'         => $cero((int) ($pm['ingreso'][$m] ?? 0) + (int) ($pm['iva_cobrado'][$m] ?? 0)),
                'pagos'             => $cero((int) ($pm['deduccion'][$m] ?? 0) + (int) ($pm['iva_acreditable'][$m] ?? 0)),
                'facturado_sin_iva' => $cero((int) ($pm['ingreso'][$m] ?? 0)),
                'pagos_sin_iva'     => $cero((int) ($pm['deduccion'][$m] ?? 0)),
                'iva_facturado'     => $cero((int) ($pm['iva_cobrado'][$m] ?? 0)),
                'iva_acreditado'    => $cero((int) ($pm['iva_acreditable'][$m] ?? 0)),
                'iva_por_pagar'     => $cero($ivaNeto),
                'isr_por_pagar'     => $cero($isrNeto),
                'impuestos'         => $cero($ivaNeto + $isrNeto),
                'real'              => $reales[$m] ?? null,
                'facturas_sin_pagar_n' => (int) ($pm['facturas_excluidas_n'][$m] ?? 0),
                'facturas_sin_pagar'   => $cero((int) ($pm['facturas_excluidas'][$m] ?? 0)),
                'gastos_no_pagados_n'  => (int) ($pm['gastos_no_pagados_n'][$m] ?? 0),
                'gastos_no_pagados'    => $cero((int) ($pm['gastos_no_pagados'][$m] ?? 0)),
                // Detalle: por qué entra o no cada importe (retenciones y otros conceptos).
                'iva_retenciones'            => $cero((int) ($pm['ret_iva'][$m] ?? 0)),
                'iva_saldo_favor'            => $cero((int) ($pm['saldo_favor_iva'][$m] ?? 0)),
                'iva_otros_ajustes'          => $cero((int) ($pm['ajuste_iva'][$m] ?? 0)),
                'isr_retenciones'            => $cero((int) ($pm['ret_isr'][$m] ?? 0)),
                'isr_otros_acreditamientos'  => $cero((int) ($pm['otros_isr'][$m] ?? 0)),
                'isr_pagos_previos'          => $cero((int) ($c['isr']['pagos_provisionales_anteriores'] ?? 0)),
                'habilitado'                 => $mesOk,
            ];
            $meses[$m] = $fila;

            foreach ($claveMes as $k) {
                $total[$k] += $fila[$k];
            }
            $total['real'] += $fila['real'] ?? 0.0;
            $total['a_pagar_meses'] += max(0.0, $fila['impuestos']);
        }

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) FROM ingresos WHERE estado = 'vigente' AND estado_facturacion = 'sin_clasificar' AND contribuyente_id = ? AND YEAR(fecha) = ?"
        );
        $consulta->bind_param('ii', $contribuyente, $anio);
        $consulta->execute();
        $sinClasificar = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        return [
            'meses' => $meses, 'total' => $total, 'tasa' => $tasa,
            'hay_reales' => $reales !== [], 'sin_clasificar' => $sinClasificar,
            // Origen y razones del cálculo (un solo motor: MotorFiscal).
            'motor'          => 'MotorFiscal',
            'habilitado'     => $habilitado,
            'avisos'         => $avisos,
            'versiones'      => $anual['versiones'] ?? null,
            'configuracion'  => $anual['configuracion'] ?? null,
            'aviso_legal'    => $anual['aviso_legal'] ?? null,
            'reglas'         => $anual['reglas'] ?? null,
            'gastos_excluidos'  => $anual['gastos_excluidos'] ?? ['naturaleza' => 0, 'no_deducibles' => 0],
            'facturas_excluidas' => $anual['facturas_excluidas'] ?? ['n' => 0, 'pendiente' => 0.0],
        ];
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
