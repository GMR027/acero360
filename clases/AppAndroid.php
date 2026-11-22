<?php

namespace App;

/**
 * AppAndroid — aplicación Android de Acero 360 (Tauri 2, carpeta android/ del repositorio).
 *
 * La app es una ventana hacia esta misma plataforma: una pantalla local revisa la versión
 * (GET /app/android/version.json) y después abre el sitio en su WebView, pasando por
 * /app/android/entrar, que deja una cookie con la versión instalada y el id de instalación.
 * Con esa cookie el servidor sabe qué versión trae cada teléfono (dispositivos_android).
 *
 * • Publicar es de LÍNEA DE COMANDOS (scripts/publicar_android.php), como el .deb de escritorio.
 * • La última versión se descarga SIN sesión (/app/android/descargar): el teléfono la baja desde
 *   el navegador del sistema, que no comparte la sesión de la app. El APK no contiene datos ni
 *   secretos (solo la dirección del servidor). Las versiones anteriores exigen administrador.
 * • La descarga verifica la suma SHA-256 antes de enviar el archivo.
 * • Una versión defectuosa se RETIRA (con motivo, queda en auditoría).
 */
class AppAndroid
{
    /** Nombre de la cookie que deja /app/android/entrar. */
    public const COOKIE = 'acero360_android';

    /** Aplicaciones Android publicadas desde esta plataforma. */
    public const APPS = ['acero360', 'entregas'];

    /** @var \mysqli */
    protected static $db;

    /** @var string[] */
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

    public static function carpeta(): string
    {
        $ruta = Archivos::base() . '/android';
        if (!is_dir($ruta)) {
            @mkdir($ruta, 0750, true);
        }

        return $ruta;
    }

