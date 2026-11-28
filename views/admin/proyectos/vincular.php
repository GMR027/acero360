<?php
/**
 * views/admin/proyectos/vincular.php — Vincula ventas con un proyecto.
 *
 * Dos entradas:
 *   ?proyecto=ID  → lista las ventas del mismo cliente/negocio sin proyecto.
 *   ?venta=ID     → lista los proyectos activos del mismo cliente/negocio.
 *
 * Vincular no modifica importes, inventario ni entregas: sólo relaciona la
 * venta con el proyecto para que su avance se calcule.
 */

soloAdmin();

$proyectoId = (int) ($_GET['proyecto'] ?? $_POST['proyecto_id'] ?? 0);
$ventaId    = (int) ($_GET['venta'] ?? $_POST['venta_id'] ?? 0);
$accion     = (string) ($_POST['accion'] ?? '');
$usuarioId  = (int) ($_SESSION['id'] ?? 0);
$errores    = [];

if ($accion === 'vincular' && $proyectoId > 0) {
    $ventaIds = array_map('intval', (array) ($_POST['venta_ids'] ?? []));
    $ventaIds = array_values(array_filter($ventaIds));
    $vinculadas = 0;

    foreach ($ventaIds as $idVenta) {
        if (\App\ProyectosEntrega::vincularVenta($proyectoId, $idVenta, $usuarioId)) {
            $vinculadas++;
        } else {
            $errores = array_merge($errores, \App\ProyectosEntrega::getErrores());
        }
    }

    if ($vinculadas > 0) {
        if ($errores) {
            agregarAlerta('advertencia', 'Algunas ventas no se vincularon: ' . implode(' ', array_unique($errores)));
        }
        redirigir(
            '/admin/proyectos/detalle?id=' . $proyectoId,
            'exito',
            $vinculadas === 1 ? 'Se vinculó 1 venta al proyecto.' : 'Se vincularon ' . $vinculadas . ' ventas al proyecto.'
        );
    }
    if (!$errores) {
        $errores[] = 'Elige al menos una venta para vincular.';
    }
}

if ($accion === 'vincular_venta' && $ventaId > 0) {
    $proyectoDestino = (int) ($_POST['proyecto_destino'] ?? 0);
    if ($proyectoDestino <= 0) {
        $errores[] = 'Elige un proyecto para vincular la venta.';
    } elseif (\App\ProyectosEntrega::vincularVenta($proyectoDestino, $ventaId, $usuarioId)) {
        redirigir('/admin/ventas/detalle?id=' . $ventaId, 'exito', 'Venta vinculada al proyecto.');
    }
    $errores = array_merge($errores, \App\ProyectosEntrega::getErrores());
}

$proyecto = $proyectoId > 0 ? \App\ProyectosEntrega::buscarID($proyectoId) : null;
$venta    = $ventaId > 0 ? \App\Ventas::buscarID($ventaId) : null;

if ($proyectoId > 0 && !$proyecto) {
    paginaNoEncontrada();
}
if ($ventaId > 0 && !$venta) {
    paginaNoEncontrada();
}
if ($proyecto && $proyecto->estado === 'cancelado') {
    redirigir('/admin/proyectos/detalle?id=' . $proyectoId, 'advertencia', 'El proyecto está cancelado: no admite ventas nuevas.');
}
if (!$proyecto && !$venta) {
    redirigir('/admin/proyectos', 'advertencia', 'Elige un proyecto o una venta para vincular.');
}

$filtros = [
    'q'     => trim((string) ($_GET['q'] ?? '')),
    'desde' => trim((string) ($_GET['desde'] ?? '')),
    'hasta' => trim((string) ($_GET['hasta'] ?? '')),
];
$ventasDisponibles = $proyecto ? \App\ProyectosEntrega::ventasDisponibles($proyectoId, $filtros, 100, 0) : [];
$proyectosDisponibles = $venta ? \App\ProyectosEntrega::proyectosDisponiblesParaVenta($ventaId) : [];

$clienteId = $proyecto ? (int) $proyecto->cliente_id : ($venta ? (int) $venta->cliente_id : 0);
$clienteNombre = $proyecto
    ? ($proyecto->cliente_empresa ?: $proyecto->cliente_nombre)
    : ($venta ? ($venta->cliente_empresa ?: $venta->cliente_nombre) : '');

$volver = $proyecto
    ? '/admin/proyectos/detalle?id=' . $proyectoId
    : '/admin/ventas/detalle?id=' . $ventaId;

template('header', [
    'titulo'    => 'Vincular ventas',
    'subtitulo' => $proyecto ? 'Proyecto ' . $proyecto->folio : 'Venta ' . ($venta->folio ?? ''),
    'rutaActiva'=> '/admin/proyectos',
    'acciones'  => '<a class="btn btn--secundario" href="' . escaparValores($volver) . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>'
        . '<span>Volver</span></a>',
]);
?>

