<?php

namespace Classes;

use App\Inventario;

/**
 * DatosExternos — catálogo y resolución de datos de Acero 360 que se pueden
 * vincular a una celda de SheetFlow.
 *
 * Claves (nunca se evalúan como SQL, solo se comparan y se validan):
 *   inventario:empresa:<empresaId>:<campo>
 *   inventario:producto:<empresaId>:<productoId>:<campo>
 *   clientes:cliente:<clienteId>:<campo>
 *   ventas:periodo:<desde>:<hasta>:<campo>
 *   ventas:empresa:<empresaId>:<desde>:<hasta>:<campo>
 *   gastos:periodo:<desde>:<hasta>:<campo>
 *   gastos:empresa:<empresaId>:<desde>:<hasta>:<campo>
 *   compras:periodo:<desde>:<hasta>:<campo>
 *   cotizaciones:periodo:<desde>:<hasta>:<campo>
 *   entregas:pendientes[:empresa:<empresaId>]
 *   entregas:periodo:<desde>:<hasta>:<campo>
 *   fiscal:<contribuyenteId>:<anio>:<mes>:<campo>   (mes 0 = anual)
 *
 * Los valores se devuelven como números sin formato para que las fórmulas los
 * puedan usar. El formato de moneda o entero lo pone el tipo de columna.
 */
class DatosExternos
{
    public const CAMPOS_EMPRESA = [
        'total_con_iva' => ['etiqueta' => 'Total con IVA', 'tipo' => 'moneda'],
        'total_sin_iva' => ['etiqueta' => 'Total sin IVA', 'tipo' => 'moneda'],
        'iva_total'     => ['etiqueta' => 'IVA total', 'tipo' => 'moneda'],
        'piezas'        => ['etiqueta' => 'Piezas en inventario', 'tipo' => 'entero'],
        'productos'     => ['etiqueta' => 'Número de productos', 'tipo' => 'entero'],
    ];

    public const CAMPOS_PRODUCTO = [
        'precio'         => ['etiqueta' => 'Precio de lista (sin IVA)', 'tipo' => 'moneda'],
        'iva_unitario'   => ['etiqueta' => 'IVA unitario', 'tipo' => 'moneda'],
        'precio_con_iva' => ['etiqueta' => 'Precio con IVA', 'tipo' => 'moneda'],
        'stock'          => ['etiqueta' => 'Existencia', 'tipo' => 'entero'],
        'stock_minimo'   => ['etiqueta' => 'Cantidad mínima', 'tipo' => 'entero'],
        'total_sin_iva'  => ['etiqueta' => 'Total sin IVA', 'tipo' => 'moneda'],
        'iva_total'      => ['etiqueta' => 'IVA del producto', 'tipo' => 'moneda'],
        'total_con_iva'  => ['etiqueta' => 'Total con IVA', 'tipo' => 'moneda'],
    ];

    public const CAMPOS_CLIENTE = [
        'rfc'                    => ['etiqueta' => 'RFC', 'tipo' => 'texto'],
        'ventas_total'           => ['etiqueta' => 'Ventas acumuladas', 'tipo' => 'moneda'],
        'ventas_12m'             => ['etiqueta' => 'Ventas últimos 12 meses', 'tipo' => 'moneda'],
        'operaciones'            => ['etiqueta' => 'Ventas realizadas', 'tipo' => 'entero'],
        'cotizaciones_abiertas'  => ['etiqueta' => 'Cotizaciones abiertas', 'tipo' => 'entero'],
        'cotizaciones_total'     => ['etiqueta' => 'Importe de cotizaciones', 'tipo' => 'moneda'],
    ];

    public const CAMPOS_VENTAS = [
        'importe'         => ['etiqueta' => 'Importe con IVA', 'tipo' => 'moneda'],
        'subtotal'        => ['etiqueta' => 'Importe sin IVA', 'tipo' => 'moneda'],
        'operaciones'     => ['etiqueta' => 'Operaciones', 'tipo' => 'entero'],
        'unidades'        => ['etiqueta' => 'Unidades vendidas', 'tipo' => 'numero'],
        'ticket_promedio' => ['etiqueta' => 'Ticket promedio', 'tipo' => 'moneda'],
    ];

