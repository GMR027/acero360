<?php

namespace Model;

/** Lámina (página) de un documento del Editor: el JSON de Konva es la fuente editable */
class EditorLamina extends ActiveRecord
{
    protected static $tabla = 'editor_laminas';
    protected static $columnasDB = ['documento_id', 'orden', 'nombre', 'marcas_json', 'marcas_png'];

    public $id;
    public $documento_id;
    public $orden;
    public $nombre;
    public $marcas_json;
    public $marcas_png;
    public $creado;
    public $actualizado;

    public function __construct($args = [])
    {
        $this->documento_id = null;
        $this->orden = 1;
        $this->nombre = '';
        $this->marcas_json = null;
        $this->marcas_png = null;

        foreach ($args as $k => $v) {
            if (property_exists($this, $k) && $v !== null) {
                $this->$k = $v;
            }
        }
    }

    public static function deDocumento(int $documentoId): array
    {
        return self::whereAll('documento_id', $documentoId, 'orden ASC, id ASC');
    }
}
