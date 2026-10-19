<?php
/**
 * views/admin/entregas/detalle.php — Una preparación: estado, productos
 * agrupados (para alistar) y ventas por cliente (para entregar).
 * Consultarla o exportarla nunca modifica inventario ni entregas.
 */

soloAdmin();

$id   = (int) ($_GET['id'] ?? 0);
$prep = \App\Entregas::buscarPreparacion($id);

if (!$prep) {
    paginaNoEncontrada();
}

$ventas  = \App\Entregas::ventasDePreparacion($id);
$grupos  = \App\Entregas::productosAgrupados($ventas);
$estado  = \App\Entregas::ESTADOS[$prep['estado']];
$activa  = in_array($prep['estado'], \App\Entregas::ACTIVOS, true);
$pendientes = array_sum(array_column($ventas, 'pendientes'));

$acciones = '<a class="btn btn--secundario" href="/admin/entregas/pdf?id=' . $id . '&tipo=preparacion" target="_blank" rel="noopener">Lista de preparación (PDF)</a>'
    . '<a class="btn btn--secundario" href="/admin/entregas/pdf?id=' . $id . '&tipo=entrega" target="_blank" rel="noopener">PDF de entrega</a>';

template('header', [
    'titulo'     => $prep['folio'],
    'subtitulo'  => 'Preparación de pedidos',
    'rutaActiva' => '/admin/entregas',
    'acciones'   => $acciones,
]);
?>

<section class="tarjeta mb-lg">
    <p><span class="badge <?= $estado['clase'] ?>"><?= escaparValores($estado['etiqueta']) ?></span>
        · <?= count($ventas) ?> venta(s) · <?= $pendientes ?> pieza(s) por entregar
        <?= $prep['notas'] ? ' · ' . escaparValores($prep['notas']) : '' ?></p>

    <?php if ($prep['estado'] !== 'cancelada') : ?>
        <div class="cotizador-estado-acciones mt-md">
            <span class="campo__etiqueta">Cambiar estado</span>
            <?php foreach (\App\Entregas::ESTADOS as $clave => $d) :
                if ($clave === 'cancelada') { continue; }
                $actual = $prep['estado'] === $clave; ?>
                <form action="/admin/entregas/estado" method="post">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="estado" value="<?= $clave ?>">
                    <button type="submit" class="btn btn--sm <?= $actual ? 'btn--primario' : 'btn--secundario' ?>" <?= $actual ? 'disabled' : '' ?>>
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem"><?= $d['icono'] ?></span>
                        <span><?= escaparValores($d['etiqueta']) ?></span>
                    </button>
                </form>
            <?php endforeach; ?>
            <?php if ($activa) : ?>
                <form action="/admin/entregas/estado" method="post" data-validar
                      data-confirmacion-sobre="<?= escaparValores($prep['folio']) ?>"
                      data-confirmacion="¿Cancelar la preparación?"
                      data-confirmacion-mensaje="Las ventas quedan libres para prepararse de nuevo. No afecta el inventario."
                      data-confirmacion-boton="Sí, cancelar">
                    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="estado" value="cancelada">
                    <button type="submit" class="btn btn--peligro btn--sm">Cancelar preparación</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Productos a preparar (agrupados)</h2>
    <?php if (!$grupos) : ?>
        <p class="texto-secundario">No queda nada pendiente por preparar o entregar en esta preparación.</p>
    <?php else : ?>
        <div class="entregas-rejilla">
            <?php foreach ($grupos as $g) : ?>
                <article class="producto-grupo">
                    <?php if ($g['imagen']) : ?>
                        <img class="producto-grupo__imagen" src="<?= escaparValores(\App\Imagenes::urlMiniatura($g['imagen'])) ?>"
                             alt="<?= escaparValores($g['nombre']) ?>" loading="lazy">
                    <?php endif; ?>
                    <span class="producto-grupo__sku"><?= escaparValores($g['sku'] ?: 'Sin código') ?></span>
                    <strong><?= escaparValores($g['nombre']) ?></strong>
                    <span class="producto-grupo__cantidad"><?= (int) $g['total'] ?> <span class="texto-sm texto-secundario"><?= escaparValores($g['unidad']) ?></span></span>
                    <ul class="producto-grupo__origenes">
                        <?php foreach ($g['origenes'] as $o) : ?>
                            <li><?= (int) $o['cantidad'] ?> · <?= escaparValores($o['venta']) ?> · <?= escaparValores($o['cliente']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Ventas de la preparación</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Ventas incluidas</caption>
            <thead><tr><th scope="col">Venta</th><th scope="col">Cliente / destino</th>
                <th scope="col" class="tabla--numerica">Entregadas</th><th scope="col" class="tabla--numerica">Pendientes</th>
                <th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
                <?php foreach ($ventas as $v) : ?>
                    <tr>
                        <td><a href="/admin/ventas/detalle?id=<?= (int) $v['id'] ?>"><strong><?= escaparValores($v['folio']) ?></strong></a></td>
                        <td><?= escaparValores($v['cliente_empresa'] ?: $v['cliente_nombre']) ?>
                            <span class="texto-xs texto-secundario"><br><?= escaparValores((string) $v['cliente_ubicacion']) ?></span></td>
                        <td class="tabla--numerica cifra"><?= (int) $v['entregadas'] ?> / <?= (int) $v['piezas'] ?></td>
                        <td class="tabla--numerica cifra"><?= (int) $v['pendientes'] ?></td>
                        <td class="tabla--numerica">
                            <?php if ((int) $v['pendientes'] > 0 && $prep['estado'] !== 'cancelada') : ?>
                                <a class="btn btn--primario btn--sm" href="/admin/entregas/entregar?venta=<?= (int) $v['id'] ?>&prep=<?= $id ?>">Registrar entrega</a>
                            <?php else : ?>
                                <span class="badge badge--aprobado">Completa</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php template('footer'); ?>
