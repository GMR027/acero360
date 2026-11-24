<?php
/**
 * templates/venta/entrega.php — Entrega y acta dentro del expediente de la venta.
 *
 * Reúne lo que antes estaba repartido entre el detalle de la venta (subir fotos)
 * y el hub de Operación Comercial (fecha/observaciones, vendido vs. entregado,
 * fotos de cada entrega, ubicación y actas). Una sola sección, sin duplicar.
 *
 * Variables: $id, $venta, $pendientes, $entregas, $afectadas, $vendidas,
 *            $entregadas, $faltan, $registrada, $rutaVenta.
 */
?>
<section class="tarjeta mb-lg" id="entrega" style="scroll-margin-top:5rem">
    <div class="tabla-toolbar">
        <div>
            <h2 class="titulo-4">Entrega y acta</h2>
            <p class="texto-secundario texto-sm mb-0">
                Entregado: <strong class="cifra"><?= $entregadas ?></strong> de <?= $vendidas ?> pieza<?= $vendidas === 1 ? '' : 's' ?><?= $faltan > 0 ? ' · faltan ' . $faltan : ' (todo entregado)' ?>
                <?= !empty($venta->entrega_cerrada_en) ? ' · entrega cerrada el ' . date('d/m/Y', strtotime($venta->entrega_cerrada_en)) : '' ?>
            </p>
            <div class="expediente-progreso">
                <progress value="<?= (int) $entregadas ?>" max="<?= max(1, (int) $vendidas) ?>"></progress>
                <strong><?= (int) $entregadas ?>/<?= (int) $vendidas ?></strong>
                <span><?= $faltan > 0 ? 'faltan ' . (int) $faltan : 'completa' ?></span>
            </div>
        </div>
        <div class="encabezado-pagina__acciones">
            <?php if ($entregas) : ?>
                <a class="btn btn--secundario btn--sm" href="/admin/entregas-seguimiento/acta?id=<?= $id ?>">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
                    <span>Acta de entrega (PDF)</span>
                </a>
            <?php endif; ?>
            <?php if ($registrada && $faltan > 0) : ?>
                <a class="btn btn--secundario btn--sm" href="/admin/entregas/entregar?venta=<?= $id ?>&volver=seguimiento"
                   data-modal-form="tpl-entrega"
                   data-modal-sobre-titulo="Venta <?= escaparValores($venta->folio) ?>"
                   data-modal-titulo="Registrar entrega"
                   data-modal-confirmar="Confirmar entrega">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">local_shipping</span>
                    <span>Registrar entrega</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$registrada) : ?>
        <p class="nota nota--advertencia" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
            <span>La venta ya no está registrada: el seguimiento queda solo como historial.</span>
        </p>
    <?php endif; ?>

    <details class="expediente-plegable" id="seguimiento-entrega"<?= (trim((string) $venta->entrega_fecha) === '' && trim((string) $venta->entrega_observaciones) === '') ? ' open' : '' ?>>
        <summary>
            <span class="material-symbols-outlined" aria-hidden="true">edit_calendar</span>
            <span>Fecha y observaciones de entrega</span>
            <small><?= trim((string) $venta->entrega_fecha) !== '' ? date('d/m/Y', strtotime((string) $venta->entrega_fecha)) : 'Sin programar' ?><?= trim((string) $venta->entrega_observaciones) !== '' ? ' · con observaciones' : '' ?></small>
        </summary>
        <div class="expediente-plegable__cuerpo">
            <form class="formulario" action="<?= escaparValores($rutaVenta) ?>" method="post" novalidate>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="accion" value="datos">
                <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                <div class="formulario__rejilla">
                    <div class="campo">
                        <label class="campo__etiqueta" for="fecha-entrega">Fecha de entrega</label>
                        <input class="control" type="date" id="fecha-entrega" name="fecha" value="<?= escaparValores((string) $venta->entrega_fecha) ?>"
                               <?= $registrada ? '' : 'disabled' ?>>
                        <span class="campo__ayuda">Fecha prometida o acordada con el cliente.</span>
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="observaciones">Observaciones de entrega</label>
                        <textarea class="control" id="observaciones" name="observaciones" rows="2" maxlength="1000"
                                  placeholder="Quién recibe, condiciones de la entrega, acuerdos…" <?= $registrada ? '' : 'disabled' ?>><?= escaparValores((string) $venta->entrega_observaciones) ?></textarea>
                    </div>
                </div>
                <?php if ($registrada) : ?>
                    <div class="formulario__acciones">
                        <button class="btn btn--primario" type="submit" data-un-envio>Guardar seguimiento</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </details>
