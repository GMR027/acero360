<?php

namespace App;

/**
 * Configuracion — parámetros globales clave/valor (tabla configuracion).
 *
 * Hoy: permitir_stock_negativo ("1" = se puede vender sin existencias; los
 * productos se fabrican y entregan después). Con "0" la venta se rechaza.
 */
class Configuracion
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function obtener(string $clave, string $porDefecto = ''): string
    {
        $consulta = self::$db->prepare('SELECT valor FROM configuracion WHERE clave = ?');
        $consulta->bind_param('s', $clave);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? (string) $fila['valor'] : $porDefecto;
    }

    public static function guardar(string $clave, string $valor, ?int $usuarioId = null): void
    {
        $anterior = self::obtener($clave, '');
        $consulta = self::$db->prepare(
            'INSERT INTO configuracion (clave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        $consulta->bind_param('ss', $clave, $valor);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('configuracion', null, 'cambio', $usuarioId, null, [
            'clave' => $clave, 'antes' => $anterior, 'despues' => $valor,
        ]);
    }

    public static function permitirStockNegativo(): bool
    {
        return self::obtener('permitir_stock_negativo', '1') === '1';
    }
}
