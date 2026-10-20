<?php
/**
 * views/admin/index.php — Panel principal del administrador.
 *
 * Centro de control operativo: indicadores enlazados a los registros que los
 * generan, listados de actividad, accesos directos y ganancias de cada negocio,
 * con filtro por mes, semestre o año.
 */

soloAdmin();

/** Accesos rápidos a los módulos del panel. */
$accesos = [
    ['ruta' => '/admin/cotizaciones/crear',         'icono' => 'note_add',      'titulo' => 'Hacer una cotización', 'texto' => 'Para darle un precio a un cliente.'],
    ['ruta' => '/admin/ventas/crear',               'icono' => 'point_of_sale', 'titulo' => 'Anotar una venta',     'texto' => 'Cuando el cliente ya compró.'],
    ['ruta' => '/admin/contabilidad/gastos/crear',  'icono' => 'receipt',       'titulo' => 'Anotar un gasto',      'texto' => 'Material, gasolina, renta… y sube su factura.'],
    ['ruta' => '/admin/entregas',                   'icono' => 'package_2',     'titulo' => 'Entregar pedidos',     'texto' => 'Lo vendido que falta por entregar.'],
    ['ruta' => '/admin/contabilidad',               'icono' => 'account_balance', 'titulo' => '¿Cómo voy este mes?', 'texto' => 'Vendiste, gastaste y qué te falta hacer.'],
    ['ruta' => '/admin/fiscal',                     'icono' => 'request_quote', 'titulo' => '¿Cuánto separo de impuestos?', 'texto' => 'Una cifra aproximada del mes.'],
    ['ruta' => '/admin/clientes',                   'icono' => 'group',         'titulo' => 'Mis clientes',         'texto' => 'Directorio y su historial.'],
    ['ruta' => '/admin/ayuda',                      'icono' => 'help',          'titulo' => 'Ayuda y manual',       'texto' => 'Cómo hacer cada cosa paso a paso.'],
];

/* ---------------------------------------------------------------------------
 * GANANCIAS POR NEGOCIO
 *
 * El periodo llega por query string: ?anio=2026&semestre=1&mes=10. La
 * prioridad la resuelve Cotizaciones::rangoPeriodo() (mes > semestre > año);
 * el año en curso es el valor por defecto y "Todos los años" deja el periodo
 * abierto sobre el histórico completo.
 * ------------------------------------------------------------------------- */

$anioFiltro    = isset($_GET['anio']) ? (int) $_GET['anio'] : (int) date('Y');
$semestreFiltro = (int) ($_GET['semestre'] ?? 0);
$mesFiltro     = (int) ($_GET['mes'] ?? 0);

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

/** Los dos negocios que emiten cotizaciones (los activos del panel). */
$negocios = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static function ($empresa): bool {
        return (int) $empresa->activa === 1;
    }
));

$ganancias = [];
foreach ($negocios as $negocio) {
    $ganancias[(int) $negocio->id] = \App\Cotizaciones::ganancias(
        (int) $negocio->id,
        $periodo['desde'],
        $periodo['hasta']
    );
}

$usuarioId   = (int) ($_SESSION['id'] ?? 0);
$indicadores = \App\Panel::indicadores($periodo['desde'], $periodo['hasta'], $usuarioId);

/** Tarjetas: clave => [etiqueta, icono, texto del pie, ¿llama la atención si es mayor que cero?]. */
$tarjetas = [
    'ventas'         => ['Ventas del periodo', 'point_of_sale', 'ventas registradas', false],
    'cotizaciones'   => ['Cotizaciones por dar seguimiento', 'request_quote', 'pendientes de respuesta', true],
    'cobrar'         => ['Ventas por cobrar', 'payments', 'ventas con saldo por cobrar', true],
    'facturar'       => ['Pendientes de facturar', 'pending_actions', 'ventas sin indicar si se facturan (todas las fechas)', true],
    'entregas'       => ['Entregas pendientes', 'package_2', 'ventas con piezas por entregar', true],
    'devoluciones'   => ['Devoluciones del periodo', 'assignment_return', 'ventas con piezas devueltas', false],
    'gastos'         => ['Gastos del periodo', 'receipt', 'gastos registrados', false],
    'notificaciones' => ['Notificaciones sin leer', 'notifications', 'avisos nuevos', true],
];
$sinImporte = ['entregas', 'devoluciones', 'notificaciones'];

