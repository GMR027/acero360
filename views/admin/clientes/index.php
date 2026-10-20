<?php
/**
 * views/admin/clientes/index.php — Listado de clientes (directorio).
 *
 * Búsqueda por nombre, alias, empresa o RFC + paginación.
 */

soloAdmin();

/** Texto de búsqueda actual (se escapa al imprimir, no al consultar). */
$termino = trim($_GET['q'] ?? '');

/** Registros por página y cálculo de la paginación. */
$porPagina = 10;
$total     = \App\Clientes::contarTodos($termino);
$pagina    = paginacion($porPagina, $total);
$clientes  = \App\Clientes::mostrarTodos($pagina['limite'], $pagina['offset'], $termino);

/** Construye la URL del listado conservando la búsqueda activa. */
$rutaBase = '/admin/clientes' . ($termino !== '' ? '?q=' . rawurlencode($termino) : '');

/** Etiqueta de estado VIP del cliente. */
$etiquetaVip = static function ($cliente): string {
    return (int) $cliente->vip === 1 ? 'VIP' : '';
};

template('header', [
    'titulo'    => 'Directorio de clientes',
    'subtitulo' => 'Gestión de clientes',
    'acciones'  => '<a class="btn btn--primario movil-visible" href="/admin/clientes/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">person_add</span>'
        . '<span>Nuevo cliente</span></a>',
]);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <!-- Buscador -->
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/clientes" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar cliente</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por nombre, alias, empresa o RFC…"
                       value="<?= escaparValores($termino) ?>">
            </div>

            <button type="submit" class="btn btn--secundario btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>

            <?php if ($termino !== '') : ?>
                <a class="btn btn--texto btn--sm" href="/admin/clientes"
                   aria-label="Limpiar la búsqueda">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'cliente' : 'clientes' ?>
        </span>
    </div>

    <?php if (empty($clientes)) : ?>
        <!-- Estado vacío -->
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">groups</span>
            <p class="vacio__titulo"><?= $termino !== '' ? 'Sin coincidencias' : 'Aún no hay clientes' ?></p>
            <p><?= $termino !== ''
                    ? 'Ningún cliente coincide con "' . escaparValores($termino) . '".'
                    : 'Registra tu primer cliente para poder cotizarle.' ?></p>
            <a class="btn btn--primario movil-visible" href="/admin/clientes/crear">Nuevo cliente</a>
        </div>
    <?php else : ?>
        <!-- Listado -->
        <div class="tabla-contenedor">
            <table class="tabla tabla--clientes">
                <caption class="solo-lectores">Listado de clientes registrados</caption>
                <thead>
                    <tr>
                        <th scope="col">Cliente</th>
                        <th scope="col" class="tabla__col-secundaria">RFC</th>
                        <!-- En móvil cada cliente muestra solo foto, nombre y contacto (ver tabla--clientes en _movil.scss). -->
                        <th scope="col" class="tabla__col-secundaria">Contacto</th>
                        <th scope="col" class="tabla__col-secundaria">Ubicación</th>
                        <th scope="col" class="tabla__col-secundaria tabla--numerica">Descuento</th>
                        <th scope="col" class="tabla__col-secundaria tabla--centro">Condición</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $cliente) : ?>
                        <tr>
                            <td>
                                <div class="tabla__producto">
                                    <img class="retrato retrato--sm"
                                         src="<?= escaparValores(\App\Imagenes::retrato($cliente->foto)) ?>"
                                         alt="">
                                    <div class="tabla__producto-textos">
                                        <strong><?= escaparValores($cliente->nombre) ?></strong>
                                        <span class="texto-sm texto-secundario texto-una-linea">
                                            <?= escaparValores($cliente->nombre_empresa ?: $cliente->alias ?: '—') ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="tabla__col-secundaria cifra"><?= escaparValores($cliente->rfc ?: '—') ?></td>
                            <td class="tabla__col-secundaria">
                                <div class="tabla__producto-textos">
                                    <span class="texto-sm"><?= escaparValores($cliente->telefono ?: '—') ?></span>
                                    <span class="texto-xs texto-secundario texto-una-linea"><?= escaparValores($cliente->email ?: '') ?></span>
                                </div>
                            </td>
                            <td class="tabla__col-secundaria texto-sm texto-secundario"><?= escaparValores($cliente->ubicacion ?: '—') ?></td>
                            <td class="tabla__col-secundaria tabla--numerica cifra"><?= (float) $cliente->descuento_pct > 0 ? number_format((float) $cliente->descuento_pct, 1) . ' %' : '—' ?></td>
                            <td class="tabla__col-secundaria tabla--centro">
                                <?php if ($etiquetaVip($cliente) !== '') : ?>
                                    <span class="badge badge--vip"><?= $etiquetaVip($cliente) ?></span>
                                <?php else : ?>
                                    <span class="texto-sm texto-secundario">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/clientes/detalle?id=<?= (int) $cliente->id ?>"
                                       title="Ver detalle" aria-label="Ver detalle de <?= escaparValores($cliente->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                                    </a>
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/clientes/editar?id=<?= (int) $cliente->id ?>"
                                       title="Editar" aria-label="Editar <?= escaparValores($cliente->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>

                                    <!-- Borrado con confirmación modal (nunca window.confirm). -->
                                    <form action="/admin/clientes/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Baja de cliente"
                                          data-confirmacion="¿Eliminar la ficha del cliente?"
                                          data-confirmacion-mensaje="Se borrará «<?= escaparValores($cliente->nombre) ?>» y sus datos de contacto. Esta acción no se puede deshacer."
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $cliente->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar <?= escaparValores($cliente->nombre) ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        template('paginacion', [
            'pagina'    => $pagina,
            'rutaBase'  => $rutaBase,
            'total'     => $total,
        ]);
        ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
