<?php

namespace App;

/**
 * Distribuidores autorizados (migración 056): puntos de venta que el sitio
 * público de Soluciones en Acero muestra en /distribuidores.
 *
 * Se administran en /admin/distribuidores. El sitio sólo lee los de su
 * empresa con publicado = 1, ordenados por «orden».
 *
 * La fachada puede ser:
 *   - un archivo de public/image subido aquí (distribuidor-xxxxxxxxxx.jpg);
 *   - una ruta heredada de la lista fija del sitio (distribuidores/lapaz/…),
 *     que sigue sirviendo el propio sitio hasta reemplazarla o importarla con
 *     scripts/importar_imagenes_distribuidores.php.
 */
class Distribuidores
{
    /** @var \mysqli */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    /** Sitio público: de ahí salen las fachadas heredadas. */
    public const SITIO_URL = 'https://solucionesenacero.iguzman.com.mx';

    /** Prefijo de las fachadas heredadas (rutas del sitio, no archivos de public/image). */
    public const PREFIJO_HEREDADA = 'distribuidores/';

    public const MAXIMOS = [
        'nombre'    => 120,
        'sucursal'  => 120,
        'municipio' => 80,
        'direccion' => 255,
        'telefono'  => 30,
        'maps'      => 255,
    ];

    public $id;
    public $empresa_id;
    public $nombre = '';
    public $sucursal = '';
    public $municipio = '';
    public $direccion = '';
    public $telefono = '';
    public $maps = '';
    public $imagen = '';
    public $publicado = 1;
    public $orden = 0;
    public $creado_en = '';
    public $actualizado_en = '';

    /** Nombre del negocio (sólo en listados). */
    public $empresa = '';

    /** Fachada reemplazada o quitada: se borra del disco sólo si se guardó bien. */
    private $imagenAnterior = '';

    /** Fachada recién subida: se borra si al final no se guarda. */
    private $imagenNueva = '';

    public function __construct(array $args = [])
    {
        $this->id             = isset($args['id']) ? (int) $args['id'] : null;
        $this->empresa_id     = isset($args['empresa_id']) ? (int) $args['empresa_id'] : null;
        $this->nombre         = (string) ($args['nombre'] ?? '');
        $this->sucursal       = (string) ($args['sucursal'] ?? '');
        $this->municipio      = (string) ($args['municipio'] ?? '');
        $this->direccion      = (string) ($args['direccion'] ?? '');
        $this->telefono       = (string) ($args['telefono'] ?? '');
        $this->maps           = (string) ($args['maps'] ?? '');
        $this->imagen         = (string) ($args['imagen'] ?? '');
        $this->publicado      = isset($args['publicado']) ? (int) $args['publicado'] : 1;
        $this->orden          = (int) ($args['orden'] ?? 0);
        $this->creado_en      = (string) ($args['creado_en'] ?? '');
        $this->actualizado_en = (string) ($args['actualizado_en'] ?? '');
        $this->empresa        = (string) ($args['empresa'] ?? '');
    }

