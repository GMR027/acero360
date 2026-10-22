<?php
/**
 * views/admin/editor/abrir.php — Editor de láminas con Konva.
 *
 * El lienzo (JSON de Konva) es la fuente editable. Las capas son grupos
 * lógicos dentro de la capa de contenido y las guías se guardan como
 * atributos del documento; ni unas ni otras se exportan al PDF/PNG.
 */

soloAdmin();

$documento = \Model\EditorDocumento::find((int) ($_GET['id'] ?? 0));
if (!$documento) {
    redirigir('/admin/editor', 'error', 'El documento no existe o fue eliminado.');
}

$laminas = $documento->laminas();
if (!$laminas) {
    $nueva = new \Model\EditorLamina([
        'documento_id' => (int) $documento->id,
        'orden'        => 1,
        'nombre'       => 'Lámina 1',
        'marcas_json'  => '',
    ]);
    $nueva->guardar();
    $laminas = $documento->laminas();
}

[$ancho, $alto] = $documento->medidas();
$configFondo = $documento->configFondo();

$config = [
    'id'           => (int) $documento->id,
    'nombre'       => $documento->nombre,
    'ancho'        => $ancho,
    'alto'         => $alto,
    'orientacion'  => $documento->orientacion,
    'fondo'        => $documento->fondo,
    'fondo_color'  => $documento->fondo_color,
    'fondo_imagen' => $documento->fondo_imagen ? '/public/uploads/vplan/' . $documento->fondo_imagen : null,
    'fondo_config' => $configFondo,
    'version'      => (string) $documento->actualizado,
    'laminas'      => array_map(fn($l) => [
        'id'     => (int) $l->id,
        'orden'  => (int) $l->orden,
        'nombre' => $l->nombre,
        'json'   => (string) $l->marcas_json,
        'png'    => $l->marcas_png ?: null,
    ], $laminas),
    'api'          => [
        'guardar' => '/admin/editor/api/guardar',
        'imagen'  => '/admin/editor/api/imagen',
        'pdf'     => '/admin/editor/api/pdf',
    ],
];

$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';
$GLOBALS['scriptsPagina'][] = '/public/vendor/herramientas/konva.min.js';
$GLOBALS['scriptsPagina'][] = '/public/js/editor.js?v=' . filemtime(__DIR__ . '/../../../public/js/editor.js');

template('header', [
    'titulo'            => $documento->nombre . ' · Editor',
    'subtitulo'         => 'Herramientas personales',
    'mostrarEncabezado' => false,
]);

/**
 * Iconos geométricos de la galería de formas (24 × 24, mismo trazo que
 * el resto de la interfaz). Se usan aquí porque Material Symbols no
 * tiene todos los polígonos que ofrece el editor.
 */
