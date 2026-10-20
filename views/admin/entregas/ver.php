<?php
/**
 * views/admin/entregas/ver.php — Muestra en una sola pantalla los pedidos elegidos: productos agrupados
 * (para alistar) y pedidos por cliente (para entregar). No guarda nada ni toca el inventario;
 * «Guardar esta vista» la conserva para abrirla después.
 */

soloAdmin();

$ids    = array_map('intval', (array) ($_GET['ventas'] ?? []));
$ventas = \App\Entregas::ventasPorIds($ids);

if (!$ventas) {
    redirigir('/admin/entregas', 'error', 'Elige al menos un pedido para mostrarlo.');
}

$grupos     = \App\Entregas::productosAgrupados($ventas);
$pendientes = array_sum(array_column($ventas, 'pendientes'));

template('header', [
    'titulo'     => 'Pedidos seleccionados',
    'subtitulo'  => count($ventas) . ' pedido(s) · ' . $pendientes . ' pieza(s) por entregar',
    'rutaActiva' => '/admin/entregas',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/entregas">Volver a elegir</a>',
]);
template('vistaPreparacion', ['grupos' => $grupos, 'ventas' => $ventas, 'prepId' => 0]);
?>

<section class="tarjeta mt-lg">
    <h2 class="titulo-5 mb-sm">¿Quieres conservar esta vista?</h2>
    <form class="formulario" action="/admin/entregas/crear" method="post">
        <?php foreach (array_column($ventas, 'id') as $id) : ?><input type="hidden" name="ventas[]" value="<?= (int) $id ?>"><?php endforeach; ?>
        <input type="hidden" name="forzar" value="1">
        <div class="campo"><label class="campo__etiqueta" for="notas">Nombre o nota (opcional)</label>
            <input class="control" type="text" id="notas" name="notas" maxlength="255" placeholder="Ej. ruta norte, sale el lunes"></div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">bookmark_add</span><span>Guardar esta vista</span></button></div>
    </form>
</section>

<?php template('footer'); ?>
