<?php

namespace App;

/**
 * InventarioReportes — exportaciones, historial de movimientos y fotografías mensuales del inventario.
 *
 * Todo es de SOLO LECTURA sobre las existencias: ningún reporte ni fotografía modifica el inventario.
 * (Las fotografías escriben únicamente en sus propias tablas inventario_cortes*.)
 */
class InventarioReportes
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    public const MOTIVOS = [
        'venta' => 'Venta', 'recepcion' => 'Recepción de proveedor', 'ajuste' => 'Ajuste manual', 'devolucion' => 'Devolución de cliente',
        'correccion' => 'Corrección', 'cancelacion' => 'Cancelación de venta',
    ];

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
     * INVENTARIO ACTUAL (para exportar, con los mismos filtros de la pantalla)
     * ------------------------------------------------------------------ */

    /**
     * @param array{q?: string, empresa?: int, estado?: string} $filtros estado: todos | bajo | minimo
     * @return array<int, array<string, mixed>>
     */
    public static function actual(array $filtros): array
    {
        $donde = ['(? = "" OR p.nombre LIKE ? OR IFNULL(p.sku, "") LIKE ? OR IFNULL(p.categoria, "") LIKE ?)'];
        $termino = trim((string) ($filtros['q'] ?? ''));
        $patron = '%' . $termino . '%';
        $tipos = 'ssss';
        $valores = [$termino, $patron, $patron, $patron];

        if ((int) ($filtros['empresa'] ?? 0) > 0) {
            $donde[] = 'EXISTS (SELECT 1 FROM producto_empresa pe WHERE pe.producto_id = p.id AND pe.empresa_id = ?)';
            $tipos .= 'i';
            $valores[] = (int) $filtros['empresa'];
        }
        $estado = (string) ($filtros['estado'] ?? 'todos');
        if ($estado === 'bajo') {
            $donde[] = 'p.stock <= 0';
        } elseif ($estado === 'minimo') {
            $donde[] = 'p.stock_minimo IS NOT NULL AND p.stock <= p.stock_minimo';
        }

        $consulta = self::$db->prepare(
            "SELECT p.id, p.sku, p.nombre, p.categoria, p.unidad, p.precio, p.costo_actual, p.stock, p.stock_minimo, p.activo,
                    (SELECT GROUP_CONCAT(e.nombre ORDER BY e.nombre SEPARATOR ' / ') FROM producto_empresa pe JOIN empresas e ON e.id = pe.empresa_id WHERE pe.producto_id = p.id) AS negocios
               FROM productos p WHERE " . implode(' AND ', $donde) . ' ORDER BY ' . Productos::ordenPorCodigoSimple()
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Encabezados y filas planas del inventario actual.
     *
     * @return array{0: string[], 1: array<int, array<int, scalar|null>>}
     */
    public static function filasActual(array $filas): array
    {
        $enc = ['SKU', 'Producto', 'Negocios', 'Unidad', 'Existencias', 'Cantidad mínima', 'Alerta', 'Costo actual', 'Precio de lista', 'Valor al costo', 'Valor a precio', 'Activo'];
        $datos = [];
        foreach ($filas as $f) {
            $bajo = $f['stock_minimo'] !== null && (int) $f['stock'] <= (int) $f['stock_minimo'];
            $datos[] = [$f['sku'], $f['nombre'], $f['negocios'], $f['unidad'], (int) $f['stock'], $f['stock_minimo'] !== null ? (int) $f['stock_minimo'] : '',
                (int) $f['stock'] < 0 ? 'Existencia negativa' : ($bajo ? 'Bajo el mínimo' : ''),
                (float) $f['costo_actual'], (float) $f['precio'],
                round((int) $f['stock'] * (float) $f['costo_actual'], 2), round((int) $f['stock'] * (float) $f['precio'], 2), (int) $f['activo'] === 1 ? 'Sí' : 'No'];
        }

        return [$enc, $datos];
    }

    /* --------------------------------------------------------------------
     * HISTORIAL DE MOVIMIENTOS
     * ------------------------------------------------------------------ */

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
    private static function condicionesMovimientos(array $f): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];

        foreach (['desde' => 'DATE(m.creado_en) >= ?', 'hasta' => 'DATE(m.creado_en) <= ?'] as $campo => $sql) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$campo] ?? ''))) {
                $donde[] = $sql;
                $tipos .= 's';
                $valores[] = $f[$campo];
            }
        }
        if ((int) ($f['producto'] ?? 0) > 0) {
            $donde[] = 'm.producto_id = ?';
            $tipos .= 'i';
            $valores[] = (int) $f['producto'];
        }
        if (isset(self::MOTIVOS[$f['motivo'] ?? ''])) {
            $donde[] = 'm.motivo = ?';
            $tipos .= 's';
            $valores[] = $f['motivo'];
        }
        if ((int) ($f['empresa'] ?? 0) > 0) {
            $donde[] = 'EXISTS (SELECT 1 FROM producto_empresa pe WHERE pe.producto_id = m.producto_id AND pe.empresa_id = ?)';
            $tipos .= 'i';
            $valores[] = (int) $f['empresa'];
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /** @return array<int, array<string, mixed>> */
    public static function movimientos(array $filtros, int $limite = 25, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condicionesMovimientos($filtros);
        $consulta = self::$db->prepare(
            "SELECT m.*, p.sku, p.nombre AS producto, u.nombre AS usuario_nombre,
                    v.folio AS venta_folio, rc.id AS recepcion_n, oc.folio AS orden_folio, c.folio AS cotizacion_folio
               FROM movimientos_inventario m
               JOIN productos p ON p.id = m.producto_id
               LEFT JOIN usuarios u ON u.id = m.usuario_id
               LEFT JOIN ventas v ON v.id = m.venta_id
               LEFT JOIN recepciones rc ON rc.id = m.recepcion_id
               LEFT JOIN ordenes_compra oc ON oc.id = rc.orden_id
               LEFT JOIN cotizaciones c ON c.id = m.cotizacion_id
              WHERE $donde ORDER BY m.creado_en DESC, m.id DESC LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    public static function contarMovimientos(array $filtros): int
    {
        [$donde, $tipos, $valores] = self::condicionesMovimientos($filtros);
        $consulta = self::$db->prepare("SELECT COUNT(*) FROM movimientos_inventario m WHERE $donde");
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        return $n;
    }

    /** @return array{0: string[], 1: array<int, array<int, scalar|null>>} */
    public static function filasMovimientos(array $filtros): array
    {
        $enc = ['Fecha', 'SKU', 'Producto', 'Tipo', 'Motivo', 'Cantidad', 'Saldo después', 'Documento de origen', 'Usuario', 'Notas'];
        $datos = [];
        foreach (self::movimientos($filtros, 100000, 0) as $m) {
            $signo = $m['tipo'] === 'salida' ? -1 : ($m['tipo'] === 'entrada' ? 1 : 0);
            $origen = $m['venta_folio'] ?: ($m['orden_folio'] ?: ($m['cotizacion_folio'] ?: ''));
            $datos[] = [$m['creado_en'], $m['sku'], $m['producto'], ucfirst($m['tipo']), self::MOTIVOS[$m['motivo']] ?? ($m['motivo'] ?? ''),
                $signo !== 0 ? $signo * (int) $m['cantidad'] : (int) $m['cantidad'], (int) $m['saldo'], $origen, $m['usuario_nombre'] ?? '', $m['notas'] ?? ''];
        }

        return [$enc, $datos];
    }

    /* --------------------------------------------------------------------
     * FOTOGRAFÍAS MENSUALES
     * ------------------------------------------------------------------ */

    /**
     * Toma la fotografía del inventario de un mes (una por mes). No modifica existencias.
     *
     * @return bool false si ya existe la del mes o el mes no es válido
     */
    public static function generarCorte(int $anio, int $mes, ?int $usuarioId): bool
    {
        self::$errores = [];
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            self::$errores[] = 'El periodo no es válido.';
            return false;
        }
        if (sprintf('%04d-%02d', $anio, $mes) > date('Y-m')) {
            self::$errores[] = 'No se puede tomar la fotografía de un mes futuro.';
            return false;
        }

        try {
            self::$db->begin_transaction();

            $crear = self::$db->prepare('INSERT INTO inventario_cortes (anio, mes, generado_por) VALUES (?, ?, ?)');
            try {
                $crear->bind_param('iii', $anio, $mes, $usuarioId);
                $crear->execute();
            } catch (\mysqli_sql_exception $duplicado) {
                self::$db->rollBack();
                self::$errores[] = 'Ya existe la fotografía de ese mes; una fotografía no se reemplaza.';
                return false;
            }
            $corteId = (int) self::$db->insert_id;
            $crear->close();

            self::$db->query(
                "INSERT INTO inventario_corte_detalles (corte_id, producto_id, sku, nombre, stock, costo_actual, precio)
                 SELECT $corteId, id, sku, nombre, stock, costo_actual, precio FROM productos"
            );
            self::$db->query(
                "UPDATE inventario_cortes c JOIN (
                    SELECT corte_id, COUNT(*) AS n, SUM(stock) AS piezas, SUM(ROUND(stock * costo_actual * 100)) / 100 AS vc, SUM(ROUND(stock * precio * 100)) / 100 AS vp
                      FROM inventario_corte_detalles WHERE corte_id = $corteId GROUP BY corte_id) d ON d.corte_id = c.id
                    SET c.productos = d.n, c.piezas = d.piezas, c.valor_costo = d.vc, c.valor_precio = d.vp"
            );

            Auditoria::registrar('inventario_corte', $corteId, 'generar', $usuarioId, null, ['periodo' => sprintf('%04d-%02d', $anio, $mes)]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[InventarioReportes::generarCorte] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible generar la fotografía.';
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function cortes(): array
    {
        return self::$db->query(
            'SELECT c.*, u.nombre AS usuario_nombre FROM inventario_cortes c LEFT JOIN usuarios u ON u.id = c.generado_por ORDER BY c.anio DESC, c.mes DESC'
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function corte(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM inventario_cortes WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** ¿La fotografía se tomó después del cierre del mes que representa? */
    public static function esTardia(array $corte): bool
    {
        $fin = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $corte['anio'], $corte['mes'])));

        return substr((string) $corte['generado_en'], 0, 10) > $fin;
    }

    /**
     * Comparación de una fotografía contra las existencias actuales, por producto.
     *
     * @return array<int, array{producto_id: int, sku: ?string, nombre: string, antes: ?int, ahora: ?int, diferencia: int}>
     */
    public static function comparar(int $corteId): array
    {
        $consulta = self::$db->prepare(
            'SELECT COALESCE(d.producto_id, p.id) AS producto_id, COALESCE(d.sku, p.sku) AS sku, COALESCE(d.nombre, p.nombre) AS nombre,
                    d.stock AS antes, p.stock AS ahora
               FROM inventario_corte_detalles d
               LEFT JOIN productos p ON p.id = d.producto_id
              WHERE d.corte_id = ?
             UNION
             SELECT p.id, p.sku, p.nombre, NULL, p.stock FROM productos p
              WHERE NOT EXISTS (SELECT 1 FROM inventario_corte_detalles d WHERE d.corte_id = ? AND d.producto_id = p.id)
             ORDER BY sku, nombre'
        );
        $consulta->bind_param('ii', $corteId, $corteId);
        $consulta->execute();
        $filas = [];
        foreach ($consulta->get_result() as $f) {
            $antes = $f['antes'] === null ? null : (int) $f['antes'];
            $ahora = $f['ahora'] === null ? null : (int) $f['ahora'];
            $filas[] = ['producto_id' => (int) $f['producto_id'], 'sku' => $f['sku'], 'nombre' => $f['nombre'], 'antes' => $antes, 'ahora' => $ahora,
                'diferencia' => ($ahora ?? 0) - ($antes ?? 0)];
        }
        $consulta->close();

        return $filas;
    }
}
