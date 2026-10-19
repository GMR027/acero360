<?php
/**
 * views/admin/fiscal/catalogo/accion.php — importar, validar y activar versiones del catálogo (solo POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$accion  = (string) ($_POST['accion'] ?? '');
$usuario = (int) ($_SESSION['id'] ?? 0);
$id      = (int) ($_POST['id'] ?? 0);

if ($accion === 'importar') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/fiscal/catalogo', 'error', 'Este formulario ya se envió; no se importó dos veces.');
    }

    $archivo = $_FILES['archivo'] ?? [];
    $nombre  = strtolower((string) pathinfo((string) ($archivo['name'] ?? ''), PATHINFO_EXTENSION));
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $nombre !== 'json' || !is_uploaded_file((string) $archivo['tmp_name'])) {
        redirigir('/admin/fiscal/catalogo', 'error', 'Selecciona un archivo .json válido.');
    }
    if (filesize($archivo['tmp_name']) > 2 * 1048576) {
        redirigir('/admin/fiscal/catalogo', 'error', 'El archivo excede 2 MB.');
    }

    if (\App\CatalogoFiscal::importar((string) file_get_contents($archivo['tmp_name']), $usuario)) {
        redirigir('/admin/fiscal/catalogo/detalle?id=' . \App\CatalogoFiscal::$ultimoId, 'exito', 'Versión importada como borrador. Valídala antes de activarla.');
    }
    redirigir('/admin/fiscal/catalogo', 'error', \App\CatalogoFiscal::getErrores()[0] ?? 'No fue posible importar el archivo.');
}

$destino = '/admin/fiscal/catalogo/detalle?id=' . $id;

if ($accion === 'validar') {
    if (\App\CatalogoFiscal::validar($id, $usuario)) {
        redirigir($destino, 'exito', 'Versión validada sin errores.');
    }
    redirigir($destino, 'error', 'La validación encontró errores; revísalos abajo.');
}

if ($accion === 'activar') {
    if (\App\CatalogoFiscal::activar($id, $usuario, (string) ($_POST['contrasena'] ?? ''), (string) ($_POST['motivo'] ?? ''))) {
        redirigir($destino, 'exito', 'Versión activada. La anterior se conservó como «reemplazada».');
    }
    redirigir($destino, 'error', \App\CatalogoFiscal::getErrores()[0] ?? 'No fue posible activar la versión.');
}

redirigir('/admin/fiscal/catalogo', 'error', 'Acción no reconocida.');
