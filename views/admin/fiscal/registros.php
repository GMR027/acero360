<?php
/**
 * views/admin/fiscal/registros.php — Retenciones, ajustes y declaraciones (captura).
 *
 * Interfaz de los registros que lee MotorFiscal y que antes sólo existían como tablas
 * sin pantalla (FASE 7):
 *   · Retenciones (ISR/IVA):    la retención de IVA resta el IVA a cargo y el ISR
 *                               retenido acredita contra el ISR del período en que se registra.
 *   · Ajustes:                  saldo a favor y otros ajustes de IVA restan ese mes; PTU,
 *                               pérdidas y otros acreditamientos bajan la utilidad fiscal
 *                               del ISR desde ese mes en adelante.
 *   · Declaraciones y pagos:    los pagos de ISR anteriores se acreditan como pagos
 *                               provisionales; las de IVA sirven para comparar declarado
 *                               vs. estimado.
 *
 * Todo pasa por RegistrosFiscales: valida formatos, respeta periodos cerrados,
 * auditoría y cancelación con motivo (los registros no se borran).
 */

soloAdmin();

$contrib = \App\Contabilidad::contribuyentePrincipal();
$anio = (int) ($_GET['anio'] ?? $_POST['anio'] ?? date('Y'));
$anio = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) date('Y');
$destino = '/admin/fiscal/registros?anio=' . $anio;
$usuario = (int) ($_SESSION['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($destino, 'error', 'Este formulario ya se envió; no se guardó dos veces.');
    }

    $accion = (string) ($_POST['accion'] ?? '');
    $exito = '';
    $ok = false;

    if ($accion === 'retencion') {
        $ingresoVinculo = trim((string) ($_POST['ingreso_id'] ?? '')) !== '' ? (int) $_POST['ingreso_id'] : null;
        $ok = \App\RegistrosFiscales::registrarRetencion(
            $contrib, (string) ($_POST['fecha'] ?? ''), (string) ($_POST['impuesto'] ?? ''),
            (string) ($_POST['importe'] ?? ''), (string) ($_POST['retenedor'] ?? ''),
            $ingresoVinculo, (string) ($_POST['nota'] ?? ''), $usuario
        );
        $exito = 'Retención registrada: resta al impuesto del mes en que se captura.';
    } elseif ($accion === 'retencion_cancelar') {
        $ok = \App\RegistrosFiscales::cancelarRetencion((int) ($_POST['retencion'] ?? 0), (string) ($_POST['motivo'] ?? ''), $usuario);
        $exito = 'Retención cancelada con motivo; queda en el historial.';
    } elseif ($accion === 'ajuste') {
        $ok = \App\RegistrosFiscales::registrarAjuste(
            $contrib, $anio, (int) ($_POST['mes'] ?? 1), (string) ($_POST['concepto'] ?? ''),
            (string) ($_POST['importe'] ?? ''), (string) ($_POST['nota'] ?? ''), $usuario
        );
        $exito = 'Ajuste registrado: el motor lo aplica en el mes indicado.';
    } elseif ($accion === 'ajuste_cancelar') {
        $ok = \App\RegistrosFiscales::cancelarAjuste((int) ($_POST['ajuste'] ?? 0), (string) ($_POST['motivo'] ?? ''), $usuario);
        $exito = 'Ajuste cancelado con motivo; queda en el historial.';
    } elseif ($accion === 'declaracion') {
        $ok = \App\RegistrosFiscales::guardarDeclaracion(
            $contrib, $anio, (int) ($_POST['mes'] ?? 1), (string) ($_POST['impuesto'] ?? 'ISR'),
            (string) ($_POST['declarado'] ?? ''), (string) ($_POST['saldo_favor'] ?? ''), (string) ($_POST['pagado'] ?? ''),
            (string) ($_POST['fecha_presentacion'] ?? ''), (string) ($_POST['fecha_pago'] ?? ''),
            (string) ($_POST['referencia'] ?? ''), (string) ($_POST['nota'] ?? ''), $usuario
        );
        $exito = 'Declaración guardada (si ya existía, se actualiza). Sus pagos se usan como pagos provisionales previos.';
    }

    if ($ok) {
        redirigir($destino, 'exito', $exito);
    }
    redirigir($destino, 'error', implode(' · ', \App\RegistrosFiscales::getErrores()) ?: 'No fue posible guardar el registro.');
}

$retenciones    = \App\RegistrosFiscales::retenciones($contrib, $anio);
$ajustes        = \App\RegistrosFiscales::ajustes($contrib, $anio);
$declaraciones  = \App\RegistrosFiscales::declaraciones($contrib, $anio);
$ingresosAnio   = \App\Contabilidad::listarIngresos(['anio' => $anio], 400, 0);
$conceptosAjuste = \App\RegistrosFiscales::CONCEPTOS_AJUSTE;
$hoy = date('Y-m-d');

