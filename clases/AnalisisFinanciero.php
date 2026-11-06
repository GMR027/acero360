<?php

namespace App;

/**
 * AnalisisFinanciero — balance del primer apartado de Análisis Financiero.
 *
 * Reúne tres cosas:
 *   · Inventario valuado al precio del catálogo (sin IVA / con IVA).
 *   · Pedidos de proveedor con saldo pendiente, sin importar el mes.
 *   · Gastos operativos pendientes del mes (solo si se piden).
 *
 * La comparación se hace en una sola base: con IVA («precio de venta») o
 * sin IVA («precio de lista»). Así el medidor nunca mezcla importes netos
 * con importes que ya incluyen el impuesto.
 */
class AnalisisFinanciero
{
    /** @var \mysqli */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    /** Tasa de IVA trasladado que usa la plataforma. */
    private const IVA = 16.0;

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

    /**
     * Balance completo.
     *
     * @param int[]  $empresaIds       Negocios seleccionados. Vacío = todos los activos.
     * @param string $base             'venta' (con IVA) | 'lista' (sin IVA).
     * @param bool   $restarProveedores Resta el saldo pendiente de pedidos.
     * @param bool   $restarGastos      Resta los gastos operativos pendientes del mes.
     * @param string $modo              'simple' (inventario contra deudas) | 'detallado' (agrega ventas y gastos pagados).
     * @param bool   $conComparativo    Calcula la comparación contra el mes anterior (solo detallado).
     * @return array<string, mixed>
     */
    public static function resumen(
        int $anio,
        int $mes,
        array $empresaIds,
        string $base = 'venta',
        bool $restarProveedores = true,
        bool $restarGastos = false,
        string $modo = 'simple',
        bool $conComparativo = true
    ): array {
        self::$errores = [];

        $empresaIds = array_values(array_unique(array_filter(array_map('intval', $empresaIds))));
        $base = $base === 'lista' ? 'lista' : 'venta';
        $modo = $modo === 'detallado' ? 'detallado' : 'simple';

        $inventario  = self::inventario($empresaIds, $base);
        $proveedores = self::proveedoresPendientes($empresaIds, $base);
        $gastos      = self::gastosOperativos($anio, $mes, $empresaIds, $base);
        $ventas      = $modo === 'detallado' ? self::ventasPeriodo($anio, $mes, $empresaIds, $base) : [
            'filas' => [], 'sin_iva' => 0.0, 'iva' => 0.0, 'con_iva' => 0.0, 'base' => 0.0,
            'operaciones' => 0, 'sin_factura_operaciones' => 0, 'sin_factura_sin_iva' => 0.0,
            'sin_factura_con_iva' => 0.0, 'sin_factura_base' => 0.0,
        ];
        $gastosPagados = $modo === 'detallado' ? self::gastosPagados($anio, $mes, $empresaIds, $base) : [
            'filas' => [], 'sin_iva' => 0.0, 'iva' => 0.0, 'con_iva' => 0.0, 'base' => 0.0,
        ];
        $cobros = $modo === 'detallado' ? self::cobrosPeriodo($anio, $mes, $empresaIds) : [
            'cobrado' => 0.0, 'por_cobrar' => 0.0, 'pct_cobrado' => 0.0,
        ];
        if ($modo === 'detallado') {
            $cobros['pct_cobrado'] = ((float) $ventas['con_iva'] > 0)
                ? round((((float) $ventas['con_iva'] - (float) $cobros['por_cobrar']) / (float) $ventas['con_iva']) * 100, 1)
                : 0.0;
        }
        $antiguedad = self::antiguedadProveedores($proveedores['filas'], $base);
        $gastosCategoria = $modo === 'detallado'
            ? self::gastosPorCategoria($gastosPagados['filas'], $gastos['filas'], $base)
            : ['filas' => [], 'pagado' => 0.0, 'pendiente' => 0.0, 'total' => 0.0];
        $ventasNegocio = $modo === 'detallado' ? self::ventasPorNegocio($ventas['filas'], $base) : ['filas' => [], 'total' => 0.0];

        $activo = (float) $inventario['base'];
        $pasivo = 0.0;
        if ($restarProveedores) {
            $pasivo += (float) $proveedores['base'];
        }
        if ($restarGastos) {
            $pasivo += (float) $gastos['base'];
        }

        $resultado = null;
        if ($modo === 'detallado') {
            // En detallado las entradas incluyen el inventario y las ventas del
            // mes; las salidas incluyen lo pagado y las deudas seleccionadas.
            $activo += (float) $ventas['base'];
            $pasivo += (float) $gastosPagados['base'];
            $resultado = (float) $ventas['base'] - (float) $gastosPagados['base']
                - ($restarGastos ? (float) $gastos['base'] : 0.0);
        }

        $balance = $activo - $pasivo;
        $total   = $activo + $pasivo;
        $solidez = $total > 0 ? max(0, min(100, ($activo / $total) * 100)) : 50.0;

        $salida = [
            'anio'        => $anio,
            'mes'         => $mes,
            'base'        => $base,
            'modo'        => $modo,
            'inventario'  => $inventario,
            'proveedores' => $proveedores,
            'gastos'      => $gastos,
            'ventas'      => $ventas,
            'gastos_pagados' => $gastosPagados,
            'cobros'      => $cobros,
            'antiguedad'  => $antiguedad,
            'gastos_categoria' => $gastosCategoria,
            'ventas_negocio' => $ventasNegocio,
            'balance'     => [
                'modo'                 => $modo,
                'base'                 => $base,
                'activo'               => round($activo, 2),
                'pasivo'               => round($pasivo, 2),
                'balance'              => round($balance, 2),
                'resultado'            => $resultado !== null ? round($resultado, 2) : null,
                'solidez'              => round($solidez, 1),
                // El medidor redondo usa un arco de 240°; el antiguo, 180°.
                'angulo'               => round($modo === 'detallado' ? (-120 + ($solidez * 2.4)) : (-90 + ($solidez * 1.8)), 2),
                'estado'               => self::estado($solidez),
                'restar_proveedores'   => $restarProveedores,
                'restar_gastos'        => $restarGastos,
            ],
            'comparativo' => null,
            'alertas'     => [],
        ];

        if ($conComparativo && $modo === 'detallado') {
            $salida['comparativo'] = self::comparativo($salida, $empresaIds, $base, $restarProveedores, $restarGastos);
        }

        $salida['alertas'] = self::alertas($salida);

        return $salida;
    }

