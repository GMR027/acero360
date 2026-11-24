<?php
/**
 * views/admin/index.php — Panel principal del administrador.
 *
 * «Resumen general»: se ordena como se atiende el día. Primero las ventas de
 * cada negocio, después los impuestos del periodo, luego
 * lo que está pendiente de cobrar, de pagar, de entregar y de resolver, y al
 * final el inventario, la actividad reciente y los indicadores del periodo.
 *
 * Todo lo que lista una cifra lleva su acción directa: el enlace del encabezado
 * abre el listado completo y cada fila abre el detalle de lo que la formó.
 */

soloAdmin();

/* ---------------------------------------------------------------------------
 * PERIODO
 *
 * Llega por query string: ?anio=2026&semestre=1&mes=10. La prioridad la
 * resuelve Cotizaciones::rangoPeriodo() (mes > semestre > año); el año en curso
 * es el valor por defecto y "Todos los años" deja el periodo abierto sobre el
 * histórico completo.
 * ------------------------------------------------------------------------- */

$anioFiltro     = isset($_GET['anio']) ? (int) $_GET['anio'] : (int) date('Y');
$semestreFiltro = (int) ($_GET['semestre'] ?? 0);
$mesFiltro      = (int) ($_GET['mes'] ?? 0);

if (!in_array($semestreFiltro, [0, 1, 2], true)) {
    $semestreFiltro = 0;
}
if ($mesFiltro < 1 || $mesFiltro > 12) {
    $mesFiltro = 0;
}

/** Opciones del selector de año: los que ya tienen cotizaciones + el actual. */
$anios = \App\Cotizaciones::anios();
if ($anioFiltro > 0 && !in_array($anioFiltro, $anios, true)) {
    $anios[] = $anioFiltro;
    rsort($anios);
}

$periodo = \App\Cotizaciones::rangoPeriodo($anioFiltro, $semestreFiltro, $mesFiltro);

/** Sólo se ofrece "Limpiar" cuando el periodo difiere del valor por defecto. */
$hayFiltros = $anioFiltro !== (int) date('Y') || $semestreFiltro > 0 || $mesFiltro > 0;

/** Los negocios que emiten cotizaciones (los activos del panel). */
$negocios = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static function ($empresa): bool {
        return (int) $empresa->activa === 1;
    }
));

$negociosResumen = [];
foreach ($negocios as $negocio) {
    $negociosResumen[(int) $negocio->id] = \App\Cotizaciones::ganancias(
        (int) $negocio->id,
        $periodo['desde'],
        $periodo['hasta']
    );
}

$usuarioId   = (int) ($_SESSION['id'] ?? 0);
$indicadores = \App\Panel::indicadores($periodo['desde'], $periodo['hasta'], $usuarioId);

/* ---------------------------------------------------------------------------
 * PENDIENTES DE AHORA MISMO
 *
 * Todo lo que está abierto a hoy, sin importar la fecha: ventas por cobrar o
 * por facturar, gastos por pagar, piezas por entregar y entregas con
 * incidencias sin resolver.
 * ------------------------------------------------------------------------- */

$ventasPendientes   = \App\Panel::ventasPendientesPago(6);
$nVentasPendientes  = \App\Panel::contarVentasPendientesPago();
$gastosPendientes   = \App\Panel::gastosPorPagar(6);
$resumenGastos      = \App\Panel::resumenGastosPorPagar();
$entregasPendientes = \App\Panel::entregasPendientes(6);
$incidenciasAbiertas = \App\Panel::incidenciasAbiertas(6);
$nIncidencias       = \App\Panel::contarIncidenciasAbiertas();

$ultimasCotizaciones = \App\Panel::ultimasCotizaciones(5);
$ultimasVentas       = \App\Panel::ultimasVentas(5);

/* ¿Es una cuenta recién creada? Si no hay ventas, cotizaciones, pendientes
 * ni movimientos, el panel muestra una guía de primeros pasos en lugar de
 * dejar solo tarjetas con ceros. */
$ventasPeriodo = 0.0;
foreach ($negociosResumen as $resumenNegocio) {
    $ventasPeriodo += (float) $resumenNegocio['ventas_n'];
}
$sinActividad = $ventasPeriodo === 0.0
    && !$ultimasCotizaciones
    && !$ultimasVentas
    && $nVentasPendientes === 0
    && !$gastosPendientes
    && !$entregasPendientes
    && $nIncidencias === 0;

/* ---------------------------------------------------------------------------
 * IMPUESTOS (PRELIMINAR)
 *
 * El mismo simulador de la pestaña Fiscal, con el periodo del panel: si se
 * eligió mes es el de ese mes; si sólo semestre o año, la suma de los meses
 * que abarca. Sólo lectura: no toca el motor de impuestos.
 * ------------------------------------------------------------------------- */

$contribuyente = \App\Contabilidad::contribuyentePrincipal();
$anioImpuestos = $anioFiltro > 0 ? $anioFiltro : (int) date('Y');
$mesesImpuestos = $mesFiltro > 0
    ? [$mesFiltro]
    : ($semestreFiltro === 1 ? range(1, 6) : ($semestreFiltro === 2 ? range(7, 12) : range(1, 12)));

$preliminarAnual = \App\PreliminarFiscal::anual($contribuyente, $anioImpuestos);
$impuestos = array_fill_keys(
    ['ventas', 'gastos', 'ventas_base', 'gastos_base', 'iva_facturado', 'iva_acreditado',
        'iva_por_pagar', 'isr', 'impuestos', 'ventas_n', 'gastos_n'], 0.0
);
foreach ($mesesImpuestos as $mesImpuesto) {
    foreach ($preliminarAnual['meses'][$mesImpuesto] as $clave => $valor) {
        $impuestos[$clave] += $valor;
    }
}

