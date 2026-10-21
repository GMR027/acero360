<?php
/**
 * views/admin/compras/catalogo/index.php — Catálogo de productos semiterminados de los proveedores.
 */

soloAdmin();

$estado = $_GET['estado'] ?? 'activos';
$filtros = [
    'q'         => trim($_GET['q'] ?? ''),
    'proveedor' => (int) ($_GET['proveedor'] ?? 0),
    'estado'    => in_array($estado, ['activos', 'inactivos', 'todos'], true) ? $estado : 'activos',
    'relacion'  => in_array($_GET['relacion'] ?? '', ['con', 'sin'], true) ? $_GET['relacion'] : '',
];
$total  = \App\ProductosProveedor::contar($filtros);
$pagina = paginacion(15, $total);
$filas  = \App\ProductosProveedor::listar($filtros, $pagina['limite'], $pagina['offset']);
$proveedores = \App\Proveedores::paraSelector(true);

$parametros = array_filter($filtros, static fn ($v, $k) => $v !== '' && $v !== 0 && !($k === 'estado' && $v === 'activos'), ARRAY_FILTER_USE_BOTH);
$rutaBase = '/admin/compras/catalogo' . ($parametros ? '?' . http_build_query($parametros) : '');

template('header', [
    'titulo'    => 'Compras y proveedores',
    'subtitulo' => 'Catálogo de productos semiterminados',
    'acciones'  => '<a class="btn btn--primario" href="/admin/compras/catalogo/crear' . ($filtros['proveedor'] ? '?proveedor=' . $filtros['proveedor'] : '') . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>'
        . '<span>Nuevo producto</span></a>',
]);
template('tabsCompras', ['tab' => 'catalogo']);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/compras/catalogo" method="get" role="search">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar producto</label>
                <input class="control" type="search" id="q" name="q" maxlength="80" placeholder="Nombre, código o descripción…" value="<?= escaparValores($filtros['q']) ?>">
            </div>
            <label class="solo-lectores" for="proveedor">Proveedor</label>
            <select class="control control--compacto" id="proveedor" name="proveedor">
                <option value="0">Todos los proveedores</option>
                <?php foreach ($proveedores as $p) : ?>
                    <option value="<?= $p['id'] ?>"<?= $filtros['proveedor'] === $p['id'] ? ' selected' : '' ?>><?= escaparValores($p['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="activos"<?= $filtros['estado'] === 'activos' ? ' selected' : '' ?>>Activos</option>
                <option value="inactivos"<?= $filtros['estado'] === 'inactivos' ? ' selected' : '' ?>>Inactivos</option>
                <option value="todos"<?= $filtros['estado'] === 'todos' ? ' selected' : '' ?>>Todos</option>
            </select>
            <label class="solo-lectores" for="relacion">Relación con productos propios</label>
            <select class="control control--compacto" id="relacion" name="relacion">
                <option value="">Con o sin relación</option>
                <option value="con"<?= $filtros['relacion'] === 'con' ? ' selected' : '' ?>>Relacionados</option>
                <option value="sin"<?= $filtros['relacion'] === 'sin' ? ' selected' : '' ?>>Sin relacionar</option>
            </select>
            <button class="btn btn--secundario btn--sm" type="submit">Buscar</button>
            <?php if ($parametros) : ?><a class="btn btn--texto btn--sm" href="/admin/compras/catalogo">Limpiar</a><?php endif; ?>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> <?= $total === 1 ? 'producto' : 'productos' ?></span>
    </div>

    <?php if (!$filas) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">category_search</span>
            <p class="vacio__titulo"><?= $parametros ? 'Sin coincidencias' : 'Aún no hay productos semiterminados en el catálogo' ?></p>
            <p class="texto-secundario">Agrega aquí lo que cada proveedor te vende, con su código y costo, para elegirlo después al comprar.</p>
            <a class="btn btn--primario" href="/admin/compras/catalogo/crear">Nuevo producto</a>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Productos semiterminados por proveedor</caption>
            <thead><tr><th scope="col">Proveedor</th><th scope="col">Producto</th><th scope="col">Código</th>
                <th scope="col" class="tabla--numerica">Costo unitario</th><th scope="col" class="tabla--centro">Productos propios</th>
                <th scope="col" class="tabla--centro">Estado</th><th scope="col" class="tabla--numerica">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($filas as $f) : $activo = (int) $f['activo'] === 1; ?>
                <tr<?= $activo ? '' : ' style="opacity:.65"' ?>>
                    <td><?= escaparValores($f['proveedor_nombre']) ?><?= (int) $f['proveedor_activo'] === 1 ? '' : ' <span class="badge badge--neutro">Proveedor inactivo</span>' ?></td>
                    <td><a href="/admin/compras/catalogo/detalle?id=<?= (int) $f['id'] ?>"><strong><?= escaparValores($f['nombre']) ?></strong></a>
                        <?= $f['descripcion'] ? '<span class="texto-xs texto-secundario"><br>' . escaparValores($f['descripcion']) . '</span>' : '' ?></td>
                    <td><?= escaparValores((string) $f['codigo']) ?: '—' ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($f['costo_unitario']) ?></td>
                    <td class="tabla--centro cifra"><?= (int) $f['relaciones'] > 0 ? (int) $f['relaciones'] : '<span class="texto-secundario">—</span>' ?></td>
                    <td class="tabla--centro"><span class="badge <?= $activo ? 'badge--aprobado' : 'badge--neutro' ?>"><?= $activo ? 'Activo' : 'Inactivo' ?></span></td>
                    <td class="tabla--numerica">
                        <a class="btn btn--secundario btn--sm" href="/admin/compras/catalogo/editar?id=<?= (int) $f['id'] ?>">Editar</a>
                        <form action="/admin/compras/catalogo/accion" method="post" data-validar style="display:inline"
                              data-confirmacion-sobre="<?= escaparValores($f['nombre']) ?>"
                              data-confirmacion="<?= $activo ? '¿Desactivar este producto?' : '¿Reactivar este producto?' ?>"
                              data-confirmacion-mensaje="<?= $activo ? 'Dejará de ofrecerse en compras nuevas. Se conserva en el historial y puedes reactivarlo.' : 'Volverá a ofrecerse en compras nuevas.' ?>"
                              data-confirmacion-boton="<?= $activo ? 'Sí, desactivar' : 'Sí, reactivar' ?>">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <input type="hidden" name="accion" value="<?= $activo ? 'desactivar' : 'activar' ?>">
                            <input type="hidden" name="retorno" value="lista">
                            <button class="btn btn--texto btn--sm" type="submit"><?= $activo ? 'Desactivar' : 'Reactivar' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
