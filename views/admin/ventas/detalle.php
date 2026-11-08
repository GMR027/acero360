<?php
/**
 * views/admin/ventas/detalle.php — Vista de una venta directa.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Documento de sólo lectura: negocio emisor, cliente, renglones, términos
 * comerciales y logísticos y Resumen de liquidación (el mismo que usan las
 * cotizaciones). Arriba vive la única sección de Facturación y cobro
 * (estado de facturación, CFDI y cobros de su ingreso); el detalle del
 * ingreso en Contabilidad redirige a esta pantalla. Desde acá se edita,
 * se exporta el PDF o se elimina.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$cotizacion = \App\Ventas::buscarID($id);

if (!$cotizacion) {
    // Igual que el resto del panel: un folio inexistente es una 404.
    paginaNoEncontrada();
}

$errores = [];

// Subida de fotos del acta a una entrega ya registrada de esta venta.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['accion'] ?? '') === 'fotos') {
    $destino = '/admin/ventas/detalle?id=' . $id . '#fotos-entrega';

    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($destino, 'error', 'Este formulario ya se envió; las fotos no se guardaron dos veces.');
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
        redirigir($destino, 'exito', $mensaje);
    }
    $errores = \App\Entregas::getErrores();
}

$detalles = \App\Ventas::detalles($id);
$entregas = \App\Entregas::entregasDeVenta($id);
$estado   = \App\Ventas::datosEstado($cotizacion->estado);

/** Descuento por renglón y descuento global (con su porcentaje negociado). */
$desglose           = \App\Cotizaciones::desgloseDescuento($cotizacion, $detalles);
$descuentoRenglones = $desglose['renglones'];
$descuentoGlobal    = $desglose['global'];
$pctGlobal          = $desglose['pct'];

$logo = trim((string) $cotizacion->empresa_logo) !== ''
    ? \App\Imagenes::url(\App\Imagenes::logoRecortado($cotizacion->empresa_logo))
    : '';

/** Productos de la venta que hoy tienen existencias negativas (se vendió antes de fabricar). */
$negativos = [];
foreach ($detalles as $detalle) {
    $existencias = \App\Inventario::stock((int) $detalle['producto_id']);
    if ($existencias < 0) {
        $negativos[(int) $detalle['producto_id']] = $detalle['producto_nombre'] . ' (' . $existencias . ')';
    }
}

/**
 * Incidencias de entrega todavía abiertas (producto faltante, dañado, incompleto, cambio o
 * devolución): aquí sólo se AVISAN —se resuelven en el hub de Operación Comercial—, pero el
 * detalle de la venta las muestra para que no se pierdan de vista al facturar o al cobrar.
 */
$incidenciasAbiertas = array_values(array_filter(
    \App\IncidenciasEntrega::deVenta($id),
    static fn (array $incidencia): bool => ($incidencia['estado'] ?? '') === 'abierta'
));
$incidenciasCorreccion = array_filter($incidenciasAbiertas, static fn (array $i): bool => !empty($i['factura_requerida']));
$motivosAbiertos = [];
foreach ($incidenciasAbiertas as $incidencia) {
    $motivosAbiertos[\App\IncidenciasEntrega::MOTIVOS[$incidencia['motivo']]['etiqueta'] ?? $incidencia['motivo']] = true;
}

template('header', [
    'titulo'            => $cotizacion->folio,
    'subtitulo'         => 'Detalle de venta',
    'rutaActiva'        => '/admin/ventas',
    'mostrarEncabezado' => false,
]);
?>

