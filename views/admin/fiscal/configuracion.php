<?php
/**
 * views/admin/fiscal/configuracion.php — Configuración fiscal del contribuyente, con historial.
 * El cambio es una operación administrativa controlada: contraseña, motivo y vigencia desde una fecha.
 */

soloAdmin();

$contrib = \App\Contabilidad::contribuyentePrincipal();
$c       = \App\Contabilidad::contribuyente($contrib);
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'activar_catalogo') {
    // Activar una versión validada: exige contraseña de autorización y motivo (quién y por qué).
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/fiscal/configuracion', 'error', 'Este formulario ya se envió; no se activó dos veces.');
    }
    if (\App\CatalogoFiscal::activar(
        (int) ($_POST['catalogo'] ?? 0), (int) ($_SESSION['id'] ?? 0),
        (string) ($_POST['contrasena'] ?? ''), (string) ($_POST['motivo'] ?? '')
    )) {
        redirigir('/admin/fiscal/configuracion', 'exito', 'Catálogo activado: los cálculos usarán esta versión a partir de ahora.');
    }
    redirigir('/admin/fiscal/configuracion', 'error',
        implode(' · ', \App\CatalogoFiscal::getErrores()) ?: 'No fue posible activar el catálogo.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    if (\App\RegistrosFiscales::cambiarConfiguracion(
        $contrib, (string) ($_POST['vigente_desde'] ?? ''), (string) ($_POST['tipo_persona'] ?? ''), (string) ($_POST['regimen'] ?? ''),
        !empty($_POST['confirmada']), (string) ($_POST['motivo'] ?? ''), (int) ($_SESSION['id'] ?? 0), (string) ($_POST['contrasena'] ?? '')
    )) {
        redirigir('/admin/fiscal/configuracion', 'exito', 'Configuración fiscal actualizada; el historial anterior se conservó.');
    }
    $errores = \App\RegistrosFiscales::getErrores();
}

$vigente   = \App\RegistrosFiscales::configuracion($contrib);
$historial = \App\RegistrosFiscales::historialConfiguracion($contrib);
$empresas  = \App\Empresas::mostrarTodos(100, 0);
$catalogos = \App\CatalogoFiscal::listar();

