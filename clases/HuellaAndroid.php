<?php

namespace App;

/**
 * clases/HuellaAndroid.php — Llaves para entrar con la huella desde la app Android.
 *
 * La huella se confirma en el teléfono (BloqueoHuella.kt); el servidor nunca la ve. Lo que
 * recibe es una llave aleatoria que emitió para esa instalación mientras había sesión, y la
 * cambia por una sesión nueva. Ver la migración 054 para las reglas de vigencia.
 */
class HuellaAndroid
{
    private const DIAS_VIGENCIA = 90;

    /** Tabla de llaves. AvisosAndroid reutiliza esta lógica con su propia tabla (static::TABLA). */
    protected const TABLA = 'llaves_huella_android';

    /** @var \mysqli */
    protected static $db;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    private static function firma(string $hashContrasena): string
    {
        return hash('sha256', $hashContrasena);
    }

    /** Emite una llave nueva para el usuario en esta instalación (revoca las anteriores). */
    public static function emitir(int $usuarioId, string $instalacion): ?string
    {
        $consulta = self::$db->prepare('SELECT contrasena FROM usuarios WHERE id = ? AND rol = 1 LIMIT 1');
        $consulta->bind_param('i', $usuarioId);
        $consulta->execute();
        $usuario = $consulta->get_result()->fetch_assoc();
        $consulta->close();
        if (!$usuario) {
            return null;
        }

        self::revocar($usuarioId, $instalacion);

        $llave = bin2hex(random_bytes(32));
        $hash = hash('sha256', $llave);
        $firma = self::firma((string) $usuario['contrasena']);
        $dias = self::DIAS_VIGENCIA;
        $alta = self::$db->prepare(
            "INSERT INTO " . static::TABLA . " (usuario_id, instalacion, llave_hash, firma_contrasena, expira_en)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL $dias DAY))"
        );
        $alta->bind_param('isss', $usuarioId, $instalacion, $hash, $firma);
        $ok = $alta->execute();
        $alta->close();

        return $ok ? $llave : null;
    }

    /**
     * Valida la llave de esta instalación y devuelve el usuario (id, nombre, email, contrasena, rol)
     * o null. Cada uso alarga la vigencia.
     */
    public static function usar(string $llave, string $instalacion): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $llave)) {
            return null;
        }
        $hash = hash('sha256', $llave);
        $consulta = self::$db->prepare(
            'SELECT l.id AS llave_id, l.firma_contrasena, u.id, u.nombre, u.email, u.contrasena, u.rol
               FROM ' . static::TABLA . ' l
               JOIN usuarios u ON u.id = l.usuario_id
              WHERE l.llave_hash = ? AND l.instalacion = ? AND l.revocada_en IS NULL AND l.expira_en > NOW()
              LIMIT 1'
        );
        $consulta->bind_param('ss', $hash, $instalacion);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$fila || (int) $fila['rol'] !== 1 || !hash_equals((string) $fila['firma_contrasena'], self::firma((string) $fila['contrasena']))) {
            return null;
        }

        $dias = self::DIAS_VIGENCIA;
        $idLlave = (int) $fila['llave_id'];
        $uso = self::$db->prepare("UPDATE " . static::TABLA . " SET usada_en = NOW(), expira_en = DATE_ADD(NOW(), INTERVAL $dias DAY) WHERE id = ?");
        $uso->bind_param('i', $idLlave);
        $uso->execute();
        $uso->close();

        unset($fila['llave_id'], $fila['firma_contrasena']);
        return $fila;
    }

    /** Revoca las llaves del usuario en esta instalación (al quitar la huella o emitir otra). */
    public static function revocar(int $usuarioId, string $instalacion): void
    {
        $consulta = self::$db->prepare(
            'UPDATE ' . static::TABLA . ' SET revocada_en = NOW() WHERE usuario_id = ? AND instalacion = ? AND revocada_en IS NULL'
        );
        $consulta->bind_param('is', $usuarioId, $instalacion);
        $consulta->execute();
        $consulta->close();
    }
}
