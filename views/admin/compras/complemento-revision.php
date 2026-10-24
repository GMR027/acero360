<?php
/**
 * views/admin/compras/complemento-revision.php — Revisión de un complemento de pago.
 *
 * Muestra los datos del CFDI tipo «P», cada factura relacionada (DoctoRelacionado)
 * con su compra, los pagos ya registrados y las discrepancias; la persona decide
 * cuáles vincular y, solo si lo pide, si registra el pago que aún falta.
 *
 * Esta pantalla NO escribe nada: la única escritura es ComplementosPago::registrar().
 */

soloAdmin();

$id = (int) ($_REQUEST['id'] ?? 0);
$analisis = \App\ComplementosPago::analizar($id);
if ($analisis === null) {
    paginaNoEncontrada();
}

$lectura = $analisis['lectura'];
$datos = $analisis['datos'];
$comp = $analisis['complemento'];
$duplicado = $analisis['duplicado'];
$doctos = $analisis['doctos'];
$fechaCorta = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $analisis['fecha_pago'])
    ? date('d/m/Y', strtotime(substr((string) $analisis['fecha_pago'], 0, 10))) : '—';
$procesada = (string) $lectura['estado'] !== 'pendiente';
/* De qué lado es el complemento: si el emisor soy yo, es un cobro a cliente
 * y NO se registra en Compras (va en Ventas → Complementos de pago). */
$ladoOk = ($analisis['lado'] ?? 'proveedor') === 'proveedor';

/** Etiqueta y clase del estado de cada factura relacionada. */
$estadoDocto = static function (string $e): array {
    return match ($e) {
        'vinculado'    => ['Vinculado', 'badge--aprobado'],
        'sin_pago'     => ['Pago sin registrar', 'badge--pendiente'],
        'discrepancia' => ['Discrepancia', 'badge--error'],
        default        => ['Sin factura', 'badge--neutro'],
    };
};

template('header', ['titulo' => 'Revisar complemento', 'subtitulo' => 'Complementos de pago']);
template('tabsCompras', ['tab' => 'complemento']);
?>

<?php foreach ($analisis['avisos'] as $aviso) : ?>
    <p class="texto-sm texto-error mb-md">⚠ <?= escaparValores($aviso) ?></p>
<?php endforeach; ?>

<?php if ($procesada) : ?>
    <div class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">check_circle</span>
        <span>Este complemento ya fue procesado (estado «<?= escaparValores($lectura['estado']) ?>»): solo consulta.
            <a href="/admin/compras/complemento">Volver a complementos</a></span>
    </div>
