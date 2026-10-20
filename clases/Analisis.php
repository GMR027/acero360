<?php

namespace App;

/**
 * Analisis — consultas y definiciones de gráficas del módulo de Estadísticas.
 *
 * Cada gráfica es una «especificación» (arreglo) con los datos YA calculados con los filtros
 * elegidos. La pantalla (ECharts), el PDF (GraficaSvg) y el Excel (gráficas nativas) se dibujan
 * a partir de esa misma especificación, así que no pueden mostrar datos distintos.
 *
 * Conceptos que NO se mezclan en una serie:
 *   • Ventas realizadas   → `ventas`, por fecha de venta.
 *   • Cobros recibidos    → `cobros` vigentes, por fecha de cobro.
 *   • Cobrado / pendiente → de las ventas de cada mes, lo cobrado a la fecha y lo que falta.
 *   • Facturación         → `ingresos.estado_facturacion` (dato explícito).
 *   • Gastos              → `gastos` vigentes, por fecha del gasto.
 * Importes en centavos enteros dentro de las consultas; las especificaciones llevan pesos.
 */
class Analisis
{
    /** @var \mysqli */
    protected static $db;

    /** Colores por concepto (los mismos en pantalla, PDF y Excel). */
    public const COLOR = [
        'ventas'    => '#2f6f9f',
        'cobros'    => '#3f8f5a',
        'facturada' => '#2a9aa0',
        'pendiente' => '#d9822b',
        'no_fact'   => '#9aa5ad',
        'gastos'    => '#b4543a',
    ];

    /** Colores para años (el más reciente primero) y para negocios. */
    public const COLORES_ANIOS = ['#2f6f9f', '#d9822b', '#9aa5ad', '#7d62b3', '#3f8f5a'];
    public const COLORES_NEGOCIOS = ['#2f6f9f', '#d9822b', '#3f8f5a', '#7d62b3', '#2a9aa0', '#b4543a'];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /* --------------------------------------------------------------------
     * CONSULTAS
     * ------------------------------------------------------------------ */

    /** @return array<int, int> mes (1-12) => centavos */
    private static function meses12(string $sql, string $tipos, array $valores, string $col = 'importe'): array
    {
        $salida = array_fill(1, 12, 0);
        $c = self::$db->prepare($sql);
        $c->bind_param($tipos, ...$valores);
        $c->execute();
        foreach ($c->get_result() as $x) {
            $salida[(int) $x['mes']] = (int) $x[$col];
        }
        $c->close();

        return $salida;
    }

