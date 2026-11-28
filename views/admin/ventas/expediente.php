<?php
/**
 * views/admin/ventas/expediente.php — Expediente único de una venta.
 *
 * Una sola pantalla para todo lo que pasa alrededor de una venta: el documento,
 * la entrega y su acta, las incidencias y devoluciones, la facturación y los
 * cobros, y el historial. Antes esto vivía en dos páginas (el detalle de la
 * venta y el hub de Operación Comercial) y obligaba a saltar de una a otra.
 *
 * Es NO DESTRUCTIVO: los datos y las reglas no cambian. Las rutas viejas
 * (/admin/ventas/detalle y /admin/entregas-seguimiento/venta) muestran este
 * mismo expediente, y los formularios y sus POST siguen siendo los mismos.
 *
 * Las secciones se enlazan por ancla (#resumen, #entrega, #incidencias,
 * #facturacion, #historial), así los enlaces viejos a #fotos-entrega,
 * #cambios o #incidencias siguen funcionando.
 *
 * La vista se organiza en pestañas para no recorrer una página kilométrica:
 * el resumen concentra las cifras, la entrega y sus fotos, las incidencias,
 * la facturación/cobro y el historial. La navegación y las acciones frecuentes
 * están siempre a la vista; las acciones secundarias viven en «Más acciones».
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$venta = \App\Ventas::buscarID($id);

if (!$venta) {
    paginaNoEncontrada();
}

$rutaVenta = '/admin/ventas/detalle?id=' . $id;
$errores   = [];

// POST: fecha de entrega y observaciones.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['accion'] ?? '') === 'datos') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($rutaVenta . '#entrega', 'error', 'Este formulario ya se envió; los datos no se guardaron dos veces.');
    }
    if (\App\IncidenciasEntrega::actualizarDatos($id, (string) ($_POST['fecha'] ?? ''), (string) ($_POST['observaciones'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
        redirigir($rutaVenta . '#entrega', 'exito', 'Seguimiento de entrega actualizado.');
    }
    $errores = \App\IncidenciasEntrega::getErrores();
}

// POST: ubicación manual de una entrega (enlace de Google Maps, coordenadas o dirección).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['accion'] ?? '') === 'ubicacion') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($rutaVenta . '#fotos-entrega', 'error', 'Este formulario ya se envió; la ubicación no se guardó dos veces.');
    }
    if (\App\Entregas::actualizarUbicacion(
        $id,
        (int) ($_POST['entrega_id'] ?? 0),
        (string) ($_POST['ubicacion'] ?? ''),
        (int) ($_SESSION['id'] ?? 0)
    )) {
        redirigir($rutaVenta . '#fotos-entrega', 'exito', 'Ubicación de la entrega guardada.');
    }
    $errores = \App\Entregas::getErrores();
}

// POST: subir fotos del acta a una entrega ya registrada (o sólo guardar su ubicación).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['accion'] ?? '') === 'fotos') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($rutaVenta . '#fotos-entrega', 'error', 'Este formulario ya se envió; las fotos no se guardaron dos veces.');
    }
    $fotos = \App\Archivos::listaDeSubidas($_FILES['fotos'] ?? null);
    $metadatosFotos = json_decode((string) ($_POST['fotos_meta'] ?? '[]'), true);
    if (!is_array($metadatosFotos)) {
        $metadatosFotos = [];
    }
    if (\App\Entregas::agregarFotos(
        $id,
        (int) ($_POST['entrega_id'] ?? 0),
        $fotos,
        (int) ($_SESSION['id'] ?? 0),
        $metadatosFotos,
        (string) ($_POST['ubicacion'] ?? '')
    )) {
        $conFotos = \App\Entregas::$fotosGuardadas;
        $mensaje = $conFotos > 0
            ? 'Se guardaron ' . $conFotos . ' foto' . ($conFotos === 1 ? '' : 's') . ' del acta.'
            : 'Ubicación de la entrega guardada.';
        redirigir($rutaVenta . '#fotos-entrega', 'exito', $mensaje);
    }
    $errores = \App\Entregas::getErrores();
}

$detalles = \App\Ventas::detalles($id);
$entregas = \App\Entregas::entregasDeVenta($id);
$intentosEntrega = \App\IntentosEntrega::deVenta($id);
$estado   = \App\Ventas::datosEstado($venta->estado);

/** Descuento por renglón y descuento global (con su porcentaje negociado). */
$desglose           = \App\Cotizaciones::desgloseDescuento($venta, $detalles);
$descuentoRenglones = $desglose['renglones'];
$descuentoGlobal    = $desglose['global'];
$pctGlobal          = $desglose['pct'];

