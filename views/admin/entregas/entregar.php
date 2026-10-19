<?php
/**
 * views/admin/entregas/entregar.php — Registro de una entrega (total o parcial) de UNA venta.
 *
 * No toca el inventario (la salida ya ocurrió con la venta). El token de un
 * solo uso evita duplicar la entrega si se reenvía el formulario.
 */

soloAdmin();

$ventaId = (int) ($_GET['venta'] ?? $_POST['venta'] ?? 0);
$prepId  = (int) ($_GET['prep'] ?? $_POST['prep'] ?? 0) ?: null;
$venta   = \App\Ventas::buscarID($ventaId);

if (!$venta) {
    paginaNoEncontrada();
}

$volver = $prepId ? '/admin/entregas/detalle?id=' . $prepId : '/admin/entregas/clientes';

if ($venta->estado !== 'registrada') {
    redirigir($volver, 'error', 'Solo se entregan ventas registradas.');
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $piezas = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $productoId => $cantidad) {
        $piezas[(int) $productoId] = (int) $cantidad;
    }

    if (\App\Entregas::registrarEntrega(
        $ventaId, $prepId, $piezas, (string) ($_POST['fecha'] ?? ''), (string) ($_POST['token'] ?? ''),
        (string) ($_POST['notas'] ?? ''), (int) ($_SESSION['id'] ?? 0)
    )) {
        redirigir($volver, 'exito', 'Entrega registrada. El inventario no cambió.');
    }
    $errores = \App\Entregas::getErrores();
}

$lineas    = array_values(\App\Entregas::pendientesDeVentas([$ventaId])[$ventaId] ?? []);
$historial = \App\Entregas::entregasDeVenta($ventaId);
$token     = bin2hex(random_bytes(16));

template('header', ['titulo' => 'Entrega · ' . $venta->folio, 'subtitulo' => $venta->cliente_nombre, 'rutaActiva' => '/admin/entregas']);
?>

<section class="tarjeta mb-lg">
    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <form class="formulario" action="/admin/entregas/entregar" method="post" novalidate>
        <input type="hidden" name="venta" value="<?= $ventaId ?>">
        <input type="hidden" name="prep" value="<?= (int) $prepId ?>">
        <input type="hidden" name="token" value="<?= $token ?>">

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha">Fecha de entrega</label>
            <input class="control" type="date" id="fecha" name="fecha" required value="<?= date('Y-m-d') ?>">
        </div>

        <p class="campo__ayuda mb-md">Captura lo que realmente se entregó. Lo que quede en 0 sigue pendiente.</p>
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr><th scope="col">Producto</th><th scope="col" class="tabla--numerica">Vendido</th>
                    <th scope="col" class="tabla--numerica">Ya entregado</th><th scope="col" class="tabla--numerica">Pendiente</th>
                    <th scope="col" class="tabla--numerica">Entregar ahora</th></tr></thead>
                <tbody>
                    <?php foreach ($lineas as $l) : ?>
                        <tr>
                            <td><strong><?= escaparValores($l['nombre']) ?></strong>
                                <span class="texto-xs texto-secundario"><br><?= escaparValores((string) $l['sku']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= $l['vendido'] ?></td>
                            <td class="tabla--numerica cifra"><?= $l['entregado'] ?></td>
                            <td class="tabla--numerica cifra"><?= $l['pendiente'] ?></td>
                            <td><input class="control" type="number" min="0" max="<?= $l['pendiente'] ?>" step="1" inputmode="numeric"
                                       name="cantidad[<?= (int) $l['producto_id'] ?>]" value="<?= $l['pendiente'] ?>"
                                       <?= $l['pendiente'] === 0 ? 'disabled' : '' ?>
                                       aria-label="Piezas entregadas de <?= escaparValores($l['nombre']) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="campo campo--completo mt-lg">
            <label class="campo__etiqueta" for="notas">Notas de la entrega</label>
            <input class="control" type="text" id="notas" name="notas" maxlength="255" placeholder="Quién recibió, observaciones…">
        </div>
        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="<?= escaparValores($volver) ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit">Confirmar entrega</button>
        </div>
    </form>
</section>

<?php if ($historial) : ?>
    <section class="tarjeta tarjeta--sin-relleno">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Entregas anteriores de esta venta</h2>
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr><th scope="col">Fecha</th><th scope="col">Piezas</th><th scope="col">Registró</th></tr></thead>
                <tbody>
                    <?php foreach ($historial as $h) : ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($h['fecha'])) ?></td>
                            <td><?php foreach ($h['renglones'] as $r) : ?><?= (int) $r['cantidad'] ?> × <?= escaparValores($r['producto_nombre']) ?><br><?php endforeach; ?></td>
                            <td><?= escaparValores($h['usuario_nombre'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
