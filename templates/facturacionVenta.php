<?php
/**
 * templates/facturacionVenta.php — Sección única «Facturación y cobro» de una venta.
 *
 * Recibe: $ingreso (Contabilidad::ingresoDeVenta / buscarIngreso) y $volver (ruta interna de regreso).
 * Reúne en un solo lugar el estado de facturación, los cobros recibidos y los documentos adjuntos.
 *
 * UN SOLO FORMULARIO: los datos fiscales del receptor, el pago y comprobante, el
 * documento (PDF/XML/foto) y el folio/UUID/fecha se capturan juntos y se envían
 * de una vez a /admin/ventas/fiscal. Antes había un modal de «Revisar y facturar»
 * y, aparte, un formulario «¿Ya se facturó?» con la subida y los folios: el
 * primero validaba pero no facturaba, y el segundo era el que sí guardaba.
 *
 * Facturar exige un CFDI adjunto (XML con timbre) o una confirmación manual
 * explícita. «No se facturará» y «volver a pendiente» son acciones aparte,
 * porque no capturan datos: sólo cambian la clasificación.
 */

$fact      = \App\Contabilidad::FACTURACION[$ingreso['estado_facturacion']];
$cobro     = \App\Contabilidad::etiquetaCobro($ingreso['estado_cobro']);
$cerrado   = \App\Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], $ingreso['fecha']);
$editable  = $ingreso['estado'] === 'vigente' && !$cerrado;
$cfdi      = \App\Contabilidad::cfdiDeIngreso((int) $ingreso['id']);
$historial = \App\Contabilidad::historialFacturacion((int) $ingreso['id']);
$maxMb     = \App\Archivos::maxBytes() / 1048576;
$etiquetas = static fn (?string $e): string => $e !== null && isset(\App\Contabilidad::FACTURACION[$e]) ? \App\Contabilidad::FACTURACION[$e]['etiqueta'] : '—';

$ventaFiscalId = (int) ($ingreso['venta_id'] ?? 0);
$revisionFiscal = ($ventaFiscalId > 0 && $editable) ? \App\VentasFiscal::validar($ventaFiscalId) : null;

/* FASE 9: RFCs de facturación del cliente (persona física y empresa pueden
 * convivir en la misma ficha). Con más de uno, el selector elige cuál usar y
 * carga su CP, régimen y uso. El elegido queda como RFC activo. */
$rfcsCliente = [];
if ($revisionFiscal !== null && (int) ($revisionFiscal['cliente']['id'] ?? 0) > 0) {
    $rfcsCliente = \App\ClientesRfc::de((int) $revisionFiscal['cliente']['id']);
}

/* El formulario se muestra mientras la facturación se pueda capturar:
 *  · venta aún no facturada, o
 *  · facturada sin CFDI adjunto (para poder adjuntarlo después o confirmarla).
 * Una venta ya respaldada con CFDI no se vuelve a capturar aquí: su corrección
 * se hace desde una incidencia de entrega, que da de baja el documento anterior. */
