<?php
/**
 * views/admin/pedidos/index.php — Pedidos de clientes por negocio.
 *
 * Tabla: N° pedido, cliente, productos con cantidades, importe sin IVA,
 * importe con IVA, existencias y acciones (marcar como cotización / venta).
 * Las conversiones se hacen por POST con confirmación modal; ver App\Pedidos.
 */

soloAdmin();

\App\Pedidos::sincronizarConVentas();

$empresas = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static fn ($e): bool => (int) $e->activa === 1
));

$empresaId = (int) ($_GET['empresa'] ?? 0);
$estado    = (string) ($_GET['estado'] ?? 'abiertos');
if ($estado !== 'abiertos' && $estado !== 'todos' && !in_array($estado, \App\Pedidos::ESTADOS, true)) {
    $estado = 'abiertos';
}
$termino = trim((string) ($_GET['q'] ?? ''));

$filtros = ['empresa_id' => $empresaId, 'estado' => $estado === 'todos' ? '' : $estado, 'q' => $termino];
$total   = \App\Pedidos::contar($filtros);
$pagina  = paginacion(15, $total);
$pedidos = \App\Pedidos::listar($filtros, $pagina['limite'], $pagina['offset']);
$pendientes = \App\Pedidos::pendientesPorEmpresa();

/** URL del listado con los filtros actuales y los cambios indicados. */
$url = static function (array $cambios = []) use ($empresaId, $estado, $termino): string {
    $p = array_merge(['empresa' => $empresaId ?: null, 'estado' => $estado !== 'abiertos' ? $estado : null, 'q' => $termino !== '' ? $termino : null], $cambios);
    $p = array_filter($p, static fn ($v) => $v !== null && $v !== '' && $v !== 0);
    return '/admin/pedidos' . ($p ? '?' . http_build_query($p) : '');
};

$estadosFiltro = ['abiertos' => 'Abiertos', 'pendiente' => 'Pendientes', 'cotizado' => 'Cotizados',
    'vendido' => 'Vendidos', 'cancelado' => 'Cancelados', 'todos' => 'Todos'];

$rutaNuevo = '/admin/pedidos/crear' . ($empresaId > 0 ? '?empresa=' . $empresaId : '');

template('header', [
    'titulo'     => 'Pedidos',
    'subtitulo'  => 'Pedidos de clientes por negocio',
    'rutaActiva' => '/admin/pedidos',
    'acciones'   => '<a class="btn btn--primario" href="' . $rutaNuevo . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_shopping_cart</span>'
        . '<span>Nuevo pedido</span></a>',
]);
?>

