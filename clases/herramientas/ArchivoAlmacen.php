<?php

namespace Classes;

/**
 * ArchivoAlmacen — validación y guardado de los archivos de la herramienta
 * «Archivo» (carpetas virtuales con ofimática, PDF y modelos BIM/CAD).
 *
 * Reglas de seguridad:
 *   • La herramienta admite cualquier extensión. Los formatos conocidos se
 *     validan además por su contenido (firma); los desconocidos se guardan
 *     como binario y sólo se sirven como descarga.
 *   • Los bytes viven FUERA de public/ (storage/archivo/…), con nombre
 *     aleatorio: el nombre del usuario sólo se guarda como dato.
 *   • Tamaño máximo limitado por el PHP del servidor (se informa en el error).
 *   • La descarga resuelve la ruta con realpath dentro del almacén.
 */
class ArchivoAlmacen
{
    /** @var string[] Mensajes de la última operación. */
    private static $errores = [];

    /**
     * extensión => [familia, mime]
     *
     * ofimatica: Office (doc/docx/xls/xlsx/ppt/pptx), LibreOffice y OnlyOffice
     * (odt/ods/odp y los .docx/.xlsx/.pptx que comparten el mismo contenedor
     * OOXML). modelos: Rhinoceros, Revit, AutoCAD, SketchUp, intercambio BIM.
     */
    private const FORMATOS = [
        // Ofimática — Microsoft Office
        'doc' => 'ofimatica', 'docx' => 'ofimatica', 'docm' => 'ofimatica', 'dotx' => 'ofimatica',
        'xls' => 'ofimatica', 'xlsx' => 'ofimatica', 'xlsm' => 'ofimatica', 'xlsb' => 'ofimatica',
        'ppt' => 'ofimatica', 'pptx' => 'ofimatica', 'pptm' => 'ofimatica',
        // Ofimática — LibreOffice / OnlyOffice / texto
        'odt' => 'ofimatica', 'ods' => 'ofimatica', 'odp' => 'ofimatica', 'odg' => 'ofimatica',
        'rtf' => 'ofimatica', 'txt' => 'texto', 'md' => 'texto', 'csv' => 'datos', 'tsv' => 'datos',
        // Documentos
        'pdf' => 'pdf',
        // Imágenes
        'jpg' => 'imagen', 'jpeg' => 'imagen', 'png' => 'imagen', 'gif' => 'imagen',
        'webp' => 'imagen', 'bmp' => 'imagen', 'svg' => 'imagen', 'tif' => 'imagen', 'tiff' => 'imagen',
        // Comprimidos
        'zip' => 'comprimido', 'rar' => 'comprimido', '7z' => 'comprimido', 'tar' => 'comprimido', 'gz' => 'comprimido',
        // CAD / BIM / modelado
        'dwg' => 'cad', 'dxf' => 'cad', 'dgn' => 'cad',
        'rvt' => 'cad', 'rfa' => 'cad', 'rte' => 'cad',
        '3dm' => 'cad', 'skp' => 'cad', 'obj' => 'cad', 'stl' => 'cad', '3ds' => 'cad',
        'step' => 'cad', 'stp' => 'cad', 'iges' => 'cad', 'igs' => 'cad', 'fbx' => 'cad', 'ifc' => 'cad',
        // Audio / vídeo ligeros
        'mp3' => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'm4a' => 'audio',
        'mp4' => 'video', 'mov' => 'video', 'avi' => 'video', 'mkv' => 'video',
    ];

    /** Imágenes raster que GD puede decodificar para la miniatura. */
    private const IMAGENES_GD = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const MIME = [
        'ofimatica' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf'       => 'application/pdf',
        'imagen'    => 'image/jpeg',
        'comprimido'=> 'application/zip',
        'cad'       => 'application/octet-stream',
        'texto'     => 'text/plain',
        'datos'     => 'text/csv',
        'audio'     => 'audio/mpeg',
        'video'     => 'video/mp4',
    ];

