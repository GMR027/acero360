<?php

namespace App;

/**
 * PaquetesEscritorio — versiones publicadas de la aplicación de escritorio (.deb).
 *
 * • Publicar es una operación de LÍNEA DE COMANDOS (scripts/publicar_escritorio.php): no hay subida web de
 *   100 MB, y así nada se publica sin una acción deliberada de quien administra el servidor.
 * • La descarga es solo para administradores autenticados y verifica la suma SHA-256 antes de enviar el archivo.
 * • Una versión defectuosa se RETIRA (con motivo, queda en auditoría): deja de ofrecerse, pero se conserva.
 * • Sustituir = publicar una versión nueva; la plataforma siempre ofrece la más reciente no retirada.
 */
class PaquetesEscritorio
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /** Carpeta del almacén de paquetes (dentro del almacén de documentos, fuera de public/). */
    public static function carpeta(): string
    {
        $ruta = Archivos::base() . '/escritorio';
        if (!is_dir($ruta)) {
            @mkdir($ruta, 0750, true);
        }

        return $ruta;
    }

    /** @return array<int, array<string, mixed>> más recientes primero */
    public static function listar(): array
    {
        return self::$db->query(
            'SELECT p.*, u.nombre AS publicada_por_nombre, r.nombre AS retirada_por_nombre
               FROM paquetes_escritorio p
               LEFT JOIN usuarios u ON u.id = p.publicada_por
               LEFT JOIN usuarios r ON r.id = p.retirada_por
              ORDER BY p.publicada_en DESC, p.id DESC'
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM paquetes_escritorio WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** Última versión publicada (no retirada) de una arquitectura. */
    public static function vigente(string $arquitectura = 'amd64'): ?array
    {
        $consulta = self::$db->prepare("SELECT * FROM paquetes_escritorio WHERE estado = 'publicada' AND arquitectura = ? ORDER BY publicada_en DESC, id DESC LIMIT 1");
        $consulta->bind_param('s', $arquitectura);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /**
     * Registra un .deb ya validado (lo llama scripts/publicar_escritorio.php).
     *
     * @param string $rutaDeb ruta del archivo local
     */
    public static function publicar(string $rutaDeb, string $version, string $arquitectura, string $notas, ?int $usuarioId): bool
    {
        self::$errores = [];

        if (!preg_match('/^\d+\.\d+\.\d+([\-+.][A-Za-z0-9.]+)?$/', $version)) {
            self::$errores[] = 'La versión debe tener el formato 1.2.3.';
            return false;
        }
        if (!in_array($arquitectura, ['amd64', 'arm64'], true)) {
            self::$errores[] = 'Arquitectura no admitida (amd64 o arm64).';
            return false;
        }
        if (!is_file($rutaDeb) || filesize($rutaDeb) < 1024) {
            self::$errores[] = 'El archivo no existe o está vacío.';
            return false;
        }
        $cabecera = (string) file_get_contents($rutaDeb, false, null, 0, 8);
        if ($cabecera !== "!<arch>\n") {
            self::$errores[] = 'El archivo no es un paquete .deb (formato ar).';
            return false;
        }

        $sha = hash_file('sha256', $rutaDeb);
        $nombre = sprintf('cotizaciones-escritorio_%s_%s.deb', $version, $arquitectura);
        $relativa = 'escritorio/' . $nombre;
        $destino = Archivos::base() . '/' . $relativa;

        $existe = self::$db->prepare('SELECT 1 FROM paquetes_escritorio WHERE version = ? AND arquitectura = ?');
        $existe->bind_param('ss', $version, $arquitectura);
        $existe->execute();
        if ($existe->get_result()->num_rows > 0) {
            $existe->close();
            self::$errores[] = "La versión $version ($arquitectura) ya está publicada: una versión publicada no se sobrescribe; sube un número nuevo.";
            return false;
        }
        $existe->close();

        self::carpeta();
        if (!copy($rutaDeb, $destino)) {
            self::$errores[] = 'No fue posible copiar el paquete al almacén.';
            return false;
        }
        @chmod($destino, 0640);

        $tamano = (int) filesize($destino);
        $notas = trim($notas) ?: null;
        $consulta = self::$db->prepare(
            'INSERT INTO paquetes_escritorio (version, arquitectura, archivo, nombre_archivo, tamano, sha256, notas, publicada_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $consulta->bind_param('ssssissi', $version, $arquitectura, $relativa, $nombre, $tamano, $sha, $notas, $usuarioId);
        $consulta->execute();
        $id = (int) self::$db->insert_id;
        $consulta->close();

        Auditoria::registrar('paquete_escritorio', $id, 'publicar', $usuarioId, $notas, ['version' => $version, 'sha256' => $sha, 'tamano' => $tamano]);
        return true;
    }

    /** Retira una versión defectuosa (no se borra). Exige motivo. */
    public static function retirar(int $id, string $motivo, int $usuarioId): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);
        $paquete = self::buscarID($id);

        if (!$paquete || $paquete['estado'] !== 'publicada') {
            self::$errores[] = 'La versión no existe o ya fue retirada.';
            return false;
        }
        if (mb_strlen($motivo) < 10) {
            self::$errores[] = 'Escribe el motivo del retiro (mínimo 10 caracteres).';
            return false;
        }

        $consulta = self::$db->prepare("UPDATE paquetes_escritorio SET estado = 'retirada', motivo_retiro = ?, retirada_por = ?, retirada_en = NOW() WHERE id = ?");
        $consulta->bind_param('sii', $motivo, $usuarioId, $id);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('paquete_escritorio', $id, 'retirar', $usuarioId, $motivo, ['version' => $paquete['version']]);
        return true;
    }

    /**
     * Envía el paquete. Verifica la suma antes: un archivo alterado o dañado nunca se entrega.
     * Solo se descargan versiones publicadas.
     */
    public static function descargar(int $id, int $usuarioId): void
    {
        $paquete = self::buscarID($id);
        $ruta = $paquete ? realpath(Archivos::base() . '/' . $paquete['archivo']) : false;
        $base = realpath(Archivos::base());

        if (!$paquete || $paquete['estado'] !== 'publicada' || $ruta === false || $base === false
            || strncmp($ruta, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($ruta)) {
            \paginaNoEncontrada();
        }

        if (!hash_equals($paquete['sha256'], hash_file('sha256', $ruta))) {
            Auditoria::registrar('paquete_escritorio', $id, 'descarga_rechazada', $usuarioId, 'La suma SHA-256 no coincide: archivo alterado o dañado');
            error_log('[PaquetesEscritorio] suma SHA-256 distinta para el paquete #' . $id);
            \redirigir('/admin/escritorio', 'error', 'El archivo no pasó la verificación de integridad y no se entregó. Vuelve a publicar esta versión.');
        }

        Auditoria::registrar('paquete_escritorio', $id, 'descarga', $usuarioId, null, ['version' => $paquete['version']]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.debian.binary-package');
        header('Content-Disposition: attachment; filename="' . $paquete['nombre_archivo'] . '"');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($ruta);
        exit;
    }
}
