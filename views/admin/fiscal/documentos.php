<?php
/**
 * views/admin/fiscal/documentos.php — Documentos fiscales por situación: pendientes, CFDI emitidos,
 * cancelados, por validar y ventas sin facturación. Solo lectura; la revisión es documental, no ante el SAT.
 */

soloAdmin();

$periodo  = periodoSeleccionado(false);
$cliente  = max(0, (int) ($_GET['cliente'] ?? 0));
$grupo    = array_key_exists($_GET['grupo'] ?? '', \App\Contabilidad::GRUPOS_FISCALES) ? $_GET['grupo'] : 'emitidos';
$empresas = \App\Empresas::mostrarTodos(100, 0);
$datos    = \App\Contabilidad::documentosFiscales($periodo['anio'], $periodo['mes'], $periodo['empresa'], $cliente);
$consulta = http_build_query(array_filter($periodo + ['cliente' => $cliente], static fn ($v) => $v !== 0));

template('header', ['titulo' => 'Documentos fiscales', 'subtitulo' => 'Impuestos', 'rutaActiva' => '/admin/fiscal']);
template('selectorPeriodo', ['accion' => '/admin/fiscal/documentos', 'periodo' => $periodo, 'empresas' => $empresas, 'conTodoElAnio' => true,
    'extra' => ['grupo' => $grupo], 'clientes' => \App\Clientes::paraSelector(), 'cliente' => $cliente]);
?>

<nav class="chip-grupo entregas-tabs ajustes-tabs mb-md" aria-label="Situación fiscal">
    <?php foreach (\App\Contabilidad::GRUPOS_FISCALES as $clave => $g) : $activo = $grupo === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="/admin/fiscal/documentos?<?= $consulta ?>&grupo=<?= $clave ?>"<?= $activo ? ' aria-current="page"' : '' ?>>
            <span><?= escaparValores($g['etiqueta']) ?> (<?= $datos['totales'][$clave]['operaciones'] ?>)</span></a>
    <?php endforeach; ?>
</nav>

<p class="campo__ayuda mb-md">
    Un CFDI cuenta como emitido solo cuando la venta está facturada con un CFDI con timbre vinculado; la fecha que cuenta es la de la factura.
    Las ventas pendientes de facturar no cuentan. La revisión es documental: el sistema no consulta al SAT, por eso no puede confirmar cancelaciones ante el SAT.
    Un mismo UUID no se puede vincular a dos operaciones.
</p>

<section class="tarjeta tarjeta--sin-relleno">
    <?php $filas = $datos['grupos'][$grupo]; if (!$filas) : ?>
        <div class="vacio"><span class="material-symbols-outlined vacio__icono" aria-hidden="true">task_alt</span>
            <p class="vacio__titulo">No hay documentos en esta situación para el periodo</p></div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores"><?= escaparValores(\App\Contabilidad::GRUPOS_FISCALES[$grupo]['etiqueta']) ?></caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Venta</th><th scope="col">Cliente</th><th scope="col">Negocio</th>
                    <th scope="col">UUID / folio</th><th scope="col" class="tabla--numerica">Total</th><th scope="col">Observación</th><th scope="col"></th></tr></thead>
                <tbody>
                    <?php foreach ($filas as $f) : ?>
                        <tr><td><?= date('d/m/Y', strtotime($f['fecha_fiscal'])) ?></td><td><strong><?= escaparValores($f['folio']) ?></strong></td>
                            <td><?= escaparValores($f['cliente_empresa'] ?: $f['cliente_nombre']) ?></td><td><?= escaparValores($f['empresa_nombre']) ?></td>
                            <td><?= escaparValores(trim((string) $f['uuid_fiscal'] . ' ' . (string) $f['factura_folio'])) ?: '—' ?></td>
                            <td class="tabla--numerica cifra"><strong><?= formatoMoneda($f['total']) ?></strong></td>
                            <td><?= escaparValores($f['nota']) ?></td>
                            <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="<?= escaparValores(\App\Contabilidad::rutaDetalle($f, 'documentos')) ?>">Ver</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5"><strong>Total</strong></td>
                    <td class="tabla--numerica cifra"><strong><?= formatoMoneda($datos['totales'][$grupo]['total']) ?></strong></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
