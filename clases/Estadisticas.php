<?php

namespace App;

/**
 * Estadisticas — métricas comerciales históricas.
 *
 * Fuente única y sin duplicados:
 *   • «Ventas realizadas»  → tabla `ventas` (estado registrada). Una cotización vendida es UNA venta.
 *   • «Ventas facturadas»  → `ingresos` vigentes marcados `facturada` (dato explícito, no un PDF adjunto).
 *   • «Cobros recibidos»   → `cobros` vigentes, por fecha de cobro (siempre con IVA).
 *   • «Gastos»             → `gastos` vigentes por fecha del gasto (los compartidos se reparten por su % entre negocios).
 * Estas medidas NO son equivalentes y se muestran por separado: nunca se mezclan en una misma serie.
 *
 * Comparaciones: mes, trimestre, semestre o año contra el mismo periodo de años anteriores. Si el
 * periodo actual no ha terminado se marca «incompleto» y además se compara contra el MISMO avance
 * del año anterior (hasta el mismo día), para no comparar un mes parcial con uno completo.
 *
 * Importes en centavos enteros. Solo lectura: nada de aquí modifica datos.
 */
class Estadisticas
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    public const AGRUPACIONES = ['dia' => 'Día', 'mes' => 'Mes', 'trimestre' => 'Trimestre', 'semestre' => 'Semestre', 'anio' => 'Año'];
    public const TIPOS_PERIODO = ['mes' => 'Mes', 'trimestre' => 'Trimestre', 'semestre' => 'Semestre', 'anio' => 'Año completo'];
    public const METRICAS = [
        'realizadas' => 'Ventas realizadas',
        'facturadas' => 'Ventas facturadas',
        'cobros'     => 'Cobros recibidos',
        'gastos'     => 'Gastos',
    ];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /* --------------------------------------------------------------------
     * PERIODOS
     * ------------------------------------------------------------------ */

    /** @return array{0: string, 1: string} primer y último día del periodo (Y-m-d) */
    public static function rango(string $tipo, int $anio, int $indice): array
    {
        switch ($tipo) {
            case 'mes':
                $m = max(1, min(12, $indice));
                return [sprintf('%04d-%02d-01', $anio, $m), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anio, $m)))];
            case 'trimestre':
                $q = max(1, min(4, $indice));
                $m = ($q - 1) * 3 + 1;
                return [sprintf('%04d-%02d-01', $anio, $m), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anio, $m + 2)))];
            case 'semestre':
                return (int) $indice === 2 ? ["$anio-07-01", "$anio-12-31"] : ["$anio-01-01", "$anio-06-30"];
            default:
                return ["$anio-01-01", "$anio-12-31"];
        }
    }

    public static function etiquetaPeriodo(string $tipo, int $anio, int $indice): string
    {
        return match ($tipo) {
            'mes'       => nombreMes($indice) . ' ' . $anio,
            'trimestre' => 'T' . $indice . ' ' . $anio,
            'semestre'  => ($indice === 2 ? 'Segundo' : 'Primer') . ' semestre ' . $anio,
            default     => 'Año ' . $anio,
        };
    }

    /* --------------------------------------------------------------------
     * AGREGACIÓN BÁSICA
     * ------------------------------------------------------------------ */

    /** Expresión SQL de agrupación sobre una columna de fecha. */
    private static function grupoSql(string $agrupacion, string $columna): string
    {
        return match ($agrupacion) {
            'dia'       => "DATE_FORMAT($columna, '%Y-%m-%d')",
            'trimestre' => "CONCAT(YEAR($columna), '-T', QUARTER($columna))",
            'semestre'  => "CONCAT(YEAR($columna), '-S', IF(MONTH($columna) <= 6, 1, 2))",
            'anio'      => "CAST(YEAR($columna) AS CHAR)",
            default     => "DATE_FORMAT($columna, '%Y-%m')",
        };
    }

    /**
     * Totales por grupo de tiempo de una métrica.
     *
     * @param array{empresa?: int, cliente?: int, producto?: int} $f
     * @param string $base 'total' (con IVA) | 'subtotal' (sin IVA, con descuentos aplicados)
     * @return array<string, array{importe: int, operaciones: int, unidades: int}>
     */
    public static function serie(string $metrica, string $agrupacion, string $desde, string $hasta, array $f = [], string $base = 'total'): array
    {
        $empresa = (int) ($f['empresa'] ?? 0);
        $cliente = (int) ($f['cliente'] ?? 0);
        $producto = (int) ($f['producto'] ?? 0);
        $tipos = '';
        $valores = [];

        if ($metrica === 'gastos') {
            if ($producto > 0 || $cliente > 0) {
                return []; // un gasto no pertenece a un producto ni a un cliente
            }
            $columna = $base === 'subtotal' ? 'g.subtotal' : 'g.total';
            $grupo = self::grupoSql($agrupacion, 'g.fecha');
            if ($empresa > 0) {
                // Con un negocio elegido: el gasto completo si es suyo o la parte que le toca de uno compartido.
                $sql = "SELECT $grupo AS g, SUM(ROUND($columna * IF(g.empresa_id = ?, 1, r.porcentaje / 100) * 100)) AS importe, COUNT(*) AS operaciones, 0 AS unidades
                          FROM gastos g LEFT JOIN gasto_reparto r ON r.gasto_id = g.id AND r.empresa_id = ?
                         WHERE g.estado = 'vigente' AND g.fecha BETWEEN ? AND ? AND (g.empresa_id = ? OR r.empresa_id IS NOT NULL)
                         GROUP BY g ORDER BY g";
                $tipos = 'iissi';
                $valores = [$empresa, $empresa, $desde, $hasta, $empresa];
            } else {
                $sql = "SELECT $grupo AS g, SUM(ROUND($columna * 100)) AS importe, COUNT(*) AS operaciones, 0 AS unidades
                          FROM gastos g WHERE g.estado = 'vigente' AND g.fecha BETWEEN ? AND ? GROUP BY g ORDER BY g";
                $tipos = 'ss';
                $valores = [$desde, $hasta];
            }
        } elseif ($metrica === 'cobros') {
            if ($producto > 0) {
                return []; // un cobro no se reparte por producto
            }
            $grupo = self::grupoSql($agrupacion, 'c.fecha');
            $donde = ["c.estado = 'vigente'", "i.estado = 'vigente'", 'c.fecha BETWEEN ? AND ?'];
            $tipos = 'ss';
            $valores = [$desde, $hasta];
            if ($empresa > 0) { $donde[] = 'i.empresa_id = ?'; $tipos .= 'i'; $valores[] = $empresa; }
            if ($cliente > 0) { $donde[] = 'i.cliente_id = ?'; $tipos .= 'i'; $valores[] = $cliente; }

            $sql = "SELECT $grupo AS g, SUM(ROUND(c.importe * 100)) AS importe, COUNT(*) AS operaciones, 0 AS unidades
                      FROM cobros c JOIN ingresos i ON i.id = c.ingreso_id
                     WHERE " . implode(' AND ', $donde) . ' GROUP BY g ORDER BY g';
        } elseif ($producto > 0) {
            if ($metrica === 'facturadas') {
                return [];
            }
            // Con filtro de producto se miden sus renglones (sin IVA ni descuento global).
            $grupo = self::grupoSql($agrupacion, 'v.fecha');
            $donde = ["v.estado = 'registrada'", 'v.fecha BETWEEN ? AND ?', 'vd.producto_id = ?'];
            $tipos = 'ssi';
            $valores = [$desde, $hasta, $producto];
            if ($empresa > 0) { $donde[] = 'v.empresa_id = ?'; $tipos .= 'i'; $valores[] = $empresa; }
            if ($cliente > 0) { $donde[] = 'v.cliente_id = ?'; $tipos .= 'i'; $valores[] = $cliente; }

            $sql = "SELECT $grupo AS g, SUM(ROUND(vd.subtotal * 100)) AS importe, COUNT(DISTINCT v.id) AS operaciones, SUM(vd.cantidad) AS unidades
                      FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
                     WHERE " . implode(' AND ', $donde) . ' GROUP BY g ORDER BY g';
        } else {
            $expresion = $base === 'subtotal' ? '(v.subtotal - v.descuento)' : 'v.total';
            $grupo = self::grupoSql($agrupacion, 'v.fecha');
            $donde = ["v.estado = 'registrada'", 'v.fecha BETWEEN ? AND ?'];
            $tipos = 'ss';
            $valores = [$desde, $hasta];
            if ($metrica === 'facturadas') {
                $donde[] = "EXISTS (SELECT 1 FROM ingresos i WHERE i.venta_id = v.id AND i.estado = 'vigente' AND i.estado_facturacion = 'facturada')";
            }
            if ($empresa > 0) { $donde[] = 'v.empresa_id = ?'; $tipos .= 'i'; $valores[] = $empresa; }
            if ($cliente > 0) { $donde[] = 'v.cliente_id = ?'; $tipos .= 'i'; $valores[] = $cliente; }

            $sql = "SELECT $grupo AS g, SUM(ROUND($expresion * 100)) AS importe, COUNT(*) AS operaciones,
                           COALESCE(SUM((SELECT SUM(vd.cantidad) FROM venta_detalles vd WHERE vd.venta_id = v.id)), 0) AS unidades
                      FROM ventas v WHERE " . implode(' AND ', $donde) . ' GROUP BY g ORDER BY g';
        }

        $consulta = self::$db->prepare($sql);
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $resultado = [];
        foreach ($consulta->get_result() as $fila) {
            $resultado[(string) $fila['g']] = ['importe' => (int) $fila['importe'], 'operaciones' => (int) $fila['operaciones'], 'unidades' => (int) $fila['unidades']];
        }
        $consulta->close();

        return $resultado;
    }

    /** Suma de una serie (o de un rango completo). @return array{importe: int, operaciones: int, unidades: int} */
    public static function total(string $metrica, string $desde, string $hasta, array $f = [], string $base = 'total'): array
    {
        $t = ['importe' => 0, 'operaciones' => 0, 'unidades' => 0];
        foreach (self::serie($metrica, 'anio', $desde, $hasta, $f, $base) as $g) {
            foreach ($t as $k => $_) {
                $t[$k] += $g[$k];
            }
        }

        return $t;
    }

    /* --------------------------------------------------------------------
     * COMPARACIÓN ENTRE PERIODOS EQUIVALENTES
     * ------------------------------------------------------------------ */

    /** Misma fecha en otro año (29-feb → 28-feb). */
    public static function mismaFechaEnAnio(string $fecha, int $anio): string
    {
        $mes = (int) substr($fecha, 5, 2);
        $dia = (int) substr($fecha, 8, 2);
        $dia = min($dia, (int) date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes))));

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /**
     * Compara un periodo contra el mismo periodo de los $aniosAtras años anteriores.
     *
     * @return array<int, array<string, mixed>> una fila por año (del más antiguo al más reciente)
     */
    public static function comparar(string $tipo, int $indice, int $anioBase, int $aniosAtras, array $f = [], string $base = 'total', ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $filas = [];
        $anterior = null;

        for ($anio = $anioBase - $aniosAtras; $anio <= $anioBase; $anio++) {
            [$desde, $hasta] = self::rango($tipo, $anio, $indice);
            $completo = $hoy >= $hasta;
            $futuro = $hoy < $desde;
            $corte = $completo ? $hasta : min($hasta, $hoy);

            $fila = ['anio' => $anio, 'desde' => $desde, 'hasta' => $hasta, 'completo' => $completo, 'futuro' => $futuro, 'etiqueta' => self::etiquetaPeriodo($tipo, $anio, $indice)];
            foreach (array_keys(self::METRICAS) as $m) {
                $fila[$m] = $futuro ? ['importe' => 0, 'operaciones' => 0, 'unidades' => 0] : self::total($m, $desde, $hasta, $f, $base);
            }
            $fila['corte'] = $completo ? null : $corte;
            $filas[] = $fila;
        }

        // Variaciones contra el año anterior; si el periodo más reciente está incompleto,
        // la comparación justa es contra el mismo avance del año anterior.
        foreach ($filas as $i => &$fila) {
            $fila['variacion'] = null;
            $fila['variacion_mismo_avance'] = null;
            $fila['variaciones'] = array_fill_keys(array_keys(self::METRICAS), null);
            $fila['variaciones_mismo_avance'] = $fila['variaciones'];
            $fila['previo_mismo_avance_metricas'] = [];
            if ($i === 0) {
                continue;
            }
            $previa = $filas[$i - 1];
            foreach (array_keys(self::METRICAS) as $m) {
                $fila['variaciones'][$m] = self::variacion($previa[$m]['importe'], $fila[$m]['importe']);
            }
            $fila['variacion'] = $fila['variaciones']['realizadas'];
            if (!$fila['completo'] && !$fila['futuro']) {
                $corteComparable = self::mismaFechaEnAnio($fila['corte'], (int) $previa['anio']);
                foreach (array_keys(self::METRICAS) as $m) {
                    $mismoAvance = self::total($m, $previa['desde'], min($previa['hasta'], $corteComparable), $f, $base);
                    $fila['previo_mismo_avance_metricas'][$m] = $mismoAvance;
                    $fila['variaciones_mismo_avance'][$m] = self::variacion($mismoAvance['importe'], $fila[$m]['importe']);
                }
                $fila['previo_mismo_avance'] = $fila['previo_mismo_avance_metricas']['realizadas'];
                $fila['variacion_mismo_avance'] = $fila['variaciones_mismo_avance']['realizadas'];
            }
        }
        unset($fila);

        return $filas;
    }

    /** @return array{absoluta: int, porcentual: ?float} porcentual null si no hay base de comparación */
    public static function variacion(int $anterior, int $actual): array
    {
        return [
            'absoluta'   => $actual - $anterior,
            'porcentual' => $anterior > 0 ? round(($actual - $anterior) * 100 / $anterior, 2) : null,
        ];
    }

    /* --------------------------------------------------------------------
     * RANKINGS
     * ------------------------------------------------------------------ */

    /**
     * Productos más vendidos del rango (por importe de renglones sin IVA), SEPARADOS por negocio:
     * cada negocio trae sus `$limite` productos con más importe, con su código (SKU).
     * El negocio es el emisor de la venta (`v.empresa_id`), el mismo que usa «Ventas por negocio».
     *
     * @return array<int, array{producto_id: int, empresa_id: int, negocio: string, sku: string, nombre: string, imagen: ?string, unidades: int, importe: int, operaciones: int}>
     */
    public static function topProductos(string $desde, string $hasta, array $f = [], int $limite = 10): array
    {
        $donde = ["v.estado = 'registrada'", 'v.fecha BETWEEN ? AND ?'];
        $tipos = 'ss';
        $valores = [$desde, $hasta];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'v.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }
        if ((int) ($f['cliente'] ?? 0) > 0) { $donde[] = 'v.cliente_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['cliente']; }
        $tipos .= 'i';
        $valores[] = max(1, $limite);

        $consulta = self::$db->prepare(
            'SELECT t.* FROM (
                SELECT p.id AS producto_id, v.empresa_id, e.nombre AS negocio, p.sku, p.nombre, p.imagen,
                       SUM(vd.cantidad) AS unidades, SUM(ROUND(vd.subtotal * 100)) AS importe, COUNT(DISTINCT v.id) AS operaciones,
                       ROW_NUMBER() OVER (PARTITION BY v.empresa_id ORDER BY SUM(ROUND(vd.subtotal * 100)) DESC, SUM(vd.cantidad) DESC, p.nombre ASC) AS posicion
                  FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id JOIN productos p ON p.id = vd.producto_id
                       JOIN empresas e ON e.id = v.empresa_id
                 WHERE ' . implode(' AND ', $donde) . '
                 GROUP BY p.id, v.empresa_id, e.nombre, p.sku, p.nombre, p.imagen) t
             WHERE t.posicion <= ? ORDER BY t.empresa_id ASC, t.posicion ASC'
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return array_map(static fn ($x) => [
            'producto_id' => (int) $x['producto_id'], 'empresa_id' => (int) $x['empresa_id'], 'negocio' => (string) $x['negocio'],
            'sku' => (string) $x['sku'], 'nombre' => $x['nombre'], 'imagen' => $x['imagen'],
            'unidades' => (int) $x['unidades'], 'importe' => (int) $x['importe'], 'operaciones' => (int) $x['operaciones'],
        ], $filas);
    }

    /**
     * Clientes con mayor volumen de compra, SEPARADOS por negocio: cada negocio trae sus
     * `$limite` clientes con más importe (un cliente que compra en los dos sale en los dos).
     *
     * @return array<int, array{cliente_id: int, empresa_id: int, negocio: string, nombre: string, importe: int, operaciones: int, unidades: int}>
     */
    public static function topClientes(string $desde, string $hasta, array $f = [], string $base = 'total', int $limite = 10): array
    {
        $expresion = $base === 'subtotal' ? '(v.subtotal - v.descuento)' : 'v.total';
        $donde = ["v.estado = 'registrada'", 'v.fecha BETWEEN ? AND ?'];
        $tipos = 'ss';
        $valores = [$desde, $hasta];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'v.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }
        $tipos .= 'i';
        $valores[] = max(1, $limite);

        $consulta = self::$db->prepare(
            "SELECT t.* FROM (
                SELECT cl.id AS cliente_id, v.empresa_id, e.nombre AS negocio, COALESCE(NULLIF(cl.nombre_empresa, ''), cl.nombre) AS nombre,
                       SUM(ROUND($expresion * 100)) AS importe, COUNT(*) AS operaciones,
                       COALESCE(SUM((SELECT SUM(vd.cantidad) FROM venta_detalles vd WHERE vd.venta_id = v.id)), 0) AS unidades,
                       ROW_NUMBER() OVER (PARTITION BY v.empresa_id ORDER BY SUM(ROUND($expresion * 100)) DESC, COUNT(*) DESC, COALESCE(NULLIF(cl.nombre_empresa, ''), cl.nombre) ASC) AS posicion
                  FROM ventas v JOIN clientes cl ON cl.id = v.cliente_id JOIN empresas e ON e.id = v.empresa_id
                 WHERE " . implode(' AND ', $donde) . '
                 GROUP BY cl.id, v.empresa_id, e.nombre, nombre) t
             WHERE t.posicion <= ? ORDER BY t.empresa_id ASC, t.posicion ASC'
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return array_map(static fn ($x) => [
            'cliente_id' => (int) $x['cliente_id'], 'empresa_id' => (int) $x['empresa_id'], 'negocio' => (string) $x['negocio'], 'nombre' => $x['nombre'],
            'importe' => (int) $x['importe'], 'operaciones' => (int) $x['operaciones'], 'unidades' => (int) $x['unidades'],
        ], $filas);
    }

    /**
     * Une filas que traen `empresa_id` y `negocio` en un grupo por negocio, conservando el orden.
     *
     * @param array<int, array{empresa_id: int, negocio: string}> $filas
     * @return array<int, array{empresa_id: int, negocio: string, filas: array<int, array>}>
     */
    public static function agruparPorNegocio(array $filas): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            $id = (int) ($fila['empresa_id'] ?? 0);
            $grupos[$id] ??= ['empresa_id' => $id, 'negocio' => (string) ($fila['negocio'] ?? ''), 'filas' => []];
            $grupos[$id]['filas'][] = $fila;
        }

        return array_values($grupos);
    }

    /**
     * Ventas por producto o por cliente durante varios años (matriz años × elementos).
     *
     * @param string $dimension 'producto' | 'cliente'
     * @return array{anios: int[], filas: array<int, array{nombre: string, por_anio: array<int, int>, total: int}>}
     */
    public static function porAnios(string $dimension, int $anioDesde, int $anioHasta, array $f = [], string $base = 'total', int $limite = 10): array
    {
        $anios = range($anioDesde, $anioHasta);
        $donde = ["v.estado = 'registrada'", 'YEAR(v.fecha) BETWEEN ? AND ?'];
        $tipos = 'ii';
        $valores = [$anioDesde, $anioHasta];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'v.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }

        if ($dimension === 'producto') {
            $sql = 'SELECT p.id AS clave, CONCAT(COALESCE(p.sku, ""), " ", p.nombre) AS nombre, YEAR(v.fecha) AS anio, SUM(ROUND(vd.subtotal * 100)) AS importe
                      FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id JOIN productos p ON p.id = vd.producto_id
                     WHERE ' . implode(' AND ', $donde) . ' GROUP BY p.id, nombre, anio';
        } else {
            $expresion = $base === 'subtotal' ? '(v.subtotal - v.descuento)' : 'v.total';
            $sql = "SELECT cl.id AS clave, COALESCE(NULLIF(cl.nombre_empresa, ''), cl.nombre) AS nombre, YEAR(v.fecha) AS anio, SUM(ROUND($expresion * 100)) AS importe
                      FROM ventas v JOIN clientes cl ON cl.id = v.cliente_id
                     WHERE " . implode(' AND ', $donde) . ' GROUP BY cl.id, nombre, anio';
        }

        $consulta = self::$db->prepare($sql);
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $mapa = [];
        foreach ($consulta->get_result() as $x) {
            $k = (int) $x['clave'];
            $mapa[$k] ??= ['nombre' => $x['nombre'], 'por_anio' => array_fill_keys($anios, 0), 'total' => 0];
            $mapa[$k]['por_anio'][(int) $x['anio']] = (int) $x['importe'];
            $mapa[$k]['total'] += (int) $x['importe'];
        }
        $consulta->close();

        uasort($mapa, static fn ($a, $b) => $b['total'] <=> $a['total']);

        return ['anios' => $anios, 'filas' => array_slice(array_values($mapa), 0, max(1, $limite))];
    }

    /* --------------------------------------------------------------------
     * TENDENCIA
     * ------------------------------------------------------------------ */

    /**
     * Tendencia de una serie ordenada por tiempo (regresión lineal simple sobre los importes).
     * Criterio documentado: pendiente por periodo ÷ promedio > +2 % = al alza; < −2 % = a la baja.
     *
     * @param int[] $importes
     * @return array{direccion: string, etiqueta: string, pendiente_pct: float}
     */
    public static function tendencia(array $importes): array
    {
        $n = count($importes);
        $promedio = $n ? array_sum($importes) / $n : 0;
        if ($n < 3 || $promedio <= 0) {
            return ['direccion' => 'sin_datos', 'etiqueta' => 'Sin datos suficientes (se necesitan al menos 3 periodos con ventas)', 'pendiente_pct' => 0.0];
        }

        $x = range(0, $n - 1);
        $mediaX = array_sum($x) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach ($importes as $i => $y) {
            $num += ($i - $mediaX) * ($y - $promedio);
            $den += ($i - $mediaX) ** 2;
        }
        $pendiente = $den > 0 ? $num / $den : 0.0;
        $pct = round($pendiente * 100 / $promedio, 2);

        if ($pct > 2) {
            return ['direccion' => 'alza', 'etiqueta' => 'Tendencia al alza', 'pendiente_pct' => $pct];
        }
        if ($pct < -2) {
            return ['direccion' => 'baja', 'etiqueta' => 'Tendencia a la baja', 'pendiente_pct' => $pct];
        }

        return ['direccion' => 'estable', 'etiqueta' => 'Tendencia estable', 'pendiente_pct' => $pct];
    }

    /** Serie completa con huecos en cero para los meses sin ventas (necesaria para la tendencia y la gráfica). */
    public static function serieMensualCompleta(string $metrica, string $desde, string $hasta, array $f = [], string $base = 'total'): array
    {
        $datos = self::serie($metrica, 'mes', $desde, $hasta, $f, $base);
        $completa = [];
        for ($t = strtotime(substr($desde, 0, 7) . '-01'); $t <= strtotime($hasta); $t = strtotime('+1 month', $t)) {
            $clave = date('Y-m', $t);
            $completa[$clave] = $datos[$clave] ?? ['importe' => 0, 'operaciones' => 0, 'unidades' => 0];
        }

        return $completa;
    }

    /* --------------------------------------------------------------------
     * REPORTE COMPLETO (lo usan la pantalla y las exportaciones)
     * ------------------------------------------------------------------ */

    /** Normaliza los parámetros de la pantalla (GET) con valores seguros por defecto. */
    public static function parametros(array $g, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $anioActual = (int) substr($hoy, 0, 4);
        $tipo = isset(self::TIPOS_PERIODO[$g['tipo'] ?? '']) ? $g['tipo'] : 'mes';
        $max = ['mes' => 12, 'trimestre' => 4, 'semestre' => 2, 'anio' => 1][$tipo];
        $porDefecto = match ($tipo) {
            'mes' => (int) substr($hoy, 5, 2),
            'trimestre' => (int) ceil((int) substr($hoy, 5, 2) / 3),
            'semestre' => (int) substr($hoy, 5, 2) > 6 ? 2 : 1,
            default => 1,
        };
        $indice = isset($g['indice']) ? max(1, min($max, (int) $g['indice'])) : $porDefecto;
        $anio = isset($g['anio']) ? max(2000, min(2100, (int) $g['anio'])) : $anioActual;

        return [
            'tipo' => $tipo, 'indice' => $indice, 'anio' => $anio,
            'atras' => max(1, min(4, (int) ($g['atras'] ?? 2))),
            'agrupacion' => isset(self::AGRUPACIONES[$g['agrupacion'] ?? '']) ? $g['agrupacion'] : 'mes',
            'base' => ($g['base'] ?? '') === 'subtotal' ? 'subtotal' : 'total',
            'filtros' => ['empresa' => max(0, (int) ($g['empresa'] ?? 0)), 'cliente' => max(0, (int) ($g['cliente'] ?? 0)), 'producto' => max(0, (int) ($g['producto'] ?? 0))],
            'hoy' => $hoy,
        ];
    }

    /**
     * Todo lo que muestran la pantalla y los reportes, a partir de los parámetros normalizados.
     *
     * @return array<string, mixed>
     */
    public static function reporte(array $p): array
    {
        $f = $p['filtros'];
        $comparacion = self::comparar($p['tipo'], $p['indice'], $p['anio'], $p['atras'], $f, $p['base'], $p['hoy']);
        $actual = end($comparacion);

        if ($p['agrupacion'] === 'dia') {
            [$desde, $hasta] = self::rango($p['tipo'], $p['anio'], $p['indice']);
        } else {
            $desde = ($p['anio'] - $p['atras']) . '-01-01';
            $hasta = $p['anio'] . '-12-31';
        }
        $hasta = min($hasta, $p['hoy']);
        $serie = $desde <= $hasta ? self::serie('realizadas', $p['agrupacion'], $desde, $hasta, $f, $p['base']) : [];
        $mensual = $desde <= $hasta ? self::serieMensualCompleta('realizadas', substr($desde, 0, 7) . '-01', $hasta, $f, $p['base']) : [];

        [$pd, $ph] = self::rango($p['tipo'], $p['anio'], $p['indice']);
        $ph = min($ph, $p['hoy']);
        $ok = $desde <= $hasta;
        $anioDesde = $p['anio'] - $p['atras'];
        $semestres = $ok ? self::serie('realizadas', 'semestre', "$anioDesde-01-01", min($p['anio'] . '-12-31', $p['hoy']), $f, $p['base']) : [];

        $reporte = [
            'parametros' => $p,
            'comparacion' => $comparacion,
            'actual' => $actual,
            'serie' => $serie,
            'tendencia' => self::tendencia(array_column($mensual, 'importe')),
            'productos' => self::topProductos($pd, $ph, $f, 10),
            'clientes' => self::topClientes($pd, $ph, $f, $p['base'], 10),
            'matriz_productos' => self::porAnios('producto', $p['anio'] - $p['atras'], $p['anio'], $f, $p['base'], 8),
            'matriz_clientes' => self::porAnios('cliente', $p['anio'] - $p['atras'], $p['anio'], $f, $p['base'], 8),
            'periodo' => [$pd, $ph],
            'rango_serie' => [$desde, $hasta],
            'semestres' => $semestres,
        ];
        $reporte['analisis'] = Analisis::datos($p, $comparacion);
        $reporte['kpis'] = self::kpis($reporte);
        $reporte['graficas'] = Analisis::graficas($reporte);

        return $reporte;
    }

    /**
     * Indicadores del periodo con su variación contra el MISMO avance del año anterior.
     *
     * @return array<int, array{clave: string, nombre: string, importe: int, previo: int, variacion: array, nota: string}>
     */
    private static function kpis(array $r): array
    {
        $p = $r['parametros'];
        $f = $p['filtros'];
        $actual = $r['actual'];
        [$pd1, $ph1] = $r['analisis']['rango_previo'];
        $previo = static fn (string $m): int => $pd1 > $ph1 ? 0 : self::total($m, $pd1, $ph1, $f, $p['base'])['importe'];

        $kpis = [
            ['clave' => 'realizadas', 'nombre' => 'Ventas realizadas', 'importe' => $actual['realizadas']['importe'], 'previo' => $previo('realizadas'), 'detalle' => number_format($actual['realizadas']['operaciones']) . ' ventas · ' . number_format($actual['realizadas']['unidades']) . ' piezas'],
            ['clave' => 'cobros', 'nombre' => 'Cobros recibidos', 'importe' => $actual['cobros']['importe'], 'previo' => $previo('cobros'), 'detalle' => number_format($actual['cobros']['operaciones']) . ($actual['cobros']['operaciones'] == 1 ? ' pago' : ' pagos')],
            ['clave' => 'facturadas', 'nombre' => 'Ventas facturadas', 'importe' => $actual['facturadas']['importe'], 'previo' => $previo('facturadas'), 'detalle' => number_format($actual['facturadas']['operaciones']) . ($actual['facturadas']['operaciones'] == 1 ? ' venta con factura' : ' ventas con factura')],
            ['clave' => 'gastos', 'nombre' => 'Gastos', 'importe' => $r['analisis']['gastos_periodo'], 'previo' => $r['analisis']['gastos_previo'], 'detalle' => 'Gastos vigentes del periodo'],
        ];
        foreach ($kpis as &$k) {
            $k['variacion'] = $r['analisis']['rango_previo'][0] > $r['analisis']['rango_previo'][1] || ($k['previo'] === 0 && $k['importe'] === 0) ? null : self::variacion($k['previo'], $k['importe']);
            $k['sentido'] = $k['clave'] === 'gastos' ? 'inverso' : 'directo';
        }
        unset($k);

        return $kpis;
    }

    /**
     * Filas planas para Excel/CSV: una sola hoja con columna de sección.
     *
     * @return array{0: string[], 1: array<int, array<int, scalar|null>>}
     */
    public static function filasExportacion(array $r): array
    {
        $m = static fn (int $c): float => round($c / 100, 2);
        $enc = ['Sección', 'Concepto', 'Periodo', 'Importe', 'Operaciones', 'Unidades', 'Variación absoluta', 'Variación %', 'Nota'];
        $filas = [];

        foreach ($r['comparacion'] as $x) {
            foreach (self::METRICAS as $clave => $nombre) {
                $v = $clave === 'realizadas' ? $x['variacion'] : null;
                $filas[] = ['Comparación', $nombre, $x['etiqueta'], $m($x[$clave]['importe']), $x[$clave]['operaciones'], $x[$clave]['unidades'],
                    $v ? $m($v['absoluta']) : '', $v && $v['porcentual'] !== null ? $v['porcentual'] : '',
                    $x['futuro'] ? 'Periodo futuro' : ($x['completo'] ? '' : 'INCOMPLETO al ' . $x['corte'])];
            }
        }
        foreach ($r['serie'] as $g => $x) {
            $filas[] = ['Serie (' . self::AGRUPACIONES[$r['parametros']['agrupacion']] . ')', 'Ventas realizadas', (string) $g, $m($x['importe']), $x['operaciones'], $x['unidades'], '', '', ''];
        }
        foreach ($r['productos'] as $x) {
            $filas[] = ['Productos más vendidos (' . $x['negocio'] . ')', trim($x['sku'] . ' ' . $x['nombre']), self::etiquetaPeriodo($r['parametros']['tipo'], $r['parametros']['anio'], $r['parametros']['indice']),
                $m($x['importe']), $x['operaciones'], $x['unidades'], '', '', 'Importe de renglones sin IVA'];
        }
        foreach ($r['clientes'] as $x) {
            $filas[] = ['Clientes con mayor volumen (' . $x['negocio'] . ')', $x['nombre'], self::etiquetaPeriodo($r['parametros']['tipo'], $r['parametros']['anio'], $r['parametros']['indice']),
                $m($x['importe']), $x['operaciones'], $x['unidades'], '', '', ''];
        }
        foreach (['matriz_productos' => 'Productos por año', 'matriz_clientes' => 'Clientes por año'] as $clave => $seccion) {
            foreach ($r[$clave]['filas'] as $x) {
                foreach ($r[$clave]['anios'] as $a) {
                    $filas[] = [$seccion, $x['nombre'], (string) $a, $m($x['por_anio'][$a]), '', '', '', '', ''];
                }
            }
        }
        foreach ($r['graficas'] as $g) {
            foreach ($g['categorias'] as $i => $cat) {
                foreach ($g['series'] as $serie) {
                    if (($serie['datos'][$i] ?? null) === null) { continue; }
                    $filas[] = ['Gráfica: ' . $g['titulo'], $serie['nombre'], (string) $cat, $serie['datos'][$i], '', '', '', '', $g['formato'] === 'moneda' ? 'Importe en pesos' : 'Piezas'];
                }
            }
        }
        $filas[] = ['Tendencia', $r['tendencia']['etiqueta'], '', '', '', '', '', $r['tendencia']['pendiente_pct'], 'Pendiente por periodo ÷ promedio'];

        return [$enc, $filas];
    }

    /** Filtros aplicados, con nombres (no números de registro). @return string[] */
    public static function filtrosTexto(array $p): array
    {
        $f = $p['filtros'];
        $buscar = static function (string $sql, int $id): ?string {
            $c = self::$db->prepare($sql);
            $c->bind_param('i', $id);
            $c->execute();
            $fila = $c->get_result()->fetch_row();
            $c->close();

            return $fila ? (string) $fila[0] : null;
        };
        $texto = [];
        $texto[] = 'Negocio: ' . ($f['empresa'] ? ($buscar('SELECT nombre FROM empresas WHERE id = ?', $f['empresa']) ?? '#' . $f['empresa']) : 'todos');
        $texto[] = 'Cliente: ' . ($f['cliente'] ? ($buscar("SELECT COALESCE(NULLIF(nombre_empresa, ''), nombre) FROM clientes WHERE id = ?", $f['cliente']) ?? '#' . $f['cliente']) : 'todos');
        $texto[] = 'Producto: ' . ($f['producto'] ? ($buscar("SELECT CONCAT(COALESCE(CONCAT(sku, ' · '), ''), nombre) FROM productos WHERE id = ?", $f['producto']) ?? '#' . $f['producto']) . ' (renglones sin IVA)' : 'todos');

        return $texto;
    }

    /** Líneas de contexto que acompañan a toda exportación. @return string[] */
    public static function contexto(array $r): array
    {
        $p = $r['parametros'];
        $lineas = [
            'Periodo: ' . self::etiquetaPeriodo($p['tipo'], $p['anio'], $p['indice']) . ' (' . date('d/m/Y', strtotime($r['periodo'][0])) . ' al ' . date('d/m/Y', strtotime($r['periodo'][1])) . ')',
            'Comparado con los ' . $p['atras'] . ' año(s) anterior(es) · Importes ' . ($p['base'] === 'subtotal' ? 'sin IVA (con descuentos)' : 'con IVA'),
            implode(' · ', self::filtrosTexto($p)),
            'Emitido ' . date('d/m/Y H:i'),
        ];
        if (!$r['actual']['completo']) {
            $lineas[] = ($r['actual']['futuro'] ? 'Periodo futuro.' : 'PERIODO INCOMPLETO: datos al ' . date('d/m/Y', strtotime($r['actual']['corte'] ?? $p['hoy'])) . '; las variaciones comparan el mismo avance del año anterior.');
        }

        return $lineas;
    }

    /** Ventas por semestre y año con variación contra el mismo semestre del año anterior. @return array<int, array<string, mixed>> */
    public static function semestresFilas(array $r): array
    {
        $filas = [];
        $previo = null;
        foreach ($r['analisis']['anios'] as $a) {
            $s1 = $r['analisis']['semestres'][$a][1] ?? 0;
            $s2 = $r['analisis']['semestres'][$a][2] ?? 0;
            $filas[] = ['anio' => $a, 's1' => $s1, 's2' => $s2, 'total' => $s1 + $s2,
                'var1' => $previo ? self::variacion($previo[0], $s1) : null, 'var2' => $previo ? self::variacion($previo[1], $s2) : null];
            $previo = [$s1, $s2];
        }

        return $filas;
    }

    /** Libro de Excel: resumen + una hoja por gráfica (con la gráfica nativa) + rankings. */
    public static function libroExcel(array $r): ?string
    {
        $p = $r['parametros'];
        $m = static fn (int $c): float => round($c / 100, 2);
        $pct = static fn (?array $v) => $v && $v['porcentual'] !== null ? $v['porcentual'] : null;
        $contexto = self::contexto($r);

        $resumen = [[['v' => 'Estadísticas de ventas · ' . self::etiquetaPeriodo($p['tipo'], $p['anio'], $p['indice']), 'f' => 'titulo']]];
        foreach ($contexto as $l) { $resumen[] = [['v' => $l, 'f' => 'nota']]; }
        $resumen[] = [];
        $resumen[] = array_map(static fn ($t) => ['v' => $t, 'f' => 'neg'], ['Indicador', 'Importe del periodo', 'Mismo periodo año anterior', 'Variación', 'Variación %', 'Detalle']);
        foreach ($r['kpis'] as $k) {
            $resumen[] = [$k['nombre'], ['v' => $m($k['importe']), 'f' => 'moneda'], ['v' => $m($k['previo']), 'f' => 'moneda'],
                ['v' => $k['variacion'] ? $m($k['variacion']['absoluta']) : null, 'f' => 'monedaNeg'], ['v' => $pct($k['variacion']), 'f' => 'pct'], $k['detalle']];
        }
        $resumen[] = [];
        $resumen[] = array_map(static fn ($t) => ['v' => $t, 'f' => 'neg'], ['Comparación entre años', 'Ventas realizadas', 'Operaciones', 'Piezas', 'Variación vs año anterior', 'Variación %', 'Facturadas', 'Cobros recibidos', 'Nota']);
        foreach (array_reverse($r['comparacion']) as $x) {
            $resumen[] = [$x['etiqueta'], ['v' => $m($x['realizadas']['importe']), 'f' => 'moneda'], ['v' => $x['realizadas']['operaciones'], 'f' => 'entero'], ['v' => $x['realizadas']['unidades'], 'f' => 'entero'],
                ['v' => $x['variacion'] ? $m($x['variacion']['absoluta']) : null, 'f' => 'monedaNeg'], ['v' => $pct($x['variacion']), 'f' => 'pct'],
                ['v' => $m($x['facturadas']['importe']), 'f' => 'moneda'], ['v' => $m($x['cobros']['importe']), 'f' => 'moneda'],
                $x['futuro'] ? 'Periodo futuro' : ($x['completo'] ? '' : 'INCOMPLETO al ' . $x['corte'])];
        }
        $resumen[] = [];
        $resumen[] = [['v' => 'Ventas realizadas = ventas del periodo; Facturadas = marcadas como facturadas; Cobros = dinero recibido en el periodo (con IVA). No son equivalentes.', 'f' => 'nota']];
        $resumen[] = [['v' => $r['tendencia']['etiqueta'] . ($r['tendencia']['direccion'] !== 'sin_datos' ? ' (' . $r['tendencia']['pendiente_pct'] . ' % por mes)' : ''), 'f' => 'nota']];

        $hojas = [['nombre' => 'Resumen', 'filas' => $resumen, 'anchos' => [0 => 34, 1 => 20, 2 => 24, 3 => 18, 4 => 22, 5 => 16, 6 => 16, 7 => 18, 8 => 32]]];

        $nombres = ['ventas-serie' => 'Ventas', 'ventas-anios' => 'Ventas por año', 'semestres' => 'Semestres', 'ingresos-acumulados' => 'Ingresos acumulados',
            'ventas-vs-cobros' => 'Ventas y cobros', 'cobrado-pendiente' => 'Cobrado y pendiente', 'facturacion' => 'Facturación', 'gastos-anios' => 'Gastos',
            'gastos-categoria' => 'Gastos por categoría', 'negocios' => 'Negocios', 'productos-top' => 'Productos top', 'productos-menor' => 'Productos menor mov.', 'clientes-top' => 'Clientes top'];
        $usadas = [];
        foreach ($r['graficas'] as $g) {
            // Las gráficas por negocio traen su propio nombre corto (`hoja`); Excel no admite dos hojas iguales.
            $nombre = $nombres[$g['id']] ?? ($g['hoja'] ?? mb_substr($g['titulo'], 0, 31));
            $nombre = trim(str_replace([':', '*', '?', '/', '\\', '[', ']'], ' ', $nombre));
            $base = $nombre;
            $intento = 1;
            while (isset($usadas[mb_strtolower($nombre)])) { $nombre = mb_substr($base, 0, 27) . ' (' . ++$intento . ')'; }
            $usadas[mb_strtolower($nombre)] = true;
            $hojas[] = Analisis::hojaExcel($g, $nombre, $contexto);
        }

        // Negocios: participación y variación
        if ($r['analisis']['negocios']) {
            $totalPeriodo = array_sum(array_column($r['analisis']['negocios'], 'periodo'));
            $fila = [array_map(static fn ($t) => ['v' => $t, 'f' => 'neg'], ['Negocio', 'Ventas del periodo', 'Operaciones', 'Mismo avance año anterior', 'Variación', 'Variación %', 'Participación %'])];
            foreach ($r['analisis']['negocios'] as $n) {
                $v = self::variacion($n['previo'], $n['periodo']);
                $fila[] = [$n['nombre'], ['v' => $m($n['periodo']), 'f' => 'moneda'], ['v' => $n['ventas'], 'f' => 'entero'], ['v' => $m($n['previo']), 'f' => 'moneda'],
                    ['v' => $m($v['absoluta']), 'f' => 'monedaNeg'], ['v' => $pct($v), 'f' => 'pct'], ['v' => $totalPeriodo > 0 ? round($n['periodo'] * 100 / $totalPeriodo, 2) : null, 'f' => 'pct']];
            }
            $hojas[] = ['nombre' => 'Comparación de negocios', 'filas' => array_merge([[['v' => 'Comparación entre negocios · ' . self::etiquetaPeriodo($p['tipo'], $p['anio'], $p['indice']), 'f' => 'titulo']], [['v' => 'No se aplica el filtro de negocio; el cliente y el producto sí se respetan donde aplican.', 'f' => 'nota']], []], $fila),
                'anchos' => [0 => 30, 1 => 20, 2 => 14, 3 => 24, 4 => 18, 5 => 16, 6 => 18]];
        }

        // Semestres con variación
        $fila = [[['v' => 'Semestres', 'f' => 'titulo']], [], array_map(static fn ($t) => ['v' => $t, 'f' => 'neg'], ['Año', 'Primer semestre', 'Variación %', 'Segundo semestre', 'Variación %', 'Total'])];
        foreach (self::semestresFilas($r) as $x) {
            $fila[] = [(string) $x['anio'], ['v' => $m($x['s1']), 'f' => 'moneda'], ['v' => $pct($x['var1']), 'f' => 'pct'], ['v' => $m($x['s2']), 'f' => 'moneda'], ['v' => $pct($x['var2']), 'f' => 'pct'], ['v' => $m($x['total']), 'f' => 'moneda']];
        }
        $hojas[] = ['nombre' => 'Detalle semestres', 'filas' => $fila, 'anchos' => [0 => 12, 1 => 20, 2 => 14, 3 => 20, 4 => 14, 5 => 20]];

        // Productos sin movimiento
        $sin = $r['analisis']['sin_movimiento'];
        $fila = [[['v' => 'Productos sin movimiento · ' . self::etiquetaPeriodo($p['tipo'], $p['anio'], $p['indice']), 'f' => 'titulo']],
            [['v' => $sin['total'] . ' producto(s) activo(s) sin una sola venta en el periodo (se muestran los de mayor existencia).', 'f' => 'nota']], [],
            array_map(static fn ($t) => ['v' => $t, 'f' => 'neg'], ['Producto', 'SKU', 'Existencia'])];
        foreach ($sin['lista'] as $x) { $fila[] = [$x['nombre'], $x['sku'], ['v' => $x['stock'], 'f' => 'entero']]; }
        $hojas[] = ['nombre' => 'Sin movimiento', 'filas' => $fila, 'anchos' => [0 => 40, 1 => 16, 2 => 14]];

        return Exportador::libro($hojas);
    }
}
