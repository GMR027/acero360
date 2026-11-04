<?php

namespace Classes;

/**
 * Caché de PDF ya compuestos en storage/cache/pdf.
 *
 * Dompdf tarda lo mismo la primera vez que la segunda: si el HTML de un
 * documento no cambió, el PDF que sale es idéntico byte a byte, así que no
 * vale la pena volver a maquetarlo. Aquí se guarda el resultado y la
 * siguiente exportación del mismo contenido lo sirve al momento.
 *
 * La clave se calcula del contenido (HTML + papel + orientación + opciones),
 * no de fechas ni de identificadores: así no puede servir un PDF de otro
 * documento ni quedar obsoleto cuando el dato cambia (otro HTML = otra
 * clave). Sólo sirve donde el HTML determina al PDF por completo; Redactor,
 * por ejemplo, mete numeración y pie que no están en el HTML y queda fuera.
 *
 * Todo es defensivo: si no se puede escribir o leer, se genera el PDF a
 * como siempre. El directorio está fuera de la red pública (.htaccess
 * prohíbe /storage) y no entra en los respaldos, que sólo toman
 * storage/documentos y storage/archivo.
 */
final class CachePdf
{
    /** Carpeta de la caché, relativa a la raíz del proyecto. */
    public const CARPETA = __DIR__ . '/../../storage/cache/pdf';

    /** Cuánto vive un PDF guardado. */
    private const VIDA = 7 * 24 * 3600;

    /** Cada cuánto se limpian los vencidos (marcador .purga). */
    private const LIMPIEZA = 24 * 3600;

    /** @var bool|null Se purga una sola vez por petición. */
    private static $purgada = null;

    /**
     * Clave de un documento: su contenido, no su identidad.
     */
    public static function clave(string $html, string|array $papel, string $orientacion, array $opciones = []): string
    {
        $papelLlave = is_array($papel)
            ? implode(',', array_map('strval', $papel))
            : (string) $papel;

        // ksort: el mismo arreglo de opciones en otro orden debe dar la misma clave.
        ksort($opciones);

        return md5($html . "\0" . $papelLlave . "\0" . $orientacion . "\0" . json_encode($opciones));
    }

    /** PDF guardado para esa clave, o null si no está (o no se pudo leer). */
    public static function obtener(string $clave): ?string
    {
        $ruta = self::ruta($clave);
        if ($ruta === null || !is_file($ruta)) {
            return null;
        }

        $bytes = @file_get_contents($ruta);
        if ($bytes === false || $bytes === '' || $bytes[0] !== '%') {
            return null; // vacío o a medias: mejor volver a generarlo
        }

        @touch($ruta); // para que lo recién usado no lo borre la purga

        return $bytes;
    }

    /** Guarda el PDF si se pudo; jamás lanza ni interrumpe la exportación. */
    public static function guardar(string $clave, string $pdf): void
    {
        if ($pdf === '' || $pdf[0] !== '%') {
            return;
        }

        $ruta = self::ruta($clave, true);
        if ($ruta === null) {
            return;
        }

        self::purgar();

        // Se escribe en un archivo aparte y se renombra: una petición que
        // llegue a media escritura nunca ve un PDF cortado.
        $tmp = $ruta . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $pdf, LOCK_EX) !== false) {
            @rename($tmp, $ruta);
        } else {
            @unlink($tmp);
        }
    }

    /** Ruta del PDF para la clave; null si no hay carpeta. */
    private static function ruta(string $clave, bool $crear = false): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $clave)) {
            return null;
        }

        $carpeta = realpath(self::CARPETA);
        if ($carpeta === false) {
            if (!$crear || !@mkdir(self::CARPETA, 0775, true)) {
                return null;
            }
            $carpeta = realpath(self::CARPETA);
            if ($carpeta === false) {
                return null;
            }
        }

        return $carpeta . '/' . $clave . '.pdf';
    }

    /**
     * Borra los PDF de más de una semana, una vez por petición.
     *
     * El marcador .purga evita recorrer la carpeta en cada exportación; si
     * no se puede escribir, simplemente no se limpia (la caché sigue
     * sirviendo, y en el peor caso crece un poco hasta el siguiente éxito).
     */
    private static function purgar(): void
    {
        if (self::$purgada !== null) {
            return;
        }
        self::$purgada = true;

        $carpeta = realpath(self::CARPETA);
        if ($carpeta === false) {
            return;
        }

        $marcador = $carpeta . '/.purga';
        $ahora = time();
        if (is_file($marcador) && ($ahora - (int) @filemtime($marcador)) < self::LIMPIEZA) {
            return;
        }

        @touch($marcador);

        $vencidos = $ahora - self::VIDA;
        $viejos = @glob($carpeta . '/*.pdf') ?: [];
        foreach ($viejos as $viejo) {
            if ((int) @filemtime($viejo) < $vencidos) {
                @unlink($viejo);
            }
        }
    }
}
