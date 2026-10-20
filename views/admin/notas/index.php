<?php
/**
 * views/admin/notas/index.php — Tablero de "Notas Personales".
 *
 * UNA SOLA bitácora interna, compartida por todos los administradores:
 * recordatorios, pendientes por cotizar, cambios, revisiones y notas
 * varias. Cualquier admin ve y gestiona las notas de los demás;
 * cada tarjeta indica quién la redactó.
 * Reproduce design/notas_personales (resumen, chips de filtro, buscador
 * y tarjetas con sus acciones).
 */

soloAdmin();

/** Texto de búsqueda y filtro activo (ambos vienen por query string). */
$termino = trim($_GET['q'] ?? '');
$filtro  = $_GET['filtro'] ?? 'todas';

if (!in_array($filtro, \App\Notas::FILTROS, true)) {
    $filtro = 'todas';
}

/** Listado paginado con el filtro activo (sección única y compartida). */
$porPagina = 12;
$total     = \App\Notas::contar($termino, $filtro);
$pagina    = paginacion($porPagina, $total);
$notas     = \App\Notas::listar($termino, $filtro, $pagina['limite'], $pagina['offset']);

/** Cifras de las tres tarjetas de resumen. */
$resumen = \App\Notas::estadisticas();

/** Conteo absoluto de cada chip (siempre se calcula sin filtro). */
$contadores = [
    'todas'        => \App\Notas::contar(),
    'fijadas'      => \App\Notas::contar('', 'fijadas'),
    'recordatorio' => \App\Notas::contar('', 'recordatorio'),
    'cotizar'      => \App\Notas::contar('', 'cotizar'),
    'cambios'      => \App\Notas::contar('', 'cambios'),
    'revisar'      => \App\Notas::contar('', 'revisar'),
    'otro'         => \App\Notas::contar('', 'otro'),
];

/** Etiquetas y iconos de los chips de filtro. */
$etiquetasFiltro = [
    'todas'        => ['Todas', 'list'],
    'fijadas'      => ['Fijadas', 'push_pin'],
    'recordatorio' => ['Recordatorios', 'alarm'],
    'cotizar'      => ['Cotizar', 'request_quote'],
    'cambios'      => ['Cambios', 'edit_note'],
    'revisar'      => ['Revisar', 'fact_check'],
    'otro'         => ['Otro', 'notes'],
];

/** Construye la URL de un chip conservando el texto de búsqueda. */
$urlChip = static function (string $clave) use ($termino): string {
    $url = '/admin/notas?filtro=' . rawurlencode($clave);
    if ($termino !== '') {
        $url .= '&q=' . rawurlencode($termino);
    }
    return $url;
};

/** Fecha amable de la tarjeta: "Hoy, 10:42 AM" · "Ayer, 4:15 PM" · "18 Oct". */
$fechaAmable = static function ($fecha): string {
    if (!$fecha) {
        return '';
    }
    $momento = new \DateTime($fecha);
    $hoy     = new \DateTime('today');
    $ayer    = (clone $hoy)->modify('-1 day');

    if ($momento->format('Y-m-d') === $hoy->format('Y-m-d')) {
        return 'Hoy, ' . $momento->format('g:i A');
    }
    if ($momento->format('Y-m-d') === $ayer->format('Y-m-d')) {
        return 'Ayer, ' . $momento->format('g:i A');
    }
    return $momento->format('d M');
};

/** Icono adecuado para la referencia según su tipo. */
$iconoReferencia = static function (string $referencia): string {
    $texto = mb_strtolower($referencia);

    if (strpos($texto, 'cliente') !== false)   { return 'corporate_fare'; }
    if (strpos($texto, 'tel') !== false)       { return 'contact_phone'; }
    if (strpos($texto, 'cat') !== false)       { return 'inventory_2'; }
    if (strpos($texto, 'destino') !== false
        || strpos($texto, 'entrega') !== false) { return 'local_shipping'; }
    if (strpos($texto, 'mail') !== false
        || strpos($texto, 'correo') !== false)  { return 'mail'; }

    return 'link';
};

