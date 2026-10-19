<?php

namespace App;

/**
 * Usuarios — administradores que inician sesión en el panel.
 *
 * IMPORTANTE: según OPENCODE.md, "los usuarios que se registren serán admin",
 * así que el rol sólo admite el valor 1. Los clientes NO viven aquí: son una
 * ficha de App\Clientes que no inicia sesión.
 *
 * La contraseña nunca se guarda en texto plano: se transforma con
 * password_hash() (bcrypt) al crear y sólo se vuelve a escribir si el
 * formulario trae una nueva.
 */
class Usuarios
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten (sin el id ni la fecha de alta). */
    protected static $columnasDB = ['id', 'nombre', 'email', 'contrasena', 'telefono', 'foto', 'rol'];

    /** @var string[] Mensajes de validación de la última operación. */
    protected static $errores = [];

    public $id;
    public $nombre;
    public $email;
    public $contrasena;
    public $telefono;
    public $foto;
    public $rol;
    public $creado_en;

    public function __construct(array $args = [])
    {
        $this->id         = $args['id'] ?? null;
        $this->nombre     = $args['nombre'] ?? '';
        $this->email      = $args['email'] ?? '';
        $this->contrasena = $args['contrasena'] ?? '';
        $this->telefono   = $args['telefono'] ?? '';
        $this->foto       = $args['foto'] ?? '';
        $this->rol        = isset($args['rol']) ? (int) $args['rol'] : 1;
        $this->creado_en  = $args['creado_en'] ?? '';
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

    /** Inserta un usuario nuevo (siempre con contraseña hasheada). */
    public function crear(): bool
    {
        if (trim($this->contrasena) === '') {
            self::$errores[] = 'La contraseña es obligatoria.';
            return false;
        }

        // Nunca se duplica el correo: el registro de sesión depende de él.
        if (self::buscarPorEmail($this->email) !== null) {
            self::$errores[] = 'Ya existe un usuario con ese correo electrónico.';
            return false;
        }

        try {
            $atributos = $this->atributos();
            // Sustituye la contraseña en claro por su hash bcrypt.
            $atributos['contrasena'] = password_hash($this->contrasena, PASSWORD_DEFAULT);

            $columnas   = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO usuarios ($columnas) VALUES ($marcadores)"
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
            error_log('[Usuarios::crear] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar el usuario.';
        return false;
    }

    /**
     * Actualiza un usuario existente.
     * Si el campo contraseña viene vacío, se conserva la que ya tenía.
     */
    public function actualizar(): bool
    {
        try {
            $atributos = $this->atributos();

            if (trim($this->contrasena) === '') {
                unset($atributos['contrasena']);
            } else {
                $atributos['contrasena'] = password_hash($this->contrasena, PASSWORD_DEFAULT);
            }

            if (empty($atributos)) {
                return true;
            }

            $asignaciones = [];
            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE usuarios SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
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
            error_log('[Usuarios::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el usuario.';
        return false;
    }

    /**
     * Elimina un usuario.
     *
     * Protecciones: no se puede borrar a uno mismo y no se puede quedar el
     * panel sin ningún administrador.
     */
    public function eliminar(int $sesionId = 0): bool
    {
        if ((int) $this->id === $sesionId) {
            self::$errores[] = 'No puedes eliminar el usuario con el que tienes la sesión abierta.';
            return false;
        }

        if (self::contarAdmins() <= 1 && (int) $this->rol === 1) {
            self::$errores[] = 'No se puede eliminar: debe quedar al menos un administrador.';
            return false;
        }

        try {
            $consulta = self::$db->prepare('DELETE FROM usuarios WHERE id = ?');
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
            error_log('[Usuarios::eliminar] ' . $excepcion->getMessage());
            self::$errores[] = 'No se puede eliminar: el usuario tiene cotizaciones '
                . 'o notas de la bitácora a su nombre.';
            return false;
        }

        self::$errores[] = 'No fue posible eliminar el usuario.';
        return false;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga un usuario por id. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /** Busca un usuario por su correo (lo usa el login y la validación). */
    public static function buscarPorEmail(string $email): ?self
    {
        $consulta = self::$db->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
        $consulta->bind_param('s', $email);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /** Comprueba credenciales. Devuelve el usuario o null. */
    public static function autenticar(string $email, string $contrasena): ?self
    {
        $usuario = self::buscarPorEmail($email);

        if ($usuario && password_verify($contrasena, $usuario->contrasena)) {
            return $usuario;
        }

        return null;
    }

    /**
     * Listado paginado con búsqueda por nombre o correo.
     */
    public static function mostrarTodos(int $limite = 10, int $offset = 0, string $termino = ''): array
    {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);
        $patron = '%' . trim($termino) . '%';

        $consulta = self::$db->prepare(
            'SELECT * FROM usuarios
              WHERE (? = "" OR nombre LIKE ? OR email LIKE ?)
              ORDER BY nombre ASC
              LIMIT ? OFFSET ?'
        );
        // 3 cadenas (texto de búsqueda + 2 patrones) y 2 enteros (LIMIT / OFFSET).
        $consulta->bind_param('sssii', $termino, $patron, $patron, $limite, $offset);
        $consulta->execute();
        $registros = $consulta->get_result();

        $usuarios = [];
        while ($fila = $registros->fetch_assoc()) {
            $usuarios[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $usuarios;
    }

    /** Cuenta los usuarios que coinciden con la búsqueda. */
    public static function contarTodos(string $termino = ''): int
    {
        $patron = '%' . trim($termino) . '%';

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM usuarios
              WHERE (? = "" OR nombre LIKE ? OR email LIKE ?)'
        );
        $consulta->bind_param('sss', $termino, $patron, $patron);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /** Número de administradores activos (para no dejar el panel sin salida). */
    public static function contarAdmins(): int
    {
        $resultado = self::$db->query('SELECT COUNT(*) AS total FROM usuarios WHERE rol = 1');
        $fila = $resultado->fetch_assoc();
        $resultado->free();

        return (int) ($fila['total'] ?? 0);
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

    /**
     * Comprueba los campos obligatorios.
     *
     * @param bool $esAlta En el alta la contraseña es obligatoria;
     *                     en la edición puede quedarse vacía para no cambiarla.
     */
    public function validar(bool $esAlta = false): array
    {
        self::$errores = [];

        if (trim($this->nombre) === '') {
            self::$errores[] = 'El nombre es obligatorio.';
        }
        if (trim($this->email) === '') {
            self::$errores[] = 'El correo electrónico es obligatorio.';
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            self::$errores[] = 'El correo electrónico no es válido.';
        }
        if ($esAlta && trim($this->contrasena) === '') {
            self::$errores[] = 'La contraseña es obligatoria.';
        }
        if (trim($this->contrasena) !== '' && strlen($this->contrasena) < 8) {
            self::$errores[] = 'La contraseña debe tener al menos 8 caracteres.';
        }

        return self::$errores;
    }
}