<?php if ($clienteId > 0) : ?>
    <?php template('tabsCliente', ['clienteId' => $clienteId, 'tab' => 'proyectos']); ?>
<?php endif; ?>

<section class="encabezado-pagina">
    <div>
        <p class="titulo-4"><?= escaparValores($clienteNombre) ?></p>
        <p class="texto-secundario">
            <?php if ($proyecto) : ?>
                Proyecto <strong><?= escaparValores($proyecto->folio) ?></strong> · <?= escaparValores($proyecto->nombre) ?>
            <?php else : ?>
                Venta <strong><?= escaparValores($venta->folio ?? '') ?></strong>
            <?php endif; ?>
        </p>
    </div>
</section>

<?php if ($errores) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><?= escaparValores(implode(' ', array_unique($errores))) ?></span>
    </div>
<?php endif; ?>

<?php if ($proyecto) : ?>
    <section class="tarjeta tarjeta--sin-relleno">
        <div class="tabla-toolbar">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">link</span>
                Ventas del cliente sin proyecto
            </h2>
            <span class="badge badge--neutro badge--sin-punto"><?= number_format(count($ventasDisponibles)) ?> disponible(s)</span>
        </div>

        <div class="tabla-toolbar">
            <form class="buscador" action="/admin/proyectos/vincular" method="get" role="search">
                <input type="hidden" name="proyecto" value="<?= $proyectoId ?>">
                <div class="buscador__campo">
                    <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                    <label class="solo-lectores" for="q">Buscar venta</label>
                    <input class="control" type="search" id="q" name="q" maxlength="80" value="<?= escaparValores($filtros['q']) ?>" placeholder="Folio de la venta…">
                </div>
                <label class="solo-lectores" for="desde">Desde</label>
                <input class="control control--compacto" type="date" id="desde" name="desde" value="<?= escaparValores($filtros['desde']) ?>">
                <label class="solo-lectores" for="hasta">Hasta</label>
                <input class="control control--compacto" type="date" id="hasta" name="hasta" value="<?= escaparValores($filtros['hasta']) ?>">
                <button class="btn btn--secundario btn--sm" type="submit">Buscar</button>
            </form>
        </div>

        <?php if (!$ventasDisponibles) : ?>
            <div class="vacio">
                <span class="material-symbols-outlined vacio__icono" aria-hidden="true">receipt_long</span>
                <p class="vacio__titulo">Sin ventas disponibles</p>
                <p>No hay ventas registradas de este cliente y negocio que todavía no tengan proyecto.</p>
                <a class="btn btn--primario" href="/admin/ventas/crear?cliente=<?= (int) $proyecto->cliente_id ?>&proyecto=<?= $proyectoId ?>">Crear venta del proyecto</a>
            </div>
        <?php else : ?>
            <form action="/admin/proyectos/vincular?proyecto=<?= $proyectoId ?>" method="post" data-validar>
                <input type="hidden" name="accion" value="vincular">
                <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
                <div class="tabla-contenedor">
                    <table class="tabla tabla--documentos">
                        <caption class="solo-lectores">Ventas disponibles para vincular</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="tabla--centro">
                                    <label class="solo-lectores" for="marcar-todas-ventas">Seleccionar todas</label>
                                    <input type="checkbox" id="marcar-todas-ventas" data-marcar-todas>
                                </th>
                                <th scope="col">Folio</th>
                                <th scope="col">Fecha</th>
                                <th scope="col" class="tabla--numerica">Piezas</th>
                                <th scope="col" class="tabla--numerica">Entregadas</th>
                                <th scope="col" class="tabla--numerica">Total</th>
                                <th scope="col">Entrega</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ventasDisponibles as $ventaDisponible) :
                                $estadoEntrega = \App\IncidenciasEntrega::ESTADOS_ENTREGA[$ventaDisponible['entrega_estado'] ?? ''] ?? null;
                                $etiquetaEntrega = [
                                    'pendiente' => 'Por entregar',
                                    'entregado' => 'Entregado',
                                    'entregado_con_incidencias' => 'Con incidencias',
                                    'entregado_incidencias_resueltas' => 'Incidencias resueltas',
                                ][$ventaDisponible['entrega_estado'] ?? ''] ?? ($estadoEntrega['etiqueta'] ?? '—');
                                ?>
                                <tr>
                                    <td class="tabla--centro">
                                        <label class="solo-lectores" for="venta-<?= (int) $ventaDisponible['id'] ?>">Vincular <?= escaparValores($ventaDisponible['folio']) ?></label>
                                        <input type="checkbox" id="venta-<?= (int) $ventaDisponible['id'] ?>" name="venta_ids[]" value="<?= (int) $ventaDisponible['id'] ?>">
                                    </td>
                                    <td class="doc-folio"><strong><?= escaparValores($ventaDisponible['folio']) ?></strong></td>
                                    <td class="doc-fecha"><?= date('d/m/Y', strtotime($ventaDisponible['fecha'])) ?></td>
                                    <td class="tabla--numerica cifra"><?= number_format((int) $ventaDisponible['piezas']) ?></td>
                                    <td class="tabla--numerica cifra"><?= number_format((int) $ventaDisponible['entregadas']) ?></td>
                                    <td class="tabla--numerica cifra doc-total"><?= formatoMoneda($ventaDisponible['total']) ?></td>
                                    <td class="doc-estado">
                                        <?php if ($estadoEntrega) : ?>
                                            <span class="badge <?= escaparValores($estadoEntrega['clase']) ?>"><?= escaparValores($etiquetaEntrega) ?></span>
                                        <?php else : ?>
                                            <span class="texto-sm texto-secundario">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="formulario__acciones">
                    <a class="btn btn--secundario" href="/admin/proyectos/detalle?id=<?= $proyectoId ?>">Cancelar</a>
                    <button class="btn btn--primario" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">link</span>
                        <span>Vincular seleccionadas</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php else : ?>
    <section class="tarjeta tarjeta--sin-relleno">
        <div class="tabla-toolbar">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">assignment</span>
                Proyectos activos del cliente
            </h2>
            <span class="badge badge--neutro badge--sin-punto"><?= number_format(count($proyectosDisponibles)) ?> proyecto(s)</span>
        </div>

        <?php if (!$proyectosDisponibles) : ?>
            <div class="vacio">
                <span class="material-symbols-outlined vacio__icono" aria-hidden="true">assignment</span>
                <p class="vacio__titulo">Sin proyectos activos</p>
                <p>Este cliente no tiene proyectos activos o pausados en este negocio.</p>
                <a class="btn btn--primario" href="/admin/proyectos/crear?cliente=<?= (int) $venta->cliente_id ?>">Crear proyecto</a>
            </div>
        <?php else : ?>
            <form action="/admin/proyectos/vincular?venta=<?= $ventaId ?>" method="post" data-validar>
                <input type="hidden" name="accion" value="vincular_venta">
                <input type="hidden" name="venta_id" value="<?= $ventaId ?>">
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <caption class="solo-lectores">Proyectos disponibles para la venta</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="tabla--centro"><span class="solo-lectores">Elegir</span></th>
                                <th scope="col">Proyecto</th>
                                <th scope="col">Fecha compromiso</th>
                                <th scope="col">Estado</th>
                                <th scope="col" class="tabla--numerica">Comprometido</th>
                                <th scope="col" class="tabla--numerica">Entregado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($proyectosDisponibles as $proyectoDisponible) :
                                $avance = \App\ProyectosEntrega::progreso((int) $proyectoDisponible->id, false);
                                $estadoProyecto = \App\ProyectosEntrega::datosEstado((string) $proyectoDisponible->estado);
                                ?>
                                <tr>
                                    <td class="tabla--centro">
                                        <input type="radio" name="proyecto_destino" value="<?= (int) $proyectoDisponible->id ?>"
                                               id="proyecto-<?= (int) $proyectoDisponible->id ?>"
                                               <?= (int) $venta->proyecto_id === (int) $proyectoDisponible->id ? 'checked' : '' ?>
                                               required>
                                        <label class="solo-lectores" for="proyecto-<?= (int) $proyectoDisponible->id ?>">Elegir <?= escaparValores($proyectoDisponible->folio) ?></label>
                                    </td>
                                    <td>
                                        <strong><?= escaparValores($proyectoDisponible->folio) ?></strong><br>
                                        <span class="texto-sm"><?= escaparValores($proyectoDisponible->nombre) ?></span>
                                    </td>
                                    <td><?= $proyectoDisponible->fecha_compromiso ? date('d/m/Y', strtotime($proyectoDisponible->fecha_compromiso)) : '—' ?></td>
                                    <td><span class="badge <?= escaparValores($estadoProyecto['clase']) ?>"><?= escaparValores($estadoProyecto['etiqueta']) ?></span></td>
                                    <td class="tabla--numerica cifra"><?= number_format((int) $avance['totales']['comprometido']) ?></td>
                                    <td class="tabla--numerica cifra"><?= number_format((int) $avance['totales']['entregado']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="formulario__acciones">
                    <a class="btn btn--secundario" href="/admin/ventas/detalle?id=<?= $ventaId ?>">Cancelar</a>
                    <button class="btn btn--primario" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">link</span>
                        <span>Vincular al proyecto</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