    public static function setDB($database): void
    {
        self::$db = $database;
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

    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare(
            'SELECT d.*, e.nombre AS empresa FROM distribuidores d JOIN empresas e ON e.id = d.empresa_id WHERE d.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? new self($fila) : null;
    }

    /** WHERE común del listado y del conteo. */
    private static function filtros(string $termino, string $municipio, int $empresa): array
    {
        $patron = '%' . $termino . '%';
        $sql = "(? = '' OR d.nombre LIKE ? OR d.sucursal LIKE ? OR d.direccion LIKE ?)
                AND (? = '' OR d.municipio = ?)
                AND (? = 0 OR d.empresa_id = ?)";
        return [$sql, 'ssssssii', [$termino, $patron, $patron, $patron, $municipio, $municipio, $empresa, $empresa]];
    }

    /** @return self[] En el orden del sitio. */
    public static function mostrarTodos(int $limite = 20, int $offset = 0, string $termino = '', string $municipio = '', int $empresa = 0): array
    {
        [$where, $tipos, $valores] = self::filtros(trim($termino), trim($municipio), $empresa);
        $limite = max(1, $limite);
        $offset = max(0, $offset);

        $consulta = self::$db->prepare(
            "SELECT d.*, e.nombre AS empresa
               FROM distribuidores d
               JOIN empresas e ON e.id = d.empresa_id
              WHERE {$where}
              ORDER BY d.orden ASC, d.id ASC
              LIMIT ? OFFSET ?"
        );
        $tipos .= 'ii';
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $lista = [];
        while ($fila = $resultado->fetch_assoc()) {
            $lista[] = new self($fila);
        }
        $consulta->close();

        return $lista;
    }

    public static function contarTodos(string $termino = '', string $municipio = '', int $empresa = 0): int
    {
        [$where, $tipos, $valores] = self::filtros(trim($termino), trim($municipio), $empresa);
        $consulta = self::$db->prepare("SELECT COUNT(*) AS total FROM distribuidores d WHERE {$where}");
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /** Cuántos se ven hoy en el sitio (publicados). */
    public static function contarPublicados(): int
    {
        return (int) self::$db->query('SELECT COUNT(*) FROM distribuidores WHERE publicado = 1')->fetch_row()[0];
    }

    /** @return string[] Municipios en uso, en el orden en que aparecen en el sitio. */
    public static function municipios(): array
    {
        $resultado = self::$db->query(
            'SELECT municipio FROM distribuidores GROUP BY municipio ORDER BY MIN(orden), MIN(id)'
        );
        $lista = array_column($resultado->fetch_all(MYSQLI_ASSOC), 'municipio');
        $resultado->free();
        return $lista;
    }

    /** Siguiente número de orden (para que lo nuevo quede al final). */
    public static function siguienteOrden(): int
    {
        return (int) self::$db->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM distribuidores')->fetch_row()[0];
    }

    /* --------------------------------------------------------------------
     * FACHADA
     * ------------------------------------------------------------------ */

    public static function esHeredada(?string $imagen): bool
    {
        return is_string($imagen) && str_starts_with($imagen, self::PREFIJO_HEREDADA);
    }

    /** URL para mostrar la fachada en el panel ('' si no tiene). */
    public function urlImagen(bool $miniatura = false): string
    {
        if ($this->imagen === '') {
            return '';
        }
        if (self::esHeredada($this->imagen)) {
            $ruta = implode('/', array_map('rawurlencode', explode('/', $this->imagen)));
            return self::SITIO_URL . '/build/img/' . $ruta;
        }
        return $miniatura ? Imagenes::urlMiniatura($this->imagen) : Imagenes::url($this->imagen);
    }

    /* --------------------------------------------------------------------
     * FORMULARIO, VALIDACIÓN Y ESCRITURA
     * ------------------------------------------------------------------ */

    /**
     * Toma los campos del formulario y la fachada. Devuelve los errores de la
     * imagen, si los hay (los del resto de campos salen de validar()).
     */
    public function sincronizar(array $post, array $archivos = []): array
    {
        foreach (['nombre', 'sucursal', 'municipio', 'direccion', 'telefono', 'maps'] as $campo) {
            if (array_key_exists($campo, $post)) {
                $this->$campo = (string) $post[$campo];
            }
        }
        if (isset($post['empresa_id'])) {
            $this->empresa_id = (int) $post['empresa_id'];
        }
        $this->publicado = !empty($post['publicado']) ? 1 : 0;
        $orden = trim((string) ($post['orden'] ?? ''));
        $this->orden = $orden === '' ? 0 : (int) $orden;

        if (!empty($post['quitar_imagen']) && $this->imagen !== '') {
            $this->imagenAnterior = $this->imagen;
            $this->imagen = '';
        }

        $archivo = $archivos['imagen'] ?? null;
        if (is_array($archivo) && ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $nueva = Imagenes::subir($archivo, 'distribuidor', 'fachada');
            if ($nueva === null) {
                return ['Fachada: ' . (Imagenes::ultimoError() ?: 'no fue posible procesar la imagen.')];
            }
            if ($this->imagen !== '' && $this->imagenAnterior === '') {
                $this->imagenAnterior = $this->imagen;
            }
            $this->imagen = $this->imagenNueva = $nueva;
        }

        return [];
    }

    /** Después de intentar guardar: limpia la fachada que ya no se usa. */
    public function cerrarFormulario(bool $guardado): void
    {
        if ($guardado && $this->imagenAnterior !== '' && !self::esHeredada($this->imagenAnterior)) {
            Imagenes::eliminar($this->imagenAnterior);
        }
        if (!$guardado) {
            // El formulario se vuelve a mostrar con la fachada que sigue guardada.
            if ($this->imagenNueva !== '') {
                Imagenes::eliminar($this->imagenNueva);
                $this->imagen = '';
            }
            if ($this->imagenAnterior !== '') {
                $this->imagen = $this->imagenAnterior;
            }
        }
        $this->imagenAnterior = $this->imagenNueva = '';
    }

    /** Texto de una línea: sin espacios repetidos ni saltos. */
    private static function limpiar(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', $texto));
    }

    public function validar(): array
    {
        self::$errores = [];

        foreach (['nombre', 'sucursal', 'municipio', 'direccion', 'telefono', 'maps'] as $campo) {
            $this->$campo = self::limpiar((string) $this->$campo);
        }

        $etiquetas = [
            'nombre' => 'El nombre', 'sucursal' => 'La sucursal', 'municipio' => 'El municipio',
            'direccion' => 'La dirección', 'telefono' => 'El teléfono', 'maps' => 'El enlace de Maps',
        ];
        foreach (['nombre', 'municipio', 'direccion'] as $obligatorio) {
            if ($this->$obligatorio === '') {
                self::$errores[] = $etiquetas[$obligatorio] . ' es obligatorio' . ($obligatorio === 'direccion' ? 'a' : '') . '.';
            }
        }
        foreach (self::MAXIMOS as $campo => $maximo) {
            if (mb_strlen($this->$campo) > $maximo) {
                self::$errores[] = $etiquetas[$campo] . " no puede pasar de {$maximo} caracteres.";
            }
        }

        if ($this->telefono !== '' && !preg_match('/^[0-9+()\s.\-]{7,30}$/', $this->telefono)) {
            self::$errores[] = 'El teléfono sólo admite números, espacios, guiones, paréntesis y «+».';
        }

        if ($this->maps !== '') {
            $esquema = strtolower((string) parse_url($this->maps, PHP_URL_SCHEME));
            if ($esquema !== 'https' || !filter_var($this->maps, FILTER_VALIDATE_URL)) {
                self::$errores[] = 'El enlace de Maps debe ser una dirección https:// (cópialo desde «Compartir» en Google Maps).';
            }
        }

        $empresa = (int) $this->empresa_id;
        $consulta = self::$db->prepare('SELECT 1 FROM empresas WHERE id = ? LIMIT 1');
        $consulta->bind_param('i', $empresa);
        $consulta->execute();
        if (!$consulta->get_result()->fetch_row()) {
            self::$errores[] = 'Elige el negocio al que pertenece el distribuidor.';
        }
        $consulta->close();

        $this->publicado = (int) $this->publicado === 1 ? 1 : 0;
        $this->orden = max(0, min(65535, (int) $this->orden));

        return self::$errores;
    }

    /** null en lugar de '' para las columnas opcionales. */
    private static function opcional(string $valor): ?string
    {
        return $valor !== '' ? $valor : null;
    }

    public function crear(): bool
    {
        if ((int) $this->orden <= 0) {
            $this->orden = self::siguienteOrden();
        }
        try {
            $consulta = self::$db->prepare(
                'INSERT INTO distribuidores
                    (empresa_id, nombre, sucursal, municipio, direccion, telefono, maps, imagen, publicado, orden)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $empresa = (int) $this->empresa_id;
            $sucursal = self::opcional($this->sucursal);
            $telefono = self::opcional($this->telefono);
            $maps = self::opcional($this->maps);
            $imagen = self::opcional($this->imagen);
            $publicado = (int) $this->publicado;
            $orden = (int) $this->orden;
            $consulta->bind_param(
                'isssssssii',
                $empresa, $this->nombre, $sucursal, $this->municipio, $this->direccion,
                $telefono, $maps, $imagen, $publicado, $orden
            );
            $correcto = $consulta->execute();
            $consulta->close();
            if ($correcto) {
                $this->id = (int) self::$db->insert_id;
            }
            return $correcto;
        } catch (\mysqli_sql_exception $e) {
            error_log('[Distribuidores::crear] ' . $e->getMessage());
            self::$errores[] = 'No fue posible guardar el distribuidor.';
            return false;
        }
    }

    public function actualizar(): bool
    {
        if ((int) $this->orden <= 0) {
            $this->orden = self::siguienteOrden();
        }
        try {
            $consulta = self::$db->prepare(
                'UPDATE distribuidores
                    SET empresa_id = ?, nombre = ?, sucursal = ?, municipio = ?, direccion = ?,
                        telefono = ?, maps = ?, imagen = ?, publicado = ?, orden = ?
                  WHERE id = ?'
            );
            $empresa = (int) $this->empresa_id;
            $sucursal = self::opcional($this->sucursal);
            $telefono = self::opcional($this->telefono);
            $maps = self::opcional($this->maps);
            $imagen = self::opcional($this->imagen);
            $publicado = (int) $this->publicado;
            $orden = (int) $this->orden;
            $id = (int) $this->id;
            $consulta->bind_param(
                'isssssssiii',
                $empresa, $this->nombre, $sucursal, $this->municipio, $this->direccion,
                $telefono, $maps, $imagen, $publicado, $orden, $id
            );
            $correcto = $consulta->execute();
            $consulta->close();
            return $correcto;
        } catch (\mysqli_sql_exception $e) {
            error_log('[Distribuidores::actualizar] ' . $e->getMessage());
            self::$errores[] = 'No fue posible guardar los cambios.';
            return false;
        }
    }

    /** Publica u oculta en el sitio sin abrir el formulario. */
    public function cambiarPublicado(bool $publicado): bool
    {
        $valor = $publicado ? 1 : 0;
        $id = (int) $this->id;
        $consulta = self::$db->prepare('UPDATE distribuidores SET publicado = ? WHERE id = ?');
        $consulta->bind_param('ii', $valor, $id);
        $correcto = $consulta->execute();
        $consulta->close();
        if ($correcto) {
            $this->publicado = $valor;
        }
        return $correcto;
    }

    public function eliminar(): bool
    {
        $id = (int) $this->id;
        try {
            $consulta = self::$db->prepare('DELETE FROM distribuidores WHERE id = ?');
            $consulta->bind_param('i', $id);
            $correcto = $consulta->execute() && $consulta->affected_rows > 0;
            $consulta->close();
        } catch (\mysqli_sql_exception $e) {
            error_log('[Distribuidores::eliminar] ' . $e->getMessage());
            self::$errores[] = 'No fue posible eliminar el distribuidor.';
            return false;
        }
        if ($correcto && $this->imagen !== '' && !self::esHeredada($this->imagen)) {
            Imagenes::eliminar($this->imagen);
        }
        return $correcto;
    }
}
