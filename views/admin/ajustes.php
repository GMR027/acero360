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
$temaActual = temaPreferido();

$seccion = in_array($_GET['seccion'] ?? '', ['version', 'novedades'], true) ? $_GET['seccion'] : 'general';

template('header', ['titulo' => 'Ajustes', 'subtitulo' => 'Preferencias de la plataforma', 'rutaActiva' => '/admin/ajustes']);
template('tabsAjustes', ['tab' => $seccion]);
?>

<?php if ($seccion === 'general') : ?>

<div class="ajustes-rejilla">
<section class="tarjeta" aria-labelledby="titulo-apariencia">
    <div class="ajustes-cabecera">
        <span class="ajustes-cabecera__icono"><span class="material-symbols-outlined" aria-hidden="true">palette</span></span>
        <div>
            <h2 class="titulo-5" id="titulo-apariencia">Apariencia</h2>
            <p class="texto-xs texto-secundario">Cómo se ve la aplicación en este equipo.</p>
        </div>
    </div>
    <form data-selector-tema action="/admin/ajustes/tema" method="post">
        <input type="hidden" name="token" value="<?= escaparValores(tokenFormulario()) ?>">
        <fieldset class="selector-tema">
            <legend class="campo__etiqueta">Tema visual</legend>
            <?php foreach ([
                'claro'  => ['light_mode', 'Claro', 'Fondos claros, el aspecto de siempre.'],
                'oscuro' => ['dark_mode', 'Oscuro', 'Fondos oscuros que cansan menos la vista con poca luz.'],
                'auto'   => ['brightness_auto', 'Automático', 'Sigue el modo claro u oscuro de tu sistema operativo.'],
            ] as $clave => [$icono, $nombre, $descripcion]) : ?>
                <label class="selector-tema__opcion">
                    <input type="radio" name="tema" value="<?= $clave ?>" <?= $temaActual === $clave ? 'checked' : '' ?>>
                    <span class="selector-tema__tarjeta">
                        <span class="material-symbols-outlined" aria-hidden="true"><?= $icono ?></span>
                        <strong><?= $nombre ?></strong>
                        <span class="texto-xs texto-secundario"><?= $descripcion ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <p class="campo__ayuda mt-md" role="status" data-estado-tema>El cambio se aplica al instante en toda la aplicación y se recuerda la próxima vez que entres. Los PDF y documentos para imprimir siempre salen en claro.</p>
    </form>
</section>

<form action="/admin/ajustes" method="post" class="tarjeta" data-validar>
    <input type="hidden" name="token" value="<?= escaparValores(tokenFormulario()) ?>">
    <div class="ajustes-cabecera">
        <span class="ajustes-cabecera__icono"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span></span>
        <div>
            <h2 class="titulo-5">Operación</h2>
            <p class="texto-xs texto-secundario">Reglas de venta e inventario.</p>
        </div>
    </div>
    <label class="interruptor" for="permitir_stock_negativo">
        <span>
            <strong>Permitir vender sin existencias</strong><br>
            <span class="texto-xs texto-secundario">Si está activo, se puede vender un producto aunque el inventario no alcance (se fabrica y se entrega después) y el stock queda en negativo. Si lo apagas, la venta se rechaza.</span>
        </span>
        <input type="checkbox" id="permitir_stock_negativo" name="permitir_stock_negativo" value="1" <?= $stockNegativo ? 'checked' : '' ?>>
        <span class="interruptor__pista" aria-hidden="true"></span>
    </label>
    <div class="formulario__acciones"><button class="btn btn--primario" type="submit">Guardar ajustes</button></div>
</form>
</div>

<p class="campo__ayuda mb-lg">Usuarios, perfil, empresas, datos fiscales y la aplicación de escritorio están en las demás pestañas.</p>
<?php elseif ($seccion === 'novedades') : ?>
<section class="mb-lg" aria-labelledby="titulo-novedades">
    <h2 class="titulo-4 mb-md" id="titulo-novedades">Novedades de la plataforma</h2>
    <?php foreach (\App\Seguimiento::NOVEDADES as [$version, $fecha, $titulo, $texto, $enlace]) : ?>
        <article class="tarjeta mb-md">
            <div class="flex-entre fila--envuelto">
                <h3 class="titulo-5"><?= escaparValores($titulo) ?></h3>
                <span class="badge badge--info">v<?= escaparValores($version) ?> · <?= escaparValores($fecha) ?></span>
            </div>
            <p class="texto-secundario mt-sm"><?= escaparValores($texto) ?></p>
            <div class="formulario__acciones"><a class="btn btn--secundario btn--sm" href="<?= escaparValores($enlace) ?>">Ver</a></div>
        </article>
    <?php endforeach; ?>
