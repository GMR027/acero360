<?php
/**
 * views/admin/contabilidad/cierre.php — Revisión y cierre mensual, reapertura controlada e historial.
 */

soloAdmin();

$periodo  = periodoSeleccionado();
$contrib  = \App\Contabilidad::contribuyentePrincipal();
$estado   = \App\CierrePeriodo::periodo($contrib, $periodo['anio'], $periodo['mes']);
$cerrado  = $estado && $estado['estado'] === 'cerrado';
$empresas = \App\Empresas::mostrarTodos(100, 0);
$historial = \App\CierrePeriodo::historial($contrib, $periodo['anio'], $periodo['mes']);
$checks   = $cerrado ? ($estado['snapshot']['verificaciones'] ?? []) : \App\CierrePeriodo::verificaciones($contrib, $periodo['anio'], $periodo['mes']);
$errores  = array_filter($checks, static fn ($c) => $c['nivel'] === 'error');
$avisos   = array_filter($checks, static fn ($c) => $c['nivel'] === 'aviso');
$niveles  = ['error' => ['Bloquea el cierre', 'badge--error', 'error'], 'aviso' => ['Requiere reconocerse', 'badge--pendiente', 'warning'], 'info' => ['Informativo', 'badge--neutro', 'info']];
$consulta = http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes']]);

