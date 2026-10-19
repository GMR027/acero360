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
        int $ventaId = 0
    ): bool {
        // 'salida' resta; 'entrada' (reversión de una venta) suma.
        $negativo = $tipo === 'salida';

        $actualizar = self::$db->prepare(
            'UPDATE productos SET stock = stock ' . ($negativo ? '-' : '+') . ' ? WHERE id = ?'
        );
        $saldoDe = self::$db->prepare('SELECT stock FROM productos WHERE id = ?');
        $registrar = self::$db->prepare(
            'INSERT INTO movimientos_inventario
                (producto_id, tipo, cantidad, saldo, cotizacion_id, venta_id, usuario_id, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($agrupadas as $productoId => $cantidad) {
            if ($productoId <= 0 || $cantidad <= 0) {
                continue;
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

            $registrar->bind_param(
                'isiiiiis',
                $productoId, $tipo, $cantidad, $saldo, $cotizacion, $venta, $usuario, $texto
            );

            if (!$registrar->execute()) {
                self::$errores[] = 'No fue posible registrar el movimiento de inventario.';
                return false;
            }
        }

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
    public static function ajustar(int $productoId, int $nuevoStock, int $usuarioId, string $notas = ''): bool
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
                    (producto_id, tipo, cantidad, saldo, usuario_id, notas)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $registrar->bind_param('isiiis', $productoId, $tipo, $cantidad, $nuevoStock, $usuarioId, $texto);

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
            $donde[]   = 'p.empresa_id = ?';
            $tipos    .= 'i';
            $valores[] = $empresaId;
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
               JOIN empresas e ON e.id = p.empresa_id
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