$logo = trim((string) $venta->empresa_logo) !== ''
    ? \App\Imagenes::url(\App\Imagenes::logoRecortado($venta->empresa_logo))
    : '';

/** Productos de la venta que hoy tienen existencias negativas (se vendió antes de fabricar). */
$negativos = [];
foreach ($detalles as $detalle) {
    $existencias = \App\Inventario::stock((int) $detalle['producto_id']);
    if ($existencias < 0) {
        $negativos[(int) $detalle['producto_id']] = $detalle['producto_nombre'] . ' (' . $existencias . ')';
    }
}

/** Incidencias de entrega todavía abiertas. */
$incidenciasAbiertas = array_values(array_filter(
    \App\IncidenciasEntrega::deVenta($id),
    static fn (array $incidencia): bool => ($incidencia['estado'] ?? '') === 'abierta'
));
$incidenciasCorreccion = array_filter($incidenciasAbiertas, static fn (array $i): bool => !empty($i['factura_requerida']));
$motivosAbiertos = [];
foreach ($incidenciasAbiertas as $incidencia) {
    $motivosAbiertos[\App\IncidenciasEntrega::MOTIVOS[$incidencia['motivo']]['etiqueta'] ?? $incidencia['motivo']] = true;
}

$pendientes  = \App\Entregas::pendientesDeVentas([$id])[$id] ?? [];
$incidencias = \App\IncidenciasEntrega::deVenta($id);
$historial   = \App\IncidenciasEntrega::historial($id);
$ingreso     = \App\Contabilidad::ingresoDeVenta($id);
$disponibles = \App\Inventario::devolvibles($id);
$registrada  = $venta->estado === 'registrada';

$vendidas   = array_sum(array_column($pendientes, 'vendido'));
$entregadas = array_sum(array_column($pendientes, 'entregado'));
$faltan     = max(0, $vendidas - $entregadas);
$abiertas   = count($incidenciasAbiertas);

// Piezas afectadas por incidencias, por producto (para la tabla de vendido vs entregado).
$afectadas = [];
foreach ($incidencias as $incidencia) {
    if ($incidencia['estado'] === 'cancelada') {
        continue;
    }
    foreach ($incidencia['renglones'] as $r) {
        $afectadas[(int) $r['producto_id']] = ($afectadas[(int) $r['producto_id']] ?? 0) + (int) $r['cantidad'];
    }
}

$cobradoExtra = $ingreso && \App\Contabilidad::centavos($ingreso['cobrado']) > \App\Contabilidad::centavos($ingreso['total']);
$facturada    = $ingreso && $ingreso['estado'] === 'vigente' && $ingreso['estado_facturacion'] === 'facturada';
$fIva         = $ingreso ? (\App\Contabilidad::FACTURACION[$ingreso['estado_facturacion']] ?? null) : null;

$esDirecta = $venta->cotizacion_id === null;
$accion    = (string) ($_GET['accion'] ?? '');

// «Volver» conserva de dónde venía la persona (listado de ventas, preparación…).
$destinosVolver = [
    'ventas'      => ['/admin/ventas', 'Volver a ventas'],
    'preparacion' => ['/admin/entregas', 'Volver a preparación'],
    'clientes'    => ['/admin/entregas/clientes', 'Volver a entregas por cliente'],
    'inicio'      => ['/admin', 'Volver al inicio'],
];
$volver       = (string) ($_GET['volver'] ?? '');
$rutaVolver   = $destinosVolver[$volver][0] ?? '/admin/ventas';
$textoVolver  = $destinosVolver[$volver][1] ?? 'Volver a ventas';

template('header', [
    'titulo'            => $venta->folio,
    'subtitulo'         => 'Expediente de la venta',
    'rutaActiva'        => '/admin/ventas',
    'mostrarEncabezado' => false,
]);
?>