    /**
     * Inventario por producto, valuado con el precio de catálogo.
     *
     * Los productos compartidos entre negocios se cuentan una sola vez cuando
     * el análisis es consolidado. Las existencias negativas se informan aparte
     * y no restan del activo.
     *
     * @param int[] $empresaIds
     * @return array{filas: array<int, array<string, mixed>>, sin_iva: float, con_iva: float, base: float, piezas: int, negativos: array<int, array<string, mixed>>}
     */
    public static function inventario(array $empresaIds, string $base = 'venta'): array
    {
        $filtro = '';
        if ($empresaIds) {
            $filtro = ' AND e.id IN (' . implode(',', $empresaIds) . ')';
        }

        $consulta = self::$db->query(
            "SELECT p.id, p.sku, p.nombre, p.imagen, p.stock, p.stock_minimo, p.precio,
                    GROUP_CONCAT(DISTINCT e.nombre ORDER BY e.nombre SEPARATOR ', ') AS negocios
               FROM productos p
               JOIN producto_empresa pe ON pe.producto_id = p.id
               JOIN empresas e ON e.id = pe.empresa_id
              WHERE e.activa = 1 AND (p.activo = 1 OR p.stock <> 0) $filtro
              GROUP BY p.id, p.sku, p.nombre, p.imagen, p.stock, p.stock_minimo, p.precio
              ORDER BY p.sku IS NULL, p.sku, p.nombre"
        );

        $filas = [];
        $sinIva = 0.0;
        $conIva = 0.0;
        $piezas = 0;
        $negativos = [];

        while ($fila = $consulta->fetch_assoc()) {
            $stock = (int) $fila['stock'];
            $stockMinimo = $fila['stock_minimo'] === null ? null : (int) $fila['stock_minimo'];
            $precio = (float) $fila['precio'];

            if ($stock < 0) {
                $negativos[] = [
                    'sku'    => (string) ($fila['sku'] ?? ''),
                    'nombre' => (string) $fila['nombre'],
                    'stock'  => $stock,
                ];
            }

            $unidades = max(0, $stock);
            $importeSinIva = round($unidades * $precio, 2);
            $importeConIva = round($importeSinIva * (1 + self::IVA / 100), 2);

            $filas[] = [
                'id'           => (int) $fila['id'],
                'sku'          => (string) ($fila['sku'] ?? ''),
                'nombre'       => (string) $fila['nombre'],
                'imagen'       => (string) ($fila['imagen'] ?? ''),
                'negocios'     => (string) ($fila['negocios'] ?? ''),
                'stock'        => $stock,
                'stock_minimo' => $stockMinimo,
                'bajo_minimo'  => $stockMinimo !== null && $stock <= $stockMinimo,
                'precio'       => $precio,
                'sin_iva'      => $importeSinIva,
                'con_iva'      => $importeConIva,
            ];

            $sinIva += $importeSinIva;
            $conIva += $importeConIva;
            $piezas += $unidades;
        }

        return [
            'filas'    => $filas,
            'sin_iva'  => round($sinIva, 2),
            'con_iva'  => round($conIva, 2),
            'base'     => round($base === 'lista' ? $sinIva : $conIva, 2),
            'piezas'   => $piezas,
            'negativos' => $negativos,
        ];
    }

