<?php
/**
 * views/admin/proyectos/detalle.php — Estado de un proyecto de entrega.
 *
 * El avance se calcula con las ventas vinculadas y sus entregas reales.
 * Desde aquí se vinculan ventas, se registran entregas (abriendo la venta) y
 * se exporta el estado del proyecto.
 */

soloAdmin();

$id = (int) ($_GET['id'] ?? 0);
$proyecto = \App\ProyectosEntrega::buscarID($id);

if (!$proyecto) {
    paginaNoEncontrada();
}

$progreso = \App\ProyectosEntrega::progreso($id);
$totales  = $progreso['totales'];
$estado   = \App\ProyectosEntrega::datosEstado((string) $proyecto->estado);
$clienteNombre = $proyecto->cliente_empresa ?: $proyecto->cliente_nombre;
$unidad = $totales['unidad_comun'] ?: 'unidades';
$vencido = $proyecto->fecha_compromiso !== null && $proyecto->fecha_compromiso !== ''
    && $proyecto->fecha_compromiso < date('Y-m-d')
    && in_array($proyecto->estado, ['activo', 'pausado'], true)
    && (int) $totales['por_entregar'] > 0;

$m = static fn ($monto): string => formatoMoneda((float) $monto);

template('header', [
    'titulo'            => $proyecto->folio . ' · ' . $proyecto->nombre,
    'subtitulo'         => 'Proyecto de entrega',
    'rutaActiva'        => '/admin/proyectos',
    'mostrarEncabezado' => false,
]);
?>

<?php template('tabsCliente', ['clienteId' => (int) $proyecto->cliente_id, 'tab' => 'proyectos']); ?>

<section class="encabezado-pagina">
    <div class="perfil-fila">
        <div class="encabezado-pagina__texto">
            <h1 class="titulo-2">
                <?= escaparValores($proyecto->nombre) ?>
                <span class="badge <?= escaparValores($estado['clase']) ?>" style="margin-left:0.5rem"><?= escaparValores($estado['etiqueta']) ?></span>
                <?php if ($vencido) : ?><span class="badge badge--error" style="margin-left:0.25rem">Vencido</span><?php endif; ?>
            </h1>
            <p class="texto-secundario">
                <strong><?= escaparValores($proyecto->folio) ?></strong>
                · <?= escaparValores($clienteNombre) ?>
                · <?= escaparValores($proyecto->empresa_nombre) ?>
            </p>
        </div>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/proyectos">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>
            <span>Volver</span>
        </a>
        <a class="btn btn--secundario" href="/admin/proyectos/pdf?id=<?= $id ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
            <span>Exportar PDF</span>
        </a>
        <a class="btn btn--secundario" href="/admin/proyectos/editar?id=<?= $id ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
            <span>Editar</span>
        </a>
        <?php if ($proyecto->estado !== 'cancelado') : ?>
            <a class="btn btn--primario" href="/admin/ventas/crear?cliente=<?= (int) $proyecto->cliente_id ?>&proyecto=<?= $id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">point_of_sale</span>
                <span>Nueva venta del proyecto</span>
            </a>
        <?php endif; ?>
    </div>
</section>

<section class="rejilla-stats mb-lg" aria-label="Avance del proyecto">
    <div class="stat">
        <span class="stat__etiqueta">Comprometido</span>
        <span class="stat__valor"><?= number_format((int) $totales['comprometido']) ?></span>
        <span class="stat__pie"><?= escaparValores($unidad) ?> en <?= count($progreso['partidas']) ?> partida(s)</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Vendido</span>
        <span class="stat__valor"><?= number_format((int) $totales['vendido']) ?></span>
        <span class="stat__pie">En <?= count($progreso['ventas']) ?> venta(s) vinculada(s)</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Entregado</span>
        <span class="stat__valor"><?= number_format((int) $totales['entregado']) ?></span>
        <span class="stat__pie"><?= number_format((float) $totales['avance'], 1) ?> % del compromiso</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Por entregar</span>
        <span class="stat__valor"><?= number_format((int) $totales['por_entregar']) ?></span>
        <span class="stat__pie">Faltan del proyecto</span>
    </div>
</section>