$svgForma = fn(string $d, string $extra = '') => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ' . $extra . '>' . $d . '</svg>';
$formas = [
    'pentagono'       => ['Pentágono', '<path d="M12 3l9 7-3.5 11h-11L3 10z"/>'],
    'hexagono'        => ['Hexágono', '<path d="M7 4h10l5 8-5 8H7l-5-8z"/>'],
    'estrella'        => ['Estrella', '<path d="M12 3l2.8 5.9 6.2.9-4.5 4.4 1.1 6.3L12 17.5l-5.6 3 1.1-6.3L3 9.8l6.2-.9z"/>'],
    'estrella4'       => ['Estrella de 4 puntas', '<path d="M12 2l2.6 7.4L22 12l-7.4 2.6L12 22l-2.6-7.4L2 12l7.4-2.6z"/>'],
    'estrella6'       => ['Estrella de 6 puntas', '<path d="M12 2l3 6.5 7-.5-3.5 6 3.5 6-7-.5L12 22l-3-6.5-7 .5 3.5-6L2 8l7 .5z"/>'],
    'estrella8'       => ['Estrella de 8 puntas', '<path d="M12 2l2 4.5 4.2-2.7-.7 4.9 4.9.7-2.7 4.2L22 12l-4.5 2 2.7 4.2-4.9.7.7 4.9-4.2-2.7L12 22l-2-4.5-4.2 2.7.7-4.9-4.9-.7L4.3 10 2 12l4.5-2-2.7-4.2 4.9-.7-.7-4.9L10 4.5z"/>'],
    'cruz'            => ['Cruz (+)', '<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>'],
    'equis'           => ['Equis (×)', '<path d="M5 5l14 14M19 5L5 19"/>'],
    'check'           => ['Palomita', '<path d="M4 13l5 5L20 6" stroke-width="3"/>'],
    'flecha-bloque'   => ['Flecha de bloque', '<path d="M3 9h9V4l9 8-9 8v-5H3z"/>'],
    'chevron'         => ['Chevron', '<path d="M4 4h9l7 8-7 8H4l7-8z"/>'],
    'trapecio'        => ['Trapecio', '<path d="M7 5h10l4 14H3z"/>'],
    'octagono'        => ['Octágono', '<path d="M8 3h8l5 5v8l-5 5H8l-5-5V8z"/>'],
    'paralelogramo'   => ['Paralelogramo', '<path d="M8 5h13l-5 14H3z"/>'],
    'triangulo-rect'  => ['Triángulo rectángulo', '<path d="M4 4v16h16z"/>'],
    'casa'            => ['Casa', '<path d="M12 3l9 6.5V20H3V9.5z"/>'],
    'corazon'         => ['Corazón', '<path d="M12 20.5C4 14.5 3 10.5 3 8.5A4.8 4.8 0 0 1 12 6.6 4.8 4.8 0 0 1 21 8.5c0 2-1 6-9 12z"/>'],
    'luna'            => ['Luna creciente', '<path d="M16 3a9 9 0 1 0 5 15 7.5 7.5 0 0 1-5-15z"/>'],
    'rayo'            => ['Rayo', '<path d="M14 2L5 13h6l-2 9 9-12h-6z"/>'],
    'gota'            => ['Gota', '<path d="M12 3C8 9 5 12 5 15.5a7 7 0 0 0 14 0C19 12 16 9 12 3z"/>'],
    'forma-l'         => ['Forma en L', '<path d="M5 4h6v9h8v7H5z"/>'],
    'forma-t'         => ['Forma en T', '<path d="M3 4h18v6h-6v10H9V10H3z"/>'],
    'forma-u'         => ['Forma en U', '<path d="M3 4h6v10h6V4h6v16H3z"/>'],
    'globo'           => ['Globo de texto', '<path d="M3 4h18v12h-9l-4 5v-5H3z"/>'],
    'bandera'         => ['Bandera', '<path d="M5 3v18M5 4h15l-4 5 4 5H5"/>'],
];
?>

