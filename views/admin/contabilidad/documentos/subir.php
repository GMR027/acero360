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

if (\App\Archivos::subir(
    (string) ($_POST['entidad'] ?? ''), (int) ($_POST['entidad_id'] ?? 0), $_FILES['archivo'] ?? [],
    (string) ($_POST['tipo'] ?? 'otro'), (int) ($_SESSION['id'] ?? 0), $reemplaza, (string) ($_POST['motivo'] ?? '')
)) {
    redirigir($retorno . '#documentos', 'exito', $reemplaza ? 'Documento sustituido; el anterior se conserva como histórico.' : 'Documento adjuntado.');
}

redirigir($retorno . '#documentos', 'error', \App\Archivos::getErrores()[0] ?? 'No fue posible adjuntar el archivo.');