if ($mesFiltro > 0) {
    $etiquetaImpuestos = nombreMes($mesFiltro) . ' ' . $anioImpuestos;
} elseif ($semestreFiltro > 0) {
    $etiquetaImpuestos = ($semestreFiltro === 1 ? '1.er semestre' : '2.º semestre') . ' ' . $anioImpuestos;
} else {
    $etiquetaImpuestos = 'año ' . $anioImpuestos;
}
/** «Todos los años» no existe en el simulador: se aclara de qué año se habla. */
$notaImpuestos = $anioFiltro <= 0 ? ' · El preliminar se calcula año por año; se muestra el ' . $anioImpuestos : '';
$rutaPreliminar = '/admin/fiscal/preliminar?anio=' . $anioImpuestos
    . ($mesFiltro > 0 ? '&mes=' . $mesFiltro : '');

/* ---------------------------------------------------------------------------
 * PRODUCTOS MÁS VENDIDOS DEL MES Y EXISTENCIAS EN ALERTA
 *
 * El mes sale del filtro (mes + año); si no se eligió mes, se usa el mes en
 * curso. El mes en curso sólo cuenta los días que ya pasaron.
 * ------------------------------------------------------------------------- */
$mesTop      = $mesFiltro > 0 && $anioFiltro > 0 ? $mesFiltro : (int) date('n');
$anioTop     = $mesFiltro > 0 && $anioFiltro > 0 ? $anioFiltro : (int) date('Y');
$desdeTop    = sprintf('%04d-%02d-01', $anioTop, $mesTop);
$hastaTop    = min(date('Y-m-t', strtotime($desdeTop)), date('Y-m-d'));
$etiquetaMes = nombreMes($mesTop) . ' ' . $anioTop;
$masVendidos = \App\Panel::productosMasVendidos($desdeTop, $hastaTop, 4);
$stockBajo   = \App\Panel::productosStockBajo(8);
$stockTotal  = \App\Panel::contarStockBajo();

/** Miniatura cuadrada de un producto (o su marcador cuando no hay imagen). */
$miniatura = static function (array $p): string {
    if (!empty($p['imagen'])) {
        return '<img class="stat__imagen" src="' . escaparValores(\App\Imagenes::urlMiniatura($p['imagen'])) . '" alt="" width="56" height="56" loading="lazy" decoding="async">';
    }

    return '<span class="stat__imagen stat__imagen--vacia" role="img" aria-label="Sin imagen de ' . escaparValores($p['nombre']) . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true">image_not_supported</span></span>';
};

/** Cantidad con plural bien escrito («1 pieza», «3 piezas»). */
$plural = static fn (int $n, string $uno, string $muchos): string => number_format($n) . ' ' . ($n === 1 ? $uno : $muchos);

/** Código (SKU) de una tarjeta de producto; los productos sin SKU se marcan. */
$codigo = static fn (array $p): string => $p['sku'] !== '' ? $p['sku'] : 'Sin código';

/** Importe con su signo (los meses a favor del preliminar salen con «−»). */
$importe = static fn (float $v): string => ($v < 0 ? '−' : '') . formatoMoneda(abs($v));

/** Cliente por ALIAS cuando lo tiene, o por nombre. */
$clienteDe = static fn (array $f): string => trim((string) $f['cliente']) !== '' ? (string) $f['cliente'] : 'Sin cliente';

/** Botón de acción de una fila de la lista (icono + texto en pantallas grandes). */
$botonFila = static function (string $ruta, string $icono, string $texto, string $clase = 'btn--secundario'): string {
    // En el teléfono el texto se oculta, así que el botón lleva su nombre en
    // aria-label/title para que siga leyéndose y reconociéndose.
    return '<a class="btn ' . $clase . ' btn--sm" href="' . escaparValores($ruta) . '"'
        . ' aria-label="' . escaparValores($texto) . '" title="' . escaparValores($texto) . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">' . $icono . '</span>'
        . '<span class="movil-oculto">' . escaparValores($texto) . '</span></a>';
};

$vacio = static function (string $texto): string {
    return '<p class="texto-secundario">' . $texto . '</p>';
};

/* Acciones del encabezado: las dos salidas más frecuentes del panel. */
$accionesPanel = '<a class="btn btn--secundario" href="/admin/ventas/crear">'
    . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">point_of_sale</span>'
    . '<span>Nueva venta</span></a>'
    . '<a class="btn btn--primario" href="/admin/cotizaciones/crear">'
    . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">note_add</span>'
    . '<span>Nueva cotización</span></a>';

template('header', [
    'titulo'     => 'Resumen general',
    'subtitulo'  => 'Todo lo que requiere tu atención hoy',
    'rutaActiva' => '/admin',
    'acciones'   => $accionesPanel,
]);

/* ---------------------------------------------------------------------------
 * INDICADORES CLAVE
 *
 * Se leen de arriba abajo, como se revisa el negocio: primero el resultado del
 * periodo, después el dinero que falta por mover y al final lo que está
 * detenido. Cada tarjeta abre el registro que la forma.
 * ------------------------------------------------------------------------- */
$nPorPagar = (int) $resumenGastos['n'];

