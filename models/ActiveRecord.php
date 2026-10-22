<?php

namespace Model;

class ActiveRecord
{
    protected static $db;
    protected static $tabla = '';
    protected static $columnasDB = [];
    protected static $alertas = [];

    public static function setDB($database)
    {
        self::$db = $database;
    }

    /** Conexión activa (para consultas escritas a mano) */
    public static function conexion()
    {
        return self::$db;
    }

    public static function setAlerta($tipo, $mensaje)
    {
        static::$alertas[$tipo][] = $mensaje;
    }

    public static function getAlertas()
    {
        return static::$alertas;
    }

    public function validar()
    {
        static::$alertas = [];
        return static::$alertas;
    }

    public static function consultarSQL($query)
    {
        $resultado = self::$db->query($query);
        if (!$resultado) {
            throw new \Exception("Error SQL: " . self::$db->error . " | Query: $query");
        }
        $array = [];
        while ($registro = $resultado->fetch_assoc()) {
            $array[] = static::crearObjeto($registro);
        }
        $resultado->free();
        return $array;
    }

    protected static function crearObjeto($registro)
    {
        $objeto = new static;
        foreach ($registro as $key => $value) {
            if (property_exists($objeto, $key)) {
                $objeto->$key = $value;
            }
        }
        return $objeto;
    }

    public function atributos()
    {
        $atributos = [];
        foreach (static::$columnasDB as $columna) {
            if ($columna === 'id') continue;
            $atributos[$columna] = $this->$columna ?? null;
        }
        return $atributos;
    }

    public function sanitizarAtributos()
    {
        $atributos = $this->atributos();
        $sanitizado = [];
        foreach ($atributos as $key => $value) {
            if (is_null($value)) {
                $sanitizado[$key] = null;
            } else {
                $sanitizado[$key] = self::$db->escape_string($value);
            }
        }
        return $sanitizado;
    }

    public function sincronizar($args = [])
    {
        foreach ($args as $key => $value) {
            if (property_exists($this, $key) && !is_null($value)) {
                $this->$key = $value;
            }
        }
    }

    /** Escapa un valor para usarlo en una consulta SQL escrita a mano */
    public static function escapar($valor): string
    {
        return self::$db->escape_string((string)$valor);
    }

    /** Ejecuta una sentencia SQL escrita a mano (UPDATE/DELETE masivos); sanitiza los valores con escapar() */
    public static function ejecutar(string $sql): bool
    {
        return (bool)self::$db->query($sql);
    }

    public function guardar()
    {
        if (!is_null($this->id)) {
            return $this->actualizar();
        }
        return $this->crear();
    }

    public static function all($orden = 'id DESC')
    {
        $query = "SELECT * FROM " . static::$tabla . " ORDER BY " . $orden;
        return self::consultarSQL($query);
    }

    public static function find($id)
    {
        $id = self::$db->escape_string($id);
        $query = "SELECT * FROM " . static::$tabla . " WHERE id = '$id'";
        $resultado = self::consultarSQL($query);
        return array_shift($resultado);
    }

    public static function where($columna, $valor)
    {
        $columna = self::$db->escape_string($columna);
        $valor = self::$db->escape_string($valor);
        $query = "SELECT * FROM " . static::$tabla . " WHERE $columna = '$valor'";
        $resultado = self::consultarSQL($query);
        return array_shift($resultado);
    }

    public static function whereAll($columna, $valor, $orden = 'id ASC')
    {
        $columna = self::$db->escape_string($columna);
        $valor = self::$db->escape_string($valor);
        $query = "SELECT * FROM " . static::$tabla . " WHERE $columna = '$valor' ORDER BY " . $orden;
        return self::consultarSQL($query);
    }

    public static function get($limite)
    {
        $limite = intval($limite);
        $query = "SELECT * FROM " . static::$tabla . " ORDER BY id DESC LIMIT " . $limite;
        return self::consultarSQL($query);
    }

    public static function total($where = null)
    {
        $query = "SELECT COUNT(*) FROM " . static::$tabla;
        if ($where) $query .= " WHERE " . $where;
        $resultado = self::$db->query($query);
        $total = $resultado->fetch_array();
        return (int) array_shift($total);
    }

    public function crear()
    {
        $atributos = $this->sanitizarAtributos();
        $columnas = [];
        $valores = [];
        foreach ($atributos as $key => $value) {
            $columnas[] = $key;
            $valores[] = is_null($value) ? "NULL" : "'{$value}'";
        }
        $query = "INSERT INTO " . static::$tabla . " (" . join(', ', $columnas) . ") VALUES (" . join(', ', $valores) . ")";
        $resultado = self::$db->query($query);
        return [
            'resultado' => $resultado,
            'id' => self::$db->insert_id
        ];
    }

    public function actualizar()
    {
        $atributos = $this->sanitizarAtributos();
        $valores = [];
        foreach ($atributos as $key => $value) {
            $valores[] = is_null($value) ? "{$key}=NULL" : "{$key}='{$value}'";
        }
        $query = "UPDATE " . static::$tabla . " SET " . join(', ', $valores);
        $query .= " WHERE id = '" . self::$db->escape_string($this->id) . "' LIMIT 1";
        return self::$db->query($query);
    }

    public function eliminar()
    {
        $query = "DELETE FROM " . static::$tabla . " WHERE id = " . self::$db->escape_string($this->id) . " LIMIT 1";
        return self::$db->query($query);
    }
}
