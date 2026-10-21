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
// Solo compras que no son de semiterminados pueden copiar el costo al catálogo (el costo del proveedor no es el interno).
$hayProductos = $orden['tipo'] !== 'semiterminado' && (bool) array_filter($partidas, static fn ($p) => $p['producto_id']);

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
                    <th scope="col">Imagen</th>
                    <th scope="col">Código</th>
                    <th scope="col">Artículo</th>
                    <th scope="col" class="tabla--numerica">Solicitada</th>
                    <th scope="col" class="tabla--numerica">Recibida antes</th>
                    <th scope="col" class="tabla--numerica">Recibido ahora</th>
                    <th scope="col" class="tabla--numerica">Pendiente</th>
                    <th scope="col" class="tabla--numerica">Costo unitario</th>
                    <th scope="col" class="tabla--numerica">Importe</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Destino</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($partidas as $p) :
                        $sinProducto = !$p['producto_id'] && !$p['material_id'];
                        $nombre = $p['descripcion'] ?: $p['nombre_mostrado'];
                        $paso = $p['material_id'] ? '0.001' : '1';
                        $pend = max(0, $p['pendiente']);
                        $estadoPartida = $p['recibida'] <= 0 ? ['Sin recibir', 'badge--pendiente']
                            : ($pend > 0 ? ['Parcial', 'badge--pendiente'] : ['Completa', 'badge--aprobado']);
                        ?>
                        <tr data-partida data-solicitada="<?= escaparValores((string) $p['solicitada']) ?>" data-recibida="<?= escaparValores((string) $p['recibida']) ?>">
                            <td>
                                <?php $imagenArticulo = $p['catalogo_imagen'] ?: ($p['producto_imagen'] ?? ''); ?>
                                <?php if ($imagenArticulo) : ?>
                                    <a href="<?= escaparValores(\App\Imagenes::url($imagenArticulo)) ?>" target="_blank" rel="noopener">
                                        <img src="<?= escaparValores(\App\Imagenes::urlMiniatura($imagenArticulo)) ?>" alt="<?= escaparValores($nombre) ?>"
                                             width="64" height="64" loading="lazy" style="object-fit:cover;border-radius:.5rem;display:block">
                                    </a>
                                <?php else : ?>
                                    <span class="material-symbols-outlined texto-secundario" aria-hidden="true" title="Sin imagen">image</span>
                                <?php endif; ?>
                            </td>
                            <td class="cifra"><?= escaparValores($p['codigo'] ?: '—') ?></td>
                            <td><strong><?= escaparValores($nombre) ?></strong>
                                <span class="texto-xs texto-secundario"><br>
                                    <?= $p['producto_id'] ? 'Producto propio: ' . escaparValores($p['producto_nombre']) : ($p['material_id'] ? 'Material: ' . escaparValores($p['material_nombre']) : '') ?></span></td>
                            <td class="tabla--numerica cifra"><?= $p['solicitada'] ?> <span class="texto-xs"><?= escaparValores($p['unidad_mostrada']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= $p['recibida'] ?></td>
                            <?php if ($sinProducto) : ?>
                                <td colspan="4"><span class="badge badge--pendiente">Sin relacionar</span>
                                    <span class="texto-xs texto-secundario">Relaciónala con un producto propio en el detalle de la compra antes de recibirla.</span></td>
                                <td><span class="badge <?= $estadoPartida[1] ?>"><?= $estadoPartida[0] ?></span></td>
                                <td></td>
                            <?php else : ?>
                                <td><input class="control" type="number" min="0" step="<?= $paso ?>" inputmode="decimal" data-cantidad
                                           name="cantidad[<?= (int) $p['id'] ?>]" aria-label="Cantidad recibida de <?= escaparValores($nombre) ?>"
                                           value="<?= $pend ?>"></td>
                                <td class="tabla--numerica cifra" data-pendiente><?= $pend ?></td>
                                <td><input class="control control--moneda" type="number" min="0" step="0.01" inputmode="decimal" data-costo
                                           name="costo[<?= (int) $p['id'] ?>]" aria-label="Costo unitario del proveedor de <?= escaparValores($nombre) ?>"
                                           value="<?= escaparValores(number_format((float) $p['costo_esperado'], 2, '.', '')) ?>">
                                    <?php if ($p['costo_modificado']) : ?><span class="texto-xs texto-secundario">Catálogo: <?= formatoMoneda($p['costo_catalogo']) ?></span><?php endif; ?></td>
                                <td class="tabla--numerica cifra" data-importe>—</td>
                                <td><span class="badge <?= $estadoPartida[1] ?>"><?= $estadoPartida[0] ?></span></td>
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


<script>
(function () {
    var fmt = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
    function calcular(fila) {
        var c = fila.querySelector('[data-cantidad]');
        if (!c) { return; }
        var ahora = parseFloat(c.value) || 0;
        var costo = parseFloat(fila.querySelector('[data-costo]').value) || 0;
        var pend = Math.round((parseFloat(fila.dataset.solicitada) - parseFloat(fila.dataset.recibida) - ahora) * 1000) / 1000;
        var celda = fila.querySelector('[data-pendiente]');
        celda.textContent = pend < 0 ? '0 (excedente ' + (-pend) + ')' : pend;
        fila.querySelector('[data-importe]').textContent = ahora > 0 ? fmt.format(ahora * costo) : '—';
    }
    document.querySelectorAll('[data-partida]').forEach(function (fila) {
        fila.addEventListener('input', function () { calcular(fila); });
        calcular(fila);
    });
})();
</script>

<?php template('footer'); ?>
