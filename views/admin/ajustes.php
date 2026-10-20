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
$tasaIsr       = \App\Configuracion::obtener('isr_tasa_simple', '');
$negocios      = array_filter(\App\Empresas::mostrarTodos(100, 0), static fn ($e) => (int) $e->activa === 1);

$atajos = [
    ['/admin/empresas', 'corporate_fare', 'Empresas y razón social', 'Datos, logotipo y condiciones de cada empresa que emite cotizaciones (' . count($negocios) . ' activos).'],
    ['/admin/usuarios', 'manage_accounts', 'Usuarios y perfil', 'Quién entra a la plataforma y su contraseña.'],
    ['/admin/fiscal/configuracion', 'tune', 'Datos fiscales', 'RFC, régimen y porcentaje de ISR' . ($tasaIsr !== '' ? ' (hoy ' . rtrim(rtrim($tasaIsr, '0'), '.') . ' %)' : '') . ' para el resumen de impuestos.'],
    ['/admin/escritorio', 'desktop_windows', 'Aplicación de escritorio', 'Descarga e instrucciones de instalación.'],
];

template('header', ['titulo' => 'Ajustes', 'subtitulo' => 'Preferencias de la plataforma', 'rutaActiva' => '/admin/ajustes']);
?>

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

<section class="mb-lg" aria-labelledby="titulo-atajos">
    <h2 class="titulo-4 mb-md" id="titulo-atajos">Otras configuraciones</h2>
    <div class="rejilla-2">
        <?php foreach ($atajos as [$ruta, $icono, $titulo, $texto]) : ?>
            <a class="tarjeta tarjeta--enlace" href="<?= escaparValores($ruta) ?>">
                <div class="tarjeta__cabecera">
                    <h3 class="tarjeta__titulo"><span class="material-symbols-outlined" aria-hidden="true"><?= escaparValores($icono) ?></span> <?= escaparValores($titulo) ?></h3>
                    <span class="material-symbols-outlined texto-secundario" aria-hidden="true">arrow_forward</span>
                </div>
                <p class="tarjeta__descripcion"><?= escaparValores($texto) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

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

<?php template('footer'); ?>
