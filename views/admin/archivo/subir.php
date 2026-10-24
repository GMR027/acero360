<?php
/**
 * views/admin/archivo/subir.php — Sube archivos a la carpeta indicada.
 *
 * Sólo POST (formulario multipart con el _csrf que inyecta la aplicación).
 * Cada archivo se valida por extensión y por firma real antes de guardarlo;
 * los que no pasan se anotan y se informa junto al resultado.
 */

soloAdmin();

use Classes\ArchivoAlmacen;
use Model\Archivo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('/admin/archivo');
}

$carpetaId = (int) ($_POST['carpeta_id'] ?? 0);
$volver = '/admin/archivo' . ($carpetaId > 0 ? '?carpeta=' . $carpetaId : '');

$carpeta = null;
if ($carpetaId > 0) {
    $carpeta = \Model\ArchivoCarpeta::find($carpetaId);
    if (!$carpeta) {
        redirigir('/admin/archivo', 'error', 'La carpeta de destino ya no existe.');
    }
}

$lista = \App\Archivos::listaDeSubidas($_FILES['archivos'] ?? null);
if (!$lista) {
    redirigir($volver, 'error', 'Ningún archivo seleccionado.');
}

$usuarioId = (int) ($_SESSION['id'] ?? 0);
$subidos = 0;
$fallidos = [];

foreach ($lista as $archivo) {
    $validado = ArchivoAlmacen::validar($archivo);
    if ($validado === null) {
        $fallidos = array_merge($fallidos, ArchivoAlmacen::errores());
        continue;
    }

    // Mismo archivo ya en esta carpeta: no se duplica.
    $duplicado = Archivo::duplicadoEn($validado['sha256'], $carpetaId ?: null);
    if ($duplicado) {
        $fallidos[] = '«' . ArchivoAlmacen::nombreOriginal($archivo) . '» ya está en esta carpeta («'
            . $duplicado->nombre . '»): no se duplica.';
        continue;
    }

    $guardado = ArchivoAlmacen::guardar($archivo, $validado['ext'], $validado['familia'], $carpetaId ?: null);
    if ($guardado === null) {
        $fallidos = array_merge($fallidos, ArchivoAlmacen::errores());
        continue;
    }

    $registro = new Archivo([
        'carpeta_id'  => $carpetaId ?: null,
        'nombre'      => ArchivoAlmacen::nombreOriginal($archivo),
        'archivo'     => $guardado['archivo'],
        'mime'        => $guardado['mime'],
        'tamano'      => $validado['tamano'],
        'sha256'      => $validado['sha256'],
        'subido_por'  => $usuarioId,
    ]);
    $registro->guardar();
    $subidos++;
}

if ($subidos && !$fallidos) {
    redirigir($volver, 'exito', $subidos === 1
        ? 'Archivo subido.'
        : $subidos . ' archivos subidos.');
}

if ($subidos) {
    redirigir($volver, 'advertencia', $subidos . ' archivo(s) subido(s). No se pudieron subir: '
        . implode(' · ', array_slice($fallidos, 0, 3)) . (count($fallidos) > 3 ? '…' : ''));
}

redirigir($volver, 'error', implode(' · ', array_slice($fallidos, 0, 3)) ?: 'No se pudo subir ningún archivo.');
