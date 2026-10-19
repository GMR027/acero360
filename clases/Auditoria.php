<?php

namespace App;

/**
 * Auditoria — bitácora de operaciones importantes (quién, qué, cuándo, por qué).
 *
 * Nunca guardes contraseñas ni datos sensibles en $detalle.
 */
class Auditoria
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function registrar(
        string $entidad,
        ?int $entidadId,
        string $accion,
        ?int $usuarioId = null,
        ?string $motivo = null,
        ?array $detalle = null
    ): void {
        try {
            $usuarioId ??= (int) ($_SESSION['id'] ?? 0) ?: null;
            $json = $detalle !== null ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;

            $consulta = self::$db->prepare(
                'INSERT INTO auditoria (usuario_id, entidad, entidad_id, accion, motivo, detalle)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $consulta->bind_param('isisss', $usuarioId, $entidad, $entidadId, $accion, $motivo, $json);
            $consulta->execute();
            $consulta->close();
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Auditoria::registrar] ' . $excepcion->getMessage());
        }
    }
}
