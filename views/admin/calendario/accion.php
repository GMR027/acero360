<?php
/**
 * views/admin/calendario/accion.php — Acciones sobre un evento ya creado.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 * Acepta la acción declarada en un campo oculto "accion":
 *
 *   eliminar  → borra el evento (con confirmación modal en el cliente)
 *
 * El calendario es compartido: cualquier administrador gestiona los eventos
 * de todos. El campo «destino» regresa al mes y al día desde donde se actuó
 * y sólo se admite una ruta del propio módulo (nunca un sitio externo).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id     = (int) ($_POST['id'] ?? 0);
$accion = $_POST['accion'] ?? 'eliminar';

/** Regreso seguro: sólo rutas del calendario (evita redirigir a otro sitio). */
$destino = (string) ($_POST['destino'] ?? '');
if (strpos($destino, '/admin/calendario') !== 0) {
    $destino = '/admin/calendario';
}

$evento = \App\EventosCalendario::buscarID($id);

if (!$evento) {
    redirigir('/admin/calendario', 'error', 'El evento seleccionado ya no existe.');
}

switch ($accion) {
    case 'eliminar':
        $hecho    = $evento->eliminar();
        $mensaje  = $hecho
            ? 'Evento eliminado del calendario.'
            : (\App\EventosCalendario::getErrores()[0] ?? 'No fue posible eliminar el evento.');
        $destinoRegreso = $hecho ? $destino : '/admin/calendario';
        break;

    default:
        $hecho         = false;
        $mensaje       = 'Acción no reconocida.';
        $destinoRegreso = '/admin/calendario';
        break;
}

redirigir($destinoRegreso, $hecho ? 'exito' : 'error', $mensaje);
