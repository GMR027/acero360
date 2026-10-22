<?php
/**
 * views/admin/editor/api/pdf.php — Genera el PDF a partir de las láminas.
 *
 * Recibe `paginas`: un arreglo JSON con un PNG en dataURL por lámina (2×).
 * Guarda esos PNG como vista previa de cada lámina y compone el PDF con
 * Dompdf al tamaño exacto de la página (px a 96 dpi → puntos).
 */

soloAdmin();

use Classes\Imagenes;
use Classes\Pdf;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\EditorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

$paginas = json_decode((string) ($_POST['paginas'] ?? ''), true);
$laminas = $documento->laminas();

if (!is_array($paginas) || count($paginas) !== count($laminas)) {
    json_respuesta(['success' => false, 'error' => 'Guarda el documento antes de exportar'], 422);
}

[$anchoPx, $altoPx] = $documento->medidas();
$anchoPt = round($anchoPx * 0.75, 2);
$altoPt = round($altoPx * 0.75, 2);

$rutas = [];
foreach ($paginas as $i => $data) {
    if (!is_string($data) || !preg_match('#^data:image/png;base64,#', $data)) {
        json_respuesta(['success' => false, 'error' => 'Una lámina no se pudo convertir'], 422);
    }
    $bin = base64_decode(substr($data, strlen('data:image/png;base64,')), true);
    if ($bin === false || strlen($bin) > 25 * 1024 * 1024) {
        json_respuesta(['success' => false, 'error' => 'Una lámina es demasiado grande'], 422);
    }
    $info = @getimagesizefromstring($bin);
    if (!$info || $info[2] !== IMAGETYPE_PNG) {
        json_respuesta(['success' => false, 'error' => 'Una lámina no es un PNG válido'], 422);
    }

    $archivo = bin2hex(random_bytes(16)) . '.png';
    if (@file_put_contents(Imagenes::CARPETA_VPLAN . '/' . $archivo, $bin) === false) {
        json_respuesta(['success' => false, 'error' => 'No se pudo guardar una lámina'], 500);
    }

    $lamina = $laminas[$i];
    $viejo = $lamina->marcas_png;
    $lamina->marcas_png = $archivo;
    $lamina->guardar();
    if ($viejo && $viejo !== $archivo) {
        Imagenes::eliminarVplan($viejo);
    }

    $rutas[] = Imagenes::CARPETA_VPLAN . '/' . $archivo;
}

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
    . '@page { margin: 0; size: ' . $anchoPt . 'pt ' . $altoPt . 'pt; }'
    . 'html, body { margin: 0; padding: 0; }'
    . '.pagina { width: ' . $anchoPt . 'pt; height: ' . $altoPt . 'pt; page-break-after: always; overflow: hidden; }'
    . '.pagina:last-child { page-break-after: auto; }'
    . 'img { display: block; width: 100%; height: 100%; }'
    . '</style></head><body>';

foreach ($rutas as $ruta) {
    $html .= '<div class="pagina"><img src="' . htmlspecialchars($ruta, ENT_QUOTES, 'UTF-8') . '"></div>';
}
$html .= '</body></html>';

try {
    @set_time_limit(180);
    $pdf = Pdf::generar($html, [0, 0, $anchoPt, $altoPt], 'portrait', [
        'chroot' => realpath(__DIR__ . '/../../../../'),
        'isRemoteEnabled' => false,
        'isHtml5ParserEnabled' => true,
    ]);
} catch (\Throwable $e) {
    error_log('Editor PDF: ' . $e->getMessage());
    json_respuesta(['success' => false, 'error' => 'No se pudo generar el PDF'], 500);
}

header_remove('Content-Type');
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store');
echo $pdf;
exit;
