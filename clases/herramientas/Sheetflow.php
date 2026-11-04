<?php

namespace Classes;

use Model\SheetflowHoja;

/**
 * SheetFlow: tablas compartidas. Un documento guarda un JSON con
 *   cols  [{n nombre, t tipo, a alineación, tot total}]
 *   rows  [[texto crudo de cada celda]]      (una celda que empieza con «=» es una fórmula)
 *   calc  {"fila,col": resultado}            (resultado de las fórmulas; lo calcula el cliente)
 *   tot   {col: resultado}                   (fila de totales; lo calcula el cliente)
 *   estilo {enc, encTxt, zebra, bordes}
 * Todo lo que llega del cliente pasa por limpiar().
 */
class Sheetflow
{
    public const TIPOS = ['texto' => 'Texto', 'numero' => 'Número', 'moneda' => 'Moneda', 'porcentaje' => 'Porcentaje', 'fecha' => 'Fecha', 'lista' => 'Lista desplegable', 'casilla' => 'Casilla (sí/no)', 'semaforo' => 'Semáforo'];
    public const SEMAFORO = ['verde' => 'Verde', 'amarillo' => 'Amarillo', 'rojo' => 'Rojo'];
    public const MAX_OPCIONES = 20;
    public const ALINEAR = ['izquierda', 'centro', 'derecha'];
    public const TOTALES = ['' => 'Sin total', 'suma' => 'Suma', 'promedio' => 'Promedio', 'min' => 'Mínimo', 'max' => 'Máximo', 'cuenta' => 'Cuenta'];
    public const MAX_COLS = 30;
    public const MAX_FILAS = 1000;
    public const MAX_CELDA = 500;
    public const MAX_BYTES = 2_000_000;

    /** Filas por tabla al componer el PDF de una hoja grande (ver tabla()). */
    private const FILAS_POR_BLOQUE = 200;

    private static function hex($c, string $def): string
    {
        return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $def;
    }

    /** Hoja nueva: n columnas de texto y filas vacías */
    public static function vacia(int $cols = 4, int $filas = 10): array
    {
        return self::limpiar([
            'cols' => array_map(fn($i) => ['n' => 'Columna ' . ($i + 1)], range(0, $cols - 1)),
            'rows' => array_fill(0, $filas, array_fill(0, $cols, '')),
        ]);
    }

