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

$definicion = \App\IncidenciasEntrega::ESTADOS_ENTREGA[$venta->entrega_estado] ?? null;
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

<section class="encabezado-pagina">
    <div>
        <h1 class="titulo-2">
            <?= escaparValores($venta->folio) ?>
            <span class="badge <?= $estado['clase'] ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $estado['icono'] ?></span>
                <?= $estado['etiqueta'] ?>
            </span>
            <span class="badge badge--info"><?= $esDirecta ? 'Venta directa' : 'Desde cotización' ?></span>
        </h1>
        <p class="texto-secundario">
            <?= escaparValores($venta->cliente_empresa ?: $venta->cliente_nombre) ?> ·
            <?= escaparValores($venta->empresa_nombre) ?> ·
            venta del <?= date('d/m/Y', strtotime($venta->fecha)) ?> ·
            <strong class="cifra"><?= escaparValores('$' . number_format((float) $venta->total, 2)) ?></strong>
            <?php if (trim((string) $venta->usuario_nombre) !== '') : ?>
                · por <?= escaparValores($venta->usuario_nombre) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="<?= escaparValores($rutaVolver) ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>
            <span><?= escaparValores($textoVolver) ?></span>
        </a>
        <?php if ($esDirecta) : ?>
            <a class="btn btn--secundario" href="/admin/ventas/editar?id=<?= $id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                <span>Editar</span>
            </a>
        <?php else : ?>
            <a class="btn btn--secundario" href="/admin/cotizaciones/detalle?id=<?= (int) $venta->cotizacion_id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">request_quote</span>
                <span>Ver cotización</span>
            </a>
        <?php endif; ?>
        <?php if ($entregas) : ?>
            <a class="btn btn--secundario" href="/admin/entregas-seguimiento/acta?id=<?= $id ?>" target="_blank" rel="noopener">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
                <span>Acta de entrega</span>
            </a>
        <?php endif; ?>
        <?php if ($registrada && $faltan > 0) : ?>
            <a class="btn btn--secundario" href="/admin/entregas/entregar?venta=<?= $id ?>&volver=seguimiento"
               data-modal-form="tpl-entrega"
               data-modal-sobre-titulo="Venta <?= escaparValores($venta->folio) ?>"
               data-modal-titulo="Registrar entrega"
               data-modal-confirmar="Confirmar entrega"<?= $accion === 'entrega' ? ' data-abrir-modal-al-cargar' : '' ?>>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">local_shipping</span>
                <span>Registrar entrega</span>
            </a>
        <?php endif; ?>
        <?php if ($registrada) : ?>
            <a class="btn btn--secundario" href="/admin/entregas-seguimiento/incidencia?id=<?= $id ?>"
               data-modal-form="tpl-incidencia"
               data-modal-sobre-titulo="Venta <?= escaparValores($venta->folio) ?>"
               data-modal-titulo="Registrar incidencia"
               data-modal-confirmar="Registrar incidencia"<?= $accion === 'incidencia' ? ' data-abrir-modal-al-cargar' : '' ?>>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">report</span>
                <span>Incidencia</span>
            </a>
            <a class="btn btn--secundario" href="/admin/ventas/devolucion?id=<?= $id ?>"
               data-modal-form="tpl-devolucion"
               data-modal-sobre-titulo="Devolución de la venta"
               data-modal-titulo="Registrar devolución"
               data-modal-confirmar="Registrar devolución"<?= $accion === 'devolucion' ? ' data-abrir-modal-al-cargar' : '' ?>>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">assignment_return</span>
                <span>Devolución</span>
            </a>
        <?php endif; ?>
        <?php if ($ingreso) : ?>
            <a class="btn btn--secundario" href="#facturacion">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">fact_check</span>
                <span><?= $facturada ? 'Ver facturación' : 'Facturar o cobrar' ?></span>
            </a>
        <?php endif; ?>
        <a class="btn btn--primario" href="/admin/ventas/pdf?id=<?= $id ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
            <span>PDF de la venta</span>
        </a>
    </div>
</section>

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
            Se resuelven en <a href="#incidencias">incidencias de entrega</a>.
        </span>
    </p>
<?php endif; ?>

<!-- Estado del expediente, siempre visible -->
<div class="chip-grupo mb-lg" aria-label="Estado del expediente">
    <?php if ($definicion) : ?>
        <a class="chip" href="#entrega">
            <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
            <span><?= escaparValores($definicion['etiqueta']) ?> · <?= $entregadas ?> de <?= $vendidas ?></span>
        </a>
    <?php endif; ?>
    <?php if ($abiertas > 0) : ?>
        <a class="chip" href="#incidencias">
            <span class="material-symbols-outlined" aria-hidden="true">report</span>
            <span><?= $abiertas ?> incidencia<?= $abiertas === 1 ? '' : 's' ?> abierta<?= $abiertas === 1 ? '' : 's' ?></span>
        </a>
    <?php endif; ?>
    <?php if ($fIva) : ?>
        <a class="chip" href="#facturacion">
            <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
            <span>Facturación: <?= escaparValores($fIva['etiqueta']) ?></span>
        </a>
    <?php endif; ?>
    <?php if ($ingreso) : ?>
        <span class="chip">
            <span class="material-symbols-outlined" aria-hidden="true">payments</span>
            <span>Cobrado <?= escaparValores('$' . number_format((float) $ingreso['cobrado'], 2)) ?></span>
        </span>
        <span class="chip">
            <span class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</span>
            <span>Saldo <?= escaparValores('$' . number_format((float) $ingreso['saldo'], 2)) ?></span>
        </span>
    <?php endif; ?>
</div>

<!-- Pestañas: enlaces a las secciones del mismo expediente -->
<nav class="chip-grupo entregas-tabs expediente-tabs" aria-label="Secciones del expediente">
    <a class="chip" href="#resumen"><span class="material-symbols-outlined" aria-hidden="true">description</span><span>Resumen</span></a>
    <a class="chip" href="#entrega"><span class="material-symbols-outlined" aria-hidden="true">local_shipping</span><span>Entrega y acta</span></a>
    <a class="chip" href="#incidencias"><span class="material-symbols-outlined" aria-hidden="true">report</span><span>Incidencias y devoluciones</span></a>
    <a class="chip" href="#facturacion"><span class="material-symbols-outlined" aria-hidden="true">fact_check</span><span>Facturación y cobro</span></a>
    <a class="chip" href="#historial"><span class="material-symbols-outlined" aria-hidden="true">history</span><span>Historial</span></a>
</nav>

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

template('venta/entrega', [
    'id'          => $id,
    'venta'       => $venta,
    'pendientes'  => $pendientes,
    'entregas'    => $entregas,
    'afectadas'   => $afectadas,
    'vendidas'    => $vendidas,
    'entregadas'  => $entregadas,
    'faltan'      => $faltan,
    'registrada'  => $registrada,
    'rutaVenta'   => $rutaVenta,
]);

template('venta/incidencias', [
    'id'          => $id,
    'venta'       => $venta,
    'incidencias' => $incidencias,
    'ingreso'     => $ingreso,
    'registrada'  => $registrada,
    'cobradoExtra' => $cobradoExtra,
    'rutaVenta'   => $rutaVenta,
]);

template('venta/facturacion', [
    'ingreso'   => $ingreso,
    'rutaVenta' => $rutaVenta,
]);

template('venta/historial', ['historial' => $historial]);

template('venta/eliminar', ['cotizacion' => $venta, 'id' => $id]);
?>

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
