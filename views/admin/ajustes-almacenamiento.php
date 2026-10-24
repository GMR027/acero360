<?php
/**
 * views/admin/ajustes-almacenamiento.php — Almacenamiento: espacio ocupado y copias de seguridad.
 *
 * La medición es en vivo (base de datos + carpetas de archivos). Los respaldos reutilizan
 * scripts/respaldar.sh y scripts/restaurar.sh, que ya valida pruebas/probar_respaldo.sh.
 * Restaurar nunca toca lo que estás usando: importa en una base nueva y extrae los archivos
 * a una carpeta aparte, para que revises y decidas.
 */

soloAdmin();

$usuarioId = (int) ($_SESSION['id'] ?? 0);
$destino = '/admin/ajustes/almacenamiento';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($destino, 'error', 'Este formulario ya se envió; no se repitió la operación.');
    }

    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'respaldar') {
        $r = \App\Almacenamiento::crear();
        \App\Auditoria::registrar('respaldo', null, 'crear', $usuarioId, $r['ok'] ? null : $r['mensaje'], ['carpeta' => $r['carpeta'] ?? '']);
        redirigir($destino, $r['ok'] ? 'exito' : 'error', $r['mensaje']);
    }

    if ($accion === 'restaurar') {
        $r = \App\Almacenamiento::restaurar((string) ($_POST['sello'] ?? ''));
        \App\Auditoria::registrar('respaldo', null, 'restaurar', $usuarioId, $r['ok'] ? null : $r['mensaje'], [
            'sello' => (string) ($_POST['sello'] ?? ''), 'base' => $r['base'] ?? '', 'carpeta' => $r['carpeta'] ?? '',
        ]);
        redirigir($destino, $r['ok'] ? 'exito' : 'error', $r['mensaje']);
    }

    if ($accion === 'guardar') {
        $hora = (string) ($_POST['hora'] ?? '23:00');
        $retener = max(1, min(365, (int) ($_POST['retener'] ?? 14)));
        $carpeta = trim((string) ($_POST['carpeta'] ?? ''));

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            redirigir($destino, 'error', 'La hora del respaldo automático no es válida (usa el formato 23:00).');
        }
        // Sólo rutas absolutas: una relativa se resolvería contra la raíz del servidor web.
        if ($carpeta !== '' && $carpeta[0] !== '/') {
            redirigir($destino, 'error', 'La carpeta de respaldos debe ser una ruta absoluta (que empiece con «/»).');
        }

        $cambios = [
            \App\Almacenamiento::CFG_AUTOMATICO => !empty($_POST['automatico']) ? '1' : '0',
            \App\Almacenamiento::CFG_HORA       => $hora,
            \App\Almacenamiento::CFG_RETENER    => (string) $retener,
            \App\Almacenamiento::CFG_CARPETA    => $carpeta,
        ];
        foreach ($cambios as $clave => $valor) {
            if (\App\Configuracion::obtener($clave, '') !== $valor) {
                \App\Configuracion::guardar($clave, $valor, $usuarioId);
            }
        }
        redirigir($destino, 'exito', 'Guardado. El respaldo automático corre a las ' . $hora . ' y se conservan ' . $retener . ' copias.');
    }

    redirigir($destino, 'error', 'Acción no reconocida.');
}

// Si ya pasó la hora de hoy y falta el respaldo del día, se genera aquí mismo.
$automaticoHoy = \App\Almacenamiento::respaldoDelDia();

$ocupacion = \App\Almacenamiento::ocupacion();
$resumen = \App\Almacenamiento::resumen();
$lista = \App\Almacenamiento::listar();
$faltan = \App\Almacenamiento::faltantes();
[$ultimoFecha, $ultimoEstado, $ultimoCarpeta] = \App\Almacenamiento::ultimo();

$peso = static fn (int $b): string => \App\Almacenamiento::formatearBytes($b);

