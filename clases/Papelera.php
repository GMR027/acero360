<?php

namespace App;

use mysqli_sql_exception;

/**
 * Papelera — ventas y cotizaciones eliminadas.
 *
 * Al eliminar, el registro completo (encabezado y renglones) se guarda como copia en la tabla
 * `papelera` dentro de la misma transacción que lo borra. Durante 90 días se puede restaurar desde
 * Ajustes → Papelera; pasado ese plazo la copia se borra definitivamente (scripts/purgar_papelera.php).
 */
class Papelera
{
    public const DIAS = 90;

    /** @var \mysqli */
    protected static $db;

    /** @var string[] */
    protected static array $errores = [];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /** @return string[] */
    public static function getErrores(): array
    {
        return self::$errores;
    }

    /* --------------------------------------------------------------------
     * ENVIAR A LA PAPELERA (se llama DENTRO de la transacción que borra)
     * ------------------------------------------------------------------ */

    /** Copia la venta y sus renglones. Devuelve las filas o null si no existe. */
    public static function copiarVenta(int $id): ?array
    {
        $venta = self::filas('SELECT * FROM ventas WHERE id = ?', $id);
        if (!$venta) {
            return null;
        }

        return ['ventas' => $venta, 'venta_detalles' => self::filas('SELECT * FROM venta_detalles WHERE venta_id = ?', $id)];
    }

    /** Copia la cotización y sus renglones. */
    public static function copiarCotizacion(int $id): ?array
    {
        $cotizacion = self::filas('SELECT * FROM cotizaciones WHERE id = ?', $id);
        if (!$cotizacion) {
            return null;
        }

        return ['cotizaciones' => $cotizacion, 'cotizacion_detalles' => self::filas('SELECT * FROM cotizacion_detalles WHERE cotizacion_id = ?', $id)];
    }

