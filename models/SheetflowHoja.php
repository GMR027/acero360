<?php

namespace Model;

use Classes\Sheetflow;

/**
 * Tabla de SheetFlow: nombre, fecha y carpeta más sus columnas y renglones
 * (JSON saneado por Classes\Sheetflow). Las tablas son compartidas.
 */
class SheetflowHoja extends ActiveRecord
{
    protected static $tabla = 'sheetflow_hojas';
    protected static $columnasDB = ['id', 'nombre', 'fecha', 'departamento', 'descripcion', 'datos', 'creado_por'];

    public $id, $nombre, $fecha, $departamento, $descripcion, $datos, $creado_por;
    public $creado, $actualizado;   // los pone la BD

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = trim((string)($args['nombre'] ?? ''));
        $this->fecha = (string)($args['fecha'] ?? date('Y-m-d'));
        $this->departamento = trim((string)($args['departamento'] ?? ''));
        $this->descripcion = mb_substr(trim(preg_replace('/[\x00-\x1f]/u', ' ', (string)($args['descripcion'] ?? ''))), 0, 300);
        $this->datos = $args['datos'] ?? null;
        $this->creado_por = $args['creado_por'] ?? null;
    }

    public function validar(): array
    {
        self::$alertas = [];
        if ($this->nombre === '') self::$alertas['error'][] = 'El nombre es obligatorio';
        if (mb_strlen($this->nombre) > 150) self::$alertas['error'][] = 'El nombre es demasiado largo (máx. 150)';
        if (mb_strlen($this->departamento) > 100) self::$alertas['error'][] = 'La carpeta es demasiado larga (máx. 100)';
        $f = \DateTime::createFromFormat('Y-m-d', $this->fecha);
        if (!$f || $f->format('Y-m-d') !== $this->fecha) self::$alertas['error'][] = 'La fecha no es válida';
        return self::$alertas;
    }

    /** Contenido ya decodificado y completo (siempre con al menos una columna) */
    public function contenido(): array
    {
        return Sheetflow::limpiar(json_decode((string)$this->datos, true));
    }

    public static function todos(): array
    {
        return self::consultarSQL("SELECT * FROM sheetflow_hojas ORDER BY departamento ASC, fecha DESC, id DESC");
    }

    /** Carpetas ya usadas por las tablas */
    public static function departamentos(): array
    {
        $out = [];
        try {
            $r = self::$db->query("SELECT DISTINCT departamento FROM sheetflow_hojas WHERE departamento <> '' ORDER BY departamento");
            while ($r && ($f = $r->fetch_assoc())) $out[] = $f['departamento'];
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** Lista corta (id, nombre, tamaño) para ofrecerlas en el Redactor y el Editor */
    public static function resumen(): array
    {
        $out = [];
        try {
            foreach (self::todos() as $h) {
                $c = $h->contenido();
                $out[] = ['id' => (int)$h->id, 'nombre' => (string)$h->nombre, 'departamento' => (string)$h->departamento, 'filas' => count($c['rows']), 'cols' => count($c['cols'])];
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** Documentos del Redactor que insertan esta hoja (`data-sf`): [id, nombre] */
    public function usosRedactor(): array
    {
        $out = [];
        try {
            $r = self::$db->query("SELECT id, nombre FROM redactor_documentos WHERE contenido LIKE '%data-sf=\\\"" . (int)$this->id . "\\\"%' ORDER BY nombre");
            while ($r && ($f = $r->fetch_assoc())) $out[] = $f;
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public static function conexion()
    {
        return self::$db;
    }

    /** Imágenes de celdas que usa esta tabla */
    public function imagenes(): array
    {
        return Sheetflow::imagenesDe((string)$this->datos);
    }
}
