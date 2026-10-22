<?php
/**
 * views/admin/sheetflow/ver.php — Vista de sólo lectura de una tabla.
 */

soloAdmin();

use Classes\Sheetflow;

$hoja = \Model\SheetflowHoja::find((int) ($_GET['id'] ?? 0));
if (!$hoja) {
    redirigir('/admin/sheetflow', 'error', 'La tabla no existe o fue eliminada.');
}

$tabla = Sheetflow::html($hoja);
$documentos = $hoja->usosRedactor();

template('header', [
    'titulo'    => $hoja->nombre . ' · SheetFlow',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="herr" id="sheetflow">
    <div class="encabezado-pagina">
        <div>
            <h1 class="titulo-2"><?= s($hoja->nombre) ?></h1>
            <p class="texto-secundario">
                <?= s(formatear_fecha($hoja->fecha, 'd/m/Y')) ?>
                <?= $hoja->departamento !== '' ? ' · ' . s($hoja->departamento) : '' ?>
                <?= $hoja->descripcion !== '' ? ' · ' . s($hoja->descripcion) : '' ?>
            </p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a href="/admin/sheetflow/abrir?id=<?= (int) $hoja->id ?>" class="btn btn--primario">
                <span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar
            </a>
            <details class="gr-descargar">
                <summary class="btn btn--texto">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span> Descargar
                </summary>
                <div class="gr-descargar-menu">
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=xlsx"><span class="material-symbols-outlined">table_chart</span> Excel (.xlsx)</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=csv"><span class="material-symbols-outlined">description</span> CSV</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=sheetflow"><span class="material-symbols-outlined">grid_on</span> SheetFlow (.sheetflow)</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=pdf&inline=1" target="_blank" rel="noopener"><span class="material-symbols-outlined">print</span> Imprimir</a>
                </div>
            </details>
            <a href="/admin/sheetflow" class="btn btn--texto">← Tablas</a>
        </div>
    </div>

    <div class="card sf-ver">
        <div class="tabla-contenedor"><?= $tabla ?></div>
    </div>

    <div class="card sf-usos">
        <h3><span class="material-symbols-outlined" aria-hidden="true">share</span> Uso en el Redactor</h3>
        <p class="texto-secundario">
            En un documento del Redactor elige <em>Insertar › Tabla › Origen › SheetFlow</em>.
            La tabla queda vinculada y se actualiza al abrir o exportar el documento.
        </p>
        <?php if ($documentos) : ?>
            <p>
                <?php foreach ($documentos as $d) : ?>
                    <a class="btn btn--texto btn--sm" href="/admin/redactor/abrir?id=<?= (int) $d['id'] ?>">
                        <span class="material-symbols-outlined" aria-hidden="true">edit_document</span>
                        Usada en «<?= s($d['nombre']) ?>»
                    </a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>
</section>

<?php template('footer'); ?>
