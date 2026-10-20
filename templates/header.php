<?php
/**
 * templates/header.php — Apertura del documento y estructura general.
 *
 * Genera el <head>, abre el contenedor de la aplicación, incluye el
 * sidebar y la barra superior, y deja abierto el <main> donde la vista
 * colocará su contenido. El cierre lo hace templates/footer.php.
 *
 * Variables opcionales:
 *   $titulo        — título de la pestaña, de la barra superior y <h1> de la página.
 *   $subtitulo     — texto secundario del encabezado.
 *   $mostrarSidebar— false en las pantallas de acceso (login/registro).
 *   $acciones      — HTML de botones en la barra superior derecha (opcional).
 *   $mostrarEncabezado — false cuando la vista lleva SU propio encabezado
 *                    (fichas de detalle, que suelen añadir avatar o insignias).
 *                    Así el título no aparece dos veces seguidas y la página
 *                    conserva exactamente un <h1>.
 *
 * Nota: la sección activa del menú se calcula desde la URL dentro de
 * templates/sidebar.php y templates/bottomnav.php, por lo que las vistas
 * no necesitan indicarla.
 */

$titulo            = $titulo            ?? 'Acero 360';
$subtitulo         = $subtitulo         ?? '';
$mostrarSidebar    = $mostrarSidebar    ?? true;
$acciones          = $acciones          ?? '';
$mostrarEncabezado = $mostrarEncabezado ?? true;

// Se comparte con footer.php para que sepa qué etiquetas debe cerrar.
$GLOBALS['mostrarSidebar'] = $mostrarSidebar;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Acero 360: plataforma comercial, contable y administrativa para cotizar, vender, entregar y controlar inventario e impuestos.">
    <title><?= escaparValores($titulo) ?> · Acero 360</title>
    <meta name="theme-color" content="#38648c">
    <link rel="icon" type="image/svg+xml" href="/public/brand/favicon.svg">
    <link rel="apple-touch-icon" href="/public/brand/apple-touch-icon.png">
    <link rel="manifest" href="/public/brand/acero360.webmanifest">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/build/css/app.css">
</head>
<body>
<!-- Primera parada del teclado: permite saltarse el menú lateral.
     Sólo se ve cuando recibe foco (ver .saltar-contenido en _utilidades). -->
<a class="saltar-contenido" href="#contenido-principal">Saltar al contenido</a>
<?php if ($mostrarSidebar) : ?>
<div class="app">
    <?php template('sidebar'); ?>

    <div class="app__panel">
        <header class="topbar">
            <div class="topbar__titulo">
                <span class="texto-etiqueta">Panel de administración</span>
                <strong class="titulo-5 texto-una-linea"><?= escaparValores($titulo) ?></strong>
            </div>

            <div class="topbar__acciones">
                <a class="btn btn--secundario btn--sm" href="/admin/perfil">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">account_circle</span>
                    <span class="oculto-en-movil">Mi perfil</span>
                </a>
                <a class="btn btn--secundario btn--sm" href="/logout">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">logout</span>
                    <span class="oculto-en-movil">Salir</span>
                </a>
            </div>
        </header>

        <main class="principal" id="contenido-principal">
            <?php if ($mostrarEncabezado && $titulo !== '') : ?>
                <div class="encabezado-pagina">
                    <div class="encabezado-pagina__texto">
                        <?php if ($subtitulo !== '') : ?>
                            <span class="texto-etiqueta"><?= escaparValores($subtitulo) ?></span>
                        <?php endif; ?>
                        <h1 class="titulo-2"><?= escaparValores($titulo) ?></h1>
                    </div>
                    <?php if (!empty($acciones)) : ?>
                        <div class="encabezado-pagina__acciones">
                            <?= $acciones /* Contenido generado por la vista. */ ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
<?php else : ?>
<main class="auth" id="contenido-principal">
<?php endif; ?>
