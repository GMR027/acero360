<?php

namespace App;

/**
 * PorTerminar — semiterminados recibidos de un proveedor que todavía requieren trabajo antes de venderse.
 *
 * Las unidades viven en productos.stock_por_terminar y NO cuentan como existencia vendible
 * (productos.stock). Cada movimiento queda en movimientos_por_terminar. Pasarlas a vendible es una
 * acción explícita (terminar()): resta de «por terminar» y suma a stock en la misma transacción,
 * con su movimiento en ambas bitácoras, así que ninguna unidad se cuenta dos veces.
 */
class PorTerminar
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

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

    public static function pendientes(int $productoId): int
    {
        $consulta = self::$db->prepare('SELECT stock_por_terminar FROM productos WHERE id = ?');
        $consulta->bind_param('i', $productoId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return (int) ($fila['stock_por_terminar'] ?? 0);
    }

    /**
     * Suma lo recibido (semiterminado) SIN tocar la existencia vendible. NO abre transacción.
     *
     * @param array<int, int> $agrupadas producto_id => unidades recibidas.
     */
    public static function entradaPorRecepcion(array $agrupadas, int $recepcionId, int $usuarioId, string $notas = ''): bool
    {
        foreach ($agrupadas as $productoId => $cantidad) {
            $cantidad = (int) $cantidad;
            if ($productoId <= 0 || $cantidad <= 0) {
                continue;
            }
            self::mover((int) $productoId, 'entrada', $cantidad, $recepcionId, $usuarioId, $notas);
        }
        return true;
    }

    /**
     * Pasa unidades de «por terminar» a existencia vendible. Nunca más de las pendientes.
     */
    public static function terminar(int $productoId, int $cantidad, int $usuarioId, string $notas = ''): bool
    {
        self::$errores = [];
        if ($cantidad <= 0) {
            self::$errores[] = 'La cantidad terminada debe ser mayor a cero.';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $consulta = self::$db->prepare('SELECT stock_por_terminar FROM productos WHERE id = ? FOR UPDATE');
            $consulta->bind_param('i', $productoId);
            $consulta->execute();
            $fila = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$fila) {
                self::$db->rollback();
                self::$errores[] = 'El producto no existe.';
                return false;
            }
            if ($cantidad > (int) $fila['stock_por_terminar']) {
                self::$db->rollback();
                self::$errores[] = 'Solo hay ' . (int) $fila['stock_por_terminar'] . ' pieza(s) por terminar; no se pueden terminar ' . $cantidad . '.';
                return false;
            }

            self::mover($productoId, 'terminado', $cantidad, 0, $usuarioId, $notas);
            if (!Inventario::entradaTerminado([$productoId => $cantidad], $usuarioId, $notas !== '' ? $notas : 'Terminado desde semiterminados')) {
                self::$db->rollback();
                self::$errores = Inventario::getErrores() ?: ['No fue posible sumar la existencia vendible.'];
                return false;
            }

            Auditoria::registrar('producto', $productoId, 'terminar', $usuarioId, $notas !== '' ? $notas : null, ['piezas' => $cantidad]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[PorTerminar::terminar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar las piezas terminadas.';
            return false;
        }
    }

    /** Corrección de las piezas por terminar (exige razón; queda en la bitácora). */
    public static function corregir(int $productoId, int $nuevo, string $razon, int $usuarioId): bool
    {
        self::$errores = [];
        $razon = trim($razon);
        if ($nuevo < 0 || mb_strlen($razon) < 5) {
            self::$errores[] = 'Indica una cantidad válida y la razón de la corrección (mínimo 5 caracteres).';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $consulta = self::$db->prepare('SELECT stock_por_terminar FROM productos WHERE id = ? FOR UPDATE');
            $consulta->bind_param('i', $productoId);
            $consulta->execute();
            $fila = $consulta->get_result()->fetch_assoc();
            $consulta->close();
            if (!$fila) {
                self::$db->rollback();
                self::$errores[] = 'El producto no existe.';
                return false;
            }
            $delta = $nuevo - (int) $fila['stock_por_terminar'];
            if ($delta !== 0) {
                self::mover($productoId, 'ajuste', $delta, 0, $usuarioId, $razon);
                Auditoria::registrar('producto', $productoId, 'corregir_por_terminar', $usuarioId, $razon, [
                    'antes' => (int) $fila['stock_por_terminar'], 'despues' => $nuevo,
                ]);
            }
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[PorTerminar::corregir] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible corregir la cantidad.';
            return false;
        }
    }

    /** Productos con piezas por terminar. @return array<int, array<string, mixed>> */
    public static function listar(): array
    {
        return self::$db->query(
            'SELECT id, nombre, sku, imagen, stock, stock_por_terminar FROM productos
              WHERE stock_por_terminar <> 0 ORDER BY nombre'
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public static function movimientos(int $productoId = 0, int $limite = 50): array
    {
        $donde = $productoId > 0 ? 'WHERE m.producto_id = ' . $productoId : '';
        return self::$db->query(
            "SELECT m.*, p.nombre AS producto_nombre, p.sku FROM movimientos_por_terminar m
               JOIN productos p ON p.id = m.producto_id $donde ORDER BY m.id DESC LIMIT " . (int) $limite
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** Aplica el delta a productos.stock_por_terminar y lo anota. Positivo suma; 'terminado' resta. */
    private static function mover(int $productoId, string $tipo, int $cantidad, int $recepcionId, int $usuarioId, string $notas): void
    {
        $firmada = $tipo === 'terminado' ? -$cantidad : $cantidad;
        $consulta = self::$db->prepare('UPDATE productos SET stock_por_terminar = stock_por_terminar + ? WHERE id = ?');
        $consulta->bind_param('ii', $firmada, $productoId);
        $consulta->execute();
        $consulta->close();

        $saldo = self::pendientes($productoId);
        $registrar = self::$db->prepare(
            'INSERT INTO movimientos_por_terminar (producto_id, tipo, cantidad, saldo, recepcion_id, usuario_id, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $recepcion = $recepcionId > 0 ? $recepcionId : null;
        $usuario = $usuarioId > 0 ? $usuarioId : null;
        $texto = $notas !== '' ? $notas : null;
        $registrar->bind_param('isiiiis', $productoId, $tipo, $cantidad, $saldo, $recepcion, $usuario, $texto);
        $registrar->execute();
        $registrar->close();
    }
}