$capturable = $ingreso['estado_facturacion'] !== 'facturada' || $cfdi === null;
?>
<section class="tarjeta mb-lg" id="facturacion">
    <div class="flex-entre mb-md">
        <h2 class="titulo-5">Facturación y cobro
            <span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span>
            <span class="badge <?= $cobro['clase'] ?>"><?= escaparValores($cobro['etiqueta']) ?></span>
            <span class="badge badge--info"><?= \App\Contabilidad::centavos($ingreso['iva']) > 0 ? 'Con IVA' : 'Sin IVA' ?></span>
            <?php if ($ingreso['estado_facturacion'] === 'facturada') : ?>
                <span class="badge <?= $ingreso['facturacion_manual'] ? 'badge--pendiente' : 'badge--aprobado' ?>"><?= $ingreso['facturacion_manual'] ? 'Confirmación manual' : 'Con CFDI adjunto' ?></span>
            <?php endif; ?>
            <?php if ($ingreso['estado'] === 'cancelado') : ?><span class="badge badge--error">Ingreso cancelado</span><?php endif; ?>
            <?php if ($cerrado) : ?><span class="badge badge--neutro">Periodo cerrado</span><?php endif; ?>
        </h2>
        <a class="btn btn--texto btn--sm" href="/admin/contabilidad/ingresos">Ver ingresos</a>
    </div>

    <p class="campo__ayuda mb-md">
        Cobrado <strong><?= formatoMoneda($ingreso['cobrado']) ?></strong> de <strong><?= formatoMoneda($ingreso['total']) ?></strong>
        · Saldo pendiente <strong><?= formatoMoneda($ingreso['saldo']) ?></strong>.
        El IVA solo define el estado inicial (con IVA: pendiente de facturar; sin IVA: no facturada). No prueba que exista un CFDI:
        una venta entra al cálculo de CFDI emitidos únicamente cuando la marcas como facturada.
    </p>

    <?php
    /* Diferencia factura vs. venta (p. ej. tras sustituir la factura por un
     * monto menor): en contabilidad manda la factura y así se avisa. */
    $ventaDif = !empty($ingreso['venta_id']) ? \App\Ventas::buscarID((int) $ingreso['venta_id']) : null;
    ?>
    <?php if ($ventaDif && \App\Contabilidad::centavos($ingreso['total']) !== \App\Contabilidad::centavos($ventaDif->total)) : ?>
        <p class="nota nota--advertencia" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">receipt_long</span>
            <span>La factura es por <strong><?= formatoMoneda((float) $ingreso['total']) ?></strong> y la venta por
                <strong><?= formatoMoneda((float) $ventaDif->total) ?></strong>: en contabilidad se registra la factura.
                Para cambiarla, usa una incidencia de entrega que marque «Corregir factura».</span>
        </p>
    <?php endif; ?>

    <?php if ($revisionFiscal !== null) : $rv = $revisionFiscal; ?>
        <?php
        /* ------------------------------------------------------------------
         * VALIDACIÓN PREVIA A FACTURAR (FASE 5)
         *
         * Se revisa el receptor, el emisor, los conceptos, los importes y el
         * método/forma de pago ANTES de facturar. Todo clasificado en
         * correcto / advertencia / error obligatorio.
         *
         * El servidor vuelve a validar en /admin/ventas/fiscal: no se factura
         * con información indispensable faltante.
         *
         * Nota: hoy no hay integración con PAC (no se timbra desde el sistema).
         * Por eso «facturar» aquí significa adjuntar el CFDI ya emitido o dejar
         * la confirmación manual explícita; no se simula ningún timbrado.
         */
        ?>
        <div class="mb-md" id="validacion-previa">
            <p class="texto-sm mb-sm">
                <strong>Validación previa a facturar:</strong>
                <span class="badge badge--aprobado"><?= (int) $rv['resumen']['correcto'] ?> correctos</span>
                <?php if ($rv['resumen']['advertencia'] > 0) : ?>
                    <span class="badge badge--pendiente"><?= (int) $rv['resumen']['advertencia'] ?> advertencias</span>
                <?php endif; ?>
                <?php if ($rv['resumen']['error'] > 0) : ?>
                    <span class="badge badge--error"><?= (int) $rv['resumen']['error'] ?> errores obligatorios</span>
                <?php endif; ?>
            </p>

            <?php if ($rv['errores'] || $rv['advertencias']) : ?>
                <details class="mb-sm">
                    <summary class="texto-sm">Puntos por corregir o revisar (<?= count($rv['errores']) ?> pendientes, <?= count($rv['advertencias']) ?> por revisar)</summary>
                    <ul class="lista-simple">
                        <?php foreach ($rv['errores'] as $error) : ?>
                            <li class="texto-error"><?= escaparValores($error) ?></li>
                        <?php endforeach; ?>
                        <?php foreach ($rv['advertencias'] as $aviso) : ?>
                            <li>⚠ <?= escaparValores($aviso) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($rv['errores']) : ?>
                        <p class="texto-sm texto-error mt-sm">Hay errores obligatorios: no se puede facturar hasta completarlos
                            <?php if ((int) ($rv['cliente']['id'] ?? 0) > 0) : ?>· <a href="/admin/clientes/editar?id=<?= (int) $rv['cliente']['id'] ?>">Editar datos fiscales del cliente</a><?php endif; ?>
                        </p>
                    <?php endif; ?>
                </details>
            <?php endif; ?>

            <?php if ($rv['uuid'] !== '') : ?>
                <p class="nota nota--info mb-sm" role="status">
                    <span>Esta venta ya tiene un CFDI: UUID <strong><?= escaparValores($rv['uuid']) ?></strong><?php
                    if ($rv['cfdi']) : ?> (archivo adjunto: <?= escaparValores($rv['cfdi']['nombre_original']) ?>)<?php endif; ?>.
                    Sólo se reemite con una corrección o sustitución autorizada.</span>
                </p>
            <?php endif; ?>
        </div>

        <?php if ($editable && $capturable) : ?>
            <form class="formulario" id="form-facturacion" action="/admin/ventas/fiscal" method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                <input type="hidden" name="accion" value="emitir">
                <input type="hidden" name="venta" value="<?= $ventaFiscalId ?>">
                <input type="hidden" name="id" value="<?= (int) $ingreso['id'] ?>">
                <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">

                <h3 class="titulo-6 mt-md mb-sm">Datos fiscales del receptor</h3>
                <div class="formulario__rejilla">
                    <div class="campo">
                        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="f-rfc">RFC del receptor</label>
                        <?php if (count($rfcsCliente) > 1) : ?>
                            <?php $rfcActivo = \App\VentasFiscal::normalizarRfc((string) $rv['cliente']['rfc']); ?>
                            <select class="control" id="f-rfc" name="rfc" data-multirfc>
                                <?php foreach ($rfcsCliente as $rfcFila) : ?>
                                    <option value="<?= escaparValores((string) $rfcFila['rfc']) ?>"
                                            data-cp="<?= escaparValores((string) ($rfcFila['codigo_postal'] ?? '')) ?>"
                                            data-regimen="<?= escaparValores((string) ($rfcFila['regimen_fiscal'] ?? '')) ?>"
                                            data-uso="<?= escaparValores((string) ($rfcFila['uso_cfdi'] ?? '')) ?>"
                                            <?= \App\VentasFiscal::normalizarRfc((string) $rfcFila['rfc']) === $rfcActivo ? 'selected' : '' ?>>
                                        <?= escaparValores((string) $rfcFila['rfc']) ?><?= !empty($rfcFila['razon_social']) ? ' — ' . escaparValores((string) $rfcFila['razon_social']) : '' ?><?= (int) $rfcFila['es_principal'] === 1 ? ' (activo)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="campo__ayuda">Este cliente tiene <?= count($rfcsCliente) ?> RFC: al elegir uno se cargan su CP, régimen y uso.</span>
                        <?php else : ?>
                            <input class="control" type="text" id="f-rfc" name="rfc" maxlength="20" style="text-transform:uppercase"
                                   value="<?= escaparValores((string) $rv['cliente']['rfc']) ?>" required>
                        <?php endif; ?>
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-cp">Código postal fiscal</label>
                        <input class="control" type="text" id="f-cp" name="codigo_postal" maxlength="5" inputmode="numeric"
                               value="<?= escaparValores((string) $rv['cliente']['codigo_postal']) ?>">
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-regimen">Régimen fiscal</label>
                        <select class="control" id="f-regimen" name="regimen_fiscal">
                            <option value="">— Sin definir —</option>
                            <?php foreach (\App\VentasFiscal::REGIMENES as $claveR) : ?>
                                <option value="<?= $claveR ?>"<?= mb_strtoupper(trim((string) $rv['cliente']['regimen_fiscal'])) === $claveR ? ' selected' : '' ?>><?= $claveR ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-uso">Uso del CFDI</label>
                        <?php /* El uso que declaró la factura leída (si la hubo) manda sobre el de la ficha. */
                        $usoActual = mb_strtoupper(trim((string) (($rv['ingreso']['uso_cfdi'] ?? '') ?: $rv['cliente']['uso_cfdi']))); ?>
                        <select class="control" id="f-uso" name="uso_cfdi">
                            <option value="">— Sin definir —</option>
                            <?php foreach (\App\VentasFiscal::USOS_CFDI as $claveU) : ?>
                                <?php $textoU = \App\Gastos::USOS_CFDI[$claveU] ?? $claveU; ?>
                                <option value="<?= $claveU ?>"<?= $usoActual === $claveU ? ' selected' : '' ?>><?= escaparValores($textoU) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h3 class="titulo-6 mt-md mb-sm">Pago y comprobante</h3>
                <div class="formulario__rejilla">
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-metodo">Método de pago</label>
                        <select class="control" id="f-metodo" name="metodo_pago">
                            <option value="">— Elegir —</option>
                            <?php $metodoActual = mb_strtoupper(trim((string) ($rv['ingreso']['metodo_pago'] ?? ''))); ?>
                            <?php foreach (\App\VentasFiscal::METODOS as $claveM => $textoM) : ?>
                                <option value="<?= $claveM ?>"<?= $metodoActual === $claveM ? ' selected' : '' ?>><?= escaparValores($textoM) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="campo__ayuda">Propuesta por la operación: <?= escaparValores($rv['propuestas']['metodo']) ?></span>
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-forma">Forma de pago</label>
                        <select class="control" id="f-forma" name="forma_pago">
                            <option value="">— Sin definir —</option>
                            <?php $formaActual = \App\VentasFiscal::normalizarFormaPago((string) ($rv['ingreso']['forma_pago'] ?? '')); ?>
                            <?php foreach (\App\VentasFiscal::FORMAS_PAGO as $claveF => $textoF) : ?>
                                <option value="<?= $claveF ?>"<?= $formaActual === $claveF ? ' selected' : '' ?>><?= escaparValores($claveF . ' · ' . $textoF) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php $formaPropuesta = \App\VentasFiscal::normalizarFormaPago((string) ($rv['propuestas']['forma'] ?? '')); ?>
                        <span class="campo__ayuda">Propuesta por la operación: <?= escaparValores($formaPropuesta . ' · ' . (\App\VentasFiscal::FORMAS_PAGO[$formaPropuesta] ?? '')) ?></span>
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-tipo">Tipo de comprobante</label>
                        <?php /* El tipo que trae la factura leída; si no, una venta se emite como «Ingreso». */
                        $tipoActual = mb_strtoupper(trim((string) (($rv['ingreso']['tipo_cfdi'] ?? '') ?: 'I'))); ?>
                        <select class="control" id="f-tipo" name="tipo_cfdi">
                            <?php foreach (\App\Contabilidad::TIPOS_CFDI as $claveT => $textoT) : ?>
                                <option value="<?= $claveT ?>"<?= $tipoActual === $claveT ? ' selected' : '' ?>><?= escaparValores($textoT) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="campo__ayuda">Una venta normal es «I · Ingreso»; «E» sólo si es una nota de crédito.</span>
                    </div>
                </div>
                <p class="campo__ayuda mt-sm">
                    Emisor: <?= escaparValores($rv['empresa']['nombre']) ?> · RFC <?= escaparValores(\App\VentasFiscal::normalizarRfc((string) $rv['empresa']['rfc']) ?: (\App\VentasFiscal::normalizarRfc((string) ($rv['contribuyente_rfc'] ?? '')) ?: 'sin definir')) ?>
                    · CP <?= escaparValores(trim((string) $rv['empresa']['cp']) ?: '—') ?>
                    · régimen <?= escaparValores(trim((string) $rv['empresa']['regimen']) ?: '—') ?>.
                </p>

                <h3 class="titulo-6 mt-md mb-sm">Documento de la factura</h3>
                <div class="campo">
                    <label class="campo__etiqueta" for="doc-archivo">Factura emitida (PDF y/o XML)</label>
                    <input class="control" type="file" id="doc-archivo" name="archivo[]"
                           multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                    <span class="campo__ayuda">Puedes elegir varios a la vez: XML (trae UUID y montos), PDF o foto ·
                        máx. <?= $maxMb ?> MB cada uno. El XML con timbre es lo que respalda la factura.</span>
                </div>
                <?php if ($cfdi) : ?>
                    <p class="nota nota--info mt-sm" role="status"><span>CFDI adjunto: <strong><?= escaparValores($cfdi['nombre_original']) ?></strong>
                        (<?= escaparValores($cfdi['uuid_fiscal']) ?>). Se conserva al guardar.</span></p>
                <?php else : ?>
                    <label class="comprobacion mt-sm" for="confirma-manual">
                        <input type="checkbox" id="confirma-manual" name="confirma_manual" value="1">
                        <span>No tengo el archivo del CFDI: confirmo que la factura ya se emitió.
                            Queda registrada como <strong>confirmación manual</strong>.</span>
                    </label>
                <?php endif; ?>

                <details class="mt-md" <?= ($ingreso['factura_folio'] === null && $ingreso['uuid_fiscal'] === null) ? 'open' : '' ?>>
                    <summary class="texto-sm">Folio, UUID y fecha de la factura (opcional)</summary>
                    <div class="formulario__rejilla mt-sm">
                        <div class="campo">
                            <label class="campo__etiqueta" for="factura_folio">Folio de la factura</label>
                            <input class="control" type="text" id="factura_folio" name="factura_folio" maxlength="40" value="<?= escaparValores((string) $ingreso['factura_folio']) ?>">
                        </div>
                        <div class="campo">
                            <label class="campo__etiqueta" for="uuid_fiscal">Folio fiscal UUID</label>
                            <input class="control" type="text" id="uuid_fiscal" name="uuid_fiscal" maxlength="36" value="<?= escaparValores((string) ($ingreso['uuid_fiscal'] ?: ($cfdi['uuid_fiscal'] ?? ''))) ?>">
                        </div>
                        <div class="campo">
                            <label class="campo__etiqueta" for="fecha_facturacion">Fecha de la factura</label>
                            <input class="control" type="date" id="fecha_facturacion" name="fecha_facturacion" value="<?= escaparValores((string) $ingreso['fecha_facturacion']) ?>">
                        </div>
                    </div>
                </details>

                <div class="formulario__acciones">
                    <button class="btn btn--primario" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">fact_check</span>
                        <span><?= $ingreso['estado_facturacion'] === 'facturada' ? 'Guardar facturación' : 'Facturar y guardar' ?></span>
                    </button>
                    <span class="campo__ayuda">Un solo paso: se guardan los datos fiscales, se adjunta el documento y se marca la facturación.</span>
                </div>
            </form>

            <?php if ($ingreso['estado_facturacion'] !== 'facturada') : ?>
                <details class="mt-md">
                    <summary class="texto-sm">Otras opciones (sin facturar)</summary>
                    <div class="formulario__rejilla mt-sm">
                        <form action="/admin/ventas/fiscal" method="post">
                            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                            <input type="hidden" name="accion" value="no_facturada">
                            <input type="hidden" name="venta" value="<?= $ventaFiscalId ?>">
                            <input type="hidden" name="id" value="<?= (int) $ingreso['id'] ?>">
                            <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">
                            <p class="texto-sm mb-sm">Esta venta no se va a facturar y no debe seguir apareciendo como pendiente.</p>
                            <button class="btn btn--secundario btn--sm" type="submit">Marcar «No se facturará»</button>
                        </form>
                        <?php if ($ingreso['estado_facturacion'] === 'no_facturada') : ?>
                            <form action="/admin/ventas/fiscal" method="post">
                                <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                <input type="hidden" name="accion" value="pendiente">
                                <input type="hidden" name="venta" value="<?= $ventaFiscalId ?>">
                                <input type="hidden" name="id" value="<?= (int) $ingreso['id'] ?>">
                                <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">
                                <p class="texto-sm mb-sm">Devolverla a «pendiente de facturar» si finalmente sí se facturará.</p>
                                <button class="btn btn--secundario btn--sm" type="submit">Volver a pendiente</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php elseif ($editable) : ?>
            <p class="texto-secundario">
                Esta venta ya está facturada con CFDI. Para corregirla, registra una
                <a href="/admin/entregas-seguimiento/venta?id=<?= $ventaFiscalId ?>#incidencias">incidencia de entrega</a>
                que marque «Corregir factura»: se da de baja el documento anterior y se sube el correcto.
            </p>
        <?php endif; ?>

        <script>
        /* FASE 9 · Varios RFC en la misma ficha: al elegir uno se cargan su CP,
         * régimen y uso en el formulario (se pueden corregir a mano). */
        (function () {
            var selectorRfc = document.getElementById('f-rfc');
            if (!selectorRfc || selectorRfc.tagName !== 'SELECT') { return; }
            selectorRfc.addEventListener('change', function () {
                var elegido = selectorRfc.options[selectorRfc.selectedIndex];
                if (!elegido) { return; }
                var asignar = function (id, valor) {
                    var campo = document.getElementById(id);
                    if (campo) { campo.value = valor; }
                };
                asignar('f-cp', elegido.getAttribute('data-cp') || '');
                asignar('f-regimen', elegido.getAttribute('data-regimen') || '');
                asignar('f-uso', elegido.getAttribute('data-uso') || '');
            });
        })();
        </script>
    <?php endif; ?>

    <?php if (!$editable) : ?>
        <?php if ($cerrado) : ?>
            <p class="texto-secundario">El periodo contable está cerrado: la facturación y los cobros no se pueden modificar.</p>
        <?php else : ?>
            <p class="texto-secundario">Este ingreso está cancelado: la facturación y los cobros quedaron fijos.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($historial) : ?>
        <h3 class="titulo-6 mt-lg mb-md">Historial de facturación</h3>
        <ul class="lista-simple">
            <?php foreach ($historial as $h) : $d = $h['detalle']; ?>
                <li><?= date('d/m/Y H:i', strtotime($h['creado_en'])) ?> ·
                    <?= escaparValores($etiquetas($d['antes'] ?? null)) ?> → <strong><?= escaparValores($etiquetas($d['despues'] ?? null)) ?></strong>
                    · <?= escaparValores($h['usuario_nombre'] ?: (($d['origen'] ?? '') === 'regla_iva' ? 'Sistema (regla de IVA)' : 'Sistema')) ?>
                    <?php if (!empty($d['manual'])) : ?> · <em>confirmación manual, sin CFDI</em><?php endif; ?>
                    <?php if (!empty($d['uuid'])) : ?> · UUID <?= escaparValores($d['uuid']) ?><?php endif; ?>
                    <?php if (!empty($d['documento_id'])) : ?> · CFDI adjunto #<?= (int) $d['documento_id'] ?><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <hr class="divisor mt-lg mb-md">

    <?php template('cobrosIngreso', ['ingreso' => $ingreso, 'retorno' => $volver, 'interna' => true]); ?>

    <hr class="divisor mt-lg mb-md">

    <?php
    /* ------------------------------------------------------------------
     * DOCUMENTOS ADJUNTOS — comprobante de pago (fotos o PDF).
     *
     * Subida directa desde el detalle de la venta: el archivo se guarda con
     * el tipo «comprobante_pago» y aparece en la lista de «Facturas y
     * comprobantes» de abajo (ahí se descarga o se da de baja).
     *
     * Es SÓLO un respaldo informativo del pago: adjuntarlo no cambia el
     * estado de facturación, el de cobro ni ningún importe, y no se pide
     * para facturar. Como cualquier documento de la operación, se bloquea
     * si el periodo contable está cerrado o el ingreso está cancelado.
     */
    $adjuntosBloqueados = $cerrado || $ingreso['estado'] === 'cancelado';
    ?>
    <?php if ($adjuntosBloqueados) : ?>
        <p class="nota nota--advertencia mb-md" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">lock</span>
            <span>No se pueden adjuntar documentos<?= $cerrado ? ': el periodo contable está cerrado' : ': el ingreso está cancelado' ?>.</span>
        </p>
    <?php else : ?>
        <form class="formulario mb-md" action="/admin/contabilidad/documentos/subir" method="post" enctype="multipart/form-data">
            <input type="hidden" name="entidad" value="ingreso">
            <input type="hidden" name="entidad_id" value="<?= (int) $ingreso['id'] ?>">
            <input type="hidden" name="retorno" value="<?= escaparValores($volver) ?>">
            <input type="hidden" name="tipo" value="comprobante_pago">
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
            <div class="campo">
                <label class="campo__etiqueta" for="adj-archivo">Documentos Adjuntos</label>
                <input class="control" type="file" id="adj-archivo" name="archivo[]" required
                       multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
                <span class="campo__ayuda">Fotos o PDF del comprobante de pago: JPG, PNG, WebP o PDF · puedes elegir varios a la vez ·
                    máx. <?= $maxMb ?> MB cada uno. Es sólo de carácter informativo (un respaldo del pago):
                    no cambia la facturación, el estado de cobro ni los importes.</span>
            </div>
            <div class="formulario__acciones">
                <button class="btn btn--primario" type="submit">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">upload_file</span>
                    <span>Subir archivos</span>
                </button>
            </div>
        </form>
    <?php endif; ?>

    <?php template('documentosOperacion', ['entidad' => 'ingreso', 'entidadId' => (int) $ingreso['id'], 'retorno' => $volver,
        'bloqueado' => $adjuntosBloqueados, 'sinSubida' => true, 'interna' => true]); ?>
</section>
