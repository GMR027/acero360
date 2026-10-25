<?php
/**
 * views/admin/fiscal/resumen.php — Impuestos: cuánto se debe pagar cada mes (estimación).
 *
 * Un solo motor: todos los números salen de MotorFiscal (catálogos fiscales versionados,
 * base de flujo de efectivo) adaptados por ResumenFiscal::anual(). Aquí NO se calcula nada:
 * la pantalla sólo los presenta con su desglose (IVA, deducción ISR, retenciones y otros
 * conceptos) y las razones de lo que queda fuera (exclusiones, reglas y avisos).
 * Sólo entran lo cobrado (facturas al 100 %, IVA prorrateado por cobro) y los gastos
 * pagados que cumplan sus reglas; el mes es el del cobro o del pago efectivo.
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
$aPagar = $actual['impuestos'];
$siguiente = $mes === 12 ? [$anio + 1, 1] : [$anio, $mes + 1];
$consultaExp = http_build_query(['anio' => $anio, 'mes' => $mes]);
$anioTexto = (string) $anio;
$versionIsr = (int) ($r['versiones']['isr'] ?? 0);
$versionIva = (int) ($r['versiones']['iva'] ?? 0);
$versionReglas = (int) ($r['versiones']['reglas'] ?? 0);

/** Términos del mes para explicar el IVA (objetivo: ver por qué entra o sale cada peso). */
$desgloseIva = array_values(array_filter([
    $actual['iva_retenciones'] > 0 ? '− retenciones ' . $m($actual['iva_retenciones']) : '',
    $actual['iva_saldo_favor'] > 0 ? '− saldo a favor anterior ' . $m($actual['iva_saldo_favor']) : '',
    $actual['iva_otros_ajustes'] > 0 ? '− otros ajustes ' . $m($actual['iva_otros_ajustes']) : '',
]));
$desgloseIsr = array_values(array_filter([
    $actual['isr_pagos_previos'] > 0 ? '− pagos provisionales anteriores ' . $m($actual['isr_pagos_previos']) : '',
    $actual['isr_retenciones'] > 0 ? '− ISR retenido ' . $m($actual['isr_retenciones']) : '',
    $actual['isr_otros_acreditamientos'] > 0 ? '− otros acreditamientos ' . $m($actual['isr_otros_acreditamientos']) : '',
]));

