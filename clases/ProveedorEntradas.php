<?php

namespace App;

/**
 * ProveedorEntradas — reporte fotográfico de cada entrega de un proveedor.
 *
 * Es el documento que guarda la entrada de material: encabezado (datos del
 * proveedor, tipo, número de pedido y categoría), descripción, fotografía
 * de la entrega y notas adicionales.
 *
 * Los datos del proveedor no se repiten aquí: se leen de Proveedores,
 * evitando capturar dos veces el mismo RFC o teléfono.
 */
class ProveedorEntradas
{
    /*
     * --------------------------------------------------------------------
     * CATEGORÍAS
     * ------------------------------------------------------------------
     * El catálogo NO vive en esta clase: está en la tabla
     * `categorias_proveedor` y se gestiona desde
     * /admin/proveedores/categorias (ver CategoriasProveedor).
     *
     * Esta clase sólo conserva dos delegaciones de lectura
     * (nombreCategoria() e idPorNombre()) porque es ella la que guarda el
     * id en la columna `categoria`: así quien pinte una entrada no tiene
     * que acordarse de qué clase consultar.
     *
     * Guardar el id (y no el texto) hace que filtrar sea `categoria = 1`
     * en vez de `LIKE '%acero%'`, y que renombrar una categoría sea un
     * `UPDATE` de una fila y no de cientos.
     */

    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten en la tabla proveedor_entradas. */
    protected static $columnasDB = [
        'id', 'proveedor_id', 'fecha', 'numero_pedido', 'categoria',
        'monto', 'pagado', 'descripcion', 'foto', 'notas', 'usuario_id',
    ];

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Última entrada creada (para redirigir al detalle). */
    public static $ultimoId = 0;

    public $id;
    public $proveedor_id;
    public $fecha;
    public $numero_pedido;
    public $categoria;
    public $monto;
    public $pagado;
    public $descripcion;
    public $foto;
    public $notas;
    public $usuario_id;
    public $creado_en;

    /* --- Datos de unión (sólo lectura, vienen del JOIN) ------------------ */
    public $proveedor_nombre;
    public $proveedor_rfc;
    public $proveedor_telefono;
    public $proveedor_ubicacion;
    public $proveedor_tipo;
    public $usuario_nombre;