$ultimasCotizaciones = \App\Panel::ultimasCotizaciones();
$ultimasVentas = \App\Panel::ultimasVentas();
$porCobrar     = \App\Panel::ventasPorCobrar();
$porFacturar   = \App\Panel::ventasPorFacturar();
$convertidas   = \App\Panel::convertidasSinFactura();
$nConvertidas  = \App\Panel::contarConvertidasSinFactura();
$entregas      = \App\Panel::entregasPendientes();
$devoluciones  = \App\Panel::devolucionesRecientes();

template('header', [
    'titulo'     => 'Resumen general',
    'subtitulo'  => 'Panel de administración',
    'rutaActiva' => '/admin',
]);
?>

<section class="mb-lg" aria-label="Periodo">
    <div class="flex-entre fila--envuelto">
        <svg class="panel-marca" viewBox="0 0 64 64" role="img" aria-label="Acero 360"><defs><linearGradient id="pg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4679a6"/><stop offset="1" stop-color="#2b4d6e"/></linearGradient></defs><rect width="64" height="64" rx="14" fill="url(#pg)"/><g class="a360-anillo"><path d="M52.67 24.48A22 22 0 1 1 39.52 11.33" fill="none" stroke="#fff" stroke-width="4"/><path d="M41.06 7.1 45.1 13.4 37.98 15.56z" fill="#F2A56B" stroke="#F2A56B" stroke-width="1.5" stroke-linejoin="round"/></g><g class="a360-letra" fill="none" stroke="#fff" stroke-width="5"><path d="M23 44 32 20l9 24"/><path d="M26.5 36.5h11" stroke="#F2A56B" stroke-width="4"/></g></svg>
        <p class="texto-secundario">Periodo: <strong><?= escaparValores($periodo['etiqueta']) ?></strong>.
            Ventas, devoluciones y gastos usan este periodo; lo pendiente se cuenta completo, sin importar la fecha.</p>
    <form class="buscador" action="/admin" method="get" role="search" data-validar>
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
            <a class="btn btn--texto btn--sm" href="/admin"
               aria-label="Restablecer el periodo">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                <span>Limpiar</span>
            </a>
        <?php endif; ?>
    </form>
    </div>
</section>

<section class="rejilla-stats mb-lg" aria-label="Indicadores">
    <?php foreach ($tarjetas as $clave => [$etiqueta, $icono, $pie, $alerta]) :
        $dato = $indicadores[$clave];
        $principal = in_array($clave, $sinImporte, true) ? number_format($dato['n']) : formatoMoneda((float) $dato['monto']);
        $detalle = in_array($clave, $sinImporte, true) ? '' : number_format($dato['n']) . ' ';
        ?>
        <a class="stat stat--enlace<?= $alerta && $dato['n'] > 0 ? ' stat--alerta' : '' ?>" href="<?= escaparValores($dato['enlace']) ?>">
            <span class="stat__etiqueta">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem;vertical-align:-0.2em"><?= $icono ?></span>
                <?= escaparValores($etiqueta) ?>
            </span>
            <span class="stat__valor cifra"><?= $principal ?></span>
            <span class="stat__pie"><?= $detalle . escaparValores($pie) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<?php
