<?php
/**
 * views/admin/android/index.php — Aplicación Android: versión vigente del APK, descarga,
 * estado de la app instalada en este teléfono, dispositivos que la usan y registro de versiones.
 */

soloAdmin();

$paquetes     = \App\AppAndroid::listar();
$vigente      = \App\AppAndroid::vigente();
$dispositivos = \App\AppAndroid::dispositivos();
$estaApp      = \App\AppAndroid::desdeApp();
$tam          = static fn (int $b): string => number_format($b / 1048576, 1) . ' MB';
$host         = $_SERVER['HTTP_HOST'] ?? 'tu-servidor';
$urlPublica   = (conexionSegura() ? 'https' : 'http') . '://' . $host . '/app/android/descargar';
$estadoDe     = static function (string $instalada) use ($vigente): array {
    if (!$vigente) {
        return ['badge--neutro', 'Sin versión publicada'];
    }
    $c = version_compare($instalada, (string) $vigente['version']);
    return $c < 0 ? ['badge--pendiente', 'Actualización disponible'] : ['badge--aprobado', 'Al día'];
};

template('header', ['titulo' => 'Aplicación Android', 'subtitulo' => 'APK para teléfonos y tabletas', 'rutaActiva' => '/admin/ajustes']);
template('tabsAjustes', ['tab' => 'android']);
?>

<p class="nota nota--advertencia mb-lg" role="note">
    <span class="material-symbols-outlined nota__icono" aria-hidden="true">phone_android</span>
    <span>La aplicación Android es una <strong>ventana segura hacia esta misma plataforma</strong> (hecha con Rust y Tauri 2): usa los mismos datos que la web
        y no guarda una base de datos propia. Al abrirse revisa si hay una versión más nueva y te ofrece actualizar.</span>
</p>

<?php if ($estaApp) : [$clase, $texto] = $estadoDe($estaApp['version']); ?>
<section class="tarjeta mb-lg" aria-labelledby="t-esta-app">
    <h2 class="titulo-5 mb-md" id="t-esta-app">Esta aplicación</h2>
    <p>Tienes instalada la versión <strong><?= escaparValores($estaApp['version']) ?></strong>
        <span class="badge <?= $clase ?>"><?= $texto ?></span></p>
    <?php if ($vigente && version_compare($estaApp['version'], (string) $vigente['version'], '<')) : ?>
        <p class="texto-secundario mt-sm">La versión <?= escaparValores($vigente['version']) ?> está lista. Al tocar «Actualizar» se descarga el APK; ábrelo desde las notificaciones y elige «Actualizar». Tus datos no se pierden.</p>
        <div class="encabezado-pagina__acciones mt-md">
            <a class="btn btn--primario" href="/app/android/descargar">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">system_update</span><span>Actualizar a <?= escaparValores($vigente['version']) ?></span></a>
        </div>
    <?php endif; ?>
    <div class="encabezado-pagina__acciones mt-md">
        <a class="btn btn--secundario" href="http://tauri.localhost/index.html">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">refresh</span><span>Revisar actualizaciones</span></a>
        <a class="btn btn--secundario" href="http://tauri.localhost/index.html?servidor=1">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">dns</span><span>Cambiar servidor</span></a>
    </div>
</section>
<?php endif; ?>

<!-- Desbloqueo con huella: solo se muestra dentro de la app 1.0.3+ y si el teléfono tiene huella (lo revela huellaApp.js). -->
<section class="tarjeta mb-lg" aria-labelledby="t-huella" data-huella-app hidden>
    <h2 class="titulo-5 mb-md" id="t-huella">Desbloqueo con huella</h2>
    <label class="interruptor" for="huella-activa">
        <span>
            <strong>Pedir mi huella al abrir la app</strong><br>
            <span class="texto-xs texto-secundario">Solo en este teléfono. Al abrir Acero 360, o al volver a ella después de un minuto, se pide la huella digital (o el PIN del teléfono) antes de mostrar la información. Para activarlo o quitarlo se confirma con tu huella.</span>
        </span>
        <input type="checkbox" id="huella-activa">
        <span class="interruptor__pista" aria-hidden="true"></span>
    </label>
    <p class="campo__ayuda mt-sm" data-huella-estado role="status" aria-live="polite"></p>
</section>