    public function __construct(array $args = [])
    {
        $this->id            = $args['id'] ?? null;
        $this->proveedor_id  = isset($args['proveedor_id']) ? (int) $args['proveedor_id'] : 0;
        $this->fecha         = $args['fecha'] ?? date('Y-m-d');
        $this->numero_pedido = $args['numero_pedido'] ?? '';
        // Entero: la categoría apunta a categorias_proveedor (0 = sin
        // cast evita que '' llegue como cadena a una columna TINYINT.
        $this->categoria     = isset($args['categoria']) ? (int) $args['categoria'] : 0;
        // Siempre numérico: '' o null caerían en una columna DECIMAL estricta.
        $this->monto         = isset($args['monto']) ? (float) $args['monto'] : 0.0;
        $this->pagado        = empty($args['pagado']) ? 0 : 1;
        $this->descripcion   = $args['descripcion'] ?? '';
        $this->foto          = $args['foto'] ?? '';
        $this->notas         = $args['notas'] ?? '';
        $this->usuario_id    = isset($args['usuario_id']) ? (int) $args['usuario_id'] : 0;
        $this->creado_en     = $args['creado_en'] ?? '';
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

    /** Crea o actualiza la entrada según exista o no id. */
    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    /** Inserta la entrada. */
    public function crear(): bool
    {
        try {
            $atributos = $this->atributos();
            $columnas  = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO proveedor_entradas ($columnas) VALUES ($marcadores)"
            );

            $valores = array_values($atributos);
            $tipos   = str_repeat('s', count($valores));
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                $this->id = (int) self::$db->insert_id;
                self::$ultimoId = $this->id;
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[ProveedorEntradas::crear] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar la entrada del proveedor.';
        return false;
    }

    /** Actualiza la entrada. */
    public function actualizar(): bool
    {
        try {
            $atributos = $this->atributos();
            $asignaciones = [];

            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE proveedor_entradas SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
            );

            $valores   = array_values($atributos);
            $valores[] = (int) $this->id;
            $tipos     = str_repeat('s', count($atributos)) . 'i';
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[ProveedorEntradas::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar la entrada.';
        return false;
    }

    /**
     * Elimina la entrada.
     *
     * @param string $fotoAnterior Foto que debe borrarse del disco tras
     *                             confirmar la escritura.
     */
    public function eliminar(string $fotoAnterior = ''): bool
    {
        try {
            $consulta = self::$db->prepare('DELETE FROM proveedor_entradas WHERE id = ?');
            $id = (int) $this->id;
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                Imagenes::eliminar($fotoAnterior !== '' ? $fotoAnterior : ($this->foto ?: null));
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[ProveedorEntradas::eliminar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible eliminar la entrada.';
        return false;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** JOIN compartido por las lecturas. */
    private const JOIN_COMPLETO = '
        FROM proveedor_entradas e
        JOIN proveedores p ON p.id = e.proveedor_id
        LEFT JOIN usuarios u ON u.id = e.usuario_id';

    /** Columnas del JOIN compartido. */
    private const CAMPOS_COMPLETOS = '
        e.*,
        p.nombre         AS proveedor_nombre,
        p.rfc            AS proveedor_rfc,
        p.telefono       AS proveedor_telefono,
        p.ubicacion      AS proveedor_ubicacion,
        p.tipo_proveedor AS proveedor_tipo,
        u.nombre         AS usuario_nombre';

    /** Carga una entrada por su id, o null si no existe. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare(
            'SELECT ' . self::CAMPOS_COMPLETOS . ' ' . self::JOIN_COMPLETO . '
              WHERE e.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /**
     * Listado paginado.
     *
     * Filtros: q (pedido, categoría o proveedor), proveedor, desde, hasta.
     *
     * @return array<int, self>
     */
    public static function mostrarTodos(array $filtros = [], int $limite = 15, int $offset = 0): array
    {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);

        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT ' . self::CAMPOS_COMPLETOS . ' ' . self::JOIN_COMPLETO . "
              WHERE $donde
              ORDER BY e.fecha DESC, e.id DESC
              LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $tipos    .= 'ii';

        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $entradas = [];
        while ($fila = $registros->fetch_assoc()) {
            $entradas[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $entradas;
    }

    /** Cuenta las entradas que cumplen los filtros. */
    public static function contarTodos(array $filtros = []): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total ' . self::JOIN_COMPLETO . " WHERE $donde"
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /* --------------------------------------------------------------------
     * BALANCE DE PAGOS (sección "Balance general" y contador de pendientes)
     * ------------------------------------------------------------------ */

    /**
     * Suma de montos de las entradas que cumplen los filtros.
     *
     * @param bool $soloPendientes true = sólo los pedidos sin pagar (lo que
     *                             todavía se le debe al proveedor).
     */
    public static function sumarMonto(array $filtros = [], bool $soloPendientes = false): float
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT COALESCE(SUM(e.monto), 0) AS total ' . self::JOIN_COMPLETO . " WHERE $donde"
            . ($soloPendientes ? ' AND e.pagado = 0' : '')
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $total = (float) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return round($total, 2);
    }

    /**
     * Cuadro completo de pagos de un conjunto de entradas.
     *
     * Devuelve general / pagado / pendiente en UNA sola consulta: calcularlos
     * por separado abriría la puerta a que los tres números no cuadren entre
     * sí (uno leído antes de una escritura y otro después).
     *
     * @return array{
     *   entradas: int, pendientes: int, total: float,
     *   pagado: float, pendiente: float
     * }
     */
    public static function balance(array $filtros = []): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS entradas,
                    COALESCE(SUM(CASE WHEN e.pagado = 0 THEN 1 ELSE 0 END), 0) AS pendientes,
                    COALESCE(SUM(e.monto), 0) AS total,
                    COALESCE(SUM(CASE WHEN e.pagado = 1 THEN e.monto ELSE 0 END), 0) AS pagado '
            . self::JOIN_COMPLETO . " WHERE $donde"
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        $total    = round((float) $fila['total'], 2);
        $pagado   = round((float) $fila['pagado'], 2);

        return [
            'entradas'  => (int) $fila['entradas'],
            'pendientes'=> (int) $fila['pendientes'],
            'total'     => $total,
            'pagado'    => $pagado,
            'pendiente' => round($total - $pagado, 2),
        ];
    }

    /**
     * Marca o desmarca una entrada como pagada.
     *
     * Se manda el estado deseado (y no un "toggle" ciego) para que dos
     * pantallas abiertas a la vez no se pongan en contrario: la segunda
     * escritura lleva a la misma verdad que la primera.
     */
    public static function alternarPagado(int $id, bool $pagado): bool
    {
        try {
            $idBd     = max(1, $id);
            $pagadoBd = $pagado ? 1 : 0;

            $consulta = self::$db->prepare(
                'UPDATE proveedor_entradas SET pagado = ? WHERE id = ?'
            );
            $consulta->bind_param('ii', $pagadoBd, $idBd);
            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[ProveedorEntradas::alternarPagado] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el estado de pago.';
        return false;
    }

    /**
     * Entradas de un año, agrupadas por proveedor: alimenta el PDF anual.
     *
     * @param int $anio Año calendario de las entregas.
     * @return array<int, array{
     *   id: int, nombre: string, rfc: string, telefono: string,
     *   ubicacion: string, tipo: string,
     *   entradas: array<int, array>,
     *   total: float, pagado: float, pendiente: float
     * }>
     */
    public static function paraPdf(int $anio): array
    {
        $consulta = self::$db->prepare(
            'SELECT e.id, e.fecha, e.numero_pedido, e.categoria, e.monto, e.pagado,
                    p.id AS proveedor_id, p.nombre AS proveedor_nombre, p.rfc AS proveedor_rfc,
                    p.telefono AS proveedor_telefono, p.ubicacion AS proveedor_ubicacion,
                    p.tipo_proveedor AS proveedor_tipo
               FROM proveedor_entradas e
               JOIN proveedores p ON p.id = e.proveedor_id
              WHERE YEAR(e.fecha) = ?
              ORDER BY p.nombre ASC, e.fecha ASC, e.id ASC'
        );
        $consulta->bind_param('i', $anio);
        $consulta->execute();
        $registros = $consulta->get_result();

        $secciones = [];
        $indice    = [];

        while ($fila = $registros->fetch_assoc()) {
            $proveedorId = (int) $fila['proveedor_id'];

            if (!isset($indice[$proveedorId])) {
                $indice[$proveedorId] = count($secciones);
                $secciones[] = [
                    'id'        => $proveedorId,
                    'nombre'    => (string) $fila['proveedor_nombre'],
                    'rfc'       => (string) ($fila['proveedor_rfc'] ?? ''),
                    'telefono'  => (string) ($fila['proveedor_telefono'] ?? ''),
                    'ubicacion' => (string) ($fila['proveedor_ubicacion'] ?? ''),
                    'tipo'      => (string) $fila['proveedor_tipo'],
                    'entradas'  => [],
                    'total'     => 0.0,
                    'pagado'    => 0.0,
                    'pendiente' => 0.0,
                ];
            }

            $monto   = round((float) $fila['monto'], 2);
            $estaPag = (int) $fila['pagado'] === 1;
            $pos     = $indice[$proveedorId];

            $secciones[$pos]['entradas'][] = [
                'fecha'         => (string) $fila['fecha'],
                'numero_pedido' => (string) ($fila['numero_pedido'] ?? ''),
                'categoria'     => self::nombreCategoria((int) $fila['categoria']),
                'monto'         => $monto,
                'pagado'        => $estaPag,
            ];

            $secciones[$pos]['total'] = round($secciones[$pos]['total'] + $monto, 2);
            if ($estaPag) {
                $secciones[$pos]['pagado'] = round($secciones[$pos]['pagado'] + $monto, 2);
            }
            $secciones[$pos]['pendiente'] = round($secciones[$pos]['total'] - $secciones[$pos]['pagado'], 2);
        }

        $consulta->close();
        return $secciones;
    }

    /**
     * Cláusula WHERE compartida por mostrarTodos() y contarTodos().
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condiciones(array $filtros): array
    {
        $termino    = trim((string) ($filtros['q'] ?? ''));
        $proveedorId = (int) ($filtros['proveedor'] ?? 0);
        $categoriaId = (int) ($filtros['categoria'] ?? 0);
        $desde      = trim((string) ($filtros['desde'] ?? ''));
        $hasta      = trim((string) ($filtros['hasta'] ?? ''));

        $patron  = '%' . $termino . '%';

        /* Términos que se prueban dentro del mismo grupo (unión con OR). */
        $busqueda       = ['p.nombre LIKE ?', 'IFNULL(e.numero_pedido, "") LIKE ?'];
        $tiposBusqueda  = 'ss';
        $valoresBusqueda = [$patron, $patron];

        // La categoría ya no es texto en la tabla: guarda un id. Si lo
        // tecleado coincide con un nombre de la lista se busca por id y, si
        // es un número, por id directo. Así "Acero" sigue encontrando filas.
        $categoriaBuscada = self::idPorNombre($termino);
        if ($categoriaBuscada > 0) {
            $busqueda[]       = 'e.categoria = ?';
            $tiposBusqueda   .= 'i';
            $valoresBusqueda[] = $categoriaBuscada;
        } elseif (ctype_digit($termino)) {
            $busqueda[]       = 'e.categoria = ?';
            $tiposBusqueda   .= 'i';
            $valoresBusqueda[] = (int) $termino;
        }

        // Entre paréntesis: sin ellos el OR vencería al AND de los filtros
        // de proveedor, categoría y fechas que se agregan abajo.
        $donde   = ['(? = "" OR ' . implode(' OR ', $busqueda) . ')'];
        $tipos   = 's' . $tiposBusqueda;
        $valores = array_merge([$termino], $valoresBusqueda);

        if ($proveedorId > 0) {
            $donde[]   = 'e.proveedor_id = ?';
            $tipos    .= 'i';
            $valores[] = $proveedorId;
        }
        if ($categoriaId > 0) {
            $donde[]   = 'e.categoria = ?';
            $tipos    .= 'i';
            $valores[] = $categoriaId;
        }
        if ($desde !== '') {
            $donde[]   = 'e.fecha >= ?';
            $tipos    .= 's';
            $valores[] = $desde;
        }
        if ($hasta !== '') {
            $donde[]   = 'e.fecha <= ?';
            $tipos    .= 's';
            $valores[] = $hasta;
        }

        return [implode(' AND ', $donde), $tipos, $valores];
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

    /**
     * Valida los datos de la entrada.
     *
     * @return string[] Lista vacía cuando todo está correcto.
     */
    public function validar(): array
    {
        self::$errores = [];

        if ($this->proveedor_id <= 0) {
            self::$errores[] = 'Debes elegir el proveedor de esta entrada.';
        }
        if (trim($this->fecha) === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($this->fecha))) {
            self::$errores[] = 'La fecha de la entrega no es válida.';
        }
        if (trim($this->descripcion) === '') {
            self::$errores[] = 'Describe los materiales que se recibieron.';
        }
        if ($this->monto < 0) {
            self::$errores[] = 'El monto no puede ser negativo.';
        }
        // Sólo los ids del catálogo: un valor inventado no tendría etiqueta
        // en pantalla ni coincidiría con ningún filtro. El 0 (sin categoría)
        // es válido siempre, aunque el catálogo esté vacío.
        $categoria = (int) $this->categoria;
        if ($categoria !== 0 && !isset(CategoriasProveedor::mapa()[$categoria])) {
            self::$errores[] = 'La categoría seleccionada no es válida.';
        }
        $this->categoria = $categoria;

        return self::$errores;
    }

    /**
     * Etiqueta legible de una categoría.
     *
     * @param int $id Identificador guardado en la base (0 = sin categoría).
     */
    public static function nombreCategoria(int $id): string
    {
        return CategoriasProveedor::nombre($id);
    }

    /**
     * Id que corresponde a un texto escrito por el usuario.
     *
     * Se usa en la búsqueda: al teclear "Acero" en el buscador de entradas
     * hay que comparar contra el **id** guardado, no contra el texto (que ya
     * no existe en la tabla). Comparación sin distinguir mayúsculas.
     *
     * @return int 0 si el texto no corresponde a ninguna categoría.
     */
    public static function idPorNombre(string $texto): int
    {
        return CategoriasProveedor::idPorNombre($texto);
    }
}
