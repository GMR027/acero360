<?php
/**
 * views/admin/contabilidad/ingresos/detalle.php — Un ingreso: facturación
 * explícita, cobros, documentos y enlace a la operación original.
 *
 * Todo lo que se puede indicar de una venta (facturación, CFDI y cobros) vive hoy en una sola
 * sección del detalle de la venta, así que esta ruta redirige ahí. Sólo se queda mostrando esta
 * pantalla cuando el ingreso no tiene venta asociada (registros legados).
 */

soloAdmin();

$id      = (int) ($_GET['id'] ?? 0);
$ingreso = \App\Contabilidad::buscarIngreso($id);

if (!$ingreso) {
    paginaNoEncontrada();
}

if (!empty($ingreso['venta_id'])) {
    // Sin ancla a propósito: el navegador conserva el fragmento original (#cobros, #documentos…).
    redirigir('/admin/ventas/detalle?id=' . (int) $ingreso['venta_id']);
}

$cerrado   = \App\Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], $ingreso['fecha']);
$fact      = \App\Contabilidad::FACTURACION[$ingreso['estado_facturacion']];
$cobro     = \App\Contabilidad::etiquetaCobro($ingreso['estado_cobro']);
$retorno   = '/admin/contabilidad/ingresos/detalle?id=' . $id;
$editable  = $ingreso['estado'] === 'vigente' && !$cerrado;
$periodo   = ['anio' => (int) substr($ingreso['fecha'], 0, 4), 'mes' => (int) substr($ingreso['fecha'], 5, 2), 'empresa' => 0];

template('header', ['titulo' => 'Ingreso ' . $ingreso['folio'], 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'ingresos', 'periodo' => $periodo]);
?>

<section class="tarjeta mb-lg">
    <p>
        <span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span>
        <span class="badge <?= $cobro['clase'] ?>"><?= escaparValores($cobro['etiqueta']) ?></span>
        <?= $ingreso['estado'] === 'cancelado' ? '<span class="badge badge--error">Cancelado</span>' : '' ?>
        <?= $cerrado ? '<span class="badge badge--neutro">Periodo cerrado</span>' : '' ?>
    </p>
    <p class="mt-md"><?= escaparValores($ingreso['cliente_empresa'] ?: $ingreso['cliente_nombre']) ?>
        · <?= escaparValores($ingreso['empresa_nombre']) ?> · <?= date('d/m/Y', strtotime($ingreso['fecha'])) ?></p>
    <dl class="cotizador-detalle__cliente mt-md">
        <div><dt>Subtotal</dt><dd><?= formatoMoneda($ingreso['subtotal']) ?></dd></div>
        <div><dt>Descuentos</dt><dd><?= formatoMoneda($ingreso['descuento']) ?></dd></div>
        <div><dt>IVA</dt><dd><?= formatoMoneda($ingreso['iva']) ?></dd></div>
        <div><dt>Total</dt><dd><strong><?= formatoMoneda($ingreso['total']) ?></strong></dd></div>
        <div><dt>Cobrado</dt><dd><?= formatoMoneda($ingreso['cobrado']) ?></dd></div>
        <div><dt>Saldo pendiente</dt><dd><?= formatoMoneda($ingreso['saldo']) ?></dd></div>
    </dl>
    <?php if ($ingreso['venta_id']) : ?>
        <p class="mt-md"><a class="btn btn--secundario btn--sm" href="/admin/ventas/detalle?id=<?= (int) $ingreso['venta_id'] ?>">Abrir la venta original</a>
            <?php if ($ingreso['cotizacion_id']) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/cotizaciones/detalle?id=<?= (int) $ingreso['cotizacion_id'] ?>">Ver su cotización</a>
            <?php endif; ?></p>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Factura</h2>
    <form class="formulario" action="/admin/contabilidad/ingresos/accion" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="accion" value="facturacion">
        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta" for="estado_facturacion">¿Ya se facturó?</label>
                <select class="control" id="estado_facturacion" name="estado_facturacion" <?= $editable ? '' : 'disabled' ?>>
                    <?php foreach (\App\Contabilidad::FACTURACION as $clave => $d) : ?>
                        <option value="<?= $clave ?>"<?= $ingreso['estado_facturacion'] === $clave ? ' selected' : '' ?>><?= escaparValores($d['etiqueta']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($editable) : ?>
                <div class="campo">
                    <label class="campo__etiqueta" for="doc-archivo">Subir factura (PDF y/o XML)</label>
                    <input class="control" type="file" id="doc-archivo" name="archivo[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                </div>
            <?php endif; ?>
        </div>
        <details class="mt-md">
            <summary class="texto-sm">Folio, UUID y fecha (opcional)</summary>
            <div class="formulario__rejilla mt-sm">
                <div class="campo">
                    <label class="campo__etiqueta" for="factura_folio">Folio de la factura</label>
                    <input class="control" type="text" id="factura_folio" name="factura_folio" maxlength="40" value="<?= escaparValores((string) $ingreso['factura_folio']) ?>" <?= $editable ? '' : 'disabled' ?>>
                </div>
                <div class="campo">
                    <label class="campo__etiqueta" for="uuid_fiscal">Folio fiscal UUID</label>
                    <input class="control" type="text" id="uuid_fiscal" name="uuid_fiscal" maxlength="36" value="<?= escaparValores((string) $ingreso['uuid_fiscal']) ?>" <?= $editable ? '' : 'disabled' ?>>
                </div>
                <div class="campo">
                    <label class="campo__etiqueta" for="fecha_facturacion">Fecha de la factura</label>
                    <input class="control" type="date" id="fecha_facturacion" name="fecha_facturacion" value="<?= escaparValores((string) $ingreso['fecha_facturacion']) ?>" <?= $editable ? '' : 'disabled' ?>>
                </div>
            </div>
        </details>
        <?php if ($editable) : ?>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar</button></div>
        <?php endif; ?>
    </form>
</section>

<?php template('documentosOperacion', ['entidad' => 'ingreso', 'entidadId' => $id, 'retorno' => $retorno, 'bloqueado' => $cerrado || $ingreso['estado'] === 'cancelado', 'sinSubida' => true]); ?>

<?php template('cobrosIngreso', ['ingreso' => $ingreso, 'retorno' => $retorno]); ?>

<?php template('footer'); ?>
