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
        'telefono', 'email', 'ubicacion', 'ubicacion_id', 'ubicacion_mapa', 'foto', 'vip', 'distribuidor',
        'descuento_pct', 'notas',
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
    /** Ubicación del catálogo ubicaciones_cliente (migración 051). */
    public $ubicacion_id;
    /** Enlace (o dirección) de Google Maps. */
    public $ubicacion_mapa;
    public $foto;
    public $vip;
    /** Cliente distribuidor (migración 051). */
    public $distribuidor;
    /** Hash bcrypt de la contraseña del portal de clientes (migración 052). Nunca texto plano. */
    public $portal_contrasena;
    public $portal_actualizada_en;
    /** Contraseña nueva capturada en el formulario (no se persiste tal cual). */
    public $portal_nueva = '';
    /** true = quitar el acceso al portal. */
    public $portal_quitar = false;
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
        $this->ubicacion_id  = !empty($args['ubicacion_id']) ? (int) $args['ubicacion_id'] : null;
        $this->ubicacion_mapa = $args['ubicacion_mapa'] ?? '';
        $this->foto          = $args['foto'] ?? '';
        $this->vip           = isset($args['vip']) ? (int) $args['vip'] : 0;
        $this->distribuidor  = isset($args['distribuidor']) ? (int) $args['distribuidor'] : 0;
        $this->portal_nueva  = (string) ($args['portal_nueva'] ?? '');
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

        $atributos['ubicacion_id']   = (int) $atributos['ubicacion_id'] > 0 ? (int) $atributos['ubicacion_id'] : null;
        $atributos['ubicacion_mapa'] = trim((string) $atributos['ubicacion_mapa']) !== '' ? trim((string) $atributos['ubicacion_mapa']) : null;
        $atributos['distribuidor']   = (int) $atributos['distribuidor'] === 1 ? 1 : 0;

        return $atributos;
    }

    /** ¿El cliente tiene acceso al portal (contraseña asignada)? */
    public function tienePortal(): bool
    {
        return trim((string) $this->portal_contrasena) !== '';
    }

    /**
     * Escribe la contraseña del portal: sólo si se capturó una nueva (se guarda
     * el hash bcrypt) o si se pidió quitar el acceso. Vacía = se conserva.
     * El portal público valida con password_verify()/bcrypt contra este hash.
     */
    private function guardarPortal(): void
    {
        $nueva = (string) $this->portal_nueva;
        if ($nueva === '' && !$this->portal_quitar) {
            return;
        }

        $hash = $nueva !== '' ? password_hash($nueva, PASSWORD_BCRYPT) : null;
        $id   = (int) $this->id;
        $consulta = self::$db->prepare('UPDATE clientes SET portal_contrasena = ?, portal_actualizada_en = NOW() WHERE id = ?');
        $consulta->bind_param('si', $hash, $id);
        $consulta->execute();
        $consulta->close();

        $this->portal_contrasena = $hash;
        $this->portal_nueva = '';
    }

    /**
     * Enlace seguro para abrir la ubicación de Google Maps del cliente.
     * Si se capturó una URL http(s) se usa tal cual; si es una dirección o
     * coordenadas, se arma una búsqueda de Google Maps. '' si no hay dato.
     */
    public static function enlaceMapa(?string $mapa): string
    {
        $mapa = trim((string) $mapa);
        if ($mapa === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $mapa) === 1) {
            return $mapa;
        }
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapa);
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
                self::registrarRfcActivo();
                $this->guardarPortal();
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
                self::registrarRfcActivo();
                $this->guardarPortal();
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Clientes::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el cliente.';
        return false;
    }

    /**
     * FASE 9: mantiene coherente el registro de RFCs del cliente con su RFC
     * ACTIVO (clientes.rfc). Se llama tras crear o actualizar la ficha: así el
     * panel «RFCs de facturación» siempre ve el RFC con el que se factura, y
     * cambiar de RFC en la ficha conserva el anterior como secundario.
     */
    private function registrarRfcActivo(): void
    {
        $rfc = trim((string) ($this->rfc ?? ''));
        if ($rfc === '' || (int) $this->id <= 0) {
            return;
        }
        ClientesRfc::sincronizarPrincipal((int) $this->id, $rfc, [
            'codigo_postal'  => (string) ($this->codigo_postal ?? ''),
            'regimen_fiscal' => (string) ($this->regimen_fiscal ?? ''),
            'uso_cfdi'       => (string) ($this->uso_cfdi ?? ''),
        ], 0);
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
        $nuevaPortal = (string) $this->portal_nueva;
        if ($nuevaPortal !== '') {
            if (mb_strlen($nuevaPortal) < 8) {
                self::$errores[] = 'La contraseña para portal debe tener al menos 8 caracteres.';
            } elseif (strlen($nuevaPortal) > 72) {
                self::$errores[] = 'La contraseña para portal no puede pasar de 72 caracteres.';
            }
            if (trim((string) $this->email) === '') {
                self::$errores[] = 'Para dar acceso al portal (contraseña) el cliente necesita correo electrónico.';
            }
        }
        if ($this->ubicacion_id && !CatalogoTexto::existe('ubicaciones_cliente', (int) $this->ubicacion_id)) {
            self::$errores[] = 'La ubicación elegida ya no existe; elige otra.';
        }
        $mapa = trim((string) $this->ubicacion_mapa);
        if (mb_strlen($mapa) > 500) {
            self::$errores[] = 'La ubicación de mapa no puede pasar de 500 caracteres.';
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $mapa) === 1 && preg_match('#^https?://#i', $mapa) !== 1
            && preg_match('/^-?\d/', $mapa) !== 1) {
            self::$errores[] = 'La ubicación de mapa debe ser un enlace http(s) de Google Maps, coordenadas o una dirección.';
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
