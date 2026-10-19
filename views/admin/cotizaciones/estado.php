<?php
/**
 * views/admin/cotizaciones/estado.php — Cambio de estado comercial.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 * Se usa desde el detalle de la cotización para marcarla como vendida,
 * pendiente o cancelada sin abrir el formulario de edición.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id     = (int) ($_POST['id'] ?? 0);
$estado = (string) ($_POST['estado'] ?? '');

$previa = \App\Cotizaciones::buscarID($id);

if (!$previa) {
    redirigir('/admin/cotizaciones', 'error', 'La cotización seleccionada ya no existe.');
}

if (!in_array($estado, \App\Cotizaciones::ESTADOS, true)) {
    redirigir('/admin/cotizaciones/detalle?id=' . $id, 'error', 'Estado no reconocido.');
}

if (\App\Cotizaciones::cambiarEstado($id, $estado)) {
    $etiqueta = \App\Cotizaciones::datosEstado($estado)['etiqueta'];
    redirigir(
        '/admin/cotizaciones/detalle?id=' . $id,
        'exito',
        'Cotización ' . $previa->folio . ' marcada como ' . mb_strtolower($etiqueta) . '.'
    );
}

redirigir(
    '/admin/cotizaciones/detalle?id=' . $id,
    'error',
    \App\Cotizaciones::getErrores()[0] ?? 'No fue posible cambiar el estado.'
);
