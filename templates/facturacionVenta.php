<?php
/**
 * templates/facturacionVenta.php — Control visible del estado de facturación de una venta.
 * Recibe: $ingreso (Contabilidad::buscarIngreso) y $volver (ruta interna a la que se regresa).
 * Cambiar a «Facturada» exige un CFDI adjunto o una confirmación manual explícita (queda registrada).
 */

$fact     = \App\Contabilidad::FACTURACION[$ingreso['estado_facturacion']];
$cerrado  = \App\Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], $ingreso['fecha']);
$editable = $ingreso['estado'] === 'vigente' && !$cerrado;
$cfdi     = \App\Contabilidad::cfdiDeIngreso((int) $ingreso['id']);
$historial = \App\Contabilidad::historialFacturacion((int) $ingreso['id']);
$etiquetas = static fn (?string $e): string => $e !== null && isset(\App\Contabilidad::FACTURACION[$e]) ? \App\Contabilidad::FACTURACION[$e]['etiqueta'] : '—';
?>
<section class="tarjeta mb-lg" id="facturacion">
    <h2 class="titulo-5 mb-md">Facturación
        <span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span>
        <span class="badge badge--info"><?= \App\Contabilidad::centavos($ingreso['iva']) > 0 ? 'Con IVA' : 'Sin IVA' ?></span>
        <?php if ($ingreso['estado_facturacion'] === 'facturada') : ?>
            <span class="badge <?= $ingreso['facturacion_manual'] ? 'badge--pendiente' : 'badge--aprobado' ?>"><?= $ingreso['facturacion_manual'] ? 'Confirmación manual' : 'Con CFDI adjunto' ?></span>
        <?php endif; ?>
    </h2>
    <p class="campo__ayuda mb-md">El IVA solo define el estado inicial (con IVA: pendiente de facturar; sin IVA: no facturada). No prueba que exista un CFDI: una venta entra al cálculo de CFDI emitidos únicamente cuando la marcas como facturada.</p>

    <?php if ($editable) : ?>
        <form class="formulario" action="/admin/contabilidad/ingresos/accion" method="post" novalidate>
            <input type="hidden" name="id" value="<?= (int) $ingreso['id'] ?>">
            <input type="hidden" name="accion" value="facturacion">
            <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">
            <div class="formulario__rejilla">
                <div class="campo">
                    <label class="campo__etiqueta" for="fv_estado">Estado de facturación</label>
                    <select class="control" id="fv_estado" name="estado_facturacion">
                        <?php foreach (\App\Contabilidad::FACTURACION as $clave => $d) : ?>
                            <option value="<?= $clave ?>"<?= $ingreso['estado_facturacion'] === $clave ? ' selected' : '' ?>><?= escaparValores($d['etiqueta']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo"><label class="campo__etiqueta" for="fv_folio">Folio de la factura</label>
                    <input class="control" type="text" id="fv_folio" name="factura_folio" maxlength="40" value="<?= escaparValores((string) $ingreso['factura_folio']) ?>"></div>
                <div class="campo"><label class="campo__etiqueta" for="fv_uuid">UUID</label>
                    <input class="control" type="text" id="fv_uuid" name="uuid_fiscal" maxlength="36" value="<?= escaparValores((string) ($ingreso['uuid_fiscal'] ?: ($cfdi['uuid_fiscal'] ?? ''))) ?>"></div>
                <div class="campo"><label class="campo__etiqueta" for="fv_fecha">Fecha de la factura</label>
                    <input class="control" type="date" id="fv_fecha" name="fecha_facturacion" value="<?= escaparValores((string) $ingreso['fecha_facturacion']) ?>"></div>
            </div>
            <?php if ($cfdi) : ?>
                <p class="nota nota--info mt-md" role="status"><span>CFDI adjunto: <strong><?= escaparValores($cfdi['nombre_original']) ?></strong> (<?= escaparValores($cfdi['uuid_fiscal']) ?>). Al marcar «Facturada» se vincula a la venta.</span></p>
            <?php else : ?>
                <p class="nota nota--advertencia mt-md" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span>
                    <span>No hay un CFDI adjunto a esta venta (puedes adjuntarlo en <a href="/admin/contabilidad/ingresos/detalle?id=<?= (int) $ingreso['id'] ?>">el detalle del ingreso</a>). Sin CFDI, «Facturada» requiere confirmar manualmente y queda registrado como tal.</span></p>
                <label class="comprobacion mt-md" for="fv_manual"><input type="checkbox" id="fv_manual" name="confirma_manual" value="1">
                    Confirmo bajo mi responsabilidad que la factura ya se emitió (sin documento verificable)</label>
            <?php endif; ?>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar facturación</button>
                <a class="btn btn--secundario" href="/admin/contabilidad/lectura?destino=venta&ingreso=<?= (int) $ingreso['id'] ?>">Leer factura (XML, PDF o foto)</a></div>
        </form>
    <?php elseif ($cerrado) : ?>
        <p class="texto-secundario">El periodo contable está cerrado: la facturación no se puede modificar.</p>
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
</section>
