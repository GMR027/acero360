<?php

namespace App;

/**
 * Empresas — los dos negocios que emiten cotizaciones.
 *
 * Son los datos fiscales que OPENCODE.md pide completar desde el CRUD:
 * RFC, régimen, domicilio, código postal y logo. Cada cotización se emite
 * con una de estas empresas y con sus condiciones de envío/compra por
 * defecto.
 */
class Empresas
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten (sin el id ni la fecha de alta). */
    protected static $columnasDB = [
        'id', 'nombre', 'rfc', 'regimen', 'direccion', 'ciudad', 'estado', 'cp',
        'telefono', 'email', 'logo', 'condiciones_envio', 'condiciones_compra', 'activa', 'logo_alto_pdf',
    ];

    /** @var string[] Mensajes de validación de la última operación. */
    protected static $errores = [];

    public $id;
    public $nombre;
    public $rfc;
    public $regimen;
    public $direccion;
    public $ciudad;
    public $estado;
    public $cp;
    public $telefono;
    public $email;
    public $logo;
    public $condiciones_envio;
    public $condiciones_compra;
    public $activa;
    public $logo_alto_pdf;
    public $creado_en;

    public function __construct(array $args = [])
    {
        $this->id                 = $args['id'] ?? null;
        $this->nombre             = $args['nombre'] ?? '';
        $this->rfc                = $args['rfc'] ?? '';
        $this->regimen            = $args['regimen'] ?? '';
        $this->direccion          = $args['direccion'] ?? '';
        $this->ciudad             = $args['ciudad'] ?? '';
        $this->estado             = $args['estado'] ?? '';
        $this->cp                 = $args['cp'] ?? '';
        $this->telefono           = $args['telefono'] ?? '';
        $this->email              = $args['email'] ?? '';
        $this->logo               = $args['logo'] ?? '';
        $this->condiciones_envio  = $args['condiciones_envio'] ?? '';
        $this->condiciones_compra = $args['condiciones_compra'] ?? '';
        $this->activa             = isset($args['activa']) ? (int) $args['activa'] : 1;
        $this->logo_alto_pdf      = isset($args['logo_alto_pdf']) ? (int) $args['logo_alto_pdf'] : Imagenes::LOGO_PDF_ALTO;
        $this->creado_en          = $args['creado_en'] ?? '';
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

        return $atributos;
    }

    /** Crea o actualiza la empresa según exista o no id. */
    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    /** Inserta una empresa nueva. */
    public function crear(): bool
    {
        try {
            $atributos = $this->atributos();
            $columnas   = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO empresas ($columnas) VALUES ($marcadores)"
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
            error_log('[Empresas::crear] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar la empresa.';
        return false;
    }

    /** Actualiza una empresa existente. */
    public function actualizar(): bool
    {
        try {
            $atributos = $this->atributos();
            $asignaciones = [];

            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE empresas SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
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
            error_log('[Empresas::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar la empresa.';
        return false;
    }

    /**
     * Elimina la empresa.
     * MySQL lo rechaza si ya tiene productos o cotizaciones asociados.
     */
    public function eliminar(): bool
    {
        try {
            $consulta = self::$db->prepare('DELETE FROM empresas WHERE id = ?');
            $id = (int) $this->id;
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                Imagenes::eliminar($this->logo ?: null);
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Empresas::eliminar] ' . $excepcion->getMessage());
            self::$errores[] = 'No se puede eliminar: el negocio ya tiene productos o cotizaciones registrados.';
            return false;
        }

        self::$errores[] = 'No fue posible eliminar la empresa.';
        return false;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga una empresa por id. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare('SELECT * FROM empresas WHERE id = ? LIMIT 1');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /** Listado paginado con búsqueda por nombre, RFC o ciudad. */
    public static function mostrarTodos(int $limite = 10, int $offset = 0, string $termino = ''): array
    {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);
        $patron = '%' . trim($termino) . '%';

        $consulta = self::$db->prepare(
            'SELECT * FROM empresas
              WHERE (? = "" OR nombre LIKE ? OR IFNULL(rfc, "") LIKE ? OR IFNULL(ciudad, "") LIKE ?)
              ORDER BY nombre ASC
              LIMIT ? OFFSET ?'
        );
        $consulta->bind_param('ssssii', $termino, $patron, $patron, $patron, $limite, $offset);
        $consulta->execute();
        $registros = $consulta->get_result();

        $empresas = [];
        while ($fila = $registros->fetch_assoc()) {
            $empresas[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $empresas;
    }

    /** Cuenta las empresas que coinciden con la búsqueda. */
    public static function contarTodos(string $termino = ''): int
    {
        $patron = '%' . trim($termino) . '%';

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM empresas
              WHERE (? = "" OR nombre LIKE ? OR IFNULL(rfc, "") LIKE ? OR IFNULL(ciudad, "") LIKE ?)'
        );
        $consulta->bind_param('ssss', $termino, $patron, $patron, $patron);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Empresas activas para poblar un <select> en el cotizador.
     *
     * @return array<int, array{id: int, nombre: string}>
     */
    public static function paraSelector(): array
    {
        $resultado = self::$db->query(
            'SELECT id, nombre FROM empresas WHERE activa = 1 ORDER BY nombre ASC'
        );

        $lista = [];
        while ($fila = $resultado->fetch_assoc()) {
            $lista[] = ['id' => (int) $fila['id'], 'nombre' => $fila['nombre']];
        }

        $resultado->free();
        return $lista;
    }

    /** Número de productos de un negocio (aviso antes de borrar). */
    public function contarProductos(): int
    {
        if (!\tablaExiste(self::$db, 'productos')) {
            return 0;
        }

        $consulta = self::$db->prepare('SELECT COUNT(*) AS total FROM productos WHERE empresa_id = ?');
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

    /** Devuelve y limpia los errores acumulados. */
    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /** Comprueba los campos obligatorios del negocio. */
    public function validar(): array
    {
        self::$errores = [];

        if (trim($this->nombre) === '') {
            self::$errores[] = 'El nombre del negocio es obligatorio.';
        }
        if (trim($this->email) !== '' && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            self::$errores[] = 'El correo electrónico no es válido.';
        }
        if (trim($this->cp) !== '' && !preg_match('/^\d{4,5}$/', $this->cp)) {
            self::$errores[] = 'El código postal debe tener 4 o 5 dígitos.';
        }

        return self::$errores;
    }
}