<section class="encabezado-pagina">
    <div>
        <h1 class="titulo-2">
            <?= escaparValores($cotizacion->folio) ?>
            <span class="badge <?= $estado['clase'] ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $estado['icono'] ?></span>
                <?= $estado['etiqueta'] ?>
            </span>
            <span class="badge badge--info"><?= $cotizacion->cotizacion_id === null ? 'Venta directa' : 'Desde cotización' ?></span>
        </h1>
        <p class="texto-secundario">
            Registrada el <?= date('d/m/Y', strtotime($cotizacion->fecha)) ?>
            <?php if (trim((string) $cotizacion->usuario_nombre) !== '') : ?>
                · por <?= escaparValores($cotizacion->usuario_nombre) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/ventas">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>
            <span>Ventas</span>
        </a>
        <?php if ($cotizacion->cotizacion_id === null) : ?>
            <a class="btn btn--secundario" href="/admin/ventas/editar?id=<?= $id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                <span>Editar</span>
            </a>
        <?php else : ?>
            <a class="btn btn--secundario" href="/admin/cotizaciones/detalle?id=<?= (int) $cotizacion->cotizacion_id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">request_quote</span>
                <span>Ver cotización</span>
            </a>
        <?php endif; ?>
        <?php if ($cotizacion->estado === 'registrada') : ?>
            <a class="btn btn--secundario" href="/admin/entregas-seguimiento/venta?id=<?= $id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">fact_check</span>
                <span>Operación de esta venta</span>
            </a>
        <?php endif; ?>
        <a class="btn btn--secundario" href="#fotos-entrega">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">photo_camera</span>
            <span>Fotos de la entrega</span>
        </a>
        <a class="btn btn--primario" href="/admin/ventas/pdf?id=<?= $id ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
            <span>Exportar PDF</span>
        </a>
    </div>
</section>

<?php if ($cotizacion->estado === 'cancelada') : ?>
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

<?php if ($incidenciasAbiertas) : $abiertas = count($incidenciasAbiertas); ?>
    <p class="nota <?= $incidenciasCorreccion ? 'nota--error' : 'nota--advertencia' ?> mb-lg" <?= $incidenciasCorreccion ? 'role="alert"' : 'role="status"' ?>>
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">report</span>
        <span>
            <strong><?= $abiertas ?> incidencia<?= $abiertas === 1 ? '' : 's' ?> de entrega abierta<?= $abiertas === 1 ? '' : 's' ?></strong>
            <?php if ($motivosAbiertos) : ?>(<?= escaparValores(implode(' · ', array_keys($motivosAbiertos))) ?>)<?php endif; ?><?= $incidenciasCorreccion ? ' · alguna exige corregir la factura' : '' ?>.
            Se resuelven en <a href="/admin/entregas-seguimiento/venta?id=<?= $id ?>#incidencias">Operación de esta venta</a>.
        </span>
    </p>
<?php endif; ?>

<?php
/**
 * Facturación y cobro: la única sección de la venta para decir si ya se facturó, adjuntar el CFDI
 * y registrar si ya se pagó. El detalle del ingreso (Contabilidad) redirige aquí.
 * Devoluciones, entregas e incidencias viven en el hub de Operación Comercial
 * (botón «Operación de esta venta»): aquí queda el enlace y el aviso de las
 * incidencias de entrega que siguen abiertas.
 */
$ingresoVenta = \App\Contabilidad::ingresoDeVenta($id);
if ($ingresoVenta) {
    template('facturacionVenta', ['ingreso' => $ingresoVenta, 'volver' => '/admin/ventas/detalle?id=' . $id]);
}
?>

<p class="texto-sm mb-lg">
    Entregas, incidencias, devoluciones y cambios de esta venta se gestionan en
    <a href="/admin/entregas-seguimiento/venta?id=<?= $id ?>">Operación de esta venta</a>.
</p>

<?php
/**
 * Fotos del acta. Viven en cada entrega (una venta puede tener entregas
 * parciales), así que aquí se muestran todas las de la venta y se pueden
 * agregar a cualquiera de sus entregas ya registradas.
 */