<section class="tarjeta mb-lg">
    <header class="tarjeta__cabecera">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">insights</span>
            Avance del proyecto
        </h2>
        <span class="badge badge--neutro badge--sin-punto"><?= number_format((float) $totales['avance'], 1) ?> %</span>
    </header>
    <div class="tarjeta__cuerpo">
        <div class="barra-cobro mb-md">
            <progress value="<?= (float) $totales['avance'] ?>" max="100" aria-label="Avance del proyecto"></progress>
            <span class="cifra"><?= number_format((int) $totales['entregado']) ?> / <?= number_format((int) $totales['comprometido']) ?> <?= escaparValores($unidad) ?></span>
        </div>
        <div class="rejilla-2">
            <div class="nota">
                <span class="material-symbols-outlined nota__icono" aria-hidden="true">local_shipping</span>
                <span>
                    <strong>Faltan <?= number_format((int) $totales['por_entregar']) ?> <?= escaparValores($unidad) ?></strong> para completar el proyecto.
                    <?php if ((int) $totales['por_vender'] > 0) : ?>
                        De ellas, <?= number_format((int) $totales['por_vender']) ?> todavía no se han vendido.
                    <?php endif; ?>
                </span>
            </div>
            <div class="ficha">
                <div class="ficha__fila"><dt>Por entregar de lo vendido</dt><dd class="cifra"><?= number_format(max(0, (int) $totales['vendido'] - (int) $totales['entregado'])) ?></dd></div>
                <div class="ficha__fila"><dt>Por vender</dt><dd class="cifra"><?= number_format((int) $totales['por_vender']) ?></dd></div>
                <div class="ficha__fila"><dt>Devuelto</dt><dd class="cifra"><?= number_format((int) $totales['devuelto']) ?></dd></div>
                <?php if ((int) $totales['excedente'] > 0) : ?>
                    <div class="ficha__fila"><dt>Excedente entregado</dt><dd class="cifra"><?= number_format((int) $totales['excedente']) ?></dd></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div class="rejilla-2 mb-lg">
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">badge</span>
                Datos del proyecto
            </h2>
        </header>
        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>Cliente</dt><dd><?= escaparValores($clienteNombre) ?></dd></div>
                <div class="ficha__fila"><dt>Contacto</dt><dd><?= escaparValores($proyecto->cliente_nombre) ?></dd></div>
                <div class="ficha__fila"><dt>RFC</dt><dd class="cifra"><?= escaparValores($proyecto->cliente_rfc ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Teléfono</dt><dd class="cifra"><?= escaparValores($proyecto->cliente_telefono ?: '—') ?></dd></div>
                <div class="ficha__fila"><dt>Negocio</dt><dd><?= escaparValores($proyecto->empresa_nombre) ?></dd></div>
                <div class="ficha__fila"><dt>Inicio</dt><dd><?= $proyecto->fecha_inicio ? date('d/m/Y', strtotime($proyecto->fecha_inicio)) : '—' ?></dd></div>
                <div class="ficha__fila"><dt>Fecha compromiso</dt><dd><?= $proyecto->fecha_compromiso ? date('d/m/Y', strtotime($proyecto->fecha_compromiso)) : '—' ?></dd></div>
                <div class="ficha__fila"><dt>Creado por</dt><dd><?= escaparValores($proyecto->usuario_nombre ?: '—') ?></dd></div>
            </dl>
            <?php if (trim((string) $proyecto->descripcion) !== '') : ?>
                <div class="nota mt-md">
                    <span class="material-symbols-outlined nota__icono" aria-hidden="true">notes</span>
                    <span><?= nl2br(escaparValores($proyecto->descripcion)) ?></span>
                </div>
            <?php endif; ?>
            <?php if (trim((string) $proyecto->notas) !== '') : ?>
                <p class="campo__ayuda mt-md"><strong>Notas internas:</strong> <?= nl2br(escaparValores($proyecto->notas)) ?></p>
            <?php endif; ?>
        </div>
    </section>

    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">settings</span>
                Acciones del proyecto
            </h2>
        </header>
        <div class="tarjeta__cuerpo">
            <div class="formulario__acciones" style="justify-content:flex-start; flex-wrap:wrap">
                <?php if ($proyecto->estado !== 'cancelado') : ?>
                    <a class="btn btn--secundario" href="/admin/proyectos/vincular?proyecto=<?= $id ?>">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">link</span>
                        <span>Vincular ventas</span>
                    </a>
                <?php endif; ?>
                <a class="btn btn--secundario" href="/admin/proyectos/pdf?id=<?= $id ?>">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
                    <span>Estado en PDF</span>
                </a>
            </div>

            <form action="/admin/proyectos/accion" method="post" class="mt-md" data-validar>
                <input type="hidden" name="accion" value="estado">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="formulario__acciones" style="justify-content:flex-start; flex-wrap:wrap">
                    <?php if ($proyecto->estado !== 'activo') : ?>
                        <button class="btn btn--secundario btn--sm" type="submit" name="estado" value="activo">Reactivar</button>
                    <?php endif; ?>
                    <?php if ($proyecto->estado === 'activo') : ?>
                        <button class="btn btn--secundario btn--sm" type="submit" name="estado" value="pausado">Pausar</button>
                    <?php endif; ?>
                    <?php if (!in_array($proyecto->estado, ['completado', 'cancelado'], true)) : ?>
                        <button class="btn btn--secundario btn--sm" type="submit" name="estado" value="completado">Marcar completado</button>
                        <button class="btn btn--peligro btn--sm" type="submit" name="estado" value="cancelado">Cancelar proyecto</button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!\App\ProyectosEntrega::tieneVentas($id)) : ?>
                <form action="/admin/proyectos/accion" method="post" class="mt-md" data-validar
                      data-confirmacion-sobre="<?= escaparValores($proyecto->folio) ?>"
                      data-confirmacion="¿Eliminar este proyecto?"
                      data-confirmacion-mensaje="Se borrarán sus partidas. Esta acción no se puede deshacer y sólo está disponible si no tiene ventas vinculadas."
                      data-confirmacion-boton="Sí, eliminar">
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn--peligro btn--sm" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                        <span>Eliminar proyecto</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <div class="tabla-toolbar">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">list_alt</span>
            Partidas y avance por producto
        </h2>
        <span class="badge badge--neutro badge--sin-punto"><?= number_format(count($progreso['partidas'])) ?> partida(s)</span>
    </div>

    <?php if (!$progreso['partidas']) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">list_alt</span>
            <p class="vacio__titulo">Sin partidas</p>
            <p>Edita el proyecto para agregar los productos comprometidos.</p>
            <a class="btn btn--primario" href="/admin/proyectos/editar?id=<?= $id ?>">Agregar partidas</a>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Partidas del proyecto</caption>
                <thead>
                    <tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="tabla--numerica">Comprometido</th>
                        <th scope="col" class="tabla--numerica">Vendido</th>
                        <th scope="col" class="tabla--numerica">Entregado</th>
                        <th scope="col" class="tabla--numerica">Por entregar</th>
                        <th scope="col" class="tabla--numerica">Por vender</th>
                        <th scope="col">Avance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($progreso['partidas'] as $partida) : ?>
                        <tr>
                            <td>
                                <div class="tabla__producto-textos">
                                    <strong><?= escaparValores($partida['nombre']) ?></strong>
                                    <span class="texto-xs texto-secundario">
                                        <?= escaparValores($partida['sku'] ?: '—') ?> · <?= escaparValores($partida['unidad']) ?>
                                        <?php if ($partida['precio_unitario'] !== null) : ?>
                                            · precio acordado <?= $m($partida['precio_unitario']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $partida['comprometido']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $partida['vendido']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $partida['entregado']) ?></td>
                            <td class="tabla--numerica cifra"><strong><?= number_format((int) $partida['por_entregar']) ?></strong></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $partida['por_vender']) ?></td>
                            <td style="min-width:9rem">
                                <div class="barra-cobro">
                                    <progress value="<?= (float) $partida['avance'] ?>" max="100" aria-label="Avance del producto"></progress>
                                    <span class="cifra"><?= number_format((float) $partida['avance'], 0) ?> %</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($progreso['extras']) : ?>
        <div class="tabla-toolbar">
            <h3 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">warning</span>
                Productos vendidos fuera de las partidas
            </h3>
        </div>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Productos vendidos fuera de las partidas</caption>
                <thead>
                    <tr><th scope="col">Producto</th><th scope="col" class="tabla--numerica">Vendido</th><th scope="col" class="tabla--numerica">Entregado</th><th scope="col" class="tabla--numerica">Devuelto</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($progreso['extras'] as $extra) : ?>
                        <tr>
                            <td><?= escaparValores($extra['nombre']) ?> <span class="texto-xs texto-secundario"><?= escaparValores($extra['sku'] ?: '') ?></span></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $extra['vendido']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $extra['entregado']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $extra['devuelto']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="campo__ayuda" style="padding:0 1rem 1rem">
            Estos productos no están en las partidas del proyecto, así que no cuentan para el compromiso.
            Si deben contar, edita el proyecto y agrega la partida correspondiente.
        </p>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <div class="tabla-toolbar">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
            Ventas vinculadas
        </h2>
        <?php if ($proyecto->estado !== 'cancelado') : ?>
            <a class="btn btn--secundario btn--sm" href="/admin/proyectos/vincular?proyecto=<?= $id ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">link</span>
                <span>Vincular ventas</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$progreso['ventas']) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">receipt_long</span>
            <p class="vacio__titulo">Sin ventas vinculadas</p>
            <p>Vincula las ventas existentes del cliente o crea una venta nueva desde el proyecto.</p>
            <?php if ($proyecto->estado !== 'cancelado') : ?>
                <a class="btn btn--primario" href="/admin/proyectos/vincular?proyecto=<?= $id ?>">Vincular ventas</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla tabla--documentos">
                <caption class="solo-lectores">Ventas vinculadas al proyecto</caption>
                <thead>
                    <tr>
                        <th scope="col">Folio</th>
                        <th scope="col">Fecha</th>
                        <th scope="col" class="tabla--numerica">Piezas</th>
                        <th scope="col" class="tabla--numerica">Entregadas</th>
                        <th scope="col" class="tabla--numerica">Pendientes</th>
                        <th scope="col" class="tabla--numerica">Total</th>
                        <th scope="col">Entrega</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($progreso['ventas'] as $venta) :
                        $piezas = (int) $venta['piezas'];
                        $entregadas = (int) $venta['entregadas'];
                        $pendientes = max(0, $piezas - $entregadas);
                        $estadoEntrega = \App\IncidenciasEntrega::ESTADOS_ENTREGA[$venta['entrega_estado'] ?? ''] ?? null;
                        $etiquetaEntrega = [
                            'pendiente' => 'Por entregar',
                            'entregado' => 'Entregado',
                            'entregado_con_incidencias' => 'Con incidencias',
                            'entregado_incidencias_resueltas' => 'Incidencias resueltas',
                        ][$venta['entrega_estado'] ?? ''] ?? ($estadoEntrega['etiqueta'] ?? '—');
                        ?>
                        <tr>
                            <td class="doc-folio">
                                <a href="/admin/ventas/detalle?id=<?= (int) $venta['id'] ?>"><strong><?= escaparValores($venta['folio']) ?></strong></a>
                            </td>
                            <td class="doc-fecha"><?= date('d/m/Y', strtotime($venta['fecha'])) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format($piezas) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format($entregadas) ?></td>
                            <td class="tabla--numerica cifra"><strong><?= number_format($pendientes) ?></strong></td>
                            <td class="tabla--numerica cifra doc-total"><?= $m($venta['total']) ?></td>
                            <td class="doc-estado">
                                <?php if ($estadoEntrega) : ?>
                                    <span class="badge <?= escaparValores($estadoEntrega['clase']) ?>"><?= escaparValores($etiquetaEntrega) ?></span>
                                <?php else : ?>
                                    <span class="texto-sm texto-secundario">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--texto btn--sm" href="/admin/ventas/detalle?id=<?= (int) $venta['id'] ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">fact_check</span>
                                        <span>Expediente</span>
                                    </a>
                                    <form action="/admin/proyectos/accion" method="post" data-validar
                                          data-confirmacion-sobre="<?= escaparValores($venta['folio']) ?>"
                                          data-confirmacion="¿Desvincular esta venta del proyecto?"
                                          data-confirmacion-mensaje="La venta y sus entregas se conservan; sólo dejarán de contar para este proyecto."
                                          data-confirmacion-boton="Sí, desvincular">
                                        <input type="hidden" name="accion" value="desvincular">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="venta_id" value="<?= (int) $venta['id'] ?>">
                                        <button class="btn btn--texto btn--sm" type="submit">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">link_off</span>
                                            <span>Desvincular</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
            Entregas registradas
        </h2>
        <span class="badge badge--neutro badge--sin-punto"><?= number_format(count($progreso['entregas'])) ?> entrega(s)</span>
    </div>

    <?php if (!$progreso['entregas']) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">local_shipping</span>
            <p class="vacio__titulo">Sin entregas</p>
            <p>Las entregas se registran desde la venta, en web o en Acero Entregas.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Entregas de las ventas del proyecto</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Venta</th>
                        <th scope="col" class="tabla--numerica">Piezas</th>
                        <th scope="col">Recibió</th>
                        <th scope="col">Origen</th>
                        <th scope="col">Notas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($progreso['entregas'] as $entrega) : ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($entrega['fecha'])) ?></td>
                            <td><a href="/admin/ventas/detalle?id=<?= (int) $entrega['venta_id'] ?>"><?= escaparValores($entrega['folio']) ?></a></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $entrega['piezas']) ?></td>
                            <td><?= escaparValores($entrega['recibio_nombre'] ?: '—') ?></td>
                            <td><span class="badge badge--neutro"><?= ($entrega['origen'] ?? 'web') === 'app' ? 'App' : 'Web' ?></span></td>
                            <td class="texto-sm texto-secundario"><?= escaparValores($entrega['notas'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
