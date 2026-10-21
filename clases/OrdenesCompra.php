<?php

namespace App;

/**
 * OrdenesCompra — solicitudes de material a proveedores y su recepción.
 *
 * Una «compra» puede ser de dos tipos (columna tipo):
 *   • semiterminado — producto del catálogo que el proveedor entrega por terminar. La relación con el
 *     producto es opcional al capturar (se puede definir después) pero obligatoria para recibir.
 *   • insumo — pintura, discos, guantes, varilla…: partidas libres, sin producto del catálogo. Cada partida
 *     decide si se controla en inventario (como material, nunca como producto de venta) o es consumible.
 *
 * Reglas:
 *   • Crear o editar una orden NO toca el inventario.
 *   • Cada recepción (parcial o total) suma SOLO lo recibido, dentro de una transacción con la orden
 *     bloqueada y un token de un solo uso: reenviar el formulario o volver a consultar/exportar no duplica.
 *   • Lo recibido de un semiterminado va a «por terminar» (NO a la existencia vendible) salvo que se
 *     indique que ya viene terminado; los materiales van a materiales; los consumibles no se recepcionan.
 *   • Nunca se recibe más que lo pendiente, salvo confirmación explícita (queda auditada).
 *   • Costo del proveedor (partidas y recepciones) y costo interno del producto propio (productos.costo_actual)
 *     son independientes: una compra de semiterminados no escribe nunca en productos.costo_actual.
 *   • El costo recibido queda en recepcion_partidas (histórico); cambiar
 *     productos.costo_actual después nunca lo altera.
 *   • Cerrar una orden con faltantes es válido; una orden cerrada solo se
 *     corrige reabriéndola con motivo (queda en la bitácora de auditoría).
 *
 * Los importes se calculan en centavos enteros para evitar errores de coma flotante.
 */