$entregasConFotos = array_values(array_filter($entregas, static fn ($e) => !empty($e['fotos'])));
?>
<section class="tarjeta mb-lg" id="fotos-entrega">
    <div class="tabla-toolbar">
        <div>
            <h2 class="titulo-5">Fotos de la entrega</h2>
            <p class="texto-secundario texto-sm mb-0">
                Fotos del acta adjuntas a las entregas de esta venta. Al abrirlas se muestran con la marca de agua del
                negocio, la fecha, la hora y la ubicación.
            </p>
        </div>
        <?php if ($entregas) : ?>
            <a class="btn btn--secundario btn--sm" href="/admin/entregas-seguimiento/venta?id=<?= $id ?>#fotos-entrega">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">fact_check</span>
                <span>Ver seguimiento</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$entregas) : ?>
        <p class="texto-secundario">
            Esta venta todavía no tiene entregas registradas. Registra la entrega para poder adjuntar las fotos del acta.
        </p>
        <?php if ($cotizacion->estado === 'registrada') : ?>
            <a class="btn btn--primario btn--sm" href="/admin/entregas/entregar?venta=<?= $id ?>&volver=seguimiento">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">local_shipping</span>
                <span>Registrar entrega</span>
            </a>
        <?php endif; ?>
    <?php else : ?>
        <?php if ($entregasConFotos) : ?>
            <?php foreach ($entregasConFotos as $e) : $fechaE = date('d/m/Y', strtotime($e['fecha'])); $nFotos = count($e['fotos']); ?>
                <div class="seguimiento-fotos">
                    <p class="texto-sm mb-0">
                        <strong>Entrega del <?= $fechaE ?></strong>
                        <span class="texto-secundario">
                            · <?= $nFotos ?> foto<?= $nFotos === 1 ? '' : 's' ?> · registró <?= escaparValores($e['usuario_nombre'] ?? '—') ?>
                        </span>
                    </p>
                    <ul class="seguimiento-fotos__lista">
                        <?php foreach ($e['fotos'] as $indice => $foto) : $rutaFoto = \App\Imagenes::url($foto['archivo']); $rutaMarcada = \App\FotosEntrega::url($foto['archivo']); ?>
                            <li class="seguimiento-fotos__item">
                                <button type="button" class="seguimiento-fotos__enlace"
                                        data-ampliar-imagen="<?= escaparValores($rutaMarcada) ?>"
                                        data-ampliar-sobre="Fotos de la entrega"
                                        data-ampliar-titulo="Foto <?= $indice + 1 ?> de la entrega del <?= $fechaE ?>"
                                        title="Ver la foto <?= $indice + 1 ?> de la entrega del <?= $fechaE ?>"
                                        aria-label="Ampliar la foto <?= $indice + 1 ?> de la entrega del <?= $fechaE ?>">
                                    <img class="seguimiento-fotos__imagen" src="<?= escaparValores($rutaFoto) ?>"
                                         alt="Foto <?= $indice + 1 ?> de la entrega del <?= $fechaE ?>"
                                         loading="lazy">
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php
                    $primeraFoto = $e['fotos'][0] ?? [];
                    $mapas = \App\FotosEntrega::urlsMapa($primeraFoto, (string) $cotizacion->cliente_ubicacion, (string) ($e['ubicacion_maps'] ?? ''));
                    ?>
                    <?php if (!empty($e['ubicacion_maps'])) : ?>
                        <p class="texto-xs texto-secundario mb-0">Ubicación manual: <?= escaparValores($e['ubicacion_maps']) ?></p>
                    <?php endif; ?>
                    <?php if ($mapas) : ?>
                        <div class="seguimiento-fotos__acciones">
                            <?php if (isset($mapas['google'])) : ?>
                                <a class="btn btn--secundario btn--sm" href="<?= escaparValores($mapas['google']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   aria-label="Abrir la ubicación de la entrega en Google Maps">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">location_on</span>
                                    <span>Google Maps</span>
                                </a>
                            <?php endif; ?>
                            <?php if (isset($mapas['apple'])) : ?>
                                <a class="btn btn--secundario btn--sm" href="<?= escaparValores($mapas['apple']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   aria-label="Abrir la ubicación de la entrega en Apple Maps">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">map</span>
                                    <span>Apple Maps</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p class="texto-secundario">Aún no hay fotos adjuntas. Puedes subirlas a cualquiera de las entregas de la venta.</p>
        <?php endif; ?>

        <form class="formulario" action="/admin/ventas/detalle?id=<?= $id ?>#fotos-entrega" method="post" enctype="multipart/form-data" novalidate data-comprimir-fotos>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="accion" value="fotos">
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">

            <div class="formulario__rejilla">
                <div class="campo">
                    <label class="campo__etiqueta" for="fotos-entrega-archivos">Agregar fotos del acta</label>
                    <input class="control" type="file" id="fotos-entrega-archivos" name="fotos[]"
                           data-max-bytes="<?= (int) \App\Imagenes::TAMANO_MAXIMO_ENTREGA ?>"
                           accept="image/jpeg,image/png,image/webp" multiple>
                    <span class="campo__ayuda">
                        Hasta <?= (int) \App\Entregas::MAX_FOTOS ?> fotos por envío y
                        <?= (int) \App\Entregas::MAX_FOTOS_TOTAL_MB ?> MB en total; cada foto puede pesar
                        hasta <?= (int) ceil(\App\Imagenes::TAMANO_MAXIMO_ENTREGA / 1048576) ?> MB.
                        Se comprimen antes de enviarlas cuando el navegador puede.
                    </span>
                    <span class="campo__ayuda" data-estado-fotos hidden></span>
                </div>
                <div class="campo">
                    <label class="campo__etiqueta" for="fotos-entrega-destino">Entrega</label>
                    <select class="control" id="fotos-entrega-destino" name="entrega_id">
                        <?php foreach (array_reverse($entregas) as $e) : $piezas = array_sum(array_column($e['renglones'], 'cantidad')); ?>
                            <option value="<?= (int) $e['id'] ?>" data-ubicacion="<?= escaparValores((string) ($e['ubicacion_maps'] ?? '')) ?>">
                                <?= date('d/m/Y', strtotime($e['fecha'])) ?> ·
                                <?= $piezas ?> pieza<?= $piezas === 1 ? '' : 's' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="campo__ayuda">Se adjuntan a la entrega elegida (la más reciente aparece primero).</span>
                </div>
                <div class="campo">
                    <label class="campo__etiqueta" for="fotos-entrega-ubicacion">Ubicación de Google Maps</label>
                    <input class="control" type="text" id="fotos-entrega-ubicacion" name="ubicacion" maxlength="500"
                           placeholder="Enlace de Google Maps, coordenadas o dirección">
                    <span class="campo__ayuda">
                        Si las fotos no traen GPS, esta ubicación se usa en el mapa. También puedes guardarla
                        sin subir fotos nuevas. El GPS de la foto siempre tiene prioridad.
                    </span>
                </div>
            </div>

            <div class="formulario__acciones">
                <button class="btn btn--primario" type="submit">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_photo_alternate</span>
                    <span>Subir fotos / guardar ubicación</span>
                </button>
            </div>
        </form>
    <?php endif; ?>
