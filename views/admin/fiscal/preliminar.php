<?php
/**
 * views/admin/fiscal/preliminar.php — Simulador básico de impuestos por pagar.
 *
 * El cálculo del documento «FISCAL 2026», pero con las facturas reales de la
 * base: ventas facturadas (ingresos con estado_facturacion = 'facturada') y
 * gastos con comprobante fiscal. Aquí NO se calcula nada: PreliminarFiscal
 * trae los importes por mes y la pantalla sólo los presenta con su desglose
 * (bases sin IVA, IVA facturado/acreditable, ISR plano del 32 %) y debajo
 * QUIÉN generó cada cifra: los clientes y los proveedores/compras del mes.
 *
 * INDEPENDIENTE del MotorFiscal (pestaña Impuestos): sin flujo de efectivo,
 * sin catálogos versionados, sin tarifa progresiva. Es para tener una idea de
 * cuánto sería, no la declaración. Sólo lectura.
 */

soloAdmin();

$contrib = \App\Contabilidad::contribuyentePrincipal();
$anio    = (int) ($_GET['anio'] ?? $_POST['anio'] ?? date('Y'));
$anio    = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) date('Y');
$mesPorDefecto = $anio === (int) date('Y') ? (int) date('n') : 12;
$mes     = (int) ($_GET['mes'] ?? $_POST['mes'] ?? $mesPorDefecto);
$mes     = ($mes >= 1 && $mes <= 12) ? $mes : $mesPorDefecto;
$destino = "/admin/fiscal/preliminar?anio=$anio&mes=$mes";

$r        = \App\PreliminarFiscal::anual($contrib, $anio);
$t        = $r['total'];
$actual   = $r['meses'][$mes];
$clientes = \App\PreliminarFiscal::clientes($contrib, $anio, $mes);
$compras  = \App\PreliminarFiscal::compras($contrib, $anio, $mes);
$m        = static fn ($v): string => $v === null ? '—' : ((float) $v < 0 ? '−' : '') . formatoMoneda(abs((float) $v));
$fecha    = static fn ($v): string => $v ? date('d/m/Y', strtotime((string) $v)) : '—';
$consultaExp = http_build_query(['anio' => $anio, 'mes' => $mes]);
$anioTexto = (string) $anio;

// Renglones de la tabla fiscal (misma estructura que la hoja «FISCAL» del documento).
$filas = [
    ['ventas', 'Ventas facturadas (con IVA)', false],
    ['gastos', 'Gastos facturados (con IVA)', false],
    ['ventas_base', 'Ventas sin IVA (base)', false],
    ['gastos_base', 'Gastos sin IVA (base)', false],
    ['iva_facturado', 'IVA facturado (trasladado)', false],
    ['iva_acreditado', 'IVA acreditable (de gastos)', false],
    ['iva_por_pagar', 'IVA por pagar', false],
    ['isr', 'ISR por pagar (32 % de la utilidad)', false],
    ['impuestos', 'IMPUESTOS POR PAGAR', true],
];

template('header', [
    'titulo'    => 'Preliminar básico',
    'subtitulo' => 'Simulador con las facturas registradas · ' . $anio,
    'rutaActiva' => '/admin/fiscal',
    'acciones'  => '<a class="btn btn--primario" href="/admin/fiscal/exportar?' . escaparValores($consultaExp) . '&amp;tipo=preliminar&amp;formato=pdf" target="_blank" rel="noopener">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span><span>Exportar PDF</span></a>',
]);
template('tabsFiscal', ['tab' => 'preliminar', 'periodo' => ['anio' => $anio, 'mes' => $mes]]);
?>

<form class="buscador mb-lg" action="/admin/fiscal/preliminar" method="get" aria-label="Elegir mes">
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