    /* --------------------------------------------------------------------
     * CONSULTAS DE CONFIGURACIÓN
     * ------------------------------------------------------------------ */

    /** Carpeta raíz del almacén (se crea al primer uso, fuera de public/). */
    public static function base(): string
    {
        $ruta = getenv('ARCHIVO_PATH') ?: dirname(__DIR__, 2) . '/storage/archivo';
        if (!is_dir($ruta)) {
            @mkdir($ruta, 0750, true);
        }
        return rtrim($ruta, '/');
    }

    /** Límite real de subida según el PHP del servidor (en bytes). */
    public static function maxBytes(): int
    {
        $valores = [];
        foreach (['upload_max_filesize', 'post_max_size'] as $clave) {
            $v = strtolower(trim((string) ini_get($clave)));
            if ($v === '') continue;
            $unidad = substr($v, -1);
            $numero = (float) $v;
            $valores[] = (int) ($numero * match ($unidad) {
                'g' => 1073741824,
                'm' => 1048576,
                'k' => 1024,
                default => 1,
            });
        }
        $min = $valores ? min($valores) : 2097152;
        return max(1048576, $min);
    }

    /**
     * Formatos con validación de firma conocida.
     *
     * La herramienta admite cualquier archivo: esta lista sólo sirve para
     * comprobar el contenido de los tipos que sabemos reconocer y para elegir
     * su familia. El resto se guarda como binario genérico.
     */
    public static function extensiones(): array
    {
        return array_keys(self::FORMATOS);
    }

    /** Familia que corresponde a una extensión ('otro' si no la conocemos). */
    public static function familia(string $ext): string
    {
        return self::FORMATOS[strtolower($ext)] ?? 'otro';
    }

    /**
     * ¿Se puede generar en el servidor una miniatura fiable para esta extensión?
     * PDF (primera página), imágenes raster que entiende GD y SVG (se sirve tal cual).
     */
    public static function admiteMiniatura(string $ext): bool
    {
        $ext = strtolower($ext);
        return $ext === 'pdf' || $ext === 'svg' || in_array($ext, self::IMAGENES_GD, true);
    }

    /** MIME razonable para una familia. */
    public static function mime(string $familia): string
    {
        return self::MIME[$familia] ?? 'application/octet-stream';
    }

    /** MIME real de una imagen/PDF al servirla inline (la familia no basta). */
    public static function mimeInline(string $ext): string
    {
        return match (strtolower($ext)) {
            'pdf'         => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'bmp'         => 'image/bmp',
            'svg'         => 'image/svg+xml',
            'avif'        => 'image/avif',
            default       => 'application/octet-stream',
        };
    }

    /** Tamaño en bytes convertido a texto legible (1,4 MB / 312 KB / 98 B). */
    public static function legible(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    }

    public static function errores(): array
    {
        $e = self::$errores;
        self::$errores = [];
        return $e;
    }

    /* --------------------------------------------------------------------
     * VALIDACIÓN
     * ------------------------------------------------------------------ */

    /**
     * Comprueba una subida: código de error, tamaño, extensión y, cuando el
     * formato es conocido, la firma real del contenido. Se admite cualquier
     * extensión (o ninguna); lo desconocido se guarda como binario.
     *
     * @param array{name?: string, tmp_name?: string, size?: int, error?: int} $archivo
     * @return array{ext: string, familia: string, sha256: string, tamano: int}|null
     */
    public static function validar(array $archivo): ?array
    {
        self::$errores = [];

        $codigo = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($codigo !== UPLOAD_ERR_OK) {
            self::$errores[] = match ($codigo) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'El archivo excede el límite del servidor (' . self::legible(self::maxBytes()) . '). Divídelo o comprímelo.',
                UPLOAD_ERR_NO_FILE => 'Ningún archivo seleccionado.',
                UPLOAD_ERR_PARTIAL => 'El archivo se subió incompleto: vuelve a intentarlo.',
                UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en el servidor.',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal del servidor.',
                UPLOAD_ERR_EXTENSION =>
                    'El servidor bloqueó la subida por una extensión o una extensión de PHP.',
                default => 'La subida falló (código ' . $codigo . '). Inténtalo de nuevo.',
            };
            return null;
        }

