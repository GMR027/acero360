<?php

namespace App;

/**
 * Clientes — fichas de los clientes seleccionables en las cotizaciones.
 *
 * IMPORTANTE: un cliente NO inicia sesión en la aplicación. Es sólo un
 * registro de contacto con sus datos fiscales, su condición VIP y su
 * descuento global. Los usuarios con login viven en App\Usuarios.
 *
 * Campos mínimos exigidos por OPENCODE.md:
 *   nombre, RFC, teléfono, email, ubicación, VIP
 * Campos añadidos por el usuario:
 *   nombre_empresa, alias
 *
 * El patrón de la clase sigue el de canastillasWep (columnas declaradas,
 * atributos(), sanitizar(), validar(), errores) pero con sentencias
 * preparadas para evitar inyección SQL.
 */
class Clientes
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten en la base de datos (sin el id). */
    protected static $columnasDB = [
        'id', 'nombre', 'nombre_empresa', 'alias', 'rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi',
        'telefono', 'email', 'ubicacion', 'foto', 'vip', 'descuento_pct', 'notas',
    ];

    /** @var string[] Mensajes de validación de la última operación. */
    protected static $errores = [];

    public $id;
    public $nombre;
    public $nombre_empresa;
    public $alias;
    public $rfc;
    /** Datos fiscales del receptor para el CFDI (migración 035). */
    public $codigo_postal;
    public $regimen_fiscal;
    public $uso_cfdi;
    public $telefono;
    public $email;
    public $ubicacion;
    public $foto;
    public $vip;
    public $descuento_pct;
    public $notas;

    public function __construct(array $args = [])
    {
        $this->id             = $args['id'] ?? null;
        $this->nombre         = $args['nombre'] ?? '';
        $this->nombre_empresa = $args['nombre_empresa'] ?? '';
        $this->alias          = $args['alias'] ?? '';
        $this->rfc            = $args['rfc'] ?? '';
        $this->codigo_postal  = $args['codigo_postal'] ?? '';
        $this->regimen_fiscal = $args['regimen_fiscal'] ?? '';
        $this->uso_cfdi       = $args['uso_cfdi'] ?? '';
        $this->telefono       = $args['telefono'] ?? '';
        $this->email         = $args['email'] ?? '';
        $this->ubicacion     = $args['ubicacion'] ?? '';
        $this->foto          = $args['foto'] ?? '';
        $this->vip           = isset($args['vip']) ? (int) $args['vip'] : 0;
        $this->descuento_pct = isset($args['descuento_pct']) ? (float) $args['descuento_pct'] : 0;
        $this->notas         = $args['notas'] ?? '';
    }

    /* --------------------------------------------------------------------
     * CONEXIÓN
     * ------------------------------------------------------------------ */

    /** Recibe la conexión a la base de datos (llamado desde includes/app.php). */
    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /* --------------------------------------------------------------------
     * ACCESO A DATOS
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

    /** Escapa cada valor para su uso en consultas. */
    public function sanitizarAtributos(): array
    {
        $sanitizado = [];

        foreach ($this->atributos() as $clave => $valor) {
            $sanitizado[$clave] = self::$db->real_escape_string((string) $valor);
        }

        return $sanitizado;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

    /** Crea o actualiza el registro según exista o no id. */
    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    /** Inserta un cliente nuevo. */
    public function crear(): bool
    {
        try {
            $atributos = $this->atributos();
            $columnas  = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO clientes ($columnas) VALUES ($marcadores)"
            );

            // Todos los valores se envían como cadena; MySQL hace la conversión.
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
            // Nunca se muestra el detalle técnico al usuario.
            error_log('[Clientes::crear] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar el cliente. Revisa que no exista ya un registro con los mismos datos.';
        return false;
    }

    /** Actualiza un cliente existente. */
    public function actualizar(): bool
    {
        try {
            $atributos = $this->atributos();
            $asignaciones = [];

            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE clientes SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
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
            error_log('[Clientes::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el cliente.';
        return false;
    }

    /**
     * Elimina el cliente.
     *
     * Si el cliente ya tiene cotizaciones o precios especiales, MySQL rechaza
     * el borrado por la integridad referencial: en ese caso se informa al
     * usuario en lugar de dejarlo sin explicación.
     */
    public function eliminar(): bool
    {
        try {
            $consulta = self::$db->prepare('DELETE FROM clientes WHERE id = ?');
            $id = (int) $this->id;
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                // Con el registro fuera, la foto de perfil ya no se usa.
                Imagenes::eliminar($this->foto ?: null);
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Clientes::eliminar] ' . $excepcion->getMessage());
            self::$errores[] = 'No se puede eliminar: este cliente ya tiene cotizaciones o precios especiales registrados.';
            return false;
        }

        self::$errores[] = 'No fue posible eliminar el cliente.';
        return false;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga un cliente por su id, o null si no existe. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /**
     * Listado paginado con búsqueda opcional por nombre, alias, empresa o RFC.
     *
     * @param string $termino Texto de búsqueda (vacío = todos).
     */
    public static function mostrarTodos(int $limite = 10, int $offset = 0, string $termino = ''): array
    {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);
        $patron = '%' . trim($termino) . '%';

        $consulta = self::$db->prepare(
            'SELECT * FROM clientes
              WHERE (? = "" OR nombre LIKE ? OR nombre_empresa LIKE ?
                     OR alias LIKE ? OR rfc LIKE ?)
              ORDER BY vip DESC, nombre ASC
              LIMIT ? OFFSET ?'
        );
        // 5 cadenas (texto de búsqueda + 4 patrones) y 2 enteros (LIMIT / OFFSET).
        $consulta->bind_param('sssssii', $termino, $patron, $patron, $patron, $patron, $limite, $offset);
        $consulta->execute();
        $registros = $consulta->get_result();

        $clientes = [];
        while ($fila = $registros->fetch_assoc()) {
            $clientes[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $clientes;
    }

    /** Cuenta los clientes que coinciden con la búsqueda. */
    public static function contarTodos(string $termino = ''): int
    {
        $patron = '%' . trim($termino) . '%';

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM clientes
              WHERE (? = "" OR nombre LIKE ? OR nombre_empresa LIKE ?
                     OR alias LIKE ? OR rfc LIKE ?)'
        );
        $consulta->bind_param('sssss', $termino, $patron, $patron, $patron, $patron);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Devuelve los clientes en un arreglo simple para poblar un <select>.
     * Se usa en el formulario de cotización.
     *
     * @return array<int, array{id: int, etiqueta: string, vip: int}>
     */
    public static function paraSelector(): array
    {
        $resultado = self::$db->query(
            'SELECT id, nombre, nombre_empresa, alias, vip FROM clientes ORDER BY vip DESC, nombre ASC'
        );

        $lista = [];
        while ($fila = $resultado->fetch_assoc()) {
            $nombreCorto = $fila['alias'] !== '' && $fila['alias'] !== null
                ? $fila['alias']
                : $fila['nombre'];

            $lista[] = [
                'id'      => (int) $fila['id'],
                'etiqueta' => $nombreCorto . ($fila['nombre_empresa'] ? ' — ' . $fila['nombre_empresa'] : ''),
                'vip'     => (int) $fila['vip'],
            ];
        }

        $resultado->free();
        return $lista;
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
     * CONSULTAS DE APOYO (detalle del cliente)
     * ------------------------------------------------------------------ */

    /**
     * Número de cotizaciones emitidas a este cliente.
     * Devuelve 0 si la tabla de cotizaciones aún no se ha creado.
     */
    public function contarCotizaciones(): int
    {
        if (!\tablaExiste(self::$db, 'cotizaciones')) {
            return 0;
        }

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM cotizaciones WHERE cliente_id = ?'
        );
        $id = (int) $this->id;
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Indica si el cliente tiene precios especiales guardados.
     * La tabla sólo aparece en Fase 4, por eso se comprueba antes.
     */
    public function tienePreciosEspeciales(): bool
    {
        if (!\tablaExiste(self::$db, 'precios_cliente')) {
            return false;
        }

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM precios_cliente WHERE cliente_id = ?'
        );
        $id = (int) $this->id;
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total > 0;
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

    /** Comprueba que los campos obligatorios tengan contenido. */
    public function validar(): array
    {
        self::$errores = [];

        if (trim($this->nombre) === '') {
            self::$errores[] = 'El nombre del contacto es obligatorio.';
        }
        if (trim($this->nombre_empresa) === '' && trim($this->alias) === '') {
            self::$errores[] = 'Indica al menos el nombre de la empresa o un alias.';
        }
        if (trim($this->email) !== '' && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            self::$errores[] = 'El correo electrónico no es válido.';
        }
        if ((float) $this->descuento_pct < 0 || (float) $this->descuento_pct > 100) {
            self::$errores[] = 'El descuento debe estar entre 0 y 100 por ciento.';
        }

        /* --- Datos fiscales del receptor para facturar (FASE 5) --- */
        $rfc = VentasFiscal::normalizarRfc((string) $this->rfc);
        if ($rfc !== '') {
            if (!VentasFiscal::rfcConFormato($rfc)) {
                self::$errores[] = 'El RFC "' . $rfc . '" no tiene el formato de un RFC.';
            } else {
                // Un solo cliente por RFC: se reutiliza la ficha existente en vez de duplicarla.
                $duplicado = VentasFiscal::buscarClientePorRfc($rfc, (int) ($this->id ?? 0));
                if ($duplicado !== null) {
                    $nombre = trim((string) ($duplicado['nombre_empresa'] ?: $duplicado['nombre']));
                    self::$errores[] = 'El RFC "' . $rfc . '" ya está registrado al cliente «' . $nombre
                        . '» (ficha #' . (int) $duplicado['id'] . '). Edita esa ficha en lugar de crear un duplicado.';
                }
            }
        }

        $cp = trim((string) $this->codigo_postal);
        if ($cp !== '' && preg_match('/^\d{5}$/', $cp) !== 1) {
            self::$errores[] = 'El código postal fiscal debe tener 5 dígitos.';
        }
        $regimen = mb_strtoupper(trim((string) $this->regimen_fiscal));
        if ($regimen !== '' && preg_match('/^[0-9A-Z]{3,4}$/', $regimen) !== 1) {
            self::$errores[] = 'El régimen fiscal "' . $regimen . '" no es una clave del catálogo SAT.';
        }
        $uso = mb_strtoupper(trim((string) $this->uso_cfdi));
        if ($uso !== '' && preg_match('/^[0-9A-Z]{3,4}$/', $uso) !== 1) {
            self::$errores[] = 'El uso del CFDI "' . $uso . '" no es una clave del catálogo SAT.';
        }

        return self::$errores;
    }
}
