<?php
/**
 * views/admin/fiscal/resumen.php — Impuestos: cuánto se debe pagar cada mes (estimación simple).
 * Facturado − pagos → IVA y ISR por pagar.
 * Sólo entran facturas pagadas al 100 % y gastos ya pagados; lo excluido se muestra aparte.
 */

soloAdmin();

$contrib = \App\Contabilidad::contribuyentePrincipal();
$anio    = (int) ($_GET['anio'] ?? $_POST['anio'] ?? date('Y'));
$anio    = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) date('Y');
$mesPorDefecto = $anio === (int) date('Y') ? (int) date('n') : 12;
$mes     = (int) ($_GET['mes'] ?? $_POST['mes'] ?? $mesPorDefecto);
$mes     = ($mes >= 1 && $mes <= 12) ? $mes : $mesPorDefecto;
$destino = "/admin/fiscal?anio=$anio&mes=$mes";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($destino, 'error', 'Este formulario ya se envió; no se guardó dos veces.');
    }
    $usuario = (int) ($_SESSION['id'] ?? 0);
    $actuales = \App\ResumenFiscal::reales($contrib, $anio);
    for ($i = 1; $i <= 12; $i++) {
        $texto = trim(str_replace([',', '$', ' '], '', (string) ($_POST['real'][$i] ?? '')));
        if ($texto === '') {
            $nuevo = null;
        } elseif (is_numeric($texto)) {
            $nuevo = round((float) $texto, 2);
        } else {
            redirigir($destino, 'error', 'Escribe solo números en «Lo que declaró tu contador» (' . nombreMes($i) . ').');
        }
        if ($nuevo !== ($actuales[$i] ?? null)) {
            \App\ResumenFiscal::guardarReal($contrib, $anio, $i, $nuevo, $usuario);
        }
    }
    redirigir($destino, 'exito', 'Listo, se guardó lo que declaró tu contador.');
}

$r = \App\ResumenFiscal::anual($contrib, $anio);
$t = $r['total'];
$actual = $r['meses'][$mes];
$m = static fn (?float $v): string => $v === null ? '—' : ($v < 0 ? '−' : '') . formatoMoneda(abs($v));
$tasaTexto = rtrim(rtrim(number_format($r['tasa'], 2), '0'), '.');
$aPagar = $actual['impuestos'];
$siguiente = $mes === 12 ? [$anio + 1, 1] : [$anio, $mes + 1];
$consultaExp = http_build_query(['anio' => $anio, 'mes' => $mes]);

// Renglones de la tabla mes por mes (lo esencial); el resto va en «Cómo se calcula».
$filas = [
    ['facturado', 'Facturado', false],
    ['pagos', 'Pagos y gastos', false],
    ['iva_por_pagar', 'IVA por pagar', false],
    ['isr_por_pagar', 'ISR por pagar (' . $tasaTexto . ' %)', false],
    ['impuestos', 'Impuestos por pagar', true],
];

template('header', ['titulo' => 'Impuestos', 'subtitulo' => 'Cuánto se paga cada mes · ' . $anio, 'rutaActiva' => '/admin/fiscal']);
template('tabsFiscal', ['tab' => 'resumen', 'periodo' => ['anio' => $anio, 'mes' => $mes]]);
?>

<form class="buscador mb-lg" action="/admin/fiscal" method="get" aria-label="Elegir mes">
    <label class="solo-lectores" for="sel-anio">Año</label>
    <select class="control control--compacto" id="sel-anio" name="anio">
        <?php foreach (\App\Contabilidad::aniosConMovimientos() as $a) : ?>
            <option value="<?= $a ?>"<?= $anio === $a ? ' selected' : '' ?>><?= $a ?></option>
        <?php endforeach; ?>
    </select>
    <label class="solo-lectores" for="sel-mes">Mes</label>
    <select class="control control--compacto" id="sel-mes" name="mes">
        <?php for ($i = 1; $i <= 12; $i++) : ?>
            <option value="<?= $i ?>"<?= $mes === $i ? ' selected' : '' ?>><?= nombreMes($i) ?></option>
        <?php endfor; ?>
    </select>
    <button class="btn btn--secundario btn--sm" type="submit">Ver</button>
</form>

<section class="impuestos-movil solo-movil" aria-label="Resumen de <?= escaparValores(nombreMes($mes)) ?> <?= $anio ?>">
    <div class="impuestos-movil__fila"><span>Facturado en <?= escaparValores(mb_strtolower(nombreMes($mes))) ?></span><strong class="impuestos-movil__valor"><?= $m($actual['facturado']) ?></strong></div>
    <div class="impuestos-movil__fila"><span>Pagado en <?= escaparValores(mb_strtolower(nombreMes($mes))) ?></span><strong class="impuestos-movil__valor"><?= $m($actual['pagos']) ?></strong></div>
    <div class="impuestos-movil__fila impuestos-movil__fila--destacada"><span><?= $aPagar >= 0 ? 'Impuestos por pagar' : 'Saldo a tu favor' ?></span><strong class="impuestos-movil__valor"><?= $m(abs($aPagar)) ?></strong></div>
</section>

