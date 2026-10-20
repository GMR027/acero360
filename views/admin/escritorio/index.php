<?php
/**
 * views/admin/escritorio/index.php — Aplicación de escritorio (Linux .deb): versiones publicadas,
 * descarga, suma de verificación, instrucciones y retiro de versiones defectuosas. Solo administradores.
 */

soloAdmin();

$paquetes = \App\PaquetesEscritorio::listar();
$vigente  = \App\PaquetesEscritorio::vigente('amd64');
$tam      = static fn (int $b): string => number_format($b / 1048576, 1) . ' MB';
$host     = $_SERVER['HTTP_HOST'] ?? 'tu-servidor';

template('header', ['titulo' => 'Aplicación de escritorio', 'subtitulo' => 'Linux (paquete .deb)', 'rutaActiva' => '/admin/escritorio']);
?>

<p class="nota nota--advertencia mb-lg" role="note">
    <span class="material-symbols-outlined nota__icono" aria-hidden="true">desktop_windows</span>
    <span>La aplicación de escritorio es una <strong>ventana segura hacia esta misma plataforma</strong>: usa los mismos datos y la misma sesión que la web.
        No guarda una base de datos propia, así que el servidor (aplicación, base de datos, documentos fiscales, PDF y correo) debe seguir disponible.</span>
</p>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Versión disponible</h2>
    <?php if ($vigente) : ?>
        <p><strong>Acero 360 <?= escaparValores($vigente['version']) ?></strong> · <?= escaparValores($vigente['arquitectura']) ?> · <?= $tam((int) $vigente['tamano']) ?>
            · publicada el <?= date('d/m/Y H:i', strtotime($vigente['publicada_en'])) ?></p>
        <?php if ($vigente['notas']) : ?><p class="texto-secundario"><?= escaparValores($vigente['notas']) ?></p><?php endif; ?>
        <p class="texto-xs texto-secundario mt-md">SHA-256: <code><?= escaparValores($vigente['sha256']) ?></code></p>
        <div class="encabezado-pagina__acciones mt-md">
            <a class="btn btn--primario" href="/admin/escritorio/descargar?id=<?= (int) $vigente['id'] ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">download</span><span>Descargar <?= escaparValores($vigente['nombre_archivo']) ?></span></a>
        </div>
    <?php else : ?>
        <p class="texto-secundario">Todavía no hay una versión publicada. Quien administra el servidor la publica con
            <code>php scripts/publicar_escritorio.php &lt;archivo.deb&gt; &lt;versión&gt;</code>.</p>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Instalación, actualización y desinstalación</h2>
    <p class="campo__ayuda mb-md">Probado en instalaciones limpias de Debian 12 y Ubuntu 24.04 (64 bits).</p>
    <ol>
        <li><strong>Instalar:</strong> <code>sudo apt install ./cotizaciones-escritorio_<?= escaparValores($vigente['version'] ?? 'X.Y.Z') ?>_amd64.deb</code> (apt instala también lo que necesite).</li>
        <li><strong>Verificar la descarga (opcional):</strong> <code>sha256sum cotizaciones-escritorio_*.deb</code> debe coincidir con la suma de arriba.</li>
        <li><strong>Abrir:</strong> «Acero 360» en el menú de aplicaciones, o <code>/opt/Cotizaciones/cotizaciones-escritorio</code>. Inicia sesión como en la web.</li>
        <li><strong>Actualizar:</strong> descarga la versión nueva y vuelve a ejecutar <code>sudo apt install ./cotizaciones-escritorio_…deb</code>; reemplaza a la anterior y conserva tu configuración.</li>
        <li><strong>Desinstalar:</strong> <code>sudo apt remove cotizaciones-escritorio</code>. Para borrar también los datos locales (cookies de sesión): <code>rm -r ~/.config/Cotizaciones</code>.</li>
        <li><strong>Servidor distinto:</strong> por defecto se conecta a <code>https://<?= escaparValores($host) ?></code>. Se cambia con <code>~/.config/Cotizaciones/config.json</code> → <code>{"url": "https://tu-servidor"}</code> (solo HTTPS).</li>
    </ol>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Registro de versiones</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Versiones publicadas de la aplicación de escritorio</caption>
            <thead><tr><th scope="col">Versión</th><th scope="col">Arquitectura</th><th scope="col" class="tabla--numerica">Tamaño</th><th scope="col">Publicada</th>
                <th scope="col">SHA-256</th><th scope="col">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
            <tbody>
                <?php foreach ($paquetes as $p) : ?>
                    <tr<?= $p['estado'] === 'retirada' ? ' style="opacity:.65"' : '' ?>>
                        <td><strong><?= escaparValores($p['version']) ?></strong></td><td><?= escaparValores($p['arquitectura']) ?></td>
                        <td class="tabla--numerica cifra"><?= $tam((int) $p['tamano']) ?></td>
                        <td class="texto-sm"><?= date('d/m/Y H:i', strtotime($p['publicada_en'])) ?><br><?= escaparValores((string) $p['publicada_por_nombre'] ?: 'Servidor') ?></td>
                        <td class="texto-xs"><?= escaparValores(substr($p['sha256'], 0, 16)) ?>…</td>
                        <td><?= $p['estado'] === 'publicada' ? '<span class="badge badge--aprobado">Publicada</span>'
                            : '<span class="badge badge--error">Retirada</span><span class="texto-xs texto-secundario"><br>' . escaparValores((string) $p['motivo_retiro']) . '</span>' ?></td>
                        <td class="tabla--numerica">
                            <?php if ($p['estado'] === 'publicada') : ?>
                                <a class="btn btn--secundario btn--sm" href="/admin/escritorio/descargar?id=<?= (int) $p['id'] ?>">Descargar</a>
                                <form action="/admin/escritorio/retirar" method="post" data-validar
                                      data-confirmacion-sobre="Versión <?= escaparValores($p['version']) ?>"
                                      data-confirmacion="¿Retirar esta versión?"
                                      data-confirmacion-mensaje="Deja de ofrecerse para descargar. Se conserva en el registro, con tu motivo. Quien ya la instaló debe actualizar a una versión nueva."
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
                <?php if (!$paquetes) : ?><tr><td colspan="7" class="tabla--centro texto-secundario">Sin versiones registradas.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="campo__ayuda" style="padding:0 1rem 1rem">Para <strong>sustituir</strong> una versión se publica una más nueva (la plataforma ofrece la más reciente no retirada). Una versión publicada nunca se sobrescribe.</p>
</section>

<?php template('footer'); ?>