    /**
     * Pedidos con saldo pendiente, de todos los meses.
     *
     * A cada pedido se le resta lo pagado. El saldo se reparte entre base e
     * IVA de forma proporcional para poder comparar en la base elegida.
     *
     * @param int[] $empresaIds
     * @return array{filas: array<int, array<string, mixed>>, sin_iva: float, iva: float, con_iva: float, base: float, pagado: float}
     */
    public static function proveedoresPendientes(array $empresaIds, string $base = 'venta'): array
    {
        $filtro = '';
        if ($empresaIds) {
            $filtro = count($empresaIds) > 1
                ? ' AND (o.empresa_id IN (' . implode(',', $empresaIds) . ') OR o.empresa_id IS NULL)'
                : ' AND o.empresa_id IN (' . implode(',', $empresaIds) . ')';
        }

        $consulta = self::$db->query(
            "SELECT x.*
               FROM (
                    SELECT o.id, o.folio, o.fecha_solicitud, o.empresa_id,
                           o.total_esperado, COALESCE(o.iva, 0) AS iva,
                           p.nombre AS proveedor_nombre,
                           COALESCE((
                               SELECT SUM(pc.importe) FROM pagos_compra pc
                                WHERE pc.orden_id = o.id AND pc.estado = 'vigente'
                           ), 0) AS pagado
                      FROM ordenes_compra o
                      JOIN proveedores p ON p.id = o.proveedor_id
                     WHERE o.estado <> 'cancelada' $filtro
               ) x
              WHERE (x.total_esperado + x.iva) > x.pagado
              ORDER BY x.fecha_solicitud, x.id"
        );

        $filas = [];
        $sinIva = 0.0;
        $ivaTotal = 0.0;
        $conIva = 0.0;
        $pagadoTotal = 0.0;

        while ($fila = $consulta->fetch_assoc()) {
            $totalSinIva = (float) $fila['total_esperado'];
            $totalConIva = $totalSinIva + (float) $fila['iva'];
            $pagado = (float) $fila['pagado'];
            $saldoConIva = max(0, round($totalConIva - $pagado, 2));

            if ($saldoConIva <= 0) {
                continue;
            }

            // Si hubo pagos parciales, el saldo sin IVA se calcula en la
            // misma proporción que el saldo con IVA.
            $factor = $totalConIva > 0 ? $saldoConIva / $totalConIva : 0;
            $saldoSinIva = round($totalSinIva * $factor, 2);
            $ivaPendiente = round($saldoConIva - $saldoSinIva, 2);

            $filas[] = [
                'id'                => (int) $fila['id'],
                'folio'             => (string) $fila['folio'],
                'fecha'             => (string) $fila['fecha_solicitud'],
                'proveedor'         => (string) $fila['proveedor_nombre'],
                'sin_iva'           => $saldoSinIva,
                'iva'               => $ivaPendiente,
                'con_iva'           => $saldoConIva,
                'pagado'            => $pagado,
                'total_con_iva'     => $totalConIva,
            ];

            $sinIva += $saldoSinIva;
            $ivaTotal += $ivaPendiente;
            $conIva += $saldoConIva;
            $pagadoTotal += $pagado;
        }

        return [
            'filas'   => $filas,
            'sin_iva' => round($sinIva, 2),
            'iva'     => round($ivaTotal, 2),
            'con_iva' => round($conIva, 2),
            'base'    => round($base === 'lista' ? $sinIva : $conIva, 2),
            'pagado'  => round($pagadoTotal, 2),
        ];
    }