template('header', ['titulo' => 'Retenciones, ajustes y declaraciones', 'subtitulo' => 'Fiscal', 'rutaActiva' => '/admin/fiscal']);
template('tabsFiscal', ['tab' => 'registros', 'periodo' => ['anio' => $anio, 'mes' => 1]]);
?>

<p class="campo__ayuda mb-md">Aquí se capturan los datos que el motor fiscal lee para calcular. Nada se inventa:
    lo que registres aquí <strong>sí cambia los números</strong> del <a href="/admin/fiscal?anio=<?= $anio ?>">resumen de impuestos</a>,
    por eso todo queda con auditoría y lo cancelado se conserva con su motivo. Si una situación requiere criterio
    profesional, regístrala con su nota en lugar de forzar el cálculo.</p>

<form class="buscador mb-lg" action="/admin/fiscal/registros" method="get" aria-label="Elegir año">
    <label class="solo-lectores" for="sel-anio">Año</label>
    <select class="control control--compacto" id="sel-anio" name="anio">
        <?php foreach (\App\Contabilidad::aniosConMovimientos() as $a) : ?>
            <option value="<?= $a ?>"<?= $anio === $a ? ' selected' : '' ?>><?= $a ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn--secundario btn--sm" type="submit">Ver</button>
</form>

