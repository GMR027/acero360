<?php
/**
 * views/admin/entregas/index.php — Pantalla de entrega · Preparación de pedidos.
 *
 * Lista las ventas confirmadas (directas y nacidas de cotización) con piezas
 * por entregar. Al marcar varias, el resumen agrupa los productos iguales y
 * suma sus cantidades. Crear una preparación NO toca el inventario.
 */

soloAdmin();

$filtros = [
    'q'       => trim($_GET['q'] ?? ''),
    'empresa' => (int) ($_GET['empresa'] ?? 0),
];
$total  = \App\Entregas::contarVentasPendientes($filtros);
$pagina = paginacion(15, $total);
$ventas = \App\Entregas::ventasPendientes($filtros, $pagina['limite'], $pagina['offset']);
$lineas = \App\Entregas::pendientesDeVentas(array_column($ventas, 'id'));
$empresas = \App\Empresas::mostrarTodos(100, 0);

$estadoPrep = $_GET['estado'] ?? '';
$totalPrep  = \App\Entregas::contarPreparaciones($estadoPrep);
$paginaPrep = paginacion(8, $totalPrep, 'pagina_prep');
$preparaciones = \App\Entregas::listarPreparaciones($estadoPrep, $paginaPrep['limite'], $paginaPrep['offset']);

$parametros = array_filter(['q' => $filtros['q'], 'empresa' => $filtros['empresa'] ?: ''], static fn ($v) => $v !== '');
$rutaBase = '/admin/entregas' . ($parametros ? '?' . http_build_query($parametros) : '');