<section class="tarjeta mb-lg" aria-labelledby="t-vigente">
    <h2 class="titulo-5 mb-md" id="t-vigente">Versión disponible</h2>
    <?php if ($vigente) : ?>
        <p><strong>Acero 360 para Android <?= escaparValores($vigente['version']) ?></strong> · <?= $tam((int) $vigente['tamano']) ?>
            · publicada el <?= date('d/m/Y H:i', strtotime($vigente['publicada_en'])) ?></p>
        <?php if ($vigente['notas']) : ?><p class="texto-secundario"><?= escaparValores($vigente['notas']) ?></p><?php endif; ?>
        <p class="texto-xs texto-secundario mt-md">SHA-256: <code><?= escaparValores($vigente['sha256']) ?></code></p>
        <div class="encabezado-pagina__acciones mt-md">
            <a class="btn btn--primario" href="/admin/android/descargar?id=<?= (int) $vigente['id'] ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">download</span><span>Descargar <?= escaparValores($vigente['nombre_archivo']) ?></span></a>
        </div>
        <p class="campo__ayuda mt-md">Enlace directo para abrir en el navegador del teléfono (no pide sesión): <code><?= escaparValores($urlPublica) ?></code></p>
    <?php else : ?>
        <p class="texto-secundario">Todavía no hay una versión publicada. Quien administra el servidor la compila con
            <code>android/compilar.sh</code> y la publica con <code>php scripts/publicar_android.php &lt;archivo.apk&gt; &lt;versión&gt;</code>.</p>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg" aria-labelledby="t-instalar">
    <h2 class="titulo-5 mb-md" id="t-instalar">Instalación y actualización</h2>
    <p class="campo__ayuda mb-md">Requiere Android 7 o superior (64 bits, ARM). Probado en un Honor X9c con Android 16.</p>
    <ol>
        <li><strong>Descargar:</strong> abre <code><?= escaparValores($urlPublica) ?></code> en Chrome del teléfono.</li>
        <li><strong>Permitir la instalación:</strong> la primera vez Android pide autorizar a Chrome para «instalar apps desconocidas». Autorízalo solo para esto.</li>
        <li><strong>Instalar:</strong> abre el archivo descargado y toca «Instalar». Aparece «Acero 360» en tus aplicaciones.</li>
        <li><strong>Actualizar:</strong> al abrirse, la app avisa cuando hay una versión nueva y ofrece el botón «Actualizar». También puedes descargarla aquí: se instala encima y conserva tu sesión y ajustes.</li>
        <li><strong>Desinstalar:</strong> mantén presionado el ícono → «Desinstalar».</li>
    </ol>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="t-dispositivos">
    <h2 class="titulo-5" style="padding:1rem 1rem 0" id="t-dispositivos">Teléfonos con la app</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Dispositivos que han abierto la aplicación Android y su versión instalada</caption>
            <thead><tr><th scope="col">Dispositivo</th><th scope="col">Usuario</th><th scope="col">Versión instalada</th><th scope="col">Estado</th><th scope="col">Última vez</th></tr></thead>
            <tbody>
                <?php foreach ($dispositivos as $d) : [$clase, $texto] = $estadoDe((string) $d['version_app']); ?>
                    <tr>
                        <td><strong><?= escaparValores((string) ($d['modelo'] ?: 'Android')) ?></strong>
                            <?php if ($d['version_android']) : ?><br><span class="texto-xs texto-secundario">Android <?= escaparValores($d['version_android']) ?></span><?php endif; ?>
                            <?php if ($estaApp && $estaApp['instalacion'] === $d['instalacion']) : ?><br><span class="texto-xs">(este teléfono)</span><?php endif; ?></td>
                        <td><?= escaparValores((string) ($d['usuario_nombre'] ?? '—')) ?></td>
                        <td class="cifra"><?= escaparValores($d['version_app']) ?></td>
                        <td><span class="badge <?= $clase ?>"><?= $texto ?></span></td>
                        <td class="texto-sm"><?= date('d/m/Y H:i', strtotime($d['ultima_vez'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$dispositivos) : ?><tr><td colspan="5" class="tabla--centro texto-secundario">Ningún teléfono ha abierto la app todavía.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Registro de versiones</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Versiones publicadas de la aplicación Android</caption>
            <thead><tr><th scope="col">Versión</th><th scope="col" class="tabla--numerica">Tamaño</th><th scope="col">Publicada</th>
                <th scope="col">SHA-256</th><th scope="col">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
                <?php foreach ($paquetes as $p) : ?>
                    <tr<?= $p['estado'] === 'retirada' ? ' style="opacity:.65"' : '' ?>>
                        <td><strong><?= escaparValores($p['version']) ?></strong><br><span class="texto-xs texto-secundario">código <?= (int) $p['version_code'] ?></span></td>
                        <td class="tabla--numerica cifra"><?= $tam((int) $p['tamano']) ?></td>
                        <td class="texto-sm"><?= date('d/m/Y H:i', strtotime($p['publicada_en'])) ?><br><?= escaparValores((string) $p['publicada_por_nombre'] ?: 'Servidor') ?></td>
                        <td class="texto-xs"><?= escaparValores(substr($p['sha256'], 0, 16)) ?>…</td>
                        <td><?= $p['estado'] === 'publicada' ? '<span class="badge badge--aprobado">Publicada</span>'
                            : '<span class="badge badge--error">Retirada</span><span class="texto-xs texto-secundario"><br>' . escaparValores((string) $p['motivo_retiro']) . '</span>' ?></td>
                        <td class="tabla--numerica">
                            <?php if ($p['estado'] === 'publicada') : ?>
                                <a class="btn btn--secundario btn--sm" href="/admin/android/descargar?id=<?= (int) $p['id'] ?>">Descargar</a>
                                <form action="/admin/android/retirar" method="post" data-validar
                                      data-confirmacion-sobre="Versión <?= escaparValores($p['version']) ?>"
                                      data-confirmacion="¿Retirar esta versión?"
                                      data-confirmacion-mensaje="Deja de ofrecerse para descargar y la app ya no la propone como actualización. Se conserva en el registro, con tu motivo."
                                      data-confirmacion-boton="Sí, retirar">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <label class="solo-lectores" for="mr-<?= (int) $p['id'] ?>">Motivo del retiro</label>
                                    <input class="control control--compacto" type="text" id="mr-<?= (int) $p['id'] ?>" name="motivo" placeholder="Motivo del retiro" required minlength="10" maxlength="200">
                                    <button class="btn btn--peligro btn--sm" type="submit">Retirar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$paquetes) : ?><tr><td colspan="6" class="tabla--centro texto-secundario">Sin versiones registradas.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="campo__ayuda" style="padding:0 1rem 1rem">Android solo acepta como actualización un APK con número de versión mayor y firmado con la misma llave. Para sustituir una versión se publica una más nueva.</p>
</section>

<?php template('footer'); ?>
