<?php
/** views/admin/redactor/api/sellar.php — Firma electrónicamente el documento guardado. */

soloAdmin();

use Classes\Redactor;
use Classes\RedactorFirmas;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

$usuario = \App\Usuarios::buscarID((int) ($_SESSION['id'] ?? 0));
if (!$usuario) {
    json_respuesta(['success' => false, 'error' => 'No autorizado'], 403);
}

if (Redactor::cambiosPendientes((string) $documento->contenido)) {
    json_respuesta(['success' => false, 'error' => 'Hay cambios sin aceptar o rechazar. Resuélvelos antes de firmar.'], 422);
}

if ($mensaje = RedactorFirmas::sellar($documento, $usuario, (string) ($_POST['cargo'] ?? ''), (string) ($_POST['password'] ?? ''))) {
    json_respuesta(['success' => false, 'error' => $mensaje], 422);
}

json_respuesta(['success' => true, 'sellos' => RedactorFirmas::sellos($documento)]);