<section class="estadisticas-resumen" aria-label="Resumen del mes">
    <div class="tarjeta"><span class="stat__etiqueta">Ventas facturadas · <?= escaparValores(nombreMes($mes)) ?></span>
        <span class="estadisticas-resumen__valor"><?= $m($actual['ventas']) ?></span>
        <span class="stat__pie"><?= (int) $actual['ventas_n'] ?> factura(s) · sin IVA: <?= $m($actual['ventas_base']) ?></span></div>
    <div class="tarjeta"><span class="stat__etiqueta">Gastos facturados · <?= escaparValores(nombreMes($mes)) ?></span>
        <span class="estadisticas-resumen__valor"><?= $m($actual['gastos']) ?></span>
        <span class="stat__pie"><?= (int) $actual['gastos_n'] ?> comprobante(s) · sin IVA: <?= $m($actual['gastos_base']) ?></span></div>
    <div class="tarjeta"><span class="stat__etiqueta"><?= $actual['impuestos'] >= 0 ? 'Impuestos por pagar' : 'Saldo a tu favor' ?> · <?= escaparValores(nombreMes($mes)) ?></span>
        <span class="estadisticas-resumen__valor"><?= $m(abs($actual['impuestos'])) ?></span>
        <span class="stat__pie">IVA <?= $m($actual['iva_por_pagar']) ?> + ISR <?= $m($actual['isr']) ?> (simulador básico, no el motor)</span></div>
    <div class="tarjeta"><span class="stat__etiqueta">Total del año <?= $anio ?></span>
        <span class="estadisticas-resumen__valor"><?= $m($t['impuestos']) ?></span>
        <span class="stat__pie">Suma de los 12 meses, como el documento · IVA <?= $m($t['iva_por_pagar']) ?> + ISR <?= $m($t['isr']) ?></span></div>
</section>

<p class="nota mb-lg" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
    <span><strong>Qué es esto:</strong> el cálculo básico del documento Fiscal 2026, alimentado con las
        <strong>ventas facturadas</strong> y los <strong>gastos con comprobante fiscal</strong> de la base (importes
        reales: soporta IVA 16 %, 8 % y 0 %). No usa cobros ni pagos: el mes es el de la <strong>fecha de la
        factura</strong>. El ISR es plano del 32 % sobre la utilidad del mes. Es un preliminar para tener una idea;
        la estimación formal vive en <a href="/admin/fiscal?anio=<?= $anio ?>&amp;mes=<?= $mes ?>">Impuestos</a>
        (flujo de efectivo, tarifa art. 106 y catálogos versionados).</span></p>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <div class="tabla-contenedor">
        <table class="tabla tabla--numerica">
            <caption class="solo-lectores">Preliminar de impuestos mes por mes, <?= $anio ?></caption>
            <thead>
                <tr>
                    <th scope="col">Concepto</th>
                    <?php for ($i = 1; $i <= 12; $i++) : ?>
                        <th scope="col"<?= $i === $mes ? ' aria-current="true" style="background:var(--color-primario-claro)"' : '' ?>><a href="/admin/fiscal/preliminar?anio=<?= $anio ?>&amp;mes=<?= $i ?>"><?= escaparValores(mb_substr(nombreMes($i), 0, 3)) ?></a></th>
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
<p class="campo__ayuda mb-lg">Un número en verde significa que ese mes quedó <strong>a tu favor</strong>. El año
    suma los 12 meses tal cual (como la fila «TOTAL IMPUESTOS» del documento): los meses a favor descuentan de los demás.</p>

<details class="mb-lg">
    <summary class="boton-enlace" style="cursor:pointer"><strong>¿Cómo se calcula cada renglón?</strong></summary>
    <div class="mt-md">
        <ul class="lista-simple mb-md">
            <li><strong>Ventas facturadas:</strong> los <strong>ingresos</strong> marcados como facturados (con su CFDI);
                su mes es el de la <strong>fecha de facturación</strong> (o la de la venta si no tiene).</li>
            <li><strong>Gastos facturados:</strong> los <strong>gastos con comprobante fiscal</strong> (tiene CFDI);
                su mes es el del gasto.</li>
            <li><strong>Bases sin IVA:</strong> el subtotal real de cada factura (no se divide entre 1.16):
                si algo va con IVA 0 o 8 %, entra con su IVA real.</li>
            <li><strong>IVA por pagar</strong> = IVA facturado (trasladado en ventas) − IVA acreditable (de gastos).</li>
            <li><strong>ISR por pagar</strong> = (base ventas − base gastos) × <strong>32 %</strong>, plano y sin
                acumular (la tarifa progresiva art. 106 está en la pestaña Impuestos).</li>
            <li><strong>Impuestos por pagar</strong> = IVA por pagar + ISR; el <strong>año</strong> suma los 12 meses.</li>
        </ul>
        <p class="campo__ayuda">No entran: ventas sin facturar o sin clasificar, gastos sin comprobante fiscal,
            cobros y pagos (flujo de efectivo), retenciones y ajustes (pestaña Retenciones). Confírmalo siempre
            con tu contador.</p>
    </div>
