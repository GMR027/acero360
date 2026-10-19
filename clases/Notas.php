<?php

namespace App;

/**
 * Notas — bitácora interna ("Notas Personales" del diseño).
 *
 * Existe UNA SOLA sección de notas, compartida por todos los usuarios con
 * rol de administrador: lo que escribe uno lo ven los demás. La columna
 * `usuario_id` sólo guarda quién redactó la nota (se muestra como "Autor")
 * y se conserva para trazabilidad; NO filtra el listado.
 *
 * Contenido típico: recordatorios de clientes, pendientes por cotizar,
 * cambios de precios y revisiones varias. No aparece dentro de las
 * cotizaciones.
 *
 * Categorías admitidas (coinciden con el formulario del diseño):
 *   recordatorio — Recordatorio
 *   cotizar      — Cotizar
 *   cambios      — Cambios
 *   revisar      — Revisar
 *   otro         — Otro
 */
class Notas
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten en la base de datos (sin el id ni la fecha). */
    protected static $columnasDB = [
        'id', 'usuario_id', 'titulo', 'cuerpo', 'categoria',
        'referencia', 'fecha_recordatorio', 'fijada', 'completada',
    ];

    /** @var string[] Mensajes de validación de la última operación. */
    protected static $errores = [];

    public $id;
    public $usuario_id;
    public $titulo;
    public $cuerpo;
    public $categoria;
    public $referencia;
    public $fecha_recordatorio;
    public $fijada;
    public $completada;
    public $creado_en;
    public $actualizado_en;

    /** @var string Nombre del administrador que redactó la nota (sólo lectura). */
    public $autor;

    /** Filtros de listado admitidos por la barra de chips. */
    public const FILTROS = [
        'todas', 'fijadas', 'recordatorio', 'cotizar', 'cambios', 'revisar', 'otro', 'activas',
    ];

    /** Categorías del mapa de la nota, en el orden del formulario. */
    public const CATEGORIAS = ['recordatorio', 'cotizar', 'cambios', 'revisar', 'otro'];

    public function __construct(array $args = [])
    {
        $this->id               = $args['id'] ?? null;
        $this->usuario_id       = isset($args['usuario_id']) ? (int) $args['usuario_id'] : 0;
        $this->titulo           = $args['titulo'] ?? '';
        $this->cuerpo           = $args['cuerpo'] ?? '';
        $this->categoria        = $args['categoria'] ?? 'otro';
        $this->referencia       = $args['referencia'] ?? '';
        $this->fecha_recordatorio = $args['fecha_recordatorio'] ?? '';
        $this->fijada           = isset($args['fijada']) ? (int) $args['fijada'] : 0;
        $this->completada       = isset($args['completada']) ? (int) $args['completada'] : 0;
        $this->creado_en        = $args['creado_en'] ?? '';
        $this->actualizado_en   = $args['actualizado_en'] ?? '';
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
     * CATÁLOGO DE CATEGORÍAS
     * ------------------------------------------------------------------ */

    /**
     * Devuelve etiqueta, icono y estilo de una categoría.
     *
     * @return array{etiqueta: string, icono: string, clase: string}
     */
    public static function datosCategoria(string $clave): array
    {
        $mapa = [
            'recordatorio' => ['etiqueta' => 'Recordatorio', 'icono' => 'alarm',        'clase' => 'badge--info'],
            'cotizar'      => ['etiqueta' => 'Cotizar',      'icono' => 'request_quote', 'clase' => 'badge--pendiente'],
            'cambios'      => ['etiqueta' => 'Cambios',      'icono' => 'edit_note',     'clase' => 'badge--error'],
            'revisar'      => ['etiqueta' => 'Revisar',      'icono' => 'fact_check',    'clase' => 'badge--aprobado'],
            'otro'         => ['etiqueta' => 'Otro',         'icono' => 'notes',         'clase' => 'badge--neutro'],
        ];

        return $mapa[$clave] ?? $mapa['otro'];
    }

    /**
     * Opciones del <select> de categorías del formulario.
     *
     * @return array<string, string> valor => etiqueta
     */
    public static function categorias(): array
    {
        return [
            'recordatorio' => 'Recordatorio',
            'cotizar'      => 'Cotizar',
            'cambios'      => 'Cambios',
            'revisar'      => 'Revisar',
            'otro'         => 'Otro',
        ];
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

    /** Crea o actualiza la nota según exista o no id. */
    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    /** Inserta una nota nueva. */
    public function crear(): bool
    {
        try {
            $atributos = $this->atributos();
            $columnas  = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO notas ($columnas) VALUES ($marcadores)"
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
            error_log('[Notas::crear] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar la nota.';
        return false;
    }

    /** Actualiza una nota existente. */
    public function actualizar(): bool
    {
        try {
            $atributos = $this->atributos();
            $asignaciones = [];

            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE notas SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
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
            error_log('[Notas::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar la nota.';
        return false;
    }

    /** Elimina la nota. */
    public function eliminar(): bool
    {
        try {
            $consulta = self::$db->prepare('DELETE FROM notas WHERE id = ?');
            $id = (int) $this->id;
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Notas::eliminar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible eliminar la nota.';
        return false;
    }

    /**
     * Cambia el estado de un indicador binario (fijada / completada).
     *
     * @param string $campo Sólo se admite 'fijada' o 'completada'.
     */
    public static function alternar(int $id, string $campo): bool
    {
        if (!in_array($campo, ['fijada', 'completada'], true)) {
            return false;
        }

        try {
            $consulta = self::$db->prepare(
                "UPDATE notas SET $campo = IF($campo = 1, 0, 1) WHERE id = ?"
            );
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[Notas::alternar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar la nota.';
        return false;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga una nota por id (sección compartida: cualquier admin puede verla). */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare(
            'SELECT n.*, IFNULL(u.nombre, "") AS autor
               FROM notas n
               LEFT JOIN usuarios u ON u.id = n.usuario_id
              WHERE n.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /**
     * Construye la cláusula WHERE según la búsqueda y el filtro activo.
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condiciones(string $termino, string $filtro): array
    {
        $donde   = ['1 = 1'];
        $tipos   = '';
        $valores = [];

        if ($termino !== '') {
            $donde[] = '(n.titulo LIKE ? OR n.cuerpo LIKE ? OR IFNULL(n.referencia, "") LIKE ? OR IFNULL(u.nombre, "") LIKE ?)';
            $tipos  .= 'ssss';
            $patron  = '%' . $termino . '%';
            $valores = array_merge($valores, [$patron, $patron, $patron, $patron]);
        }

        $mapaFiltros = [
            'fijadas'      => 'n.fijada = 1',
            'activas'      => 'n.completada = 0',
            'recordatorio' => 'n.categoria = "recordatorio"',
            'cotizar'      => 'n.categoria = "cotizar"',
            'cambios'      => 'n.categoria = "cambios"',
            'revisar'      => 'n.categoria = "revisar"',
            'otro'         => 'n.categoria = "otro"',
        ];

        if (isset($mapaFiltros[$filtro])) {
            $donde[] = $mapaFiltros[$filtro];
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Listado paginado de las notas.
     *
     * Sección única compartida por todos los administradores.
     * Las fijadas siempre encima; dentro de cada grupo, las más recientes.
     */
    public static function listar(
        string $termino = '',
        string $filtro = 'todas',
        int $limite = 20,
        int $offset = 0
    ): array {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);

        [$donde, $tipos, $valores] = self::condiciones($termino, $filtro);

        $consulta = self::$db->prepare(
            "SELECT n.*, IFNULL(u.nombre, '') AS autor
               FROM notas n
               LEFT JOIN usuarios u ON u.id = n.usuario_id
              WHERE $donde
              ORDER BY n.fijada DESC, n.completada ASC, n.creado_en DESC
              LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $tipos    .= 'ii';

        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $notas = [];
        while ($fila = $registros->fetch_assoc()) {
            $notas[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $notas;
    }

    /** Cuenta las notas que cumplen la búsqueda y el filtro. */
    public static function contar(string $termino = '', string $filtro = 'todas'): int
    {
        [$donde, $tipos, $valores] = self::condiciones($termino, $filtro);

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS total
               FROM notas n
               LEFT JOIN usuarios u ON u.id = n.usuario_id
              WHERE $donde"
        );
        if ($tipos === '') {
            $consulta->execute();
        } else {
            $consulta->bind_param($tipos, ...$valores);
            $consulta->execute();
        }
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Tarjetas de resumen del tablero.
     *
     * @return array{activas: int, fijadas: int, acuerdos: int}
     */
    public static function estadisticas(): array
    {
        $consulta = self::$db->prepare(
            'SELECT SUM(completada = 0)        AS activas,
                    SUM(fijada = 1)            AS fijadas,
                    SUM(categoria = "cotizar") AS por_cotizar
               FROM notas'
        );
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return [
            'activas'      => (int) ($fila['activas'] ?? 0),
            'fijadas'      => (int) ($fila['fijadas'] ?? 0),
            'por_cotizar'  => (int) ($fila['por_cotizar'] ?? 0),
        ];
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

    /** Comprueba que los campos obligatorios tengan contenido. */
    public function validar(): array
    {
        self::$errores = [];

        if (trim($this->titulo) === '') {
            self::$errores[] = 'El título de la nota es obligatorio.';
        }
        if (mb_strlen(trim($this->titulo)) > 200) {
            self::$errores[] = 'El título no puede exceder los 200 caracteres.';
        }
        if (trim($this->cuerpo) === '') {
            self::$errores[] = 'El contenido de la nota es obligatorio.';
        }
        if (!array_key_exists($this->categoria, self::categorias())) {
            self::$errores[] = 'La categoría seleccionada no es válida.';
        }

        return self::$errores;
    }
}
