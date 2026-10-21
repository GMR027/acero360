<?php

namespace App;

/**
 * CategoriasProveedor — catálogo de categorías de compra.
 *
 * Cada entrada de material (ProveedorEntradas) se clasifica con el id de una
 * fila de esta tabla. El catálogo es **uno solo para todos los proveedores**:
 * lo que se clasifica es el tipo de compra (Acero, Empaque, Servicio…), y ese
 * concepto pertenece a la empresa, no a quien nos surte. Si cada proveedor
 * tuviera sus categorías, "Acero" existiría tres veces con tres significados
 * y el filtro dejaría de servir para comparar.
 *
 * ¿Por qué una tabla y no una constante en PHP?
 *   * añadir una categoría dejó de ser un cambio de código + deploy: es un
 *     alta desde /admin/proveedores/categorias;
 *   * la lista se puede corregir (renombrar "Mano de obra" por "Mano de obra
 *     y servicios") sin tocar una sola fila de proveedor_entradas, porque lo
 *     que se guarda es el id y el nombre sólo se resuelve al pintar;
 *   * la lista ya no puede desincronizarse entre el formulario, el filtro,
 *     la tabla, la ficha y los PDF: todos leen de `mapa()`.
 *
 * El id 0 = "Sin categoría" NO es una fila: es el valor por defecto de la
 * columna y siempre es válido, aunque el catálogo esté vacío.
 */
