<?php
/**
 * views/admin/contabilidad/periodos.php — Historial de periodos cerrados.
 * El cierre y la reapertura controlada llegan con el módulo fiscal.
 */

soloAdmin();

$periodos = \App\Contabilidad::periodosCerrados();
$periodo  = periodoSeleccionado();

template('header', ['titulo' => 'Periodos cerrados', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'periodos', 'periodo' => $periodo]);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <?php if (!$periodos) : ?>
        <div class="vacio"><span class="material-symbols-outlined vacio__icono" aria-hidden="true">lock_open</span>
            <p class="vacio__titulo">Todavía no hay periodos cerrados</p>
            <p>Un periodo cerrado bloquea cambios en sus ventas, cobros, gastos y documentos, salvo reapertura autorizada.</p></div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Periodos cerrados</caption>
                <thead><tr><th scope="col">Periodo</th><th scope="col">Cerrado el</th><th scope="col">Cerrado por</th></tr></thead>
                <tbody>
                    <?php foreach ($periodos as $p) : ?>
                        <tr><td><?= nombreMes((int) $p['mes']) ?> <?= (int) $p['anio'] ?></td>
                            <td><?= $p['cerrado_en'] ? date('d/m/Y H:i', strtotime($p['cerrado_en'])) : '—' ?></td>
                            <td><?= escaparValores((string) $p['cerrado_por_nombre']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
