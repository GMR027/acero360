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
 *
 * Las categorías de producto además tienen datos para el sitio público
 * (migración 053, config 'web' => true): slug de la URL, descripción, portada
 * y orden. El slug se genera al crear y NO cambia al renombrar (los enlaces
 * publicados siguen funcionando); se puede editar a mano.
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
            'web'        => true,
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
            'web'        => false,
        ],
    ];

    public $id;
    public $nombre;
    public $creado_en;
    public $usos = 0;

    /* Sitio público (sólo catálogos con 'web' => true) */
    public $slug = '';
    public $descripcion = '';
    public $imagen = '';
    public $orden = 0;

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
        $this->slug        = (string) ($args['slug'] ?? '');
        $this->descripcion = (string) ($args['descripcion'] ?? '');
        $this->imagen      = (string) ($args['imagen'] ?? '');
        $this->orden       = (int) ($args['orden'] ?? 0);
    }

    /** ¿El catálogo tiene datos para el sitio público? */
    public static function esWeb(string $catalogo): bool
    {
        return !empty(self::config($catalogo)['web']);
    }

    /** Texto apto para URL: minúsculas, sin acentos, guiones. */
    public static function slugificar(string $texto): string
    {
        $texto = strtr(mb_strtolower(trim($texto)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        $texto = $ascii !== false ? strtolower($ascii) : $texto;
        return trim(preg_replace('/[^a-z0-9]+/', '-', $texto), '-');
    }

    /** ¿Otro registro ya usa ese slug? */
    private function slugOcupado(string $slug): bool
    {
        $c = self::config($this->catalogo);
        $id = (int) $this->id;
        $consulta = self::$db->prepare("SELECT id FROM {$c['tabla']} WHERE slug = ? AND id <> ? LIMIT 1");
        $consulta->bind_param('si', $slug, $id);
        $consulta->execute();
        $ocupado = (bool) $consulta->get_result()->fetch_assoc();
        $consulta->close();
        return $ocupado;
    }

    /** Slug libre a partir del nombre: base, base-2, base-3... */
    private function slugDisponible(string $base): string
    {
        $base = $base !== '' ? mb_substr($base, 0, 80) : 'categoria';
        $slug = $base;
        for ($n = 2; $this->slugOcupado($slug); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
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

        if (self::esWeb($this->catalogo)) {
            $this->descripcion = trim(preg_replace('/\s+/u', ' ', (string) $this->descripcion));
            if (mb_strlen($this->descripcion) > 255) {
                self::$errores[] = 'La descripción para el sitio no puede pasar de 255 caracteres.';
            }
            $this->orden = max(0, min(9999, (int) $this->orden));

            $this->slug = trim((string) $this->slug);
            if ($this->slug !== '') {
                $this->slug = self::slugificar($this->slug);
                if ($this->slug === '' || strlen($this->slug) > 90) {
                    self::$errores[] = 'La dirección web sólo admite letras, números y guiones (máximo 90).';
                } elseif ($this->slugOcupado($this->slug)) {
                    self::$errores[] = 'Ya hay otra ' . $c['singular'] . ' con la dirección web «' . $this->slug . '».';
                }
            }
        }

        return self::$errores;
    }

    /** Portada reemplazada o quitada: se borra del disco sólo si se guardó bien. */
    private $imagenAnterior = '';

    /** Portada recién subida: se borra si al final no se guarda. */
    private $imagenNueva = '';

    /**
     * Toma del formulario los campos del sitio público (descripción, orden,
     * dirección web y portada). Devuelve errores de la imagen, si los hay.
     */
    public function tomarFormularioWeb(array $post, array $archivos): array
    {
        if (!self::esWeb($this->catalogo)) {
            return [];
        }

        $this->descripcion = (string) ($post['descripcion'] ?? '');
        $this->orden = (int) ($post['orden'] ?? 0);
        $this->slug = (string) ($post['slug'] ?? $this->slug);

        if (!empty($post['quitar_imagen']) && $this->imagen !== '') {
            $this->imagenAnterior = $this->imagen;
            $this->imagen = '';
        }

        $archivo = $archivos['imagen'] ?? null;
        if (is_array($archivo) && !empty($archivo['tmp_name'])) {
            $nueva = Imagenes::subir($archivo, 'categoria', 'cuadrada');
            if ($nueva === null) {
                return ['Portada: ' . Imagenes::ultimoError()];
            }
            if ($this->imagen !== '' && $this->imagenAnterior === '') {
                $this->imagenAnterior = $this->imagen;
            }
            $this->imagen = $this->imagenNueva = $nueva;
        }

        return [];
    }

    /** Después de intentar guardar: limpia la portada que ya no se usa. */
    public function cerrarFormularioWeb(bool $guardado): void
    {
        if ($guardado && $this->imagenAnterior !== '') {
            Imagenes::eliminar($this->imagenAnterior);
        }
        if (!$guardado && $this->imagenNueva !== '') {
            Imagenes::eliminar($this->imagenNueva);
            $this->imagen = $this->imagenAnterior;
        }
        $this->imagenAnterior = $this->imagenNueva = '';
    }

    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    public function crear(): bool
    {
        $c = self::config($this->catalogo);
        try {
            if (self::esWeb($this->catalogo)) {
                if ($this->slug === '') {
                    $this->slug = $this->slugDisponible(self::slugificar($this->nombre));
                }
                $descripcion = $this->descripcion !== '' ? $this->descripcion : null;
                $imagen = $this->imagen !== '' ? $this->imagen : null;
                $orden = (int) $this->orden;
                $consulta = self::$db->prepare(
                    "INSERT INTO {$c['tabla']} (nombre, slug, descripcion, imagen, orden) VALUES (?, ?, ?, ?, ?)"
                );
                $consulta->bind_param('ssssi', $this->nombre, $this->slug, $descripcion, $imagen, $orden);
            } else {
                $consulta = self::$db->prepare("INSERT INTO {$c['tabla']} (nombre) VALUES (?)");
                $consulta->bind_param('s', $this->nombre);
            }
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
            if (self::esWeb($this->catalogo)) {
                // El slug no se recalcula con el nombre: sólo cambia si se edita a mano.
                if ($this->slug === '') {
                    $this->slug = $this->slugDisponible(self::slugificar($this->nombre));
                }
                $descripcion = $this->descripcion !== '' ? $this->descripcion : null;
                $imagen = $this->imagen !== '' ? $this->imagen : null;
                $orden = (int) $this->orden;
                $consulta = self::$db->prepare(
                    "UPDATE {$c['tabla']} SET nombre = ?, slug = ?, descripcion = ?, imagen = ?, orden = ? WHERE id = ?"
                );
                $consulta->bind_param('ssssii', $this->nombre, $this->slug, $descripcion, $imagen, $orden, $id);
            } else {
                $consulta = self::$db->prepare("UPDATE {$c['tabla']} SET nombre = ? WHERE id = ?");
                $consulta->bind_param('si', $this->nombre, $id);
            }
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
                if (self::esWeb($this->catalogo) && $this->imagen !== '') {
                    Imagenes::eliminar($this->imagen);
                }
                return true;
            }
        } catch (\mysqli_sql_exception $e) {
            error_log('[CatalogoTexto::eliminar] ' . $e->getMessage());
        }
        self::$errores[] = 'No fue posible eliminar la ' . $c['singular'] . '.';
        return false;
    }
}
