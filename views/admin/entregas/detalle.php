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
$pendientes = array_sum(array_column($ventas, 'pendientes'));

$acciones = '<a class="btn btn--secundario" href="/admin/entregas/imprimir?id=' . $id . '&tipo=preparacion" target="_blank" rel="noopener">Imprimir</a>'
    . '<a class="btn btn--secundario" href="/admin/entregas/pdf?id=' . $id . '&tipo=preparacion" target="_blank" rel="noopener">Lista de preparación (PDF)</a>'
    . '<a class="btn btn--secundario" href="/admin/entregas/pdf?id=' . $id . '&tipo=entrega" target="_blank" rel="noopener">PDF de entrega</a>';

template('header', [
    'titulo'     => $prep['folio'],
    'subtitulo'  => 'Vista guardada · ' . count($ventas) . ' pedido(s) · ' . $pendientes . ' pieza(s) por entregar'
        . ($prep['notas'] ? ' · ' . $prep['notas'] : ''),
    'rutaActiva' => '/admin/entregas',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/entregas">Volver</a>' . $acciones,
]);

template('vistaPreparacion', ['grupos' => $grupos, 'ventas' => $ventas, 'prepId' => $id]);
?>

<?php if ($prep['estado'] !== 'cancelada') : ?>
    <form class="mt-lg" action="/admin/entregas/estado" method="post" data-validar
          data-confirmacion-sobre="<?= escaparValores($prep['folio']) ?>"
          data-confirmacion="¿Quitar esta vista guardada?"
          data-confirmacion-mensaje="Solo se quita de la lista; los pedidos y sus entregas no cambian."
          data-confirmacion-boton="Sí, quitarla">
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="estado" value="cancelada">
        <button type="submit" class="btn btn--texto btn--sm">Quitar esta vista guardada</button>
    </form>
<?php endif; ?>

<?php template('footer'); ?>