template('header', ['titulo' => 'Pantalla de entrega', 'subtitulo' => 'Preparación de pedidos y entregas']);
template('tabsEntregas', ['tab' => 'preparacion']);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/entregas" method="get" role="search">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar venta</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Folio o cliente…" value="<?= escaparValores($filtros['q']) ?>">
            </div>
            <label class="solo-lectores" for="empresa">Negocio</label>
            <select class="control control--compacto" id="empresa" name="empresa">
                <option value="0">Todos los negocios</option>
                <?php foreach ($empresas as $e) : ?>
                    <option value="<?= (int) $e->id ?>"<?= $filtros['empresa'] === (int) $e->id ? ' selected' : '' ?>><?= escaparValores($e->nombre) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn--secundario btn--sm" type="submit">Buscar</button>
            <?php if ($parametros) : ?><a class="btn btn--texto btn--sm" href="/admin/entregas">Limpiar</a><?php endif; ?>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> <?= $total === 1 ? 'venta por entregar' : 'ventas por entregar' ?></span>
    </div>

    <?php if (!$ventas) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">inventory</span>
            <p class="vacio__titulo"><?= $parametros ? 'Sin coincidencias' : 'No hay ventas por entregar' ?></p>
            <p>Solo aparecen ventas confirmadas (ventas directas o cotizaciones marcadas como vendidas).</p>
        </div>
    <?php else : ?>
        <form action="/admin/entregas/crear" method="post" data-seleccion-ventas>
            <div class="tabla-contenedor">
                <table class="tabla">
                    <caption class="solo-lectores">Ventas confirmadas por entregar</caption>
                    <thead><tr>
                        <th scope="col" class="tabla--centro">
                            <label class="solo-lectores" for="marcar-todas">Seleccionar todas</label>
                            <input type="checkbox" id="marcar-todas" data-marcar-todas>
                        </th>
                        <th scope="col">Venta</th><th scope="col">Cliente</th><th scope="col">Negocio</th>
                        <th scope="col" class="tabla--numerica">Por entregar</th><th scope="col">Preparación</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($ventas as $v) :
                            $grupo = array_map(static fn ($l) => [
                                'id' => (int) $l['producto_id'], 'sku' => (string) $l['sku'], 'nombre' => (string) $l['nombre'],
                                'imagen' => $l['imagen'] ? \App\Imagenes::urlMiniatura($l['imagen']) : '',
                                'pendiente' => (int) $l['pendiente'],
                            ], array_values($lineas[(int) $v['id']] ?? []));
                            $pendiente = (int) $v['piezas'] - (int) $v['entregadas'];
                            ?>
                            <tr>
                                <td class="tabla--centro">
                                    <label class="solo-lectores" for="venta-<?= (int) $v['id'] ?>">Seleccionar <?= escaparValores($v['folio']) ?></label>
                                    <input class="entregas-seleccion" type="checkbox" id="venta-<?= (int) $v['id'] ?>" name="ventas[]"
                                           value="<?= (int) $v['id'] ?>"
                                           data-lineas="<?= escaparValores(json_encode($grupo, JSON_UNESCAPED_UNICODE)) ?>">
                                </td>
                                <td>
                                    <a href="/admin/ventas/detalle?id=<?= (int) $v['id'] ?>"><strong><?= escaparValores($v['folio']) ?></strong></a>
                                    <span class="texto-xs texto-secundario"><br><?= $v['cotizacion_id'] ? 'Desde cotización' : 'Venta directa' ?> · <?= date('d/m/Y', strtotime($v['fecha'])) ?></span>
                                </td>
                                <td><?= escaparValores($v['cliente_empresa'] ?: $v['cliente_nombre']) ?>
                                    <span class="texto-xs texto-secundario"><br><?= escaparValores((string) $v['cliente_ubicacion']) ?></span></td>
                                <td><?= escaparValores($v['empresa_nombre']) ?></td>
                                <td class="tabla--numerica cifra"><?= $pendiente ?> <?= (int) $v['entregadas'] > 0 ? '<span class="texto-xs texto-secundario">de ' . (int) $v['piezas'] . '</span>' : '' ?></td>
                                <td><?= $v['en_preparaciones']
                                    ? '<span class="badge badge--pendiente">En ' . escaparValores($v['en_preparaciones']) . '</span>'
                                    : '<span class="texto-secundario">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="entregas-resumen" data-resumen-agrupado hidden>
                <h2 class="titulo-5" data-resumen-contador></h2>
                <p class="campo__ayuda mb-md">Productos iguales agrupados y sumados:</p>
                <div class="entregas-rejilla" data-resumen-lista></div>
                <div class="campo mt-md">
                    <label class="campo__etiqueta" for="notas">Notas de la preparación (opcional)</label>
                    <input class="control" type="text" id="notas" name="notas" maxlength="255" placeholder="Ej. ruta norte, sale el lunes">
                </div>
                <div class="formulario__acciones">
                    <button class="btn btn--primario" type="submit" data-crear-preparacion disabled>Crear preparación</button>
                </div>
            </div>
        </form>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno mt-lg">
    <div class="tabla-toolbar">
        <h2 class="titulo-5">Preparaciones</h2>
        <form action="/admin/entregas" method="get">
            <?php foreach ($parametros as $k => $val) : ?><input type="hidden" name="<?= escaparValores($k) ?>" value="<?= escaparValores((string) $val) ?>"><?php endforeach; ?>
            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado" onchange="this.form.requestSubmit()">
                <option value="">Todos los estados</option>
                <?php foreach (\App\Entregas::ESTADOS as $clave => $d) : ?>
                    <option value="<?= $clave ?>"<?= $estadoPrep === $clave ? ' selected' : '' ?>><?= escaparValores($d['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if (!$preparaciones) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no hay preparaciones.</p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Preparaciones</caption>
                <thead><tr><th scope="col">Folio</th><th scope="col" class="tabla--centro">Estado</th>
                    <th scope="col" class="tabla--numerica">Ventas</th><th scope="col">Creada</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($preparaciones as $p) : $est = \App\Entregas::ESTADOS[$p['estado']]; ?>
                        <tr>
                            <td><strong><?= escaparValores($p['folio']) ?></strong>
                                <?= $p['notas'] ? '<span class="texto-xs texto-secundario"><br>' . escaparValores($p['notas']) . '</span>' : '' ?></td>
                            <td class="tabla--centro"><span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= (int) $p['ventas'] ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($p['creado_en'])) ?></td>
                            <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/entregas/detalle?id=<?= (int) $p['id'] ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php template('paginacion', ['pagina' => $paginaPrep, 'rutaBase' => '/admin/entregas' . ($estadoPrep ? '?estado=' . urlencode($estadoPrep) : ''), 'total' => $totalPrep]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