class OrdenesCompra
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Id de la última orden creada (para redirigir al detalle). */
    public static $ultimoId = 0;

    public const ESTADOS = [
        'solicitada' => ['etiqueta' => 'Solicitada', 'clase' => 'badge--pendiente'],
        'parcial'    => ['etiqueta' => 'Recibida parcial', 'clase' => 'badge--pendiente'],
        'completa'   => ['etiqueta' => 'Recibida completa', 'clase' => 'badge--aprobado'],
        'cerrada'    => ['etiqueta' => 'Cerrada', 'clase' => 'badge--neutro'],
        'cancelada'  => ['etiqueta' => 'Cancelada', 'clase' => 'badge--error'],
        'sin_recepcion' => ['etiqueta' => 'Sin recepción', 'clase' => 'badge--neutro'],
    ];

    public const TIPOS = [
        'semiterminado' => 'Producto semiterminado para venta',
        'insumo'        => 'Material, insumo o consumible',
    ];

    /** Cantidad con hasta 3 decimales; entera si lo es (así 5 sigue siendo int). */
    public static function num($valor)
    {
        return Materiales::numero($valor);
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

    /** Importe en pesos de cantidad × costo, calculado en centavos (cantidad con hasta 3 decimales). */
    public static function importe($cantidad, $costo): float
    {
        $milesimas = (int) round((float) $cantidad * 1000);
        return ((int) round(((int) round(((float) $costo) * 100) * $milesimas) / 1000)) / 100;
    }

    /** Convierte los arreglos paralelos del formulario en partidas. */
    public static function lineasDePost(array $post): array
    {
        $lineas = [];
        $n = max(count((array) ($post['cantidad'] ?? [])), count((array) ($post['producto_id'] ?? [])), count((array) ($post['descripcion'] ?? [])));
        for ($i = 0; $i < $n; $i++) {
            $cantidad = str_replace(',', '.', trim((string) ($post['cantidad'][$i] ?? '')));
            $costo = str_replace(',', '.', trim((string) ($post['costo'][$i] ?? '0')));
            $producto = (int) ($post['producto_id'][$i] ?? 0);
            $material = (int) ($post['material_id'][$i] ?? 0);
            $descripcion = trim((string) ($post['descripcion'][$i] ?? ''));
            $nuevo = trim((string) ($post['material_nuevo'][$i] ?? ''));
            $catalogo = (int) ($post['proveedor_producto_id'][$i] ?? 0);
            if ($producto === 0 && $material === 0 && $catalogo === 0 && $descripcion === '' && $nuevo === '' && ($cantidad === '' || (float) $cantidad <= 1) && (float) $costo === 0.0) {
                continue; // renglón vacío
            }
            $lineas[] = [
                'producto_id'    => $producto,
                'proveedor_producto_id' => $catalogo,
                'material_id'    => $material,
                'material_nuevo' => $nuevo,
                'cantidad'       => $cantidad,
                'costo'          => $costo,
                'descripcion'    => $descripcion,
                'unidad'         => trim((string) ($post['unidad'][$i] ?? '')),
                'controla'       => !empty($post['controla'][$i]) ? 1 : 0,
                'aplica_iva'     => !empty($post['aplica_iva'][$i]) ? 1 : 0,
            ];
        }
        return $lineas;
    }

    /** Encabezado de la compra a partir del formulario (los mismos campos al crear y al editar). */
    public static function encabezadoDePost(array $post): array
    {
        return [
            'folio'           => trim((string) ($post['folio'] ?? '')),
            'tipo'            => (string) ($post['tipo'] ?? 'semiterminado'),
            'proveedor_id'    => (int) ($post['proveedor_id'] ?? 0),
            'fecha_solicitud' => (string) ($post['fecha_solicitud'] ?? ''),
            'referencia'      => trim((string) ($post['referencia'] ?? '')),
            'categoria_id'    => (int) ($post['categoria_id'] ?? 0),
            'empresa_id'      => (int) ($post['empresa_id'] ?? 0),
            'estado_cfdi'     => (string) ($post['estado_cfdi'] ?? 'pendiente_cfdi'),
            'iva'             => trim((string) ($post['iva'] ?? '')),
            'iva_tasa'        => trim((string) ($post['iva_tasa'] ?? '')),
            'observaciones'   => trim((string) ($post['observaciones'] ?? '')),
        ];
    }

    /** Partidas guardadas, en la forma que usa el formulario (para editar). */
    public static function lineasDeOrden(int $ordenId): array
    {
        return array_map(static fn ($p) => [
            'producto_id'    => (int) $p['producto_id'],
            'proveedor_producto_id' => (int) $p['proveedor_producto_id'],
            'codigo'         => (string) ($p['codigo'] ?? ''),
            'costo_catalogo' => $p['costo_catalogo'],
            'material_id'    => (int) $p['material_id'],
            'material_nuevo' => (string) ($p['material_nombre'] ?? ''),
            'cantidad'       => $p['solicitada'],
            'costo'          => $p['costo_esperado'],
            'descripcion'    => (string) $p['descripcion'],
            'unidad'         => (string) ($p['unidad'] ?? ''),
            'controla'       => (int) $p['controla'],
            'aplica_iva'     => (int) $p['aplica_iva'],
        ], self::partidas($ordenId));
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Encabezado de una orden con el nombre del proveedor, o null. */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT o.*, p.nombre AS proveedor_nombre, p.rfc AS proveedor_rfc,
                    p.telefono AS proveedor_telefono, p.email AS proveedor_email
               FROM ordenes_compra o
               JOIN proveedores p ON p.id = o.proveedor_id
              WHERE o.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /**
     * Partidas con lo recibido hasta hoy.
     *
     * Por partida: solicitada, recibida, pendiente (negativa = excedente),
     * costo esperado, costo final (promedio ponderado de lo recibido) y la
     * diferencia económica = importe recibido − (recibida × costo esperado).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function partidas(int $ordenId): array
    {
        $consulta = self::$db->prepare(
            'SELECT c.*, pr.nombre AS producto_nombre, pr.sku AS producto_sku, pr.imagen AS producto_imagen,
                    pr.unidad AS producto_unidad, mt.nombre AS material_nombre, mt.unidad AS material_unidad,
                    COALESCE(SUM(rp.cantidad_recibida), 0) AS recibida,
                    COALESCE(SUM(ROUND(rp.cantidad_recibida * rp.costo_unitario * 100)), 0) AS recibido_centavos
               FROM orden_compra_partidas c
               LEFT JOIN productos pr ON pr.id = c.producto_id
               LEFT JOIN materiales mt ON mt.id = c.material_id
               LEFT JOIN recepcion_partidas rp ON rp.partida_id = c.id
              WHERE c.orden_id = ?
              GROUP BY c.id
              ORDER BY c.id'
        );
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $partidas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $solicitada = round((float) $fila['cantidad_solicitada'], 3);
            $recibida   = round((float) $fila['recibida'], 3);
            $recibido   = (int) $fila['recibido_centavos'];
            $esperadoRecibido = (int) round((int) round((float) $fila['costo_esperado'] * 100) * $recibida);

            $fila['solicitada']       = self::num($solicitada);
            $fila['recibida']         = self::num($recibida);
            $fila['pendiente']        = self::num($solicitada - $recibida);
            $fila['importe_recibido'] = $recibido / 100;
            $fila['costo_final']      = $recibida > 0 ? round($recibido / 100 / $recibida, 2) : null;
            $fila['diferencia']       = ($recibido - $esperadoRecibido) / 100;
            $fila['nombre_mostrado']  = $fila['producto_nombre'] ?? $fila['material_nombre'] ?? ($fila['descripcion'] ?: 'Sin descripción');
            // Si el costo usado difiere del que tenía el catálogo al comprar, el usuario lo modificó a mano.
            $fila['costo_modificado'] = $fila['proveedor_producto_id'] !== null && $fila['costo_catalogo'] !== null
                && abs((float) $fila['costo_esperado'] - (float) $fila['costo_catalogo']) > 0.004;
            $fila['unidad_mostrada']  = $fila['unidad'] ?: ($fila['producto_unidad'] ?? $fila['material_unidad'] ?? 'pza');
            $partidas[] = $fila;
        }
        $consulta->close();

        return $partidas;
    }

    /**
     * Historial de recepciones con sus renglones.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recepciones(int $ordenId): array
    {
        $consulta = self::$db->prepare(
            'SELECT r.*, u.nombre AS usuario_nombre
               FROM recepciones r
               LEFT JOIN usuarios u ON u.id = r.usuario_id
              WHERE r.orden_id = ? ORDER BY r.id'
        );
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $recepciones = [];
        while ($fila = $resultado->fetch_assoc()) {
            $fila['renglones'] = [];
            $recepciones[(int) $fila['id']] = $fila;
        }
        $consulta->close();

        if ($recepciones) {
            $consulta = self::$db->prepare(
                'SELECT rp.*, pr.nombre AS producto_nombre, pr.sku AS producto_sku, mt.nombre AS material_nombre
                   FROM recepcion_partidas rp
                   LEFT JOIN productos pr ON pr.id = rp.producto_id
                   LEFT JOIN materiales mt ON mt.id = rp.material_id
                   JOIN recepciones r ON r.id = rp.recepcion_id
                  WHERE r.orden_id = ? ORDER BY rp.id'
            );
            $consulta->bind_param('i', $ordenId);
            $consulta->execute();
            $resultado = $consulta->get_result();
            while ($fila = $resultado->fetch_assoc()) {
                $recepciones[(int) $fila['recepcion_id']]['renglones'][] = $fila;
            }
            $consulta->close();
        }

        return array_values($recepciones);
    }

    /**
     * Totales para el reporte de revisión antes del pago.
     *
     * @return array{esperado: float, recibido: float, diferencia: float,
     *               piezas_solicitadas: int, piezas_recibidas: int, piezas_pendientes: int}
     */
    public static function totales(array $partidas): array
    {
        $esperado = 0;
        $recibido = 0;
        $solicitadas = 0;
        $recibidas = 0;
        $pendientes = 0;

        foreach ($partidas as $partida) {
            $esperado    += (int) round((float) $partida['importe_esperado'] * 100);
            $recibido    += (int) round((float) $partida['importe_recibido'] * 100);
            // Los consumibles no se recepcionan: no cuentan como piezas solicitadas, recibidas ni pendientes.
            if ((int) ($partida['controla'] ?? 1) === 1) {
                $solicitadas += $partida['solicitada'];
                $recibidas   += $partida['recibida'];
                $pendientes  += max(0, $partida['pendiente']);
            }
        }

        return [
            'esperado'           => $esperado / 100,
            'recibido'           => $recibido / 100,
            'diferencia'         => array_sum(array_column($partidas, 'diferencia')),
            'piezas_solicitadas' => $solicitadas,
            'piezas_recibidas'   => $recibidas,
            'piezas_pendientes'  => $pendientes,
        ];
    }

    /** Suma de pagos vigentes de la compra `o` (para filtros y columnas). */
    private const PAGADO_SQL = "(SELECT COALESCE(SUM(pc.importe), 0) FROM pagos_compra pc WHERE pc.orden_id = o.id AND pc.estado = 'vigente')";
    private const TOTAL_SQL = '(o.total_esperado + COALESCE(o.iva, 0))';

    /** @return array<int, array<string, mixed>> */
    public static function listar(array $filtros, int $limite, int $offset): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT o.*, p.nombre AS proveedor_nombre, cat.nombre AS categoria_nombre,
                    (SELECT COUNT(*) FROM documentos d WHERE d.entidad = \'gasto\' AND d.entidad_id = o.gasto_id AND d.activo = 1) AS documentos,
                    ' . self::TOTAL_SQL . ' AS total_compra, ' . self::PAGADO_SQL . " AS pagado
               FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id
               LEFT JOIN categorias_proveedor cat ON cat.id = o.categoria_id
              WHERE $donde ORDER BY o.fecha_solicitud DESC, o.id DESC LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        foreach ($filas as &$fila) {
            $fila['saldo'] = ((int) round((float) $fila['total_compra'] * 100) - (int) round((float) $fila['pagado'] * 100)) / 100;
            $fila['estado_pago'] = PagosCompra::estado((int) round((float) $fila['total_compra'] * 100), (int) round((float) $fila['pagado'] * 100));
        }
        unset($fila);

        return $filas;
    }

    public static function contar(array $filtros): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS total FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id WHERE $donde"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Totales de las compras que cumplen los filtros (para el resumen y los reportes), en pesos.
     *
     * @return array{compras: int, comprado: float, facturado: float, pendiente_documentar: float, sin_factura: float, pagado: float, saldo: float}
     */
    public static function totalesFiltrados(array $filtros): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS compras,
                    COALESCE(SUM(ROUND(" . self::TOTAL_SQL . " * 100)), 0) AS comprado,
                    COALESCE(SUM(IF(o.estado_cfdi = 'con_cfdi', ROUND(" . self::TOTAL_SQL . " * 100), 0)), 0) AS facturado,
                    COALESCE(SUM(IF(o.estado_cfdi = 'pendiente_cfdi', ROUND(" . self::TOTAL_SQL . " * 100), 0)), 0) AS por_documentar,
                    COALESCE(SUM(IF(o.estado_cfdi = 'no_facturada', ROUND(" . self::TOTAL_SQL . " * 100), 0)), 0) AS sin_factura,
                    COALESCE(SUM(ROUND(" . self::PAGADO_SQL . " * 100)), 0) AS pagado
               FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id
              WHERE $donde AND o.estado <> 'cancelada'"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $f = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return [
            'compras'              => (int) $f['compras'],
            'comprado'             => (int) $f['comprado'] / 100,
            'facturado'            => (int) $f['facturado'] / 100,
            'pendiente_documentar' => (int) $f['por_documentar'] / 100,
            'sin_factura'          => (int) $f['sin_factura'] / 100,
            'pagado'               => (int) $f['pagado'] / 100,
            'saldo'                => ((int) $f['comprado'] - (int) $f['pagado']) / 100,
        ];
    }

    /**
     * Filtros: q (folio, proveedor, referencia), estado, proveedor, tipo, categoria, desde, hasta,
     * cfdi (pendiente_cfdi|con_cfdi|no_facturada), pago (pendiente|parcial|pagada), recepcion (pendiente).
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condiciones(array $filtros): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];

        $termino = trim((string) ($filtros['q'] ?? ''));
        if ($termino !== '') {
            $donde[] = '(o.folio LIKE ? OR p.nombre LIKE ? OR o.referencia LIKE ?)';
            $tipos .= 'sss';
            $valores[] = $valores[] = $valores[] = '%' . $termino . '%';
        }
        if (isset(self::ESTADOS[$filtros['estado'] ?? ''])) {
            $donde[] = 'o.estado = ?';
            $tipos .= 's';
            $valores[] = $filtros['estado'];
        }
        if ((int) ($filtros['proveedor'] ?? 0) > 0) {
            $donde[] = 'o.proveedor_id = ?';
            $tipos .= 'i';
            $valores[] = (int) $filtros['proveedor'];
        }
        if (isset(self::TIPOS[$filtros['tipo'] ?? ''])) {
            $donde[] = 'o.tipo = ?';
            $tipos .= 's';
            $valores[] = $filtros['tipo'];
        }
        if ((int) ($filtros['categoria'] ?? 0) > 0) {
            $donde[] = 'o.categoria_id = ?';
            $tipos .= 'i';
            $valores[] = (int) $filtros['categoria'];
        }
        foreach (['desde' => 'o.fecha_solicitud >= ?', 'hasta' => 'o.fecha_solicitud <= ?'] as $clave => $sql) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filtros[$clave] ?? ''))) {
                $donde[] = $sql;
                $tipos .= 's';
                $valores[] = $filtros[$clave];
            }
        }
        if (isset(CompraContable::ESTADOS_CFDI[$filtros['cfdi'] ?? ''])) {
            $donde[] = 'o.estado_cfdi = ?';
            $tipos .= 's';
            $valores[] = $filtros['cfdi'];
        }
        if (!empty($filtros['activas'])) {
            $donde[] = "o.estado <> 'cancelada'";
        }
        if (($filtros['recepcion'] ?? '') === 'pendiente') {
            $donde[] = "o.estado IN ('solicitada', 'parcial')";
        }
        switch ($filtros['pago'] ?? '') {
            case 'pendiente':
                $donde[] = self::PAGADO_SQL . ' = 0';
                break;
            case 'parcial':
                $donde[] = self::PAGADO_SQL . ' > 0 AND ' . self::PAGADO_SQL . ' < ' . self::TOTAL_SQL;
                break;
            case 'con_saldo':
                $donde[] = self::PAGADO_SQL . ' < ' . self::TOTAL_SQL . " AND o.estado <> 'cancelada'";
                break;
            case 'pagada':
                $donde[] = self::PAGADO_SQL . ' > 0 AND ' . self::PAGADO_SQL . ' >= ' . self::TOTAL_SQL;
                break;
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Indicadores del resumen de compras.
     *
     * @return array{por_recibir: int, piezas_por_terminar: int, sin_cfdi: int, importe_sin_cfdi: float, con_saldo: int, saldo: float, comprado_mes: float}
     */
    public static function indicadores(): array
    {
        $activas = ['activas' => true];
        $totales = self::totalesFiltrados($activas);
        $mes = self::totalesFiltrados($activas + ['desde' => date('Y-m-01'), 'hasta' => date('Y-m-t')]);
        $piezas = (int) self::$db->query('SELECT COALESCE(SUM(stock_por_terminar), 0) FROM productos WHERE stock_por_terminar > 0')->fetch_row()[0];

        return [
            'por_recibir'         => self::contar($activas + ['recepcion' => 'pendiente']),
            'piezas_por_terminar' => $piezas,
            'sin_cfdi'            => self::contar($activas + ['cfdi' => 'pendiente_cfdi']),
            'importe_sin_cfdi'    => $totales['pendiente_documentar'],
            'con_saldo'           => self::contar($activas + ['pago' => 'con_saldo']),
            'saldo'               => $totales['saldo'],
            'comprado_mes'        => $mes['comprado'],
        ];
    }

    /**
     * Partidas con piezas pendientes de recibir, de compras abiertas (solicitada o parcial).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendientesDeRecibir(int $proveedorId = 0, int $limite = 100): array
    {
        $donde = $proveedorId > 0 ? 'AND o.proveedor_id = ' . $proveedorId : '';
        $filas = self::$db->query(
            "SELECT o.id AS orden_id, o.folio, o.tipo, o.fecha_solicitud, p.nombre AS proveedor_nombre,
                    c.id AS partida_id, c.descripcion, c.producto_id, c.material_id, c.unidad, c.cantidad_solicitada,
                    pr.nombre AS producto_nombre, mt.nombre AS material_nombre,
                    COALESCE((SELECT SUM(rp.cantidad_recibida) FROM recepcion_partidas rp WHERE rp.partida_id = c.id), 0) AS recibida
               FROM orden_compra_partidas c
               JOIN ordenes_compra o ON o.id = c.orden_id
               JOIN proveedores p ON p.id = o.proveedor_id
               LEFT JOIN productos pr ON pr.id = c.producto_id
               LEFT JOIN materiales mt ON mt.id = c.material_id
              WHERE c.controla = 1 AND o.estado IN ('solicitada', 'parcial') $donde
              HAVING recibida < c.cantidad_solicitada
              ORDER BY o.fecha_solicitud, o.id, c.id LIMIT " . (int) $limite
        )->fetch_all(MYSQLI_ASSOC);

        foreach ($filas as &$f) {
            $f['pendiente'] = self::num((float) $f['cantidad_solicitada'] - (float) $f['recibida']);
            $f['solicitada'] = self::num($f['cantidad_solicitada']);
            $f['recibida'] = self::num($f['recibida']);
            $f['nombre_mostrado'] = $f['producto_nombre'] ?? $f['material_nombre'] ?? ($f['descripcion'] ?: 'Sin descripción');
        }
        unset($f);

        return $filas;
    }

    /**
     * Historial de recepciones de todas las compras (más recientes primero).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function historialRecepciones(int $proveedorId = 0, int $limite = 50): array
    {
        $donde = $proveedorId > 0 ? 'WHERE o.proveedor_id = ' . $proveedorId : '';
        return self::$db->query(
            "SELECT r.id, r.fecha, r.notas, o.id AS orden_id, o.folio, p.nombre AS proveedor_nombre,
                    SUM(rp.cantidad_recibida) AS piezas, GROUP_CONCAT(DISTINCT rp.destino) AS destinos
               FROM recepciones r
               JOIN ordenes_compra o ON o.id = r.orden_id
               JOIN proveedores p ON p.id = o.proveedor_id
               JOIN recepcion_partidas rp ON rp.recepcion_id = r.id
               $donde GROUP BY r.id ORDER BY r.fecha DESC, r.id DESC LIMIT " . (int) $limite
        )->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Importes de una compra en pesos: comprado, IVA, total, facturado, pagado, saldo y estados.
     * «Facturado» solo cuenta con un CFDI adjunto (estado con_cfdi); lo demás está pendiente de documentar o sin factura.
     *
     * @return array<string, mixed>
     */
    public static function resumen(int $ordenId): array
    {
        $orden = self::buscarID($ordenId);
        if (!$orden) {
            return [];
        }
        $total = PagosCompra::totalCompra($orden);
        $pagado = PagosCompra::pagado($ordenId);

        return [
            'subtotal'             => Contabilidad::centavos($orden['total_esperado']) / 100,
            'iva'                  => $orden['iva'] !== null ? (float) $orden['iva'] : null,
            'total'                => $total / 100,
            'facturado'            => $orden['estado_cfdi'] === 'con_cfdi' ? $total / 100 : 0.0,
            'pendiente_documentar' => $orden['estado_cfdi'] === 'pendiente_cfdi' ? $total / 100 : 0.0,
            'pagado'               => $pagado / 100,
            'saldo'                => ($total - $pagado) / 100,
            'estado_pago'          => PagosCompra::estado($total, $pagado),
            'estado_cfdi'          => $orden['estado_cfdi'],
            'gasto_id'             => $orden['gasto_id'] !== null ? (int) $orden['gasto_id'] : null,
        ];
    }

    /* --------------------------------------------------------------------
     * ESCRITURA — ORDEN
     * ------------------------------------------------------------------ */

    /**
     * Valida los datos de la compra y sus partidas.
     *
     * @param array<int, array<string, mixed>> $lineas
     * @return string[]
     */
    public static function validar(array $datos, array $lineas): array
    {
        $errores = [];

        $tipo = (string) ($datos['tipo'] ?? 'semiterminado');
        if (!isset(self::TIPOS[$tipo])) {
            $errores[] = 'Elige el tipo de compra.';
        }

        $proveedor = (int) ($datos['proveedor_id'] ?? 0);
        $consulta = self::$db->prepare('SELECT activo FROM proveedores WHERE id = ?');
        $consulta->bind_param('i', $proveedor);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$fila) {
            $errores[] = 'Elige un proveedor válido.';
        } elseif ((int) $fila['activo'] !== 1) {
            // Una compra ya existente puede conservar a su proveedor inactivo; las nuevas no.
            $propia = (int) ($datos['id'] ?? 0);
            $actual = 0;
            if ($propia > 0) {
                $c = self::$db->prepare('SELECT proveedor_id FROM ordenes_compra WHERE id = ?');
                $c->bind_param('i', $propia);
                $c->execute();
                $actual = (int) ($c->get_result()->fetch_assoc()['proveedor_id'] ?? 0);
                $c->close();
            }
            if ($actual !== $proveedor) {
                $errores[] = 'El proveedor está desactivado.';
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($datos['fecha_solicitud'] ?? ''))
            || !strtotime((string) $datos['fecha_solicitud'])) {
            $errores[] = 'La fecha de la compra no es válida.';
        }

        if (mb_strlen(trim((string) ($datos['referencia'] ?? ''))) > 60) {
            $errores[] = 'La referencia del proveedor puede tener hasta 60 caracteres.';
        }
        foreach (['categoria_id' => ['categorias_proveedor', 'La categoría elegida ya no existe.'], 'empresa_id' => ['empresas', 'El negocio elegido ya no existe.']] as $campo => [$tabla, $mensaje]) {
            $valor = (int) ($datos[$campo] ?? 0);
            if ($valor > 0) {
                $c = self::$db->prepare("SELECT COUNT(*) FROM $tabla WHERE id = ?");
                $c->bind_param('i', $valor);
                $c->execute();
                if ((int) $c->get_result()->fetch_row()[0] === 0) {
                    $errores[] = $mensaje;
                }
                $c->close();
            }
        }
        if (isset($datos['estado_cfdi']) && !in_array($datos['estado_cfdi'], ['pendiente_cfdi', 'no_facturada'], true)) {
            $errores[] = 'El estado de la factura solo puede ser «CFDI pendiente» o «Sin factura»; «Con CFDI» se obtiene al adjuntar el XML.';
        }
        $iva = str_replace(',', '.', trim((string) ($datos['iva'] ?? '')));
        if ($iva !== '' && (!is_numeric($iva) || (float) $iva < 0)) {
            $errores[] = 'El IVA debe ser un número mayor o igual a cero (o déjalo vacío si no lo sabes).';
        }

        $folio = trim((string) ($datos['folio'] ?? ''));
        if ($folio !== '') {
            if (mb_strlen($folio) > 20 || !preg_match('/^[\p{L}\p{N}._\/-]+$/u', $folio)) {
                $errores[] = 'El folio puede tener hasta 20 letras, números, guiones o diagonales, sin espacios.';
            } else {
                $propia = (int) ($datos['id'] ?? 0);
                $consulta = self::$db->prepare('SELECT COUNT(*) FROM ordenes_compra WHERE folio = ? AND id <> ?');
                $consulta->bind_param('si', $folio, $propia);
                $consulta->execute();
                $repetido = (int) $consulta->get_result()->fetch_row()[0];
                $consulta->close();
                if ($repetido > 0) {
                    $errores[] = "Ya existe una compra con el folio $folio. Elige otro o déjalo vacío para que se asigne solo.";
                }
            }
        }

        if (empty($lineas)) {
            $errores[] = 'Agrega al menos una partida a la compra.';
        }

        foreach ($lineas as $indice => $linea) {
            $n = $indice + 1;
            $cantidad = str_replace(',', '.', trim((string) ($linea['cantidad'] ?? '')));
            $productoId = (int) ($linea['producto_id'] ?? 0);
            $descripcion = trim((string) ($linea['descripcion'] ?? ''));

            if (!is_numeric($cantidad) || (float) $cantidad <= 0) {
                $errores[] = "Partida $n: la cantidad debe ser mayor a cero.";
            } elseif (abs((float) $cantidad * 1000 - round((float) $cantidad * 1000)) > 1e-6) {
                $errores[] = "Partida $n: la cantidad admite hasta 3 decimales.";
            }
            $catalogoId = (int) ($linea['proveedor_producto_id'] ?? 0);
            $costoVacio = trim((string) ($linea['costo'] ?? '')) === '';
            // Con un producto del catálogo, un costo vacío se toma del catálogo; capturado a mano, debe ser válido.
            if (!($catalogoId > 0 && $costoVacio) && (!is_numeric($linea['costo'] ?? '') || (float) $linea['costo'] < 0)) {
                $errores[] = "Partida $n: el costo unitario debe ser un número mayor o igual a cero.";
            }
            if (mb_strlen($descripcion) > 200) {
                $errores[] = "Partida $n: la descripción puede tener hasta 200 caracteres.";
            }

            if ($tipo === 'semiterminado') {
                if ($catalogoId > 0) {
                    [, $falla] = self::itemCatalogo($catalogoId, $proveedor, (int) ($datos['id'] ?? 0));
                    if ($falla !== null) {
                        $errores[] = "Partida $n: $falla";
                    }
                } elseif ($productoId <= 0 && $descripcion === '') {
                    $errores[] = "Partida $n: elige el producto del catálogo del proveedor o escribe cómo lo nombra.";
                }
                if (is_numeric($cantidad) && (float) $cantidad != (int) $cantidad) {
                    $errores[] = "Partida $n: las piezas de un semiterminado se cuentan completas (sin decimales).";
                }
                if ($productoId > 0) {   // producto propio al que corresponde
                    $c = self::$db->prepare('SELECT COUNT(*) FROM productos WHERE id = ?');
                    $c->bind_param('i', $productoId);
                    $c->execute();
                    if ((int) $c->get_result()->fetch_row()[0] === 0) {
                        $errores[] = "Partida $n: el producto elegido ya no existe.";
                    }
                    $c->close();
                }
            } elseif ($tipo === 'insumo') {
                if ($catalogoId > 0) {
                    $errores[] = "Partida $n: los productos del catálogo de semiterminados no se usan en insumos.";
                }
                if ($descripcion === '') {
                    $errores[] = "Partida $n: describe lo que compraste.";
                }
                if ($productoId > 0) {
                    $errores[] = "Partida $n: un insumo no se liga a un producto de venta (usa «semiterminado» para eso).";
                }
                if (!empty($linea['controla']) && (int) ($linea['material_id'] ?? 0) <= 0 && trim((string) ($linea['material_nuevo'] ?? '')) === '') {
                    $errores[] = "Partida $n: para controlarlo en inventario elige un material o escribe el nombre del nuevo.";
                }
            }
        }

        return $errores;
    }

    /**
     * Producto del catálogo del proveedor para una partida: [fila, null] o [null, motivo del rechazo].
     * Debe pertenecer al proveedor elegido y estar activo; en una compra ya guardada se acepta uno que se desactivó después
     * (para poder corregir la compra sin perder sus partidas).
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private static function itemCatalogo(int $catalogoId, int $proveedorId, int $ordenId): array
    {
        $item = ProductosProveedor::buscarID($catalogoId);
        if (!$item) {
            return [null, 'el producto del catálogo ya no existe.'];
        }
        if ((int) $item['proveedor_id'] !== $proveedorId) {
            return [null, '«' . $item['nombre'] . '» no es de este proveedor: vuelve a elegir los productos.'];
        }
        if ((int) $item['activo'] !== 1) {
            $yaEnLaCompra = false;
            if ($ordenId > 0) {
                $c = self::$db->prepare('SELECT COUNT(*) FROM orden_compra_partidas WHERE orden_id = ? AND proveedor_producto_id = ?');
                $c->bind_param('ii', $ordenId, $catalogoId);
                $c->execute();
                $yaEnLaCompra = (int) $c->get_result()->fetch_row()[0] > 0;
                $c->close();
            }
            if (!$yaEnLaCompra) {
                return [null, '«' . $item['nombre'] . '» está inactivo y no se ofrece en compras nuevas.'];
            }
        }
        return [$item, null];
    }

    /**
     * Resuelve cada partida a su forma final: material creado si hace falta, bandera «controla», y — para un producto
     * del catálogo — nombre, código y costo tomados del catálogo (con copia del costo del catálogo para el historial).
     * Debe llamarse dentro de la transacción (puede crear materiales).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function resolverLineas(string $tipo, array $lineas, int $proveedorId = 0, int $ordenId = 0): array
    {
        // Copias de catálogo ya guardadas en esta compra (al editar se conserva el costo de catálogo con el que se compró).
        $previas = [];
        if ($ordenId > 0) {
            foreach (self::partidas($ordenId) as $p) {
                if ($p['proveedor_producto_id']) {
                    $previas[(int) $p['proveedor_producto_id']] = $p;
                }
            }
        }

        $resueltas = [];
        foreach ($lineas as $linea) {
            $productoId = $tipo === 'semiterminado' ? ((int) ($linea['producto_id'] ?? 0) ?: null) : null;
            $materialId = null;
            $controla = 1;
            $catalogoId = null;
            $linea['codigo'] = null;
            $linea['costo_catalogo'] = null;

            if ($tipo === 'semiterminado' && (int) ($linea['proveedor_producto_id'] ?? 0) > 0) {
                [$item, $falla] = self::itemCatalogo((int) $linea['proveedor_producto_id'], $proveedorId, $ordenId);
                if ($item === null) {
                    throw new \RuntimeException($falla ?? 'Producto del catálogo no válido.');
                }
                $catalogoId = (int) $item['id'];
                $linea['descripcion'] = $item['nombre'];
                $linea['codigo'] = $item['codigo'];
                // Si el semiterminado está relacionado con UN solo producto propio y no se eligió otro, se asocia solo.
                if ($productoId === null) {
                    $relacionados = ProductosProveedor::productosRelacionados($catalogoId);
                    if (count($relacionados) === 1) {
                        $productoId = (int) $relacionados[0]['id'];
                    }
                }
                $previa = $previas[$catalogoId] ?? null;
                $linea['costo_catalogo'] = $previa && $previa['costo_catalogo'] !== null ? $previa['costo_catalogo'] : $item['costo_unitario'];
                if (trim((string) ($linea['costo'] ?? '')) === '') {
                    $linea['costo'] = $item['costo_unitario'];
                }
            }
            if ($tipo === 'insumo') {
                $controla = !empty($linea['controla']) ? 1 : 0;
                if ($controla) {
                    $materialId = (int) ($linea['material_id'] ?? 0) ?: null;
                    if (!$materialId) {
                        $materialId = Materiales::crear((string) $linea['material_nuevo'], (string) ($linea['unidad'] ?? '') ?: 'pza') ?: null;
                    }
                    if (!$materialId) {
                        throw new \RuntimeException(implode(' ', Materiales::getErrores()) ?: 'No fue posible crear el material.');
                    }
                }
            }
            $linea['producto_id'] = $productoId;
            $linea['proveedor_producto_id'] = $catalogoId;
            $linea['material_id'] = $materialId;
            $linea['controla'] = $controla;
            $resueltas[] = $linea;
        }
        return $resueltas;
    }

    /** Deja en la bitácora las partidas cuyo costo se cambió a mano respecto al del catálogo. */
    private static function auditarCostosManuales(int $ordenId, array $lineas, int $usuarioId): void
    {
        $cambios = [];
        foreach ($lineas as $l) {
            if ($l['proveedor_producto_id'] && $l['costo_catalogo'] !== null && abs((float) $l['costo'] - (float) $l['costo_catalogo']) > 0.004) {
                $cambios[] = ['producto' => $l['descripcion'], 'catalogo' => number_format((float) $l['costo_catalogo'], 2, '.', ''), 'usado' => number_format((float) $l['costo'], 2, '.', '')];
            }
        }
        if ($cambios) {
            Auditoria::registrar('orden_compra', $ordenId, 'costo_modificado', $usuarioId, 'Costo distinto al del catálogo del proveedor', ['partidas' => $cambios]);
        }
    }

    /**
     * Tasa general de IVA (%) del catálogo fiscal ACTIVO para el ejercicio de la fecha; null si no hay catálogo activo
     * (entonces no se supone ninguna tasa: el IVA se captura a mano).
     */
    public static function tasaIvaVigente(string $fecha): ?string
    {
        $anio = preg_match('/^(\d{4})-/', $fecha, $m) ? (int) $m[1] : (int) date('Y');
        $tasa = CatalogoFiscal::activa('iva_tasas', $anio)['doc']['tasa_general'] ?? null;

        return is_numeric($tasa) ? rtrim(rtrim(number_format((float) $tasa, 2, '.', ''), '0'), '.') : null;
    }

    /**
     * IVA automático: (suma de importes de las partidas gravadas) × tasa, redondeado al centavo (mitad hacia arriba),
     * igual que el IVA de los gastos. Solo cuenta las partidas marcadas con aplica_iva.
     *
     * @param array<int, array<string, mixed>> $lineas
     */
    public static function ivaDe(array $lineas, $tasa): string
    {
        $base = 0;
        foreach ($lineas as $l) {
            if (!empty($l['aplica_iva'])) {
                $base += (int) round(self::importe($l['cantidad'], $l['costo']) * 100);
            }
        }

        return Gastos::calcular(number_format($base / 100, 2, '.', ''), $tasa)['iva'];
    }

    /** Con tasa y sin importe de IVA capturado, calcula el IVA de las partidas gravadas; con importe capturado se respeta. */
    private static function aplicarIva(array $h, array $lineas): array
    {
        if ($h['iva'] === null && $h['iva_tasa'] !== null) {
            $h['iva'] = self::ivaDe($lineas, $h['iva_tasa']);
        }

        return $h;
    }

    private static function totalDe(array $lineas): string
    {
        $total = 0;
        foreach ($lineas as $linea) {
            $total += (int) round(self::importe($linea['cantidad'], $linea['costo']) * 100);
        }
        return number_format($total / 100, 2, '.', '');
    }

    /** Datos del encabezado normalizados para guardar. @return array<string, mixed> */
    private static function encabezado(array $datos): array
    {
        $empresa = (int) ($datos['empresa_id'] ?? 0) ?: null;
        $iva = str_replace(',', '.', trim((string) ($datos['iva'] ?? '')));
        return [
            'tipo'            => (string) ($datos['tipo'] ?? 'semiterminado'),
            'proveedor_id'    => (int) $datos['proveedor_id'],
            'fecha'           => (string) $datos['fecha_solicitud'],
            'referencia'      => trim((string) ($datos['referencia'] ?? '')) ?: null,
            'categoria_id'    => (int) ($datos['categoria_id'] ?? 0) ?: null,
            'empresa_id'      => $empresa,
            'contribuyente'   => $empresa ? Contabilidad::contribuyenteDeEmpresa($empresa) : Contabilidad::contribuyentePrincipal(),
            'estado_cfdi'     => in_array($datos['estado_cfdi'] ?? '', ['pendiente_cfdi', 'no_facturada'], true) ? $datos['estado_cfdi'] : 'pendiente_cfdi',
            'iva'             => $iva !== '' ? number_format((float) $iva, 2, '.', '') : null,
            'iva_tasa'        => is_numeric(str_replace(',', '.', trim((string) ($datos['iva_tasa'] ?? '')))) ? number_format((float) str_replace(',', '.', trim((string) $datos['iva_tasa'])), 2, '.', '') : null,
            'observaciones'   => trim((string) ($datos['observaciones'] ?? '')) ?: null,
        ];
    }

    public static function crear(array $datos, array $lineas, int $usuarioId): bool
    {
        self::$errores = self::validar($datos, $lineas);
        if (self::$errores) {
            return false;
        }

        try {
            self::$db->begin_transaction();

            $h = self::encabezado($datos);
            $lineas = self::resolverLineas($h['tipo'], $lineas, $h['proveedor_id']);
            $h = self::aplicarIva($h, $lineas);
            $folio = trim((string) ($datos['folio'] ?? '')) ?: self::siguienteFolio();
            $totalPesos = self::totalDe($lineas);
            $estado = array_sum(array_column($lineas, 'controla')) > 0 ? 'solicitada' : 'sin_recepcion';

            $consulta = self::$db->prepare(
                'INSERT INTO ordenes_compra (folio, tipo, proveedor_id, fecha_solicitud, referencia, categoria_id, contribuyente_id, empresa_id,
                        estado, total_esperado, estado_cfdi, iva, iva_tasa, observaciones, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $consulta->bind_param(
                'ssissiiissssssi',
                $folio, $h['tipo'], $h['proveedor_id'], $h['fecha'], $h['referencia'], $h['categoria_id'], $h['contribuyente'], $h['empresa_id'],
                $estado, $totalPesos, $h['estado_cfdi'], $h['iva'], $h['iva_tasa'], $h['observaciones'], $usuarioId
            );
            $consulta->execute();
            $ordenId = (int) self::$db->insert_id;
            $consulta->close();

            self::guardarPartidas($ordenId, $lineas);
            self::auditarCostosManuales($ordenId, $lineas, $usuarioId);
            Auditoria::registrar('orden_compra', $ordenId, 'crear', $usuarioId, null, ['folio' => $folio, 'tipo' => $h['tipo'], 'total' => $totalPesos]);

            self::$db->commit();
            self::$ultimoId = $ordenId;
            return true;
        } catch (\RuntimeException $excepcion) {
            self::$db->rollback();
            self::$errores[] = $excepcion->getMessage();
            return false;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::crear] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible guardar la compra.';
            return false;
        }
    }

    /**
     * Indica por qué una compra ya no se puede editar o cancelar (null = se puede).
     * No se corrige una compra con recepciones, pagos vigentes o un gasto vigente: primero se anula eso (queda auditado).
     */
    private static function motivoBloqueo(array $orden): ?string
    {
        if (!in_array($orden['estado'], ['solicitada', 'sin_recepcion'], true) || self::contarRecepciones((int) $orden['id']) > 0) {
            return 'Solo se puede modificar una compra que aún no tenga recepciones ni esté cerrada o cancelada.';
        }
        if (PagosCompra::pagado((int) $orden['id']) > 0) {
            return 'La compra tiene pagos vigentes: cancela los pagos (con motivo) antes de modificarla.';
        }
        if (!empty($orden['gasto_id'])) {
            $gasto = Gastos::buscarID((int) $orden['gasto_id']);
            if ($gasto && $gasto['estado'] === 'vigente') {
                return 'La compra ya está en contabilidad (gasto #' . $orden['gasto_id'] . '): cancela ese gasto antes de modificarla.';
            }
        }
        return null;
    }

    /** Edita una compra mientras no tenga recepciones, pagos ni gasto vigentes. */
    public static function actualizar(int $id, array $datos, array $lineas, int $usuarioId): bool
    {
        $datos['id'] = $id;
        self::$errores = self::validar($datos, $lineas);
        if (self::$errores) {
            return false;
        }

        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($id);

            $bloqueo = $orden ? self::motivoBloqueo($orden) : 'La compra no existe.';
            if ($bloqueo !== null) {
                self::$db->rollback();
                self::$errores[] = $bloqueo;
                return false;
            }

            $h = self::encabezado($datos);
            $lineas = self::resolverLineas($h['tipo'], $lineas, $h['proveedor_id'], $id);
            $h = self::aplicarIva($h, $lineas);
            $totalPesos = self::totalDe($lineas);
            $estado = array_sum(array_column($lineas, 'controla')) > 0 ? 'solicitada' : 'sin_recepcion';
            $folio = trim((string) ($datos['folio'] ?? '')) ?: $orden['folio']; // vacío = conserva el actual

            $consulta = self::$db->prepare(
                'UPDATE ordenes_compra SET folio = ?, tipo = ?, proveedor_id = ?, fecha_solicitud = ?, referencia = ?, categoria_id = ?,
                        contribuyente_id = ?, empresa_id = ?, estado = ?, total_esperado = ?, estado_cfdi = ?, iva = ?, iva_tasa = ?, observaciones = ?
                  WHERE id = ?'
            );
            $consulta->bind_param(
                'ssissiiissssssi',
                $folio, $h['tipo'], $h['proveedor_id'], $h['fecha'], $h['referencia'], $h['categoria_id'], $h['contribuyente'], $h['empresa_id'],
                $estado, $totalPesos, $h['estado_cfdi'], $h['iva'], $h['iva_tasa'], $h['observaciones'], $id
            );
            $consulta->execute();
            $consulta->close();

            $borrar = self::$db->prepare('DELETE FROM orden_compra_partidas WHERE orden_id = ?');
            $borrar->bind_param('i', $id);
            $borrar->execute();
            $borrar->close();

            self::guardarPartidas($id, $lineas);
            self::auditarCostosManuales($id, $lineas, $usuarioId);
            Auditoria::registrar('orden_compra', $id, 'editar', $usuarioId, null, ['total' => $totalPesos, 'folio' => $folio, 'tipo' => $h['tipo']]);

            self::$db->commit();
            return true;
        } catch (\RuntimeException $excepcion) {
            self::$db->rollback();
            self::$errores[] = $excepcion->getMessage();
            return false;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::actualizar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible actualizar la compra.';
            return false;
        }
    }

    /**
     * Relaciona una partida de semiterminado con un producto del catálogo (o cambia la relación) mientras
     * no tenga lo recibido. Nunca inventa asociaciones: solo la que el usuario elige.
     */
    public static function relacionarProducto(int $partidaId, int $productoId, int $usuarioId): bool
    {
        self::$errores = [];
        try {
            self::$db->begin_transaction();
            $consulta = self::$db->prepare(
                'SELECT c.*, o.tipo, o.estado FROM orden_compra_partidas c JOIN ordenes_compra o ON o.id = c.orden_id WHERE c.id = ? FOR UPDATE'
            );
            $consulta->bind_param('i', $partidaId);
            $consulta->execute();
            $partida = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$partida || $partida['tipo'] !== 'semiterminado') {
                self::$db->rollback();
                self::$errores[] = 'Solo las partidas de un semiterminado se relacionan con un producto del catálogo.';
                return false;
            }
            $recibida = self::$db->query('SELECT COALESCE(SUM(cantidad_recibida), 0) FROM recepcion_partidas WHERE partida_id = ' . (int) $partidaId)->fetch_row()[0];
            if ((float) $recibida > 0 && (int) $partida['producto_id'] !== $productoId) {
                self::$db->rollback();
                self::$errores[] = 'Esta partida ya tiene piezas recibidas; no se puede cambiar su producto.';
                return false;
            }
            $existe = self::$db->prepare('SELECT COUNT(*) FROM productos WHERE id = ?');
            $existe->bind_param('i', $productoId);
            $existe->execute();
            if ((int) $existe->get_result()->fetch_row()[0] === 0) {
                self::$db->rollback();
                self::$errores[] = 'El producto elegido no existe.';
                return false;
            }
            $existe->close();

            $actualizar = self::$db->prepare('UPDATE orden_compra_partidas SET producto_id = ? WHERE id = ?');
            $actualizar->bind_param('ii', $productoId, $partidaId);
            $actualizar->execute();
            $actualizar->close();

            Auditoria::registrar('orden_compra', (int) $partida['orden_id'], 'relacionar_producto', $usuarioId, null, [
                'partida' => $partidaId, 'antes' => $partida['producto_id'], 'despues' => $productoId,
            ]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::relacionarProducto] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible relacionar el producto.';
            return false;
        }
    }

    /** Cancela una compra sin recepciones, pagos ni gasto vigentes. */
    public static function cancelar(int $id, int $usuarioId): bool
    {
        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($id);

            $bloqueo = $orden ? self::motivoBloqueo($orden) : 'La compra no existe.';
            if ($bloqueo !== null) {
                self::$db->rollback();
                self::$errores[] = $bloqueo;
                return false;
            }

            $consulta = self::$db->prepare("UPDATE ordenes_compra SET estado = 'cancelada' WHERE id = ?");
            $consulta->bind_param('i', $id);
            $consulta->execute();
            $consulta->close();

            Auditoria::registrar('orden_compra', $id, 'cancelar', $usuarioId);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::cancelar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cancelar la compra.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * ESCRITURA — RECEPCIÓN, CIERRE Y REAPERTURA
     * ------------------------------------------------------------------ */

    /**
     * Registra una recepción y suma SOLO lo realmente recibido.
     *
     * Destino de cada renglón: material → materiales; producto → «por terminar» (o existencia vendible si el
     * renglón trae 'terminado' => true, es decir, ya llegó terminado). Un consumible no se recepciona.
     *
     * @param array<int, array{cantidad: mixed, costo: mixed, terminado?: mixed}> $renglones partida_id => datos.
     * @param bool $confirmaExcedente true solo cuando el usuario confirmó explícitamente recibir más de lo pendiente.
     */
    public static function recibir(
        int $ordenId,
        string $fecha,
        array $renglones,
        string $token,
        bool $actualizaCosto,
        string $notas,
        int $usuarioId,
        bool $confirmaExcedente = false
    ): bool {
        self::$errores = [];

        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            self::$errores[] = 'El formulario de recepción no es válido. Vuelve a abrirlo.';
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            self::$errores[] = 'La fecha de recepción no es válida.';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($ordenId);

            $admitidos = $confirmaExcedente ? ['solicitada', 'parcial', 'completa'] : ['solicitada', 'parcial'];
            if (!$orden || !in_array($orden['estado'], $admitidos, true)) {
                self::$db->rollback();
                self::$errores[] = 'La compra no admite más recepciones (debe estar solicitada o con recepción parcial).';
                return false;
            }

            $partidas = [];
            foreach (self::partidas($ordenId) as $partida) {
                $partidas[(int) $partida['id']] = $partida;
            }

            $aInsertar = [];
            $enStock = [];
            $porTerminar = [];
            $materiales = [];
            $excedentes = [];
            foreach ($renglones as $partidaId => $datos) {
                $cantidad = str_replace(',', '.', trim((string) ($datos['cantidad'] ?? '0')));
                if ($cantidad === '' || (is_numeric($cantidad) && (float) $cantidad == 0.0)) {
                    continue;
                }
                $partidaId = (int) $partidaId;
                if (!is_numeric($cantidad) || (float) $cantidad < 0 || !isset($partidas[$partidaId])) {
                    self::$db->rollback();
                    self::$errores[] = 'Hay una cantidad recibida inválida.';
                    return false;
                }
                $cantidad = round((float) $cantidad, 3);
                $partida = $partidas[$partidaId];
                $nombre = $partida['nombre_mostrado'];

                if (!(int) $partida['controla']) {
                    self::$db->rollback();
                    self::$errores[] = '«' . $nombre . '» es un consumible sin control de inventario: no lleva recepción.';
                    return false;
                }
                if ($partida['material_id']) {
                    $destino = 'material';
                } elseif ($partida['producto_id']) {
                    $destino = !empty($datos['terminado']) ? 'stock' : 'por_terminar';
                    if ($cantidad != (int) $cantidad) {
                        self::$db->rollback();
                        self::$errores[] = 'Las piezas de «' . $nombre . '» se reciben completas (sin decimales).';
                        return false;
                    }
                } else {
                    self::$db->rollback();
                    self::$errores[] = 'Relaciona «' . $nombre . '» con un producto del catálogo antes de recibirlo.';
                    return false;
                }

                $pendiente = (float) $partida['pendiente'];
                if ($cantidad > $pendiente + 0.0005) {
                    if (!$confirmaExcedente) {
                        self::$db->rollback();
                        self::$errores[] = 'La cantidad recibida de «' . $nombre . '» (' . self::num($cantidad) . ') excede lo pendiente ('
                            . self::num(max(0, $pendiente)) . '). Si realmente llegó de más, confirma el excedente de forma explícita.';
                        return false;
                    }
                    $excedentes[] = ['partida' => $partidaId, 'pendiente' => self::num(max(0, $pendiente)), 'recibido' => self::num($cantidad)];
                }

                $costo = $datos['costo'] ?? '';
                $costo = str_replace(',', '.', trim((string) $costo));
                if (!is_numeric($costo) || (float) $costo < 0) {
                    self::$db->rollback();
                    self::$errores[] = 'El costo unitario de «' . $nombre . '» no es válido.';
                    return false;
                }
                $aInsertar[$partidaId] = [$cantidad, number_format((float) $costo, 2, '.', ''), $destino];

                if ($destino === 'material') {
                    $materiales[(int) $partida['material_id']] = ($materiales[(int) $partida['material_id']] ?? 0) + $cantidad;
                } elseif ($destino === 'stock') {
                    $enStock[(int) $partida['producto_id']] = ($enStock[(int) $partida['producto_id']] ?? 0) + (int) $cantidad;
                } else {
                    $porTerminar[(int) $partida['producto_id']] = ($porTerminar[(int) $partida['producto_id']] ?? 0) + (int) $cantidad;
                }
            }

            if (!$aInsertar) {
                self::$db->rollback();
                self::$errores[] = 'Captura al menos una cantidad recibida mayor a cero.';
                return false;
            }

            $notas = trim($notas) ?: null;
            $flag = $actualizaCosto ? 1 : 0;
            $consulta = self::$db->prepare(
                'INSERT INTO recepciones (orden_id, fecha, token, actualiza_costo, notas, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $consulta->bind_param('issisi', $ordenId, $fecha, $token, $flag, $notas, $usuarioId);
            try {
                $consulta->execute();
            } catch (\mysqli_sql_exception $duplicada) {
                self::$db->rollback();
                self::$errores[] = 'Esta recepción ya fue registrada; no se volvió a sumar.';
                return false;
            }
            $recepcionId = (int) self::$db->insert_id;
            $consulta->close();

            $insertar = self::$db->prepare(
                'INSERT INTO recepcion_partidas (recepcion_id, partida_id, producto_id, material_id, destino, cantidad_recibida, costo_unitario)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($aInsertar as $partidaId => [$cantidad, $costo, $destino]) {
                $productoId = $partidas[$partidaId]['producto_id'] !== null ? (int) $partidas[$partidaId]['producto_id'] : null;
                $materialId = $partidas[$partidaId]['material_id'] !== null ? (int) $partidas[$partidaId]['material_id'] : null;
                $texto = number_format($cantidad, 3, '.', '');
                $insertar->bind_param('iiiisss', $recepcionId, $partidaId, $productoId, $materialId, $destino, $texto, $costo);
                $insertar->execute();

                // El costo vigente del catálogo solo cambia por una acción explícita y solo para productos.
                // Costo del proveedor ≠ costo interno: en compras de semiterminados NUNCA se copia al producto propio.
                if ($actualizaCosto && $productoId && $orden['tipo'] !== 'semiterminado') {
                    $actualizar = self::$db->prepare('UPDATE productos SET costo_actual = ? WHERE id = ?');
                    $actualizar->bind_param('si', $costo, $productoId);
                    $actualizar->execute();
                    $actualizar->close();
                }
            }
            $insertar->close();

            $nota = 'Recepción ' . $orden['folio'];
            if ($enStock && !Inventario::entradaPorRecepcion($enStock, $recepcionId, $usuarioId, $nota)) {
                self::$db->rollback();
                self::$errores = Inventario::getErrores() ?: ['No fue posible sumar al inventario.'];
                return false;
            }
            if ($porTerminar) {
                PorTerminar::entradaPorRecepcion($porTerminar, $recepcionId, $usuarioId, $nota);
            }
            if ($materiales && !Materiales::entradaPorRecepcion($materiales, $recepcionId, $usuarioId, $nota)) {
                self::$db->rollback();
                self::$errores = Materiales::getErrores() ?: ['No fue posible sumar el material.'];
                return false;
            }

            self::recalcularEstado($ordenId);
            Auditoria::registrar('orden_compra', $ordenId, 'recepcion', $usuarioId, null, [
                'recepcion_id' => $recepcionId,
                'vendibles' => array_sum($enStock), 'por_terminar' => array_sum($porTerminar), 'material' => array_sum($materiales),
            ]);
            if ($excedentes) {
                Auditoria::registrar('orden_compra', $ordenId, 'excedente', $usuarioId, 'Recepción con excedente confirmado', [
                    'recepcion_id' => $recepcionId, 'partidas' => $excedentes,
                ]);
            }

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::recibir] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar la recepción.';
            return false;
        }
    }

    /** Cierra la orden (aun con faltantes). */
    public static function cerrar(int $id, string $motivo, int $usuarioId): bool
    {
        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($id);

            if (!$orden || !in_array($orden['estado'], ['solicitada', 'parcial', 'completa'], true)) {
                self::$db->rollback();
                self::$errores[] = 'La orden no se puede cerrar en su estado actual.';
                return false;
            }

            $motivo = trim($motivo) ?: null;
            $consulta = self::$db->prepare(
                "UPDATE ordenes_compra SET estado = 'cerrada', cerrada_en = NOW(), cerrada_por = ?, motivo_cierre = ? WHERE id = ?"
            );
            $consulta->bind_param('isi', $usuarioId, $motivo, $id);
            $consulta->execute();
            $consulta->close();

            Auditoria::registrar('orden_compra', $id, 'cerrar', $usuarioId, $motivo);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::cerrar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cerrar la orden.';
            return false;
        }
    }

    /** Reabre una orden cerrada. El motivo es obligatorio y queda auditado. */
    public static function reabrir(int $id, string $motivo, int $usuarioId): bool
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Para reabrir una orden cerrada escribe el motivo (mínimo 5 caracteres).';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($id);

            if (!$orden || $orden['estado'] !== 'cerrada') {
                self::$db->rollback();
                self::$errores[] = 'Solo se puede reabrir una orden cerrada.';
                return false;
            }

            $consulta = self::$db->prepare(
                'UPDATE ordenes_compra SET cerrada_en = NULL, cerrada_por = NULL, motivo_cierre = NULL WHERE id = ?'
            );
            $consulta->bind_param('i', $id);
            $consulta->execute();
            $consulta->close();

            self::recalcularEstado($id);
            Auditoria::registrar('orden_compra', $id, 'reabrir', $usuarioId, $motivo);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::reabrir] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible reabrir la orden.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * INTERNOS
     * ------------------------------------------------------------------ */

    /** Bloquea la fila de la orden hasta el fin de la transacción. */
    private static function bloquear(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM ordenes_compra WHERE id = ? FOR UPDATE');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    private static function contarRecepciones(int $ordenId): int
    {
        $consulta = self::$db->prepare('SELECT COUNT(*) AS n FROM recepciones WHERE orden_id = ?');
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $n;
    }

    /** solicitada → parcial → completa según lo recibido (solo cuentan las partidas que llevan recepción). */
    private static function recalcularEstado(int $ordenId): void
    {
        $partidas = array_filter(self::partidas($ordenId), static fn ($p) => (int) $p['controla'] === 1);
        $recibidas = array_sum(array_column($partidas, 'recibida'));
        $pendiente = array_sum(array_map(static fn ($p) => max(0, $p['pendiente']), $partidas));

        if (!$partidas) {
            $estado = 'sin_recepcion';
        } else {
            $estado = $recibidas == 0 ? 'solicitada' : ($pendiente > 0 ? 'parcial' : 'completa');
        }

        $consulta = self::$db->prepare(
            "UPDATE ordenes_compra SET estado = ? WHERE id = ? AND estado NOT IN ('cancelada')"
        );
        $consulta->bind_param('si', $estado, $ordenId);
        $consulta->execute();
        $consulta->close();
    }

    /** @param array<int, array<string, mixed>> $lineas ya resueltas por resolverLineas(). */
    private static function guardarPartidas(int $ordenId, array $lineas): void
    {
        $consulta = self::$db->prepare(
            'INSERT INTO orden_compra_partidas
                (orden_id, producto_id, material_id, proveedor_producto_id, descripcion, codigo, unidad, controla,
                 cantidad_solicitada, costo_esperado, costo_catalogo, importe_esperado, aplica_iva)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lineas as $linea) {
            $productoId = $linea['producto_id'] !== null ? (int) $linea['producto_id'] : null;
            $materialId = $linea['material_id'] !== null ? (int) $linea['material_id'] : null;
            $cantidad   = number_format((float) $linea['cantidad'], 3, '.', '');
            $costo      = number_format((float) $linea['costo'], 2, '.', '');
            $importe    = number_format(self::importe($linea['cantidad'], $linea['costo']), 2, '.', '');
            $descripcion = trim((string) ($linea['descripcion'] ?? '')) ?: null;
            $unidad     = trim((string) ($linea['unidad'] ?? '')) ?: null;
            $controla   = (int) $linea['controla'];
            $catalogoId = $linea['proveedor_producto_id'] !== null ? (int) $linea['proveedor_producto_id'] : null;
            $codigo     = $linea['codigo'] !== null && $linea['codigo'] !== '' ? (string) $linea['codigo'] : null;
            $costoCat   = $linea['costo_catalogo'] !== null ? number_format((float) $linea['costo_catalogo'], 2, '.', '') : null;
            $aplicaIva  = !empty($linea['aplica_iva']) ? 1 : 0;
            $consulta->bind_param('iiiisssissssi', $ordenId, $productoId, $materialId, $catalogoId, $descripcion, $codigo, $unidad, $controla, $cantidad, $costo, $costoCat, $importe, $aplicaIva);
            $consulta->execute();
        }
        $consulta->close();
    }

    /** OC-AAAA-NNNN; el índice único es la última defensa ante carreras. */
    private static function siguienteFolio(): string
    {
        $anio = date('Y');
        $prefijo = "OC-$anio-";
        $consulta = self::$db->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(folio, 9) AS UNSIGNED)), 0) AS n
               FROM ordenes_compra WHERE folio LIKE ? FOR UPDATE'
        );
        $patron = $prefijo . '%';
        $consulta->bind_param('s', $patron);
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $prefijo . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
