<?php

namespace App;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * FotosEntrega — metadatos y marca de agua de las fotos del acta de entrega.
 *
 * La foto guardada NUNCA se modifica: la marca de agua se dibuja al vuelo
 * cuando el seguimiento abre la imagen ampliada. Así las fotos anteriores
 * también se ven marcadas y el archivo original queda intacto.
 *
 * La marca lleva, arriba a la izquierda, el logo del negocio que emitió la
 * venta y, abajo a la izquierda, la fecha/hora y la ubicación de la toma:
 *
 *   · fecha y hora → EXIF de la foto; si no existe, la fecha/hora de registro
 *                    de la entrega;
 *   · ubicación    → GPS del EXIF (grados decimales) y la dirección registrada
 *                    del cliente; si no hay GPS, sólo la dirección.
 *
 * Intervention re-codifica al subir y borra el EXIF, por eso `metadatos()` se
 * llama ANTES de `Imagenes::subir()` y lo leído se guarda en `entrega_fotos`.
 */
class FotosEntrega
{
    /** Ancho máximo con el que se sirve la foto marcada. */
    public const ANCHO_MAX = 1600;

    /** Proporción del logo respecto al ancho de la foto. */
    private const LOGO_ANCHO = 0.20;

    /** Proporción máxima de alto del logo (para fotos muy apaisadas). */
    private const LOGO_ALTO = 0.18;

    /** Margen proporcional de la marca (arriba y abajo a la izquierda). */
    private const MARGEN = 0.035;

    /** Tamaño tipográfico del texto respecto al ancho de la foto. */
    private const TEXTO = 0.028;

    /** Fuente de los textos (dompdf ya es dependencia obligatoria del proyecto). */
    private const FUENTE_BOLD = __DIR__ . '/../vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
    private const FUENTE      = __DIR__ . '/../vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';

    /** URL del endpoint que sirve la foto ampliada con la marca de agua. */
    public static function url(string $archivo): string
    {
        return '/admin/entregas/foto?f=' . rawurlencode($archivo);
    }

    /**
     * Enlaces a Google Maps y Apple Maps con la ubicación de una foto.
     *
     * Prioridad:
     *   1. GPS de la foto (metadatos EXIF).
     *   2. Ubicación manual de la entrega: enlace de Google Maps, coordenadas
     *      o dirección.
     *   3. Dirección registrada del cliente.
     *
     * Devuelve [] cuando no hay ningún dato con el que ubicarla. Si la
     * ubicación manual es un enlace corto de Google Maps no se puede construir
     * el enlace de Apple Maps: en ese caso sólo se devuelve «google».
     *
     * @param array  $foto      Fila de entrega_fotos (latitud, longitud).
     * @param string $direccion Dirección registrada del cliente.
     * @param string $manual    Ubicación manual de la entrega.
     * @return array{google?: string, apple?: string}
     */
    public static function urlsMapa(array $foto, string $direccion = '', string $manual = ''): array
    {
        $lat = $foto['latitud']  ?? null;
        $lon = $foto['longitud'] ?? null;

        if ($lat !== null && $lon !== null) {
            return self::enlacesDeConsulta(
                number_format((float) $lat, 7, '.', '')
                . ',' . number_format((float) $lon, 7, '.', '')
            );
        }

        $manual = trim($manual);
        if ($manual !== '') {
            $coordenadas = self::coordenadasDeEnlace($manual);
            if ($coordenadas !== null) {
                return self::enlacesDeConsulta($coordenadas);
            }
            if (self::esEnlace($manual)) {
                // Enlace corto de Google Maps: se abre tal cual y no se inventa
                // un enlace de Apple Maps que llevaría a otro sitio.
                return ['google' => $manual];
            }

            return self::enlacesDeConsulta($manual);
        }

        $consulta = trim((string) preg_replace('/\s+/', ' ', $direccion));
        return $consulta === '' ? [] : self::enlacesDeConsulta($consulta);
    }

    /** @return array{google: string, apple: string} */
    private static function enlacesDeConsulta(string $consulta): array
    {
        $codificada = rawurlencode($consulta);

        return [
            'google' => 'https://www.google.com/maps/search/?api=1&query=' . $codificada,
            'apple'  => 'https://maps.apple.com/?q=' . $codificada,
        ];
    }