template('header', ['titulo' => 'Almacenamiento', 'subtitulo' => 'Espacio ocupado y copias de seguridad', 'rutaActiva' => '/admin/ajustes']);
template('tabsAjustes', ['tab' => 'almacenamiento']);
?>

<?php if ($automaticoHoy !== null) : ?>
    <p class="nota <?= $automaticoHoy['ok'] ? 'nota--exito' : 'nota--error' ?> mb-md" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true"><?= $automaticoHoy['ok'] ? 'backup' : 'error' ?></span>
        <span><?= escaparValores($automaticoHoy['mensaje']) ?></span>
    </p>
<?php endif; ?>

<?php if ($faltan) : ?>
    <p class="nota nota--error mb-md" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span>Esta máquina no tiene las herramientas para respaldar: falta <strong><?= escaparValores(implode(', ', $faltan)) ?></strong>.
        Instala el cliente de MySQL y las utilidades de compresión, o pídeselo a quien administra el servidor.</span>
    </p>
<?php endif; ?>

<section class="tarjeta mb-lg" aria-labelledby="titulo-ocupacion">
    <div class="ajustes-cabecera">
        <span class="ajustes-cabecera__icono"><span class="material-symbols-outlined" aria-hidden="true">hard_drive</span></span>
        <div>
            <h2 class="titulo-5" id="titulo-ocupacion">Espacio ocupado</h2>
            <p class="texto-xs texto-secundario">Se calcula al abrir esta página, sin guardar nada.</p>
        </div>
    </div>

    <div class="flex-entre fila--envuelto mb-md">
        <strong class="cifra"><?= $peso($ocupacion['total']) ?></strong>
        <span class="texto-xs texto-secundario"><?= $peso($ocupacion['base']) ?> en base de datos · <?= $peso($ocupacion['archivos']) ?> en archivos</span>
    </div>

    <!-- Barra: cada segmento usa el ancho que le corresponde del total. -->
    <div style="display:flex;height:1rem;border-radius:.5rem;overflow:hidden;background:var(--color-borde);margin-bottom:1rem" role="img"
         aria-label="Reparto del espacio: <?php foreach ($ocupacion['areas'] as $a) : ?><?= escaparValores($a['etiqueta']) ?> <?= \App\Almacenamiento::porcentaje($a['bytes'], $ocupacion['total']) ?>%, <?php endforeach; ?>">
        <?php foreach ($ocupacion['areas'] as $indice => $area) :
            $pct = \App\Almacenamiento::porcentaje($area['bytes'], $ocupacion['total']);
            // Paleta fija por orden de tamaño (los tonos del tema se usan en los textos).
            $tonos = ['var(--color-primario)', 'var(--color-exito)', 'var(--color-advertencia)', 'var(--color-error)', 'var(--color-borde-fuerte)'];
            $fondo = $pct > 0 ? $tonos[$indice % count($tonos)] : 'transparent';
            ?>
            <span style="width:<?= $pct ?>%;background:<?= $fondo ?>;border-right:1px solid var(--color-fondo)" title="<?= escaparValores($area['etiqueta']) ?>: <?= $peso($area['bytes']) ?>"></span>
        <?php endforeach; ?>
    </div>

    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Espacio ocupado por cada parte de la plataforma</caption>
            <thead><tr><th scope="col">Parte</th><th scope="col">Dónde está</th><th scope="col" class="tabla--numerica">Ocupa</th><th scope="col" class="tabla--numerica">% del total</th></tr></thead>
            <tbody>
                <?php foreach ($ocupacion['areas'] as $area) : ?>
                    <tr>
                        <td><strong><?= escaparValores($area['etiqueta']) ?></strong><span class="texto-xs texto-secundario"><br><?= escaparValores($area['nota']) ?></span></td>
                        <td class="texto-xs"><?= escaparValores($area['ruta']) ?></td>
                        <td class="tabla--numerica cifra"><?= $peso($area['bytes']) ?></td>
                        <td class="tabla--numerica"><?= \App\Almacenamiento::porcentaje($area['bytes'], $ocupacion['total']) ?> %</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="campo__ayuda">Si ocupa demasiado, lo que más pesa son los XML y PDF de facturas y las copias de seguridad acumuladas: revisa la retención de aquí abajo.</p>
