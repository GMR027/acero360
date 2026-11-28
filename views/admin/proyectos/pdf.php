<?php
/**
 * views/admin/proyectos/pdf.php — PDF del estado de un proyecto.
 */

soloAdmin();

$id = (int) ($_GET['id'] ?? 0);
$proyecto = \App\ProyectosEntrega::buscarID($id);

if (!$proyecto) {
    paginaNoEncontrada();
}

$progreso = \App\ProyectosEntrega::progreso($id);

\App\Documentos::descargarProyectoEntrega($proyecto, $progreso);
