<?php

namespace App;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Imagenes — subida y borrado de archivos con Intervention Image.
 *
 * Centraliza la política de imágenes del proyecto:
 *
 *   'cuadrada' → recorte cuadrado 1000×1000 (formato de productos, según
 *                OPENCODE.md) y miniatura 300×300, ambas en JPG.
 *   'logo'     → lienzo cuadrado de 300×300 (formato de empresas, según
 *                OPENCODE.md): la marca se escala para caber dentro sin
 *                recortarse ni deformarse y se centra sobre fondo
 *                transparente (o blanco en JPG, que no admite transparencia).
 *   'retrato'  → recorte cuadrado 500×500 de una foto de perfil (usuarios y
 *                clientes); se muestra redonda desde CSS. Si no hay foto se
 *                usa RETRATO_POR_DEFECTO.
 *   'informe'  → foto de entrega (acta de venta o de proveedor): se reduce
 *                proporcionalmente sin recortar y sin agrandar, con un lado
 *                máximo de INFORME_LADO px, para que el documento conserve
 *                la proporción real de la fotografía.
 *
 * Todo se guarda en public/image con un nombre único generado por el
 * servidor: nunca se conserva el nombre que trae el navegador.
 */
class Imagenes
{
    /** Carpeta física de las subidas (relativa a esta clase). */
    public const CARPETA = __DIR__ . '/../public/image';

    /** Carpeta pública (URL) de las subidas. */
    public const URL = '/public/image';

    /** Imagen genérica que se muestra cuando no hay foto de perfil. */
    public const RETRATO_POR_DEFECTO = 'perfilSinfoto.jpg';

    /** Lado del cuadro que genera el modo 'retrato' (fotos de perfil). */
    private const RETRATO_LADO = 500;

    /** Lado máximo que genera el modo 'informe' (fotos de entrega). */
    private const INFORME_LADO = 1600;

    /**
     * Tamaño máximo admitido por archivo: 6 MB.
     *
     * Éste es el tope que manda en la aplicación: si hay que subirlo, basta
     * con cambiar este número (PHP admite 20M y el ingress 20m, así que hay
     * holgura). Los mensajes de ayuda y la validación del navegador salen de
     * aquí y de TAMANO_MAXIMO_IMAGEN en src/js/modulos/validacion.js, que
     * debe mantenerse igual para que ambas capas digan lo mismo.
     */
    public const TAMANO_MAXIMO = 6291456;

    /** Tipos MIME admitidos. */
    public const TIPOS = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var string Motivo del último error de subida. */
    private static $ultimoError = '';

    /** Extensiones admitidas por tipo MIME. */
    private const EXTENSIONES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /* --------------------------------------------------------------------
     * CONSULTAS
     * ------------------------------------------------------------------ */

    /** Motivo por el que no se pudo subir la última imagen (vacío = todo bien). */
    public static function ultimoError(): string
    {
        return self::$ultimoError;
    }

    /** URL pública de una imagen guardada. */
    public static function url(?string $archivo): string
    {
        return $archivo ? self::URL . '/' . $archivo : '';
    }

    /**
     * Procesa la foto de perfil adjunta a un formulario.
     *
     * Tres situaciones posibles:
     *   - se adjuntó un archivo → se procesa y se devuelve el nombre nuevo;
     *   - se marcó "quitar foto" → se devuelve cadena vacía;
     *   - no hubo cambios       → se devuelve null (se conserva el actual).
     *
     * El archivo anterior NO se borra aquí: sólo la vista lo hace cuando la
     * escritura en base de datos ya fue exitosa (mismo criterio que el logo).
     *
     * @param array|null $archivo Elemento de $_FILES.
     * @param bool       $quitar  true si se marcó la casilla de quitar foto.
     * @param array      $errores Acumulador de errores de la vista (por referencia).
     * @param string     $prefijo Nombre inicial del archivo generado.
     *
     * @return string|null null si no hay cambios; '' si se pidió quitarla;
     *                      el nombre del archivo si se subió uno nuevo.
     */
    public static function subirRetrato(
        ?array $archivo,
        bool $quitar,
        array &$errores,
        string $prefijo = 'perfil'
    ): ?string {
        if ($quitar) {
            return '';
        }

        if (!is_array($archivo) || empty($archivo['tmp_name'])) {
            return null;
        }

        $nuevo = self::subir($archivo, $prefijo, 'retrato');

        if ($nuevo === null) {
            $motivo = self::ultimoError();
            if ($motivo !== '') {
                $errores[] = $motivo;
            }
            return null;
        }

        return $nuevo;
    }