<!-- Negocios: cada chip filtra la tabla; el número son los pedidos pendientes. -->
<nav class="chip-grupo mb-md" aria-label="Negocio">
    <a class="chip<?= $empresaId === 0 ? ' chip--activo' : '' ?>" href="<?= escaparValores($url(['empresa' => null])) ?>"
       <?= $empresaId === 0 ? 'aria-current="page"' : '' ?>>
        <span>Todos los negocios</span>
        <?php if (array_sum($pendientes) > 0) : ?><span class="badge badge--pendiente badge--sin-punto cifra"><?= array_sum($pendientes) ?></span><?php endif; ?>
    </a>
    <?php foreach ($empresas as $empresa) : $activo = $empresaId === (int) $empresa->id; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= escaparValores($url(['empresa' => (int) $empresa->id])) ?>"
           <?= $activo ? 'aria-current="page"' : '' ?>>
            <span><?= escaparValores($empresa->nombre) ?></span>
            <?php if (!empty($pendientes[(int) $empresa->id])) : ?>
                <span class="badge badge--pendiente badge--sin-punto cifra"><?= (int) $pendientes[(int) $empresa->id] ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/pedidos" method="get" role="search" data-validar>
            <?php if ($empresaId > 0) : ?><input type="hidden" name="empresa" value="<?= $empresaId ?>"><?php endif; ?>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar pedido</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por folio o cliente…" value="<?= escaparValores($termino) ?>">
            </div>
            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <?php foreach ($estadosFiltro as $clave => $texto) : ?>
                    <option value="<?= $clave ?>"<?= $estado === $clave ? ' selected' : '' ?>><?= $texto ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn--secundario btn--sm" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">filter_list</span>
                <span>Filtrar</span>
            </button>
        </form>

        <a class="btn btn--primario btn--sm" href="<?= escaparValores($rutaNuevo) ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
            <span>Crear pedido</span>
        </a>
    </div>

    <?php if (empty($pedidos)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">shopping_bag</span>
            <p class="vacio__titulo">Sin pedidos</p>
            <p>No hay pedidos con estos filtros.</p>
            <a class="btn btn--primario" href="<?= escaparValores($rutaNuevo) ?>">Crear pedido</a>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Pedidos de clientes</caption>
                <thead>
                    <tr>
                        <th scope="col">N° pedido</th>
                        <th scope="col">Cliente</th>
                        <th scope="col">Productos (cantidad solicitada)</th>
                        <th scope="col" class="tabla--numerica">Importe sin IVA</th>
                        <th scope="col" class="tabla--numerica">Importe con IVA</th>
                        <th scope="col" class="tabla--centro">Existencias</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pedidos as $pedido) :
                    $datosEstado = \App\Pedidos::datosEstado($pedido->estado);
                    $sinIva  = round((float) $pedido->subtotal - (float) $pedido->descuento, 2);
                    $faltan  = \App\Pedidos::faltantes($pedido->renglones);
                    $cliente = trim((string) ($pedido->cliente_empresa ?: $pedido->cliente_alias ?: ''));
                    $abierto = in_array($pedido->estado, ['pendiente', 'cotizado'], true);
                    ?>
                    <tr>
                        <td>
                            <div class="tabla__producto-textos">
                                <a href="/admin/pedidos/detalle?id=<?= (int) $pedido->id ?>"><strong class="cifra"><?= escaparValores($pedido->folio) ?></strong></a>
                                <span class="texto-xs texto-secundario">
                                    <?= date('d/m/Y', strtotime((string) $pedido->fecha)) ?>
                                    <?= $empresaId === 0 ? ' · ' . escaparValores($pedido->empresa_nombre) : '' ?>
                                    <?= $pedido->origen === 'portal' ? ' · Portal' : '' ?>
                                </span>
                                <span><span class="badge <?= $datosEstado['clase'] ?>"><?= $datosEstado['etiqueta'] ?></span></span>
                            </div>
                        </td>
                        <td>
                            <div class="tabla__producto-textos">
                                <strong><?= escaparValores($pedido->cliente_nombre) ?></strong>
                                <?php if ($cliente !== '') : ?><span class="texto-xs texto-secundario"><?= escaparValores($cliente) ?></span><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <ul class="texto-sm" style="margin:0;padding-left:1rem">
                                <?php foreach ($pedido->renglones as $r) : ?>
                                    <li><strong class="cifra"><?= (int) $r['cantidad'] ?></strong> <?= escaparValores((string) $r['producto_unidad']) ?> ×
                                        <?= escaparValores($r['producto_nombre']) ?><?= trim((string) $r['producto_sku']) !== '' ? ' <span class="texto-secundario">(' . escaparValores($r['producto_sku']) . ')</span>' : '' ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($sinIva) ?></td>
                        <td class="tabla--numerica cifra"><strong><?= formatoMoneda($pedido->total) ?></strong></td>
                        <td class="tabla--centro">
                            <?php if (!$abierto) : ?>
                                <span class="texto-sm texto-secundario">—</span>
                            <?php elseif ($faltan === []) : ?>
                                <span class="badge badge--aprobado">Alcanza</span>
                            <?php else : ?>
                                <span class="badge badge--error" title="<?= escaparValores(implode("\n", array_map(
                                    static fn ($f) => $f['nombre'] . ': pide ' . $f['requerido'] . ', hay ' . $f['disponible'], $faltan))) ?>">
                                    Faltan <?= count($faltan) ?> <?= count($faltan) === 1 ? 'producto' : 'productos' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla--numerica">
                            <div class="tabla__acciones">
                                <?php if ($pedido->estado === 'pendiente') : ?>
                                    <form action="/admin/pedidos/cotizar" method="post" data-validar
                                          data-confirmacion-sobre="Pedido <?= escaparValores($pedido->folio) ?>"
                                          data-confirmacion="¿Marcar como cotización?"
                                          data-confirmacion-mensaje="Se creará una cotización pendiente con los mismos productos, precios e IVA."
                                          data-confirmacion-boton="Sí, crear cotización">
                                        <input type="hidden" name="id" value="<?= (int) $pedido->id ?>">
                                        <button type="submit" class="btn btn--secundario btn--sm" title="Marcar como cotización">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">request_quote</span>
                                            <span>Cotización</span>
                                        </button>
                                    </form>
                                <?php elseif ($pedido->cotizacion_id) : ?>
                                    <a class="btn btn--texto btn--sm" href="/admin/cotizaciones/detalle?id=<?= (int) $pedido->cotizacion_id ?>"
                                       title="Ver la cotización"><?= escaparValores($pedido->cotizacion_folio) ?></a>
                                <?php endif; ?>

                                <?php if ($abierto) : ?>
                                    <form action="/admin/pedidos/vender" method="post" data-validar
                                          data-confirmacion-sobre="Pedido <?= escaparValores($pedido->folio) ?>"
                                          data-confirmacion="¿Marcar como venta?"
                                          data-confirmacion-mensaje="<?= $pedido->cotizacion_id
                                              ? 'La cotización ' . escaparValores($pedido->cotizacion_folio) . ' pasará a vendida'
                                              : 'Se registrará una venta directa' ?>: se descuenta el inventario y nace el ingreso en Contabilidad.<?= $faltan !== [] ? ' Ojo: no alcanza el inventario, quedará en negativo.' : '' ?>"
                                          data-confirmacion-boton="Sí, vender">
                                        <input type="hidden" name="id" value="<?= (int) $pedido->id ?>">
                                        <button type="submit" class="btn btn--primario btn--sm" title="Marcar como venta">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">point_of_sale</span>
                                            <span>Venta</span>
                                        </button>
                                    </form>
                                <?php elseif ($pedido->venta_id) : ?>
                                    <a class="btn btn--texto btn--sm" href="/admin/ventas/detalle?id=<?= (int) $pedido->venta_id ?>"
                                       title="Ver la venta"><?= escaparValores($pedido->venta_folio) ?></a>
                                <?php endif; ?>

                                <a class="btn btn--secundario btn--sm btn--icono" href="/admin/pedidos/detalle?id=<?= (int) $pedido->id ?>"
                                   title="Ver" aria-label="Ver el pedido <?= escaparValores($pedido->folio) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                                </a>
                                <?php if ($pedido->estado === 'pendiente') : ?>
                                    <a class="btn btn--secundario btn--sm btn--icono" href="/admin/pedidos/editar?id=<?= (int) $pedido->id ?>"
                                       title="Editar" aria-label="Editar el pedido <?= escaparValores($pedido->folio) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>
                                <?php endif; ?>
                                <?php if (!$pedido->venta_id && $pedido->estado !== 'vendido') : ?>
                                    <form action="/admin/pedidos/eliminar" method="post" data-validar
                                          data-confirmacion-sobre="Eliminar pedido <?= escaparValores($pedido->folio) ?>"
                                          data-confirmacion="¿Eliminar este pedido?"
                                          data-confirmacion-mensaje="Se borrará también del portal del cliente y no se puede deshacer.<?= $pedido->cotizacion_id ? ' La cotización ' . escaparValores($pedido->cotizacion_folio) . ' se conserva.' : '' ?>"
                                          data-confirmacion-boton="Sí, eliminar">
                                        <input type="hidden" name="id" value="<?= (int) $pedido->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar el pedido <?= escaparValores($pedido->folio) ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $url(), 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
