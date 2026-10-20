<?php
/**
 * views/admin/contabilidad/documentos/subir.php — Sube o sustituye un documento (solo POST).
 * La validación real (contenido, tamaño, XML, duplicados) está en App\Archivos.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

/** Solo rutas internas del módulo (sin esquemas ni //). */
$retorno = (string) ($_POST['retorno'] ?? '');
if (strpos($retorno, '/admin/contabilidad') !== 0 || strpos($retorno, '//') !== false) {
    $retorno = '/admin/contabilidad/documentos';
}

if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($retorno, 'error', 'Este formulario ya se envió; no se adjuntó el archivo dos veces.');
}

$reemplaza = (int) ($_POST['reemplaza'] ?? 0) ?: null;
$entidad   = (string) ($_POST['entidad'] ?? '');
$entidadId = (int) ($_POST['entidad_id'] ?? 0);
$usuario   = (int) ($_SESSION['id'] ?? 0);

// Uno o varios archivos (facturas PDF, XML, fotos) en un solo envío.
$archivos = \App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null);
if (!$archivos) {
    redirigir($retorno . '#documentos', 'error', 'Elige al menos un archivo.');
}

$subidos = 0;
$fallos  = [];
foreach ($archivos as $archivo) {
    if (\App\Archivos::subir($entidad, $entidadId, $archivo, (string) ($_POST['tipo'] ?? 'auto'), $usuario, $reemplaza, (string) ($_POST['motivo'] ?? ''))) {
        $subidos++;
    } else {
        $fallos[] = $archivo['name'] . ': ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
    }
}

if ($fallos) {
    redirigir($retorno . '#documentos', 'error', ($subidos ? "Se adjuntaron $subidos; " : '') . implode(' · ', $fallos));
}
redirigir($retorno . '#documentos', 'exito', $reemplaza ? 'Documento sustituido; el anterior se conserva como histórico.' : ($subidos === 1 ? 'Documento adjuntado.' : "$subidos documentos adjuntados."));
