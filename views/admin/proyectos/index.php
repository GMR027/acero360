<?php
/**
 * views/admin/proyectos/index.php — Proyectos de entrega por cliente.
 *
 * Lista los compromisos (por ejemplo, 2,000 canastillas para una vialidad) y
 * muestra su avance real: comprometido, vendido y entregado. El avance se
 * calcula con las ventas vinculadas y sus entregas; no se captura dos veces.
 */

soloAdmin();

$filtros = [
    'q'          => trim((string) ($_GET['q'] ?? '')),
    'cliente_id' => (int) ($_GET['cliente'] ?? 0),
    'empresa_id' => (int) ($_GET['empresa'] ?? 0),
    'estado'     => (string) ($_GET['estado'] ?? ''),
    'anio'       => (int) ($_GET['anio'] ?? 0),
    'vencidos'   => !empty($_GET['vencidos']) ? 1 : 0,
];

if (!isset(\App\ProyectosEntrega::ESTADOS[$filtros['estado']])) {
    $filtros['estado'] = '';
}
if ($filtros['anio'] < 2000 || $filtros['anio'] > 2100) {
    $filtros['anio'] = 0;
}

$total     = \App\ProyectosEntrega::contar($filtros);
$pagina    = paginacion(15, $total);
$proyectos = \App\ProyectosEntrega::listar($filtros, $pagina['limite'], $pagina['offset']);

$clientes = \App\Clientes::mostrarTodos(1000, 0, '');
$empresas = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static fn ($empresa): bool => (int) $empresa->activa === 1
));

$clienteFiltro = $filtros['cliente_id'] > 0 ? \App\Clientes::buscarID($filtros['cliente_id']) : null;
$anios = range((int) date('Y'), (int) date('Y') - 5);

$parametros = [
    'q'        => $filtros['q'],
    'cliente'  => $filtros['cliente_id'] ?: '',
    'empresa'  => $filtros['empresa_id'] ?: '',
    'estado'   => $filtros['estado'],
    'anio'     => $filtros['anio'] ?: '',
    'vencidos' => $filtros['vencidos'] ?: '',
];
$parametros = array_filter($parametros, static fn ($v): bool => $v !== '' && $v !== 0);
$urlBase = '/admin/proyectos' . ($parametros ? '?' . http_build_query($parametros) : '');

$activos = 0;
$vencidos = 0;
$conPendientes = 0;
foreach ($proyectos as $proyecto) {
    $progreso = \App\ProyectosEntrega::progreso((int) $proyecto->id, false);
    $proyecto->progreso = $progreso;
    if ($proyecto->estado === 'activo') {
        $activos++;
    }
    if ((int) $progreso['totales']['por_entregar'] > 0) {
        $conPendientes++;
    }
    if ($proyecto->fecha_compromiso !== null && $proyecto->fecha_compromiso !== ''
        && $proyecto->fecha_compromiso < date('Y-m-d')
        && in_array($proyecto->estado, ['activo', 'pausado'], true)
        && (int) $progreso['totales']['por_entregar'] > 0) {
        $vencidos++;
    }
}

$titulo = $clienteFiltro
    ? 'Proyectos de ' . ($clienteFiltro->nombre_empresa ?: $clienteFiltro->nombre)
    : 'Proyectos de entrega';

template('header', [
    'titulo'    => $titulo,
    'subtitulo' => 'Compromisos y avance de entrega',
    'rutaActiva'=> '/admin/proyectos',
    'acciones'  => '<a class="btn btn--primario" href="/admin/proyectos/crear'
        . ($clienteFiltro ? '?cliente=' . (int) $clienteFiltro->id : '') . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">assignment_add</span>'
        . '<span>Nuevo proyecto</span></a>',
]);
?>

<?php if ($clienteFiltro) : ?>
    <?php template('tabsCliente', ['clienteId' => (int) $clienteFiltro->id, 'tab' => 'proyectos']); ?>
<?php endif; ?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Un proyecto es lo que el cliente comprometió para una obra. Las ventas se vinculan al proyecto
            y las entregas se registran como siempre; el avance se actualiza solo.
        </p>
    </div>
</section>

