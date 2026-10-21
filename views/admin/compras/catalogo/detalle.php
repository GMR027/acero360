<?php
/**
 * views/admin/compras/catalogo/detalle.php — Ficha de un semiterminado de proveedor: datos, costo vigente
 * y los productos propios con los que se relaciona. Solo consulta; los cambios van por «accion».
 */

soloAdmin();

$id = (int) ($_GET['id'] ?? 0);
$r  = \App\ProductosProveedor::buscarID($id);
if (!$r) {
    paginaNoEncontrada();
}

$activo = (int) $r['activo'] === 1;
$relacionados = \App\ProductosProveedor::productosRelacionados($id);
$idsRelacionados = array_map('intval', array_column($relacionados, 'id'));
$disponibles = array_values(array_filter(\App\Productos::paraCotizador(), static fn ($p) => !in_array($p['id'], $idsRelacionados, true)));

template('header', [
    'titulo'    => $r['nombre'],
    'subtitulo' => 'Producto de ' . $r['proveedor_nombre'],
    'acciones'  => '<a class="btn btn--secundario" href="/admin/compras/catalogo?proveedor=' . (int) $r['proveedor_id'] . '">Volver al catálogo</a>'
        . '<a class="btn btn--primario" href="/admin/compras/catalogo/editar?id=' . $id . '">Editar</a>',
]);
template('tabsCompras', ['tab' => 'catalogo']);
?>

<div class="rejilla-2">
    <section class="tarjeta">
        <header class="tarjeta__cabecera"><h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span> Datos del producto</h2></header>
        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>Proveedor</dt><dd><a href="/admin/proveedores/detalle?id=<?= (int) $r['proveedor_id'] ?>"><?= escaparValores($r['proveedor_nombre']) ?></a>
                    <?= (int) $r['proveedor_activo'] === 1 ? '' : '<span class="badge badge--neutro">Inactivo</span>' ?></dd></div>
                <div class="ficha__fila"><dt>Código</dt><dd class="cifra"><?= escaparValores((string) $r['codigo']) ?: '—' ?></dd></div>
                <div class="ficha__fila"><dt>Costo unitario de compra</dt><dd class="cifra"><?= formatoMoneda($r['costo_unitario']) ?></dd></div>
                <div class="ficha__fila"><dt>Descripción</dt><dd><?= escaparValores((string) $r['descripcion']) ?: '—' ?></dd></div>
                <div class="ficha__fila"><dt>Estado</dt><dd><span class="badge <?= $activo ? 'badge--aprobado' : 'badge--neutro' ?>"><?= $activo ? 'Activo' : 'Inactivo' ?></span>
                    <?php if (!$activo) : ?><span class="texto-xs texto-secundario">No se ofrece en compras nuevas; su historial se conserva.</span><?php endif; ?></dd></div>
            </dl>
            <form action="/admin/compras/catalogo/accion" method="post" data-validar class="mt-md"
                  data-confirmacion-sobre="<?= escaparValores($r['nombre']) ?>"
                  data-confirmacion="<?= $activo ? '¿Desactivar este producto?' : '¿Reactivar este producto?' ?>"
                  data-confirmacion-mensaje="<?= $activo ? 'Dejará de ofrecerse en compras nuevas. Se conserva en el historial y puedes reactivarlo.' : 'Volverá a ofrecerse en compras nuevas.' ?>"
                  data-confirmacion-boton="<?= $activo ? 'Sí, desactivar' : 'Sí, reactivar' ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="accion" value="<?= $activo ? 'desactivar' : 'activar' ?>">
                <button class="btn <?= $activo ? 'btn--peligro' : 'btn--secundario' ?>" type="submit"><?= $activo ? 'Desactivar producto' : 'Reactivar producto' ?></button>
            </form>
        </div>
    </section>

    <section class="tarjeta" id="relaciones">
        <header class="tarjeta__cabecera"><h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">link</span> Se usa para estos productos propios</h2></header>
        <div class="tarjeta__cuerpo">
            <p class="texto-sm texto-secundario">Es opcional. Sirve para saber qué semiterminado de qué proveedor se usa en cada producto que fabricas o vendes.
                El semiterminado y el producto propio son cosas distintas: cada uno conserva su código y su costo.</p>
            <?php if (!$relacionados) : ?>
                <p class="texto-secundario mt-md">Aún no está relacionado con ningún producto propio.</p>
            <?php else : ?>
                <ul class="lista-simple mt-md">
                    <?php foreach ($relacionados as $p) : ?>
                        <li class="lista-simple__elemento">
                            <div class="tabla__producto-textos">
                                <strong><?= escaparValores($p['nombre']) ?></strong>
                                <span class="texto-xs texto-secundario"><?= escaparValores((string) $p['sku']) ?> · costo propio <?= formatoMoneda($p['costo_actual']) ?></span>
                            </div>
                            <form action="/admin/compras/catalogo/accion" method="post" data-validar>
                                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="desrelacionar">
                                <input type="hidden" name="producto" value="<?= (int) $p['id'] ?>">
                                <button class="btn btn--texto btn--sm" type="submit">Quitar relación</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($disponibles) : ?>
                <form class="formulario mt-md" action="/admin/compras/catalogo/accion" method="post" data-validar>
                    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="relacionar">
                    <div class="campo">
                        <label class="campo__etiqueta" for="producto">Relacionar con un producto propio</label>
                        <select class="control" id="producto" name="producto" required>
                            <option value="">— Elige un producto —</option>
                            <?php foreach ($disponibles as $p) : ?>
                                <option value="<?= (int) $p['id'] ?>"><?= escaparValores(($p['sku'] ? $p['sku'] . ' · ' : '') . $p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn--secundario" type="submit">Relacionar</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php template('footer'); ?>