$kpisPanel = [
    [
        'etiqueta' => 'Ventas del periodo',
        'valor'    => formatoMoneda((float) $indicadores['ventas']['monto']),
        'pie'      => number_format((int) $indicadores['ventas']['n']) . ((int) $indicadores['ventas']['n'] === 1 ? ' venta registrada' : ' ventas registradas'),
        'icono'    => 'trending_up',
        'enlace'   => $indicadores['ventas']['enlace'],
        'alerta'   => false,
    ],
    [
        'etiqueta' => 'Gastos del periodo',
        'valor'    => formatoMoneda((float) $indicadores['gastos']['monto']),
        'pie'      => number_format((int) $indicadores['gastos']['n']) . ((int) $indicadores['gastos']['n'] === 1 ? ' gasto registrado' : ' gastos registrados'),
        'icono'    => 'receipt_long',
        'enlace'   => $indicadores['gastos']['enlace'],
        'alerta'   => false,
    ],
    [
        'etiqueta' => 'Ventas por cobrar',
        'valor'    => formatoMoneda((float) $indicadores['cobrar']['monto']),
        'pie'      => number_format((int) $indicadores['cobrar']['n']) . ((int) $indicadores['cobrar']['n'] === 1 ? ' venta' : ' ventas') . ' · todas las fechas',
        'icono'    => 'account_balance_wallet',
        'enlace'   => $indicadores['cobrar']['enlace'],
        'alerta'   => (int) $indicadores['cobrar']['n'] > 0,
    ],
    [
        'etiqueta' => 'Gastos por pagar',
        'valor'    => formatoMoneda((float) $resumenGastos['monto']),
        'pie'      => number_format($nPorPagar) . ($nPorPagar === 1 ? ' gasto' : ' gastos') . ' · todas las fechas',
        'icono'    => 'payments',
        'enlace'   => '/admin/contabilidad/gastos?pago=pendiente&todos=1',
        'alerta'   => $nPorPagar > 0,
    ],
    [
        'etiqueta' => 'Entregas pendientes',
        'valor'    => number_format((int) $indicadores['entregas']['n']),
        'pie'      => (int) $indicadores['entregas']['n'] === 1 ? 'venta con piezas por entregar' : 'ventas con piezas por entregar',
        'icono'    => 'local_shipping',
        'enlace'   => $indicadores['entregas']['enlace'],
        'alerta'   => (int) $indicadores['entregas']['n'] > 0,
    ],
    [
        'etiqueta' => 'Avisos sin leer',
        'valor'    => number_format((int) $indicadores['notificaciones']['n']),
        'pie'      => (int) $indicadores['notificaciones']['n'] === 1 ? 'requiere revisión' : 'requieren revisión',
        'icono'    => 'notifications_active',
        'enlace'   => $indicadores['notificaciones']['enlace'],
        'alerta'   => (int) $indicadores['notificaciones']['n'] > 0,
    ],
];
?>

<section class="panel-toolbar" aria-label="Periodo del panel">
    <div class="panel-toolbar__periodo">
        <span class="panel-toolbar__icono" aria-hidden="true">
            <span class="material-symbols-outlined">calendar_month</span>
        </span>
        <div class="panel-toolbar__texto">
            <span class="texto-etiqueta">Periodo analizado</span>
            <strong><?= escaparValores($periodo['etiqueta']) ?></strong>
            <span class="texto-secundario">Ventas, gastos e impuestos usan este periodo; los pendientes se cuentan completos.</span>
        </div>
    </div>

    <form class="panel-toolbar__form" action="/admin" method="get" role="search" data-validar>
        <label class="solo-lectores" for="filtro-anio">Año</label>
        <select class="control control--compacto" id="filtro-anio" name="anio">
            <?php foreach ($anios as $anio) : ?>
                <option value="<?= $anio ?>"<?= $anio === $anioFiltro ? ' selected' : '' ?>>
                    Año <?= $anio ?>
                </option>
            <?php endforeach; ?>
            <option value=""<?= $anioFiltro <= 0 ? ' selected' : '' ?>>Todos los años</option>
        </select>

        <label class="solo-lectores" for="filtro-semestre">Semestre</label>
        <select class="control control--compacto" id="filtro-semestre" name="semestre"
                <?= $anioFiltro <= 0 ? 'disabled' : '' ?>>
            <option value="">Todo el semestre</option>
            <option value="1"<?= $semestreFiltro === 1 ? ' selected' : '' ?>>1.er semestre (ene–jun)</option>
            <option value="2"<?= $semestreFiltro === 2 ? ' selected' : '' ?>>2.º semestre (jul–dic)</option>
        </select>

        <label class="solo-lectores" for="filtro-mes">Mes</label>
        <select class="control control--compacto" id="filtro-mes" name="mes"
                <?= $anioFiltro <= 0 ? 'disabled' : '' ?>>
            <option value="">Todo el año</option>
            <?php foreach (\App\Cotizaciones::MESES as $indice => $nombre) :
                $valorMes = $indice + 1; ?>
                <option value="<?= $valorMes ?>"<?= $mesFiltro === $valorMes ? ' selected' : '' ?>>
                    <?= escaparValores($nombre) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn--secundario btn--sm" type="submit">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
            <span>Buscar</span>
        </button>

        <?php if ($hayFiltros) : ?>
            <a class="btn btn--texto btn--sm" href="/admin" aria-label="Restablecer el periodo">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                <span>Limpiar</span>
            </a>
        <?php endif; ?>
    </form>
</section>