template('header', ['titulo' => 'Cierre del mes', 'subtitulo' => nombreMes($periodo['mes']) . ' ' . $periodo['anio'], 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'cierre', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/contabilidad/cierre', 'periodo' => $periodo, 'empresas' => $empresas]);
?>

<section class="tarjeta mb-lg">
    <p>
        <?= $cerrado ? '<span class="badge badge--neutro">Periodo cerrado</span>' : '<span class="badge badge--aprobado">Periodo abierto</span>' ?>
        <?php if ($cerrado) : ?>
            Cerrado el <?= date('d/m/Y H:i', strtotime($estado['cerrado_en'])) ?> por <?= escaparValores((string) $estado['cerrado_por_nombre']) ?>.
            Ventas, cobros, gastos, retenciones, ajustes y documentos de este mes no se pueden modificar hasta reabrirlo.
        <?php endif; ?>
    </p>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <h2 class="titulo-5" style="padding:1rem 1rem 0"><?= $cerrado ? 'Verificaciones al momento del cierre' : 'Verificaciones previas al cierre' ?></h2>
    <?php if (!$checks) : ?>
        <p class="texto-secundario" style="padding:1rem">Sin hallazgos: no se detectaron ventas duplicadas, operaciones sin clasificar, comprobantes pendientes ni descuadres.</p>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Verificaciones</caption>
                <thead><tr><th scope="col">Nivel</th><th scope="col">Verificación</th><th scope="col" class="tabla--numerica">Cantidad</th><th scope="col">Detalle</th></tr></thead>
                <tbody>
                    <?php foreach ($checks as $c) : [$etiqueta, $clase] = $niveles[$c['nivel']]; ?>
                        <tr><td><span class="badge <?= $clase ?>"><?= $etiqueta ?></span></td>
                            <td><strong><?= escaparValores($c['titulo']) ?></strong></td>
                            <td class="tabla--numerica cifra"><?= (int) $c['cantidad'] ?></td>
                            <td><?= escaparValores($c['detalle']) ?>
                                <?php if (!$cerrado && $c['enlace']) : ?> <a href="<?= escaparValores($c['enlace']) ?>">Revisar</a><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (!$cerrado) : ?>
    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Cerrar <?= nombreMes($periodo['mes']) ?> <?= $periodo['anio'] ?></h2>
        <?php if ($errores) : ?>
            <p class="nota nota--error mb-md" role="alert"><span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
                <span>Corrige los hallazgos que bloquean el cierre antes de continuar.</span></p>
        <?php endif; ?>
        <form class="formulario" action="/admin/contabilidad/cierre/accion" method="post" data-validar
              data-confirmacion-sobre="Cierre mensual"
              data-confirmacion="¿Cerrar este mes?"
              data-confirmacion-mensaje="Se guarda una fotografía del mes y se bloquean sus ventas, cobros, gastos y documentos. Solo se modifica reabriéndolo con contraseña y motivo."
              data-confirmacion-boton="Sí, cerrar el mes">
            <input type="hidden" name="accion" value="cerrar"><input type="hidden" name="anio" value="<?= $periodo['anio'] ?>"><input type="hidden" name="mes" value="<?= $periodo['mes'] ?>">
            <?php foreach ($avisos as $a) : ?>
                <label class="comprobacion" for="rec-<?= escaparValores($a['codigo']) ?>">
                    <input type="checkbox" id="rec-<?= escaparValores($a['codigo']) ?>" name="reconocidos[]" value="<?= escaparValores($a['codigo']) ?>">
                    <span>Reconozco: <strong><?= escaparValores($a['titulo']) ?></strong> (<?= (int) $a['cantidad'] ?>)</span></label>
            <?php endforeach; ?>
            <div class="campo mt-md"><label class="campo__etiqueta" for="nota">Nota del cierre (opcional)</label>
                <input class="control" type="text" id="nota" name="nota" maxlength="200"></div>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit"<?= $errores ? ' disabled' : '' ?>>Cerrar el mes</button></div>
        </form>
    </section>
<?php else : ?>
    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Resumen del cierre</h2>
        <?php $f = $estado['snapshot']['fiscal'] ?? []; $mx = static fn ($c) => $c === null ? '—' : formatoMoneda($c / 100); ?>
        <dl class="cotizador-detalle__cliente">
            <div><dt>Ventas del periodo</dt><dd><?= formatoMoneda($estado['snapshot']['contabilidad']['ventas']['total'] ?? 0) ?></dd></div>
            <div><dt>IVA estimado a cargo</dt><dd><?= $mx($f['iva']['a_cargo'] ?? null) ?></dd></div>
            <div><dt>ISR provisional estimado</dt><dd><?= $mx($f['isr']['a_pagar'] ?? null) ?></dd></div>
            <div><dt>Avisos reconocidos</dt><dd><?= count($estado['snapshot']['avisos_reconocidos'] ?? []) ?></dd></div>
        </dl>
        <div class="encabezado-pagina__acciones mt-md">
            <a class="btn btn--secundario" href="/admin/contabilidad/cierre/exportar?<?= $consulta ?>&formato=pdf" target="_blank" rel="noopener">Exportar resumen (PDF)</a>
            <a class="btn btn--secundario" href="/admin/contabilidad/cierre/exportar?<?= $consulta ?>&formato=csv">Exportar resumen (CSV)</a>
        </div>
    </section>

    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Reabrir el periodo (procedimiento controlado)</h2>
        <p class="campo__ayuda mb-md">Exige tu contraseña y un motivo; queda en el historial y en la bitácora. Si hay meses posteriores cerrados, reábrelos primero.</p>
        <form class="formulario" action="/admin/contabilidad/cierre/accion" method="post" data-validar
              data-confirmacion-sobre="Reapertura"
              data-confirmacion="¿Reabrir este mes?"
              data-confirmacion-mensaje="El mes volverá a aceptar cambios. Queda registrado quién lo reabrió y por qué."
              data-confirmacion-boton="Sí, reabrir">
            <input type="hidden" name="accion" value="reabrir"><input type="hidden" name="anio" value="<?= $periodo['anio'] ?>"><input type="hidden" name="mes" value="<?= $periodo['mes'] ?>">
            <div class="formulario__rejilla">
                <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="motivo">Motivo de la reapertura</label>
                    <input class="control" type="text" id="motivo" name="motivo" required minlength="10" maxlength="200"></div>
                <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="contrasena">Tu contraseña</label>
                    <input class="control" type="password" id="contrasena" name="contrasena" required autocomplete="current-password"></div>
            </div>
            <div class="formulario__acciones"><button class="btn btn--peligro" type="submit">Reabrir el mes</button></div>
        </form>
    </section>
<?php endif; ?>

<?php if ($historial) : ?>
    <section class="tarjeta tarjeta--sin-relleno">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Historial del periodo</h2>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Cierres y reaperturas</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Acción</th><th scope="col">Motivo / nota</th><th scope="col">Usuario</th><th scope="col">Huella del resumen</th></tr></thead>
                <tbody>
                    <?php foreach ($historial as $h) : ?>
                        <tr><td><?= date('d/m/Y H:i', strtotime($h['creado_en'])) ?></td>
                            <td><?= $h['accion'] === 'cierre' ? '<span class="badge badge--neutro">Cierre</span>' : '<span class="badge badge--pendiente">Reapertura</span>' ?></td>
                            <td><?= escaparValores((string) $h['motivo']) ?></td><td><?= escaparValores((string) $h['usuario_nombre']) ?></td>
                            <td class="texto-xs"><?= escaparValores(substr((string) $h['resumen_sha256'], 0, 16)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
