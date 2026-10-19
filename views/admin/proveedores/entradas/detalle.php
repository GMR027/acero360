<?php
/**
 * views/admin/proveedores/entradas/detalle.php — Reporte fotográfico de la
 * entrada de material.
 *
 * Encabezado (datos del proveedor, tipo, número de pedido y categoría),
 * descripción, fotografía de la entrega y notas adicionales.
 */

soloAdmin();

$entrada = \App\ProveedorEntradas::buscarID((int) ($_GET['id'] ?? 0));

if (!$entrada) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$hayFoto = trim((string) $entrada->foto) !== '';

template('header', [
    'titulo'     => 'Entrada de material',
    'subtitulo'  => 'Reporte fotográfico',
    'rutaActiva' => '/admin/proveedores',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/proveedores/entradas">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>'
        . '<span>Volver</span></a>'
        . '<a class="btn btn--secundario" href="/admin/proveedores/entradas/editar?id=' . (int) $entrada->id . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>'
        . '<span>Editar</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Entrada del <strong><?= date('d/m/Y', strtotime($entrada->fecha)) ?></strong>
            · <?= escaparValores($entrada->proveedor_nombre) ?>
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/proveedores/detalle?id=<?= (int) $entrada->proveedor_id ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">badge</span>
            <span>Ver proveedor</span>
        </a>

        <!-- Borrado con confirmación modal (nunca window.confirm). -->
        <form action="/admin/proveedores/entradas/eliminar" method="post" data-validar
              data-confirmacion-sobre="Baja de entrada"
              data-confirmacion="¿Eliminar esta entrada?"
              data-confirmacion-mensaje="Se borrará la entrada del proveedor «<?= escaparValores($entrada->proveedor_nombre) ?>» y su fotografía. Esta acción no se puede deshacer."
              data-confirmacion-boton="Sí, eliminar">
            <input type="hidden" name="id" value="<?= (int) $entrada->id ?>">
            <button type="submit" class="btn btn--peligro">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                <span>Eliminar</span>
            </button>
        </form>
    </div>
</section>

<div class="rejilla-2">
    <!-- Encabezado del reporte -->
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
                Encabezado del proveedor
            </h2>
        </header>

        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>Nombre</dt><dd><strong><?= escaparValores($entrada->proveedor_nombre) ?></strong></dd></div>
                <div class="ficha__fila"><dt>RFC</dt><dd class="cifra"><?= escaparValores($entrada->proveedor_rfc ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Teléfono</dt><dd class="cifra"><?= escaparValores($entrada->proveedor_telefono ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Ubicación</dt><dd><?= escaparValores($entrada->proveedor_ubicacion ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Tipo de proveedor</dt><dd><?= escaparValores($entrada->proveedor_tipo) ?></dd></div>
                <div class="ficha__fila"><dt>Número de pedido</dt><dd class="cifra"><?= escaparValores($entrada->numero_pedido ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Categoría</dt><dd><?= escaparValores(\App\ProveedorEntradas::nombreCategoria((int) $entrada->categoria)) ?></dd></div>
                <div class="ficha__fila"><dt>Monto</dt><dd class="cifra"><?= formatoMoneda($entrada->monto) ?></dd></div>
                <div class="ficha__fila">
                    <dt>Estado de pago</dt>
                    <dd>
                        <?php if ((int) $entrada->pagado === 1) : ?>
                            <span class="badge badge--aprobado">Pagado</span>
                        <?php else : ?>
                            <span class="badge badge--pendiente">Pendiente por pagar</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="ficha__fila"><dt>Fecha</dt><dd class="cifra"><?= date('d/m/Y', strtotime($entrada->fecha)) ?></dd></div>
                <div class="ficha__fila"><dt>Registró</dt><dd><?= escaparValores($entrada->usuario_nombre ?: '—') ?></dd></div>
            </dl>
        </div>
    </section>

    <!-- Fotografía de la entrega -->
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                Foto de entrega del proveedor
            </h2>
        </header>

        <div class="tarjeta__cuerpo">
            <?php if ($hayFoto) : ?>
                <img class="foto-acta" src="<?= escaparValores(\App\Imagenes::url($entrada->foto)) ?>"
                     alt="Fotografía de la entrega de <?= escaparValores($entrada->proveedor_nombre) ?>">
            <?php else : ?>
                <div class="foto-acta foto-acta--vacia">
                    <span class="material-symbols-outlined" aria-hidden="true">image</span>
                    <span>Sin fotografía de entrega</span>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Descripción y notas -->
<section class="tarjeta mb-lg">
    <header class="tarjeta__cabecera">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">description</span>
            Descripción de lo recibido
        </h2>
    </header>

    <div class="tarjeta__cuerpo">
        <?php if (trim((string) $entrada->descripcion) !== '') : ?>
            <p><?= nl2br(escaparValores($entrada->descripcion)) ?></p>
        <?php else : ?>
            <p class="texto-secundario">Sin descripción.</p>
        <?php endif; ?>
    </div>
</section>

<section class="tarjeta">
    <header class="tarjeta__cabecera">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">sticky_note_2</span>
            Notas adicionales
        </h2>
    </header>

    <div class="tarjeta__cuerpo">
        <?php if (trim((string) $entrada->notas) !== '') : ?>
            <p><?= nl2br(escaparValores($entrada->notas)) ?></p>
        <?php else : ?>
            <p class="texto-secundario">Esta entrada no tiene notas adicionales.</p>
        <?php endif; ?>
    </div>
</section>

<?php template('footer'); ?>
