<?php
/**
 * views/admin/fiscal/catalogo/detalle.php — Una versión del catálogo: fuente, validación, pruebas y activación.
 */

soloAdmin();

$id = (int) ($_GET['id'] ?? 0);
$v  = \App\CatalogoFiscal::buscarID($id);

if (!$v) {
    paginaNoEncontrada();
}

$est = \App\CatalogoFiscal::ESTADOS[$v['estado']];
$val = $v['validacion'];
$doc = $v['doc'];
$mesVer = max(1, min(12, (int) ($_GET['mes'] ?? date('n'))));
$periodo = ['anio' => (int) $v['ejercicio'], 'mes' => (int) date('n')];

template('header', ['titulo' => (\App\CatalogoFiscal::TIPOS[$v['tipo']] ?? $v['tipo']) . ' · v' . $v['version'], 'subtitulo' => 'Ejercicio ' . $v['ejercicio'], 'rutaActiva' => '/admin/fiscal']);
template('tabsFiscal', ['tab' => 'catalogo', 'periodo' => $periodo]);
?>

<section class="tarjeta mb-lg">
    <p><span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span>
        Vigencia <?= date('d/m/Y', strtotime($v['vigencia_desde'])) ?> – <?= date('d/m/Y', strtotime($v['vigencia_hasta'])) ?></p>
    <dl class="cotizador-detalle__cliente mt-md">
        <div><dt>Documento fuente</dt><dd><?= escaparValores($v['fuente_documento']) ?></dd></div>
        <div><dt>Publicación</dt><dd><?= escaparValores((string) $v['fuente_publicacion']) ?: '—' ?></dd></div>
        <div><dt>Enlace oficial</dt><dd><a href="<?= escaparValores($v['fuente_url']) ?>" target="_blank" rel="noopener noreferrer"><?= escaparValores($v['fuente_url']) ?></a></dd></div>
        <div><dt>SHA-256 del documento oficial</dt><dd class="texto-xs"><?= escaparValores((string) $v['sha256_fuente']) ?: '—' ?></dd></div>
        <div><dt>SHA-256 de los datos importados</dt><dd class="texto-xs"><?= escaparValores($v['sha256_datos']) ?></dd></div>
        <?php if ($v['activada_en']) : ?><div><dt>Activada</dt><dd><?= date('d/m/Y H:i', strtotime($v['activada_en'])) ?> · <?= escaparValores((string) $v['motivo_activacion']) ?></dd></div><?php endif; ?>
    </dl>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Validación y pruebas de regresión</h2>
    <?php if (!$val) : ?>
        <p class="texto-secundario">Esta versión aún no se ha validado.</p>
    <?php else : ?>
        <p><span class="badge <?= $val['ok'] ? 'badge--aprobado' : 'badge--error' ?>"><?= $val['ok'] ? 'Sin errores' : 'Con errores' ?></span>
            Pruebas: <?= (int) ($val['pruebas']['total'] ?? 0) ?> ejecutadas, <?= (int) ($val['pruebas']['falladas'] ?? 0) ?> falladas.
            <?php if (!empty($val['comparacion'])) : ?>Comparación contra v<?= (int) $val['comparacion']['contra_version'] ?>: <?= (int) $val['comparacion']['diferencias'] ?> de <?= (int) $val['comparacion']['revisados'] ?> resultados cambian.<?php endif; ?></p>
        <?php foreach ($val['errores'] ?? [] as $e) : ?><p class="nota nota--error mt-md"><span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span><span><?= escaparValores($e) ?></span></p><?php endforeach; ?>
        <?php foreach ($val['avisos'] ?? [] as $a) : ?><p class="nota nota--advertencia mt-md"><span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span><span><?= escaparValores($a) ?></span></p><?php endforeach; ?>
    <?php endif; ?>

    <?php if (in_array($v['estado'], ['borrador', 'validada'], true)) : ?>
        <form action="/admin/fiscal/catalogo/accion" method="post" class="mt-md">
            <input type="hidden" name="accion" value="validar"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn--secundario" type="submit"><?= $val ? 'Volver a validar' : 'Validar versión' ?></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($v['estado'] === 'validada') : ?>
    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Activar esta versión</h2>
        <p class="campo__ayuda mb-md">Al activarla, la versión activa actual pasa a «reemplazada» (se conserva intacta) y los cálculos nuevos usarán esta. Los cálculos guardados no cambian. Requiere tu contraseña.</p>
        <form class="formulario" action="/admin/fiscal/catalogo/accion" method="post" data-validar
              data-confirmacion-sobre="Catálogo fiscal"
              data-confirmacion="¿Activar esta versión?"
              data-confirmacion-mensaje="Los cálculos nuevos usarán estas reglas desde ahora. Queda registrado quién y por qué."
              data-confirmacion-boton="Sí, activar">
            <input type="hidden" name="accion" value="activar"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="formulario__rejilla">
                <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="motivo">Motivo de la activación</label>
                    <input class="control" type="text" id="motivo" name="motivo" required minlength="10" maxlength="200" placeholder="Ej. Tarifa oficial 2026 publicada en el DOF"></div>
                <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="contrasena">Tu contraseña</label>
                    <input class="control" type="password" id="contrasena" name="contrasena" required autocomplete="current-password"></div>
            </div>
            <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Activar versión</button></div>
        </form>
    </section>
<?php endif; ?>

<?php if ($v['tipo'] === 'isr_pagos_provisionales_art106' && isset($doc['tarifas'][(string) $mesVer])) : ?>
    <section class="tarjeta tarjeta--sin-relleno">
        <div class="tabla-toolbar">
            <h2 class="titulo-5">Tarifa del mes de <?= nombreMes($mesVer) ?></h2>
            <form action="/admin/fiscal/catalogo/detalle" method="get">
                <input type="hidden" name="id" value="<?= $id ?>">
                <label class="solo-lectores" for="mes">Mes</label>
                <select class="control control--compacto" id="mes" name="mes" onchange="this.form.requestSubmit()">
                    <?php for ($i = 1; $i <= 12; $i++) : ?><option value="<?= $i ?>"<?= $i === $mesVer ? ' selected' : '' ?>><?= nombreMes($i) ?></option><?php endfor; ?>
                </select>
            </form>
        </div>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Tarifa mensual</caption>
                <thead><tr><th scope="col" class="tabla--numerica">Límite inferior</th><th scope="col" class="tabla--numerica">Límite superior</th>
                    <th scope="col" class="tabla--numerica">Cuota fija</th><th scope="col" class="tabla--numerica">% sobre el excedente</th></tr></thead>
                <tbody>
                    <?php foreach ($doc['tarifas'][(string) $mesVer] as $f) : ?>
                        <tr><td class="tabla--numerica cifra"><?= formatoMoneda($f['limite_inferior']) ?></td>
                            <td class="tabla--numerica cifra"><?= $f['limite_superior'] === null ? 'En adelante' : formatoMoneda($f['limite_superior']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['cuota_fija']) ?></td>
                            <td class="tabla--numerica cifra"><?= escaparValores($f['porcentaje']) ?> %</td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