        $temporal = (string) ($archivo['tmp_name'] ?? '');
        if ($temporal === '' || !is_file($temporal) || !is_uploaded_file($temporal)) {
            self::$errores[] = 'El archivo recibido no es válido.';
            return null;
        }

        $tamano = (int) filesize($temporal);
        if ($tamano <= 0) {
            self::$errores[] = 'El archivo «' . self::nombreOriginal($archivo) . '» está vacío.';
            return null;
        }
        if ($tamano > self::maxBytes()) {
            self::$errores[] = '«' . self::nombreOriginal($archivo) . '» pesa ' . self::legible($tamano)
                . ' y el límite es ' . self::legible(self::maxBytes()) . '.';
            return null;
        }

        // Cualquier extensión es válida. Se normaliza para usarla como sufijo
        // del nombre aleatorio; si no hay extensión se guarda como «.bin».
        $ext = strtolower((string) pathinfo((string) ($archivo['name'] ?? ''), PATHINFO_EXTENSION));
        $ext = substr(preg_replace('/[^a-z0-9]/', '', $ext) ?? '', 0, 16);
        $extGuardado = $ext !== '' ? $ext : 'bin';
        $conocido = $ext !== '' && isset(self::FORMATOS[$ext]);
        $familia = $conocido ? self::FORMATOS[$ext] : 'otro';

        // Firma real del contenido: sólo para los formatos que sabemos validar.
        if ($conocido) {
            $firma = (string) file_get_contents($temporal, false, null, 0, 16);
            if (!self::firmaValida($ext, $firma)) {
                self::$errores[] = '«' . self::nombreOriginal($archivo) . '» no parece un archivo ' . strtoupper($ext)
                    . ' válido (el contenido no coincide con su extensión).';
                return null;
            }
        }

