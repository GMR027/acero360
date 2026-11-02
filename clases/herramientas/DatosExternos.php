<?php

namespace Classes;

use App\Inventario;

/**
 * DatosExternos — catálogo y resolución de datos de Acero 360 que se pueden
 * vincular a una celda de SheetFlow.
 *
 * Claves (nunca se evalúan como SQL, solo se comparan):
 *   inventario:empresa:<empresaId>:<campo>
 *   inventario:producto:<empresaId>:<productoId>:<campo>
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

    /** ¿La clave tiene una forma válida y un campo conocido? No consulta la base. */
    public static function esClave(string $clave): bool
    {
        if (preg_match('/^inventario:empresa:(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_EMPRESA[$m[2]]);
        }
        if (preg_match('/^inventario:producto:(\d{1,9}):(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
            return isset(self::CAMPOS_PRODUCTO[$m[3]]);
        }

        return false;
    }

    /** @return array{empresas: array<int, array<string, mixed>>, campos: array<string, array<string, array<string, string>>>} */
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
            'empresas' => $empresas,
            'campos'   => [
                'empresa'  => self::CAMPOS_EMPRESA,
                'producto' => self::CAMPOS_PRODUCTO,
            ],
        ];
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
        foreach (Inventario::paraPdf() as $seccion) {
            $secciones[(int) $seccion['id']] = $seccion;
        }

        $valores = [];
        foreach ($claves as $clave) {
            if (preg_match('/^inventario:empresa:(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
                $seccion = $secciones[(int) $m[1]] ?? null;
                if ($seccion && isset(self::CAMPOS_EMPRESA[$m[2]])) {
                    $valores[$clave] = self::valorEmpresa($seccion, $m[2], self::CAMPOS_EMPRESA[$m[2]]['tipo']);
                }
                continue;
            }
            if (preg_match('/^inventario:producto:(\d{1,9}):(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
                $seccion = $secciones[(int) $m[1]] ?? null;
                if (!$seccion || !isset(self::CAMPOS_PRODUCTO[$m[3]])) {
                    continue;
                }
                foreach ($seccion['productos'] as $p) {
                    if ((int) $p['id'] === (int) $m[2]) {
                        $valores[$clave] = self::valorProducto($p, $m[3], self::CAMPOS_PRODUCTO[$m[3]]['tipo']);
                        break;
                    }
                }
            }
        }

        return $valores;
    }

    /** Etiqueta legible de una clave (para mensajes y tooltips). */
    public static function etiqueta(string $clave): string
    {
        $catalogo = self::catalogo();
        if (preg_match('/^inventario:empresa:(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
            foreach ($catalogo['empresas'] as $e) {
                if ((int) $e['id'] === (int) $m[1] && isset(self::CAMPOS_EMPRESA[$m[2]])) {
                    return $e['nombre'] . ' · ' . self::CAMPOS_EMPRESA[$m[2]]['etiqueta'];
                }
            }
        }
        if (preg_match('/^inventario:producto:(\d{1,9}):(\d{1,9}):([a-z_]+)$/', $clave, $m)) {
            foreach ($catalogo['empresas'] as $e) {
                if ((int) $e['id'] !== (int) $m[1]) {
                    continue;
                }
                foreach ($e['productos'] as $p) {
                    if ((int) $p['id'] === (int) $m[2] && isset(self::CAMPOS_PRODUCTO[$m[3]])) {
                        $nombre = $p['sku'] !== '' ? $p['sku'] . ' · ' . $p['nombre'] : $p['nombre'];
                        return $e['nombre'] . ' · ' . $nombre . ' · ' . self::CAMPOS_PRODUCTO[$m[3]]['etiqueta'];
                    }
                }
            }
        }

        return 'Dato externo';
    }

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

    private static function texto($valor, string $tipo): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        if ($tipo === 'moneda') {
            return number_format((float) $valor, 2, '.', '');
        }

        return (string) (int) round((float) $valor);
    }
}
