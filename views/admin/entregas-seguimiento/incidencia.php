<?php
/**
 * views/admin/entregas-seguimiento/incidencia.php — Registrar una incidencia de entrega.
 *
 * El producto afectado REGRESA AL INVENTARIO al registrarse (la venta ya lo
 * descontó) y, si el cliente debe recibir un reemplazo, queda como deuda de
 * cambio que se descuenta al entregarlo. Si la entrega ya no incluye piezas,
 * estas dejan de estar pendientes: es el mismo criterio de una devolución.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$venta = \App\Ventas::buscarID($id);

if (!$venta) {
    paginaNoEncontrada();
}
if ($venta->estado !== 'registrada') {
    redirigir('/admin/entregas-seguimiento/venta?id=' . $id, 'error', 'Solo se registran incidencias de una venta registrada.');
}

$rutaVenta = '/admin/entregas-seguimiento/venta?id=' . $id;
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($rutaVenta, 'error', 'Este formulario ya se envió; la incidencia no se registró dos veces.');
    }

    $renglones = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $productoId => $cantidad) {
        $renglones[(int) $productoId] = [
            'cantidad' => (int) $cantidad,
            'cambio'   => (int) ($_POST['cambio'][$productoId] ?? 0),
        ];
    }

    if (\App\IncidenciasEntrega::registrar(
        $id,
        (string) ($_POST['motivo'] ?? ''),
        $renglones,
        (string) ($_POST['notas'] ?? ''),
        !empty($_POST['factura_requerida']),
        (int) ($_SESSION['id'] ?? 0),
        isset($_POST['entrega_id']) && (int) $_POST['entrega_id'] > 0 ? (int) $_POST['entrega_id'] : null
    )) {
        $piezas = count(array_filter($renglones, static fn ($r) => $r['cantidad'] > 0));
        redirigir($rutaVenta . '#incidencias', 'exito',
            'Incidencia registrada: ' . $piezas . ' producto(s) afectado(s) regresaron al inventario y el seguimiento de la venta quedó actualizado.');
    }
    $errores = \App\IncidenciasEntrega::getErrores();
}

$pendientes = \App\Entregas::pendientesDeVentas([$id])[$id] ?? [];
$disponibles = \App\Inventario::devolvibles($id);
$ingreso = \App\Contabilidad::ingresoDeVenta($id);
$facturada = $ingreso && $ingreso['estado'] === 'vigente' && $ingreso['estado_facturacion'] === 'facturada';
$hoy = date('Y-m-d');

template('header', ['titulo' => 'Incidencia de entrega · ' . $venta->folio, 'subtitulo' => 'Seguimiento de entregas', 'rutaActiva' => '/admin/entregas-seguimiento']);
template('tabsEntregas', ['tab' => 'seguimiento']);
?>

<section class="tarjeta">
    <p class="nota nota--info mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
        <span>Al registrar la incidencia, las piezas afectadas <strong>regresan al inventario</strong> (entrada por devolución
            ligada a la venta) y dejan de estar pendientes de entrega si nunca se entregaron. Si marcas piezas «a cambiar»,
            queda como deuda de reemplazo y se descuenta del inventario cuando la entregues.
            La venta y su importe NO cambian: si hay que corregir la factura, se hace al resolver la incidencia.</span>
    </p>

    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <form class="formulario" action="/admin/entregas-seguimiento/incidencia" method="post" novalidate data-incidencia>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">

        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta" for="motivo">Motivo de la incidencia *</label>
                <select class="control" id="motivo" name="motivo" required>
                    <option value="">Elige el motivo…</option>
                    <?php foreach (\App\IncidenciasEntrega::MOTIVOS as $clave => $def) : ?>
                        <option value="<?= $clave ?>"<?= ($_POST['motivo'] ?? '') === $clave ? ' selected' : '' ?>><?= escaparValores($def['etiqueta']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="campo__ayuda">Faltante = no llegó; dañado/defectuoso = llegó mal; incompleto = falta algo del producto;
                    cambio/sustitución = hay que reemplazarlo; devolución = el cliente devuelve lo entregado.</span>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="notas">Observaciones</label>
                <textarea class="control" id="notas" name="notas" rows="2" maxlength="400"
                          placeholder="Qué ocurrió, quién lo reportó, acuerdos con el cliente…"><?= escaparValores((string) ($_POST['notas'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Productos de la venta para afectar con la incidencia</caption>
                <thead><tr>
                    <th scope="col">Producto</th>
                    <th scope="col" class="tabla--numerica">Vendido</th>
                    <th scope="col" class="tabla--numerica movil-oculto">Entregado</th>
                    <th scope="col" class="tabla--numerica">Piezas afectadas</th>
                    <th scope="col" class="tabla--centro">Requiere cambio</th>
                </tr></thead>
                <tbody>
                    <?php $vistos = [];
                    foreach (\App\Ventas::detalles($id) as $d) :
                        $pid = (int) $d['producto_id'];
                        if (isset($vistos[$pid])) { continue; }
                        $vistos[$pid] = true;
                        $info = $disponibles[$pid] ?? ['vendido' => 0, 'devuelto' => 0, 'disponible' => 0];
                        $linea = $pendientes[$pid] ?? ['entregado' => 0];
                        $maximo = (int) $info['disponible']; ?>
                        <tr>
                            <td><strong><?= escaparValores($d['producto_nombre']) ?></strong>
                                <span class="texto-xs texto-secundario movil-oculto"><br><?= escaparValores($d['producto_sku']) ?> · <?= escaparValores($d['producto_unidad']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= (int) $info['vendido'] ?></td>
                            <td class="tabla--numerica cifra movil-oculto"><?= (int) $linea['entregado'] ?></td>
                            <td>
                                <input class="control" type="number" min="0" max="<?= $maximo ?>" step="1" value="0"
                                       name="cantidad[<?= $pid ?>]" <?= $maximo <= 0 ? 'disabled' : '' ?>
                                       aria-label="Piezas afectadas de <?= escaparValores($d['producto_nombre']) ?>">
                                <span class="campo__ayuda"><?= $maximo > 0 ? 'máx. ' . $maximo : 'sin piezas disponibles' ?></span>
                            </td>
                            <td class="tabla--centro">
                                <label class="solo-lectores" for="cambio-<?= $pid ?>">Cambiar <?= escaparValores($d['producto_nombre']) ?></label>
                                <input type="checkbox" id="cambio-<?= $pid ?>" name="cambio[<?= $pid ?>]" value="1"
                                       <?= $maximo <= 0 ? 'disabled' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-lg">
            <?php if ($facturada) : ?>
                <label class="campo__etiqueta" for="factura_requerida">
                    <input type="checkbox" id="factura_requerida" name="factura_requerida" value="1"
                        <?= !empty($_POST['factura_requerida']) ? 'checked' : '' ?>>
                    Esta incidencia implica <strong>cancelar la factura y subir la correcta</strong> (PDF y XML)
                </label>
                <span class="campo__ayuda">Al resolver la incidencia se sustituirán los documentos y el ingreso se
                    sincronizará con los datos del XML nuevo (folio, UUID, fecha, subtotal, IVA y total).
                    <?php if ($ingreso['uuid_fiscal']) : ?>Factura actual: <?= escaparValores($ingreso['uuid_fiscal']) ?>.<?php endif; ?></span>
            <?php else : ?>
                <p class="nota nota--info mb-0" role="status">
                    <span class="material-symbols-outlined nota__icono" aria-hidden="true">receipt_long</span>
                    <span>Esta venta aún no está marcada como facturada: cuando lo esté, podrás indicar aquí que hay que
                        cancelar la factura y subir la correcta.</span>
                </p>
            <?php endif; ?>
        </div>

        <p class="campo__ayuda mt-lg">Registra: <?= escaparValores($_SESSION['nombre'] ?? 'tu usuario') ?> · venta <?= escaparValores($venta->folio) ?> · <?= $hoy ?>.</p>

        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="<?= $rutaVenta ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-un-envio>Registrar incidencia</button>
        </div>
    </form>
</section>

<script>
/* Al elegir «Cambio o sustitución» se marcan como «requieren cambio» los productos con piezas afectadas. */
(function () {
  var motivo = document.getElementById('motivo');
  var form = document.querySelector('[data-incidencia]');
  if (!motivo || !form) { return; }
  motivo.addEventListener('change', function () {
    var esCambio = motivo.value === 'cambio_sustitucion';
    form.querySelectorAll('input[type="checkbox"][name^="cambio["]').forEach(function (casilla) {
      var fila = casilla.closest('tr');
      var cantidad = fila ? fila.querySelector('input[type="number"]') : null;
      if (esCambio && cantidad && Number(cantidad.value) > 0) {
        casilla.checked = true;
      } else if (!esCambio) {
        casilla.checked = false;
      }
    });
    form.querySelectorAll('input[type="number"][name^="cantidad["]').forEach(function (cantidad) {
      if (esCambio && Number(cantidad.value) > 0) {
        var casilla = cantidad.closest('tr') ? cantidad.closest('tr').querySelector('input[type="checkbox"]') : null;
        if (casilla) { casilla.checked = true; }
      }
    });
  });
})();
/* Evita el doble clic: tras el primer envío el botón se desactiva. */
document.querySelectorAll('form').forEach(function (f) {
  f.addEventListener('submit', function () { const b = f.querySelector('[data-un-envio]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
});
</script>

<?php template('footer'); ?>
