<?php
/**
 * views/admin/compras/detalle.php — Detalle de una compra: importes, partidas (comprado vs. recibido),
 * recepciones, factura/comprobantes, pagos y acciones. Consultarlo nunca modifica inventario ni contabilidad.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? 0);
$orden = \App\OrdenesCompra::buscarID($id);

if (!$orden) {
    paginaNoEncontrada();
}

$partidas    = \App\OrdenesCompra::partidas($id);
$totales     = \App\OrdenesCompra::totales($partidas);
$recepciones = \App\OrdenesCompra::recepciones($id);
$resumen     = \App\OrdenesCompra::resumen($id);
$pagos       = \App\PagosCompra::deOrden($id);
$documentos  = \App\CompraContable::documentos($id);
$gasto       = \App\CompraContable::gastoDe($id);

$estado = \App\OrdenesCompra::ESTADOS[$orden['estado']];
$cfdi   = \App\CompraContable::ESTADOS_CFDI[$orden['estado_cfdi']];
$pagoEt = \App\PagosCompra::ESTADOS[$resumen['estado_pago']];
$esInsumo = $orden['tipo'] === 'insumo';
$cancelada = $orden['estado'] === 'cancelada';

$puedeRecibir = in_array($orden['estado'], ['solicitada', 'parcial'], true);
$puedeCerrar  = in_array($orden['estado'], ['solicitada', 'parcial', 'completa'], true);
$puedeEditar  = in_array($orden['estado'], ['solicitada', 'sin_recepcion'], true) && !$pagos && !$gasto;
$gastoVigente = $gasto && $gasto['estado'] === 'vigente';
$sinRelacionar = array_filter($partidas, static fn ($p) => !$esInsumo && !$p['producto_id']);

$acciones = '<a class="btn btn--secundario" href="/admin/compras/pdf?id=' . $id . '&tipo=orden" target="_blank" rel="noopener">PDF de la compra</a>';
if ($puedeRecibir || $recepciones) {
    $acciones .= '<a class="btn btn--secundario" href="/admin/compras/pdf?id=' . $id . '&tipo=cierre" target="_blank" rel="noopener">Comprado vs. recibido</a>';
}

template('header', [
    'titulo'    => 'Compra ' . $orden['folio'],
    'subtitulo' => $orden['proveedor_nombre'],
    'acciones'  => $acciones,
]);
template('tabsCompras', ['tab' => 'pedidos']);
?>

<section class="tarjeta">
    <p>
        <span class="badge badge--neutro"><?= $esInsumo ? 'Insumo o material' : 'Semiterminado' ?></span>
        <span class="badge <?= $estado['clase'] ?>"><?= escaparValores($estado['etiqueta']) ?></span>
        <span class="badge <?= $cfdi['clase'] ?>"><?= escaparValores($cfdi['etiqueta']) ?></span>
        <?php if (!$cancelada) : ?><span class="badge <?= $pagoEt['clase'] ?>"><?= escaparValores($pagoEt['etiqueta']) ?></span><?php endif; ?>
    </p>
    <p class="mt-sm">Proveedor: <a href="/admin/proveedores/detalle?id=<?= (int) $orden['proveedor_id'] ?>"><strong><?= escaparValores($orden['proveedor_nombre']) ?></strong></a>
       · Fecha <?= escaparValores(date('d/m/Y', strtotime($orden['fecha_solicitud']))) ?>
       <?= $orden['referencia'] ? '· Pedido/folio del proveedor <strong>' . escaparValores($orden['referencia']) . '</strong>' : '' ?>
       <?= $orden['categoria_id'] ? '· ' . escaparValores(\App\CategoriasProveedor::nombre((int) $orden['categoria_id'])) : '' ?></p>
    <?php if ($orden['estado'] === 'cerrada') : ?>
        <p class="texto-secundario">Cerrada el <?= escaparValores(date('d/m/Y H:i', strtotime($orden['cerrada_en']))) ?>
            <?= $orden['motivo_cierre'] ? '· ' . escaparValores($orden['motivo_cierre']) : '' ?></p>
    <?php endif; ?>
    <?php if ($orden['observaciones']) : ?>
        <p class="texto-secundario"><?= nl2br(escaparValores($orden['observaciones'])) ?></p>
    <?php endif; ?>
</section>

<section class="rejilla-stats mb-lg" aria-label="Importes de la compra">
    <article class="stat">
        <span class="stat__etiqueta">Comprado</span>
        <span class="stat__valor cifra"><?= formatoMoneda($resumen['total']) ?></span>
        <span class="stat__pie">Subtotal <?= formatoMoneda($resumen['subtotal']) ?>
            · IVA <?= $orden['iva_tasa'] !== null ? '(' . rtrim(rtrim(number_format((float) $orden['iva_tasa'], 2, '.', ''), '0'), '.') . ' %) ' : '' ?><?= $resumen['iva'] === null ? 'no especificado' : formatoMoneda($resumen['iva']) ?></span>
    </article>
    <article class="stat">
        <span class="stat__etiqueta">Facturado</span>
        <span class="stat__valor cifra"><?= formatoMoneda($resumen['facturado']) ?></span>
        <span class="stat__pie"><?= $resumen['pendiente_documentar'] > 0 ? formatoMoneda($resumen['pendiente_documentar']) . ' pendiente de documentar' : ($orden['estado_cfdi'] === 'no_facturada' ? 'Compra sin factura' : 'Con CFDI adjunto') ?></span>
    </article>
    <article class="stat">
        <span class="stat__etiqueta">Pagado</span>
        <span class="stat__valor cifra"><?= formatoMoneda($resumen['pagado']) ?></span>
        <span class="stat__pie"><?= count(array_filter($pagos, static fn ($p) => $p['estado'] === 'vigente')) ?> pago(s) vigente(s)</span>
    </article>
    <article class="stat">
        <span class="stat__etiqueta">Saldo pendiente</span>
        <span class="stat__valor cifra"><?= $cancelada ? '—' : formatoMoneda($resumen['saldo']) ?></span>
        <span class="stat__pie"><?= $gastoVigente ? 'En contabilidad: gasto #' . (int) $gasto['id'] : 'Aún sin enviar a contabilidad' ?></span>
    </article>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Partidas</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Comparativo comprado contra recibido</caption>
            <thead><tr>
                <th scope="col">Imagen</th>
                <th scope="col">Artículo</th>
                <th scope="col" class="tabla--numerica">Comprado</th>
                <th scope="col" class="tabla--numerica">Recibido</th>
                <th scope="col" class="tabla--numerica">Pendiente</th>
                <th scope="col" class="tabla--numerica">Costo pactado</th>
                <th scope="col" class="tabla--numerica">Costo recibido</th>
                <th scope="col" class="tabla--numerica">Importe</th>
                <?php if ($orden['iva_tasa'] !== null) : ?><th scope="col" class="tabla--numerica">IVA</th><?php endif; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($partidas as $p) : ?>
                    <tr>
                        <td>
                            <?php $imagenArticulo = $p['catalogo_imagen'] ?: ($p['producto_imagen'] ?? ''); ?>
                            <?php if ($imagenArticulo) : ?>
                                <a href="<?= escaparValores(\App\Imagenes::url($imagenArticulo)) ?>" target="_blank" rel="noopener">
                                    <img src="<?= escaparValores(\App\Imagenes::urlMiniatura($imagenArticulo)) ?>" alt="<?= escaparValores($p['descripcion'] ?: $p['nombre_mostrado']) ?>"
                                         width="64" height="64" loading="lazy" style="object-fit:cover;border-radius:.5rem;display:block">
                                </a>
                            <?php else : ?>
                                <span class="material-symbols-outlined texto-secundario" aria-hidden="true" title="Sin imagen">image</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= escaparValores($p['descripcion'] ?: $p['nombre_mostrado']) ?></strong>
                            <?php if ($p['codigo']) : ?><span class="texto-xs texto-secundario">· <?= escaparValores($p['codigo']) ?></span><?php endif; ?>
                            <?php if ($p['proveedor_producto_id']) : ?>
                                <span class="texto-xs"><br><a href="/admin/compras/catalogo/detalle?id=<?= (int) $p['proveedor_producto_id'] ?>">Ver en el catálogo del proveedor</a></span>
                            <?php endif; ?>
                            <span class="texto-xs texto-secundario"><br>
                                <?php if ($p['producto_id']) : ?>Catálogo: <?= escaparValores(($p['producto_sku'] ? $p['producto_sku'] . ' · ' : '') . $p['producto_nombre']) ?>
                                <?php elseif ($p['material_id']) : ?>Material controlado: <?= escaparValores($p['material_nombre']) ?>
                                <?php elseif ($esInsumo) : ?>Consumible, sin inventario
                                <?php else : ?><span class="badge badge--pendiente">Sin relacionar con el catálogo</span><?php endif; ?>
                            </span>
                            <?php if (!$esInsumo && !$p['producto_id'] && !$cancelada) : ?>
                                <form class="formulario mt-sm" action="/admin/compras/accion" method="post" data-validar>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="accion" value="relacionar">
                                    <input type="hidden" name="partida" value="<?= (int) $p['id'] ?>">
                                    <label class="solo-lectores" for="rel-<?= (int) $p['id'] ?>">Producto del catálogo</label>
                                    <select class="control control--compacto" id="rel-<?= (int) $p['id'] ?>" name="producto_id" required>
                                        <option value="">— Relacionar con… —</option>
                                        <?php foreach (\App\Productos::paraCotizador() as $prod) : ?>
                                            <option value="<?= (int) $prod['id'] ?>"><?= escaparValores(($prod['sku'] ? $prod['sku'] . ' · ' : '') . $prod['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn--secundario btn--sm" type="submit">Relacionar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td class="tabla--numerica cifra"><?= $p['solicitada'] ?> <span class="texto-xs"><?= escaparValores($p['unidad_mostrada']) ?></span></td>
                        <td class="tabla--numerica cifra"><?= (int) $p['controla'] ? $p['recibida'] : '—' ?></td>
                        <td class="tabla--numerica cifra">
                            <?php if (!(int) $p['controla']) : ?>—
                            <?php else : ?><?= $p['pendiente'] > 0 ? '<strong>' . $p['pendiente'] . '</strong>' : ($p['pendiente'] < 0 ? '+' . abs($p['pendiente']) . ' excedente' : '0') ?><?php endif; ?>
                        </td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($p['costo_esperado']) ?>
                            <?php if ($p['costo_modificado']) : ?><span class="texto-xs"><br><span class="badge badge--pendiente">Costo modificado</span>
                                <span class="texto-secundario">catálogo: <?= formatoMoneda($p['costo_catalogo']) ?></span></span><?php endif; ?></td>
                        <td class="tabla--numerica cifra"><?= $p['costo_final'] !== null ? formatoMoneda($p['costo_final']) : '—' ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($p['importe_esperado']) ?></td>
                        <?php if ($orden['iva_tasa'] !== null) : ?>
                            <td class="tabla--numerica cifra">
                                <?php if ((int) $p['aplica_iva'] === 1) : ?>
                                    <span class="badge badge--info badge--sin-punto"><?= rtrim(rtrim(number_format((float) $orden['iva_tasa'], 2, '.', ''), '0'), '.') ?> %</span>
                                <?php else : ?><span class="texto-secundario" title="Partida sin IVA">Sin IVA</span><?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="tarjeta">
    <div class="encabezado-pagina__acciones">
        <?php if ($puedeRecibir && array_filter($partidas, static fn ($p) => (int) $p['controla'])) : ?>
            <a class="btn btn--primario" href="/admin/compras/recibir?id=<?= $id ?>">Registrar recepción</a>
        <?php endif; ?>
        <?php if ($puedeEditar) : ?>
            <a class="btn btn--secundario" href="/admin/compras/editar?id=<?= $id ?>">Editar compra</a>
            <form action="/admin/compras/accion" method="post" data-validar
                  data-confirmacion-sobre="Compra <?= escaparValores($orden['folio']) ?>"
                  data-confirmacion="¿Cancelar esta compra?"
                  data-confirmacion-mensaje="La compra quedará cancelada. No se ha recibido mercancía ni se ha pagado."
                  data-confirmacion-boton="Sí, cancelar">
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cancelar">
                <button class="btn btn--peligro" type="submit">Cancelar compra</button>
            </form>
        <?php elseif (!$cancelada && in_array($orden['estado'], ['solicitada', 'sin_recepcion'], true)) : ?>
            <p class="texto-secundario texto-sm">Para editarla o cancelarla primero hay que anular sus pagos<?= $gastoVigente ? ' y cancelar su gasto en contabilidad' : '' ?>.</p>
        <?php endif; ?>
    </div>

    <?php if ($puedeCerrar) : ?>
        <form class="formulario mt-lg" action="/admin/compras/accion" method="post" data-validar
              data-confirmacion-sobre="Compra <?= escaparValores($orden['folio']) ?>"
              data-confirmacion="¿Cerrar la compra?"
              data-confirmacion-mensaje="<?= $totales['piezas_pendientes'] > 0
                  ? 'Hay ' . $totales['piezas_pendientes'] . ' pieza(s) sin recibir; quedarán como faltantes. Podrás reabrirla solo con un motivo.'
                  : 'Después de cerrar, solo se puede corregir reabriéndola con un motivo (queda auditado).' ?>"
              data-confirmacion-boton="Sí, cerrar">
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cerrar">
            <div class="campo">
                <label class="campo__etiqueta" for="motivo_cierre">Nota de cierre (opcional)</label>
                <input class="control" type="text" id="motivo_cierre" name="motivo" maxlength="255"
                       placeholder="Ej. El proveedor no surtió el resto">
            </div>
            <button class="btn btn--secundario" type="submit">Cerrar compra<?= $totales['piezas_pendientes'] > 0 ? ' con faltantes' : '' ?></button>
        </form>
    <?php endif; ?>

    <?php if ($orden['estado'] === 'cerrada') : ?>
        <form class="formulario" action="/admin/compras/accion" method="post" data-validar>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="reabrir">
            <div class="campo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="motivo_reapertura">Motivo para reabrir (queda auditado)</label>
                <input class="control" type="text" id="motivo_reapertura" name="motivo" maxlength="255" required minlength="5">
            </div>
            <button class="btn btn--secundario" type="submit">Reabrir compra</button>
        </form>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Historial de recepciones</h2>
    <?php if (!$recepciones) : ?>
        <p class="texto-secundario" style="padding:1rem"><?= $orden['estado'] === 'sin_recepcion' ? 'Esta compra no lleva recepción (consumibles).' : 'Aún no se ha recibido mercancía.' ?></p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr><th scope="col">Fecha</th><th scope="col">Artículo</th><th scope="col">Destino</th>
                    <th scope="col" class="tabla--numerica">Cantidad</th><th scope="col" class="tabla--numerica">Costo unitario</th>
                    <th scope="col">Registró</th></tr></thead>
                <tbody>
                    <?php
                    $destinos = ['por_terminar' => 'Por terminar', 'stock' => 'Disponible para venta', 'material' => 'Material en inventario'];
                    foreach ($recepciones as $r) : foreach ($r['renglones'] as $l) : ?>
                        <tr>
                            <td><?= escaparValores(date('d/m/Y', strtotime($r['fecha']))) ?></td>
                            <td><?= escaparValores($l['producto_nombre'] ?? $l['material_nombre'] ?? '—') ?></td>
                            <td><?= escaparValores($destinos[$l['destino']] ?? $l['destino']) ?></td>
                            <td class="tabla--numerica cifra"><?= \App\OrdenesCompra::num($l['cantidad_recibida']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($l['costo_unitario']) ?></td>
                            <td><?= escaparValores($r['usuario_nombre'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (!$cancelada) : ?>
<section class="tarjeta tarjeta--sin-relleno mt-lg" id="factura">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Factura y comprobantes</h2>
    <p class="texto-sm texto-secundario" style="padding:.5rem 1rem 0">
        Estado: <span class="badge <?= $cfdi['clase'] ?>"><?= escaparValores($cfdi['etiqueta']) ?></span>
        · Adjuntar un documento no vuelve deducible la compra: la revisión fiscal la valida tu contador.
        Un PDF o una foto no cuenta como CFDI; solo el XML con timbre.
    </p>

    <?php if (!$documentos) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no hay documentos adjuntos.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Documentos de la compra</caption>
            <thead><tr><th scope="col">Archivo</th><th scope="col">Revisión documental</th><th scope="col">Cargado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($documentos as $d) : $val = \App\Archivos::etiquetaValidacion($d['estado_validacion']); ?>
                <tr<?= (int) $d['activo'] === 0 ? ' style="opacity:.6"' : '' ?>>
                    <td><strong><?= escaparValores($d['nombre_original']) ?></strong>
                        <span class="texto-xs texto-secundario"><br><?= escaparValores(\App\Archivos::TIPOS[$d['tipo']] ?? $d['tipo']) ?>
                            <?= $d['uuid_fiscal'] ? ' · UUID ' . escaparValores($d['uuid_fiscal']) : '' ?>
                            <?= (int) $d['activo'] === 0 ? ' · <strong>De baja</strong>: ' . escaparValores((string) $d['motivo_baja']) : '' ?></span>
                        <?php foreach ($d['cfdi']['alertas'] ?? [] as $alerta) : ?>
                            <span class="texto-xs texto-error"><br>⚠ <?= escaparValores($alerta) ?></span>
                        <?php endforeach; ?></td>
                    <td><span class="badge <?= $val['clase'] ?>"><?= escaparValores($val['etiqueta']) ?></span></td>
                    <td class="texto-sm"><?= date('d/m/Y H:i', strtotime($d['creado_en'])) ?></td>
                    <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/contabilidad/documentos/descargar?id=<?= (int) $d['id'] ?>">Descargar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($gastoVigente) : ?>
            <p class="texto-xs texto-secundario" style="padding:0 1rem 1rem">Para sustituir o dar de baja un documento usa el
                <a href="/admin/contabilidad/gastos/detalle?id=<?= (int) $gasto['id'] ?>#documentos">gasto #<?= (int) $gasto['id'] ?> en contabilidad</a>; esta compra se actualiza sola.</p>
        <?php endif; ?>
    <?php endif; ?>

    <form class="formulario" style="padding:1rem" action="/admin/compras/comprobante" method="post" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="accion" value="adjuntar">
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="comp-archivo">Adjuntar factura o comprobante</label>
                <input class="control" type="file" id="comp-archivo" name="archivo[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                <span class="campo__ayuda">XML del CFDI, PDF o foto del ticket. Si la compra aún no estaba en contabilidad, se envía una sola vez; si el CFDI llega después, se asocia al mismo registro.</span>
            </div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Subir archivos</button></div>
    </form>

    <div style="padding:0 1rem 1rem">
        <?php if (!$gastoVigente) : ?>
            <form action="/admin/compras/comprobante" method="post" data-validar
                  data-confirmacion-sobre="Compra <?= escaparValores($orden['folio']) ?>"
                  data-confirmacion="¿Enviar esta compra a contabilidad?"
                  data-confirmacion-mensaje="Se creará UN gasto con el importe de la compra. Si ya existe uno, se reutiliza; nunca se duplica. La revisión fiscal queda «por validar»."
                  data-confirmacion-boton="Sí, enviar">
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="enviar">
                <button class="btn btn--secundario" type="submit">Enviar a contabilidad (sin documento)</button>
            </form>
        <?php else : ?>
            <p class="texto-sm">En contabilidad: <a href="/admin/contabilidad/gastos/detalle?id=<?= (int) $gasto['id'] ?>">gasto #<?= (int) $gasto['id'] ?></a>
                · revisión fiscal: <strong><?= escaparValores(\App\Gastos::REVISION[$gasto['revision_fiscal']]['etiqueta'] ?? $gasto['revision_fiscal']) ?></strong></p>
        <?php endif; ?>

        <?php if ($orden['estado_cfdi'] !== 'con_cfdi') : ?>
            <form class="formulario mt-md" action="/admin/compras/comprobante" method="post" data-validar>
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="estado_cfdi">
                <div class="campo">
                    <label class="campo__etiqueta" for="estado_cfdi">Estado de la factura</label>
                    <select class="control" id="estado_cfdi" name="estado_cfdi">
                        <option value="pendiente_cfdi"<?= $orden['estado_cfdi'] === 'pendiente_cfdi' ? ' selected' : '' ?>>CFDI pendiente</option>
                        <option value="no_facturada"<?= $orden['estado_cfdi'] === 'no_facturada' ? ' selected' : '' ?>>Sin factura (no se facturó)</option>
                    </select>
                </div>
                <button class="btn btn--secundario btn--sm" type="submit">Guardar estado</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno mt-lg" id="pagos">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Pagos al proveedor</h2>
    <?php if (!$pagos) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no se registran pagos.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Pagos de la compra</caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col" class="tabla--numerica">Importe</th><th scope="col">Método</th>
                <th scope="col">Referencia</th><th scope="col">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($pagos as $pg) : $vigente = $pg['estado'] === 'vigente'; ?>
                <tr<?= $vigente ? '' : ' style="opacity:.6"' ?>>
                    <td><?= escaparValores(date('d/m/Y', strtotime($pg['fecha']))) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($pg['importe']) ?></td>
                    <td><?= escaparValores((string) $pg['metodo']) ?></td>
                    <td><?= escaparValores((string) $pg['referencia']) ?></td>
                    <td><?= $vigente ? '<span class="badge badge--aprobado">Vigente</span>' : '<span class="badge badge--neutro">Cancelado</span><span class="texto-xs texto-secundario"><br>' . escaparValores((string) $pg['motivo_cancelacion']) . '</span>' ?></td>
                    <td class="tabla--numerica">
                        <?php if ($vigente) : ?>
                            <details>
                                <summary class="btn btn--texto btn--sm">Cancelar pago</summary>
                                <form class="formulario mt-sm" action="/admin/compras/pago" method="post" data-validar
                                      data-confirmacion-sobre="Pago de <?= escaparValores(formatoMoneda($pg['importe'])) ?>"
                                      data-confirmacion="¿Cancelar este pago?"
                                      data-confirmacion-mensaje="El saldo de la compra vuelve a subir. El pago no se borra: queda cancelado con su motivo."
                                      data-confirmacion-boton="Sí, cancelar pago">
                                    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cancelar">
                                    <input type="hidden" name="pago" value="<?= (int) $pg['id'] ?>">
                                    <label class="campo__etiqueta campo__etiqueta--obligatorio" for="mp-<?= (int) $pg['id'] ?>">Motivo</label>
                                    <input class="control" type="text" id="mp-<?= (int) $pg['id'] ?>" name="motivo" maxlength="255" required minlength="5">
                                    <button class="btn btn--peligro btn--sm" type="submit">Cancelar pago</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <?php if ($resumen['saldo'] > 0) : ?>
        <form class="formulario" style="padding:1rem" action="/admin/compras/pago" method="post" data-validar
              data-confirmacion-sobre="Compra <?= escaparValores($orden['folio']) ?>"
              data-confirmacion="¿Registrar este pago?"
              data-confirmacion-mensaje="Reduce el saldo pendiente. No crea un gasto nuevo."
              data-confirmacion-boton="Sí, registrar pago">
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="registrar">
            <input type="hidden" name="token" value="<?= bin2hex(random_bytes(16)) ?>">
            <div class="formulario__rejilla">
                <div class="campo">
                    <label class="campo__etiqueta campo__etiqueta--obligatorio" for="pago-fecha">Fecha del pago</label>
                    <input class="control" type="date" id="pago-fecha" name="fecha" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="campo">
                    <label class="campo__etiqueta campo__etiqueta--obligatorio" for="pago-importe">Importe</label>
                    <input class="control control--moneda" type="number" id="pago-importe" name="importe" required min="0.01" step="0.01" inputmode="decimal"
                           max="<?= escaparValores(number_format($resumen['saldo'], 2, '.', '')) ?>" value="<?= escaparValores(number_format($resumen['saldo'], 2, '.', '')) ?>">
                    <span class="campo__ayuda">Saldo pendiente: <?= formatoMoneda($resumen['saldo']) ?>. Puedes pagar una parte.</span>
                </div>
                <div class="campo">
                    <label class="campo__etiqueta" for="pago-metodo">Método</label>
                    <select class="control" id="pago-metodo" name="metodo">
                        <option value="">—</option>
                        <?php foreach (\App\Contabilidad::METODOS_PAGO as $m) : ?><option value="<?= escaparValores($m) ?>"><?= escaparValores($m) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label class="campo__etiqueta" for="pago-ref">Referencia</label>
                    <input class="control" type="text" id="pago-ref" name="referencia" maxlength="80" placeholder="Folio de la transferencia, cheque…">
                </div>
            </div>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Registrar pago</button></div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php template('footer'); ?>
