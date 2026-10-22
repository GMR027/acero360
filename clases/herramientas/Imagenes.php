<?php

namespace Classes;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/** Miniaturas de materiales (Intervention Image, driver GD) */
class Imagenes
{
    public const CARPETA = __DIR__ . '/../public/uploads/materiales';
    public const ANCHO = 240;

    /** Genera miniatura desde un archivo y devuelve el nombre guardado */
    public static function miniatura(string $origen): ?string
    {
        try {
            $mime = mime_content_type($origen);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;
            $img = (new ImageManager(new Driver()))->read($origen)->scaleDown(width: self::ANCHO, height: self::ANCHO);
            if (!is_dir(self::CARPETA)) mkdir(self::CARPETA, 0775, true);
            $png = $mime === 'image/png' || $mime === 'image/gif';
            $nombre = md5(uniqid('', true)) . ($png ? '.png' : '.jpg');
            ($png ? $img->toPng() : $img->toJpeg(80))->save(self::CARPETA . '/' . $nombre);
            return $nombre;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public const CARPETA_VPLAN = __DIR__ . '/../../public/uploads/vplan';

    /** Guarda una imagen de plano/simbología reducida a $maxAncho px de ancho (si es mayor) */
    public static function guardarGrande(string $origen, int $maxAncho = 3840): ?string
    {
        try {
            $mime = mime_content_type($origen);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;
            $img = (new ImageManager(new Driver()))->read($origen)->scaleDown(width: $maxAncho);
            if (!is_dir(self::CARPETA_VPLAN)) mkdir(self::CARPETA_VPLAN, 0775, true);
            $png = $mime === 'image/png' || $mime === 'image/gif';
            $nombre = md5(uniqid('', true)) . ($png ? '.png' : '.jpg');
            ($png ? $img->toPng() : $img->toJpeg(88))->save(self::CARPETA_VPLAN . '/' . $nombre);
            return $nombre;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Copia un archivo de vPlan con otro nombre (para que cada lámina sea dueña del suyo); null si no existe */
    public static function copiarVplan(?string $nombre): ?string
    {
        $ruta = $nombre ? self::CARPETA_VPLAN . '/' . basename($nombre) : null;
        if (!$ruta || !is_file($ruta)) return null;
        $nuevo = md5(uniqid('', true)) . '.' . pathinfo($ruta, PATHINFO_EXTENSION);
        return @copy($ruta, self::CARPETA_VPLAN . '/' . $nuevo) ? $nuevo : null;
    }

    public static function eliminarVplan(?string $nombre): void
    {
        if ($nombre) @unlink(self::CARPETA_VPLAN . '/' . basename($nombre));
    }

    /** data URI de una imagen de vPlan reducida (para PDF); null si no existe */
    public static function vplanBase64(?string $nombre, int $maxAncho = 2000): ?string
    {
        $ruta = $nombre ? self::CARPETA_VPLAN . '/' . basename($nombre) : null;
        if (!$ruta || !is_file($ruta)) return null;
        try {
            $img = (new ImageManager(new Driver()))->read($ruta)->scaleDown(width: $maxAncho);
            $png = strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) === 'png';
            return 'data:image/' . ($png ? 'png' : 'jpeg') . ';base64,' . base64_encode((string)($png ? $img->toPng() : $img->toJpeg(85)));
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function eliminar(?string $nombre): void
    {
        if ($nombre) @unlink(self::CARPETA . '/' . basename($nombre));
    }

    public static function ruta(?string $nombre): ?string
    {
        $r = $nombre ? self::CARPETA . '/' . basename($nombre) : null;
        return $r && is_file($r) ? $r : null;
    }

    /** Guarda una foto reducida en public/uploads/{carpeta}; devuelve el nombre o null si no es una imagen válida */
    public static function guardarEn(string $carpeta, string $origen, int $maxLado = 1600, int $calidad = 85): ?string
    {
        try {
            $mime = mime_content_type($origen);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;
            $dir = __DIR__ . '/../public/uploads/' . preg_replace('/[^a-z_]/', '', $carpeta);
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $img = (new ImageManager(new Driver()))->read($origen)->scaleDown(width: $maxLado, height: $maxLado);
            $png = $mime === 'image/png' || $mime === 'image/gif';
            $nombre = md5(uniqid('', true)) . ($png ? '.png' : '.jpg');
            ($png ? $img->toPng() : $img->toJpeg($calidad))->save($dir . '/' . $nombre);
            return $nombre;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function eliminarEn(string $carpeta, ?string $nombre): void
    {
        if ($nombre) @unlink(__DIR__ . '/../public/uploads/' . preg_replace('/[^a-z_]/', '', $carpeta) . '/' . basename($nombre));
    }
}
