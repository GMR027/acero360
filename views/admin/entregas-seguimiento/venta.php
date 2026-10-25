<?php
/**
 * views/admin/entregas-seguimiento/venta.php — Seguimiento de entregas de UNA venta.
 *
 * Reúne todo lo que pasó después de la venta: fecha y observaciones de entrega,
 * productos vendidos vs. entregados, incidencias (con sus renglones y cierre:
 * resolución o cancelación, con sustitución de factura si aplica), devoluciones
 * y cambios, y la línea de tiempo del historial (auditoría).
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$venta = \App\Ventas::buscarID($id);

if (!$venta) {
    paginaNoEncontrada();
}

$rutaVenta = '/admin/entregas-seguimiento/venta?id=' . $id;
$errores = [];

// POST: actualizar fecha de entrega y observaciones del seguimiento.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['accion'] ?? '') === 'datos') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($rutaVenta, 'error', 'Este formulario ya se envió; los datos no se guardaron dos veces.');
    }
    if (\App\IncidenciasEntrega::actualizarDatos($id, (string) ($_POST['fecha'] ?? ''), (string) ($_POST['observaciones'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
        redirigir($rutaVenta, 'exito', 'Seguimiento de entrega actualizado.');
    }
    $errores = \App\IncidenciasEntrega::getErrores();
}

$pendientes = \App\Entregas::pendientesDeVentas([$id])[$id] ?? [];
$entregas   = \App\Entregas::entregasDeVenta($id);
$incidencias = \App\IncidenciasEntrega::deVenta($id);
$historial   = \App\IncidenciasEntrega::historial($id);
$ingreso     = \App\Contabilidad::ingresoDeVenta($id);
$registrada  = $venta->estado === 'registrada';

$definicion = \App\IncidenciasEntrega::ESTADOS_ENTREGA[$venta->entrega_estado] ?? null;
$vendidas   = array_sum(array_column($pendientes, 'vendido'));
$entregadas = array_sum(array_column($pendientes, 'entregado'));
$faltan     = max(0, $vendidas - $entregadas);
$abiertas   = count(array_filter($incidencias, static fn ($i) => $i['estado'] === 'abierta'));

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

template('header', ['titulo' => 'Seguimiento · ' . $venta->folio, 'subtitulo' => 'Seguimiento de entregas', 'rutaActiva' => '/admin/entregas-seguimiento']);
template('tabsEntregas', ['tab' => 'seguimiento']);
?>

<?php if ($errores) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><?php foreach ($errores as $i2 => $e) : ?><?= $i2 ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
    </div>
<?php endif; ?>

<!-- Estado general y datos del seguimiento -->
<section class="tarjeta">
    <div class="tabla-toolbar">
        <div>
            <h2 class="titulo-5">
                <?= $definicion ? '<span class="badge ' . $definicion['clase'] . '">' . escaparValores($definicion['etiqueta']) . '</span>' : '' ?>
                <?php if ($abiertas > 0) : ?>
                    <span class="badge badge--error"><?= $abiertas ?> incidencia<?= $abiertas === 1 ? '' : 's' ?> abierta<?= $abiertas === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </h2>
            <p class="texto-secundario texto-sm mb-0">
                <?= escaparValores($venta->cliente_empresa ?: $venta->cliente_nombre) ?> ·
                <?= escaparValores($venta->empresa_nombre) ?> · venta del <?= date('d/m/Y', strtotime($venta->fecha)) ?> ·
                <strong class="cifra"><?= escaparValores('$' . number_format((float) $venta->total, 2)) ?></strong> ·
                <a href="/admin/ventas/detalle?id=<?= $id ?>">Abrir la venta</a>
            </p>
            <p class="texto-secundario texto-sm mb-0">
                Entregado: <strong class="cifra"><?= $entregadas ?></strong> de <?= $vendidas ?> pieza<?= $vendidas === 1 ? '' : 's' ?><?= $faltan > 0 ? ' · faltan ' . $faltan : ' (todo entregado)' ?>
                <?= !empty($venta->entrega_cerrada_en) ? ' · entrega cerrada el ' . date('d/m/Y', strtotime($venta->entrega_cerrada_en)) : '' ?>
            </p>
        </div>
        <?php if ($registrada && $faltan > 0) : ?>
            <a class="btn btn--secundario" href="/admin/entregas/entregar?id=<?= $id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">local_shipping</span>
                <span>Registrar entrega</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$registrada) : ?>
        <p class="nota nota--advertencia" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
            <span>La venta ya no está registrada: el seguimiento queda solo como historial.</span>
        </p>
    <?php endif; ?>

    <form class="formulario" action="<?= $rutaVenta ?>" method="post" novalidate>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="accion" value="datos">
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta" for="fecha-entrega">Fecha de entrega</label>
                <input class="control" type="date" id="fecha-entrega" name="fecha" value="<?= escaparValores((string) $venta->entrega_fecha) ?>"
                       <?= $registrada ? '' : 'disabled' ?>>
                <span class="campo__ayuda">Fecha prometida o acordada con el cliente.</span>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="observaciones">Observaciones de entrega</label>
                <textarea class="control" id="observaciones" name="observaciones" rows="2" maxlength="1000"
                          placeholder="Quién recibe, condiciones de la entrega, acuerdos…" <?= $registrada ? '' : 'disabled' ?>><?= escaparValores((string) $venta->entrega_observaciones) ?></textarea>
            </div>
        </div>
        <?php if ($registrada) : ?>
            <div class="formulario__acciones">
                <button class="btn btn--primario" type="submit" data-un-envio>Guardar seguimiento</button>
            </div>
        <?php endif; ?>
    </form>
</section>

<!-- Productos: vendido vs entregado vs incidencias -->
<section class="tarjeta mb-lg">
    <div class="tabla-toolbar">
        <h2 class="titulo-5">Productos de la venta</h2>
        <?php if ($registrada) : ?>
            <a class="btn btn--primario btn--sm" href="/admin/entregas-seguimiento/incidencia?id=<?= $id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">report</span>
                <span>Registrar incidencia</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$pendientes) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">inventory_2</span>
            <p class="vacio__titulo">La venta no tiene productos</p>
            <p>Revisa el detalle de <a href="/admin/ventas/detalle?id=<?= $id ?>">la venta</a>.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Vendido, entregado y afectado por incidencias por producto</caption>
                <thead><tr>
                    <th scope="col">Producto</th>
                    <th scope="col" class="tabla--numerica">Vendido</th>
                    <th scope="col" class="tabla--numerica">Entregado</th>
                    <th scope="col" class="tabla--numerica movil-oculto">Incidencias</th>
                    <th scope="col" class="tabla--numerica">Pendiente</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($pendientes as $l) : $pid = (int) $l['producto_id']; ?>
                        <tr>
                            <td><strong><?= escaparValores($l['nombre']) ?></strong>
                                <span class="texto-xs texto-secundario movil-oculto"><br><?= escaparValores($l['sku']) ?> · <?= escaparValores($l['unidad']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= (int) $l['vendido'] ?></td>
                            <td class="tabla--numerica cifra"><?= (int) $l['entregado'] ?></td>
                            <td class="tabla--numerica cifra movil-oculto"><?= ($afectadas[$pid] ?? 0) > 0 ? '<span class="badge badge--info">' . (int) $afectadas[$pid] . '</span>' : '—' ?></td>
                            <td class="tabla--numerica cifra">
                                <?= (int) $l['pendiente'] > 0 ? '<span class="badge badge--pendiente">' . (int) $l['pendiente'] . '</span>' : '<span class="badge badge--aprobado">0</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Incidencias -->
<section class="tarjeta mb-lg">
    <h2 class="titulo-5">Incidencias de entrega</h2>

    <?php if (!$incidencias) : ?>
        <p class="nota nota--info" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">check_circle</span>
            <span>Sin incidencias: no se reportó nada después de la entrega (producto faltante, dañado, incompleto, cambio o devolución).</span>
        </p>
    <?php endif; ?>

    <?php foreach ($incidencias as $incidencia) :
        $motivo = \App\IncidenciasEntrega::MOTIVOS[$incidencia['motivo']] ?? null;
        $estadoI = \App\IncidenciasEntrega::ESTADOS[$incidencia['estado']] ?? null;
        $abierta = $incidencia['estado'] === 'abierta'; ?>
        <article class="tarjeta tarjeta--sin-relleno mb-md" id="incidencia-<?= (int) $incidencia['id'] ?>">
            <div class="tabla-toolbar">
                <div>
                    <strong><?= escaparValores($motivo['etiqueta'] ?? $incidencia['motivo']) ?></strong>
                    <?php if ($estadoI) : ?><span class="badge <?= $estadoI['clase'] ?>"><?= escaparValores($estadoI['etiqueta']) ?></span><?php endif; ?>
                    <?php if ($incidencia['factura_requerida']) : ?><span class="badge badge--error">Corregir factura</span><?php endif; ?>
                    <span class="texto-xs texto-secundario"><br>Reportada el <?= date('d/m/Y H:i', strtotime($incidencia['creado_en'])) ?> por <?= escaparValores($incidencia['usuario_nombre'] ?? '—') ?>
                    <?php if ($incidencia['fecha_entrega']) : ?> · de la entrega del <?= date('d/m/Y', strtotime($incidencia['fecha_entrega'])) ?><?php endif; ?></span>
                </div>
                <?php if ($abierta && $registrada) : ?>
                    <a class="btn btn--secundario btn--sm" href="#resolver-<?= (int) $incidencia['id'] ?>">Resolver</a>
                <?php endif; ?>
            </div>

            <?php if ($incidencia['notas']) : ?>
                <p class="mb-md"><?= nl2br(escaparValores($incidencia['notas'])) ?></p>
            <?php endif; ?>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead><tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="tabla--numerica">Piezas afectadas</th>
                        <th scope="col" class="tabla--numerica">Se le debe al cliente</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($incidencia['renglones'] as $r) : ?>
                            <tr>
                                <td><strong><?= escaparValores($r['producto_nombre']) ?></strong>
                                    <span class="texto-xs texto-secundario movil-oculto"><br><?= escaparValores($r['sku']) ?></span></td>
                                <td class="tabla--numerica cifra"><?= (int) $r['cantidad'] ?></td>
                                <td class="tabla--numerica cifra"><?= $r['requiere_cambio'] ? '<span class="badge badge--info">' . (int) $r['cantidad'] . ' por entregar</span>' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($abierta && $registrada) : ?>
                <details class="seguimiento-resolucion" id="resolver-<?= (int) $incidencia['id'] ?>">
                    <summary>Resolver incidencia<?= $incidencia['factura_requerida'] ? ' y sustituir la factura' : '' ?></summary>
                    <form class="formulario" action="/admin/entregas-seguimiento/cerrar" method="post" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="incidencia_id" value="<?= (int) $incidencia['id'] ?>">
                        <input type="hidden" name="accion" value="resolver">
                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">

                        <?php if ($incidencia['factura_requerida']) : ?>
                            <p class="nota nota--advertencia" role="status">
                                <span class="material-symbols-outlined nota__icono" aria-hidden="true">receipt_long</span>
                                <span>Al resolver se <strong>cambia la factura de esta venta</strong>: los documentos actuales
                                    <?php if ($ingreso && $ingreso['uuid_fiscal']) : ?> (UUID <?= escaparValores($ingreso['uuid_fiscal']) ?>)<?php endif; ?>
                                    se dan de baja y los nuevos sustituyen folio, UUID, fecha y montos en contabilidad.
                                    Los cobros ya registrados se conservan: si quedaron de más que el nuevo total, el sistema lo avisará.</span>
                            </p>
                            <?php if ($ingreso && $cobradoExtra) : ?>
                                <p class="nota nota--error" role="alert">
                                    <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
                                    <span>La venta ya tiene cobros por <strong><?= escaparValores('$' . number_format((float) $ingreso['cobrado'], 2)) ?></strong>.
                                        Si la factura correcta es por menos, revisa los <a href="/admin/ventas/detalle?id=<?= $id ?>#cobros">cobros de la venta</a> para cancelar o ajustar.</span>
                                </p>
                            <?php endif; ?>
                            <div class="formulario__rejilla">
                                <div class="campo">
                                    <label class="campo__etiqueta" for="factura-pdf-<?= (int) $incidencia['id'] ?>">Factura correcta (PDF) *</label>
                                    <input class="control" type="file" id="factura-pdf-<?= (int) $incidencia['id'] ?>" name="factura_pdf" accept=".pdf,application/pdf" required>
                                </div>
                                <div class="campo">
                                    <label class="campo__etiqueta" for="factura-xml-<?= (int) $incidencia['id'] ?>">Factura correcta (XML del CFDI) *</label>
                                    <input class="control" type="file" id="factura-xml-<?= (int) $incidencia['id'] ?>" name="factura_xml" accept=".xml,text/xml,application/xml" required>
                                    <span class="campo__ayuda">Del XML se leen UUID, folio, fecha y montos para sincronizar el ingreso.</span>
                                </div>
                                <div class="campo">
                                    <label class="campo__etiqueta" for="fecha-factura-<?= (int) $incidencia['id'] ?>">Fecha de la factura (opcional)</label>
                                    <input class="control" type="date" id="fecha-factura-<?= (int) $incidencia['id'] ?>" name="fecha_factura"
                                           value="<?= date('Y-m-d') ?>">
                                    <span class="campo__ayuda">Si la dejas vacía se toma la del XML.</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="campo mt-md">
                            <label class="campo__etiqueta" for="resolucion-<?= (int) $incidencia['id'] ?>">¿Cómo se resolvió? *</label>
                            <textarea class="control" id="resolucion-<?= (int) $incidencia['id'] ?>" name="resolucion" rows="2" maxlength="400" required
                                      placeholder="Se reprogramó la entrega, el cliente aceptó la pieza, se sustituyó la factura…"></textarea>
                        </div>

                        <div class="formulario__acciones">
                            <button class="btn btn--primario" type="submit" data-un-envio>
                                <?= $incidencia['factura_requerida'] ? 'Resolver y sustituir factura' : 'Resolver incidencia' ?>
                            </button>
                        </div>
                    </form>

                    <form class="formulario" action="/admin/entregas-seguimiento/cerrar" method="post" novalidate>
                        <input type="hidden" name="incidencia_id" value="<?= (int) $incidencia['id'] ?>">
                        <input type="hidden" name="accion" value="cancelar">
                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                        <div class="campo">
                            <label class="campo__etiqueta" for="motivo-cancelar-<?= (int) $incidencia['id'] ?>">O cancelar la incidencia (reportada por error) *</label>
                            <input class="control" type="text" id="motivo-cancelar-<?= (int) $incidencia['id'] ?>" name="resolucion" maxlength="400" required
                                   placeholder="Motivo de la cancelación">
                        </div>
                        <div class="formulario__acciones">
                            <button class="btn btn--secundario" type="submit" data-un-envio>Cancelar incidencia</button>
                        </div>
                    </form>
                </details>
            <?php else : ?>
                <div class="seguimiento-cierre">
                    <p class="texto-sm mb-0">
                        <strong>Resolución:</strong> <?= escaparValores((string) ($incidencia['resolucion'] ?: '—')) ?><br>
                        <span class="texto-secundario">Cerrada el <?= $incidencia['resuelta_en'] ? date('d/m/Y H:i', strtotime($incidencia['resuelta_en'])) : '—' ?>
                        por <?= escaparValores($incidencia['resuelta_por_nombre'] ?? '—') ?></span>
                    </p>
                    <?php if (!empty($incidencia['documento'])) : ?>
                        <p class="texto-sm mb-0">
                            <span class="badge badge--aprobado">Factura correcta</span>
                            <?= escaparValores($incidencia['documento']['nombre_original']) ?>
                            (<a href="/admin/contabilidad/documentos/descargar?id=<?= (int) $incidencia['documento']['id'] ?>">descargar</a>)
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>

<!-- Facturación de la venta -->
<?php if ($ingreso) : $fIva = \App\Contabilidad::FACTURACION[$ingreso['estado_facturacion']] ?? null; ?>
    <section class="tarjeta mb-lg">
        <div class="tabla-toolbar">
            <h2 class="titulo-5">Facturación</h2>
            <?php if ($fIva) : ?><span class="badge <?= $fIva['clase'] ?>"><?= escaparValores($fIva['etiqueta']) ?></span><?php endif; ?>
        </div>
        <p class="texto-sm mb-md">
            <?php if ($ingreso['uuid_fiscal']) : ?>
                <strong>Folio fiscal:</strong> <?= escaparValores($ingreso['uuid_fiscal']) ?><br>
            <?php endif; ?>
            <?php if ($ingreso['factura_folio']) : ?><strong>Factura:</strong> <?= escaparValores($ingreso['factura_folio']) ?><br><?php endif; ?>
            <strong>Importe contable:</strong> <span class="cifra"><?= escaparValores('$' . number_format((float) $ingreso['total'], 2)) ?></span>
            <?php if ($ingreso['iva'] > 0) : ?> (IVA <span class="cifra"><?= escaparValores('$' . number_format((float) $ingreso['iva'], 2)) ?></span>)<?php endif; ?>
            · <strong>Cobrado:</strong> <span class="cifra"><?= escaparValores('$' . number_format((float) $ingreso['cobrado'], 2)) ?></span>
            · <strong>Saldo:</strong> <span class="cifra"><?= escaparValores('$' . number_format((float) $ingreso['saldo'], 2)) ?></span>
        </p>
        <?php if ($cobradoExtra) : ?>
            <p class="nota nota--advertencia" role="status">
                <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
                <span>La venta tiene cobros por más del importe actual: <a href="/admin/ventas/detalle?id=<?= $id ?>#cobros">revisa los cobros</a> para cancelar los que sobren.</span>
            </p>
        <?php endif; ?>
        <?php if ($incidencias && $abiertas > 0) : $primeraAbierta2 = 0; foreach ($incidencias as $inc) { if ($inc['estado'] === 'abierta') { $primeraAbierta2 = (int) $inc['id']; break; } } ?>
        <p class="texto-sm texto-secundario mb-0">
            La factura se corrige desde la <a href="#incidencia-<?= $primeraAbierta2 ?>">incidencia de entrega</a>
            que marque «Corregir factura»: se da de baja el documento anterior y se sube el correcto (PDF y XML),
            sincronizando el ingreso con los montos del XML. El importe de la venta no cambia.
        </p>
        <?php else : ?>
        <p class="texto-sm texto-secundario mb-0">
            Para corregir la factura de esta venta, registra una incidencia de entrega marcando «se debe cancelar la factura
            y subir la correcta»: el módulo da de baja los documentos anteriores y sincroniza folio, UUID, fecha y montos
            con el XML nuevo. El importe de la venta no cambia.
        </p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php $ventaId = $id; template('devolucionesVenta', ['ventaId' => $ventaId, 'registrada' => $registrada]); ?>

<!-- Historial -->
<section class="tarjeta mb-lg">
    <h2 class="titulo-5">Historial de cambios de la entrega</h2>
    <?php if (!$historial) : ?>
        <p class="texto-secundario">Aún no hay movimientos: las entregas, devoluciones, cambios, incidencias y correcciones de factura aparecerán aquí.</p>
    <?php else : ?>
        <ol class="seguimiento-timeline">
            <?php foreach ($historial as $linea) : ?>
                <li class="seguimiento-timeline__item">
                    <span class="badge <?= $linea['clase'] ?>"><?= escaparValores($linea['tipo']) ?></span>
                    <div class="seguimiento-timeline__cuerpo">
                        <strong><?= escaparValores($linea['titulo']) ?></strong>
                        <?php if ($linea['descripcion'] !== '') : ?><span class="texto-secundario"><br><?= nl2br(escaparValores($linea['descripcion'])) ?></span><?php endif; ?>
                        <span class="texto-xs texto-secundario"><br><?= date('d/m/Y H:i', strtotime($linea['fecha'])) ?><?= $linea['usuario'] ? ' · ' . escaparValores($linea['usuario']) : '' ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php if ($incidencias) : ?>
    <p class="texto-secundario texto-sm">
        <a href="/admin/entregas-seguimiento">← Volver al seguimiento de entregas</a>
    </p>
<?php endif; ?>

<script>
/* Evita el doble clic: tras el primer envío el botón se desactiva. */
document.querySelectorAll('form').forEach(function (f) {
  f.addEventListener('submit', function () { const b = f.querySelector('[data-un-envio]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
});
</script>

<?php template('footer'); ?>
