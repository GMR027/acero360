<?php

namespace Classes;

/**
 * Evaluador de fórmulas de SheetFlow (mismo lenguaje que public/vendor/sheetflow.js, sin eval).
 * Celdas A1 (el encabezado es la fila 1), rangos A1:B3, + - * / ^ % &, comparaciones (números o texto) y funciones:
 * SUMA PROMEDIO MIN MAX CONTAR REDONDEAR · condicionales: SI Y O NO SI.ERROR SUMAR.SI CONTAR.SI PROMEDIO.SI.
 * Resultado: ['v' => float] | ['s' => string] | ['e' => '#ERROR!'].
 */
/** Error de valor en tiempo de ejecución (#DIV/0!, #VALOR!…): viaja como valor de celda; un error de sintaxis es una RuntimeException normal */
class ErrorValor extends \RuntimeException
{
}

class SheetflowFormulas
{
    private array $filas;
    private array $cols;
    private array $memo = [];
    private array $visitando = [];
    private string $src = '';
    private int $i = 0;

    private function __construct(array $d)
    {
        $this->filas = $d['rows'];
        $this->cols = $d['cols'];
    }

    public static function esFormula($t): bool
    {
        return (bool)preg_match('/^\s*=/', (string)$t);
    }

    /** Completa `calc` y `tot` de una hoja ya saneada */
    public static function recalcular(array $d): array
    {
        $ev = new self($d);
        $calc = [];
        foreach ($d['rows'] as $r => $fila) {
            foreach ($fila as $c => $t) if (self::esFormula($t)) $calc["$r,$c"] = self::resultado($ev->celda($r, $c));
        }
        $tot = [];
        foreach ($d['cols'] as $c => $col) {
            if ($col['tot'] === '') continue;
            $vals = [];
            $llenas = 0;
            foreach ($d['rows'] as $r => $fila) {
                $v = self::esFormula($fila[$c]) ? ($calc["$r,$c"] ?? '') : $fila[$c];
                if ($v !== '') $llenas++;
                if (($n = Sheetflow::numero($v)) !== null) $vals[] = $n;
            }
            $res = match ($col['tot']) {
                'cuenta' => $llenas,
                'suma' => array_sum($vals),
                'promedio' => $vals ? array_sum($vals) / count($vals) : 0,
                'min' => $vals ? min($vals) : 0,
                default => $vals ? max($vals) : 0,
            };
            $tot[$c] = self::num($res);
        }
        $d['calc'] = (object)$calc;
        $d['tot'] = (object)$tot;
        return $d;
    }

