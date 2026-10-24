<?php

namespace Model;

/**
 * Archivo subido a la herramienta Archivo: su nombre original, dónde está en
 * disco (nombre aleatorio dentro de storage/archivo) y quién lo subió.
 * Los bytes no se guardan en la BD, sólo la referencia.
 */
class Archivo extends ActiveRecord
{
    protected static $tabla = 'archivo_archivos';
    protected static $columnasDB = ['id', 'carpeta_id', 'nombre', 'archivo', 'mime', 'tamano', 'sha256', 'descripcion', 'subido_por'];

    public $id, $carpeta_id, $nombre, $archivo, $mime, $tamano, $sha256, $descripcion, $subido_por;
    public $subido, $actualizado;   // los pone la BD

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->carpeta_id = isset($args['carpeta_id']) && (int) $args['carpeta_id'] > 0 ? (int) $args['carpeta_id'] : null;
        $this->nombre = (string) ($args['nombre'] ?? '');
        $this->archivo = (string) ($args['archivo'] ?? '');
        $this->mime = (string) ($args['mime'] ?? 'application/octet-stream');
        $this->tamano = (int) ($args['tamano'] ?? 0);
        $this->sha256 = (string) ($args['sha256'] ?? '');
        $this->descripcion = mb_substr(trim(preg_replace('/[\x00-\x1f]/u', ' ', (string) ($args['descripcion'] ?? ''))), 0, 300);
        $this->subido_por = $args['subido_por'] ?? null;
    }

    public function validar(): array
    {
        self::$alertas = [];
        if ($this->nombre === '') self::$alertas['error'][] = 'El archivo no tiene nombre';
        if ($this->archivo === '') self::$alertas['error'][] = 'Falta la referencia al archivo en disco';
        return self::$alertas;
    }

    /** @return array<int, self> Archivos de una carpeta (null = raíz), más recientes primero. */
    public static function enCarpeta(?int $carpetaId, string $busqueda = ''): array
    {
        $donde = $carpetaId ? 'carpeta_id = ' . (int) $carpetaId : 'carpeta_id IS NULL';
        $orden = 'ORDER BY subido DESC, id DESC';
        if ($busqueda !== '') {
            $q = self::escapar(mb_strtolower($busqueda));
            // Nombre, descripción o extensión: sirve para encontrar «presupuesto», «.dwg»…
            $donde .= " AND (LOWER(nombre) LIKE '%$q%' OR LOWER(descripcion) LIKE '%$q%')";
            $orden = 'ORDER BY nombre ASC';
        }
        return self::consultarSQL("SELECT * FROM archivo_archivos WHERE $donde $orden");
    }

    /** ¿Existe ya este archivo (mismo SHA-256) en esa carpeta? Devuelve el registro. */
    public static function duplicadoEn(string $sha256, ?int $carpetaId): ?self
    {
        if ($sha256 === '') return null;
        $sha = self::escapar($sha256);
        $donde = $carpetaId ? 'carpeta_id = ' . (int) $carpetaId : 'carpeta_id IS NULL';
        $filas = self::consultarSQL("SELECT * FROM archivo_archivos WHERE sha256 = '$sha' AND $donde LIMIT 1");
        return array_shift($filas) ?: null;
    }

    /** @return array<int, self> Todos los archivos (para el buscador global). */
    public static function buscar(string $busqueda): array
    {
        $q = self::escapar(mb_strtolower(trim($busqueda)));
        if ($q === '') return [];
        return self::consultarSQL(
            "SELECT * FROM archivo_archivos WHERE LOWER(nombre) LIKE '%$q%' OR LOWER(descripcion) LIKE '%$q%' ORDER BY subido DESC LIMIT 200"
        );
    }

    /** Extensión en minúsculas (sin punto); '' si no la tiene. */
    public function extension(): string
    {
        return strtolower(pathinfo($this->nombre, PATHINFO_EXTENSION));
    }

    /** Icono de Material Symbols según el tipo de archivo. */
    public function icono(): string
    {
        return match ($this->extension()) {
            'pdf'                                => 'picture_as_pdf',
            'doc', 'docx', 'docm', 'odt', 'rtf', 'txt', 'md' => 'description',
            'xls', 'xlsx', 'xlsm', 'ods', 'csv'  => 'table_chart',
            'ppt', 'pptx', 'pptm', 'odp'          => 'slideshow',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff' => 'image',
            'zip', 'rar', '7z', 'tar', 'gz'       => 'folder_zip',
            'dwg', 'dxf'                          => 'architecture',
            'rvt', 'rfa', 'rte'                   => 'deployed_code',
            '3dm', 'obj', 'stl', 'step', 'stp', 'iges', 'igs', 'fbx' => 'view_in_ar',
            'skp'                                 => 'view_day',
            'mp3', 'wav', 'ogg', 'm4a'            => 'audio_file',
            'mp4', 'mov', 'avi', 'mkv'            => 'video_file',
            default                               => 'draft',
        };
    }

    /** Familia de formato, para filtros y etiquetas. */
    public function familia(): string
    {
        return match ($this->extension()) {
            'doc', 'docx', 'docm', 'odt', 'rtf',
            'xls', 'xlsx', 'xlsm', 'ods', 'csv',
            'ppt', 'pptx', 'pptm', 'odp'          => 'ofimatica',
            'pdf'                                 => 'pdf',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff' => 'imagen',
            'dwg', 'dxf', 'rvt', 'rfa', 'rte', '3dm', 'obj', 'stl',
            'step', 'stp', 'iges', 'igs', 'fbx', 'skp' => 'cad',
            'zip', 'rar', '7z', 'tar', 'gz'       => 'comprimido',
            default                               => 'otro',
        };
    }

    /** Tamaño legible (1,4 MB / 312 KB). */
    public function tamanoLegible(): string
    {
        return \Classes\ArchivoAlmacen::legible((int) $this->tamano);
    }

    /** Nombre sin ruta para mostrar (ya viene limpio de la BD). */
    public function titulo(): string
    {
        return $this->nombre !== '' ? $this->nombre : 'archivo.' . $this->extension();
    }
}
