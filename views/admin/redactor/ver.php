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
[$aw] = $documento->medidas();
$comentarios = json_decode((string) $documento->comentarios, true) ?: [];
$sellos = \Classes\RedactorFirmas::sellos($documento);

template('header', [
    'titulo'    => $documento->nombre . ' · Redactor',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="herr" id="redactor">
    <div class="encabezado-pagina">
        <div>
            <h1 class="titulo-2"><?= s($documento->nombre) ?></h1>
            <p class="texto-secundario">
                <?= s(formatear_fecha($documento->fecha, 'd/m/Y')) ?>
                <?= $documento->departamento !== '' ? ' · ' . s($documento->departamento) : '' ?>
            </p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a href="/admin/redactor/abrir?id=<?= (int) $documento->id ?>" class="btn btn--primario">
                <span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar
            </a>
            <details class="gr-descargar">
                <summary class="btn btn--texto">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span> Descargar
                </summary>
                <div class="gr-descargar-menu">
                    <a href="/admin/redactor/descargar?id=<?= (int) $documento->id ?>&formato=docx"><span class="material-symbols-outlined">description</span> Word (.docx)</a>
                    <a href="/admin/redactor/descargar?id=<?= (int) $documento->id ?>&formato=odt"><span class="material-symbols-outlined">article</span> LibreOffice (.odt)</a>
                    <a href="/admin/redactor/descargar?id=<?= (int) $documento->id ?>&formato=pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</a>
                    <a href="/admin/redactor/descargar?id=<?= (int) $documento->id ?>&formato=pdf&inline=1" target="_blank" rel="noopener"><span class="material-symbols-outlined">print</span> Imprimir</a>
                    <?php if ($comentarios) : ?>
                        <label class="rd-opc"><input type="checkbox" id="rd-x-coment"> Incluir comentarios al exportar</label>
                    <?php endif; ?>
                </div>
            </details>
            <a href="/admin/redactor" class="btn btn--texto">← Documentos</a>
        </div>
    </div>

    <div class="rd-fondo">
        <article class="rd-hoja rd-lectura"
                 style="width:<?= round($aw * 37.795, 1) ?>px;padding:<?= (float) $documento->margen_sup ?>cm <?= (float) $documento->margen_der ?>cm <?= (float) $documento->margen_inf ?>cm <?= (float) $documento->margen_izq ?>cm">
            <?= $documento->contenido !== '' ? $documento->contenido : '<p class="text-muted">Documento vacío.</p>' ?>
        </article>
    </div>

    <?php if ($sellos) : ?>
        <div class="card rd-sellos-ver">
            <h3><span class="material-symbols-outlined" aria-hidden="true">approve</span> Firmas electrónicas</h3>
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
    <?php endif; ?>

    <?php if ($comentarios) : ?>
        <div class="card rd-comentarios-ver">
            <h3><span class="material-symbols-outlined" aria-hidden="true">forum</span> Comentarios (<?= count($comentarios) ?>)</h3>
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
