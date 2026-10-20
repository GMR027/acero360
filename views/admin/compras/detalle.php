<?php
/**
 * views/admin/compras/detalle.php — Orden de compra: solicitado vs. recibido,
 * historial de recepciones y acciones (recibir, cerrar, reabrir, cancelar, PDF).
 * Consultar esta pantalla nunca modifica el inventario.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? 0);
$orden = \App\OrdenesCompra::buscarID($id);

if (!$orden) {
    paginaNoEncontrada();
}

$partidas    = \App\OrdenesCompra::partidas($id);
$totales     = \App\OrdenesCompra::totales($partidas);
$recepciones = \App\OrdenesCompra::recepciones($id);
$estado      = \App\OrdenesCompra::ESTADOS[$orden['estado']];
$puedeRecibir = in_array($orden['estado'], ['solicitada', 'parcial'], true);
$puedeCerrar  = in_array($orden['estado'], ['solicitada', 'parcial', 'completa'], true);

$acciones = '<a class="btn btn--secundario" href="/admin/compras/pdf?id=' . $id . '&tipo=orden" target="_blank" rel="noopener">PDF de la orden</a>'
    . '<a class="btn btn--secundario" href="/admin/compras/pdf?id=' . $id . '&tipo=cierre" target="_blank" rel="noopener">Reporte solicitado vs. recibido</a>';

template('header', [
    'titulo'    => 'Orden ' . $orden['folio'],
    'subtitulo' => $orden['proveedor_nombre'],
    'acciones'  => $acciones,
]);
template('tabsCompras', ['tab' => 'ordenes']);
?>

<section class="tarjeta">
    <p><span class="badge <?= $estado['clase'] ?>"><?= escaparValores($estado['etiqueta']) ?></span>
       · Proveedor: <strong><?= escaparValores($orden['proveedor_nombre']) ?></strong>
       · Solicitada el <?= escaparValores(date('d/m/Y', strtotime($orden['fecha_solicitud']))) ?></p>
    <?php if ($orden['estado'] === 'cerrada') : ?>
        <p class="texto-secundario">Cerrada el <?= escaparValores(date('d/m/Y H:i', strtotime($orden['cerrada_en']))) ?>
            <?= $orden['motivo_cierre'] ? '· ' . escaparValores($orden['motivo_cierre']) : '' ?></p>
    <?php endif; ?>
    <?php if ($orden['observaciones']) : ?>
        <p class="texto-secundario"><?= nl2br(escaparValores($orden['observaciones'])) ?></p>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Comparativo solicitado contra recibido</caption>
            <thead><tr>
                <th scope="col">Producto</th>
                <th scope="col" class="tabla--numerica">Solicitado</th>
                <th scope="col" class="tabla--numerica">Recibido</th>
                <th scope="col" class="tabla--numerica">Faltante</th>
                <th scope="col" class="tabla--numerica">Costo esperado</th>
                <th scope="col" class="tabla--numerica">Costo final</th>
                <th scope="col" class="tabla--numerica">Diferencia</th>
            </tr></thead>
            <tbody>
                <?php foreach ($partidas as $p) : ?>
                    <tr>
                        <td><strong><?= escaparValores($p['producto_nombre']) ?></strong>
                            <span class="texto-xs texto-secundario"><br><?= escaparValores($p['producto_sku'] ?? '') ?>
                            <?= $p['descripcion'] ? ' · ' . escaparValores($p['descripcion']) : '' ?></span></td>
                        <td class="tabla--numerica cifra"><?= $p['solicitada'] ?></td>
                        <td class="tabla--numerica cifra"><?= $p['recibida'] ?></td>
                        <td class="tabla--numerica cifra">
                            <?= $p['pendiente'] > 0 ? '<strong>' . $p['pendiente'] . '</strong>' : ($p['pendiente'] < 0 ? '+' . abs($p['pendiente']) . ' excedente' : '0') ?>
                        </td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($p['costo_esperado']) ?></td>
                        <td class="tabla--numerica cifra"><?= $p['costo_final'] !== null ? formatoMoneda($p['costo_final']) : '—' ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($p['diferencia']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <td><strong>Totales</strong></td>
                <td class="tabla--numerica cifra"><?= $totales['piezas_solicitadas'] ?></td>
                <td class="tabla--numerica cifra"><?= $totales['piezas_recibidas'] ?></td>
                <td class="tabla--numerica cifra"><?= $totales['piezas_pendientes'] ?></td>
                <td class="tabla--numerica cifra"><?= formatoMoneda($totales['esperado']) ?></td>
                <td class="tabla--numerica cifra"><?= formatoMoneda($totales['recibido']) ?></td>
                <td class="tabla--numerica cifra"><?= formatoMoneda($totales['diferencia']) ?></td>
            </tr></tfoot>
        </table>
    </div>
</section>

<section class="tarjeta">
    <div class="encabezado-pagina__acciones">
        <?php if ($puedeRecibir) : ?>
            <a class="btn btn--primario" href="/admin/compras/recibir?id=<?= $id ?>">Registrar recepción</a>
        <?php endif; ?>
        <?php if ($orden['estado'] === 'solicitada') : ?>
            <a class="btn btn--secundario" href="/admin/compras/editar?id=<?= $id ?>">Editar</a>
            <form action="/admin/compras/accion" method="post" data-validar
                  data-confirmacion-sobre="Orden <?= escaparValores($orden['folio']) ?>"
                  data-confirmacion="¿Cancelar esta orden?"
                  data-confirmacion-mensaje="La orden quedará cancelada. No se ha recibido mercancía."
                  data-confirmacion-boton="Sí, cancelar">
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cancelar">
                <button class="btn btn--peligro" type="submit">Cancelar orden</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($puedeCerrar) : ?>
        <form class="formulario mt-lg" action="/admin/compras/accion" method="post" data-validar
              data-confirmacion-sobre="Orden <?= escaparValores($orden['folio']) ?>"
              data-confirmacion="¿Cerrar la orden?"
              data-confirmacion-mensaje="<?= $totales['piezas_pendientes'] > 0
                  ? 'Hay ' . $totales['piezas_pendientes'] . ' pieza(s) sin recibir; quedarán como faltantes. Podrás reabrirla solo con un motivo.'
                  : 'Después de cerrar, solo se puede corregir reabriéndola con un motivo (queda auditado).' ?>"
              data-confirmacion-boton="Sí, cerrar">
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cerrar">
            <div class="campo">
                <label class="campo__etiqueta" for="motivo_cierre">Nota de cierre (opcional)</label>
                <input class="control" type="text" id="motivo_cierre" name="motivo" maxlength="255"
                       placeholder="Ej. El proveedor no surtió el resto">
            </div>
            <button class="btn btn--secundario" type="submit">Cerrar orden<?= $totales['piezas_pendientes'] > 0 ? ' con faltantes' : '' ?></button>
        </form>
    <?php endif; ?>

    <?php if ($orden['estado'] === 'cerrada') : ?>
        <form class="formulario" action="/admin/compras/accion" method="post" data-validar>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="reabrir">
            <div class="campo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="motivo_reapertura">Motivo para reabrir (queda auditado)</label>
                <input class="control" type="text" id="motivo_reapertura" name="motivo" maxlength="255" required minlength="5">
            </div>
            <button class="btn btn--secundario" type="submit">Reabrir orden</button>
        </form>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Historial de recepciones</h2>
    <?php if (!$recepciones) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no se ha recibido mercancía.</p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr><th scope="col">Fecha</th><th scope="col">Producto</th>
                    <th scope="col" class="tabla--numerica">Cantidad</th><th scope="col" class="tabla--numerica">Costo unitario</th>
                    <th scope="col">Registró</th></tr></thead>
                <tbody>
                    <?php foreach ($recepciones as $r) : foreach ($r['renglones'] as $l) : ?>
                        <tr>
                            <td><?= escaparValores(date('d/m/Y', strtotime($r['fecha']))) ?></td>
                            <td><?= escaparValores($l['producto_nombre']) ?></td>
                            <td class="tabla--numerica cifra"><?= (int) $l['cantidad_recibida'] ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($l['costo_unitario']) ?></td>
                            <td><?= escaparValores($r['usuario_nombre'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