class CategoriasProveedor
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten en la tabla categorias_proveedor. */
    protected static $columnasDB = ['id', 'nombre'];

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Etiqueta del id 0 (entradas sin clasificar y catálogo vacío). */
    public const SIN_CATEGORIA = 'Sin categoría';

    /** @var array<int, string>|null Caché id => nombre de esta petición. */
    private static $mapa = null;

    public $id;
    public $nombre;
    public $creado_en;

    /** Entradas que usan esta categoría: sólo lo calcula el listado. */
    public $usos = 0;

    public function __construct(array $args = [])
    {
        $this->id        = $args['id'] ?? null;
        $this->nombre    = $args['nombre'] ?? '';
        $this->creado_en = $args['creado_en'] ?? '';
        $this->usos      = (int) ($args['usos'] ?? 0);
    }

    /* --------------------------------------------------------------------
     * CONEXIÓN
     * ------------------------------------------------------------------ */

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /** Devuelve y limpia los errores acumulados. */
    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /* --------------------------------------------------------------------
     * EL CATÁLOGO (lo que leen el resto de clases y las vistas)
     * ------------------------------------------------------------------ */

    /**
     * Mapa id => nombre de todas las categorías, en orden alfabético.
     *
     * Se cachea por petición: el filtro del Balance general, la tabla de
     * entradas y la ficha pueden pedirlo varias veces en la misma pantalla y
     * no tiene sentido repetir la consulta.
     *
     * @return array<int, string>
     */
    public static function mapa(): array
    {
        if (self::$mapa !== null) {
            return self::$mapa;
        }

        $mapa     = [];
        $resultado = self::$db->query(
            'SELECT id, nombre FROM categorias_proveedor ORDER BY nombre ASC'
        );

        while ($fila = $resultado->fetch_assoc()) {
            $mapa[(int) $fila['id']] = (string) $fila['nombre'];
        }

        $resultado->free();
        return self::$mapa = $mapa;
    }

    /**
     * Nombre legible de un id.
     *
     * Un id desconocido (categoría borrada en otra pestaña, dato heredado)
     * cae en "Sin categoría" en lugar de imprimir un número en pantalla.
     */
    public static function nombre(int $id): string
    {
        if ($id <= 0) {
            return self::SIN_CATEGORIA;
        }

        return self::mapa()[$id] ?? self::SIN_CATEGORIA;
    }

    /**
     * Id que corresponde a un texto escrito por el usuario.
     *
     * Lo usa la búsqueda del listado de entradas: como en la tabla sólo está
     * el id, escribir "Acero" tiene que traducirse a `categoria = 1`.
     *
     * @return int 0 si el texto no corresponde a ninguna categoría.
     */
    public static function idPorNombre(string $texto): int
    {
        $buscado = strtolower(trim($texto));
        if ($buscado === '') {
            return 0;
        }

        foreach (self::mapa() as $id => $nombre) {
            if (strtolower($nombre) === $buscado) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * Cuántos registros (compras y entregas anteriores) están usando esta categoría.
     *
     * Es la barrera que impide borrar una categoría en uso: sin esta
     * comprobación, un borrado dejaría registros apuntando a un id que ya no
     * existe y se leerían como "Sin categoría".
     */
    public static function enUso(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        $consulta = self::$db->prepare(
            'SELECT (SELECT COUNT(*) FROM proveedor_entradas WHERE categoria = ?)
                  + (SELECT COUNT(*) FROM ordenes_compra WHERE categoria_id = ?) AS total'
        );
        $consulta->bind_param('ii', $id, $id);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga una categoría por su id, o null si no existe. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare(
            'SELECT * FROM categorias_proveedor WHERE id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /**
     * Listado paginado con el número de entradas que usa cada categoría.
     *
     * @return array<int, self>
     */
    public static function mostrarTodos(
        int $limite = 15,
        int $offset = 0,
        string $termino = ''
    ): array {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);

        [$donde, $tipos, $valores] = self::condiciones($termino);

        $consulta = self::$db->prepare(
            "SELECT c.*,
                    ((SELECT COUNT(*) FROM proveedor_entradas e WHERE e.categoria = c.id)
                     + (SELECT COUNT(*) FROM ordenes_compra o WHERE o.categoria_id = c.id)) AS usos
               FROM categorias_proveedor c
              WHERE $donde
              ORDER BY c.nombre ASC
              LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $tipos    .= 'ii';

        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $lista = [];
        while ($fila = $registros->fetch_assoc()) {
            $lista[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $lista;
    }

    /** Cuenta las categorías que cumplen el filtro. */
    public static function contarTodos(string $termino = ''): int
    {
        [$donde, $tipos, $valores] = self::condiciones($termino);

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS total FROM categorias_proveedor c WHERE $donde"
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Cláusula WHERE compartida por mostrarTodos() y contarTodos().
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condiciones(string $termino): array
    {
        $patron = '%' . trim($termino) . '%';
        // Entre paréntesis: sin ellos el OR de la búsqueda vencería al AND
        // de cualquier filtro que se agregue más abajo.
        return ['(? = "" OR c.nombre LIKE ?)', 'ss', [trim($termino), $patron]];
    }

    /** Construye el objeto a partir de una fila de la base. */
    protected static function crearObjeto(array $registro): self
    {
        $objeto = new self();

        foreach ($registro as $clave => $valor) {
            if (property_exists($objeto, $clave)) {
                $objeto->$clave = $valor;
            }
        }

        return $objeto;
    }

    /* --------------------------------------------------------------------
     * VALIDACIÓN
     * ------------------------------------------------------------------ */

    public function validar(): array
    {
        self::$errores = [];

        $nombre = trim((string) $this->nombre);
        if ($nombre === '') {
            self::$errores[] = 'El nombre de la categoría es obligatorio.';
        } elseif (mb_strlen($nombre) > 80) {
            self::$errores[] = 'El nombre de la categoría no puede pasar de 80 caracteres.';
        } else {
            // Duplicados: la clave única de la base los impediría, pero un
            // error crudo de MySQL no le diría al usuario qué nombre chocó.
            // $existe es 0 cuando NO hay duplicado (no es null), así que hay
            // que descartarlo explícitamente: con "no nulo" cualquiera que
            // editara sin cambiar el nombre recibiría el error igual.
            $existe = $this->buscarPorNombre($nombre);
            if ($existe > 0 && $existe !== (int) $this->id) {
                self::$errores[] = 'Ya existe una categoría llamada «' . $nombre . '».';
            }
        }

        $this->nombre = $nombre;
        return self::$errores;
    }

    /** Id de otra categoría que ya usa ese nombre (0 si no hay ninguna). */
    private function buscarPorNombre(string $nombre): int
    {
        $consulta = self::$db->prepare(
            'SELECT id FROM categorias_proveedor WHERE nombre = ? LIMIT 1'
        );
        $consulta->bind_param('s', $nombre);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? (int) $fila['id'] : 0;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

    /** Devuelve los atributos persistibles, sin el id. */
    public function atributos(): array
    {
        $atributos = [];

        foreach (self::$columnasDB as $columna) {
            if ($columna === 'id') {
                continue;
            }
            $atributos[$columna] = $this->$columna;
        }

        return $atributos;
    }

    /** Crea o actualiza la categoría según exista o no id. */
    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    /** Inserta una categoría nueva. */
    public function crear(): bool
    {
        try {
            $atributos  = $this->atributos();
            $columnas   = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO categorias_proveedor ($columnas) VALUES ($marcadores)"
            );

            $valores = array_values($atributos);
            $tipos   = str_repeat('s', count($valores));
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                $this->id = (int) self::$db->insert_id;
                self::$mapa = null; // La caché ya no refleja el catálogo.
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[CategoriasProveedor::crear] ' . $excepcion->getMessage());
            if ($excepcion->getCode() == 1062) {
                self::$errores[] = 'Ya existe una categoría con ese nombre.';
                return false;
            }
        }

        self::$errores[] = 'No fue posible guardar la categoría.';
        return false;
    }

    /** Actualiza una categoría existente (el id no cambia). */
    public function actualizar(): bool
    {
        try {
            $atributos     = $this->atributos();
            $asignaciones  = [];

            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE categorias_proveedor SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
            );

            $valores   = array_values($atributos);
            $valores[] = (int) $this->id;
            $tipos     = str_repeat('s', count($atributos)) . 'i';
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                self::$mapa = null;
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[CategoriasProveedor::actualizar] ' . $excepcion->getMessage());
            if ($excepcion->getCode() == 1062) {
                self::$errores[] = 'Ya existe otra categoría con ese nombre.';
                return false;
            }
        }

        self::$errores[] = 'No fue posible actualizar la categoría.';
        return false;
    }

    /**
     * Elimina la categoría, pero sólo si ninguna entrada la está usando.
     *
     * No hay clave foránea (la columna admite el 0, que no es una fila de
     * esta tabla), así que esta comprobación ES la protección: borrar una
     * categoría en uso dejaría las entradas apuntando a un id inexistente y
     * se leerían como "Sin categoría".
     */
    public function eliminar(): bool
    {
        $usos = self::enUso((int) $this->id);

        if ($usos > 0) {
            self::$errores[] = 'No se puede eliminar «' . $this->nombre . '»: la usan '
                . number_format($usos) . ($usos === 1 ? ' entrada' : ' entradas')
                . '. Edítala o libera esas entradas antes de borrarla.';
            return false;
        }

        try {
            $consulta = self::$db->prepare('DELETE FROM categorias_proveedor WHERE id = ?');
            $id = (int) $this->id;
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                self::$mapa = null;
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[CategoriasProveedor::eliminar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible eliminar la categoría.';
        return false;
    }
}
