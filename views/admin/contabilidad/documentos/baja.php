<?php
/**
 * views/admin/contabilidad/documentos/baja.php — Da de baja un documento con motivo (solo POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$retorno = (string) ($_POST['retorno'] ?? '');
if (strpos($retorno, '/admin/contabilidad') !== 0 || strpos($retorno, '//') !== false) {
    $retorno = '/admin/contabilidad/documentos';
}

if (\App\Archivos::baja((int) ($_POST['id'] ?? 0), (string) ($_POST['motivo'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
    redirigir($retorno . '#documentos', 'exito', 'Documento dado de baja. El archivo se conserva en el almacén.');
}

redirigir($retorno . '#documentos', 'error', \App\Archivos::getErrores()[0] ?? 'No fue posible dar de baja el documento.');
