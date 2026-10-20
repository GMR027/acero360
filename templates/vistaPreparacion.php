<?php
/**
 * templates/vistaPreparacion.php — Productos agrupados + pedidos, compartido por la pantalla de selección
 * y las vistas guardadas. Variables: $grupos, $ventas, $prepId (0 si aún no está guardada).
 */
$prepId = (int) ($prepId ?? 0);
?>
<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Productos a preparar (agrupados)</h2>
    <?php if (!$grupos) : ?>
        <p class="texto-secundario">No queda nada pendiente por preparar o entregar.</p>
    <?php else : ?>
        <div class="entregas-rejilla">
            <?php foreach ($grupos as $g) : ?>
                <article class="producto-grupo">
                    <?php if ($g['imagen']) : ?>
                        <img class="producto-grupo__imagen" src="<?= escaparValores(\App\Imagenes::urlMiniatura($g['imagen'])) ?>"
                             alt="<?= escaparValores($g['nombre']) ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="producto-grupo__texto">
                        <span class="producto-grupo__sku"><?= escaparValores($g['sku'] ?: 'Sin código') ?></span>
                        <strong><?= escaparValores($g['nombre']) ?></strong>
                    </div>
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
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Pedidos</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Pedidos incluidos</caption>
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
                            <?php if ((int) $v['pendientes'] > 0) : ?>
                                <a class="btn btn--primario btn--sm" href="/admin/entregas/entregar?venta=<?= (int) $v['id'] ?><?= $prepId ? '&prep=' . $prepId : '' ?>">Registrar entrega</a>
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
