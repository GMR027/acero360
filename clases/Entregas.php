<?php

namespace App;

/**
 * Entregas — preparación de pedidos y entregas a clientes.
 *
 * Trabaja SOLO con ventas confirmadas (tabla `ventas`: directas y nacidas de
 * una cotización) y NUNCA toca el inventario: la salida de almacén ya ocurrió
 * al registrar la venta.
 *
 * Conceptos:
 *   • Preparación: lote de ventas que se alista junto; los productos iguales
 *     se agrupan y suman. Estados: pendiente → en preparación → preparada →
 *     en reparto → entregada (o cancelada).
 *   • Entrega: piezas realmente entregadas de UNA venta (total o parcial).
 *     Pendiente = vendido − entregado, por producto.
 *   • Una venta no se agrega a dos preparaciones activas sin advertirlo:
 *     crearPreparacion() devuelve los conflictos y exige confirmación.
 */
class Entregas
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** @var array<int, array{venta: string, preparaciones: string}> Ventas ya incluidas en otra preparación activa. */
    public static $conflictos = [];

    /** Id de la última preparación creada. */
    public static $ultimoId = 0;

    public const ESTADOS = [
        'pendiente'      => ['etiqueta' => 'Pendiente de preparación', 'icono' => 'schedule',        'clase' => 'badge--pendiente'],
        'en_preparacion' => ['etiqueta' => 'En preparación',           'icono' => 'inventory',       'clase' => 'badge--info'],
        'preparada'      => ['etiqueta' => 'Preparado',                'icono' => 'task_alt',        'clase' => 'badge--info'],
        'en_reparto'     => ['etiqueta' => 'En reparto',               'icono' => 'local_shipping',  'clase' => 'badge--info'],
        'entregada'      => ['etiqueta' => 'Entregado',                'icono' => 'check_circle',    'clase' => 'badge--aprobado'],
        'cancelada'      => ['etiqueta' => 'Cancelada',                'icono' => 'cancel',          'clase' => 'badge--error'],
    ];

    /** Estados en los que la preparación sigue viva. */
    public const ACTIVOS = ['pendiente', 'en_preparacion', 'preparada', 'en_reparto'];

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
     * PENDIENTES POR VENTA
     * ------------------------------------------------------------------ */

    /**
     * Vendido, entregado y pendiente por producto para varias ventas.
     *
     * @param int[] $ventaIds
     * @return array<int, array<int, array<string, mixed>>> venta_id => producto_id => datos
     */
    public static function pendientesDeVentas(array $ventaIds): array
    {
        $ventaIds = array_values(array_unique(array_filter(array_map('intval', $ventaIds))));
        if (!$ventaIds) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ventaIds), '?'));
        $tipos = str_repeat('i', count($ventaIds));

        $consulta = self::$db->prepare(
            "SELECT vd.venta_id, vd.producto_id, SUM(vd.cantidad) AS vendido,
                    p.sku, p.nombre, p.imagen, p.unidad
               FROM venta_detalles vd
               JOIN productos p ON p.id = vd.producto_id
              WHERE vd.venta_id IN ($marcadores)
              GROUP BY vd.venta_id, vd.producto_id, p.sku, p.nombre, p.imagen, p.unidad
              ORDER BY p.sku, p.nombre"
        );
        $consulta->bind_param($tipos, ...$ventaIds);
        $consulta->execute();

        $resultado = [];
        foreach ($consulta->get_result() as $fila) {
            $fila['vendido']   = (int) $fila['vendido'];
            $fila['entregado'] = 0;
            $fila['pendiente'] = $fila['vendido'];
            $resultado[(int) $fila['venta_id']][(int) $fila['producto_id']] = $fila;
        }
        $consulta->close();

        $consulta = self::$db->prepare(
            "SELECT en.venta_id, er.producto_id, SUM(er.cantidad) AS entregado
               FROM entrega_renglones er
               JOIN entregas en ON en.id = er.entrega_id
              WHERE en.venta_id IN ($marcadores)
              GROUP BY en.venta_id, er.producto_id"
        );
        $consulta->bind_param($tipos, ...$ventaIds);
        $consulta->execute();
        foreach ($consulta->get_result() as $fila) {
            $v = (int) $fila['venta_id'];
            $p = (int) $fila['producto_id'];
            if (isset($resultado[$v][$p])) {
                $resultado[$v][$p]['entregado'] = (int) $fila['entregado'];
                $resultado[$v][$p]['pendiente'] = $resultado[$v][$p]['vendido'] - (int) $fila['entregado'];
            }
        }
        $consulta->close();

        // Una venta con la entrega cerrada (p. ej. por una devolución) ya no debe nada.
        $consulta = self::$db->prepare(
            "SELECT id FROM ventas WHERE id IN ($marcadores) AND entrega_cerrada_en IS NOT NULL"
        );
        $consulta->bind_param($tipos, ...$ventaIds);
        $consulta->execute();
        foreach ($consulta->get_result() as $fila) {
            foreach ($resultado[(int) $fila['id']] ?? [] as $p => $_) {
                $resultado[(int) $fila['id']][$p]['pendiente'] = 0;
            }
        }
        $consulta->close();

        return $resultado;
    }

    /* --------------------------------------------------------------------
     * VENTAS DISPONIBLES PARA PREPARAR
     * ------------------------------------------------------------------ */

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
    private static function condicionesVentas(array $filtros): array
    {
        $donde   = ['v.estado = "registrada"', 'v.entrega_cerrada_en IS NULL'];
        $tipos   = '';
        $valores = [];

        $termino = trim((string) ($filtros['q'] ?? ''));
        if ($termino !== '') {
            $donde[] = '(v.folio LIKE ? OR cl.nombre LIKE ? OR IFNULL(cl.nombre_empresa, "") LIKE ? OR IFNULL(cl.alias, "") LIKE ?)';
            $tipos  .= 'ssss';
            $patron  = '%' . $termino . '%';
            array_push($valores, $patron, $patron, $patron, $patron);
        }
        if ((int) ($filtros['empresa'] ?? 0) > 0) {
            $donde[]   = 'v.empresa_id = ?';
            $tipos    .= 'i';
            $valores[] = (int) $filtros['empresa'];
        }
        if ((int) ($filtros['cliente'] ?? 0) > 0) {
            $donde[]   = 'v.cliente_id = ?';
            $tipos    .= 'i';
            $valores[] = (int) $filtros['cliente'];
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    private static function sqlVentasPendientes(string $donde): string
    {
        $activos = "'" . implode("','", self::ACTIVOS) . "'";

        return "SELECT v.id, v.folio, v.fecha, v.total, v.cotizacion_id,
                       e.nombre AS empresa_nombre,
                       cl.id AS cliente_id, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                       cl.ubicacion AS cliente_ubicacion,
                       (SELECT COALESCE(SUM(cantidad), 0) FROM venta_detalles WHERE venta_id = v.id) AS piezas,
                       (SELECT COALESCE(SUM(er.cantidad), 0) FROM entrega_renglones er
                          JOIN entregas en ON en.id = er.entrega_id WHERE en.venta_id = v.id) AS entregadas,
                       (SELECT GROUP_CONCAT(p.folio ORDER BY p.id SEPARATOR ', ') FROM preparacion_ventas pv
                          JOIN preparaciones p ON p.id = pv.preparacion_id
                         WHERE pv.venta_id = v.id AND p.estado IN ($activos)) AS en_preparaciones
                  FROM ventas v
                  JOIN empresas e  ON e.id  = v.empresa_id
                  JOIN clientes cl ON cl.id = v.cliente_id
                 WHERE $donde
                HAVING piezas > entregadas";
    }

    /**
     * Ventas confirmadas con piezas por entregar.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ventasPendientes(array $filtros = [], int $limite = 15, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condicionesVentas($filtros);

        $consulta = self::$db->prepare(
            self::sqlVentasPendientes($donde) . ' ORDER BY v.fecha DESC, v.id DESC LIMIT ? OFFSET ?'
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    public static function contarVentasPendientes(array $filtros = []): int
    {
        [$donde, $tipos, $valores] = self::condicionesVentas($filtros);

        $consulta = self::$db->prepare('SELECT COUNT(*) AS n FROM (' . self::sqlVentasPendientes($donde) . ') t');
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $n;
    }

    /* --------------------------------------------------------------------
     * PREPARACIONES
     * ------------------------------------------------------------------ */

    /**
     * Crea una preparación con las ventas elegidas.
     *
     * Si alguna ya está en otra preparación activa y $forzar es false, no crea
     * nada: deja el detalle en self::$conflictos para que la pantalla advierta
     * y pida confirmación.
     *
     * @param int[] $ventaIds
     */
    public static function crearPreparacion(array $ventaIds, string $notas, int $usuarioId, bool $forzar = false): bool
    {
        self::$errores = [];
        self::$conflictos = [];
        $ventaIds = array_values(array_unique(array_filter(array_map('intval', $ventaIds))));

        if (!$ventaIds) {
            self::$errores[] = 'Selecciona al menos una venta.';
            return false;
        }

        try {
            self::$db->begin_transaction();

            $marcadores = implode(',', array_fill(0, count($ventaIds), '?'));
            $bloqueo = self::$db->prepare("SELECT id, folio, estado FROM ventas WHERE id IN ($marcadores) FOR UPDATE");
            $bloqueo->bind_param(str_repeat('i', count($ventaIds)), ...$ventaIds);
            $bloqueo->execute();
            $ventas = $bloqueo->get_result()->fetch_all(MYSQLI_ASSOC);
            $bloqueo->close();

            if (count($ventas) !== count($ventaIds)) {
                self::$db->rollBack();
                self::$errores[] = 'Alguna de las ventas seleccionadas ya no existe.';
                return false;
            }

            $pendientes = self::pendientesDeVentas($ventaIds);
            foreach ($ventas as $venta) {
                $faltan = array_sum(array_column($pendientes[(int) $venta['id']] ?? [], 'pendiente'));
                if ($venta['estado'] !== 'registrada') {
                    self::$db->rollBack();
                    self::$errores[] = 'La venta ' . $venta['folio'] . ' no está confirmada (solo se preparan ventas registradas).';
                    return false;
                }
                if ($faltan <= 0) {
                    self::$db->rollBack();
                    self::$errores[] = 'La venta ' . $venta['folio'] . ' ya no tiene piezas por entregar (entregada o cerrada).';
                    return false;
                }
            }

            $activos = "'" . implode("','", self::ACTIVOS) . "'";
            $duplicadas = self::$db->prepare(
                "SELECT v.id, v.folio AS venta, GROUP_CONCAT(p.folio ORDER BY p.id SEPARATOR ', ') AS preparaciones
                   FROM preparacion_ventas pv
                   JOIN preparaciones p ON p.id = pv.preparacion_id AND p.estado IN ($activos)
                   JOIN ventas v ON v.id = pv.venta_id
                  WHERE pv.venta_id IN ($marcadores)
                  GROUP BY v.id, v.folio"
            );
            $duplicadas->bind_param(str_repeat('i', count($ventaIds)), ...$ventaIds);
            $duplicadas->execute();
            $conflictos = $duplicadas->get_result()->fetch_all(MYSQLI_ASSOC);
            $duplicadas->close();

            if ($conflictos && !$forzar) {
                self::$db->rollBack();
                self::$conflictos = $conflictos;
                self::$errores[] = 'Hay ventas que ya están en otra preparación activa.';
                return false;
            }

            $folio = self::siguienteFolio();
            $notas = trim($notas) ?: null;
            $crear = self::$db->prepare('INSERT INTO preparaciones (folio, notas, usuario_id) VALUES (?, ?, ?)');
            $crear->bind_param('ssi', $folio, $notas, $usuarioId);
            $crear->execute();
            $preparacionId = (int) self::$db->insert_id;
            $crear->close();

            $unir = self::$db->prepare('INSERT INTO preparacion_ventas (preparacion_id, venta_id) VALUES (?, ?)');
            foreach ($ventaIds as $ventaId) {
                $unir->bind_param('ii', $preparacionId, $ventaId);
                $unir->execute();
            }
            $unir->close();

            Auditoria::registrar('preparacion', $preparacionId, 'crear', $usuarioId, null, [
                'folio' => $folio, 'ventas' => $ventaIds, 'forzada' => (bool) $conflictos,
            ]);

            self::$db->commit();
            self::$ultimoId = $preparacionId;
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Entregas::crearPreparacion] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible crear la preparación.';
            return false;
        }
    }

    public static function buscarPreparacion(int $id): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT p.*, u.nombre AS usuario_nombre FROM preparaciones p
               LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listarPreparaciones(string $estado = '', int $limite = 10, int $offset = 0): array
    {
        $vigentes = $estado === 'vigentes';
        $donde = isset(self::ESTADOS[$estado]) ? 'WHERE p.estado = ?' : ($vigentes ? "WHERE p.estado <> 'cancelada'" : 'WHERE 1 = 1');
        $consulta = self::$db->prepare(
            "SELECT p.*, (SELECT COUNT(*) FROM preparacion_ventas WHERE preparacion_id = p.id) AS ventas
               FROM preparaciones p $donde ORDER BY p.id DESC LIMIT ? OFFSET ?"
        );
        if (isset(self::ESTADOS[$estado])) {
            $consulta->bind_param('sii', $estado, $limite, $offset);
        } else {
            $consulta->bind_param('ii', $limite, $offset);
        }
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    public static function contarPreparaciones(string $estado = ''): int
    {
        if (isset(self::ESTADOS[$estado])) {
            $consulta = self::$db->prepare('SELECT COUNT(*) AS n FROM preparaciones WHERE estado = ?');
            $consulta->bind_param('s', $estado);
        } elseif ($estado === 'vigentes') {
            $consulta = self::$db->prepare("SELECT COUNT(*) AS n FROM preparaciones WHERE estado <> 'cancelada'");
        } else {
            $consulta = self::$db->prepare('SELECT COUNT(*) AS n FROM preparaciones');
        }
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $n;
    }

    /**
     * Ventas de una preparación con cliente y lo que aún falta entregar.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ventasDePreparacion(int $preparacionId): array
    {
        $consulta = self::$db->prepare('SELECT venta_id FROM preparacion_ventas WHERE preparacion_id = ?');
        $consulta->bind_param('i', $preparacionId);
        $consulta->execute();
        $ids = array_column($consulta->get_result()->fetch_all(MYSQLI_ASSOC), 'venta_id');
        $consulta->close();

        return self::ventasPorIds($ids, false);
    }

    /**
     * Las ventas indicadas con su cliente y lo que aún falta entregar (para mostrar una selección
     * en pantalla sin necesidad de guardarla).
     *
     * @param int[] $ventaIds
     * @return array<int, array<string, mixed>>
     */
    public static function ventasPorIds(array $ventaIds, bool $soloRegistradas = true): array
    {
        $ventaIds = array_values(array_unique(array_filter(array_map('intval', $ventaIds))));
        if (!$ventaIds) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($ventaIds), '?'));
        $consulta = self::$db->prepare(
            "SELECT v.id, v.folio, v.fecha, v.total, v.estado, v.cotizacion_id,
                    cl.id AS cliente_id, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    cl.ubicacion AS cliente_ubicacion, cl.telefono AS cliente_telefono,
                    e.nombre AS empresa_nombre
               FROM ventas v
               JOIN clientes cl ON cl.id = v.cliente_id
               JOIN empresas e  ON e.id  = v.empresa_id
              WHERE v.id IN ($marcadores)" . ($soloRegistradas ? " AND v.estado = 'registrada'" : '') . "
              ORDER BY cl.nombre, v.id"
        );
        $consulta->bind_param(str_repeat('i', count($ventaIds)), ...$ventaIds);
        $consulta->execute();
        $ventas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $pendientes = self::pendientesDeVentas(array_column($ventas, 'id'));
        foreach ($ventas as &$venta) {
            $lineas = $pendientes[(int) $venta['id']] ?? [];
            $venta['lineas']    = array_values($lineas);
            $venta['piezas']    = array_sum(array_column($lineas, 'vendido'));
            $venta['entregadas'] = array_sum(array_column($lineas, 'entregado'));
            $venta['pendientes'] = array_sum(array_column($lineas, 'pendiente'));
        }
        unset($venta);

        return $ventas;
    }

    /**
     * Productos de la preparación AGRUPADOS: los iguales se suman y se
     * indican las ventas/clientes de origen. Solo cuenta lo pendiente.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function productosAgrupados(array $ventas): array
    {
        $grupos = [];

        foreach ($ventas as $venta) {
            foreach ($venta['lineas'] as $linea) {
                if ($linea['pendiente'] <= 0) {
                    continue;
                }
                $id = (int) $linea['producto_id'];
                if (!isset($grupos[$id])) {
                    $grupos[$id] = [
                        'producto_id' => $id, 'sku' => $linea['sku'], 'nombre' => $linea['nombre'],
                        'imagen' => $linea['imagen'], 'unidad' => $linea['unidad'],
                        'total' => 0, 'origenes' => [],
                    ];
                }
                $grupos[$id]['total'] += $linea['pendiente'];
                $grupos[$id]['origenes'][] = [
                    'venta' => $venta['folio'],
                    'cliente' => $venta['cliente_empresa'] ?: $venta['cliente_nombre'],
                    'cantidad' => $linea['pendiente'],
                ];
            }
        }

        uasort($grupos, static fn ($a, $b) => strnatcasecmp((string) $a['sku'], (string) $b['sku']));
        return array_values($grupos);
    }

    /**
     * Cambia el estado de una preparación (cualquier paso razonable).
     * «Entregado» solo si ya no queda nada pendiente; «Cancelada» solo si
     * todavía no se registró ninguna entrega.
     */
    public static function cambiarEstado(int $id, string $estado, int $usuarioId): bool
    {
        self::$errores = [];
        if (!isset(self::ESTADOS[$estado])) {
            self::$errores[] = 'Estado no reconocido.';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $bloqueo = self::$db->prepare('SELECT estado FROM preparaciones WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $id);
            $bloqueo->execute();
            $actual = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if (!$actual) {
                self::$db->rollBack();
                self::$errores[] = 'La preparación ya no existe.';
                return false;
            }
            if ($actual['estado'] === 'cancelada') {
                self::$db->rollBack();
                self::$errores[] = 'Una preparación cancelada ya no cambia de estado.';
                return false;
            }
            if ($actual['estado'] === $estado) {
                self::$db->rollBack();
                return true;
            }

            $ventas = self::ventasDePreparacion($id);
            $pendientes = array_sum(array_column($ventas, 'pendientes'));
            $entregadas = array_sum(array_column($ventas, 'entregadas'));

            if ($estado === 'entregada' && $pendientes > 0) {
                self::$db->rollBack();
                self::$errores[] = 'Aún hay ' . $pendientes . ' pieza(s) por entregar: registra las entregas para completarla.';
                return false;
            }
            if ($estado === 'cancelada' && self::contarEntregas($id) > 0) {
                self::$db->rollBack();
                self::$errores[] = 'No se puede cancelar: ya tiene entregas registradas.';
                return false;
            }

            $anterior = $actual['estado'];
            $cambiar = self::$db->prepare('UPDATE preparaciones SET estado = ? WHERE id = ?');
            $cambiar->bind_param('si', $estado, $id);
            $cambiar->execute();
            $cambiar->close();

            Auditoria::registrar('preparacion', $id, 'estado', $usuarioId, null, ['de' => $anterior, 'a' => $estado]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Entregas::cambiarEstado] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cambiar el estado.';
            return false;
        }
    }

    private static function contarEntregas(int $preparacionId): int
    {
        $consulta = self::$db->prepare('SELECT COUNT(*) AS n FROM entregas WHERE preparacion_id = ?');
        $consulta->bind_param('i', $preparacionId);
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $n;
    }

    /* --------------------------------------------------------------------
     * ENTREGA A CLIENTE (total o parcial)
     * ------------------------------------------------------------------ */

    /**
     * Registra las piezas entregadas de una venta. Nunca más que lo pendiente.
     * No toca el inventario. El token impide duplicar al reenviar el formulario.
     *
     * @param array<int, int> $piezas producto_id => cantidad entregada ahora.
     */
    public static function registrarEntrega(
        int $ventaId,
        ?int $preparacionId,
        array $piezas,
        string $fecha,
        string $token,
        string $notas,
        int $usuarioId
    ): bool {
        self::$errores = [];
        $piezas = array_filter(array_map('intval', $piezas), static fn ($c) => $c > 0);

        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            self::$errores[] = 'El formulario de entrega no es válido. Vuelve a abrirlo.';
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            self::$errores[] = 'La fecha de entrega no es válida.';
            return false;
        }
        if (!$piezas) {
            self::$errores[] = 'Captura al menos una pieza entregada.';
            return false;
        }

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT folio, estado FROM ventas WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $ventaId);
            $bloqueo->execute();
            $venta = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if (!$venta || $venta['estado'] !== 'registrada') {
                self::$db->rollBack();
                self::$errores[] = 'Solo se entregan ventas registradas.';
                return false;
            }

            $pendientes = self::pendientesDeVentas([$ventaId])[$ventaId] ?? [];
            foreach ($piezas as $productoId => $cantidad) {
                if (!isset($pendientes[$productoId]) || $cantidad > $pendientes[$productoId]['pendiente']) {
                    self::$db->rollBack();
                    self::$errores[] = 'No se pueden entregar más piezas de las pendientes'
                        . (isset($pendientes[$productoId]) ? ' de «' . $pendientes[$productoId]['nombre'] . '»' : '') . '.';
                    return false;
                }
            }

            if ($preparacionId !== null) {
                $existe = self::$db->prepare('SELECT 1 FROM preparacion_ventas WHERE preparacion_id = ? AND venta_id = ?');
                $existe->bind_param('ii', $preparacionId, $ventaId);
                $existe->execute();
                if ($existe->get_result()->num_rows === 0) {
                    $preparacionId = null;
                }
                $existe->close();
            }

            $notas = trim($notas) ?: null;
            $crear = self::$db->prepare(
                'INSERT INTO entregas (venta_id, preparacion_id, fecha, token, notas, usuario_id) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $crear->bind_param('iisssi', $ventaId, $preparacionId, $fecha, $token, $notas, $usuarioId);
            try {
                $crear->execute();
            } catch (\mysqli_sql_exception $duplicada) {
                self::$db->rollBack();
                self::$errores[] = 'Esta entrega ya fue registrada; no se duplicó.';
                return false;
            }
            $entregaId = (int) self::$db->insert_id;
            $crear->close();

            $renglon = self::$db->prepare('INSERT INTO entrega_renglones (entrega_id, producto_id, cantidad) VALUES (?, ?, ?)');
            foreach ($piezas as $productoId => $cantidad) {
                $renglon->bind_param('iii', $entregaId, $productoId, $cantidad);
                $renglon->execute();
            }
            $renglon->close();

            self::avanzarPreparaciones($ventaId);
            Auditoria::registrar('venta', $ventaId, 'entrega', $usuarioId, null, ['entrega_id' => $entregaId, 'piezas' => $piezas]);

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Entregas::registrarEntrega] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar la entrega.';
            return false;
        }
    }

    /**
     * Tras una entrega: las preparaciones activas de esa venta pasan a «En
     * reparto» (si hubo entrega parcial) o a «Entregado» (si todo se entregó).
     */
    public static function avanzarPreparaciones(int $ventaId): void
    {
        $activos = "'" . implode("','", self::ACTIVOS) . "'";
        $consulta = self::$db->prepare(
            "SELECT p.id, p.estado FROM preparacion_ventas pv
               JOIN preparaciones p ON p.id = pv.preparacion_id AND p.estado IN ($activos)
              WHERE pv.venta_id = ?"
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $preparaciones = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        foreach ($preparaciones as $preparacion) {
            $ventas = self::ventasDePreparacion((int) $preparacion['id']);
            $nuevo = array_sum(array_column($ventas, 'pendientes')) === 0 ? 'entregada' : 'en_reparto';

            if ($nuevo !== $preparacion['estado']) {
                $cambiar = self::$db->prepare('UPDATE preparaciones SET estado = ? WHERE id = ?');
                $id = (int) $preparacion['id'];
                $cambiar->bind_param('si', $nuevo, $id);
                $cambiar->execute();
                $cambiar->close();
            }
        }
    }

    /**
     * Historial de entregas de una venta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function entregasDeVenta(int $ventaId): array
    {
        $consulta = self::$db->prepare(
            'SELECT en.*, u.nombre AS usuario_nombre FROM entregas en
               LEFT JOIN usuarios u ON u.id = en.usuario_id WHERE en.venta_id = ? ORDER BY en.id'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $entregas = [];
        foreach ($consulta->get_result() as $fila) {
            $fila['renglones'] = [];
            $entregas[(int) $fila['id']] = $fila;
        }
        $consulta->close();

        if ($entregas) {
            $consulta = self::$db->prepare(
                'SELECT er.*, p.nombre AS producto_nombre FROM entrega_renglones er
                   JOIN productos p ON p.id = er.producto_id
                   JOIN entregas en ON en.id = er.entrega_id WHERE en.venta_id = ? ORDER BY er.id'
            );
            $consulta->bind_param('i', $ventaId);
            $consulta->execute();
            foreach ($consulta->get_result() as $fila) {
                $entregas[(int) $fila['entrega_id']]['renglones'][] = $fila;
            }
            $consulta->close();
        }

        return array_values($entregas);
    }

    /* --------------------------------------------------------------------
     * ENTREGAS POR CLIENTE / DESTINO
     * ------------------------------------------------------------------ */

    /**
     * Lo que falta entregar, agrupado por cliente (destino): sus ventas y los
     * productos pendientes sumados.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendientesPorCliente(array $filtros = []): array
    {
        $ventas = self::ventasPendientes($filtros, 500, 0);
        $pendientes = self::pendientesDeVentas(array_column($ventas, 'id'));

        $clientes = [];
        foreach ($ventas as $venta) {
            $id = (int) $venta['cliente_id'];
            if (!isset($clientes[$id])) {
                $clientes[$id] = [
                    'cliente_id' => $id,
                    'nombre' => $venta['cliente_empresa'] ?: $venta['cliente_nombre'],
                    'contacto' => $venta['cliente_nombre'],
                    'ubicacion' => $venta['cliente_ubicacion'],
                    'ventas' => [], 'productos' => [], 'piezas' => 0,
                ];
            }
            $lineas = array_values($pendientes[(int) $venta['id']] ?? []);
            $venta['lineas'] = $lineas;
            $clientes[$id]['ventas'][] = $venta;

            foreach ($lineas as $linea) {
                if ($linea['pendiente'] <= 0) {
                    continue;
                }
                $p = (int) $linea['producto_id'];
                $clientes[$id]['productos'][$p] ??= [
                    'sku' => $linea['sku'], 'nombre' => $linea['nombre'], 'imagen' => $linea['imagen'], 'total' => 0,
                ];
                $clientes[$id]['productos'][$p]['total'] += $linea['pendiente'];
                $clientes[$id]['piezas'] += $linea['pendiente'];
            }
        }

        uasort($clientes, static fn ($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
        return array_values($clientes);
    }

    /** PREP-AAAA-NNNN; el índice único es la última defensa ante carreras. */
    private static function siguienteFolio(): string
    {
        $prefijo = 'PREP-' . date('Y') . '-';
        $consulta = self::$db->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(folio, ?) AS UNSIGNED)), 0) AS n
               FROM preparaciones WHERE folio LIKE ? FOR UPDATE'
        );
        $posicion = strlen($prefijo) + 1;
        $patron = $prefijo . '%';
        $consulta->bind_param('is', $posicion, $patron);
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $prefijo . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
