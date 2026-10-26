<?php
/**
 * templates/formIncidenciaVenta.php — Formulario de registro de incidencia de entrega.
 *
 * Compartido por la página suelta /admin/entregas-seguimiento/incidencia y por
 * el modal del hub «Operación Comercial». Sin botones por defecto (los pone
 * cada contexto); con $conAcciones y $hrefCancelar dibuja el pie de la página
 * suelta. La nota informativa tampoco la trae: cada pantalla explica lo suyo.
 *
 * Regla de negocio (IncidenciasEntrega::MOTIVOS_INVENTARIO / MOTIVOS_CORRECCION):
 *   · faltante / incompleto → las piezas regresan al inventario y, si la venta
 *     está facturada, la corrección de factura se fuerza (data-forzar-factura);
 *   · dañado / cambio / devolución → sólo notificación, sin inventario.
 *
 * Variables: $id (ventaId), $pendientes, $disponibles (Inventario::devolvibles),
 *            $facturada (bool), $ingreso (array|null), $token (string),
 *            $motivoPreseleccion (string, opcional),
 *            $conAcciones (bool, opcional), $hrefCancelar (string, opcional).
 */
$pendientes   = $pendientes ?? [];
$disponibles  = $disponibles ?? [];
$facturada    = (bool) ($facturada ?? false);
$ingreso      = $ingreso ?? null;
$motivoPrevio = (string) ($motivoPreseleccion ?? '');
$conAcciones  = (bool) ($conAcciones ?? false);
$hrefCancelar = (string) ($hrefCancelar ?? '');
$etiqueta     = 'inc-' . (int) $id;
?>
<form class="formulario" action="/admin/entregas-seguimiento/incidencia" method="post" novalidate
      data-incidencia data-forzar-factura="<?= escaparValores(implode(',', \App\IncidenciasEntrega::MOTIVOS_CORRECCION)) ?>">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="token" value="<?= escaparValores($token) ?>">

    <div class="formulario__rejilla">
        <div class="campo">
            <label class="campo__etiqueta" for="motivo-<?= $etiqueta ?>">Motivo de la incidencia *</label>
            <select class="control" id="motivo-<?= $etiqueta ?>" name="motivo" required>
                <option value="">Elige el motivo…</option>
                <?php foreach (\App\IncidenciasEntrega::MOTIVOS as $clave => $def) : ?>
                    <option value="<?= $clave ?>" data-inventario="<?= in_array($clave, \App\IncidenciasEntrega::MOTIVOS_INVENTARIO, true) ? '1' : '0' ?>"
                        <?= $motivoPrevio === $clave ? 'selected' : '' ?>><?= escaparValores($def['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="campo__ayuda">Faltante/incompleto: regresan al inventario y exigen corregir la factura si ya está facturada.
                Dañado, cambio y devolución: sólo quedan notificados (no mueven inventario).</span>
        </div>
        <div class="campo">
            <label class="campo__etiqueta" for="notas-<?= $etiqueta ?>">Observaciones</label>
            <textarea class="control" id="notas-<?= $etiqueta ?>" name="notas" rows="2" maxlength="400"
                      placeholder="Qué ocurrió, quién lo reportó, acuerdos con el cliente…"></textarea>
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
                            <label class="solo-lectores" for="cambio-<?= $etiqueta ?>-<?= $pid ?>">Cambiar <?= escaparValores($d['producto_nombre']) ?></label>
                            <input type="checkbox" id="cambio-<?= $etiqueta ?>-<?= $pid ?>" name="cambio[<?= $pid ?>]" value="1"
                                   <?= $maximo <= 0 ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-lg">
        <?php if ($facturada) : ?>
            <label class="campo__etiqueta" for="factura-requerida-<?= $etiqueta ?>">
                <input type="checkbox" id="factura-requerida-<?= $etiqueta ?>" name="factura_requerida" value="1">
                Esta incidencia implica <strong>cancelar la factura y subir la correcta</strong> (PDF y XML)
            </label>
            <span class="campo__ayuda">Con «faltante» o «incompleto» esta casilla se marca sola: hay que facturar sólo lo
                entregado. Al resolver se sustituyen documentos y el ingreso toma folio, UUID, fecha y montos del XML nuevo.
                <?php if (!empty($ingreso['uuid_fiscal'])) : ?>Factura actual: <?= escaparValores($ingreso['uuid_fiscal']) ?>.<?php endif; ?></span>
        <?php else : ?>
            <p class="nota nota--info mb-0" role="status">
                <span class="material-symbols-outlined nota__icono" aria-hidden="true">receipt_long</span>
                <span>Esta venta aún no está marcada como facturada: si es faltante o incompleto, al facturarla se hará
                    la corrección con lo entregado.</span>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($conAcciones) : ?>
        <div class="formulario__acciones mt-lg">
            <a class="btn btn--secundario" href="<?= escaparValores($hrefCancelar) ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-un-envio>Registrar incidencia</button>
        </div>
    <?php endif; ?>
</form>
