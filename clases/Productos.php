<?php

namespace App;

/**
 * Productos — catálogo de ambos negocios.
 *
 * `precio` es el PRECIO DE LISTA: en el cotizador aparece como sólo lectura.
 * El precio acordado con un cliente se guarda aparte en App\PreciosCliente.
 *
 * La imagen se sube únicamente con <input type="file"> (OPENCODE.md): el
 * servidor la recorta a cuadrado 1000×1000 y genera una miniatura 300×300
 * con Intervention Image.
 */
class Productos
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten (sin el id ni la fecha de alta). */
    protected static $columnasDB = [
        'id', 'empresa_id', 'nombre', 'sku', 'categoria', 'unidad', 'descripcion',
        'precio', 'costo_actual', 'stock_minimo', 'imagen', 'activo',
    ];

    /** @var string[] Mensajes de validación de la última operación. */
    protected static $errores = [];

    public $id;
    public $empresa_id;
    public $nombre;
    public $sku;
    public $categoria;
    public $unidad;
    public $descripcion;
    public $precio;
    public $costo_actual;
    public $stock_minimo;
    public $imagen;
    public $activo;
    public $creado_en;

    /** @var int[] Negocios a los que se asigna (producto_empresa); el primero es el principal. */
    public $negocios = [];

    public function __construct(array $args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->empresa_id  = isset($args['empresa_id']) ? (int) $args['empresa_id'] : 0;
        $this->nombre      = $args['nombre'] ?? '';
        $this->sku         = $args['sku'] ?? '';
        $this->categoria   = $args['categoria'] ?? '';
        $this->unidad      = $args['unidad'] ?? 'pza';
        $this->descripcion = $args['descripcion'] ?? '';
        $this->precio      = isset($args['precio']) ? (float) $args['precio'] : 0.0;
        $this->costo_actual = isset($args['costo_actual']) ? (float) $args['costo_actual'] : 0.0;
        $this->stock_minimo = isset($args['stock_minimo']) && $args['stock_minimo'] !== '' ? (int) $args['stock_minimo'] : null;
        $this->negocios    = array_values(array_unique(array_map('intval', $args['negocios'] ?? [])));
        $this->imagen      = $args['imagen'] ?? '';
        $this->activo      = isset($args['activo']) ? (int) $args['activo'] : 1;
        $this->creado_en   = $args['creado_en'] ?? '';
    }

    /* --------------------------------------------------------------------
     * CONEXIÓN
     * ------------------------------------------------------------------ */

    public static function setDB($database): void
    {
        self::$db = $database;
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

        // El SKU vacío se guarda como NULL: así el índice único admite varios sin código.
        if (trim((string) $atributos['sku']) === '') {
            $atributos['sku'] = null;
        }

        return $atributos;
    }

    /** Crea o actualiza el producto según exista o no id. */
    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    /** Inserta un producto nuevo. */
    public function crear(): bool
    {
        try {
            $atributos = $this->atributos();
            $columnas   = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO productos ($columnas) VALUES ($marcadores)"
            );

            // Los importes viajan como cadena: así MySQL aplica la precisión decimal.
            $valores = array_values($atributos);
            $tipos   = str_repeat('s', count($valores));
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                $this->id = (int) self::$db->insert_id;
                $this->sincronizarNegocios();
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Productos::crear] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar el producto.';
        return false;
    }

    /** Actualiza un producto existente. */
    public function actualizar(): bool
    {
        try {
            $atributos   = $this->atributos();
            $asignaciones = [];

            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE productos SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
            );

            $valores   = array_values($atributos);
            $valores[] = (int) $this->id;
            $tipos     = str_repeat('s', count($atributos)) . 'i';
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                $this->sincronizarNegocios();
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Productos::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el producto.';
        return false;
    }

    /**
     * Elimina el producto.
     *
     * Si ya se usó en una cotización, MySQL lo rechaza (clave foránea) y se
     * recomienda desactivarlo en lugar de borrarlo.
     */
    public function eliminar(): bool
    {
        try {
            $consulta = self::$db->prepare('DELETE FROM productos WHERE id = ?');
            $id = (int) $this->id;
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                Imagenes::eliminar($this->imagen ?: null);
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Productos::eliminar] ' . $excepcion->getMessage());
            self::$errores[] = 'No se puede eliminar: el producto ya aparece en una cotización. Desactiválo en su lugar.';
            return false;
        }

        self::$errores[] = 'No fue posible eliminar el producto.';
        return false;
    }

    /**
     * Deja producto_empresa igual a $this->negocios. La existencia no se toca:
     * sigue viviendo una sola vez en productos.stock.
     */
    private function sincronizarNegocios(): void
    {
        $id = (int) $this->id;
        $negocios = $this->negocios ?: [(int) $this->empresa_id];

        $borrar = self::$db->prepare('DELETE FROM producto_empresa WHERE producto_id = ?');
        $borrar->bind_param('i', $id);
        $borrar->execute();
        $borrar->close();

        $insertar = self::$db->prepare('INSERT INTO producto_empresa (producto_id, empresa_id) VALUES (?, ?)');
        foreach ($negocios as $empresaId) {
            $insertar->bind_param('ii', $id, $empresaId);
            $insertar->execute();
        }
        $insertar->close();
    }

    /** @return int[] Ids de los negocios asignados al producto. */
    public static function negociosDe(int $productoId): array
    {
        $consulta = self::$db->prepare('SELECT empresa_id FROM producto_empresa WHERE producto_id = ? ORDER BY empresa_id');
        $consulta->bind_param('i', $productoId);
        $consulta->execute();
        $ids = array_map('intval', array_column($consulta->get_result()->fetch_all(MYSQLI_ASSOC), 'empresa_id'));
        $consulta->close();

        return $ids;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga un producto por id. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare('SELECT * FROM productos WHERE id = ? LIMIT 1');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$registro) {
            return null;
        }

        $producto = self::crearObjeto($registro);
        $producto->negocios = self::negociosDe((int) $producto->id);

        return $producto;
    }

    /**
     * Listado paginado con búsqueda por código, nombre o descripción.
     *
     * @param int    $empresaId 0 = todas las empresas.
     * @param string $estado    'todos' | 'activos' | 'inactivos'.
     */
    public static function mostrarTodos(
        int $limite = 10,
        int $offset = 0,
        string $termino = '',
        int $empresaId = 0,
        string $estado = 'todos'
    ): array {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);

        [$donde, $tipos, $valores] = self::condiciones($termino, $empresaId, $estado);

        $consulta = self::$db->prepare(
            "SELECT * FROM productos WHERE $donde
              ORDER BY nombre ASC
              LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $tipos    .= 'ii';

        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $productos = [];
        while ($fila = $registros->fetch_assoc()) {
            $productos[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $productos;
    }

    /** Cuenta los productos que cumplen los criterios indicados. */
    public static function contarTodos(
        string $termino = '',
        int $empresaId = 0,
        string $estado = 'todos'
    ): int {
        [$donde, $tipos, $valores] = self::condiciones($termino, $empresaId, $estado);

        $consulta = self::$db->prepare("SELECT COUNT(*) AS total FROM productos WHERE $donde");
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Cláusula WHERE compartida por listado y conteo.
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condiciones(string $termino, int $empresaId, string $estado): array
    {
        // Entre paréntesis: sin ellos el OR vencería al AND de los filtros
        // de empresa/estado que se agregan abajo.
        $donde   = ['(? = "" OR IFNULL(sku, "") LIKE ? OR nombre LIKE ? OR IFNULL(categoria, "") LIKE ? OR IFNULL(descripcion, "") LIKE ?)'];
        $tipos   = 'sssss';
        $patron  = '%' . trim($termino) . '%';
        $valores = [trim($termino), $patron, $patron, $patron, $patron];

        if ($empresaId > 0) {
            $donde[] = 'EXISTS (SELECT 1 FROM producto_empresa pe WHERE pe.producto_id = productos.id AND pe.empresa_id = ?)';
            $tipos  .= 'i';
            $valores[] = $empresaId;
        }

        if ($estado === 'activos') {
            $donde[] = 'activo = 1';
        } elseif ($estado === 'inactivos') {
            $donde[] = 'activo = 0';
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Orden natural por código de producto.
     *
     * Expresión compartida por el listado en pantalla y por los PDF (inventario
     * y catálogo), para que un mismo SKU aparezca en el mismo lugar en ambos.
     *
     * Separa el código en dos partes:
     *   prefijo → la letra del código                (RA-, TM-, BB-)
     *   número  → la parte numérica                  (1, 3, 4, 10)
     *
     * Sin esa separación, el orden alfabético pondría RA-10 entre RA-1 y
     * RA-3, lo cual es inútil para buscar una pieza. Los códigos sin número
     * quedan con 0 y se resuelven con IFNULL(sku).
     *
     * Requiere los alias `p` (productos) y `e` (empresas) en la consulta.
     *
     * @return string Cláusula ORDER BY lista para usar en una consulta.
     */
    public static function ordenPorCodigo(): string
    {
        return "e.nombre ASC,
                REGEXP_REPLACE(IFNULL(p.sku, ''), '[0-9].*$', '') ASC,
                CAST(REGEXP_REPLACE(IFNULL(p.sku, ''), '^[^0-9]*', '') AS UNSIGNED) ASC,
                IFNULL(p.sku, '') ASC,
                p.nombre ASC";
    }

    /** Orden por SKU natural sin requerir el alias de empresa (para reportes planos). */
    public static function ordenPorCodigoSimple(): string
    {
        return "REGEXP_REPLACE(IFNULL(p.sku, ''), '[0-9].*$', '') ASC,
                CAST(REGEXP_REPLACE(IFNULL(p.sku, ''), '^[^0-9]*', '') AS UNSIGNED) ASC,
                IFNULL(p.sku, '') ASC, p.nombre ASC";
    }

    /**
     * Productos activos para poblar el cotizador (con su precio de lista).
     *
     * @return array<int, array{id: int, codigo: string, nombre: string, precio: float, imagen: string}>
     */
    public static function paraCotizador(int $empresaId = 0, string $termino = ''): array
    {
        [$donde, $tipos, $valores] = self::condiciones($termino, $empresaId, 'activos');

        $consulta = self::$db->prepare(
            "SELECT id, empresa_id, sku, nombre, categoria, unidad, precio, costo_actual, imagen,
                    (SELECT GROUP_CONCAT(pe.empresa_id) FROM producto_empresa pe WHERE pe.producto_id = productos.id) AS negocios
               FROM productos
              WHERE $donde ORDER BY nombre ASC LIMIT 300"
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $lista = [];
        while ($fila = $registros->fetch_assoc()) {
            $lista[] = [
                'id'        => (int) $fila['id'],
                'empresa_id'=> (int) $fila['empresa_id'],
                'empresas'  => array_map('intval', array_filter(explode(',', (string) $fila['negocios']))),
                'sku'       => $fila['sku'],
                'nombre'    => $fila['nombre'],
                'categoria' => $fila['categoria'],
                'unidad'    => $fila['unidad'],
                'precio'    => (float) $fila['precio'],
                'costo'     => (float) $fila['costo_actual'],
                'imagen'    => $fila['imagen'],
            ];
        }

        $consulta->close();
        return $lista;
    }

    /**
     * Catálogo completo agrupado por empresa: alimenta el PDF
     * "Exportar catálogo de productos".
     *
     * A diferencia de Inventario::paraPdf(), aquí NO se filtra por existencias
     * ni por estado: un catálogo es la oferta completa del negocio, incluidos
     * los productos agotados (siguen siendo parte del catálogo) y los dados de
     * baja (para poder auditar qué se dejó de vender).
     *
     * @return array<int, array{
     *   id: int, nombre: string, logo: string,
     *   productos: array<int, array{sku: string, nombre: string, precio: float, imagen: string}>,
     *   total: float
     * }>
     */
    public static function paraPdf(): array
    {
        $resultado = self::$db->query(
            'SELECT p.sku, p.nombre, p.precio, p.imagen,
                    e.id AS empresa_id, e.nombre AS empresa_nombre, e.logo AS empresa_logo
               FROM productos p
               JOIN empresas e ON e.id = p.empresa_id
              WHERE e.activa = 1
              ORDER BY ' . self::ordenPorCodigo()
        );

        $empresas = [];

        while ($fila = $resultado->fetch_assoc()) {
            $empresaId = (int) $fila['empresa_id'];

            if (!isset($empresas[$empresaId])) {
                $empresas[$empresaId] = [
                    'id'        => $empresaId,
                    'nombre'    => (string) $fila['empresa_nombre'],
                    'logo'      => (string) ($fila['empresa_logo'] ?? ''),
                    'productos' => [],
                ];
            }

            $precio = (float) $fila['precio'];

            $empresas[$empresaId]['productos'][] = [
                'sku'    => (string) ($fila['sku'] ?? ''),
                'nombre' => (string) $fila['nombre'],
                'precio' => $precio,
                'imagen' => (string) ($fila['imagen'] ?? ''),
            ];
        }

        $resultado->free();
        return array_values($empresas);
    }

    /** Convierte un registro asociativo en una instancia de esta clase. */
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

    /** Devuelve y limpia los errores acumulados. */
    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /** Comprueba los campos obligatorios del producto. */
    public function validar(): array
    {
        self::$errores = [];

        if (trim($this->nombre) === '') {
            self::$errores[] = 'El nombre del producto es obligatorio.';
        }
        if ($this->empresa_id <= 0 || empty($this->negocios)) {
            self::$errores[] = 'Debes elegir al menos un negocio para el producto.';
        }
        if ($this->stock_minimo !== null && $this->stock_minimo < 0) {
            self::$errores[] = 'La cantidad mínima no puede ser negativa.';
        }
        if (!is_numeric($this->costo_actual) || (float) $this->costo_actual < 0) {
            self::$errores[] = 'El costo actual debe ser un número mayor o igual a cero.';
        }
        if (trim((string) $this->sku) !== '') {
            $consulta = self::$db->prepare('SELECT id FROM productos WHERE sku = ? AND id <> ? LIMIT 1');
            $id = (int) $this->id;
            $consulta->bind_param('si', $this->sku, $id);
            $consulta->execute();
            if ($consulta->get_result()->num_rows > 0) {
                self::$errores[] = 'El SKU «' . $this->sku . '» ya pertenece a otro producto.';
            }
            $consulta->close();
        }
        if (!is_numeric($this->precio) || (float) $this->precio < 0) {
            self::$errores[] = 'El precio de lista debe ser un número mayor o igual a cero.';
        }

        return self::$errores;
    }
}
