<?php
/**
 * views/admin/seguimiento.php — Centro de seguimiento: avisos de cobros, facturas, gastos, entregas, inventario y novedades.
 * Cada aviso abre su registro de origen. Marcar como leído solo escribe en seguimiento_lecturas.
 */

soloAdmin();

$usuarioId = (int) ($_SESSION['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave = (string) ($_POST['clave'] ?? '');
    if (($_POST['accion'] ?? '') === 'todas') {
        \App\Seguimiento::marcarLeida($usuarioId, null);
        redirigir('/admin/seguimiento', 'exito', 'Todos los avisos quedaron como leídos.');
    }
    \App\Seguimiento::marcarLeida($usuarioId, $clave);
    redirigir('/admin/seguimiento' . (!empty($_POST['volver']) ? '?' . http_build_query(['categoria' => (string) $_POST['volver']]) : ''));
}

$categoria = array_key_exists($_GET['categoria'] ?? '', \App\Seguimiento::CATEGORIAS) ? $_GET['categoria'] : '';
$soloNuevos = ($_GET['ver'] ?? '') === 'nuevos';

$todos = \App\Seguimiento::paraUsuario($usuarioId);
$porCategoria = array_fill_keys(array_keys(\App\Seguimiento::CATEGORIAS), 0);
$nuevos = 0;
foreach ($todos as $a) {
    if (!$a['leida']) {
        $porCategoria[$a['categoria']]++;
        $nuevos++;
    }
}
$avisos = array_values(array_filter($todos, static fn ($a) => ($categoria === '' || $a['categoria'] === $categoria) && (!$soloNuevos || !$a['leida'])));

$prioridades = \App\Seguimiento::NIVELES;
$volverA = '/admin/seguimiento' . (($categoria !== '' || $soloNuevos) ? '?' . http_build_query(array_filter(['categoria' => $categoria, 'ver' => $soloNuevos ? 'nuevos' : ''])) : '');
[$tipoAviso, $idAviso] = [null, null];
$enlaceFiltro = static function (string $cat, bool $nuevosSolo) {
    $q = array_filter(['categoria' => $cat, 'ver' => $nuevosSolo ? 'nuevos' : ''], static fn ($v) => $v !== '');
    return '/admin/seguimiento' . ($q ? '?' . http_build_query($q) : '');
};

template('header', [
    'titulo' => 'Centro de seguimiento', 'subtitulo' => 'Lo que necesita tu atención', 'rutaActiva' => '/admin/seguimiento',
    'acciones' => $nuevos > 0
        ? '<form action="/admin/seguimiento" method="post"><input type="hidden" name="accion" value="todas">'
          . '<button class="btn btn--secundario" type="submit"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">done_all</span><span>Marcar todo como leído</span></button></form>'
        : '',
]);
?>

<nav class="chip-grupo ajustes-tabs mb-md" aria-label="Categorías de avisos">
    <a class="chip<?= $categoria === '' ? ' chip--activo' : '' ?>" href="<?= escaparValores($enlaceFiltro('', $soloNuevos)) ?>"><span>Todos<?= $nuevos ? ' (' . $nuevos . ')' : '' ?></span></a>
    <?php foreach (\App\Seguimiento::CATEGORIAS as $clave => [$texto, $icono]) : ?>
        <a class="chip<?= $categoria === $clave ? ' chip--activo' : '' ?>" href="<?= escaparValores($enlaceFiltro($clave, $soloNuevos)) ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= $icono ?></span>
            <span><?= escaparValores($texto) ?><?= $porCategoria[$clave] ? ' (' . $porCategoria[$clave] . ')' : '' ?></span></a>
    <?php endforeach; ?>
</nav>
<p class="mb-lg">
    <a class="chip<?= !$soloNuevos ? ' chip--activo' : '' ?>" href="<?= escaparValores($enlaceFiltro($categoria, false)) ?>">Todos los avisos</a>
    <a class="chip<?= $soloNuevos ? ' chip--activo' : '' ?>" href="<?= escaparValores($enlaceFiltro($categoria, true)) ?>">Solo sin leer</a>
</p>

<?php if (!$avisos) : ?>
    <p class="nota nota--info" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">check_circle</span>
        <span>No hay avisos <?= $soloNuevos ? 'sin leer ' : '' ?>en esta categoría. Todo al día.</span></p>
<?php endif; ?>

<?php foreach ($avisos as $a) : [$textoCat, $iconoCat] = \App\Seguimiento::CATEGORIAS[$a['categoria']]; [$etiqueta, $iconoNivel, $claseBadge] = $prioridades[$a['prioridad']]; ?>
    <article class="tarjeta mb-md seguimiento-aviso<?= $a['leida'] ? ' seguimiento-aviso--leida' : '' ?>" data-clave="<?= escaparValores($a['clave']) ?>" data-prioridad="<?= escaparValores($a['prioridad']) ?>">
        <div class="flex-entre fila--envuelto">
            <h2 class="titulo-5">
                <span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:middle"><?= $iconoCat ?></span>
                <?= escaparValores($a['titulo']) ?>
            </h2>
            <span>
                <span class="badge <?= $claseBadge ?>"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem;vertical-align:middle"><?= $iconoNivel ?></span> Prioridad: <?= $etiqueta ?></span>
                <?php if (!$a['leida']) : ?><span class="badge badge--info">Nuevo</span><?php endif; ?>
            </span>
        </div>
        <p class="texto-secundario mt-sm"><?= escaparValores($a['detalle']) ?></p>
        <div class="formulario__acciones" style="gap:.5rem">
            <a class="btn btn--primario btn--sm" href="<?= escaparValores($a['enlace']) ?>"><?= escaparValores(\App\Seguimiento::etiquetaAbrir($a['clave'])) ?></a>
            <?php [$tipoAviso, $idAviso] = explode(':', $a['clave']) + [1 => '0']; $idAviso = (int) $idAviso; ?>
            <?php if ($tipoAviso === 'cotizacion') : ?>
                <form action="/admin/cotizaciones/estado" method="post">
                    <input type="hidden" name="id" value="<?= $idAviso ?>"><input type="hidden" name="estado" value="vendido"><input type="hidden" name="volver" value="<?= escaparValores($volverA) ?>">
                    <button class="btn btn--secundario btn--sm" type="submit">Marcar como vendida</button>
                </form>
                <form action="/admin/cotizaciones/estado" method="post">
                    <input type="hidden" name="id" value="<?= $idAviso ?>"><input type="hidden" name="estado" value="cancelado"><input type="hidden" name="volver" value="<?= escaparValores($volverA) ?>">
                    <button class="btn btn--texto btn--sm" type="submit">Cancelar</button>
                </form>
            <?php elseif ($tipoAviso === 'cobro') : ?>
                <details class="accion-rapida">
                    <summary class="btn btn--secundario btn--sm">Registrar cobro</summary>
                    <form action="/admin/contabilidad/ingresos/accion" method="post" class="fila--envuelto" style="display:flex;gap:.5rem;margin-top:.5rem">
                        <input type="hidden" name="accion" value="cobro"><input type="hidden" name="id" value="<?= $idAviso ?>">
                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>"><input type="hidden" name="fecha" value="<?= date('Y-m-d') ?>">
                        <input type="hidden" name="volver" value="<?= escaparValores($volverA) ?>">
                        <label class="solo-lectores" for="imp-<?= $idAviso ?>">Importe</label>
                        <input class="control control--compacto" id="imp-<?= $idAviso ?>" name="importe" inputmode="decimal" required value="<?= number_format((float) $a['saldo'], 2, '.', '') ?>" style="width:8rem">
                        <label class="solo-lectores" for="met-<?= $idAviso ?>">Método</label>
                        <select class="control control--compacto" id="met-<?= $idAviso ?>" name="metodo">
                            <?php foreach (\App\Contabilidad::METODOS_PAGO as $m) : ?><option<?= $m === ($a['metodo'] ?? '') ? ' selected' : '' ?>><?= escaparValores($m) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn--primario btn--sm" type="submit">Guardar cobro</button>
                    </form>
                </details>
            <?php elseif ($tipoAviso === 'factura') : ?>
                <form action="/admin/contabilidad/ingresos/accion" method="post">
                    <input type="hidden" name="accion" value="facturacion"><input type="hidden" name="id" value="<?= $idAviso ?>">
                    <input type="hidden" name="estado_facturacion" value="no_facturada"><input type="hidden" name="volver" value="<?= escaparValores($volverA) ?>">
                    <button class="btn btn--secundario btn--sm" type="submit">No se facturará</button>
                </form>
                <details class="accion-rapida">
                    <summary class="btn btn--secundario btn--sm">Ya está facturada</summary>
                    <form action="/admin/contabilidad/ingresos/accion" method="post" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.5rem">
                        <input type="hidden" name="accion" value="facturacion"><input type="hidden" name="id" value="<?= $idAviso ?>">
                        <input type="hidden" name="estado_facturacion" value="facturada"><input type="hidden" name="fecha_facturacion" value="<?= date('Y-m-d') ?>">
                        <input type="hidden" name="volver" value="<?= escaparValores($volverA) ?>">
                        <label class="solo-lectores" for="fol-<?= $idAviso ?>">Folio de la factura</label>
                        <input class="control control--compacto" id="fol-<?= $idAviso ?>" name="factura_folio" maxlength="40" placeholder="Folio (opcional)" style="width:10rem">
                        <label><input type="checkbox" name="confirma_manual" value="1" required> Confirmo que la factura ya se emitió</label>
                        <button class="btn btn--primario btn--sm" type="submit">Guardar</button>
                    </form>
                </details>
            <?php elseif ($tipoAviso === 'gasto_pago') : ?>
                <form action="/admin/contabilidad/gastos/accion" method="post">
                    <input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="<?= $idAviso ?>"><input type="hidden" name="volver" value="<?= escaparValores($volverA) ?>">
                    <button class="btn btn--secundario btn--sm" type="submit">Marcar como pagado</button>
                </form>
            <?php endif; ?>
            <?php if (!$a['leida']) : ?>
                <form action="/admin/seguimiento" method="post">
                    <input type="hidden" name="clave" value="<?= escaparValores($a['clave']) ?>">
                    <input type="hidden" name="volver" value="<?= escaparValores($categoria) ?>">
                    <button class="btn btn--secundario btn--sm" type="submit">Marcar como leído</button>
                </form>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>

<?php template('footer'); ?>
