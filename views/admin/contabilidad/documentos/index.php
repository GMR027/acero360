<?php
/**
 * views/admin/contabilidad/documentos/index.php — Comprobantes y documentos del periodo
 * (ventas y gastos), con su validación documental y los dos ZIP del mes.
 */

soloAdmin();

$periodo  = periodoSeleccionado();
$empresas = \App\Empresas::mostrarTodos(100, 0);
$tipoOp   = in_array($_GET['operacion'] ?? '', ['ventas', 'gastos'], true) ? $_GET['operacion'] : 'ventas';
$docs     = \App\ArchivoMensual::documentos($tipoOp, $periodo['anio'], $periodo['mes']);

template('header', ['titulo' => 'Comprobantes y documentos', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'documentos', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/contabilidad/documentos', 'periodo' => $periodo, 'empresas' => $empresas, 'extra' => ['operacion' => $tipoOp]]);
?>

<nav class="chip-grupo ajustes-tabs mb-lg" aria-label="Tipo de operación">
    <?php foreach (['ventas' => 'Documentos de ventas', 'gastos' => 'Documentos de gastos'] as $clave => $texto) : ?>
        <a class="chip<?= $tipoOp === $clave ? ' chip--activo' : '' ?>" href="/admin/contabilidad/documentos?<?= http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'operacion' => $clave]) ?>"
           <?= $tipoOp === $clave ? 'aria-current="page"' : '' ?>><span><?= $texto ?></span></a>
    <?php endforeach; ?>
    <a class="chip" href="/admin/contabilidad/zip?tipo=<?= $tipoOp ?>&anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">folder_zip</span><span>Descargar ZIP de <?= $tipoOp ?></span></a>
</nav>

<section class="tarjeta tarjeta--sin-relleno">
    <?php if (!$docs) : ?>
        <div class="vacio"><span class="material-symbols-outlined vacio__icono" aria-hidden="true">folder_open</span>
            <p class="vacio__titulo">Sin documentos en <?= nombreMes($periodo['mes']) ?> <?= $periodo['anio'] ?></p>
            <p>Adjunta comprobantes desde el detalle de cada ingreso o gasto.</p></div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Documentos del periodo</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col"><?= $tipoOp === 'ventas' ? 'Venta' : 'Concepto' ?></th>
                    <th scope="col"><?= $tipoOp === 'ventas' ? 'Cliente' : 'Proveedor' ?></th><th scope="col">Documento</th>
                    <th scope="col">Carpeta del ZIP</th><th scope="col">Validación documental</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($docs as $d) : $val = \App\Archivos::etiquetaValidacion($d['estado_validacion']); ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($d['fecha'])) ?></td>
                            <td><?= escaparValores($d['referencia']) ?></td>
                            <td><?= escaparValores($d['contraparte']) ?></td>
                            <td><?= escaparValores($d['nombre_original']) ?><span class="texto-xs texto-secundario"><br><?= escaparValores(\App\Archivos::TIPOS[$d['tipo']] ?? $d['tipo']) ?>
                                <?= $d['uuid_fiscal'] ? ' · ' . escaparValores($d['uuid_fiscal']) : '' ?></span></td>
                            <td><?= escaparValores(\App\ArchivoMensual::carpeta($tipoOp, $d)) ?></td>
                            <td><span class="badge <?= $val['clase'] ?>"><?= escaparValores($val['etiqueta']) ?></span></td>
                            <td class="tabla--numerica">
                                <a class="btn btn--secundario btn--sm" href="/admin/contabilidad/documentos/descargar?id=<?= (int) $d['id'] ?>">Descargar</a>
                                <a class="btn btn--texto btn--sm" href="/admin/contabilidad/<?= $tipoOp === 'ventas' ? 'ingresos' : 'gastos' ?>/detalle?id=<?= (int) $d['entidad_id'] ?>">Abrir operación</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
