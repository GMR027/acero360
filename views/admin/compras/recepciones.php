<?php
/**
 * views/admin/compras/recepciones.php — Recepciones: lo pendiente de recibir, los semiterminados «por terminar»,
 * los materiales en inventario y el historial de recepciones.
 *
 * GET solo consulta. El POST (terminar piezas o corregir existencias) es explícito, pide confirmación y queda
 * en las bitácoras de movimientos y de auditoría.
 */

soloAdmin();

$usuario = (int) ($_SESSION['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $accion = (string) ($_POST['accion'] ?? '');
    $destino = '/admin/compras/recepciones';

    if ($accion === 'terminar') {
        if (\App\PorTerminar::terminar((int) ($_POST['producto'] ?? 0), (int) ($_POST['cantidad'] ?? 0), $usuario, trim((string) ($_POST['notas'] ?? '')))) {
            redirigir($destino . '#por-terminar', 'exito', 'Piezas pasadas a disponibles para venta. Ya no cuentan como «por terminar».');
        }
        redirigir($destino . '#por-terminar', 'error', \App\PorTerminar::getErrores()[0] ?? 'No fue posible registrar las piezas terminadas.');
    }
    if ($accion === 'corregir_por_terminar') {
        if (\App\PorTerminar::corregir((int) ($_POST['producto'] ?? 0), (int) ($_POST['nuevo'] ?? -1), (string) ($_POST['razon'] ?? ''), $usuario)) {
            redirigir($destino . '#por-terminar', 'exito', 'Cantidad por terminar corregida; la razón quedó en la bitácora.');
        }
        redirigir($destino . '#por-terminar', 'error', \App\PorTerminar::getErrores()[0] ?? 'No fue posible corregir la cantidad.');
    }
    if ($accion === 'corregir_material') {
        if (\App\Materiales::corregir((int) ($_POST['material'] ?? 0), str_replace(',', '.', (string) ($_POST['nueva'] ?? '')), (string) ($_POST['razon'] ?? ''), $usuario)) {
            redirigir($destino . '#materiales', 'exito', 'Existencia del material corregida; la razón quedó en la bitácora.');
        }
        redirigir($destino . '#materiales', 'error', \App\Materiales::getErrores()[0] ?? 'No fue posible corregir la existencia.');
    }
    redirigir($destino, 'error', 'Acción no reconocida.');
}

$proveedorId = (int) ($_GET['proveedor'] ?? 0);
$pendientes  = \App\OrdenesCompra::pendientesDeRecibir($proveedorId, 200);
$porTerminar = \App\PorTerminar::listar();
$materiales  = \App\Materiales::listar(false);
$historial   = \App\OrdenesCompra::historialRecepciones($proveedorId, 30);
$destinos    = ['por_terminar' => 'Por terminar', 'stock' => 'Disponible para venta', 'material' => 'Material'];

template('header', [
    'titulo'    => 'Compras y proveedores',
    'subtitulo' => 'Recepciones',
]);
template('tabsCompras', ['tab' => 'recepciones']);
?>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Pendiente de recibir</h2>
    <?php if (!$pendientes) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay piezas pendientes. Lo que se pide no cambia las existencias: solo la recepción confirmada.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Artículos pendientes de recibir</caption>
            <thead><tr><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col">Artículo</th>
                <th scope="col" class="tabla--numerica">Comprado</th><th scope="col" class="tabla--numerica">Recibido</th>
                <th scope="col" class="tabla--numerica">Pendiente</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($pendientes as $f) : ?>
                <tr>
                    <td><a href="/admin/compras/detalle?id=<?= (int) $f['orden_id'] ?>"><strong><?= escaparValores($f['folio']) ?></strong></a></td>
                    <td><?= escaparValores($f['proveedor_nombre']) ?></td>
                    <td><?= escaparValores($f['descripcion'] ?: $f['nombre_mostrado']) ?>
                        <?php if (!$f['producto_id'] && !$f['material_id']) : ?><span class="badge badge--pendiente">Sin relacionar</span><?php endif; ?></td>
                    <td class="tabla--numerica cifra"><?= $f['solicitada'] ?></td>
                    <td class="tabla--numerica cifra"><?= $f['recibida'] ?></td>
                    <td class="tabla--numerica cifra"><strong><?= $f['pendiente'] ?></strong></td>
                    <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/compras/recibir?id=<?= (int) $f['orden_id'] ?>">Recibir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg" id="por-terminar">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Semiterminados por terminar</h2>
    <p class="texto-sm texto-secundario" style="padding:.5rem 1rem 0">Piezas ya recibidas del proveedor que todavía necesitan trabajo. No cuentan como existencia para venta
        hasta que las pases a «disponibles»; así ninguna pieza se cuenta dos veces.</p>
    <?php if (!$porTerminar) : ?>
        <p class="texto-secundario" style="padding:1rem">No hay piezas por terminar.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Productos con piezas por terminar</caption>
            <thead><tr><th scope="col">Producto</th><th scope="col" class="tabla--numerica">Por terminar</th>
                <th scope="col" class="tabla--numerica">Disponibles para venta</th><th scope="col">Pasar a disponibles</th></tr></thead>
            <tbody>
            <?php foreach ($porTerminar as $p) : ?>
                <tr>
                    <td><strong><?= escaparValores($p['nombre']) ?></strong><?= $p['sku'] ? '<span class="texto-xs texto-secundario"><br>' . escaparValores($p['sku']) . '</span>' : '' ?></td>
                    <td class="tabla--numerica cifra"><strong><?= (int) $p['stock_por_terminar'] ?></strong></td>
                    <td class="tabla--numerica cifra"><?= (int) $p['stock'] ?></td>
                    <td>
                        <?php if ((int) $p['stock_por_terminar'] > 0) : ?>
                            <form class="formulario" action="/admin/compras/recepciones" method="post" data-validar
                                  data-confirmacion-sobre="<?= escaparValores($p['nombre']) ?>"
                                  data-confirmacion="¿Pasar piezas a disponibles para venta?"
                                  data-confirmacion-mensaje="Las piezas indicadas dejan de estar «por terminar» y se suman a la existencia que se puede vender."
                                  data-confirmacion-boton="Sí, pasar a disponibles">
                                <input type="hidden" name="accion" value="terminar">
                                <input type="hidden" name="producto" value="<?= (int) $p['id'] ?>">
                                <label class="solo-lectores" for="term-<?= (int) $p['id'] ?>">Piezas terminadas</label>
                                <input class="control control--compacto" type="number" id="term-<?= (int) $p['id'] ?>" name="cantidad" min="1" max="<?= (int) $p['stock_por_terminar'] ?>" step="1" required value="<?= (int) $p['stock_por_terminar'] ?>">
                                <button class="btn btn--secundario btn--sm" type="submit">Pasar a disponibles</button>
                            </form>
                        <?php endif; ?>
                        <details class="mt-sm">
                            <summary class="btn btn--texto btn--sm">Corregir cantidad</summary>
                            <form class="formulario mt-sm" action="/admin/compras/recepciones" method="post" data-validar
                                  data-confirmacion-sobre="<?= escaparValores($p['nombre']) ?>"
                                  data-confirmacion="¿Corregir las piezas por terminar?"
                                  data-confirmacion-mensaje="La corrección queda en la bitácora con su razón."
                                  data-confirmacion-boton="Sí, corregir">
                                <input type="hidden" name="accion" value="corregir_por_terminar">
                                <input type="hidden" name="producto" value="<?= (int) $p['id'] ?>">
                                <label class="campo__etiqueta" for="nuevo-<?= (int) $p['id'] ?>">Cantidad correcta por terminar</label>
                                <input class="control" type="number" id="nuevo-<?= (int) $p['id'] ?>" name="nuevo" min="0" step="1" required value="<?= (int) $p['stock_por_terminar'] ?>">
                                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="razon-<?= (int) $p['id'] ?>">Razón</label>
                                <input class="control" type="text" id="razon-<?= (int) $p['id'] ?>" name="razon" maxlength="255" required minlength="5">
                                <button class="btn btn--secundario btn--sm" type="submit">Guardar corrección</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg" id="materiales">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Materiales en inventario</h2>
    <p class="texto-sm texto-secundario" style="padding:.5rem 1rem 0">Solo los materiales que decidiste controlar al comprarlos. Son aparte del catálogo de venta.</p>
    <?php if (!$materiales) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no controlas materiales en inventario. Al registrar una compra de insumos elige «Controlar en inventario».</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Materiales con existencia</caption>
            <thead><tr><th scope="col">Material</th><th scope="col" class="tabla--numerica">Existencia</th><th scope="col">Corrección</th></tr></thead>
            <tbody>
            <?php foreach ($materiales as $m) : ?>
                <tr>
                    <td><strong><?= escaparValores($m['nombre']) ?></strong></td>
                    <td class="tabla--numerica cifra"><?= \App\Materiales::numero($m['existencia']) ?> <span class="texto-xs"><?= escaparValores($m['unidad']) ?></span></td>
                    <td>
                        <details>
                            <summary class="btn btn--texto btn--sm">Corregir existencia</summary>
                            <form class="formulario mt-sm" action="/admin/compras/recepciones" method="post" data-validar
                                  data-confirmacion-sobre="<?= escaparValores($m['nombre']) ?>"
                                  data-confirmacion="¿Corregir la existencia del material?"
                                  data-confirmacion-mensaje="La corrección queda en la bitácora con su razón."
                                  data-confirmacion-boton="Sí, corregir">
                                <input type="hidden" name="accion" value="corregir_material">
                                <input type="hidden" name="material" value="<?= (int) $m['id'] ?>">
                                <label class="campo__etiqueta" for="mn-<?= (int) $m['id'] ?>">Existencia correcta</label>
                                <input class="control" type="number" id="mn-<?= (int) $m['id'] ?>" name="nueva" step="0.001" required value="<?= escaparValores((string) \App\Materiales::numero($m['existencia'])) ?>">
                                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="mr-<?= (int) $m['id'] ?>">Razón</label>
                                <input class="control" type="text" id="mr-<?= (int) $m['id'] ?>" name="razon" maxlength="255" required minlength="5">
                                <button class="btn btn--secundario btn--sm" type="submit">Guardar corrección</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Historial de recepciones</h2>
    <?php if (!$historial) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no se ha recibido mercancía.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Recepciones registradas</caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col">Compra</th><th scope="col">Proveedor</th><th scope="col">Destino</th>
                <th scope="col" class="tabla--numerica">Cantidad</th><th scope="col">Notas</th></tr></thead>
            <tbody>
            <?php foreach ($historial as $h) : ?>
                <tr>
                    <td><?= escaparValores(date('d/m/Y', strtotime($h['fecha']))) ?></td>
                    <td><a href="/admin/compras/detalle?id=<?= (int) $h['orden_id'] ?>"><strong><?= escaparValores($h['folio']) ?></strong></a></td>
                    <td><?= escaparValores($h['proveedor_nombre']) ?></td>
                    <td><?= escaparValores(implode(', ', array_map(static fn ($d) => $destinos[$d] ?? $d, explode(',', (string) $h['destinos'])))) ?></td>
                    <td class="tabla--numerica cifra"><?= \App\OrdenesCompra::num($h['piezas']) ?></td>
                    <td><?= escaparValores((string) $h['notas']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