<section class="expediente" id="expediente-venta" data-expediente>
    <header class="expediente-hero">
        <a class="expediente-hero__volver" href="<?= escaparValores($rutaVolver) ?>">
            <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
            <span><?= escaparValores($textoVolver) ?></span>
        </a>

        <div class="expediente-hero__principal">
            <span class="expediente-hero__icono" aria-hidden="true">
                <span class="material-symbols-outlined">receipt_long</span>
            </span>
            <div class="expediente-hero__texto">
                <p class="expediente-hero__eyebrow">
                    Expediente de venta
                    <span class="badge <?= $estado['clase'] ?>">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $estado['icono'] ?></span>
                        <?= $estado['etiqueta'] ?>
                    </span>
                    <span class="badge badge--info"><?= $esDirecta ? 'Venta directa' : 'Desde cotización' ?></span>
                    <?php if (!empty($venta->proyecto_folio)) : ?>
                        <a class="badge badge--aprobado" href="/admin/proyectos/detalle?id=<?= (int) $venta->proyecto_id ?>"
                           title="<?= escaparValores((string) ($venta->proyecto_nombre ?? '')) ?>">
                            <?= escaparValores((string) $venta->proyecto_folio) ?>
                        </a>
                    <?php elseif ($registrada) : ?>
                        <a class="badge badge--neutro" href="/admin/proyectos/vincular?venta=<?= $id ?>">Vincular a proyecto</a>
                    <?php endif; ?>
                </p>
                <h1 class="expediente-hero__titulo"><?= escaparValores($venta->folio) ?></h1>
                <p class="expediente-hero__meta">
                    <span><span class="material-symbols-outlined" aria-hidden="true">person</span><?= escaparValores($venta->cliente_empresa ?: $venta->cliente_nombre) ?></span>
                    <span><span class="material-symbols-outlined" aria-hidden="true">storefront</span><?= escaparValores($venta->empresa_nombre) ?></span>
                    <span><span class="material-symbols-outlined" aria-hidden="true">calendar_today</span><?= date('d/m/Y', strtotime($venta->fecha)) ?></span>
                    <?php if (trim((string) $venta->usuario_nombre) !== '') : ?>
                        <span><span class="material-symbols-outlined" aria-hidden="true">badge</span><?= escaparValores($venta->usuario_nombre) ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="expediente-hero__acciones">
                <?php if ($registrada && $faltan > 0) : ?>
                    <a class="btn btn--primario" href="/admin/entregas/entregar?venta=<?= $id ?>&volver=seguimiento"
                       data-modal-form="tpl-entrega"
                       data-modal-sobre-titulo="Venta <?= escaparValores($venta->folio) ?>"
                       data-modal-titulo="Registrar entrega"
                       data-modal-confirmar="Confirmar entrega"<?= $accion === 'entrega' ? ' data-abrir-modal-al-cargar' : '' ?>>
                        <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
                        <span>Registrar entrega</span>
                    </a>
                <?php elseif ($ingreso && (float) $ingreso['saldo'] > 0 && $ingreso['estado'] === 'vigente') : ?>
                    <a class="btn btn--primario" href="#facturacion" data-abrir-tab="facturacion">
                        <span class="material-symbols-outlined" aria-hidden="true">payments</span>
                        <span>Cobrar saldo</span>
                    </a>
                <?php elseif ($ingreso && $ingreso['estado_facturacion'] !== 'facturada' && $ingreso['estado'] === 'vigente') : ?>
                    <a class="btn btn--primario" href="#facturacion" data-abrir-tab="facturacion">
                        <span class="material-symbols-outlined" aria-hidden="true">fact_check</span>
                        <span>Facturar venta</span>
                    </a>
                <?php else : ?>
                    <a class="btn btn--primario" href="/admin/ventas/pdf?id=<?= $id ?>">
                        <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>
                        <span>PDF de la venta</span>
                    </a>
                <?php endif; ?>

                <details class="expediente-menu">
                    <summary class="btn btn--secundario">
                        <span class="material-symbols-outlined" aria-hidden="true">more_horiz</span>
                        <span>Más acciones</span>
                    </summary>
                    <div class="expediente-menu__panel">
                        <span class="expediente-menu__rotulo">Documento</span>
                        <?php if ($esDirecta) : ?>
                            <a href="/admin/ventas/editar?id=<?= $id ?>">
                                <span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar venta
                            </a>
                        <?php else : ?>
                            <a href="/admin/cotizaciones/detalle?id=<?= (int) $venta->cotizacion_id ?>">
                                <span class="material-symbols-outlined" aria-hidden="true">request_quote</span> Ver cotización
                            </a>
                        <?php endif; ?>
                        <a href="/admin/ventas/pdf?id=<?= $id ?>">
                            <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span> PDF de la venta
                        </a>

                        <?php if ($entregas || $registrada) : ?>
                            <span class="expediente-menu__rotulo">Entrega</span>
                        <?php endif; ?>
                        <?php if ($entregas) : ?>
                            <a href="/admin/entregas-seguimiento/acta?id=<?= $id ?>">
                                <span class="material-symbols-outlined" aria-hidden="true">description</span> Acta de entrega (PDF)
                            </a>
                        <?php endif; ?>
                        <?php if ($registrada) : ?>
                            <a href="/admin/entregas-seguimiento/incidencia?id=<?= $id ?>"
                               data-modal-form="tpl-incidencia"
                               data-modal-sobre-titulo="Venta <?= escaparValores($venta->folio) ?>"
                               data-modal-titulo="Registrar incidencia"
                               data-modal-confirmar="Registrar incidencia"<?= $accion === 'incidencia' ? ' data-abrir-modal-al-cargar' : '' ?>>
                                <span class="material-symbols-outlined" aria-hidden="true">report</span> Registrar incidencia
                            </a>
                            <a href="/admin/ventas/devolucion?id=<?= $id ?>"
                               data-modal-form="tpl-devolucion"
                               data-modal-sobre-titulo="Devolución de la venta"
                               data-modal-titulo="Registrar devolución"
                               data-modal-confirmar="Registrar devolución"<?= $accion === 'devolucion' ? ' data-abrir-modal-al-cargar' : '' ?>>
                                <span class="material-symbols-outlined" aria-hidden="true">assignment_return</span> Registrar devolución
                            </a>
                        <?php endif; ?>

                        <span class="expediente-menu__rotulo">Facturación</span>
                        <a href="#facturacion" data-abrir-tab="facturacion">
                            <span class="material-symbols-outlined" aria-hidden="true">fact_check</span>
                            <?= $facturada ? 'Ver facturación y cobros' : 'Facturar o cobrar' ?>
                        </a>
                        <?php if ($registrada) : ?>
                            <a href="#eliminar-venta" data-abrir-tab="resumen" class="expediente-menu__peligro">
                                <span class="material-symbols-outlined" aria-hidden="true">delete</span> Eliminar venta
                            </a>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        </div>
    </header>

    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i2 => $e) : ?><?= $i2 ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <?php if ($venta->estado === 'cancelada') : ?>
        <p class="nota nota--advertencia mb-lg" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">block</span>
            <span>Venta cancelada: el inventario ya recuperó sus piezas y no cuenta en las ganancias.</span>
        </p>
    <?php elseif (!empty($negativos)) : ?>
        <p class="nota nota--advertencia mb-lg" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">inventory</span>
            <span>
                <strong>Existencias en negativo.</strong>
                Se vendió material que aún no está en almacén: <?= escaparValores(implode('; ', $negativos)) ?>.
                <a href="/admin/inventario">Cargar inventario</a>
            </span>
        </p>
    <?php endif; ?>

    <?php if ($incidenciasAbiertas) : $abiertasTexto = count($incidenciasAbiertas); ?>
        <p class="nota <?= $incidenciasCorreccion ? 'nota--error' : 'nota--advertencia' ?> mb-lg" <?= $incidenciasCorreccion ? 'role="alert"' : 'role="status"' ?>>
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">report</span>
            <span>
                <strong><?= $abiertasTexto ?> incidencia<?= $abiertasTexto === 1 ? '' : 's' ?> de entrega abierta<?= $abiertasTexto === 1 ? '' : 's' ?></strong>
                <?php if ($motivosAbiertos) : ?>(<?= escaparValores(implode(' · ', array_keys($motivosAbiertos))) ?>)<?php endif; ?><?= $incidenciasCorreccion ? ' · alguna exige corregir la factura' : '' ?>.
                <a href="#incidencias" data-abrir-tab="incidencias">Revisar incidencias</a>
            </span>
        </p>
    <?php endif; ?>

    <section class="expediente-kpis" aria-label="Resumen de la venta">
        <a class="expediente-kpi" href="#resumen" data-abrir-tab="resumen">
            <span class="expediente-kpi__icono" aria-hidden="true"><span class="material-symbols-outlined">receipt_long</span></span>
            <span class="expediente-kpi__texto">
                <span class="expediente-kpi__etiqueta">Total de la venta</span>
                <strong class="expediente-kpi__valor"><?= formatoMoneda($venta->total) ?></strong>
                <span class="expediente-kpi__pie"><?= (int) $venta->aplica_iva === 1 ? 'IVA incluido' : 'Sin IVA' ?> · <?= count($detalles) ?> partida<?= count($detalles) === 1 ? '' : 's' ?></span>
            </span>
        </a>

        <a class="expediente-kpi" href="#facturacion" data-abrir-tab="facturacion">
            <span class="expediente-kpi__icono" aria-hidden="true"><span class="material-symbols-outlined">payments</span></span>
            <span class="expediente-kpi__texto">
                <span class="expediente-kpi__etiqueta">Cobrado</span>
                <strong class="expediente-kpi__valor"><?= $ingreso ? formatoMoneda($ingreso['cobrado']) : '—' ?></strong>
                <span class="expediente-kpi__pie"><?= $ingreso ? \App\Contabilidad::etiquetaCobro($ingreso['estado_cobro'])['etiqueta'] : 'Sin ingreso contable' ?></span>
            </span>
        </a>

        <a class="expediente-kpi" href="#facturacion" data-abrir-tab="facturacion">
            <span class="expediente-kpi__icono" aria-hidden="true"><span class="material-symbols-outlined">account_balance_wallet</span></span>
            <span class="expediente-kpi__texto">
                <span class="expediente-kpi__etiqueta">Saldo pendiente</span>
                <strong class="expediente-kpi__valor"><?= $ingreso ? formatoMoneda($ingreso['saldo']) : '—' ?></strong>
                <span class="expediente-kpi__pie"><?= $fIva ? escaparValores($fIva['etiqueta']) : 'Sin facturación' ?></span>
            </span>
        </a>

        <a class="expediente-kpi" href="#entrega" data-abrir-tab="entrega">
            <span class="expediente-kpi__icono" aria-hidden="true"><span class="material-symbols-outlined">local_shipping</span></span>
            <span class="expediente-kpi__texto">
                <span class="expediente-kpi__etiqueta">Entrega</span>
                <strong class="expediente-kpi__valor"><?= $entregadas ?> <small>de <?= $vendidas ?> piezas</small></strong>
                <span class="expediente-kpi__pie"><?= $faltan > 0 ? 'Faltan ' . $faltan . ' por entregar' : 'Entrega completa' ?></span>
            </span>
        </a>
    </section>

    <nav class="expediente-nav" data-expediente-nav role="tablist" aria-label="Secciones del expediente">
        <a class="expediente-nav__tab is-activo" id="tab-resumen" href="#resumen" role="tab" data-expediente-tab="resumen" aria-controls="panel-resumen" aria-selected="true">
            <span class="material-symbols-outlined" aria-hidden="true">description</span><span>Resumen</span>
        </a>
        <a class="expediente-nav__tab" id="tab-entrega" href="#entrega" role="tab" data-expediente-tab="entrega" aria-controls="panel-entrega" aria-selected="false" tabindex="-1">
            <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span><span>Entrega</span>
            <span class="badge <?= $faltan > 0 ? 'badge--pendiente' : 'badge--aprobado' ?>"><?= $entregadas ?>/<?= $vendidas ?></span>
        </a>
        <a class="expediente-nav__tab" id="tab-incidencias" href="#incidencias" role="tab" data-expediente-tab="incidencias" aria-controls="panel-incidencias" aria-selected="false" tabindex="-1">
            <span class="material-symbols-outlined" aria-hidden="true">report</span><span>Incidencias</span>
            <span class="badge <?= $abiertas > 0 ? 'badge--error' : 'badge--aprobado' ?>"><?= $abiertas ?></span>
        </a>
        <a class="expediente-nav__tab" id="tab-facturacion" href="#facturacion" role="tab" data-expediente-tab="facturacion" aria-controls="panel-facturacion" aria-selected="false" tabindex="-1">
            <span class="material-symbols-outlined" aria-hidden="true">fact_check</span><span>Facturación<span class="expediente-nav__extra"> y cobro</span></span>
            <?php if ($fIva) : ?><span class="badge <?= $fIva['clase'] ?>"><?= escaparValores($fIva['etiqueta']) ?></span><?php endif; ?>
        </a>
        <a class="expediente-nav__tab" id="tab-historial" href="#historial" role="tab" data-expediente-tab="historial" aria-controls="panel-historial" aria-selected="false" tabindex="-1">
            <span class="material-symbols-outlined" aria-hidden="true">history</span><span>Historial</span>
            <?php if ($historial) : ?><span class="badge badge--neutro"><?= count($historial) ?></span><?php endif; ?>
        </a>
    </nav>

    <div class="expediente-paneles">
        <div class="expediente-panel" id="panel-resumen" data-expediente-panel="resumen" role="tabpanel" aria-labelledby="tab-resumen">
            <?php
            template('venta/documento', [
                'cotizacion'          => $venta,
                'detalles'            => $detalles,
                'descuentoRenglones'  => $descuentoRenglones,
                'descuentoGlobal'     => $descuentoGlobal,
                'pctGlobal'           => $pctGlobal,
                'logo'                => $logo,
                'id'                  => $id,
            ]);
            template('venta/eliminar', ['cotizacion' => $venta, 'id' => $id]);
            ?>
        </div>

        <div class="expediente-panel" id="panel-entrega" data-expediente-panel="entrega" role="tabpanel" aria-labelledby="tab-entrega" hidden>
            <?php
            template('venta/entrega', [
                'id'          => $id,
                'venta'       => $venta,
                'pendientes'  => $pendientes,
                'entregas'    => $entregas,
                'intentos'    => $intentosEntrega,
                'afectadas'   => $afectadas,
                'vendidas'    => $vendidas,
                'entregadas'  => $entregadas,
                'faltan'      => $faltan,
                'registrada'  => $registrada,
                'rutaVenta'   => $rutaVenta,
            ]);
            ?>
        </div>

        <div class="expediente-panel" id="panel-incidencias" data-expediente-panel="incidencias" role="tabpanel" aria-labelledby="tab-incidencias" hidden>
            <?php
            template('venta/incidencias', [
                'id'          => $id,
                'venta'       => $venta,
                'incidencias' => $incidencias,
                'ingreso'     => $ingreso,
                'registrada'  => $registrada,
                'cobradoExtra' => $cobradoExtra,
                'rutaVenta'   => $rutaVenta,
            ]);
            ?>
        </div>

        <div class="expediente-panel" id="panel-facturacion" data-expediente-panel="facturacion" role="tabpanel" aria-labelledby="tab-facturacion" hidden>
            <?php
            template('venta/facturacion', [
                'ingreso'   => $ingreso,
                'rutaVenta' => $rutaVenta,
            ]);
            ?>
        </div>

        <div class="expediente-panel" id="panel-historial" data-expediente-panel="historial" role="tabpanel" aria-labelledby="tab-historial" hidden>
            <?php template('venta/historial', ['historial' => $historial]); ?>
        </div>
    </div>