<section class="rejilla-stats mb-lg" aria-label="Resumen de proyectos">
    <div class="stat">
        <span class="stat__etiqueta">Proyectos activos</span>
        <span class="stat__valor"><?= number_format($activos) ?></span>
        <span class="stat__pie">En esta página</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Con entregas pendientes</span>
        <span class="stat__valor"><?= number_format($conPendientes) ?></span>
        <span class="stat__pie">En esta página</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Vencidos</span>
        <span class="stat__valor"><?= number_format($vencidos) ?></span>
        <span class="stat__pie">En esta página</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Proyectos</span>
        <span class="stat__valor"><?= number_format($total) ?></span>
        <span class="stat__pie">Con los filtros actuales</span>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador buscador--filtros" action="/admin/proyectos" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar proyecto</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Folio, proyecto o cliente…" value="<?= escaparValores($filtros['q']) ?>">
            </div>

            <label class="solo-lectores" for="cliente">Cliente</label>
            <select class="control control--compacto" id="cliente" name="cliente">
                <option value="0">Todos los clientes</option>
                <?php foreach ($clientes as $cliente) : ?>
                    <option value="<?= (int) $cliente->id ?>" <?= $filtros['cliente_id'] === (int) $cliente->id ? 'selected' : '' ?>>
                        <?= escaparValores(trim((string) $cliente->alias) !== '' ? $cliente->alias : $cliente->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="empresa">Negocio</label>
            <select class="control control--compacto" id="empresa" name="empresa">
                <option value="0">Todos los negocios</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= (int) $empresa->id ?>" <?= $filtros['empresa_id'] === (int) $empresa->id ? 'selected' : '' ?>>
                        <?= escaparValores($empresa->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="">Todos los estados</option>
                <?php foreach (\App\ProyectosEntrega::ESTADOS as $clave => $datos) : ?>
                    <option value="<?= escaparValores($clave) ?>" <?= $filtros['estado'] === $clave ? 'selected' : '' ?>>
                        <?= escaparValores($datos['etiqueta']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="anio">Año</label>
            <select class="control control--compacto" id="anio" name="anio">
                <option value="0">Todos</option>
                <?php foreach ($anios as $anio) : ?>
                    <option value="<?= (int) $anio ?>" <?= $filtros['anio'] === (int) $anio ? 'selected' : '' ?>><?= (int) $anio ?></option>
                <?php endforeach; ?>
            </select>

            <label class="comprobacion" for="vencidos">
                <input type="checkbox" id="vencidos" name="vencidos" value="1" <?= $filtros['vencidos'] ? 'checked' : '' ?>>
                <span>Solo vencidos</span>
            </label>

            <button type="submit" class="btn btn--secundario btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">filter_list</span>
                <span>Buscar</span>
            </button>

            <?php if ($parametros) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/proyectos">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'proyecto' : 'proyectos' ?>
        </span>
    </div>

    <?php if (!$proyectos) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">assignment</span>
            <p class="vacio__titulo"><?= $parametros ? 'Sin coincidencias' : 'Aún no hay proyectos' ?></p>
            <p><?= $parametros
                    ? 'Ningún proyecto coincide con los filtros.'
                    : 'Crea un proyecto para controlar lo comprometido y lo entregado a un cliente.' ?></p>
            <a class="btn btn--primario" href="/admin/proyectos/crear">Nuevo proyecto</a>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla tabla--documentos">
                <caption class="solo-lectores">Proyectos de entrega</caption>
                <thead>
                    <tr>
                        <th scope="col">Proyecto</th>
                        <th scope="col">Cliente</th>
                        <th scope="col" class="movil-oculto">Negocio</th>
                        <th scope="col">Compromiso</th>
                        <th scope="col">Avance</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proyectos as $proyecto) :
                        $progreso = $proyecto->progreso;
                        $totales = $progreso['totales'];
                        $estado = \App\ProyectosEntrega::datosEstado((string) $proyecto->estado);
                        $clienteNombre = $proyecto->cliente_empresa ?: $proyecto->cliente_nombre;
                        $vencido = $proyecto->fecha_compromiso !== null && $proyecto->fecha_compromiso !== ''
                            && $proyecto->fecha_compromiso < date('Y-m-d')
                            && in_array($proyecto->estado, ['activo', 'pausado'], true)
                            && (int) $totales['por_entregar'] > 0;
                        ?>
                        <tr>
                            <td class="doc-folio">
                                <a href="/admin/proyectos/detalle?id=<?= (int) $proyecto->id ?>"><strong><?= escaparValores($proyecto->folio) ?></strong></a><br>
                                <span class="texto-sm"><?= escaparValores($proyecto->nombre) ?></span>
                                <?php if ($vencido) : ?><br><span class="badge badge--error">Vencido</span><?php endif; ?>
                            </td>
                            <td class="doc-cliente">
                                <span><?= escaparValores($clienteNombre) ?></span>
                                <span class="texto-xs texto-secundario"><?= escaparValores((string) $proyecto->cliente_rfc) ?></span>
                            </td>
                            <td class="movil-oculto doc-negocio"><?= escaparValores($proyecto->empresa_nombre) ?></td>
                            <td>
                                <?php if ($proyecto->fecha_compromiso) : ?>
                                    <span class="texto-sm"><?= date('d/m/Y', strtotime($proyecto->fecha_compromiso)) ?></span>
                                <?php else : ?>
                                    <span class="texto-sm texto-secundario">Sin fecha</span>
                                <?php endif; ?>
                                <span class="texto-xs texto-secundario" style="display:block"><?= number_format(count($progreso['partidas'])) ?> partida(s)</span>
                            </td>
                            <td style="min-width:12rem">
                                <div class="barra-cobro">
                                    <progress value="<?= (float) $totales['avance'] ?>" max="100" aria-label="Avance del proyecto"></progress>
                                    <span class="cifra"><?= number_format((float) $totales['avance'], 1) ?> %</span>
                                </div>
                                <span class="texto-xs texto-secundario">
                                    <?= number_format((int) $totales['entregado']) ?> de <?= number_format((int) $totales['comprometido']) ?>
                                    <?= $totales['unidad_comun'] ? escaparValores((string) $totales['unidad_comun']) : 'unidades' ?>
                                    · faltan <?= number_format((int) $totales['por_entregar']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= escaparValores($estado['clase']) ?>"><?= escaparValores($estado['etiqueta']) ?></span>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--texto btn--sm" href="/admin/proyectos/detalle?id=<?= (int) $proyecto->id ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>
                                        <span>Abrir</span>
                                    </a>
                                    <?php if ($proyecto->estado !== 'cancelado') : ?>
                                        <a class="btn btn--texto btn--sm" href="/admin/proyectos/vincular?proyecto=<?= (int) $proyecto->id ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">link</span>
                                            <span>Ventas</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php template('paginacion', [
            'pagina'   => $pagina,
            'rutaBase' => $urlBase,
            'total'    => $total,
        ]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