<section class="panel-kpis" aria-label="Indicadores clave">
    <?php foreach ($kpisPanel as $kpi) : ?>
        <a class="panel-kpi<?= $kpi['alerta'] ? ' panel-kpi--alerta' : '' ?>" href="<?= escaparValores($kpi['enlace']) ?>">
            <span class="panel-kpi__cabecera">
                <span class="panel-kpi__icono" aria-hidden="true">
                    <span class="material-symbols-outlined"><?= escaparValores($kpi['icono']) ?></span>
                </span>
                <span class="panel-kpi__etiqueta"><?= escaparValores($kpi['etiqueta']) ?></span>
            </span>
            <strong class="panel-kpi__valor cifra"><?= $kpi['valor'] ?></strong>
            <span class="panel-kpi__pie"><?= escaparValores($kpi['pie']) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<div class="panel-metricas" aria-label="Otros indicadores del periodo">
    <a class="panel-metrica" href="<?= escaparValores($indicadores['cotizaciones']['enlace']) ?>">
        <span class="material-symbols-outlined" aria-hidden="true">request_quote</span>
        <span class="panel-metrica__texto">Cotizaciones pendientes</span>
        <strong class="cifra"><?= number_format((int) $indicadores['cotizaciones']['n']) ?></strong>
        <span class="panel-metrica__monto cifra"><?= formatoMoneda((float) $indicadores['cotizaciones']['monto']) ?></span>
    </a>
    <a class="panel-metrica" href="<?= escaparValores($indicadores['facturar']['enlace']) ?>">
        <span class="material-symbols-outlined" aria-hidden="true">pending_actions</span>
        <span class="panel-metrica__texto">Pendientes de facturar</span>
        <strong class="cifra"><?= number_format((int) $indicadores['facturar']['n']) ?></strong>
        <span class="panel-metrica__monto cifra"><?= formatoMoneda((float) $indicadores['facturar']['monto']) ?></span>
    </a>
    <a class="panel-metrica" href="<?= escaparValores($indicadores['devoluciones']['enlace']) ?>">
        <span class="material-symbols-outlined" aria-hidden="true">assignment_return</span>
        <span class="panel-metrica__texto">Devoluciones</span>
        <strong class="cifra"><?= number_format((int) $indicadores['devoluciones']['n']) ?></strong>
        <span class="panel-metrica__monto">ventas con piezas devueltas</span>
    </a>
</div>



<?php if ($sinActividad) : ?>
<section class="tarjeta panel-primeros-pasos" aria-labelledby="titulo-primeros-pasos">
    <div class="tarjeta__cabecera">
        <h2 class="panel-seccion__titulo" id="titulo-primeros-pasos">
            <span class="material-symbols-outlined" aria-hidden="true">rocket_launch</span>
            Primeros pasos
        </h2>
        <span class="badge badge--neutro badge--sin-punto">Sin actividad todavía</span>
    </div>
    <p class="texto-secundario mb-md">
        Empieza por lo básico: registra tu empresa, da de alta un cliente y un producto, y crea tu primera cotización.
        En cuanto haya movimiento, este panel se llenará con tus cifras.
    </p>
    <div class="encabezado-pagina__acciones">
        <?php if (!$negocios) : ?>
            <a class="btn btn--primario" href="/admin/empresas/crear">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">domain_add</span>
                <span>Crear empresa</span>
            </a>
        <?php endif; ?>
        <a class="btn btn--secundario" href="/admin/clientes/crear">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">person_add</span>
            <span>Nuevo cliente</span>
        </a>
        <a class="btn btn--secundario" href="/admin/productos/crear">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_box</span>
            <span>Nuevo producto</span>
        </a>
        <?php if ($negocios) : ?>
            <a class="btn btn--primario" href="/admin/cotizaciones/crear">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">note_add</span>
                <span>Nueva cotización</span>
            </a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- 1. Lo que está pendiente: cobro, facturación, gastos, entregas e incidencias -->
