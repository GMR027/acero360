<?php

namespace Model;

/**
 * Carpeta virtual de la herramienta Archivo (árbol de hasta varios niveles).
 *
 * Compartida entre administradores, como el resto de herramientas personales.
 * Al borrar una carpeta MySQL empuja su contenido a la raíz (carpeta_id NULL):
 * no se pierde ningún archivo.
 */
class ArchivoCarpeta extends ActiveRecord
{
    protected static $tabla = 'archivo_carpetas';
    protected static $columnasDB = ['id', 'nombre', 'carpeta_padre_id', 'creado_por'];

    public $id, $nombre, $carpeta_padre_id, $creado_por;
    public $creado, $actualizado;   // los pone la BD

    /** Profundidad máxima admitida (raíz + 4 niveles). */
    public const PROFUNDIDAD_MAX = 4;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = self::limpiarNombre($args['nombre'] ?? '');
        $this->carpeta_padre_id = isset($args['carpeta_padre_id']) && (int) $args['carpeta_padre_id'] > 0
            ? (int) $args['carpeta_padre_id']
            : null;
        $this->creado_por = $args['creado_por'] ?? null;
    }

    /** Nombre apto para guardar: sin controles, espacios colapsados, 100 caracteres. */
    public static function limpiarNombre($nombre): string
    {
        $nombre = preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) $nombre);
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) $nombre));
        // Caracteres que encajan mal en rutas, URLs y nombres de archivo.
        $nombre = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', (string) $nombre);
        return mb_substr($nombre, 0, 100);
    }

    public function validar(): array
    {
        self::$alertas = [];
        if ($this->nombre === '') {
            self::$alertas['error'][] = 'El nombre de la carpeta es obligatorio';
        }
        if (mb_strlen($this->nombre) > 100) {
            self::$alertas['error'][] = 'El nombre es demasiado largo (máx. 100)';
        }
        if ($this->carpeta_padre_id) {
            $padre = self::find($this->carpeta_padre_id);
            if (!$padre) {
                self::$alertas['error'][] = 'La carpeta contenedora ya no existe';
            } elseif (count($padre->ruta()) >= self::PROFUNDIDAD_MAX) {
                self::$alertas['error'][] = 'Se alcanzó la profundidad máxima de carpetas';
            }
            if ((int) $this->id === (int) $this->carpeta_padre_id) {
                self::$alertas['error'][] = 'Una carpeta no puede contenerse a sí misma';
            }
        }
        return self::$alertas;
    }

    /** Carpetas de un nivel concreto (null = raíz), ordenadas por nombre. */
    public static function enNivel(?int $padreId): array
    {
        $donde = $padreId ? 'carpeta_padre_id = ' . (int) $padreId : 'carpeta_padre_id IS NULL';
        return self::consultarSQL("SELECT * FROM archivo_carpetas WHERE $donde ORDER BY nombre ASC");
    }

    /** @return array<int, array{id: int, nombre: string}> Ruta desde la raíz hasta esta carpeta. */
    public function ruta(): array
    {
        $ruta = [];
        $actual = $this;
        $giros = 0;
        while ($actual && $giros <= self::PROFUNDIDAD_MAX + 2) {
            array_unshift($ruta, ['id' => (int) $actual->id, 'nombre' => (string) $actual->nombre]);
            if (!$actual->carpeta_padre_id) {
                break;
            }
            $actual = self::find((int) $actual->carpeta_padre_id);
            $giros++;
        }
        return $ruta;
    }

    /** @return array<int, int> Ids de esta carpeta y todas sus descendientes. */
    public function idsConDescendientes(): array
    {
        $ids = [(int) $this->id];
        $pendientes = [(int) $this->id];
        $giros = 0;
        while ($pendientes && $giros < 500) {
            $siguientes = [];
            foreach ($pendientes as $padre) {
                foreach (self::enNivel($padre) as $hija) {
                    $ids[] = (int) $hija->id;
                    $siguientes[] = (int) $hija->id;
                }
            }
            $pendientes = $siguientes;
            $giros++;
        }
        return $ids;
    }

    /** Total de archivos guardados en esta carpeta y sus descendientes. */
    public function totalArchivos(): int
    {
        $ids = implode(',', $this->idsConDescendientes());
        $r = self::$db->query("SELECT COUNT(*) FROM archivo_archivos WHERE carpeta_id IN ($ids)");
        $fila = $r ? $r->fetch_array() : null;
        return $fila ? (int) array_shift($fila) : 0;
    }

    /** ¿Existe ya una hermana con este nombre? (evita duplicados en un nivel) */
    public function nombreUsado(string $nombre, int $padreId): bool
    {
        $n = self::escapar(mb_strtolower(self::limpiarNombre($nombre)));
        $p = $padreId ? (int) $padreId : 0;
        $donde = $padreId ? "carpeta_padre_id = $p" : 'carpeta_padre_id IS NULL';
        $r = self::$db->query("SELECT COUNT(*) FROM archivo_carpetas WHERE $donde AND LOWER(nombre) = '$n' AND id <> " . (int) $this->id);
        $fila = $r ? $r->fetch_array() : null;
        return $fila && (int) array_shift($fila) > 0;
    }
}