    /**
     * Procesa la fotografía adjunta a un acta de entrega (venta o proveedor).
     *
     * Mismas tres situaciones que subirRetrato(): archivo nuevo, casilla de
     * quitar, o nada que tocar.
     *
     * @param array|null $archivo Elemento de $_FILES.
     * @param bool       $quitar  true si se marcó la casilla de quitar foto.
     * @param array      $errores Acumulador de errores de la vista (por referencia).
     * @param string     $prefijo Nombre inicial del archivo generado.
     *
     * @return string|null null si no hay cambios; '' si se pidió quitarla;
     *                      el nombre del archivo si se subió uno nuevo.
     */
    public static function subirInforme(
        ?array $archivo,
        bool $quitar,
        array &$errores,
        string $prefijo = 'entrega'
    ): ?string {
        if ($quitar) {
            return '';
        }

        if (!is_array($archivo) || empty($archivo['tmp_name'])) {
            return null;
        }

        $nuevo = self::subir($archivo, $prefijo, 'informe');

        if ($nuevo === null) {
            $motivo = self::ultimoError();
            if ($motivo !== '') {
                $errores[] = $motivo;
            }
            return null;
        }

        return $nuevo;
    }

    /**
     * URL pública de la foto de perfil de un usuario o de un cliente.
     *
     * Si no hay foto cargada devuelve la imagen genérica, de modo que la
     * interfaz no tenga que comprobar el campo en cada punto de la vista.
     */
    public static function retrato(?string $archivo): string
    {
        return self::url($archivo ?: self::RETRATO_POR_DEFECTO);
    }

    /**
     * Logo sin márgenes vacíos: muchos logos vienen en un lienzo cuadrado con la marca pequeña al centro
     * y se ven diminutos junto a otros. Devuelve el nombre de un archivo recortado a su contenido
     * (se guarda una vez en public/image/recortes/) o el original si no hay nada que recortar.
     */
    public static function logoRecortado(?string $archivo): string
    {
        $archivo = trim((string) $archivo);
        $origen  = dirname(__DIR__) . '/public/image/' . $archivo;
        if ($archivo === '' || strpos($archivo, '..') !== false || !is_file($origen) || !function_exists('imagecreatefromstring')) {
            return $archivo;
        }

        $nombre  = 'recortes/' . pathinfo($archivo, PATHINFO_FILENAME) . '-' . filemtime($origen) . '.png';
        $destino = dirname(__DIR__) . '/public/image/' . $nombre;
        if (is_file($destino)) {
            return $nombre;
        }

        $imagen = @imagecreatefromstring((string) file_get_contents($origen));
        if (!$imagen) {
            return $archivo;
        }
        $ancho = imagesx($imagen);
        $alto  = imagesy($imagen);
        $minX = $ancho; $minY = $alto; $maxX = -1; $maxY = -1;
        for ($y = 0; $y < $alto; $y++) {
            for ($x = 0; $x < $ancho; $x++) {
                $c = imagecolorat($imagen, $x, $y);
                $transparente = (($c >> 24) & 0x7F) > 100;
                $blanco = (($c >> 16) & 0xFF) > 244 && (($c >> 8) & 0xFF) > 244 && ($c & 0xFF) > 244;
                if (!$transparente && !$blanco) {
                    $minX = min($minX, $x); $maxX = max($maxX, $x);
                    $minY = min($minY, $y); $maxY = max($maxY, $y);
                }
            }
        }
        if ($maxX < 0) {
            imagedestroy($imagen);
            return $archivo;
        }

        $relleno = (int) round(max($maxX - $minX, $maxY - $minY) * 0.03);
        $minX = max(0, $minX - $relleno); $minY = max(0, $minY - $relleno);
        $maxX = min($ancho - 1, $maxX + $relleno); $maxY = min($alto - 1, $maxY + $relleno);
        $anchoNuevo = $maxX - $minX + 1;
        $altoNuevo  = $maxY - $minY + 1;

        // Si apenas hay margen, no vale la pena duplicar el archivo.
        if ($anchoNuevo * $altoNuevo >= 0.92 * $ancho * $alto) {
            imagedestroy($imagen);
            return $archivo;
        }

        $recorte = imagecreatetruecolor($anchoNuevo, $altoNuevo);
        imagealphablending($recorte, false);
        imagesavealpha($recorte, true);
        imagefill($recorte, 0, 0, imagecolorallocatealpha($recorte, 255, 255, 255, 127));
        imagecopy($recorte, $imagen, 0, 0, $minX, $minY, $anchoNuevo, $altoNuevo);

        if (!is_dir(dirname($destino))) {
            @mkdir(dirname($destino), 0755, true);
        }
        $guardado = @imagepng($recorte, $destino);
        imagedestroy($imagen);
        imagedestroy($recorte);

        return $guardado ? $nombre : $archivo;
    }

