<?php
/**
 * views/admin/inventario/movimientos.php — Historial de movimientos de inventario (solo lectura).
 * Fecha, producto, tipo, motivo, cantidad, saldo, documento de origen y usuario; exportable a PDF, Excel y CSV.
 */

soloAdmin();

$f = [
    'desde' => (string) ($_GET['desde'] ?? ''), 'hasta' => (string) ($_GET['hasta'] ?? ''),
    'producto' => (int) ($_GET['producto'] ?? 0), 'motivo' => (string) ($_GET['motivo'] ?? ''), 'empresa' => (int) ($_GET['empresa'] ?? 0),
];
$total  = \App\InventarioReportes::contarMovimientos($f);
$pagina = paginacion(25, $total);
$filas  = \App\InventarioReportes::movimientos($f, $pagina['limite'], $pagina['offset']);
$productos = \App\Productos::mostrarTodos(500, 0);
$empresas  = \App\Empresas::mostrarTodos(100, 0);
$parametros = array_filter($f, static fn ($v) => $v !== '' && $v !== 0);
$consulta = http_build_query($parametros);

template('header', [
    'titulo' => 'Historial de movimientos', 'subtitulo' => 'Inventario', 'rutaActiva' => '/admin/inventario',
    'acciones' => '<a class="btn btn--secundario" href="/admin/inventario/exportar?tipo=movimientos&formato=pdf&' . escaparValores($consulta) . '" target="_blank" rel="noopener">PDF</a>'
        . '<a class="btn btn--secundario" href="/admin/inventario/exportar?tipo=movimientos&formato=xlsx&' . escaparValores($consulta) . '">Excel</a>'
        . '<a class="btn btn--secundario" href="/admin/inventario/exportar?tipo=movimientos&formato=csv&' . escaparValores($consulta) . '">CSV</a>',
]);
?>

<form class="tarjeta mb-lg" action="/admin/inventario/movimientos" method="get" aria-label="Filtros del historial">
    <div class="formulario__rejilla">
        <div class="campo"><label class="campo__etiqueta" for="desde">Desde</label><input class="control" type="date" id="desde" name="desde" value="<?= escaparValores($f['desde']) ?>"></div>
        <div class="campo"><label class="campo__etiqueta" for="hasta">Hasta</label><input class="control" type="date" id="hasta" name="hasta" value="<?= escaparValores($f['hasta']) ?>"></div>
        <div class="campo"><label class="campo__etiqueta" for="producto">Producto</label>
            <select class="control" id="producto" name="producto"><option value="0">Todos</option><?php foreach ($productos as $p) : ?><option value="<?= (int) $p->id ?>"<?= $f['producto'] === (int) $p->id ? ' selected' : '' ?>><?= escaparValores(($p->sku ? $p->sku . ' · ' : '') . $p->nombre) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="motivo">Motivo</label>
            <select class="control" id="motivo" name="motivo"><option value="">Todos</option><?php foreach (\App\InventarioReportes::MOTIVOS as $k => $t) : ?><option value="<?= $k ?>"<?= $f['motivo'] === $k ? ' selected' : '' ?>><?= escaparValores($t) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label class="campo__etiqueta" for="empresa">Negocio</label>
            <select class="control" id="empresa" name="empresa"><option value="0">Ambos</option><?php foreach ($empresas as $e) : ?><option value="<?= (int) $e->id ?>"<?= $f['empresa'] === (int) $e->id ? ' selected' : '' ?>><?= escaparValores($e->nombre) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="formulario__acciones"><a class="btn btn--texto" href="/admin/inventario/movimientos">Restablecer</a><button class="btn btn--primario" type="submit">Filtrar</button></div>
</form>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar"><span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> <?= $total === 1 ? 'movimiento' : 'movimientos' ?></span></div>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Movimientos de inventario</caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col">Producto</th><th scope="col">Motivo</th><th scope="col" class="tabla--numerica">Cantidad</th>
                <th scope="col" class="tabla--numerica">Saldo</th><th scope="col">Documento</th><th scope="col">Usuario</th></tr></thead>
            <tbody>
                <?php foreach ($filas as $m) :
                    $signo = $m['tipo'] === 'salida' ? '−' : ($m['tipo'] === 'entrada' ? '+' : '±');
                    $doc = $m['venta_folio'] ?: ($m['orden_folio'] ?: ($m['cotizacion_folio'] ?: '—')); ?>
                    <tr><td class="texto-sm"><?= date('d/m/Y H:i', strtotime($m['creado_en'])) ?></td>
                        <td><strong><?= escaparValores($m['producto']) ?></strong><span class="texto-xs texto-secundario"><br><?= escaparValores((string) $m['sku']) ?></span></td>
                        <td><?= escaparValores(\App\InventarioReportes::MOTIVOS[$m['motivo']] ?? ucfirst($m['tipo'])) ?><span class="texto-xs texto-secundario"><br><?= escaparValores((string) $m['notas']) ?></span></td>
                        <td class="tabla--numerica cifra"><?= $signo . (int) $m['cantidad'] ?></td>
                        <td class="tabla--numerica cifra"><?= (int) $m['saldo'] ?></td>
                        <td><?= escaparValores($doc) ?></td><td><?= escaparValores((string) $m['usuario_nombre']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$filas) : ?><tr><td colspan="7" class="tabla--centro texto-secundario">Sin movimientos con estos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => '/admin/inventario/movimientos' . ($consulta ? '?' . $consulta : ''), 'total' => $total]); ?>
</section>

<?php template('footer'); ?>