</section>

<!-- Formularios de las ventanas modales: los mismos de las páginas sueltas. -->
<?php if ($registrada) : ?>
    <template id="tpl-entrega">
        <?php template('formEntregaVenta', [
            'ventaId' => $id,
            'lineas'  => array_values($pendientes),
            'token'   => tokenFormulario(),
            'volver'  => 'seguimiento',
        ]); ?>
    </template>

    <template id="tpl-incidencia">
        <?php template('formIncidenciaVenta', [
            'id'          => $id,
            'pendientes'  => $pendientes,
            'disponibles' => $disponibles,
            'facturada'   => $facturada,
            'ingreso'     => $ingreso,
            'token'       => tokenFormulario(),
        ]); ?>
    </template>

    <template id="tpl-devolucion">
        <?php template('formDevolucionVenta', [
            'ventaId'     => $id,
            'devolvibles' => $disponibles,
            'detalles'    => \App\Ventas::detalles($id),
            'token'       => tokenFormulario(),
            'volver'      => 'seguimiento',
        ]); ?>
    </template>
<?php endif; ?>

<script>
/* Evita el doble clic: tras el primer envío el botón se desactiva. */
document.querySelectorAll('form').forEach(function (f) {
  f.addEventListener('submit', function () { const b = f.querySelector('[data-un-envio]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
});
</script>

<?php template('footer'); ?>
