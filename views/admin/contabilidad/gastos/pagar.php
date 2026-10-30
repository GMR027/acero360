<?php
/**
 * views/admin/contabilidad/gastos/pagar.php — Página suelta para registrar el
 * pago de un gasto. Es el respaldo sin JavaScript del modal «Pagar» del
 * detalle: el formulario es el mismo y envía a gastos/accion.
 */

soloAdmin();

$id = (int) ($_GET['id'] ?? 0);
$gasto = \App\Gastos::buscarID($id);

if (!$gasto) {
    paginaNoEncontrada();
}
if ($gasto['estado'] !== 'vigente') {
    redirigir('/admin/contabilidad/gastos/detalle?id=' . $id, 'error', 'Un gasto cancelado no se puede pagar.');
}
if ($gasto['estado_pago'] === 'pagado') {
    redirigir('/admin/contabilidad/gastos/detalle?id=' . $id, 'error', 'El gasto ya está pagado.');
}
// La factura puede venir de un periodo cerrado: se paga con una fecha del
// periodo abierto y Gastos::pagar valida esa fecha, no la de la factura.
if ($orden = \App\CompraContable::ordenDeGasto($id)) {
    redirigir('/admin/compras/detalle?id=' . (int) $orden['id'], 'error', 'Este gasto viene de una compra: el pago se registra en la compra.');
}

template('header', [
    'titulo'    => 'Registrar pago',
    'subtitulo' => 'Gasto: ' . $gasto['beneficiario'],
    'rutaActiva' => '/admin/contabilidad',
]);
?>

<section class="tarjeta">
    <p class="texto-secundario mb-md">
        <?= escaparValores($gasto['concepto']) ?> · <?= date('d/m/Y', strtotime($gasto['fecha'])) ?>
        · total <strong class="cifra"><?= formatoMoneda((float) $gasto['total']) ?></strong>
    </p>
    <?php template('formPagoGasto', [
        'gasto'        => $gasto,
        'token'        => tokenFormulario(),
        'conAcciones'  => true,
        'hrefCancelar' => '/admin/contabilidad/gastos/detalle?id=' . $id,
    ]); ?>
</section>

<?php template('footer'); ?>