    /** Código de versión de Android tal como lo calcula Tauri: mayor·1 000 000 + menor·1 000 + parche. */
    public static function versionCode(string $version): int
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m)) {
            return 0;
        }

        return (int) $m[1] * 1000000 + (int) $m[2] * 1000 + (int) $m[3];
    }

    /** @return array<int, array<string, mixed>> */
    public static function listar(string $aplicacion = 'acero360'): array
    {
        $aplicacion = in_array($aplicacion, self::APPS, true) ? $aplicacion : 'acero360';
        $consulta = self::$db->prepare(
            'SELECT p.*, u.nombre AS publicada_por_nombre
               FROM paquetes_android p
               LEFT JOIN usuarios u ON u.id = p.publicada_por
              WHERE p.aplicacion = ?
              ORDER BY p.version_code DESC, p.id DESC'
        );
        $consulta->bind_param('s', $aplicacion);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM paquetes_android WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** Versión vigente: la de mayor version_code que no esté retirada. */
    public static function vigente(string $aplicacion = 'acero360'): ?array
    {
        $aplicacion = in_array($aplicacion, self::APPS, true) ? $aplicacion : 'acero360';
        $consulta = self::$db->prepare(
            "SELECT * FROM paquetes_android
              WHERE aplicacion = ? AND estado = 'publicada'
              ORDER BY version_code DESC, id DESC LIMIT 1"
        );
        $consulta->bind_param('s', $aplicacion);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** Lo que la app consulta para saber si hay actualización (público, sin datos privados). */
    public static function infoPublica(string $urlBase, string $aplicacion = 'acero360'): array
    {
        $v = self::vigente($aplicacion);
        if (!$v) {
            return ['disponible' => false];
        }
        $ruta = $aplicacion === 'entregas' ? '/app/entregas/descargar' : '/app/android/descargar';

        return [
            'disponible'   => true,
            'aplicacion'   => $aplicacion,
            'version'      => $v['version'],
            'version_code' => (int) $v['version_code'],
            'tamano'       => (int) $v['tamano'],
            'sha256'       => $v['sha256'],
            'notas'        => (string) ($v['notas'] ?? ''),
            'publicada'    => date('c', strtotime($v['publicada_en'])),
            'url'          => rtrim($urlBase, '/') . $ruta,
        ];
    }

    /** Registra un APK ya firmado (scripts/publicar_android.php y publicar_entregas.php). */
    public static function publicar(string $rutaApk, string $version, string $notas, ?int $usuarioId, string $aplicacion = 'acero360'): bool
    {
        self::$errores = [];
        $aplicacion = in_array($aplicacion, self::APPS, true) ? $aplicacion : 'acero360';

        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            self::$errores[] = 'La versión debe tener el formato 1.2.3.';
            return false;
        }
        if (!is_file($rutaApk) || filesize($rutaApk) < 1024) {
            self::$errores[] = 'El archivo no existe o está vacío.';
            return false;
        }
        // Un APK es un ZIP que trae AndroidManifest.xml.
        $zip = new \ZipArchive();
        if ((string) file_get_contents($rutaApk, false, null, 0, 4) !== "PK\x03\x04" || $zip->open($rutaApk) !== true) {
            self::$errores[] = 'El archivo no es un APK (no es un ZIP válido).';
            return false;
        }
        $tieneManifiesto = $zip->locateName('AndroidManifest.xml') !== false;
        $firmado = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^META-INF/.+\.(RSA|EC|DSA)$#', (string) $zip->getNameIndex($i))) {
                $firmado = true;
                break;
            }
        }
        $zip->close();
        if (!$tieneManifiesto) {
            self::$errores[] = 'El archivo no es un APK (falta AndroidManifest.xml).';
            return false;
        }
        if (!$firmado && !self::tieneBloqueFirmaV2($rutaApk)) {
            self::$errores[] = 'El APK no está firmado: Android no lo instalará. Compila con la firma de publicación (android/compilar.sh).';
            return false;
        }

        $codigo = self::versionCode($version);
        $mayorQ = self::$db->prepare(
            'SELECT version, version_code FROM paquetes_android
              WHERE aplicacion = ? ORDER BY version_code DESC LIMIT 1'
        );
        $mayorQ->bind_param('s', $aplicacion);
        $mayorQ->execute();
        $mayor = $mayorQ->get_result()->fetch_assoc();
        $mayorQ->close();
        if ($mayor && (int) $mayor['version_code'] >= $codigo) {
            self::$errores[] = "La versión $version no es mayor que la ya publicada {$mayor['version']}: Android no la aceptaría como actualización. Una versión publicada no se sobrescribe; sube un número nuevo.";
            return false;
        }

        $sha = hash_file('sha256', $rutaApk);
        $nombre = $aplicacion === 'entregas'
            ? sprintf('acero360-entregas_%s.apk', $version)
            : sprintf('acero360-android_%s.apk', $version);
        $relativa = 'android/' . $nombre;
        $destino = Archivos::base() . '/' . $relativa;

        self::carpeta();
        if (!copy($rutaApk, $destino)) {
            self::$errores[] = 'No fue posible copiar el APK al almacén.';
            return false;
        }
        @chmod($destino, 0640);
        foreach ([$destino, dirname($destino)] as $ruta) {
            @chown($ruta, 'www-data');
            @chgrp($ruta, 'www-data');
        }

        $tamano = (int) filesize($destino);
        $notas = trim($notas) ?: null;
        $consulta = self::$db->prepare(
            'INSERT INTO paquetes_android (aplicacion, version, version_code, archivo, nombre_archivo, tamano, sha256, notas, publicada_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $consulta->bind_param('ssississi', $aplicacion, $version, $codigo, $relativa, $nombre, $tamano, $sha, $notas, $usuarioId);
        $consulta->execute();
        $id = (int) self::$db->insert_id;
        $consulta->close();

        Auditoria::registrar('paquete_android', $id, 'publicar', $usuarioId, $notas, [
            'aplicacion' => $aplicacion, 'version' => $version, 'sha256' => $sha, 'tamano' => $tamano,
        ]);
        return true;
    }

    /** Firma v2/v3 de APK: bloque «APK Sig Block 42» antes del directorio central del ZIP. */
    protected static function tieneBloqueFirmaV2(string $ruta): bool
    {
        $tam = filesize($ruta);
        $leer = (int) min($tam, 70000);
        $cola = (string) file_get_contents($ruta, false, null, $tam - $leer, $leer);
        $pos = strrpos($cola, "PK\x05\x06");
        if ($pos === false) {
            return false;
        }
        $inicioCentral = unpack('V', substr($cola, $pos + 16, 4))[1];
        if ($inicioCentral < 24) {
            return false;
        }

        return (string) file_get_contents($ruta, false, null, $inicioCentral - 16, 16) === 'APK Sig Block 42';
    }

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

        $consulta = self::$db->prepare("UPDATE paquetes_android SET estado = 'retirada', motivo_retiro = ?, retirada_por = ?, retirada_en = NOW() WHERE id = ?");
        $consulta->bind_param('sii', $motivo, $usuarioId, $id);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('paquete_android', $id, 'retirar', $usuarioId, $motivo, ['version' => $paquete['version']]);
        return true;
    }

    /** Envía un APK publicado (verifica la suma SHA-256 antes). */
    public static function descargar(array $paquete, ?int $usuarioId): void
    {
        $ruta = realpath(Archivos::base() . '/' . $paquete['archivo']);
        $base = realpath(Archivos::base());

        if ($paquete['estado'] !== 'publicada' || $ruta === false || $base === false
            || strncmp($ruta, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($ruta)) {
            \paginaNoEncontrada();
        }

        if (!hash_equals($paquete['sha256'], hash_file('sha256', $ruta))) {
            Auditoria::registrar('paquete_android', (int) $paquete['id'], 'descarga_rechazada', $usuarioId, 'La suma SHA-256 no coincide: archivo alterado o dañado');
            error_log('[AppAndroid] suma SHA-256 distinta para el paquete #' . $paquete['id']);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'El archivo no pasó la verificación de integridad y no se entregó.';
            exit;
        }

        Auditoria::registrar('paquete_android', (int) $paquete['id'], 'descarga', $usuarioId, null, ['version' => $paquete['version']]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="' . $paquete['nombre_archivo'] . '"');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($ruta);
        exit;
    }

    /* -----------------------------------------------------------------------
     * Versión instalada en el teléfono
     * --------------------------------------------------------------------- */

    /**
     * La app entra por /app/android/entrar?v=1.2.3&id=<uuid>: se guarda en una cookie de un año
     * (HttpOnly) para reconocer la app en cada petición, aunque se cierre la sesión.
     */
    public static function recordarApp(string $version, string $instalacion): bool
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $instalacion)) {
            return false;
        }

        setcookie(self::COOKIE, $version . '|' . $instalacion, [
            'expires'  => time() + 365 * 86400,
            'path'     => '/',
            'secure'   => \conexionSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $version . '|' . $instalacion;
        unset($_SESSION['android_visto']);

        return true;
    }

    /** @return array{version: string, instalacion: string}|null cuando la petición viene de la app */
    public static function desdeApp(): ?array
    {
        $valor = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^(\d+\.\d+\.\d+)\|([0-9a-f-]{36})$/', $valor, $m)) {
            return null;
        }

        return ['version' => $m[1], 'instalacion' => $m[2]];
    }

    /** Modelo y versión de Android a partir del agente de usuario del WebView. */
    public static function datosNavegador(string $agente): array
    {
        $modelo = null;
        $android = null;
        if (preg_match('/Android\s+([\d.]+)(?:;\s*([^;)]+?))?(?:\s+Build\/[^;)]*)?[;)]/', $agente, $m)) {
            $android = $m[1];
            $modelo = isset($m[2]) && trim($m[2]) !== '' && strtoupper(trim($m[2])) !== 'K' ? trim($m[2]) : null;
        }

        return ['modelo' => $modelo ? mb_substr($modelo, 0, 80) : null, 'android' => $android];
    }

    /**
     * Anota el dispositivo (una vez por sesión, o cuando cambia la versión). Lo llama el header
     * en cada página con sesión: así se sabe qué usuario usa qué teléfono y con qué versión.
     */
    public static function registrarVisita(?int $usuarioId): void
    {
        $app = self::desdeApp();
        if (!$app || !self::$db) {
            return;
        }
        $marca = $app['version'] . '|' . $app['instalacion'] . '|' . (int) $usuarioId;
        if (($_SESSION['android_visto'] ?? '') === $marca) {
            return;
        }

        try {
            $nav = self::datosNavegador((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $consulta = self::$db->prepare(
                'INSERT INTO dispositivos_android (instalacion, usuario_id, version_app, modelo, version_android)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE usuario_id = COALESCE(VALUES(usuario_id), usuario_id), version_app = VALUES(version_app),
                    modelo = COALESCE(VALUES(modelo), modelo), version_android = COALESCE(VALUES(version_android), version_android), ultima_vez = NOW()'
            );
            $consulta->bind_param('sisss', $app['instalacion'], $usuarioId, $app['version'], $nav['modelo'], $nav['android']);
            $consulta->execute();
            $consulta->close();
            $_SESSION['android_visto'] = $marca;
        } catch (\Throwable $e) {
            error_log('[AppAndroid] no se registró el dispositivo: ' . $e->getMessage());
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function dispositivos(): array
    {
        return self::$db->query(
            'SELECT d.*, u.nombre AS usuario_nombre
               FROM dispositivos_android d
               LEFT JOIN usuarios u ON u.id = d.usuario_id
              ORDER BY d.ultima_vez DESC'
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** ¿La versión instalada es más vieja que la publicada? */
    public static function desactualizada(string $instalada): bool
    {
        $v = self::vigente();

        return $v !== null && version_compare($instalada, (string) $v['version'], '<');
    }
}