</section>
<?php else :
    $v = \App\InfoVersion::informe(!empty($_GET['verificar']));
    $c = $v['compilacion'];
    $u = $v['ultima'];
    $infra = $v['infra'];
    $fechaLarga = static fn (?string $iso): string => $iso && ($t = strtotime($iso)) ? date('d/m/Y H:i', $t) . ' (hora del servidor)' : 'No disponible';
    $claseEstado = ['al_dia' => 'badge--aprobado', 'disponible' => 'badge--pendiente', 'desarrollo' => 'badge--info', 'sin_verificar' => 'badge--neutro'][$v['estado']['clave']] ?? 'badge--neutro';
    $nombreEstado = ['al_dia' => 'Al día', 'disponible' => 'Actualización disponible', 'desarrollo' => 'Copia de desarrollo', 'sin_verificar' => 'Sin verificar'][$v['estado']['clave']] ?? '';
?>
<section class="tarjeta mb-lg" id="acerca" aria-labelledby="titulo-acerca">
    <div class="ajustes-marca">
        <img src="/public/brand/acero360-marca.svg" alt="" width="64" height="64">
        <div>
            <h2 class="titulo-4" id="titulo-acerca"><?= escaparValores($v['plataforma']) ?> · versión y actualizaciones</h2>
            <p class="texto-secundario">Plataforma comercial, contable y administrativa para cotizar, vender, entregar y controlar inventario e impuestos.</p>
        </div>
    </div>
    <p class="mb-md" role="status"><span class="badge <?= $claseEstado ?>"><?= escaparValores($nombreEstado) ?></span> <?= escaparValores($v['estado']['texto']) ?></p>
    <dl class="cotizador-detalle__cliente">
        <div><dt>Plataforma</dt><dd><?= escaparValores($v['plataforma']) ?> (antes «Cotizaciones»)</dd></div>
        <div><dt>Versión instalada</dt><dd><?= escaparValores($v['version']) ?></dd></div>
        <div><dt>Compilación</dt><dd><?= $c['tag'] !== null ? 'Imagen ' . escaparValores($c['imagen']) . ':' . escaparValores($c['tag']) : 'Copia de desarrollo (sin imagen)' ?><?= $c['commit'] ? ' · ' . escaparValores($c['commit']) : '' ?></dd></div>
        <div><dt><?= $c['origen'] === 'imagen' ? 'Fecha de compilación' : 'Última compilación de recursos' ?></dt><dd><?= escaparValores($fechaLarga($c['compilado'])) ?></dd></div>
        <div><dt>Última imagen publicada</dt><dd><?= $u['ok'] ? escaparValores($c['imagen']) . ':' . (int) $u['tag'] . ' · ' . escaparValores($fechaLarga($u['publicada'] ?? null)) : 'No se pudo verificar' ?></dd></div>
        <div><dt>Verificado</dt><dd><?= escaparValores($fechaLarga($u['consultado'] ?? null)) ?><?= !empty($u['desactualizado']) ? ' · dato anterior: hoy no hubo conexión' : '' ?> · <a href="/admin/ajustes?seccion=version&amp;verificar=1">Verificar ahora</a></dd></div>
    </dl>
</section>

<section class="tarjeta mb-lg" aria-labelledby="titulo-infra">
    <h2 class="titulo-5 mb-md" id="titulo-infra">Dónde se ejecuta</h2>
    <dl class="cotizador-detalle__cliente">
        <div><dt>Plataforma</dt><dd><?= escaparValores($infra['plataforma']) ?></dd></div>
        <div><dt>Contenedor / equipo</dt><dd><?= escaparValores($infra['contenedor']) ?></dd></div>
        <div><dt>PHP</dt><dd><?= escaparValores($infra['php']) ?></dd></div>
        <div><dt>Servidor web</dt><dd><?= escaparValores($infra['servidor_web']) ?></dd></div>
        <div><dt>Base de datos</dt><dd><?= escaparValores($infra['mysql'] !== '' ? $infra['mysql'] : 'No disponible') ?></dd></div>
        <div><dt>Sistema</dt><dd><?= escaparValores($infra['sistema']) ?> · <?= escaparValores($infra['zona_horaria']) ?></dd></div>
    </dl>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="titulo-compat">
    <h2 class="titulo-5 ajustes-titulo" id="titulo-compat">Compatibilidad</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Requisitos de la plataforma frente a lo instalado</caption>
            <thead><tr><th scope="col">Componente</th><th scope="col">Requiere</th><th scope="col">Detectado</th><th scope="col">Estado</th></tr></thead>
            <tbody>
                <?php foreach ($v['compatibilidad'] as $fila) : ?>
                    <tr>
                        <td><strong><?= escaparValores($fila['nombre']) ?></strong><span class="texto-xs texto-secundario"><br><?= escaparValores($fila['nota']) ?></span></td>
                        <td><?= escaparValores($fila['requerido']) ?></td>
                        <td><?= escaparValores($fila['detectado']) ?></td>
                        <td><?= $fila['ok'] === null ? '<span class="badge badge--neutro">Sin dato</span>' : ($fila['ok'] ? '<span class="badge badge--aprobado">Compatible</span>' : '<span class="badge badge--pendiente">Revisar</span>') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<p class="campo__ayuda mb-lg">Los cálculos de impuestos son una estimación informativa; confírmalos con tu contador. Soporte: <a href="/admin/ayuda">Ayuda y manual</a>.</p>

<?php endif; ?>

<?php template('footer'); ?>
