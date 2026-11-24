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
$totalCeldas = 0;

foreach ($todas as $h) {
    $carpeta = $h->departamento !== '' ? $h->departamento : \Classes\Carpetas::SIN;
    $porDepto[$carpeta][] = $h;
    $c = $h->contenido();
    $tamanos[$h->id] = [count($c['rows']), count($c['cols'])];
    $totalCeldas += $tamanos[$h->id][0] * $tamanos[$h->id][1];
}
$carpetas = array_values(array_diff(array_keys($porDepto), [\Classes\Carpetas::SIN]));

$totalTablas = count($todas);
$totalCarpetas = count($carpetas);

// Confirmaciones, carpetas e iconos de las herramientas portadas (modal estilizado).
$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';

template('header', [
    'titulo'            => 'SheetFlow',
    'subtitulo'         => 'Herramientas personales',
    'mostrarEncabezado' => false,
]);
?>

<section class="herr" id="sheetflow">
    <header class="herr-hero">
        <span class="herr-hero__icono" aria-hidden="true">
            <span class="material-symbols-outlined">table_chart</span>
        </span>
        <div class="herr-hero__texto">
            <p class="herr-hero__eyebrow">Herramientas personales</p>
            <h1 class="herr-hero__titulo">SheetFlow</h1>
            <p class="herr-hero__desc">
                Tablas de cálculo compartidas para llevar controles, presupuestos y listados.
                Admite fórmulas, formatos, imágenes y totales, y sus tablas se pueden insertar
                en los documentos del Redactor.
            </p>
        </div>
        <div class="herr-hero__acciones">
            <a class="btn btn--primario" href="/admin/sheetflow/crear">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
                <span>Nueva tabla</span>
            </a>
        </div>
    </header>

    <section class="herr-kpis" aria-label="Resumen de SheetFlow">
        <div class="herr-kpi">
            <span class="herr-kpi__icono" aria-hidden="true">
                <span class="material-symbols-outlined">grid_on</span>
            </span>
            <div class="herr-kpi__texto">
                <span class="herr-kpi__etiqueta">Tablas</span>
                <span class="herr-kpi__valor"><?= number_format($totalTablas) ?></span>
                <span class="herr-kpi__pie">En todas las carpetas</span>
            </div>
        </div>
        <div class="herr-kpi">
            <span class="herr-kpi__icono" aria-hidden="true">
                <span class="material-symbols-outlined">folder_open</span>
            </span>
            <div class="herr-kpi__texto">
                <span class="herr-kpi__etiqueta">Carpetas</span>
                <span class="herr-kpi__valor"><?= number_format($totalCarpetas) ?></span>
                <span class="herr-kpi__pie">Organización compartida</span>
            </div>
        </div>
        <div class="herr-kpi">
            <span class="herr-kpi__icono" aria-hidden="true">
                <span class="material-symbols-outlined">data_table</span>
            </span>
            <div class="herr-kpi__texto">
                <span class="herr-kpi__etiqueta">Celdas de datos</span>
                <span class="herr-kpi__valor"><?= number_format($totalCeldas) ?></span>
                <span class="herr-kpi__pie">Renglones × columnas</span>
            </div>
        </div>
    </section>

    <?php if (!$porDepto) : ?>
        <div class="herr-panel">
            <div class="vacio">
                <span class="material-symbols-outlined vacio__icono" aria-hidden="true">table_chart</span>
                <p class="vacio__titulo">Aún no hay tablas</p>
                <p>Crea la primera en blanco, desde una plantilla o importando un Excel o CSV.</p>
                <a class="btn btn--primario" href="/admin/sheetflow/crear">
                    <span class="material-symbols-outlined" aria-hidden="true">add</span>
                    Nueva tabla
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($porDepto as $depto => $hojas) : ?>
        <section class="herr-grupo" aria-label="Carpeta <?= s($depto) ?>">
            <div class="herr-grupo__cabecera">
                <span class="herr-grupo__icono" aria-hidden="true">
                    <span class="material-symbols-outlined">folder_open</span>
                </span>
                <h2 class="herr-grupo__titulo"><?= s($depto) ?></h2>
                <span class="herr-grupo__cuenta"><?= count($hojas) ?></span>
                <div class="herr-grupo__acciones">
                    <?php if ($depto !== \Classes\Carpetas::SIN) : ?>
                        <button type="button" class="btn btn--texto btn--sm" data-carpeta-renombrar="<?= s($depto) ?>"
                                data-herramienta="sheetflow" title="Renombrar carpeta">
                            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                            <span>Renombrar</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="herr-docs">
                <?php foreach ($hojas as $h) :
                    $q = 'id=' . (int) $h->id;
                    [$nf, $nc] = $tamanos[$h->id];
                ?>
                    <article class="herr-doc">
                        <span class="herr-doc__icono" aria-hidden="true">
                            <span class="material-symbols-outlined">table_chart</span>
                        </span>
                        <div class="herr-doc__texto">
                            <h3 class="herr-doc__titulo"><?= s($h->nombre) ?></h3>
                            <?php if ($h->descripcion !== '') : ?>
                                <p class="herr-doc__desc"><?= s($h->descripcion) ?></p>
                            <?php endif; ?>
                            <div class="herr-doc__meta">
                                <span class="herr-meta">
                                    <span class="material-symbols-outlined" aria-hidden="true">calendar_today</span>
                                    <?= s(formatear_fecha($h->fecha, 'd/m/Y')) ?>
                                </span>
                                <span class="herr-meta">
                                    <span class="material-symbols-outlined" aria-hidden="true">grid_on</span>
                                    <?= number_format($nf) ?> × <?= number_format($nc) ?>
                                </span>
                                <span class="herr-meta">
                                    <span class="material-symbols-outlined" aria-hidden="true">history</span>
                                    Actualizada <?= s(formatear_fecha($h->actualizado, 'd/m/Y H:i')) ?>
                                </span>
                            </div>
                        </div>
                        <div class="herr-doc__acciones">
                            <a href="/admin/sheetflow/ver?<?= $q ?>" class="btn btn--texto btn--sm">
                                <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                                <span>Ver</span>
                            </a>
                            <a href="/admin/sheetflow/abrir?<?= $q ?>" class="btn btn--primario btn--sm">
                                <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                                <span>Abrir</span>
                            </a>
                            <details class="gr-descargar">
                                <summary class="btn btn--texto btn--sm">
                                    <span class="material-symbols-outlined" aria-hidden="true">download</span>
                                    <span>Descargar</span>
                                </summary>
                                <div class="gr-descargar-menu">
                                    <a href="/admin/sheetflow/descargar?<?= $q ?>&formato=xlsx"><span class="material-symbols-outlined" aria-hidden="true">table_chart</span> Excel (.xlsx)</a>
                                    <a href="/admin/sheetflow/descargar?<?= $q ?>&formato=csv"><span class="material-symbols-outlined" aria-hidden="true">description</span> CSV</a>
                                    <a href="/admin/sheetflow/descargar?<?= $q ?>&formato=sheetflow"><span class="material-symbols-outlined" aria-hidden="true">grid_on</span> SheetFlow (.sheetflow)</a>
                                    <a href="/admin/sheetflow/descargar?<?= $q ?>&formato=pdf"><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span> PDF</a>
                                </div>
                            </details>
                            <details class="gr-descargar herr-mas">
                                <summary class="btn btn--texto btn--sm" title="Más acciones" aria-label="Más acciones de <?= s($h->nombre) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">more_horiz</span>
                                </summary>
                                <div class="gr-descargar-menu">
                                    <span class="herr-mas__rotulo">Mover a carpeta</span>
                                    <span class="herr-mas__carpeta">
                                        <?= \Classes\Carpetas::selector('sheetflow', (int) $h->id, (string) $h->departamento, $carpetas) ?>
                                    </span>
                                    <form method="POST" action="/admin/sheetflow/duplicar">
                                        <input type="hidden" name="id" value="<?= (int) $h->id ?>">
                                        <button type="submit">
                                            <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
                                            Duplicar
                                        </button>
                                    </form>
                                    <form method="POST" action="/admin/sheetflow/eliminar"
                                          data-confirm="¿Eliminar «<?= s($h->nombre) ?>»?|Esta acción no se puede deshacer.">
                                        <input type="hidden" name="id" value="<?= (int) $h->id ?>">
                                        <button type="submit" class="herr-menu__peligro">
                                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                            Eliminar
                                        </button>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</section>

<?php template('footer'); ?>