        return [
            'ext'     => $extGuardado,
            'familia' => $familia,
            'sha256'  => hash_file('sha256', $temporal),
            'tamano'  => $tamano,
        ];
    }

    /** Nombre original sanitizado (sólo para mensajes). */
    public static function nombreOriginal(array $archivo): string
    {
        $n = basename((string) ($archivo['name'] ?? ''));
        $n = preg_replace('/[\x00-\x1f\x7f]/u', '', $n) ?? '';
        return mb_substr($n !== '' ? $n : 'archivo', 0, 180);
    }

    /**
     * ¿Los primeros bytes corresponden a la extensión declarada?
     * Se revisa sólo lo que la extensión exige; los formatos sin firma fiable
     * (RVT, 3DM, SKP, IFC…) se aceptan con la comprobación genérica.
     */
    private static function firmaValida(string $ext, string $firma): bool
    {
        if ($firma === '') return false;
        $hex = bin2hex($firma);

        // PDF
        if ($ext === 'pdf') return strncmp($firma, '%PDF-', 5) === 0;

        // ZIP: contenedor de docx/xlsx/pptx, odt/ods/odp, y los propios .zip
        $esZip = strncmp($firma, "PK\x03\x04", 4) === 0 || strncmp($firma, "PK\x05\x06", 4) === 0
            || strncmp($firma, "PK\x07\x08", 4) === 0;

        // OLE2: los formatos binarios antiguos (.doc, .xls, .ppt, .msg)
        $esOle = strncmp($firma, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;

        // Ofimática: contenedor OOXML (zip) u OLE
        if (in_array($ext, ['docx', 'docm', 'dotx', 'xlsx', 'xlsm', 'xlsb', 'pptx', 'pptm', 'odt', 'ods', 'odp', 'odg'], true)) {
            return $esZip || $esOle;
        }
        if (in_array($ext, ['doc', 'xls', 'ppt'], true)) {
            return $esOle || $esZip;   // Office 97-2003 o reconvertido
        }

        // Imágenes: firma binaria real.
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff'], true)) {
            return str_starts_with($hex, 'ffd8ff')        // JPEG
                || str_starts_with($hex, '89504e47')      // PNG
                || str_starts_with($hex, '47494638')      // GIF
                || str_starts_with($hex, '52494646')      // WEBP (RIFF)
                || str_starts_with($hex, '424d')          // BMP
                || str_starts_with($hex, '49492a00')      // TIFF LE
                || str_starts_with($hex, '4d4d002a');     // TIFF BE
        }

        // SVG: texto que empieza por < (admite BOM y espacio).
        if ($ext === 'svg') {
            $inicio = ltrim(str_replace("\xEF\xBB\xBF", '', $firma), " \t\r\n");
            return str_starts_with($inicio, '<');
        }

        // Comprimidos
        if ($ext === 'zip') return $esZip;
        if ($ext === 'rar') return str_starts_with($firma, 'Rar!');
        if ($ext === '7z') return str_starts_with($firma, "\x37\x7A\xBC\xAF\x27\x1C");
        if ($ext === 'gz') return str_starts_with($firma, "\x1F\x8B");
        if ($ext === 'tar') return str_pad(substr($firma, 257, 5), 5, "\0") === 'ustar' || $esZip;

        // AutoCAD: la extensión va con la versión (AC10xx).
        if (in_array($ext, ['dwg', 'dxf'], true)) {
            return preg_match('/^AC10[0-9]{2}/', $firma) === 1
                || str_starts_with(ltrim($firma), '  0')
                || $firma === '';   // dxf en texto se revisa abajo
        }

        // Texto plano: no debe ser binario (salvo que la extensión lo permita).
        if (in_array($ext, ['txt', 'md', 'csv', 'tsv', 'rtf'], true)) {
            if ($ext === 'rtf') return str_starts_with($firma, '{\\rtf');
            return !self::esBinario($firma);
        }

        // Resto (RVT, 3DM, SKP, IFC, STEP, STL, MP3, MP4…): extensión correcta y
        // cabecera razonable — no debe ser un texto HTML de error ni un binario vacío.
        return true;
    }

    /** ¿El fragmento contiene muchos bytes nulos? (señal de binario) */
    private static function esBinario(string $datos): bool
    {
        if ($datos === '') return false;
        $nulos = substr_count($datos, "\0");
        return $nulos > 0 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $datos) === 1;
    }

    /* --------------------------------------------------------------------
     * GUARDADO Y BORRADO
     * ------------------------------------------------------------------ */

    /**
     * Mueve la subida al almacén con nombre aleatorio.
     *
     * @param array{name?: string, tmp_name?: string} $archivo
     * @return array{ruta: string, archivo: string, mime: string}|null errores en errores()
     */
    public static function guardar(array $archivo, string $ext, string $familia, ?int $carpetaId): ?array
    {
        self::$errores = [];

        // Carpeta física por carpeta virtual (NULL = raíz) para no amontonar todo junto.
        $sub = $carpetaId ? 'c' . (int) $carpetaId : 'raiz';
        $destino = self::base() . '/' . $sub;
        if (!is_dir($destino) && !@mkdir($destino, 0750, true)) {
            self::$errores[] = 'No fue posible preparar el almacén de archivos.';
            return null;
        }

        $ext = substr(preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?? '', 0, 16);
        // Los tipos desconocidos se guardan como binario, sin conservar una
        // extensión que pudiera interpretarse como ejecutable en el servidor.
        if ($familia === 'otro') $ext = 'bin';
        $nombre = bin2hex(random_bytes(16)) . '.' . ($ext !== '' ? $ext : 'bin');
        $ruta = $destino . '/' . $nombre;

        $movido = is_uploaded_file($archivo['tmp_name'])
            ? @move_uploaded_file($archivo['tmp_name'], $ruta)
            : @rename($archivo['tmp_name'], $ruta);

        if (!$movido) {
            self::$errores[] = 'No fue posible guardar «' . self::nombreOriginal($archivo) . '» en el servidor.';
            return null;
        }
        @chmod($ruta, 0640);

        return [
            'ruta'    => $ruta,
            'archivo' => $sub . '/' . $nombre,
            'mime'    => self::mime($familia),
        ];
    }

    /**
     * Ruta absoluta de un archivo registrado, sólo si cae dentro del almacén
     * (realpath + prefijo: sin recorridos de directorio).
     */
    public static function rutaAbsoluta(string $relativa): ?string
    {
        $base = realpath(self::base());
        $ruta = realpath(self::base() . '/' . ltrim($relativa, '/'));

        if ($base === false || $ruta === false) return null;
        if (!is_file($ruta)) return null;
        if (strncmp($ruta, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) return null;
        return $ruta;
    }

    /** Borra los bytes de un archivo ya registrado. Devuelve true si ya no está. */
    public static function borrar(string $relativa): bool
    {
        self::borrarMiniatura($relativa);
        $ruta = self::rutaAbsoluta($relativa);
        if ($ruta === null) return true;      // ya no existe: nada que borrar
        return @unlink($ruta);
    }

    /* --------------------------------------------------------------------
     * MINIATURAS Y VISTA PREVIA
     * ------------------------------------------------------------------ */

    /** Carpeta de caché de miniaturas (fuera de public/, junto al almacén). */
    private static function carpetaMiniaturas(): string
    {
        $ruta = self::base() . '/miniaturas';
        if (!is_dir($ruta)) {
            @mkdir($ruta, 0750, true);
        }
        return $ruta;
    }

    /** Ruta de la miniatura en caché para un archivo del almacén. */
    private static function rutaMiniatura(string $relativa): string
    {
        return self::carpetaMiniaturas() . '/' . sha1($relativa) . '.jpg';
    }

    /** Borra la miniatura en caché de un archivo (al moverlo o eliminarlo). */
    public static function borrarMiniatura(string $relativa): void
    {
        $mini = self::rutaMiniatura($relativa);
        if (is_file($mini)) {
            @unlink($mini);
        }
    }

    /**
     * Devuelve la ruta absoluta de la miniatura de un archivo, generándola y
     * guardándola en caché la primera vez. Null si no se puede generar
     * (formato no soportado, PDF corrupto, GD sin soporte…).
     */
    public static function miniatura(string $relativa, string $ext): ?string
    {
        $ruta = self::rutaAbsoluta($relativa);
        if ($ruta === null) return null;

        $ext = strtolower($ext);
        if (!self::admiteMiniatura($ext) || $ext === 'svg') return null;   // SVG se sirve tal cual

        $destino = self::rutaMiniatura($relativa);
        if (is_file($destino) && filemtime($destino) >= filemtime($ruta)) {
            return $destino;
        }

        $ok = $ext === 'pdf'
            ? self::miniaturaPdf($ruta, $destino)
            : self::miniaturaImagen($ruta, $destino);

        return $ok && is_file($destino) ? $destino : null;
    }

    /** Miniatura de la primera página de un PDF con pdftoppm (poppler-utils). */
    private static function miniaturaPdf(string $ruta, string $destino): bool
    {
        $binario = self::rutaBinario('pdftoppm');
        if ($binario === null || !function_exists('exec')) return false;

        $base = substr($destino, 0, -4) . '.' . bin2hex(random_bytes(4));
        $comando = escapeshellarg($binario)
            . ' -jpeg -f 1 -l 1 -scale-to 400 -singlefile '
            . escapeshellarg($ruta) . ' ' . escapeshellarg($base) . ' 2>&1';

        $salida = [];
        $codigo = 1;
        @exec($comando, $salida, $codigo);

        $generada = $base . '.jpg';
        if ($codigo !== 0 || !is_file($generada)) {
            @unlink($generada);
            return false;
        }
        if (!@rename($generada, $destino)) {
            @unlink($generada);
            return false;
        }
        return true;
    }

    /** Miniatura de una imagen raster con GD (proporcional, máx. 400 px). */
    private static function miniaturaImagen(string $ruta, string $destino): bool
    {
        if (!function_exists('imagecreatefromstring')) return false;

        // Evita decodificar imágenes enormes que agotarían la memoria.
        $info = @getimagesize($ruta);
        if ($info && ((int) $info[0] * (int) $info[1]) > 40000000) return false;

        $datos = @file_get_contents($ruta);
        if ($datos === false) return false;
        $origen = @imagecreatefromstring($datos);
        if (!$origen) return false;

        $ancho = imagesx($origen);
        $alto  = imagesy($origen);
        if ($ancho < 1 || $alto < 1) {
            imagedestroy($origen);
            return false;
        }

        $escala     = min(400 / $ancho, 400 / $alto, 1.0);
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto  = max(1, (int) round($alto * $escala));

        $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        $blanco = imagecolorallocate($lienzo, 255, 255, 255);
        imagefill($lienzo, 0, 0, $blanco);
        imagecopyresampled($lienzo, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        $temporal = $destino . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = @imagejpeg($lienzo, $temporal, 82);

        imagedestroy($lienzo);
        imagedestroy($origen);

        if (!$ok) {
            @unlink($temporal);
            return false;
        }
        if (!@rename($temporal, $destino)) {
            @unlink($temporal);
            return false;
        }
        return true;
    }

    /** Localiza un binario del sistema recorriendo el PATH. */
    private static function rutaBinario(string $binario): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directorio) {
            $ruta = rtrim($directorio, '/') . '/' . $binario;
            if (is_file($ruta) && is_executable($ruta)) {
                return $ruta;
            }
        }
        return null;
    }

    /** Envía una miniatura ya generada (JPEG en caché). Termina la petición. */
    public static function enviarMiniatura(string $ruta): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=86400');
        readfile($ruta);
        exit;
    }

    /** Envía un SVG como miniatura (se dibuja sin ejecutar scripts). */
    public static function enviarMiniaturaSvg(string $ruta): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: image/svg+xml');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, max-age=86400');
        readfile($ruta);
        exit;
    }

    /**
     * Envía el archivo para verlo dentro de la aplicación (inline): PDF e
     * imágenes, nunca como descarga. Termina la petición.
     */
    public static function ver(string $ruta, string $mime, string $nombreOriginal, string $vista): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $limpio = preg_replace('/[^A-Za-z0-9._ -]/u', '', (string) $nombreOriginal) ?: 'archivo';
        $tipo   = $vista === 'pdf' ? 'application/pdf' : ($mime !== '' ? $mime : 'application/octet-stream');

        header('Content-Type: ' . $tipo);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $limpio)
            . '"; filename*=UTF-8\'\'' . rawurlencode((string) $nombreOriginal));
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        // Solo la propia aplicación puede incrustarlo; el visor PDF necesita
        // frame-ancestors y no admite «sandbox».
        header('X-Frame-Options: SAMEORIGIN');
        header($vista === 'pdf'
            ? "Content-Security-Policy: frame-ancestors 'self'"
            : "Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'self'");
        header('Cache-Control: private, no-store');
        readfile($ruta);
        exit;
    }

    /** Envía el archivo como descarga con su nombre original. Termina la petición. */
    public static function descargar(string $ruta, string $nombreOriginal, string $mime): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $limpio = preg_replace('/[^A-Za-z0-9._ -]/u', '', (string) $nombreOriginal) ?: 'archivo';
        $mime = $mime !== '' ? $mime : 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $limpio)
            . '"; filename*=UTF-8\'\'' . rawurlencode((string) $nombreOriginal));
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Cache-Control: private, no-store');
        header('Accept-Ranges: none');
        readfile($ruta);
        exit;
    }
}
