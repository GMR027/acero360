<?php
/**
 * views/admin/compras/recibir.php — Registro de una recepción (parcial o total).
 *
 * GET solo muestra el formulario y emite un token de un solo uso; el POST
 * suma al inventario únicamente lo recibido. Reenviar el formulario con el
 * mismo token no duplica la entrada.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$orden = \App\OrdenesCompra::buscarID($id);

if (!$orden) {
    paginaNoEncontrada();
}
if (!in_array($orden['estado'], ['solicitada', 'parcial'], true)) {
    redirigir('/admin/compras/detalle?id=' . $id, 'error', 'Esta orden ya no admite recepciones.');
}

$errores  = [];
$partidas = \App\OrdenesCompra::partidas($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $renglones = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $partidaId => $cantidad) {
        $renglones[(int) $partidaId] = [
            'cantidad' => (int) $cantidad,
            'costo'    => str_replace(',', '.', trim((string) ($_POST['costo'][$partidaId] ?? ''))),
        ];
    }

    $ok = \App\OrdenesCompra::recibir(
        $id,
        (string) ($_POST['fecha'] ?? ''),
        $renglones,
        (string) ($_POST['token'] ?? ''),
        !empty($_POST['actualiza_costo']),
        (string) ($_POST['notas'] ?? ''),
        (int) ($_SESSION['id'] ?? 0)
    );

    if ($ok) {
        redirigir('/admin/compras/detalle?id=' . $id, 'exito', 'Recepción registrada y sumada al inventario.');
    }
    $errores = \App\OrdenesCompra::getErrores();
}

$token = bin2hex(random_bytes(16));

template('header', ['titulo' => 'Recepción · ' . $orden['folio'], 'subtitulo' => $orden['proveedor_nombre']]);
?>

<section class="tarjeta">
    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <form class="formulario" action="/admin/compras/recibir" method="post" novalidate>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="token" value="<?= $token ?>">

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha">Fecha de recepción</label>
            <input class="control" type="date" id="fecha" name="fecha" required value="<?= date('Y-m-d') ?>">
        </div>

        <p class="campo__ayuda mb-md">Captura lo que realmente llegó. Deja en 0 lo que no llegó: quedará como faltante.</p>
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr>
                    <th scope="col">Producto</th>
                    <th scope="col" class="tabla--numerica">Pendiente</th>
                    <th scope="col" class="tabla--numerica">Recibido ahora</th>
                    <th scope="col" class="tabla--numerica">Costo unitario efectivo</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($partidas as $p) : ?>
                        <tr>
                            <td><strong><?= escaparValores($p['producto_nombre']) ?></strong></td>
                            <td class="tabla--numerica cifra"><?= max(0, $p['pendiente']) ?></td>
                            <td><input class="control" type="number" min="0" step="1" inputmode="numeric"
                                       name="cantidad[<?= (int) $p['id'] ?>]" aria-label="Cantidad recibida de <?= escaparValores($p['producto_nombre']) ?>"
                                       value="<?= max(0, $p['pendiente']) ?>"></td>
                            <td><input class="control control--moneda" type="number" min="0" step="0.01" inputmode="decimal"
                                       name="costo[<?= (int) $p['id'] ?>]" aria-label="Costo unitario de <?= escaparValores($p['producto_nombre']) ?>"
                                       value="<?= escaparValores(number_format((float) $p['costo_esperado'], 2, '.', '')) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="campo campo--completo mt-lg">
            <label class="campo__etiqueta" for="notas">Notas de la entrega</label>
            <input class="control" type="text" id="notas" name="notas" maxlength="255">
        </div>
        <label class="comprobacion" for="actualiza_costo">
            <input type="checkbox" id="actualiza_costo" name="actualiza_costo" value="1">
            <span><strong>Actualizar el costo actual del catálogo</strong><br>
                <span class="texto-xs texto-secundario">Copia el costo recibido a cada producto. Los costos de compras anteriores no cambian.</span></span>
        </label>

        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/compras/detalle?id=<?= $id ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit">Confirmar recepción</button>
        </div>
    </form>
</section>

<?php template('footer'); ?>
