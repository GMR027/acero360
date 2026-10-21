<?php
/**
 * views/admin/compras/pagos.php — Pagos a proveedores: compras con saldo pendiente y pagos realizados.
 * Solo consulta; los pagos se registran en el detalle de cada compra (así quedan ligados a ella y no crean gastos).
 */

soloAdmin();

$filtros = [
    'proveedor' => (int) ($_GET['proveedor'] ?? 0),
    'desde'     => $_GET['desde'] ?? '',
    'hasta'     => $_GET['hasta'] ?? '',
];
$conSaldo = \App\OrdenesCompra::listar(['pago' => 'con_saldo', 'proveedor' => $filtros['proveedor']], 100, 0);
$tot      = \App\OrdenesCompra::totalesFiltrados(['proveedor' => $filtros['proveedor'], 'activas' => true]);
$pagos    = \App\PagosCompra::listar($filtros + ['estado' => ''], 100, 0);
$proveedores = \App\Proveedores::paraSelector(true);
$parametros = array_filter($filtros, static fn ($v) => $v !== '' && $v !== 0);

template('header', ['titulo' => 'Compras y proveedores', 'subtitulo' => 'Pagos a proveedores']);
template('tabsCompras', ['tab' => 'pagos']);
?>

<section class="rejilla-stats mb-lg" aria-label="Resumen de pagos">
    <article class="stat"><span class="stat__etiqueta">Comprado</span>
        <span class="stat__valor cifra"><?= formatoMoneda($tot['comprado']) ?></span><span class="stat__pie">Sin compras canceladas</span></article>
    <article class="stat"><span class="stat__etiqueta">Pagado</span>
        <span class="stat__valor cifra"><?= formatoMoneda($tot['pagado']) ?></span><span class="stat__pie">Pagos vigentes</span></article>
    <article class="stat"><span class="stat__etiqueta">Saldo pendiente</span>
        <span class="stat__valor cifra"><?= formatoMoneda($tot['saldo']) ?></span><span class="stat__pie">Comprado menos pagado</span></article>
</section>

<form class="buscador mb-md" action="/admin/compras/pagos" method="get" role="search">
    <label class="solo-lectores" for="proveedor">Proveedor</label>
    <select class="control control--compacto" id="proveedor" name="proveedor">
        <option value="0">Todos los proveedores</option>
        <?php foreach ($proveedores as $p) : ?>
            <option value="<?= $p['id'] ?>"<?= $filtros['proveedor'] === $p['id'] ? ' selected' : '' ?>><?= escaparValores($p['etiqueta']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="solo-lectores" for="desde">Pagos desde</label>
    <input class="control control--compacto" type="date" id="desde" name="desde" value="<?= escaparValores($filtros['desde']) ?>">
    <label class="solo-lectores" for="hasta">Pagos hasta</label>
    <input class="control control--compacto" type="date" id="hasta" name="hasta" value="<?= escaparValores($filtros['hasta']) ?>">
    <button class="btn btn--secundario btn--sm" type="submit">Filtrar</button>
    <?php if ($parametros) : ?><a class="btn btn--texto btn--sm" href="/admin/compras/pagos">Limpiar</a><?php endif; ?>
</form>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Compras con saldo pendiente</h2>
    <?php if (!$conSaldo) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay saldos pendientes.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Compras con saldo</caption>
            <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col">Fecha</th>
                <th scope="col" class="tabla--numerica">Total</th><th scope="col" class="tabla--numerica">Pagado</th>
                <th scope="col" class="tabla--numerica">Saldo</th><th scope="col" class="tabla--centro">Pago</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($conSaldo as $o) : $pe = \App\PagosCompra::ESTADOS[$o['estado_pago']]; ?>
                <tr>
                    <td><a href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#pagos"><strong><?= escaparValores($o['folio']) ?></strong></a></td>
                    <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                    <td><?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_compra']) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($o['pagado']) ?></td>
                    <td class="tabla--numerica cifra"><strong><?= formatoMoneda($o['saldo']) ?></strong></td>
                    <td class="tabla--centro"><span class="badge <?= $pe['clase'] ?>"><?= escaparValores($pe['etiqueta']) ?></span></td>
                    <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#pagos">Pagar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Pagos realizados</h2>
    <?php if (!$pagos) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no hay pagos registrados.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Pagos a proveedores</caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col" class="tabla--numerica">Importe</th>
                <th scope="col">Método</th><th scope="col">Referencia</th><th scope="col">Estado</th></tr></thead>
            <tbody>
            <?php foreach ($pagos as $pg) : $vig = $pg['estado'] === 'vigente'; ?>
                <tr<?= $vig ? '' : ' style="opacity:.6"' ?>>
                    <td><?= escaparValores(date('d/m/Y', strtotime($pg['fecha']))) ?></td>
                    <td><a href="/admin/compras/detalle?id=<?= (int) $pg['orden_id'] ?>#pagos"><strong><?= escaparValores($pg['folio']) ?></strong></a></td>
                    <td><?= escaparValores($pg['proveedor_nombre']) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($pg['importe']) ?></td>
                    <td><?= escaparValores((string) $pg['metodo']) ?></td>
                    <td><?= escaparValores((string) $pg['referencia']) ?></td>
                    <td><?= $vig ? '<span class="badge badge--aprobado">Vigente</span>' : '<span class="badge badge--neutro">Cancelado</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
