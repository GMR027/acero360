<?php
/**
 * templates/documentosOperacion.php — Documentos de un ingreso o de un gasto:
 * lista (con validación documental), subida, sustitución y baja.
 *
 * Variables: $entidad ('ingreso'|'gasto'), $entidadId, $retorno (URL a la que volver),
 *            $bloqueado (bool: periodo cerrado), $interna (bool: se pega dentro de otra tarjeta),
 *            $gasto (array, solo en gastos: para el apartado del complemento de pago)
 */
$documentos = \App\Archivos::deOperacion($entidad, $entidadId);
$bloqueado  = $bloqueado ?? false;
$sinSubida  = $sinSubida ?? false;
$interna    = $interna ?? false;
$gasto      = $gasto ?? null;
$maxMb      = \App\Archivos::maxBytes() / 1048576;
$relleno    = $interna ? '' : ' style="padding:1rem"';

// El complemento de pago tiene su propio apartado: se separa de la factura y
// de los comprobantes para que la lista general no lo mezcle con ellos.
$complementos = [];
if ($entidad === 'gasto' && is_array($gasto)) {
    $complementos = array_values(array_filter($documentos, static fn ($d) => ($d['tipo'] ?? '') === 'complemento_pago'));
    $documentos = array_values(array_filter($documentos, static fn ($d) => ($d['tipo'] ?? '') !== 'complemento_pago'));
}
?>
<?php if ($interna) : ?><div id="documentos"><?php else : ?><section class="tarjeta tarjeta--sin-relleno mt-lg" id="documentos"><?php endif; ?>
    <<?= $interna ? 'h3 class="titulo-5 mb-md"' : 'h2 class="titulo-5" style="padding:1rem 1rem 0"' ?>>Facturas y comprobantes</<?= $interna ? 'h3' : 'h2' ?>>

    <?php if (!$documentos) : ?>
        <p class="texto-secundario"<?= $relleno ?>>Aún no has subido nada.</p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Documentos adjuntos</caption>
                <thead><tr><th scope="col">Archivo</th><th scope="col">Tipo</th><th scope="col">Validación documental</th>
                    <th scope="col">Cargado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($documentos as $d) : $val = \App\Archivos::etiquetaValidacion($d['estado_validacion']); ?>
                        <tr<?= (int) $d['activo'] === 0 ? ' style="opacity:.6"' : '' ?>>
                            <td><strong><?= escaparValores($d['nombre_original']) ?></strong>
                                <span class="texto-xs texto-secundario"><br><?= number_format($d['tamano'] / 1024, 1) ?> KB
                                    <?= $d['uuid_fiscal'] ? ' · UUID ' . escaparValores($d['uuid_fiscal']) : '' ?>
                                    <?= (int) $d['activo'] === 0 ? ' · <strong>De baja</strong>: ' . escaparValores((string) $d['motivo_baja']) : '' ?></span>
                                <?php foreach ($d['cfdi']['alertas'] ?? [] as $alerta) : ?>
                                    <span class="texto-xs texto-error"><br>⚠ <?= escaparValores($alerta) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td><?= escaparValores(\App\Archivos::TIPOS[$d['tipo']] ?? $d['tipo']) ?></td>
                            <td><span class="badge <?= $val['clase'] ?>"><?= escaparValores($val['etiqueta']) ?></span></td>
                            <td class="texto-sm"><?= date('d/m/Y H:i', strtotime($d['creado_en'])) ?><br><?= escaparValores((string) $d['subido_por_nombre']) ?></td>
                            <td class="tabla--numerica">
                                <?php if ($vistaDocumento = \App\Archivos::tipoVista($d)) : ?>
                                    <button type="button" class="btn btn--secundario btn--sm"
                                            data-ver-documento="<?= (int) $d['id'] ?>"
                                            data-documento-tipo="<?= escaparValores($vistaDocumento) ?>"
                                            data-documento-nombre="<?= escaparValores($d['nombre_original']) ?>">Ver</button>
                                <?php endif; ?>
                                <a class="btn btn--secundario btn--sm" href="/admin/contabilidad/documentos/descargar?id=<?= (int) $d['id'] ?>">Descargar</a>
                                <?php if ((int) $d['activo'] === 1 && !$bloqueado) : ?>
                                    <details class="mt-sm">
                                        <summary class="btn btn--texto btn--sm">Sustituir o dar de baja</summary>
                                        <form class="formulario mt-sm" action="/admin/contabilidad/documentos/subir" method="post" enctype="multipart/form-data">
                                            <input type="hidden" name="entidad" value="<?= escaparValores($entidad) ?>">
                                            <input type="hidden" name="entidad_id" value="<?= $entidadId ?>">
                                            <input type="hidden" name="retorno" value="<?= escaparValores($retorno) ?>">
                                            <input type="hidden" name="reemplaza" value="<?= (int) $d['id'] ?>">
                                            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                            <input type="hidden" name="tipo" value="<?= escaparValores($d['tipo']) ?>">
                                            <label class="campo__etiqueta" for="sust-<?= (int) $d['id'] ?>">Archivo nuevo</label>
                                            <input class="control" type="file" id="sust-<?= (int) $d['id'] ?>" name="archivo" required
                                                   accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                                            <label class="campo__etiqueta" for="mot-<?= (int) $d['id'] ?>">Motivo</label>
                                            <input class="control" type="text" id="mot-<?= (int) $d['id'] ?>" name="motivo" maxlength="200" placeholder="Ej. versión corregida">
                                            <button class="btn btn--secundario btn--sm" type="submit">Sustituir</button>
                                        </form>
                                        <form class="formulario mt-sm" action="/admin/contabilidad/documentos/baja" method="post" data-validar
                                              data-confirmacion-sobre="Documentos"
                                              data-confirmacion="¿Dar de baja este documento?"
                                              data-confirmacion-mensaje="El archivo se conserva en el almacén, pero deja de contar. Queda registrado el motivo."
                                              data-confirmacion-boton="Sí, dar de baja">
                                            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                            <input type="hidden" name="retorno" value="<?= escaparValores($retorno) ?>">
                                            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="baja-<?= (int) $d['id'] ?>">Motivo de la baja</label>
                                            <input class="control" type="text" id="baja-<?= (int) $d['id'] ?>" name="motivo" maxlength="200" required minlength="5">
                                            <button class="btn btn--peligro btn--sm" type="submit">Dar de baja</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($sinSubida) : ?>
    <?php elseif (!$bloqueado) : ?>
        <form class="formulario"<?= $interna ? '' : ' style="padding:1rem"' ?> action="/admin/contabilidad/documentos/subir" method="post" enctype="multipart/form-data">
            <input type="hidden" name="entidad" value="<?= escaparValores($entidad) ?>">
            <input type="hidden" name="entidad_id" value="<?= $entidadId ?>">
            <input type="hidden" name="retorno" value="<?= escaparValores($retorno) ?>">
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
            <div class="formulario__rejilla">
                <div class="campo">
                    <label class="campo__etiqueta campo__etiqueta--obligatorio" data-ayuda="Elige la factura en PDF y su XML, o la foto del ticket. Puedes elegir varios a la vez." for="doc-archivo">Elegir archivos</label>
                    <input class="control" type="file" id="doc-archivo" name="archivo[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                    <span class="campo__ayuda">Puedes elegir varios a la vez: PDF, XML o foto (JPG, PNG, WebP) · máx. <?= $maxMb ?> MB cada uno</span>
                </div>
                <div class="campo">
                    <label class="campo__etiqueta" for="doc-tipo">¿Qué es?</label>
                    <select class="control" id="doc-tipo" name="tipo">
                        <option value="auto" selected>Detectar automáticamente</option>
                        <?php foreach (\App\Archivos::TIPOS as $clave => $texto) : ?>
                            <?php if ($clave === 'complemento_pago') { continue; } ?>
                            <option value="<?= $clave ?>"><?= escaparValores($texto) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                </div>
            </div>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Subir archivos</button></div>
        </form>
    <?php else : ?>
        <p class="nota nota--advertencia"<?= $interna ? ' style="margin-top:1rem"' : ' style="margin:1rem"' ?> role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">lock</span>
            <span>El periodo está cerrado: no se pueden agregar ni cambiar documentos hasta reabrirlo.</span></p>
    <?php endif; ?>

    <?php
    // Apartado propio del complemento de pago: solo aplica a gastos pagados con
    // factura. Se permite adjuntarlo aunque el periodo de la factura esté cerrado
    // (es un respaldo del pago, no un cambio de importes).
    $complementoVigente = $entidad === 'gasto' && is_array($gasto) && (string) ($gasto['estado'] ?? '') === 'vigente';
    $complementoPagado = $complementoVigente && (string) ($gasto['estado_pago'] ?? '') === 'pagado';
    $complementoConFactura = (int) ($gasto['comprobante_fiscal'] ?? 0) === 1;
    $complementoMetodo = strtoupper(trim((string) ($gasto['metodo_pago_sat'] ?? '')));
    $complementoRequerido = $complementoMetodo !== 'PUE';
    $complementoActivo = array_values(array_filter($complementos, static fn ($d) => (int) $d['activo'] === 1));
    ?>
    <?php if ($complementoPagado && $complementoConFactura) : ?>
        <div style="border-top:1px solid var(--color-borde); padding:1rem">
            <h3 class="titulo-5 mb-sm">Complemento de pago</h3>

            <?php if (!$complementoRequerido) : ?>
                <p class="texto-secundario">La factura es PUE (pago en una sola exhibición): no requiere complemento de pago.</p>
            <?php else : ?>
                <p>
                    <?php if ($complementoActivo) : ?>
                        <span class="badge badge--aprobado">Complemento recibido</span>
                    <?php else : ?>
                        <span class="badge badge--pendiente">Falta el complemento de pago</span>
                    <?php endif; ?>
                    <span class="texto-xs texto-secundario"> · La factura es <?= escaparValores($complementoMetodo !== '' ? $complementoMetodo : 'PPD o por definir') ?></span>
                </p>
                <?php if (trim((string) ($gasto['uuid_fiscal'] ?? '')) === '') : ?>
                    <p class="texto-sm texto-secundario">La factura no tiene UUID registrado: el archivo se adjuntará sin validación.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($complementos) : ?>
                <div class="tabla-contenedor mt-md">
                    <table class="tabla">
                        <caption class="solo-lectores">Complementos de pago adjuntos</caption>
                        <thead><tr><th scope="col">Archivo</th><th scope="col">Cargado</th>
                            <th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                        <tbody>
                            <?php foreach ($complementos as $d) : ?>
                                <tr<?= (int) $d['activo'] === 0 ? ' style="opacity:.6"' : '' ?>>
                                    <td><strong><?= escaparValores($d['nombre_original']) ?></strong>
                                        <span class="texto-xs texto-secundario"><br><?= number_format($d['tamano'] / 1024, 1) ?> KB
                                            <?= (int) $d['activo'] === 0 ? ' · <strong>De baja</strong>: ' . escaparValores((string) $d['motivo_baja']) : '' ?></span>
                                    </td>
                                    <td class="texto-sm"><?= date('d/m/Y H:i', strtotime($d['creado_en'])) ?><br><?= escaparValores((string) $d['subido_por_nombre']) ?></td>
                                    <td class="tabla--numerica">
                                        <?php if ($vistaComplemento = \App\Archivos::tipoVista($d)) : ?>
                                            <button type="button" class="btn btn--secundario btn--sm"
                                                    data-ver-documento="<?= (int) $d['id'] ?>"
                                                    data-documento-tipo="<?= escaparValores($vistaComplemento) ?>"
                                                    data-documento-nombre="<?= escaparValores($d['nombre_original']) ?>">Ver</button>
                                        <?php endif; ?>
                                        <a class="btn btn--secundario btn--sm" href="/admin/contabilidad/documentos/descargar?id=<?= (int) $d['id'] ?>">Descargar</a>
                                        <?php if ((int) $d['activo'] === 1 && $complementoVigente) : ?>
                                            <details class="mt-sm">
                                                <summary class="btn btn--texto btn--sm">Dar de baja</summary>
                                                <form class="formulario mt-sm" action="/admin/contabilidad/documentos/baja" method="post" data-validar
                                                      data-confirmacion-sobre="Complemento de pago"
                                                      data-confirmacion="¿Dar de baja este complemento?"
                                                      data-confirmacion-mensaje="El archivo se conserva en el almacén, pero deja de contar. Queda registrado el motivo."
                                                      data-confirmacion-boton="Sí, dar de baja">
                                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                                    <input type="hidden" name="retorno" value="<?= escaparValores($retorno) ?>">
                                                    <label class="campo__etiqueta campo__etiqueta--obligatorio" for="baja-complemento-<?= (int) $d['id'] ?>">Motivo de la baja</label>
                                                    <input class="control" type="text" id="baja-complemento-<?= (int) $d['id'] ?>" name="motivo" maxlength="200" required minlength="5">
                                                    <button class="btn btn--peligro btn--sm" type="submit">Dar de baja</button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($complementoRequerido && $complementoVigente) : ?>
                <form class="formulario mt-md" action="/admin/contabilidad/documentos/subir" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="entidad" value="gasto">
                    <input type="hidden" name="entidad_id" value="<?= (int) $entidadId ?>">
                    <input type="hidden" name="retorno" value="<?= escaparValores($retorno) ?>">
                    <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                    <input type="hidden" name="tipo" value="complemento_pago">
                    <div class="campo">
                        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="complemento-archivo">XML o PDF del complemento</label>
                        <input class="control" type="file" id="complemento-archivo" name="archivo" required accept=".xml,.pdf">
                        <span class="campo__ayuda">Se adjunta tal cual, sin validarlo contra el SAT · máx. <?= $maxMb ?> MB</span>
                    </div>
                    <div class="formulario__acciones">
                        <button class="btn btn--primario" type="submit">Subir complemento</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php if ($interna) : ?></div><?php else : ?></section><?php endif; ?>
