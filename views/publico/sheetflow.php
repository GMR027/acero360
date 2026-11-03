<?php
/**
 * views/publico/sheetflow.php — Tabla compartida por enlace (solo lectura).
 *
 * Se abre con /s?t=<token>. No pide sesión; solo muestra la tabla y sus datos.
 */

$token = (string) ($_GET['t'] ?? '');
$hoja = \Model\SheetflowHoja::porToken($token);
if (!$hoja) {
    http_response_code(404);
}
$tabla = $hoja ? \Classes\Sheetflow::html($hoja, 'sf-tabla') : '';
$graficas = $hoja ? \Classes\Sheetflow::graficasHtml($hoja) : '';
$esc = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
$css = @filemtime(__DIR__ . '/../../build/css/app.css');
?><!DOCTYPE html>
<html lang="es" data-tema="claro" data-color="acero">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $hoja ? $esc($hoja->nombre) : 'Tabla no disponible' ?> · Acero 360</title>
    <link rel="icon" type="image/svg+xml" href="/public/brand/favicon.svg">
    <link rel="stylesheet" href="/build/css/app.css<?= $css !== false ? '?v=' . $css : '' ?>">
</head>
<body class="sf-publico-cuerpo">
<main class="sf-publico">
    <header class="sf-publico__cabecera">
        <div>
            <p class="texto-etiqueta">Acero 360 · Enlace de solo lectura</p>
            <h1 class="titulo-2"><?= $hoja ? $esc($hoja->nombre) : 'Tabla no disponible' ?></h1>
            <?php if ($hoja) : ?>
                <p class="texto-secundario">
                    <?= $esc(formatear_fecha($hoja->fecha, 'd/m/Y')) ?>
                    <?= $hoja->departamento !== '' ? ' · ' . $esc($hoja->departamento) : '' ?>
                    <?= $hoja->descripcion !== '' ? ' · ' . $esc($hoja->descripcion) : '' ?>
                </p>
            <?php endif; ?>
        </div>
    </header>
    <div class="card sf-publico__tabla sf-ver">
        <?php if ($hoja) : ?>
            <div class="tabla-contenedor"><?= $tabla ?></div>
            <?= $graficas ?>
        <?php else : ?>
            <p class="texto-secundario">Este enlace no existe, fue desactivado o ya no tiene una tabla asociada.</p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