<section class="herr ed-pagina" id="editor">
    <div class="ed-barra">
        <div class="ed-barra__izq">
            <a href="/admin/editor" class="btn btn--texto btn--sm ed-barra__volver" title="Volver a los documentos">
                <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
            </a>
            <input type="text" id="ed-nombre" class="ed-nombre" maxlength="150"
                   value="<?= s($documento->nombre) ?>" aria-label="Nombre del documento">
            <span id="ed-estado" class="ed-estado text-sm text-muted" role="status"></span>
        </div>
        <div class="ed-barra__der">
            <span class="ed-historial" role="group" aria-label="Historial">
                <button type="button" class="btn btn--texto btn--sm" id="ed-deshacer" title="Deshacer (Ctrl+Z)" aria-label="Deshacer">
                    <span class="material-symbols-outlined" aria-hidden="true">undo</span>
                </button>
                <button type="button" class="btn btn--texto btn--sm" id="ed-rehacer" title="Rehacer (Ctrl+Y)" aria-label="Rehacer">
                    <span class="material-symbols-outlined" aria-hidden="true">redo</span>
                </button>
            </span>
            <span class="ed-zoom">
                <button type="button" class="btn btn--texto btn--sm" data-zoom="-1" title="Alejar">−</button>
                <button type="button" class="btn btn--texto btn--sm" id="ed-zoom-valor" title="Ajustar a la ventana">100%</button>
                <button type="button" class="btn btn--texto btn--sm" data-zoom="1" title="Acercar">+</button>
            </span>
            <button type="button" id="ed-panel-toggle" class="btn btn--texto btn--sm" title="Mostrar u ocultar el panel lateral" aria-pressed="true">
                <span class="material-symbols-outlined" aria-hidden="true">dock_to_right</span>
            </button>
            <button type="button" id="ed-maximizar" class="btn btn--texto btn--sm" title="Área de trabajo ampliada" aria-pressed="false">
                <span class="material-symbols-outlined" aria-hidden="true">fullscreen</span>
            </button>
            <a href="/admin/editor/ver?id=<?= (int) $documento->id ?>" class="btn btn--texto btn--sm" title="Vista de lectura">
                <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
            </a>
            <details class="gr-descargar">
                <summary class="btn btn--texto btn--sm">
                    <span class="material-symbols-outlined" aria-hidden="true">ios_share</span> Exportar
                </summary>
                <div class="gr-descargar-menu">
                    <button type="button" data-exportar="pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</button>
                    <button type="button" data-exportar="imprimir"><span class="material-symbols-outlined">print</span> Imprimir</button>
                    <button type="button" data-exportar="png"><span class="material-symbols-outlined">image</span> PNG de esta lámina</button>
                </div>
            </details>
            <button type="button" id="ed-guardar" class="btn btn--primario btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true">save</span> Guardar
            </button>
        </div>
    </div>

    <div class="ed-opciones" id="ed-opciones" role="toolbar" aria-label="Herramientas de dibujo">
        <div class="ed-grupo ed-grupo--rotulo" role="group" aria-label="Selección">
            <span class="ed-grupo__rotulo">Selección</span>
            <button type="button" class="ed-herr" data-herr="select" title="Seleccionar / mover (V). Mayús o Ctrl + clic suma objetos; arrastra en vacío para seleccionar varios" aria-label="Seleccionar">
                <span class="material-symbols-outlined">near_me</span>
            </button>
            <button type="button" class="ed-herr" data-herr="paneo" title="Mover el lienzo (H). Arrastra para desplazarte; rueda: desplazar; Ctrl+rueda: acercar" aria-label="Mover el lienzo">
                <span class="material-symbols-outlined">pan_tool</span>
            </button>
            <button type="button" class="ed-herr" data-herr="multi" title="Selección múltiple: arrastra un recuadro" aria-label="Selección múltiple">
                <span class="material-symbols-outlined">select_all</span>
            </button>
            <button type="button" class="ed-herr" data-herr="medir" title="Medir: arrastra entre dos puntos. La unidad se elige en Propiedades" aria-label="Medir">
                <span class="material-symbols-outlined">straighten</span>
            </button>
        </div>

        <div class="ed-grupo ed-grupo--rotulo" role="group" aria-label="Líneas">
            <span class="ed-grupo__rotulo">Líneas</span>
            <button type="button" class="ed-herr" data-herr="linea" title="Línea continua (L)" aria-label="Línea continua">
                <span class="material-symbols-outlined">horizontal_rule</span>
            </button>
            <button type="button" class="ed-herr" data-herr="linea-dash" title="Línea discontinua" aria-label="Línea discontinua">
                <span class="material-symbols-outlined">more_horiz</span>
            </button>
            <button type="button" class="ed-herr" data-herr="linea-dashdot" title="Línea trazo y punto" aria-label="Línea trazo y punto">
                <span class="material-symbols-outlined">density_medium</span>
            </button>
            <button type="button" class="ed-herr" data-herr="linea-dot" title="Línea punteada" aria-label="Línea punteada">
                <span class="material-symbols-outlined">blur_on</span>
            </button>
            <button type="button" class="ed-herr" data-herr="polilinea" title="Polilínea: clic en cada punto; doble clic o Enter para terminar" aria-label="Polilínea">
                <span class="material-symbols-outlined">polyline</span>
            </button>
            <button type="button" class="ed-herr" data-herr="lapiz" title="Lápiz / trazo libre (P)" aria-label="Trazo libre">
                <span class="material-symbols-outlined">draw</span>
            </button>
            <button type="button" class="ed-herr" data-herr="flecha" title="Flecha (F)" aria-label="Flecha">
                <span class="material-symbols-outlined">arrow_right_alt</span>
            </button>
            <button type="button" class="ed-herr" data-herr="flecha-doble" title="Flecha doble" aria-label="Flecha doble">
                <span class="material-symbols-outlined">swap_horiz</span>
            </button>
        </div>

        <div class="ed-grupo ed-grupo--rotulo" role="group" aria-label="Formas">
            <span class="ed-grupo__rotulo">Formas</span>
            <button type="button" class="ed-herr" data-herr="rect" title="Rectángulo (R)" aria-label="Rectángulo">
                <span class="material-symbols-outlined">rectangle</span>
            </button>
            <button type="button" class="ed-herr" data-herr="rrect" title="Rectángulo redondeado" aria-label="Rectángulo redondeado">
                <span class="material-symbols-outlined">rounded_corner</span>
            </button>
            <button type="button" class="ed-herr" data-herr="elipse" title="Elipse / círculo (O)" aria-label="Elipse">
                <span class="material-symbols-outlined">circle</span>
            </button>
            <button type="button" class="ed-herr" data-herr="triangulo" title="Triángulo" aria-label="Triángulo">
                <span class="material-symbols-outlined">change_history</span>
            </button>
            <button type="button" class="ed-herr" data-herr="rombo" title="Rombo" aria-label="Rombo">
                <span class="material-symbols-outlined">diamond</span>
            </button>
            <button type="button" class="ed-herr" data-herr="zona" title="Zona translúcida" aria-label="Zona translúcida">
                <span class="material-symbols-outlined">blur_on</span>
            </button>
            <details class="ed-mas" id="ed-mas">
                <summary class="ed-herr" title="Más formas" aria-label="Más formas">
                    <span class="material-symbols-outlined">category</span>
                </summary>
                <div class="ed-mas__panel" role="group" aria-label="Galería de formas">
                    <strong class="ed-mas__titulo">Galería de formas</strong>
                    <div class="ed-mas__grid">
                        <?php foreach ($formas as $clave => [$nombreForma, $trazo]) : ?>
                            <button type="button" data-herr="<?= s($clave) ?>" title="<?= s($nombreForma) ?>" aria-label="<?= s($nombreForma) ?>">
                                <?= $svgForma($trazo) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>
        </div>

        <div class="ed-grupo ed-grupo--rotulo" role="group" aria-label="Insertar">
            <span class="ed-grupo__rotulo">Insertar</span>
            <button type="button" class="ed-herr" data-herr="texto" title="Texto (T)" aria-label="Texto">
                <span class="material-symbols-outlined">text_fields</span>
            </button>
            <button type="button" class="ed-herr" data-herr="tabla" title="Tabla: arrastra para dibujarla; doble clic en una celda para escribir" aria-label="Tabla">
                <span class="material-symbols-outlined">table_chart</span>
            </button>
            <button type="button" class="ed-herr" data-herr="pin" title="Círculo numerado" aria-label="Círculo numerado">
                <span class="material-symbols-outlined">pin_drop</span>
            </button>
            <button type="button" class="ed-herr" data-herr="restr" title="Rombo de restricción numerado" aria-label="Rombo de restricción">
                <span class="material-symbols-outlined">report</span>
            </button>
            <button type="button" class="ed-herr" data-herr="imagen" title="Insertar imagen (I)" aria-label="Insertar imagen">
                <span class="material-symbols-outlined">image</span>
            </button>
        </div>

        <div class="ed-grupo ed-grupo--vista" role="group" aria-label="Vista">
            <label class="ed-chk" title="Muestra una cuadrícula de referencia"><input type="checkbox" id="ed-cuadricula" checked> Cuadrícula</label>
            <label class="ed-chk" title="Lo que dibujas y mueves se imanta a la cuadrícula y a las guías"><input type="checkbox" id="ed-snap" checked> Imán</label>
            <label class="ed-chk" title="Reglas y guías"><input type="checkbox" id="ed-reglas-on" checked> Reglas</label>
            <label class="ed-chk" title="Muestra las guías y atrae hacia ellas lo que dibujas"><input type="checkbox" id="ed-guias-on" checked> Guías</label>
            <label class="ed-chk" title="Líneas, flechas y polilíneas sólo en horizontal o vertical (F8)"><input type="checkbox" id="ed-orto"> Ortogonal <kbd>F8</kbd></label>
        </div>
    </div>

    <div class="ed-app" id="ed-app">
        <div class="ed-lienzo" id="ed-lienzo">
            <canvas id="ed-regla-h" class="ed-regla ed-regla--h" aria-hidden="true"></canvas>
            <canvas id="ed-regla-v" class="ed-regla ed-regla--v" aria-hidden="true"></canvas>
            <span id="ed-regla-esq" class="ed-regla-esq" aria-hidden="true">px</span>
            <div id="ed-guias" class="ed-guias" aria-hidden="true"></div>
            <div class="ed-lienzo__scroll" id="ed-scroll">
                <div class="ed-lienzo__marco" id="ed-marco">
                    <div id="ed-stage"></div>
                </div>
            </div>
        </div>

        <aside class="ed-lateral" id="ed-lateral" aria-label="Propiedades y capas">
            <div class="ed-tabs" role="tablist" aria-label="Paneles del editor">
                <button type="button" role="tab" class="activa" data-tab="props" aria-selected="true">Propiedades</button>
                <button type="button" role="tab" data-tab="capas" aria-selected="false">Capas y guías</button>
            </div>

            <div class="ed-panel" data-panel="props">
                <div id="ed-sin-seleccion" class="ed-props__vacio">
                    <strong>Sin selección</strong>
                    <p class="text-sm text-muted">Elige una herramienta y dibuja sobre la hoja. Haz clic en un objeto para editarlo; arrastra en vacío para seleccionar varios.</p>

                    <div class="ed-prop-grupo">
                        <h4>Fondo del documento</h4>
                        <label class="ed-campo">Color del papel
                            <input type="color" id="ed-fondo-color" value="<?= s($documento->fondo_color) ?>">
                        </label>
                        <label class="ed-campo">Trama
                            <select id="ed-fondo">
                                <?php foreach (\Model\EditorDocumento::FONDOS as $k => $n) : if ($k === 'imagen') continue; ?>
                                    <option value="<?= s($k) ?>"<?= $documento->fondo === $k ? ' selected' : '' ?>><?= s($n) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="ed-fila">
                            <label class="ed-campo">Separación
                                <input type="number" id="ed-fondo-paso" min="8" max="120" value="<?= (int) ($configFondo['paso'] ?? 40) ?>">
                            </label>
                            <label class="ed-campo">Color de trama
                                <input type="color" id="ed-fondo-linea" value="<?= s($configFondo['color'] ?? '#e2e8f0') ?>">
                            </label>
                        </div>
                    </div>

                    <div class="ed-prop-grupo">
                        <h4>Atajos</h4>
                        <p class="text-sm text-muted ed-atajos">
                            V seleccionar · H mover lienzo · T texto · R rectángulo · O elipse · L línea · F flecha · P lápiz · I imagen<br>
                            Supr borrar · Ctrl+Z/Y · Ctrl+S guardar · Ctrl+D duplicar · Ctrl+G agrupar<br>
                            Rueda acerca · Ctrl+rueda acerca en el cursor · En modo mano la rueda desplaza<br>
                            Flechas mueven 1 px (Mayús: 10 px) · F8 ortogonal · Esc cancela
                        </p>
                    </div>
                </div>

                <div id="ed-con-seleccion" hidden>
                    <div class="ed-sel-cab">
                        <strong id="ed-sel-titulo">Objeto</strong>
                        <span id="ed-sel-info" class="text-sm text-muted"></span>
                    </div>

                    <div id="ed-grupo-geometria" class="ed-prop-grupo">
                        <h4>Posición y tamaño</h4>
                        <div class="ed-fila">
                            <label class="ed-campo">X <input type="number" id="ed-p-x" step="1"></label>
                            <label class="ed-campo">Y <input type="number" id="ed-p-y" step="1"></label>
                        </div>
                        <div class="ed-fila" id="ed-fila-tamano">
                            <label class="ed-campo">Ancho <input type="number" id="ed-p-w" min="1" step="1"></label>
                            <label class="ed-campo">Alto <input type="number" id="ed-p-h" min="1" step="1"></label>
                        </div>
                        <div class="ed-fila">
                            <label class="ed-campo">Giro <input type="number" id="ed-p-rot" step="1"></label>
                            <label class="ed-campo">Opacidad <input type="number" id="ed-p-op" min="0" max="100" step="5"></label>
                        </div>
                    </div>

                    <div id="ed-grupo-texto" class="ed-prop-grupo" hidden>
                        <h4>Texto</h4>
                        <label class="ed-campo">Contenido
                            <textarea id="ed-p-texto" rows="3"></textarea>
                        </label>
                        <div class="ed-fila">
                            <label class="ed-campo">Fuente
                                <select id="ed-p-fuente">
                                    <?php foreach (\Classes\Redactor::FUENTES as $f) : ?>
                                        <option value="<?= s($f) ?>"><?= s($f) ?></option>
                                    <?php endforeach; ?>
                                    <option value="DejaVu Sans">DejaVu Sans</option>
                                    <option value="monospace">Monoespaciada</option>
                                </select>
                            </label>
                            <label class="ed-campo">Tamaño <input type="number" id="ed-p-fuente-tam" min="6" max="400" step="1"></label>
                        </div>
                        <div class="ed-fila">
                            <label class="ed-campo">Interlineado <input type="number" id="ed-p-interlineado" min=".6" max="4" step=".1"></label>
                            <label class="ed-campo">Espaciado <input type="number" id="ed-p-espaciado" min="-20" max="80" step="1"></label>
                        </div>
                        <div class="ed-botones ed-botones--estilo">
                            <button type="button" class="btn btn--texto btn--sm" data-estilo="bold" title="Negrita (Ctrl+B)"><strong>B</strong></button>
                            <button type="button" class="btn btn--texto btn--sm" data-estilo="italic" title="Cursiva (Ctrl+I)"><em>I</em></button>
                            <button type="button" class="btn btn--texto btn--sm" data-estilo="underline" title="Subrayado (Ctrl+U)"><u>U</u></button>
                            <button type="button" class="btn btn--texto btn--sm" data-estilo="line-through" title="Tachado"><s>S</s></button>
                            <span class="ed-sep-v"></span>
                            <button type="button" class="btn btn--texto btn--sm" data-alinear="left" title="Izquierda"><span class="material-symbols-outlined">format_align_left</span></button>
                            <button type="button" class="btn btn--texto btn--sm" data-alinear="center" title="Centrar"><span class="material-symbols-outlined">format_align_center</span></button>
                            <button type="button" class="btn btn--texto btn--sm" data-alinear="right" title="Derecha"><span class="material-symbols-outlined">format_align_right</span></button>
                            <button type="button" class="btn btn--texto btn--sm" data-alinear="justify" title="Justificar"><span class="material-symbols-outlined">format_align_justify</span></button>
                        </div>
                    </div>

                    <div id="ed-grupo-apariencia" class="ed-prop-grupo">
                        <h4>Apariencia</h4>
                        <div class="ed-fila" id="ed-fila-relleno">
                            <label class="ed-campo">Relleno <input type="color" id="ed-p-fill"></label>
                            <label class="ed-campo">Borde <input type="color" id="ed-p-stroke"></label>
                        </div>
                        <label class="ed-chk ed-chk--fila" id="ed-fila-sin-relleno"><input type="checkbox" id="ed-p-sin-relleno"> Sin relleno</label>
                        <div class="ed-fila">
                            <label class="ed-campo" id="ed-fila-grosor">Grosor
                                <input type="number" id="ed-p-grosor" min="0" max="80" step=".5">
                            </label>
                            <label class="ed-campo" id="ed-fila-estilo">Estilo de línea
                                <select id="ed-p-dash">
                                    <option value="solid">Continua</option>
                                    <option value="dash">Discontinua</option>
                                    <option value="dashdot">Trazo y punto</option>
                                    <option value="dot">Punteada</option>
                                </select>
                            </label>
                        </div>
                        <div class="ed-fila" id="ed-fila-dash">
                            <label class="ed-campo">Largo del trazo <small class="text-muted">(× grosor)</small>
                                <input type="number" id="ed-p-dash-l" min=".2" max="60" step=".5">
                            </label>
                            <label class="ed-campo">Separación <small class="text-muted">(× grosor)</small>
                                <input type="number" id="ed-p-dash-g" min=".2" max="60" step=".5">
                            </label>
                        </div>
                    </div>

                    <div id="ed-grupo-tabla" class="ed-prop-grupo" hidden>
                        <h4>Tabla</h4>
                        <div class="ed-fila">
                            <label class="ed-campo">Filas <input type="number" id="ed-p-filas" min="1" max="40"></label>
                            <label class="ed-campo">Columnas <input type="number" id="ed-p-columnas" min="1" max="16"></label>
                        </div>
                        <div class="ed-fila">
                            <label class="ed-campo">Ancho de columna <input type="number" id="ed-p-col-w" min="10" step="1"></label>
                            <label class="ed-campo">Alto de fila <input type="number" id="ed-p-fila-h" min="8" step="1"></label>
                        </div>
                        <label class="ed-chk ed-chk--fila"><input type="checkbox" id="ed-p-encabezado"> Primera fila como encabezado</label>
                        <div class="ed-fila">
                            <label class="ed-campo">Fondo del encabezado <input type="color" id="ed-p-hfill"></label>
                            <label class="ed-campo">Color del texto <input type="color" id="ed-p-texto-color"></label>
                        </div>
                        <p class="text-sm text-muted">Doble clic en una celda para escribir; Tab pasa a la siguiente.</p>
                    </div>

                    <div id="ed-grupo-pin" class="ed-prop-grupo" hidden>
                        <h4>Marcador</h4>
                        <label class="ed-campo">Número <input type="number" id="ed-p-numero" min="1" max="999"></label>
                        <p class="text-sm text-muted">Doble clic sobre el marcador para cambiar el número.</p>
                    </div>

                    <div id="ed-grupo-medida" class="ed-prop-grupo" hidden>
                        <h4>Medida</h4>
                        <label class="ed-campo">Unidad
                            <select id="ed-p-unidad">
                                <option value="px">píxeles</option>
                                <option value="mm">milímetros</option>
                                <option value="cm">centímetros</option>
                                <option value="m">metros</option>
                                <option value="in">pulgadas</option>
                            </select>
                        </label>
                        <p class="text-sm">Longitud: <strong id="ed-p-longitud">—</strong></p>
                    </div>

                    <div class="ed-prop-grupo">
                        <h4>Organización</h4>
                        <label class="ed-campo" id="ed-fila-capa">Capa
                            <select id="ed-p-capa"></select>
                        </label>
                        <button type="button" class="btn btn--texto btn--sm btn--bloque" id="ed-mover-capa">
                            <span class="material-symbols-outlined">drive_file_move</span> Mover a la capa activa
                        </button>
                        <div class="ed-botones">
                            <button type="button" class="btn btn--texto btn--sm" id="ed-al-frente" title="Traer al frente">
                                <span class="material-symbols-outlined">flip_to_front</span> Frente</button>
                            <button type="button" class="btn btn--texto btn--sm" id="ed-al-fondo" title="Enviar al fondo">
                                <span class="material-symbols-outlined">flip_to_back</span> Fondo</button>
                            <button type="button" class="btn btn--texto btn--sm" id="ed-duplicar" title="Duplicar (Ctrl+D)">
                                <span class="material-symbols-outlined">content_copy</span> Duplicar</button>
                        </div>
                    </div>

                    <div id="ed-panel-alinear" class="ed-prop-grupo" hidden>
                        <h4>Alinear y distribuir <small class="text-muted" id="ed-sel-n"></small></h4>
                        <label class="ed-campo">Alinear respecto a
                            <select id="ed-al-ref">
                                <option value="sel">La selección</option>
                                <option value="pag">La página</option>
                                <option value="prim">El primero</option>
                                <option value="ult">El último</option>
                            </select>
                        </label>
                        <div class="ed-al-fila" role="group" aria-label="Alinear">
                            <button type="button" data-alinear-sel="izq" title="Alinear a la izquierda"><span class="material-symbols-outlined">align_horizontal_left</span></button>
                            <button type="button" data-alinear-sel="cx" title="Centrar horizontalmente"><span class="material-symbols-outlined">align_horizontal_center</span></button>
                            <button type="button" data-alinear-sel="der" title="Alinear a la derecha"><span class="material-symbols-outlined">align_horizontal_right</span></button>
                            <button type="button" data-alinear-sel="sup" title="Alinear arriba"><span class="material-symbols-outlined">align_vertical_top</span></button>
                            <button type="button" data-alinear-sel="cy" title="Centrar verticalmente"><span class="material-symbols-outlined">align_vertical_center</span></button>
                            <button type="button" data-alinear-sel="inf" title="Alinear abajo"><span class="material-symbols-outlined">align_vertical_bottom</span></button>
                        </div>
                        <div class="ed-al-fila" role="group" aria-label="Distribuir">
                            <button type="button" data-distribuir="h-ini" title="Distribuir bordes izquierdos"><span class="material-symbols-outlined">format_horizontal_align_left</span></button>
                            <button type="button" data-distribuir="h-cen" title="Distribuir centros horizontales"><span class="material-symbols-outlined">horizontal_distribute</span></button>
                            <button type="button" data-distribuir="h-fin" title="Distribuir bordes derechos"><span class="material-symbols-outlined">format_horizontal_align_right</span></button>
                            <button type="button" data-distribuir="v-ini" title="Distribuir bordes superiores"><span class="material-symbols-outlined">format_vertical_align_top</span></button>
                            <button type="button" data-distribuir="v-cen" title="Distribuir centros verticales"><span class="material-symbols-outlined">vertical_distribute</span></button>
                            <button type="button" data-distribuir="v-fin" title="Distribuir bordes inferiores"><span class="material-symbols-outlined">format_vertical_align_bottom</span></button>
                        </div>
                        <div class="ed-botones">
                            <button type="button" class="btn btn--texto btn--sm" id="ed-agrupar" title="Agrupar (Ctrl+G)">
                                <span class="material-symbols-outlined">workspaces</span> Agrupar</button>
                            <button type="button" class="btn btn--texto btn--sm" id="ed-desagrupar" title="Desagrupar (Ctrl+Mayús+G)">
                                <span class="material-symbols-outlined">dashboard</span> Desagrupar</button>
                        </div>
                    </div>

                    <button type="button" class="btn btn--peligro btn--sm btn--bloque ed-eliminar" id="ed-eliminar-sel">
                        <span class="material-symbols-outlined">delete</span> Eliminar selección
                    </button>
                </div>
            </div>

            <div class="ed-panel" data-panel="capas" hidden>
                <div class="ed-capas-cab">
                    <h4>Capas</h4>
                    <button type="button" id="ed-capa-nueva" class="btn btn--primario btn--sm">
                        <span class="material-symbols-outlined">add</span> Nueva
                    </button>
                </div>
                <ul id="ed-capas-lista" class="ed-capas-lista"></ul>
                <p class="text-sm text-muted">
                    Lo que dibujas cae en la capa activa (resaltada). El ojo la oculta también en las
                    exportaciones; el candado impide tocar sus objetos. Doble clic en el nombre para renombrarla.
                </p>

                <div class="ed-capas-cab ed-capas-cab--guia">
                    <h4>Guías</h4>
                    <span class="ed-guias-btns">
                        <button type="button" id="ed-guia-v" class="btn btn--texto btn--sm" title="Guía vertical al centro">+ V</button>
                        <button type="button" id="ed-guia-h" class="btn btn--texto btn--sm" title="Guía horizontal al centro">+ H</button>
                        <button type="button" id="ed-guias-x" class="btn btn--texto btn--sm" title="Quitar todas las guías">
                            <span class="material-symbols-outlined">delete_sweep</span>
                        </button>
                    </span>
                </div>
                <ul id="ed-guias-lista" class="ed-guias-lista"></ul>
                <p class="text-sm text-muted">
                    Arrastra desde la regla superior o izquierda para crear guías. Muévelas con el ratón;
                    suéltalas sobre la regla para quitarlas. No salen en el PNG ni en el PDF.
                </p>
            </div>
        </aside>
    </div>

    <div class="ed-paginas" aria-label="Láminas">
        <div class="ed-paginas__lista" id="ed-paginas"></div>
        <div class="ed-paginas__acciones">
            <button type="button" class="btn btn--texto btn--sm" id="ed-pagina-nueva" title="Agregar lámina">
                <span class="material-symbols-outlined">add</span> Lámina
            </button>
            <button type="button" class="btn btn--texto btn--sm" id="ed-pagina-copiar" title="Duplicar esta lámina">
                <span class="material-symbols-outlined">content_copy</span> Duplicar
            </button>
            <button type="button" class="btn btn--texto btn--sm" id="ed-pagina-izq" title="Mover a la izquierda">
                <span class="material-symbols-outlined">arrow_back</span>
            </button>
            <button type="button" class="btn btn--texto btn--sm" id="ed-pagina-der" title="Mover a la derecha">
                <span class="material-symbols-outlined">arrow_forward</span>
            </button>
            <button type="button" class="btn btn--peligro btn--sm" id="ed-pagina-borrar" title="Eliminar esta lámina">
                <span class="material-symbols-outlined">delete</span>
            </button>
        </div>
    </div>

    <input type="file" id="ed-archivo-img" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
    <script>window.ED_CONFIG = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
</section>

<?php template('footer'); ?>
