<?php

namespace App;

/**
 * Proveedores — directorio de quién nos surte material y producto.
 *
 * Los datos fiscales (nombre, RFC, teléfono, ubicación y tipo) se capturan
 * UNA sola vez aquí; cada entrega que haga ese proveedor se registra como
 * una fila en ProveedorEntradas, que es la que guarda número de pedido,
 * categoría, descripción, fotografía y notas.
 */
class Proveedores
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten en la tabla proveedores. */
    protected static $columnasDB = [
        'id', 'nombre', 'rfc', 'telefono', 'ubicacion', 'tipo_proveedor',
    ];

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Tipos de proveedor admitidos (select del formulario). */
    public const TIPOS = ['Material', 'Producto', 'Servicio', 'Otro'];

    public $id;
    public $nombre;
    public $rfc;
    public $telefono;
    public $ubicacion;
    public $tipo_proveedor;
    public $creado_en;

    public function __construct(array $args = [])
    {
        $this->id             = $args['id'] ?? null;
        $this->nombre         = $args['nombre'] ?? '';
        $this->rfc            = $args['rfc'] ?? '';
        $this->telefono       = $args['telefono'] ?? '';
        $this->ubicacion      = $args['ubicacion'] ?? '';
        $this->tipo_proveedor = $args['tipo_proveedor'] ?? self::TIPOS[0];
        $this->creado_en      = $args['creado_en'] ?? '';
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

    /** Crea o actualiza el registro según exista o no id. */
    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    /** Inserta un proveedor nuevo. */
    public function crear(): bool
    {
        try {
            $atributos = $this->atributos();
            $columnas  = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO proveedores ($columnas) VALUES ($marcadores)"
            );

            $valores = array_values($atributos);
            $tipos   = str_repeat('s', count($valores));
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                $this->id = (int) self::$db->insert_id;
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Proveedores::crear] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar el proveedor.';
        return false;
    }

    /** Actualiza un proveedor existente. */
    public function actualizar(): bool
    {
        try {
            $atributos = $this->atributos();
            $asignaciones = [];

            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE proveedores SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
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
            error_log('[Proveedores::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el proveedor.';
        return false;
    }

    /**
     * Elimina el proveedor.
     *
     * Sus entradas caen en cascada: el modal de confirmación lo advierte
     * antes de que el usuario acepte.
     */
    public function eliminar(): bool
    {
        try {
            $consulta = self::$db->prepare('DELETE FROM proveedores WHERE id = ?');
            $id = (int) $this->id;
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Proveedores::eliminar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible eliminar el proveedor.';
            return false;
        }

        self::$errores[] = 'No fue posible eliminar el proveedor.';
        return false;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga un proveedor por su id, o null si no existe. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare('SELECT * FROM proveedores WHERE id = ? LIMIT 1');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /**
     * Listado paginado.
     *
     * Filtros: q (nombre, RFC, ubicación) y tipo.
     *
     * @return array<int, self>
     */
    public static function mostrarTodos(
        int $limite = 10,
        int $offset = 0,
        string $termino = '',
        string $tipo = ''
    ): array {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);

        [$donde, $tipos, $valores] = self::condiciones($termino, $tipo);

        $consulta = self::$db->prepare(
            "SELECT * FROM proveedores WHERE $donde
              ORDER BY nombre ASC
              LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $tipos    .= 'ii';

        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $proveedores = [];
        while ($fila = $registros->fetch_assoc()) {
            $proveedores[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $proveedores;
    }

    /** Cuenta los proveedores que cumplen los filtros. */
    public static function contarTodos(string $termino = '', string $tipo = ''): int
    {
        [$donde, $tipos, $valores] = self::condiciones($termino, $tipo);

        $consulta = self::$db->prepare("SELECT COUNT(*) AS total FROM proveedores WHERE $donde");
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
    private static function condiciones(string $termino, string $tipo): array
    {
        $patron  = '%' . trim($termino) . '%';
        // Entre paréntesis: sin ellos el OR vencería al AND del filtro de tipo.
        $donde   = ['(? = "" OR nombre LIKE ? OR IFNULL(rfc, "") LIKE ? OR IFNULL(ubicacion, "") LIKE ?)'];
        $tipos   = 'ssss';
        $valores = [trim($termino), $patron, $patron, $patron];

        if ($tipo !== '') {
            $donde[]   = 'tipo_proveedor = ?';
            $tipos    .= 's';
            $valores[] = $tipo;
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Selector para poblar un <select>.
     *
     * @return array<int, array{id: int, etiqueta: string}>
     */
    public static function paraSelector(): array
    {
        $resultado = self::$db->query(
            'SELECT id, nombre, rfc, tipo_proveedor FROM proveedores ORDER BY nombre ASC'
        );

        $lista = [];
        while ($fila = $resultado->fetch_assoc()) {
            $lista[] = [
                'id'       => (int) $fila['id'],
                'etiqueta' => $fila['nombre'] . ($fila['rfc'] ? ' · ' . $fila['rfc'] : ''),
            ];
        }

        $resultado->free();
        return $lista;
    }

    /** Número de entradas registradas por este proveedor. */
    public function contarEntradas(): int
    {
        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM proveedor_entradas WHERE proveedor_id = ?'
        );
        $id = (int) $this->id;
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
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

    /** Comprueba que los campos obligatorios tengan contenido. */
    public function validar(): array
    {
        self::$errores = [];

        if (trim($this->nombre) === '') {
            self::$errores[] = 'El nombre del proveedor es obligatorio.';
        }
        if (!in_array($this->tipo_proveedor, self::TIPOS, true)) {
            self::$errores[] = 'El tipo de proveedor no es válido.';
        }

        return self::$errores;
    }
}