</details>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:.75rem .75rem 0">Clientes facturados · <?= escaparValores(nombreMes($mes)) ?> <?= $anio ?></h2>
    <?php if (!$clientes['filas']) : ?>
        <div class="vacio"><p class="vacio__titulo">Sin ventas facturadas en <?= escaparValores(mb_strtolower(nombreMes($mes))) ?></p></div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla tabla--numerica">
                <caption class="solo-lectores">Ventas facturadas de <?= escaparValores(nombreMes($mes)) ?> <?= $anio ?></caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Folio</th><th scope="col">Cliente</th>
                    <th scope="col" class="tabla--numerica">Monto S/IVA</th><th scope="col" class="tabla--numerica">IVA</th><th scope="col" class="tabla--numerica">Monto C/IVA</th></tr></thead>
                <tbody>
                    <?php foreach ($clientes['filas'] as $f) : ?>
                        <tr>
                            <td><?= $fecha($f['fecha']) ?></td>
                            <td><?= escaparValores($f['folio']) ?></td>
                            <td><?= escaparValores($f['cliente']) ?></td>
                            <td class="cifra"><?= $m($f['sin_iva']) ?></td>
                            <td class="cifra"><?= $m($f['iva']) ?></td>
                            <td class="cifra"><?= $m($f['con_iva']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th scope="row" colspan="3">TOTAL (<?= (int) $clientes['totales']['n'] ?> factura(s))</th>
                        <td class="cifra"><?= $m($clientes['totales']['sin_iva']) ?></td>
                        <td class="cifra"><?= $m($clientes['totales']['iva']) ?></td>
                        <td class="cifra"><?= $m($clientes['totales']['con_iva']) ?></td></tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:.75rem .75rem 0">Proveedores y compras facturadas · <?= escaparValores(nombreMes($mes)) ?> <?= $anio ?></h2>
    <?php if (!$compras['filas']) : ?>
        <div class="vacio"><p class="vacio__titulo">Sin gastos con comprobante en <?= escaparValores(mb_strtolower(nombreMes($mes))) ?></p></div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla tabla--numerica">
                <caption class="solo-lectores">Compras facturadas de <?= escaparValores(nombreMes($mes)) ?> <?= $anio ?></caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Proveedor o beneficiario</th><th scope="col">Concepto</th>
                    <th scope="col" class="tabla--numerica">Monto S/IVA</th><th scope="col" class="tabla--numerica">IVA</th><th scope="col" class="tabla--numerica">Monto C/IVA</th></tr></thead>
                <tbody>
                    <?php foreach ($compras['filas'] as $f) : ?>
                        <tr>
                            <td><?= $fecha($f['fecha']) ?></td>
                            <td><?= escaparValores($f['beneficiario']) ?></td>
                            <td><?= escaparValores($f['concepto']) ?></td>
                            <td class="cifra"><?= $m($f['sin_iva']) ?></td>
                            <td class="cifra"><?= $m($f['iva']) ?></td>
                            <td class="cifra"><?= $m($f['con_iva']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th scope="row" colspan="3">TOTAL (<?= (int) $compras['totales']['n'] ?> comprobante(s))</th>
                        <td class="cifra"><?= $m($compras['totales']['sin_iva']) ?></td>
                        <td class="cifra"><?= $m($compras['totales']['iva']) ?></td>
                        <td class="cifra"><?= $m($compras['totales']['con_iva']) ?></td></tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
