<?php

namespace App;

/**
 * Seguimiento — Centro de seguimiento (notificaciones de Acero 360).
 *
 * Los avisos NO se guardan: se calculan en vivo desde la operación (ingresos, gastos, compras, entregas, inventario)
 * y cada uno apunta a su registro de origen. Lo único que se escribe es la tabla seguimiento_lecturas
 * (qué avisos leyó cada usuario), de modo que leer un aviso jamás modifica ventas, cobros ni facturas.
 *
 * Cada aviso: clave (tipo:id), categoria, prioridad (urgente|alta|normal|informativa), titulo, detalle, fecha (de la
 * operación, o null), dias (antigüedad), enlace y huella (estado actual; si cambia, el aviso vuelve a ser nuevo).
 *
 * Estados independientes: nunca se deduce uno del otro, solo se muestran los dos. Así, facturación y cobro de una
 * venta son avisos aparte; y una compra ya enviada a contabilidad genera «Gasto por pagar» y «Compra por pagar»
 * (deducir uno del otro dejaría la deuda sin aviso si se paga el gasto desde Contabilidad sin registrar el pago
 * en Compras, donde vive el saldo real de la compra).
 *
 * Prioridad (4 niveles) calculada SOLO con fechas y estados reales; si no hay fecha de vencimiento no se inventa:
 *   urgente      producto o material sin existencias
 *   alta         cotización con vigencia vencida o que vence en ≤ 2 días (fecha + vigencia_dias);
 *                venta ya cobrada por completo y aún pendiente de facturar
 *   normal       lo demás: cobros, facturas, gastos, compras, entregas y entradas a proveedor pendientes;
 *                y las facturas de gasto con pago en parcialidades (PPD) o con el método de pago por definir
 *                (la plataforma no registra fecha de vencimiento de cobros, entregas ni compras;
 *                se muestra su antigüedad, no un vencimiento)
 *   informativa  novedades y mejoras de la plataforma
 */
