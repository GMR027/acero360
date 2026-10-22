<?php

namespace Classes;

/**
 * Carpetas propias de cada herramienta (Redactor, SheetFlow, Minutas): la carpeta es el departamento del documento.
 * Renombrar y mover solo afectan a los documentos de esa herramienta, no a la lista de departamentos de la bitácora.
 */
class Carpetas
{
    public const SIN = 'Sin departamento';
    private const TABLAS = ['redactor' => 'redactor_documentos', 'sheetflow' => 'sheetflow_hojas', 'minutas' => 'minutas'];

    public static function tabla(string $herramienta): ?string
    {
        return self::TABLAS[$herramienta] ?? null;
    }

    private static function limpiar(string $n): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $n)), 0, 100);
    }

    /** Renombra la carpeta $de a $a dentro de la herramienta; si $a ya existe, se unen. Devuelve los documentos afectados */
    public static function renombrar(string $herramienta, int $proyectoId, string $de, string $a): int
    {
        $t = self::TABLAS[$herramienta];
        $db = \Model\ActiveRecord::conexion();
        $d = $db->escape_string($de === self::SIN ? '' : $de);
        $n = $db->escape_string(self::limpiar($a) === self::SIN ? '' : self::limpiar($a));
        $db->query("UPDATE $t SET departamento = '$n' WHERE departamento = '$d'");
        return $db->affected_rows;
    }

    /** Pasa un documento a la carpeta $a (vacío o «Sin departamento» = sin carpeta) */
    public static function mover(string $herramienta, int $proyectoId, int $id, string $a): bool
    {
        $t = self::TABLAS[$herramienta];
        $db = \Model\ActiveRecord::conexion();
        $a = self::limpiar($a);
        $n = $db->escape_string($a === self::SIN ? '' : $a);
        $db->query("UPDATE $t SET departamento = '$n' WHERE id = " . (int)$id);
        return $db->affected_rows >= 0;
    }

    /** Selector «mover a…» de un renglón (con opción de crear una carpeta nueva) */
    public static function selector(string $herramienta, int $id, string $actual, array $carpetas): string
    {
        $o = '<option value="">Mover a…</option>';
        foreach (array_unique(array_merge($carpetas, [self::SIN])) as $c) {
            if ($c === ($actual === '' ? self::SIN : $actual)) continue;
            $o .= '<option value="' . htmlspecialchars($c) . '">' . htmlspecialchars($c) . '</option>';
        }
        $o .= '<option value="__nueva">＋ Carpeta nueva…</option>';
        return '<select class="carpeta-mover" data-herramienta="' . $herramienta . '" data-id="' . $id . '" aria-label="Mover a otra carpeta">' . $o . '</select>';
    }
}
