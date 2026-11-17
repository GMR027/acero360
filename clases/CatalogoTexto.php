<?php

namespace App;

/**
 * CatalogoTexto — catálogos sencillos de un solo campo de texto (`nombre`).
 *
 * Una misma clase atiende dos catálogos con el mismo comportamiento:
 *   * categorias_producto  → productos.categoria_id   (/admin/productos/categorias)
 *   * ubicaciones_cliente  → clientes.ubicacion_id    (/admin/clientes/ubicaciones)
 *
 * Cada fila referenciada guarda el id; una categoría o ubicación en uso no se
 * puede borrar (se avisa), pero sí renombrar. En productos además se mantiene
 * `productos.categoria` como copia del nombre, para que la búsqueda, el
 * cotizador y los reportes que ya leían ese texto sigan funcionando igual.
 */
class CatalogoTexto
{
    /** @var \mysqli */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    /** @var array<string, array<int, string>> Caché id => nombre por catálogo. */
    private static $mapas = [];

    /**
     * Configuración de cada catálogo.
     *
     * tabla      tabla del catálogo
     * usoTabla   tabla que lo referencia; usoColumna, la columna con el id
     * copia      columna de usoTabla que guarda una copia del nombre (o null)
     */
    public const CATALOGOS = [
        'categorias_producto' => [
            'tabla'      => 'categorias_producto',
            'usoTabla'   => 'productos',
            'usoColumna' => 'categoria_id',
            'copia'      => 'categoria',
            'maximo'     => 80,
            'singular'   => 'categoría',
            'plural'     => 'categorías',
            'titulo'     => 'Categorías de producto',
            'ruta'       => '/admin/productos/categorias',
            'rutaPadre'  => '/admin/productos',
            'padre'      => 'Catálogo de productos',
            'usoPlural'  => 'productos',
            'usoSingular'=> 'producto',
            'buscarUso'  => '/admin/productos?q=',
            'ejemplo'    => 'Ej. Estantes, Canastillas, Accesorios',
            'icono'      => 'category',
        ],
        'ubicaciones_cliente' => [
            'tabla'      => 'ubicaciones_cliente',
            'usoTabla'   => 'clientes',
            'usoColumna' => 'ubicacion_id',
            'copia'      => null,
            'maximo'     => 120,
            'singular'   => 'ubicación',
            'plural'     => 'ubicaciones',
            'titulo'     => 'Ubicaciones de clientes',
            'ruta'       => '/admin/clientes/ubicaciones',
            'rutaPadre'  => '/admin/clientes',
            'padre'      => 'Módulo de clientes',
            'usoPlural'  => 'clientes',
            'usoSingular'=> 'cliente',
            'buscarUso'  => null,
            'ejemplo'    => 'Ej. Guadalajara, Zona norte, Bodega centro',
            'icono'      => 'location_on',
        ],
    ];

    public $id;
    public $nombre;
    public $creado_en;
    public $usos = 0;

    /** @var string Clave del catálogo (ver CATALOGOS). */
    public $catalogo;

    public function __construct(string $catalogo, array $args = [])
    {
        self::config($catalogo);
        $this->catalogo  = $catalogo;
        $this->id        = $args['id'] ?? null;
        $this->nombre    = $args['nombre'] ?? '';
        $this->creado_en = $args['creado_en'] ?? '';
        $this->usos      = (int) ($args['usos'] ?? 0);
    }

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /** Configuración del catálogo; lanza si la clave no existe. */
    public static function config(string $catalogo): array
    {
        if (!isset(self::CATALOGOS[$catalogo])) {
            throw new \InvalidArgumentException('Catálogo desconocido: ' . $catalogo);
        }
        return self::CATALOGOS[$catalogo];
    }

    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** @return array<int, string> id => nombre en orden alfabético. */
    public static function mapa(string $catalogo): array
    {
        if (isset(self::$mapas[$catalogo])) {
            return self::$mapas[$catalogo];
        }
        $c = self::config($catalogo);
        $mapa = [];
        $resultado = self::$db->query("SELECT id, nombre FROM {$c['tabla']} ORDER BY nombre ASC");
        while ($fila = $resultado->fetch_assoc()) {
            $mapa[(int) $fila['id']] = (string) $fila['nombre'];
        }
        $resultado->free();
        return self::$mapas[$catalogo] = $mapa;
    }

    /** Nombre del id, o '' si no existe. */
    public static function nombre(string $catalogo, ?int $id): string
    {
        return $id ? (self::mapa($catalogo)[$id] ?? '') : '';
    }

    public static function existe(string $catalogo, int $id): bool
    {
        return $id > 0 && isset(self::mapa($catalogo)[$id]);
    }

    public static function buscarID(string $catalogo, $id): ?self
    {
        $c = self::config($catalogo);
        $id = (int) $id;
        $consulta = self::$db->prepare("SELECT * FROM {$c['tabla']} WHERE id = ? LIMIT 1");
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? new self($catalogo, $fila) : null;
    }

