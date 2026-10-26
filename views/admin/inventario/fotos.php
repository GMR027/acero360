<?php
/**
 * views/admin/inventario/fotos.php — Fotografías mensuales del inventario y comparación con las existencias actuales.
 * Generar o consultar una fotografía nunca modifica el inventario.
 */

soloAdmin();

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/inventario/fotos', 'error', 'Este formulario ya se envió; no se generó la fotografía dos veces.');
    }
    if (\App\InventarioReportes::generarCorte((int) ($_POST['anio'] ?? 0), (int) ($_POST['mes'] ?? 0), (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/inventario/fotos', 'exito', 'Fotografía generada. El inventario no se modificó.');
    }
    redirigir('/admin/inventario/fotos', 'error', \App\InventarioReportes::getErrores()[0] ?? 'No fue posible generar la fotografía.');
}

$cortes = \App\InventarioReportes::cortes();
$id     = (int) ($_GET['id'] ?? 0);
$corte  = $id ? \App\InventarioReportes::corte($id) : null;
$comparacion = $corte ? \App\InventarioReportes::comparar($id) : [];
$mesAnterior = strtotime('first day of last month');

template('header', ['titulo' => 'Fotografías mensuales del inventario', 'subtitulo' => 'Inventario', 'rutaActiva' => '/admin/inventario']);
?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Tomar una fotografía</h2>
    <p class="campo__ayuda mb-md">Guarda las existencias de todos los productos en este momento para compararlas después. Lo ideal es tomarla al cierre de cada mes
        (también puede automatizarse con <code>php scripts/foto_inventario.php</code>). Una fotografía no se reemplaza.</p>
    <form class="formulario" action="/admin/inventario/fotos" method="post" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta" for="mes">Mes que representa</label>
                <select class="control" id="mes" name="mes"><?php for ($m = 1; $m <= 12; $m++) : ?><option value="<?= $m ?>"<?= $m === (int) date('n', $mesAnterior) ? ' selected' : '' ?>><?= nombreMes($m) ?></option><?php endfor; ?></select></div>
            <div class="campo"><label class="campo__etiqueta" for="anio">Año</label>
                <select class="control" id="anio" name="anio"><?php for ($a = (int) date('Y'); $a >= 2020; $a--) : ?><option value="<?= $a ?>"<?= $a === (int) date('Y', $mesAnterior) ? ' selected' : '' ?>><?= $a ?></option><?php endfor; ?></select></div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Generar fotografía</button></div>
    </form>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Fotografías guardadas</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Fotografías del inventario</caption>
            <thead><tr><th scope="col">Mes</th><th scope="col">Tomada</th><th scope="col" class="tabla--numerica">Productos</th><th scope="col" class="tabla--numerica">Piezas</th>
                <th scope="col" class="tabla--numerica">Valor al costo</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
                <?php foreach ($cortes as $c) : ?>
                    <tr><td><strong><?= nombreMes((int) $c['mes']) ?> <?= (int) $c['anio'] ?></strong></td>
                        <td class="texto-sm"><?= date('d/m/Y H:i', strtotime($c['generado_en'])) ?><br><?= escaparValores((string) $c['usuario_nombre'] ?: 'Automática') ?>
                            <?= \App\InventarioReportes::esTardia($c) ? '<span class="badge badge--pendiente">Posterior al cierre del mes</span>' : '' ?></td>
                        <td class="tabla--numerica cifra"><?= (int) $c['productos'] ?></td><td class="tabla--numerica cifra"><?= number_format((int) $c['piezas']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($c['valor_costo']) ?></td>
                        <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/inventario/fotos?id=<?= (int) $c['id'] ?>">Comparar con hoy</a></td></tr>
                <?php endforeach; ?>
                <?php if (!$cortes) : ?><tr><td colspan="6" class="tabla--centro texto-secundario">Aún no hay fotografías.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($corte) : ?>
    <section class="tarjeta tarjeta--sin-relleno">
        <div class="tabla-toolbar">
            <h2 class="titulo-5">Comparación: <?= nombreMes((int) $corte['mes']) ?> <?= (int) $corte['anio'] ?> contra hoy</h2>
            <span>
                <a class="btn btn--secundario btn--sm" href="/admin/inventario/exportar?tipo=corte&id=<?= $id ?>&formato=pdf">PDF</a>
                <a class="btn btn--secundario btn--sm" href="/admin/inventario/exportar?tipo=corte&id=<?= $id ?>&formato=xlsx">Excel</a>
                <a class="btn btn--secundario btn--sm" href="/admin/inventario/exportar?tipo=corte&id=<?= $id ?>&formato=csv">CSV</a>
            </span>
        </div>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Comparación de existencias</caption>
                <thead><tr><th scope="col">Producto</th><th scope="col" class="tabla--numerica">En la fotografía</th><th scope="col" class="tabla--numerica">Hoy</th><th scope="col" class="tabla--numerica">Diferencia</th></tr></thead>
                <tbody>
                    <?php foreach ($comparacion as $x) : ?>
                        <tr><td><strong><?= escaparValores($x['nombre']) ?></strong><span class="texto-xs texto-secundario"><br><?= escaparValores((string) $x['sku']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= $x['antes'] === null ? 'No existía' : (int) $x['antes'] ?></td>
                            <td class="tabla--numerica cifra"><?= $x['ahora'] === null ? 'Eliminado' : (int) $x['ahora'] ?></td>
                            <td class="tabla--numerica cifra"><span class="variacion <?= $x['diferencia'] > 0 ? 'variacion--sube' : ($x['diferencia'] < 0 ? 'variacion--baja' : '') ?>"><?= ($x['diferencia'] > 0 ? '+' : '') . $x['diferencia'] ?></span></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
