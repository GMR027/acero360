<?php
/**
 * views/admin/sheetflow/index.php — Listado de tablas de SheetFlow.
 *
 * Sección compartida: cualquier administrador ve y edita todas las tablas.
 * Se agrupan por carpeta (departamento) y se pueden descargar en Excel, CSV,
 * PDF o en el formato propio .sheetflow.
 */

soloAdmin();

$todas = \Model\SheetflowHoja::todos();
$porDepto = [];
$tamanos = [];

foreach ($todas as $h) {
    $carpeta = $h->departamento !== '' ? $h->departamento : \Classes\Carpetas::SIN;
    $porDepto[$carpeta][] = $h;
    $c = $h->contenido();
    $tamanos[$h->id] = [count($c['rows']), count($c['cols'])];
}
$carpetas = array_values(array_diff(array_keys($porDepto), [\Classes\Carpetas::SIN]));

template('header', [
    'titulo'    => 'SheetFlow',
    'subtitulo' => 'Herramientas personales',
    'acciones'  => '<a class="btn btn--primario" href="/admin/sheetflow/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>'
        . '<span>Nueva tabla</span></a>',
]);
?>

<section class="herr" id="sheetflow">
    <div class="encabezado-pagina">
        <div>
            <p class="texto-secundario">
                Tablas de cálculo compartidas: sirven para llevar controles, presupuestos y
                listados, y se pueden insertar en los documentos del Redactor. Se descargan
                en Excel, CSV o PDF.
            </p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a class="btn btn--primario" href="/admin/sheetflow/crear">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
                <span>Nueva tabla</span>
            </a>
        </div>
    </div>

    <?php if (!$porDepto) : ?>
        <div class="card">
            <p class="text-muted">Aún no hay tablas. Crea la primera con «Nueva tabla»: en blanco,
                desde una plantilla o importando un Excel/CSV.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($porDepto as $depto => $hojas) : ?>
        <div class="card rd-grupo">
            <h3>
                <span class="material-symbols-outlined" aria-hidden="true">folder_open</span>
                <?= s($depto) ?>
                <small class="text-muted">(<?= count($hojas) ?>)</small>
                <?php if ($depto !== \Classes\Carpetas::SIN) : ?>
                    <button type="button" class="btn btn--texto btn--sm" data-carpeta-renombrar="<?= s($depto) ?>"
                            data-herramienta="sheetflow" title="Renombrar carpeta">
                        <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                    </button>
                <?php endif; ?>
            </h3>
            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr><th>Fecha</th><th>Nombre</th><th>Tamaño</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($hojas as $h) :
                        $q = 'id=' . (int) $h->id;
                        [$nf, $nc] = $tamanos[$h->id];
                    ?>
                        <tr>
                            <td class="mono"><?= s(formatear_fecha($h->fecha, 'd/m/Y')) ?></td>
                            <td>
                                <span class="material-symbols-outlined ico-tabla" aria-hidden="true">table_chart</span>
                                <strong><?= s($h->nombre) ?></strong>
                                <?php if ($h->descripcion !== '') : ?>
                                    <br><small class="text-muted"><?= s($h->descripcion) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?= $nf ?> × <?= $nc ?></td>
                            <td class="herramientas-acciones">
                                <a href="/admin/sheetflow/ver?<?= $q ?>" class="btn btn--texto btn--sm">
                                    <span class="material-symbols-outlined" aria-hidden="true">visibility</span> Ver
                                </a>
                                <a href="/admin/sheetflow/abrir?<?= $q ?>" class="btn btn--primario btn--sm">
                                    <span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar
                                </a>
                                <details class="gr-descargar">
                                    <summary class="btn btn--texto btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true">download</span> Descargar
                                    </summary>
                                    <div class="gr-descargar-menu">
                                        <a href="/admin/sheetflow/descargar?<?= $q ?>&formato=xlsx">
                                            <span class="material-symbols-outlined" aria-hidden="true">table_chart</span> Excel (.xlsx)</a>
                                        <a href="/admin/sheetflow/descargar?<?= $q ?>&formato=csv">
                                            <span class="material-symbols-outlined" aria-hidden="true">description</span> CSV</a>
                                        <a href="/admin/sheetflow/descargar?<?= $q ?>&formato=sheetflow">
                                            <span class="material-symbols-outlined" aria-hidden="true">grid_on</span> SheetFlow (.sheetflow)</a>
                                        <a href="/admin/sheetflow/descargar?<?= $q ?>&formato=pdf">
                                            <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span> PDF</a>
                                    </div>
                                </details>
                                <?= \Classes\Carpetas::selector('sheetflow', (int) $h->id, (string) $h->departamento, $carpetas) ?>
                                <form method="POST" action="/admin/sheetflow/duplicar" class="en-linea">
                                    <input type="hidden" name="id" value="<?= (int) $h->id ?>">
                                    <button type="submit" class="btn btn--texto btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true">content_copy</span> Duplicar
                                    </button>
                                </form>
                                <form method="POST" action="/admin/sheetflow/eliminar" class="en-linea"
                                      data-confirm="¿Eliminar «<?= s($h->nombre) ?>»?|Esta acción no se puede deshacer.">
                                    <input type="hidden" name="id" value="<?= (int) $h->id ?>">
                                    <button type="submit" class="btn btn--peligro btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true">delete</span> Eliminar
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<?php template('footer'); ?>
