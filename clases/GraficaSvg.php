<?php

namespace App;

/**
 * GraficaSvg — dibuja en SVG (sin JavaScript) las especificaciones de Analisis::graficas().
 *
 * Es lo que lleva el PDF: se dibuja en el servidor con los mismos datos y filtros de la pantalla,
 * así la gráfica del reporte nunca puede diferir de la tabla que la acompaña.
 * Tipos: barras (agrupadas), apilado, linea, barras-h.
 */
class GraficaSvg
{
    private const TEXTO = '#4d4c53';
    private const TENUE = '#64646a';
    private const REJILLA = '#e4e6ea';

    private static function esc(string $t): string
    {
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Importe corto para ejes y etiquetas. */
    public static function corto(float $v, string $formato): string
    {
        $abs = abs($v);
        $pref = $formato === 'moneda' ? '$' : '';
        if ($abs >= 1000000) { return $pref . rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . ' M'; }
        if ($abs >= 1000) { return $pref . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . ' mil'; }

        return $pref . number_format($v, 0);
    }

    /** Máximo «redondo» (1, 2, 5 × 10^n) para el eje. */
    private static function techo(float $v): float
    {
        if ($v <= 0) { return 1.0; }
        $exp = 10 ** floor(log10($v));
        $fr = $v / $exp;

        return ($fr <= 1 ? 1 : ($fr <= 2 ? 2 : ($fr <= 5 ? 5 : 10))) * $exp;
    }

    private static function recortar(string $t, int $max): string
    {
        return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
    }

    /** @param array<string, mixed> $e especificación de Analisis::graficas() */
    public static function dibujar(array $e, int $ancho = 640, int $alto = 250): string
    {
        $horizontal = $e['tipo'] === 'barras-h';
        $cats = array_values($e['categorias']);
        $series = $e['series'];
        $n = count($cats);
        if (!$n || !$series) {
            return '';
        }
        if ($horizontal) {
            $alto = max(120, 30 * $n + 20);
        }
        $leyenda = count($series) > 1 ? 26 : 0;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $ancho . '" height="' . ($alto + $leyenda) . '" viewBox="0 0 ' . $ancho . ' ' . ($alto + $leyenda) . '" font-family="DejaVu Sans">';

        $formato = $e['formato'] ?? 'moneda';
        if ($horizontal) {
            $svg .= self::horizontal($e, $cats, $series[0], $ancho, $alto, $formato);
        } else {
            $svg .= self::cartesiana($e, $cats, $series, $ancho, $alto, $formato);
        }

        if ($leyenda) {
            $x = 8;
            foreach ($series as $s) {
                $svg .= '<rect x="' . $x . '" y="' . ($alto + 6) . '" width="10" height="10" rx="2" fill="' . self::esc($s['color']) . '"/>'
                    . '<text x="' . ($x + 14) . '" y="' . ($alto + 17) . '" font-size="11" fill="' . self::TEXTO . '">' . self::esc(self::recortar($s['nombre'], 34)) . '</text>';
                $x += 28 + 6.4 * min(34, mb_strlen($s['nombre']));
            }
        }

        return $svg . '</svg>';
    }

    private static function horizontal(array $e, array $cats, array $serie, int $ancho, int $alto, string $formato): string
    {
        $izq = 190;
        $der = 70;
        $max = max(array_merge($serie['datos'], [1]));
        $fila = 30;
        $svg = '';
        foreach ($cats as $i => $cat) {
            $y = 6 + $i * $fila;
            $v = (float) $serie['datos'][$i];
            $largo = max(2, $v / $max * ($ancho - $izq - $der));
            $svg .= '<text x="' . ($izq - 8) . '" y="' . ($y + 16) . '" font-size="11" text-anchor="end" fill="' . self::TEXTO . '">' . self::esc(self::recortar((string) $cat, 28)) . '</text>'
                . '<rect x="' . $izq . '" y="' . ($y + 4) . '" width="' . round($largo, 1) . '" height="16" rx="3" fill="' . self::esc($serie['color']) . '" fill-opacity="' . round(max(0.45, 1 - $i * 0.05), 2) . '"/>'
                . '<text x="' . round($izq + $largo + 6, 1) . '" y="' . ($y + 16) . '" font-size="11" font-weight="bold" fill="' . self::TEXTO . '">' . self::esc(self::corto($v, $formato)) . '</text>';
        }

        return $svg;
    }

    private static function cartesiana(array $e, array $cats, array $series, int $ancho, int $alto, string $formato): string
    {
        $m = ['t' => 14, 'r' => 12, 'b' => 28, 'l' => 62];
        $aw = $ancho - $m['l'] - $m['r'];
        $ah = $alto - $m['t'] - $m['b'];
        $n = count($cats);
        $apilado = $e['tipo'] === 'apilado';

        $tope = 0.0;
        if ($apilado) {
            for ($i = 0; $i < $n; $i++) {
                $tope = max($tope, array_sum(array_map(static fn ($s) => (float) ($s['datos'][$i] ?? 0), $series)));
            }
        } else {
            foreach ($series as $s) {
                $tope = max($tope, max(array_map('floatval', array_filter($s['datos'], static fn ($v) => $v !== null)) ?: [0]));
            }
        }
        $max = self::techo($tope);
        $y = static fn (float $v): float => $m['t'] + $ah - $v / $max * $ah;
        $paso = $aw / $n;
        $svg = '';

        // Franja del periodo elegido
        if (!empty($e['resaltar'])) {
            [$a, $b] = $e['resaltar'];
            $svg .= '<rect x="' . round($m['l'] + ($a - 1) * $paso, 1) . '" y="' . $m['t'] . '" width="' . round(($b - $a + 1) * $paso, 1) . '" height="' . $ah . '" fill="#4679a6" fill-opacity="0.07"/>';
        }
        for ($i = 0; $i <= 4; $i++) {
            $v = $max / 4 * $i;
            $yy = round($y($v), 1);
            $svg .= '<line x1="' . $m['l'] . '" x2="' . ($ancho - $m['r']) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="' . self::REJILLA . '" stroke-width="1"/>'
                . '<text x="' . ($m['l'] - 5) . '" y="' . ($yy + 3) . '" font-size="10" text-anchor="end" fill="' . self::TENUE . '">' . self::esc(self::corto($v, $formato)) . '</text>';
        }
        $cada = max(1, (int) ceil($n / 14));
        foreach ($cats as $i => $c) {
            if ($i % $cada === 0) {
                $svg .= '<text x="' . round($m['l'] + $paso * $i + $paso / 2, 1) . '" y="' . ($alto - $m['b'] + 14) . '" font-size="10" text-anchor="middle" fill="' . self::TENUE . '">' . self::esc(self::recortar((string) $c, 12)) . '</text>';
            }
        }

        if ($e['tipo'] === 'linea') {
            foreach ($series as $k => $s) {
                $pts = [];
                foreach ($s['datos'] as $i => $v) {
                    if ($v !== null) { $pts[] = [round($m['l'] + $paso * $i + $paso / 2, 1), round($y((float) $v), 1)]; }
                }
                if (count($pts) < 1) { continue; }
                $color = self::esc($s['color']);
                if ($k === count($series) - 1 && count($pts) > 1) {
                    $base = round($y(0), 1);
                    $svg .= '<path d="M' . $pts[0][0] . ',' . $base . ' L' . implode(' L', array_map(static fn ($p) => $p[0] . ',' . $p[1], $pts)) . ' L' . end($pts)[0] . ',' . $base . ' Z" fill="' . $color . '" fill-opacity="0.12"/>';
                }
                $svg .= '<polyline points="' . implode(' ', array_map(static fn ($p) => $p[0] . ',' . $p[1], $pts)) . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round"/>';
                if ($n <= 24) {
                    foreach ($pts as $p) {
                        $svg .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="2.6" fill="#fff" stroke="' . $color . '" stroke-width="1.5"/>';
                    }
                }
            }

            return $svg;
        }

        $k = count($series);
        $grupo = min($paso * 0.74, 46 * $k);
        $ancho1 = $apilado ? $grupo : $grupo / $k;
        $conValor = $n * ($apilado ? 1 : $k) <= 14;
        for ($i = 0; $i < $n; $i++) {
            $acum = 0.0;
            foreach ($series as $j => $s) {
                $v = (float) ($s['datos'][$i] ?? 0);
                if ($v <= 0) { continue; }
                $x = $m['l'] + $paso * $i + ($paso - $grupo) / 2 + ($apilado ? 0 : $ancho1 * $j);
                $top = $y($apilado ? $acum + $v : $v);
                $h = $apilado ? $y($acum) - $top : $y(0) - $top;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($top, 1) . '" width="' . round(max(2, $ancho1 - 2), 1) . '" height="' . round(max(1, $h), 1) . '" rx="2" fill="' . self::esc($s['color']) . '"/>';
                if ($conValor && !$apilado) {
                    $svg .= '<text x="' . round($x + ($ancho1 - 2) / 2, 1) . '" y="' . round($top - 3, 1) . '" font-size="9.5" text-anchor="middle" font-weight="bold" fill="' . self::TEXTO . '">' . self::esc(self::corto($v, $formato)) . '</text>';
                }
                $acum += $v;
            }
        }

        return $svg;
    }

    /** SVG como imagen incrustada (data URI) para el PDF. */
    public static function imagen(array $e, int $ancho = 640, int $alto = 250): string
    {
        $svg = self::dibujar($e, $ancho, $alto);
        if ($svg === '') {
            return '';
        }

        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="width:100%" alt="' . self::esc($e['titulo']) . '">';
    }
}
