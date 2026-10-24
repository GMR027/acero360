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
