<?php
/**
 * views/admin/entregas-seguimiento/acta.php — Acta de entrega en PDF.
 *
 * Con ?id=<venta> exporta el acta de todas las entregas de la venta; con
 * &entrega=<id> exporta sólo esa entrega. Sólo lee: no cambia estados,
 * existencias ni incidencias.
 */

soloAdmin();

\App\Documentos::descargarActaEntrega(
    (int) ($_GET['id'] ?? 0),
    (int) ($_GET['entrega'] ?? 0)
);