    /** Cuántos registros usan esta fila del catálogo. */
    public static function enUso(string $catalogo, int $id): int
    {
        $c = self::config($catalogo);
        $consulta = self::$db->prepare("SELECT COUNT(*) AS total FROM {$c['usoTabla']} WHERE {$c['usoColumna']} = ?");
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /** @return self[] Listado paginado con el número de usos. */
    public static function mostrarTodos(string $catalogo, int $limite = 15, int $offset = 0, string $termino = ''): array
    {
        $c = self::config($catalogo);
        $limite = max(1, $limite);
        $offset = max(0, $offset);
        $termino = trim($termino);
        $patron = '%' . $termino . '%';

        $consulta = self::$db->prepare(
            "SELECT t.*, (SELECT COUNT(*) FROM {$c['usoTabla']} u WHERE u.{$c['usoColumna']} = t.id) AS usos
               FROM {$c['tabla']} t
              WHERE (? = '' OR t.nombre LIKE ?)
              ORDER BY t.nombre ASC
              LIMIT ? OFFSET ?"
        );
        $consulta->bind_param('ssii', $termino, $patron, $limite, $offset);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $lista = [];
        while ($fila = $resultado->fetch_assoc()) {
            $lista[] = new self($catalogo, $fila);
        }
        $consulta->close();

        return $lista;
    }

    public static function contarTodos(string $catalogo, string $termino = ''): int
    {
        $c = self::config($catalogo);
        $termino = trim($termino);
        $patron = '%' . $termino . '%';
        $consulta = self::$db->prepare("SELECT COUNT(*) AS total FROM {$c['tabla']} WHERE (? = '' OR nombre LIKE ?)");
        $consulta->bind_param('ss', $termino, $patron);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /* --------------------------------------------------------------------
     * VALIDACIÓN Y ESCRITURA
     * ------------------------------------------------------------------ */

    public function validar(): array
    {
        self::$errores = [];
        $c = self::config($this->catalogo);
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) $this->nombre));
        $etiqueta = 'El nombre de la ' . $c['singular'];

        if ($nombre === '') {
            self::$errores[] = $etiqueta . ' es obligatorio.';
        } elseif (mb_strlen($nombre) > $c['maximo']) {
            self::$errores[] = $etiqueta . ' no puede pasar de ' . $c['maximo'] . ' caracteres.';
        } else {
            $consulta = self::$db->prepare("SELECT id FROM {$c['tabla']} WHERE nombre = ? LIMIT 1");
            $consulta->bind_param('s', $nombre);
            $consulta->execute();
            $fila = $consulta->get_result()->fetch_assoc();
            $consulta->close();
            if ($fila && (int) $fila['id'] !== (int) $this->id) {
                self::$errores[] = 'Ya existe una ' . $c['singular'] . ' con el nombre «' . $nombre . '».';
            }
        }

        $this->nombre = $nombre;
        return self::$errores;
    }

    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    public function crear(): bool
    {
        $c = self::config($this->catalogo);
        try {
            $consulta = self::$db->prepare("INSERT INTO {$c['tabla']} (nombre) VALUES (?)");
            $consulta->bind_param('s', $this->nombre);
            $correcto = $consulta->execute();
            $consulta->close();
            if ($correcto) {
                $this->id = (int) self::$db->insert_id;
                unset(self::$mapas[$this->catalogo]);
                return true;
            }
        } catch (\mysqli_sql_exception $e) {
            error_log('[CatalogoTexto::crear] ' . $e->getMessage());
            if ((int) $e->getCode() === 1062) {
                self::$errores[] = 'Ya existe una ' . $c['singular'] . ' con ese nombre.';
                return false;
            }
        }
        self::$errores[] = 'No fue posible guardar la ' . $c['singular'] . '.';
        return false;
    }

    /** Renombra; si el catálogo tiene copia del nombre, la sincroniza. */
    public function actualizar(): bool
    {
        $c = self::config($this->catalogo);
        $id = (int) $this->id;
        self::$db->begin_transaction();
        try {
            $consulta = self::$db->prepare("UPDATE {$c['tabla']} SET nombre = ? WHERE id = ?");
            $consulta->bind_param('si', $this->nombre, $id);
            $consulta->execute();
            $consulta->close();

            if ($c['copia']) {
                $copia = self::$db->prepare("UPDATE {$c['usoTabla']} SET {$c['copia']} = ? WHERE {$c['usoColumna']} = ?");
                $copia->bind_param('si', $this->nombre, $id);
                $copia->execute();
                $copia->close();
            }

            self::$db->commit();
            unset(self::$mapas[$this->catalogo]);
            return true;
        } catch (\mysqli_sql_exception $e) {
            self::$db->rollback();
            error_log('[CatalogoTexto::actualizar] ' . $e->getMessage());
            if ((int) $e->getCode() === 1062) {
                self::$errores[] = 'Ya existe una ' . $c['singular'] . ' con ese nombre.';
                return false;
            }
        }
        self::$errores[] = 'No fue posible actualizar la ' . $c['singular'] . '.';
        return false;
    }

    /** Elimina sólo si nadie la usa. */
    public function eliminar(): bool
    {
        $c = self::config($this->catalogo);
        $id = (int) $this->id;
        $usos = self::enUso($this->catalogo, $id);
        if ($usos > 0) {
            self::$errores[] = 'No se puede eliminar «' . $this->nombre . '»: la usan ' . $usos . ' '
                . ($usos === 1 ? $c['usoSingular'] : $c['usoPlural']) . '. Cámbiales la ' . $c['singular'] . ' primero.';
            return false;
        }
        try {
            $consulta = self::$db->prepare("DELETE FROM {$c['tabla']} WHERE id = ?");
            $consulta->bind_param('i', $id);
            $correcto = $consulta->execute();
            $consulta->close();
            if ($correcto) {
                unset(self::$mapas[$this->catalogo]);
                return true;
            }
        } catch (\mysqli_sql_exception $e) {
            error_log('[CatalogoTexto::eliminar] ' . $e->getMessage());
        }
        self::$errores[] = 'No fue posible eliminar la ' . $c['singular'] . '.';
        return false;
    }
}