<section class="tarjeta mb-lg movil-oculto" aria-label="Impuestos del mes">
    <p class="texto-secundario"><?= $aPagar >= 0 ? 'Impuestos por pagar de' : 'Saldo a tu favor en' ?> <?= escaparValores(nombreMes($mes)) ?> <?= $anio ?></p>
    <p class="titulo-2" style="margin:.25rem 0"><?= $m(abs($aPagar)) ?></p>
    <ul class="lista-simple mt-md">
        <li class="lista-simple__elemento"><strong>IVA:</strong> <?= $m($actual['iva_por_pagar']) ?>
            <span class="texto-secundario">— el IVA de las facturas que ya te pagaron menos el IVA de los gastos que ya pagaste.</span></li>
        <li class="lista-simple__elemento"><strong>ISR:</strong> <?= $m($actual['isr_por_pagar']) ?>
            <span class="texto-secundario">— <?= escaparValores($tasaTexto) ?> % de lo facturado y cobrado menos lo pagado, sin IVA.</span></li>
    </ul>
    <p class="campo__ayuda mt-md">Se paga a más tardar el día 17 de <?= escaparValores(mb_strtolower(nombreMes($siguiente[1]))) ?> <?= (int) $siguiente[0] ?>. Es una estimación: confírmala con tu contador.</p>
    <div class="formulario__acciones" style="justify-content:flex-start">
        <a class="btn btn--primario" href="/admin/fiscal/exportar?<?= $consultaExp ?>&amp;tipo=impuestos&amp;formato=pdf"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span><span>Reporte del mes en PDF</span></a>
        <a class="btn btn--secundario" href="/admin/fiscal/exportar?anio=<?= $anio ?>&amp;mes=0&amp;tipo=impuestos&amp;formato=pdf">Reporte del año en PDF</a>
        <a class="btn btn--secundario" href="/admin/fiscal/movimientos?<?= $consultaExp ?>">Ver ventas y gastos del mes</a>
    </div>
</section>

<?php if ($r['sin_clasificar'] > 0) : ?>
    <p class="nota nota--advertencia mb-lg movil-oculto" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">lightbulb</span>
        <span><?= (int) $r['sin_clasificar'] ?> venta(s) de <?= $anio ?> no indican si se facturaron y <strong>no se cuentan</strong>. Solo cuentan las ventas marcadas como facturadas.
            <a href="/admin/contabilidad/sin-facturar?anio=<?= $anio ?>">Revisarlas</a>.</span></p>
<?php endif; ?>

<?php
/* Lo que la estimación deja fuera este mes: facturas sin pagar o en parcialidades y gastos por pagar. */
$facturasFuera = (int) ($actual['facturas_sin_pagar_n'] ?? 0);
$gastosFuera   = (int) ($actual['gastos_no_pagados_n'] ?? 0);
$fuera = [];
if ($facturasFuera > 0) {
    $fuera[] = '<strong>' . number_format($facturasFuera) . ' factura(s) sin pagar o en parcialidades</strong> por ' . $m($actual['facturas_sin_pagar']);
}
if ($gastosFuera > 0) {
    $fuera[] = '<strong>' . number_format($gastosFuera) . ' gasto(s) por pagar</strong> por ' . $m($actual['gastos_no_pagados']);
}
?>
<?php if ($fuera) : ?>
    <p class="nota mb-lg" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">schedule</span>
        <span>No entran en esta estimación de <?= escaparValores(mb_strtolower(nombreMes($mes))) ?>: <?= implode(' y ', $fuera) ?>.
            Se cuentan cuando se confirme que ya se pagaron.</span></p>
<?php endif; ?>

<section class="tarjeta tarjeta--sin-relleno mb-lg movil-oculto">
    <div class="tabla-contenedor">
        <table class="tabla tabla--numerica">
            <caption class="solo-lectores">Impuestos por pagar mes por mes, <?= $anio ?></caption>
            <thead>
                <tr>
                    <th scope="col">Concepto</th>
                    <?php for ($i = 1; $i <= 12; $i++) : ?>
                        <th scope="col"<?= $i === $mes ? ' aria-current="true" style="background:var(--color-primario-claro)"' : '' ?>><a href="/admin/fiscal?anio=<?= $anio ?>&amp;mes=<?= $i ?>"><?= escaparValores(mb_substr(nombreMes($i), 0, 3)) ?></a></th>
                    <?php endfor; ?>
                    <th scope="col">Año</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as [$clave, $titulo, $fuerte]) : ?>
                    <tr>
                        <th scope="row"><?= $fuerte ? '<strong>' . escaparValores($titulo) . '</strong>' : escaparValores($titulo) ?></th>
                        <?php for ($i = 1; $i <= 12; $i++) : $v = $r['meses'][$i][$clave]; ?>
                            <td class="cifra<?= $fuerte && $v < 0 ? ' variacion--sube' : '' ?>"<?= $i === $mes ? ' style="background:var(--color-primario-claro)"' : '' ?>><?= $fuerte ? '<strong>' . $m($v) . '</strong>' : $m($v) ?></td>
                        <?php endfor; ?>
                        <td class="cifra"><strong><?= $m($t[$clave]) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<p class="campo__ayuda mb-lg movil-oculto">Un número en verde significa que ese mes quedó <strong>a tu favor</strong>. En el año, los meses a favor se descuentan de los demás
    (<?= $m($t['impuestos']) ?>); sumando solo los meses con pago serían <?= $m($t['a_pagar_meses']) ?>.</p>

