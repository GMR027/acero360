<?php

namespace App;

/**
 * Seguimiento — Centro de seguimiento (notificaciones de Acero 360).
 *
 * Los avisos NO se guardan: se calculan en vivo desde la operación (ingresos, gastos, entregas, inventario)
 * y cada uno apunta a su registro de origen. Lo único que se escribe es la tabla seguimiento_lecturas
 * (qué avisos leyó cada usuario), de modo que leer un aviso jamás modifica ventas, cobros ni facturas.
 *
 * Cada aviso: clave (tipo:id), categoria, prioridad (alta|media|baja), titulo, detalle, fecha (de la
 * operación, o null), dias (antigüedad), enlace y huella (estado actual; si cambia, el aviso vuelve a ser nuevo).
 *
 * Prioridad según la fecha disponible de la operación (antigüedad en días):
 *   cotización pendiente  supera su vigencia alta · ≥ 7 media · si no baja
 *   cobro pendiente   ≥ 30 alta · ≥ 15 media · si no baja
 *   factura por definir ≥ 30 alta · ≥ 10 media · si no baja
 *   gasto por pagar   ≥ 30 alta · ≥ 15 media · si no baja
 *   gasto sin comprobante ≥ 30 media · si no baja
 *   entrega pendiente ≥ 14 alta · ≥ 7 media · si no baja
 *   stock mínimo      sin existencias alta · si no media (no hay fecha)
 *   novedad           baja (no hay fecha de operación)
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
        'entrega'    => ['Entregas', 'package_2'],
        'inventario' => ['Inventario', 'warehouse'],
        'novedad'    => ['Novedades', 'campaign'],
    ];

    public const PRIORIDADES = ['alta' => 0, 'media' => 1, 'baja' => 2];

    /** Máximo de avisos por categoría (para no cargar listas enormes). */
    private const LIMITE = 200;

    /** Novedades de la plataforma: [clave/versión, fecha, título, texto, enlace]. */
    public const NOVEDADES = [
        ['1.1.0', '2026-10-20', 'Ahora es Acero 360',
         'La plataforma estrena nombre y logotipo, un menú de Ajustes con pestañas (empresas, datos fiscales, aplicación de escritorio y versión) y este Centro de seguimiento.', '/admin/ajustes?seccion=novedades'],
    ];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    private static function prioridad(?int $dias, int $alta, ?int $media): string
    {
        if ($dias === null) {
            return 'baja';
        }

        return $dias >= $alta ? 'alta' : (($media !== null && $dias >= $media) ? 'media' : 'baja');
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
            $vigente = $dias !== null && $dias <= (int) $f['vigencia_dias'];
            $avisos[] = [
                'clave' => 'cotizacion:' . $f['id'], 'categoria' => 'cotizacion', 'prioridad' => self::prioridad($dias, max(1, (int) $f['vigencia_dias']) + 1, 7),
                'titulo' => 'Cotización sin respuesta · ' . $f['folio'],
                'detalle' => self::nombreCliente($f) . ' · ' . $moneda($f['total']) . ' · enviada ' . self::antiguedad($dias) . ($vigente ? '' : ' · vigencia vencida'),
                'fecha' => $f['fecha'], 'dias' => $dias, 'enlace' => '/admin/cotizaciones/detalle?id=' . (int) $f['id'],
                'huella' => 'pendiente',
            ];
        }

        // Cobros pendientes: ventas vigentes cuyo total supera lo cobrado (incluye pagos parciales).
        $ingresos = self::$db->query(
            "SELECT i.id, i.folio, i.fecha, i.total, i.iva, i.estado_facturacion, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
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
                'clave' => 'cobro:' . $f['id'], 'categoria' => 'cobro', 'prioridad' => self::prioridad($dias, 30, 15),
                'titulo' => ($parcial ? 'Pago parcial' : 'Venta sin cobrar') . ' · ' . $f['folio'],
                'detalle' => self::nombreCliente($f) . ' · falta cobrar ' . $moneda($saldoC / 100) . ' · venta ' . self::antiguedad($dias),
                'fecha' => $f['fecha'], 'dias' => $dias, 'enlace' => '/admin/contabilidad/ingresos/detalle?id=' . (int) $f['id'],
                'huella' => 'saldo' . $saldoC, 'saldo' => $saldoC / 100,
                'metodo' => Contabilidad::metodoSugerido($f),
            ];
        }

        // Facturas por definir: solo las que nadie ha marcado como facturada ni como no facturada.
        $sin = self::$db->query(
            "SELECT i.id, i.folio, i.fecha, i.total, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa
               FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id
              WHERE i.estado = 'vigente' AND i.estado_facturacion = 'sin_clasificar'
              ORDER BY i.fecha, i.id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($sin as $f) {
            $dias = self::dias($f['fecha']);
            $avisos[] = [
                'clave' => 'factura:' . $f['id'], 'categoria' => 'factura', 'prioridad' => self::prioridad($dias, 30, 10),
                'titulo' => 'Falta indicar la factura · ' . $f['folio'],
                'detalle' => self::nombreCliente($f) . ' · ' . $moneda($f['total']) . ' · venta ' . self::antiguedad($dias) . '. Indica si ya está facturada o no se facturará.',
                'fecha' => $f['fecha'], 'dias' => $dias, 'enlace' => '/admin/contabilidad/ingresos/detalle?id=' . (int) $f['id'],
                'huella' => 'sin_clasificar',
            ];
        }

        // Gastos por pagar y gastos sin comprobante fiscal.
        $gastos = self::$db->query(
            "SELECT id, fecha, beneficiario, concepto, total, estado_pago, comprobante_fiscal FROM gastos
              WHERE estado = 'vigente' AND (estado_pago = 'pendiente' OR comprobante_fiscal = 0)
              ORDER BY fecha, id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($gastos as $g) {
            $dias = self::dias($g['fecha']);
            $base = ['categoria' => 'gasto', 'fecha' => $g['fecha'], 'dias' => $dias, 'enlace' => '/admin/contabilidad/gastos/detalle?id=' . (int) $g['id']];
            if ($g['estado_pago'] === 'pendiente') {
                $avisos[] = $base + [
                    'clave' => 'gasto_pago:' . $g['id'], 'prioridad' => self::prioridad($dias, 30, 15),
                    'titulo' => 'Gasto por pagar · ' . $g['beneficiario'],
                    'detalle' => $g['concepto'] . ' · ' . $moneda($g['total']) . ' · gasto ' . self::antiguedad($dias),
                    'huella' => 'pendiente',
                ];
            }
            if ((int) $g['comprobante_fiscal'] === 0) {
                $avisos[] = $base + [
                    'clave' => 'gasto_comp:' . $g['id'], 'prioridad' => self::prioridad($dias, 30, null) === 'alta' ? 'media' : 'baja',
                    'titulo' => 'Gasto sin comprobante fiscal · ' . $g['beneficiario'],
                    'detalle' => $g['concepto'] . ' · ' . $moneda($g['total']) . ' · sin factura no se puede deducir.',
                    'huella' => 'sin_comprobante',
                ];
            }
        }

        // Entregas pendientes (ventas registradas con piezas por entregar y entrega no cerrada).
        foreach (Entregas::ventasPendientes([], $lim, 0) as $v) {
            $faltan = (int) $v['piezas'] - (int) $v['entregadas'];
            $dias = self::dias($v['fecha']);
            $avisos[] = [
                'clave' => 'entrega:' . $v['id'], 'categoria' => 'entrega', 'prioridad' => self::prioridad($dias, 14, 7),
                'titulo' => 'Entrega pendiente · ' . $v['folio'],
                'detalle' => self::nombreCliente($v) . ' · faltan ' . $faltan . ' pieza' . ($faltan === 1 ? '' : 's') . ' · venta ' . self::antiguedad($dias),
                'fecha' => $v['fecha'], 'dias' => $dias, 'enlace' => '/admin/ventas/detalle?id=' . (int) $v['id'],
                'huella' => 'faltan' . $faltan,
            ];
        }

        // Productos en o por debajo de su stock mínimo.
        $productos = self::$db->query(
            "SELECT id, nombre, stock, stock_minimo FROM productos
              WHERE activo = 1 AND stock_minimo IS NOT NULL AND stock <= stock_minimo ORDER BY stock, id LIMIT $lim"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($productos as $p) {
            $avisos[] = [
                'clave' => 'stock:' . $p['id'], 'categoria' => 'inventario', 'prioridad' => (int) $p['stock'] <= 0 ? 'alta' : 'media',
                'titulo' => 'Stock bajo · ' . $p['nombre'],
                'detalle' => 'Hay ' . (int) $p['stock'] . ' y el mínimo es ' . (int) $p['stock_minimo'] . '.',
                'fecha' => null, 'dias' => null, 'enlace' => '/admin/productos/editar?id=' . (int) $p['id'],
                'huella' => 'stock' . (int) $p['stock'],
            ];
        }

        foreach (self::NOVEDADES as [$version, $fecha, $titulo, $texto, $enlace]) {
            $avisos[] = [
                'clave' => 'novedad:' . $version, 'categoria' => 'novedad', 'prioridad' => 'baja', 'titulo' => $titulo, 'detalle' => $texto,
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
