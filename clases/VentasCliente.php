<?php

namespace App;

/**
 * VentasCliente — reporte de compras de UN cliente en un periodo.
 *
 * Sólo lectura. La fuente es la tabla unificada `ventas` (ventas directas y
 * cotizaciones marcadas como vendidas) y sus renglones en `venta_detalles`.
 * Las ventas canceladas no se incluyen.
 *
 * Se usa en la ficha del cliente (pestaña «Compras») y en su PDF.
 */
class VentasCliente
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

    /** Nombre del mes en español (1 = enero). */
    public static function nombreMes(int $numero): string
    {
        $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        return $meses[$numero] ?? '';
    }

    /**
     * Normaliza los filtros de periodo que llegan por la URL.
     *
     * @param array<string, mixed> $consulta $_GET
     * @return array{periodo: string, anio: int, mes: int, desde: string, hasta: string, etiqueta: string}
     */
    public static function normalizarPeriodo(array $consulta, ?string $hoy = null): array
    {
        $hoy = $hoy ?: date('Y-m-d');
        $periodo = (string) ($consulta['periodo'] ?? 'mes');
        if (!in_array($periodo, ['mes', 'anio', 'rango', 'todo'], true)) {
            $periodo = 'mes';
        }

        $anio = (int) ($consulta['anio'] ?? date('Y'));
        $mes  = (int) ($consulta['mes'] ?? date('n'));
        $anio = max(2000, min(2100, $anio));
        $mes  = max(1, min(12, $mes));

        $desde = (string) ($consulta['desde'] ?? '');
        $hasta = (string) ($consulta['hasta'] ?? '');

        if ($periodo === 'mes') {
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-t', strtotime($desde));
        } elseif ($periodo === 'anio') {
            $desde = sprintf('%04d-01-01', $anio);
            $hasta = sprintf('%04d-12-31', $anio);
        } elseif ($periodo === 'todo') {
            $desde = '2000-01-01';
            $hasta = $hoy;
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
                $desde = sprintf('%04d-%02d-01', $anio, $mes);
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
                $hasta = date('Y-m-t', strtotime($desde));
            }
            if ($desde > $hasta) {
                $cambio = $desde;
                $desde = $hasta;
                $hasta = $cambio;
            }
            $periodo = 'rango';
        }

        if ($periodo === 'mes') {
            $etiqueta = self::nombreMes($mes) . ' ' . $anio;
        } elseif ($periodo === 'anio') {
            $etiqueta = 'Año ' . $anio;
        } elseif ($periodo === 'todo') {
            $etiqueta = 'Todo el histórico';
        } else {
            $etiqueta = date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));
        }

        return [
            'periodo'  => $periodo,
            'anio'     => $anio,
            'mes'      => $mes,
            'desde'    => $desde,
            'hasta'    => $hasta,
            'etiqueta' => $etiqueta,
        ];
    }

    /**
     * Condiciones comunes: cliente, ventas registradas y rango de fechas.
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condiciones(int $clienteId, string $desde, string $hasta, int $empresaId = 0): array
    {
        $donde = ['v.cliente_id = ?', 'v.estado = "registrada"', 'v.fecha BETWEEN ? AND ?'];
        $tipos = 'iss';
        $valores = [$clienteId, $desde, $hasta];

        if ($empresaId > 0) {
            $donde[] = 'v.empresa_id = ?';
            $tipos .= 'i';
            $valores[] = $empresaId;
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Totales del periodo.
     *
     * @return array{ventas: int, piezas: int, subtotal: float, descuento: float,
     *               base: float, iva: float, total: float, ticket_promedio: float,
     *               ultima: string}
     */
    public static function resumen(int $clienteId, string $desde, string $hasta, int $empresaId = 0): array
    {
        [$donde, $tipos, $valores] = self::condiciones($clienteId, $desde, $hasta, $empresaId);

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS ventas,
                    COALESCE(SUM(v.subtotal), 0) AS subtotal,
                    COALESCE(SUM(v.descuento), 0) AS descuento,
                    COALESCE(SUM(v.subtotal - v.descuento), 0) AS base,
                    COALESCE(SUM(v.iva), 0) AS iva,
                    COALESCE(SUM(v.total), 0) AS total,
                    MAX(v.fecha) AS ultima
               FROM ventas v
              WHERE ' . $donde
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        $consulta = self::$db->prepare(
            'SELECT COALESCE(SUM(d.cantidad), 0) AS piezas
               FROM venta_detalles d
               JOIN ventas v ON v.id = d.venta_id
              WHERE ' . $donde
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $piezas = (int) ($consulta->get_result()->fetch_assoc()['piezas'] ?? 0);
        $consulta->close();

        $ventas = (int) ($fila['ventas'] ?? 0);
        $total  = (float) ($fila['total'] ?? 0);

        return [
            'ventas'         => $ventas,
            'piezas'         => $piezas,
            'subtotal'       => (float) ($fila['subtotal'] ?? 0),
            'descuento'      => (float) ($fila['descuento'] ?? 0),
            'base'           => (float) ($fila['base'] ?? 0),
            'iva'            => (float) ($fila['iva'] ?? 0),
            'total'          => $total,
            'ticket_promedio'=> $ventas > 0 ? $total / $ventas : 0.0,
            'ultima'         => (string) ($fila['ultima'] ?? ''),
        ];
    }

    /**
     * Productos comprados en el periodo, de mayor a menor.
     *
     * @return array<int, array{producto_id: int, sku: string, nombre: string,
     *               unidad: string, imagen: string, piezas: int, ventas: int,
     *               importe: float, ultima: string}>
     */
    public static function productos(int $clienteId, string $desde, string $hasta, int $empresaId = 0, string $orden = 'piezas'): array
    {
        [$donde, $tipos, $valores] = self::condiciones($clienteId, $desde, $hasta, $empresaId);

        $ordenSql = $orden === 'importe'
            ? 'importe DESC, piezas DESC, p.nombre ASC'
            : 'piezas DESC, importe DESC, p.nombre ASC';

        $consulta = self::$db->prepare(
            'SELECT p.id AS producto_id, p.sku, p.nombre, p.unidad, p.imagen,
                    COALESCE(SUM(d.cantidad), 0) AS piezas,
                    COUNT(DISTINCT v.id) AS ventas,
                    COALESCE(SUM(d.subtotal), 0) AS importe,
                    MAX(v.fecha) AS ultima
               FROM venta_detalles d
               JOIN ventas v    ON v.id = d.venta_id
               JOIN productos p ON p.id = d.producto_id
              WHERE ' . $donde . '
              GROUP BY p.id, p.sku, p.nombre, p.unidad, p.imagen
              ORDER BY ' . $ordenSql
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return array_map(static fn (array $f): array => [
            'producto_id' => (int) $f['producto_id'],
            'sku'         => (string) ($f['sku'] ?? ''),
            'nombre'      => (string) $f['nombre'],
            'unidad'      => (string) ($f['unidad'] ?? 'pza'),
            'imagen'      => (string) ($f['imagen'] ?? ''),
            'piezas'      => (int) $f['piezas'],
            'ventas'      => (int) $f['ventas'],
            'importe'     => (float) $f['importe'],
            'ultima'      => (string) ($f['ultima'] ?? ''),
        ], $filas);
    }

    /** Número de documentos (ventas) del periodo. */
    public static function contarDocumentos(int $clienteId, string $desde, string $hasta, int $empresaId = 0): int
    {
        [$donde, $tipos, $valores] = self::condiciones($clienteId, $desde, $hasta, $empresaId);

        $consulta = self::$db->prepare('SELECT COUNT(*) AS total FROM ventas v WHERE ' . $donde);
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $total = (int) ($consulta->get_result()->fetch_assoc()['total'] ?? 0);
        $consulta->close();

        return $total;
    }

    /**
     * Ventas del periodo, paginadas.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function documentos(int $clienteId, string $desde, string $hasta, int $empresaId = 0, int $limite = 15, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condiciones($clienteId, $desde, $hasta, $empresaId);
        $limite = max(1, $limite);
        $offset = max(0, $offset);

        $consulta = self::$db->prepare(
            'SELECT v.id, v.folio, v.fecha, v.subtotal, v.descuento, v.iva, v.total,
                    v.entrega_estado, v.cotizacion_id, v.creado_en,
                    e.nombre AS empresa_nombre,
                    py.folio AS proyecto_folio, py.nombre AS proyecto_nombre,
                    (SELECT COALESCE(SUM(d.cantidad), 0) FROM venta_detalles d WHERE d.venta_id = v.id) AS piezas
               FROM ventas v
               JOIN empresas e ON e.id = v.empresa_id
               LEFT JOIN proyectos_entrega py ON py.id = v.proyecto_id
              WHERE ' . $donde . '
              ORDER BY v.fecha DESC, v.id DESC
              LIMIT ? OFFSET ?'
        );
        $tipos .= 'ii';
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Serie de los 12 meses de un año: ventas, piezas y total.
     *
     * @return array<int, array{mes: int, ventas: int, piezas: int, total: float}>
     */
    public static function serieMensual(int $clienteId, int $anio, int $empresaId = 0): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);
        [$donde, $tipos, $valores] = self::condiciones($clienteId, $desde, $hasta, $empresaId);

        $consulta = self::$db->prepare(
            'SELECT MONTH(v.fecha) AS mes, COUNT(*) AS ventas, COALESCE(SUM(v.total), 0) AS total
               FROM ventas v
              WHERE ' . $donde . '
              GROUP BY MONTH(v.fecha)'
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $porMes = [];
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
            $porMes[(int) $fila['mes']] = [
                'ventas' => (int) $fila['ventas'],
                'total'  => (float) $fila['total'],
                'piezas' => 0,
            ];
        }
        $consulta->close();

        $consulta = self::$db->prepare(
            'SELECT MONTH(v.fecha) AS mes, COALESCE(SUM(d.cantidad), 0) AS piezas
               FROM venta_detalles d
               JOIN ventas v ON v.id = d.venta_id
              WHERE ' . $donde . '
              GROUP BY MONTH(v.fecha)'
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
            $mes = (int) $fila['mes'];
            $porMes[$mes] = ($porMes[$mes] ?? ['ventas' => 0, 'total' => 0.0, 'piezas' => 0]);
            $porMes[$mes]['piezas'] = (int) $fila['piezas'];
        }
        $consulta->close();

        $serie = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            $serie[] = [
                'mes'    => $mes,
                'ventas' => (int) ($porMes[$mes]['ventas'] ?? 0),
                'piezas' => (int) ($porMes[$mes]['piezas'] ?? 0),
                'total'  => (float) ($porMes[$mes]['total'] ?? 0),
            ];
        }

        return $serie;
    }

    /**
     * Años con ventas registradas del cliente, del más reciente al más viejo.
     *
     * @return int[]
     */
    public static function anios(int $clienteId): array
    {
        $consulta = self::$db->prepare(
            'SELECT DISTINCT YEAR(v.fecha) AS anio
               FROM ventas v
              WHERE v.cliente_id = ? AND v.estado = "registrada"
              ORDER BY anio DESC'
        );
        $consulta->bind_param('i', $clienteId);
        $consulta->execute();
        $anios = array_map('intval', array_column($consulta->get_result()->fetch_all(MYSQLI_ASSOC), 'anio'));
        $consulta->close();

        return $anios;
    }
}