    private static function num($x): string
    {
        $s = rtrim(rtrim(number_format((float)$x, 10, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private static function resultado(array $r): string
    {
        return isset($r['e']) ? $r['e'] : (isset($r['s']) ? $r['s'] : self::num($r['v']));
    }

    private function celda(int $r, int $c): array
    {
        if ($r < -1 || $c < 0) return ['e' => '#REF!'];
        if ($r === -1) return $c < count($this->cols) ? ['s' => $this->cols[$c]['n']] : ['v' => 0.0, 'vacio' => true];
        if ($r >= count($this->filas) || $c >= count($this->cols)) return ['v' => 0.0, 'vacio' => true];
        $t = $this->filas[$r][$c];
        if (!self::esFormula($t)) {
            if ($t === '') return ['v' => 0.0, 'vacio' => true];
            $n = Sheetflow::numero($t);
            return $n === null ? ['s' => $t] : ['v' => $n];
        }
        $k = "$r,$c";
        if (isset($this->memo[$k])) return $this->memo[$k];
        if (isset($this->visitando[$k])) return ['e' => '#CICLO!'];
        $this->visitando[$k] = true;
        [$ss, $si] = [$this->src, $this->i];
        $res = $this->evaluar(preg_replace('/^\s*=/', '', $t));
        [$this->src, $this->i] = [$ss, $si];
        unset($this->visitando[$k]);
        return $this->memo[$k] = $res;
    }

    private function evaluar(string $src): array
    {
        $this->src = $src;
        $this->i = 0;
        try {
            $r = $this->comparacion();
            $this->esp();
            if ($this->i < strlen($this->src) || isset($r['rango'])) $this->error('#VALOR!');
            return $r;
        } catch (\RuntimeException $e) {
            return ['e' => $e->getMessage()];
        }
    }

    private function error(string $e): never
    {
        throw new \RuntimeException($e);
    }

    private function fallo(string $e): never
    {
        throw new ErrorValor($e);
    }

    private function esp(): void
    {
        while ($this->i < strlen($this->src) && ctype_space($this->src[$this->i])) $this->i++;
    }

    private function n(array $x): float
    {
        if (isset($x['e'])) $this->fallo($x['e']);
        if (isset($x['rango'])) $this->fallo('#VALOR!');
        if (isset($x['s'])) {
            $n = Sheetflow::numero($x['s']);
            if ($n === null) $this->fallo('#VALOR!');
            return $n;
        }
        return (float)$x['v'];
    }

    /** Ejecuta una operación y, si falla por un valor, devuelve el error como valor (el análisis sigue donde iba) */
    private function seguro(callable $f): array
    {
        try {
            return $f();
        } catch (ErrorValor $e) {
            return ['e' => $e->getMessage()];
        }
    }

    private function esTexto(array $x): bool
    {
        return isset($x['s']) && Sheetflow::numero($x['s']) === null;
    }

    private function texto(array $x): string
    {
        if (isset($x['e'])) $this->fallo($x['e']);
        if (isset($x['rango'])) $this->fallo('#VALOR!');
        return isset($x['s']) ? $x['s'] : (!empty($x['vacio']) ? '' : self::num($x['v']));
    }

    private function comparacion(): array
    {
        $a = $this->concat();
        $this->esp();
        if (!preg_match('/^(<=|>=|<>|<|>|=)/', substr($this->src, $this->i), $m)) return $a;
        $this->i += strlen($m[1]);
        $b = $this->concat();
        return $this->seguro(function () use ($a, $b, $m) {
            if ($this->esTexto($a) || $this->esTexto($b)) {
                $c = strcasecmp($this->texto($a), $this->texto($b));
                $x = $c <=> 0;
                $y = 0;
            } else {
                $x = $this->n($a);
                $y = $this->n($b);
            }
            return ['v' => (float)match ($m[1]) { '=' => $x == $y, '<>' => $x != $y, '<' => $x < $y, '>' => $x > $y, '<=' => $x <= $y, default => $x >= $y }];
        });
    }

    private function concat(): array
    {
        $a = $this->suma();
        $this->esp();
        while (($this->src[$this->i] ?? '') === '&') {
            $this->i++;
            $b = $this->suma();
            $a = $this->seguro(fn() => ['s' => $this->texto($a) . $this->texto($b)]);
            $this->esp();
        }
        return $a;
    }

    private function suma(): array
    {
        $a = $this->prod();
        $this->esp();
        while ($this->i < strlen($this->src) && ($this->src[$this->i] === '+' || $this->src[$this->i] === '-')) {
            $op = $this->src[$this->i++];
            $b = $this->prod();
            $a = $this->seguro(fn() => ['v' => $op === '+' ? $this->n($a) + $this->n($b) : $this->n($a) - $this->n($b)]);
            $this->esp();
        }
        return $a;
    }

    private function prod(): array
    {
        $a = $this->pot();
        $this->esp();
        while ($this->i < strlen($this->src) && ($this->src[$this->i] === '*' || $this->src[$this->i] === '/')) {
            $op = $this->src[$this->i++];
            $b = $this->pot();
            $a = $this->seguro(function () use ($op, $a, $b) {
                if ($op === '/') {
                    $d = $this->n($b);
                    if ($d == 0) $this->fallo('#DIV/0!');
                    return ['v' => $this->n($a) / $d];
                }
                return ['v' => $this->n($a) * $this->n($b)];
            });
            $this->esp();
        }
        return $a;
    }

    private function pot(): array
    {
        $a = $this->unario();
        $this->esp();
        if (($this->src[$this->i] ?? '') === '^') {
            $this->i++;
            $b = $this->pot();
            $a = $this->seguro(fn() => ['v' => (float)pow($this->n($a), $this->n($b))]);
        }
        return $a;
    }

    private function unario(): array
    {
        $this->esp();
        $c = $this->src[$this->i] ?? '';
        if ($c === '-') { $this->i++; $x = $this->unario(); return $this->seguro(fn() => ['v' => -$this->n($x)]); }
        if ($c === '+') { $this->i++; return $this->unario(); }
        return $this->primario();
    }

    private function args(): array
    {
        $out = [];
        $this->esp();
        if (($this->src[$this->i] ?? '') === ')') { $this->i++; return $out; }
        for (;;) {
            $out[] = $this->comparacion();
            $this->esp();
            $c = $this->src[$this->i] ?? '';
            if ($c === ',' || $c === ';') { $this->i++; continue; }
            if ($c === ')') { $this->i++; return $out; }
            $this->error('#VALOR!');
        }
    }

    private function lista(array $a): array
    {
        $nums = [];
        foreach ($a as $x) {
            if (isset($x['rango'])) {
                foreach ($x['rango'] as $c) {
                    if (isset($c['e'])) $this->fallo($c['e']);
                    if (isset($c['v']) && empty($c['vacio'])) $nums[] = $c['v'];
                }
            } elseif (isset($x['e'])) {
                $this->fallo($x['e']);
            } elseif (isset($x['v'])) {
                $nums[] = $x['v'];
            } elseif (isset($x['s']) && ($n = Sheetflow::numero($x['s'])) !== null) {
                $nums[] = $n;
            }
        }
        return $nums;
    }

    private function letraAIndice(string $l): int
    {
        $n = 0;
        foreach (str_split(strtoupper($l)) as $ch) $n = $n * 26 + ord($ch) - 64;
        return $n - 1;
    }

    private function primario(): array
    {
        $this->esp();
        $rest = substr($this->src, $this->i);
        if (preg_match('/^(\d+([.,]\d+)?|[.]\d+)/', $rest, $m)) {
            $this->i += strlen($m[0]);
            $v = (float)str_replace(',', '.', $m[0]);
            $this->esp();
            if (($this->src[$this->i] ?? '') === '%') { $this->i++; $v /= 100; }
            return ['v' => $v];
        }
        if (preg_match('/^(VERDADERO|FALSO|TRUE|FALSE)\b/i', $rest, $m)) {
            $this->i += strlen($m[0]);
            return ['v' => in_array(strtoupper($m[1]), ['VERDADERO', 'TRUE'], true) ? 1.0 : 0.0];
        }
        if (str_starts_with($rest, '#REF!')) { $this->i += 5; return ['e' => '#REF!']; }
        $c = $rest[0] ?? '';
        if ($c === '(') {
            $this->i++;
            $x = $this->comparacion();
            $this->esp();
            if (($this->src[$this->i] ?? '') !== ')') $this->error('#VALOR!');
            $this->i++;
            return $x;
        }
        if ($c === '"') {
            $j = strpos($this->src, '"', $this->i + 1);
            if ($j === false) $this->error('#VALOR!');
            $s = substr($this->src, $this->i + 1, $j - $this->i - 1);
            $this->i = $j + 1;
            return ['s' => $s];
        }
        if (preg_match('/^\$?([A-Za-z]{1,2})\$?(\d+)(?::\$?([A-Za-z]{1,2})\$?(\d+))?(?![A-Za-z(])/', $rest, $m)) {
            $this->i += strlen($m[0]);
            $c0 = $this->letraAIndice($m[1]);
            $r0 = (int)$m[2] - 2;
            if (!empty($m[3])) {
                $c1 = $this->letraAIndice($m[3]);
                $r1 = (int)$m[4] - 2;
                $out = [];
                for ($r = min($r0, $r1); $r <= max($r0, $r1); $r++) for ($cc = min($c0, $c1); $cc <= max($c0, $c1); $cc++) $out[] = $this->celda($r, $cc);
                return ['rango' => $out];
            }
            return $this->celda($r0, $c0);
        }
        if (preg_match('/^([A-Za-zÁÉÍÓÚáéíóú.]+)\s*\(/u', $rest, $m)) {
            $this->i += strlen($m[0]);
            $f = strtr(mb_strtoupper($m[1]), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
            $a = $this->args();
            return $this->seguro(fn() => $this->funcion($f, $a));
        }
        $this->error('#VALOR!');
    }

    /** ¿La celda cumple el criterio de SUMAR.SI / CONTAR.SI? (número, texto, «>5», «<>x», comodines * y ?) */
    private function cumple(array $c, array $crit): bool
    {
        if (isset($c['e'])) return false;
        $t = isset($crit['s']) ? $crit['s'] : (isset($crit['v']) ? self::num($crit['v']) : '');
        $op = '=';
        if (preg_match('/^(<=|>=|<>|<|>|=)(.*)$/s', $t, $m)) [$op, $t] = [$m[1], $m[2]];
        $valor = isset($c['s']) ? $c['s'] : (!empty($c['vacio']) ? '' : self::num($c['v']));
        $nc = Sheetflow::numero($valor);
        $nt = Sheetflow::numero($t);
        if ($nt !== null && $nc !== null && $valor !== '') {
            $x = $nc;
            $y = $nt;
            return match ($op) { '=' => $x == $y, '<>' => $x != $y, '<' => $x < $y, '>' => $x > $y, '<=' => $x <= $y, default => $x >= $y };
        }
        if ($op === '=' || $op === '<>') {
            $re = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($t, '/')) . '$/iu';
            return (bool)preg_match($re, $valor) === ($op === '=');
        }
        if ($nt !== null || $valor === '') return false;
        $k = strcasecmp($valor, $t);
        return match ($op) { '<' => $k < 0, '>' => $k > 0, '<=' => $k <= 0, default => $k >= 0 };
    }

    private function funcion(string $f, array $a): array
    {
        switch ($f) {
            case 'SUMA': case 'SUM': return ['v' => array_sum($this->lista($a))];
            case 'PROMEDIO': case 'AVERAGE': case 'PROM':
                $n = $this->lista($a);
                if (!$n) $this->fallo('#DIV/0!');
                return ['v' => array_sum($n) / count($n)];
            case 'MIN': $n = $this->lista($a); return ['v' => $n ? (float)min($n) : 0.0];
            case 'MAX': $n = $this->lista($a); return ['v' => $n ? (float)max($n) : 0.0];
            case 'CONTAR': case 'COUNT': return ['v' => (float)count($this->lista($a))];
            case 'REDONDEAR': case 'ROUND': return ['v' => round($this->n($a[0] ?? ['v' => 0]), isset($a[1]) ? (int)$this->n($a[1]) : 0)];
            case 'SI': case 'IF': return $this->n($a[0] ?? ['v' => 0]) ? ($a[1] ?? ['v' => 0.0]) : ($a[2] ?? ['v' => 0.0]);
            case 'Y': case 'AND':
            case 'O': case 'OR':
                if (!$a) $this->fallo('#VALOR!');
                $y = in_array($f, ['Y', 'AND'], true);
                foreach ($a as $x) {
                    foreach (isset($x['rango']) ? $x['rango'] : [$x] as $v) {
                        if (isset($v['e'])) $this->fallo($v['e']);
                        if (!empty($v['vacio'])) continue;
                        $b = $this->n($v) != 0;
                        if ($y && !$b) return ['v' => 0.0];
                        if (!$y && $b) return ['v' => 1.0];
                    }
                }
                return ['v' => $y ? 1.0 : 0.0];
            case 'NO': case 'NOT': return ['v' => $this->n($a[0] ?? ['v' => 0]) == 0 ? 1.0 : 0.0];
            case 'SI.ERROR': case 'IFERROR':
                $x = $a[0] ?? ['v' => 0.0];
                return isset($x['e']) ? ($a[1] ?? ['s' => '']) : $x;
            case 'SUMAR.SI': case 'SUMIF': case 'CONTAR.SI': case 'COUNTIF': case 'PROMEDIO.SI': case 'AVERAGEIF':
                $rango = $a[0]['rango'] ?? (isset($a[0]) ? [$a[0]] : null);
                if ($rango === null || !isset($a[1])) $this->fallo('#VALOR!');
                $suma = in_array($f, ['SUMAR.SI', 'SUMIF', 'PROMEDIO.SI', 'AVERAGEIF'], true) ? ($a[2]['rango'] ?? $rango) : null;
                $n = 0;
                $tot = 0.0;
                foreach ($rango as $i => $c) {
                    if (!$this->cumple($c, $a[1])) continue;
                    $n++;
                    if ($suma !== null) {
                        $v = $suma[$i] ?? ['v' => 0.0, 'vacio' => true];
                        if (isset($v['e'])) $this->fallo($v['e']);
                        $x = isset($v['s']) ? Sheetflow::numero($v['s']) : ($v['v'] ?? 0.0);
                        $tot += $x ?? 0.0;
                    }
                }
                if (in_array($f, ['CONTAR.SI', 'COUNTIF'], true)) return ['v' => (float)$n];
                if (in_array($f, ['PROMEDIO.SI', 'AVERAGEIF'], true)) {
                    if (!$n) $this->fallo('#DIV/0!');
                    return ['v' => $tot / $n];
                }
                return ['v' => $tot];
            default: $this->fallo('#NOMBRE?');
        }
    }
}
