<?php
/**
 * views/admin/cotizaciones/duplicar.php — Duplicación de una cotización.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 *
 * La copia nace con folio nuevo, fecha de hoy y estado "pendiente": se trata
 * de un borrador de trabajo para que el usuario sólo cambie productos,
 * cantidades o precios y lo guarde como una cotización nueva. Se conservan
 * el negocio emisor, el cliente, la vigencia, el IVA, el descuento global,
 * las condiciones comerciales y todos los renglones de la original.
 *
 * Los precios especiales del cliente NO se tocan: se reutilizan tal cual los
 * de la cotización origen, que siguen siendo los acordados con esa ficha.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id     = (int) ($_POST['id'] ?? 0);
$origen = \App\Cotizaciones::buscarID($id);

if (!$origen) {
    redirigir('/admin/cotizaciones', 'error', 'La cotización seleccionada ya no existe.');
}

$detalles = \App\Cotizaciones::detalles($id);

/**
 * Los renglones se indexan por producto_id: validar() y guardarDetalles()
 * leen la CLAVE del arreglo como identificador del producto, y
 * lineasDesdeDetalles() devuelve una lista numerada.
 */
$lineas = [];
foreach (\App\Cotizaciones::lineasDesdeDetalles($detalles) as $linea) {
    $lineas[(int) $linea['id']] = $linea;
}

if (!$lineas) {
    redirigir(
        '/admin/cotizaciones',
        'error',
        'La cotización ' . $origen->folio . ' no tiene renglones para duplicar.'
    );
}

/**
 * Descuento global de la copia: se recupera con el mismo cálculo que usa el
 * formulario de edición, de modo que al abrir el borrador se muestre exactamente
 * el porcentaje que ya estaba negociado y los importes no se muevan.
 */
$descuentoPct = \App\Cotizaciones::desgloseDescuento($origen, $detalles)['pct'];

$datos = [
    'empresa_id'         => (int) $origen->empresa_id,
    'cliente_id'         => (int) $origen->cliente_id,
    'fecha'              => date('Y-m-d'),
    'vigencia_dias'      => (int) $origen->vigencia_dias,
    'aplica_iva'         => (int) $origen->aplica_iva ? 1 : 0,
    'descuento_pct'      => $descuentoPct,
    'condiciones_envio'  => (string) $origen->condiciones_envio,
    'condiciones_compra' => (string) $origen->condiciones_compra,
    'comentarios'        => (string) $origen->comentarios,
    'estado'             => 'pendiente',
];

$errores = \App\Cotizaciones::validar($datos, $lineas);

if (empty($errores) && \App\Cotizaciones::crear($datos, $lineas, (int) ($_SESSION['id'] ?? 0))) {
    $nuevoId = (int) \App\Cotizaciones::$ultimoId;
    $nuevo   = \App\Cotizaciones::buscarID($nuevoId);

    redirigir(
        '/admin/cotizaciones/editar?id=' . $nuevoId,
        'exito',
        'Cotización ' . ($nuevo ? $nuevo->folio : '') . ' duplicada de ' . $origen->folio
        . '. Modificá renglones y cantidades y guardá cuando esté lista.'
    );
}

redirigir(
    '/admin/cotizaciones',
    'error',
    \App\Cotizaciones::getErrores()[0] ?? ($errores[0] ?? 'No fue posible duplicar la cotización.')
);