</section>

<div class="cotizador-detalle">
    <!-- Documento -->
    <section class="tarjeta mb-lg">
        <div class="cotizador-detalle__emisor">
            <?php if ($logo !== '') : ?>
                <img class="cotizador-detalle__logo" src="<?= escaparValores($logo) ?>"
                     alt="<?= escaparValores($cotizacion->empresa_nombre) ?>">
            <?php endif; ?>

            <div>
                <h2 class="titulo-3"><?= escaparValores($cotizacion->empresa_nombre) ?></h2>
                <p class="texto-secundario">
                    <?php
                    $domicilio = trim((string) $cotizacion->empresa_direccion);
                    if (trim((string) $cotizacion->empresa_ciudad) !== '') {
                        $domicilio .= ($domicilio !== '' ? ', ' : '')
                            . $cotizacion->empresa_ciudad;
                    }
                    if (trim((string) $cotizacion->empresa_estado) !== '') {
                        $domicilio .= ($domicilio !== '' ? ', ' : '') . $cotizacion->empresa_estado;
                    }
                    if (trim((string) $cotizacion->empresa_cp) !== '') {
                        $domicilio .= ($domicilio !== '' ? ' C.P. ' : 'C.P. ') . $cotizacion->empresa_cp;
                    }
                    ?>
                    <?php if ($domicilio !== '') : ?>
                        <?= escaparValores($domicilio) ?><br>
                    <?php endif; ?>
                    <?php if (trim((string) $cotizacion->empresa_telefono) !== '') : ?>
                        Tel. <?= escaparValores($cotizacion->empresa_telefono) ?><br>
                    <?php endif; ?>
                    <?php if (trim((string) $cotizacion->empresa_email) !== '') : ?>
                        <?= escaparValores($cotizacion->empresa_email) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <dl class="cotizador-detalle__cliente">
            <div>
                <dt>Cliente</dt>
                <dd>
                    <strong><?= escaparValores($cotizacion->cliente_nombre) ?></strong><br>
                    <?php if (trim((string) $cotizacion->cliente_empresa) !== '') : ?>
                        <?= escaparValores($cotizacion->cliente_empresa) ?><br>
                    <?php endif; ?>
                    <?php if (trim((string) $cotizacion->cliente_email) !== '') : ?>
                        <?= escaparValores($cotizacion->cliente_email) ?><br>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Referencia</dt>
                <dd>
                    Folio <strong><?= escaparValores($cotizacion->folio) ?></strong><br>
                    Fecha <?= date('d/m/Y', strtotime($cotizacion->fecha)) ?>
                </dd>
            </div>
        </dl>

        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Renglones de la venta</caption>
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Producto</th>
                        <th scope="col">Unidad</th>
                        <th scope="col" class="tabla--numerica">Precio unitario</th>
                        <th scope="col" class="tabla--numerica">Cantidad</th>
                        <th scope="col" class="tabla--numerica">Desc. (%)</th>
                        <th scope="col" class="tabla--numerica">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detalles as $indice => $detalle) :
                        $especial = (float) $detalle['precio_unitario'] !== (float) $detalle['producto_precio'];
                        ?>
                        <tr>
                            <td class="tabla--numerica cifra"><?= $indice + 1 ?></td>
                            <td>
                                <div class="tabla__producto">
                                    <?php $rutaImg = trim((string) $detalle['producto_imagen']); ?>
                                    <?php if ($rutaImg !== '') : ?>
                                        <img class="tabla__miniatura" src="<?= escaparValores(\App\Imagenes::urlMiniatura($rutaImg)) ?>"
                                             alt="" width="300" height="300" loading="lazy">
                                    <?php endif; ?>
                                    <div class="tabla__producto-textos">
                                        <span><?= escaparValores($detalle['producto_nombre']) ?></span>
                                        <span class="texto-secundario">
                                            <?= escaparValores($detalle['producto_sku']) ?>
                                            <?php if ($especial) : ?>
                                                <span class="badge badge--vip">Precio especial</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td><?= escaparValores($detalle['producto_unidad']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($detalle['precio_unitario']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $detalle['cantidad']) ?></td>
                            <td class="tabla--numerica cifra"><?= (float) $detalle['descuento_pct'] > 0 ? number_format((float) $detalle['descuento_pct'], 2) . '%' : '—' ?></td>
                            <td class="tabla--numerica cifra"><strong><?= formatoMoneda($detalle['subtotal']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Condiciones comerciales -->
    <section class="tarjeta mb-lg">
        <h2 class="titulo-3 mb-md">
            <span class="material-symbols-outlined" aria-hidden="true">handshake</span>
            Términos comerciales y logística
        </h2>

        <?php if (trim((string) $cotizacion->condiciones_envio) !== '') : ?>
            <div class="cotizador-detalle__condicion">
                <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
                <div>
                    <strong>Entrega</strong>
                    <p><?= nl2br(escaparValores($cotizacion->condiciones_envio)) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (trim((string) $cotizacion->condiciones_compra) !== '') : ?>
            <div class="cotizador-detalle__condicion">
                <span class="material-symbols-outlined" aria-hidden="true">account_balance</span>
                <div>
                    <strong>Pago</strong>
                    <p><?= nl2br(escaparValores($cotizacion->condiciones_compra)) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (trim((string) $cotizacion->comentarios) !== '') : ?>
            <div class="cotizador-detalle__condicion">
                <span class="material-symbols-outlined" aria-hidden="true">sticky_note_2</span>
                <div>
                    <strong>Comentarios adicionales</strong>
                    <p><?= nl2br(escaparValores($cotizacion->comentarios)) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (trim((string) $cotizacion->condiciones_envio) === ''
            && trim((string) $cotizacion->condiciones_compra) === ''
            && trim((string) $cotizacion->comentarios) === '') : ?>
            <p class="texto-secundario">Esta venta no tiene condiciones comerciales registradas.</p>
        <?php endif; ?>
    </section>
</div>

<!-- Resumen -->
<section class="tarjeta mb-lg">
    <div class="cotizador-resumen cotizador-resumen--plano">
        <header class="cotizador-resumen__cabecera">
            <h2 class="cotizador-resumen__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">calculate</span>
                Resumen de liquidación
            </h2>
            <span class="badge badge--neutro">Moneda: MXN</span>
        </header>

        <dl class="cotizador-resumen__cuerpo">
            <div class="cotizador-resumen__fila">
                <dt>Subtotal bruto de partidas</dt>
                <dd><?= formatoMoneda($cotizacion->subtotal) ?></dd>
            </div>
            <?php if ($descuentoRenglones > 0) : ?>
                <div class="cotizador-resumen__fila">
                    <dt>Descuento por renglón</dt>
                    <dd class="cotizador-resumen__negativo">-<?= formatoMoneda($descuentoRenglones) ?></dd>
                </div>
            <?php endif; ?>
            <div class="cotizador-resumen__fila">
                <dt>
                    Descuento global
                    <span class="cotizador-resumen__ayuda"><?= $pctGlobal > 0 ? number_format($pctGlobal, 2) . '%' : 'Sin descuento' ?></span>
                </dt>
                <dd class="cotizador-resumen__negativo">-<?= formatoMoneda($descuentoGlobal) ?></dd>
            </div>
            <div class="cotizador-resumen__fila">
                <dt>Base gravable</dt>
                <dd><?= formatoMoneda((float) $cotizacion->subtotal - (float) $cotizacion->descuento) ?></dd>
            </div>
            <div class="cotizador-resumen__fila">
                <dt>I.V.A. federal trasladado (16%)<?= (int) $cotizacion->aplica_iva !== 1 ? ' — no aplicado' : '' ?></dt>
                <dd><?= formatoMoneda($cotizacion->iva) ?></dd>
            </div>
            <div class="cotizador-resumen__fila cotizador-resumen__fila--total">
                <dt>Total neto autorizado</dt>
                <dd><strong><?= formatoMoneda($cotizacion->total) ?></strong></dd>
            </div>
        </dl>

        <p class="cotizador-resumen__letra"><?= escaparValores(numeroALetras((float) $cotizacion->total)) ?></p>
    </div>
</section>

<!-- Baja (sólo POST, con confirmación modal) -->
<section class="tarjeta" id="eliminar-venta">
    <h2 class="titulo-3 mb-md">
        <span class="material-symbols-outlined" aria-hidden="true">delete_forever</span>
        Eliminar venta
    </h2>
    <p class="texto-secundario mb-md">
        Se retira la venta y el inventario recupera las piezas descontadas.
        Para conservarla en el historial sin que cuente, cámbiala a «Cancelada» al editarla.
    </p>

    <form action="/admin/ventas/eliminar" method="post" data-validar
          data-confirmacion-sobre="<?= escaparValores($cotizacion->folio) ?>"
          data-confirmacion="¿Eliminar esta venta?"
          data-confirmacion-mensaje="Se borrará la venta <?= escaparValores($cotizacion->folio) ?> y todos sus renglones.<?php if ($cotizacion->estado === 'registrada') : ?> El inventario recuperará las piezas descontadas.<?php endif; ?> Irá a Ajustes → Papelera y ahí podrás restaurarla durante 90 días."
          data-confirmacion-boton="Sí, eliminar">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn--peligro">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
            <span>Eliminar venta</span>
        </button>
    </form>
</section>

<?php template('footer'); ?>