$vacio = static function (string $texto): string {
    return '<p class="texto-secundario">' . $texto . '</p>';
};
?>
<section class="mb-lg" aria-labelledby="titulo-actividad">
    <h2 class="titulo-4 mb-md" id="titulo-actividad">Actividad</h2>

    <div class="rejilla-2">
        <article class="tarjeta">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Últimas cotizaciones</h3>
                <a class="btn btn--texto btn--sm" href="/admin/cotizaciones">Ver todas</a>
            </div>
            <?php if (!$ultimasCotizaciones) : ?><?= $vacio('Todavía no hay cotizaciones.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($ultimasCotizaciones as $f) :
                        $estado = \App\Cotizaciones::datosEstado($f['estado']); ?>
                        <li><a class="actividad__fila" href="/admin/cotizaciones/detalle?id=<?= (int) $f['id'] ?>">
                            <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($f['cliente']) ?> · <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?>
                                    · <span class="badge <?= $estado['clase'] ?>"><?= escaparValores($estado['etiqueta']) ?></span></span></span>
                            <span class="actividad__importe"><?= formatoMoneda((float) $f['total']) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="tarjeta">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Últimas ventas</h3>
                <a class="btn btn--texto btn--sm" href="/admin/ventas">Ver todas</a>
            </div>
            <?php if (!$ultimasVentas) : ?><?= $vacio('Todavía no hay ventas.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($ultimasVentas as $f) : ?>
                        <li><a class="actividad__fila" href="/admin/ventas/detalle?id=<?= (int) $f['id'] ?>">
                            <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($f['cliente']) ?> · <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?></span></span>
                            <span class="actividad__importe"><?= formatoMoneda((float) $f['total']) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="tarjeta">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Ventas pendientes de cobro</h3>
                <a class="btn btn--texto btn--sm" href="<?= escaparValores($indicadores['cobrar']['enlace']) ?>">Ver todas (<?= $indicadores['cobrar']['n'] ?>)</a>
            </div>
            <?php if (!$porCobrar) : ?><?= $vacio('No hay ventas por cobrar.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($porCobrar as $f) : ?>
                        <li><a class="actividad__fila" href="/admin/contabilidad/ingresos/detalle?id=<?= (int) $f['id'] ?>">
                            <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($f['cliente']) ?> · venta del <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?></span></span>
                            <span class="actividad__importe"><?= formatoMoneda((float) $f['saldo']) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="tarjeta">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Ventas pendientes de facturación</h3>
                <a class="btn btn--texto btn--sm" href="<?= escaparValores($indicadores['facturar']['enlace']) ?>">Ver todas (<?= $indicadores['facturar']['n'] ?>)</a>
            </div>
            <?php if (!$porFacturar) : ?><?= $vacio('No hay ventas por definir en facturación.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($porFacturar as $f) : ?>
                        <li><a class="actividad__fila" href="/admin/contabilidad/ingresos/detalle?id=<?= (int) $f['id'] ?>">
                            <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($f['cliente']) ?> · venta del <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?></span></span>
                            <span class="actividad__importe"><?= formatoMoneda((float) $f['total']) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="tarjeta">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Cotizaciones vendidas sin factura emitida</h3>
                <a class="btn btn--texto btn--sm" href="/admin/contabilidad/ingresos?todos=1">Ver ingresos (<?= $nConvertidas ?>)</a>
            </div>
            <?php if (!$convertidas) : ?><?= $vacio('Todas las cotizaciones vendidas ya tienen factura.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($convertidas as $f) :
                        $fact = \App\Contabilidad::FACTURACION[$f['estado_facturacion']] ?? ['etiqueta' => $f['estado_facturacion'], 'clase' => 'badge--neutro']; ?>
                        <li><a class="actividad__fila" href="/admin/contabilidad/ingresos/detalle?id=<?= (int) $f['id'] ?>">
                            <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($f['cliente']) ?> · <span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span></span></span>
                            <span class="actividad__importe"><?= formatoMoneda((float) $f['total']) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="tarjeta">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Entregas pendientes</h3>
                <a class="btn btn--texto btn--sm" href="/admin/entregas">Ver todas (<?= $indicadores['entregas']['n'] ?>)</a>
            </div>
            <?php if (!$entregas) : ?><?= $vacio('No hay entregas pendientes.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($entregas as $f) :
                        $faltan = (int) $f['piezas'] - (int) $f['entregadas']; ?>
                        <li><a class="actividad__fila" href="/admin/ventas/detalle?id=<?= (int) $f['id'] ?>">
                            <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($f['cliente_nombre']) ?> · venta del <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?></span></span>
                            <span class="actividad__importe">faltan <?= $faltan ?> pza<?= $faltan === 1 ? '' : 's' ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="tarjeta">
            <div class="tarjeta__cabecera">
                <h3 class="tarjeta__titulo">Devoluciones recientes</h3>
                <a class="btn btn--texto btn--sm" href="/admin/inventario/movimientos?motivo=devolucion">Ver todas</a>
            </div>
            <?php if (!$devoluciones) : ?><?= $vacio('No hay devoluciones registradas.') ?><?php else : ?>
                <ul class="actividad">
                    <?php foreach ($devoluciones as $f) : ?>
                        <li><a class="actividad__fila" href="/admin/ventas/detalle?id=<?= (int) $f['venta_id'] ?>">
                            <span><span class="actividad__titulo"><?= escaparValores($f['folio']) ?></span>
                                <span class="actividad__detalle"><?= escaparValores($f['cliente']) ?> · <?= escaparValores(date('d/m/Y', strtotime($f['fecha']))) ?></span></span>
                            <span class="actividad__importe"><?= (int) $f['piezas'] ?> pza<?= (int) $f['piezas'] === 1 ? '' : 's' ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="texto-secundario mt-md">Los cambios de producto aún no se registran por separado.</p>
        </article>
    </div>
</section>

<section class="mb-lg" aria-labelledby="titulo-accesos">
    <h2 class="titulo-4 mb-md" id="titulo-accesos">¿Qué quieres hacer hoy?</h2>

    <div class="rejilla-2">
        <?php foreach ($accesos as $acceso) : ?>
            <a class="tarjeta tarjeta--enlace" href="<?= escaparValores($acceso['ruta']) ?>">
                <div class="tarjeta__cabecera">
                    <h3 class="tarjeta__titulo">
                        <span class="material-symbols-outlined" aria-hidden="true"><?= escaparValores($acceso['icono']) ?></span>
                        <?= escaparValores($acceso['titulo']) ?>
                    </h3>
                    <span class="material-symbols-outlined texto-secundario" aria-hidden="true">arrow_forward</span>
                </div>
                <p class="tarjeta__descripcion"><?= escaparValores($acceso['texto']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-lg" aria-labelledby="titulo-ganancias">
    <h2 class="titulo-4 mb-md" id="titulo-ganancias">Ganancias por negocio</h2>

    <p class="texto-secundario mb-md">
        Periodo contabilizado: <strong><?= escaparValores($periodo['etiqueta']) ?></strong>.
        Cuentan como ganancia las cotizaciones en estado <strong>Vendido</strong>
        y las ventas directas registradas.
    </p>

    <?php if (!$negocios) : ?>
        <p class="texto-secundario">Todavía no hay negocios activos para mostrar sus ganancias.</p>
    <?php else : ?>
        <div class="rejilla-2">
            <?php foreach ($negocios as $negocio) :
                $resumen = $ganancias[(int) $negocio->id];
                $logo = trim((string) $negocio->logo) !== ''
                    ? \App\Imagenes::url($negocio->logo)
                    : '';
                $pluralVendidas = $resumen['vendidas'] === 1 ? 'cotización vendida' : 'cotizaciones vendidas';
                $pluralPendientes = $resumen['pendientes'] === 1 ? 'cotización pendiente' : 'cotizaciones pendientes';
                ?>
                <article class="tarjeta ganancia">
                    <div class="tarjeta__cabecera">
                        <h3 class="tarjeta__titulo">
                            <?php if ($logo !== '') : ?>
                                <img class="ganancia__logo" src="<?= escaparValores($logo) ?>" alt="">
                            <?php endif; ?>
                            <span><?= escaparValores($negocio->nombre) ?></span>
                        </h3>
                        <span class="badge badge--info"><?= escaparValores($periodo['etiqueta']) ?></span>
                    </div>

                    <span class="ganancia__etiqueta">Ganancias</span>
                    <strong class="ganancia__valor cifra"><?= formatoMoneda($resumen['ganancias']) ?></strong>
                    <span class="ganancia__nota">
                        <?= number_format($resumen['vendidas']) ?> <?= $pluralVendidas ?> ·
                        <?= number_format($resumen['directas']) ?> <?= $resumen['directas'] === 1 ? 'venta directa' : 'ventas directas' ?> ·
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
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