</section>

<!-- Productos: vendido vs entregado vs incidencias -->
<section class="tarjeta mb-lg">
    <div class="tabla-toolbar">
        <h2 class="titulo-5">Productos de la venta</h2>
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

    <?php if (!$pendientes) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">inventory_2</span>
            <p class="vacio__titulo">La venta no tiene productos</p>
            <p>Revisa el detalle de <a href="#resumen">la venta</a>.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Vendido, entregado y afectado por incidencias por producto</caption>
                <thead><tr>
                    <th scope="col">Producto</th>
                    <th scope="col" class="tabla--numerica">Vendido</th>
                    <th scope="col" class="tabla--numerica">Entregado</th>
                    <th scope="col" class="tabla--numerica movil-oculto">Incidencias</th>
                    <th scope="col" class="tabla--numerica">Pendiente</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($pendientes as $l) : $pid = (int) $l['producto_id']; ?>
                        <tr>
                            <td><strong><?= escaparValores($l['nombre']) ?></strong>
                                <span class="texto-xs texto-secundario movil-oculto"><br><?= escaparValores($l['sku']) ?> · <?= escaparValores($l['unidad']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= (int) $l['vendido'] ?></td>
                            <td class="tabla--numerica cifra"><?= (int) $l['entregado'] ?></td>
                            <td class="tabla--numerica cifra movil-oculto"><?= ($afectadas[$pid] ?? 0) > 0 ? '<span class="badge badge--info">' . (int) $afectadas[$pid] . '</span>' : '—' ?></td>
                            <td class="tabla--numerica cifra">
                                <?= (int) $l['pendiente'] > 0 ? '<span class="badge badge--pendiente">' . (int) $l['pendiente'] . '</span>' : '<span class="badge badge--aprobado">0</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
// Fotos del acta y ubicación manual: una sección por cada entrega registrada.
?>
<section class="tarjeta mb-lg" id="fotos-entrega" style="scroll-margin-top:5rem">
    <div class="tabla-toolbar">
        <div>
            <h2 class="titulo-4">Fotos de la entrega</h2>
            <p class="texto-secundario texto-sm mb-0">
                Adjuntadas al registrar cada entrega. Al abrirlas se muestran con la marca de agua del negocio,
                la fecha, la hora y la ubicación. Si una foto no trae GPS, aquí puedes dejar la ubicación manual de su entrega.
            </p>
        </div>
    </div>

    <?php if (!$entregas) : ?>
        <p class="texto-secundario">
            Esta venta todavía no tiene entregas registradas. Registra la entrega para poder adjuntar las fotos del acta.
        </p>
        <?php if ($registrada && $faltan > 0) : ?>
            <a class="btn btn--primario btn--sm" href="/admin/entregas/entregar?venta=<?= $id ?>&volver=seguimiento"
               data-modal-form="tpl-entrega"
               data-modal-sobre-titulo="Venta <?= escaparValores($venta->folio) ?>"
               data-modal-titulo="Registrar entrega"
               data-modal-confirmar="Confirmar entrega">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">local_shipping</span>
                <span>Registrar entrega</span>
            </a>
        <?php endif; ?>
    <?php else : ?>
        <?php foreach ($entregas as $e) : ?>
            <p class="texto-sm mb-0">
                <strong><?= date('d/m/Y', strtotime((string) $e['fecha'])) ?></strong>
                · Registró <?= escaparValores((string) ($e['usuario_nombre'] ?? '—')) ?>
                <?php if (!empty($e['recibio_nombre'])) : ?> · Recibió <?= escaparValores((string) $e['recibio_nombre']) ?><?php endif; ?>
                · Origen <?= ($e['origen'] ?? 'web') === 'app' ? 'App' : 'Web' ?>
            </p>
            <?php template('galeriaFotosEntrega', [
                'entrega'          => $e,
                'direccionCliente' => (string) $venta->cliente_ubicacion,
            ]); ?>

            <p class="texto-sm mb-0">
                <a class="btn btn--texto btn--sm" href="/admin/entregas-seguimiento/acta?id=<?= $id ?>&entrega=<?= (int) $e['id'] ?>">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
                    <span>Acta de esta entrega (PDF)</span>
                </a>
            </p>

            <details class="expediente-avanzado">
                <summary>
                    <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
                    <span>Ubicación de esta entrega</span>
                    <small><?= trim((string) ($e['ubicacion_maps'] ?? '')) !== '' ? escaparValores((string) $e['ubicacion_maps']) : 'Sin ubicación manual' ?></small>
                </summary>
                <div class="expediente-avanzado__cuerpo">
                    <form class="formulario formulario--ubicacion-entrega" action="<?= escaparValores($rutaVenta) ?>" method="post" novalidate>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="accion" value="ubicacion">
                        <input type="hidden" name="entrega_id" value="<?= (int) $e['id'] ?>">
                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                        <div class="campo">
                            <label class="campo__etiqueta" for="ubicacion-entrega-<?= (int) $e['id'] ?>">Ubicación de Google Maps</label>
                            <input class="control" type="text" id="ubicacion-entrega-<?= (int) $e['id'] ?>" name="ubicacion"
                                   maxlength="500" value="<?= escaparValores((string) ($e['ubicacion_maps'] ?? '')) ?>"
                                   placeholder="Enlace de Google Maps, coordenadas o dirección">
                            <button class="btn btn--secundario btn--sm mt-sm" type="button"
                                    data-usar-ubicacion="#ubicacion-entrega-<?= (int) $e['id'] ?>">
                                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">my_location</span>
                                <span>Usar mi ubicación actual</span>
                            </button>
                            <span class="campo__ayuda">
                                Se usa cuando las fotos no traen GPS. Déjala vacía y guarda para quitarla.
                            </span>
                            <span class="campo__ayuda" data-estado-ubicacion hidden></span>
                        </div>
                        <button class="btn btn--secundario btn--sm" type="submit">Guardar ubicación</button>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>

        <details class="expediente-captura" id="subir-fotos-entrega"<?= $entregas ? '' : ' open' ?>>
            <summary>
                <span class="material-symbols-outlined" aria-hidden="true">add_photo_alternate</span>
                <span>
                    <strong>Subir fotos del acta</strong>
                    <small>Hasta <?= (int) \App\Entregas::MAX_FOTOS ?> fotos por envío y <?= (int) \App\Entregas::MAX_FOTOS_TOTAL_MB ?> MB en total · se comprimen antes de enviar</small>
                </span>
            </summary>
            <div class="expediente-captura__cuerpo">
                <form class="formulario" action="<?= escaparValores($rutaVenta) ?>#fotos-entrega" method="post" enctype="multipart/form-data" novalidate data-comprimir-fotos>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="accion" value="fotos">
                    <input type="hidden" name="token" value="<?= tokenFormulario() ?>">

                    <div class="formulario__rejilla">
                        <div class="campo">
                            <label class="campo__etiqueta" for="fotos-entrega-archivos">Fotos (JPG, PNG o WebP)</label>
                            <input class="control" type="file" id="fotos-entrega-archivos" name="fotos[]"
                                   data-max-bytes="<?= (int) \App\Imagenes::TAMANO_MAXIMO_ENTREGA ?>"
                                   accept="image/jpeg,image/png,image/webp" multiple>
                            <span class="campo__ayuda">
                                Cada foto puede pesar hasta <?= (int) ceil(\App\Imagenes::TAMANO_MAXIMO_ENTREGA / 1048576) ?> MB.
                            </span>
                            <span class="campo__ayuda" data-estado-fotos hidden></span>
                        </div>
                        <div class="campo">
                            <label class="campo__etiqueta" for="fotos-entrega-destino">Entrega</label>
                            <select class="control" id="fotos-entrega-destino" name="entrega_id">
                                <?php foreach (array_reverse($entregas) as $e) : $piezas = array_sum(array_column($e['renglones'], 'cantidad')); ?>
                                    <option value="<?= (int) $e['id'] ?>" data-ubicacion="<?= escaparValores((string) ($e['ubicacion_maps'] ?? '')) ?>">
                                        <?= date('d/m/Y', strtotime($e['fecha'])) ?> ·
                                        <?= $piezas ?> pieza<?= $piezas === 1 ? '' : 's' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="campo__ayuda">Se adjuntan a la entrega elegida.</span>
                        </div>
                    </div>

                    <details class="expediente-avanzado">
                        <summary>
                            <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
                            <span>Ubicación manual (opcional)</span>
                            <small>Solo si las fotos no traen GPS</small>
                        </summary>
                        <div class="expediente-avanzado__cuerpo">
                            <div class="campo">
                                <label class="campo__etiqueta" for="fotos-entrega-ubicacion">Ubicación de Google Maps</label>
                                <input class="control" type="text" id="fotos-entrega-ubicacion" name="ubicacion" maxlength="500"
                                       placeholder="Enlace de Google Maps, coordenadas o dirección">
                                <button class="btn btn--secundario btn--sm mt-sm" type="button"
                                        data-usar-ubicacion="#fotos-entrega-ubicacion">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">my_location</span>
                                    <span>Usar mi ubicación actual</span>
                                </button>
                                <span class="campo__ayuda">
                                    Se usa cuando las fotos no traen GPS. El GPS de la foto siempre tiene prioridad.
                                </span>
                                <span class="campo__ayuda" data-estado-ubicacion hidden></span>
                            </div>
                        </div>
                    </details>

                    <div class="formulario__acciones">
                        <button class="btn btn--primario" type="submit">
                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_photo_alternate</span>
                            <span>Subir fotos</span>
                        </button>
                    </div>
                </form>
            </div>
        </details>
    <?php endif; ?>
</section>

<?php if (!empty($intentos)) : ?>
    <details class="expediente-plegable" id="intentos-entrega">
        <summary>
            <span class="material-symbols-outlined" aria-hidden="true">event_busy</span>
            <span>Visitas sin entrega</span>
            <small><?= count($intentos) ?> registro<?= count($intentos) === 1 ? '' : 's' ?> · no mueven inventario ni cambian el importe</small>
        </summary>
        <div class="expediente-plegable__cuerpo">
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Visitas en las que no se pudo entregar</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Motivo</th><th scope="col">Notas</th>
                    <th scope="col">Registró</th><th scope="col" class="tabla--numerica">Fotos</th></tr></thead>
                <tbody>
                    <?php foreach ($intentos as $intento) : ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime((string) $intento['fecha'])) ?></td>
                            <td><strong><?= escaparValores(\App\IntentosEntrega::MOTIVOS[$intento['motivo']]['etiqueta'] ?? (string) $intento['motivo']) ?></strong></td>
                            <td><?= escaparValores((string) ($intento['notas'] ?? '')) ?: '—' ?></td>
                            <td><?= escaparValores((string) ($intento['usuario_nombre'] ?? '—')) ?></td>
                            <td class="tabla--numerica cifra"><?= count($intento['fotos'] ?? []) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </div>
    </details>
<?php endif; ?>
