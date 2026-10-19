<?php
/**
 * views/admin/compras/index.php — Órdenes de compra a proveedores.
 */

soloAdmin();

$filtros = [
    'q'         => trim($_GET['q'] ?? ''),
    'estado'    => $_GET['estado'] ?? '',
    'proveedor' => (int) ($_GET['proveedor'] ?? 0),
];
$total   = \App\OrdenesCompra::contar($filtros);
$pagina  = paginacion(10, $total);
$ordenes = \App\OrdenesCompra::listar($filtros, $pagina['limite'], $pagina['offset']);
$proveedores = \App\Proveedores::paraSelector();

$parametros = array_filter($filtros, static fn ($v) => $v !== '' && $v !== 0);
$rutaBase = '/admin/compras' . ($parametros ? '?' . http_build_query($parametros) : '');

template('header', [
    'titulo'    => 'Órdenes de compra',
    'subtitulo' => 'Compras a proveedores',
    'acciones'  => '<a class="btn btn--primario" href="/admin/compras/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_box</span>'
        . '<span>Nueva orden</span></a>',
]);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/compras" method="get" role="search">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar orden</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Folio o proveedor…" value="<?= escaparValores($filtros['q']) ?>">
            </div>
            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="">Todos los estados</option>
                <?php foreach (\App\OrdenesCompra::ESTADOS as $clave => $datos) : ?>
                    <option value="<?= $clave ?>"<?= $filtros['estado'] === $clave ? ' selected' : '' ?>><?= escaparValores($datos['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="proveedor">Proveedor</label>
            <select class="control control--compacto" id="proveedor" name="proveedor">
                <option value="0">Todos los proveedores</option>
                <?php foreach ($proveedores as $p) : ?>
                    <option value="<?= $p['id'] ?>"<?= $filtros['proveedor'] === $p['id'] ? ' selected' : '' ?>><?= escaparValores($p['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn--secundario btn--sm" type="submit">Buscar</button>
            <?php if ($parametros) : ?><a class="btn btn--texto btn--sm" href="/admin/compras">Limpiar</a><?php endif; ?>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> <?= $total === 1 ? 'orden' : 'órdenes' ?></span>
    </div>

    <?php if (!$ordenes) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">shopping_cart</span>
            <p class="vacio__titulo"><?= $parametros ? 'Sin coincidencias' : 'Aún no hay órdenes de compra' ?></p>
            <a class="btn btn--primario" href="/admin/compras/crear">Nueva orden</a>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Órdenes de compra</caption>
                <thead><tr>
                    <th scope="col">Folio</th><th scope="col">Proveedor</th><th scope="col">Fecha</th>
                    <th scope="col" class="tabla--numerica">Total esperado</th>
                    <th scope="col" class="tabla--centro">Estado</th><th scope="col" class="tabla--numerica">Acciones</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($ordenes as $o) : $est = \App\OrdenesCompra::ESTADOS[$o['estado']]; ?>
                        <tr>
                            <td><strong><?= escaparValores($o['folio']) ?></strong></td>
                            <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                            <td><?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($o['total_esperado']) ?></td>
                            <td class="tabla--centro"><span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span></td>
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
