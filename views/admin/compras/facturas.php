<?php
/**
 * views/admin/compras/facturas.php — Facturas y comprobantes de las compras: qué está facturado (CFDI adjunto),
 * qué está pendiente de documentar y qué no se facturó. Solo consulta; los documentos se adjuntan en cada compra.
 */

soloAdmin();

$filtros = [
    'q'         => trim($_GET['q'] ?? ''),
    'proveedor' => (int) ($_GET['proveedor'] ?? 0),
    'cfdi'      => $_GET['cfdi'] ?? '',
    'desde'     => $_GET['desde'] ?? '',
    'hasta'     => $_GET['hasta'] ?? '',
    'activas'   => true,
];
$total   = \App\OrdenesCompra::contar($filtros);
$pagina  = paginacion(15, $total);
$ordenes = \App\OrdenesCompra::listar($filtros, $pagina['limite'], $pagina['offset']);
$tot     = \App\OrdenesCompra::totalesFiltrados($filtros);
$proveedores = \App\Proveedores::paraSelector(true);

$parametros = array_filter(array_diff_key($filtros, ['activas' => 1]), static fn ($v) => $v !== '' && $v !== 0);
$rutaBase = '/admin/compras/facturas' . ($parametros ? '?' . http_build_query($parametros) : '');

template('header', ['titulo' => 'Compras y proveedores', 'subtitulo' => 'Facturas y comprobantes']);
template('tabsCompras', ['tab' => 'facturas']);
?>

<section class="rejilla-stats mb-lg" aria-label="Importes por estado de factura">
    <article class="stat"><span class="stat__etiqueta">Facturado (CFDI adjunto)</span>
        <span class="stat__valor cifra"><?= formatoMoneda($tot['facturado']) ?></span><span class="stat__pie">Sin verificar ante el SAT</span></article>
    <article class="stat"><span class="stat__etiqueta">Pendiente de documentar</span>
        <span class="stat__valor cifra"><?= formatoMoneda($tot['pendiente_documentar']) ?></span><span class="stat__pie">Falta recibir el CFDI</span></article>
    <article class="stat"><span class="stat__etiqueta">Sin factura</span>
        <span class="stat__valor cifra"><?= formatoMoneda($tot['sin_factura']) ?></span><span class="stat__pie">Compras que no se facturaron</span></article>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/compras/facturas" method="get" role="search">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar</label>
                <input class="control" type="search" id="q" name="q" maxlength="80" placeholder="Folio, referencia o proveedor…" value="<?= escaparValores($filtros['q']) ?>">
            </div>
            <label class="solo-lectores" for="proveedor">Proveedor</label>
            <select class="control control--compacto" id="proveedor" name="proveedor">
                <option value="0">Todos los proveedores</option>
                <?php foreach ($proveedores as $p) : ?>
                    <option value="<?= $p['id'] ?>"<?= $filtros['proveedor'] === $p['id'] ? ' selected' : '' ?>><?= escaparValores($p['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="cfdi">Factura</label>
            <select class="control control--compacto" id="cfdi" name="cfdi">
                <option value="">Todos los estados</option>
                <?php foreach (\App\CompraContable::ESTADOS_CFDI as $clave => $datos) : ?>
                    <option value="<?= $clave ?>"<?= $filtros['cfdi'] === $clave ? ' selected' : '' ?>><?= escaparValores($datos['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="desde">Desde</label>
            <input class="control control--compacto" type="date" id="desde" name="desde" value="<?= escaparValores($filtros['desde']) ?>">
            <label class="solo-lectores" for="hasta">Hasta</label>
            <input class="control control--compacto" type="date" id="hasta" name="hasta" value="<?= escaparValores($filtros['hasta']) ?>">
            <button class="btn btn--secundario btn--sm" type="submit">Buscar</button>
            <?php if ($parametros) : ?><a class="btn btn--texto btn--sm" href="/admin/compras/facturas">Limpiar</a><?php endif; ?>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> <?= $total === 1 ? 'compra' : 'compras' ?></span>
    </div>

    <?php if (!$ordenes) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">receipt_long</span>
            <p class="vacio__titulo"><?= $parametros ? 'Sin coincidencias' : 'Aún no hay compras con comprobantes' ?></p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Facturas y comprobantes por compra</caption>
            <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col">Fecha</th>
                <th scope="col" class="tabla--numerica">Total</th><th scope="col" class="tabla--centro">Factura</th>
                <th scope="col" class="tabla--centro">Ciclo</th>
                <th scope="col" class="tabla--numerica">Documentos</th><th scope="col">Contabilidad</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($ordenes as $o) : $cfdi = \App\CompraContable::ESTADOS_CFDI[$o['estado_cfdi']]; $ciclo = \App\PagosCompra::ciclo($o); ?>
                <tr>
                    <td><a href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>"><strong><?= escaparValores($o['folio']) ?></strong></a>
                        <?= $o['referencia'] ? '<span class="texto-xs texto-secundario"><br>Ref. ' . escaparValores($o['referencia']) . '</span>' : '' ?></td>
                    <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                    <td><?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_compra']) ?></td>
                    <td class="tabla--centro"><span class="badge <?= $cfdi['clase'] ?>"><?= escaparValores($cfdi['etiqueta']) ?></span></td>
                    <td class="tabla--centro"><span class="badge <?= $ciclo['clase'] ?>"><?= escaparValores($ciclo['etiqueta']) ?></span></td>
                    <td class="tabla--numerica cifra"><?= (int) $o['documentos'] ?></td>
                    <td><?= $o['gasto_id'] ? '<a href="/admin/contabilidad/gastos/detalle?id=' . (int) $o['gasto_id'] . '">Gasto #' . (int) $o['gasto_id'] . '</a>' : '<span class="texto-secundario">Sin enviar</span>' ?></td>
                    <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#factura"><?= $o['estado_cfdi'] === 'con_cfdi' ? 'Ver' : 'Adjuntar' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
