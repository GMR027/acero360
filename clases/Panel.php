<?php

namespace App;

/**
 * Panel — Datos del panel principal (centro de control operativo).
 *
 * Solo lee: cada cifra se calcula en vivo sobre los registros de origen y trae el enlace al listado que
 * contiene exactamente esos registros (mismos criterios que los filtros de esos listados).
 *
 * Criterios:
 *   - Ventas y gastos del periodo: registros vigentes cuya fecha cae en el periodo elegido.
 *   - Pendientes (cotizaciones, cobro, facturación, gastos por pagar, entregas, incidencias): todo lo
 *     abierto a hoy, sin importar la fecha.
 *   - «Pendiente de facturación» = venta vigente sin indicar si se factura (igual que el Centro de seguimiento).
 *   - «Ventas pendientes de pago o por facturar» = UNA sola lista con la unión de las dos: el ingreso
 *     vigente con saldo por cobrar y/o sin clasificar en facturación; cada fila trae los dos avisos.
 *   - «Gastos pendientes por pagar» = gastos vigentes con estado_pago «pendiente», con su total CON IVA.
 *   - «Incidencias abiertas» = entrega_incidencias en estado «abierta» de las ventas todavía registradas.
 *   - «Devoluciones»: movimientos de inventario de devolución de cliente. La plataforma no maneja cambios
 *     de producto ni un estado «pendiente» de la devolución, así que se muestran las registradas.
 *   - «Productos más vendidos»: importe de renglones (sin IVA) del periodo que se le pida; el panel usa
 *     el mes elegido en el filtro y, si no hay mes elegido, el mes en curso.
 *   - «Existencias en alerta»: productos activos con stock negativo, agotado (0) o en/bajo su mínimo;
 *     los negativos son los más urgentes y salen primero.
 */
