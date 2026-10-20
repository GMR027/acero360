<?php
/**
 * views/admin/contabilidad/mas.php — Opciones menos frecuentes de contabilidad, explicadas en pocas palabras.
 */

soloAdmin();

$periodo = periodoSeleccionado();
$opciones = [
    ['/admin/contabilidad/documentos?anio=' . $periodo['anio'] . '&mes=' . $periodo['mes'], 'folder_open', 'Facturas y comprobantes del mes',
        'Todos los archivos que subiste (facturas, tickets, XML) y la descarga en ZIP para tu contador.'],
    ['/admin/contabilidad/cierre?anio=' . $periodo['anio'] . '&mes=' . $periodo['mes'], 'event_available', 'Cerrar el mes',
        'Cuando el mes ya está completo, la plataforma lo revisa y lo «congela» para que nadie lo cambie sin querer.'],
    ['/admin/contabilidad/periodos', 'lock', 'Meses ya cerrados', 'La lista de meses cerrados y su resumen.'],
    ['/admin/fiscal', 'request_quote', 'Impuestos', 'Cuánto separar este mes para el SAT (estimado).'],
];

template('header', ['titulo' => 'Más opciones', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'mas', 'periodo' => $periodo]);
?>

<section class="entregas-rejilla" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,18rem),1fr))">
    <?php foreach ($opciones as [$ruta, $icono, $titulo, $texto]) : ?>
        <a class="tarjeta" href="<?= escaparValores($ruta) ?>" style="text-decoration:none;color:inherit">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:2rem;color:var(--color-primario,#4679a6)"><?= $icono ?></span>
            <h2 class="titulo-5 mt-sm"><?= escaparValores($titulo) ?></h2>
            <p class="texto-secundario"><?= escaparValores($texto) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<?php template('footer'); ?>
