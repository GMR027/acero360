<?php

namespace App;

/**
 * EventosCalendario — recordatorios y eventos del módulo «Calendario».
 *
 * Herramienta personal (grupo «Herramientas personales»): un calendario del
 * mes activo donde, al hacer clic sobre un día, se captura un recordatorio o
 * un evento con su fecha, nombre, descripción opcional, negocio opcional,
 * nivel de importancia y hora.
 *
 * Es COMPARTIDA, como el resto de herramientas personales: cualquier
 * administrador ve y gestiona los eventos de todos. La columna `usuario_id`
 * sólo guarda QUIÉN lo capturó (se muestra como «Autor») y no filtra el
 * listado; por eso su relación con usuarios es ON DELETE RESTRICT.
 *
 * Niveles de importancia (coinciden con el formulario):
 *   bajo  — Bajo
 *   medio — Medio (por defecto)
 *   alto  — Alto
 */
class EventosCalendario
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten en la base de datos (sin el id ni las fechas de sistema). */
    protected static $columnasDB = [
        'id', 'usuario_id', 'fecha', 'hora', 'nombre', 'descripcion', 'empresa_id', 'importancia',
    ];

    /** Tipo de parámetro mysqli de cada columna (i = entero, s = texto). */
    protected static $tiposDB = [
        'usuario_id'  => 'i',
        'fecha'       => 's',
        'hora'        => 's',
        'nombre'      => 's',
        'descripcion' => 's',
        'empresa_id'  => 'i',
        'importancia' => 's',
    ];

    /** @var string[] Mensajes de validación de la última operación. */
    protected static $errores = [];

    public $id;
    public $usuario_id;
    public $fecha;
    public $hora;
    public $nombre;
    public $descripcion;
    public $empresa_id;
    public $importancia;
    public $creado_en;
    public $actualizado_en;

    /** @var string Nombre del negocio asociado (sólo lectura, viene del JOIN). */
    public $negocio;

    /** @var string Nombre del administrador que lo capturó (sólo lectura). */
    public $autor;

    /** Niveles de importancia admitidos, en el orden del formulario. */
    public const IMPORTANCIAS = ['bajo', 'medio', 'alto'];

    public function __construct(array $args = [])
    {
        $this->id           = $args['id'] ?? null;
        $this->usuario_id   = isset($args['usuario_id']) ? (int) $args['usuario_id'] : 0;
        $this->fecha        = $args['fecha'] ?? '';
        $this->nombre       = $args['nombre'] ?? '';
        $this->descripcion  = $args['descripcion'] ?? '';
        $this->empresa_id   = isset($args['empresa_id']) && $args['empresa_id'] !== '' ? (int) $args['empresa_id'] : null;
        $this->importancia  = $args['importancia'] ?? 'medio';
        // Sin hora = evento de todo el día: se guarda NULL, nunca '00:00:00'.
        $this->hora         = trim((string) ($args['hora'] ?? '')) === '' ? null : trim((string) $args['hora']);
        $this->creado_en    = $args['creado_en'] ?? '';
        $this->actualizado_en = $args['actualizado_en'] ?? '';
        $this->negocio      = $args['negocio'] ?? '';
        $this->autor        = $args['autor'] ?? '';
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
     * CATÁLOGO DE IMPORTANCIAS
     * ------------------------------------------------------------------ */

    /**
     * Devuelve etiqueta, icono y estilo de un nivel de importancia.
     *
     * @return array{etiqueta: string, icono: string, clase: string}
     */
    public static function datosImportancia(string $clave): array
    {
        $mapa = [
            'bajo'  => ['etiqueta' => 'Bajo',  'icono' => 'arrow_downward',   'clase' => 'badge--neutro'],
            'medio' => ['etiqueta' => 'Medio', 'icono' => 'remove',           'clase' => 'badge--pendiente'],
            'alto'  => ['etiqueta' => 'Alto',  'icono' => 'priority_high',    'clase' => 'badge--error'],
        ];

        return $mapa[$clave] ?? $mapa['medio'];
    }

    /**
     * Opciones del <select> de importancia del formulario.
     *
     * @return array<string, string> valor => etiqueta
     */
    public static function importancias(): array
    {
        return [
            'bajo'  => 'Bajo',
            'medio' => 'Medio',
            'alto'  => 'Alto',
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

        // Sin hora = evento de todo el día: se guarda NULL. Si se escribiera
        // '' MySQL lo convertiría en 00:00:00 y el evento perdería su sentido.
        if (trim((string) $atributos['hora']) === '') {
            $atributos['hora'] = null;
        }

        return $atributos;
    }

    /**
     * Ordena los valores para bind_param y arma la cadena de tipos.
     *
     * Un NULL opcional (empresa_id) se envía como tal: mysqli manda NULL al
     * motor, que es lo que la columna admite.
     *
     * @param array<string, mixed> $atributos
     * @return array{0: array<int, mixed>, 1: string}
     */
    protected static function prepararBind(array $atributos): array
    {
        $valores = [];
        $tipos   = '';

        foreach (array_keys($atributos) as $columna) {
            $valores[] = $atributos[$columna];
            $tipos    .= self::$tiposDB[$columna];
        }

        return [$valores, $tipos];
    }

    /** Crea o actualiza el evento según exista o no id. */
    public function guardar(): bool
    {
        return is_null($this->id) ? $this->crear() : $this->actualizar();
    }

    /** Inserta un recordatorio o evento nuevo. */
    public function crear(): bool
    {
        try {
            $atributos = $this->atributos();
            $columnas  = implode(', ', array_keys($atributos));
            $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

            $consulta = self::$db->prepare(
                "INSERT INTO calendario_eventos ($columnas) VALUES ($marcadores)"
            );

            [$valores, $tipos] = self::prepararBind($atributos);
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                $this->id = (int) self::$db->insert_id;
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[EventosCalendario::crear] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar el recordatorio o evento.';
        return false;
    }

    /** Actualiza un evento existente. */
    public function actualizar(): bool
    {
        try {
            $atributos = $this->atributos();
            $asignaciones = [];

            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = "$columna = ?";
            }

            $consulta = self::$db->prepare(
                'UPDATE calendario_eventos SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
            );

            [$valores, $tipos] = self::prepararBind($atributos);
            $valores[] = (int) $this->id;
            $tipos    .= 'i';
            $consulta->bind_param($tipos, ...$valores);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[EventosCalendario::actualizar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el recordatorio o evento.';
        return false;
    }

    /** Elimina el evento. */
    public function eliminar(): bool
    {
        try {
            $consulta = self::$db->prepare('DELETE FROM calendario_eventos WHERE id = ?');
            $id = (int) $this->id;
            $consulta->bind_param('i', $id);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[EventosCalendario::eliminar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible eliminar el recordatorio o evento.';
        return false;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga un evento por id (sección compartida: cualquier admin puede verlo). */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare(
            'SELECT e.*, IFNULL(emp.nombre, "") AS negocio, IFNULL(u.nombre, "") AS autor
               FROM calendario_eventos e
               LEFT JOIN empresas emp ON emp.id = e.empresa_id
               LEFT JOIN usuarios u   ON u.id   = e.usuario_id
              WHERE e.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /** Eventos de un mes, ordenados por día y hora (los de todo el día primero). */
    public static function listarMes(int $anio, int $mes): array
    {
        $consulta = self::$db->prepare(
            'SELECT e.*, IFNULL(emp.nombre, "") AS negocio, IFNULL(u.nombre, "") AS autor
               FROM calendario_eventos e
               LEFT JOIN empresas emp ON emp.id = e.empresa_id
               LEFT JOIN usuarios u   ON u.id   = e.usuario_id
              WHERE YEAR(e.fecha) = ? AND MONTH(e.fecha) = ?
              ORDER BY e.fecha ASC, e.hora IS NULL DESC, e.hora ASC, e.id ASC'
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $registros = $consulta->get_result();

        $eventos = [];
        while ($fila = $registros->fetch_assoc()) {
            $eventos[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $eventos;
    }

    /**
     * Cuántos eventos tiene cada día del mes (para marcar los días en la rejilla).
     *
     * @return array<string, int> 'Y-m-d' => cantidad
     */
    public static function contarPorDia(int $anio, int $mes): array
    {
        $consulta = self::$db->prepare(
            'SELECT e.fecha, COUNT(*) AS total
               FROM calendario_eventos e
              WHERE YEAR(e.fecha) = ? AND MONTH(e.fecha) = ?
              GROUP BY e.fecha'
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $registros = $consulta->get_result();

        $conteo = [];
        while ($fila = $registros->fetch_assoc()) {
            $conteo[$fila['fecha']] = (int) $fila['total'];
        }

        $consulta->close();
        return $conteo;
    }

    /**
     * Cifras del mes para las tarjetas de resumen.
     *
     * @return array{eventos: int, con_hora: int, alta: int}
     */
    public static function resumenMes(int $anio, int $mes): array
    {
        $consulta = self::$db->prepare(
            'SELECT COUNT(*)                    AS eventos,
                    SUM(e.hora IS NOT NULL)     AS con_hora,
                    SUM(e.importancia = "alto") AS alta
               FROM calendario_eventos e
              WHERE YEAR(e.fecha) = ? AND MONTH(e.fecha) = ?'
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return [
            'eventos'  => (int) ($fila['eventos'] ?? 0),
            'con_hora' => (int) ($fila['con_hora'] ?? 0),
            'alta'     => (int) ($fila['alta'] ?? 0),
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

    /**
     * ¿La cadena es una hora real (HH:MM o HH:MM:SS dentro de sus rangos)?
     *
     * Un simple patrón no basta: 25:99:99 lo tendría por buena y MySQL
     * después lo rechazaría o lo convertiría.
     */
    public static function horaValida(string $hora): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $hora, $partes)) {
            return false;
        }

        $segundos = $partes[3] ?? 0;

        return (int) $partes[1] <= 23 && (int) $partes[2] <= 59 && (int) $segundos <= 59;
    }

    /** Comprueba que los campos obligatorios tengan contenido y sean válidos. */
    public function validar(): array
    {
        self::$errores = [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->fecha)
            || !checkdate((int) substr($this->fecha, 5, 2), (int) substr($this->fecha, 8, 2), (int) substr($this->fecha, 0, 4))) {
            self::$errores[] = 'La fecha del evento no es válida.';
        }
        if (trim($this->nombre) === '') {
            self::$errores[] = 'El nombre del evento es obligatorio.';
        }
        if (mb_strlen(trim($this->nombre)) > 200) {
            self::$errores[] = 'El nombre del evento no puede exceder los 200 caracteres.';
        }
        if (trim((string) $this->hora) !== '' && !self::horaValida((string) $this->hora)) {
            self::$errores[] = 'La hora del evento no es válida.';
        }
        if (!array_key_exists($this->importancia, self::importancias())) {
            self::$errores[] = 'El nivel de importancia seleccionado no es válido.';
        }

        return self::$errores;
    }
}
