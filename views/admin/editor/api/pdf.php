<?php
/**
 * views/admin/editor/api/pdf.php — Genera el PDF a partir de las láminas.
 *
 * Dos caminos:
 *
 *   · `reusar=1` (sin `paginas`): si las imágenes de alta resolución ya
 *     generadas siguen vigentes, se componen el PDF con ellas y listo. Es lo
 *     que hace que repetir la exportación de un documento sin cambios salga
 *     de inmediato, sin volver a dibujar cada lámina en el navegador.
 *   · `paginas`: un arreglo JSON con un PNG en dataURL por lámina. Se guardan
 *     en `marcas_full` (imagen de alta resolución para las descargas) y se
 *     compone el PDF con Dompdf al tamaño exacto de la página (px a 96 dpi →
 *     puntos). La miniatura `marcas_png` no se toca aquí.
 *
 * Para decidir si `marcas_full` sigue vigente, el nombre del archivo empieza
 * con una huella del contenido del documento (nombre, fondo y JSON de cada
 * lámina): cualquier cambio hace que la huella cambie y obligue a volver a
 * dibujar. No se comparan fechas porque el reloj de la base y el de PHP
 * pueden no coincidir.
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

$laminas = $documento->laminas();
if (!$laminas) {
    json_respuesta(['success' => false, 'error' => 'El documento aún no tiene láminas'], 422);
}

[$anchoPx, $altoPx] = $documento->medidas();
$anchoPt = round($anchoPx * 0.75, 2);
$altoPt = round($altoPx * 0.75, 2);

/**
 * Huella de lo que se ve en el documento: el nombre, el fondo y el JSON de
 * cada lámina. Si cambia cualquier cosa, cambia la huella.
 *
 * Se usa en lugar de comparar fechas porque el reloj de la base y el de PHP
 * pueden no coincidir (aquí van con una hora de diferencia) y una imagen
 * vieja pasaría por buena. La huella no depende de relojes: o el contenido es
 * el mismo o no lo es.
 */
$huella = static function (\Model\EditorDocumento $documento, array $laminas): string {
    $partes = [
        'nombre'   => (string) $documento->nombre,
        'fondo'    => (string) $documento->fondo,
        'color'    => (string) $documento->fondo_color,
        'config'   => $documento->configFondo(),
        'medidas'  => $documento->medidas(),
    ];
    foreach ($laminas as $lamina) {
        $partes['laminas'][] = [
            (int) $lamina->orden,
            (string) $lamina->nombre,
            (string) $lamina->marcas_json,
        ];
    }

    return substr(md5(json_encode($partes)), 0, 16);
};

$versionDoc = $huella($documento, $laminas);

/** Rutas absolutas de las imágenes de alta resolución, o null si alguna ya no sirve. */
$reutilizables = static function (array $laminas, int $anchoPx, string $versionDoc): ?array {
    $minimo = (int) round($anchoPx * 0.9);
    $rutas = [];

    foreach ($laminas as $lamina) {
        $archivo = (string) ($lamina->marcas_full ?? '');
        // El nombre del archivo empieza con la huella del contenido que
        // representa: si no coincide con la de ahora, la imagen es de una
        // versión anterior del documento y hay que volver a dibujarla.
        if ($archivo === '' || !str_starts_with($archivo, $versionDoc . '-')) {
            return null;
        }
        $ruta = Imagenes::CARPETA_VPLAN . '/' . $archivo;
        $info = is_file($ruta) ? @getimagesize($ruta) : null;
        if (!$info || $info[0] < $minimo) {
            return null;
        }
        $rutas[] = $ruta;
    }

    return $rutas;
};

if (!empty($_POST['reusar'])) {
    $rutas = $reutilizables($laminas, $anchoPx, $versionDoc);
    if ($rutas === null) {
        // El cliente vuelve a dibujar las láminas y repite la petición.
        json_respuesta(['success' => false, 'reuso' => false, 'error' => 'Las láminas cambiaron'], 409);
    }
} else {
    $paginas = json_decode((string) ($_POST['paginas'] ?? ''), true);

    if (!is_array($paginas) || count($paginas) !== count($laminas)) {
        json_respuesta(['success' => false, 'error' => 'Guarda el documento antes de exportar'], 422);
    }

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

        // El nombre lleva delante la huella del contenido que la imagen
        // representa: así el siguiente exporte sabe, sin mirar relojes, si la
        // imagen sigue correspondiendo al documento actual.
        $archivo = $versionDoc . '-' . bin2hex(random_bytes(8)) . '.png';
        if (@file_put_contents(Imagenes::CARPETA_VPLAN . '/' . $archivo, $bin) === false) {
            json_respuesta(['success' => false, 'error' => 'No se pudo guardar una lámina'], 500);
        }

        $lamina = $laminas[$i];
        $viejo = $lamina->marcas_full;
        $lamina->marcas_full = $archivo;
        $lamina->guardar();
        if ($viejo && $viejo !== $archivo) {
            Imagenes::eliminarVplan($viejo);
        }

        $rutas[] = Imagenes::CARPETA_VPLAN . '/' . $archivo;
    }
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