<?php endif; ?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Lo que dice el complemento</h2>
    <div class="formulario__rejilla">
        <div class="campo"><span class="campo__etiqueta">UUID del complemento</span>
            <span><?= escaparValores($datos['uuid'] ?: 'sin timbre') ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Proveedor (emisor)</span>
            <span><?= escaparValores($datos['emisor_nombre'] ?: '—') ?><?= $datos['emisor_rfc'] ? ' · ' . escaparValores($datos['emisor_rfc']) : '' ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Fecha de pago</span>
            <span><?= escaparValores($fechaCorta) ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Monto del complemento</span>
            <span class="cifra"><strong><?= $comp ? formatoMoneda((float) ($comp['monto'] ?: $datos['total'])) : '—' ?></strong></span></div>
        <div class="campo"><span class="campo__etiqueta">Moneda</span>
            <span><?= escaparValores($comp['moneda'] ?? $datos['moneda'] ?? 'MXN') ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Forma de pago (SAT)</span>
            <span><?= escaparValores($comp['forma_pago'] ?: '—') ?></span></div>
    </div>
    <?php if ($analisis['periodo_cerrado']) : ?>
        <p class="texto-sm texto-error mt-md">⚠ <?= escaparValores(\App\Contabilidad::mensajePeriodoCerrado((string) $analisis['fecha_pago'])) ?></p>
    <?php endif; ?>

    <?php if ($duplicado) : ?>
        <div class="nota nota--error mt-md" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><strong>Este complemento ya está registrado</strong> (UUID <?= escaparValores($duplicado['uuid']) ?>,
                fecha <?= escaparValores(date('d/m/Y', strtotime($duplicado['fecha_pago']))) ?>):
                no se vuelve a cargar y no se duplica ningún pago.
                <a href="/admin/compras/complemento">Ver complementos registrados</a></span>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Facturas relacionadas (<?= count($doctos) ?>)</h2>
    <?php if (!$doctos) : ?>
        <p class="texto-sm texto-secundario">El complemento no trae facturas relacionadas: no hay nada que vincular.</p>
    <?php else : ?>
        <p class="texto-sm texto-secundario mb-md">Cada fila es una factura del proveedor. Si su pago ya existe se VINCULA
            (nunca se crea otro); si no existe, puedes pedir que se registre. Las discrepancias se ven antes de confirmar.</p>

        <form action="/admin/compras/complemento/accion" method="post" id="form-complemento">
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
            <input type="hidden" name="accion" value="registrar">
            <input type="hidden" name="lectura" value="<?= $id ?>">

            <?php foreach ($doctos as $d) : [$etiqueta, $clase] = $estadoDocto($d['estado']); ?>
                <article class="tarjeta mb-md">
                    <p class="texto-sm">
                        <span class="badge <?= $clase ?>"><?= escaparValores($etiqueta) ?></span>
                        <?php if ($d['serie'] || $d['folio']) : ?>
                            · Factura <strong><?= escaparValores(trim($d['serie'] . ' ' . $d['folio'])) ?></strong>
                        <?php endif; ?>
                        <?= $d['num_parcialidad'] ? ' · parcialidad ' . escaparValores($d['num_parcialidad']) : '' ?>
                        <br><span class="texto-xs texto-secundario">UUID <?= escaparValores($d['uuid'] ?: '—') ?></span>
                    </p>

                    <dl class="cotizador-detalle__cliente mt-md">
                        <?php if ($d['orden']) : ?>
                            <div><dt>Compra</dt><dd><a href="/admin/compras/detalle?id=<?= (int) $d['orden']['id'] ?>">
                                <?= escaparValores($d['orden']['folio']) ?></a>
                                · <?= escaparValores($d['orden']['proveedor']) ?></dd></div>
                            <div><dt>Estado compra</dt><dd>
                                <span class="badge <?= $d['orden']['estado_cfdi'] === 'con_cfdi' ? 'badge--aprobado' : 'badge--pendiente' ?>">
                                    <?= $d['orden']['estado_cfdi'] === 'con_cfdi' ? 'Con factura' : 'Pendiente de recibir CFDI' ?></span>
                                <span class="badge <?= ['pendiente' => 'badge--neutro', 'parcial' => 'badge--pendiente', 'pagada' => 'badge--aprobado'][ $d['orden']['estado_pago'] ] ?>">
                                    <?= ['pendiente' => 'Por pagar', 'parcial' => 'Pago parcial', 'pagada' => 'Pagada'][ $d['orden']['estado_pago'] ] ?></span>
                            </dd></div>
                            <div><dt>Saldo compra</dt><dd class="cifra"><?= formatoMoneda($d['orden']['saldo']) ?>
                                <span class="texto-xs texto-secundario">(de <?= formatoMoneda($d['orden']['total']) ?>,
                                    pagado <?= formatoMoneda($d['orden']['pagado']) ?>)</span></dd></div>
                        <?php else : ?>
                            <div><dt>Compra</dt><dd><em><?= escaparValores($d['nota']) ?></em></dd></div>
                        <?php endif; ?>

                        <?php if ($d['saldo_anterior'] > 0) : ?>
                            <div><dt>Saldo anterior</dt><dd class="cifra"><?= formatoMoneda($d['saldo_anterior']) ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Pagado según complemento</dt><dd class="cifra"><strong><?= formatoMoneda($d['importe_pagado']) ?></strong></dd></div>

                        <?php if ($d['pagos']) : ?>
                            <div><dt>Pagos registrados</dt><dd>
                                <?php foreach ($d['pagos'] as $pg) : ?>
                                    <span class="badge badge--neutro"><?= formatoMoneda($pg['importe']) ?>
                                        · <?= date('d/m/Y', strtotime($pg['fecha'])) ?></span>
                                <?php endforeach; ?>
                            </dd></div>
                        <?php endif; ?>

                        <?php if ($d['estado'] === 'discrepancia') : ?>
                            <div><dt class="texto-error">Discrepancia</dt>
                                <dd class="texto-error">Diferencia de <?= formatoMoneda($d['diferencia']) ?></dd></div>
                        <?php endif; ?>
                    </dl>

                    <p class="texto-sm mt-md"><?= escaparValores($d['nota']) ?></p>

                    <?php if ($d['orden'] && !$procesada && !$duplicado && !$analisis['periodo_cerrado']) : ?>
                        <div class="formulario__acciones mt-md">
                            <label class="comprobacion" for="vincular_<?= (int) $d['indice'] ?>">
                                <input type="checkbox" id="vincular_<?= (int) $d['indice'] ?>" name="doctos[<?= (int) $d['indice'] ?>][vincular]" value="1" checked
                                    <?= $d['estado'] === 'sin_factura' ? 'disabled' : '' ?>>
                                <span>Vincular esta factura a su compra y pagos</span>
                            </label>
                            <?php if ($d['puede_crear']) : ?>
                                <label class="comprobacion" for="crear_<?= (int) $d['indice'] ?>">
                                    <input type="checkbox" id="crear_<?= (int) $d['indice'] ?>" name="doctos[<?= (int) $d['indice'] ?>][crear_pago]" value="1">
                                    <span>Registrar el pago que el complemento evidencia y aún no existe
                                        (<?= formatoMoneda($d['importe_pagado']) ?>)</span>
                                </label>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <?php if (!$procesada && !$duplicado && !$analisis['periodo_cerrado'] && $ladoOk) : ?>
                <div class="formulario__acciones mt-lg">
                    <a class="btn btn--secundario" href="/admin/compras/complemento">Cancelar</a>
                    <button class="btn btn--primario" type="submit"
                        <?= $comp === null ? 'disabled' : '' ?>>Confirmar complemento</button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>

<?php if ($comp === null && !$procesada) : ?>
    <p class="texto-sm texto-error">No hay complemento de pagos que registrar en este archivo.</p>
<?php endif; ?>

<?php template('footer'); ?>