</section>

<section class="tarjeta mb-lg" aria-labelledby="titulo-automatico">
    <div class="ajustes-cabecera">
        <span class="ajustes-cabecera__icono"><span class="material-symbols-outlined" aria-hidden="true">schedule_send</span></span>
        <div>
            <h2 class="titulo-5" id="titulo-automatico">Respaldo automático</h2>
            <p class="texto-xs texto-secundario">Una copia al día de la base de datos y de todos los archivos.</p>
        </div>
    </div>

    <form action="/admin/ajustes/almacenamiento" method="post" data-validar>
        <input type="hidden" name="token" value="<?= escaparValores(tokenFormulario()) ?>">
        <input type="hidden" name="accion" value="guardar">

        <label class="interruptor mb-md" for="automatico">
            <span>
                <strong>Hacer el respaldo solo, cada día</strong><br>
                <span class="texto-xs texto-secundario">Se ejecuta la primera vez que alguien abra la plataforma después de la hora elegida. Si ese día nadie entra, no se hace: para que ocurra aunque nadie entre, copia la línea de cron de abajo.</span>
            </span>
            <input type="checkbox" id="automatico" name="automatico" value="1" <?= \App\Almacenamiento::automatico() ? 'checked' : '' ?>>
            <span class="interruptor__pista" aria-hidden="true"></span>
        </label>

        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta" for="hora">Hora del respaldo</label>
                <input class="control" type="time" id="hora" name="hora" value="<?= escaparValores(\App\Almacenamiento::hora()) ?>">
                <span class="campo__ayuda">Por defecto a las 23:00.</span>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="retener">Copias que se conservan</label>
                <input class="control" type="number" id="retener" name="retener" min="1" max="365" step="1" value="<?= \App\Almacenamiento::retener() ?>">
                <span class="campo__ayuda">Las más antiguas se borran solas al respaldar.</span>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="carpeta">Carpeta de respaldos (opcional)</label>
                <input class="control" type="text" id="carpeta" name="carpeta" maxlength="255" placeholder="/respaldos" value="<?= escaparValores(\App\Almacenamiento::carpetaRespaldos() === \App\Almacenamiento::RAIZ . '/respaldos' ? '' : \App\Almacenamiento::carpetaRespaldos()) ?>">
                <span class="campo__ayuda">Vacío = <code>respaldos</code> dentro de la plataforma. Usa un disco o servidor aparte si puedes.</span>
            </div>
        </div>

        <div class="formulario__acciones">
            <button class="btn btn--primario" type="submit">Guardar</button>
        </div>
    </form>

    <dl class="cotizador-detalle__cliente mt-md">
        <div><dt>Último respaldo</dt><dd><?= $ultimoFecha ? escaparValores($ultimoFecha) . ' · ' . ($ultimoEstado === 'ok' ? '<span class="badge badge--aprobado">Correcto</span>' : '<span class="badge badge--error">Falló</span>') . ' · ' . escaparValores($ultimoCarpeta) : 'Todavía no se ha hecho ninguno.' ?></dd></div>
        <div><dt>Carpetas guardadas</dt><dd><?= escaparValores(implode(', ', ['base de datos', 'public/image', 'storage/documentos'])) ?></dd></div>
    </dl>

    <?php if (!$faltan) : ?>
        <details class="mt-md">
            <summary class="btn btn--texto btn--sm">Garantizar el respaldo aunque nadie entre (cron)</summary>
            <p class="campo__ayuda mt-sm">Esta línea hace el respaldo todos los días a la hora elegida sin depender de que alguien abra la plataforma. Pégala en el <code>crontab</code> del servidor (<code>crontab -e</code>):</p>
            <pre style="overflow:auto;background:var(--color-fondo);border:1px solid var(--color-borde);border-radius:.5rem;padding:.75rem;font-size:.8125rem"><?= escaparValores(\App\Almacenamiento::lineaCron()) ?></pre>
        </details>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg" aria-labelledby="titulo-copias">
    <div class="ajustes-cabecera">
        <span class="ajustes-cabecera__icono"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span></span>
        <div>
            <h2 class="titulo-5" id="titulo-copias">Copias guardadas</h2>
            <p class="texto-xs texto-secundario"><?= $resumen['copias'] ?> <?= $resumen['copias'] === 1 ? 'copia' : 'copias' ?> · <?= $peso($resumen['bytes']) ?> en total. Cada una trae la base y los archivos, con sus huellas de verificación.</p>
        </div>
    </div>

    <?php if (!$lista) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">inventory_2</span>
            <p class="vacio__titulo">Todavía no hay copias</p>
            <p>Pulsa «Hacer un respaldo ahora» para crear la primera.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Copias de seguridad disponibles</caption>
                <thead><tr><th scope="col">Copia</th><th scope="col" class="tabla--numerica">Peso</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                <tbody>
                    <?php foreach ($lista as $copia) : ?>
                        <tr>
                            <td>
                                <strong><?= escaparValores($copia['sello']) ?></strong>
                                <span class="texto-xs texto-secundario"><br><?= $copia['fecha'] ? 'Hecha el ' . escaparValores($copia['fecha']) : 'Sin fecha en el manifiesto' ?><?= $copia['tablas'] !== '' ? ' · ' . (int) $copia['tablas'] . ' tablas' : '' ?></span>
                            </td>
                            <td class="tabla--numerica cifra"><?= $peso($copia['bytes']) ?></td>
                            <td><?= $copia['incompleta'] ? '<span class="badge badge--error">Incompleta</span>' : '<span class="badge badge--aprobado">Verificable</span>' ?></td>
                            <td>
                                <form action="/admin/ajustes/almacenamiento" method="post" style="display:flex;gap:.5rem;flex-wrap:wrap"
                                      data-confirmacion-sobre="Copia <?= escaparValores($copia['sello']) ?>"
                                      data-confirmacion="¿Restaurar esta copia?"
                                      data-confirmacion-mensaje="Se importará en una base NUEVA (<?= escaparValores('cotizaciones_restaurada_' . date('Ymd')) ?>) y los archivos se extraerán a una carpeta aparte. Lo que estás usando ahora no se toca ni se pisa."
                                      data-confirmacion-boton="Sí, restaurar">
                                    <input type="hidden" name="token" value="<?= escaparValores(tokenFormulario()) ?>">
                                    <input type="hidden" name="accion" value="restaurar">
                                    <input type="hidden" name="sello" value="<?= escaparValores($copia['sello']) ?>">
                                    <button class="btn btn--secundario btn--sm" type="submit" <?= $copia['incompleta'] || $faltan ? 'disabled' : '' ?>>Restaurar en base nueva</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="campo__ayuda">Restaurar <strong>nunca</strong> borra ni pisa lo que estás usando: verifica las huellas de la copia, la importa en una base nueva y deja los archivos en una carpeta aparte. Después decides si conviene usarla.</p>
    <?php endif; ?>

    <form action="/admin/ajustes/almacenamiento" method="post" class="mt-md">
        <input type="hidden" name="token" value="<?= escaparValores(tokenFormulario()) ?>">
        <input type="hidden" name="accion" value="respaldar">
        <button class="btn btn--primario" type="submit" <?= $faltan ? 'disabled' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">backup</span><span>Hacer un respaldo ahora</span>
        </button>
    </form>
</section>

<p class="campo__ayuda mb-lg">Cada copia se comprueba con sus huellas SHA-256 antes de restaurarla. Guarda también una copia fuera de esta computadora (disco externo o nube): si el equipo se daña, aquí no queda nada.</p>

<?php template('footer'); ?>
