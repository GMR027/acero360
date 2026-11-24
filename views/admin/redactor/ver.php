<?php
/**
 * views/admin/redactor/ver.php — Lectura de un documento del Redactor.
 */

soloAdmin();

$documento = \Model\RedactorDocumento::find((int) ($_GET['id'] ?? 0));
if (!$documento) {
    redirigir('/admin/redactor', 'error', 'El documento no existe o fue eliminado.');
}

$documento->contenido = \Classes\Sheetflow::refrescarHtml((string) $documento->contenido);
[$aw, $ah] = $documento->medidas();
$comentarios = json_decode((string) $documento->comentarios, true) ?: [];
$sellos = \Classes\RedactorFirmas::sellos($documento);

template('header', [
    'titulo'            => $documento->nombre . ' · Redactor',
    'subtitulo'         => 'Herramientas personales',
    'mostrarEncabezado' => false,
]);
?>

<section class="herr" id="redactor">
    <header class="herr-hero herr-hero--compacta">
        <span class="herr-hero__icono" aria-hidden="true">
            <span class="material-symbols-outlined">description</span>
        </span>
        <div class="herr-hero__texto">
            <p class="herr-hero__eyebrow">Redactor · documento compartido</p>
            <h1 class="herr-hero__titulo"><?= s($documento->nombre) ?></h1>
            <div class="herr-hero__meta">
                <span class="herr-meta">
                    <span class="material-symbols-outlined" aria-hidden="true">calendar_today</span>
                    <?= s(formatear_fecha($documento->fecha, 'd/m/Y')) ?>
                </span>
                <?php if ($documento->departamento !== '') : ?>
                    <span class="herr-meta">
                        <span class="material-symbols-outlined" aria-hidden="true">folder</span>
                        <?= s($documento->departamento) ?>
                    </span>
                <?php endif; ?>
                <span class="herr-meta">
                    <span class="material-symbols-outlined" aria-hidden="true">description</span>
                    <?= s(\Model\RedactorDocumento::PAPELES[$documento->papel][0]) ?>
                </span>
                <?php if ($comentarios) : ?>
                    <span class="herr-meta">
                        <span class="material-symbols-outlined" aria-hidden="true">forum</span>
                        <?= count($comentarios) ?> comentario<?= count($comentarios) === 1 ? '' : 's' ?>
                    </span>
                <?php endif; ?>
                <?php if ($sellos) : ?>
                    <span class="herr-meta">
                        <span class="material-symbols-outlined" aria-hidden="true">approve</span>
                        <?= count($sellos) ?> firma<?= count($sellos) === 1 ? '' : 's' ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="herr-hero__acciones">
            <a href="/admin/redactor/abrir?id=<?= (int) $documento->id ?>" class="btn btn--primario">
                <span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar
            </a>
            <details class="gr-descargar">
                <summary class="btn btn--secundario">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span> Descargar
                </summary>
                <div class="gr-descargar-menu">
                    <a href="/admin/redactor/descargar?id=<?= (int) $documento->id ?>&formato=docx"><span class="material-symbols-outlined">description</span> Word (.docx)</a>
                    <a href="/admin/redactor/descargar?id=<?= (int) $documento->id ?>&formato=odt"><span class="material-symbols-outlined">article</span> LibreOffice (.odt)</a>
                    <a href="/admin/redactor/descargar?id=<?= (int) $documento->id ?>&formato=pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</a>
                    <a href="/admin/redactor/descargar?id=<?= (int) $documento->id ?>&formato=pdf<?= consultaPdfInline() ?>"<?= atributosNuevaPestana() ?>><span class="material-symbols-outlined">print</span> Imprimir</a>
                    <?php if ($comentarios) : ?>
                        <label class="rd-opc"><input type="checkbox" id="rd-x-coment"> Incluir comentarios al exportar</label>
                    <?php endif; ?>
                </div>
            </details>
            <a href="/admin/redactor" class="btn btn--texto">
                <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Documentos
            </a>
        </div>
    </header>

    <section class="herr-panel" aria-label="Contenido del documento">
        <div class="herr-panel__cabecera">
            <span class="herr-panel__icono" aria-hidden="true">
                <span class="material-symbols-outlined">chrome_reader_mode</span>
            </span>
            <div class="herr-panel__texto">
                <h2 class="herr-panel__titulo">Vista de lectura</h2>
                <p class="herr-panel__sub">El documento se muestra tal como se exportaría.</p>
            </div>
            <div class="herr-panel__extra">
                <span class="badge badge--neutro badge--sin-punto">
                    <?= s($documento->orientacion === 'horizontal' ? 'Horizontal' : 'Vertical') ?>
                </span>
            </div>
        </div>
        <div class="herr-visor">
            <article class="rd-hoja rd-lectura"
                     style="width:<?= round($aw * 37.795, 1) ?>px;aspect-ratio:<?= (int) $aw ?> / <?= (int) $ah ?>;padding:<?= (float) $documento->margen_sup ?>cm <?= (float) $documento->margen_der ?>cm <?= (float) $documento->margen_inf ?>cm <?= (float) $documento->margen_izq ?>cm">
                <?= $documento->contenido !== '' ? $documento->contenido : '<p class="text-muted">Documento vacío.</p>' ?>
            </article>
        </div>
    </section>

    <?php if ($sellos) : ?>
        <section class="herr-panel rd-sellos-ver" aria-label="Firmas electrónicas">
            <div class="herr-panel__cabecera">
                <span class="herr-panel__icono" aria-hidden="true">
                    <span class="material-symbols-outlined">approve</span>
                </span>
                <div class="herr-panel__texto">
                    <h2 class="herr-panel__titulo">Firmas electrónicas</h2>
                    <p class="herr-panel__sub"><?= count($sellos) ?> firma<?= count($sellos) === 1 ? '' : 's' ?> registrada<?= count($sellos) === 1 ? '' : 's' ?>.</p>
                </div>
            </div>
            <div class="herr-panel__cuerpo">
                <?php foreach ($sellos as $f) : ?>
                    <p>
                        <strong><?= s($f['nombre']) ?></strong><?= $f['cargo'] !== '' ? ' · ' . s($f['cargo']) : '' ?>
                        · <?= s(date('d/m/Y H:i', strtotime($f['firmado']))) ?>
                        <span class="<?= $f['valido'] ? 'rd-sello-ok' : 'rd-sello-mal' ?>">
                            <?= $f['valido'] ? '✔ Íntegro' : '⚠ Modificado después de firmar' ?>
                        </span>
                        <small class="text-muted mono">huella <?= s(substr($f['huella'], 0, 16)) ?>…</small>
                    </p>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($comentarios) : ?>
        <section class="herr-panel rd-comentarios-ver" aria-label="Comentarios del documento">
            <div class="herr-panel__cabecera">
                <span class="herr-panel__icono" aria-hidden="true">
                    <span class="material-symbols-outlined">forum</span>
                </span>
                <div class="herr-panel__texto">
                    <h2 class="herr-panel__titulo">Comentarios</h2>
                    <p class="herr-panel__sub"><?= count($comentarios) ?> comentario<?= count($comentarios) === 1 ? '' : 's' ?> en el documento.</p>
                </div>
            </div>
            <div class="herr-panel__cuerpo">
                <?php foreach ($comentarios as $c) : ?>
                    <div class="rd-com-item<?= !empty($c['resuelto']) ? ' resuelto' : '' ?>">
                        <?php if (!empty($c['cita'])) : ?><blockquote>«<?= s($c['cita']) ?>»</blockquote><?php endif; ?>
                        <p>
                            <strong><?= s($c['autor']) ?></strong>
                            <small class="text-muted"><?= s($c['fecha'] ? date('d/m/Y H:i', strtotime($c['fecha'])) : '') ?></small>
                            <?= !empty($c['resuelto']) ? ' <span class="badge">Resuelto</span>' : '' ?>
                        </p>
                        <p><?= nl2br(s($c['texto'])) ?></p>
                        <?php foreach ($c['respuestas'] ?? [] as $r) : ?>
                            <p class="rd-resp">↳ <strong><?= s($r['autor']) ?></strong>: <?= s($r['texto']) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <script>
    (function () {
        var c = document.getElementById('rd-x-coment');
        if (!c) return;
        c.addEventListener('change', function () {
            document.querySelectorAll('.gr-descargar-menu a').forEach(function (a) {
                a.href = a.href.replace(/&comentarios=1/, '') + (c.checked ? '&comentarios=1' : '');
            });
        });
    })();
    </script>
</section>

<?php template('footer'); ?>
