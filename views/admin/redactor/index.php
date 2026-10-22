<?php
/**
 * views/admin/redactor/index.php — Listado de documentos del Redactor.
 *
 * Sección compartida: cualquier administrador ve y edita todos los
 * documentos, agrupados por carpeta.
 */

soloAdmin();

$porDepto = [];
foreach (\Model\RedactorDocumento::todos() as $d) {
    $carpeta = $d->departamento !== '' ? $d->departamento : \Classes\Carpetas::SIN;
    $porDepto[$carpeta][] = $d;
}
$carpetas = array_values(array_diff(array_keys($porDepto), [\Classes\Carpetas::SIN]));

template('header', [
    'titulo'    => 'Redactor',
    'subtitulo' => 'Herramientas personales',
    'acciones'  => '<a class="btn btn--primario" href="/admin/redactor/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>'
        . '<span>Nuevo documento</span></a>',
]);
?>

<section class="herr" id="redactor">
    <div class="encabezado-pagina">
        <div>
            <p class="texto-secundario">
                Documentos de texto compartidos: memorias, cartas, actas, procedimientos…
                Con corrector ortográfico, plantillas, comentarios, control de cambios y
                exportación a Word, LibreOffice o PDF.
            </p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a class="btn btn--primario" href="/admin/redactor/crear">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
                <span>Nuevo documento</span>
            </a>
        </div>
    </div>

    <?php if (!$porDepto) : ?>
        <div class="card">
            <p class="text-muted">Aún no hay documentos. Crea el primero con «Nuevo documento»:
                puedes empezar en blanco o con una plantilla.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($porDepto as $depto => $docs) : ?>
        <div class="card rd-grupo">
            <h3>
                <span class="material-symbols-outlined" aria-hidden="true">folder_open</span>
                <?= s($depto) ?>
                <small class="text-muted">(<?= count($docs) ?>)</small>
                <?php if ($depto !== \Classes\Carpetas::SIN) : ?>
                    <button type="button" class="btn btn--texto btn--sm" data-carpeta-renombrar="<?= s($depto) ?>"
                            data-herramienta="redactor" title="Renombrar carpeta">
                        <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                    </button>
                <?php endif; ?>
            </h3>
            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead><tr><th>Fecha</th><th>Nombre</th><th>Hoja</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($docs as $d) : ?>
                        <tr>
                            <td class="mono"><?= s(formatear_fecha($d->fecha, 'd/m/Y')) ?></td>
                            <td>
                                <span class="material-symbols-outlined ico-tabla" aria-hidden="true">description</span>
                                <strong><?= s($d->nombre) ?></strong>
                            </td>
                            <td><?= s(\Model\RedactorDocumento::PAPELES[$d->papel][0]) ?> · <?= s($d->orientacion) ?></td>
                            <td class="herramientas-acciones">
                                <a href="/admin/redactor/ver?id=<?= (int) $d->id ?>" class="btn btn--texto btn--sm">
                                    <span class="material-symbols-outlined" aria-hidden="true">visibility</span> Ver
                                </a>
                                <a href="/admin/redactor/abrir?id=<?= (int) $d->id ?>" class="btn btn--primario btn--sm">
                                    <span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar
                                </a>
                                <details class="gr-descargar">
                                    <summary class="btn btn--texto btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true">download</span> Descargar
                                    </summary>
                                    <div class="gr-descargar-menu">
                                        <a href="/admin/redactor/descargar?id=<?= (int) $d->id ?>&formato=docx"><span class="material-symbols-outlined">description</span> Word (.docx)</a>
                                        <a href="/admin/redactor/descargar?id=<?= (int) $d->id ?>&formato=odt"><span class="material-symbols-outlined">article</span> LibreOffice (.odt)</a>
                                        <a href="/admin/redactor/descargar?id=<?= (int) $d->id ?>&formato=pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</a>
                                    </div>
                                </details>
                                <?= \Classes\Carpetas::selector('redactor', (int) $d->id, (string) $d->departamento, $carpetas) ?>
                                <form method="POST" action="/admin/redactor/duplicar" class="en-linea">
                                    <input type="hidden" name="id" value="<?= (int) $d->id ?>">
                                    <button type="submit" class="btn btn--texto btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true">content_copy</span> Duplicar
                                    </button>
                                </form>
                                <form method="POST" action="/admin/redactor/eliminar" class="en-linea"
                                      data-confirm="¿Eliminar «<?= s($d->nombre) ?>»?|Se borrarán también sus imágenes. Esta acción no se puede deshacer.">
                                    <input type="hidden" name="id" value="<?= (int) $d->id ?>">
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
