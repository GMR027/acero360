<?php
/**
 * views/admin/ajustes.php — Ajustes de Acero 360: preferencias de operación, atajos a la configuración
 * que vive en otros módulos y la sección «Acerca de».
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/ajustes', 'error', 'Este formulario ya se envió; no se guardó dos veces.');
    }
    $permitir = !empty($_POST['permitir_stock_negativo']) ? '1' : '0';
    if (\App\Configuracion::obtener('permitir_stock_negativo', '1') !== $permitir) {
        \App\Configuracion::guardar('permitir_stock_negativo', $permitir, (int) ($_SESSION['id'] ?? 0));
    }
    redirigir('/admin/ajustes', 'exito', 'Ajustes guardados.');
}

$stockNegativo = \App\Configuracion::permitirStockNegativo();

$seccion = ($_GET['seccion'] ?? '') === 'acerca' ? 'acerca' : 'general';

template('header', ['titulo' => 'Ajustes', 'subtitulo' => 'Preferencias de la plataforma', 'rutaActiva' => '/admin/ajustes']);
template('tabsAjustes', ['tab' => $seccion]);
?>

<?php if ($seccion === 'general') : ?>

<form action="/admin/ajustes" method="post" class="tarjeta mb-lg" data-validar>
    <input type="hidden" name="token" value="<?= escaparValores(tokenFormulario()) ?>">
    <h2 class="titulo-5 mb-md">Operación</h2>
    <div class="campo campo--completo">
        <label class="comprobacion" for="permitir_stock_negativo">
            <input type="checkbox" id="permitir_stock_negativo" name="permitir_stock_negativo" value="1" <?= $stockNegativo ? 'checked' : '' ?>>
            <span>
                <strong>Permitir vender sin existencias</strong><br>
                <span class="texto-xs texto-secundario">Si está activo, se puede vender un producto aunque el inventario no alcance (se fabrica y se entrega después) y el stock queda en negativo. Si lo apagas, la venta se rechaza.</span>
            </span>
        </label>
    </div>
    <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar ajustes</button></div>
</form>

<p class="campo__ayuda mb-lg">Usuarios y contraseñas: <a href="/admin/usuarios">Usuarios y perfil</a>.</p>
<?php else : ?>
<section class="tarjeta mb-lg" id="acerca" aria-labelledby="titulo-acerca">
    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap" class="mb-md">
        <img src="/public/brand/acero360-marca.svg" alt="" width="64" height="64" style="border-radius:.75rem">
        <div>
            <h2 class="titulo-4" id="titulo-acerca">Acerca de Acero 360</h2>
            <p class="texto-secundario">Plataforma comercial, contable y administrativa para cotizar, vender, entregar y controlar inventario e impuestos.</p>
        </div>
    </div>
    <dl class="cotizador-detalle__cliente">
        <div><dt>Versión</dt><dd><?= escaparValores(ACERO360_VERSION) ?></dd></div>
        <div><dt>Servidor</dt><dd><?= escaparValores($_SERVER['HTTP_HOST'] ?? '') ?></dd></div>
        <div><dt>Antes llamada</dt><dd>Cotizaciones</dd></div>
        <div><dt>Soporte</dt><dd><a href="/admin/ayuda">Ayuda y manual</a></dd></div>
    </dl>
    <p class="campo__ayuda mt-md">Los cálculos de impuestos son una estimación informativa; confírmalos con tu contador.</p>
</section>

<?php endif; ?>

<?php template('footer'); ?>