class Panel
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    private static function uno(string $sql, string $tipos = '', array $valores = []): array
    {
        $consulta = self::$db->prepare($sql);
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc() ?: [];
        $consulta->close();

        return $fila;
    }

    private static function varias(string $sql, string $tipos = '', array $valores = []): array
    {
        $consulta = self::$db->prepare($sql);
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Condición y parámetros de un rango de fechas sobre $campo ('' = sin límite). */
    private static function rango(string $campo, string $desde, string $hasta): array
    {
        $donde = '1 = 1';
        $tipos = '';
        $valores = [];
        if ($desde !== '') {
            $donde .= " AND $campo >= ?";
            $tipos .= 's';
            $valores[] = $desde;
        }
        if ($hasta !== '') {
            $donde .= " AND $campo <= ?";
            $tipos .= 's';
            $valores[] = $hasta;
        }

        return [$donde, $tipos, $valores];
    }

    /** Parte de consulta con el total cobrado (vigente) de cada ingreso. */
    private const COBRADO = "LEFT JOIN (SELECT ingreso_id, SUM(ROUND(importe * 100)) AS cobrado FROM cobros WHERE estado = 'vigente' GROUP BY ingreso_id) k ON k.ingreso_id = i.id";

    /**
     * Indicadores del panel. Cada uno: n (cantidad), monto (importe o null) y enlace.
     *
     * @return array<string, array{n: int, monto: ?float, enlace: string}>
     */
    public static function indicadores(string $desde, string $hasta, int $usuarioId): array
    {
        $fechas = http_build_query(array_filter(['desde' => $desde, 'hasta' => $hasta]));
        $enPeriodo = $fechas !== '' ? $fechas : 'todos=1';

        [$d, $t, $v] = self::rango('v.fecha', $desde, $hasta);
        $ventas = self::uno("SELECT COUNT(*) AS n, COALESCE(SUM(v.total), 0) AS monto FROM ventas v WHERE v.estado = 'registrada' AND $d", $t, $v);

        $cotizaciones = self::uno("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS monto FROM cotizaciones WHERE estado = 'pendiente'");

        $porCobrar = self::uno(
            'SELECT COUNT(*) AS n, COALESCE(SUM(ROUND(i.total * 100) - COALESCE(k.cobrado, 0)), 0) AS saldo FROM ingresos i ' . self::COBRADO
            . " WHERE i.estado = 'vigente' AND ROUND(i.total * 100) > COALESCE(k.cobrado, 0)"
        );

        $porFacturar = self::uno("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS monto FROM ingresos WHERE estado = 'vigente' AND estado_facturacion = 'sin_clasificar'");

        [$d, $t, $v] = self::rango('m.creado_en', $desde, $hasta);
        $devoluciones = self::uno(
            "SELECT COUNT(DISTINCT m.venta_id) AS n FROM movimientos_inventario m WHERE m.motivo = 'devolucion' AND m.venta_id IS NOT NULL AND " . str_replace('m.creado_en', 'DATE(m.creado_en)', $d),
            $t,
            $v
        );

        [$d, $t, $v] = self::rango('g.fecha', $desde, $hasta);
        $gastos = self::uno("SELECT COUNT(*) AS n, COALESCE(SUM(g.total), 0) AS monto FROM gastos g WHERE g.estado = 'vigente' AND $d", $t, $v);

        return [
            'ventas'        => ['n' => (int) $ventas['n'], 'monto' => (float) $ventas['monto'], 'enlace' => '/admin/ventas' . ($fechas !== '' ? '?' . $fechas : '')],
            'cotizaciones'  => ['n' => (int) $cotizaciones['n'], 'monto' => (float) $cotizaciones['monto'], 'enlace' => '/admin/cotizaciones?estado=pendiente'],
            'cobrar'        => ['n' => (int) $porCobrar['n'], 'monto' => (float) $porCobrar['saldo'] / 100, 'enlace' => '/admin/contabilidad/ingresos?cobro=pendiente&todos=1'],
            'facturar'      => ['n' => (int) $porFacturar['n'], 'monto' => (float) $porFacturar['monto'], 'enlace' => '/admin/contabilidad/ingresos?facturacion=sin_clasificar&todos=1'],
            'entregas'      => ['n' => Entregas::contarVentasPendientes([]), 'monto' => null, 'enlace' => '/admin/entregas'],
            'devoluciones'  => ['n' => (int) $devoluciones['n'], 'monto' => null, 'enlace' => '/admin/inventario/movimientos?motivo=devolucion' . ($fechas !== '' ? '&' . $fechas : '')],
            'gastos'        => ['n' => (int) $gastos['n'], 'monto' => (float) $gastos['monto'], 'enlace' => '/admin/contabilidad/gastos?' . $enPeriodo],
            'notificaciones' => ['n' => Seguimiento::contarNoLeidas($usuarioId), 'monto' => null, 'enlace' => '/admin/seguimiento?ver=nuevos'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function ultimasCotizaciones(int $limite = 6): array
    {
        return self::varias(
            "SELECT c.id, c.folio, c.fecha, c.total, c.estado, cl.nombre AS cliente
               FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
              ORDER BY c.fecha DESC, c.id DESC LIMIT $limite"
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function ultimasVentas(int $limite = 6): array
    {
        return self::varias(
            "SELECT v.id, IF(v.cotizacion_id IS NULL, v.folio, c.folio) AS folio, v.fecha, v.total, cl.nombre AS cliente
               FROM ventas v JOIN clientes cl ON cl.id = v.cliente_id LEFT JOIN cotizaciones c ON c.id = v.cotizacion_id
              WHERE v.estado = 'registrada' ORDER BY v.fecha DESC, v.id DESC LIMIT $limite"
        );
    }

    /** Condición SQL compartida: ingreso vigente con saldo por cobrar y/o sin clasificar en facturación. */
    private const POR_ATENDER = "(ROUND(i.total * 100) > COALESCE(k.cobrado, 0) OR i.estado_facturacion = 'sin_clasificar')";

    /**
     * Ventas pendientes de pago o por facturar, en UNA sola lista: lo que urge
     * atender primero (los dos avisos a la vez) y después lo de un solo aviso,
     * y dentro de cada grupo lo más antiguo. Cada fila trae el total CON IVA y
     * el saldo que falta cobrar; el cliente se muestra por ALIAS si lo tiene.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ventasPendientesPago(int $limite = 6): array
    {
        return self::varias(
            "SELECT i.id, i.venta_id, i.folio, i.fecha, i.total, i.estado_facturacion,
                    (ROUND(i.total * 100) - COALESCE(k.cobrado, 0)) / 100 AS saldo,
                    ROUND(i.total * 100) > COALESCE(k.cobrado, 0) AS por_cobrar,
                    i.estado_facturacion = 'sin_clasificar' AS por_facturar,
                    CASE WHEN IFNULL(cl.alias, '') <> '' THEN cl.alias ELSE cl.nombre END AS cliente
               FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id " . self::COBRADO . "
              WHERE i.estado = 'vigente' AND " . self::POR_ATENDER . "
              ORDER BY (ROUND(i.total * 100) > COALESCE(k.cobrado, 0)) + (i.estado_facturacion = 'sin_clasificar') DESC,
                       i.fecha, i.id
              LIMIT " . max(1, $limite)
        );
    }

    /** Cuántas ventas están en esa lista (los dos avisos cuentan una sola vez). */
    public static function contarVentasPendientesPago(): int
    {
        return (int) self::uno(
            'SELECT COUNT(*) AS n FROM ingresos i ' . self::COBRADO
            . " WHERE i.estado = 'vigente' AND " . self::POR_ATENDER
        )['n'];
    }

    /**
     * Gastos pendientes de pagar, los más antiguos primero: con el nombre de
     * quién cobra (beneficiario) y su total CON IVA.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function gastosPorPagar(int $limite = 6): array
    {
        return self::varias(
            'SELECT g.id, g.fecha, g.beneficiario, g.concepto, g.total
               FROM gastos g
              WHERE g.estado = \'vigente\' AND g.estado_pago = \'pendiente\'
              ORDER BY g.fecha, g.id LIMIT ' . max(1, $limite)
        );
    }

    /** Cuántos gastos y cuánto se les debe (todos, sin importar la fecha). */
    public static function resumenGastosPorPagar(): array
    {
        $f = self::uno(
            "SELECT COUNT(*) AS n, COALESCE(SUM(ROUND(g.total * 100)), 0) AS monto
               FROM gastos g WHERE g.estado = 'vigente' AND g.estado_pago = 'pendiente'"
        );

        return ['n' => (int) $f['n'], 'monto' => ((int) $f['monto']) / 100];
    }

    /**
     * Incidencias de logística o entrega todavía ABIERTAS (producto faltante,
     * dañado, incompleto, cambio o devolución), con su venta, cliente y piezas.
     * Las más recientes primero.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function incidenciasAbiertas(int $limite = 6): array
    {
        return self::varias(
            'SELECT ei.id, ei.venta_id, ei.motivo, ei.factura_requerida, ei.creado_en,
                    v.folio, v.fecha,
                    CASE WHEN IFNULL(cl.alias, \'\') <> \'\' THEN cl.alias ELSE cl.nombre END AS cliente,
                    (SELECT COUNT(*) FROM entrega_incidencia_renglones r WHERE r.incidencia_id = ei.id) AS renglones,
                    (SELECT COALESCE(SUM(r.cantidad), 0) FROM entrega_incidencia_renglones r WHERE r.incidencia_id = ei.id) AS piezas
               FROM entrega_incidencias ei
               JOIN ventas v ON v.id = ei.venta_id AND v.estado = \'registrada\'
               JOIN clientes cl ON cl.id = v.cliente_id
              WHERE ei.estado = \'abierta\'
              ORDER BY ei.creado_en DESC, ei.id DESC LIMIT ' . max(1, $limite)
        );
    }

    /** Cuántas incidencias de entrega siguen abiertas. */
    public static function contarIncidenciasAbiertas(): int
    {
        return (int) self::uno(
            "SELECT COUNT(*) AS n FROM entrega_incidencias ei JOIN ventas v ON v.id = ei.venta_id
              WHERE ei.estado = 'abierta' AND v.estado = 'registrada'"
        )['n'];
    }

    /** Ventas con piezas por entregar (las más antiguas primero). */
    public static function entregasPendientes(int $limite = 6): array
    {
        $filas = Entregas::ventasPendientes([], 200, 0);
        usort($filas, static fn ($a, $b) => [$a['fecha'], $a['id']] <=> [$b['fecha'], $b['id']]);

        return array_slice($filas, 0, $limite);
    }

    /**
     * Productos más vendidos del rango (por importe de renglones sin IVA), con su imagen:
     * piezas vendidas, número de ventas e importe total de renglones.
     *
     * @return array<int, array{producto_id: int, sku: string, nombre: string, imagen: ?string, piezas: int, ventas: int, importe: float}>
     */
    public static function productosMasVendidos(string $desde, string $hasta, int $limite = 4): array
    {
        return array_map(static fn ($x) => [
            'producto_id' => (int) $x['producto_id'], 'sku' => (string) $x['sku'], 'nombre' => (string) $x['nombre'], 'imagen' => $x['imagen'],
            'piezas' => (int) $x['piezas'], 'ventas' => (int) $x['ventas'], 'importe' => (float) $x['importe'],
        ], self::varias(
            "SELECT p.id AS producto_id, p.sku, p.nombre, p.imagen, SUM(vd.cantidad) AS piezas, COUNT(DISTINCT v.id) AS ventas,
                    ROUND(SUM(vd.subtotal * 100)) / 100 AS importe
               FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id JOIN productos p ON p.id = vd.producto_id
              WHERE v.estado = 'registrada' AND v.fecha BETWEEN ? AND ?
              GROUP BY p.id, p.sku, p.nombre, p.imagen
              ORDER BY importe DESC, piezas DESC, p.nombre ASC LIMIT " . max(1, $limite),
            'ss',
            [$desde, $hasta]
        ));
    }

    /**
     * Productos activos con existencias en alerta: NEGATIVAS primero, luego agotados (0)
     * y por último los que están en o debajo de su cantidad mínima.
     *
     * @return array<int, array{producto_id: int, sku: string, nombre: string, imagen: ?string, stock: int, stock_minimo: ?int, estado: string}>
     */
    public static function productosStockBajo(int $limite = 8): array
    {
        $filas = self::varias(
            'SELECT p.id AS producto_id, p.sku, p.nombre, p.imagen, p.stock, p.stock_minimo
               FROM productos p
              WHERE p.activo = 1 AND (p.stock <= 0 OR (p.stock_minimo IS NOT NULL AND p.stock <= p.stock_minimo))
              ORDER BY (p.stock < 0) DESC, (p.stock = 0) DESC, p.stock ASC, p.nombre ASC LIMIT ' . max(1, $limite)
        );

        return array_map(static fn ($x) => [
            'producto_id' => (int) $x['producto_id'], 'sku' => (string) $x['sku'], 'nombre' => (string) $x['nombre'], 'imagen' => $x['imagen'],
            'stock' => (int) $x['stock'], 'stock_minimo' => $x['stock_minimo'] === null ? null : (int) $x['stock_minimo'],
            'estado' => (int) $x['stock'] < 0 ? 'negativo' : ((int) $x['stock'] === 0 ? 'agotado' : 'bajo'),
        ], $filas);
    }

    /** Cuántos productos activos tienen existencias en alerta (negativas, 0 o bajo el mínimo). */
    public static function contarStockBajo(): int
    {
        return (int) self::uno(
            'SELECT COUNT(*) AS n FROM productos p
              WHERE p.activo = 1 AND (p.stock <= 0 OR (p.stock_minimo IS NOT NULL AND p.stock <= p.stock_minimo))'
        )['n'];
    }
}
