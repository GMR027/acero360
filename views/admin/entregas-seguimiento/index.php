<?php
/**
 * views/admin/entregas-seguimiento/index.php — Seguimiento de entregas · listado.
 *
 * Todas las ventas registradas con su ESTADO GENERAL de entrega. Desde aquí se
 * abre el seguimiento de cada venta: fecha y observaciones, entregas, incidencias
 * (devoluciones, cambios, producto faltante…) y la corrección de la factura.
 */

soloAdmin();

$filtros = [
    'estado'  => (string) ($_GET['estado'] ?? ''),
    'q'       => trim($_GET['q'] ?? ''),
    'empresa' => (int) ($_GET['empresa'] ?? 0),
];
$total  = \App\IncidenciasEntrega::contarListado($filtros);
$pagina = paginacion(15, $total);
$ventas = \App\IncidenciasEntrega::listar($filtros, $pagina['limite'], $pagina['offset']);
$conteo = \App\IncidenciasEntrega::conteoEstados();
$empresas = \App\Empresas::mostrarTodos(100, 0);

$parametros = array_filter([
    'estado'  => in_array($filtros['estado'], \App\IncidenciasEntrega::FILTROS_ENTREGA, true) ? $filtros['estado'] : '',
    'q'       => $filtros['q'],
    'empresa' => $filtros['empresa'] ?: '',
], static fn ($v) => $v !== '' && $v !== null);
$rutaBase = '/admin/entregas-seguimiento' . ($parametros ? '?' . http_build_query($parametros) : '');

template('header', ['titulo' => 'Seguimiento de entregas', 'subtitulo' => 'Qué pasó con cada venta después de entregarla']);
template('tabsEntregas', ['tab' => 'seguimiento']);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <nav class="chip-grupo ajustes-tabs" aria-label="Filtrar por estado">
            <a class="chip<?= $filtros['estado'] === '' ? ' chip--activo' : '' ?>" href="/admin/entregas-seguimiento">
                <span>Todas · <?= number_format(array_sum($conteo)) ?></span>
            </a>
            <?php foreach (\App\IncidenciasEntrega::ESTADOS_ENTREGA as $clave => $definicion) : ?>
                <a class="chip<?= $filtros['estado'] === $clave ? ' chip--activo' : '' ?>" href="/admin/entregas-seguimiento?estado=<?= $clave ?>">
                    <span><?= escaparValores($definicion['etiqueta']) ?> · <?= number_format($conteo[$clave]) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/entregas-seguimiento" method="get" role="search">
            <input type="hidden" name="estado" value="<?= escaparValores($filtros['estado']) ?>">
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
            <?php if ($parametros) : ?><a class="btn btn--texto btn--sm" href="/admin/entregas-seguimiento">Limpiar</a><?php endif; ?>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> venta<?= $total === 1 ? '' : 's' ?> con seguimiento</span>
    </div>

    <?php if (!$ventas) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">fact_check</span>
            <p class="vacio__titulo"><?= $parametros ? 'Sin coincidencias' : 'Aún no hay ventas con seguimiento de entrega' ?></p>
            <p>El seguimiento aparece después de que la venta se entrega en la <a href="/admin/entregas">pantalla de entrega</a>: su estado, las devoluciones y cambios de cada entrega.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Seguimiento de entregas de las ventas registradas</caption>
                <thead><tr>
                    <th scope="col">Venta</th><th scope="col">Cliente</th><th scope="col" class="movil-oculto">Negocio</th>
                    <th scope="col">Estado de la entrega</th><th scope="col" class="movil-oculto">Fecha de entrega</th>
                    <th scope="col" class="tabla--numerica">Entregado</th><th scope="col" class="tabla--centro">Seguir</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($ventas as $v) :
                        $definicion = \App\IncidenciasEntrega::ESTADOS_ENTREGA[$v['entrega_estado']] ?? null;
                        $vendidas   = (int) $v['vendidas'];
                        $entregadas = (int) $v['entregadas'];
                        $pendiente  = empty($v['entrega_cerrada_en']) ? max(0, $vendidas - $entregadas) : 0;
                        ?>
                        <tr>
                            <td>
                                <a href="/admin/entregas-seguimiento/venta?id=<?= (int) $v['id'] ?>"><strong><?= escaparValores($v['folio']) ?></strong></a>
                                <span class="texto-xs texto-secundario movil-oculto"><br><?= date('d/m/Y', strtotime($v['fecha'])) ?> · <?= escaparValores('$' . number_format((float) $v['total'], 2)) ?></span>
                            </td>
                            <td><?= escaparValores($v['cliente_empresa'] ?: $v['cliente_nombre']) ?>
                                <span class="texto-xs texto-secundario movil-oculto"><br><?= escaparValores((string) $v['cliente_ubicacion']) ?></span></td>
                            <td class="movil-oculto"><?= escaparValores($v['empresa_nombre']) ?></td>
                            <td>
                                <?php if ($definicion) : ?>
                                    <span class="badge <?= $definicion['clase'] ?>"><?= escaparValores($definicion['etiqueta']) ?></span>
                                <?php endif; ?>
                                <?php if ((int) $v['incidencias_abiertas'] > 0) : ?>
                                    <span class="badge badge--error"><?= (int) $v['incidencias_abiertas'] ?> incidencia<?= (int) $v['incidencias_abiertas'] === 1 ? '' : 's' ?> abierta<?= (int) $v['incidencias_abiertas'] === 1 ? '' : 's' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="movil-oculto"><?= $v['entrega_fecha'] ? date('d/m/Y', strtotime($v['entrega_fecha'])) : '<span class="texto-secundario">—</span>' ?></td>
                            <td class="tabla--numerica cifra"><?= $entregadas ?> <span class="texto-xs texto-secundario">de <?= $vendidas ?></span>
                                <?php if ($pendiente > 0) : ?><span class="texto-xs texto-secundario"><br>faltan <?= $pendiente ?></span><?php endif; ?>
                            </td>
                            <td class="tabla--centro">
                                <a class="btn btn--secundario btn--sm" href="/admin/entregas-seguimiento/venta?id=<?= (int) $v['id'] ?>">Ver seguimiento</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>
<?php template('footer'); ?>
