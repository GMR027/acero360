<?php
/**
 * views/admin/ventas/complemento-revision.php — Revisión de un complemento de pago de cliente.
 *
 * Muestra el CFDI tipo «P» cargado y, para CADA factura que relaciona
 * (DoctoRelacionado): su venta, su ingreso, la facturación y el cobro (estados
 * separados), los cobros registrados uno por uno, los importes y saldos
 * comparados con lo que declara el complemento y las discrepancias — todo antes
 * de confirmar.
 *
 * Esta pantalla NO escribe nada: la única escritura es ComplementosVenta::registrar().
 */

soloAdmin();

$id = (int) ($_REQUEST['id'] ?? 0);
$analisis = \App\ComplementosVenta::analizar($id);

if ($analisis === null) {
    paginaNoEncontrada();
}

$lectura = $analisis['lectura'];
$datos = $analisis['datos'];
$comp = $analisis['complemento'];
$duplicado = $analisis['duplicado'];
$doctos = $analisis['doctos'];
$procesada = (string) $lectura['estado'] !== 'pendiente';
$fechaCorta = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $analisis['fecha_pago'])
    ? date('d/m/Y', strtotime(substr((string) $analisis['fecha_pago'], 0, 10))) : '—';
$activo = !$procesada && $analisis['lado_ok'] && $duplicado === null && !$analisis['periodo_cerrado'] && $comp !== null;

/** Etiqueta del estado de cada factura relacionada del complemento. */
$estadoDocto = static fn (string $e): array => \App\ComplementosVenta::ESTADOS[$e]
    ?? ['etiqueta' => $e, 'clase' => 'badge--neutro'];

template('header', ['titulo' => 'Revisar complemento', 'subtitulo' => 'Complementos de pago']);
?>

<?php foreach ($analisis['avisos'] as $aviso) : ?>
    <p class="texto-sm texto-error mb-md">⚠ <?= escaparValores($aviso) ?></p>
<?php endforeach; ?>

<?php if ($procesada) : ?>
    <div class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">check_circle</span>
        <span>Este complemento ya fue procesado (estado «<?= escaparValores($lectura['estado']) ?>»): sólo consulta.
            <a href="/admin/ventas/complemento">Volver a complementos</a></span>
    </div>
