<?php
/**
 * views/admin/fiscal/registros.php — Datos de entrada del motor que no salen de ventas ni gastos:
 * retenciones, ajustes y los importes realmente DECLARADOS y PAGADOS.
 */

soloAdmin();

$anio    = (int) ($_GET['anio'] ?? date('Y'));
$anio    = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) date('Y');
$contrib = \App\Contabilidad::contribuyentePrincipal();
$ret     = \App\RegistrosFiscales::retenciones($contrib, $anio);
$aju     = \App\RegistrosFiscales::ajustes($contrib, $anio);
$dec     = \App\RegistrosFiscales::declaraciones($contrib, $anio);
$periodo = ['anio' => $anio, 'mes' => (int) date('n')];

template('header', ['titulo' => 'Retenciones, ajustes y pagos', 'subtitulo' => 'Fiscal ' . $anio, 'rutaActiva' => '/admin/fiscal']);
template('tabsFiscal', ['tab' => 'registros', 'periodo' => $periodo]);
?>

<form class="buscador mb-lg" action="/admin/fiscal/registros" method="get">
    <label class="solo-lectores" for="anio">Año</label>
    <select class="control control--compacto" id="anio" name="anio">
        <?php foreach (\App\Contabilidad::aniosConMovimientos() as $a) : ?><option value="<?= $a ?>"<?= $a === $anio ? ' selected' : '' ?>><?= $a ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn--secundario btn--sm" type="submit">Ver año</button>
</form>

