<?php
/**
 * views/admin/fiscal/catalogo/index.php — Catálogo fiscal versionado: versiones, importación controlada.
 * La importación crea SIEMPRE una versión nueva en borrador; activar exige validar y autorizar.
 */

soloAdmin();

$versiones = \App\CatalogoFiscal::listar();
$activas = array_filter($versiones, static fn ($v) => $v['estado'] === 'activa');
$anio = (int) date('Y');
$periodo = ['anio' => $anio, 'mes' => (int) date('n')];

template('header', ['titulo' => 'Catálogo de reglas fiscales', 'subtitulo' => 'Fiscal', 'rutaActiva' => '/admin/fiscal']);
template('tabsFiscal', ['tab' => 'catalogo', 'periodo' => $periodo]);
?>

<p class="nota nota--advertencia mb-lg" role="note">
    <span class="material-symbols-outlined nota__icono" aria-hidden="true">gavel</span>
    <span>Cada regla cita su <strong>fuente oficial</strong> y vigencia. Una versión nueva nunca pisa a la anterior: se importa como borrador, se valida
        (formato, datos y pruebas de regresión) y solo se activa con tu contraseña. Los cálculos guardados conservan la versión que usaron.
        La plataforma no descarga reglas por sí sola.</span>
</p>

<?php if (!$activas) : ?>
    <p class="nota nota--advertencia mb-lg" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span>
        <span>No hay reglas activas: el motor fiscal queda deshabilitado hasta importar, validar y activar los catálogos.</span></p>
<?php endif; ?>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Versiones del catálogo fiscal</caption>
            <thead><tr><th scope="col">Ejercicio</th><th scope="col">Regla</th><th scope="col">Versión</th><th scope="col">Vigencia</th>
                <th scope="col" class="tabla--centro">Estado</th><th scope="col">Importada</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
                <?php foreach ($versiones as $v) : $est = \App\CatalogoFiscal::ESTADOS[$v['estado']]; ?>
                    <tr><td><?= (int) $v['ejercicio'] ?></td><td><?= escaparValores(\App\CatalogoFiscal::TIPOS[$v['tipo']] ?? $v['tipo']) ?></td>
                        <td>v<?= (int) $v['version'] ?></td>
                        <td class="texto-sm"><?= date('d/m/Y', strtotime($v['vigencia_desde'])) ?> – <?= date('d/m/Y', strtotime($v['vigencia_hasta'])) ?></td>
                        <td class="tabla--centro"><span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span></td>
                        <td class="texto-sm"><?= date('d/m/Y H:i', strtotime($v['creado_en'])) ?><br><?= escaparValores((string) $v['creado_por_nombre']) ?></td>
                        <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/fiscal/catalogo/detalle?id=<?= (int) $v['id'] ?>">Abrir</a></td></tr>
                <?php endforeach; ?>
                <?php if (!$versiones) : ?><tr><td colspan="7" class="tabla--centro texto-secundario">Aún no hay versiones importadas.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="tarjeta">
    <h2 class="titulo-5 mb-md">Importar una versión</h2>
    <p class="campo__ayuda mb-md">Archivo JSON estructurado (tarifas del Anexo 8 de la RMF, tasa de IVA o criterios de la plataforma) con su fuente y vigencia.
        Los archivos oficiales del ejercicio 2026 vienen en <code>mysql/catalogo_fiscal/</code>. Máximo 2 MB.</p>
    <form class="formulario" action="/admin/fiscal/catalogo/accion" method="post" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="importar"><input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="archivo">Archivo .json</label>
            <input class="control" type="file" id="archivo" name="archivo" accept=".json,application/json" required></div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Importar como borrador</button></div>
    </form>
</section>

<?php template('footer'); ?>