<!-- ============================ RETENCIONES ============================ -->
<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Retenciones (<?= $anio ?>)</h2>
    <p class="campo__ayuda mb-md">Retención de IVA: resta el IVA a cargo del mes en que la registres. ISR retenido:
        acredita contra el ISR de ese mismo mes.</p>

    <form class="formulario" action="/admin/fiscal/registros" method="post" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <input type="hidden" name="anio" value="<?= $anio ?>">
        <input type="hidden" name="accion" value="retencion">
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="ret-fecha">Fecha</label>
                <input class="control" type="date" id="ret-fecha" name="fecha" required value="<?= $hoy ?>"></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="ret-impuesto">Impuesto</label>
                <select class="control" id="ret-impuesto" name="impuesto"><option value="IVA">IVA</option><option value="ISR">ISR</option></select></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="ret-importe">Importe retenido</label>
                <input class="control control--moneda" type="number" id="ret-importe" name="importe" required min="0.01" step="0.01" inputmode="decimal"></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="ret-retenedor">¿Quién retuvo?</label>
                <input class="control" type="text" id="ret-retenedor" name="retenedor" required maxlength="150" placeholder="Ej. cliente, banco, platform"></div>
            <div class="campo"><label class="campo__etiqueta" for="ret-ingreso">Venta / factura relacionada (opcional)</label>
                <select class="control" id="ret-ingreso" name="ingreso_id">
                    <option value="">— Sin vínculo —</option>
                    <?php foreach ($ingresosAnio as $ing) : ?>
                        <option value="<?= (int) $ing['id'] ?>"><?= escaparValores($ing['folio'] . ' · ' . ($ing['cliente_nombre'] ?: $ing['cliente_empresa'])) ?> · <?= formatoMoneda((float) $ing['total']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo campo--completo"><label class="campo__etiqueta" for="ret-nota">Nota</label>
                <input class="control" type="text" id="ret-nota" name="nota" maxlength="255" placeholder="Contexto o documento que la respalda"></div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Registrar retención</button></div>
    </form>

    <?php if ($retenciones) : ?>
        <div class="tabla-contenedor mt-md"><table class="tabla">
            <caption class="solo-lectores">Retenciones del <?= $anio ?></caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col">Impuesto</th><th scope="col" class="tabla--numerica">Importe</th>
                <th scope="col">Retenedor</th><th scope="col">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($retenciones as $r) : $cancelada = (string) $r['estado'] === 'cancelado'; ?>
                <tr<?= $cancelada ? ' style="opacity:.6"' : '' ?>>
                    <td><?= date('d/m/Y', strtotime((string) $r['fecha'])) ?></td>
                    <td><span class="badge <?= $r['impuesto'] === 'IVA' ? 'badge--info' : 'badge--pendiente' ?>"><?= escaparValores($r['impuesto']) ?></span></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda((float) $r['importe']) ?></td>
                    <td class="texto-sm"><?= escaparValores((string) $r['retenedor']) ?>
                        <?php if (!empty($r['nota'])) : ?><span class="texto-xs texto-secundario"><br><?= escaparValores($r['nota']) ?></span><?php endif; ?></td>
                    <td><?= $cancelada
                        ? '<span class="badge badge--error">Cancelada</span> <span class="texto-xs">' . escaparValores((string) $r['motivo_cancelacion']) . '</span>'
                        : '<span class="badge badge--aprobado">Vigente</span>' ?></td>
                    <td class="tabla--numerica">
                        <?php if (!$cancelada) : ?>
                            <form action="/admin/fiscal/registros" method="post" data-validar
                                  data-confirmacion-sobre="Retención"
                                  data-confirmacion="¿Cancelar esta retención?"
                                  data-confirmacion-mensaje="El registro se conserva como cancelado y deja de restar del impuesto del mes."
                                  data-confirmacion-boton="Sí, cancelar">
                                <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                <input type="hidden" name="anio" value="<?= $anio ?>">
                                <input type="hidden" name="accion" value="retencion_cancelar">
                                <input type="hidden" name="retencion" value="<?= (int) $r['id'] ?>">
                                <label class="solo-lectores" for="ret-mot-<?= (int) $r['id'] ?>">Motivo</label>
                                <input class="control control--compacto" type="text" id="ret-mot-<?= (int) $r['id'] ?>"
                                       name="motivo" placeholder="Motivo" maxlength="200" required minlength="5">
                                <button class="btn btn--peligro btn--sm" type="submit">Cancelar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<!-- ============================== AJUSTES ============================== -->
<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Ajustes fiscales (<?= $anio ?>)</h2>
    <p class="campo__ayuda mb-md">Los ajustes de IVA restan <strong>solo ese mes</strong>; PTU, pérdidas por amortizar y
        otros acreditamientos de ISR <strong>bajan la utilidad desde ese mes en adelante</strong> (el motor los acumula).</p>

    <form class="formulario" action="/admin/fiscal/registros" method="post" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <input type="hidden" name="anio" value="<?= $anio ?>">
        <input type="hidden" name="accion" value="ajuste">
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="aj-mes">Mes</label>
                <select class="control" id="aj-mes" name="mes">
                    <?php for ($i = 1; $i <= 12; $i++) : ?><option value="<?= $i ?>"><?= escaparValores(nombreMes($i)) ?></option><?php endfor; ?>
                </select></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="aj-concepto">Concepto</label>
                <select class="control" id="aj-concepto" name="concepto">
                    <?php foreach ($conceptosAjuste as $clave => $texto) : ?>
                        <option value="<?= $clave ?>"><?= escaparValores($texto) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="aj-importe">Importe</label>
                <input class="control control--moneda" type="number" id="aj-importe" name="importe" required min="0.01" step="0.01" inputmode="decimal"></div>
            <div class="campo"><label class="campo__etiqueta" for="aj-nota">Nota (¿de dónde sale?)</label>
                <input class="control" type="text" id="aj-nota" name="nota" maxlength="255" placeholder="Ej. constancia de pago, acta de PTU"></div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Registrar ajuste</button></div>
    </form>

    <?php if ($ajustes) : ?>
        <div class="tabla-contenedor mt-md"><table class="tabla">
            <caption class="solo-lectores">Ajustes fiscales del <?= $anio ?></caption>
            <thead><tr><th scope="col">Mes</th><th scope="col">Concepto</th><th scope="col" class="tabla--numerica">Importe</th>
                <th scope="col">Nota</th><th scope="col">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($ajustes as $a) : $cancelado = (string) $a['estado'] === 'cancelado'; ?>
                <tr<?= $cancelado ? ' style="opacity:.6"' : '' ?>>
                    <td><?= escaparValores(nombreMes((int) $a['mes'])) ?></td>
                    <td class="texto-sm"><?= escaparValores($conceptosAjuste[$a['concepto']] ?? $a['concepto']) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda((float) $a['importe']) ?></td>
                    <td class="texto-sm"><?= escaparValores((string) ($a['nota'] ?? '—')) ?></td>
                    <td><?= $cancelado
                        ? '<span class="badge badge--error">Cancelado</span> <span class="texto-xs">' . escaparValores((string) $a['motivo_cancelacion']) . '</span>'
                        : '<span class="badge badge--aprobado">Vigente</span>' ?></td>
                    <td class="tabla--numerica">
                        <?php if (!$cancelado) : ?>
                            <form action="/admin/fiscal/registros" method="post" data-validar
                                  data-confirmacion-sobre="Ajuste fiscal"
                                  data-confirmacion="¿Cancelar este ajuste?"
                                  data-confirmacion-mensaje="El registro se conserva como cancelado y deja de aplicarse al cálculo."
                                  data-confirmacion-boton="Sí, cancelar">
                                <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                <input type="hidden" name="anio" value="<?= $anio ?>">
                                <input type="hidden" name="accion" value="ajuste_cancelar">
                                <input type="hidden" name="ajuste" value="<?= (int) $a['id'] ?>">
                                <label class="solo-lectores" for="aj-mot-<?= (int) $a['id'] ?>">Motivo</label>
                                <input class="control control--compacto" type="text" id="aj-mot-<?= (int) $a['id'] ?>"
                                       name="motivo" placeholder="Motivo" maxlength="200" required minlength="5">
                                <button class="btn btn--peligro btn--sm" type="submit">Cancelar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<!-- ========================== DECLARACIONES ============================ -->
<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Declaraciones y pagos al SAT (<?= $anio ?>)</h2>
    <p class="campo__ayuda mb-md">Lo que declaraste y pagaste. Los pagos de <strong>ISR</strong> de meses anteriores se
        descuentan como pagos provisionales previos del mes en curso; las de IVA se muestran para comparar
        <em>declarado vs. estimado</em>. Si guardas dos veces el mismo mes e impuesto, se <strong>actualiza</strong>.</p>

    <form class="formulario" action="/admin/fiscal/registros" method="post" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <input type="hidden" name="anio" value="<?= $anio ?>">
        <input type="hidden" name="accion" value="declaracion">
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="dec-mes">Mes</label>
                <select class="control" id="dec-mes" name="mes">
                    <?php for ($i = 1; $i <= 12; $i++) : ?><option value="<?= $i ?>"><?= escaparValores(nombreMes($i)) ?></option><?php endfor; ?>
                </select></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="dec-impuesto">Impuesto</label>
                <select class="control" id="dec-impuesto" name="impuesto"><option value="ISR">ISR</option><option value="IVA">IVA</option></select></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="dec-declarado">Importe declarado</label>
                <input class="control control--moneda" type="number" id="dec-declarado" name="declarado" required min="0" step="0.01" inputmode="decimal"></div>
            <div class="campo"><label class="campo__etiqueta" for="dec-saldo">Saldo a favor (IVA)</label>
                <input class="control control--moneda" type="number" id="dec-saldo" name="saldo_favor" min="0" step="0.01" inputmode="decimal" value="0"></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="dec-pagado">Importe pagado</label>
                <input class="control control--moneda" type="number" id="dec-pagado" name="pagado" required min="0" step="0.01" inputmode="decimal"></div>
            <div class="campo"><label class="campo__etiqueta" for="dec-fpres">Fecha de presentación</label>
                <input class="control" type="date" id="dec-fpres" name="fecha_presentacion"></div>
            <div class="campo"><label class="campo__etiqueta" for="dec-fpago">Fecha de pago</label>
                <input class="control" type="date" id="dec-fpago" name="fecha_pago"></div>
            <div class="campo"><label class="campo__etiqueta" for="dec-ref">Referencia (sello de pago)</label>
                <input class="control" type="text" id="dec-ref" name="referencia" maxlength="80"></div>
            <div class="campo campo--completo"><label class="campo__etiqueta" for="dec-nota">Nota</label>
                <input class="control" type="text" id="dec-nota" name="nota" maxlength="255"></div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar declaración</button></div>
    </form>

    <?php if ($declaraciones) : ?>
        <div class="tabla-contenedor mt-md"><table class="tabla">
            <caption class="solo-lectores">Declaraciones del <?= $anio ?></caption>
            <thead><tr><th scope="col">Periodo</th><th scope="col">Impuesto</th><th scope="col" class="tabla--numerica">Declarado</th>
                <th scope="col" class="tabla--numerica">Saldo a favor</th><th scope="col" class="tabla--numerica">Pagado</th>
                <th scope="col">Presentación / pago</th><th scope="col">Referencia</th></tr></thead>
            <tbody>
            <?php foreach ($declaraciones as $d) : ?>
                <tr>
                    <td><?= escaparValores(nombreMes((int) $d['mes'])) ?> <?= (int) $d['anio'] ?></td>
                    <td><span class="badge <?= $d['impuesto'] === 'IVA' ? 'badge--info' : 'badge--pendiente' ?>"><?= escaparValores($d['impuesto']) ?></span></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda((float) $d['importe_declarado']) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda((float) $d['saldo_favor']) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda((float) $d['importe_pagado']) ?></td>
                    <td class="texto-sm"><?= $d['fecha_presentacion'] ? date('d/m/Y', strtotime((string) $d['fecha_presentacion'])) : '—' ?>
                        / <?= $d['fecha_pago'] ? date('d/m/Y', strtotime((string) $d['fecha_pago'])) : '—' ?></td>
                    <td class="texto-sm"><?= escaparValores((string) ($d['referencia'] ?: '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
