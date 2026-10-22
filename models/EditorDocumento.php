<?php

namespace Model;

use Classes\Imagenes;

/**
 * Documento del Editor: una o varias láminas (páginas) dibujadas con Konva.
 *
 * El documento guarda el tamaño de página en píxeles a 96 dpi; el JSON de
 * cada lámina es la fuente editable y los PNG/PDF son sólo exportaciones.
 */
class EditorDocumento extends ActiveRecord
{
    protected static $tabla = 'editor_documentos';
    protected static $columnasDB = [
        'nombre', 'ancho', 'alto', 'orientacion', 'fondo', 'fondo_color',
        'fondo_imagen', 'fondo_config', 'creado_por',
    ];

    /** dpi de referencia: 1 mm = 96/25.4 px */
    public const DPI = 96;
    public const MIN_PX = 200;
    public const MAX_PX = 6000;

    /** Tamaños de página en milímetros */
    public const TAMANOS = [
        'carta'      => ['Carta (216 × 279 mm)', 216, 279],
        'oficio'     => ['Oficio (216 × 330 mm)', 216, 330],
        'legal'      => ['Legal (216 × 356 mm)', 216, 356],
        'tabloide'   => ['Tabloide (279 × 432 mm)', 279, 432],
        'a4'         => ['A4 (210 × 297 mm)', 210, 297],
        'a3'         => ['A3 (297 × 420 mm)', 297, 420],
        'a5'         => ['A5 (148 × 210 mm)', 148, 210],
        'personalizado' => ['Personalizado', 0, 0],
    ];

    public const FONDOS = [
        'liso'      => 'Liso',
        'cuadricula' => 'Cuadrícula',
        'puntos'    => 'Puntos',
        'imagen'    => 'Imagen',
    ];

    public $id;
    public $nombre;
    public $ancho;
    public $alto;
    public $orientacion;
    public $fondo;
    public $fondo_color;
    public $fondo_imagen;
    public $fondo_config;
    public $creado_por;
    public $creado;
    public $actualizado;

    public function __construct($args = [])
    {
        $this->ancho = 816;
        $this->alto = 1056;
        $this->orientacion = 'vertical';
        $this->fondo = 'liso';
        $this->fondo_color = '#ffffff';
        $this->fondo_config = json_encode(['paso' => 40, 'color' => '#e2e8f0']);
        $this->creado_por = null;

        foreach ($args as $k => $v) {
            if (property_exists($this, $k) && $v !== null) {
                $this->$k = $v;
            }
        }
    }

    /** Convierte milímetros a píxeles del lienzo */
    public static function mm(int|float $mm): int
    {
        return (int) round($mm * self::DPI / 25.4);
    }

    public static function pxAMm(int $px): float
    {
        return round($px * 25.4 / self::DPI, 1);
    }

    /** Ancho y alto efectivos del lienzo (ya incluyen la orientación elegida) */
    public function medidas(): array
    {
        $ancho = max(self::MIN_PX, min(self::MAX_PX, (int) $this->ancho));
        $alto = max(self::MIN_PX, min(self::MAX_PX, (int) $this->alto));
        return [$ancho, $alto];
    }

    public function validar(): array
    {
        static::$alertas = [];

        if (trim((string) $this->nombre) === '') {
            static::$alertas['error'][] = 'El nombre es obligatorio';
        } elseif (mb_strlen(trim((string) $this->nombre)) > 150) {
            static::$alertas['error'][] = 'El nombre no puede pasar de 150 caracteres';
        }

        [$ancho, $alto] = $this->medidas();
        if ($ancho < self::MIN_PX || $ancho > self::MAX_PX || $alto < self::MIN_PX || $alto > self::MAX_PX) {
            static::$alertas['error'][] = 'El tamaño de página debe estar entre ' . self::MIN_PX . ' y ' . self::MAX_PX . ' px';
        }

        if (!isset(self::FONDOS[$this->fondo])) {
            $this->fondo = 'liso';
        }

        if (!preg_match('/^#[0-9a-f]{6}$/i', (string) $this->fondo_color)) {
            $this->fondo_color = '#ffffff';
        }

        return static::$alertas;
    }

    public static function todos(): array
    {
        return self::all('actualizado DESC, id DESC');
    }

    /** Láminas del documento, en orden */
    public function laminas(): array
    {
        return EditorLamina::deDocumento((int) $this->id);
    }

    /** Configuración del fondo (paso de la cuadrícula, color) */
    public function configFondo(): array
    {
        $c = json_decode((string) $this->fondo_config, true);
        return is_array($c) ? $c : ['paso' => 40, 'color' => '#e2e8f0'];
    }

    /** Imágenes usadas por las láminas y el fondo */
    public function imagenes(): array
    {
        $out = [];
        if (!empty($this->fondo_imagen)) {
            $out[] = $this->fondo_imagen;
        }
        foreach ($this->laminas() as $l) {
            $out = array_merge($out, self::imagenesDe($l->marcas_json));
        }
        return array_values(array_unique($out));
    }

    /** Nombres de archivo dentro del JSON de una lámina (atributo src de las imágenes) */
    public static function imagenesDe(?string $json): array
    {
        if (!$json) {
            return [];
        }
        preg_match_all('#/public/uploads/vplan/([a-zA-Z0-9._-]+)#', $json, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /** Imágenes usadas por otros documentos */
    private function imagenesDeOtros(): array
    {
        $out = [];
        $db = self::conexion();
        $id = (int) $this->id;
        $r = $db->query("SELECT marcas_json FROM editor_laminas WHERE documento_id <> $id");
        while ($r && ($f = $r->fetch_assoc())) {
            $out = array_merge($out, self::imagenesDe($f['marcas_json']));
        }
        $r = $db->query("SELECT fondo_imagen FROM editor_documentos WHERE id <> $id AND fondo_imagen IS NOT NULL");
        while ($r && ($f = $r->fetch_assoc())) {
            $out[] = $f['fondo_imagen'];
        }
        return array_values(array_unique($out));
    }

    /** Borra los PNG de las láminas y las imágenes que no use otro documento */
    public function eliminarArchivos(): void
    {
        $propias = $this->imagenes();

        $db = self::conexion();
        $id = (int) $this->id;
        $r = $db->query("SELECT marcas_png FROM editor_laminas WHERE documento_id = $id");
        while ($r && ($f = $r->fetch_assoc())) {
            if (!empty($f['marcas_png'])) {
                Imagenes::eliminarVplan($f['marcas_png']);
            }
        }

        $otros = $this->imagenesDeOtros();
        foreach (array_diff($propias, $otros) as $n) {
            Imagenes::eliminarVplan($n);
        }
    }

    /** Marca el documento como modificado (aunque sólo cambien sus láminas) */
    public function tocar(): void
    {
        self::ejecutar('UPDATE editor_documentos SET actualizado = NOW() WHERE id = ' . (int) $this->id);
    }

    /** Elimina el documento con sus láminas */
    public function eliminar(): bool
    {
        $this->eliminarArchivos();
        return parent::eliminar();
    }
}
