<?php

namespace App;

/**
 * Materiales — materias primas y materiales que SÍ se controlan en inventario.
 *
 * No son productos para venta: viven aparte del catálogo comercial (tabla materiales) y sus movimientos
 * quedan en movimientos_material. Las compras de consumibles (pintura, discos, guantes…) pueden
 * registrarse SIN material; solo los que el usuario decide controlar generan existencias.
 *
 * Las funciones de movimiento NO abren transacción: las llaman OrdenesCompra::recibir() (que ya la abrió).
 */
class Materiales
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

    /** Cantidad como entero si es entera (así las pruebas y las pantallas no muestran 5.000). */
    public static function numero($valor)
    {
        $f = round((float) $valor, 3);
        return $f == (int) $f ? (int) $f : $f;
    }

    /** @return array<string, mixed>|null */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM materiales WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listar(bool $soloActivos = true): array
    {
        $donde = $soloActivos ? 'WHERE activo = 1' : '';
        return self::$db->query("SELECT * FROM materiales $donde ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
    }

    /** Crea un material (o devuelve el existente con ese nombre). 0 si no es válido. */
    public static function crear(string $nombre, string $unidad = 'pza', ?string $minimo = null): int
    {
        $nombre = trim($nombre);
        $unidad = trim($unidad) ?: 'pza';
        if ($nombre === '' || mb_strlen($nombre) > 150 || mb_strlen($unidad) > 20) {
            self::$errores[] = 'El material necesita un nombre (hasta 150 caracteres) y una unidad corta.';
            return 0;
        }

        $consulta = self::$db->prepare('SELECT id FROM materiales WHERE nombre = ? LIMIT 1');
        $consulta->bind_param('s', $nombre);
        $consulta->execute();
        $existente = $consulta->get_result()->fetch_assoc();
        $consulta->close();
        if ($existente) {
            return (int) $existente['id'];
        }

        $minimo = $minimo !== null && is_numeric($minimo) ? number_format((float) $minimo, 3, '.', '') : null;
        $consulta = self::$db->prepare('INSERT INTO materiales (nombre, unidad, minimo) VALUES (?, ?, ?)');
        $consulta->bind_param('sss', $nombre, $unidad, $minimo);
        $consulta->execute();
        $id = (int) self::$db->insert_id;
        $consulta->close();

        return $id;
    }

    /**
     * Suma lo recibido de una compra. NO abre transacción.
     *
     * @param array<int, float|int|string> $agrupadas material_id => cantidad recibida.
     */
    public static function entradaPorRecepcion(array $agrupadas, int $recepcionId, int $usuarioId, string $notas = ''): bool
    {
        return self::mover($agrupadas, 'entrada', $recepcionId, 'recepcion', $usuarioId, $notas);
    }

    /** Consumo del material (salida). NO abre transacción. */
    public static function consumir(int $materialId, $cantidad, int $usuarioId, string $notas = ''): bool
    {
        self::$errores = [];
        if (!is_numeric($cantidad) || (float) $cantidad <= 0) {
            self::$errores[] = 'La cantidad a consumir debe ser mayor a cero.';
            return false;
        }
        return self::mover([$materialId => $cantidad], 'salida', 0, 'consumo', $usuarioId, $notas);
    }

    /**
     * Fija la existencia (corrección). Exige razón; el delta queda en la bitácora de movimientos y en la de auditoría.
     */
    public static function corregir(int $materialId, $nuevaExistencia, string $razon, int $usuarioId): bool
    {
        self::$errores = [];
        $razon = trim($razon);
        if (mb_strlen($razon) < 5) {
            self::$errores[] = 'Escribe la razón de la corrección (mínimo 5 caracteres).';
            return false;
        }
        if (!is_numeric($nuevaExistencia)) {
            self::$errores[] = 'La existencia debe ser un número.';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $consulta = self::$db->prepare('SELECT existencia FROM materiales WHERE id = ? FOR UPDATE');
            $consulta->bind_param('i', $materialId);
            $consulta->execute();
            $actual = $consulta->get_result()->fetch_assoc();
            $consulta->close();
            if (!$actual) {
                self::$db->rollback();
                self::$errores[] = 'El material no existe.';
                return false;
            }

            $nueva = round((float) $nuevaExistencia, 3);
            $delta = round($nueva - (float) $actual['existencia'], 3);
            if ($delta != 0.0) {
                $actualizar = self::$db->prepare('UPDATE materiales SET existencia = ? WHERE id = ?');
                $texto = number_format($nueva, 3, '.', '');
                $actualizar->bind_param('si', $texto, $materialId);
                $actualizar->execute();
                $actualizar->close();

                self::registrar($materialId, 'ajuste', abs($delta), $nueva, 0, 'correccion', $usuarioId, $razon);
                Auditoria::registrar('material', $materialId, 'corregir', $usuarioId, $razon, [
                    'antes' => (float) $actual['existencia'], 'despues' => $nueva,
                ]);
            }
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[Materiales::corregir] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible corregir la existencia.';
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function movimientos(int $materialId = 0, int $limite = 50): array
    {
        $donde = $materialId > 0 ? 'WHERE m.material_id = ' . $materialId : '';
        return self::$db->query(
            "SELECT m.*, mt.nombre AS material_nombre, mt.unidad FROM movimientos_material m
               JOIN materiales mt ON mt.id = m.material_id $donde ORDER BY m.id DESC LIMIT " . (int) $limite
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** @param array<int, float|int|string> $agrupadas */
    private static function mover(array $agrupadas, string $tipo, int $recepcionId, string $motivo, int $usuarioId, string $notas): bool
    {
        $bloquear = self::$db->prepare('SELECT existencia FROM materiales WHERE id = ? FOR UPDATE');
        $actualizar = self::$db->prepare('UPDATE materiales SET existencia = ? WHERE id = ?');

        foreach ($agrupadas as $materialId => $cantidad) {
            $cantidad = round((float) $cantidad, 3);
            if ($materialId <= 0 || $cantidad <= 0) {
                continue;
            }
            $bloquear->bind_param('i', $materialId);
            $bloquear->execute();
            $actual = $bloquear->get_result()->fetch_assoc();
            if (!$actual) {
                self::$errores[] = 'El material #' . $materialId . ' no existe.';
                return false;
            }
            $saldo = round((float) $actual['existencia'] + ($tipo === 'salida' ? -$cantidad : $cantidad), 3);
            $texto = number_format($saldo, 3, '.', '');
            $actualizar->bind_param('si', $texto, $materialId);
            $actualizar->execute();
            self::registrar((int) $materialId, $tipo, $cantidad, $saldo, $recepcionId, $motivo, $usuarioId, $notas);
        }
        $bloquear->close();
        $actualizar->close();

        return true;
    }

    private static function registrar(int $materialId, string $tipo, float $cantidad, float $saldo, int $recepcionId, string $motivo, int $usuarioId, string $notas): void
    {
        $consulta = self::$db->prepare(
            'INSERT INTO movimientos_material (material_id, tipo, cantidad, saldo, recepcion_id, motivo, usuario_id, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $c = number_format($cantidad, 3, '.', '');
        $s = number_format($saldo, 3, '.', '');
        $recepcion = $recepcionId > 0 ? $recepcionId : null;
        $usuario = $usuarioId > 0 ? $usuarioId : null;
        $texto = $notas !== '' ? $notas : null;
        $consulta->bind_param('isssisis', $materialId, $tipo, $c, $s, $recepcion, $motivo, $usuario, $texto);
        $consulta->execute();
        $consulta->close();
    }
}