    public const CAMPOS_GASTOS = [
        'total'          => ['etiqueta' => 'Total de gastos', 'tipo' => 'moneda'],
        'subtotal'       => ['etiqueta' => 'Subtotal de gastos', 'tipo' => 'moneda'],
        'iva'            => ['etiqueta' => 'IVA de gastos', 'tipo' => 'moneda'],
        'por_pagar'      => ['etiqueta' => 'Gastos por pagar', 'tipo' => 'moneda'],
        'n'              => ['etiqueta' => 'Número de gastos', 'tipo' => 'entero'],
        'sin_comprobante'=> ['etiqueta' => 'Gastos sin comprobante', 'tipo' => 'entero'],
    ];

    public const CAMPOS_COMPRAS = [
        'comprado'             => ['etiqueta' => 'Compras del periodo', 'tipo' => 'moneda'],
        'pagado'               => ['etiqueta' => 'Pagado a proveedores', 'tipo' => 'moneda'],
        'saldo'                => ['etiqueta' => 'Saldo con proveedores', 'tipo' => 'moneda'],
        'pendiente_documentar' => ['etiqueta' => 'Compras sin factura', 'tipo' => 'moneda'],
        'compras'              => ['etiqueta' => 'Órdenes de compra', 'tipo' => 'entero'],
    ];

    public const CAMPOS_COTIZACIONES = [
        'total'           => ['etiqueta' => 'Cotizaciones', 'tipo' => 'entero'],
        'abiertas'        => ['etiqueta' => 'Cotizaciones abiertas', 'tipo' => 'entero'],
        'importe'         => ['etiqueta' => 'Importe cotizado', 'tipo' => 'moneda'],
        'importe_abiertas'=> ['etiqueta' => 'Importe abierto', 'tipo' => 'moneda'],
    ];

    public const CAMPOS_ENTREGAS = [
        'pendientes' => ['etiqueta' => 'Ventas por entregar', 'tipo' => 'entero'],
        'entregas'   => ['etiqueta' => 'Entregas del periodo', 'tipo' => 'entero'],
        'piezas'     => ['etiqueta' => 'Piezas entregadas', 'tipo' => 'entero'],
    ];

    public const CAMPOS_FISCAL = [
        'facturado'        => ['etiqueta' => 'Facturado (con IVA)', 'tipo' => 'moneda'],
        'facturado_sin_iva'=> ['etiqueta' => 'Facturado sin IVA', 'tipo' => 'moneda'],
        'pagos'            => ['etiqueta' => 'Pagos deducibles (con IVA)', 'tipo' => 'moneda'],
        'iva_facturado'    => ['etiqueta' => 'IVA facturado', 'tipo' => 'moneda'],
        'iva_acreditado'   => ['etiqueta' => 'IVA acreditable', 'tipo' => 'moneda'],
        'iva_por_pagar'    => ['etiqueta' => 'IVA a cargo', 'tipo' => 'moneda'],
        'isr_por_pagar'    => ['etiqueta' => 'ISR a pagar', 'tipo' => 'moneda'],
        'impuestos'        => ['etiqueta' => 'Impuestos del periodo', 'tipo' => 'moneda'],
    ];

    private const FECHA = '\d{4}-\d{2}-\d{2}';

