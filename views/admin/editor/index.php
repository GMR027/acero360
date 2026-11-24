<?php
/**
 * views/admin/editor/index.php — Listado de documentos del Editor.
 *
 * Herramienta compartida: cualquier administrador ve y edita todos los
 * documentos. Cada documento tiene una o varias láminas dibujadas con Konva.
 */

soloAdmin();

$documentos = \Model\EditorDocumento::todos();
$filas = [];
$totalLaminas = 0;
$actualizadosMes = 0;

foreach ($documentos as $d) {
    $laminas = $d->laminas();
    [$ancho, $alto] = $d->medidas();
    $totalLaminas += count($laminas);
    if (str_starts_with((string) $d->actualizado, date('Y-m'))) {
        $actualizadosMes++;
    }
    $filas[] = [
        'doc'      => $d,
        'laminas'  => count($laminas),
        'primera'  => $laminas[0] ?? null,
        'mm'       => \Model\EditorDocumento::pxAMm($ancho) . ' × ' . \Model\EditorDocumento::pxAMm($alto),
    ];
}

// Confirmaciones, carpetas e iconos de las herramientas portadas (modal estilizado).
$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';

template('header', [
    'titulo'            => 'Editor',
    'subtitulo'         => 'Herramientas personales',
    'mostrarEncabezado' => false,
]);
?>