    /**
     * Ingresos registrados (tabla `ingresos` vigente) por mes de la operación.
     *
     * @return array<int, int> mes => centavos
     */
    public static function ingresosMensual(int $anio, array $f = [], string $base = 'total'): array
    {
        if ((int) ($f['producto'] ?? 0) > 0) {
            return array_fill(1, 12, 0);
        }
        $expresion = $base === 'subtotal' ? '(i.subtotal - i.descuento)' : 'i.total';
        $donde = ["i.estado = 'vigente'", 'YEAR(i.fecha) = ?'];
        $tipos = 'i';
        $valores = [$anio];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'i.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }
        if ((int) ($f['cliente'] ?? 0) > 0) { $donde[] = 'i.cliente_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['cliente']; }

        return self::meses12("SELECT MONTH(i.fecha) AS mes, SUM(ROUND($expresion * 100)) AS importe FROM ingresos i WHERE " . implode(' AND ', $donde) . ' GROUP BY mes', $tipos, $valores);
    }

    /**
     * De las ventas de cada mes: total, cobrado a la fecha y pendiente de cobro (siempre con IVA,
     * porque los cobros se registran con IVA).
     *
     * @return array{total: array<int,int>, cobrado: array<int,int>, pendiente: array<int,int>}
     */
    public static function cobranzaMensual(int $anio, array $f = []): array
    {
        $vacio = array_fill(1, 12, 0);
        if ((int) ($f['producto'] ?? 0) > 0) {
            return ['total' => $vacio, 'cobrado' => $vacio, 'pendiente' => $vacio];
        }
        $donde = ["i.estado = 'vigente'", 'YEAR(i.fecha) = ?'];
        $tipos = 'i';
        $valores = [$anio];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'i.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }
        if ((int) ($f['cliente'] ?? 0) > 0) { $donde[] = 'i.cliente_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['cliente']; }

        $sql = "SELECT mes, SUM(total) AS total, SUM(LEAST(cobrado, total)) AS cobrado, SUM(GREATEST(total - cobrado, 0)) AS pendiente FROM (
                    SELECT MONTH(i.fecha) AS mes, ROUND(i.total * 100) AS total,
                           COALESCE((SELECT SUM(ROUND(c.importe * 100)) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente'), 0) AS cobrado
                      FROM ingresos i WHERE " . implode(' AND ', $donde) . ') t GROUP BY mes';

        $salida = ['total' => $vacio, 'cobrado' => $vacio, 'pendiente' => $vacio];
        $c = self::$db->prepare($sql);
        $c->bind_param($tipos, ...$valores);
        $c->execute();
        foreach ($c->get_result() as $x) {
            foreach (array_keys($salida) as $k) {
                $salida[$k][(int) $x['mes']] = (int) $x[$k];
            }
        }
        $c->close();

        return $salida;
    }

    /**
     * Ventas del año por estado de facturación (dato explícito de `ingresos`).
     *
     * @return array{facturada: array<int,int>, pendiente: array<int,int>, no_facturada: array<int,int>}
     */
    public static function facturacionMensual(int $anio, array $f = [], string $base = 'total'): array
    {
        $vacio = array_fill(1, 12, 0);
        $salida = ['facturada' => $vacio, 'pendiente' => $vacio, 'no_facturada' => $vacio];
        if ((int) ($f['producto'] ?? 0) > 0) {
            return $salida;
        }
        $expresion = $base === 'subtotal' ? '(i.subtotal - i.descuento)' : 'i.total';
        $donde = ["i.estado = 'vigente'", 'YEAR(i.fecha) = ?'];
        $tipos = 'i';
        $valores = [$anio];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'i.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }
        if ((int) ($f['cliente'] ?? 0) > 0) { $donde[] = 'i.cliente_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['cliente']; }

        $c = self::$db->prepare("SELECT MONTH(i.fecha) AS mes, i.estado_facturacion AS fact, SUM(ROUND($expresion * 100)) AS importe
                                   FROM ingresos i WHERE " . implode(' AND ', $donde) . ' GROUP BY mes, fact');
        $c->bind_param($tipos, ...$valores);
        $c->execute();
        foreach ($c->get_result() as $x) {
            $clave = match ($x['fact']) { 'facturada' => 'facturada', 'no_facturada' => 'no_facturada', default => 'pendiente' };
            $salida[$clave][(int) $x['mes']] += (int) $x['importe'];
        }
        $c->close();

        return $salida;
    }

    /** Condición de negocio para gastos: solo los asignados a ese negocio (los compartidos no se reparten). */
    private static function filtroGastos(array $f, string &$tipos, array &$valores): array
    {
        $donde = ["g.estado = 'vigente'"];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'g.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }

        return $donde;
    }

    /** Color de un año: el seleccionado en el color del concepto; los anteriores en tonos distintos. */
    private static function colorAnio(int $a, int $anioBase, string $principal): string
    {
        return $a === $anioBase ? $principal : (self::COLORES_ANIOS[$anioBase - $a] ?? '#9aa5ad');
    }

    /** @return array<int, int> mes => centavos de gastos vigentes del año (con el reparto de los compartidos) */
    public static function gastosMensual(int $anio, array $f = [], string $base = 'total'): array
    {
        $salida = array_fill(1, 12, 0);
        foreach (Estadisticas::serie('gastos', 'mes', "$anio-01-01", "$anio-12-31", $f, $base) as $clave => $x) {
            $salida[(int) substr((string) $clave, 5, 2)] = $x['importe'];
        }

        return $salida;
    }

    /** @return int centavos de gastos vigentes del rango */
    public static function gastosTotal(string $desde, string $hasta, array $f = [], string $base = 'total'): int
    {
        return Estadisticas::total('gastos', $desde, $hasta, $f, $base)['importe'];
    }

    /**
     * Gastos del rango por categoría (mayor a menor).
     *
     * @return array<int, array{nombre: string, importe: int}>
     */
    public static function gastosPorCategoria(string $desde, string $hasta, array $f = [], string $base = 'total', int $limite = 8): array
    {
        $tipos = '';
        $valores = [];
        $donde = self::filtroGastos($f, $tipos, $valores);
        $donde[] = 'g.fecha BETWEEN ? AND ?';
        $tipos .= 'ss';
        $valores[] = $desde;
        $valores[] = $hasta;
        $expresion = $base === 'subtotal' ? 'g.subtotal' : 'g.total';
        $c = self::$db->prepare("SELECT COALESCE(cg.nombre, 'Sin categoría') AS categoria, SUM(ROUND($expresion * 100)) AS importe
                                   FROM gastos g LEFT JOIN categorias_gasto cg ON cg.id = g.categoria_id
                                  WHERE " . implode(' AND ', $donde) . ' GROUP BY categoria ORDER BY importe DESC');
        $c->bind_param($tipos, ...$valores);
        $c->execute();
        $filas = array_map(static fn ($x) => ['nombre' => (string) $x['categoria'], 'importe' => (int) $x['importe']], $c->get_result()->fetch_all(MYSQLI_ASSOC));
        $c->close();

        if (count($filas) > $limite) {
            $resto = array_sum(array_column(array_slice($filas, $limite - 1), 'importe'));
            $filas = array_slice($filas, 0, $limite - 1);
            $filas[] = ['nombre' => 'Otras categorías', 'importe' => $resto];
        }

        return $filas;
    }

    /**
     * Ventas por negocio: por mes del año y total del periodo, con el mismo avance del año anterior.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function porNegocio(int $anio, string $desde, string $hasta, string $desdePrev, string $hastaPrev, string $base = 'total'): array
    {
        $expresion = $base === 'subtotal' ? '(v.subtotal - v.descuento)' : 'v.total';
        $negocios = [];
        foreach (self::$db->query('SELECT id, nombre FROM empresas ORDER BY id')->fetch_all(MYSQLI_ASSOC) as $e) {
            $negocios[(int) $e['id']] = ['id' => (int) $e['id'], 'nombre' => (string) $e['nombre'], 'mensual' => array_fill(1, 12, 0), 'periodo' => 0, 'previo' => 0, 'ventas' => 0];
        }
        $c = self::$db->prepare("SELECT v.empresa_id AS e, MONTH(v.fecha) AS mes, SUM(ROUND($expresion * 100)) AS importe FROM ventas v WHERE v.estado = 'registrada' AND YEAR(v.fecha) = ? GROUP BY e, mes");
        $c->bind_param('i', $anio);
        $c->execute();
        foreach ($c->get_result() as $x) {
            if (isset($negocios[(int) $x['e']])) {
                $negocios[(int) $x['e']]['mensual'][(int) $x['mes']] = (int) $x['importe'];
            }
        }
        $c->close();

        foreach ([[$desde, $hasta, 'periodo'], [$desdePrev, $hastaPrev, 'previo']] as [$d, $h, $clave]) {
            if ($d > $h) { continue; }
            $c = self::$db->prepare("SELECT v.empresa_id AS e, SUM(ROUND($expresion * 100)) AS importe, COUNT(*) AS n FROM ventas v WHERE v.estado = 'registrada' AND v.fecha BETWEEN ? AND ? GROUP BY e");
            $c->bind_param('ss', $d, $h);
            $c->execute();
            foreach ($c->get_result() as $x) {
                if (isset($negocios[(int) $x['e']])) {
                    $negocios[(int) $x['e']][$clave] = (int) $x['importe'];
                    if ($clave === 'periodo') { $negocios[(int) $x['e']]['ventas'] = (int) $x['n']; }
                }
            }
            $c->close();
        }

        return array_values($negocios);
    }

    /**
     * Productos con menor movimiento (con ventas, de menos a más piezas).
     *
     * @return array<int, array{nombre: string, sku: string, unidades: int, importe: int}>
     */
    public static function menorMovimiento(string $desde, string $hasta, array $f = [], int $limite = 10): array
    {
        $donde = ["v.estado = 'registrada'", 'v.fecha BETWEEN ? AND ?'];
        $tipos = 'ss';
        $valores = [$desde, $hasta];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'v.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }
        if ((int) ($f['cliente'] ?? 0) > 0) { $donde[] = 'v.cliente_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['cliente']; }
        $tipos .= 'i';
        $valores[] = max(1, $limite);
        $c = self::$db->prepare('SELECT p.nombre, COALESCE(p.sku, "") AS sku, SUM(vd.cantidad) AS unidades, SUM(ROUND(vd.subtotal * 100)) AS importe
                                   FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id JOIN productos p ON p.id = vd.producto_id
                                  WHERE ' . implode(' AND ', $donde) . ' GROUP BY p.id, p.nombre, p.sku ORDER BY unidades ASC, importe ASC LIMIT ?');
        $c->bind_param($tipos, ...$valores);
        $c->execute();
        $filas = array_map(static fn ($x) => ['nombre' => (string) $x['nombre'], 'sku' => (string) $x['sku'], 'unidades' => (int) $x['unidades'], 'importe' => (int) $x['importe']], $c->get_result()->fetch_all(MYSQLI_ASSOC));
        $c->close();

        return $filas;
    }

    /**
     * Productos activos sin una sola venta en el rango.
     *
     * @return array{total: int, lista: array<int, array{nombre: string, sku: string, stock: int}>}
     */
    public static function sinMovimiento(string $desde, string $hasta, array $f = [], int $limite = 10): array
    {
        $donde = ['p.activo = 1', "NOT EXISTS (SELECT 1 FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id WHERE vd.producto_id = p.id AND v.estado = 'registrada' AND v.fecha BETWEEN ? AND ?)"];
        $tipos = 'ss';
        $valores = [$desde, $hasta];
        if ((int) ($f['empresa'] ?? 0) > 0) { $donde[] = 'p.empresa_id = ?'; $tipos .= 'i'; $valores[] = (int) $f['empresa']; }
        $c = self::$db->prepare('SELECT p.nombre, COALESCE(p.sku, "") AS sku, p.stock FROM productos p WHERE ' . implode(' AND ', $donde) . ' ORDER BY p.stock DESC, p.nombre');
        $c->bind_param($tipos, ...$valores);
        $c->execute();
        $todas = $c->get_result()->fetch_all(MYSQLI_ASSOC);
        $c->close();

        return [
            'total' => count($todas),
            'lista' => array_map(static fn ($x) => ['nombre' => (string) $x['nombre'], 'sku' => (string) $x['sku'], 'stock' => (int) $x['stock']], array_slice($todas, 0, max(1, $limite))),
        ];
    }

    /* --------------------------------------------------------------------
     * ESPECIFICACIONES DE GRÁFICAS
     * ------------------------------------------------------------------ */

    /** @param array<int,int> $centavos mes => centavos  @return float[] pesos, 12 posiciones */
    private static function pesos(array $centavos): array
    {
        return array_map(static fn ($c) => round($c / 100, 2), array_values($centavos));
    }

    /** Pone null en los meses que aún no ocurren del año en curso (la línea no cae a cero). @return array<int, ?float> */
    private static function sinFuturo(array $pesos, int $anioSerie, string $hoy): array
    {
        $anioHoy = (int) substr($hoy, 0, 4);
        $mesHoy = (int) substr($hoy, 5, 2);
        foreach (array_keys($pesos) as $i) {
            if ($anioSerie > $anioHoy || ($anioSerie === $anioHoy && $i + 1 > $mesHoy)) {
                $pesos[$i] = null;
            }
        }

        return $pesos;
    }

    /** @return string[] */
    public static function mesesCortos(): array
    {
        return array_map(static fn ($i) => mb_substr(nombreMes($i), 0, 3), range(1, 12));
    }

    /** Meses (1-12) que cubre el periodo elegido, para resaltarlos; null si es el año completo. */
    private static function mesesDelPeriodo(string $tipo, int $indice): ?array
    {
        return match ($tipo) {
            'mes' => [$indice, $indice],
            'trimestre' => [($indice - 1) * 3 + 1, $indice * 3],
            'semestre' => $indice === 2 ? [7, 12] : [1, 6],
            default => null,
        };
    }

    /**
     * Datos de todos los análisis, listos para las especificaciones.
     *
     * @param array<string, mixed> $p parámetros normalizados de Estadisticas::parametros()
     * @return array<string, mixed>
     */
    public static function datos(array $p, array $comparacion): array
    {
        $f = $p['filtros'];
        $base = $p['base'];
        $anio = $p['anio'];
        $anios = range($anio - $p['atras'], $anio);
        [$pd, $ph] = Estadisticas::rango($p['tipo'], $anio, $p['indice']);
        $ph = min($ph, $p['hoy']);

        // Mismo avance del año anterior para comparar negocios.
        [$pd1, $ph1] = Estadisticas::rango($p['tipo'], $anio - 1, $p['indice']);
        $ph1 = min($ph1, Estadisticas::mismaFechaEnAnio($ph, $anio - 1));

        $ventasAnios = [];
        $ingresosAnios = [];
        $gastosAnios = [];
        foreach ($anios as $a) {
            $ventas = Estadisticas::serie('realizadas', 'mes', "$a-01-01", "$a-12-31", $f, $base);
            $m = array_fill(1, 12, 0);
            foreach ($ventas as $clave => $x) { $m[(int) substr((string) $clave, 5, 2)] = $x['importe']; }
            $ventasAnios[$a] = $m;
            $ingresosAnios[$a] = self::ingresosMensual($a, $f, $base);
            $gastosAnios[$a] = self::gastosMensual($a, $f, $base);
        }

        $cobrosMes = array_fill(1, 12, 0);
        foreach (Estadisticas::serie('cobros', 'mes', "$anio-01-01", "$anio-12-31", $f, $base) as $clave => $x) {
            $cobrosMes[(int) substr((string) $clave, 5, 2)] = $x['importe'];
        }

        $semestres = [];
        foreach (Estadisticas::serie('realizadas', 'semestre', ($anio - $p['atras']) . '-01-01', "$anio-12-31", $f, $base) as $clave => $x) {
            $semestres[(int) substr((string) $clave, 0, 4)][(int) substr((string) $clave, -1)] = $x['importe'];
        }

        return [
            'anios' => $anios,
            'ventas_anios' => $ventasAnios,
            'ingresos_anios' => $ingresosAnios,
            'gastos_anios' => $gastosAnios,
            'cobros_mes' => $cobrosMes,
            'cobranza' => self::cobranzaMensual($anio, $f),
            'facturacion' => self::facturacionMensual($anio, $f, $base),
            'gastos_categoria' => self::gastosPorCategoria($pd, $ph, $f, $base),
            'gastos_periodo' => self::gastosTotal($pd, $ph, $f, $base),
            'gastos_previo' => self::gastosTotal($pd1, $ph1, $f, $base),
            'negocios' => self::porNegocio($anio, $pd, $ph, $pd1, $ph1, $base),
            'semestres' => $semestres,
            'menor_movimiento' => self::menorMovimiento($pd, $ph, $f, 10),
            'sin_movimiento' => self::sinMovimiento($pd, $ph, $f, 10),
            'rango_previo' => [$pd1, $ph1],
        ];
    }

    /**
     * Especificaciones de gráficas: id, sección, título, tipo, categorías y series con datos en pesos.
     *
     * @param array<string, mixed> $r Estadisticas::reporte()
     * @return array<int, array<string, mixed>>
     */
    public static function graficas(array $r): array
    {
        $p = $r['parametros'];
        $d = $r['analisis'];
        $f = $p['filtros'];
        $anio = $p['anio'];
        $meses = self::mesesCortos();
        $resaltar = self::mesesDelPeriodo($p['tipo'], $p['indice']);
        $conProducto = $f['producto'] > 0;
        $sinIva = $p['base'] === 'subtotal' ? ' (sin IVA)' : ' (con IVA)';
        $g = [];

        // ---- Ventas ------------------------------------------------------
        $g[] = self::especSerieVentas($r);

        $series = [];
        foreach ($d['anios'] as $a) {
            $series[] = ['nombre' => (string) $a, 'datos' => self::sinFuturo(self::pesos($d['ventas_anios'][$a]), $a, $p['hoy']), 'color' => self::colorAnio($a, $anio, self::COLOR['ventas'])];
        }
        $g[] = ['id' => 'ventas-anios', 'seccion' => 'ventas', 'titulo' => 'Ventas mensuales · comparación entre años', 'subtitulo' => 'Ventas realizadas' . $sinIva . ', mes contra mes',
            'tipo' => 'linea', 'formato' => 'moneda', 'categorias' => $meses, 'series' => $series, 'resaltar' => $resaltar, 'comparaSeries' => true];

        // Semestres
        $s1 = $s2 = [];
        foreach ($d['anios'] as $a) { $s1[] = round(($d['semestres'][$a][1] ?? 0) / 100, 2); $s2[] = round(($d['semestres'][$a][2] ?? 0) / 100, 2); }
        $g[] = ['id' => 'semestres', 'seccion' => 'ventas', 'titulo' => 'Comparación entre semestres', 'subtitulo' => 'Ventas realizadas' . $sinIva . ' por semestre',
            'tipo' => 'barras', 'formato' => 'moneda', 'categorias' => array_map('strval', $d['anios']),
            'series' => [['nombre' => 'Primer semestre', 'datos' => $s1, 'color' => self::COLORES_ANIOS[0]], ['nombre' => 'Segundo semestre', 'datos' => $s2, 'color' => self::COLORES_ANIOS[1]]], 'comparaSeries' => true];

        // Ingresos acumulados
        $series = [];
        $mesActual = (int) substr($p['hoy'], 5, 2);
        $anioActual = (int) substr($p['hoy'], 0, 4);
        foreach ($d['anios'] as $a) {
            $acum = 0;
            $datos = [];
            foreach (range(1, 12) as $m) {
                $acum += $d['ingresos_anios'][$a][$m];
                $datos[] = ($a === $anioActual && $m > $mesActual) || $a > $anioActual ? null : round($acum / 100, 2);
            }
            $series[] = ['nombre' => (string) $a, 'datos' => $datos, 'color' => self::colorAnio($a, $anio, self::COLOR['ventas'])];
        }
        $g[] = ['id' => 'ingresos-acumulados', 'seccion' => 'ingresos', 'titulo' => 'Evolución de ingresos · acumulado del año', 'subtitulo' => 'Ingresos registrados' . $sinIva . ', acumulados mes a mes',
            'tipo' => 'linea', 'formato' => 'moneda', 'categorias' => $meses, 'series' => $series, 'comparaSeries' => true, 'acumulado' => true];

        // ---- Cobros y saldos ---------------------------------------------
        $g[] = ['id' => 'ventas-vs-cobros', 'seccion' => 'ingresos', 'titulo' => 'Ventas realizadas y cobros recibidos · ' . $anio,
            'subtitulo' => 'Son conceptos distintos: la venta se cuenta el día que se hace; el cobro, el día que el cliente paga (siempre con IVA)',
            'tipo' => 'barras', 'formato' => 'moneda', 'categorias' => $meses, 'resaltar' => $resaltar,
            'series' => [
                ['nombre' => 'Ventas realizadas' . $sinIva, 'datos' => self::pesos($d['ventas_anios'][$anio]), 'color' => self::COLOR['ventas']],
                ['nombre' => 'Cobros recibidos (fecha de cobro)', 'datos' => self::pesos($d['cobros_mes']), 'color' => self::COLOR['cobros']],
            ]];
        $g[] = ['id' => 'cobrado-pendiente', 'seccion' => 'ingresos', 'titulo' => 'Cobrado y saldo pendiente de las ventas de ' . $anio,
            'subtitulo' => 'De las ventas de cada mes (con IVA): lo que ya se cobró a la fecha y lo que falta por cobrar',
            'tipo' => 'apilado', 'formato' => 'moneda', 'categorias' => $meses, 'resaltar' => $resaltar,
            'series' => [
                ['nombre' => 'Cobrado', 'datos' => self::pesos($d['cobranza']['cobrado']), 'color' => self::COLOR['cobros']],
                ['nombre' => 'Saldo pendiente', 'datos' => self::pesos($d['cobranza']['pendiente']), 'color' => self::COLOR['pendiente']],
            ]];

        // ---- Facturación -------------------------------------------------
        $g[] = ['id' => 'facturacion', 'seccion' => 'ingresos', 'titulo' => 'Ventas facturadas y pendientes de facturar · ' . $anio,
            'subtitulo' => 'Según el estado de facturación registrado en cada venta' . $sinIva,
            'tipo' => 'apilado', 'formato' => 'moneda', 'categorias' => $meses, 'resaltar' => $resaltar,
            'series' => [
                ['nombre' => 'Facturadas', 'datos' => self::pesos($d['facturacion']['facturada']), 'color' => self::COLOR['facturada']],
                ['nombre' => 'Pendientes de facturar', 'datos' => self::pesos($d['facturacion']['pendiente']), 'color' => self::COLOR['pendiente']],
                ['nombre' => 'Sin factura (no aplica)', 'datos' => self::pesos($d['facturacion']['no_facturada']), 'color' => self::COLOR['no_fact']],
            ]];

        // ---- Gastos ------------------------------------------------------
        $series = [];
        foreach ($d['anios'] as $a) {
            $series[] = ['nombre' => (string) $a, 'datos' => self::sinFuturo(self::pesos($d['gastos_anios'][$a]), $a, $p['hoy']), 'color' => self::colorAnio($a, $anio, self::COLOR['gastos'])];
        }
        $g[] = ['id' => 'gastos-anios', 'seccion' => 'gastos', 'titulo' => 'Evolución de gastos · comparación entre años',
            'subtitulo' => 'Gastos vigentes' . ($p['base'] === 'subtotal' ? ' (sin IVA)' : ' (con IVA)') . ($f['empresa'] > 0 ? ' · con el negocio elegido: sus gastos y su parte de los compartidos' : ''),
            'tipo' => 'linea', 'formato' => 'moneda', 'categorias' => $meses, 'series' => $series, 'resaltar' => $resaltar, 'comparaSeries' => true];
        if ($d['gastos_categoria']) {
            $g[] = ['id' => 'gastos-categoria', 'seccion' => 'gastos', 'titulo' => 'Gastos por categoría · ' . Estadisticas::etiquetaPeriodo($p['tipo'], $anio, $p['indice']),
                'subtitulo' => 'Reparto del gasto del periodo',
                'tipo' => 'barras-h', 'formato' => 'moneda', 'categorias' => array_column($d['gastos_categoria'], 'nombre'),
                'series' => [['nombre' => 'Gasto', 'datos' => array_map(static fn ($x) => round($x['importe'] / 100, 2), $d['gastos_categoria']), 'color' => self::COLOR['gastos']]]];
        }

        // ---- Negocios ----------------------------------------------------
        $series = [];
        foreach ($d['negocios'] as $i => $n) {
            $series[] = ['nombre' => $n['nombre'], 'datos' => self::pesos($n['mensual']), 'color' => self::COLORES_NEGOCIOS[$i % count(self::COLORES_NEGOCIOS)]];
        }
        if ($series) {
            $g[] = ['id' => 'negocios', 'seccion' => 'negocios', 'titulo' => 'Ventas por negocio · ' . $anio, 'subtitulo' => 'Ventas realizadas' . $sinIva . '; no se aplica el filtro de negocio para poder compararlos',
                'tipo' => 'barras', 'formato' => 'moneda', 'categorias' => $meses, 'series' => $series, 'resaltar' => $resaltar];
        }

        // ---- Productos ---------------------------------------------------
        if ($r['productos']) {
            $g[] = ['id' => 'productos-top', 'seccion' => 'productos', 'titulo' => 'Productos con mayor movimiento', 'subtitulo' => 'Importe de renglones sin IVA · ' . Estadisticas::etiquetaPeriodo($p['tipo'], $anio, $p['indice']),
                'tipo' => 'barras-h', 'formato' => 'moneda', 'categorias' => array_column($r['productos'], 'nombre'),
                'series' => [['nombre' => 'Importe', 'datos' => array_map(static fn ($x) => round($x['importe'] / 100, 2), $r['productos']), 'color' => self::COLOR['ventas']]],
                'extra' => ['Piezas' => array_column($r['productos'], 'unidades')]];
        }
        if ($d['menor_movimiento']) {
            $g[] = ['id' => 'productos-menor', 'seccion' => 'productos', 'titulo' => 'Productos con menor movimiento', 'subtitulo' => 'Productos que sí se vendieron, de menos a más piezas · piezas vendidas',
                'tipo' => 'barras-h', 'formato' => 'entero', 'categorias' => array_column($d['menor_movimiento'], 'nombre'),
                'series' => [['nombre' => 'Piezas', 'datos' => array_column($d['menor_movimiento'], 'unidades'), 'color' => self::COLOR['no_fact']]]];
        }
        if ($r['clientes']) {
            $g[] = ['id' => 'clientes-top', 'seccion' => 'negocios', 'titulo' => 'Clientes con mayor volumen', 'subtitulo' => 'Ventas realizadas' . $sinIva . ' · ' . Estadisticas::etiquetaPeriodo($p['tipo'], $anio, $p['indice']),
                'tipo' => 'barras-h', 'formato' => 'moneda', 'categorias' => array_column($r['clientes'], 'nombre'),
                'series' => [['nombre' => 'Importe', 'datos' => array_map(static fn ($x) => round($x['importe'] / 100, 2), $r['clientes']), 'color' => self::COLOR['ventas']]]];
        }

        // Con producto elegido solo se miden renglones: las gráficas que no se pueden repartir por producto salen vacías.
        if ($conProducto) {
            $g = array_values(array_filter($g, static fn ($x) => !in_array($x['id'], ['ventas-vs-cobros', 'cobrado-pendiente', 'facturacion', 'ingresos-acumulados'], true)));
        }

        return $g;
    }

    /**
     * Tabla de una gráfica: encabezados, filas y fila de total (null si no aplica).
     * Si las series son años, añade la variación del último contra el anterior.
     *
     * @return array{0: string[], 1: array<int, array<int, mixed>>, 2: ?array<int, mixed>}
     */
    public static function tabla(array $e): array
    {
        $series = $e['series'];
        $enc = [$e['tipo'] === 'barras-h' ? 'Concepto' : 'Periodo'];
        foreach ($series as $s) { $enc[] = $s['nombre']; }
        $extra = $e['extra'] ?? [];
        foreach (array_keys($extra) as $nombre) { $enc[] = $nombre; }
        $conVar = !empty($e['comparaSeries']) && count($series) > 1;
        if ($conVar) {
            $enc[] = 'Variación ' . $series[count($series) - 1]['nombre'] . ' vs ' . $series[count($series) - 2]['nombre'] . ' (%)';
        }

        $filas = [];
        foreach ($e['categorias'] as $i => $cat) {
            $fila = [(string) $cat];
            foreach ($series as $s) { $fila[] = $s['datos'][$i] ?? null; }
            foreach ($extra as $valores) { $fila[] = $valores[$i] ?? null; }
            if ($conVar) {
                $a = (float) ($series[count($series) - 2]['datos'][$i] ?? 0);
                $bruto = $series[count($series) - 1]['datos'][$i] ?? null;
                $fila[] = $a > 0 && $bruto !== null ? round(((float) $bruto - $a) * 100 / $a, 2) : null;
            }
            $filas[] = $fila;
        }

        $total = null;
        if (empty($e['acumulado']) && ($e['tipo'] !== 'barras-h' || ($e['id'] ?? '') === 'gastos-categoria')) {
            $total = ['Total'];
            foreach ($series as $s) { $total[] = round(array_sum(array_map('floatval', $s['datos'])), 2); }
            foreach ($extra as $valores) { $total[] = array_sum($valores); }
            if ($conVar) {
                $n = count($series);
                $total[] = $total[$n - 1] > 0 ? round(($total[$n] - $total[$n - 1]) * 100 / $total[$n - 1], 2) : null;
            }
        }

        return [$enc, $filas, $total];
    }

    /**
     * Hoja de Excel de una gráfica: título, contexto (periodo y filtros), tabla y gráfica nativa.
     *
     * @return array<string, mixed>
     */
    public static function hojaExcel(array $e, string $nombre, array $contexto): array
    {
        [$enc, $filas, $total] = self::tabla($e);
        $moneda = ($e['formato'] ?? 'moneda') === 'moneda';
        $f = $moneda ? 'moneda' : 'entero';
        $nSeries = count($e['series']);
        $nExtra = count($e['extra'] ?? []);
        $filasHoja = [[['v' => $e['titulo'], 'f' => 'titulo']], [['v' => $e['subtitulo'] ?? '', 'f' => 'nota']]];
        foreach ($contexto as $linea) { $filasHoja[] = [['v' => $linea, 'f' => 'nota']]; }
        $filasHoja[] = [];
        $filaEnc = count($filasHoja) + 1;
        $filasHoja[] = array_map(static fn ($t) => ['v' => $t, 'f' => 'neg'], $enc);

        $celdas = static function (array $fila) use ($f, $nSeries, $nExtra): array {
            $o = [];
            foreach ($fila as $c => $v) {
                if ($c === 0) { $o[] = ['v' => $v, 'f' => $v === 'Total' ? 'neg' : null]; continue; }
                $o[] = $c <= $nSeries ? ['v' => is_numeric($v) ? (float) $v : null, 'f' => $f]
                    : ($c <= $nSeries + $nExtra ? ['v' => $v, 'f' => 'entero'] : ['v' => $v === null ? null : (float) $v, 'f' => 'pct']);
            }

            return $o;
        };
        foreach ($filas as $fila) { $filasHoja[] = $celdas($fila); }
        if ($total) { $filasHoja[] = $celdas($total); }

        $series = [];
        foreach ($e['series'] as $i => $s) { $series[] = ['col' => $i + 1, 'color' => $s['color']]; }

        return [
            'nombre' => $nombre,
            'filas' => $filasHoja,
            'anchos' => [0 => 34] + array_fill(1, max(1, count($enc) - 1), 18),
            'grafica' => ['tipo' => $e['tipo'], 'titulo' => $e['titulo'], 'formato' => $e['formato'] ?? 'moneda', 'fila_enc' => $filaEnc,
                'fila_ini' => $filaEnc + 1, 'fila_fin' => $filaEnc + count($filas), 'series' => $series, 'col_ancla' => count($enc) + 1, 'fila_ancla' => $filaEnc - 1],
        ];
    }

    /** Serie de ventas según la agrupación elegida (día, mes, trimestre, semestre, año). */
    private static function especSerieVentas(array $r): array
    {
        $p = $r['parametros'];
        $sinIva = $p['base'] === 'subtotal' ? ' (sin IVA)' : ' (con IVA)';
        $espec = ['id' => 'ventas-serie', 'seccion' => 'ventas', 'titulo' => 'Ventas por ' . mb_strtolower(Estadisticas::AGRUPACIONES[$p['agrupacion']]),
            'subtitulo' => 'Ventas realizadas' . $sinIva . ($p['filtros']['producto'] > 0 ? ' · solo renglones del producto elegido' : ''),
            'tipo' => count($r['serie']) > 18 ? 'linea' : 'barras', 'formato' => 'moneda', 'categorias' => array_map('strval', array_keys($r['serie'])),
            'series' => [['nombre' => 'Ventas realizadas', 'datos' => array_map(static fn ($x) => round($x['importe'] / 100, 2), array_values($r['serie'])), 'color' => self::COLOR['ventas']]]];

        // Mes, trimestre o semestre con varios años: una serie por año sobre las mismas posiciones.
        if (in_array($p['agrupacion'], ['mes', 'trimestre', 'semestre'], true) && $r['serie']) {
            $posiciones = ['mes' => 12, 'trimestre' => 4, 'semestre' => 2][$p['agrupacion']];
            $porAnio = [];
            foreach ($r['serie'] as $clave => $x) {
                $a = (int) substr((string) $clave, 0, 4);
                $pos = $p['agrupacion'] === 'mes' ? (int) substr((string) $clave, 5, 2) : (int) substr((string) $clave, -1);
                $porAnio[$a] ??= array_fill(1, $posiciones, 0.0);
                $porAnio[$a][$pos] = round($x['importe'] / 100, 2);
            }
            ksort($porAnio);
            if (count($porAnio) > 1) {
                $espec['tipo'] = 'barras';
                $espec['comparaSeries'] = true;
                $espec['categorias'] = array_map(static fn ($i) => match ($p['agrupacion']) { 'mes' => mb_substr(nombreMes($i), 0, 3), 'trimestre' => 'T' . $i, default => 'S' . $i }, range(1, $posiciones));
                $espec['series'] = [];
                foreach ($porAnio as $a => $vals) {
                    $espec['series'][] = ['nombre' => (string) $a, 'datos' => array_values($vals), 'color' => self::colorAnio((int) $a, $p['anio'], self::COLOR['ventas'])];
                }
            }
        }

        return $espec;
    }
}
