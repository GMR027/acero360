<?php
/**
 * views/admin/redactor/index.php — Listado de documentos del Redactor.
 *
 * Sección compartida: cualquier administrador ve y edita todos los
 * documentos, agrupados por carpeta.
 */

soloAdmin();

$documentos = \Model\RedactorDocumento::todos();
$porDepto = [];
foreach ($documentos as $d) {
    $carpeta = $d->departamento !== '' ? $d->departamento : \Classes\Carpetas::SIN;
    $porDepto[$carpeta][] = $d;
}
$carpetas = array_values(array_diff(array_keys($porDepto), [\Classes\Carpetas::SIN]));

$totalDocumentos = count($documentos);
$totalCarpetas = count($carpetas);
$actualizadosMes = count(array_filter(
    $documentos,
    static fn ($d): bool => str_starts_with((string) $d->actualizado, date('Y-m'))
));

// Confirmaciones, carpetas e iconos de las herramientas portadas (modal estilizado).
$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';

template('header', [
    'titulo'            => 'Redactor',
    'subtitulo'         => 'Herramientas personales',
    'mostrarEncabezado' => false,
]);
?>

<section class="herr" id="redactor">
    <header class="herr-hero">
        <span class="herr-hero__icono" aria-hidden="true">
            <span class="material-symbols-outlined">description</span>
        </span>
        <div class="herr-hero__texto">
            <p class="herr-hero__eyebrow">Herramientas personales</p>
            <h1 class="herr-hero__titulo">Redactor</h1>
            <p class="herr-hero__desc">
                Documentos de texto compartidos: memorias, cartas, actas y procedimientos.
                Con corrector ortográfico, plantillas, comentarios, control de cambios y
                exportación a Word, LibreOffice o PDF.
            </p>
        </div>
        <div class="herr-hero__acciones">
            <a class="btn btn--primario" href="/admin/redactor/crear">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
                <span>Nuevo documento</span>
            </a>
        </div>
    </header>

    <section class="herr-kpis" aria-label="Resumen del Redactor">
        <div class="herr-kpi">
            <span class="herr-kpi__icono" aria-hidden="true">
                <span class="material-symbols-outlined">description</span>
            </span>
            <div class="herr-kpi__texto">
                <span class="herr-kpi__etiqueta">Documentos</span>
                <span class="herr-kpi__valor"><?= number_format($totalDocumentos) ?></span>
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
                <span class="material-symbols-outlined">history</span>
            </span>
            <div class="herr-kpi__texto">
                <span class="herr-kpi__etiqueta">Actualizados este mes</span>
                <span class="herr-kpi__valor"><?= number_format($actualizadosMes) ?></span>
                <span class="herr-kpi__pie"><?= escaparValores(nombreMes((int) date('n'))) ?> de <?= date('Y') ?></span>
            </div>
        </div>
    </section>

    <?php if (!$porDepto) : ?>
        <div class="herr-panel">
            <div class="vacio">
                <span class="material-symbols-outlined vacio__icono" aria-hidden="true">description</span>
                <p class="vacio__titulo">Aún no hay documentos</p>
                <p>Crea el primero y empieza en blanco o con una plantilla incluida.</p>
                <a class="btn btn--primario" href="/admin/redactor/crear">
                    <span class="material-symbols-outlined" aria-hidden="true">add</span>
                    Nuevo documento
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($porDepto as $depto => $docs) : ?>
        <section class="herr-grupo" aria-label="Carpeta <?= s($depto) ?>">
            <div class="herr-grupo__cabecera">
                <span class="herr-grupo__icono" aria-hidden="true">
                    <span class="material-symbols-outlined">folder_open</span>
                </span>
                <h2 class="herr-grupo__titulo"><?= s($depto) ?></h2>
                <span class="herr-grupo__cuenta"><?= count($docs) ?></span>
                <div class="herr-grupo__acciones">
                    <?php if ($depto !== \Classes\Carpetas::SIN) : ?>
                        <button type="button" class="btn btn--texto btn--sm" data-carpeta-renombrar="<?= s($depto) ?>"
                                data-herramienta="redactor" title="Renombrar carpeta">
                            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                            <span>Renombrar</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="herr-docs">
                <?php foreach ($docs as $d) : ?>
                    <article class="herr-doc">
                        <span class="herr-doc__icono" aria-hidden="true">
                            <span class="material-symbols-outlined">article</span>
                        </span>
                        <div class="herr-doc__texto">
                            <h3 class="herr-doc__titulo"><?= s($d->nombre) ?></h3>
                            <div class="herr-doc__meta">
                                <span class="herr-meta">
                                    <span class="material-symbols-outlined" aria-hidden="true">calendar_today</span>
                                    <?= s(formatear_fecha($d->fecha, 'd/m/Y')) ?>
                                </span>
                                <span class="herr-meta">
                                    <span class="material-symbols-outlined" aria-hidden="true">description</span>
                                    <?= s(\Model\RedactorDocumento::PAPELES[$d->papel][0]) ?> · <?= s($d->orientacion) ?>
                                </span>
                                <span class="herr-meta">
                                    <span class="material-symbols-outlined" aria-hidden="true">translate</span>
                                    <?= s(\Model\RedactorDocumento::IDIOMAS[$d->idioma] ?? $d->idioma) ?>
                                </span>
                                <span class="herr-meta">
                                    <span class="material-symbols-outlined" aria-hidden="true">history</span>
                                    Actualizado <?= s(formatear_fecha($d->actualizado, 'd/m/Y H:i')) ?>
                                </span>
                            </div>
                        </div>
                        <div class="herr-doc__acciones">
                            <a href="/admin/redactor/ver?id=<?= (int) $d->id ?>" class="btn btn--texto btn--sm">
                                <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                                <span>Ver</span>
                            </a>
                            <a href="/admin/redactor/abrir?id=<?= (int) $d->id ?>" class="btn btn--primario btn--sm">
                                <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                                <span>Abrir</span>
                            </a>
                            <details class="gr-descargar">
                                <summary class="btn btn--texto btn--sm">
                                    <span class="material-symbols-outlined" aria-hidden="true">download</span>
                                    <span>Descargar</span>
                                </summary>
                                <div class="gr-descargar-menu">
                                    <a href="/admin/redactor/descargar?id=<?= (int) $d->id ?>&formato=docx"><span class="material-symbols-outlined">description</span> Word (.docx)</a>
                                    <a href="/admin/redactor/descargar?id=<?= (int) $d->id ?>&formato=odt"><span class="material-symbols-outlined">article</span> LibreOffice (.odt)</a>
                                    <a href="/admin/redactor/descargar?id=<?= (int) $d->id ?>&formato=pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</a>
                                </div>
                            </details>
                            <details class="gr-descargar herr-mas">
                                <summary class="btn btn--texto btn--sm" title="Más acciones" aria-label="Más acciones de <?= s($d->nombre) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">more_horiz</span>
                                </summary>
                                <div class="gr-descargar-menu">
                                    <span class="herr-mas__rotulo">Mover a carpeta</span>
                                    <span class="herr-mas__carpeta">
                                        <?= \Classes\Carpetas::selector('redactor', (int) $d->id, (string) $d->departamento, $carpetas) ?>
                                    </span>
                                    <form method="POST" action="/admin/redactor/duplicar">
                                        <input type="hidden" name="id" value="<?= (int) $d->id ?>">
                                        <button type="submit">
                                            <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
                                            Duplicar
                                        </button>
                                    </form>
                                    <form method="POST" action="/admin/redactor/eliminar"
                                          data-confirm="¿Eliminar «<?= s($d->nombre) ?>»?|Se borrarán también sus imágenes. Esta acción no se puede deshacer.">
                                        <input type="hidden" name="id" value="<?= (int) $d->id ?>">
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