template('header', [
    'titulo'    => 'Notas Personales',
    'subtitulo' => 'Bitácora compartida',
    'acciones'  => '<a class="btn btn--primario" href="/admin/notas/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>'
        . '<span>Nueva Nota</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <!-- <h1 class="titulo-2">
            Notas Personales
            <span class="notas-sello">Bitácora de Equipo</span>
        </h1> -->
        <p class="texto-secundario">
            Único espacio de anotaciones del equipo: recordatorios, pendientes por cotizar,
            cambios, revisiones y notas varias. Es compartido: todo administrador
            puede leer y gestionar las notas de todos.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--primario" href="/admin/notas/crear">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
            <span>Nueva Nota</span>
        </a>
    </div>
</section>

<!-- Resumen de la bitácora -->
<section class="rejilla-stats mb-lg" aria-label="Resumen de notas">
    <div class="stat">
        <span class="stat__etiqueta">Notas Activas</span>
        <span class="stat__valor"><?= number_format($resumen['activas']) ?></span>
        <span class="stat__pie">Sin completar</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Fijadas</span>
        <span class="stat__valor"><?= number_format($resumen['fijadas']) ?></span>
        <span class="stat__pie">Visibles arriba</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Por cotizar</span>
        <span class="stat__valor"><?= number_format($resumen['por_cotizar']) ?></span>
        <span class="stat__pie">Sin cotización</span>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <!-- Buscador + filtros -->
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/notas" method="get" role="search" data-validar>
            <input type="hidden" name="filtro" value="<?= escaparValores($filtro) ?>">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar nota</label>
                <input class="control" type="search" id="q" name="q" maxlength="100"
                       placeholder="Buscar por palabra, cliente o etiqueta…"
                       value="<?= escaparValores($termino) ?>">
            </div>
            <button type="submit" class="btn btn--secundario btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>
            <?php if ($termino !== '') : ?>
                <a class="btn btn--texto btn--sm" href="/admin/notas<?= $filtro !== 'todas' ? '?filtro=' . rawurlencode($filtro) : '' ?>">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'nota' : 'notas' ?>
        </span>
    </div>

    <div class="notas-filtros">
        <nav class="chip-grupo ajustes-tabs" aria-label="Filtros de notas">
            <?php foreach ($etiquetasFiltro as $clave => [$texto, $icono]) :
                $activo = $filtro === $clave;
                ?>
                <a class="chip<?= $activo ? ' chip--activo' : '' ?>"
                   href="<?= escaparValores($urlChip($clave)) ?>"
                   <?= $activo ? 'aria-current="page"' : '' ?>>
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= $icono ?></span>
                    <span><?= $texto ?></span>
                    <span class="chip__cuenta"><?= number_format($contadores[$clave]) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php if (empty($notas)) : ?>
        <!-- Estado vacío -->
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">
                <?= $termino !== '' || $filtro !== 'todas' ? 'search_off' : 'sticky_note_2' ?>
            </span>
            <p class="vacio__titulo">
                <?= ($termino !== '' || $filtro !== 'todas') ? 'No se encontraron notas' : 'Aún no hay notas' ?>
            </p>
            <p>
                <?= ($termino !== '' || $filtro !== 'todas')
                    ? 'Ninguna nota coincide con el criterio de búsqueda o filtro seleccionado.'
                    : 'Registra tu primera nota para no perder ningún recordatorio o pendiente.' ?>
            </p>
            <?php if ($termino !== '' || $filtro !== 'todas') : ?>
                <a class="btn btn--secundario" href="/admin/notas">Restablecer filtros</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/admin/notas/crear">Nueva Nota</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <!-- Tarjetas -->
        <div class="notas-cuerpo">
            <div class="notas-lista">
                <?php foreach ($notas as $nota) :
                    $categoria = \App\Notas::datosCategoria($nota->categoria);
                    $esFijada = (int) $nota->fijada === 1;
                    $esHecha  = (int) $nota->completada === 1;
                    $ref      = trim((string) $nota->referencia);
                    $partes   = explode(':', $ref, 2);
                    $etiquetaRef = trim($partes[0]);
                    $valorRef    = trim($partes[1] ?? '');
                    if ($valorRef === '') {
                        $etiquetaRef = '';
                        $valorRef    = $ref;
                    }
                    ?>
                    <article class="nota-tarjeta<?= $esFijada ? ' nota-tarjeta--fijada' : '' ?><?= $esHecha ? ' nota-tarjeta--hecha' : '' ?>">
                        <header class="nota-tarjeta__cabecera">
                            <span class="badge <?= $categoria['clase'] ?>">
                                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $categoria['icono'] ?></span>
                                <?= $categoria['etiqueta'] ?>
                            </span>

                            <?php if ($esFijada) : ?>
                                <span class="badge badge--info">Fijada</span>
                            <?php endif; ?>

                            <?php if (trim((string) $nota->fecha_recordatorio) !== '') : ?>
                                <span class="nota-tarjeta__vencimiento">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem">event</span>
                                    <?= escaparValores($nota->fecha_recordatorio) ?>
                                </span>
                            <?php endif; ?>

                            <span class="nota-tarjeta__autor">
                                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem">person</span>
                                <?= escaparValores(trim((string) $nota->autor) !== '' ? $nota->autor : 'Administrador') ?>
                            </span>

                            <time class="nota-tarjeta__fecha" datetime="<?= escaparValores($nota->creado_en) ?>">
                                <?= $fechaAmable($nota->creado_en) ?>
                            </time>
                        </header>

                        <h2 class="nota-tarjeta__titulo"><?= escaparValores($nota->titulo) ?></h2>
                        <p class="nota-tarjeta__cuerpo"><?= escaparValores($nota->cuerpo) ?></p>

                        <?php if ($valorRef !== '') : ?>
                            <div class="nota-tarjeta__referencia">
                                <span class="material-symbols-outlined" aria-hidden="true"><?= $iconoReferencia($ref) ?></span>
                                <span>
                                    <?php if ($etiquetaRef !== '') : ?>
                                        <strong><?= escaparValores($etiquetaRef) ?>:</strong>
                                    <?php endif; ?>
                                    <?= escaparValores($valorRef) ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <footer class="nota-tarjeta__acciones">
                            <a class="btn btn--texto btn--sm"
                               href="/admin/notas/editar?id=<?= (int) $nota->id ?>"
                               aria-label="Editar <?= escaparValores($nota->titulo) ?>">
                                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                <span>Editar</span>
                            </a>

                            <!-- Fijar / desfijar -->
                            <form action="/admin/notas/accion" method="post" data-validar>
                                <input type="hidden" name="id" value="<?= (int) $nota->id ?>">
                                <input type="hidden" name="accion" value="fijar">
                                <button type="submit" class="btn btn--texto btn--sm"
                                        aria-label="<?= $esFijada ? 'Desfijar' : 'Fijar' ?> <?= escaparValores($nota->titulo) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">push_pin</span>
                                    <span><?= $esFijada ? 'Fijada' : 'Fijar' ?></span>
                                </button>
                            </form>

                            <!-- Marcar como hecha / reabrir -->
                            <form action="/admin/notas/accion" method="post" data-validar>
                                <input type="hidden" name="id" value="<?= (int) $nota->id ?>">
                                <input type="hidden" name="accion" value="completar">
                                <button type="submit" class="btn btn--secundario btn--sm btn--icono"
                                        title="<?= $esHecha ? 'Reabrir' : 'Marcar como hecha' ?>"
                                        aria-label="<?= $esHecha ? 'Reabrir' : 'Marcar como hecha' ?> <?= escaparValores($nota->titulo) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">check_circle</span>
                                </button>
                            </form>

                            <!-- Eliminar con confirmación modal (nunca window.confirm). -->
                            <form action="/admin/notas/accion" method="post" data-validar
                                  data-confirmacion-sobre="Bitácora interna"
                                  data-confirmacion="¿Eliminar esta nota?"
                                  data-confirmacion-mensaje="Se borrará «<?= escaparValores($nota->titulo) ?>». Esta acción no se puede deshacer."
                                  data-confirmacion-boton="Sí, eliminar">
                                <input type="hidden" name="id" value="<?= (int) $nota->id ?>">
                                <input type="hidden" name="accion" value="eliminar">
                                <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                        title="Eliminar" aria-label="Eliminar <?= escaparValores($nota->titulo) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                                </button>
                            </form>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php
            template('paginacion', [
                'pagina'   => $pagina,
                'rutaBase' => '/admin/notas'
                    . ($filtro !== 'todas' ? '?filtro=' . rawurlencode($filtro) : '')
                    . ($termino !== '' ? '&q=' . rawurlencode($termino) : ''),
                'total'    => $total,
            ]);
            ?>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
