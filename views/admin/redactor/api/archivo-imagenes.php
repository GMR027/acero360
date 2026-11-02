<?php
/** views/admin/redactor/api/archivo-imagenes.php — Imágenes del Archivo insertables en el Redactor. */

soloAdmin();

use Model\Archivo;

$busqueda = trim((string) ($_GET['q'] ?? ''));
$limite = (int) ($_GET['limite'] ?? 300);

json_respuesta([
    'success' => true,
    'imagenes' => Archivo::imagenesInsertables($busqueda, $limite),
]);