    /** Estructura válida y acotada a partir de lo que sea que llegue */
    public static function limpiar($d): array
    {
        $d = is_array($d) ? $d : [];
        $cols = [];
        foreach (array_slice(array_values((array)($d['cols'] ?? [])), 0, self::MAX_COLS) as $c) {
            $c = is_array($c) ? $c : [];
            $t = isset(self::TIPOS[$c['t'] ?? '']) ? $c['t'] : 'texto';
            $numerico = in_array($t, ['numero', 'moneda', 'porcentaje'], true);
            $a = in_array($c['a'] ?? '', self::ALINEAR, true) ? $c['a'] : ($numerico ? 'derecha' : (in_array($t, ['casilla', 'semaforo'], true) ? 'centro' : 'izquierda'));
            $tot = (string)($c['tot'] ?? '');
            $cols[] = [
                'n' => mb_substr(trim(preg_replace('/[\x00-\x1f]/u', ' ', (string)($c['n'] ?? ''))), 0, 60),
                't' => $t, 'a' => $a,
                'tot' => isset(self::TOTALES[$tot]) && ($numerico || $tot === 'cuenta') ? $tot : '',
                'w' => isset($c['w']) && is_numeric($c['w']) && $c['w'] >= 40 ? (int)min(800, $c['w']) : 0,
            ];
            if ($t === 'lista') $cols[count($cols) - 1]['op'] = self::opciones($c['op'] ?? []);
        }
        if (!$cols) $cols[] = ['n' => 'Columna 1', 't' => 'texto', 'a' => 'izquierda', 'tot' => ''];
        $nc = count($cols);
        $rows = [];
        foreach (array_slice(array_values((array)($d['rows'] ?? [])), 0, self::MAX_FILAS) as $f) {
            $f = array_values((array)$f);
            $fila = [];
            for ($c = 0; $c < $nc; $c++) $fila[] = mb_substr(str_replace("\r", '', (string)($f[$c] ?? '')), 0, self::MAX_CELDA);
            $rows[] = $fila;
        }
        $nr = count($rows);
        $calc = [];
        foreach ((array)($d['calc'] ?? []) as $k => $v) {
            if (!preg_match('/^(\d+),(\d+)$/', (string)$k, $m) || $m[1] >= $nr || $m[2] >= $nc || !str_starts_with(ltrim($rows[$m[1]][$m[2]]), '=')) continue;
            $calc[$k] = mb_substr((string)$v, 0, 60);
        }
        $tot = [];
        foreach ((array)($d['tot'] ?? []) as $k => $v) {
            if (is_numeric($k) && $k >= 0 && $k < $nc && $cols[(int)$k]['tot'] !== '') $tot[(int)$k] = mb_substr((string)$v, 0, 60);
        }
        $e = (array)($d['estilo'] ?? []);
        $estilo = ['enc' => self::hex($e['enc'] ?? '', '#1f3a5f'), 'encTxt' => self::hex($e['encTxt'] ?? '', '#ffffff'),
            'zebra' => !array_key_exists('zebra', $e) || !empty($e['zebra']), 'bordes' => !array_key_exists('bordes', $e) || !empty($e['bordes'])];
        $fmt = [];
        foreach (array_slice((array)($d['fmt'] ?? []), 0, 20000, true) as $k => $f) {
            if (!preg_match('/^(-1|\d+),(\d+)$/', (string)$k, $m) || $m[1] >= $nr || $m[2] >= $nc) continue;
            if ($f = self::limpiarFmt($f)) $fmt[$k] = $f;
        }
        /* Celdas vinculadas a datos externos: "fila,col" => clave del dato. */
        $refs = [];
        foreach ((array)($d['refs'] ?? []) as $k => $v) {
            if (!preg_match('/^(\d+),(\d+)$/', (string)$k, $m)) continue;
            $r = (int)$m[1]; $c = (int)$m[2];
            if ($r < 0 || $r >= $nr || $c < 0 || $c >= $nc) continue;
            $clave = (string)$v;
            if (DatosExternos::esClave($clave)) $refs[$r . ',' . $c] = $clave;
        }
        /* Reglas de formato condicional por rango. */
        $reglas = [];
        foreach (array_slice((array)($d['reglas'] ?? []), 0, 100) as $r) {
            $r = is_array($r) ? $r : [];
            $tipo = in_array($r['tipo'] ?? '', ['mayor', 'menor', 'entre', 'contiene', 'igual', 'vacio', 'no_vacio', 'escala', 'barras'], true) ? $r['tipo'] : '';
            if ($tipo === '') continue;
            $r0 = max(0, min($nr - 1, (int)($r['r0'] ?? 0)));
            $r1 = max($r0, min($nr - 1, (int)($r['r1'] ?? $r0)));
            $c0 = max(0, min($nc - 1, (int)($r['c0'] ?? 0)));
            $c1 = max($c0, min($nc - 1, (int)($r['c1'] ?? $c0)));
            $regla = ['tipo' => $tipo, 'r0' => $r0, 'r1' => $r1, 'c0' => $c0, 'c1' => $c1];
            foreach (['v1', 'v2'] as $k) {
                if (isset($r[$k])) $regla[$k] = mb_substr((string)$r[$k], 0, 60);
            }
            foreach (['color', 'color2'] as $k) {
                if (isset($r[$k]) && is_string($r[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', $r[$k])) $regla[$k] = strtolower($r[$k]);
            }
            $regla['fmt'] = self::limpiarFmt($r['fmt'] ?? []);
            $reglas[] = $regla;
        }
        $graficas = [];
        foreach (array_slice((array)($d['graficas'] ?? []), 0, 20) as $g) {
            $g = is_array($g) ? $g : [];
            $rango = trim((string)($g['rango'] ?? ''));
            if (!preg_match('/^[A-Za-z]{1,2}\d+(?::[A-Za-z]{1,2}\d+)?$/', $rango)) continue;
            $graficas[] = [
                'titulo' => mb_substr(trim(preg_replace('/[\x00-\x1f]/u', ' ', (string)($g['titulo'] ?? ''))), 0, 80),
                'rango'  => $rango,
                'tipo'   => in_array($g['tipo'] ?? '', ['barras', 'linea', 'pastel'], true) ? $g['tipo'] : 'barras',
            ];
        }
        $merges = [];
        foreach (array_slice((array)($d['merges'] ?? []), 0, 200) as $mg) {
            $mg = is_array($mg) ? $mg : [];
            $r0 = max(0, min($nr - 1, (int)($mg['r0'] ?? 0)));
            $r1 = max(0, min($nr - 1, (int)($mg['r1'] ?? 0)));
            $c0 = max(0, min($nc - 1, (int)($mg['c0'] ?? 0)));
            $c1 = max(0, min($nc - 1, (int)($mg['c1'] ?? 0)));
            if ($r1 <= $r0 && $c1 <= $c0) continue;
            $merges[] = ['r0' => min($r0, $r1), 'c0' => min($c0, $c1), 'r1' => max($r0, $r1), 'c1' => max($c0, $c1)];
        }
        return ['cols' => $cols, 'rows' => $rows, 'calc' => (object)$calc, 'tot' => (object)$tot, 'estilo' => $estilo, 'fmt' => (object)$fmt, 'refs' => (object)$refs, 'reglas' => $reglas, 'graficas' => $graficas, 'merges' => $merges];
    }

    public const BORDES = ['bt' => 'top', 'br' => 'right', 'bb' => 'bottom', 'bl' => 'left'];
    public const ESTILOS_BORDE = ['solid', 'dashed', 'dotted', 'double'];

    /** Formato de una celda: negrita/cursiva/subrayado, tamaño, colores, alineación, bordes («grosor|estilo|#color» o «n» = sin borde») e imagen */
    private static function limpiarFmt($f): array
    {
        $f = is_array($f) ? $f : [];
        $o = [];
        foreach (['b', 'i', 'u'] as $k) if (!empty($f[$k])) $o[$k] = 1;
        if (isset($f['fs']) && is_numeric($f['fs'])) $o['fs'] = (int)max(6, min(72, $f['fs']));
        foreach (['tc', 'bg'] as $k) if (isset($f[$k]) && is_string($f[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', $f[$k])) $o[$k] = strtolower($f[$k]);
        if (in_array($f['al'] ?? '', self::ALINEAR, true)) $o['al'] = $f['al'];
        foreach (array_keys(self::BORDES) as $k) {
            $v = (string)($f[$k] ?? '');
            if ($v === 'n') $o[$k] = 'n';
            elseif (preg_match('/^([1-6])\|(solid|dashed|dotted|double)\|(#[0-9a-fA-F]{6})$/', $v, $m)) $o[$k] = $m[1] . '|' . $m[2] . '|' . strtolower($m[3]);
        }
        if (isset($f['im']) && is_string($f['im']) && preg_match('#^/(?:public/)?uploads/vplan/[a-f0-9]{32}\.(png|jpg)$#', $f['im'])) {
            $o['im'] = '/public/uploads/vplan/' . basename($f['im']);
        }
        // Enlaces, barras, notas y herramientas de celda (se validan aquí para que el JSON guardado sea seguro).
        $lk = trim((string)($f['lk'] ?? ''));
        if ($lk !== '' && mb_strlen($lk) <= 500 && preg_match('#^(https?://|mailto:|tel:|/)#i', $lk)) $o['lk'] = $lk;
        if (!empty($f['bar'])) $o['bar'] = 1;
        if (isset($f['barColor']) && is_string($f['barColor']) && preg_match('/^#[0-9a-fA-F]{6}$/', $f['barColor'])) $o['barColor'] = strtolower($f['barColor']);
        $nota = trim(preg_replace('/[\x00-\x1f]/u', ' ', (string)($f['nota'] ?? '')));
        if ($nota !== '' && mb_strlen($nota) <= 300) $o['nota'] = $nota;
        $qr = trim((string)($f['qr'] ?? ''));
        if ($qr !== '' && mb_strlen($qr) <= 300) $o['qr'] = $qr;
        $barcode = trim((string)($f['barcode'] ?? ''));
        if ($barcode !== '' && mb_strlen($barcode) <= 300) $o['barcode'] = $barcode;
        $spark = trim((string)($f['spark'] ?? ''));
        if ($spark !== '' && mb_strlen($spark) <= 300 && preg_match('/^([A-Za-z]{1,2}\d+(?::[A-Za-z]{1,2}\d+)?|[-\d.,\s]+)$/', $spark)) $o['spark'] = $spark;
        if (isset($f['sparkTipo']) && in_array($f['sparkTipo'], ['linea', 'barras'], true)) $o['sparkTipo'] = $f['sparkTipo'];
        if (isset($f['sparkColor']) && is_string($f['sparkColor']) && preg_match('/^#[0-9a-fA-F]{6}$/', $f['sparkColor'])) $o['sparkColor'] = strtolower($f['sparkColor']);
        $grafica = trim((string)($f['grafica'] ?? ''));
        if ($grafica !== '' && mb_strlen($grafica) <= 300) $o['grafica'] = $grafica;
        if (isset($f['adj']) && is_string($f['adj']) && mb_strlen($f['adj']) <= 500 && preg_match('#^/#', $f['adj'])) $o['adj'] = $f['adj'];
        if (isset($f['firma']) && is_string($f['firma']) && preg_match('#^/uploads/[a-z0-9/_-]+\.(png|jpg)$#i', $f['firma'])) $o['firma'] = $f['firma'];
        return $o;
    }

    /** Letra negra o blanca según la luminosidad del relleno (igual que contraste() en sheetflow.js) */
    public static function contraste(string $hex): string
    {
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 140 ? '#111111' : '#ffffff';
    }

    /** CSS de un formato de celda (tamaño en pt). $sinBordes = true no emite bordes */
    public static function cssFmt(array $f, bool $sinBordes = false): string
    {
        $c = '';
        if (!empty($f['b'])) $c .= 'font-weight:bold;';
        if (!empty($f['i'])) $c .= 'font-style:italic;';
        if (!empty($f['u'])) $c .= 'text-decoration:underline;';
        if (!empty($f['fs'])) $c .= 'font-size:' . round($f['fs'] * 0.75, 1) . 'pt;';
        if (!empty($f['tc'])) $c .= 'color:' . $f['tc'] . ';';
        elseif (!empty($f['bg'])) $c .= 'color:' . self::contraste($f['bg']) . ';';
        if (!empty($f['bg'])) $c .= 'background-color:' . $f['bg'] . ';';
        if (!empty($f['dbar'])) {
            $dc = $f['dbarColor'] ?? '#93c5fd';
            $p = max(0, min(100, (float) $f['dbar']));
            $c .= 'background-image:linear-gradient(to right,' . $dc . ' 0%,' . $dc . ' ' . $p . '%,transparent ' . $p . '%);';
        }
        if (!empty($f['al'])) $c .= 'text-align:' . ['izquierda' => 'left', 'centro' => 'center', 'derecha' => 'right'][$f['al']] . ';';
        if (!$sinBordes) foreach (self::BORDES as $k => $lado) {
            if (!isset($f[$k])) continue;
            if ($f[$k] === 'n') { $c .= "border-$lado:none;"; continue; }
            [$w, $est, $col] = explode('|', $f[$k]);
            $c .= "border-$lado:{$w}px $col $est;";
        }
        return $c;
    }

    // ---------- Valores y formato (mismo criterio que public/vendor/sheetflow.js) ----------

    /** Opciones de una columna de lista: sin repetidos, máx. 20 de 40 caracteres */
    public static function opciones($op): array
    {
        $out = [];
        foreach ((array)$op as $x) {
            $x = mb_substr(trim(preg_replace('/[\x00-\x1f]/u', ' ', (string)$x)), 0, 40);
            if ($x !== '' && !in_array($x, $out, true)) $out[] = $x;
            if (count($out) >= self::MAX_OPCIONES) break;
        }
        return $out;
    }

    /** Valor que se guarda en una celda de casilla o semáforo («si», «x», «Verde»…); los demás tipos lo dejan igual */
    public static function normalizarValor(string $v, string $tipo): string
    {
        $v = trim($v);
        if ($tipo === 'casilla') return in_array(mb_strtolower($v), ['1', 'si', 'sí', 'x', 'true', 'yes', '✔', '✓'], true) ? '1' : '';
        if ($tipo === 'semaforo') {
            $k = mb_strtolower($v);
            $alias = ['green' => 'verde', 'yellow' => 'amarillo', 'red' => 'rojo'];
            $k = $alias[$k] ?? $k;
            return isset(self::SEMAFORO[$k]) ? $k : '';
        }
        return $v;
    }

    /** Hoja nueva a partir del constructor de «tabla inteligente»: columnas {n, t, op, bg} y número de renglones */
    public static function inteligente($cols, int $filas): array
    {
        $out = [];
        $fmt = [];
        foreach (array_slice(array_values((array)$cols), 0, self::MAX_COLS) as $i => $c) {
            $c = is_array($c) ? $c : [];
            $out[] = ['n' => $c['n'] ?? 'Columna ' . ($i + 1), 't' => $c['t'] ?? 'texto', 'op' => $c['op'] ?? []];
            $bg = (string)($c['bg'] ?? '');
            if (preg_match('/^#[0-9a-f]{6}$/i', $bg)) $fmt["-1,$i"] = ['bg' => strtolower($bg)];
        }
        $n = max(1, count($out));
        $filas = max(1, min(200, $filas));
        return self::limpiar(['cols' => $out, 'rows' => array_fill(0, $filas, array_fill(0, $n, '')), 'fmt' => $fmt]);
    }

    /** Número de un texto («1,234.5», «$ 12», «45%») o null */
    public static function numero($v): ?float
    {
        $t = trim(str_replace([',', '$', '%', ' '], '', (string)$v));
        return $t !== '' && is_numeric($t) ? (float)$t : null;
    }

    private static function decimales(float $n, int $max = 4): string
    {
        $s = number_format($n, $max, '.', ',');
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    public static function formatear($v, string $tipo): string
    {
        $v = trim((string)$v);
        if ($v === '') return '';
        $n = self::numero($v);
        switch ($tipo) {
            case 'numero': return $n === null ? $v : self::decimales($n);
            case 'moneda': return $n === null ? $v : ($n < 0 ? '-' : '') . '$' . number_format(abs($n), 2, '.', ',');
            case 'porcentaje': return $n === null ? $v : self::decimales($n, 2) . '%';
            case 'fecha':
                return preg_match('/^(\d{4})-(\d\d)-(\d\d)$/', $v, $m) ? "$m[3]/$m[2]/$m[1]" : $v;
            case 'casilla': return self::normalizarValor($v, 'casilla') === '1' ? '✔' : '';
            case 'semaforo': return self::SEMAFORO[self::normalizarValor($v, 'semaforo')] ?? $v;
        }
        return $v;
    }

    /** Valor mostrado de una celda: el resultado si es fórmula, formateado según el tipo de su columna */
    public static function celda(array $d, int $r, int $c): string
    {
        $crudo = (string)($d['rows'][$r][$c] ?? '');
        $calc = (array)$d['calc'];
        $v = str_starts_with(ltrim($crudo), '=') ? (string)($calc["$r,$c"] ?? '') : $crudo;
        return self::formatear($v, $d['cols'][$c]['t']);
    }

    /** Todo lo que necesita quien consume la tabla: nombres, tipos, alineación, renglones ya formateados y fila de totales (o null) */
    public static function matriz(SheetflowHoja $h): array
    {
        $d = $h->contenido();
        $cols = $d['cols'];
        $filas = [];
        foreach ($d['rows'] as $r => $fila) {
            // los renglones totalmente vacíos al final no se llevan a otras herramientas
            $filas[] = array_map(fn($c) => self::celda($d, $r, $c), array_keys($cols));
        }
        $fmt = (array)$d['fmt'];
        $conImagen = [];
        foreach ($fmt as $k => $f) if (!empty($f['im'])) $conImagen[(int)explode(',', $k)[0]] = true;
        while ($filas && !isset($conImagen[count($filas) - 1]) && !array_filter(end($filas), fn($x) => $x !== '')) array_pop($filas);
        $tot = (array)$d['tot'];
        $totales = null;
        if (array_filter(array_column($cols, 'tot'))) {
            $totales = [];
            foreach ($cols as $c => $col) $totales[] = $col['tot'] !== '' && isset($tot[$c]) ? ($col['tot'] === 'cuenta' ? (string)$tot[$c] : self::formatear($tot[$c], $col['t'])) : '';
            $totales[0] = $totales[0] !== '' ? $totales[0] : 'Total';
        }
        return ['columnas' => array_column($cols, 'n'), 'tipos' => array_column($cols, 't'), 'alinear' => array_column($cols, 'a'), 'filas' => $filas, 'totales' => $totales, 'estilo' => $d['estilo'], 'fmt' => $fmt, 'reglas' => $d['reglas'] ?? [], 'reglasFmt' => self::reglasFmt($d), 'graficas' => $d['graficas'] ?? [], 'merges' => $d['merges'] ?? [], 'anchos' => array_column($cols, 'w'), 'opciones' => array_map(fn($c) => $c['op'] ?? [], $cols)];
    }

    /** Formato efectivo que aportan las reglas condicionales: "fila,col" => {bg, tc, dbar…} */
    public static function reglasFmt(array $d): array
    {
        $reglas = (array)($d['reglas'] ?? []);
        if (!$reglas) return [];

        $minmax = [];
        foreach ($reglas as $i => $r) {
            if (!in_array($r['tipo'] ?? '', ['escala', 'barras'], true)) continue;
            $min = null; $max = null;
            for ($rr = $r['r0']; $rr <= $r['r1']; $rr++) {
                for ($cc = $r['c0']; $cc <= $r['c1']; $cc++) {
                    $v = self::numero(self::celda($d, $rr, $cc));
                    if ($v === null) continue;
                    if ($min === null || $v < $min) $min = $v;
                    if ($max === null || $v > $max) $max = $v;
                }
            }
            $minmax[$i] = [$min ?? 0.0, $max ?? 0.0];
        }

        $out = [];
        foreach ($reglas as $i => $r) {
            [$min, $max] = $minmax[$i] ?? [0.0, 0.0];
            for ($rr = $r['r0']; $rr <= $r['r1']; $rr++) {
                for ($cc = $r['c0']; $cc <= $r['c1']; $cc++) {
                    $texto = self::celda($d, $rr, $cc);
                    if (!self::reglaCumple($r, $texto, $min, $max)) continue;
                    $f = [];
                    $n = self::numero($texto);
                    if ($r['tipo'] === 'escala') {
                        $p = $max > $min && $n !== null ? max(0.0, min(1.0, ($n - $min) / ($max - $min))) : 0.0;
                        $f['bg'] = self::mezclaColor($r['color'] ?? '#fee2e2', $r['color2'] ?? '#bbf7d0', $p);
                        $f['tc'] = self::contraste($f['bg']);
                    } elseif ($r['tipo'] === 'barras') {
                        $p = $max > $min && $n !== null ? max(0.0, min(100.0, ($n - $min) / ($max - $min) * 100)) : 0.0;
                        $f['dbar'] = round($p, 1);
                        $f['dbarColor'] = $r['color'] ?? '#93c5fd';
                    }
                    if (!empty($r['fmt'])) $f = array_merge($f, $r['fmt']);
                    if ($f) $out["$rr,$cc"] = array_merge($out["$rr,$cc"] ?? [], $f);
                }
            }
        }
        return $out;
    }

    private static function reglaCumple(array $r, string $texto, float $min, float $max): bool
    {
        $n = self::numero($texto);
        $v1 = (string)($r['v1'] ?? '');
        $v2 = (string)($r['v2'] ?? '');
        $n1 = self::numero($v1);
        $n2 = self::numero($v2);

        return match ($r['tipo']) {
            'mayor'    => $n !== null && $n1 !== null && $n > $n1,
            'menor'    => $n !== null && $n1 !== null && $n < $n1,
            'entre'    => $n !== null && $n1 !== null && $n2 !== null && $n >= min($n1, $n2) && $n <= max($n1, $n2),
            'contiene' => $v1 !== '' && mb_stripos($texto, $v1) !== false,
            'igual'    => $n !== null && $n1 !== null ? $n == $n1 : trim($texto) === trim($v1),
            'vacio'    => trim($texto) === '',
            'no_vacio' => trim($texto) !== '',
            'escala', 'barras' => $n !== null,
            default    => false,
        };
    }

    private static function mezclaColor(string $a, string $b, float $p): string
    {
        $p = max(0.0, min(1.0, $p));
        $ca = array_map('hexdec', str_split(ltrim($a, '#'), 2));
        $cb = array_map('hexdec', str_split(ltrim($b, '#'), 2));
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $p)), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /** Minigráfica (sparkline) de un rango A1 o de una lista de números separados por comas. */
    public static function sparkSvg(string $expr, array $filas, int $w = 90, int $h = 28, string $color = '#2563eb', string $tipo = 'linea'): string
    {
        $vals = [];
        $expr = trim($expr);
        if (preg_match('/^([A-Za-z]{1,2})(\d+)(?::([A-Za-z]{1,2})(\d+))?$/', $expr, $m)) {
            $c0 = self::indiceColumna($m[1]);
            $r0 = max(0, (int) $m[2] - 2);
            $c1 = isset($m[3]) && $m[3] !== '' ? self::indiceColumna($m[3]) : $c0;
            $r1 = isset($m[4]) && $m[4] !== '' ? max(0, (int) $m[4] - 2) : $r0;
            for ($r = min($r0, $r1); $r <= max($r0, $r1); $r++) {
                for ($c = min($c0, $c1); $c <= max($c0, $c1); $c++) {
                    $n = self::numero($filas[$r][$c] ?? '');
                    if ($n !== null) $vals[] = $n;
                }
            }
        } else {
            foreach (preg_split('/[\s,;]+/', $expr) ?: [] as $x) {
                $n = self::numero($x);
                if ($n !== null) $vals[] = $n;
            }
        }
        if (!$vals) return '';
        $min = min($vals);
        $max = max($vals);
        $rango = $max - $min ?: 1;
        $pad = 2;
        $ancho = max(1, $w - $pad * 2);
        $alto = max(1, $h - $pad * 2);
        $col = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#2563eb';
        $svg = '<svg class="sf-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true">';
        $svg .= '<line x1="' . $pad . '" y1="' . ($h - $pad) . '" x2="' . ($w - $pad) . '" y2="' . ($h - $pad) . '" stroke="#e2e8f0" stroke-width="1"/>';
        if ($tipo === 'barras') {
            $n = count($vals);
            $bw = max(1.0, $ancho / max(1, $n) * 0.8);
            foreach ($vals as $i => $v) {
                $bh = (($v - $min) / $rango) * $alto;
                $x = $pad + ($ancho / max(1, $n)) * $i + (($ancho / max(1, $n)) - $bw) / 2;
                $svg .= '<rect x="' . round($x, 2) . '" y="' . round($h - $pad - $bh, 2) . '" width="' . round($bw, 2) . '" height="' . round(max(1, $bh), 2) . '" fill="' . $col . '"/>';
            }
        } else {
            $pts = [];
            $n = count($vals);
            foreach ($vals as $i => $v) {
                $x = $pad + ($n === 1 ? $ancho / 2 : $ancho * $i / ($n - 1));
                $y = $h - $pad - (($v - $min) / $rango) * $alto;
                $pts[] = round($x, 2) . ',' . round($y, 2);
            }
            $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $col . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
        }
        return $svg . '</svg>';
    }

    private static function indiceColumna(string $letras): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letras)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return max(0, $n - 1);
    }

    // ---------- Plantillas ----------

    public static function plantillas(): array
    {
        return [
            'presupuesto' => ['Presupuesto por conceptos', 'Concepto, unidad, cantidad, P.U. e importe', 'fa-coins'],
        ];
    }

    private static function plano(array $cols, array $filas, array $extra = []): array
    {
        return SheetflowFormulas::recalcular(self::limpiar(['cols' => $cols, 'rows' => $filas] + $extra));
    }

    /** Saneado + resultados de fórmulas y totales calculados aquí (el servidor es quien manda) */
    public static function normalizar($d): array
    {
        return SheetflowFormulas::recalcular(self::limpiar($d));
    }

    /**
     * Actualiza las celdas vinculadas a datos externos y recalcula fórmulas.
     * Solo toca las celdas de `refs`: los valores manuales y las fórmulas que
     * no dependen de ellas quedan intactos.
     */
    public static function refrescarExternos(array $d): array
    {
        $d = self::limpiar($d);
        $refs = (array)($d['refs'] ?? []);
        if (!$refs) {
            return $d;
        }

        $valores = DatosExternos::resolver(array_values($refs));
        foreach ($refs as $k => $clave) {
            if (!array_key_exists($clave, $valores)) {
                continue;
            }
            if (!preg_match('/^(\d+),(\d+)$/', (string)$k, $m)) {
                continue;
            }
            $r = (int)$m[1]; $c = (int)$m[2];
            if (isset($d['rows'][$r][$c])) {
                $d['rows'][$r][$c] = (string)$valores[$clave];
            }
        }

        return self::normalizar($d);
    }

    /** Guarda el contenido de la hoja (saneado, con datos externos al día y recalculado). */
    public static function guardarDatos(SheetflowHoja $h, array $d): SheetflowHoja
    {
        $h->datos = json_encode(self::refrescarExternos($d), JSON_UNESCAPED_UNICODE);
        $h->guardar();
        return $h;
    }

    /** Datos iniciales de una plantilla (o null si la clave no existe) */
    public static function plantilla(string $clave): ?array
    {
        switch ($clave) {
            case 'presupuesto':
                $filas = [];
                for ($i = 2; $i <= 11; $i++) $filas[] = ['', '', '', '', "=C$i*D$i"];
                return self::plano([
                    ['n' => 'Concepto'], ['n' => 'Unidad'], ['n' => 'Cantidad', 't' => 'numero'], ['n' => 'P.U.', 't' => 'moneda'],
                    ['n' => 'Importe', 't' => 'moneda', 'tot' => 'suma'],
                ], $filas);
            case '': case 'blanco':
                return self::vacia();
        }
        return null;
    }

    // ---------- Importar ----------

    /** Primera hoja de un .csv/.xlsx/.ods; la primera fila son los encabezados */
    public static function importar(string $ruta, string $nombreOriginal): array
    {
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if ($ext === 'sheetflow') return self::deArchivo($ruta)[0];
        if (!in_array($ext, ['csv', 'xlsx', 'ods'], true)) throw new \RuntimeException('Usa un archivo .sheetflow, .csv, .xlsx u .ods');
        $lector = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(ucfirst($ext));
        if ($ext === 'csv') {
            $muestra = (string)file_get_contents($ruta, false, null, 0, 20000);
            $lector->setInputEncoding(mb_check_encoding($muestra, 'UTF-8') ? 'UTF-8' : 'Windows-1252');
        }
        $libro = $lector->load($ruta);
        $filas = [];
        foreach ($libro->getSheet(0)->toArray(null, true, true, false) as $f) {
            $f = array_map(fn($v) => trim((string)$v), $f);
            if (array_filter($f, fn($v) => $v !== '')) $filas[] = $f;
            if (count($filas) > self::MAX_FILAS + 1) break;
        }
        return self::deMatriz($filas);
    }

    /** Datos de SheetFlow a partir de una matriz de textos: la primera fila son los encabezados; el tipo (número/texto) se deduce de cada columna */
    public static function deMatriz(array $filas): array
    {
        $filas = array_values(array_filter(array_map(fn($f) => array_map(fn($v) => trim((string)$v), array_values($f)), $filas), fn($f) => array_filter($f, fn($v) => $v !== '')));
        if (!$filas) throw new \RuntimeException('El archivo no tiene datos');
        $nc = min(self::MAX_COLS, max(array_map('count', $filas)));
        while ($nc > 1 && !array_filter(array_column($filas, $nc - 1), fn($v) => $v !== '')) $nc--;
        $enc = array_pad(array_slice(array_shift($filas), 0, $nc), $nc, '');
        $cols = [];
        foreach ($enc as $i => $n) {
            $col = array_map(fn($f) => (string)($f[$i] ?? ''), $filas);
            $llenas = array_filter($col, fn($v) => $v !== '');
            $num = $llenas && !array_filter($llenas, fn($v) => self::numero($v) === null);
            $cols[] = ['n' => $n !== '' ? $n : 'Columna ' . ($i + 1), 't' => $num ? 'numero' : 'texto'];
        }
        $rows = array_map(fn($f) => array_map(fn($i) => ($cols[$i]['t'] === 'numero' && ($n = self::numero($f[$i] ?? '')) !== null) ? (string)$n : (string)($f[$i] ?? ''), array_keys($cols)), $filas);
        return self::limpiar(['cols' => $cols, 'rows' => $rows ?: [array_fill(0, $nc, '')]]);
    }

    // ---------- Exportar ----------

    public static function csv(SheetflowHoja $h): string
    {
        $m = self::matriz($h);
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $m['columnas'], ',', '"', '\\');
        foreach ($m['filas'] as $fila) fputcsv($f, $fila, ',', '"', '\\');
        if ($m['totales']) fputcsv($f, $m['totales'], ',', '"', '\\');
        rewind($f);
        return (string)stream_get_contents($f);
    }

    public static function xlsx(SheetflowHoja $h): string
    {
        $d = $h->contenido();
        $m = self::matriz($h);
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(mb_substr(preg_replace('/[\\\\\/?*:\[\]]/u', ' ', $h->nombre) ?: 'Tabla', 0, 31));
        $Coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::class;
        $nc = count($d['cols']);
        $argb = fn($hex) => 'FF' . strtoupper(ltrim($hex, '#'));
        $formato = ['numero' => '#,##0', 'moneda' => '"$"#,##0.00', 'porcentaje' => '0"%"', 'fecha' => 'dd/mm/yyyy'];
        $alinea = ['izquierda' => 'left', 'centro' => 'center', 'derecha' => 'right'];
        foreach ($d['cols'] as $c => $col) {
            $L = $Coord::stringFromColumnIndex($c + 1);
            $hoja->setCellValue($L . '1', $col['n']);
            $hoja->getColumnDimension($L)->setAutoSize(true);
            $hoja->getStyle($L . '2:' . $L . (count($m['filas']) + 2))->getAlignment()->setHorizontal($alinea[$col['a']]);
            if (isset($formato[$col['t']])) {
                $codigo = $formato[$col['t']];
                if (in_array($col['t'], ['numero', 'porcentaje'], true) && array_filter(array_column($m['filas'], $c), fn($v) => ($n = self::numero($v)) !== null && floor($n) != $n)) {
                    $codigo = $col['t'] === 'numero' ? '#,##0.00##' : '0.00"%"';
                }
                $hoja->getStyle($L . '2:' . $L . (count($m['filas']) + 2))->getNumberFormat()->setFormatCode($codigo);
            }
        }
        foreach ($d['rows'] as $r => $fila) {
            if ($r >= count($m['filas'])) break;
            foreach ($fila as $c => $crudo) {
                $L = $Coord::stringFromColumnIndex($c + 1) . ($r + 2);
                $v = str_starts_with(ltrim($crudo), '=') ? (string)(((array)$d['calc'])["$r,$c"] ?? '') : $crudo;
                if ($v === '') continue;
                $tipo = $d['cols'][$c]['t'];
                $n = self::numero($v);
                if ($tipo === 'fecha' && preg_match('/^\d{4}-\d\d-\d\d$/', $v)) {
                    $hoja->setCellValue($L, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime($v . ' 12:00:00')));
                } elseif (in_array($tipo, ['numero', 'moneda', 'porcentaje'], true) && $n !== null) {
                    $hoja->setCellValue($L, $n);
                } else {
                    $hoja->setCellValueExplicit($L, $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
        $ult = $Coord::stringFromColumnIndex($nc);
        $est = $hoja->getStyle("A1:{$ult}1");
        $est->getFont()->setBold(true)->getColor()->setARGB($argb($d['estilo']['encTxt']));
        $est->getFill()->setFillType('solid')->getStartColor()->setARGB($argb($d['estilo']['enc']));
        $est->getAlignment()->setHorizontal('center');
        $fin = count($m['filas']) + 1;
        if ($m['totales']) {
            $fin++;
            foreach ($m['totales'] as $c => $t) {
                $col = $d['cols'][$c];
                $L = $Coord::stringFromColumnIndex($c + 1) . $fin;
                $n = self::numero(((array)$d['tot'])[$c] ?? '');
                if ($t !== '' && $col['tot'] !== '' && $n !== null) $hoja->setCellValue($L, $n);
                elseif ($t !== '') $hoja->setCellValueExplicit($L, $t, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $hoja->getStyle("A$fin:$ult$fin")->getFont()->setBold(true);
            $hoja->getStyle("A$fin:$ult$fin")->getBorders()->getTop()->setBorderStyle('medium');
        }
        if ($d['estilo']['bordes']) $hoja->getStyle("A1:$ult$fin")->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FFBBBBBB');
        if ($d['estilo']['zebra']) for ($r = 3; $r <= $fin - ($m['totales'] ? 1 : 0); $r += 2) $hoja->getStyle("A$r:$ult$r")->getFill()->setFillType('solid')->getStartColor()->setARGB('FFF1F5F9');
        self::formatosXlsx($hoja, $d, $m);
        $hoja->freezePane('A2');
        $tmp = tempnam(sys_get_temp_dir(), 'sf');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($tmp);
        $datos = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $datos;
    }

    /** Aplica a la hoja de Excel el formato por celda (fuente, colores, bordes, alineación), los anchos de columna y las imágenes */
    private static function formatosXlsx($hoja, array $d, array $m): void
    {
        $Coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::class;
        $argb = fn($hex) => 'FF' . strtoupper(ltrim($hex, '#'));
        $est = ['solid' => 'thin', 'dashed' => 'dashed', 'dotted' => 'dotted', 'double' => 'double'];
        foreach ($d['cols'] as $c => $col) if ($col['w']) $hoja->getColumnDimension($Coord::stringFromColumnIndex($c + 1))->setAutoSize(false)->setWidth(round($col['w'] / 7, 1));
        $fmtExcel = (array)$d['fmt'];
        foreach (($m['reglasFmt'] ?? []) as $k => $f) $fmtExcel[$k] = array_merge($f, $fmtExcel[$k] ?? []);
        foreach ($fmtExcel as $k => $f) {
            [$r, $c] = array_map('intval', explode(',', $k));
            if ($r >= count($m['filas'])) continue;
            $ref = $Coord::stringFromColumnIndex($c + 1) . ($r + 2);
            $st = $hoja->getStyle($ref);
            if (!empty($f['b'])) $st->getFont()->setBold(true);
            if (!empty($f['i'])) $st->getFont()->setItalic(true);
            if (!empty($f['u'])) $st->getFont()->setUnderline('single');
            if (!empty($f['fs'])) $st->getFont()->setSize(round($f['fs'] * 0.75, 1));
            if (!empty($f['tc'])) $st->getFont()->getColor()->setARGB($argb($f['tc']));
            elseif (!empty($f['bg'])) $st->getFont()->getColor()->setARGB($argb(self::contraste($f['bg'])));
            if (!empty($f['bg'])) $st->getFill()->setFillType('solid')->getStartColor()->setARGB($argb($f['bg']));
            if (!empty($f['al'])) $st->getAlignment()->setHorizontal(['izquierda' => 'left', 'centro' => 'center', 'derecha' => 'right'][$f['al']]);
            foreach (self::BORDES as $kb => $lado) {
                if (!isset($f[$kb])) continue;
                $b = $st->getBorders()->{'get' . ucfirst($lado)}();
                if ($f[$kb] === 'n') { $b->setBorderStyle('none'); continue; }
                [$w, $es, $co] = explode('|', $f[$kb]);
                $b->setBorderStyle($w >= 3 && $es === 'solid' ? 'medium' : $est[$es])->getColor()->setARGB($argb($co));
            }
            if (!empty($f['im']) && is_file($ruta = Imagenes::CARPETA_VPLAN . '/' . basename($f['im']))) {
                $dim = @getimagesize($ruta);
                if ($dim) {
                    $alto = 80;
                    $dr = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $dr->setPath($ruta)->setCoordinates($ref)->setResizeProportional(true)->setHeight($alto)->setOffsetX(2)->setOffsetY(2)->setWorksheet($hoja);
                    $hoja->getRowDimension($r + 2)->setRowHeight(max(15, $alto * 0.75 + 4));
                }
            }
        }
    }

    // ---------- Imágenes de celdas (uploads/vplan, como el Redactor) ----------

    /** Nombres de archivo de imagen que usa un contenido (JSON o HTML) */
    public static function imagenesDe($datos): array
    {
        $t = is_string($datos) ? $datos : json_encode($datos);
        preg_match_all('#/(?:public/)?uploads/vplan/([a-f0-9]{32}\.(?:png|jpg))#', str_replace('\\/', '/', (string)$t), $m);
        return array_values(array_unique($m[1]));
    }

    /** ¿Alguna otra tabla o documento del Redactor usa la imagen? */
    private static function imagenEnUso(string $nombre, int $excluirHoja): bool
    {
        $db = SheetflowHoja::conexion();
        $n = $db->escape_string($nombre);
        foreach (["SELECT 1 FROM sheetflow_hojas WHERE id <> $excluirHoja AND datos LIKE '%$n%' LIMIT 1",
                  "SELECT 1 FROM sheetflow_versiones WHERE datos LIKE '%$n%' LIMIT 1",
                  "SELECT 1 FROM redactor_documentos WHERE contenido LIKE '%$n%' LIMIT 1"] as $q) {
            $r = $db->query($q);
            if ($r && $r->num_rows) return true;
        }
        return false;
    }

    /** Borra del disco las imágenes de $antes que ya no están en $despues ni las usa otra cosa */
    public static function limpiarImagenes(array $antes, array $despues, int $hojaId): void
    {
        foreach (array_diff($antes, $despues) as $n) {
            if (!self::imagenEnUso($n, $hojaId)) Imagenes::eliminarVplan($n);
        }
    }

    // ---------- Archivo propio .sheetflow (copia de seguridad / intercambio; con su propio icono en Archivo) ----------

    public static function aArchivo(SheetflowHoja $h): string
    {
        $d = $h->contenido();
        $img = [];
        foreach (self::imagenesDe($d) as $n) {
            $ruta = Imagenes::CARPETA_VPLAN . '/' . $n;
            if (is_file($ruta) && filesize($ruta) <= 6_000_000) $img[$n] = base64_encode((string)file_get_contents($ruta));
        }
        return json_encode(['sheetflow' => 1, 'nombre' => $h->nombre, 'descripcion' => $h->descripcion, 'departamento' => $h->departamento, 'datos' => $d, 'imagenes' => $img], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Lee un .sheetflow: devuelve [datos, meta]. Las imágenes se vuelven a guardar (nombres nuevos) */
    public static function deArchivo(string $ruta): array
    {
        $j = json_decode((string)file_get_contents($ruta), true);
        if (!is_array($j) || ($j['sheetflow'] ?? 0) !== 1 || !is_array($j['datos'] ?? null)) throw new \RuntimeException('El archivo no es una tabla de SheetFlow válida');
        $mapa = [];
        foreach (array_slice((array)($j['imagenes'] ?? []), 0, 200, true) as $n => $b64) {
            if (!preg_match('/^[a-f0-9]{32}\.(png|jpg)$/', (string)$n)) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'sfi');
            file_put_contents($tmp, (string)base64_decode((string)$b64, true));
            if ($nuevo = Imagenes::guardarGrande($tmp, 2400)) $mapa['/public/uploads/vplan/' . $n] = '/public/uploads/vplan/' . $nuevo;
            @unlink($tmp);
        }
        $json = strtr(json_encode($j['datos'], JSON_UNESCAPED_SLASHES), $mapa);
        return [self::normalizar(json_decode($json, true)), ['nombre' => (string)($j['nombre'] ?? ''), 'descripcion' => (string)($j['descripcion'] ?? ''), 'departamento' => (string)($j['departamento'] ?? '')]];
    }

    /** HTML de la tabla (PDF y vista de solo lectura). Todo va escapado. $rutas = las imágenes salen como ruta del disco (Dompdf) */
    public static function html(SheetflowHoja $h, string $clase = 'sf-tabla', bool $rutas = false): string
    {
        return self::tabla($h, ['clase' => $clase, 'rutas' => $rutas]);
    }

    /** Tabla HTML para el Redactor, marcada con data-sf (se refresca sola al abrir, ver y exportar). Bordes en el orden «1px #color solid» que lee PhpWord */
    public static function htmlRedactor(SheetflowHoja $h): string
    {
        return self::tabla($h, ['redactor' => true]);
    }

    private static function tabla(SheetflowHoja $h, array $op): string
    {
        $m = self::matriz($h);
        $e = $m['estilo'];
        $fmt = $m['fmt'];
        foreach (($m['reglasFmt'] ?? []) as $k => $f) $fmt[$k] = array_merge($f, $fmt[$k] ?? []);
        $mergeInicio = [];
        $mergeCubierta = [];
        foreach (($m['merges'] ?? []) as $mg) {
            $mergeInicio[$mg['r0'] . ',' . $mg['c0']] = $mg;
            for ($rr = $mg['r0']; $rr <= $mg['r1']; $rr++) {
                for ($cc = $mg['c0']; $cc <= $mg['c1']; $cc++) {
                    if ($rr !== $mg['r0'] || $cc !== $mg['c0']) $mergeCubierta["$rr,$cc"] = true;
                }
            }
        }
        $red = !empty($op['redactor']);
        $esc = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
        $alin = ['izquierda' => 'left', 'centro' => 'center', 'derecha' => 'right'];
        $borde = $red ? 'border:1px #888888 solid;' : ($e['bordes'] ? 'border:1px solid #b6bfcc;' : 'border-bottom:1px solid #e2e8f0;');
        $bg = $red ? 'background-color:' : 'background:';
        $img = function (array $f) use ($op, $esc) {
            if (empty($f['im'])) return '';
            $src = !empty($op['rutas']) ? Imagenes::CARPETA_VPLAN . '/' . basename($f['im']) : $f['im'];
            if (!empty($op['rutas']) && !is_file($src)) return '';
            return '<img src="' . $esc($src) . '" style="max-width:100%;max-height:' . (!empty($op['rutas']) ? '70pt' : '9rem') . ';display:block;margin:0 auto 2pt">';
        };
        $col = '';
        if (array_filter($m['anchos'])) {
            $col = '<colgroup>' . implode('', array_map(fn($w) => '<col' . ($w ? ' style="width:' . round($w * 0.75) . 'pt"' : '') . '>', $m['anchos'])) . '</colgroup>';
        }
        $encabezados = '';
        foreach ($m['columnas'] as $c => $n) {
            $f = $fmt["-1,$c"] ?? [];
            $encabezados .= '<th style="' . $borde . 'padding:4pt 6pt;' . $bg . $e['enc'] . ';color:' . $e['encTxt'] . ';text-align:center;' . self::cssFmt($f) . '">' . $img($f) . $esc($n) . '</th>';
        }
        $apertura = $red
            ? '<table data-sf="' . (int)$h->id . '" style="width:100%;border-collapse:collapse"><tbody><tr>' . $encabezados . '</tr>'
            : '<table class="' . $esc($op['clase'] ?? 'sf-tabla') . '" style="border-collapse:collapse;width:100%">' . $col
                . '<thead><tr>' . $encabezados . '</tr></thead><tbody>';
        $cierre = '</tbody></table>';

        $cuerpo = [];
        foreach ($m['filas'] as $r => $fila) {
            $tr = '<tr>';
            foreach ($fila as $c => $v) {
                if (isset($mergeCubierta["$r,$c"])) continue;
                $mg = $mergeInicio["$r,$c"] ?? null;
                $span = $mg ? ' colspan="' . ($mg['c1'] - $mg['c0'] + 1) . '" rowspan="' . ($mg['r1'] - $mg['r0'] + 1) . '"' : '';
                $f = $fmt["$r,$c"] ?? [];
                $cero = $e['zebra'] && $r % 2 && empty($f['bg']) ? ';' . $bg . '#f1f5f9' : '';
                $imgHtml = $img($f);
                $contenido = !empty($f['spark'])
                    ? self::sparkSvg((string)$f['spark'], $m['filas'], 90, 28, (string)($f['sparkColor'] ?? '#2563eb'), (string)($f['sparkTipo'] ?? 'linea'))
                    : ($v !== '' ? $esc($v) : ($red && $imgHtml === '' ? '<br>' : ''));
                $tr .= '<td' . $span . ' style="' . $borde . 'padding:3pt 6pt;text-align:' . $alin[$m['alinear'][$c]] . $cero . ';' . self::cssFmt($f) . '">' . $imgHtml . $contenido . '</td>';
            }
            $tr .= '</tr>';
            $cuerpo[] = $tr;
        }

        $pie = '';
        if ($m['totales']) {
            $pie = '<tr>';
            foreach ($m['totales'] as $c => $v) $pie .= '<td style="' . $borde . 'border-top:2px solid #334155;padding:3pt 6pt;font-weight:bold;' . ($red ? 'background-color:#e2e8f0;' : '') . 'text-align:' . $alin[$m['alinear'][$c]] . '">' . ($v !== '' ? $esc($v) : ($red ? '<br>' : '')) . '</td>';
            $pie .= '</tr>';
        }

        if ($red) {
            return $apertura . implode('', $cuerpo) . $pie . $cierre;
        }

        /* En el PDF, Dompdf tarda y consume memoria de forma desproporcionada
           al maquetar una tabla enorme (una sola de 1000 filas llegó a pedir
           ~460 MB con celdas combinadas). Partirla en tablas más cortas baja
           el consumo y el tiempo a la mitad, con el mismo aspecto: cada trozo
           lleva su encabezado y sólo el último cierra con la fila de totales.
           El corte nunca cae a media celda combinada. */
        if (!empty($op['bloques']) && count($cuerpo) > self::FILAS_POR_BLOQUE) {
            $mergeDeFila = [];
            foreach (($m['merges'] ?? []) as $mg) {
                for ($rr = $mg['r0']; $rr <= $mg['r1']; $rr++) {
                    $mergeDeFila[$rr] = $mg;
                }
            }

            $trozos = [];
            $actual = [];
            $contador = 0;
            $totalFilas = count($cuerpo);
            foreach ($cuerpo as $i => $tr) {
                $actual[] = $tr;
                $contador++;
                $merge = $mergeDeFila[$i] ?? null;
                // Sólo se corta donde no hay celdas combinadas abiertas.
                $corto = $contador >= self::FILAS_POR_BLOQUE
                    && $i < $totalFilas - 1
                    && ($merge === null || $merge['r1'] === $i);
                if ($corto) {
                    $trozos[] = $actual;
                    $actual = [];
                    $contador = 0;
                }
            }
            if ($actual) {
                $trozos[] = $actual;
            }
        } else {
            $trozos = [$cuerpo];
        }

        $salida = '';
        $ultimo = count($trozos) - 1;
        foreach ($trozos as $i => $grupo) {
            $salida .= $apertura . implode('', $grupo) . ($i === $ultimo ? $pie : '') . $cierre;
        }
        return $salida;
    }

    /** Gráficas guardadas en la hoja, como SVG (PDF y enlace público). */
    public static function graficasHtml(SheetflowHoja $h): string
    {
        $graficas = (array) ($h->contenido()['graficas'] ?? []);
        if (!$graficas) return '';
        $m = self::matriz($h);
        $esc = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $o = '';
        foreach ($graficas as $g) {
            $o .= '<div style="margin-top:12pt"><strong style="font-size:10pt">' . $esc((string) ($g['titulo'] ?? 'Gráfica')) . '</strong><br>'
                . self::sparkSvg((string) $g['rango'], $m['filas'], 520, 180, '#2563eb', ($g['tipo'] ?? '') === 'linea' ? 'linea' : 'barras') . '</div>';
        }
        return $o;
    }

    public static function pdf(SheetflowHoja $h): string
    {
        @ini_set('memory_limit', '512M');
        $ancha = count($h->contenido()['cols']) > 6;
        $esc = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
        $css = '@page{margin:1.6cm}body{font-family:DejaVu Sans,Helvetica,sans-serif;font-size:' . ($ancha ? 8 : 10) . 'pt;color:#000}h1{font-size:15pt;margin:0 0 2pt}p.sub{margin:0 0 10pt;color:#555;font-size:9pt}tr{page-break-inside:avoid}thead{display:table-header-group}';
        $sub = formatear_fecha($h->fecha, 'd/m/Y') . ($h->departamento !== '' ? ' · ' . $h->departamento : '');
        $graficas = self::graficasHtml($h);
        $html = '<html><head><meta charset="utf-8"><title>' . $esc($h->nombre) . '</title><style>' . $css . '</style></head><body><h1>' . $esc($h->nombre) . '</h1><p class="sub">' . $esc($sub) . '</p>' . self::tabla($h, ['clase' => 'sf-tabla', 'rutas' => true, 'bloques' => true]) . $graficas . '</body></html>';

        // Mismo contenido de la hoja = mismo PDF; una tabla de 1000 filas
        // tarda ~4 s en maquetarse y casi siempre es la misma de siempre.
        $papel = 'letter';
        $orientacion = $ancha ? 'landscape' : 'portrait';
        $opciones = ['isRemoteEnabled' => false, 'chroot' => realpath(Imagenes::CARPETA_VPLAN) ?: Imagenes::CARPETA_VPLAN, 'defaultFont' => 'serif'];
        $clave = CachePdf::clave($html, $papel, $orientacion, $opciones);
        $guardado = CachePdf::obtener($clave);
        if ($guardado !== null) {
            return $guardado;
        }

        $dompdf = Pdf::preparar($html, $papel, $orientacion, $opciones);
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() / 2 - 30, $canvas->get_height() - 22, 'Página {PAGE_NUM} de {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('helvetica'), 8, [0.4, 0.4, 0.4]);
        $pdf = $dompdf->output();

        // El pie («Página X de Y») se dibuja en el canvas, no en el HTML, pero
        // depende sólo de las páginas que salieron de ese HTML: el PDF guardado
        // lo trae ya puesto.
        if (is_string($pdf) && $pdf !== '') {
            CachePdf::guardar($clave, $pdf);
        }

        return $pdf;
    }

    // ---------- Hojas creadas desde otras herramientas ----------

    /** Hoja nueva (vacía, o con la matriz dada: encabezados primero) para que otra herramienta la use como fuente */
    public static function crearHoja(string $nombre, ?array $filas = null, ?int $usuarioId = null): SheetflowHoja
    {
        $datos = $filas ? self::deMatriz($filas) : self::vacia();
        $h = new SheetflowHoja(['nombre' => mb_substr(trim($nombre) ?: 'Tabla', 0, 150), 'fecha' => date('Y-m-d'), 'creado_por' => $usuarioId]);
        $h->datos = json_encode(self::normalizar($datos), JSON_UNESCAPED_UNICODE);
        $h->id = $h->guardar()['id'];
        return $h;
    }

    /** Reemplaza las tablas `data-sf` de un HTML del Redactor por su versión actual (si la hoja ya no existe, se deja como está) */
    public static function refrescarHtml(string $html): string
    {
        if (!str_contains($html, 'data-sf=')) return $html;
        return preg_replace_callback('#<table\b[^>]*\bdata-sf="(\d{1,9})"[^>]*>.*?</table>#s', function ($m) {
            $h = SheetflowHoja::find((int)$m[1]);
            return $h ? self::htmlRedactor($h) : $m[0];
        }, $html);
    }
}