    /** Límites del alto del logo en PDF (px) y proporción máxima ancho/alto de su caja. */
    public const LOGO_PDF_ALTO_MIN = 24;
    public const LOGO_PDF_ALTO_MAX = 120;
    public const LOGO_PDF_ALTO     = 64;
    private const LOGO_PDF_RAZON   = 3.5;

    /**
     * Logo listo para un PDF: ajustado DENTRO de una caja (equivalente a «contain»), sin deformar.
     * El alto máximo sale de empresas.logo_alto_pdf (buscada por el nombre del archivo) × $escala; el ancho
     * máximo es 3.5 veces ese alto, así que un logo apaisado y uno cuadrado quedan equilibrados.
     * dompdf recibe ancho y alto explícitos (no max-*) para que el resultado no dependa del motor.
     *
     * @return array{src: string, ancho: int, alto: int}|null  null si no hay archivo legible
     */
    public static function logoPdf(?string $archivo, float $escala = 1.0): ?array
    {
        $original = trim((string) $archivo);
        $recortado = self::logoRecortado($original);
        $ruta = dirname(__DIR__) . '/public/image/' . $recortado;
        if ($recortado === '' || strpos($recortado, '..') !== false || !is_file($ruta)) {
            return null;
        }
        $medidas = @getimagesize($ruta);
        $datos   = @file_get_contents($ruta);
        if (!$medidas || $datos === false || $medidas[0] < 1 || $medidas[1] < 1) {
            return null;
        }

        $altoMax = self::altoLogoPdf($original) * $escala;
        $anchoMax = $altoMax * self::LOGO_PDF_RAZON;
        $factor = min($anchoMax / $medidas[0], $altoMax / $medidas[1]);

        $tipo = (string) ($medidas['mime'] ?? 'image/png');
        return [
            'src'   => 'data:' . $tipo . ';base64,' . base64_encode($datos),
            'ancho' => max(1, (int) round($medidas[0] * $factor)),
            'alto'  => max(1, (int) round($medidas[1] * $factor)),
        ];
    }

