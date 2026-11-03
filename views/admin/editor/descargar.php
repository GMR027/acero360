<?php
/**
 * views/admin/editor/descargar.php — PDF a partir de la imagen de alta
 * resolución de cada lámina (`marcas_full`).
 *
 * `marcas_png` es solo la miniatura del listado (0.3×); usarla aquí estiraba
 * una imagen de ~244 px a toda la hoja y el PDF salía pixelado. Si una lámina
 * nunca se exportó (o solo tiene la miniatura), se manda al editor para
 * generarla desde el lienzo.
 */

soloAdmin();

use Classes\Imagenes;
use Classes\Pdf;

$documento = \Model\EditorDocumento::find((int) ($_GET['id'] ?? 0));
if (!$documento) {
    redirigir('/admin/editor', 'error', 'El documento no existe o fue eliminado.');
}

$laminas = $documento->laminas();
if (!$laminas) {
    redirigir('/admin/editor/abrir?id=' . (int) $documento->id, 'error', 'El documento aún no tiene láminas.');
}

[$anchoPx, $altoPx] = $documento->medidas();

/* Elige la imagen de exportación y comprueba que tenga resolución suficiente:
   al menos el tamaño del lienzo a 96 dpi (las miniaturas son 0.3×). */
$archivos = [];
foreach ($laminas as $l) {
    $archivo = !empty($l->marcas_full) ? $l->marcas_full : (!empty($l->marcas_png) ? $l->marcas_png : null);
    $ruta = $archivo ? Imagenes::CARPETA_VPLAN . '/' . $archivo : null;
    $info = ($ruta && is_file($ruta)) ? @getimagesize($ruta) : null;
    if (!$info || $info[0] < (int) round($anchoPx * 0.9)) {
        redirigir('/admin/editor/abrir?id=' . (int) $documento->id, 'advertencia', 'Exporta el PDF desde el editor una vez para generar las imágenes de alta resolución de las láminas.');
    }
    $archivos[] = $archivo;
}

$anchoPt = round($anchoPx * 0.75, 2);
$altoPt = round($altoPx * 0.75, 2);

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
    . '@page { margin: 0; size: ' . $anchoPt . 'pt ' . $altoPt . 'pt; }'
    . 'html, body { margin: 0; padding: 0; }'
    . '.pagina { width: ' . $anchoPt . 'pt; height: ' . $altoPt . 'pt; page-break-after: always; overflow: hidden; }'
    . '.pagina:last-child { page-break-after: auto; }'
    . 'img { display: block; width: 100%; height: 100%; }'
    . '</style></head><body>';

foreach ($archivos as $archivo) {
    $html .= '<div class="pagina"><img src="'
        . htmlspecialchars(Imagenes::CARPETA_VPLAN . '/' . $archivo, ENT_QUOTES, 'UTF-8')
        . '"></div>';
}
$html .= '</body></html>';

try {
    @set_time_limit(180);
    $pdf = Pdf::generar($html, [0, 0, $anchoPt, $altoPt], 'portrait', [
        'chroot' => realpath(__DIR__ . '/../../../'),
        'isRemoteEnabled' => false,
        'isHtml5ParserEnabled' => true,
    ]);
} catch (\Throwable $e) {
    error_log('Editor PDF: ' . $e->getMessage());
    redirigir('/admin/editor/ver?id=' . (int) $documento->id, 'error', 'No se pudo generar el PDF.');
}

enviar_descarga($pdf, 'application/pdf', date('Y_m_d') . '_' . slug_archivo($documento->nombre) . '.pdf', !empty($_GET['inline']));