<!-- Pagos reales -->
<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Importes declarados y pagados realmente</h2>
    <p class="campo__ayuda" style="padding:0 1rem">Con estos datos la plataforma compara su estimación contra lo que se presentó al SAT. El ISR de cada mes toma como «pago provisional anterior» lo que registres aquí como pagado.</p>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Declaraciones registradas</caption>
            <thead><tr><th scope="col">Mes</th><th scope="col">Impuesto</th><th scope="col" class="tabla--numerica">Declarado</th><th scope="col" class="tabla--numerica">Saldo a favor</th>
                <th scope="col" class="tabla--numerica">Pagado</th><th scope="col">Fecha de pago</th><th scope="col">Referencia</th></tr></thead>
            <tbody>
                <?php foreach ($dec as $d) : ?>
                    <tr><td><?= nombreMes((int) $d['mes']) ?></td><td><?= escaparValores($d['impuesto']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($d['importe_declarado']) ?></td><td class="tabla--numerica cifra"><?= formatoMoneda($d['saldo_favor']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($d['importe_pagado']) ?></td>
                        <td><?= $d['fecha_pago'] ? date('d/m/Y', strtotime($d['fecha_pago'])) : '—' ?></td><td><?= escaparValores((string) $d['referencia']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$dec) : ?><tr><td colspan="7" class="tabla--centro texto-secundario">Aún no hay declaraciones registradas para <?= $anio ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <form class="formulario" style="padding:1rem" action="/admin/fiscal/registros/accion" method="post" novalidate>
        <input type="hidden" name="accion" value="declaracion"><input type="hidden" name="anio" value="<?= $anio ?>">
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="d-mes">Mes</label>
                <select class="control" id="d-mes" name="mes"><?php for ($i = 1; $i <= 12; $i++) : ?><option value="<?= $i ?>"><?= nombreMes($i) ?></option><?php endfor; ?></select></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="d-imp">Impuesto</label>
                <select class="control" id="d-imp" name="impuesto"><option>ISR</option><option>IVA</option></select></div>
            <div class="campo"><label class="campo__etiqueta" for="d-decl">Importe declarado a cargo</label>
                <input class="control control--moneda" type="number" id="d-decl" name="declarado" min="0" step="0.01" value="0"></div>
            <div class="campo"><label class="campo__etiqueta" for="d-fav">Saldo a favor declarado</label>
                <input class="control control--moneda" type="number" id="d-fav" name="saldo_favor" min="0" step="0.01" value="0"></div>
            <div class="campo"><label class="campo__etiqueta" for="d-pag">Importe pagado</label>
                <input class="control control--moneda" type="number" id="d-pag" name="pagado" min="0" step="0.01" value="0"></div>
            <div class="campo"><label class="campo__etiqueta" for="d-fpag">Fecha de pago</label><input class="control" type="date" id="d-fpag" name="fecha_pago"></div>
            <div class="campo"><label class="campo__etiqueta" for="d-fpres">Fecha de presentación</label><input class="control" type="date" id="d-fpres" name="fecha_presentacion"></div>
            <div class="campo"><label class="campo__etiqueta" for="d-ref">Número de operación / línea de captura</label><input class="control" type="text" id="d-ref" name="referencia" maxlength="80"></div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar importes reales</button></div>
    </form>
</section>

<!-- Retenciones -->
<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Retenciones de ISR e IVA que te hicieron tus clientes</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Retenciones</caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col">Impuesto</th><th scope="col">Retenedor</th><th scope="col" class="tabla--numerica">Importe</th><th scope="col">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
                <?php foreach ($ret as $r) : ?>
                    <tr<?= $r['estado'] === 'cancelado' ? ' style="opacity:.6"' : '' ?>><td><?= date('d/m/Y', strtotime($r['fecha'])) ?></td><td><?= escaparValores($r['impuesto']) ?></td>
                        <td><?= escaparValores($r['retenedor']) ?></td><td class="tabla--numerica cifra"><?= formatoMoneda($r['importe']) ?></td>
                        <td><?= $r['estado'] === 'vigente' ? '<span class="badge badge--aprobado">Vigente</span>' : '<span class="badge badge--error">Cancelada</span> ' . escaparValores((string) $r['motivo_cancelacion']) ?></td>
                        <td class="tabla--numerica"><?php if ($r['estado'] === 'vigente') : ?>
                            <form action="/admin/fiscal/registros/accion" method="post" data-validar data-confirmacion-sobre="Retención" data-confirmacion="¿Cancelar la retención?"
                                  data-confirmacion-mensaje="Se conserva como cancelada, con tu motivo." data-confirmacion-boton="Sí, cancelar">
                                <input type="hidden" name="accion" value="retencion_cancelar"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="anio" value="<?= $anio ?>">
                                <label class="solo-lectores" for="rm-<?= (int) $r['id'] ?>">Motivo</label>
                                <input class="control control--compacto" type="text" id="rm-<?= (int) $r['id'] ?>" name="motivo" placeholder="Motivo" required minlength="5" maxlength="200">
                                <button class="btn btn--peligro btn--sm" type="submit">Cancelar</button></form><?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$ret) : ?><tr><td colspan="6" class="tabla--centro texto-secundario">Sin retenciones registradas en <?= $anio ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <form class="formulario" style="padding:1rem" action="/admin/fiscal/registros/accion" method="post" novalidate>
        <input type="hidden" name="accion" value="retencion"><input type="hidden" name="anio" value="<?= $anio ?>">
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="r-fecha">Fecha</label><input class="control" type="date" id="r-fecha" name="fecha" required value="<?= date('Y-m-d') ?>"></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="r-imp">Impuesto</label><select class="control" id="r-imp" name="impuesto"><option>ISR</option><option>IVA</option></select></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="r-importe">Importe retenido</label><input class="control control--moneda" type="number" id="r-importe" name="importe" min="0.01" step="0.01" required></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="r-ret">Quién retuvo</label><input class="control" type="text" id="r-ret" name="retenedor" maxlength="150" required></div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Registrar retención</button></div>
    </form>
</section>

<!-- Ajustes -->
<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Ajustes y acreditamientos permitidos</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Ajustes fiscales</caption>
            <thead><tr><th scope="col">Mes</th><th scope="col">Concepto</th><th scope="col" class="tabla--numerica">Importe</th><th scope="col">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
                <?php foreach ($aju as $a) : ?>
                    <tr<?= $a['estado'] === 'cancelado' ? ' style="opacity:.6"' : '' ?>><td><?= nombreMes((int) $a['mes']) ?></td><td><?= escaparValores(\App\RegistrosFiscales::CONCEPTOS_AJUSTE[$a['concepto']] ?? $a['concepto']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($a['importe']) ?></td>
                        <td><?= $a['estado'] === 'vigente' ? '<span class="badge badge--aprobado">Vigente</span>' : '<span class="badge badge--error">Cancelado</span>' ?></td>
                        <td class="tabla--numerica"><?php if ($a['estado'] === 'vigente') : ?>
                            <form action="/admin/fiscal/registros/accion" method="post" data-validar data-confirmacion-sobre="Ajuste" data-confirmacion="¿Cancelar el ajuste?"
                                  data-confirmacion-mensaje="Se conserva como cancelado, con tu motivo." data-confirmacion-boton="Sí, cancelar">
                                <input type="hidden" name="accion" value="ajuste_cancelar"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="anio" value="<?= $anio ?>">
                                <label class="solo-lectores" for="am-<?= (int) $a['id'] ?>">Motivo</label>
                                <input class="control control--compacto" type="text" id="am-<?= (int) $a['id'] ?>" name="motivo" placeholder="Motivo" required minlength="5" maxlength="200">
                                <button class="btn btn--peligro btn--sm" type="submit">Cancelar</button></form><?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$aju) : ?><tr><td colspan="5" class="tabla--centro texto-secundario">Sin ajustes registrados en <?= $anio ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <form class="formulario" style="padding:1rem" action="/admin/fiscal/registros/accion" method="post" novalidate>
        <input type="hidden" name="accion" value="ajuste"><input type="hidden" name="anio" value="<?= $anio ?>">
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="a-mes">Mes</label>
                <select class="control" id="a-mes" name="mes"><?php for ($i = 1; $i <= 12; $i++) : ?><option value="<?= $i ?>"><?= nombreMes($i) ?></option><?php endfor; ?></select></div>
            <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="a-concepto">Concepto</label>
                <select class="control" id="a-concepto" name="concepto"><?php foreach (\App\RegistrosFiscales::CONCEPTOS_AJUSTE as $k => $t) : ?><option value="<?= $k ?>"><?= escaparValores($t) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="a-importe">Importe</label><input class="control control--moneda" type="number" id="a-importe" name="importe" min="0.01" step="0.01" required></div>
            <div class="campo"><label class="campo__etiqueta" for="a-nota">Nota</label><input class="control" type="text" id="a-nota" name="nota" maxlength="200"></div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Registrar ajuste</button></div>
    </form>
</section>

<?php template('footer'); ?>