<section class="panel-seccion" aria-labelledby="titulo-pendientes">
    <div class="panel-seccion__cabecera">
        <div class="panel-seccion__texto">
            <h2 class="panel-seccion__titulo" id="titulo-pendientes">
                <span class="material-symbols-outlined" aria-hidden="true">priority_high</span>
                Pendientes de atender
            </h2>
            <p class="panel-seccion__sub">Cobrar, facturar, pagar, entregar y resolver: lo que hoy pide una acción.</p>
        </div>
        <a class="btn btn--texto btn--sm" href="/admin/seguimiento">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">notifications</span>
            <span>Centro de seguimiento<?= $indicadores['notificaciones']['n'] > 0 ? ' (' . number_format((int) $indicadores['notificaciones']['n']) . ')' : '' ?></span>
        </a>
    </div>

    <div class="panel-pendientes">
        <!-- Ventas pendientes de pago o por facturar -->
        <article class="tarjeta tarjeta--panel">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">
                    <span class="panel-titulo-icono" aria-hidden="true"><span class="material-symbols-outlined">payments</span></span>
                    Ventas por cobrar o facturar
                    <span class="badge <?= $nVentasPendientes > 0 ? 'badge--pendiente' : 'badge--aprobado' ?>"><?= number_format($nVentasPendientes) ?></span>
                </h3>
                <div class="fila">
                    <a class="btn btn--texto btn--sm" href="<?= escaparValores($indicadores['cobrar']['enlace']) ?>">
                        Por cobrar (<?= number_format((int) $indicadores['cobrar']['n']) ?>)
                    </a>
                    <a class="btn btn--texto btn--sm" href="<?= escaparValores($indicadores['facturar']['enlace']) ?>">
                        Por facturar (<?= number_format((int) $indicadores['facturar']['n']) ?>)
                    </a>
                </div>
            </div>
            <?php if (!$ventasPendientes) : ?>
                <?= $vacio('Todas las ventas están cobradas y clasificadas en facturación.') ?>
            <?php else : ?>
                <ul class="actividad">
                    <?php foreach ($ventasPendientes as $f) :
                        $porCobrar = (int) $f['por_cobrar'] === 1;
                        $porFacturar = (int) $f['por_facturar'] === 1;
                        $ruta = \App\Contabilidad::rutaDetalle($f, $porCobrar ? 'cobros' : 'facturacion');
                        $avisos = '';
                        if ($porCobrar) {
                            $avisos .= ' <span class="badge badge--pendiente">Por cobrar</span>';
                        }
                        if ($porFacturar) {
                            $avisos .= ' <span class="badge badge--info">Por facturar</span>';
                        }
                        /** Sólo se avanza el saldo cuando ya hubo un pago parcial. */
                        $resta = $porCobrar && (float) $f['saldo'] < (float) $f['total']
                            ? ' · falta cobrar ' . formatoMoneda((float) $f['saldo'])
                            : ''; ?>
                        <li><a class="actividad__fila" href="<?= escaparValores($ruta) ?>">
                            <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($clienteDe($f)) ?> · venta del <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?><?= $avisos ?><?= $resta ?></span></span>
                            <span class="actividad__importe"><?= formatoMoneda((float) $f['total']) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="tarjeta__pie">
                <span>Por cobrar: <strong class="cifra"><?= formatoMoneda((float) $indicadores['cobrar']['monto']) ?></strong></span>
                <a class="btn btn--texto btn--sm" href="<?= escaparValores($indicadores['cobrar']['enlace']) ?>">Ver todas</a>
            </div>
        </article>

        <!-- Gastos pendientes por pagar -->
        <article class="tarjeta tarjeta--panel">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">
                    <span class="panel-titulo-icono" aria-hidden="true"><span class="material-symbols-outlined">receipt_long</span></span>
                    Gastos por pagar
                    <span class="badge <?= $resumenGastos['n'] > 0 ? 'badge--pendiente' : 'badge--aprobado' ?>"><?= number_format((int) $resumenGastos['n']) ?></span>
                </h3>
                <div class="fila">
                    <a class="btn btn--texto btn--sm" href="/admin/contabilidad/gastos?pago=pendiente&amp;todos=1">
                        Ver todos (<?= number_format($nPorPagar) ?>)
                    </a>
                </div>
            </div>
            <?php if (!$gastosPendientes) : ?>
                <?= $vacio('No hay gastos con el pago pendiente.') ?>
            <?php else : ?>
                <ul class="actividad">
                    <?php foreach ($gastosPendientes as $g) : ?>
                        <li><a class="actividad__fila" href="/admin/contabilidad/gastos/detalle?id=<?= (int) $g['id'] ?>">
                            <span><span class="actividad__titulo"><?= escaparValores(trim((string) $g['beneficiario']) !== '' ? $g['beneficiario'] : $g['concepto']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($g['concepto']) ?> · <?= escaparValores(date('d/m/Y', strtotime($g['fecha']))) ?></span></span>
                            <span class="actividad__importe"><?= formatoMoneda((float) $g['total']) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="tarjeta__pie">
                <span>Total por pagar: <strong class="cifra"><?= formatoMoneda((float) $resumenGastos['monto']) ?></strong></span>
                <a class="btn btn--texto btn--sm" href="/admin/contabilidad/gastos?pago=pendiente&amp;todos=1">Gestionar pagos</a>
            </div>
        </article>

        <!-- Ventas pendientes por entregar -->
        <article class="tarjeta tarjeta--panel">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">
                    <span class="panel-titulo-icono" aria-hidden="true"><span class="material-symbols-outlined">local_shipping</span></span>
                    Ventas por entregar
                    <span class="badge <?= $indicadores['entregas']['n'] > 0 ? 'badge--pendiente' : 'badge--aprobado' ?>"><?= number_format((int) $indicadores['entregas']['n']) ?></span>
                </h3>
                <div class="fila">
                    <a class="btn btn--texto btn--sm" href="/admin/entregas">Ver todas (<?= number_format((int) $indicadores['entregas']['n']) ?>)</a>
                </div>
            </div>
            <?php if (!$entregasPendientes) : ?>
                <?= $vacio('No hay ventas con piezas por entregar.') ?>
            <?php else : ?>
                <ul class="actividad">
                    <?php foreach ($entregasPendientes as $f) :
                        $faltan = max(0, (int) $f['piezas'] - (int) $f['entregadas']);
                        $enPreparacion = trim((string) ($f['en_preparaciones'] ?? '')) !== '';
                        $rutaSeguimiento = '/admin/ventas/detalle?id=' . (int) $f['id'] . '&volver=inicio';
                        $rutaEntrega = '/admin/ventas/detalle?id=' . (int) $f['id'] . '&accion=entrega&volver=inicio'; ?>
                        <li class="actividad__item">
                            <a class="actividad__fila" href="<?= escaparValores($rutaSeguimiento) ?>">
                                <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                    <span class="actividad__detalle"><?= escaparValores($f['cliente_empresa'] ?: $f['cliente_nombre']) ?> · venta del <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?><?= $enPreparacion ? ' · en preparación' : '' ?></span></span>
                                <span class="actividad__importe">faltan <?= $plural($faltan, 'pza', 'pzas') ?></span>
                            </a>
                            <?= $botonFila($rutaEntrega, 'local_shipping', 'Entregar') ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="tarjeta__pie">
                <span>Piezas pendientes de salir del almacén</span>
                <a class="btn btn--texto btn--sm" href="/admin/entregas">Hoja de ruta</a>
            </div>
        </article>

        <!-- Incidencias de logística o entrega -->
        <article class="tarjeta tarjeta--panel">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">
                    <span class="panel-titulo-icono panel-titulo-icono--alerta" aria-hidden="true"><span class="material-symbols-outlined">report</span></span>
                    Incidencias abiertas
                    <span class="badge <?= $nIncidencias > 0 ? 'badge--error' : 'badge--aprobado' ?>"><?= number_format($nIncidencias) ?></span>
                </h3>
                <div class="fila">
                    <a class="btn btn--texto btn--sm" href="/admin/ventas?entrega=incidencias">Ver seguimiento</a>
                </div>
            </div>
            <?php if (!$incidenciasAbiertas) : ?>
                <?= $vacio('No hay incidencias de entrega sin resolver.') ?>
            <?php else : ?>
                <ul class="actividad">
                    <?php foreach ($incidenciasAbiertas as $i) :
                        $motivo = \App\IncidenciasEntrega::MOTIVOS[$i['motivo']] ?? ['etiqueta' => $i['motivo'], 'clase' => 'badge--neutro'];
                        $rutaSeguimiento = '/admin/entregas-seguimiento/venta?id=' . (int) $i['venta_id'] . '#incidencias';
                        $piezas = (int) $i['piezas']; ?>
                        <li class="actividad__item">
                            <a class="actividad__fila" href="<?= escaparValores($rutaSeguimiento) ?>">
                                <span><span class="actividad__titulo"><?= escaparValores($i['folio']) ?></span>
                                    <span class="actividad__detalle"><?= escaparValores($clienteDe($i)) ?> · <span class="badge <?= $motivo['clase'] ?>"><?= escaparValores($motivo['etiqueta']) ?></span><?= (int) $i['factura_requerida'] === 1 ? ' <span class="badge badge--error">Corregir factura</span>' : '' ?></span></span>
                                <span class="actividad__importe"><?= $plural($piezas, 'pza', 'pzas') ?></span>
                            </a>
                            <?= $botonFila($rutaSeguimiento, 'report', 'Atender', 'btn--primario') ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="tarjeta__pie">
                <span>Logística y entregas con seguimiento abierto</span>
                <a class="btn btn--texto btn--sm" href="/admin/ventas?entrega=incidencias">Ver historial</a>
            </div>
        </article>
    </div>
</section>

<!-- 2. Ventas por negocio e impuestos -->
<section class="panel-seccion" aria-labelledby="titulo-negocios">
    <div class="panel-seccion__cabecera">
        <div class="panel-seccion__texto">
            <h2 class="panel-seccion__titulo" id="titulo-negocios">
                <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
                Ventas por negocio e impuestos
            </h2>
            <p class="panel-seccion__sub">Cómo va cada negocio en <strong><?= escaparValores($periodo['etiqueta']) ?></strong> y cuánto conviene reservar para impuestos.</p>
        </div>
        <a class="btn btn--texto btn--sm" href="/admin/estadisticas">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">monitoring</span>
            <span>Ver estadísticas</span>
        </a>
    </div>

    <div class="rejilla-resumen panel-resumen">
        <div class="panel-negocios">
            <?php if (!$negocios) : ?>
                <div class="tarjeta"><?= $vacio('Todavía no hay negocios activos para mostrar sus ventas.') ?></div>
            <?php else : ?>
                <?php foreach ($negocios as $negocio) :
                    $resumen = $negociosResumen[(int) $negocio->id];
                    $logo = trim((string) $negocio->logo) !== ''
                        ? \App\Imagenes::url($negocio->logo)
                        : '';
                    $rutaVentas = '/admin/ventas?empresa=' . (int) $negocio->id
                        . ($periodo['desde'] !== '' ? '&desde=' . $periodo['desde'] : '')
                        . ($periodo['hasta'] !== '' ? '&hasta=' . $periodo['hasta'] : '');
                    $pluralVendidas = $resumen['vendidas'] === 1 ? 'cotización vendida' : 'cotizaciones vendidas';
                    $pluralPendientes = $resumen['pendientes'] === 1 ? 'cotización pendiente' : 'cotizaciones pendientes';
                    ?>
                    <article class="tarjeta ganancia tarjeta--panel">
                        <div class="tarjeta__cabecera">
                            <h3 class="tarjeta__titulo">
                                <?php if ($logo !== '') : ?>
                                    <img class="ganancia__logo" src="<?= escaparValores($logo) ?>" alt="">
                                <?php endif; ?>
                                <span><?= escaparValores($negocio->nombre) ?></span>
                            </h3>
                            <a class="btn btn--texto btn--sm" href="<?= escaparValores($rutaVentas) ?>">
                                <span>Ver ventas (<?= number_format($resumen['ventas_n']) ?>)</span>
                            </a>
                        </div>

                        <span class="ganancia__etiqueta">Ventas del periodo (con IVA)</span>
                        <strong class="ganancia__valor cifra"><?= formatoMoneda($resumen['ventas']) ?></strong>
                        <span class="ganancia__nota">
                            <?= number_format($resumen['ventas_n']) ?> <?= $resumen['ventas_n'] === 1 ? 'venta' : 'ventas' ?> en el periodo ·
                            <?= number_format($resumen['vendidas']) ?> <?= $pluralVendidas ?> ·
                            <?= number_format($resumen['pendientes']) ?> <?= $pluralPendientes ?>
                        </span>

                        <dl class="ganancia__rejilla">
                            <div class="ganancia__dato">
                                <dt>En cartera</dt>
                                <dd class="cifra"><?= formatoMoneda($resumen['cartera']) ?></dd>
                            </div>
                            <div class="ganancia__dato">
                                <dt>Total cotizado</dt>
                                <dd class="cifra"><?= formatoMoneda($resumen['cotizado']) ?></dd>
                            </div>
                            <div class="ganancia__dato">
                                <dt>Cotizaciones</dt>
                                <dd class="cifra"><?= number_format($resumen['cotizaciones']) ?></dd>
                            </div>
                            <div class="ganancia__dato">
                                <dt>Canceladas</dt>
                                <dd class="cifra"><?= number_format($resumen['canceladas']) ?></dd>
                            </div>
                        </dl>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <aside class="panel-lateral" aria-labelledby="titulo-impuestos">
            <article class="tarjeta ganancia tarjeta--panel panel-impuestos">
                <div class="tarjeta__cabecera">
                    <h3 class="tarjeta__titulo" id="titulo-impuestos">
                        <span class="panel-titulo-icono" aria-hidden="true"><span class="material-symbols-outlined">account_balance</span></span>
                        Preliminar de impuestos
                    </h3>
                    <span class="badge badge--neutro">Simulador</span>
                </div>

                <span class="ganancia__etiqueta"><?= $impuestos['impuestos'] >= 0 ? 'Impuestos por pagar' : 'Saldo a tu favor' ?> · <?= escaparValores($etiquetaImpuestos) ?></span>
                <strong class="ganancia__valor cifra"><?= $importe((float) $impuestos['impuestos']) ?></strong>
                <span class="ganancia__nota">
                    IVA <?= $importe((float) $impuestos['iva_por_pagar']) ?> + ISR <?= $importe((float) $impuestos['isr']) ?> ·
                    <?= (int) $impuestos['ventas_n'] ?> <?= (int) $impuestos['ventas_n'] === 1 ? 'factura' : 'facturas' ?> de venta y
                    <?= (int) $impuestos['gastos_n'] ?> <?= (int) $impuestos['gastos_n'] === 1 ? 'comprobante' : 'comprobantes' ?> de compra
                </span>

                <dl class="ganancia__rejilla">
                    <div class="ganancia__dato">
                        <dt>Ventas facturadas (con IVA)</dt>
                        <dd class="cifra"><?= $importe((float) $impuestos['ventas']) ?></dd>
                    </div>
                    <div class="ganancia__dato">
                        <dt>Gastos facturados (con IVA)</dt>
                        <dd class="cifra"><?= $importe((float) $impuestos['gastos']) ?></dd>
                    </div>
                    <div class="ganancia__dato">
                        <dt>IVA por pagar</dt>
                        <dd class="cifra"><?= $importe((float) $impuestos['iva_por_pagar']) ?></dd>
                    </div>
                    <div class="ganancia__dato">
                        <dt>ISR (32 % de la utilidad)</dt>
                        <dd class="cifra"><?= $importe((float) $impuestos['isr']) ?></dd>
                    </div>
                </dl>

                <p class="panel-aviso">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                    <span>Es un estimado, no la declaración<?= escaparValores($notaImpuestos) ?>.</span>
                </p>

                <div class="panel-impuestos__acciones">
                    <a class="btn btn--texto btn--sm" href="<?= escaparValores($rutaPreliminar) ?>">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">calculate</span>
                        <span>Ver preliminar</span>
                    </a>
                    <a class="btn btn--texto btn--sm" href="/admin/fiscal?anio=<?= $anioImpuestos ?><?= $mesFiltro > 0 ? '&amp;mes=' . $mesFiltro : '' ?>">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">request_quote</span>
                        <span>Ver impuestos</span>
                    </a>
                </div>
            </article>
        </aside>
    </div>
</section>

<!-- 3. Productos más vendidos y existencias bajas -->
<section class="panel-seccion" aria-labelledby="titulo-mas-vendidos">
    <div class="rejilla-resumen panel-resumen">
        <div class="panel-columna">
            <div class="panel-seccion__cabecera panel-seccion__cabecera--compacta">
                <div class="panel-seccion__texto">
                    <h2 class="panel-seccion__titulo" id="titulo-mas-vendidos">
                        <span class="material-symbols-outlined" aria-hidden="true">sell</span>
                        Productos más vendidos
                    </h2>
                    <p class="panel-seccion__sub"><?= escaparValores($etiquetaMes) ?> · importe de renglones sin IVA</p>
                </div>
                <a class="btn btn--texto btn--sm" href="/admin/estadisticas?tipo=mes&indice=<?= $mesTop ?>&anio=<?= $anioTop ?>">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">monitoring</span>
                    <span>Ver en estadísticas</span>
                </a>
            </div>

            <?php if (!$masVendidos) : ?>
                <div class="tarjeta"><?= $vacio('No hay ventas de productos registradas en ' . escaparValores($etiquetaMes) . '.') ?></div>
            <?php else : ?>
                <div class="panel-productos">
                    <?php foreach ($masVendidos as $p) : ?>
                        <a class="stat stat--enlace stat--producto" href="/admin/productos/editar?id=<?= (int) $p['producto_id'] ?>">
                            <span class="stat__producto">
                                <?= $miniatura($p) ?>
                                <span class="stat__titulos">
                                    <span class="stat__codigo cifra"><?= escaparValores($codigo($p)) ?></span>
                                    <span class="stat__nombre"><?= escaparValores($p['nombre']) ?></span>
                                </span>
                            </span>
                            <span class="stat__valor cifra"><?= formatoMoneda($p['importe']) ?></span>
                            <span class="stat__pie"><?= $plural($p['piezas'], 'pieza', 'piezas') ?> en <?= $plural($p['ventas'], 'venta', 'ventas') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <aside class="panel-lateral" aria-labelledby="titulo-stock-bajo">
            <div class="panel-seccion__cabecera panel-seccion__cabecera--compacta">
                <div class="panel-seccion__texto">
                    <h2 class="panel-seccion__titulo" id="titulo-stock-bajo">
                        <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                        Existencias bajas
                    </h2>
                    <p class="panel-seccion__sub"><?= $stockTotal > 0 ? number_format($stockTotal) . ' productos requieren reposición' : 'Todo por encima del mínimo' ?></p>
                </div>
                <a class="btn btn--texto btn--sm" href="/admin/inventario">
                    <span>Ver inventario<?= $stockTotal > 0 ? ' (' . number_format($stockTotal) . ')' : '' ?></span>
                </a>
            </div>

            <?php if (!$stockBajo) : ?>
                <div class="tarjeta"><?= $vacio('Ningún producto está agotado, con existencia negativa ni por debajo de su cantidad mínima.') ?></div>
            <?php else : ?>
                <div class="panel-stock">
                    <?php foreach ($stockBajo as $p) :
                        $textoEstado = ['negativo' => 'Existencia negativa', 'agotado' => 'Agotado', 'bajo' => 'Bajo el mínimo'][$p['estado']] ?? '';
                        $buscar = $p['sku'] !== '' ? $p['sku'] : $p['nombre']; ?>
                        <a class="stat stat--enlace stat--producto<?= $p['estado'] === 'bajo' ? ' stat--pocas-existencias' : ' stat--sin-existencias' ?>"
                           href="/admin/inventario?q=<?= escaparValores(urlencode($buscar)) ?>">
                            <span class="stat__producto">
                                <?= $miniatura($p) ?>
                                <span class="stat__titulos">
                                    <span class="stat__codigo cifra"><?= escaparValores($codigo($p)) ?></span>
                                    <span class="stat__nombre"><?= escaparValores($p['nombre']) ?></span>
                                </span>
                            </span>
                            <span class="stat__valor cifra"><?= number_format($p['stock']) ?></span>
                            <span class="stat__pie"><?= escaparValores($textoEstado) ?><?= $p['stock_minimo'] !== null ? ' · mínimo ' . number_format($p['stock_minimo']) : '' ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($stockTotal > count($stockBajo)) : ?>
                    <p class="texto-secundario mt-sm">Se muestran <?= count($stockBajo) ?> de <?= number_format($stockTotal) ?>; primero los más urgentes.</p>
                <?php endif; ?>
            <?php endif; ?>
        </aside>
    </div>
</section>

<!-- 4. Últimas cotizaciones y últimas ventas, con su PDF -->
<section class="panel-seccion" aria-labelledby="titulo-actividad">
    <div class="panel-seccion__cabecera panel-seccion__cabecera--compacta">
        <div class="panel-seccion__texto">
            <h2 class="panel-seccion__titulo" id="titulo-actividad">
                <span class="material-symbols-outlined" aria-hidden="true">history</span>
                Últimos documentos
            </h2>
            <p class="panel-seccion__sub">Las cotizaciones y ventas más recientes, con su PDF a un clic.</p>
        </div>
    </div>

    <div class="rejilla-2 panel-documentos">
        <article class="tarjeta tarjeta--panel">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Últimas cotizaciones</h3>
                <a class="btn btn--texto btn--sm" href="/admin/cotizaciones">Ver todas</a>
            </div>
            <?php if (!$ultimasCotizaciones) : ?><?= $vacio('Todavía no hay cotizaciones.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($ultimasCotizaciones as $f) :
                        $estado = \App\Cotizaciones::datosEstado($f['estado']); ?>
                        <li class="actividad__item">
                            <a class="actividad__fila" href="/admin/cotizaciones/detalle?id=<?= (int) $f['id'] ?>">
                                <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                    <span class="actividad__detalle"><?= escaparValores($f['cliente']) ?> · <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?>
                                        · <span class="badge <?= $estado['clase'] ?>"><?= escaparValores($estado['etiqueta']) ?></span></span></span>
                                <span class="actividad__importe"><?= formatoMoneda((float) $f['total']) ?></span>
                            </a>
                            <a class="btn btn--texto btn--sm" href="/admin/cotizaciones/pdf?id=<?= (int) $f['id'] ?>"
                               title="Descargar la <?= escaparValores($f['folio']) ?> en PDF"
                               aria-label="Descargar la cotización <?= escaparValores($f['folio']) ?> en PDF">
                                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
                                <span class="movil-oculto">PDF</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="tarjeta tarjeta--panel">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Últimas ventas</h3>
                <a class="btn btn--texto btn--sm" href="/admin/ventas">Ver todas</a>
            </div>
            <?php if (!$ultimasVentas) : ?><?= $vacio('Todavía no hay ventas.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($ultimasVentas as $f) : ?>
                        <li class="actividad__item">
                            <a class="actividad__fila" href="/admin/ventas/detalle?id=<?= (int) $f['id'] ?>">
                                <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                    <span class="actividad__detalle"><?= escaparValores($f['cliente']) ?> · <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?></span></span>
                                <span class="actividad__importe"><?= formatoMoneda((float) $f['total']) ?></span>
                            </a>
                            <a class="btn btn--texto btn--sm" href="/admin/ventas/pdf?id=<?= (int) $f['id'] ?>"
                               title="Descargar la <?= escaparValores($f['folio']) ?> en PDF"
                               aria-label="Descargar la venta <?= escaparValores($f['folio']) ?> en PDF">
                                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
                                <span class="movil-oculto">PDF</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </div>
</section>

<?php template('footer'); ?>