template('header', ['titulo' => 'Configuración fiscal', 'subtitulo' => 'Fiscal', 'rutaActiva' => '/admin/ajustes']);
template('tabsAjustes', ['tab' => 'fiscal']);
?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Contribuyente</h2>
    <dl class="cotizador-detalle__cliente">
        <div><dt>RFC</dt><dd><?= escaparValores($c['rfc'] ?? '') ?></dd></div>
        <div><dt>País</dt><dd><?= escaparValores($vigente['pais'] ?? 'México') ?></dd></div>
        <div><dt>Tipo de persona</dt><dd><?= ($vigente['tipo_persona'] ?? '') === 'moral' ? 'Moral' : 'Física' ?></dd></div>
        <div><dt>Régimen fiscal</dt><dd><?= escaparValores($vigente['regimen'] ?? '') ?></dd></div>
        <div><dt>Base de ingresos</dt><dd>Flujo de efectivo (ISR e IVA al cobrar; deducciones y acreditamiento al pagar)</dd></div>
        <div><dt>Confirmada con el contador</dt><dd><?= !empty($vigente['confirmada_con_contador']) ? 'Sí' : '<strong>No</strong>' ?></dd></div>
    </dl>
    <p class="campo__ayuda mt-md">Negocios comerciales que operan bajo este contribuyente:
        <?= escaparValores(implode(', ', array_map(static fn ($e) => $e->nombre, $empresas))) ?>. La contabilidad se consolida por contribuyente y se conserva el negocio de origen de cada operación.</p>
    <?php if (empty($vigente['confirmada_con_contador'])) : ?>
        <p class="nota nota--advertencia mt-md"><span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span>
            <span>El régimen y el método de flujo de efectivo aún no están confirmados con tu contador. Los cálculos mostrarán un aviso hasta que lo confirmes.</span></p>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Catálogos fiscales (versionados)</h2>
    <p class="campo__ayuda mb-md">El motor fiscal sólo usa catálogos con <strong>fuente oficial</strong> y en estado
        <strong>«activa»</strong>: sin una versión activa, los cálculos quedan <strong>deshabilitados</strong> y no se
        inventa ninguna tasa. Activar una versión validada conserva la anterior como «reemplazada» (nunca se edita la
        activa) y queda en el historial con motivo y autorización.
        <br><span class="texto-secundario">Nota de transición: la tasa plana de ISR que se capturaba aquí ya no dirige
            ningún cálculo; con catálogo activo el ISR usa la tarifa progresiva del art. 106.</span></p>

    <div class="tabla-contenedor"><table class="tabla">
        <caption class="solo-lectores">Catálogos fiscales importados</caption>
        <thead><tr><th scope="col">Catálogo</th><th scope="col">Ejercicio</th><th scope="col">Versión</th>
            <th scope="col" class="tabla--centro">Estado</th><th scope="col">Vigencia</th><th scope="col">Fuente</th>
            <th scope="col">Activación</th></tr></thead>
        <tbody>
        <?php
        $etiquetasTipo = [
            'isr_pagos_provisionales_art106' => 'Tarifa ISR (art. 106 LISR)',
            'iva_tasas'                      => 'Tasas de IVA',
            'reglas_plataforma'              => 'Criterios de la plataforma',
        ];
        $clasesEstado = ['activa' => 'badge--aprobado', 'validada' => 'badge--pendiente', 'borrador' => 'badge--neutro', 'reemplazada' => 'badge--neutro'];
        foreach ($catalogos as $cat) :
            $esValidada = (string) $cat['estado'] === 'validada'; ?>
            <tr<?= $cat['estado'] === 'reemplazada' ? ' style="opacity:.6"' : '' ?>>
                <td><?= escaparValores($etiquetasTipo[$cat['tipo']] ?? $cat['tipo']) ?></td>
                <td><?= (int) $cat['ejercicio'] ?></td>
                <td>v<?= (int) $cat['version'] ?></td>
                <td class="tabla--centro"><span class="badge <?= $clasesEstado[$cat['estado']] ?? 'badge--neutro' ?>">
                    <?= escaparValores($cat['estado']) ?></span></td>
                <td class="texto-sm"><?= date('d/m/Y', strtotime((string) $cat['vigencia_desde'])) ?> – <?= date('d/m/Y', strtotime((string) $cat['vigencia_hasta'])) ?></td>
                <td class="texto-sm"><?php if (!empty($cat['fuente_url'])) : ?><a href="<?= escaparValores($cat['fuente_url']) ?>" target="_blank" rel="noopener">fuente oficial</a><?php else : ?>—<?php endif; ?></td>
                <td>
                    <?php if ($esValidada) : ?>
                        <form action="/admin/fiscal/configuracion" method="post" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">
                            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                            <input type="hidden" name="accion" value="activar_catalogo">
                            <input type="hidden" name="catalogo" value="<?= (int) $cat['id'] ?>">
                            <label class="solo-lectores" for="cat-pw-<?= (int) $cat['id'] ?>">Contraseña</label>
                            <input class="control control--compacto" type="password" id="cat-pw-<?= (int) $cat['id'] ?>"
                                   name="contrasena" placeholder="Contraseña" autocomplete="current-password" required>
                            <label class="solo-lectores" for="cat-mot-<?= (int) $cat['id'] ?>">Motivo</label>
                            <input class="control control--compacto" type="text" id="cat-mot-<?= (int) $cat['id'] ?>"
                                   name="motivo" placeholder="Motivo (10+)" maxlength="255" required minlength="10">
                            <button class="btn btn--secundario btn--sm" type="submit">Activar</button>
                        </form>
                    <?php else : ?>
                        <span class="texto-xs texto-secundario"><?= $cat['estado'] === 'activa' ? 'en uso' : '—' ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (!array_filter($catalogos, static fn ($x) => $x['estado'] === 'activa')) : ?>
        <p class="nota nota--advertencia mt-md" role="alert"><span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span>
            <span><strong>No hay ningún catálogo activo</strong>: los cálculos de impuestos quedan deshabilitados hasta que actives una versión validada.</span></p>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Cambiar la configuración (operación controlada)</h2>
    <p class="campo__ayuda mb-md">El cambio rige desde la fecha que indiques, conserva el historial y no puede aplicarse a un periodo cerrado.</p>
    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert"><span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span></div>
    <?php endif; ?>
    <form class="formulario" action="/admin/fiscal/configuracion" method="post" novalidate>
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="vigente_desde">Vigente desde</label>
                <input class="control" type="date" id="vigente_desde" name="vigente_desde" required value="<?= escaparValores($_POST['vigente_desde'] ?? date('Y-m-d')) ?>"></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="tipo_persona">Tipo de persona</label>
                <select class="control" id="tipo_persona" name="tipo_persona"><option value="fisica"<?= ($vigente['tipo_persona'] ?? '') === 'fisica' ? ' selected' : '' ?>>Física</option><option value="moral"<?= ($vigente['tipo_persona'] ?? '') === 'moral' ? ' selected' : '' ?>>Moral</option></select></div>
            <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="regimen">Régimen fiscal</label>
                <input class="control" type="text" id="regimen" name="regimen" maxlength="120" required value="<?= escaparValores($_POST['regimen'] ?? ($vigente['regimen'] ?? '')) ?>"></div>
            <div class="campo campo--completo"><label class="comprobacion" for="confirmada"><input type="checkbox" id="confirmada" name="confirmada" value="1" <?= !empty($_POST['confirmada']) ? 'checked' : '' ?>>
                <span><strong>Confirmado con mi contador</strong><br><span class="texto-xs texto-secundario">Régimen y base de flujo de efectivo.</span></span></label></div>
            <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="motivo">Motivo del cambio</label>
                <input class="control" type="text" id="motivo" name="motivo" required minlength="10" maxlength="200" value="<?= escaparValores($_POST['motivo'] ?? '') ?>"></div>
            <div class="campo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="contrasena">Tu contraseña</label>
                <div class="campo__caja"><input class="control" type="password" id="contrasena" name="contrasena" required autocomplete="current-password" data-ver-contrasena></div></div>
        </div>
        <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar configuración</button></div>
    </form>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Historial de configuraciones</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Historial</caption>
            <thead><tr><th scope="col">Desde</th><th scope="col">Hasta</th><th scope="col">Persona</th><th scope="col">Régimen</th><th scope="col">Contador</th><th scope="col">Motivo</th><th scope="col">Registró</th></tr></thead>
            <tbody>
                <?php foreach ($historial as $h) : ?>
                    <tr><td><?= date('d/m/Y', strtotime($h['vigente_desde'])) ?></td><td><?= $h['vigente_hasta'] ? date('d/m/Y', strtotime($h['vigente_hasta'])) : 'Vigente' ?></td>
                        <td><?= $h['tipo_persona'] === 'moral' ? 'Moral' : 'Física' ?></td><td><?= escaparValores($h['regimen']) ?></td>
                        <td><?= (int) $h['confirmada_con_contador'] === 1 ? 'Confirmada' : 'Pendiente' ?></td>
                        <td><?= escaparValores((string) $h['motivo_cambio']) ?></td><td><?= escaparValores((string) $h['usuario_nombre']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php template('footer'); ?>
