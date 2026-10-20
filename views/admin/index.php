<?php
/**
 * views/admin/index.php — Panel principal del administrador.
 *
 * Muestra un resumen operativo: número de cotizaciones, clientes, productos
 * y empresas registradas, accesos directos a los módulos y las ganancias
 * de cada negocio por separado, con filtro por mes, semestre o año.
 */

soloAdmin();

/** Cuenta segura de registros (0 si la tabla todavía no existe). */
$conteos = [
    'cotizaciones' => contarRegistros($db, 'cotizaciones'),
    'clientes'     => contarRegistros($db, 'clientes'),
    'productos'    => contarRegistros($db, 'productos'),
    'empresas'     => contarRegistros($db, 'empresas'),
];

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

template('header', [
    'titulo'     => 'Resumen general',
    'subtitulo'  => 'Panel de administración',
    'rutaActiva' => '/admin',
]);
?>

<section class="rejilla-stats mb-lg" aria-label="Indicadores">
    <article class="stat">
        <span class="stat__etiqueta">Cotizaciones</span>
        <span class="stat__valor cifra"><?= number_format($conteos['cotizaciones']) ?></span>
        <span class="stat__pie">Emitidas hasta hoy</span>
    </article>

    <article class="stat">
        <span class="stat__etiqueta">Clientes</span>
        <span class="stat__valor cifra"><?= number_format($conteos['clientes']) ?></span>
        <span class="stat__pie">Fichas registradas</span>
    </article>

    <article class="stat">
        <span class="stat__etiqueta">Productos</span>
        <span class="stat__valor cifra"><?= number_format($conteos['productos']) ?></span>
        <span class="stat__pie">En el catálogo</span>
    </article>

    <article class="stat">
        <span class="stat__etiqueta">Empresas</span>
        <span class="stat__valor cifra"><?= number_format($conteos['empresas']) ?></span>
        <span class="stat__pie">Negocios activos</span>
    </article>
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
    <div class="flex-entre fila--envuelto mb-md">
        <h2 class="titulo-4" id="titulo-ganancias">Ganancias por negocio</h2>

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
