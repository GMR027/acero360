<?php
/**
 * templates/facturacionVenta.php — Sección única «Facturación y cobro» de una venta.
 *
 * Recibe: $ingreso (Contabilidad::ingresoDeVenta / buscarIngreso) y $volver (ruta interna de regreso).
 * Reúne en un solo lugar el estado de facturación (con la subida del CFDI), los cobros recibidos y
 * los documentos adjuntos: antes este control estaba repartido entre el detalle de la venta y el
 * detalle del ingreso, que eran dos pantallas para la misma operación.
 * Cambiar a «Facturada» exige un CFDI adjunto o una confirmación manual explícita (queda registrada).
 */

$fact      = \App\Contabilidad::FACTURACION[$ingreso['estado_facturacion']];
$cobro     = \App\Contabilidad::etiquetaCobro($ingreso['estado_cobro']);
$cerrado   = \App\Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], $ingreso['fecha']);
$editable  = $ingreso['estado'] === 'vigente' && !$cerrado;
$cfdi      = \App\Contabilidad::cfdiDeIngreso((int) $ingreso['id']);
$historial = \App\Contabilidad::historialFacturacion((int) $ingreso['id']);
$maxMb     = \App\Archivos::maxBytes() / 1048576;
$etiquetas = static fn (?string $e): string => $e !== null && isset(\App\Contabilidad::FACTURACION[$e]) ? \App\Contabilidad::FACTURACION[$e]['etiqueta'] : '—';
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
    /* -----------------------------------------------------------------------
     * VALIDACIÓN PREVIA A FACTURAR (FASE 5)
     *
     * Se revisa el receptor, el emisor, los conceptos, los importes, el
     * método/forma de pago y la emitibilidad de la venta ANTES de facturar.
     * Todo clasificado en correcto / advertencia / error obligatorio.
     *
     * El botón abre el modal de revisión (cliente, datos fiscales, conceptos,
     * importes y advertencias). Si hay errores, el modal no ofrece continuar y
     * el servidor vuelve a bloquear en /admin/ventas/fiscal y en
     * ingresos/accion: no se factura con información indispensable faltante.
     *
     * Nota: hoy no hay integración con PAC (no se timbra desde el sistema),
     * por eso «continuar» no simula una emisión: sólo valida y relaciona un
     * CFDI existente si lo hay.
     */
    $ventaFiscalId = (int) ($ingreso['venta_id'] ?? 0);
    $revisionFiscal = ($ventaFiscalId > 0 && $editable) ? \App\VentasFiscal::validar($ventaFiscalId) : null;
    ?>
    <?php if ($revisionFiscal !== null) : $rv = $revisionFiscal; ?>
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

            <button class="btn btn--primario" type="button" id="btn-revision-fiscal">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">fact_check</span>
                <span>Revisar y facturar</span>
            </button>
        </div>

        <!-- Cuerpo del modal de revisión (inerte hasta que JS lo abre). -->
        <template id="tpl-revision-fiscal">
            <div>
                <p class="texto-sm">
                    <strong>Cliente:</strong> <?= escaparValores(trim($rv['cliente']['nombre_empresa'] ?: $rv['cliente']['nombre'])) ?>
                    (ficha #<?= (int) $rv['cliente']['id'] ?>) ·
                    <strong>RFC:</strong> <?= escaparValores(\App\VentasFiscal::normalizarRfc((string) $rv['cliente']['rfc']) ?: 'sin definir') ?>
                </p>

                <h4 class="titulo-6 mt-md mb-sm">Datos fiscales</h4>
                <div class="formulario__rejilla">
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-rfc">RFC del receptor</label>
                        <input class="control" type="text" id="f-rfc" maxlength="20" style="text-transform:uppercase"
                               value="<?= escaparValores((string) $rv['cliente']['rfc']) ?>">
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-cp">Código postal fiscal</label>
                        <input class="control" type="text" id="f-cp" maxlength="5" inputmode="numeric"
                               value="<?= escaparValores((string) $rv['cliente']['codigo_postal']) ?>">
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-regimen">Régimen fiscal</label>
                        <select class="control" id="f-regimen">
                            <option value="">— Sin definir —</option>
                            <?php foreach (\App\VentasFiscal::REGIMENES as $claveR) : ?>
                                <option value="<?= $claveR ?>"<?= mb_strtoupper(trim((string) $rv['cliente']['regimen_fiscal'])) === $claveR ? ' selected' : '' ?>><?= $claveR ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-uso">Uso del CFDI</label>
                        <select class="control" id="f-uso">
                            <option value="">— Sin definir —</option>
                            <?php foreach (\App\VentasFiscal::USOS_CFDI as $claveU) : ?>
                                <?php $textoU = \App\Gastos::USOS_CFDI[$claveU] ?? $claveU; ?>
                                <option value="<?= $claveU ?>"<?= mb_strtoupper(trim((string) $rv['cliente']['uso_cfdi'])) === $claveU ? ' selected' : '' ?>><?= escaparValores($textoU) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label class="campo__etiqueta" for="f-metodo">Método de pago</label>
                        <select class="control" id="f-metodo">
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
                        <select class="control" id="f-forma">
                            <option value="">— Sin definir —</option>
                            <?php $formaActual = trim((string) ($rv['ingreso']['forma_pago'] ?? '')); ?>
                            <?php foreach (\App\VentasFiscal::FORMAS_PAGO as $claveF => $textoF) : ?>
                                <option value="<?= $claveF ?>"<?= $formaActual === $claveF ? ' selected' : '' ?>><?= escaparValores($claveF . ' · ' . $textoF) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="campo__ayuda">Propuesta por la operación: <?= escaparValores($rv['propuestas']['forma'] . ' · ' . (\App\VentasFiscal::FORMAS_PAGO[$rv['propuestas']['forma']] ?? '')) ?></span>
                    </div>
                </div>
                <p class="campo__ayuda mt-sm">
                    Emisor: <?= escaparValores($rv['empresa']['nombre']) ?> · RFC <?= escaparValores(\App\VentasFiscal::normalizarRfc((string) $rv['empresa']['rfc']) ?: (\App\VentasFiscal::normalizarRfc((string) ($rv['contribuyente_rfc'] ?? '')) ?: 'sin definir')) ?>
                    · CP <?= escaparValores(trim((string) $rv['empresa']['cp']) ?: '—') ?>
                    · régimen <?= escaparValores(trim((string) $rv['empresa']['regimen']) ?: '—') ?>.
                </p>

                <h4 class="titulo-6 mt-md mb-sm">Conceptos</h4>
                <?php if ($rv['conceptos']) : ?>
                    <div class="tabla-contenedor mb-sm"><table class="tabla">
                        <thead><tr><th scope="col">Descripción</th><th scope="col" class="tabla--numerica">Cant.</th>
                            <th scope="col" class="tabla--numerica">Precio</th><th scope="col" class="tabla--numerica">Dcto.</th>
                            <th scope="col" class="tabla--numerica">Importe</th></tr></thead>
                        <tbody>
                        <?php foreach ($rv['conceptos'] as $con) : ?>
                            <tr><td><?= escaparValores($con['descripcion']) ?></td>
                                <td class="tabla--numerica cifra"><?= (int) $con['cantidad'] ?></td>
                                <td class="tabla--numerica cifra"><?= formatoMoneda($con['precio']) ?></td>
                                <td class="tabla--numerica cifra"><?= $con['descuento'] > 0 ? rtrim(rtrim(number_format($con['descuento'], 2), '0'), '.') . '%' : '—' ?></td>
                                <td class="tabla--numerica cifra"><?= formatoMoneda($con['importe']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php else : ?>
                    <p class="texto-sm texto-error">La venta no tiene conceptos.</p>
                <?php endif; ?>

                <h4 class="titulo-6 mt-md mb-sm">Importes</h4>
                <p class="texto-sm">
                    Subtotal <strong class="cifra"><?= formatoMoneda((float) $rv['venta']['subtotal']) ?></strong>
                    · Descuento <strong class="cifra"><?= formatoMoneda((float) $rv['venta']['descuento']) ?></strong>
                    · IVA <strong class="cifra"><?= formatoMoneda((float) $rv['venta']['iva']) ?></strong>
                    · Otros impuestos <strong class="cifra"><?= formatoMoneda((float) ($rv['ingreso']['otros_impuestos'] ?? 0)) ?></strong>
                    · <strong>Total <span class="cifra"><?= formatoMoneda((float) $rv['venta']['total']) ?></span></strong>
                    <br>Cobrado <span class="cifra"><?= formatoMoneda((float) $rv['cobrado']) ?></span>
                    · saldo <span class="cifra"><?= formatoMoneda((float) $rv['venta']['total'] - (float) $rv['cobrado']) ?></span>
                    · venta <?= escaparValores($rv['venta']['folio']) ?> del <?= date('d/m/Y', strtotime((string) $rv['venta']['fecha'])) ?>
                </p>

                <h4 class="titulo-6 mt-md mb-sm">Validaciones (<?= count($rv['items']) ?>)</h4>
                <ul class="lista-simple">
                    <?php foreach ($rv['items'] as $item) : $est = \App\VentasFiscal::ESTADOS[$item['estado']]; ?>
                        <li>
                            <span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span>
                            <span class="texto-xs texto-secundario"><?= escaparValores(\App\VentasFiscal::GRUPOS[$item['grupo']] ?? $item['grupo']) ?> ·</span>
                            <?= escaparValores($item['detalle']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($rv['facturada'] && $rv['uuid'] !== '') : ?>
                    <hr class="divisor mt-md mb-md">
                    <label class="comprobacion" for="f-sustitucion">
                        <input type="checkbox" id="f-sustitucion">
                        <span>Corrección o sustitución autorizada de la venta (ya existe CFDI <?= escaparValores($rv['uuid']) ?>)</span>
                    </label>
                    <label class="campo__etiqueta mt-sm" for="f-motivo">Motivo de la corrección o sustitución</label>
                    <input class="control" type="text" id="f-motivo" maxlength="120" placeholder="Ej. Error en cantidades: se sustituye el comprobante">
                <?php endif; ?>

                <?php if (!$rv['puede_facturar']) : ?>
                    <p class="nota nota--error mt-md" role="alert">
                        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
                        <span><strong>Falta información indispensable</strong> (<?= (int) $rv['resumen']['error'] ?> error(es) obligatorio(s)).
                            Completa los campos de arriba o corrige los puntos marcados: no se puede avanzar a facturar.</span>
                    </p>
                <?php else : ?>
                    <p class="nota nota--info mt-md" role="status">
                        <span class="material-symbols-outlined nota__icono" aria-hidden="true">check_circle</span>
                        <span>Validación aprobada<?php if ($rv['resumen']['advertencia'] > 0) : ?> con <?= (int) $rv['resumen']['advertencia'] ?> advertencia(s) por revisar<?php endif; ?>.</span>
                    </p>
                <?php endif; ?>

                <p class="texto-sm texto-error" id="f-falta" hidden></p>
            </div>
        </template>

        <!-- Formulario real: el modal sólo llena estos campos y lo envía. -->
        <form id="form-fiscal" action="/admin/ventas/fiscal" method="post" hidden>
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
            <input type="hidden" name="accion" value="emitir">
            <input type="hidden" name="venta" value="<?= $ventaFiscalId ?>">
            <input type="hidden" id="env-rfc" name="rfc" value="">
            <input type="hidden" id="env-cp" name="codigo_postal" value="">
            <input type="hidden" id="env-regimen" name="regimen_fiscal" value="">
            <input type="hidden" id="env-uso" name="uso_cfdi" value="">
            <input type="hidden" id="env-metodo" name="metodo_pago" value="">
            <input type="hidden" id="env-forma" name="forma_pago" value="">
            <input type="hidden" id="env-sustitucion" name="sustitucion" value="">
            <input type="hidden" id="env-motivo" name="motivo_sustitucion" value="">
        </form>

        <script>
        /* Modal de revisión previa a facturar (FASE 5).
         * Abre la revisión clasificada; sólo envía si los campos indispensables
         * están completos (el servidor vuelve a validar todo antes de avanzar).
         * Sin JS el resumen de arriba sigue visible y el formulario de
         * facturación queda bloqueado en el servidor si hay errores. */
        (function () {
            var boton = document.getElementById('btn-revision-fiscal');
            if (!boton) { return; }
            if (typeof abrirModal !== 'function') { boton.hidden = true; return; }

            var copiar = function (origen, destino) {
                var o = document.getElementById(origen), d = document.getElementById(destino);
                if (o && d) { d.value = o.value; }
            };

            boton.addEventListener('click', function () {
                var plantilla = document.getElementById('tpl-revision-fiscal');
                var cuerpo = plantilla.content.cloneNode(true);

                abrirModal({
                    sobreTitulo: 'Facturación de la venta',
                    titulo: 'Revisión previa a facturar',
                    cuerpo: cuerpo,
                    textoConfirmar: 'Continuar y facturar',
                    alConfirmar: function () {
                        var faltan = [];
                        var rfc = (document.getElementById('f-rfc').value || '').trim();
                        var cp = (document.getElementById('f-cp').value || '').trim();
                        var regimen = document.getElementById('f-regimen').value;
                        var uso = document.getElementById('f-uso').value;
                        var metodo = document.getElementById('f-metodo').value;
                        var forma = document.getElementById('f-forma').value;

                        if (rfc === '') { faltan.push('RFC del receptor'); }
                        if (!/^[A-Za-zÑñ&]{3,4}\d{6}[A-Za-z0-9]{3}$/.test(rfc.replace(/[\s\-.]/g, '').toUpperCase())
                            && !/^(XAXX010101000|XEXX010101000)$/.test(rfc.replace(/[\s\-.]/g, '').toUpperCase())) {
                            faltan.push('el formato del RFC');
                        }
                        if (!/^\d{5}$/.test(cp)) { faltan.push('el código postal fiscal (5 dígitos)'); }
                        if (regimen === '') { faltan.push('el régimen fiscal'); }
                        if (uso === '') { faltan.push('el uso del CFDI'); }
                        if (metodo === '') { faltan.push('el método de pago'); }
                        if (metodo === 'PUE' && forma === '') { faltan.push('la forma de pago (obligatoria con PUE)'); }
                        var sustitucion = document.getElementById('f-sustitucion');
                        var motivo = document.getElementById('f-motivo');
                        if (sustitucion && sustitucion.checked && (!motivo || motivo.value.trim().length < 5)) {
                            faltan.push('el motivo de la sustitución (mínimo 5 caracteres)');
                        }

                        var aviso = document.getElementById('f-falta');
                        if (faltan.length > 0) {
                            aviso.textContent = 'No se puede continuar: falta ' + faltan.join(', ') + '.';
                            aviso.hidden = false;
                            return;
                        }

                        copiar('f-rfc', 'env-rfc');
                        copiar('f-cp', 'env-cp');
                        copiar('f-regimen', 'env-regimen');
                        copiar('f-uso', 'env-uso');
                        copiar('f-metodo', 'env-metodo');
                        copiar('f-forma', 'env-forma');
                        if (sustitucion) { document.getElementById('env-sustitucion').value = sustitucion.checked ? '1' : ''; }
                        if (motivo) { document.getElementById('env-motivo').value = motivo.value; }
                        document.getElementById('form-fiscal').submit();
                    }
                });
            });
        })();
        </script>
    <?php endif; ?>

    <?php if ($editable) : ?>
        <form class="formulario" action="/admin/contabilidad/ingresos/accion" method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="id" value="<?= (int) $ingreso['id'] ?>">
            <input type="hidden" name="accion" value="facturacion">
            <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">
            <div class="formulario__rejilla">
                <div class="campo">
                    <label class="campo__etiqueta" for="estado_facturacion">¿Ya se facturó?</label>
                    <select class="control" id="estado_facturacion" name="estado_facturacion">
                        <?php foreach (\App\Contabilidad::FACTURACION as $clave => $d) : ?>
                            <option value="<?= $clave ?>"<?= $ingreso['estado_facturacion'] === $clave ? ' selected' : '' ?>><?= escaparValores($d['etiqueta']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label class="campo__etiqueta" for="doc-archivo">Subir factura (PDF y/o XML)</label>
                    <input class="control" type="file" id="doc-archivo" name="archivo[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                    <span class="campo__ayuda">Varios a la vez: PDF, XML o foto (JPG, PNG, WebP) · máx. <?= $maxMb ?> MB cada uno.</span>
                </div>
            </div>
            <details class="mt-md">
                <summary class="texto-sm">Folio, UUID y fecha (opcional)</summary>
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
            <?php if ($cfdi) : ?>
                <p class="nota nota--info mt-md" role="status"><span>CFDI adjunto: <strong><?= escaparValores($cfdi['nombre_original']) ?></strong> (<?= escaparValores($cfdi['uuid_fiscal']) ?>). Al marcar «Facturada» se vincula a la venta.</span></p>
            <?php endif; ?>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar facturación</button>
                <a class="btn btn--secundario" href="/admin/contabilidad/lectura?destino=venta&ingreso=<?= (int) $ingreso['id'] ?>">Leer factura (XML, PDF o foto)</a></div>
        </form>
    <?php elseif ($cerrado) : ?>
        <p class="texto-secundario">El periodo contable está cerrado: la facturación y los cobros no se pueden modificar.</p>
    <?php else : ?>
        <p class="texto-secundario">Este ingreso está cancelado: la facturación y los cobros quedaron fijos.</p>
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

    <?php template('documentosOperacion', ['entidad' => 'ingreso', 'entidadId' => (int) $ingreso['id'], 'retorno' => $volver,
        'bloqueado' => $cerrado || $ingreso['estado'] === 'cancelado', 'sinSubida' => true, 'interna' => true]); ?>
</section>
