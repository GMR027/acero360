<?php
/**
 * views/admin/compras/recibir.php — Registro de una recepción (parcial o total).
 *
 * GET solo muestra el formulario y emite un token de un solo uso; el POST suma únicamente lo recibido.
 * Reenviar el formulario con el mismo token no duplica la entrada. Lo recibido de un semiterminado queda
 * «por terminar» (no vendible) salvo que se marque que ya llegó terminado; los materiales suman a su inventario.
 * Recibir más de lo pendiente exige una confirmación explícita.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$orden = \App\OrdenesCompra::buscarID($id);

if (!$orden) {
    paginaNoEncontrada();
}
if (!in_array($orden['estado'], ['solicitada', 'parcial'], true)) {
    redirigir('/admin/compras/detalle?id=' . $id, 'error', 'Esta compra ya no admite recepciones.');
}

$errores  = [];
$partidas = array_values(array_filter(\App\OrdenesCompra::partidas($id), static fn ($p) => (int) $p['controla'] === 1));
if (!$partidas) {
    redirigir('/admin/compras/detalle?id=' . $id, 'error', 'Esta compra no tiene partidas que lleven recepción.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $renglones = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $partidaId => $cantidad) {
        $renglones[(int) $partidaId] = [
            'cantidad'  => str_replace(',', '.', trim((string) $cantidad)),
            'costo'     => str_replace(',', '.', trim((string) ($_POST['costo'][$partidaId] ?? ''))),
            'terminado' => !empty($_POST['terminado'][$partidaId]),
        ];
    }

    $ok = \App\OrdenesCompra::recibir(
        $id,
        (string) ($_POST['fecha'] ?? ''),
        $renglones,
        (string) ($_POST['token'] ?? ''),
        !empty($_POST['actualiza_costo']),
        (string) ($_POST['notas'] ?? ''),
        (int) ($_SESSION['id'] ?? 0),
        !empty($_POST['confirma_excedente'])
    );

    if ($ok) {
        redirigir('/admin/compras/detalle?id=' . $id, 'exito', 'Recepción registrada. Lo recibido de semiterminados quedó «por terminar»; no se suma a lo disponible para venta.');
    }
    $errores = \App\OrdenesCompra::getErrores();
}

$token = bin2hex(random_bytes(16));
$hayProductos = (bool) array_filter($partidas, static fn ($p) => $p['producto_id']);

template('header', ['titulo' => 'Recepción · ' . $orden['folio'], 'subtitulo' => $orden['proveedor_nombre']]);
template('tabsCompras', ['tab' => 'recepciones']);
?>

<section class="tarjeta">
    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <form class="formulario" action="/admin/compras/recibir" method="post" novalidate
          data-validar data-confirmacion-sobre="Compra <?= escaparValores($orden['folio']) ?>"
          data-confirmacion="¿Confirmar la recepción?"
          data-confirmacion-mensaje="Se registrará únicamente lo capturado como recibido y se actualizarán las existencias correspondientes."
          data-confirmacion-boton="Sí, confirmar recepción">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="token" value="<?= $token ?>">

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha">Fecha de recepción</label>
            <input class="control" type="date" id="fecha" name="fecha" required value="<?= date('Y-m-d') ?>">
        </div>

        <p class="campo__ayuda mb-md">Captura lo que realmente llegó. Deja en 0 lo que no llegó: quedará como pendiente.</p>
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr>
                    <th scope="col">Artículo</th>
                    <th scope="col" class="tabla--numerica">Pendiente</th>
                    <th scope="col" class="tabla--numerica">Recibido ahora</th>
                    <th scope="col" class="tabla--numerica">Costo unitario efectivo</th>
                    <th scope="col">Destino</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($partidas as $p) :
                        $sinProducto = !$p['producto_id'] && !$p['material_id'];
                        $nombre = $p['descripcion'] ?: $p['nombre_mostrado'];
                        $paso = $p['material_id'] ? '0.001' : '1';
                        ?>
                        <tr>
                            <td><strong><?= escaparValores($nombre) ?></strong>
                                <span class="texto-xs texto-secundario"><br>
                                    <?= $p['producto_id'] ? 'Catálogo: ' . escaparValores($p['producto_nombre']) : ($p['material_id'] ? 'Material: ' . escaparValores($p['material_nombre']) : '') ?></span></td>
                            <td class="tabla--numerica cifra"><?= max(0, $p['pendiente']) ?> <span class="texto-xs"><?= escaparValores($p['unidad_mostrada']) ?></span></td>
                            <?php if ($sinProducto) : ?>
                                <td colspan="3"><span class="badge badge--pendiente">Sin relacionar</span>
                                    <span class="texto-xs texto-secundario">Relaciónala con un producto del catálogo en el detalle de la compra antes de recibirla.</span></td>
                            <?php else : ?>
                                <td><input class="control" type="number" min="0" step="<?= $paso ?>" inputmode="decimal"
                                           name="cantidad[<?= (int) $p['id'] ?>]" aria-label="Cantidad recibida de <?= escaparValores($nombre) ?>"
                                           value="<?= max(0, $p['pendiente']) ?>"></td>
                                <td><input class="control control--moneda" type="number" min="0" step="0.01" inputmode="decimal"
                                           name="costo[<?= (int) $p['id'] ?>]" aria-label="Costo unitario de <?= escaparValores($nombre) ?>"
                                           value="<?= escaparValores(number_format((float) $p['costo_esperado'], 2, '.', '')) ?>"></td>
                                <td>
                                    <?php if ($p['material_id']) : ?>
                                        <span class="texto-sm">Inventario de materiales</span>
                                    <?php else : ?>
                                        <label class="comprobacion" for="term-<?= (int) $p['id'] ?>">
                                            <input type="checkbox" id="term-<?= (int) $p['id'] ?>" name="terminado[<?= (int) $p['id'] ?>]" value="1">
                                            <span class="texto-sm">Ya llegó terminado, disponible para venta</span>
                                        </label>
                                        <span class="texto-xs texto-secundario">Sin marcar: queda «por terminar» y no se puede vender todavía.</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="campo campo--completo mt-lg">
            <label class="campo__etiqueta" for="notas">Notas de la entrega</label>
            <input class="control" type="text" id="notas" name="notas" maxlength="255">
        </div>
        <?php if ($hayProductos) : ?>
            <label class="comprobacion" for="actualiza_costo">
                <input type="checkbox" id="actualiza_costo" name="actualiza_costo" value="1">
                <span><strong>Actualizar el costo actual del catálogo</strong><br>
                    <span class="texto-xs texto-secundario">Copia el costo recibido a cada producto. Los costos de compras anteriores no cambian. No toca el precio de venta.</span></span>
            </label>
        <?php endif; ?>
        <label class="comprobacion" for="confirma_excedente">
            <input type="checkbox" id="confirma_excedente" name="confirma_excedente" value="1">
            <span><strong>Llegó más de lo pendiente</strong><br>
                <span class="texto-xs texto-secundario">Marca esto solo si realmente se recibió un excedente; queda registrado en la bitácora. Sin marcarlo, no se acepta más de lo pendiente.</span></span>
        </label>

        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/compras/detalle?id=<?= $id ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit">Confirmar recepción</button>
        </div>
    </form>
</section>

<?php template('footer'); ?>