    /** Etiqueta <img> del logo para PDF, o '' si no hay logo. */
    public static function logoPdfHtml(?string $archivo, string $alt, float $escala = 1.0): string
    {
        $logo = self::logoPdf($archivo, $escala);
        if ($logo === null) {
            return '';
        }
        return '<img class="logo" src="' . $logo['src'] . '" width="' . $logo['ancho'] . '" height="' . $logo['alto']
            . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Alto máximo configurado para el logo de la empresa que usa ese archivo (por defecto 64 px). */
    private static function altoLogoPdf(string $archivo): int
    {
        static $cache = [];
        if (!isset($cache[$archivo])) {
            $alto = self::LOGO_PDF_ALTO;
            $db = $GLOBALS['db'] ?? null;
            if ($db instanceof \mysqli && $archivo !== '') {
                try {
                    $consulta = $db->prepare('SELECT logo_alto_pdf FROM empresas WHERE logo = ? LIMIT 1');
                    $consulta->bind_param('s', $archivo);
                    $consulta->execute();
                    $fila = $consulta->get_result()->fetch_row();
                    $consulta->close();
                    if ($fila) {
                        $alto = (int) $fila[0];
                    }
                } catch (\Throwable $e) {
                    // Sin la columna (migración pendiente) se usa el valor por defecto.
                }
            }
            $cache[$archivo] = max(self::LOGO_PDF_ALTO_MIN, min(self::LOGO_PDF_ALTO_MAX, $alto));
        }
        return $cache[$archivo];
    }

    /** Ruta pública de la miniatura asociada a una imagen de producto. */
    public static function urlMiniatura(?string $archivo): string
    {
        if (!$archivo) {
            return '';
        }
        $miniatura = preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', $archivo);
        return self::URL . '/' . $miniatura;
    }

    /**
     * Comprueba que el archivo subido sea admisible.
     *
     * @return string '' si no hay nada que validar o si todo está bien;
     *                 en otro caso, el motivo del rechazo.
     */
    public static function validarArchivo(array $archivo): string
    {
        self::$ultimoError = '';

        // Sin archivo adjunto: no es un error, la imagen es opcional.
        if (!isset($archivo['error']) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            return 'No fue posible subir el archivo. Intenta de nuevo.';
        }
        if ((int) ($archivo['size'] ?? 0) > self::TAMANO_MAXIMO) {
            // El número sale de la constante: así el mensaje no se queda
            // viejo cuando cambia el tope.
            $megas = (int) ceil(self::TAMANO_MAXIMO / 1048576);
            return "La imagen no puede pesar más de {$megas} MB.";
        }
        if (!in_array($archivo['type'] ?? '', self::TIPOS, true)) {
            return 'Sólo se admiten imágenes JPG, PNG o WebP.';
        }

        return '';
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

    /**
     * Procesa y guarda una imagen.
     *
     * @param array  $archivo Elemento de $_FILES.
     * @param string $prefijo Nombre inicial del archivo (ej. 'producto').
     * @param string $modo    'cuadrada', 'logo', 'retrato' o 'informe'.
     *
     * @return string|null Nombre del archivo generado, o null si no hay
     *                      imagen nueva (o si hubo un error: ver ultimoError()).
     */
    public static function subir(array $archivo, string $prefijo, string $modo = 'cuadrada'): ?string
    {
        $motivo = self::validarArchivo($archivo);

        if ($motivo !== '') {
            self::$ultimoError = $motivo;
            return null;
        }

        // No se adjuntó nada: la vista decidirá si conserva la imagen anterior.
        if (empty($archivo['tmp_name'])) {
            self::$ultimoError = '';
            return null;
        }

        if (!is_dir(self::CARPETA) && !mkdir(self::CARPETA, 0775, true) && !is_dir(self::CARPETA)) {
            self::$ultimoError = 'No se pudo crear la carpeta de imágenes.';
            return null;
        }

        $mime = $archivo['type'] ?? 'image/jpeg';
        $ext  = self::EXTENSIONES[$mime] ?? 'jpg';

        try {
            $gerenciador = new ImageManager(new Driver());

            if ($modo === 'cuadrada') {
                $principal = self::nombreUnico($prefijo, 'jpg');
                $gerenciador->read($archivo['tmp_name'])
                    ->cover(1000, 1000)
                    ->encodeByMediaType('image/jpeg', 85)
                    ->save(self::CARPETA . '/' . $principal);

                // Miniatura a partir del archivo original (la anterior ya se modificó).
                $miniatura = preg_replace('/\.jpg$/', '_mini.jpg', $principal);
                $gerenciador->read($archivo['tmp_name'])
                    ->cover(300, 300)
                    ->encodeByMediaType('image/jpeg', 85)
                    ->save(self::CARPETA . '/' . $miniatura);

                return $principal;
            }

            if ($modo === 'retrato') {
                // Foto de perfil: cuadrado 500×500 recortado al centro. Se
                // muestra redonda desde CSS y se conserva el formato original
                // para no perder la transparencia del PNG.
                $retrato = self::nombreUnico($prefijo, $ext);
                $gerenciador->read($archivo['tmp_name'])
                    ->cover(self::RETRATO_LADO, self::RETRATO_LADO)
                    ->encodeByMediaType($mime)
                    ->save(self::CARPETA . '/' . $retrato);

                return $retrato;
            }

            if ($modo === 'informe') {
                // Acta de entrega (venta o proveedor): se reduce para que
                // quepa en pantalla y en el documento, conservando la
                // proporción original y sin ampliar una foto pequeña.
                $informe = self::nombreUnico($prefijo, $ext);
                $gerenciador->read($archivo['tmp_name'])
                    ->scaleDown(self::INFORME_LADO, self::INFORME_LADO)
                    ->encodeByMediaType($mime, 88)
                    ->save(self::CARPETA . '/' . $informe);

                return $informe;
            }

            // Modo 'logo': cuadrado 300×300 sin recortar ni deformar la marca.
            // El fondo es transparente en PNG/WebP y blanco en JPG (sin alfa).
            $logo = self::nombreUnico($prefijo, $ext);
            $fondo = $ext === 'jpg' ? 'ffffff' : 'transparent';

            $gerenciador->read($archivo['tmp_name'])
                ->contain(300, 300, $fondo)
                ->encodeByMediaType($mime)
                ->save(self::CARPETA . '/' . $logo);

            return $logo;
        } catch (\Throwable $excepcion) {
            error_log('[Imagenes::subir] ' . $excepcion->getMessage());
            self::$ultimoError = 'No fue posible procesar la imagen.';
            return null;
        }
    }

    /** Borra una imagen y su miniatura si existen. */
    public static function eliminar(?string $archivo): void
    {
        if (!$archivo) {
            return;
        }

        // Sólo se borran nombres simples: nunca se aceptan rutas con "../".
        $nombre = basename($archivo);
        $ruta   = self::CARPETA . '/' . $nombre;

        if (is_file($ruta)) {
            @unlink($ruta);
        }

        $miniatura = preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', $nombre);
        $rutaMini  = self::CARPETA . '/' . $miniatura;

        if ($miniatura !== $nombre && is_file($rutaMini)) {
            @unlink($rutaMini);
        }
    }

    /* --------------------------------------------------------------------
     * AUXILIARES
     * ------------------------------------------------------------------ */

    /** Genera un nombre de archivo único que no depende del navegador. */
    private static function nombreUnico(string $prefijo, string $extension): string
    {
        $prefijo = preg_replace('/[^a-z0-9_\-]/i', '', $prefijo) ?: 'imagen';
        return $prefijo . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
    }
}