<details class="mb-lg movil-oculto">
    <summary class="boton-enlace" style="cursor:pointer"><strong>¿Cómo se calcula?</strong></summary>
    <div class="mt-md">
        <p class="campo__ayuda mb-md">Cuenta <strong>sólo</strong> las ventas marcadas como <strong>facturadas y pagadas al 100 %</strong>, y <strong>sólo</strong> los gastos que <strong>ya pagaste</strong>.
            Las facturas con pagos en parcialidades o sin cobrar y los gastos por pagar quedan fuera hasta que se confirme su pago (arriba se indica cuántos y por cuánto).
            El IVA es el <strong>real de cada CFDI</strong> (no se inventa el 16 %): la base es el total menos su IVA. IVA por pagar = IVA facturado − IVA de los gastos. ISR por pagar = (facturado sin IVA − pagos sin IVA) × <?= escaparValores($tasaTexto) ?> %.
            El método de pago (PUE una sola exhibición, PPD parcialidades o por definir) es informativo: no cambia la cuenta, sólo explica por qué una operación entra o no.
            <a href="/admin/fiscal/configuracion">Cambiar el porcentaje de ISR</a>.</p>
        <section class="tarjeta tarjeta--sin-relleno">
            <div class="tabla-contenedor">
                <table class="tabla tabla--numerica">
                    <caption class="solo-lectores">Pasos del cálculo, <?= $anio ?></caption>
                    <thead><tr><th scope="col">Concepto</th>
                        <?php for ($i = 1; $i <= 12; $i++) : ?><th scope="col"><?= escaparValores(mb_substr(nombreMes($i), 0, 3)) ?></th><?php endfor; ?>
                        <th scope="col">Año</th></tr></thead>
                    <tbody>
                        <?php foreach ([['facturado_sin_iva', 'Facturado sin IVA'], ['pagos_sin_iva', 'Pagos sin IVA'], ['iva_facturado', 'IVA facturado'], ['iva_acreditado', 'IVA de los gastos']] as [$clave, $titulo]) : ?>
                            <tr><th scope="row"><?= escaparValores($titulo) ?></th>
                                <?php for ($i = 1; $i <= 12; $i++) : ?><td class="cifra"><?= $m($r['meses'][$i][$clave]) ?></td><?php endfor; ?>
                                <td class="cifra"><strong><?= $m($t[$clave]) ?></strong></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</details>

<details class="mb-lg movil-oculto"<?= $r['hay_reales'] ? ' open' : '' ?>>
    <summary class="boton-enlace" style="cursor:pointer"><strong>Comparar con lo que declaró mi contador</strong> (opcional)</summary>
    <form class="mt-md" action="/admin/fiscal" method="post" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <input type="hidden" name="anio" value="<?= $anio ?>">
        <input type="hidden" name="mes" value="<?= $mes ?>">
        <section class="tarjeta tarjeta--sin-relleno mb-md">
            <div class="tabla-contenedor">
                <table class="tabla tabla--numerica">
                    <caption class="solo-lectores">Impuestos declarados por el contador, <?= $anio ?></caption>
                    <thead><tr><th scope="col">Concepto</th>
                        <?php for ($i = 1; $i <= 12; $i++) : ?><th scope="col"><?= escaparValores(mb_substr(nombreMes($i), 0, 3)) ?></th><?php endfor; ?>
                        <th scope="col">Año</th></tr></thead>
                    <tbody>
                        <tr><th scope="row">Declarado (negativo = a favor)</th>
                            <?php for ($i = 1; $i <= 12; $i++) : $real = $r['meses'][$i]['real']; ?>
                                <td><label class="solo-lectores" for="real-<?= $i ?>">Declarado en <?= escaparValores(nombreMes($i)) ?></label>
                                    <input class="control control--compacto control--moneda" type="text" inputmode="decimal" id="real-<?= $i ?>" name="real[<?= $i ?>]"
                                           value="<?= $real === null ? '' : escaparValores(number_format($real, 2, '.', '')) ?>" placeholder="—" style="min-width:6rem"></td>
                            <?php endfor; ?>
                            <td class="cifra"><strong><?= $r['hay_reales'] ? $m($t['real']) : '—' ?></strong></td></tr>
                        <?php if ($r['hay_reales']) : ?>
                            <tr><th scope="row">Diferencia (estimado − contador)</th>
                                <?php for ($i = 1; $i <= 12; $i++) : $real = $r['meses'][$i]['real']; ?>
                                    <td class="cifra"><?= $real === null ? '—' : $m($r['meses'][$i]['impuestos'] - $real) ?></td>
                                <?php endfor; ?>
                                <td class="cifra"><?= $m($t['impuestos'] - $t['real']) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar</button></div>
    </form>
</details>

<?php template('footer'); ?>
