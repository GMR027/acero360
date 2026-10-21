<?php
/**
 * views/admin/compras/pedidos.php — Compras y pedidos a proveedores (de cualquier tipo), con filtros.
 */

soloAdmin();

$filtros = [
    'q'         => trim($_GET['q'] ?? ''),
    'estado'    => $_GET['estado'] ?? '',
    'proveedor' => (int) ($_GET['proveedor'] ?? 0),
    'tipo'      => $_GET['tipo'] ?? '',
    'categoria' => (int) ($_GET['categoria'] ?? 0),
    'desde'     => $_GET['desde'] ?? '',
    'hasta'     => $_GET['hasta'] ?? '',
    'cfdi'      => $_GET['cfdi'] ?? '',
    'pago'      => $_GET['pago'] ?? '',
];
$total   = \App\OrdenesCompra::contar($filtros);
$pagina  = paginacion(10, $total);
$ordenes = \App\OrdenesCompra::listar($filtros, $pagina['limite'], $pagina['offset']);
$tot     = \App\OrdenesCompra::totalesFiltrados($filtros);
$proveedores = \App\Proveedores::paraSelector(true);
$categorias  = \App\CategoriasProveedor::mapa();

$parametros = array_filter($filtros, static fn ($v) => $v !== '' && $v !== 0);
$rutaBase = '/admin/compras/pedidos' . ($parametros ? '?' . http_build_query($parametros) : '');