    /**
     * Valida y normaliza la ubicación manual de una entrega.
     *
     * Acepta un enlace de Google Maps, coordenadas «latitud, longitud» o una
     * dirección de texto. Devuelve el valor listo para guardar y el error
     * (vacío si todo está bien).
     *
     * @return array{valor: string, error: string}
     */
    public static function normalizarUbicacion(string $valor): array
    {
        $valor = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valor));
        $valor = trim((string) preg_replace('/\s+/u', ' ', $valor));

        if ($valor === '') {
            return ['valor' => '', 'error' => ''];
        }
        if (mb_strlen($valor) > 500) {
            return ['valor' => '', 'error' => 'La ubicación no puede tener más de 500 caracteres.'];
        }

        // Un enlace sin esquema (maps.app.goo.gl/…) se completa con https.
        if (preg_match('/^(?:maps\.|google\.|goo\.gl)\S+$/i', $valor) === 1) {
            $valor = 'https://' . $valor;
        }

        if (self::esEnlace($valor)) {
            $partes = parse_url($valor);
            $host   = strtolower((string) ($partes['host'] ?? ''));

            $esGoogle = $host === 'goo.gl'
                || $host === 'maps.app.goo.gl'
                || $host === 'maps.google.com'
                || preg_match('/^(?:(?:www|maps)\.)?google\.(?:com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$/', $host) === 1;

            if (!$esGoogle) {
                return ['valor' => '', 'error' => 'Pega un enlace de Google Maps válido o escribe coordenadas o una dirección.'];
            }

            return ['valor' => $valor, 'error' => ''];
        }

        // Coordenadas escritas a mano.
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $valor, $m) === 1) {
            $lat = (float) $m[1];
            $lon = (float) $m[2];
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return ['valor' => '', 'error' => 'Las coordenadas no son válidas: la latitud va de -90 a 90 y la longitud de -180 a 180.'];
            }
            return ['valor' => number_format($lat, 7, '.', '') . ', ' . number_format($lon, 7, '.', ''), 'error' => ''];
        }

        return ['valor' => $valor, 'error' => ''];
    }

    /** ¿Es un enlace http(s)? */
    private static function esEnlace(string $valor): bool
    {
        return (bool) preg_match('#^https?://#i', $valor);
    }

    /**
     * Coordenadas «lat,lon» que se puedan leer de un enlace de Google Maps.
     *
     * Reconoce los formatos habituales (@lat,lon, ?q=lat,lon, ?ll=lat,lon y
     * !3dlat!4dlon). Devuelve null si el enlace no las trae (p. ej. un enlace
     * corto maps.app.goo.gl).
     */
    public static function coordenadasDeEnlace(string $valor): ?string
    {
        $texto = rawurldecode(trim($valor));

        // Coordenadas escritas directamente («24.1426667, -110.3127500»).
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $texto, $m) === 1) {
            $lat = (float) $m[1];
            $lon = (float) $m[2];
            if (abs($lat) <= 90 && abs($lon) <= 180) {
                return number_format($lat, 7, '.', '') . ',' . number_format($lon, 7, '.', '');
            }
        }

        $patrones = [
            '/@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/',
            '/[?&](?:q|ll|destination)=(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/i',
            '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',
        ];

        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $m) === 1) {
                $lat = (float) $m[1];
                $lon = (float) $m[2];
                if (abs($lat) <= 90 && abs($lon) <= 180) {
                    return number_format($lat, 7, '.', '') . ',' . number_format($lon, 7, '.', '');
                }
            }
        }

        return null;
    }

    /* --------------------------------------------------------------------
     * METADATOS EXIF
     * ------------------------------------------------------------------ */

    /**
     * Fecha de captura y GPS de un archivo recién subido.
     *
     * Se llama con el archivo temporal de $_FILES, antes de que Intervention
     * lo re-codifique. Devuelve nulls cuando no hay EXIF o no se puede leer.
     *
     * @param array $archivo Entrada de $_FILES ya separada por archivo.
     * @return array{tomada_en: ?string, latitud: ?float, longitud: ?float}
     */
    public static function metadatos(array $archivo): array
    {
        $vacio = ['tomada_en' => null, 'latitud' => null, 'longitud' => null];
        $ruta  = (string) ($archivo['tmp_name'] ?? '');

        if ($ruta === '' || !is_file($ruta) || !function_exists('exif_read_data')) {
            return $vacio;
        }

        $datos = @exif_read_data($ruta, null, true);
        if (!is_array($datos)) {
            return $vacio;
        }

        $fecha = $datos['EXIF']['DateTimeOriginal']
            ?? $datos['EXIF']['DateTime']
            ?? $datos['IFD0']['DateTimeOriginal']
            ?? $datos['IFD0']['DateTime']
            ?? null;

        if (is_string($fecha) && trim($fecha) !== '') {
            $tomada = \DateTime::createFromFormat('Y:m:d H:i:s', trim($fecha));
            if ($tomada instanceof \DateTime) {
                $vacio['tomada_en'] = $tomada->format('Y-m-d H:i:s');
            }
        }

        $gps = is_array($datos['GPS'] ?? null) ? $datos['GPS'] : [];
        $vacio['latitud']  = self::grados($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null);
        $vacio['longitud'] = self::grados($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null);

        return $vacio;
    }

    /**
     * Normaliza los metadatos que envía el navegador tras comprimir la foto.
     *
     * El navegador lee el EXIF del archivo original y lo manda en un campo
     * oculto; después la imagen ya no lo trae. Los valores son sólo para
     * mostrar la fecha y la ubicación, así que se validan antes de guardarlos.
     *
     * @return array{tomada_en: ?string, latitud: ?float, longitud: ?float}
     */
    public static function metadatosDeFormulario(mixed $meta): array
    {
        $vacio = ['tomada_en' => null, 'latitud' => null, 'longitud' => null];
        if (!is_array($meta)) {
            return $vacio;
        }

        $fecha = trim((string) ($meta['tomada_en'] ?? ''));
        if ($fecha !== '' && strtotime($fecha)) {
            $vacio['tomada_en'] = date('Y-m-d H:i:s', strtotime($fecha));
        }

        $lat = $meta['latitud'] ?? null;
        $lon = $meta['longitud'] ?? null;
        if (is_numeric($lat) && is_numeric($lon)) {
            $lat = (float) $lat;
            $lon = (float) $lon;
            if (abs($lat) <= 90 && abs($lon) <= 180) {
                $vacio['latitud']  = round($lat, 7);
                $vacio['longitud'] = round($lon, 7);
            }
        }

        return $vacio;
    }

    /**
     * Combina los metadatos del servidor (archivo original) con los del
     * navegador. Manda el EXIF auténtico del temporal y, cuando el navegador
     * ya comprimió y lo borró, se completan los huecos con los del cliente.
     *
     * @param array{tomada_en: ?string, latitud: ?float, longitud: ?float} $servidor
     * @param array{tomada_en: ?string, latitud: ?float, longitud: ?float} $cliente
     * @return array{tomada_en: ?string, latitud: ?float, longitud: ?float}
     */
    public static function combinarMetadatos(array $servidor, array $cliente): array
    {
        foreach (['tomada_en', 'latitud', 'longitud'] as $campo) {
            if (($servidor[$campo] ?? null) === null && ($cliente[$campo] ?? null) !== null) {
                $servidor[$campo] = $cliente[$campo];
            }
        }

        return $servidor;
    }

    /** Convierte grados/minutos/segundos EXIF a grados decimales. */
    private static function grados(mixed $coordenada, mixed $referencia): ?float
    {
        if (!is_array($coordenada) || count($coordenada) < 3) {
            return null;
        }

        $grados   = self::racional($coordenada[0]);
        $minutos  = self::racional($coordenada[1]);
        $segundos = self::racional($coordenada[2]);

        if ($grados === 0.0 && $minutos === 0.0 && $segundos === 0.0) {
            return null;
        }

        $valor = $grados + ($minutos / 60) + ($segundos / 3600);
        $ref   = strtoupper(trim((string) $referencia));

        if ($ref === 'S' || $ref === 'W') {
            $valor *= -1;
        }

        return round($valor, 7);
    }

    private static function racional(mixed $valor): float
    {
        if (is_string($valor) && str_contains($valor, '/')) {
            [$numerador, $denominador] = array_pad(explode('/', $valor, 2), 2, '0');
            return (float) $denominador === 0.0 ? 0.0 : (float) $numerador / (float) $denominador;
        }

        return (float) $valor;
    }

    /* --------------------------------------------------------------------
     * MARCA DE AGUA
     * ------------------------------------------------------------------ */

    /**
     * Dibuja la marca de agua sobre una copia en memoria de la foto.
     *
     * @param array $foto Fila de Entregas::fotoDeEntrega() (logo, fechas, GPS…).
     * @param int|null $anchoMax Ancho máximo de salida (null = tamaño original).
     * @return array{contenido: string, mime: string}|null
     */
    public static function marcada(array $foto, ?int $anchoMax = null): ?array
    {
        $archivo = basename((string) ($foto['archivo'] ?? ''));
        $ruta    = Imagenes::CARPETA . '/' . $archivo;

        if ($archivo === '' || !is_file($ruta)) {
            return null;
        }

        try {
            $gerenciador = new ImageManager(new Driver());
            $imagen      = $gerenciador->read($ruta);

            if ($anchoMax !== null && $anchoMax > 0 && $imagen->width() > $anchoMax) {
                $imagen->scaleDown($anchoMax);
            }

            self::ponerLogo($gerenciador, $imagen, $foto);
            self::ponerTexto($imagen, $foto);

            $codificada = $imagen->toJpeg(88);
            return [
                'contenido' => (string) $codificada,
                'mime'      => 'image/jpeg',
            ];
        } catch (\Throwable $excepcion) {
            error_log('[FotosEntrega::marcada] ' . $excepcion->getMessage());
            return null;
        }
    }

    /** Logo del negocio emisor, arriba a la izquierda y sin deformar. */
    private static function ponerLogo(ImageManager $gerenciador, $imagen, array $foto): void
    {
        $archivo = trim((string) ($foto['empresa_logo'] ?? ''));
        if ($archivo === '' || str_contains($archivo, '..')) {
            return;
        }

        $recortado = Imagenes::logoRecortado($archivo);
        $ruta      = Imagenes::CARPETA . '/' . $recortado;
        if ($recortado === '' || str_contains($recortado, '..') || !is_file($ruta)) {
            return;
        }

        try {
            $logo = $gerenciador->read($ruta);
            $logo->scaleDown(
                (int) max(48, round($imagen->width() * self::LOGO_ANCHO)),
                (int) max(48, round($imagen->height() * self::LOGO_ALTO))
            );

            $margen = self::margen($imagen);
            $imagen->place($logo, 'top-left', $margen, $margen);
        } catch (\Throwable $excepcion) {
            // Sin logo la foto sigue siendo útil: se registra y se continúa.
            error_log('[FotosEntrega::ponerLogo] ' . $excepcion->getMessage());
        }
    }

    /** Fecha/hora y ubicación abajo a la izquierda, con sombra para resaltar. */
    private static function ponerTexto($imagen, array $foto): void
    {
        $lineas = self::etiquetas($foto);
        if (!$lineas) {
            return;
        }

        $fuente = self::fuente();
        if ($fuente === null) {
            return;
        }

        $ancho  = $imagen->width();
        $alto   = $imagen->height();
        $margen = self::margen($imagen);
        $tamano = max(14.0, round($ancho * self::TEXTO, 1));
        $sombra = max(2, (int) round($tamano * 0.09));
        $maximo = $ancho - ($margen * 2);

        $lineas = array_map(
            static fn (string $linea): string => self::recortar($linea, $tamano, $maximo),
            $lineas
        );
        $texto = implode("\n", $lineas);
        $y     = $alto - $margen;

        // Sombra: el mismo bloque desplazado y en negro semitransparente.
        $imagen->text($texto, $margen + $sombra, $y + $sombra, static fn (FontFactory $f) => $f
            ->filename($fuente)
            ->size($tamano)
            ->color('rgba(0,0,0,0.60)')
            ->align('left')
            ->valign('bottom'));

        // Texto principal en blanco.
        $imagen->text($texto, $margen, $y, static fn (FontFactory $f) => $f
            ->filename($fuente)
            ->size($tamano)
            ->color('ffffff')
            ->align('left')
            ->valign('bottom'));
    }

    /**
     * Líneas de la marca: fecha/hora y ubicación, sólo si hay dato.
     *
     * @return string[]
     */
    private static function etiquetas(array $foto): array
    {
        $lineas = [];

        $fecha = self::fechaHora($foto);
        if ($fecha !== '') {
            $lineas[] = 'Entrega: ' . $fecha;
        }

        $direccion = trim((string) ($foto['cliente_ubicacion'] ?? ''));
        $manual    = trim((string) ($foto['entrega_ubicacion'] ?? ''));
        $coordenadas = self::coordenadas($foto);
        $desdeGps = $coordenadas !== '';

        if ($coordenadas === '' && $manual !== '') {
            $coordenadas = (string) self::coordenadasDeEnlace($manual);
        }

        if ($direccion !== '') {
            $lineas[] = 'Ubicación: ' . $direccion;
        }

        if ($coordenadas !== '') {
            // Sin dirección, las coordenadas ocupan la línea de ubicación.
            $lineas[] = ($desdeGps && $direccion !== '' ? 'GPS: ' : 'Ubicación: ') . $coordenadas;
        } elseif ($manual !== '' && !self::esEnlace($manual)) {
            $lineas[] = 'Ubicación: ' . $manual;
        }

        return $lineas;
    }

    /**
     * Fecha y hora de la toma: EXIF primero; si no hay, el registro de la entrega.
     */
    private static function fechaHora(array $foto): string
    {
        foreach (['tomada_en', 'creado_en'] as $campo) {
            $valor = trim((string) ($foto[$campo] ?? ''));
            if ($valor !== '' && strtotime($valor)) {
                return date('d/m/Y H:i', strtotime($valor));
            }
        }

        $fecha = trim((string) ($foto['fecha'] ?? ''));
        return $fecha !== '' && strtotime($fecha) ? date('d/m/Y', strtotime($fecha)) : '';
    }

    /** Coordenadas GPS en grados decimales, si la foto las trae. */
    private static function coordenadas(array $foto): string
    {
        if ($foto['latitud'] === null || $foto['longitud'] === null) {
            return '';
        }

        return number_format((float) $foto['latitud'], 6, '.', '')
            . ', ' . number_format((float) $foto['longitud'], 6, '.', '');
    }

    /** Margen proporcional, con un mínimo legible para fotos pequeñas. */
    private static function margen($imagen): int
    {
        return max(10, (int) round($imagen->width() * self::MARGEN));
    }

    /** Fuente disponible (negrita; si faltara, la normal). */
    private static function fuente(): ?string
    {
        if (is_file(self::FUENTE_BOLD)) {
            return self::FUENTE_BOLD;
        }
        if (is_file(self::FUENTE)) {
            return self::FUENTE;
        }

        return null;
    }

    /**
     * Recorta una línea con «…» si no cabe en el ancho disponible.
     *
     * GD usa un tamaño nativo distinto al tipográfico de Intervention
     * (0.76 ×), por eso la medición aplica el mismo factor.
     */
    private static function recortar(string $texto, float $tamano, int $maxAncho): string
    {
        $fuente = self::fuente();
        if ($texto === '' || $fuente === null || !function_exists('imagettfbbox')) {
            return $texto;
        }

        $nativo = (int) round($tamano * 0.76);
        $caja   = @imagettfbbox($nativo, 0, $fuente, $texto);
        if ($caja === false || abs($caja[2] - $caja[0]) <= $maxAncho) {
            return $texto;
        }

        $inicio = 0;
        $fin    = mb_strlen($texto);
        $corte  = 0;

        while ($inicio <= $fin) {
            $medio  = intdiv($inicio + $fin, 2);
            $prueba = mb_substr($texto, 0, $medio) . '…';
            $caja   = @imagettfbbox($nativo, 0, $fuente, $prueba);

            if ($caja !== false && abs($caja[2] - $caja[0]) <= $maxAncho) {
                $corte  = $medio;
                $inicio = $medio + 1;
            } else {
                $fin = $medio - 1;
            }
        }

        return mb_substr($texto, 0, max(1, $corte)) . '…';
    }
}