class Seguimiento
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    public const CATEGORIAS = [
        'cotizacion' => ['Cotizaciones', 'request_quote'],
        'cobro'      => ['Por cobrar', 'payments'],
        'factura'    => ['Facturas por definir', 'receipt_long'],
        'gasto'      => ['Gastos', 'request_quote'],
        'compra'     => ['Compras', 'shopping_cart'],
        'entrega'    => ['Entregas', 'package_2'],
        'inventario' => ['Inventario', 'warehouse'],
        'novedad'    => ['Novedades', 'campaign'],
    ];

    public const PRIORIDADES = ['urgente' => 0, 'alta' => 1, 'normal' => 2, 'informativa' => 3];

    /** Nivel => [etiqueta, icono, clase de badge]. El texto y el icono acompañan siempre al color. */
    public const NIVELES = [
        'urgente'     => ['Urgente', 'error', 'badge--error'],
        'alta'        => ['Alta', 'priority_high', 'badge--pendiente'],
        'normal'      => ['Normal', 'schedule', 'badge--neutro'],
        'informativa' => ['Informativa', 'info', 'badge--info'],
    ];

    /** Máximo de avisos por categoría (para no cargar listas enormes). */
    private const LIMITE = 200;

    /** Novedades de la plataforma: [clave/versión, fecha, título, texto, enlace]. */
    public const NOVEDADES = [
        ['1.4.0', '2026-10-23', 'Uso del CFDI y método de pago en los gastos',
         'Al anotar un gasto con factura ya puedes capturar su Uso del CFDI (adquisición de mercancías, gastos en general…) y su método de pago: PPD en parcialidades, PUE en una sola exhibición o por definir. El centro de seguimiento avisa de las facturas con pago en parcialidades o con el método aún por definir, y desde el aviso puedes definirlo.', '/admin/contabilidad/gastos/crear'],
        ['1.3.0', '2026-10-23', 'Seguimiento de compras, proveedores y materiales',
         'El centro de seguimiento ahora también vigila el lado de las compras: órdenes por recibir, facturas de proveedor pendientes, saldos por pagar, entradas sin pagar y materias primas por debajo de su mínimo.', '/admin/compras'],
        ['1.2.3', '2026-10-21', 'Conmutadores de facturación y cobro en Ingresos',
         'En la lista de ingresos, un interruptor junto a Facturación pasa la venta de Pendiente de facturar a Facturada, y otro junto a Cobro de Sin pagar a Pagada (y de regreso). Piden confirmación; marcar como pagada registra el cobro del saldo y revertirlo cancela los cobros.', '/admin/contabilidad/ingresos'],
        ['1.2.2', '2026-10-21', 'Reporte fiscal con proveedores',
         'El reporte de ventas y gastos (PDF y Excel) separa Ventas, Gastos e Impuestos por pagar con totales por sección, y agrega Proveedores: compras y pagos del periodo y cuánto se les debe. El Excel trae una hoja por cada parte.', '/admin/fiscal/movimientos'],
        ['1.2.1', '2026-10-21', 'Cierre de compra con IVA e imagen',
         'El PDF del reporte de cierre de una orden de compra muestra subtotal, IVA y total cuando la compra lleva IVA, y la imagen de cada artículo del catálogo.', '/admin/compras'],
        ['1.2.0', '2026-10-21', 'Imagen en el catálogo del proveedor',
         'Cada artículo del catálogo del proveedor puede tener su imagen, que se ve en el catálogo y al recibir la compra para identificar lo que llega.', '/admin/compras/catalogo'],
        ['1.1.0', '2026-10-20', 'Ahora es Acero 360',
         'La plataforma estrena nombre y logotipo, un menú de Ajustes con pestañas (empresas, datos fiscales, aplicación de escritorio y versión) y este Centro de seguimiento.', '/admin/ajustes?seccion=novedades'],
    ];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /** Texto del botón que abre el registro de un aviso, según su tipo. */
    public static function etiquetaAbrir(string $clave): string
    {
        return [
            'cotizacion' => 'Abrir cotización', 'cobro' => 'Abrir venta', 'factura' => 'Abrir venta para facturar',
            'gasto_pago' => 'Abrir gasto', 'gasto_comp' => 'Abrir gasto', 'gasto_cfdi' => 'Abrir gasto',
            'compra_recibir' => 'Ver compra por recibir', 'compra_cfdi' => 'Abrir compra para facturar', 'compra_saldo' => 'Abrir compra',
            'entrada' => 'Abrir entrada', 'material' => 'Ver material',
            'entrega' => 'Ver entrega pendiente', 'cambio' => 'Entregar cambio',
            'stock' => 'Abrir producto', 'novedad' => 'Ver novedad',
        ][explode(':', $clave)[0]] ?? 'Abrir registro';
    }

    private static function dias(?string $fecha): ?int
    {
        if (!$fecha) {
            return null;
        }

        return max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime($fecha)) / 86400));
    }

    private static function nombreCliente(array $f): string
    {
        return trim((string) ($f['cliente_nombre'] ?? '')) . (!empty($f['cliente_empresa']) ? ' (' . $f['cliente_empresa'] . ')' : '');
    }

    private static function antiguedad(?int $dias): string
    {
        return $dias === null ? '' : ($dias === 0 ? 'hoy' : "hace $dias " . ($dias === 1 ? 'día' : 'días'));
    }

    /**
     * Todos los avisos vigentes, ordenados por prioridad y, a igual prioridad, del más antiguo al más reciente.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function avisos(): array
    {
        $avisos = [];
        $moneda = static fn ($n) => '$' . number_format((float) $n, 2);
        $lim = self::LIMITE;

        // Cotizaciones pendientes de respuesta del cliente.
        $cotizaciones = self::$db->query(
            "SELECT c.id, c.folio, c.fecha, c.total, c.vigencia_dias, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa
               FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
              WHERE c.estado = 'pendiente' ORDER BY c.fecha, c.id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($cotizaciones as $f) {
            $dias = self::dias($f['fecha']);
            // Vencimiento real: fecha de la cotización + sus días de vigencia.
            $restan = (int) $f['vigencia_dias'] - (int) $dias;
            $venc = $restan < 0 ? ' · vigencia vencida hace ' . (-$restan) . ($restan === -1 ? ' día' : ' días')
                : ($restan === 0 ? ' · vence hoy' : ' · vence en ' . $restan . ($restan === 1 ? ' día' : ' días'));
            $avisos[] = [
                'clave' => 'cotizacion:' . $f['id'], 'categoria' => 'cotizacion', 'prioridad' => $restan <= 2 ? 'alta' : 'normal',
                'titulo' => 'Cotización sin respuesta · ' . $f['folio'],
                'detalle' => self::nombreCliente($f) . ' · ' . $moneda($f['total']) . ' · enviada ' . self::antiguedad($dias) . $venc,
                'fecha' => $f['fecha'], 'dias' => $dias, 'enlace' => '/admin/cotizaciones/detalle?id=' . (int) $f['id'],
                'huella' => 'pendiente',
            ];
        }

        // Cobros pendientes: ventas vigentes cuyo total supera lo cobrado (incluye pagos parciales).
        $ingresos = self::$db->query(
            "SELECT i.id, i.venta_id, i.folio, i.fecha, i.total, i.iva, i.estado_facturacion, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    COALESCE(k.cobrado, 0) AS cobrado
               FROM ingresos i
               JOIN clientes cl ON cl.id = i.cliente_id
               LEFT JOIN (SELECT ingreso_id, SUM(ROUND(importe * 100)) AS cobrado FROM cobros WHERE estado = 'vigente' GROUP BY ingreso_id) k ON k.ingreso_id = i.id
              WHERE i.estado = 'vigente' AND ROUND(i.total * 100) > COALESCE(k.cobrado, 0)
              ORDER BY i.fecha, i.id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($ingresos as $f) {
            $saldoC = (int) round((float) $f['total'] * 100) - (int) $f['cobrado'];
            $dias = self::dias($f['fecha']);
            $parcial = (int) $f['cobrado'] > 0;
            $avisos[] = [
                'clave' => 'cobro:' . $f['id'], 'categoria' => 'cobro', 'prioridad' => 'normal',
                'titulo' => ($parcial ? 'Pago parcial' : 'Venta sin cobrar') . ' · ' . $f['folio'],
                'detalle' => self::nombreCliente($f) . ' · falta cobrar ' . $moneda($saldoC / 100) . ' · venta ' . self::antiguedad($dias),
                'fecha' => $f['fecha'], 'dias' => $dias, 'enlace' => Contabilidad::rutaDetalle($f),
                'huella' => 'saldo' . $saldoC, 'saldo' => $saldoC / 100,
                'metodo' => Contabilidad::metodoSugerido($f),
            ];
        }

        // Facturas por definir: solo las que nadie ha marcado como facturada ni como no facturada.
        $sin = self::$db->query(
            "SELECT i.id, i.venta_id, i.folio, i.fecha, i.total, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    COALESCE((SELECT SUM(ROUND(k.importe * 100)) FROM cobros k WHERE k.ingreso_id = i.id AND k.estado = 'vigente'), 0) AS cobrado
               FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id
              WHERE i.estado = 'vigente' AND i.estado_facturacion = 'sin_clasificar'
              ORDER BY i.fecha, i.id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($sin as $f) {
            $dias = self::dias($f['fecha']);
            $cobradaTodo = (int) $f['cobrado'] >= (int) round((float) $f['total'] * 100);
            $avisos[] = [
                'clave' => 'factura:' . $f['id'], 'categoria' => 'factura', 'prioridad' => $cobradaTodo ? 'alta' : 'normal',
                'titulo' => 'Pendiente de facturar · ' . $f['folio'],
                'detalle' => self::nombreCliente($f) . ' · ' . $moneda($f['total']) . ' · venta ' . self::antiguedad($dias)
                    . ' · cobro: ' . ($cobradaTodo ? 'ya cobrada por completo' : ((int) $f['cobrado'] > 0 ? 'pago parcial' : 'sin cobrar')) . '.',
                'fecha' => $f['fecha'], 'dias' => $dias,
                'enlace' => Contabilidad::rutaDetalle($f),
                'huella' => 'sin_clasificar' . ($cobradaTodo ? ':cobrada' : ''),
            ];
        }

        // Gastos por pagar, gastos sin comprobante fiscal y facturas con el método de pago sin resolver.
        // «Por definir» incluye los gastos antiguos guardados sin método: la factura existe y todavía
        // no se sabe si se paga de una vez (PUE) o en parcialidades (PPD).
        $gastos = self::$db->query(
            "SELECT id, fecha, beneficiario, concepto, total, estado_pago, comprobante_fiscal, metodo_pago_sat FROM gastos
              WHERE estado = 'vigente' AND (estado_pago = 'pendiente' OR comprobante_fiscal = 0 OR
                    (comprobante_fiscal = 1 AND IFNULL(metodo_pago_sat, 'POR') <> 'PUE'))
              ORDER BY fecha, id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($gastos as $g) {
            $dias = self::dias($g['fecha']);
            $base = ['categoria' => 'gasto', 'fecha' => $g['fecha'], 'dias' => $dias, 'enlace' => '/admin/contabilidad/gastos/detalle?id=' . (int) $g['id']];
            if ($g['estado_pago'] === 'pendiente') {
                $avisos[] = $base + [
                    'clave' => 'gasto_pago:' . $g['id'], 'prioridad' => 'normal',
                    'titulo' => 'Gasto por pagar · ' . $g['beneficiario'],
                    'detalle' => $g['concepto'] . ' · ' . $moneda($g['total']) . ' · gasto ' . self::antiguedad($dias),
                    'huella' => 'pendiente',
                ];
            }
            if ((int) $g['comprobante_fiscal'] === 0) {
                $avisos[] = $base + [
                    'clave' => 'gasto_comp:' . $g['id'], 'prioridad' => 'normal',
                    'titulo' => 'Gasto sin comprobante fiscal · ' . $g['beneficiario'],
                    'detalle' => $g['concepto'] . ' · ' . $moneda($g['total']) . ' · sin factura no se puede deducir.',
                    'huella' => 'sin_comprobante',
                ];
            }
            $metodo = (string) ($g['metodo_pago_sat'] ?? '');
            if ((int) $g['comprobante_fiscal'] === 1 && $metodo !== 'PUE') {
                $ppd = $metodo === 'PPD';
                $avisos[] = $base + [
                    'clave' => 'gasto_cfdi:' . $g['id'], 'prioridad' => 'normal',
                    'titulo' => ($ppd ? 'Factura con pago en parcialidades · ' : 'Factura con método de pago por definir · ') . $g['beneficiario'],
                    'detalle' => $g['concepto'] . ' · ' . $moneda($g['total']) . ' · factura emitida ' . self::antiguedad($dias)
                        . ($ppd
                            ? ' · PPD: al cobrar cada parcialidad hay que emitir su complemento de pago.'
                            : ' · define si se paga en una sola exhibición (PUE) o en parcialidades (PPD).'),
                    'huella' => 'metodo' . ($metodo ?: 'sin'), 'metodo_cfdi' => $metodo ?: 'POR',
                ];
            }
        }

        // Compras por recibir: órdenes abiertas cuyas partidas controladas no se han recibido completo.
        $compras = self::$db->query(
            "SELECT o.id, o.folio, o.fecha_solicitud, p.nombre AS proveedor_nombre,
                    COALESCE(SUM(c.cantidad_solicitada), 0) AS solicitado,
                    COALESCE((SELECT SUM(rp.cantidad_recibida) FROM recepcion_partidas rp
                                JOIN orden_compra_partidas c2 ON c2.id = rp.partida_id
                               WHERE c2.orden_id = o.id AND c2.controla = 1), 0) AS recibido
               FROM ordenes_compra o
               JOIN proveedores p ON p.id = o.proveedor_id
               LEFT JOIN orden_compra_partidas c ON c.orden_id = o.id AND c.controla = 1
              WHERE o.estado IN ('solicitada', 'parcial')
              GROUP BY o.id, o.folio, o.fecha_solicitud, p.nombre
              ORDER BY o.fecha_solicitud, o.id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($compras as $f) {
            $solicitado = (float) $f['solicitado'];
            $recibido = (float) $f['recibido'];
            if ($recibido >= $solicitado) {
                continue;   // la orden no controla existencias (servicios): nada que recibir
            }
            $faltan = Materiales::numero($solicitado - $recibido);
            $dias = self::dias($f['fecha_solicitud']);
            $avisos[] = [
                'clave' => 'compra_recibir:' . $f['id'], 'categoria' => 'compra', 'prioridad' => 'normal',
                'titulo' => 'Compra por recibir · ' . $f['folio'],
                'detalle' => $f['proveedor_nombre'] . ' · faltan ' . $faltan . ' de ' . Materiales::numero($solicitado)
                    . ' · solicitada ' . self::antiguedad($dias),
                'fecha' => $f['fecha_solicitud'], 'dias' => $dias, 'enlace' => '/admin/compras/detalle?id=' . (int) $f['id'],
                'huella' => 'faltan' . $faltan,
            ];
        }

        // Facturas de proveedor pendientes: compras que aún no tienen su CFDI del proveedor.
        $sinCfdi = self::$db->query(
            "SELECT o.id, o.folio, o.fecha_solicitud, o.total_esperado, o.iva, p.nombre AS proveedor_nombre
               FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id
              WHERE o.estado <> 'cancelada' AND o.estado_cfdi = 'pendiente_cfdi'
              ORDER BY o.fecha_solicitud, o.id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($sinCfdi as $f) {
            $dias = self::dias($f['fecha_solicitud']);
            $avisos[] = [
                'clave' => 'compra_cfdi:' . $f['id'], 'categoria' => 'compra', 'prioridad' => 'normal',
                'titulo' => 'Compra pendiente de factura · ' . $f['folio'],
                'detalle' => $f['proveedor_nombre'] . ' · ' . $moneda((float) $f['total_esperado'] + (float) $f['iva'])
                    . ' · sin el XML no se deduce ni se acredita el IVA · solicitada ' . self::antiguedad($dias),
                'fecha' => $f['fecha_solicitud'], 'dias' => $dias,
                'enlace' => '/admin/compras/detalle?id=' . (int) $f['id'] . '#factura',
                'huella' => 'pendiente_cfdi',
            ];
        }

        // Saldos por pagar a proveedores: el total de la compra supera sus pagos vigentes.
        $porPagar = self::$db->query(
            "SELECT o.id, o.folio, o.fecha_solicitud, p.nombre AS proveedor_nombre,
                    ROUND((o.total_esperado + COALESCE(o.iva, 0)) * 100) AS total,
                    COALESCE((SELECT SUM(ROUND(pc.importe * 100)) FROM pagos_compra pc
                               WHERE pc.orden_id = o.id AND pc.estado = 'vigente'), 0) AS pagado,
                    (SELECT pc.metodo FROM pagos_compra pc WHERE pc.orden_id = o.id AND pc.estado = 'vigente'
                      ORDER BY pc.id DESC LIMIT 1) AS metodo
               FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id
              WHERE o.estado <> 'cancelada'
                AND ROUND((o.total_esperado + COALESCE(o.iva, 0)) * 100) > COALESCE((SELECT SUM(ROUND(pc.importe * 100)) FROM pagos_compra pc
                     WHERE pc.orden_id = o.id AND pc.estado = 'vigente'), 0)
              ORDER BY o.fecha_solicitud, o.id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($porPagar as $f) {
            $saldo = (int) $f['total'] - (int) $f['pagado'];
            $dias = self::dias($f['fecha_solicitud']);
            $parcial = (int) $f['pagado'] > 0;
            $avisos[] = [
                'clave' => 'compra_saldo:' . $f['id'], 'categoria' => 'compra', 'prioridad' => 'normal',
                'titulo' => ($parcial ? 'Pago parcial a proveedor' : 'Compra por pagar') . ' · ' . $f['folio'],
                'detalle' => $f['proveedor_nombre'] . ' · falta pagar ' . $moneda($saldo / 100) . ' · compra ' . self::antiguedad($dias),
                'fecha' => $f['fecha_solicitud'], 'dias' => $dias,
                'enlace' => '/admin/compras/detalle?id=' . (int) $f['id'] . '#pagos',
                'huella' => 'saldo' . $saldo, 'saldo' => $saldo / 100,
                'metodo' => in_array((string) $f['metodo'], Contabilidad::METODOS_PAGO, true) ? (string) $f['metodo'] : null,
            ];
        }

        // Entradas a proveedor (pedidos directos, sin orden de compra) que siguen sin pagar.
        $entradas = self::$db->query(
            "SELECT e.id, e.fecha, e.numero_pedido, e.monto, p.nombre AS proveedor_nombre
               FROM proveedor_entradas e JOIN proveedores p ON p.id = e.proveedor_id
              WHERE e.pagado = 0
              ORDER BY e.fecha, e.id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($entradas as $f) {
            $dias = self::dias($f['fecha']);
            $avisos[] = [
                'clave' => 'entrada:' . $f['id'], 'categoria' => 'compra', 'prioridad' => 'normal',
                'titulo' => 'Entrada a proveedor por pagar · ' . trim((string) ($f['numero_pedido'] ?: $f['proveedor_nombre'])),
                'detalle' => $f['proveedor_nombre'] . ' · ' . $moneda((float) $f['monto']) . ' · entrada ' . self::antiguedad($dias),
                'fecha' => $f['fecha'], 'dias' => $dias, 'enlace' => '/admin/proveedores/entradas/detalle?id=' . (int) $f['id'],
                'huella' => 'pendiente',
            ];
        }

        // Entregas pendientes (ventas registradas con piezas por entregar y entrega no cerrada).
        foreach (Entregas::ventasPendientes([], $lim, 0) as $v) {
            $faltan = (int) $v['piezas'] - (int) $v['entregadas'];
            $dias = self::dias($v['fecha']);
            $avisos[] = [
                'clave' => 'entrega:' . $v['id'], 'categoria' => 'entrega', 'prioridad' => 'normal',
                'titulo' => 'Entrega pendiente · ' . $v['folio'],
                'detalle' => self::nombreCliente($v) . ' · faltan ' . $faltan . ' pieza' . ($faltan === 1 ? '' : 's') . ' · venta ' . self::antiguedad($dias),
                'fecha' => $v['fecha'], 'dias' => $dias, 'enlace' => '/admin/ventas/detalle?id=' . (int) $v['id'],
                'huella' => 'faltan' . $faltan,
            ];
        }

        // Cambios por entregar: piezas devueltas que el cliente debe recibir de nuevo.
        foreach (Inventario::ventasConCambiosPendientes($lim) as $v) {
            $dias = self::dias($v['fecha']);
            $avisos[] = [
                'clave' => 'cambio:' . $v['id'], 'categoria' => 'entrega', 'prioridad' => 'normal',
                'titulo' => 'Cambio por entregar · ' . $v['folio'],
                'detalle' => self::nombreCliente($v) . ' · se deben ' . (int) $v['pendiente'] . ' pieza' . ((int) $v['pendiente'] === 1 ? '' : 's') . ' · venta ' . self::antiguedad($dias),
                'fecha' => $v['fecha'], 'dias' => $dias, 'enlace' => '/admin/ventas/detalle?id=' . (int) $v['id'] . '#cambios',
                'huella' => 'debe' . (int) $v['pendiente'],
            ];
        }

        // Existencias en alerta: negativas o agotadas, y las que llegan a su mínimo.
        // El criterio es el mismo que Panel::productosStockBajo: un producto sin mínimo definido
        // pero ya agotado (o en negativo) también es urgente, porque no tiene red de seguridad.
        $productos = self::$db->query(
            "SELECT id, nombre, stock, stock_minimo FROM productos
              WHERE activo = 1 AND (stock <= 0 OR (stock_minimo IS NOT NULL AND stock <= stock_minimo))
              ORDER BY (stock < 0) DESC, (stock = 0) DESC, stock, id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($productos as $p) {
            $avisos[] = [
                'clave' => 'stock:' . $p['id'], 'categoria' => 'inventario', 'prioridad' => (int) $p['stock'] <= 0 ? 'urgente' : 'normal',
                'titulo' => 'Stock bajo · ' . $p['nombre'],
                'detalle' => $p['stock_minimo'] === null
                    ? 'Quedan ' . (int) $p['stock'] . ' y no tiene mínimo definido.'
                    : 'Hay ' . (int) $p['stock'] . ' y el mínimo es ' . (int) $p['stock_minimo'] . '.',
                'fecha' => null, 'dias' => null, 'enlace' => '/admin/productos/editar?id=' . (int) $p['id'],
                'huella' => 'stock' . (int) $p['stock'],
            ];
        }

        // Materias primas por debajo de su mínimo (existencias en materiales, separadas del catálogo de venta).
        $materiales = self::$db->query(
            "SELECT id, nombre, existencia, minimo, unidad FROM materiales
              WHERE activo = 1 AND minimo IS NOT NULL AND existencia <= minimo
              ORDER BY (existencia <= 0) DESC, existencia, id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($materiales as $m) {
            $avisos[] = [
                'clave' => 'material:' . $m['id'], 'categoria' => 'inventario', 'prioridad' => (float) $m['existencia'] <= 0 ? 'urgente' : 'normal',
                'titulo' => 'Material bajo el mínimo · ' . $m['nombre'],
                'detalle' => 'Hay ' . Materiales::numero($m['existencia']) . ' ' . $m['unidad']
                    . ' y el mínimo es ' . Materiales::numero($m['minimo']) . '.',
                'fecha' => null, 'dias' => null, 'enlace' => '/admin/compras/recepciones#materiales',
                'huella' => 'existencia' . rtrim(rtrim(number_format((float) $m['existencia'], 3, '.', ''), '0'), '.'),
            ];
        }

        foreach (self::NOVEDADES as [$version, $fecha, $titulo, $texto, $enlace]) {
            $avisos[] = [
                'clave' => 'novedad:' . $version, 'categoria' => 'novedad', 'prioridad' => 'informativa', 'titulo' => $titulo, 'detalle' => $texto,
                'fecha' => $fecha, 'dias' => self::dias($fecha), 'enlace' => $enlace, 'huella' => 'v' . $version,
            ];
        }

        usort($avisos, static function (array $a, array $b): int {
            return [self::PRIORIDADES[$a['prioridad']], -($a['dias'] ?? -1), $a['clave']]
                <=> [self::PRIORIDADES[$b['prioridad']], -($b['dias'] ?? -1), $b['clave']];
        });

        return $avisos;
    }

    /** @return array<string, string> clave => huella leída por el usuario. */
    private static function lecturas(int $usuarioId): array
    {
        $consulta = self::$db->prepare('SELECT clave, huella FROM seguimiento_lecturas WHERE usuario_id = ?');
        $consulta->bind_param('i', $usuarioId);
        $consulta->execute();
        $lecturas = array_column($consulta->get_result()->fetch_all(MYSQLI_ASSOC), 'huella', 'clave');
        $consulta->close();

        return $lecturas;
    }

    /** Avisos con el campo 'leida' (true si el usuario ya lo leyó y la operación no cambió desde entonces). */
    public static function paraUsuario(int $usuarioId): array
    {
        $lecturas = self::lecturas($usuarioId);
        $avisos = self::avisos();
        foreach ($avisos as &$aviso) {
            $aviso['leida'] = ($lecturas[$aviso['clave']] ?? null) === $aviso['huella'];
        }
        unset($aviso);

        return $avisos;
    }

    /** Número de avisos sin leer (con caché de 60 s en la sesión, que se invalida al marcar). */
    public static function contarNoLeidas(int $usuarioId): int
    {
        if (isset($_SESSION['seguimiento_n'], $_SESSION['seguimiento_t']) && time() - $_SESSION['seguimiento_t'] < 60) {
            return (int) $_SESSION['seguimiento_n'];
        }
        $n = count(array_filter(self::paraUsuario($usuarioId), static fn ($a) => !$a['leida']));
        $_SESSION['seguimiento_n'] = $n;
        $_SESSION['seguimiento_t'] = time();

        return $n;
    }

    /**
     * Marca como leído un aviso (o todos si $clave es null). Solo escribe en seguimiento_lecturas.
     * La huella se toma del estado actual del servidor, nunca del navegador.
     */
    public static function marcarLeida(int $usuarioId, ?string $clave): int
    {
        $marcadas = 0;
        $consulta = self::$db->prepare(
            'INSERT INTO seguimiento_lecturas (usuario_id, clave, huella) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE huella = VALUES(huella), leida_en = CURRENT_TIMESTAMP'
        );
        foreach (self::avisos() as $aviso) {
            if ($clave !== null && $aviso['clave'] !== $clave) {
                continue;
            }
            $consulta->bind_param('iss', $usuarioId, $aviso['clave'], $aviso['huella']);
            $consulta->execute();
            $marcadas++;
        }
        $consulta->close();
        unset($_SESSION['seguimiento_n'], $_SESSION['seguimiento_t']);

        return $marcadas;
    }
}
