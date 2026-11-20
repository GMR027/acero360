<?php
/**
 * views/admin/ventas/detalle.php — Detalle de una venta.
 *
 * Desde la reestructuración de Operación Comercial esta ruta muestra el
 * EXPEDIENTE único de la venta (views/admin/ventas/expediente.php): documento,
 * entrega y acta, incidencias, devoluciones, facturación y cobro, e historial.
 *
 * Se conserva la ruta porque es la que enlazan los listados, el panel y los
 * correos; el contenido y las acciones son los mismos que en el seguimiento.
 */

require __DIR__ . '/expediente.php';
