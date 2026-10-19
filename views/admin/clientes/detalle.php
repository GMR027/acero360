<?php
/**
 * views/admin/clientes/detalle.php — Ficha de un cliente.
 *
 * Muestra los datos de contacto, la condición comercial, los precios
 * especiales que se le hayan guardado y el botón para crear una cotización.
 */

soloAdmin();

$cliente = \App\Clientes::buscarID((int) ($_GET['id'] ?? 0));

if (!$cliente) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

/** ¿Tiene precios especiales registrados? */
$tienePrecios = $cliente->tienePreciosEspeciales();

/** Número de cotizaciones emitidas a este cliente. */
$cotizaciones = $cliente->contarCotizaciones();

/** Etiqueta y clase del distintivo VIP. */
$esVip    = (int) $cliente->vip === 1;
$claseVip = $esVip ? 'badge badge--vip' : 'badge badge--neutro';

template('header', [
    'titulo'            => $cliente->nombre_empresa ?: $cliente->nombre,
    'rutaActiva'        => '/admin/clientes',
    // Esta vista pinta su propio encabezado (retrato, nombre e insignia),
    // así que el genérico se omite: si no, el nombre del cliente saldría
    // dos veces seguidas y la página tendría dos <h1>.
    'mostrarEncabezado' => false,
]);
?>

<section class="encabezado-pagina">
    <div class="perfil-fila">
        <img class="retrato" src="<?= escaparValores(\App\Imagenes::retrato($cliente->foto)) ?>"
             alt="Foto de perfil de <?= escaparValores($cliente->nombre) ?>">
        <div class="encabezado-pagina__texto">
            <h1 class="titulo-2">
                <?= escaparValores($cliente->nombre_empresa ?: $cliente->nombre) ?>
                <span class="<?= $claseVip ?>" style="margin-left:0.5rem">
                    <?= $esVip ? 'VIP' : 'Cliente' ?>
                </span>
            </h1>
            <p class="texto-secundario"><?= escaparValores($cliente->nombre) ?><?= $cliente->alias !== '' ? ' · Alias: ' . escaparValores($cliente->alias) : '' ?></p>
        </div>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/clientes">Volver</a>
        <a class="btn btn--secundario" href="/admin/clientes/editar?id=<?= (int) $cliente->id ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
            <span>Editar</span>
        </a>
        <a class="btn btn--primario" href="/admin/cotizaciones/crear?id_cliente=<?= (int) $cliente->id ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">note_add</span>
            <span>Nueva cotización</span>
        </a>
    </div>
</section>

<div class="rejilla-2">
    <!-- Ficha del cliente -->
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">badge</span>
                Datos del cliente
            </h2>
        </header>

        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>RFC</dt><dd class="cifra"><?= escaparValores($cliente->rfc ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Teléfono</dt><dd class="cifra"><?= escaparValores($cliente->telefono ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Correo electrónico</dt><dd><?= escaparValores($cliente->email ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Ubicación</dt><dd><?= escaparValores($cliente->ubicacion ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Nombre de la empresa</dt><dd><?= escaparValores($cliente->nombre_empresa ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Alias</dt><dd><?= escaparValores($cliente->alias ?: '—') ?></dd></div>
            </dl>

            <?php if (trim((string) $cliente->notas) !== '') : ?>
                <div class="nota">
                    <span class="material-symbols-outlined nota__icono" aria-hidden="true">sticky_note_2</span>
                    <span><?= nl2br(escaparValores($cliente->notas)) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Condición comercial -->
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">sell</span>
                Condición comercial
            </h2>
        </header>

        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila">
                    <dt>Descuento global</dt>
                    <dd class="cifra"><strong><?= number_format((float) $cliente->descuento_pct, 1) ?> %</strong></dd>
                </div>
                <div class="ficha__fila">
                    <dt>Cotizaciones emitidas</dt>
                    <dd class="cifra"><?= number_format($cotizaciones) ?></dd>
                </div>
                <div class="ficha__fila">
                    <dt>Precios especiales</dt>
                    <dd><?= $tienePrecios ? '<span class="badge badge--aprobado">Activos</span>' : '<span class="badge badge--neutro">Ninguno</span>' ?></dd>
                </div>
                <div class="ficha__fila">
                    <dt>Condición</dt>
                    <dd><?= $esVip ? '<span class="badge badge--vip">VIP</span>' : '<span class="badge badge--neutro">Normal</span>' ?></dd>
                </div>
            </dl>

            <p class="texto-sm texto-secundario mt-md">
                Los precios especiales se guardan desde el cotizador al modificar el precio de una
                línea. Nunca se borran automáticamente: se eliminan desde esta ficha o al editar el cliente.
            </p>
        </div>
    </section>
</div>

<?php template('footer'); ?>