// Renglones de la tabla mes por mes (lo esencial); el resto va en «Cómo se calcula».
$filas = [
    ['facturado', 'Cobrado en el mes', false],
    ['pagos', 'Gastos que entran', false],
    ['iva_por_pagar', 'IVA por pagar', false],
    ['isr_por_pagar', 'ISR provisional (art. 106)', false],
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

<?php if (!$r['habilitado']) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><strong>Cálculo deshabilitado para <?= $anioTexto ?></strong>: los importes de impuestos NO se calculan
            (no se inventa ninguna cifra). Motivos:
            <?php foreach ($r['avisos'] as $i => $aviso) : ?><?= $i ? ' · ' : '' ?><?= escaparValores($aviso) ?><?php endforeach; ?>
            <?php if (!$r['avisos']) : ?>sin configuración fiscal ni catálogos activos para este ejercicio<?php endif; ?>
            · <a href="/admin/fiscal/configuracion">Revisar datos fiscales y catálogos</a>.</span>
    </div>
<?php elseif ($r['avisos']) : ?>
    <div class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
        <span><strong>El cálculo avisa:</strong>
            <?php foreach ($r['avisos'] as $i => $aviso) : ?><?= $i ? ' · ' : '' ?><?= escaparValores($aviso) ?><?php endforeach; ?></span>
    </div>
<?php endif; ?>

<section class="impuestos-movil solo-movil" aria-label="Resumen de <?= escaparValores(nombreMes($mes)) ?> <?= $anio ?>">
    <div class="impuestos-movil__fila"><span>Cobrado en <?= escaparValores(mb_strtolower(nombreMes($mes))) ?></span><strong class="impuestos-movil__valor"><?= $m($actual['facturado']) ?></strong></div>
    <div class="impuestos-movil__fila"><span>Pagado (que entra) en <?= escaparValores(mb_strtolower(nombreMes($mes))) ?></span><strong class="impuestos-movil__valor"><?= $m($actual['pagos']) ?></strong></div>
    <div class="impuestos-movil__fila impuestos-movil__fila--destacada"><span><?= $aPagar >= 0 ? 'Impuestos por pagar' : 'Saldo a tu favor' ?></span><strong class="impuestos-movil__valor"><?= $m(abs($aPagar)) ?></strong></div>

    
</section>

<?php /* FASE 9: mini-calendario de captura: qué meses del año ya tienen ventas y
             gastos registrados (datos crudos, no cálculo). A simple vista se ve qué falta. */
    $ventasMes = array_fill(1, 12, 0);
    $gastosMes = array_fill(1, 12, 0);
    foreach ($db->query("SELECT MONTH(fecha) m, COUNT(*) n FROM ingresos WHERE estado = 'vigente' AND contribuyente_id = $contrib AND YEAR(fecha) = $anio GROUP BY MONTH(fecha)") as $f) { $ventasMes[(int) $f['m']] = (int) $f['n']; }
    foreach ($db->query("SELECT MONTH(fecha) m, COUNT(*) n FROM gastos WHERE estado = 'vigente' AND contribuyente_id = $contrib AND YEAR(fecha) = $anio GROUP BY MONTH(fecha)") as $f) { $gastosMes[(int) $f['m']] = (int) $f['n']; } ?>
    <div class="nota mt-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">calendar_month</span>
        <span><strong>Meses con datos capturados <?= $anio ?>:</strong>
            <?php for ($i = 1; $i <= 12; $i++) :
                $clase = $ventasMes[$i] > 0 && $gastosMes[$i] > 0 ? 'badge--aprobado' : ($ventasMes[$i] > 0 || $gastosMes[$i] > 0 ? 'badge--pendiente' : 'badge--neutro'); ?>
                <span class="badge <?= $clase ?><?= $i === $mes ? '" style="outline:2px solid var(--color-primario)' : '' ?>"
                      title="<?= $ventasMes[$i] ?> venta(s), <?= $gastosMes[$i] ?> gasto(s)"><?= mb_substr(nombreMes($i), 0, 3) ?></span><?php endfor; ?>
            · verde = ventas y gastos; amarillo = sólo uno; gris = sin capturar (la carga por lotes
            llena los meses anteriores de una vez).</span>
    </div>

<section class="tarjeta mb-lg movil-oculto" aria-label="Impuestos del mes">
    <p class="texto-secundario"><?= $aPagar >= 0 ? 'Impuestos por pagar de' : 'Saldo a tu favor en' ?> <?= escaparValores(nombreMes($mes)) ?> <?= $anio ?></p>
    <p class="titulo-2" style="margin:.25rem 0"><?= $m(abs($aPagar)) ?></p>
    <ul class="lista-simple mt-md">
        <li class="lista-simple__elemento"><strong>IVA:</strong> <?= $m($actual['iva_por_pagar']) ?>
            <span class="texto-secundario">— IVA cobrado <?= $m($actual['iva_facturado']) ?> − IVA acreditable <?= $m($actual['iva_acreditado']) ?><?= $desgloseIva ? ' ' . implode(' ', $desgloseIva) : '' ?>.</span></li>
        <li class="lista-simple__elemento"><strong>ISR provisional:</strong> <?= $m($actual['isr_por_pagar']) ?>
            <span class="texto-secundario">— tarifa progresiva de la <strong>tarifa oficial art. 106</strong> (catálogo v<?= $versionIsr ?>)
                sobre la utilidad acumulada del año<?= $desgloseIsr ? '; ' . implode(' ', $desgloseIsr) : '' ?>.</span></li>
        <?php if ($actual['iva_retenciones'] > 0 || $actual['isr_retenciones'] > 0 || $actual['iva_otros_ajustes'] > 0
            || $actual['iva_saldo_favor'] > 0 || $actual['isr_otros_acreditamientos'] > 0) : ?>
            <li class="lista-simple__elemento"><strong>Retenciones y otros conceptos del mes:</strong>
                <?php $conceptos = [];
                if ($actual['iva_retenciones'] > 0) { $conceptos[] = 'retención de IVA ' . $m($actual['iva_retenciones']); }
                if ($actual['isr_retenciones'] > 0) { $conceptos[] = 'ISR retenido ' . $m($actual['isr_retenciones']); }
                if ($actual['iva_saldo_favor'] > 0) { $conceptos[] = 'saldo a favor de IVA ' . $m($actual['iva_saldo_favor']); }
                if ($actual['iva_otros_ajustes'] > 0) { $conceptos[] = 'ajustes de IVA ' . $m($actual['iva_otros_ajustes']); }
                if ($actual['isr_otros_acreditamientos'] > 0) { $conceptos[] = 'acreditamientos de ISR ' . $m($actual['isr_otros_acreditamientos']); }
                echo escaparValores(implode(', ', $conceptos)); ?>.
                <a href="/admin/fiscal/registros?anio=<?= $anio ?>&amp;mes=<?= $mes ?>">Capturar o revisar</a></li>
        <?php endif; ?>
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
        <span><?= (int) $r['sin_clasificar'] ?> venta(s) de <?= $anio ?> no indican si se facturaron. En flujo de efectivo lo que cuenta es
            <strong>el cobro</strong>: revisa que cada cobro tenga su CFDI.
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
            Se cuentan en el mes en que se paguen/cobren (flujo de efectivo).</span></p>
<?php endif; ?>

<details class="mb-lg">
    <summary class="boton-enlace" style="cursor:pointer"><strong>¿Por qué entra o sale cada importe?</strong> (razones, exclusiones y reglas)</summary>
    <div class="mt-md">
        <p class="campo__ayuda mb-md">Todo lo que sigue lo calcula <strong>MotorFiscal</strong> con los
            <strong>catálogos versionados</strong> (ninguna tasa se inventa):
            IVA <strong>v<?= $versionIva ?></strong> · criterios de la plataforma <strong>v<?= $versionReglas ?></strong> ·
            tarifa ISR art. 106 <strong>v<?= $versionIsr ?></strong>; régimen
            «<?= escaparValores($r['configuracion']['regimen'] ?? '—') ?>»,
            <?= escaparValores($r['reglas']['tipo_persona'] ?? '—') === 'moral' ? 'persona moral' : 'persona física' ?>,
            base <strong>flujo de efectivo</strong>.</p>

        <ul class="lista-simple mb-md">
            <li><strong>Fuera del cálculo este año:</strong>
                <?php $fx = $r['facturas_excluidas']; ?>
                <?= (int) $fx['n'] ?> factura(s) cobradas al 100 % (les falta <strong><?= $m($fx['pendiente']) ?></strong> por cobrar)
                y <?= (int) ($r['meses'][$mes]['gastos_no_pagados_n'] ?? 0) ?> gasto(s) por pagar en <?= escaparValores(mb_strtolower(nombreMes($mes))) ?>.</li>
            <?php $gx = $r['gastos_excluidos']; $pieGastos = [];
            if ((int) $gx['sin_comprobante'] > 0) { $pieGastos[] = '<strong>' . (int) $gx['sin_comprobante'] . '</strong> pagado(s) <strong>sin comprobante fiscal</strong>'; }
            if ((int) $gx['no_validados'] > 0) { $pieGastos[] = '<strong>' . (int) $gx['no_validados'] . '</strong> pagado(s) <strong>sin validación fiscal</strong>'; }
            if ((int) $gx['no_deducibles'] > 0) { $pieGastos[] = '<strong>' . (int) $gx['no_deducibles'] . '</strong> marcado(s) como <strong>no deducible(s)</strong>'; }
            if ((int) $gx['naturaleza'] > 0) { $pieGastos[] = '<strong>' . (int) $gx['naturaleza'] . '</strong> por <strong>naturaleza excluida</strong>'; } ?>
            <?php if ($pieGastos) : ?>
                <li><strong>Gastos pagados que NO se descuentan:</strong> <?= implode(', ', $pieGastos) ?>.</li>
            <?php else : ?>
                <li><strong>Gastos pagados que NO se descuentan:</strong> ninguno este año.</li>
            <?php endif; ?>
            <li><strong>Reglas aplicadas (catálogo v<?= $versionReglas ?>):</strong>
                comprobante fiscal <?= !empty($r['reglas']['gasto_requiere_comprobante']) ? '<strong>obligatorio</strong>' : 'no obligatorio' ?>;
                validación fiscal <?= !empty($r['reglas']['gasto_requiere_validacion']) ? '<strong>obligatoria</strong>' : 'no obligatoria' ?>;
                naturalezas sin deducción: <?= escaparValores(implode(', ', $r['reglas']['naturalezas_excluidas_deduccion'] ?? []) ?: 'ninguna') ?>;
                sin IVA acreditable: <?= escaparValores(implode(', ', $r['reglas']['naturalezas_excluidas_iva_acreditable'] ?? []) ?: 'ninguna') ?>.</li>
        </ul>

        <?php if ($r['avisos']) : ?>
            <h4 class="titulo-6 mb-sm">Avisos del motor</h4>
            <ul class="lista-simple mb-md">
                <?php foreach ($r['avisos'] as $aviso) : ?><li><?= escaparValores($aviso) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="campo__ayuda"><?= escaparValores((string) ($r['aviso_legal'] ?? '')) ?></p>
        <p class="campo__ayuda"><a href="/admin/fiscal/registros?anio=<?= $anio ?>">Retenciones, ajustes y declaraciones</a> ·
            <a href="/admin/fiscal/configuracion">Catálogos y datos fiscales</a></p>
    </div>
</details>

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
        <p class="campo__ayuda mb-md">Un solo motor (<strong>MotorFiscal</strong>, con los catálogos oficiales versionados arriba):
            base de <strong>flujo de efectivo</strong>. Entra <strong>lo cobrado</strong> (facturas al 100 %; el IVA se reparte
            entre los cobros, y el mes es el del cobro, no el de la factura) y <strong>los gastos pagados</strong> que cumplan las
            reglas del catálogo (comprobante fiscal obligatorio y naturalezas excluidas). El método de pago (PUE/PPD) es
            informativo: no cambia la cuenta.
            <br>· <strong>IVA por pagar</strong> = IVA cobrado − IVA acreditable − retenciones de IVA − saldo a favor anterior − otros ajustes de IVA.
            <br>· <strong>ISR provisional (art. 106)</strong> = tarifa progresiva del catálogo sobre la utilidad acumulada
            (cobros − deducciones − PTU − pérdidas), contra pagos provisionales anteriores, ISR retenido y otros acreditamientos.
            <br>· IVA e ISR no comparten base ni cifras. Lo que queda fuera se lista en «¿Por qué entra o sale cada importe?».
            <a href="/admin/fiscal/registros">Capturar retenciones, ajustes y declaraciones</a>.</p>
        <section class="tarjeta tarjeta--sin-relleno">
            <div class="tabla-contenedor">
                <table class="tabla tabla--numerica">
                    <caption class="solo-lectores">Pasos del cálculo, <?= $anio ?></caption>
                    <thead><tr><th scope="col">Concepto</th>
                        <?php for ($i = 1; $i <= 12; $i++) : ?><th scope="col"><?= escaparValores(mb_substr(nombreMes($i), 0, 3)) ?></th><?php endfor; ?>
                        <th scope="col">Año</th></tr></thead>
                    <tbody>
                        <?php foreach ([
                            ['facturado_sin_iva', 'Base cobrada (sin IVA)'],
                            ['pagos_sin_iva', 'Deducción de gastos (sin IVA)'],
                            ['iva_facturado', 'IVA cobrado (trasladado)'],
                            ['iva_acreditado', 'IVA acreditable'],
                            ['iva_retenciones', 'Retenciones de IVA'],
                            ['iva_saldo_favor', 'Saldo a favor de IVA'],
                            ['iva_otros_ajustes', 'Otros ajustes de IVA'],
                            ['isr_retenciones', 'ISR retenido'],
                            ['isr_pagos_previos', 'Pagos provisionales previos'],
                            ['isr_otros_acreditamientos', 'Otros acreditamientos de ISR'],
                        ] as [$clave, $titulo]) : ?>
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
