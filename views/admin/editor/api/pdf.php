<?php
/**
 * views/admin/editor/api/pdf.php — Genera el PDF a partir de las láminas.
 *
 * Dos caminos:
 *
 *   · `reusar=1` (sin `paginas`): si las imágenes de alta resolución ya
 *     generadas siguen vigentes, se componen el PDF con ellas y listo. Si
 *     sólo cambió una parte del documento se responde 409 con los índices
 *     que hay que volver a dibujar (`faltan`), de modo que el navegador no
 *     repinta las láminas que no cambiaron.
 *   · `paginas`: un objeto/arreglo JSON con una imagen en dataURL por lámina.
 *     Puede traer todas o sólo las que faltan. Se guardan en `marcas_full`
 *     (imagen de alta resolución para las descargas) y se compone el PDF con
 *     Dompdf al tamaño exacto de la página (px a 96 dpi → puntos). La
 *     miniatura `marcas_png` no se toca aquí.
 *
 * Las imágenes del navegador llegan como JPEG opaco: Dompdf las embebe sin
 * decodificarlas ni separar el canal alfa, que era la parte más lenta de la
 * exportación anterior (PNG RGBA a 200 dpi).
 *
 * Para decidir si `marcas_full` sigue vigente, el nombre del archivo empieza
 * con una huella del contenido que representa (fondo, medidas y JSON de la
 * lámina): cualquier cambio en esa lámina cambia su huella y obliga a
 * volver a dibujarla. No se comparan fechas porque el reloj de la base y el
 * de PHP pueden no coincidir.
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

$laminas = array_values($documento->laminas());
if (!$laminas) {
    json_respuesta(['success' => false, 'error' => 'El documento aún no tiene láminas'], 422);
}

[$anchoPx, $altoPx] = $documento->medidas();
$anchoPt = round($anchoPx * 0.75, 2);
$altoPt = round($altoPx * 0.75, 2);

/**
 * Huella de lo que se ve en UNA lámina: fondo, medidas y su JSON de Konva.
 * No incluye el nombre del documento ni el de la lámina porque no se pintan:
 * cambiar el título no debe obligar a redibujar todas las páginas.
 */
$huellaLamina = static function (\Model\EditorDocumento $documento, \Model\EditorLamina $lamina): string {
    $partes = [
        'fondo'   => (string) $documento->fondo,
        'color'   => (string) $documento->fondo_color,
        'config'  => $documento->configFondo(),
        'medidas' => $documento->medidas(),
        'lamina'  => [
            (int) $lamina->orden,
            (string) $lamina->marcas_json,
        ],
    ];

    return substr(md5(json_encode($partes)), 0, 16);
};

$hashes = [];
foreach ($laminas as $i => $lamina) {
    $hashes[$i] = $huellaLamina($documento, $lamina);
}

/** Ruta de la imagen de alta resolución vigente para la lámina, o null. */
$rutaReusable = static function (\Model\EditorLamina $lamina, string $hash, int $anchoPx): ?string {
    $archivo = (string) ($lamina->marcas_full ?? '');
    // El nombre del archivo empieza con la huella del contenido que
    // representa: si no coincide con la de ahora, la imagen es de una
    // versión anterior de esa lámina y hay que volver a dibujarla.
    if ($archivo === '' || !str_starts_with($archivo, $hash . '-')) {
        return null;
    }

    $ruta = Imagenes::CARPETA_VPLAN . '/' . $archivo;
    $minimo = (int) round($anchoPx * 0.9);
    $info = is_file($ruta) ? @getimagesize($ruta) : null;

    return ($info && $info[0] >= $minimo) ? $ruta : null;
};

/** Rutas reutilizables por índice; los índices ausentes son los que faltan. */
$rutas = [];
$faltan = [];
foreach ($laminas as $i => $lamina) {
    $ruta = $rutaReusable($lamina, $hashes[$i], $anchoPx);
    if ($ruta === null) {
        $faltan[] = $i;
    } else {
        $rutas[$i] = $ruta;
    }
}

if (!empty($_POST['reusar'])) {
    if ($faltan) {
        // El cliente vuelve a dibujar sólo estas láminas y repite la petición.
        json_respuesta([
            'success' => false,
            'reuso'   => false,
            'faltan'  => $faltan,
            'error'   => 'Algunas láminas cambiaron',
        ], 409);
    }
} else {
    $paginas = json_decode((string) ($_POST['paginas'] ?? ''), true);

    if (!is_array($paginas) || !$paginas) {
        json_respuesta(['success' => false, 'error' => 'Guarda el documento antes de exportar'], 422);
    }

    $entrantes = [];
    foreach ($paginas as $i => $data) {
        $i = (int) $i;
        if ($i < 0 || $i >= count($laminas)) {
            json_respuesta(['success' => false, 'error' => 'Una lámina no corresponde al documento'], 422);
        }
        $entrantes[$i] = $data;
    }

    foreach ($entrantes as $i => $data) {
        if (!is_string($data) || !preg_match('#^data:image/(png|jpeg);base64,#', $data, $tipo)) {
            json_respuesta(['success' => false, 'error' => 'Una lámina no se pudo convertir'], 422);
        }

        $prefijo = 'data:image/' . $tipo[1] . ';base64,';
        $bin = base64_decode(substr($data, strlen($prefijo)), true);
        if ($bin === false || strlen($bin) > 25 * 1024 * 1024) {
            json_respuesta(['success' => false, 'error' => 'Una lámina es demasiado grande'], 422);
        }

        $info = @getimagesizefromstring($bin);
        $esperado = $tipo[1] === 'jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
        if (!$info || $info[2] !== $esperado) {
            json_respuesta(['success' => false, 'error' => 'Una lámina no es una imagen válida'], 422);
        }

        // El nombre lleva delante la huella del contenido que la imagen
        // representa: así el siguiente exporte sabe, sin mirar relojes, si
        // la imagen sigue correspondiendo a la lámina actual.
        $extension = $tipo[1] === 'jpeg' ? 'jpg' : 'png';
        $archivo = $hashes[$i] . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
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

        $rutas[$i] = Imagenes::CARPETA_VPLAN . '/' . $archivo;
    }

    if (count($rutas) !== count($laminas)) {
        json_respuesta(['success' => false, 'error' => 'Falta una lámina por dibujar: vuelve a exportar'], 422);
    }
}

ksort($rutas);

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
