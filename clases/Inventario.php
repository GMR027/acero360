<?php

namespace App;

/**
 * Inventario — existencias de los productos y su bitácora.
 *
 * El stock tiene DOS fuentes de cambio, tal como pide OPENCODE.md:
 *
 *   1. MANUAL   — el administrador carga la fabricación desde
 *                 /admin/inventario (el número que quiera, sube o baja).
 *   2. AUTOMÁTICA — cada cotización que pasa a estado "Vendido" descuenta
 *                 las cantidades de sus renglones; si se devuelve a
 *                 "Pendiente" o "Cancelado", el stock regresa.
 *
 * Todo movimiento queda registrado en movimientos_inventario con el signo,
 * el saldo resultante y quién lo originó, de modo que siempre se pueda
 * responder "¿por qué este producto tiene N piezas?".
 *
 * Las funciones descontar()/devolver() NO abren su propia transacción:
 * deben llamarse desde una transacción ya iniciada (Cotizaciones y Ventas
 * son las que la abren), para que estado, stock y bitácora sean atómicos.
 */
class Inventario
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Tipos de movimiento con su etiqueta visual (se usan en la bitácora). */
    public const TIPOS = [
        'entrada' => ['etiqueta' => 'Entrada manual',   'icono' => 'add_circle',   'clase' => 'badge--aprobado'],
        'salida'  => ['etiqueta' => 'Salida por venta', 'icono' => 'remove_circle', 'clase' => 'badge--error'],
        'ajuste'  => ['etiqueta' => 'Ajuste manual',    'icono' => 'tune',          'clase' => 'badge--info'],
    ];

    /* --------------------------------------------------------------------
     * CONEXIÓN
     * ------------------------------------------------------------------ */

    /** Recibe la conexión a la base de datos (llamado desde includes/app.php). */
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
     * CONSULTAS DE EXISTENCIAS
     * ------------------------------------------------------------------ */

    /** Existencias actuales de un producto (0 si no existe). */
    public static function stock(int $productoId): int
    {
        $consulta = self::$db->prepare('SELECT stock FROM productos WHERE id = ?');
        $consulta->bind_param('i', $productoId);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? (int) $registro['stock'] : 0;
    }

    /**
     * Renglones de una cotización cuyo producto no alcanza en almacén.
     *
     * Se agrupan los renglones por producto: si la cotización pide 3 + 5 de
     * lo mismo, el faltante se calcula sobre 8.
     *
     * @return array<int, array{producto_id: int, nombre: string, sku: string,
     *                          requerido: int, disponible: int, faltante: int}>
     */
    public static function faltantes(int $cotizacionId): array
    {
        $consulta = self::$db->prepare(
            'SELECT d.producto_id, p.nombre, p.sku, p.stock,
                    SUM(d.cantidad) AS requerido
               FROM cotizacion_detalles d
               JOIN productos p ON p.id = d.producto_id
              WHERE d.cotizacion_id = ?
              GROUP BY d.producto_id, p.nombre, p.sku, p.stock'
        );
        $consulta->bind_param('i', $cotizacionId);
        $consulta->execute();
        $registros = $consulta->get_result();

        $faltantes = [];
        while ($fila = $registros->fetch_assoc()) {
            $requerido  = (int) $fila['requerido'];
            $disponible = (int) $fila['stock'];
            $faltante   = $requerido - $disponible;

            if ($faltante > 0) {
                $faltantes[] = [
                    'producto_id' => (int) $fila['producto_id'],
                    'nombre'      => (string) $fila['nombre'],
                    'sku'         => (string) ($fila['sku'] ?? ''),
                    'requerido'   => $requerido,
                    'disponible'  => $disponible,
                    'faltante'    => $faltante,
                ];
            }
        }

        $consulta->close();
        return $faltantes;
    }

    /**
     * Faltantes de una lista de renglones aún no guardada (venta directa).
     *
     * @param array<int, array{cantidad: int}> $lineas producto_id => renglón del formulario.
     * @return array<int, array{producto_id: int, nombre: string, sku: string,
     *                          requerido: int, disponible: int, faltante: int}>
     */
    public static function faltantesDeLineas(array $lineas): array
    {
        $consulta = self::$db->prepare('SELECT nombre, sku, stock FROM productos WHERE id = ?');
        $faltantes = [];

        foreach ($lineas as $productoId => $linea) {
            $productoId = (int) $productoId;
            $requerido  = max(0, (int) ($linea['cantidad'] ?? 0));

            $consulta->bind_param('i', $productoId);
            $consulta->execute();
            $fila = $consulta->get_result()->fetch_assoc();
            if (!$fila) {
                continue;
            }

            $disponible = (int) $fila['stock'];
            if ($requerido - $disponible > 0) {
                $faltantes[] = [
                    'producto_id' => $productoId,
                    'nombre'      => (string) $fila['nombre'],
                    'sku'         => (string) ($fila['sku'] ?? ''),
                    'requerido'   => $requerido,
                    'disponible'  => $disponible,
                    'faltante'    => $requerido - $disponible,
                ];
            }
        }

        $consulta->close();
        return $faltantes;
    }

    /**
     * Cantidades agrupadas por producto a partir de los renglones.
     *
     * @param array $detalles Filas de Cotizaciones::detalles() (producto_id + cantidad).
     * @return array<int, int> producto_id => cantidad total.
     */
    public static function cantidadesAgrupadas(array $detalles): array
    {
        $agrupadas = [];

        foreach ($detalles as $detalle) {
            $productoId = (int) ($detalle['producto_id'] ?? 0);
            if ($productoId <= 0) {
                continue;
            }
            $agrupadas[$productoId] = ($agrupadas[$productoId] ?? 0) + max(0, (int) ($detalle['cantidad'] ?? 0));
        }

        return $agrupadas;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA — MOVIMIENTOS AUTOMÁTICOS (transacción del llamador)
     * ------------------------------------------------------------------ */

    /**
     * Descuenta existencias (salida por venta).
     *
     * @param array $detalles Filas de Cotizaciones::detalles().
     * @return bool false sólo si algo falló: el llamador debe revertir.
     */
    public static function descontar(
        array $detalles,
        int $cotizacionId,
        int $usuarioId,
        string $notas = '',
        int $ventaId = 0
    ): bool {
        return self::mover(self::cantidadesAgrupadas($detalles), 'salida', $cotizacionId, $usuarioId, $notas, $ventaId);
    }

    /**
     * Devuelve existencias (venta revertida: la cotización sale de "Vendido").
     *
     * @param array $detalles Filas de Cotizaciones::detalles().
     */
    public static function devolver(
        array $detalles,
        int $cotizacionId,
        int $usuarioId,
        string $notas = '',
        int $ventaId = 0
    ): bool {
        return self::mover(self::cantidadesAgrupadas($detalles), 'entrada', $cotizacionId, $usuarioId, $notas, $ventaId);
    }

    /**
     * Entrada por devolución de UNA incidencia de entrega (IncidenciasEntrega la
     * llama dentro de su propia transacción: NO abre ni confirma). Igual que en
     * registrarDevolucion(): el producto regresa con motivo «devolucion» ligado
     * a la venta, para que el rastro del kardex quede explicado.
     *
     * @param array<int, int> $piezas producto_id => cantidad a regresar.
     */
    public static function devolucionPorIncidencia(array $piezas, int $ventaId, int $usuarioId, string $notas): bool
    {
        return self::mover($piezas, 'entrada', 0, $usuarioId, $notas, $ventaId, 0, 'devolucion');
    }

    /**
     * Suma al inventario lo realmente recibido de un proveedor.
     * NO abre transacción: la abre OrdenesCompra::recibir().
     *
     * @param array<int, int> $agrupadas producto_id => unidades recibidas.
     */
    public static function entradaPorRecepcion(array $agrupadas, int $recepcionId, int $usuarioId, string $notas = ''): bool
    {
        return self::mover($agrupadas, 'entrada', 0, $usuarioId, $notas, 0, $recepcionId, 'recepcion');
    }

    /**
     * Pasa a existencia vendible unidades que estaban «por terminar» (PorTerminar::terminar lo llama).
     * NO abre transacción.
     *
     * @param array<int, int> $agrupadas producto_id => unidades terminadas.
     */
    public static function entradaTerminado(array $agrupadas, int $usuarioId, string $notas = ''): bool
    {
        return self::mover($agrupadas, 'entrada', 0, $usuarioId, $notas, 0, 0, 'terminado');
    }

    /**
     * Núcleo de escritura: mueve el stock de varios productos y registra
     * el movimiento en la bitácora. NO abre transacción.
     *
     * @param array<int, int> $agrupadas producto_id => cantidad total.
     */
    private static function mover(
        array $agrupadas,
        string $tipo,
        int $cotizacionId,
        int $usuarioId,
        string $notas,
        int $ventaId = 0,
        int $recepcionId = 0,
        ?string $motivo = null
    ): bool {
        // 'salida' resta; 'entrada' (reversión de una venta) suma.
        $negativo = $tipo === 'salida';
        $motivo ??= $negativo ? 'venta' : 'cancelacion';
        $permitirNegativo = Configuracion::permitirStockNegativo();
        $bloquear = self::$db->prepare('SELECT stock FROM productos WHERE id = ? FOR UPDATE');

        $actualizar = self::$db->prepare(
            'UPDATE productos SET stock = stock ' . ($negativo ? '-' : '+') . ' ? WHERE id = ?'
        );
        $saldoDe = self::$db->prepare('SELECT stock FROM productos WHERE id = ?');
        $registrar = self::$db->prepare(
            'INSERT INTO movimientos_inventario
                (producto_id, tipo, cantidad, saldo, cotizacion_id, venta_id, recepcion_id, motivo, usuario_id, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($agrupadas as $productoId => $cantidad) {
            if ($productoId <= 0 || $cantidad <= 0) {
                continue;
            }

            // Bloquea la fila: dos ventas simultáneas no pueden leer el mismo saldo.
            $bloquear->bind_param('i', $productoId);
            $bloquear->execute();
            $actual = $bloquear->get_result()->fetch_assoc();

            if ($negativo && !$permitirNegativo && (int) ($actual['stock'] ?? 0) < $cantidad) {
                self::$errores[] = 'Existencias insuficientes para el producto #' . $productoId
                    . ' (hay ' . (int) ($actual['stock'] ?? 0) . ', se piden ' . $cantidad . ').';
                return false;
            }

            $actualizar->bind_param('ii', $cantidad, $productoId);
            $actualizar->execute();

            $saldoDe->bind_param('i', $productoId);
            $saldoDe->execute();
            $filaSaldo = $saldoDe->get_result()->fetch_assoc();
            $saldo = $filaSaldo ? (int) $filaSaldo['stock'] : 0;

            $cotizacion = $cotizacionId > 0 ? $cotizacionId : null;
            $venta      = $ventaId > 0 ? $ventaId : null;
            $usuario    = $usuarioId > 0 ? $usuarioId : null;
            $texto      = $notas !== '' ? $notas : null;

            $recepcion  = $recepcionId > 0 ? $recepcionId : null;

            $registrar->bind_param(
                'isiiiiisis',
                $productoId, $tipo, $cantidad, $saldo, $cotizacion, $venta, $recepcion, $motivo, $usuario, $texto
            );

            if (!$registrar->execute()) {
                self::$errores[] = 'No fue posible registrar el movimiento de inventario.';
                return false;
            }
        }

        $bloquear->close();
        $actualizar->close();
        $saldoDe->close();
        $registrar->close();

        return true;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA — CARGA MANUAL DEL ADMINISTRADOR
     * ------------------------------------------------------------------ */

    /**
     * Fija las existencias de un producto al valor indicado por el usuario.
     *
     * Si sube se registra como "Entrada manual" (fabricación nueva) y si
     * baja como "Ajuste manual"; el valor absoluto nunca se "sobreescribe
     * en silencio": siempre queda el delta en la bitácora.
     *
     * @return bool true si el valor es válido (aunque no haya cambiado).
     */
    public static function ajustar(int $productoId, int $nuevoStock, int $usuarioId, string $notas = '', string $motivo = 'ajuste'): bool
    {
        self::$errores = [];

        try {
            self::$db->begin_transaction();

            $consulta = self::$db->prepare(
                'SELECT stock FROM productos WHERE id = ? FOR UPDATE'
            );
            $consulta->bind_param('i', $productoId);
            $consulta->execute();
            $registro = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$registro) {
                self::$db->rollBack();
                self::$errores[] = 'El producto seleccionado ya no existe.';
                return false;
            }

            $anterior = (int) $registro['stock'];
            $delta    = $nuevoStock - $anterior;

            if ($delta === 0) {
                // Nada que mover: se confirma sin ensuciar la bitácora.
                self::$db->commit();
                return true;
            }

            $cantidad = abs($delta);
            $tipo     = $delta > 0 ? 'entrada' : 'ajuste';

            $actualizar = self::$db->prepare('UPDATE productos SET stock = ? WHERE id = ?');
            $actualizar->bind_param('ii', $nuevoStock, $productoId);
            if (!$actualizar->execute()) {
                $actualizar->close();
                self::$db->rollBack();
                self::$errores[] = 'No fue posible actualizar las existencias.';
                return false;
            }
            $actualizar->close();

            $texto = $notas !== '' ? $notas : 'Carga manual desde Inventario';

            $registrar = self::$db->prepare(
                'INSERT INTO movimientos_inventario
                    (producto_id, tipo, cantidad, saldo, usuario_id, notas, motivo)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $registrar->bind_param('isiiiss', $productoId, $tipo, $cantidad, $nuevoStock, $usuarioId, $texto, $motivo);

            if (!$registrar->execute()) {
                $registrar->close();
                self::$db->rollBack();
                self::$errores[] = 'No fue posible registrar el movimiento de inventario.';
                return false;
            }
            $registrar->close();

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Inventario::ajustar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el inventario.';
        return false;
    }

    /**
     * Corrección justificada: fija las existencias exigiendo un motivo.
     * Queda en movimientos_inventario (motivo «correccion») y en la bitácora.
     */
    public static function corregir(int $productoId, int $nuevoStock, string $razon, int $usuarioId): bool
    {
        $razon = trim($razon);
        if (mb_strlen($razon) < 5) {
            self::$errores = ['Una corrección necesita un motivo (mínimo 5 caracteres).'];
            return false;
        }

        if (!self::ajustar($productoId, $nuevoStock, $usuarioId, 'Corrección: ' . $razon, 'correccion')) {
            return false;
        }

        Auditoria::registrar('producto', $productoId, 'correccion_inventario', $usuarioId, $razon, ['nuevo_stock' => $nuevoStock]);
        return true;
    }

    /**
     * Piezas que aún se pueden devolver de una venta, por producto.
     *
     * @return array<int, array{vendido: int, devuelto: int, disponible: int}>
     */
    public static function devolvibles(int $ventaId): array
    {
        $consulta = self::$db->prepare(
            'SELECT producto_id, SUM(cantidad) AS vendido FROM venta_detalles WHERE venta_id = ? GROUP BY producto_id'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $resultado = [];
        foreach ($consulta->get_result() as $fila) {
            $resultado[(int) $fila['producto_id']] = ['vendido' => (int) $fila['vendido'], 'devuelto' => 0, 'disponible' => (int) $fila['vendido']];
        }
        $consulta->close();

        $consulta = self::$db->prepare(
            "SELECT producto_id, SUM(cantidad_devuelta) AS devuelto FROM venta_devoluciones
              WHERE venta_id = ? GROUP BY producto_id"
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        foreach ($consulta->get_result() as $fila) {
            $id = (int) $fila['producto_id'];
            if (isset($resultado[$id])) {
                $resultado[$id]['devuelto']   = (int) $fila['devuelto'];
                $resultado[$id]['disponible'] = $resultado[$id]['vendido'] - (int) $fila['devuelto'];
            }
        }
        $consulta->close();

        return $resultado;
    }

    /**
     * Devolución simple (sin cambio, sin nota): atajo de registrarDevolucion() con la fecha de hoy.
     *
     * @param array<int, int> $piezas producto_id => cantidad a devolver.
     */
    public static function devolverDeVenta(int $ventaId, array $piezas, string $nota, int $usuarioId): bool
    {
        $items = [];
        foreach ($piezas as $productoId => $cantidad) {
            $items[(int) $productoId] = ['devuelta' => (int) $cantidad, 'cambio' => 0];
        }

        return self::registrarDevolucion($ventaId, $items, date('Y-m-d'), $nota, $usuarioId);
    }

    /**
     * Registra una devolución (y, si se pide, cuántas de esas piezas requieren cambio) de UNA venta registrada.
     *   • Las piezas devueltas suman al inventario en ese momento.
     *   • Las piezas «a cambiar» quedan como deuda de la venta; se descuentan del inventario cuando se entrega el cambio.
     *   • Nunca se devuelve más de lo vendido menos lo ya devuelto; el cambio nunca supera lo devuelto en ese registro.
     *   • El motivo no es obligatorio (nota opcional).
     *
     * @param array<int, array{devuelta: int, cambio: int}> $items producto_id => cantidades.
     */
    public static function registrarDevolucion(int $ventaId, array $items, string $fecha, string $nota, int $usuarioId): bool
    {
        self::$errores = [];
        $filtrados = [];
        foreach ($items as $productoId => $c) {
            $devuelta = max(0, (int) ($c['devuelta'] ?? 0));
            $cambio   = max(0, (int) ($c['cambio'] ?? 0));
            if ($devuelta > 0 || $cambio > 0) {
                $filtrados[(int) $productoId] = ['devuelta' => $devuelta, 'cambio' => $cambio];
            }
        }

        if (!$filtrados) {
            self::$errores[] = 'Indica al menos una pieza devuelta.';
            return false;
        }
        foreach ($filtrados as $c) {
            if ($c['cambio'] > $c['devuelta']) {
                self::$errores[] = 'Las piezas a cambiar no pueden ser más que las devueltas.';
                return false;
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha) || $fecha > date('Y-m-d')) {
            self::$errores[] = 'La fecha de la devolución no es válida (no puede ser futura).';
            return false;
        }
        $nota = mb_substr(trim($nota), 0, 200);

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT folio, estado FROM ventas WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $ventaId);
            $bloqueo->execute();
            $venta = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if (!$venta || $venta['estado'] !== 'registrada') {
                self::$db->rollBack();
                self::$errores[] = 'Solo se pueden devolver piezas de una venta registrada.';
                return false;
            }

            $disponibles = self::devolvibles($ventaId);
            $piezas = [];
            foreach ($filtrados as $productoId => $c) {
                if (!isset($disponibles[$productoId]) || $c['devuelta'] > $disponibles[$productoId]['disponible']) {
                    self::$db->rollBack();
                    self::$errores[] = 'No se pueden devolver más piezas de las vendidas (producto #' . $productoId . ').';
                    return false;
                }
                $piezas[$productoId] = $c['devuelta'];
            }

            $texto = 'Devolución de ' . $venta['folio'] . ($nota !== '' ? ': ' . $nota : '');
            if (!self::mover($piezas, 'entrada', 0, $usuarioId, $texto, $ventaId, 0, 'devolucion')) {
                self::$db->rollBack();
                return false;
            }

            $guardar = self::$db->prepare(
                'INSERT INTO venta_devoluciones (venta_id, producto_id, fecha, cantidad_devuelta, cantidad_cambio, nota, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $notaSql = $nota !== '' ? $nota : null;
            $usuarioSql = $usuarioId > 0 ? $usuarioId : null;
            foreach ($filtrados as $productoId => $c) {
                $guardar->bind_param('iisiisi', $ventaId, $productoId, $fecha, $c['devuelta'], $c['cambio'], $notaSql, $usuarioSql);
                $guardar->execute();
            }
            $guardar->close();

            // Lo devuelto que no se había entregado deja de estar pendiente (ver Entregas::pendientesDeVentas).
            Entregas::avanzarPreparaciones($ventaId);
            IncidenciasEntrega::actualizarEstado($ventaId);

            Auditoria::registrar('venta', $ventaId, 'devolucion', $usuarioId, $nota !== '' ? $nota : null, ['piezas' => $filtrados, 'fecha' => $fecha]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Inventario::registrarDevolucion] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar la devolución.';
            return false;
        }
    }

    /**
     * Cambios que se le deben al cliente en una venta: piezas a cambiar menos las ya entregadas.
     *
     * @return array<int, array{producto_id: int, nombre: string, a_cambiar: int, entregadas: int, pendiente: int}>
     */
    public static function cambiosPendientes(int $ventaId): array
    {
        $consulta = self::$db->prepare(
            'SELECT d.producto_id, p.nombre, SUM(d.cantidad_cambio) AS a_cambiar,
                    COALESCE(SUM((SELECT SUM(e.cantidad) FROM venta_cambios_entregados e WHERE e.devolucion_id = d.id)), 0) AS entregadas
               FROM venta_devoluciones d JOIN productos p ON p.id = d.producto_id
              WHERE d.venta_id = ? GROUP BY d.producto_id, p.nombre HAVING a_cambiar > 0 ORDER BY p.nombre'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $filas = [];
        foreach ($consulta->get_result() as $f) {
            $pendiente = (int) $f['a_cambiar'] - (int) $f['entregadas'];
            $filas[(int) $f['producto_id']] = [
                'producto_id' => (int) $f['producto_id'], 'nombre' => $f['nombre'],
                'a_cambiar' => (int) $f['a_cambiar'], 'entregadas' => (int) $f['entregadas'], 'pendiente' => $pendiente,
            ];
        }
        $consulta->close();

        return $filas;
    }

    /**
     * Entrega (parcial o total) de la pieza de reemplazo: se descuenta del inventario ahora.
     * No se entrega más de lo que se debe; respeta la preferencia de stock negativo.
     */
    public static function entregarCambio(int $ventaId, int $productoId, int $cantidad, string $fecha, int $usuarioId): bool
    {
        self::$errores = [];

        if ($cantidad <= 0) {
            self::$errores[] = 'Indica cuántas piezas se entregan.';
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha) || $fecha > date('Y-m-d')) {
            self::$errores[] = 'La fecha de entrega no es válida (no puede ser futura).';
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
                self::$errores[] = 'Solo se entregan cambios de una venta registrada.';
                return false;
            }

            $pendiente = self::cambiosPendientes($ventaId)[$productoId]['pendiente'] ?? 0;
            if ($cantidad > $pendiente) {
                self::$db->rollBack();
                self::$errores[] = $pendiente > 0
                    ? 'No se pueden entregar más piezas de las que se deben (faltan ' . $pendiente . ').'
                    : 'Este producto no tiene cambios pendientes.';
                return false;
            }

            if (!self::mover([$productoId => $cantidad], 'salida', 0, $usuarioId, 'Cambio entregado de ' . $venta['folio'], $ventaId, 0, 'cambio')) {
                self::$db->rollBack();
                return false;
            }

            // Reparte la entrega entre los registros de devolución, del más antiguo al más reciente.
            $registros = self::$db->prepare(
                'SELECT d.id, d.cantidad_cambio - COALESCE((SELECT SUM(e.cantidad) FROM venta_cambios_entregados e WHERE e.devolucion_id = d.id), 0) AS falta
                   FROM venta_devoluciones d WHERE d.venta_id = ? AND d.producto_id = ? ORDER BY d.id FOR UPDATE'
            );
            $registros->bind_param('ii', $ventaId, $productoId);
            $registros->execute();
            $filas = $registros->get_result()->fetch_all(MYSQLI_ASSOC);
            $registros->close();

            $entrega = self::$db->prepare('INSERT INTO venta_cambios_entregados (devolucion_id, cantidad, fecha, usuario_id) VALUES (?, ?, ?, ?)');
            $usuarioSql = $usuarioId > 0 ? $usuarioId : null;
            $resto = $cantidad;
            foreach ($filas as $f) {
                $parte = min($resto, (int) $f['falta']);
                if ($parte <= 0) {
                    continue;
                }
                $devolucionId = (int) $f['id'];
                $entrega->bind_param('iisi', $devolucionId, $parte, $fecha, $usuarioSql);
                $entrega->execute();
                $resto -= $parte;
            }
            $entrega->close();

            Auditoria::registrar('venta', $ventaId, 'cambio_entregado', $usuarioId, null, ['producto_id' => $productoId, 'cantidad' => $cantidad, 'fecha' => $fecha]);
            IncidenciasEntrega::actualizarEstado($ventaId);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Inventario::entregarCambio] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar la entrega del cambio.';
            return false;
        }
    }

    /** Historial de devoluciones de una venta, con lo entregado de cada cambio. @return array<int, array<string, mixed>> */
    public static function historialDevoluciones(int $ventaId): array
    {
        $consulta = self::$db->prepare(
            'SELECT d.*, p.nombre AS producto_nombre, u.nombre AS usuario_nombre,
                    COALESCE((SELECT SUM(e.cantidad) FROM venta_cambios_entregados e WHERE e.devolucion_id = d.id), 0) AS entregadas
               FROM venta_devoluciones d JOIN productos p ON p.id = d.producto_id LEFT JOIN usuarios u ON u.id = d.usuario_id
              WHERE d.venta_id = ? ORDER BY d.fecha DESC, d.id DESC'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Ventas con cambios por entregar (para avisos y pendientes). @return array<int, array<string, mixed>> */
    public static function ventasConCambiosPendientes(int $limite = 200): array
    {
        $resultado = self::$db->query(
            "SELECT v.id, v.folio, v.fecha, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    SUM(d.cantidad_cambio) - COALESCE(SUM((SELECT SUM(e.cantidad) FROM venta_cambios_entregados e WHERE e.devolucion_id = d.id)), 0) AS pendiente
               FROM venta_devoluciones d JOIN ventas v ON v.id = d.venta_id JOIN clientes cl ON cl.id = v.cliente_id
              WHERE v.estado = 'registrada' GROUP BY v.id, v.folio, v.fecha, cl.nombre, cl.nombre_empresa
             HAVING pendiente > 0 ORDER BY v.fecha, v.id LIMIT " . (int) $limite
        );

        return $resultado->fetch_all(MYSQLI_ASSOC);
    }

    /* --------------------------------------------------------------------
     * LISTADOS
     * ------------------------------------------------------------------ */

    /**
     * Productos con su existencia, para la pantalla de inventario.
     *
     * Filtros:
     *   q       → nombre, SKU o categoría
     *   empresa → id de la empresa (0 = todas)
     *   estado  → 'bajo' (sin existencias) | 'todos'
     *
     * @return array<int, object>
     */
    public static function listar(array $filtros = [], int $limite = 15, int $offset = 0): array
    {
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);

        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            "SELECT p.*, e.nombre AS empresa_nombre
               FROM productos p
               JOIN empresas e ON e.id = p.empresa_id
              WHERE $donde
              ORDER BY " . self::ordenPorCodigo() . "
              LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $tipos    .= 'ii';

        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $productos = [];
        while ($fila = $registros->fetch_assoc()) {
            $productos[] = (object) $fila;
        }

        $consulta->close();
        return $productos;
    }

    /**
     * Orden del listado: por NEGOCIO y, dentro de él, por CÓDIGO (SKU).
     *
     * El código se descompone en dos columnas para lograr un ordenamiento
     * "natural" y no alfabético crudo:
     *
     *   prefijo → todo lo que va antes del primer dígito  (RA- , TM- , BB-)
     *   número  → la parte numérica                       (1, 3, 4, 10)
     *
     * Sin esa separación, el orden alfabético pondría RA-10 entre RA-1 y
     * RA-3, lo cual es inútil para buscar una pieza en el almacén. Los
     * códigos sin número quedan con 0 y se resuelven con IFNULL(sku).
     *
     * @return string Cláusula ORDER BY lista para usar en una consulta.
     */
    private static function ordenPorCodigo(): string
    {
        // El orden vive en Productos: es el mismo criterio que usa el catálogo
        // y ambos deben coincidir si no, buscar un código "falla" de pantalla
        // en pantalla.
        return Productos::ordenPorCodigo();
    }

    /** Cuenta los productos que cumplen los criterios indicados. */
    public static function contar(array $filtros = []): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS total
               FROM productos p
               JOIN empresas e ON e.id = p.empresa_id
              WHERE $donde"
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Cláusula WHERE compartida por listar() y contar().
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condiciones(array $filtros): array
    {
        $termino  = trim((string) ($filtros['q'] ?? ''));
        $empresaId = (int) ($filtros['empresa'] ?? 0);
        $estado   = (string) ($filtros['estado'] ?? 'todos');

        $patron   = '%' . $termino . '%';
        // OJO: la cláusula de búsqueda va ENTRE paréntesis. Sin ellos, al
        // combinarse con implode(' AND ', ...) el OR de "sin término" ganaría
        // por precedencia y los filtros de empresa/estado se ignorarían.
        $donde    = ['(? = "" OR p.nombre LIKE ? OR IFNULL(p.sku, "") LIKE ? OR IFNULL(p.categoria, "") LIKE ?)'];
        $tipos    = 'ssss';
        $valores  = [$termino, $patron, $patron, $patron];

        if ($empresaId > 0) {
            $donde[]   = 'EXISTS (SELECT 1 FROM producto_empresa pe WHERE pe.producto_id = p.id AND pe.empresa_id = ?)';
            $tipos    .= 'i';
            $valores[] = $empresaId;
        }

        if ($estado === 'minimo') {
            $donde[] = 'p.stock_minimo IS NOT NULL AND p.stock <= p.stock_minimo';
        }

        if ($estado === 'bajo') {
            $donde[] = 'p.stock <= 0';
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Últimos movimientos registrados (bitácora visible en la pantalla).
     *
     * @return array<int, object>
     */
    public static function movimientos(int $limite = 15): array
    {
        $limite = max(1, (int) $limite);

        $consulta = self::$db->prepare(
            'SELECT m.*, p.nombre AS producto_nombre, p.sku AS producto_sku,
                    u.nombre AS usuario_nombre
               FROM movimientos_inventario m
               JOIN productos p ON p.id = m.producto_id
               LEFT JOIN usuarios u ON u.id = m.usuario_id
              ORDER BY m.id DESC
              LIMIT ?'
        );
        $consulta->bind_param('i', $limite);
        $consulta->execute();
        $registros = $consulta->get_result();

        $lista = [];
        while ($fila = $registros->fetch_assoc()) {
            $lista[] = (object) $fila;
        }

        $consulta->close();
        return $lista;
    }

    /* --------------------------------------------------------------------
     * RESUMEN PARA EL PDF
     * ------------------------------------------------------------------ */

    /**
     * Inventario agrupado por empresa activa, para "Exportar Inventario".
     *
     * Se incluyen los productos activos y también los inactivos que aún
     * conserven existencias: un artículo dado de baja con piezas en almacén
     * sigue siendo parte del inventario.
     *
     * @return array<int, array{
     *     id: int, nombre: string, logo: string,
     *     productos: array<int, array{sku: string, nombre: string, precio: float,
     *                                 stock: int, monto: float}>,
     *     piezas: int, importe: float
     * }>
     */
    public static function paraPdf(): array
    {
        $resultado = self::$db->query(
            'SELECT p.sku, p.nombre, p.precio, p.stock,
                    e.id AS empresa_id, e.nombre AS empresa_nombre, e.logo AS empresa_logo
               FROM productos p
               JOIN producto_empresa pe ON pe.producto_id = p.id
               JOIN empresas e ON e.id = pe.empresa_id
              WHERE e.activa = 1 AND (p.activo = 1 OR p.stock <> 0)
              ORDER BY ' . self::ordenPorCodigo()
        );

        $empresas = [];

        while ($fila = $resultado->fetch_assoc()) {
            $empresaId = (int) $fila['empresa_id'];

            if (!isset($empresas[$empresaId])) {
                $empresas[$empresaId] = [
                    'id'        => $empresaId,
                    'nombre'    => (string) $fila['empresa_nombre'],
                    'logo'      => (string) ($fila['empresa_logo'] ?? ''),
                    'productos' => [],
                    'piezas'    => 0,
                    'importe'   => 0.0,
                ];
            }

            $stock  = (int) $fila['stock'];
            $precio = (float) $fila['precio'];
            $monto  = round($precio * $stock, 2);

            $empresas[$empresaId]['productos'][] = [
                'sku'    => (string) ($fila['sku'] ?? ''),
                'nombre' => (string) $fila['nombre'],
                'precio' => $precio,
                'stock'  => $stock,
                'monto'  => $monto,
            ];

            $empresas[$empresaId]['piezas']  += $stock;
            $empresas[$empresaId]['importe']  = round($empresas[$empresaId]['importe'] + $monto, 2);
        }

        $resultado->free();
        return array_values($empresas);
    }
}
