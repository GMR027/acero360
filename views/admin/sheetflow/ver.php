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
$datos = $hoja->contenido();
$filas = count($datos['rows'] ?? []);
$columnas = count($datos['cols'] ?? []);

template('header', [
    'titulo'            => $hoja->nombre . ' · SheetFlow',
    'subtitulo'         => 'Herramientas personales',
    'mostrarEncabezado' => false,
]);
?>

<section class="herr" id="sheetflow">
    <header class="herr-hero herr-hero--compacta">
        <span class="herr-hero__icono" aria-hidden="true">
            <span class="material-symbols-outlined">table_chart</span>
        </span>
        <div class="herr-hero__texto">
            <p class="herr-hero__eyebrow">SheetFlow · tabla compartida</p>
            <h1 class="herr-hero__titulo"><?= s($hoja->nombre) ?></h1>
            <div class="herr-hero__meta">
                <span class="herr-meta">
                    <span class="material-symbols-outlined" aria-hidden="true">calendar_today</span>
                    <?= s(formatear_fecha($hoja->fecha, 'd/m/Y')) ?>
                </span>
                <?php if ($hoja->departamento !== '') : ?>
                    <span class="herr-meta">
                        <span class="material-symbols-outlined" aria-hidden="true">folder</span>
                        <?= s($hoja->departamento) ?>
                    </span>
                <?php endif; ?>
                <span class="herr-meta">
                    <span class="material-symbols-outlined" aria-hidden="true">grid_on</span>
                    <?= number_format($filas) ?> × <?= number_format($columnas) ?>
                </span>
                <span class="herr-meta">
                    <span class="material-symbols-outlined" aria-hidden="true">history</span>
                    Actualizada <?= s(formatear_fecha($hoja->actualizado, 'd/m/Y H:i')) ?>
                </span>
            </div>
            <?php if ($hoja->descripcion !== '') : ?>
                <p class="herr-hero__desc"><?= s($hoja->descripcion) ?></p>
            <?php endif; ?>
        </div>
        <div class="herr-hero__acciones">
            <a href="/admin/sheetflow/abrir?id=<?= (int) $hoja->id ?>" class="btn btn--primario">
                <span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar
            </a>
            <details class="gr-descargar">
                <summary class="btn btn--secundario">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span> Descargar
                </summary>
                <div class="gr-descargar-menu">
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=xlsx"><span class="material-symbols-outlined">table_chart</span> Excel (.xlsx)</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=csv"><span class="material-symbols-outlined">description</span> CSV</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=sheetflow"><span class="material-symbols-outlined">grid_on</span> SheetFlow (.sheetflow)</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=pdf<?= consultaPdfInline() ?>"<?= atributosNuevaPestana() ?>><span class="material-symbols-outlined">print</span> Imprimir</a>
                </div>
            </details>
            <a href="/admin/sheetflow" class="btn btn--texto">
                <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Tablas
            </a>
        </div>
    </header>

    <section class="herr-panel" aria-label="Contenido de la tabla">
        <div class="herr-panel__cabecera">
            <span class="herr-panel__icono" aria-hidden="true">
                <span class="material-symbols-outlined">visibility</span>
            </span>
            <div class="herr-panel__texto">
                <h2 class="herr-panel__titulo">Vista de la tabla</h2>
                <p class="herr-panel__sub">Sólo lectura. Para cambiar celdas, abre la tabla en el editor.</p>
            </div>
            <div class="herr-panel__extra">
                <span class="badge badge--neutro badge--sin-punto">Sólo lectura</span>
            </div>
        </div>
        <div class="herr-panel__cuerpo herr-panel__cuerpo--sin-relleno sf-ver">
            <div class="tabla-contenedor"><?= $tabla ?></div>
        </div>
    </section>

    <section class="herr-panel sf-usos" aria-label="Uso de la tabla en el Redactor">
        <div class="herr-panel__cabecera">
            <span class="herr-panel__icono" aria-hidden="true">
                <span class="material-symbols-outlined">share</span>
            </span>
            <div class="herr-panel__texto">
                <h2 class="herr-panel__titulo">Uso en el Redactor</h2>
                <p class="herr-panel__sub">La tabla puede vincularse dentro de un documento de texto.</p>
            </div>
        </div>
        <div class="herr-panel__cuerpo">
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
</section>

<?php template('footer'); ?>