template('header', [
    'titulo'    => 'Compras y proveedores',
    'subtitulo' => 'Compras y pedidos',
    'acciones'  => '<a class="btn btn--primario" href="/admin/compras/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_box</span>'
        . '<span>Nueva compra</span></a>',
]);
template('tabsCompras', ['tab' => 'pedidos']);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/compras/pedidos" method="get" role="search">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar compra</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Folio, referencia o proveedor…" value="<?= escaparValores($filtros['q']) ?>">
            </div>
            <label class="solo-lectores" for="proveedor">Proveedor</label>
            <select class="control control--compacto" id="proveedor" name="proveedor">
                <option value="0">Todos los proveedores</option>
                <?php foreach ($proveedores as $p) : ?>
                    <option value="<?= $p['id'] ?>"<?= $filtros['proveedor'] === $p['id'] ? ' selected' : '' ?>><?= escaparValores($p['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="tipo">Tipo de compra</label>
            <select class="control control--compacto" id="tipo" name="tipo">
                <option value="">Todos los tipos</option>
                <?php foreach (\App\OrdenesCompra::TIPOS as $clave => $texto) : ?>
                    <option value="<?= $clave ?>"<?= $filtros['tipo'] === $clave ? ' selected' : '' ?>><?= escaparValores($texto) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="categoria">Categoría</label>
            <select class="control control--compacto" id="categoria" name="categoria">
                <option value="0">Todas las categorías</option>
                <?php foreach ($categorias as $cid => $cnombre) : ?>
                    <option value="<?= (int) $cid ?>"<?= $filtros['categoria'] === (int) $cid ? ' selected' : '' ?>><?= escaparValores($cnombre) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="estado">Recepción</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="">Toda recepción</option>
                <?php foreach (\App\OrdenesCompra::ESTADOS as $clave => $datos) : ?>
                    <option value="<?= $clave ?>"<?= $filtros['estado'] === $clave ? ' selected' : '' ?>><?= escaparValores($datos['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="cfdi">Factura</label>
            <select class="control control--compacto" id="cfdi" name="cfdi">
                <option value="">Toda factura</option>
                <?php foreach (\App\CompraContable::ESTADOS_CFDI as $clave => $datos) : ?>
                    <option value="<?= $clave ?>"<?= $filtros['cfdi'] === $clave ? ' selected' : '' ?>><?= escaparValores($datos['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="pago">Pago</label>
            <select class="control control--compacto" id="pago" name="pago">
                <option value="">Todo pago</option>
                <?php foreach (\App\PagosCompra::ESTADOS as $clave => $datos) : ?>
                    <option value="<?= $clave ?>"<?= $filtros['pago'] === $clave ? ' selected' : '' ?>><?= escaparValores($datos['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="desde">Desde</label>
            <input class="control control--compacto" type="date" id="desde" name="desde" value="<?= escaparValores($filtros['desde']) ?>">
            <label class="solo-lectores" for="hasta">Hasta</label>
            <input class="control control--compacto" type="date" id="hasta" name="hasta" value="<?= escaparValores($filtros['hasta']) ?>">
            <button class="btn btn--secundario btn--sm" type="submit">Buscar</button>
            <?php if ($parametros) : ?><a class="btn btn--texto btn--sm" href="/admin/compras/pedidos">Limpiar</a><?php endif; ?>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> <?= $total === 1 ? 'compra' : 'compras' ?></span>
    </div>

    <?php if ($total > 0) : ?>
        <p class="texto-sm texto-secundario" style="padding:0 1rem 1rem">
            Comprado <strong class="cifra"><?= formatoMoneda($tot['comprado']) ?></strong>
            · facturado <strong class="cifra"><?= formatoMoneda($tot['facturado']) ?></strong>
            · pendiente de documentar <strong class="cifra"><?= formatoMoneda($tot['pendiente_documentar']) ?></strong>
            · pagado <strong class="cifra"><?= formatoMoneda($tot['pagado']) ?></strong>
            · saldo <strong class="cifra"><?= formatoMoneda($tot['saldo']) ?></strong>
            <span class="texto-xs">(sin contar compras canceladas)</span>
        </p>
    <?php endif; ?>

    <?php if (!$ordenes) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">shopping_cart</span>
            <p class="vacio__titulo"><?= $parametros ? 'Sin coincidencias' : 'Aún no hay compras registradas' ?></p>
            <a class="btn btn--primario" href="/admin/compras/crear">Nueva compra</a>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Compras y pedidos</caption>
                <thead><tr>
                    <th scope="col">Folio</th><th scope="col">Proveedor</th><th scope="col">Tipo</th><th scope="col">Fecha</th>
                    <th scope="col" class="tabla--numerica">Total</th><th scope="col" class="tabla--numerica">Saldo</th>
                    <th scope="col" class="tabla--centro">Recepción</th><th scope="col" class="tabla--centro">Factura</th>
                    <th scope="col" class="tabla--centro">Pago</th><th scope="col" class="tabla--numerica">Acciones</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($ordenes as $o) :
                        $est = \App\OrdenesCompra::ESTADOS[$o['estado']];
                        $cfdi = \App\CompraContable::ESTADOS_CFDI[$o['estado_cfdi']];
                        $pago = \App\PagosCompra::ESTADOS[$o['estado_pago']];
                        ?>
                        <tr>
                            <td><strong><?= escaparValores($o['folio']) ?></strong>
                                <?= $o['referencia'] ? '<span class="texto-xs texto-secundario"><br>Ref. ' . escaparValores($o['referencia']) . '</span>' : '' ?></td>
                            <td><?= escaparValores($o['proveedor_nombre']) ?>
                                <?= $o['categoria_nombre'] ? '<span class="texto-xs texto-secundario"><br>' . escaparValores($o['categoria_nombre']) . '</span>' : '' ?></td>
                            <td><?= $o['tipo'] === 'insumo' ? 'Insumo' : 'Semiterminado' ?></td>
                            <td><?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_compra']) ?></td>
                            <td class="tabla--numerica cifra"><?= $o['estado'] === 'cancelada' ? '—' : formatoMoneda($o['saldo']) ?></td>
                            <td class="tabla--centro"><span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span>
                                <?php if (trim((string) $o['motivo_cierre']) !== '') : ?>
                                    <a style="font-weight:700;text-decoration:none;color:var(--color-advertencia-texto);margin-left:.25rem" href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>"
                                       title="Nota de cierre: <?= escaparValores($o['motivo_cierre']) ?>" aria-label="Tiene nota de cierre: <?= escaparValores($o['motivo_cierre']) ?>">!</a>
                                <?php endif; ?></td>
                            <td class="tabla--centro"><span class="badge <?= $cfdi['clase'] ?>"><?= escaparValores($cfdi['etiqueta']) ?></span></td>
                            <td class="tabla--centro"><?= $o['estado'] === 'cancelada' ? '—' : '<span class="badge ' . $pago['clase'] . '">' . escaparValores($pago['etiqueta']) . '</span>' ?></td>
                            <td class="tabla--numerica">
                                <a class="btn btn--secundario btn--sm" href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>">Ver</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
