<?php
/**
 * views/admin/editor/descargar.php — PDF a partir de las miniaturas guardadas.
 *
 * Si alguna lámina no tiene PNG (nunca se exportó), se manda al editor para
 * generarlo desde el lienzo.
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

foreach ($laminas as $l) {
    if (empty($l->marcas_png) || !is_file(Imagenes::CARPETA_VPLAN . '/' . $l->marcas_png)) {
        redirigir('/admin/editor/abrir?id=' . (int) $documento->id, 'advertencia', 'Exporta el PDF desde el editor para generar la vista de las láminas.');
    }
}

[$anchoPx, $altoPx] = $documento->medidas();
$anchoPt = round($anchoPx * 0.75, 2);
$altoPt = round($altoPx * 0.75, 2);

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
    . '@page { margin: 0; size: ' . $anchoPt . 'pt ' . $altoPt . 'pt; }'
    . 'html, body { margin: 0; padding: 0; }'
    . '.pagina { width: ' . $anchoPt . 'pt; height: ' . $altoPt . 'pt; page-break-after: always; overflow: hidden; }'
    . '.pagina:last-child { page-break-after: auto; }'
    . 'img { display: block; width: 100%; height: 100%; }'
    . '</style></head><body>';

foreach ($laminas as $l) {
    $html .= '<div class="pagina"><img src="'
        . htmlspecialchars(Imagenes::CARPETA_VPLAN . '/' . $l->marcas_png, ENT_QUOTES, 'UTF-8')
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