    /** Guarda la copia. false si no se pudo (el llamador debe revertir y NO borrar). */
    public static function guardar(string $tipo, int $referenciaId, string $etiqueta, float $total, array $filas, int $usuarioId): bool
    {
        try {
            self::purgarVencidos();
            $json = json_encode($filas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $dias = self::DIAS;
            $consulta = self::$db->prepare(
                'INSERT INTO papelera (tipo, referencia_id, etiqueta, total, datos, eliminado_por, vence_en)
                 VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
            );
            $consulta->bind_param('sisdsii', $tipo, $referenciaId, $etiqueta, $total, $json, $usuarioId, $dias);
            $consulta->execute();
            $consulta->close();
            return true;
        } catch (\Throwable $e) {
            error_log('[Papelera::guardar] ' . $e->getMessage());
            self::$errores[] = 'No se pudo guardar la copia en la papelera; no se eliminó nada.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** @return array<int, array<string, mixed>> */
    public static function listar(): array
    {
        $resultado = self::$db->query(
            'SELECT p.id, p.tipo, p.referencia_id, p.etiqueta, p.total, p.eliminado_en, p.vence_en,
                    DATEDIFF(p.vence_en, NOW()) AS dias_restantes, u.nombre AS eliminado_por_nombre
               FROM papelera p LEFT JOIN usuarios u ON u.id = p.eliminado_por
              ORDER BY p.eliminado_en DESC'
        );

        return $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** @return array<string, mixed>|null */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM papelera WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /* --------------------------------------------------------------------
     * RESTAURAR / BORRAR DEFINITIVAMENTE
     * ------------------------------------------------------------------ */

    /** Devuelve el registro a su lugar. Si el folio ya lo usa otro, recibe uno nuevo. */
    public static function restaurar(int $id, int $usuarioId): bool
    {
        self::$errores = [];
        $item = self::buscarID($id);
        if (!$item) {
            self::$errores[] = 'Ese elemento ya no está en la papelera.';
            return false;
        }

        $filas = json_decode((string) $item['datos'], true);
        if (!is_array($filas)) {
            self::$errores[] = 'La copia guardada está dañada y no se puede restaurar.';
            return false;
        }

        try {
            self::$db->begin_transaction();

            $mensaje = $item['tipo'] === 'venta'
                ? self::restaurarVenta($filas, $usuarioId)
                : self::restaurarCotizacion($filas);

            if ($mensaje === null) {
                self::$db->rollBack();
                return false;
            }

            $borrar = self::$db->prepare('DELETE FROM papelera WHERE id = ?');
            $borrar->bind_param('i', $id);
            $borrar->execute();
            $borrar->close();

            self::$db->commit();
            self::$errores = [];
            return true;
        } catch (mysqli_sql_exception $e) {
            self::$db->rollBack();
            error_log('[Papelera::restaurar] ' . $e->getMessage());
            self::$errores[] = 'No fue posible restaurarlo: algún dato relacionado (cliente, negocio o producto) ya no existe.';
            return false;
        }
    }

    /** Borra definitivamente un elemento de la papelera. */
    public static function borrarDefinitivo(int $id): bool
    {
        $consulta = self::$db->prepare('DELETE FROM papelera WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $borrado = $consulta->affected_rows > 0;
        $consulta->close();

        return $borrado;
    }

    /** Borra lo que lleva más de 90 días. Devuelve cuántos. */
    public static function purgarVencidos(): int
    {
        self::$db->query('DELETE FROM papelera WHERE vence_en < NOW()');

        return (int) self::$db->affected_rows;
    }

    /* --------------------------------------------------------------------
     * INTERNOS
     * ------------------------------------------------------------------ */

    /** @return string|null texto del resultado, o null si falló (con el error ya registrado). */
    private static function restaurarVenta(array $filas, int $usuarioId): ?string
    {
        $venta = $filas['ventas'][0] ?? null;
        $detalles = $filas['venta_detalles'] ?? [];
        if (!$venta) {
            self::$errores[] = 'La copia guardada está incompleta.';
            return null;
        }

        $id = (int) $venta['id'];
        if (self::existe('ventas', 'id', $id)) {
            self::$errores[] = 'Ya existe una venta con ese número; no se restauró.';
            return null;
        }
        if (self::existe('ventas', 'folio', (string) $venta['folio'])) {
            $venta['folio'] = Ventas::siguienteFolio();
        }
        if ($venta['cotizacion_id'] !== null
            && (!self::existe('cotizaciones', 'id', (int) $venta['cotizacion_id']) || self::existe('ventas', 'cotizacion_id', (int) $venta['cotizacion_id']))) {
            $venta['cotizacion_id'] = null;
        }

        self::insertar('ventas', $venta);
        foreach ($detalles as $detalle) {
            self::insertar('venta_detalles', $detalle);
        }

        if ($venta['estado'] === 'registrada') {
            $lineas = Ventas::detalles($id);
            if (!Inventario::descontar($lineas, 0, $usuarioId, 'Salida por la venta ' . $venta['folio'] . ' (restaurada de la papelera)', $id)) {
                self::$errores = array_merge(self::$errores, Inventario::getErrores());
                return null;
            }
        }
        if (!Contabilidad::sincronizarIngreso($id, $usuarioId)) {
            self::$errores = array_merge(self::$errores, Contabilidad::getErrores());
            return null;
        }

        return $venta['folio'];
    }

    private static function restaurarCotizacion(array $filas): ?string
    {
        $cotizacion = $filas['cotizaciones'][0] ?? null;
        if (!$cotizacion) {
            self::$errores[] = 'La copia guardada está incompleta.';
            return null;
        }

        if (self::existe('cotizaciones', 'id', (int) $cotizacion['id'])) {
            self::$errores[] = 'Ya existe una cotización con ese número; no se restauró.';
            return null;
        }
        if (self::existe('cotizaciones', 'folio', (string) $cotizacion['folio'])) {
            $cotizacion['folio'] = Cotizaciones::siguienteFolio();
        }
        // Al eliminarla, su venta se canceló y el stock volvió: regresa como pendiente para no mover inventario.
        if ($cotizacion['estado'] === 'vendido') {
            $cotizacion['estado'] = 'pendiente';
        }

        self::insertar('cotizaciones', $cotizacion);
        foreach ($filas['cotizacion_detalles'] ?? [] as $detalle) {
            self::insertar('cotizacion_detalles', $detalle);
        }

        return $cotizacion['folio'];
    }

    /** @return array<int, array<string, mixed>> */
    private static function filas(string $sql, int $id): array
    {
        $consulta = self::$db->prepare($sql);
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** @param int|string $valor */
    private static function existe(string $tabla, string $columna, $valor): bool
    {
        $consulta = self::$db->prepare("SELECT 1 FROM `$tabla` WHERE `$columna` = ? LIMIT 1");
        $consulta->bind_param('s', $valor);
        $consulta->execute();
        $hay = $consulta->get_result()->num_rows > 0;
        $consulta->close();

        return $hay;
    }

    /** INSERT genérico de una fila guardada (las columnas vienen de nuestra propia copia). */
    private static function insertar(string $tabla, array $fila): void
    {
        $columnas = array_map(static fn(string $c): string => '`' . str_replace('`', '', $c) . '`', array_keys($fila));
        $consulta = self::$db->prepare(
            "INSERT INTO `$tabla` (" . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($fila), '?')) . ')'
        );
        $valores = array_values($fila);
        $consulta->bind_param(str_repeat('s', count($valores)), ...$valores);
        $consulta->execute();
        $consulta->close();
    }
}
