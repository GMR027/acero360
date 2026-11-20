<?php
/**
 * templates/venta/incidencias.php — Incidencias y devoluciones del expediente.
 *
 * Incidencias de entrega (faltantes, dañados, cambios) y, debajo, el bloque de
 * devoluciones y cambios que ya existía (templates/devolucionesVenta.php).
 *
 * Variables: $id, $venta, $incidencias, $ingreso, $registrada, $cobradoExtra, $rutaVenta.
 */
?>
<section class="tarjeta mb-lg" id="incidencias" style="scroll-margin-top:5rem">
    <div class="tabla-toolbar">
        <div>
            <h2 class="titulo-4">Incidencias de entrega</h2>
            <p class="texto-secundario texto-sm mb-0">
                Producto faltante, dañado, incompleto, cambio o devolución. Lo que exige corregir factura se marca aquí.
            </p>
        </div>
        <?php if ($registrada) : ?>
            <a class="btn btn--primario btn--sm" href="/admin/entregas-seguimiento/incidencia?id=<?= $id ?>"
               data-modal-form="tpl-incidencia"
               data-modal-sobre-titulo="Venta <?= escaparValores($venta->folio) ?>"
               data-modal-titulo="Registrar incidencia"
               data-modal-confirmar="Registrar incidencia">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">report</span>
                <span>Registrar incidencia</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$incidencias) : ?>
        <p class="nota nota--info" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">check_circle</span>
            <span>Sin incidencias: no se reportó nada después de la entrega (producto faltante, dañado, incompleto, cambio o devolución).</span>
        </p>
    <?php endif; ?>

    <?php foreach ($incidencias as $incidencia) :
        $motivo = \App\IncidenciasEntrega::MOTIVOS[$incidencia['motivo']] ?? null;
        $estadoI = \App\IncidenciasEntrega::ESTADOS[$incidencia['estado']] ?? null;
        $abierta = $incidencia['estado'] === 'abierta'; ?>
        <article class="tarjeta tarjeta--sin-relleno mb-md" id="incidencia-<?= (int) $incidencia['id'] ?>">
            <div class="tabla-toolbar">
                <div>
                    <strong><?= escaparValores($motivo['etiqueta'] ?? $incidencia['motivo']) ?></strong>
                    <?php if ($estadoI) : ?><span class="badge <?= $estadoI['clase'] ?>"><?= escaparValores($estadoI['etiqueta']) ?></span><?php endif; ?>
                    <?php if ($incidencia['factura_requerida']) : ?><span class="badge badge--error">Corregir factura</span><?php endif; ?>
                    <span class="texto-xs texto-secundario"><br>Reportada el <?= date('d/m/Y H:i', strtotime($incidencia['creado_en'])) ?> por <?= escaparValores($incidencia['usuario_nombre'] ?? '—') ?>
                    <?php if ($incidencia['fecha_entrega']) : ?> · de la entrega del <?= date('d/m/Y', strtotime($incidencia['fecha_entrega'])) ?><?php endif; ?></span>
                </div>
                <?php if ($abierta && $registrada) : ?>
                    <a class="btn btn--secundario btn--sm" href="#resolver-<?= (int) $incidencia['id'] ?>">Resolver</a>
                <?php endif; ?>
            </div>

            <?php if ($incidencia['notas']) : ?>
                <p class="mb-md"><?= nl2br(escaparValores($incidencia['notas'])) ?></p>
            <?php endif; ?>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <caption class="solo-lectores">Productos afectados por la incidencia</caption>
                    <thead><tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="tabla--numerica">Piezas afectadas</th>
                        <th scope="col" class="tabla--numerica">Se le debe al cliente</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($incidencia['renglones'] as $r) : ?>
                            <tr>
                                <td><strong><?= escaparValores($r['producto_nombre']) ?></strong>
                                    <span class="texto-xs texto-secundario movil-oculto"><br><?= escaparValores($r['sku']) ?></span></td>
                                <td class="tabla--numerica cifra"><?= (int) $r['cantidad'] ?></td>
                                <td class="tabla--numerica cifra"><?= $r['requiere_cambio'] ? '<span class="badge badge--info">' . (int) $r['cantidad'] . ' por entregar</span>' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($abierta && $registrada) : ?>
                <details class="seguimiento-resolucion" id="resolver-<?= (int) $incidencia['id'] ?>">
                    <summary>Resolver incidencia<?= $incidencia['factura_requerida'] ? ' y sustituir la factura' : '' ?></summary>
                    <form class="formulario" action="/admin/entregas-seguimiento/cerrar" method="post" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="incidencia_id" value="<?= (int) $incidencia['id'] ?>">
                        <input type="hidden" name="accion" value="resolver">
                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">

                        <?php if ($incidencia['factura_requerida']) : ?>
                            <p class="nota nota--advertencia" role="status">
                                <span class="material-symbols-outlined nota__icono" aria-hidden="true">receipt_long</span>
                                <span>Al resolver se <strong>cambia la factura de esta venta</strong>: los documentos actuales
                                    <?php if ($ingreso && $ingreso['uuid_fiscal']) : ?> (UUID <?= escaparValores($ingreso['uuid_fiscal']) ?>)<?php endif; ?>
                                    se dan de baja y los nuevos sustituyen folio, UUID, fecha y montos en contabilidad.
                                    Los cobros ya registrados se conservan: si quedaron de más que el nuevo total, el sistema lo avisará.</span>
                            </p>
                            <?php if ($ingreso && $cobradoExtra) : ?>
                                <p class="nota nota--error" role="alert">
                                    <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
                                    <span>La venta ya tiene cobros por <strong><?= escaparValores('$' . number_format((float) $ingreso['cobrado'], 2)) ?></strong>.
                                        Si la factura correcta es por menos, revisa los <a href="#facturacion">cobros de la venta</a> para cancelar o ajustar.</span>
                                </p>
                            <?php endif; ?>
                            <div class="formulario__rejilla">
                                <div class="campo">
                                    <label class="campo__etiqueta" for="factura-pdf-<?= (int) $incidencia['id'] ?>">Factura correcta (PDF) *</label>
                                    <input class="control" type="file" id="factura-pdf-<?= (int) $incidencia['id'] ?>" name="factura_pdf" accept=".pdf,application/pdf" required>
                                </div>
                                <div class="campo">
                                    <label class="campo__etiqueta" for="factura-xml-<?= (int) $incidencia['id'] ?>">Factura correcta (XML del CFDI) *</label>
                                    <input class="control" type="file" id="factura-xml-<?= (int) $incidencia['id'] ?>" name="factura_xml" accept=".xml,text/xml,application/xml" required>
                                    <span class="campo__ayuda">Del XML se leen UUID, folio, fecha y montos para sincronizar el ingreso.</span>
                                </div>
                                <div class="campo">
                                    <label class="campo__etiqueta" for="fecha-factura-<?= (int) $incidencia['id'] ?>">Fecha de la factura (opcional)</label>
                                    <input class="control" type="date" id="fecha-factura-<?= (int) $incidencia['id'] ?>" name="fecha_factura"
                                           value="<?= date('Y-m-d') ?>">
                                    <span class="campo__ayuda">Si la dejas vacía se toma la del XML.</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="campo mt-md">
                            <label class="campo__etiqueta" for="resolucion-<?= (int) $incidencia['id'] ?>">¿Cómo se resolvió? *</label>
                            <textarea class="control" id="resolucion-<?= (int) $incidencia['id'] ?>" name="resolucion" rows="2" maxlength="400" required
                                      placeholder="Se reprogramó la entrega, el cliente aceptó la pieza, se sustituyó la factura…"></textarea>
                        </div>

                        <div class="formulario__acciones">
                            <button class="btn btn--primario" type="submit" data-un-envio>
                                <?= $incidencia['factura_requerida'] ? 'Resolver y sustituir factura' : 'Resolver incidencia' ?>
                            </button>
                        </div>
                    </form>

                    <form class="formulario" action="/admin/entregas-seguimiento/cerrar" method="post" novalidate>
                        <input type="hidden" name="incidencia_id" value="<?= (int) $incidencia['id'] ?>">
                        <input type="hidden" name="accion" value="cancelar">
                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                        <div class="campo">
                            <label class="campo__etiqueta" for="motivo-cancelar-<?= (int) $incidencia['id'] ?>">O cancelar la incidencia (reportada por error) *</label>
                            <input class="control" type="text" id="motivo-cancelar-<?= (int) $incidencia['id'] ?>" name="resolucion" maxlength="400" required
                                   placeholder="Motivo de la cancelación">
                        </div>
                        <div class="formulario__acciones">
                            <button class="btn btn--secundario" type="submit" data-un-envio>Cancelar incidencia</button>
                        </div>
                    </form>
                </details>
            <?php else : ?>
                <div class="seguimiento-cierre">
                    <p class="texto-sm mb-0">
                        <strong>Resolución:</strong> <?= escaparValores((string) ($incidencia['resolucion'] ?: '—')) ?><br>
                        <span class="texto-secundario">Cerrada el <?= $incidencia['resuelta_en'] ? date('d/m/Y H:i', strtotime($incidencia['resuelta_en'])) : '—' ?>
                        por <?= escaparValores($incidencia['resuelta_por_nombre'] ?? '—') ?></span>
                    </p>
                    <?php if (!empty($incidencia['documento'])) : ?>
                        <p class="texto-sm mb-0">
                            <span class="badge badge--aprobado">Factura correcta</span>
                            <?= escaparValores($incidencia['documento']['nombre_original']) ?>
                            (<a href="/admin/contabilidad/documentos/descargar?id=<?= (int) $incidencia['documento']['id'] ?>">descargar</a>)
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>

<?php template('devolucionesVenta', [
    'ventaId'         => $id,
    'registrada'      => $registrada,
    'botonDevolucion' => true,
]); ?>
