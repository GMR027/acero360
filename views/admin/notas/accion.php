<?php
/**
 * views/admin/notas/accion.php — Acciones sobre una nota ya creada.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 * Acepta tres acciones declaradas en un campo oculto "accion":
 *
 *   fijar      → alterna el fijado en el tablero
 *   completar  → alterna el estado de hecha
 *   eliminar   → borra la nota (con confirmación modal en el cliente)
 *
 * La bitácora es única y compartida: cualquier administrador puede
 * gestionar cualquier nota.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id     = (int) ($_POST['id'] ?? 0);
$accion = $_POST['accion'] ?? '';

$nota = \App\Notas::buscarID($id);

if (!$nota) {
    redirigir('/admin/notas', 'error', 'La nota seleccionada ya no existe.');
}

switch ($accion) {
    case 'fijar':
        $campo = 'fijada';
        $hecho = \App\Notas::alternar($id, $campo);
        $mensaje = $hecho
            ? ((int) $nota->fijada === 1 ? 'Nota desfijada del tablero.' : 'Nota fijada en la parte superior.')
            : 'No fue posible actualizar la nota.';
        break;

    case 'completar':
        $campo = 'completada';
        $hecho = \App\Notas::alternar($id, $campo);
        $mensaje = $hecho
            ? ((int) $nota->completada === 1 ? 'Nota reabierta.' : 'Nota marcada como hecha.')
            : 'No fue posible actualizar la nota.';
        break;

    case 'eliminar':
        $hecho = $nota->eliminar();
        $mensaje = $hecho
            ? 'Nota eliminada de la bitácora.'
            : (\App\Notas::getErrores()[0] ?? 'No fue posible eliminar la nota.');
        break;

    default:
        $hecho = false;
        $mensaje = 'Acción no reconocida.';
        break;
}

redirigir('/admin/notas', $hecho ? 'exito' : 'error', $mensaje);