<?php endif; ?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Lo que dice el complemento</h2>
    <div class="formulario__rejilla">
        <div class="campo"><span class="campo__etiqueta">UUID del complemento</span>
            <span><?= escaparValores($datos['uuid'] ?: 'sin timbre') ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Emisor (tú, si cobraste)</span>
            <span><?= escaparValores($datos['emisor_nombre'] ?: '—') ?><?= $datos['emisor_rfc'] ? ' · ' . escaparValores($datos['emisor_rfc']) : '' ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Receptor (cliente)</span>
            <span><?= escaparValores($datos['receptor_nombre'] ?: '—') ?><?= $datos['receptor_rfc'] ? ' · ' . escaparValores($datos['receptor_rfc']) : '' ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Fecha de pago</span>
            <span><?= escaparValores($fechaCorta) ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Monto del complemento</span>
            <span class="cifra"><strong><?= $comp ? formatoMoneda((float) ($comp['monto'] ?: $datos['total'])) : '—' ?></strong></span></div>
        <div class="campo"><span class="campo__etiqueta">Forma de pago (SAT)</span>
            <span><?= escaparValores(trim((string) ($comp['forma_pago'] ?? '')) !== '' ? $comp['forma_pago'] : '—') ?></span></div>
    </div>
    <p class="campo__ayuda mt-sm">El complemento declara <strong>pagos</strong>; no factura ni modifica ventas:
        sólo los relaciona con el CFDI y los cobros que ya existen.</p>

    <?php if ($analisis['periodo_cerrado']) : ?>
        <p class="texto-sm texto-error mt-md">⚠ <?= escaparValores(\App\Contabilidad::mensajePeriodoCerrado((string) $analisis['fecha_pago'])) ?></p>
    <?php endif; ?>

    <?php if (!$analisis['puede_emitir']) : ?>
        <p class="nota nota--info mt-md" role="status"><span>Sin PAC no se emiten complementos desde el sistema:
            este registro respalda el XML emitido fuera.</span></p>
    <?php endif; ?>

    <?php if ($duplicado) : ?>
        <div class="nota nota--error mt-md" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><strong>Este complemento ya está registrado</strong> (UUID <?= escaparValores($duplicado['uuid']) ?>):
                no se vuelve a cargar y <strong>no se duplica ningún cobro</strong>.
                <a href="/admin/ventas/complemento">Ver complementos registrados</a></span>
        </div>
    <?php endif; ?>

    <?php if (!$analisis['lado_ok']) : ?>
        <div class="nota nota--error mt-md" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><strong>Este complemento no corresponde a este flujo.</strong>
                <?= $analisis['lado'] === 'proveedor'
                    ? 'Si el receptor eres tú, es un pago a proveedor: va en Compras → Complementos de pago.'
                    : 'Ni el emisor ni el receptor coincide con este negocio.' ?></span>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Facturas relacionadas (<?= count($doctos) ?>)</h2>
    <?php if (!$doctos) : ?>
        <p class="texto-sm texto-secundario">El complemento no trae facturas relacionadas (DoctoRelacionado):
            no hay nada que vincular.</p>
    <?php else : ?>
        <p class="texto-sm texto-secundario mb-md">Cada fila es una factura que el complemento dice pagar. Se comparan
            los importes, los saldos y los cobros ya registrados; <strong>se confirma sólo lo que decidas</strong>.</p>

        <form action="/admin/ventas/complemento/accion" method="post" id="form-complemento">
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
            <input type="hidden" name="accion" value="registrar">
            <input type="hidden" name="lectura" value="<?= $id ?>">

            <?php foreach ($doctos as $d) : [$etiqueta, $clase] = [$estadoDocto($d['estado'])['etiqueta'], $estadoDocto($d['estado'])['clase']]; ?>
                <article class="tarjeta mb-md">
                    <p class="texto-sm">
                        <span class="badge <?= $clase ?>"><?= escaparValores($etiqueta) ?></span>
                        <?php if ($d['serie'] || $d['folio']) : ?>
                            · Factura <strong><?= escaparValores(trim($d['serie'] . ' ' . $d['folio'])) ?></strong>
                        <?php endif; ?>
                        <?= $d['num_parcialidad'] ? ' · parcialidad ' . escaparValores($d['num_parcialidad']) : '' ?>
                        <br><span class="texto-xs texto-secundario">UUID <?= escaparValores($d['uuid'] ?: '—') ?></span>
                    </p>

                    <?php if ($d['ingreso']) : $ing = $d['ingreso']; $fact = \App\Contabilidad::FACTURACION[$ing['estado_facturacion']] ?? null; ?>
                        <dl class="cotizador-detalle__cliente mt-md">
                            <div><dt>Venta</dt><dd>
                                <?php if ($d['venta']) : ?>
                                    <a href="/admin/ventas/detalle?id=<?= (int) $d['ingreso']['venta_id'] ?>"><?= escaparValores($d['venta']['folio']) ?></a>
                                    · <?= date('d/m/Y', strtotime($d['venta']['fecha'])) ?>
                                <?php else : ?>
                                    ingreso <?= escaparValores($ing['folio']) ?> (sin venta asociada)
                                <?php endif; ?>
                            </dd></div>
                            <div><dt>Cliente</dt><dd><?= escaparValores($ing['cliente'] ?: '—') ?></dd></div>
                            <div><dt>Facturación</dt><dd>
                                <?php if ($fact) : ?><span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span>
                                <?php else : ?><span class="badge badge--neutro"><?= escaparValores($ing['estado_facturacion']) ?></span><?php endif; ?>
                                <span class="texto-xs texto-secundario">CFDI: <?= escaparValores($ing['uuid_fiscal'] ?: 'sin UUID') ?></span>
                            </dd></div>
                            <div><dt>Cobro</dt><dd>
                                <?php $cob = \App\Contabilidad::etiquetaCobro($ing['estado_cobro']); ?>
                                <span class="badge <?= $cob['clase'] ?>"><?= escaparValores($cob['etiqueta']) ?></span>
                            </dd></div>
                            <div><dt>Totales</dt><dd class="cifra">
                                <?= formatoMoneda($ing['total']) ?> · cobrado <?= formatoMoneda($ing['cobrado']) ?>
                                · saldo <?= formatoMoneda($ing['saldo']) ?></dd></div>
                        </dl>

                        <?php if ($d['cobros']) : ?>
                            <h4 class="titulo-6 mt-md mb-sm">Cobros registrados de esta venta</h4>
                            <div class="tabla-contenedor mb-sm"><table class="tabla">
                                <thead><tr><th scope="col">Fecha</th><th scope="col" class="tabla--numerica">Importe</th>
                                    <th scope="col">Método</th><th scope="col">Referencia</th>
                                    <th scope="col" class="tabla--centro">Estado</th><th scope="col">Complemento</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['cobros'] as $c) : ?>
                                    <tr<?= $c['estado'] === 'cancelado' ? ' style="opacity:.6"' : '' ?>>
                                        <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                                        <td class="tabla--numerica cifra"><?= formatoMoneda($c['importe']) ?></td>
                                        <td><?= escaparValores($c['metodo'] ?: '—') ?></td>
                                        <td class="texto-sm"><?= escaparValores($c['referencia'] ?: '—') ?></td>
                                        <td class="tabla--centro"><?= $c['estado'] === 'vigente'
                                            ? '<span class="badge badge--aprobado">Vigente</span>'
                                            : '<span class="badge badge--error">Cancelado</span>' ?></td>
                                        <td class="texto-xs"><?= $c['complemento_uuid']
                                            ? 'Sí · ' . escaparValores(substr($c['complemento_uuid'], 0, 8)) . '…' : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table></div>
                        <?php else : ?>
                            <p class="texto-sm texto-secundario mt-md">Esta venta todavía no tiene cobros registrados.</p>
                        <?php endif; ?>

                        <h4 class="titulo-6 mt-md mb-sm">Comparación con el complemento</h4>
                        <dl class="cotizador-detalle__cliente">
                            <div><dt>Declara pagado</dt><dd class="cifra"><strong><?= formatoMoneda($d['importe_pagado']) ?></strong>
                                <?php if ($d['diferencia'] != 0) : ?>
                                    <span class="texto-error">(diferencia <?= formatoMoneda(abs($d['diferencia'])) ?>)</span>
                                <?php endif; ?></dd></div>
                            <?php if ($d['saldo_anterior'] > 0) : ?>
                                <div><dt>Declara saldo anterior</dt><dd class="cifra"><?= formatoMoneda($d['saldo_anterior']) ?>
                                    <?php if (!$d['saldo_ok']) : ?>
                                        <span class="texto-error">(no coincide: diferencia <?= formatoMoneda(abs($d['diferencia_saldo'])) ?>)</span>
                                    <?php endif; ?></dd></div>
                            <?php endif; ?>
                        </dl>

                        <p class="texto-sm mt-md"><?= escaparValores($d['nota']) ?></p>

                        <?php if ($activo && $d['ingreso']) : ?>
                            <div class="formulario__acciones mt-md">
                                <label class="comprobacion" for="vincular_<?= (int) $d['indice'] ?>">
                                    <input type="checkbox" id="vincular_<?= (int) $d['indice'] ?>"
                                           name="doctos[<?= (int) $d['indice'] ?>][vincular]" value="1" checked>
                                    <span>Relacionar esta factura con el complemento</span>
                                </label>
                                <?php if ($d['puede_crear']) : ?>
                                    <label class="comprobacion" for="cobrar_<?= (int) $d['indice'] ?>">
                                        <input type="checkbox" id="cobrar_<?= (int) $d['indice'] ?>"
                                               name="doctos[<?= (int) $d['indice'] ?>][crear_cobro]" value="1">
                                        <span>Registrar el cobro que el complemento declara y aún no existe
                                            (<?= formatoMoneda($d['importe_pagado']) ?>)</span>
                                    </label>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else : ?>
                        <p class="texto-sm texto-secundario mt-md"><?= escaparValores($d['nota']) ?></p>
                        <?php if ($activo) : ?>
                            <div class="formulario__acciones mt-md">
                                <label class="comprobacion" for="vincular_<?= (int) $d['indice'] ?>">
                                    <input type="checkbox" id="vincular_<?= (int) $d['indice'] ?>"
                                           name="doctos[<?= (int) $d['indice'] ?>][vincular]" value="1" checked>
                                    <span>Guardar este documento del complemento aunque no tenga factura localizada</span>
                                </label>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <?php if ($activo) : ?>
                <div class="formulario__acciones mt-lg">
                    <a class="btn btn--secundario" href="/admin/ventas/complemento">Cancelar</a>
                    <button class="btn btn--primario" type="submit">Confirmar complemento</button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>

<?php if ($comp === null && !$procesada) : ?>
    <p class="texto-sm texto-error">No hay complemento de pagos que registrar en este archivo.</p>
<?php endif; ?>

<?php template('footer'); ?>
