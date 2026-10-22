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
foreach ($documentos as $d) {
    $laminas = $d->laminas();
    [$ancho, $alto] = $d->medidas();
    $filas[] = [
        'doc'      => $d,
        'laminas'  => count($laminas),
        'primera'  => $laminas[0] ?? null,
        'mm'       => \Model\EditorDocumento::pxAMm($ancho) . ' × ' . \Model\EditorDocumento::pxAMm($alto),
    ];
}

template('header', [
    'titulo'    => 'Editor',
    'subtitulo' => 'Herramientas personales',
    'acciones'  => '<a class="btn btn--primario" href="/admin/editor/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>'
        . '<span>Nuevo documento</span></a>',
]);
?>

<section class="herr" id="editor">
    <div class="encabezado-pagina">
        <div>
            <p class="texto-secundario">
                Documentos gráficos por páginas: planos, croquis, diagramas, portadas y fichas.
                Se dibujan con formas, textos e imágenes sobre un lienzo editable; el PDF y el PNG
                son sólo la exportación, así que siempre puedes volver a corregir.
            </p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a class="btn btn--primario" href="/admin/editor/crear">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
                <span>Nuevo documento</span>
            </a>
        </div>
    </div>

    <?php if (!$filas) : ?>
        <div class="card">
            <p class="text-muted">Aún no hay documentos. Crea el primero con «Nuevo documento»:
                elige un tamaño de página (Carta, Oficio, A4, A3…) o define uno personalizado en milímetros.</p>
        </div>
    <?php endif; ?>

    <?php if ($filas) : ?>
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
                        <p class="text-sm text-muted">
                            <?= s($f['mm']) ?> mm · <?= s(\Model\EditorDocumento::FONDOS[$d->fondo] ?? 'Liso') ?>
                            <br><?= s(formatear_fecha($d->actualizado, 'd/m/Y H:i')) ?>
                        </p>
                        <div class="herramientas-acciones">
                            <a href="/admin/editor/abrir?id=<?= (int) $d->id ?>" class="btn btn--primario btn--sm">
                                <span class="material-symbols-outlined" aria-hidden="true">edit</span> Abrir
                            </a>
                            <a href="/admin/editor/ver?id=<?= (int) $d->id ?>" class="btn btn--texto btn--sm">
                                <span class="material-symbols-outlined" aria-hidden="true">visibility</span> Ver
                            </a>
                            <a href="/admin/editor/descargar?id=<?= (int) $d->id ?>&formato=pdf" class="btn btn--texto btn--sm">
                                <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span> PDF
                            </a>
                            <form method="POST" action="/admin/editor/duplicar" class="en-linea">
                                <input type="hidden" name="id" value="<?= (int) $d->id ?>">
                                <button type="submit" class="btn btn--texto btn--sm">
                                    <span class="material-symbols-outlined" aria-hidden="true">content_copy</span> Duplicar
                                </button>
                            </form>
                            <form method="POST" action="/admin/editor/eliminar" class="en-linea"
                                  data-confirm="¿Eliminar «<?= s($d->nombre) ?>»?|Se borrarán también sus láminas e imágenes. Esta acción no se puede deshacer.">
                                <input type="hidden" name="id" value="<?= (int) $d->id ?>">
                                <button type="submit" class="btn btn--peligro btn--sm">
                                    <span class="material-symbols-outlined" aria-hidden="true">delete</span> Eliminar
                                </button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
