<?php

namespace App;

/**
 * ProyectosEntrega — compromisos de entrega por cliente.
 *
 * Un proyecto NO es una venta ni una entrega: es la cantidad que el cliente
 * comprometió para una obra (por ejemplo, 2,000 canastillas para la vialidad
 * X). Las ventas se vinculan al proyecto y las entregas se siguen registrando
 * contra la venta, de modo que el avance se calcula solo.
 *
 * Reglas:
 *   • Una venta pertenece a un solo proyecto (o a ninguno).
 *   • La venta y el proyecto deben ser del mismo cliente y negocio.
 *   • Sólo cuentan ventas registradas; las canceladas se excluyen.
 *   • Los contadores no se guardan: se calculan con ventas y entregas reales.
 */
class ProyectosEntrega
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Id del último proyecto creado (para redirigir al detalle). */
    public static $ultimoId = 0;

    /** Estados válidos y su presentación. */
    public const ESTADOS = [
        'activo'     => ['etiqueta' => 'Activo',     'clase' => 'badge--aprobado', 'icono' => 'play_circle'],
        'pausado'    => ['etiqueta' => 'Pausado',    'clase' => 'badge--pendiente', 'icono' => 'pause_circle'],
        'completado' => ['etiqueta' => 'Completado', 'clase' => 'badge--info',      'icono' => 'check_circle'],
        'cancelado'  => ['etiqueta' => 'Cancelado',  'clase' => 'badge--error',     'icono' => 'cancel'],
    ];

    /** Columnas persistibles de la cabecera. */
    protected static $columnasDB = [
        'cliente_id', 'empresa_id', 'nombre', 'descripcion',
        'fecha_inicio', 'fecha_compromiso', 'estado', 'notas', 'usuario_id',
    ];

    public $id;
    public $folio;
    public $cliente_id;
    public $empresa_id;
    public $nombre;
    public $descripcion;
    public $fecha_inicio;
    public $fecha_compromiso;
    public $estado;
    public $notas;
    public $usuario_id;
    public $creado_en;
    public $actualizado_en;

    /* Datos de unión, sólo lectura. */
    public $cliente_nombre;
    public $cliente_empresa;
    public $cliente_alias;
    public $cliente_rfc;
    public $cliente_telefono;
    public $cliente_email;
    public $cliente_ubicacion;
    public $empresa_nombre;
    public $usuario_nombre;
    /** Avance calculado que la vista del listado adjunta a cada proyecto. */
    public $progreso;

    public function __construct(array $args = [])
    {
        $this->id              = $args['id'] ?? null;
        $this->folio           = $args['folio'] ?? '';
        $this->cliente_id      = isset($args['cliente_id']) ? (int) $args['cliente_id'] : 0;
        $this->empresa_id      = isset($args['empresa_id']) ? (int) $args['empresa_id'] : 0;
        $this->nombre          = $args['nombre'] ?? '';
        $this->descripcion     = $args['descripcion'] ?? '';
        $this->fecha_inicio    = $args['fecha_inicio'] ?? '';
        $this->fecha_compromiso= $args['fecha_compromiso'] ?? '';
        $this->estado          = $args['estado'] ?? 'activo';
        $this->notas           = $args['notas'] ?? '';
        $this->usuario_id      = isset($args['usuario_id']) ? (int) $args['usuario_id'] : 0;
        $this->creado_en       = $args['creado_en'] ?? '';
        $this->actualizado_en  = $args['actualizado_en'] ?? '';
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

    /** Datos de presentación de un estado. */
    public static function datosEstado(string $estado): array
    {
        return self::ESTADOS[$estado] ?? self::ESTADOS['activo'];
    }

    /** Siguiente folio libre del año: PRY-2026-0001, PRY-2026-0002… */
    public static function siguienteFolio(): string
    {
        $prefijo  = 'PRY-' . date('Y') . '-';
        $posicion = strlen($prefijo) + 1;
        $patron   = $prefijo . '%';

        $consulta = self::$db->prepare(
            'SELECT MAX(CAST(SUBSTRING(folio, ?) AS UNSIGNED)) AS maximo
               FROM proyectos_entrega
              WHERE folio LIKE ?'
        );
        $consulta->bind_param('is', $posicion, $patron);
        $consulta->execute();
        $maximo = (int) ($consulta->get_result()->fetch_assoc()['maximo'] ?? 0);
        $consulta->close();

        return $prefijo . str_pad((string) ($maximo + 1), 4, '0', STR_PAD_LEFT);
    }

    /* --------------------------------------------------------------------
     * VALIDACIÓN
     * ------------------------------------------------------------------ */

    /**
     * Valida la cabecera y las partidas.
     *
     * @param array<string, mixed> $datos
     * @param array<int, array<string, mixed>> $partidas
     * @return string[]
     */
    public static function validar(array $datos, array $partidas): array
    {
        self::$errores = [];

        $clienteId = (int) ($datos['cliente_id'] ?? 0);
        $empresaId = (int) ($datos['empresa_id'] ?? 0);
        $nombre    = trim((string) ($datos['nombre'] ?? ''));

        if ($nombre === '') {
            self::$errores[] = 'El nombre del proyecto es obligatorio.';
        } elseif (mb_strlen($nombre) > 160) {
            self::$errores[] = 'El nombre del proyecto no puede pasar de 160 caracteres.';
        }

        if ($clienteId <= 0 || !Clientes::buscarID($clienteId)) {
            self::$errores[] = 'Elige un cliente válido.';
        }
        $empresa = $empresaId > 0 ? Empresas::buscarID($empresaId) : null;
        if (!$empresa) {
            self::$errores[] = 'Elige un negocio válido.';
        }

        $inicio = trim((string) ($datos['fecha_inicio'] ?? ''));
        $compromiso = trim((string) ($datos['fecha_compromiso'] ?? ''));
        if ($inicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) {
            self::$errores[] = 'La fecha de inicio no es válida.';
        }
        if ($compromiso !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $compromiso)) {
            self::$errores[] = 'La fecha compromiso no es válida.';
        }
        if ($inicio !== '' && $compromiso !== '' && $compromiso < $inicio) {
            self::$errores[] = 'La fecha compromiso no puede ser anterior a la fecha de inicio.';
        }

        $estado = (string) ($datos['estado'] ?? 'activo');
        if (!isset(self::ESTADOS[$estado])) {
            self::$errores[] = 'El estado del proyecto no es válido.';
        }

        if (!$partidas) {
            self::$errores[] = 'Agrega al menos un producto al proyecto.';
        }

        $productosVistos = [];
        foreach ($partidas as $indice => $partida) {
            $productoId = (int) ($partida['producto_id'] ?? 0);
            $cantidad   = (int) ($partida['cantidad_comprometida'] ?? 0);

            if ($productoId <= 0) {
                self::$errores[] = 'La partida ' . ($indice + 1) . ' no tiene un producto válido.';
                continue;
            }
            if (isset($productosVistos[$productoId])) {
                self::$errores[] = 'El mismo producto no puede repetirse en el proyecto.';
                continue;
            }
            $productosVistos[$productoId] = true;

            if ($cantidad <= 0) {
                self::$errores[] = 'La cantidad comprometida debe ser mayor que cero.';
            }

            $consulta = self::$db->prepare(
                'SELECT p.id, p.activo, p.empresa_id,
                        (SELECT COUNT(*) FROM producto_empresa pe
                          WHERE pe.producto_id = p.id AND pe.empresa_id = ?) AS en_empresa
                   FROM productos p
                  WHERE p.id = ? LIMIT 1'
            );
            $consulta->bind_param('ii', $empresaId, $productoId);
            $consulta->execute();
            $producto = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$producto || (int) $producto['activo'] !== 1) {
                self::$errores[] = 'Uno de los productos del proyecto ya no está activo.';
            } elseif ((int) $producto['en_empresa'] === 0 && (int) $producto['empresa_id'] !== $empresaId) {
                self::$errores[] = 'Uno de los productos no pertenece al negocio elegido.';
            }
        }

        return self::$errores;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

    /**
     * Crea un proyecto y sus partidas dentro de una transacción.
     *
     * @param array<string, mixed> $datos
     * @param array<int, array<string, mixed>> $partidas
     */
    public static function crear(array $datos, array $partidas, int $usuarioId): bool
    {
        $errores = self::validar($datos, $partidas);
        if ($errores) {
            self::$errores = $errores;
            return false;
        }

        $intentos = 0;
        do {
            $folio = self::siguienteFolio();
            try {
                self::$db->begin_transaction();

                $proyecto = new self($datos);
                $proyecto->folio = $folio;
                $proyecto->usuario_id = $usuarioId;
                $proyecto->estado = (string) ($datos['estado'] ?? 'activo');

                $atributos = $proyecto->atributos();
                $columnas = array_keys($atributos);
                $columnas[] = 'folio';
                $valores = array_values($atributos);
                $valores[] = $folio;
                $marcadores = implode(', ', array_fill(0, count($columnas), '?'));

                $consulta = self::$db->prepare(
                    'INSERT INTO proyectos_entrega (' . implode(', ', $columnas) . ') VALUES (' . $marcadores . ')'
                );
                $consulta->bind_param(str_repeat('s', count($valores)), ...$valores);
                $consulta->execute();
                $consulta->close();

                $proyecto->id = (int) self::$db->insert_id;
                if ($proyecto->id <= 0 || !self::guardarPartidas($proyecto->id, $partidas)) {
                    self::$db->rollBack();
                    return false;
                }

                self::$db->commit();
                self::$ultimoId = $proyecto->id;
                return true;
            } catch (\mysqli_sql_exception $excepcion) {
                self::$db->rollBack();
                $intentos++;
                if ($intentos < 3 && strpos($excepcion->getMessage(), 'Duplicate') !== false) {
                    continue;
                }
                error_log('[ProyectosEntrega::crear] ' . $excepcion->getMessage());
                self::$errores[] = 'No fue posible guardar el proyecto.';
                return false;
            }
        } while ($intentos < 3);

        self::$errores[] = 'No fue posible asignar un folio al proyecto.';
        return false;
    }

    /**
     * Actualiza la cabecera y las partidas.
     *
     * @param array<string, mixed> $datos
     * @param array<int, array<string, mixed>> $partidas
     */
    public static function actualizar(int $id, array $datos, array $partidas, int $usuarioId): bool
    {
        $errores = self::validar($datos, $partidas);
        if ($errores) {
            self::$errores = $errores;
            return false;
        }

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT id FROM proyectos_entrega WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $id);
            $bloqueo->execute();
            $bloqueo->close();

            $actual = self::buscarID($id);
            if (!$actual) {
                self::$db->rollBack();
                self::$errores[] = 'El proyecto ya no existe.';
                return false;
            }

            $cambiaCliente  = (int) $actual->cliente_id !== (int) ($datos['cliente_id'] ?? 0);
            $cambiaEmpresa  = (int) $actual->empresa_id !== (int) ($datos['empresa_id'] ?? 0);
            if (($cambiaCliente || $cambiaEmpresa) && self::tieneVentas($id)) {
                self::$db->rollBack();
                self::$errores[] = 'No puedes cambiar el cliente o el negocio de un proyecto con ventas vinculadas. Desvincula las ventas primero.';
                return false;
            }

            $proyecto = new self($datos);
            $proyecto->id = $id;
            // El autor original no se pisa al editar; el usuario que edita queda en la auditoría general.
            $proyecto->usuario_id = (int) $actual->usuario_id;
            $atributos = $proyecto->atributos();

            $asignaciones = [];
            foreach (array_keys($atributos) as $columna) {
                $asignaciones[] = $columna . ' = ?';
            }
            $valores = array_values($atributos);
            $valores[] = $id;

            $consulta = self::$db->prepare(
                'UPDATE proyectos_entrega SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
            );
            $consulta->bind_param(str_repeat('s', count($atributos)) . 'i', ...$valores);
            $consulta->execute();
            $consulta->close();

            if (!self::guardarPartidas($id, $partidas, true)) {
                self::$db->rollBack();
                return false;
            }

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[ProyectosEntrega::actualizar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible actualizar el proyecto.';
            return false;
        }
    }

    /**
     * Lee las partidas que llegan del formulario (arrays paralelos).
     *
     * @param array<string, mixed> $post
     * @return array<int, array{producto_id: int, cantidad_comprometida: int,
     *               precio_unitario: string|null, notas: string}>
     */
    public static function leerPartidasPost(array $post): array
    {
        $productos  = (array) ($post['producto_id'] ?? []);
        $cantidades = (array) ($post['cantidad_comprometida'] ?? []);
        $precios    = (array) ($post['precio_unitario'] ?? []);
        $notas      = (array) ($post['partida_notas'] ?? []);
        $partidas   = [];

        foreach ($productos as $indice => $productoId) {
            $productoId = (int) $productoId;
            if ($productoId <= 0) {
                continue;
            }
            $precio = isset($precios[$indice]) && trim((string) $precios[$indice]) !== ''
                ? trim((string) $precios[$indice])
                : null;
            $partidas[] = [
                'producto_id'          => $productoId,
                'cantidad_comprometida'=> max(1, (int) ($cantidades[$indice] ?? 1)),
                'precio_unitario'      => $precio,
                'notas'                => trim((string) ($notas[$indice] ?? '')),
            ];
        }

        return $partidas;
    }

    /** Atributos persistibles (sin id ni folio). */
    public function atributos(): array
    {
        $atributos = [];
        foreach (self::$columnasDB as $columna) {
            $valor = $this->$columna;
            if ($columna === 'fecha_inicio' || $columna === 'fecha_compromiso') {
                $valor = trim((string) $valor) !== '' ? trim((string) $valor) : null;
            }
            $atributos[$columna] = $valor;
        }

        return $atributos;
    }

    /**
     * Guarda las partidas. En edición, impide quitar un producto que ya tiene
     * ventas o entregas vinculadas.
     *
     * @param array<int, array<string, mixed>> $partidas
     */
    private static function guardarPartidas(int $proyectoId, array $partidas, bool $esEdicion = false): bool
    {
        if ($esEdicion) {
            $progreso = self::progreso($proyectoId, false);
            $conMovimiento = [];
            foreach ($progreso['partidas'] as $partida) {
                if ((int) $partida['vendido'] > 0 || (int) $partida['entregado'] > 0) {
                    $conMovimiento[(int) $partida['producto_id']] = $partida['nombre'];
                }
            }

            $nuevos = [];
            foreach ($partidas as $partida) {
                $nuevos[(int) ($partida['producto_id'] ?? 0)] = true;
            }

            foreach ($conMovimiento as $productoId => $nombre) {
                if (!isset($nuevos[$productoId])) {
                    self::$errores[] = 'No puedes quitar la partida «' . $nombre . '» porque ya tiene ventas o entregas vinculadas.';
                    return false;
                }
            }
        }

        $consulta = self::$db->prepare(
            'INSERT INTO proyecto_entrega_partidas
                (proyecto_id, producto_id, cantidad_comprometida, precio_unitario, notas)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                cantidad_comprometida = VALUES(cantidad_comprometida),
                precio_unitario = VALUES(precio_unitario),
                notas = VALUES(notas)'
        );

        foreach ($partidas as $partida) {
            $productoId = (int) ($partida['producto_id'] ?? 0);
            $cantidad   = max(1, (int) ($partida['cantidad_comprometida'] ?? 0));
            $precio     = $partida['precio_unitario'] === '' || $partida['precio_unitario'] === null
                ? null
                : max(0, round((float) $partida['precio_unitario'], 2));
            $notas      = trim((string) ($partida['notas'] ?? ''));

            $consulta->bind_param('iiiss', $proyectoId, $productoId, $cantidad, $precio, $notas);
            if (!$consulta->execute()) {
                $consulta->close();
                self::$errores[] = 'No fue posible guardar una partida del proyecto.';
                return false;
            }
        }
        $consulta->close();

        if ($esEdicion) {
            $ids = array_map(static fn (array $p): int => (int) ($p['producto_id'] ?? 0), $partidas);
            $ids = array_values(array_filter($ids));
            if ($ids) {
                $marcadores = implode(',', array_fill(0, count($ids), '?'));
                $tipos = str_repeat('i', count($ids)) . 'i';
                $valores = array_merge($ids, [$proyectoId]);
                $borrar = self::$db->prepare(
                    'DELETE FROM proyecto_entrega_partidas
                      WHERE producto_id NOT IN (' . $marcadores . ') AND proyecto_id = ?'
                );
                $borrar->bind_param($tipos, ...$valores);
                $borrar->execute();
                $borrar->close();
            }
        }

        return true;
    }

    /**
     * Vincula una venta al proyecto.
     */
    public static function vincularVenta(int $proyectoId, int $ventaId, int $usuarioId): bool
    {
        self::$errores = [];

        $proyecto = self::buscarID($proyectoId);
        if (!$proyecto) {
            self::$errores[] = 'El proyecto no existe.';
            return false;
        }
        if ($proyecto->estado === 'cancelado') {
            self::$errores[] = 'No se pueden vincular ventas a un proyecto cancelado.';
            return false;
        }

        $consulta = self::$db->prepare(
            'SELECT id, cliente_id, empresa_id, estado, proyecto_id, folio
               FROM ventas WHERE id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $venta = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$venta) {
            self::$errores[] = 'La venta no existe.';
            return false;
        }
        if ($venta['estado'] !== 'registrada') {
            self::$errores[] = 'Sólo se pueden vincular ventas registradas.';
            return false;
        }
        if ((int) $venta['cliente_id'] !== (int) $proyecto->cliente_id) {
            self::$errores[] = 'La venta pertenece a otro cliente.';
            return false;
        }
        if ((int) $venta['empresa_id'] !== (int) $proyecto->empresa_id) {
            self::$errores[] = 'La venta pertenece a otro negocio.';
            return false;
        }
        if ($venta['proyecto_id'] !== null && (int) $venta['proyecto_id'] !== $proyectoId) {
            self::$errores[] = 'La venta ya está vinculada a otro proyecto.';
            return false;
        }

        $actualizar = self::$db->prepare(
            'UPDATE ventas SET proyecto_id = ? WHERE id = ? AND (proyecto_id IS NULL OR proyecto_id = ?)'
        );
        $actualizar->bind_param('iii', $proyectoId, $ventaId, $proyectoId);
        $correcto = $actualizar->execute();
        $actualizar->close();

        if (!$correcto) {
            self::$errores[] = 'No fue posible vincular la venta.';
            return false;
        }

        return true;
    }

    /** Desvincula una venta del proyecto. */
    public static function desvincularVenta(int $proyectoId, int $ventaId, int $usuarioId): bool
    {
        self::$errores = [];

        $consulta = self::$db->prepare(
            'UPDATE ventas SET proyecto_id = NULL WHERE id = ? AND proyecto_id = ?'
        );
        $consulta->bind_param('ii', $ventaId, $proyectoId);
        $correcto = $consulta->execute();
        $consulta->close();

        if (!$correcto) {
            self::$errores[] = 'No fue posible desvincular la venta.';
            return false;
        }

        return true;
    }

    /** Cambia el estado del proyecto. */
    public static function cambiarEstado(int $id, string $estado, int $usuarioId): bool
    {
        self::$errores = [];

        if (!isset(self::ESTADOS[$estado])) {
            self::$errores[] = 'El estado no es válido.';
            return false;
        }

        $consulta = self::$db->prepare('UPDATE proyectos_entrega SET estado = ? WHERE id = ?');
        $consulta->bind_param('si', $estado, $id);
        $consulta->execute();
        $consulta->close();

        return true;
    }

    /** ¿El proyecto tiene alguna venta vinculada, incluso cancelada? */
    public static function tieneVentas(int $id): bool
    {
        $consulta = self::$db->prepare('SELECT COUNT(*) AS total FROM ventas WHERE proyecto_id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $total = (int) ($consulta->get_result()->fetch_assoc()['total'] ?? 0);
        $consulta->close();

        return $total > 0;
    }

    /** Elimina un proyecto sólo si no tiene ventas vinculadas. */
    public static function eliminar(int $id, int $usuarioId): bool
    {
        self::$errores = [];

        $consulta = self::$db->prepare('SELECT COUNT(*) AS total FROM ventas WHERE proyecto_id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $total = (int) ($consulta->get_result()->fetch_assoc()['total'] ?? 0);
        $consulta->close();

        if ($total > 0) {
            self::$errores[] = 'No puedes eliminar un proyecto con ventas vinculadas. Cancélalo para conservar el historial.';
            return false;
        }

        $consulta = self::$db->prepare('DELETE FROM proyectos_entrega WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $consulta->close();

        return true;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Carga un proyecto con los datos del cliente y del negocio. */
    public static function buscarID(int $id): ?self
    {
        if ($id <= 0) {
            return null;
        }

        $consulta = self::$db->prepare(
            'SELECT p.*,
                    cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    cl.alias AS cliente_alias, cl.rfc AS cliente_rfc,
                    cl.telefono AS cliente_telefono, cl.email AS cliente_email,
                    cl.ubicacion AS cliente_ubicacion,
                    e.nombre AS empresa_nombre,
                    u.nombre AS usuario_nombre
               FROM proyectos_entrega p
               JOIN clientes cl  ON cl.id = p.cliente_id
               JOIN empresas e   ON e.id = p.empresa_id
               LEFT JOIN usuarios u ON u.id = p.usuario_id
              WHERE p.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? self::crearObjeto($fila) : null;
    }

    /**
     * Listado paginado de proyectos.
     *
     * @param array{q?: string, cliente_id?: int, empresa_id?: int, estado?: string,
     *              anio?: int, vencidos?: int} $filtros
     * @return self[]
     */
    public static function listar(array $filtros = [], int $limite = 15, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $limite = max(1, $limite);
        $offset = max(0, $offset);

        $consulta = self::$db->prepare(
            'SELECT p.*,
                    cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    cl.alias AS cliente_alias, cl.rfc AS cliente_rfc,
                    cl.telefono AS cliente_telefono, cl.email AS cliente_email,
                    cl.ubicacion AS cliente_ubicacion,
                    e.nombre AS empresa_nombre,
                    u.nombre AS usuario_nombre
               FROM proyectos_entrega p
               JOIN clientes cl  ON cl.id = p.cliente_id
               JOIN empresas e   ON e.id = p.empresa_id
               LEFT JOIN usuarios u ON u.id = p.usuario_id
              WHERE ' . $donde . '
              ORDER BY FIELD(p.estado, "activo", "pausado", "completado", "cancelado"), p.fecha_compromiso IS NULL, p.fecha_compromiso ASC, p.id DESC
              LIMIT ? OFFSET ?'
        );
        $tipos .= 'ii';
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return array_map([self::class, 'crearObjeto'], $filas);
    }

    /** Cuenta los proyectos que coinciden con los filtros. */
    public static function contar(array $filtros = []): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total
               FROM proyectos_entrega p
               JOIN clientes cl ON cl.id = p.cliente_id
               JOIN empresas e  ON e.id = p.empresa_id
              WHERE ' . $donde
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $total = (int) ($consulta->get_result()->fetch_assoc()['total'] ?? 0);
        $consulta->close();

        return $total;
    }

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
    private static function condiciones(array $filtros): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $donde[] = '(p.folio LIKE ? OR p.nombre LIKE ? OR p.descripcion LIKE ?
                         OR cl.nombre LIKE ? OR IFNULL(cl.nombre_empresa, "") LIKE ? OR IFNULL(cl.alias, "") LIKE ?)';
            $tipos .= 'ssssss';
            $patron = '%' . $q . '%';
            array_push($valores, $patron, $patron, $patron, $patron, $patron, $patron);
        }

        $clienteId = (int) ($filtros['cliente_id'] ?? 0);
        if ($clienteId > 0) {
            $donde[] = 'p.cliente_id = ?';
            $tipos .= 'i';
            $valores[] = $clienteId;
        }

        $empresaId = (int) ($filtros['empresa_id'] ?? 0);
        if ($empresaId > 0) {
            $donde[] = 'p.empresa_id = ?';
            $tipos .= 'i';
            $valores[] = $empresaId;
        }

        $estado = (string) ($filtros['estado'] ?? '');
        if (isset(self::ESTADOS[$estado])) {
            $donde[] = 'p.estado = ?';
            $tipos .= 's';
            $valores[] = $estado;
        }

        $anio = (int) ($filtros['anio'] ?? 0);
        if ($anio > 0) {
            $donde[] = 'COALESCE(YEAR(p.fecha_inicio), YEAR(p.creado_en)) = ?';
            $tipos .= 'i';
            $valores[] = $anio;
        }

        if (!empty($filtros['vencidos'])) {
            $donde[] = 'p.fecha_compromiso IS NOT NULL AND p.fecha_compromiso < CURDATE()
                        AND p.estado IN ("activo", "pausado")';
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /** Partidas del proyecto con datos del producto. */
    public static function partidas(int $proyectoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT pp.*, p.sku, p.nombre, p.unidad, p.imagen, p.precio AS precio_lista
               FROM proyecto_entrega_partidas pp
               JOIN productos p ON p.id = pp.producto_id
              WHERE pp.proyecto_id = ?
              ORDER BY p.nombre ASC'
        );
        $consulta->bind_param('i', $proyectoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Avance del proyecto calculado con ventas y entregas reales.
     *
     * @return array<string, mixed>
     */
    public static function progreso(int $proyectoId, bool $conDetalle = true): array
    {
        $partidas = self::partidas($proyectoId);

        $vendido = self::mapaPorProducto(
            'SELECT d.producto_id, COALESCE(SUM(d.cantidad), 0) AS total
               FROM venta_detalles d
               JOIN ventas v ON v.id = d.venta_id
              WHERE v.proyecto_id = ? AND v.estado = "registrada"
              GROUP BY d.producto_id',
            $proyectoId
        );
        $entregado = self::mapaPorProducto(
            'SELECT er.producto_id, COALESCE(SUM(er.cantidad), 0) AS total
               FROM entrega_renglones er
               JOIN entregas en ON en.id = er.entrega_id
               JOIN ventas v ON v.id = en.venta_id
              WHERE v.proyecto_id = ? AND v.estado = "registrada"
              GROUP BY er.producto_id',
            $proyectoId
        );
        $devuelto = self::mapaPorProducto(
            'SELECT dv.producto_id, COALESCE(SUM(dv.cantidad_devuelta), 0) AS total
               FROM venta_devoluciones dv
               JOIN ventas v ON v.id = dv.venta_id
              WHERE v.proyecto_id = ? AND v.estado = "registrada"
              GROUP BY dv.producto_id',
            $proyectoId
        );

        $filas = [];
        $productosEnPartidas = [];
        $unidades = [];
        $totales = [
            'comprometido' => 0, 'vendido' => 0, 'entregado' => 0, 'devuelto' => 0,
            'por_entregar' => 0, 'por_vender' => 0, 'excedente' => 0,
        ];

        foreach ($partidas as $partida) {
            $productoId = (int) $partida['producto_id'];
            $productosEnPartidas[$productoId] = true;
            $comprometido = (int) $partida['cantidad_comprometida'];
            $vendidoP = (int) ($vendido[$productoId] ?? 0);
            $entregadoP = (int) ($entregado[$productoId] ?? 0);
            $devueltoP = (int) ($devuelto[$productoId] ?? 0);

            $porEntregar = max(0, $comprometido - $entregadoP);
            $porVender = max(0, $comprometido - $vendidoP);
            $excedente = max(0, $entregadoP - $comprometido);
            $avance = $comprometido > 0 ? min(100, round($entregadoP / $comprometido * 100, 1)) : 0;

            $filas[] = [
                'producto_id'         => $productoId,
                'sku'                 => (string) ($partida['sku'] ?? ''),
                'nombre'              => (string) $partida['nombre'],
                'unidad'              => (string) ($partida['unidad'] ?? 'pza'),
                'imagen'              => (string) ($partida['imagen'] ?? ''),
                'precio_unitario'     => $partida['precio_unitario'],
                'comprometido'        => $comprometido,
                'vendido'             => $vendidoP,
                'entregado'           => $entregadoP,
                'devuelto'            => $devueltoP,
                'por_entregar'        => $porEntregar,
                'por_vender'          => $porVender,
                'excedente'           => $excedente,
                'avance'              => $avance,
                'notas'               => (string) ($partida['notas'] ?? ''),
            ];

            $unidades[(string) ($partida['unidad'] ?? 'pza')] = true;
            $totales['comprometido'] += $comprometido;
            $totales['vendido']      += $vendidoP;
            $totales['entregado']    += $entregadoP;
            $totales['devuelto']     += $devueltoP;
            $totales['por_entregar'] += $porEntregar;
            $totales['por_vender']   += $porVender;
            $totales['excedente']    += $excedente;
        }

        $extras = [];
        if ($conDetalle) {
            foreach ($vendido as $productoId => $cantidadVendida) {
                if (isset($productosEnPartidas[$productoId])) {
                    continue;
                }
                $producto = self::producto((int) $productoId);
                if (!$producto) {
                    continue;
                }
                $extras[] = [
                    'producto_id' => (int) $productoId,
                    'sku'         => (string) ($producto['sku'] ?? ''),
                    'nombre'      => (string) $producto['nombre'],
                    'unidad'      => (string) ($producto['unidad'] ?? 'pza'),
                    'vendido'     => (int) $cantidadVendida,
                    'entregado'   => (int) ($entregado[$productoId] ?? 0),
                    'devuelto'    => (int) ($devuelto[$productoId] ?? 0),
                ];
            }
        }

        $totales['avance'] = $totales['comprometido'] > 0
            ? min(100, round($totales['entregado'] / $totales['comprometido'] * 100, 1))
            : 0;
        $totales['unidad_comun'] = count($unidades) === 1 ? array_key_first($unidades) : null;

        return [
            'partidas' => $filas,
            'extras'   => $extras,
            'totales'  => $totales,
            'ventas'   => $conDetalle ? self::ventasVinculadas($proyectoId) : [],
            'entregas' => $conDetalle ? self::entregasVinculadas($proyectoId) : [],
        ];
    }

    /** @return array<int, int> producto_id => total */
    private static function mapaPorProducto(string $sql, int $proyectoId): array
    {
        $consulta = self::$db->prepare($sql);
        $consulta->bind_param('i', $proyectoId);
        $consulta->execute();
        $mapa = [];
        foreach ($consulta->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
            $mapa[(int) $fila['producto_id']] = (int) $fila['total'];
        }
        $consulta->close();

        return $mapa;
    }

    /** Datos mínimos de un producto. */
    private static function producto(int $productoId): ?array
    {
        $consulta = self::$db->prepare('SELECT id, sku, nombre, unidad, imagen FROM productos WHERE id = ? LIMIT 1');
        $consulta->bind_param('i', $productoId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** Ventas vinculadas al proyecto. */
    public static function ventasVinculadas(int $proyectoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT v.id, v.folio, v.fecha, v.total, v.entrega_estado, v.creado_en,
                    e.nombre AS empresa_nombre,
                    (SELECT COALESCE(SUM(d.cantidad), 0) FROM venta_detalles d WHERE d.venta_id = v.id) AS piezas,
                    (SELECT COALESCE(SUM(er.cantidad), 0)
                       FROM entrega_renglones er
                       JOIN entregas en ON en.id = er.entrega_id
                      WHERE en.venta_id = v.id) AS entregadas
               FROM ventas v
               JOIN empresas e ON e.id = v.empresa_id
              WHERE v.proyecto_id = ? AND v.estado = "registrada"
              ORDER BY v.fecha ASC, v.id ASC'
        );
        $consulta->bind_param('i', $proyectoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Entregas registradas de las ventas del proyecto. */
    public static function entregasVinculadas(int $proyectoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT en.id, en.fecha, en.creado_en, en.notas, en.recibio_nombre, en.origen,
                    v.id AS venta_id, v.folio,
                    (SELECT COALESCE(SUM(er.cantidad), 0) FROM entrega_renglones er WHERE er.entrega_id = en.id) AS piezas
               FROM entregas en
               JOIN ventas v ON v.id = en.venta_id
              WHERE v.proyecto_id = ? AND v.estado = "registrada"
              ORDER BY en.fecha DESC, en.id DESC'
        );
        $consulta->bind_param('i', $proyectoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Ventas del mismo cliente y negocio que todavía no tienen proyecto.
     *
     * @param array{q?: string, desde?: string, hasta?: string} $filtros
     */
    public static function ventasDisponibles(int $proyectoId, array $filtros = [], int $limite = 100, int $offset = 0): array
    {
        $proyecto = self::buscarID($proyectoId);
        if (!$proyecto) {
            return [];
        }

        $donde = [
            'v.cliente_id = ?', 'v.empresa_id = ?', 'v.estado = "registrada"',
            'v.proyecto_id IS NULL',
        ];
        $tipos = 'ii';
        $valores = [(int) $proyecto->cliente_id, (int) $proyecto->empresa_id];

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $donde[] = 'v.folio LIKE ?';
            $tipos .= 's';
            $valores[] = '%' . $q . '%';
        }
        $desde = (string) ($filtros['desde'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $donde[] = 'v.fecha >= ?';
            $tipos .= 's';
            $valores[] = $desde;
        }
        $hasta = (string) ($filtros['hasta'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            $donde[] = 'v.fecha <= ?';
            $tipos .= 's';
            $valores[] = $hasta;
        }

        $limite = max(1, $limite);
        $offset = max(0, $offset);
        $tipos .= 'ii';
        $valores[] = $limite;
        $valores[] = $offset;

        $consulta = self::$db->prepare(
            'SELECT v.id, v.folio, v.fecha, v.total, v.entrega_estado, v.proyecto_id,
                    (SELECT COALESCE(SUM(d.cantidad), 0) FROM venta_detalles d WHERE d.venta_id = v.id) AS piezas,
                    (SELECT COALESCE(SUM(er.cantidad), 0)
                       FROM entrega_renglones er
                       JOIN entregas en ON en.id = er.entrega_id
                      WHERE en.venta_id = v.id) AS entregadas
               FROM ventas v
              WHERE ' . implode(' AND ', $donde) . '
              ORDER BY v.fecha DESC, v.id DESC
              LIMIT ? OFFSET ?'
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Proyectos del mismo cliente y negocio que se pueden asignar a una venta.
     */
    public static function proyectosDisponiblesParaVenta(int $ventaId): array
    {
        $consulta = self::$db->prepare(
            'SELECT v.cliente_id, v.empresa_id, v.proyecto_id
               FROM ventas v WHERE v.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $venta = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$venta) {
            return [];
        }

        $consulta = self::$db->prepare(
            'SELECT p.*,
                    cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    cl.alias AS cliente_alias, cl.rfc AS cliente_rfc,
                    cl.telefono AS cliente_telefono, cl.email AS cliente_email,
                    cl.ubicacion AS cliente_ubicacion,
                    e.nombre AS empresa_nombre
               FROM proyectos_entrega p
               JOIN clientes cl ON cl.id = p.cliente_id
               JOIN empresas e  ON e.id = p.empresa_id
              WHERE p.cliente_id = ? AND p.empresa_id = ?
                AND p.estado IN ("activo", "pausado")
              ORDER BY p.fecha_compromiso IS NULL, p.fecha_compromiso ASC, p.id DESC'
        );
        $consulta->bind_param('ii', $venta['cliente_id'], $venta['empresa_id']);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return array_map([self::class, 'crearObjeto'], $filas);
    }

    /** Convierte un registro asociativo en una instancia. */
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
}
