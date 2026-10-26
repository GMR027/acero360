<?php
/**
 * views/admin/entregas/clientes.php — Pantalla de entrega · Entregas por cliente o destino.
 * Todo lo que falta entregar, agrupado por cliente, con los productos sumados.
 * «Registrar entrega» abre la hoja de seguimiento de la venta (/admin/entregas-seguimiento/venta),
 * único lugar donde además quedan incidencias, devoluciones e historial.
 */

soloAdmin();

$filtros = ['q' => trim($_GET['q'] ?? ''), 'empresa' => (int) ($_GET['empresa'] ?? 0)];
$clientes = \App\Entregas::pendientesPorCliente($filtros);

template('header', ['titulo' => 'Entregas por cliente', 'subtitulo' => 'Pantalla de entrega', 'rutaActiva' => '/admin/entregas']);
template('tabsEntregas', ['tab' => 'clientes']);
?>

<form class="buscador mb-lg" action="/admin/entregas/clientes" method="get" role="search">
    <div class="buscador__campo">
        <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
        <label class="solo-lectores" for="q">Buscar cliente</label>
        <input class="control" type="search" id="q" name="q" maxlength="80" placeholder="Cliente o folio…" value="<?= escaparValores($filtros['q']) ?>">
    </div>
    <button class="btn btn--secundario btn--sm" type="submit">Buscar</button>
    <?php if ($filtros['q'] !== '') : ?><a class="btn btn--texto btn--sm" href="/admin/entregas/clientes">Limpiar</a><?php endif; ?>
</form>

<?php if (!$clientes) : ?>
    <div class="vacio">
        <span class="material-symbols-outlined vacio__icono" aria-hidden="true">task_alt</span>
        <p class="vacio__titulo">No hay entregas pendientes</p>
    </div>
<?php endif; ?>

<?php foreach ($clientes as $c) : ?>
    <section class="tarjeta entrega-cliente">
        <h2 class="titulo-4"><?= escaparValores($c['nombre']) ?></h2>
        <p class="texto-secundario"><?= escaparValores((string) $c['ubicacion']) ?: 'Sin ubicación registrada' ?>
            · <?= (int) $c['piezas'] ?> pieza(s) por entregar en <?= count($c['ventas']) ?> venta(s)</p>

        <h3 class="titulo-5 mt-md mb-md">Total a entregar a este cliente</h3>
        <div class="entregas-rejilla">
            <?php foreach ($c['productos'] as $p) : ?>
                <article class="producto-grupo">
                    <?php if ($p['imagen']) : ?>
                        <img class="producto-grupo__imagen" src="<?= escaparValores(\App\Imagenes::urlMiniatura($p['imagen'])) ?>"
                             alt="<?= escaparValores($p['nombre']) ?>" loading="lazy">
                    <?php endif; ?>
                    <span class="producto-grupo__sku"><?= escaparValores($p['sku'] ?: 'Sin código') ?></span>
                    <strong><?= escaparValores($p['nombre']) ?></strong>
                    <span class="producto-grupo__cantidad"><?= (int) $p['total'] ?></span>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="tabla-contenedor mt-md">
            <table class="tabla">
                <caption class="solo-lectores">Ventas de <?= escaparValores($c['nombre']) ?></caption>
                <thead><tr><th scope="col">Venta</th><th scope="col" class="tabla--numerica">Por entregar</th><th scope="col">Preparación</th>
                    <th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($c['ventas'] as $v) : ?>
                        <tr>
                            <td><a href="/admin/ventas/detalle?id=<?= (int) $v['id'] ?>"><strong><?= escaparValores($v['folio']) ?></strong></a></td>
                            <td class="tabla--numerica cifra"><?= (int) $v['piezas'] - (int) $v['entregadas'] ?></td>
                            <td><?= $v['en_preparaciones'] ? escaparValores($v['en_preparaciones']) : '—' ?></td>
                            <td class="tabla--numerica"><a class="btn btn--primario btn--sm" href="/admin/entregas-seguimiento/venta?id=<?= (int) $v['id'] ?>">Registrar entrega</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<?php template('footer'); ?>