<section class="herr" id="editor">
    <header class="herr-hero">
        <span class="herr-hero__icono" aria-hidden="true">
            <span class="material-symbols-outlined">design_services</span>
        </span>
        <div class="herr-hero__texto">
            <p class="herr-hero__eyebrow">Herramientas personales</p>
            <h1 class="herr-hero__titulo">Editor</h1>
            <p class="herr-hero__desc">
                Documentos gráficos por láminas: planos, croquis, diagramas, portadas y fichas.
                Se dibujan con formas, textos e imágenes sobre un lienzo editable; el PDF y el PNG
                son sólo la exportación, así que siempre puedes volver a corregir.
            </p>
        </div>
        <div class="herr-hero__acciones">
            <a class="btn btn--primario" href="/admin/editor/crear">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
                <span>Nuevo documento</span>
            </a>
        </div>
    </header>

    <section class="herr-kpis" aria-label="Resumen del Editor">
        <div class="herr-kpi">
            <span class="herr-kpi__icono" aria-hidden="true">
                <span class="material-symbols-outlined">dashboard</span>
            </span>
            <div class="herr-kpi__texto">
                <span class="herr-kpi__etiqueta">Documentos</span>
                <span class="herr-kpi__valor"><?= number_format(count($filas)) ?></span>
                <span class="herr-kpi__pie">Lienzos editables</span>
            </div>
        </div>
        <div class="herr-kpi">
            <span class="herr-kpi__icono" aria-hidden="true">
                <span class="material-symbols-outlined">layers</span>
            </span>
            <div class="herr-kpi__texto">
                <span class="herr-kpi__etiqueta">Láminas</span>
                <span class="herr-kpi__valor"><?= number_format($totalLaminas) ?></span>
                <span class="herr-kpi__pie">En todos los documentos</span>
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

    <section class="herr-panel" aria-label="Documentos del Editor">
        <div class="herr-panel__cabecera">
            <span class="herr-panel__icono" aria-hidden="true">
                <span class="material-symbols-outlined">folder_open</span>
            </span>
            <div class="herr-panel__texto">
                <h2 class="herr-panel__titulo">Documentos</h2>
                <p class="herr-panel__sub">Compartidos: cualquier administrador puede abrirlos y editarlos.</p>
            </div>
            <div class="herr-panel__extra">
                <span class="badge badge--neutro badge--sin-punto">
                    <?= count($filas) ?> <?= count($filas) === 1 ? 'documento' : 'documentos' ?>
                </span>
            </div>
        </div>

        <?php if (!$filas) : ?>
            <div class="vacio">
                <span class="material-symbols-outlined vacio__icono" aria-hidden="true">design_services</span>
                <p class="vacio__titulo">Aún no hay documentos</p>
                <p>Elige un tamaño de página (Carta, Oficio, A4, A3…) o define uno personalizado en milímetros.</p>
                <a class="btn btn--primario" href="/admin/editor/crear">
                    <span class="material-symbols-outlined" aria-hidden="true">add</span>
                    Nuevo documento
                </a>
            </div>
        <?php else : ?>
            <div class="herr-panel__cuerpo">
                <div class="ed-lista">
                    <?php foreach ($filas as $f) : $d = $f['doc']; ?>
                        <article class="card ed-tarjeta">
                            <a class="ed-tarjeta__vista" href="/admin/editor/abrir?id=<?= (int) $d->id ?>"
                               aria-label="Abrir <?= s($d->nombre) ?>">
                                <?php if ($f['primera'] && !empty($f['primera']->marcas_png)) : ?>
                                    <img src="/public/uploads/vplan/<?= s($f['primera']->marcas_png) ?>" alt="" loading="lazy">
                                <?php else : ?>
                                    <span class="material-symbols-outlined" aria-hidden="true">design_services</span>
                                <?php endif; ?>
                                <span class="ed-tarjeta__paginas"><?= (int) $f['laminas'] ?> <?= $f['laminas'] === 1 ? 'lámina' : 'láminas' ?></span>
                            </a>
                            <div class="ed-tarjeta__cuerpo">
                                <h3><?= s($d->nombre) ?></h3>
                                <div class="herr-doc__meta">
                                    <span class="herr-meta">
                                        <span class="material-symbols-outlined" aria-hidden="true">aspect_ratio</span>
                                        <?= s($f['mm']) ?> mm
                                    </span>
                                    <span class="herr-meta">
                                        <span class="material-symbols-outlined" aria-hidden="true">texture</span>
                                        <?= s(\Model\EditorDocumento::FONDOS[$d->fondo] ?? 'Liso') ?>
                                    </span>
                                    <span class="herr-meta">
                                        <span class="material-symbols-outlined" aria-hidden="true">history</span>
                                        <?= s(formatear_fecha($d->actualizado, 'd/m/Y H:i')) ?>
                                    </span>
                                </div>
                                <div class="herramientas-acciones">
                                    <a href="/admin/editor/abrir?id=<?= (int) $d->id ?>" class="btn btn--primario btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true">edit</span> Abrir
                                    </a>
                                    <a href="/admin/editor/ver?id=<?= (int) $d->id ?>" class="btn btn--texto btn--sm"
                                       title="Ver <?= s($d->nombre) ?>" aria-label="Ver <?= s($d->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                                        <span class="ed-accion-texto">Ver</span>
                                    </a>
                                    <a href="/admin/editor/descargar?id=<?= (int) $d->id ?>&formato=pdf" class="btn btn--texto btn--sm"
                                       title="Descargar PDF de <?= s($d->nombre) ?>" aria-label="Descargar PDF de <?= s($d->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>
                                        <span class="ed-accion-texto">PDF</span>
                                    </a>
                                    <form method="POST" action="/admin/editor/duplicar" class="en-linea">
                                        <input type="hidden" name="id" value="<?= (int) $d->id ?>">
                                        <button type="submit" class="btn btn--texto btn--sm btn--icono"
                                                title="Duplicar" aria-label="Duplicar <?= s($d->nombre) ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
                                        </button>
                                    </form>
                                    <form method="POST" action="/admin/editor/eliminar" class="en-linea"
                                          data-confirm="¿Eliminar «<?= s($d->nombre) ?>»?|Se borrarán también sus láminas e imágenes. Esta acción no se puede deshacer.">
                                        <input type="hidden" name="id" value="<?= (int) $d->id ?>">
                                        <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                title="Eliminar" aria-label="Eliminar <?= s($d->nombre) ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</section>

<?php template('footer'); ?>