    /** ¿La clave tiene una forma válida y un campo conocido? No consulta la base. */
    public static function esClave(string $clave): bool
    {
        if (preg_match('/^inventario:empresa:(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_EMPRESA[$m[2]]);
        }
        if (preg_match('/^inventario:producto:(\d{1,9}):(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_PRODUCTO[$m[3]]);
        }
        if (preg_match('/^clientes:cliente:(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_CLIENTE[$m[2]]);
        }
        if (preg_match('/^ventas:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_VENTAS[$m[3]]) && self::fechasValidas($m[1], $m[2]);
        }
        if (preg_match('/^ventas:empresa:(\d{1,9}):(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_VENTAS[$m[4]]) && self::fechasValidas($m[2], $m[3]);
        }
        if (preg_match('/^gastos:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_GASTOS[$m[3]]) && self::fechasValidas($m[1], $m[2]);
        }
        if (preg_match('/^gastos:empresa:(\d{1,9}):(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_GASTOS[$m[4]]) && self::fechasValidas($m[2], $m[3]);
        }
        if (preg_match('/^compras:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_COMPRAS[$m[3]]) && self::fechasValidas($m[1], $m[2]);
        }
        if (preg_match('/^cotizaciones:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_COTIZACIONES[$m[3]]) && self::fechasValidas($m[1], $m[2]);
        }
        if ($clave === 'entregas:pendientes' || preg_match('/^entregas:pendientes:empresa:(\d{1,9})$/', $clave)) {
            return true;
        }
        if (preg_match('/^entregas:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
            return in_array($m[3], ['entregas', 'piezas'], true) && self::fechasValidas($m[1], $m[2]);
        }
        if (preg_match('/^fiscal:(\d{1,9}):(\d{4}):(\d{1,2}):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_FISCAL[$m[4]]) && (int) $m[2] >= 2000 && (int) $m[2] <= 2100 && (int) $m[3] >= 0 && (int) $m[3] <= 12;
        }

        return false;
    }

    private static function fechasValidas(string $desde, string $hasta): bool
    {
        return checkdate((int) substr($desde, 5, 2), (int) substr($desde, 8, 2), (int) substr($desde, 0, 4))
            && checkdate((int) substr($hasta, 5, 2), (int) substr($hasta, 8, 2), (int) substr($hasta, 0, 4));
    }

    /** @return array<string, mixed> */
    public static function catalogo(): array
    {
        $empresas = [];
        foreach (Inventario::paraPdf() as $seccion) {
            $totales = [];
            foreach (self::CAMPOS_EMPRESA as $campo => $def) {
                $totales[$campo] = self::valorEmpresa($seccion, $campo, $def['tipo']);
            }

            $productos = [];
            foreach ($seccion['productos'] as $p) {
                $valores = [];
                foreach (self::CAMPOS_PRODUCTO as $campo => $def) {
                    $valores[$campo] = self::valorProducto($p, $campo, $def['tipo']);
                }
                $productos[] = [
                    'id'      => (int) $p['id'],
                    'sku'     => (string) $p['sku'],
                    'nombre'  => (string) $p['nombre'],
                    'valores' => $valores,
                ];
            }

            $empresas[] = [
                'id'       => (int) $seccion['id'],
                'nombre'   => (string) $seccion['nombre'],
                'totales'  => $totales,
                'productos' => $productos,
            ];
        }

        return [
            'empresas'      => $empresas,
            'negocios'      => self::negocios(),
            'clientes'      => self::clientes(),
            'contribuyentes' => self::contribuyentes(),
            'hoy'           => date('Y-m-d'),
            'campos'        => [
                'empresa'      => self::CAMPOS_EMPRESA,
                'producto'     => self::CAMPOS_PRODUCTO,
                'cliente'      => self::CAMPOS_CLIENTE,
                'ventas'       => self::CAMPOS_VENTAS,
                'gastos'       => self::CAMPOS_GASTOS,
                'compras'      => self::CAMPOS_COMPRAS,
                'cotizaciones' => self::CAMPOS_COTIZACIONES,
                'entregas'     => self::CAMPOS_ENTREGAS,
                'fiscal'       => self::CAMPOS_FISCAL,
            ],
        ];
    }

    /** @return array<int, array{id:int,nombre:string}> */
    private static function negocios(): array
    {
        $db = self::db();
        if (!$db) return [];
        $out = [];
        $r = $db->query('SELECT id, nombre FROM empresas WHERE activa = 1 ORDER BY nombre');
        while ($r && ($f = $r->fetch_assoc())) {
            $out[] = ['id' => (int) $f['id'], 'nombre' => (string) $f['nombre']];
        }
        return $out;
    }

    /** @return array<int, array{id:int,nombre:string,rfc:string}> */
    private static function clientes(): array
    {        $db = self::db();
        if (!$db) return [];
        $out = [];
        $r = $db->query('SELECT id, nombre, IFNULL(nombre_empresa, "") AS empresa, IFNULL(rfc, "") AS rfc FROM clientes ORDER BY nombre');
        while ($r && ($f = $r->fetch_assoc())) {
            $out[] = [
                'id' => (int) $f['id'],
                'nombre' => (string) ($f['empresa'] !== '' ? $f['empresa'] : $f['nombre']),
                'rfc' => (string) $f['rfc'],
            ];
        }
        return $out;
    }

    /** @return array<int, array{id:int,nombre:string,rfc:string}> */
    private static function contribuyentes(): array
    {
        $db = self::db();
        if (!$db) return [];
        $out = [];
        $r = $db->query('SELECT id, razon_social, IFNULL(rfc, "") AS rfc FROM contribuyentes WHERE activo = 1 ORDER BY razon_social');
        while ($r && ($f = $r->fetch_assoc())) {
            $out[] = ['id' => (int) $f['id'], 'nombre' => (string) $f['razon_social'], 'rfc' => (string) $f['rfc']];
        }
        return $out;
    }

    private static function db()
    {
        try {
            return \Model\SheetflowHoja::conexion();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resuelve un conjunto de claves. Devuelve solo las que existen.
     *
     * @param string[] $claves
     * @return array<string, string>
     */
    public static function resolver(array $claves): array
    {
        $claves = array_values(array_unique(array_filter(array_map('strval', $claves), [self::class, 'esClave'])));
        if (!$claves) {
            return [];
        }

        $secciones = [];
        $valores = [];
        $cache = [];

        foreach ($claves as $clave) {
            try {
                if (preg_match('/^inventario:empresa:(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
                    if (!isset($secciones[(int) $m[1]])) {
                        $secciones[(int) $m[1]] = self::seccion((int) $m[1]);
                    }
                    $s = $secciones[(int) $m[1]];
                    if ($s) $valores[$clave] = self::valorEmpresa($s, $m[2], self::CAMPOS_EMPRESA[$m[2]]['tipo']);
                    continue;
                }
                if (preg_match('/^inventario:producto:(\d{1,9}):(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
                    if (!isset($secciones[(int) $m[1]])) {
                        $secciones[(int) $m[1]] = self::seccion((int) $m[1]);
                    }
                    $s = $secciones[(int) $m[1]];
                    if (!$s) continue;
                    foreach ($s['productos'] as $p) {
                        if ((int) $p['id'] === (int) $m[2]) {
                            $valores[$clave] = self::valorProducto($p, $m[3], self::CAMPOS_PRODUCTO[$m[3]]['tipo']);
                            break;
                        }
                    }
                    continue;
                }
                if (preg_match('/^clientes:cliente:(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorCliente((int) $m[1], $m[2]);
                    continue;
                }
                if (preg_match('/^ventas:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorVentas($m[1], $m[2], 0, $m[3], $cache);
                    continue;
                }
                if (preg_match('/^ventas:empresa:(\d{1,9}):(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorVentas($m[2], $m[3], (int) $m[1], $m[4], $cache);
                    continue;
                }
                if (preg_match('/^gastos:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorGastos($m[1], $m[2], 0, $m[3], $cache);
                    continue;
                }
                if (preg_match('/^gastos:empresa:(\d{1,9}):(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorGastos($m[2], $m[3], (int) $m[1], $m[4], $cache);
                    continue;
                }
                if (preg_match('/^compras:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorCompras($m[1], $m[2], $m[3], $cache);
                    continue;
                }
                if (preg_match('/^cotizaciones:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorCotizaciones($m[1], $m[2], $m[3]);
                    continue;
                }
                if ($clave === 'entregas:pendientes') {
                    $valores[$clave] = self::texto(\App\Entregas::contarVentasPendientes(), 'entero');
                    continue;
                }
                if (preg_match('/^entregas:pendientes:empresa:(\d{1,9})$/', $clave, $m)) {
                    $valores[$clave] = self::texto(\App\Entregas::contarVentasPendientes(['empresa' => (int) $m[1]]), 'entero');
                    continue;
                }
                if (preg_match('/^entregas:periodo:(' . self::FECHA . '):(' . self::FECHA . '):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorEntregas($m[1], $m[2], $m[3]);
                    continue;
                }
                if (preg_match('/^fiscal:(\d{1,9}):(\d{4}):(\d{1,2}):([a-z_]+)$/', $clave, $m)) {
                    $valores[$clave] = self::valorFiscal((int) $m[1], (int) $m[2], (int) $m[3], $m[4], $cache);
                    continue;
                }
            } catch (\Throwable $e) {
                // Un dato que falla no debe romper el resto de la tabla.
            }
        }

        return $valores;
    }

    private static function seccion(int $empresaId): ?array
    {
        foreach (Inventario::paraPdf() as $s) {
            if ((int) $s['id'] === $empresaId) return $s;
        }
        return null;
    }

    /** Etiqueta legible de una clave (para mensajes y tooltips). */
    public static function etiqueta(string $clave): string
    {
        $nombres = [
            'inventario' => 'Inventario', 'clientes' => 'Cliente', 'ventas' => 'Ventas',
            'gastos' => 'Gastos', 'compras' => 'Compras', 'cotizaciones' => 'Cotizaciones',
            'entregas' => 'Entregas', 'fiscal' => 'Fiscal',
        ];
        foreach ($nombres as $prefijo => $nombre) {
            if (str_starts_with($clave, $prefijo . ':')) return $nombre . ' · ' . $clave;
        }
        return 'Dato externo';
    }

    // ---------- Inventario ----------

    /** @param array<string, mixed> $seccion */
    private static function valorEmpresa(array $seccion, string $campo, string $tipo): string
    {
        $valor = match ($campo) {
            'productos' => count($seccion['productos']),
            default => $seccion[$campo] ?? 0,
        };

        return self::texto($valor, $tipo);
    }

    /** @param array<string, mixed> $p */
    private static function valorProducto(array $p, string $campo, string $tipo): string
    {
        if ($campo === 'stock_minimo' && $p['stock_minimo'] === null) {
            return '';
        }

        return self::texto($p[$campo] ?? 0, $tipo);
    }

    // ---------- Clientes ----------

    private static function valorCliente(int $clienteId, string $campo): string
    {
        $db = self::db();
        if (!$db) return '';

        if ($campo === 'rfc') {
            $r = $db->query('SELECT IFNULL(rfc, "") FROM clientes WHERE id = ' . $clienteId);
            return $r && ($f = $r->fetch_row()) ? (string) $f[0] : '';
        }

        if ($campo === 'ventas_total' || $campo === 'ventas_12m' || $campo === 'operaciones') {
            $desde = $campo === 'ventas_12m' ? date('Y-m-d', strtotime('-12 months')) : '2000-01-01';
            $t = \App\Estadisticas::total('realizadas', $desde, date('Y-m-d'), ['cliente' => $clienteId]);
            return self::texto($campo === 'operaciones' ? $t['operaciones'] : $t['importe'] / 100, $campo === 'operaciones' ? 'entero' : 'moneda');
        }

        if ($campo === 'cotizaciones_abiertas') {
            $r = $db->query('SELECT COUNT(*) FROM cotizaciones WHERE cliente_id = ' . $clienteId . ' AND estado = "pendiente"');
            return self::texto($r ? (int) $r->fetch_row()[0] : 0, 'entero');
        }
        if ($campo === 'cotizaciones_total') {
            $r = $db->query('SELECT COALESCE(SUM(total), 0) FROM cotizaciones WHERE cliente_id = ' . $clienteId);
            return self::texto($r ? (float) $r->fetch_row()[0] : 0, 'moneda');
        }

        return '';
    }

    // ---------- Ventas ----------

    /** @param array<string, mixed> $cache */
    private static function valorVentas(string $desde, string $hasta, int $empresa, string $campo, array &$cache): string
    {
        $llave = $desde . '|' . $hasta . '|' . $empresa;
        if (!isset($cache['ventas'][$llave])) {
            $f = $empresa > 0 ? ['empresa' => $empresa] : [];
            $conIva = \App\Estadisticas::total('realizadas', $desde, $hasta, $f);
            $sinIva = \App\Estadisticas::total('realizadas', $desde, $hasta, $f, 'subtotal');
            $cache['ventas'][$llave] = ['con' => $conIva, 'sin' => $sinIva];
        }
        $t = $cache['ventas'][$llave];
        return match ($campo) {
            'importe' => self::texto($t['con']['importe'] / 100, 'moneda'),
            'subtotal' => self::texto($t['sin']['importe'] / 100, 'moneda'),
            'operaciones' => self::texto($t['con']['operaciones'], 'entero'),
            'unidades' => self::texto($t['con']['unidades'], 'numero'),
            'ticket_promedio' => self::texto($t['con']['operaciones'] > 0 ? $t['con']['importe'] / 100 / $t['con']['operaciones'] : 0, 'moneda'),
            default => '',
        };
    }

    // ---------- Gastos ----------

    /** @param array<string, mixed> $cache */
    private static function valorGastos(string $desde, string $hasta, int $empresa, string $campo, array &$cache): string
    {
        $llave = $desde . '|' . $hasta . '|' . $empresa;
        if (!isset($cache['gastos'][$llave])) {
            $f = ['desde' => $desde, 'hasta' => $hasta];
            if ($empresa > 0) $f['empresa'] = $empresa;
            $cache['gastos'][$llave] = \App\Gastos::totales($f);
        }
        $t = $cache['gastos'][$llave];
        return match ($campo) {
            'total' => self::texto($t['total'], 'moneda'),
            'subtotal' => self::texto($t['subtotal'], 'moneda'),
            'iva' => self::texto($t['iva'], 'moneda'),
            'por_pagar' => self::texto($t['por_pagar'], 'moneda'),
            'n' => self::texto($t['n'], 'entero'),
            'sin_comprobante' => self::texto($t['sin_comprobante'], 'entero'),
            default => '',
        };
    }

    // ---------- Compras ----------

    /** @param array<string, mixed> $cache */
    private static function valorCompras(string $desde, string $hasta, string $campo, array &$cache): string
    {
        $llave = $desde . '|' . $hasta;
        if (!isset($cache['compras'][$llave])) {
            $cache['compras'][$llave] = \App\OrdenesCompra::totalesFiltrados(['desde' => $desde, 'hasta' => $hasta]);
        }
        $t = $cache['compras'][$llave];
        return match ($campo) {
            'comprado' => self::texto($t['comprado'], 'moneda'),
            'pagado' => self::texto($t['pagado'], 'moneda'),
            'saldo' => self::texto($t['saldo'], 'moneda'),
            'pendiente_documentar' => self::texto($t['pendiente_documentar'], 'moneda'),
            'compras' => self::texto($t['compras'], 'entero'),
            default => '',
        };
    }

    // ---------- Cotizaciones ----------

    private static function valorCotizaciones(string $desde, string $hasta, string $campo): string
    {
        $db = self::db();
        if (!$db) return '';
        $consulta = $db->prepare('SELECT COUNT(*) AS total, SUM(estado = "pendiente") AS abiertas, COALESCE(SUM(total), 0) AS importe, COALESCE(SUM(CASE WHEN estado = "pendiente" THEN total END), 0) AS importe_abiertas FROM cotizaciones WHERE fecha BETWEEN ? AND ?');
        $consulta->bind_param('ss', $desde, $hasta);
        $consulta->execute();
        $f = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return match ($campo) {
            'total' => self::texto((int) $f['total'], 'entero'),
            'abiertas' => self::texto((int) ($f['abiertas'] ?? 0), 'entero'),
            'importe' => self::texto((float) $f['importe'], 'moneda'),
            'importe_abiertas' => self::texto((float) $f['importe_abiertas'], 'moneda'),
            default => '',
        };
    }

    // ---------- Entregas ----------

    private static function valorEntregas(string $desde, string $hasta, string $campo): string
    {
        $db = self::db();
        if (!$db) return '';
        if ($campo === 'entregas') {
            $consulta = $db->prepare('SELECT COUNT(*) FROM entregas WHERE fecha BETWEEN ? AND ?');
            $consulta->bind_param('ss', $desde, $hasta);
            $consulta->execute();
            $n = (int) $consulta->get_result()->fetch_row()[0];
            $consulta->close();
            return self::texto($n, 'entero');
        }
        if ($campo === 'piezas') {
            $consulta = $db->prepare('SELECT COALESCE(SUM(er.cantidad), 0) FROM entrega_renglones er JOIN entregas e ON e.id = er.entrega_id WHERE e.fecha BETWEEN ? AND ?');
            $consulta->bind_param('ss', $desde, $hasta);
            $consulta->execute();
            $n = (float) $consulta->get_result()->fetch_row()[0];
            $consulta->close();
            return self::texto($n, 'entero');
        }
        return '';
    }

    // ---------- Fiscal ----------

    /** @param array<string, mixed> $cache */
    private static function valorFiscal(int $contribuyente, int $anio, int $mes, string $campo, array &$cache): string
    {
        $llave = $contribuyente . '|' . $anio . '|' . $mes;
        if (!isset($cache['fiscal'][$llave])) {
            $cache['fiscal'][$llave] = \App\ResumenFiscal::impuestosDelPeriodo($contribuyente, $anio, $mes);
        }
        $t = $cache['fiscal'][$llave];
        return isset($t[$campo]) ? self::texto($t[$campo], 'moneda') : '';
    }

    /**
     * Listas completas para insertar como rango (no una sola celda).
     *
     * @param array<string, mixed> $p
     * @return array{filas: array<int, array<int, string>>, tipos: array<int, string>, alinear: array<int, string>}|null
     */
    public static function lista(string $tipo, array $p = []): ?array
    {
        $db = self::db();
        if (!$db) return null;
        $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['desde'] ?? '')) ? (string) $p['desde'] : date('Y-m-01');
        $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['hasta'] ?? '')) ? (string) $p['hasta'] : date('Y-m-d');
        $empresa = (int) ($p['empresa'] ?? 0);
        $limite = 500;

        if ($tipo === 'inventario') {
            $seccion = null;
            foreach (Inventario::paraPdf() as $s) {
                if ((int) $s['id'] === $empresa) { $seccion = $s; break; }
            }
            if (!$seccion) return null;
            $filas = [['Código', 'Producto', 'Precio sin IVA', 'IVA unitario', 'Precio con IVA', 'Existencia', 'Total sin IVA', 'Total con IVA']];
            foreach (array_slice($seccion['productos'], 0, $limite) as $pr) {
                $filas[] = [
                    $pr['sku'] !== '' ? (string) $pr['sku'] : '—',
                    (string) $pr['nombre'],
                    number_format((float) $pr['precio'], 2, '.', ''),
                    number_format((float) $pr['iva_unitario'], 2, '.', ''),
                    number_format((float) $pr['precio_con_iva'], 2, '.', ''),
                    (string) (int) $pr['stock'],
                    number_format((float) $pr['total_sin_iva'], 2, '.', ''),
                    number_format((float) $pr['total_con_iva'], 2, '.', ''),
                ];
            }
            return ['filas' => $filas, 'tipos' => ['texto', 'texto', 'moneda', 'moneda', 'moneda', 'numero', 'moneda', 'moneda'], 'alinear' => ['izquierda', 'izquierda', 'derecha', 'derecha', 'derecha', 'derecha', 'derecha', 'derecha']];
        }

        if ($tipo === 'clientes') {
            $filas = [['Cliente', 'RFC', 'Ventas', 'Cotizaciones abiertas']];
            $r = $db->query('SELECT COALESCE(NULLIF(c.nombre_empresa, ""), c.nombre) AS nombre, IFNULL(c.rfc, "") AS rfc,
                    (SELECT COALESCE(SUM(v.total), 0) FROM ventas v WHERE v.cliente_id = c.id AND v.estado = "registrada") AS ventas,
                    (SELECT COUNT(*) FROM cotizaciones q WHERE q.cliente_id = c.id AND q.estado = "pendiente") AS abiertas
               FROM clientes c ORDER BY nombre LIMIT ' . $limite);
            while ($r && ($f = $r->fetch_assoc())) {
                $filas[] = [(string) $f['nombre'], (string) $f['rfc'], number_format((float) $f['ventas'], 2, '.', ''), (string) (int) $f['abiertas']];
            }
            return ['filas' => $filas, 'tipos' => ['texto', 'texto', 'moneda', 'numero'], 'alinear' => ['izquierda', 'izquierda', 'derecha', 'derecha']];
        }

        if ($tipo === 'ventas') {
            $filas = [['Fecha', 'Folio', 'Cliente', 'Empresa', 'Total']];
            $sql = 'SELECT v.fecha, v.folio, COALESCE(NULLIF(c.nombre_empresa, ""), c.nombre) AS cliente, e.nombre AS empresa, v.total
                      FROM ventas v JOIN clientes c ON c.id = v.cliente_id JOIN empresas e ON e.id = v.empresa_id
                     WHERE v.estado = "registrada" AND v.fecha BETWEEN ? AND ?' . ($empresa > 0 ? ' AND v.empresa_id = ?' : '') . ' ORDER BY v.fecha, v.id LIMIT ' . $limite;
            $consulta = $db->prepare($sql);
            if ($empresa > 0) $consulta->bind_param('ssi', $desde, $hasta, $empresa); else $consulta->bind_param('ss', $desde, $hasta);
            $consulta->execute();
            $r = $consulta->get_result();
            while ($f = $r->fetch_assoc()) {
                $filas[] = [formatear_fecha($f['fecha'], 'd/m/Y'), (string) $f['folio'], (string) $f['cliente'], (string) $f['empresa'], number_format((float) $f['total'], 2, '.', '')];
            }
            $consulta->close();
            return ['filas' => $filas, 'tipos' => ['fecha', 'texto', 'texto', 'texto', 'moneda'], 'alinear' => ['centro', 'izquierda', 'izquierda', 'izquierda', 'derecha']];
        }

        if ($tipo === 'cotizaciones') {
            $filas = [['Fecha', 'Folio', 'Cliente', 'Estado', 'Total']];
            $consulta = $db->prepare('SELECT q.fecha, q.folio, COALESCE(NULLIF(c.nombre_empresa, ""), c.nombre) AS cliente, q.estado, q.total
                      FROM cotizaciones q JOIN clientes c ON c.id = q.cliente_id
                     WHERE q.fecha BETWEEN ? AND ? ORDER BY q.fecha, q.id LIMIT ' . $limite);
            $consulta->bind_param('ss', $desde, $hasta);
            $consulta->execute();
            $r = $consulta->get_result();
            while ($f = $r->fetch_assoc()) {
                $filas[] = [formatear_fecha($f['fecha'], 'd/m/Y'), (string) $f['folio'], (string) $f['cliente'], (string) $f['estado'], number_format((float) $f['total'], 2, '.', '')];
            }
            $consulta->close();
            return ['filas' => $filas, 'tipos' => ['fecha', 'texto', 'texto', 'texto', 'moneda'], 'alinear' => ['centro', 'izquierda', 'izquierda', 'centro', 'derecha']];
        }

        if ($tipo === 'gastos') {
            $filas = [['Fecha', 'Beneficiario', 'Concepto', 'Total', 'Pago']];
            $sql = 'SELECT g.fecha, g.beneficiario, g.concepto, g.total, g.estado_pago
                      FROM gastos g WHERE g.estado = "vigente" AND g.fecha BETWEEN ? AND ?' . ($empresa > 0 ? ' AND (g.empresa_id = ? OR EXISTS (SELECT 1 FROM gasto_reparto r WHERE r.gasto_id = g.id AND r.empresa_id = ?))' : '') . ' ORDER BY g.fecha, g.id LIMIT ' . $limite;
            $consulta = $db->prepare($sql);
            if ($empresa > 0) $consulta->bind_param('ssii', $desde, $hasta, $empresa, $empresa); else $consulta->bind_param('ss', $desde, $hasta);
            $consulta->execute();
            $r = $consulta->get_result();
            while ($f = $r->fetch_assoc()) {
                $filas[] = [formatear_fecha($f['fecha'], 'd/m/Y'), (string) $f['beneficiario'], (string) $f['concepto'], number_format((float) $f['total'], 2, '.', ''), (string) $f['estado_pago']];
            }
            $consulta->close();
            return ['filas' => $filas, 'tipos' => ['fecha', 'texto', 'texto', 'moneda', 'texto'], 'alinear' => ['centro', 'izquierda', 'izquierda', 'derecha', 'centro']];
        }

        if ($tipo === 'compras') {
            $filas = [['Fecha', 'Folio', 'Proveedor', 'Total', 'Estado']];
            $consulta = $db->prepare('SELECT o.fecha_solicitud AS fecha, o.folio, p.nombre AS proveedor, (o.total_esperado + COALESCE(o.iva, 0)) AS total, o.estado
                      FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id
                     WHERE o.fecha_solicitud BETWEEN ? AND ? AND o.estado <> "cancelada" ORDER BY o.fecha_solicitud, o.id LIMIT ' . $limite);
            $consulta->bind_param('ss', $desde, $hasta);
            $consulta->execute();
            $r = $consulta->get_result();
            while ($f = $r->fetch_assoc()) {
                $filas[] = [formatear_fecha($f['fecha'], 'd/m/Y'), (string) $f['folio'], (string) $f['proveedor'], number_format((float) $f['total'], 2, '.', ''), (string) $f['estado']];
            }
            $consulta->close();
            return ['filas' => $filas, 'tipos' => ['fecha', 'texto', 'texto', 'moneda', 'texto'], 'alinear' => ['centro', 'izquierda', 'izquierda', 'derecha', 'centro']];
        }

        return null;
    }

    // ---------- Formato ----------

    private static function texto($valor, string $tipo): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        if ($tipo === 'moneda') {
            return number_format((float) $valor, 2, '.', '');
        }
        if ($tipo === 'numero') {
            return rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.');
        }

        return (string) (int) round((float) $valor);
    }
}
