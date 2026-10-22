<?php

namespace Classes;

/** Acceso a la base para las clases estáticas de las herramientas (Redactor y SheetFlow). */
trait AccesoDb
{
    private static function db(): \mysqli
    {
        return \Model\ActiveRecord::conexion();
    }

    /** Filas de un SELECT; vacío si la consulta falla (p. ej. tabla que aún no existe) */
    private static function filas(string $sql): array
    {
        try {
            $r = self::db()->query($sql);
            return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function esc($v): string
    {
        return self::db()->real_escape_string((string)$v);
    }

    private static function corto(?string $t, int $n): string
    {
        $t = trim((string)preg_replace('/\s+/u', ' ', (string)$t));
        return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;
    }
}