    /**
     * Gastos operativos pendientes del mes.
     *
     * Se excluyen los gastos ligados a un proveedor y los que ya están
     * ligados a un pedido: esas deudas ya se muestran en proveedores y no
     * deben contarse dos veces.
     *
     * @param int[] $empresaIds
     * @return array{filas: array<int, array<string, mixed>>, sin_iva: float, iva: float, con_iva: float, base: float}
     */
    public static function gastosOperativos(int $anio, int $mes, array $empresaIds, string $base = 'venta'): array
    {
        $consulta = self::$db->prepare(
            "SELECT g.*, c.nombre AS categoria_nombre
               FROM gastos g
               LEFT JOIN categorias_gasto c ON c.id = g.categoria_id
              WHERE g.estado = 'vigente' AND g.estado_pago = 'pendiente'
                AND YEAR(g.fecha) = ? AND MONTH(g.fecha) = ?
                AND g.proveedor_id IS NULL
                AND g.beneficiario_tipo <> 'proveedor'
                AND NOT EXISTS (
                    SELECT 1 FROM ordenes_compra oc
                     WHERE oc.gasto_id = g.id AND oc.estado <> 'cancelada'
                )
              ORDER BY g.fecha, g.id"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $filas = [];
        $sinIva = 0.0;
        $ivaTotal = 0.0;
        $conIva = 0.0;
        $especifico = count($empresaIds) === 1;

        while ($g = $resultado->fetch_assoc()) {
            $asignaciones = [];
            if ((int) $g['compartido'] === 1) {
                foreach (Gastos::reparto((int) $g['id']) as $parte) {
                    $asignaciones[(int) $parte['empresa_id']] = [
                        'subtotal' => (float) $g['subtotal'] * ((float) $parte['porcentaje'] / 100),
                        'iva'      => (float) $g['iva'] * ((float) $parte['porcentaje'] / 100),
                    ];
                }
            } else {
                $asignaciones[(int) ($g['empresa_id'] ?? 0)] = [
                    'subtotal' => (float) $g['subtotal'],
                    'iva'      => (float) $g['iva'],
                ];
            }

            if ($especifico) {
                $empresaId = $empresaIds[0];
                if (!isset($asignaciones[$empresaId])) {
                    continue;
                }
                $subtotal = $asignaciones[$empresaId]['subtotal'];
                $iva = $asignaciones[$empresaId]['iva'];
            } else {
                // Consolidado: se incluye una sola vez si toca a algún negocio
                // seleccionado. Sin filtro de negocios entran todos.
                $aplica = !$empresaIds;
                if (!$aplica) {
                    foreach (array_keys($asignaciones) as $empresaId) {
                        if (in_array($empresaId, $empresaIds, true)) {
                            $aplica = true;
                            break;
                        }
                    }
                }
                if (!$aplica) {
                    continue;
                }
                $subtotal = (float) $g['subtotal'];
                $iva = (float) $g['iva'];
            }

            $importeSinIva = round($subtotal, 2);
            $importeConIva = round($subtotal + $iva, 2);

            if ($importeConIva <= 0) {
                continue;
            }

            $filas[] = [
                'id'          => (int) $g['id'],
                'fecha'       => (string) $g['fecha'],
                'concepto'    => (string) $g['concepto'],
                'beneficiario' => (string) $g['beneficiario'],
                'categoria'   => (string) ($g['categoria_nombre'] ?? ''),
                'sin_iva'     => $importeSinIva,
                'iva'         => round($iva, 2),
                'con_iva'     => $importeConIva,
            ];

            $sinIva += $importeSinIva;
            $ivaTotal += $iva;
            $conIva += $importeConIva;
        }
        $consulta->close();

        return [
            'filas'   => $filas,
            'sin_iva' => round($sinIva, 2),
            'iva'     => round($ivaTotal, 2),
            'con_iva' => round($conIva, 2),
            'base'    => round($base === 'lista' ? $sinIva : $conIva, 2),
        ];
    }

    /**
     * Ventas realizadas en el mes para el modo detallado.
     *
     * Se toma el ingreso contable (una sola fila por venta) e incluye todas
     * las ventas vigentes: facturadas, no facturadas y sin clasificar.
     *
     * @param int[] $empresaIds
     * @return array{filas: array<int, array<string, mixed>>, sin_iva: float, iva: float, con_iva: float, base: float, operaciones: int, sin_factura_operaciones: int, sin_factura_sin_iva: float, sin_factura_con_iva: float, sin_factura_base: float}
     */
    public static function ventasPeriodo(int $anio, int $mes, array $empresaIds, string $base = 'venta'): array
    {
        $filtro = $empresaIds ? ' AND i.empresa_id IN (' . implode(',', $empresaIds) . ')' : '';

        $consulta = self::$db->prepare(
            "SELECT i.id, i.folio, i.fecha, i.subtotal, i.descuento, i.iva, i.total, i.estado_facturacion,
                    i.empresa_id, e.nombre AS empresa_nombre,
                    cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa
               FROM ingresos i
               JOIN empresas e ON e.id = i.empresa_id
               JOIN clientes cl ON cl.id = i.cliente_id
              WHERE i.estado = 'vigente' AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? $filtro
              ORDER BY i.fecha, i.id"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $etiquetas = [
            'facturada'      => 'Facturada',
            'no_facturada'   => 'No facturada',
            'sin_clasificar' => 'Sin clasificar',
        ];

        $filas = [];
        $sinIva = 0.0;
        $ivaTotal = 0.0;
        $conIva = 0.0;
        $operaciones = 0;
        $sinFacturaOperaciones = 0;
        $sinFacturaSinIva = 0.0;
        $sinFacturaConIva = 0.0;

        while ($fila = $resultado->fetch_assoc()) {
            $importeSinIva = round((float) $fila['subtotal'] - (float) $fila['descuento'], 2);
            $importeIva = round((float) $fila['iva'], 2);
            $importeConIva = round((float) $fila['total'], 2);
            $estadoFact = (string) $fila['estado_facturacion'];
            $sinFactura = $estadoFact !== 'facturada';

            $filas[] = [
                'id'            => (int) $fila['id'],
                'folio'         => (string) $fila['folio'],
                'fecha'         => (string) $fila['fecha'],
                'cliente'       => (string) ($fila['cliente_empresa'] ?: $fila['cliente_nombre']),
                'negocio'       => (string) $fila['empresa_nombre'],
                'facturacion'   => $etiquetas[$estadoFact] ?? $estadoFact,
                'sin_factura'   => $sinFactura,
                'sin_iva'       => $importeSinIva,
                'iva'           => $importeIva,
                'con_iva'       => $importeConIva,
            ];

            $sinIva += $importeSinIva;
            $ivaTotal += $importeIva;
            $conIva += $importeConIva;
            $operaciones++;
            if ($sinFactura) {
                $sinFacturaOperaciones++;
                $sinFacturaSinIva += $importeSinIva;
                $sinFacturaConIva += $importeConIva;
            }
        }
        $consulta->close();

        return [
            'filas'                    => $filas,
            'sin_iva'                  => round($sinIva, 2),
            'iva'                      => round($ivaTotal, 2),
            'con_iva'                  => round($conIva, 2),
            'base'                     => round($base === 'lista' ? $sinIva : $conIva, 2),
            'operaciones'              => $operaciones,
            'sin_factura_operaciones'  => $sinFacturaOperaciones,
            'sin_factura_sin_iva'      => round($sinFacturaSinIva, 2),
            'sin_factura_con_iva'      => round($sinFacturaConIva, 2),
            'sin_factura_base'         => round($base === 'lista' ? $sinFacturaSinIva : $sinFacturaConIva, 2),
        ];
    }

    /**
     * Gastos pagados del mes para el modo detallado.
     *
     * Incluye todos los gastos pagados, sean operativos o de proveedor: son
     * salidas reales del periodo. Los pendientes se muestran en su propia
     * tabla y no se mezclan aquí.
     *
     * @param int[] $empresaIds
     * @return array{filas: array<int, array<string, mixed>>, sin_iva: float, iva: float, con_iva: float, base: float}
     */
    public static function gastosPagados(int $anio, int $mes, array $empresaIds, string $base = 'venta'): array
    {
        $consulta = self::$db->prepare(
            "SELECT g.*, c.nombre AS categoria_nombre
               FROM gastos g
               LEFT JOIN categorias_gasto c ON c.id = g.categoria_id
              WHERE g.estado = 'vigente' AND g.estado_pago = 'pagado'
                AND YEAR(g.fecha) = ? AND MONTH(g.fecha) = ?
              ORDER BY g.fecha, g.id"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $filas = [];
        $sinIva = 0.0;
        $ivaTotal = 0.0;
        $conIva = 0.0;
        $especifico = count($empresaIds) === 1;

        while ($g = $resultado->fetch_assoc()) {
            $asignaciones = [];
            if ((int) $g['compartido'] === 1) {
                foreach (Gastos::reparto((int) $g['id']) as $parte) {
                    $asignaciones[(int) $parte['empresa_id']] = [
                        'subtotal' => (float) $g['subtotal'] * ((float) $parte['porcentaje'] / 100),
                        'iva'      => (float) $g['iva'] * ((float) $parte['porcentaje'] / 100),
                    ];
                }
            } else {
                $asignaciones[(int) ($g['empresa_id'] ?? 0)] = [
                    'subtotal' => (float) $g['subtotal'],
                    'iva'      => (float) $g['iva'],
                ];
            }

            if ($especifico) {
                $empresaId = $empresaIds[0];
                if (!isset($asignaciones[$empresaId])) {
                    continue;
                }
                $subtotal = $asignaciones[$empresaId]['subtotal'];
                $iva = $asignaciones[$empresaId]['iva'];
            } else {
                $aplica = !$empresaIds;
                if (!$aplica) {
                    foreach (array_keys($asignaciones) as $empresaId) {
                        if (in_array($empresaId, $empresaIds, true)) {
                            $aplica = true;
                            break;
                        }
                    }
                }
                if (!$aplica) {
                    continue;
                }
                $subtotal = (float) $g['subtotal'];
                $iva = (float) $g['iva'];
            }

            $importeSinIva = round($subtotal, 2);
            $importeConIva = round($subtotal + $iva, 2);
            if ($importeConIva <= 0) {
                continue;
            }

            $filas[] = [
                'id'           => (int) $g['id'],
                'fecha'        => (string) $g['fecha'],
                'concepto'     => (string) $g['concepto'],
                'beneficiario' => (string) $g['beneficiario'],
                'categoria'    => (string) ($g['categoria_nombre'] ?? ''),
                'sin_iva'      => $importeSinIva,
                'iva'          => round($iva, 2),
                'con_iva'      => $importeConIva,
            ];

            $sinIva += $importeSinIva;
            $ivaTotal += $iva;
            $conIva += $importeConIva;
        }
        $consulta->close();

        return [
            'filas'   => $filas,
            'sin_iva' => round($sinIva, 2),
            'iva'     => round($ivaTotal, 2),
            'con_iva' => round($conIva, 2),
            'base'    => round($base === 'lista' ? $sinIva : $conIva, 2),
        ];
    }

    /**
     * Cobros del mes y saldo por cobrar de las ventas del mismo mes.
     *
     * «Cobrado» es dinero real recibido en el mes (puede incluir ventas de
     * meses anteriores). «Por cobrar» es lo que sigue pendiente de las ventas
     * del mes seleccionado. Ambos importes van con IVA, porque son flujo de caja.
     *
     * @param int[] $empresaIds
     * @return array{cobrado: float, por_cobrar: float, pct_cobrado: float}
     */
    public static function cobrosPeriodo(int $anio, int $mes, array $empresaIds): array
    {
        $filtro = $empresaIds ? ' AND i.empresa_id IN (' . implode(',', $empresaIds) . ')' : '';

        $consulta = self::$db->prepare(
            "SELECT COALESCE(SUM(ROUND(c.importe * 100)), 0) AS cobrado
               FROM cobros c
               JOIN ingresos i ON i.id = c.ingreso_id
              WHERE c.estado = 'vigente' AND i.estado = 'vigente'
                AND YEAR(c.fecha) = ? AND MONTH(c.fecha) = ? $filtro"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $cobrado = (int) $consulta->get_result()->fetch_assoc()['cobrado'];
        $consulta->close();

        $consulta = self::$db->prepare(
            "SELECT COALESCE(SUM(ROUND(i.total * 100)), 0) - COALESCE(SUM(k.cobrado), 0) AS saldo
               FROM ingresos i
               LEFT JOIN (
                    SELECT ingreso_id, SUM(ROUND(importe * 100)) AS cobrado
                      FROM cobros WHERE estado = 'vigente' GROUP BY ingreso_id
               ) k ON k.ingreso_id = i.id
              WHERE i.estado = 'vigente' AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? $filtro"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $porCobrar = (int) $consulta->get_result()->fetch_assoc()['saldo'];
        $consulta->close();

        return [
            'cobrado'    => round($cobrado / 100, 2),
            'por_cobrar' => round(max(0, $porCobrar) / 100, 2),
            'pct_cobrado' => 0.0,
        ];
    }

    /**
     * Antigüedad de los pedidos con saldo pendiente.
     *
     * @param array<int, array<string, mixed>> $filas Filas de proveedoresPendientes().
     * @return array{buckets: array<string, array{etiqueta: string, monto: float, pedidos: int}>, total: float, mas_60: float}
     */
    public static function antiguedadProveedores(array $filas, string $base = 'venta'): array
    {
        $buckets = [
            '0-30'  => ['etiqueta' => '0 a 30 días', 'monto' => 0.0, 'pedidos' => 0],
            '31-60' => ['etiqueta' => '31 a 60 días', 'monto' => 0.0, 'pedidos' => 0],
            '61-90' => ['etiqueta' => '61 a 90 días', 'monto' => 0.0, 'pedidos' => 0],
            '90+'   => ['etiqueta' => 'Más de 90 días', 'monto' => 0.0, 'pedidos' => 0],
        ];

        $hoy = strtotime(date('Y-m-d'));
        foreach ($filas as $fila) {
            $fecha = strtotime((string) ($fila['fecha'] ?? ''));
            if ($fecha === false) {
                continue;
            }
            $dias = max(0, (int) floor(($hoy - $fecha) / 86400));
            $clave = $dias <= 30 ? '0-30' : ($dias <= 60 ? '31-60' : ($dias <= 90 ? '61-90' : '90+'));
            $monto = $base === 'lista' ? (float) $fila['sin_iva'] : (float) $fila['con_iva'];

            $buckets[$clave]['monto'] += $monto;
            $buckets[$clave]['pedidos']++;
        }

        foreach ($buckets as &$bucket) {
            $bucket['monto'] = round($bucket['monto'], 2);
        }
        unset($bucket);

        return [
            'buckets' => $buckets,
            'total'   => round(array_sum(array_column($buckets, 'monto')), 2),
            'mas_60'  => round($buckets['61-90']['monto'] + $buckets['90+']['monto'], 2),
        ];
    }

    /**
     * Gastos del mes agrupados por categoría, separando pagado y pendiente.
     *
     * @param array<int, array<string, mixed>> $pagados
     * @param array<int, array<string, mixed>> $pendientes
     * @return array{filas: array<int, array<string, mixed>>, pagado: float, pendiente: float, total: float}
     */
    public static function gastosPorCategoria(array $pagados, array $pendientes, string $base = 'venta'): array
    {
        $mapa = [];
        foreach ([['filas' => $pagados, 'tipo' => 'pagado'], ['filas' => $pendientes, 'tipo' => 'pendiente']] as $grupo) {
            foreach ($grupo['filas'] as $fila) {
                $categoria = trim((string) ($fila['categoria'] ?? ''));
                if ($categoria === '') {
                    $categoria = 'Sin categoría';
                }
                $monto = $base === 'lista' ? (float) $fila['sin_iva'] : (float) $fila['con_iva'];
                $mapa[$categoria] ??= ['categoria' => $categoria, 'pagado' => 0.0, 'pendiente' => 0.0, 'total' => 0.0];
                $mapa[$categoria][$grupo['tipo']] += $monto;
                $mapa[$categoria]['total'] += $monto;
            }
        }

        usort($mapa, static fn ($a, $b) => $b['total'] <=> $a['total']);
        foreach ($mapa as &$fila) {
            $fila['pagado'] = round($fila['pagado'], 2);
            $fila['pendiente'] = round($fila['pendiente'], 2);
            $fila['total'] = round($fila['total'], 2);
        }
        unset($fila);

        return [
            'filas'     => array_values($mapa),
            'pagado'    => round(array_sum(array_column($mapa, 'pagado')), 2),
            'pendiente' => round(array_sum(array_column($mapa, 'pendiente')), 2),
            'total'     => round(array_sum(array_column($mapa, 'total')), 2),
        ];
    }

    /**
     * Ventas del mes agrupadas por negocio.
     *
     * @param array<int, array<string, mixed>> $filas Filas de ventasPeriodo().
     * @return array{filas: array<int, array<string, mixed>>, total: float}
     */
    public static function ventasPorNegocio(array $filas, string $base = 'venta'): array
    {
        $mapa = [];
        foreach ($filas as $fila) {
            $negocio = trim((string) ($fila['negocio'] ?? ''));
            if ($negocio === '') {
                $negocio = 'Sin negocio';
            }
            $monto = $base === 'lista' ? (float) $fila['sin_iva'] : (float) $fila['con_iva'];
            $mapa[$negocio] ??= ['negocio' => $negocio, 'operaciones' => 0, 'ventas' => 0.0, 'sin_factura' => 0.0];
            $mapa[$negocio]['operaciones']++;
            $mapa[$negocio]['ventas'] += $monto;
            if (!empty($fila['sin_factura'])) {
                $mapa[$negocio]['sin_factura'] += $monto;
            }
        }

        usort($mapa, static fn ($a, $b) => $b['ventas'] <=> $a['ventas']);
        foreach ($mapa as &$fila) {
            $fila['ventas'] = round($fila['ventas'], 2);
            $fila['sin_factura'] = round($fila['sin_factura'], 2);
        }
        unset($fila);

        return [
            'filas' => array_values($mapa),
            'total' => round(array_sum(array_column($mapa, 'ventas')), 2),
        ];
    }

    /**
     * Comparación contra el mes anterior.
     *
     * @param array<string, mixed> $actual
     * @param int[] $empresaIds
     * @return array<string, mixed>
     */
    private static function comparativo(array $actual, array $empresaIds, string $base, bool $restarProveedores, bool $restarGastos): array
    {
        [$anioAnterior, $mesAnterior] = \App\Contabilidad::mesAnterior((int) $actual['anio'], (int) $actual['mes']);
        $previo = self::resumen($anioAnterior, $mesAnterior, $empresaIds, $base, $restarProveedores, $restarGastos, 'detallado', false);

        $items = [
            ['clave' => 'ventas', 'etiqueta' => 'Ventas', 'actual' => (float) $actual['ventas']['base'], 'anterior' => (float) $previo['ventas']['base']],
            ['clave' => 'gastos_pagados', 'etiqueta' => 'Gastos pagados', 'actual' => (float) $actual['gastos_pagados']['base'], 'anterior' => (float) $previo['gastos_pagados']['base']],
            ['clave' => 'gastos_pendientes', 'etiqueta' => 'Gastos pendientes', 'actual' => (float) $actual['gastos']['base'], 'anterior' => (float) $previo['gastos']['base']],
            ['clave' => 'resultado', 'etiqueta' => 'Resultado', 'actual' => (float) $actual['balance']['resultado'], 'anterior' => (float) $previo['balance']['resultado']],
            ['clave' => 'posicion', 'etiqueta' => 'Posición', 'actual' => (float) $actual['balance']['balance'], 'anterior' => (float) $previo['balance']['balance']],
        ];

        foreach ($items as &$item) {
            $item['variacion'] = \App\Contabilidad::variacion($item['actual'], $item['anterior']);
        }
        unset($item);

        return [
            'periodo' => [
                'anio'    => $anioAnterior,
                'mes'     => $mesAnterior,
                'etiqueta' => \nombreMes($mesAnterior) . ' ' . $anioAnterior,
            ],
            'items' => $items,
        ];
    }

    /**
     * Avisos automáticos del modo detallado.
     *
     * @param array<string, mixed> $r
     * @return array<int, array{tipo: string, texto: string}>
     */
    private static function alertas(array $r): array
    {
        if (($r['modo'] ?? 'simple') !== 'detallado') {
            return [];
        }

        $alertas = [];
        $sinFactura = (float) ($r['ventas']['sin_factura_base'] ?? 0);
        if ($sinFactura > 0.005) {
            $alertas[] = [
                'tipo'  => 'advertencia',
                'texto' => 'Hay ' . number_format((int) $r['ventas']['sin_factura_operaciones']) . ' venta(s) sin facturar por '
                    . \formatoMoneda($sinFactura) . '.',
            ];
        }

        $mas60 = (float) ($r['antiguedad']['mas_60'] ?? 0);
        if ($mas60 > 0.005) {
            $alertas[] = [
                'tipo'  => 'advertencia',
                'texto' => 'Hay ' . \formatoMoneda($mas60) . ' de deuda con proveedores con más de 60 días.',
            ];
        }

        $resultado = $r['balance']['resultado'];
        if ($resultado !== null && (float) $resultado < 0) {
            $alertas[] = [
                'tipo'  => 'error',
                'texto' => 'El resultado del periodo es negativo: ' . \formatoMoneda($resultado) . '.',
            ];
        }

        if (!empty($r['balance']['restar_gastos']) && (float) $r['gastos']['base'] > max(0.0, (float) $resultado)) {
            $alertas[] = [
                'tipo'  => 'advertencia',
                'texto' => 'Los gastos operativos pendientes (' . \formatoMoneda($r['gastos']['base'])
                    . ') superan el resultado del periodo.',
            ];
        }

        $bajos = count(array_filter($r['inventario']['filas'], static fn ($f) => !empty($f['bajo_minimo'])));
        if ($bajos > 0) {
            $alertas[] = [
                'tipo'  => 'advertencia',
                'texto' => 'Hay ' . number_format($bajos) . ' producto(s) en o por debajo de su stock mínimo.',
            ];
        }

        if ((float) ($r['cobros']['pct_cobrado'] ?? 100) < 70 && (float) $r['ventas']['con_iva'] > 0) {
            $alertas[] = [
                'tipo'  => 'advertencia',
                'texto' => 'Solo se ha cobrado el ' . number_format((float) $r['cobros']['pct_cobrado'], 1)
                    . ' % de las ventas del mes.',
            ];
        }

        if ((float) ($r['balance']['solidez'] ?? 100) < 56) {
            $alertas[] = [
                'tipo'  => 'advertencia',
                'texto' => 'La posición financiera está ajustada: conviene revisar deudas y gastos.',
            ];
        }

        return $alertas;
    }

    /** Etiqueta y clase del medidor según la solidez. */
    private static function estado(float $solidez): array
    {
        if ($solidez < 36) {
            return ['etiqueta' => 'En riesgo', 'clase' => 'badge--error'];
        }
        if ($solidez < 56) {
            return ['etiqueta' => 'Ajustado', 'clase' => 'badge--pendiente'];
        }
        if ($solidez < 76) {
            return ['etiqueta' => 'Saludable', 'clase' => 'badge--info'];
        }
        return ['etiqueta' => 'Muy saludable', 'clase' => 'badge--aprobado'];
    }

    /**
     * Medidor con la aguja dibujada para el PDF.
     *
     * Dompdf no soporta la rotación CSS/SVG que usa la pantalla, así que la
     * aguja se pinta con GD sobre la misma imagen y se entrega como data URI
     * (Dompdf no sale a Internet y no depende de archivos externos).
     */
    public static function medidorDataUri(float $angulo): string
    {
        $ruta = __DIR__ . '/../public/image/analisis/medidor.webp';
        if (!is_file($ruta) || !function_exists('imagecreatefromstring')) {
            return '';
        }

        $bin = @file_get_contents($ruta);
        $im = $bin !== false ? @imagecreatefromstring($bin) : false;
        if (!$im) {
            return '';
        }

        $ancho = imagesx($im);
        $alto  = imagesy($im);
        $escala = $ancho / 740;
        $pivoteX = 380 * $escala;
        $pivoteY = 386 * ($alto / 493);

        $rad = deg2rad($angulo);
        $dirX = sin($rad);
        $dirY = -cos($rad);
        $perpX = -$dirY;
        $perpY = $dirX;

        $largo = 210 * $escala;
        $semi  = 9 * $escala;
        $base  = 10 * $escala;

        $puntos = [
            (int) round($pivoteX + $dirX * $base + $perpX * $semi),
            (int) round($pivoteY + $dirY * $base + $perpY * $semi),
            (int) round($pivoteX + $dirX * $largo),
            (int) round($pivoteY + $dirY * $largo),
            (int) round($pivoteX + $dirX * $base - $perpX * $semi),
            (int) round($pivoteY + $dirY * $base - $perpY * $semi),
        ];

        $color = imagecolorallocate($im, 47, 53, 66);
        imagefilledpolygon($im, $puntos, $color);

        $diametro = (int) round(36 * $escala);
        imagefilledellipse($im, (int) round($pivoteX), (int) round($pivoteY), $diametro, $diametro, $color);

        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png !== '' ? 'data:image/png;base64,' . base64_encode($png) : '';
    }

    /**
     * Medidor2 vectorial para el modo detallado.
     *
     * El SVG base vive en public/image/analisis/medidor2.svg. Aquí se le
     * inserta la aguja en el ángulo calculado y se entrega como data URI:
     * sirve igual para la pantalla (nítido en cualquier tamaño) y para Dompdf.
     */
    public static function medidor2DataUri(float $angulo): string
    {
        $ruta = __DIR__ . '/../public/image/analisis/medidor2.svg';
        if (!is_file($ruta)) {
            return '';
        }

        $svg = @file_get_contents($ruta);
        if ($svg === false || $svg === '') {
            return '';
        }

        $angulo = number_format($angulo, 2, '.', '');
        $aguja = '<g transform="rotate(' . $angulo . ' 300 300)">'
            . '<line x1="300" y1="300" x2="300" y2="100" stroke="#0b0d0e" stroke-width="9" stroke-linecap="round"/>'
            . '<line x1="300" y1="300" x2="300" y2="100" stroke="#4b5563" stroke-width="2" stroke-linecap="round"/>'
            . '<circle cx="300" cy="300" r="18" fill="#e5e7eb" stroke="#6b7280" stroke-width="3"/>'
            . '<circle cx="300" cy="300" r="7" fill="#9ca3af"/>'
            . '</g>';

        $svg = str_replace('<!-- AGUJA -->', $aguja, $svg);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
