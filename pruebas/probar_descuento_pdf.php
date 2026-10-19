<?php
/**
 * Prueba: la fila "Descuento global" del PDF sólo aparece si hay descuento > 0.
 * Renderiza templates/pdfCotizaciones.php sin base de datos.
 * Uso: php pruebas/probar_descuento_pdf.php
 */
require __DIR__ . '/../includes/funciones.php';
require __DIR__ . '/../vendor/autoload.php';

$raiz = dirname(__DIR__);
if (!defined('TEMPLATES_URL')) {
    define('TEMPLATES_URL', $raiz . '/templates');
}

/** Cotización simulada: un renglón de 2 × 100, con el descuento global indicado. */
function renderizar(float $descuentoGlobal, float $descuentoRenglon = 0.0): string
{
    $bruto  = 200.0;
    $lineas = $bruto - $descuentoRenglon;

    $subtotal  = $bruto;
    $descuento = $descuentoRenglon + $descuentoGlobal;
    $base      = $subtotal - $descuento;

    $c = new \App\Cotizaciones([
        'folio'     => 'FOL-TEST-0001',
        'estado'    => 'pendiente',
        'fecha'     => '2026-10-13',
        'subtotal'  => $subtotal,
        'descuento' => $descuento,
        'iva'       => round($base * 0.16, 2),
        'total'     => round($base * 1.16, 2),
    ]);

    $detalles = [[
        'cantidad' => 2, 'precio_unitario' => 100, 'subtotal' => $lineas,
        'descuento_pct' => 0, 'producto_nombre' => 'Producto', 'producto_sku' => 'SKU-1',
        'producto_precio' => 100, 'producto_imagen' => '', 'producto_unidad' => 'pza',
    ]];

    ob_start();
    try {
        (static function () use ($c, $detalles) {
            $cotizacion = $c;
            include TEMPLATES_URL . '/pdfCotizaciones.php';
        })();
    } finally {
        $html = ob_get_clean();
    }
    return $html;
}

$casos = [
    'Sin descuento global (0)'            => [0.0,  0.0,  false],
    'Sin descuento global, con renglón'   => [0.0, 20.0,  false],
    'Con descuento global de 10'          => [10.0, 0.0,  true],
];

$fallos = 0;
foreach ($casos as $nombre => [$global, $renglon, $debeVerse]) {
    $html  = renderizar($global, $renglon);
    $visto = strpos($html, 'Descuento global') !== false;
    $ok    = $visto === $debeVerse;
    $fallos += $ok ? 0 : 1;
    printf("[%s] %-38s esperado: %-10s obtenido: %s\n", $ok ? 'OK ' : 'FALLO', $nombre,
        $debeVerse ? 'se muestra' : 'se oculta', $visto ? 'se muestra' : 'se oculta');
}
echo $fallos === 0 ? "\nTodas las pruebas pasaron.\n" : "\n$fallos prueba(s) fallaron.\n";
exit($fallos === 0 ? 0 : 1);
