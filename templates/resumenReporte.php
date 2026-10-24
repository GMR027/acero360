<?php
/**
 * templates/resumenReporte.php — Tarjetas de resumen de un reporte y botones de exportación.
 * Variables: $tarjetas (lista de ['etiqueta', 'valor', 'pie', 'variacion' (float|null), 'subeEsBueno' (bool)]),
 *            $etiquetaComparativa (texto, p. ej. «vs. Septiembre 2026»; vacío si no hay comparativa),
 *            $exportar (ruta base con su consulta, sin formato; vacío para no mostrar botones),
 *            $accionesExtra (opcional: botones propios de la pantalla, a la derecha de los de exportar,
 *                            cada uno con destino, texto, icono y ayuda),
 *            $ocrDisponible (opcional: true/false para avisar si el servidor puede leer fotos y PDFs escaneados).
 */
$etiquetaComparativa = $etiquetaComparativa ?? '';
$exportar = $exportar ?? '';
$accionesExtra = $accionesExtra ?? [];
$ocrDisponible = $ocrDisponible ?? null;
?>
<section class="rejilla-stats mb-lg" aria-label="Resumen del reporte">
    <?php foreach ($tarjetas as $t) :
        $v = $t['variacion'] ?? null;
        $bueno = $v === null ? null : (($v >= 0) === ($t['subeEsBueno'] ?? true)); ?>
        <div class="stat"><span class="stat__etiqueta"><?= escaparValores($t['etiqueta']) ?></span>
            <span class="stat__valor"><?= escaparValores((string) $t['valor']) ?></span>
            <span class="stat__pie"><?= escaparValores((string) ($t['pie'] ?? '')) ?></span>
            <?php if ($etiquetaComparativa !== '') : ?>
                <span class="stat__pie" data-comparativa>
                    <?php if ($v === null) : ?>
                        Sin datos <?= escaparValores($etiquetaComparativa) ?>
                    <?php else : ?>
                        <span class="badge <?= $v == 0 ? 'badge--neutro' : ($bueno ? 'badge--aprobado' : 'badge--pendiente') ?> badge--sin-punto cifra"><?= $v > 0 ? '▲ +' : ($v < 0 ? '▼ −' : '') ?><?= number_format(abs($v), 1) ?> %</span>
                        <?= escaparValores($etiquetaComparativa) ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php if ($exportar !== '' || $accionesExtra) : ?>
    <div class="formulario__acciones mb-lg" style="justify-content:flex-start"<?= $ocrDisponible !== null ? ' data-ocr="' . ($ocrDisponible ? '1' : '0') . '"' : '' ?>>
        <?php if ($exportar !== '') : ?>
            <?php foreach (['pdf' => ['PDF', 'picture_as_pdf'], 'xlsx' => ['Excel', 'table'], 'csv' => ['CSV', 'description']] as $formato => [$texto, $icono]) : ?>
                <a class="btn btn--secundario" href="<?= escaparValores($exportar . (str_contains($exportar, '?') ? '&' : '?') . 'formato=' . $formato) ?>"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem"><?= $icono ?></span><span>Exportar <?= $texto ?></span></a>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php foreach ($accionesExtra as $accion) : ?>
            <button class="btn btn--primario" type="button"
                    data-inteligente="<?= escaparValores($accion['destino']) ?>"
                    title="<?= escaparValores((string) ($accion['ayuda'] ?? '')) ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem"><?= escaparValores((string) $accion['icono']) ?></span>
                <span><?= escaparValores((string) $accion['texto']) ?></span>
            </button>
        <?php endforeach; ?>
        <?php if ($accionesExtra) : ?>
            <!-- Token de un solo uso que consume el endpoint de lectura (src/js/modulos/inteligente.js). -->
            <input type="hidden" data-inteligente-token value="<?= tokenFormulario() ?>">
        <?php endif; ?>
    </div>
<?php endif; ?>
