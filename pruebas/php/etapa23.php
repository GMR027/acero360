<?php
/**
 * pruebas/php/etapa23.php — Herramientas personales (Redactor, SheetFlow, Editor): creación, persistencia y exportaciones.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa23.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use Classes\{Redactor, Sheetflow};
use Model\{RedactorDocumento, SheetflowHoja, EditorDocumento};

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$db->query("DELETE FROM sheetflow_hojas WHERE nombre LIKE 'T23 %'");
$db->query("DELETE FROM redactor_documentos WHERE nombre LIKE 'T23 %'");
$db->query("DELETE FROM editor_documentos WHERE nombre LIKE 'T23 %'");

echo "Tablas de la migración 025\n";
foreach (['redactor_documentos', 'redactor_versiones', 'redactor_palabras', 'redactor_plantillas', 'redactor_firmas', 'redactor_sellos', 'sheetflow_hojas', 'editor_documentos', 'editor_laminas'] as $t) {
    $prueba("existe $t", (int) $db->query("SHOW TABLES LIKE '$t'")->num_rows === 1);
}

echo "SheetFlow\n";
$h = Sheetflow::crearHoja('T23 Hoja', [['Concepto', 'Monto'], ['Perfil', '100'], ['Placa', '250.5']]);
$prueba('crea la hoja', (int) $h->id > 0);
$prueba('persiste en la BD', (int) $uno("SELECT COUNT(*) FROM sheetflow_hojas WHERE id=" . (int) $h->id) === 1);
$h2 = SheetflowHoja::find($h->id);
$prueba('se relee con sus datos', $h2 && str_contains((string) $h2->datos, 'Perfil'));
$prueba('CSV contiene los valores', str_contains(Sheetflow::csv($h2), 'Placa'));
$xlsx = Sheetflow::xlsx($h2);
$prueba('XLSX es un zip válido', str_starts_with($xlsx, 'PK'));
$pdf = Sheetflow::pdf($h2);
$prueba('PDF válido', str_starts_with($pdf, '%PDF'));
$prueba('HTML con tabla', str_contains(Sheetflow::html($h2), '<table'));

echo "Datos externos\n";
$seccion = \App\Inventario::paraPdf()[0] ?? null;
$prueba('hay inventario para vincular', $seccion !== null);
if ($seccion) {
    $producto = $seccion['productos'][0] ?? null;
    $prueba('el catálogo incluye productos', $producto !== null);
    $refEmpresa = 'inventario:empresa:' . (int) $seccion['id'] . ':total_con_iva';
    $d = [
        'cols' => [['n' => 'Total', 't' => 'moneda'], ['n' => 'Ajuste', 't' => 'moneda'], ['n' => 'Diferencia', 't' => 'moneda']],
        'rows' => [['Total', 'Ajuste', 'Diferencia'], ['0.00', '1000.00', '=A3-B3']],
        'refs' => ['1,0' => $refEmpresa],
    ];
    $r = Sheetflow::refrescarExternos($d);
    $prueba('resuelve el total con IVA de la empresa', (float) $r['rows'][1][0] === round((float) $seccion['total_con_iva'], 2), (string) $r['rows'][1][0]);
    $prueba('conserva el valor manual', $r['rows'][1][1] === '1000.00');
    $prueba('recalcula la fórmula con el dato externo', abs((float) ($r['calc']->{'1,2'} ?? 0) - ((float) $r['rows'][1][0] - 1000)) < 0.005, (string) ($r['calc']->{'1,2'} ?? ''));
    $prueba('conserva la referencia al sanear', isset($r['refs']->{'1,0'}) && $r['refs']->{'1,0'} === $refEmpresa);
    $prueba('las celdas sin referencia no se tocan', $r['rows'][1][1] === '1000.00');

    if ($producto) {
        $refProducto = 'inventario:producto:' . (int) $seccion['id'] . ':' . (int) $producto['id'] . ':stock';
        $valores = \Classes\DatosExternos::resolver([$refProducto]);
        $prueba('resuelve la existencia de un producto', ($valores[$refProducto] ?? null) === (string) (int) $producto['stock'], json_encode($valores));
    }
}

echo "Redactor\n";
$sucio = '<p onclick="x()">Hola <b>mundo</b></p><script>alert(1)</script>';
$limpio = Redactor::limpiar($sucio);
$prueba('limpia scripts y eventos', !str_contains($limpio, 'script') && !str_contains($limpio, 'onclick') && str_contains($limpio, 'mundo'));
$d = new RedactorDocumento(['nombre' => 'T23 Doc', 'fecha' => date('Y-m-d'), 'contenido' => '<h1>Título</h1><p>Texto de prueba</p>']);
$d->id = $d->guardar()['id'];
$prueba('crea el documento', (int) $d->id > 0);
RedactorDocumento::guardarVersion((int) $d->id, '<p>v1</p>', 'prueba');
$prueba('guarda versión', (int) $uno("SELECT COUNT(*) FROM redactor_versiones WHERE documento_id=" . (int) $d->id) === 1);
$d = RedactorDocumento::find($d->id);
foreach (['pdf' => '%PDF', 'docx' => 'PK', 'odt' => 'PK'] as $f => $firma) {
    try {
        $bin = $f === 'pdf' ? Redactor::pdf($d) : Redactor::exportar($d, $f);
        $prueba("exporta $f", str_starts_with($bin, $firma) && strlen($bin) > 500);
    } catch (\Throwable $e) {
        $prueba("exporta $f", false, $e->getMessage());
    }
}
$prueba('texto plano', str_contains(Redactor::textoPlano($d->contenido), 'Texto de prueba'));

echo "Editor\n";
$e = new EditorDocumento(['nombre' => 'T23 Lámina']);
$errores = method_exists($e, 'validar') ? $e->validar() : [];
$prueba('valida sin errores', !$errores, implode('|', $errores));

$db->query("DELETE FROM sheetflow_hojas WHERE nombre LIKE 'T23 %'");
$db->query("DELETE FROM redactor_documentos WHERE nombre LIKE 'T23 %'");
$db->query("DELETE FROM editor_documentos WHERE nombre LIKE 'T23 %'");

echo $fallos ? "\nResultado: $fallos fallos\n" : "\nResultado: TODO OK\n";
exit($fallos ? 1 : 0);
