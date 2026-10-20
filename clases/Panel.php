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
 *   - Pendientes (cotizaciones, cobro, facturación, entregas): todo lo abierto a hoy, sin importar la fecha.
 *   - «Pendiente de facturación» = venta vigente sin indicar si se factura (igual que el Centro de seguimiento).
 *   - «Devoluciones»: movimientos de inventario de devolución de cliente. La plataforma no maneja cambios
 *     de producto ni un estado «pendiente» de la devolución, así que se muestran las registradas.
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

    /** Ventas con saldo por cobrar, las más antiguas primero (las que más urge cobrar). */
    public static function ventasPorCobrar(int $limite = 6): array
    {
        return self::varias(
            "SELECT i.id, i.folio, i.fecha, cl.nombre AS cliente, (ROUND(i.total * 100) - COALESCE(k.cobrado, 0)) / 100 AS saldo
               FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id " . self::COBRADO . "
              WHERE i.estado = 'vigente' AND ROUND(i.total * 100) > COALESCE(k.cobrado, 0)
              ORDER BY i.fecha, i.id LIMIT $limite"
        );
    }

    /** Ventas sin indicar si se facturan, las más antiguas primero. */
    public static function ventasPorFacturar(int $limite = 6): array
    {
        return self::varias(
            "SELECT i.id, i.folio, i.fecha, i.total, cl.nombre AS cliente
               FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id
              WHERE i.estado = 'vigente' AND i.estado_facturacion = 'sin_clasificar'
              ORDER BY i.fecha, i.id LIMIT $limite"
        );
    }

    /** Cotizaciones convertidas en venta cuyo ingreso aún no está marcado como facturado. */
    public static function convertidasSinFactura(int $limite = 6): array
    {
        return self::varias(
            "SELECT i.id, c.folio, i.fecha, i.total, i.estado_facturacion, cl.nombre AS cliente
               FROM ventas v
               JOIN cotizaciones c ON c.id = v.cotizacion_id
               JOIN ingresos i ON i.venta_id = v.id AND i.estado = 'vigente'
               JOIN clientes cl ON cl.id = v.cliente_id
              WHERE v.estado = 'registrada' AND i.estado_facturacion <> 'facturada'
              ORDER BY i.fecha, i.id LIMIT $limite"
        );
    }

    public static function contarConvertidasSinFactura(): int
    {
        return (int) self::uno(
            "SELECT COUNT(*) AS n FROM ventas v JOIN ingresos i ON i.venta_id = v.id AND i.estado = 'vigente'
              WHERE v.estado = 'registrada' AND v.cotizacion_id IS NOT NULL AND i.estado_facturacion <> 'facturada'"
        )['n'];
    }

    /** Ventas con piezas por entregar (las más antiguas primero). */
    public static function entregasPendientes(int $limite = 6): array
    {
        $filas = Entregas::ventasPendientes([], 200, 0);
        usort($filas, static fn ($a, $b) => [$a['fecha'], $a['id']] <=> [$b['fecha'], $b['id']]);

        return array_slice($filas, 0, $limite);
    }

    /** Devoluciones de cliente recientes (una fila por venta y día, con las piezas devueltas). */
    public static function devolucionesRecientes(int $limite = 6): array
    {
        return self::varias(
            "SELECT m.venta_id, IF(v.cotizacion_id IS NULL, v.folio, c.folio) AS folio, DATE(m.creado_en) AS fecha, SUM(m.cantidad) AS piezas, cl.nombre AS cliente
               FROM movimientos_inventario m
               JOIN ventas v ON v.id = m.venta_id JOIN clientes cl ON cl.id = v.cliente_id LEFT JOIN cotizaciones c ON c.id = v.cotizacion_id
              WHERE m.motivo = 'devolucion'
              GROUP BY m.venta_id, folio, DATE(m.creado_en), cl.nombre
              ORDER BY MAX(m.creado_en) DESC LIMIT $limite"
        );
    }
}
