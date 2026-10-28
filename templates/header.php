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

$temaActual = temaPreferido();

// Versión del CSS compilado (?v=archivo): una compilación nueva cambia la URL,
// para que una caché (CDN o navegador) no sirva nunca el build anterior.
$versionCss = @filemtime(__DIR__ . '/../build/css/app.css');
?>
<!DOCTYPE html>
<html lang="es" data-tema="<?= escaparValores($temaActual) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf" content="<?= tokenCsrf() ?>">
    <?php if ($mostrarSidebar) : ?>
    <script>/* Tema: si no hay cookie (otro navegador), se crea con la preferencia del servidor. Solo con sesión iniciada: en el login aún no se sabe cuál es. */
        (function(){try{if(document.cookie.indexOf("a360_tema=")<0){document.cookie="a360_tema="+document.documentElement.getAttribute("data-tema")+";path=/;max-age=31536000;SameSite=Lax";}}catch(e){}})();</script>
    <?php endif; ?>
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
    <!-- Material Icons (el set CLÁSICO, estilo relleno): sólo lo usan los marcadores
         del Calendario. Se carga aparte porque es una familia distinta a Symbols. -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/build/css/app.css<?= $versionCss !== false ? '?v=' . $versionCss : '' ?>">
    <?php foreach (($GLOBALS['estilosPagina'] ?? []) as $estiloPagina) :
        // Igual que scriptsPagina: los ficheros locales se auto-versionan con filemtime.
        if (strpos($estiloPagina, '?') === false && str_starts_with($estiloPagina, '/')) {
            $ficheroEstilo = __DIR__ . '/..' . $estiloPagina;
            if (is_file($ficheroEstilo)) {
                $estiloPagina .= '?v=' . filemtime($ficheroEstilo);
            }
        }
    ?>
    <link rel="stylesheet" href="<?= escaparValores($estiloPagina) ?>">
    <?php endforeach; ?>
</head>
<body data-movil="<?= modoMovil() ?>">
<!-- Primera parada del teclado: permite saltarse el menú lateral.
     Sólo se ve cuando recibe foco (ver .saltar-contenido en _utilidades). -->
<a class="saltar-contenido" href="#contenido-principal">Saltar al contenido</a>
<?php if ($mostrarSidebar) : ?>
<div class="app">
    <?php template('sidebar'); ?>

    <div class="app__panel">
        <header class="topbar">
            <div class="topbar__titulo">
                <span class="texto-etiqueta movil-oculto">Panel de administración</span>
                <strong class="titulo-5 texto-una-linea"><?= escaparValores($titulo) ?></strong>
            </div>

            <div class="topbar__acciones">
                <?php $noLeidas = \App\Seguimiento::contarNoLeidas((int) ($_SESSION['id'] ?? 0)); ?>
                <div class="campana-caja" data-campana>
                    <button class="btn btn--secundario btn--sm campana" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="campana-panel"
                            aria-label="Avisos<?= $noLeidas ? ': ' . $noLeidas . ' sin leer' : '' ?>" title="Avisos" data-campana-boton>
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">notifications</span>
                        <span class="campana__n" aria-hidden="true" data-campana-n<?= $noLeidas > 0 ? '' : ' hidden' ?>><?= $noLeidas > 99 ? '99+' : $noLeidas ?></span>
                    </button>
                    <div class="campana-panel" id="campana-panel" role="region" aria-label="Avisos recientes" hidden data-campana-panel>
                        <div class="campana-panel__cabecera">
                            <strong>Avisos recientes</strong>
                            <button class="btn btn--texto btn--sm" type="button" data-campana-todas>Marcar todo como leído</button>
                        </div>
                        <ul class="campana-panel__lista" data-campana-lista aria-live="polite"><li class="texto-secundario">Cargando…</li></ul>
                        <a class="campana-panel__pie" href="/admin/seguimiento">Ver el Centro de seguimiento</a>
                    </div>
                </div>
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
        <script>
        /* Campana: contador sin leer (se refresca cada 60 s), panel con los avisos recientes, marcar leído y abrir el registro. */
        (function () {
          const caja = document.querySelector('[data-campana]');
          if (!caja) { return; }
          const boton = caja.querySelector('[data-campana-boton]');
          const panel = caja.querySelector('[data-campana-panel]');
          const lista = caja.querySelector('[data-campana-lista]');
          const badge = caja.querySelector('[data-campana-n]');
          const url = '/admin/seguimiento/campana';
          const texto = function (t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; };

          function pintar(datos) {
            badge.hidden = datos.n <= 0;
            badge.textContent = datos.n > 99 ? '99+' : datos.n;
            boton.setAttribute('aria-label', 'Avisos' + (datos.n ? ': ' + datos.n + ' sin leer' : ''));
            if (!datos.avisos.length) { lista.innerHTML = '<li class="texto-secundario">No hay avisos. Todo al día.</li>'; return; }
            lista.innerHTML = datos.avisos.map(function (a) {
              return '<li class="campana-aviso' + (a.leida ? ' campana-aviso--leida' : '') + '" data-prioridad="' + texto(a.prioridad) + '">'
                + '<span class="badge badge--neutro"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:.9rem;vertical-align:middle">' + texto(a.icono) + '</span> ' + texto(a.nivel) + '</span>'
                + (a.leida ? '' : ' <span class="badge badge--info">Nuevo</span>')
                + '<strong>' + texto(a.titulo) + '</strong><span class="texto-secundario">' + texto(a.detalle) + '</span>'
                + '<span class="campana-aviso__acciones"><a class="btn btn--primario btn--sm" href="' + texto(a.enlace) + '" data-abrir="' + texto(a.clave) + '">' + texto(a.abrir) + '</a>'
                + (a.leida ? '' : '<button class="btn btn--texto btn--sm" type="button" data-leer="' + texto(a.clave) + '">Marcar como leído</button>') + '</span></li>';
            }).join('');
          }
          function pedir(cuerpo) {
            const opciones = cuerpo ? { method: 'POST', headers: { 'X-Requested-With': 'campana', 'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '', 'Content-Type': 'application/x-www-form-urlencoded' }, body: cuerpo, credentials: 'same-origin' } : { credentials: 'same-origin' };
            return fetch(url, opciones).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (d) { pintar(d); } return d; }).catch(function () { return null; });
          }
          boton.addEventListener('click', function () {
            const abrir = panel.hidden;
            panel.hidden = !abrir;
            boton.setAttribute('aria-expanded', String(abrir));
            if (abrir) { pedir(); }
          });
          document.addEventListener('click', function (e) { if (!panel.hidden && !caja.contains(e.target)) { panel.hidden = true; boton.setAttribute('aria-expanded', 'false'); } });
          document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { panel.hidden = true; boton.setAttribute('aria-expanded', 'false'); boton.focus(); } });
          caja.querySelector('[data-campana-todas]').addEventListener('click', function () { pedir('todas=1'); });
          lista.addEventListener('click', function (e) {
            const leer = e.target.closest('[data-leer]');
            if (leer) { pedir('clave=' + encodeURIComponent(leer.dataset.leer)); return; }
            const abrir = e.target.closest('[data-abrir]');
            if (abrir) { /* abrir el registro también lo marca como leído */
              fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'campana', 'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '', 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'clave=' + encodeURIComponent(abrir.dataset.abrir), credentials: 'same-origin', keepalive: true });
            }
          });
          setInterval(function () { if (!document.hidden && panel.hidden) { fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (d) { badge.hidden = d.n <= 0; badge.textContent = d.n > 99 ? '99+' : d.n; } }).catch(function () {}); } }, 60000);
        })();
        </script>

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
