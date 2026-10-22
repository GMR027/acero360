<?php
/** views/admin/redactor/plantilla-eliminar.php — Borra una plantilla propia. */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('/admin/redactor');
}

$id = (int) ($_POST['eliminar_plantilla'] ?? 0);
if ($id) {
    \Classes\RedactorPlantillas::eliminar($id);
}

redirigir('/admin/redactor/crear', 'exito', 'Plantilla eliminada.');
