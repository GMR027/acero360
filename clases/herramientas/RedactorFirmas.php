<?php

namespace Classes;

use Model\RedactorDocumento;
use App\Usuarios as Usuario;

/** Firmas del Redactor: firmas manuscritas guardadas por usuario y sellos de firma electrónica interna con huella del contenido */
class RedactorFirmas
{
    use AccesoDb;

    public const CARPETA = __DIR__ . '/../../public/uploads/firmas';
    public const MAX_POR_USUARIO = 5;
    public const SRC = '#^/public/uploads/firmas/[a-f0-9]{32}\.png$#';

    /** Firmas guardadas del usuario: [['id', 'nombre', 'src'], ...] */
    public static function deUsuario(int $usuarioId): array
    {
        $out = [];
        try {
            $r = self::db()->query("SELECT id, nombre, archivo FROM redactor_firmas WHERE usuario_id = $usuarioId ORDER BY id DESC");
            while ($r && ($f = $r->fetch_assoc())) $out[] = ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'src' => '/public/uploads/firmas/' . $f['archivo']];
        } catch (\Throwable $e) {   // migración pendiente
        }
        return $out;
    }

    /** Guarda una firma dibujada (data URI PNG) y devuelve ['id', 'nombre', 'src'] o un mensaje de error */
    public static function guardar(int $usuarioId, string $nombre, string $dataUri): array|string
    {
        $nombre = mb_substr(trim($nombre), 0, 60) ?: 'Mi firma';
        if (count(self::deUsuario($usuarioId)) >= self::MAX_POR_USUARIO) return 'Ya tienes ' . self::MAX_POR_USUARIO . ' firmas guardadas; elimina una primero';
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUri, $m) || strlen($dataUri) > 1_500_000) return 'La firma no es válida';
        $bin = base64_decode($m[1], true);
        $info = $bin !== false ? @getimagesizefromstring($bin) : false;
        if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] > 1600 || $info[1] > 800) return 'La firma no es una imagen PNG válida';
        if (!is_dir(self::CARPETA)) mkdir(self::CARPETA, 0775, true);
        $archivo = md5(uniqid('', true)) . '.png';
        if (file_put_contents(self::CARPETA . '/' . $archivo, $bin) === false) return 'No se pudo guardar la firma';
        $n = self::db()->real_escape_string($nombre);
        self::db()->query("INSERT INTO redactor_firmas (usuario_id, nombre, archivo) VALUES ($usuarioId, '$n', '$archivo')");
        return ['id' => (int)self::db()->insert_id, 'nombre' => $nombre, 'src' => '/public/uploads/firmas/' . $archivo];
    }

    public static function eliminar(int $usuarioId, int $id): void
    {
        $r = self::db()->query("SELECT archivo FROM redactor_firmas WHERE id = $id AND usuario_id = $usuarioId");
        if ($r && ($f = $r->fetch_assoc())) {
            self::db()->query("DELETE FROM redactor_firmas WHERE id = $id AND usuario_id = $usuarioId");
            @unlink(self::CARPETA . '/' . basename($f['archivo']));
        }
    }

    /** ¿La imagen de firma pertenece a este usuario? (solo puede insertar las suyas) */
    public static function esDe(int $usuarioId, string $src): bool
    {
        if (!preg_match(self::SRC, $src)) return false;
        $a = self::db()->real_escape_string(basename($src));
        $r = self::db()->query("SELECT 1 FROM redactor_firmas WHERE usuario_id = $usuarioId AND archivo = '$a' LIMIT 1");
        return $r && $r->num_rows > 0;
    }

    // ---------- Sellos de firma electrónica ----------

    /** Huella del contenido firmado: texto visible (sin comentarios ni cambios pendientes por resolver) e imágenes, independiente del formato */
    public static function huella(RedactorDocumento $d): string
    {
        $html = Redactor::resolverCambios((string)$d->contenido, true);
        preg_match_all('#/public/uploads/(?:vplan|firmas)/[a-f0-9]{32}\.(?:png|jpg)#', $html, $m);
        return hash('sha256', Redactor::textoPlano($html) . "\n" . implode(',', $m[0]));
    }

    /** Sellos del documento con su validez: [['id', 'nombre', 'cargo', 'firmado', 'valido'], ...] */
    public static function sellos(RedactorDocumento $d): array
    {
        $out = [];
        if (!$d->id) return $out;
        $actual = self::huella($d);
        try {
            $r = self::db()->query('SELECT id, nombre, cargo, huella, firmado FROM redactor_sellos WHERE documento_id = ' . (int)$d->id . ' ORDER BY id');
            while ($r && ($f = $r->fetch_assoc())) $out[] = ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'cargo' => $f['cargo'], 'firmado' => $f['firmado'], 'huella' => $f['huella'], 'valido' => hash_equals($f['huella'], $actual)];
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** Firma electrónicamente el documento tal como está guardado. Pide la contraseña del usuario; devuelve null si todo salió bien o un mensaje */
    public static function sellar(RedactorDocumento $d, Usuario $u, string $cargo, string $password): ?string
    {
        if (!password_verify($password, (string)$u->contrasena)) return 'La contraseña no coincide';
        $nombre = self::db()->real_escape_string(mb_substr((string)$u->nombre, 0, 100));
        $cargo = self::db()->real_escape_string(mb_substr(trim($cargo), 0, 100));
        $h = self::huella($d);
        self::db()->query('INSERT INTO redactor_sellos (documento_id, usuario_id, nombre, cargo, huella) VALUES (' . (int)$d->id . ', ' . (int)$u->id . ", '$nombre', '$cargo', '$h')");
        return null;
    }

    public static function quitarSello(RedactorDocumento $d, int $id): void
    {
        self::db()->query("DELETE FROM redactor_sellos WHERE id = $id AND documento_id = " . (int)$d->id);
    }
}
