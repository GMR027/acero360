<?php
/**
 * views/admin/redactor/abrir.php — Editor de un documento.
 *
 * Hoja contenteditable con barras de formato, corrector ortográfico,
 * comentarios, control de cambios, versiones, plantillas y exportación.
 * Se guarda por AJAX con control de versión.
 */

soloAdmin();

use Model\RedactorDocumento;

$documento = \Model\RedactorDocumento::find((int) ($_GET['id'] ?? 0));
if (!$documento) {
    redirigir('/admin/redactor', 'error', 'El documento no existe o fue eliminado.');
}

$documento->contenido = \Classes\Sheetflow::refrescarHtml((string) $documento->contenido);
$departamentos = \Model\RedactorDocumento::departamentos();
$usuario = (string) ($_SESSION['nombre'] ?? '');

/* Imágenes de los documentos del Editor para insertarlas como gráficos. */
$imagenesEditor = [];
try {
    foreach (\Model\EditorDocumento::todos() as $ed) {
        foreach ($ed->laminas() as $l) {
            if (!empty($l->marcas_png)) {
                $imagenesEditor[] = ['nombre' => $ed->nombre . ' · ' . ($l->nombre !== '' ? $l->nombre : 'Lámina ' . $l->orden), 'src' => '/public/uploads/vplan/' . $l->marcas_png];
            }
        }
    }
} catch (\Throwable $e) {
}

$destinos = [
    ['grupo' => 'Secciones', 'etiqueta' => 'Notas personales', 'url' => '/admin/notas'],
    ['grupo' => 'Secciones', 'etiqueta' => 'Redactor', 'url' => '/admin/redactor'],
    ['grupo' => 'Secciones', 'etiqueta' => 'SheetFlow', 'url' => '/admin/sheetflow'],
    ['grupo' => 'Secciones', 'etiqueta' => 'Editor', 'url' => '/admin/editor'],
    ['grupo' => 'Secciones', 'etiqueta' => 'Cotizaciones', 'url' => '/admin/cotizaciones'],
    ['grupo' => 'Secciones', 'etiqueta' => 'Clientes', 'url' => '/admin/clientes'],
    ['grupo' => 'Secciones', 'etiqueta' => 'Productos', 'url' => '/admin/productos'],
];

$config = [
    'id'          => (int) $documento->id,
    'proyecto'    => 0,
    'version'     => (string) $documento->actualizado,
    'palabras'    => \Model\RedactorDocumento::palabras(),
    'comentarios' => json_decode((string) $documento->comentarios, true) ?: [],
    'rastrear'    => (bool) $documento->rastrear,
    'usuario'     => $usuario,
    'firmas'      => \Classes\RedactorFirmas::deUsuario((int) ($_SESSION['id'] ?? 0)),
    'sellos'      => \Classes\RedactorFirmas::sellos($documento),
    'maxFirmas'   => \Classes\RedactorFirmas::MAX_POR_USUARIO,
    'destinos'    => $destinos,
    'imagenes'    => $imagenesEditor,
    'simbolos'    => [],
    'fuentes'     => \Classes\Redactor::FUENTES,
    'papeles'     => array_map(fn($p) => [$p[1], $p[2]], \Model\RedactorDocumento::PAPELES),
    'api'         => [
        'guardar'          => '/admin/redactor/api/guardar',
        'imagen'           => '/admin/redactor/api/imagen',
        'palabra'          => '/admin/redactor/api/palabra',
        'firma'            => '/admin/redactor/api/firma',
        'firmaBorrar'      => '/admin/redactor/api/firma-borrar',
        'sellar'           => '/admin/redactor/api/sellar',
        'plantilla'        => '/admin/redactor/api/plantilla',
        'versiones'        => '/admin/redactor/api/versiones',
        'version'          => '/admin/redactor/api/version',
        'programa'         => '/admin/redactor/api/programa',
        'hoja'             => '/admin/redactor/api/hoja',
        'exportarSeleccion' => '/admin/redactor/api/exportar-seleccion',
    ],
    'descargar'   => '/admin/redactor/descargar?id=' . (int) $documento->id,
    'dic'         => '/public/vendor/herramientas/redactor/',
];

$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';
$GLOBALS['scriptsPagina'][] = '/public/js/pantone.js?v=' . filemtime(__DIR__ . '/../../../public/js/pantone.js');
$GLOBALS['scriptsPagina'][] = '/public/vendor/herramientas/redactor/typo.js';
$GLOBALS['scriptsPagina'][] = '/public/vendor/herramientas/redactor.js?v=' . filemtime(__DIR__ . '/../../../public/vendor/herramientas/redactor.js');
$GLOBALS['scriptsPagina'][] = '/public/vendor/herramientas/redactor-extras.js?v=' . filemtime(__DIR__ . '/../../../public/vendor/herramientas/redactor-extras.js');

$btn = fn(string $cmd, string $icono, string $titulo) => '<button type="button" class="rd-b" data-cmd="' . $cmd . '" title="' . s($titulo) . '" aria-label="' . s($titulo) . '"><i class="fa-solid ' . $icono . '"></i></button>';

template('header', [
    'titulo'    => $documento->nombre . ' · Redactor',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="herr" id="redactor">
    <div class="encabezado-pagina">
        <div>
            <h1 class="titulo-2" id="rd-titulo"><?= s($documento->nombre) ?></h1>
            <p class="texto-secundario">Redactor · documento compartido</p>
        </div>
        <div class="encabezado-pagina__acciones">
            <span id="rd-estado" class="text-sm text-muted" role="status"></span>
            <button type="button" id="rd-guardar" class="btn btn--primario">
                <span class="material-symbols-outlined" aria-hidden="true">save</span> Guardar
            </button>
            <details class="gr-descargar">
                <summary class="btn btn--texto">
                    <span class="material-symbols-outlined" aria-hidden="true">ios_share</span> Exportar
                </summary>
                <div class="gr-descargar-menu">
                    <label class="rd-opc"><input type="checkbox" id="rd-x-cambios"> Mostrar cambios marcados</label>
                    <label class="rd-opc"><input type="checkbox" id="rd-x-coment"> Incluir comentarios al final</label>
                    <label class="rd-opc"><input type="checkbox" id="rd-x-firmas" checked> Incluir firmas electrónicas</label>
                    <hr>
                    <button type="button" data-exportar="docx"><span class="material-symbols-outlined">description</span> Word (.docx)</button>
                    <button type="button" data-exportar="odt"><span class="material-symbols-outlined">article</span> LibreOffice (.odt)</button>
                    <button type="button" data-exportar="pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</button>
                    <button type="button" data-exportar="imprimir"><span class="material-symbols-outlined">print</span> Imprimir</button>
                </div>
            </details>
            <a href="/admin/redactor/ver?id=<?= (int) $documento->id ?>" class="btn btn--texto">
                <span class="material-symbols-outlined" aria-hidden="true">visibility</span> Ver
            </a>
            <a href="/admin/redactor" class="btn btn--texto">← Documentos</a>
        </div>
    </div>

<div class="card rd-datos">
    <label>Nombre <input type="text" id="rd-nombre" maxlength="150" value="<?php echo s($documento->nombre); ?>"></label>
    <label>Fecha <input type="date" id="rd-fecha" value="<?php echo s($documento->fecha); ?>"></label>
    <label>Departamento <input type="text" id="rd-depto" maxlength="100" list="rd-deptos" value="<?php echo s($documento->departamento); ?>" autocomplete="off"></label>
    <datalist id="rd-deptos"><?php foreach ($departamentos as $dp): ?><option value="<?php echo s($dp); ?>"><?php endforeach; ?></datalist>
</div>

<div class="rd-pestanas" id="rd-pestanas" role="tablist" aria-label="Pestañas de herramientas">
    <button type="button" role="tab" class="activo" data-pest="inicio"><i class="fa-solid fa-house"></i> Inicio</button>
    <button type="button" role="tab" data-pest="insertar"><i class="fa-solid fa-plus"></i> Insertar</button>
    <button type="button" role="tab" data-pest="diseno"><i class="fa-regular fa-file"></i> Diseño</button>
    <button type="button" role="tab" data-pest="revision"><i class="fa-regular fa-comments"></i> Revisión</button>
    <button type="button" role="tab" data-pest="herramientas"><i class="fa-solid fa-screwdriver-wrench"></i> Herramientas</button>
</div>
<div class="rd-barra" id="rd-barra" role="toolbar" aria-label="Herramientas de redacción">
    <div class="rd-grupo-b" data-pest="inicio">
        <?php echo $btn('undo', 'fa-rotate-left', 'Deshacer (Ctrl+Z)') . $btn('redo', 'fa-rotate-right', 'Rehacer (Ctrl+Y)'); ?>
        <?php echo $btn('cut', 'fa-scissors', 'Cortar') . $btn('copy', 'fa-copy', 'Copiar') . $btn('paste', 'fa-paste', 'Pegar') . $btn('pastetext', 'fa-i-cursor', 'Pegar sin formato'); ?>
        <button type="button" class="rd-b" id="rd-i-buscar" title="Buscar y reemplazar (Ctrl+F)"><i class="fa-solid fa-magnifying-glass"></i></button>
    </div>
    <div class="rd-grupo-b" data-pest="inicio">
        <button type="button" class="rd-b" id="rd-x-brocha" title="Copiar formato: copia el formato del texto donde está el cursor y se aplica a la siguiente selección"><i class="fa-solid fa-paintbrush"></i> Copiar formato</button>
        <button type="button" class="rd-b" id="rd-x-estilos" title="Estilos propios del proyecto"><i class="fa-solid fa-swatchbook"></i> Mis estilos</button>
    </div>
    <div class="rd-grupo-b" data-pest="inicio">
        <select id="rd-estilo" title="Estilo de párrafo" aria-label="Estilo de párrafo">
            <option value="p">Párrafo</option><option value="h1">Título 1</option><option value="h2">Título 2</option><option value="h3">Título 3</option><option value="h4">Título 4</option><option value="blockquote">Cita</option><option value="pre">Código</option>
        </select>
        <select id="rd-fuente" title="Fuente" aria-label="Fuente"><option value="">Fuente</option><?php foreach ($config['fuentes'] as $f): ?><option value="<?php echo s($f); ?>" style="font-family:'<?php echo s($f); ?>'"><?php echo s($f); ?></option><?php endforeach; ?></select>
        <select id="rd-tamano" title="Tamaño (pt)" aria-label="Tamaño de letra"><option value="">Tam.</option><?php foreach ([8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36, 48, 72] as $t): ?><option value="<?php echo $t; ?>"><?php echo $t; ?></option><?php endforeach; ?></select>
    </div>
    <div class="rd-grupo-b" data-pest="inicio">
        <?php echo $btn('bold', 'fa-bold', 'Negrita (Ctrl+B)') . $btn('italic', 'fa-italic', 'Cursiva (Ctrl+I)') . $btn('underline', 'fa-underline', 'Subrayado (Ctrl+U)') . $btn('strikeThrough', 'fa-strikethrough', 'Tachado') . $btn('subscript', 'fa-subscript', 'Subíndice') . $btn('superscript', 'fa-superscript', 'Superíndice'); ?>
        <label class="rd-color" title="Color de letra"><i class="fa-solid fa-font"></i><input type="color" id="rd-color" value="#000000" aria-label="Color de letra"></label>
        <label class="rd-color" title="Resaltado"><i class="fa-solid fa-highlighter"></i><input type="color" id="rd-resalte" value="#ffff00" aria-label="Color de resaltado"></label>
        <?php echo $btn('removeFormat', 'fa-eraser', 'Quitar formato'); ?>
    </div>
    <div class="rd-grupo-b" data-pest="inicio">
        <?php echo $btn('justifyLeft', 'fa-align-left', 'Alinear a la izquierda') . $btn('justifyCenter', 'fa-align-center', 'Centrar') . $btn('justifyRight', 'fa-align-right', 'Alinear a la derecha') . $btn('justifyFull', 'fa-align-justify', 'Justificar'); ?>
        <?php echo $btn('insertUnorderedList', 'fa-list-ul', 'Viñetas') . $btn('insertOrderedList', 'fa-list-ol', 'Numeración') . $btn('outdent', 'fa-outdent', 'Reducir sangría') . $btn('indent', 'fa-indent', 'Aumentar sangría'); ?>
        <select id="rd-interlineado" title="Interlineado" aria-label="Interlineado"><option value="">Interl.</option><?php foreach (['1', '1.15', '1.5', '2'] as $i): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endforeach; ?></select>
    </div>

    <div class="rd-grupo-b" data-pest="insertar">
        <button type="button" class="rd-b" id="rd-i-tabla" title="Insertar tabla"><i class="fa-solid fa-table"></i> Tabla</button>
        <button type="button" class="rd-b" id="rd-i-imagen" title="Insertar imagen"><i class="fa-regular fa-image"></i> Imagen</button>
        <button type="button" class="rd-b" id="rd-i-figura" title="Insertar figura"><i class="fa-solid fa-shapes"></i> Figura</button>
        <button type="button" class="rd-b" id="rd-x-dibujo" title="Dibujo con las herramientas del Editor: lápiz, líneas, flechas, formas, texto y cuadrícula"><i class="fa-solid fa-pen-ruler"></i> Dibujo</button>
    </div>
    <div class="rd-grupo-b" data-pest="insertar">
        <button type="button" class="rd-b" id="rd-i-enlace" title="Hipervínculo (web o dentro de la plataforma)"><i class="fa-solid fa-link"></i> Vínculo</button>
        <?php echo $btn('unlink', 'fa-link-slash', 'Quitar hipervínculo'); ?>
        <?php echo $btn('insertHorizontalRule', 'fa-minus', 'Línea horizontal'); ?>
        <button type="button" class="rd-b" id="rd-i-salto" title="Salto de página"><i class="fa-solid fa-file-circle-plus"></i> Salto</button>
        <button type="button" class="rd-b" id="rd-i-indice" title="Insertar o actualizar el índice (usa los títulos 1 a 3)"><i class="fa-solid fa-list-ol"></i> Índice</button>
    </div>

    <div class="rd-grupo-b" data-pest="insertar">
        <button type="button" class="rd-b" id="rd-x-nota" title="Nota al pie: inserta la llamada y agrega la nota al final del documento"><i class="fa-solid fa-asterisk"></i> Nota al pie</button>
        <select id="rd-x-leyenda" title="Leyenda numerada automáticamente" aria-label="Leyenda"><option value="">Leyenda</option><option value="Tabla">Tabla</option><option value="Figura">Figura</option></select>
        <button type="button" class="rd-b" id="rd-x-leyendas-act" title="Renumerar todas las leyendas (Tabla 1, Tabla 2… / Figura 1…) y las notas al pie"><i class="fa-solid fa-arrow-down-1-9"></i> Renumerar</button>
        <button type="button" class="rd-b" id="rd-x-refcruz" title="Referencia cruzada a un título o a una leyenda"><i class="fa-solid fa-diagram-project"></i> Ref. cruzada</button>
    </div>
    <div class="rd-grupo-b" data-pest="insertar">
        <button type="button" class="rd-b" id="rd-x-ecuacion" title="Ecuación o fórmula"><i class="fa-solid fa-square-root-variable"></i> Ecuación</button>
        <button type="button" class="rd-b" id="rd-x-caja" title="Cuadro de texto a un lado del texto"><i class="fa-regular fa-note-sticky"></i> Cuadro de texto</button>
        <button type="button" class="rd-b" id="rd-x-portada" title="Portada con plantillas"><i class="fa-regular fa-file-image"></i> Portada</button>
    </div>
    <div class="rd-grupo-b" data-pest="insertar">
        <select id="rd-x-bloque" title="Bloques predefinidos de obra" aria-label="Bloques de obra"><option value="">Bloque de obra</option><option value="acta">Acta de reunión</option><option value="minuta">Minuta</option><option value="bitacora">Bitácora diaria</option><option value="cambio">Orden de cambio</option><option value="asistencia">Lista de asistencia</option></select>
        <button type="button" class="rd-b" id="rd-x-programa" title="Tabla con las partidas y actividades del programa de obra"><i class="fa-solid fa-bars-progress"></i> Tabla del programa</button>
    </div>

    <div class="rd-grupo-b" data-pest="diseno">
        <button type="button" class="rd-b" id="rd-i-pagina" title="Página, márgenes e idioma"><i class="fa-regular fa-file"></i> Página y márgenes</button>
        <button type="button" class="rd-b" id="rd-h-ancho" title="Ajustar el zoom al ancho de la ventana"><i class="fa-solid fa-left-right"></i> Ajustar al ancho</button>
        <button type="button" class="rd-b" id="rd-h-enfoque" title="Modo enfoque: oculta todo menos la hoja (Esc para salir)"><i class="fa-solid fa-expand"></i> Enfoque</button>
    </div>
    <div class="rd-grupo-b" data-pest="diseno">
        <button type="button" class="rd-b" data-herr="sangria" title="Sangría de primera línea (alterna)"><i class="fa-solid fa-arrow-right-to-bracket"></i> Sangría 1.ª línea</button>
        <select id="rd-h-espacio" title="Espacio después de cada párrafo" aria-label="Espacio entre párrafos"><option value="">Espacio ¶</option><?php foreach ([0, 4, 6, 8, 12, 18, 24] as $e): ?><option value="<?php echo $e; ?>"><?php echo $e; ?> pt</option><?php endforeach; ?></select>
        <label class="rd-color" title="Sombreado del párrafo (blanco lo quita)"><i class="fa-solid fa-fill"></i><input type="color" id="rd-h-sombra" value="#fef3c7" aria-label="Sombreado del párrafo"></label>
        <select id="rd-x-borde" title="Borde del párrafo" aria-label="Borde del párrafo"><option value="">Borde ¶</option><option value="ninguno">Sin borde</option><option value="fino">Fino</option><option value="grueso">Grueso</option><option value="discontinuo">Discontinuo</option><option value="doble">Doble</option></select>
        <button type="button" class="rd-b" id="rd-x-capital" title="Letra capital: agranda la primera letra del párrafo (otra vez la quita)"><i class="fa-solid fa-a"></i> Capital</button>
    </div>
    <div class="rd-grupo-b" data-pest="diseno">
        <select id="rd-x-tnum" title="Numerar los títulos 1, 1.1, 1.1.1" aria-label="Numerar títulos"><option value="">Numerar títulos</option><option value="aplicar">Aplicar / actualizar</option><option value="quitar">Quitar numeración</option></select>
        <button type="button" class="rd-b" id="rd-x-multinivel" title="Convierte la lista donde está el cursor en numeración multinivel 1, 1.1, 1.1.1"><i class="fa-solid fa-list-ol"></i> Lista multinivel</button>
        <button type="button" class="rd-b" id="rd-x-encpie" title="Encabezado y pie personalizados, y marca de agua"><i class="fa-regular fa-window-maximize"></i> Encabezado, pie y marca</button>
    </div>

    <div class="rd-grupo-b" data-pest="revision">
        <button type="button" class="rd-b" id="rd-i-comentar" title="Comentar el texto seleccionado"><i class="fa-regular fa-comment-dots"></i> Comentar selección</button>
        <button type="button" class="rd-b" id="rd-i-comentar-clic" title="Modo comentar: haz clic en un párrafo, título, imagen, lista o celda para comentarlo"><i class="fa-solid fa-comment-medical"></i> Comentar con clic</button>
        <button type="button" class="rd-b" id="rd-i-rastrear" title="Control de cambios: lo que escribas o borres queda marcado para revisión"><i class="fa-solid fa-user-pen"></i> Cambios</button>
        <button type="button" class="rd-b" data-x-cambios="aceptar" title="Aceptar todos los cambios pendientes"><i class="fa-solid fa-check-double"></i> Aceptar todos</button>
        <button type="button" class="rd-b" data-x-cambios="rechazar" title="Rechazar todos los cambios pendientes"><i class="fa-solid fa-xmark"></i> Rechazar todos</button>
        <button type="button" class="rd-b" id="rd-x-versiones" title="Historial de versiones: ver, comparar y restaurar"><i class="fa-solid fa-clock-rotate-left"></i> Versiones</button>
        <button type="button" class="rd-b" id="rd-i-revision" title="Panel de comentarios y cambios"><i class="fa-solid fa-table-columns"></i> Panel <span id="rd-n-revision" class="rd-contador" hidden></span></button>
    </div>
    <div class="rd-grupo-b" data-pest="revision">
        <button type="button" class="rd-b" id="rd-i-firma" title="Firmas: manuscrita, bloques de firma y firma electrónica"><i class="fa-solid fa-signature"></i> Firmas</button>
        <button type="button" class="rd-b" id="rd-i-plantilla" title="Guardar este documento como plantilla del proyecto"><i class="fa-regular fa-star"></i> Plantilla</button>
    </div>

    <div class="rd-grupo-b" data-pest="herramientas">
        <button type="button" class="rd-b" data-herr="mayus" title="Convertir la selección a MAYÚSCULAS">AA</button>
        <button type="button" class="rd-b" data-herr="minus" title="Convertir la selección a minúsculas">aa</button>
        <button type="button" class="rd-b" data-herr="capit" title="Poner En Mayúscula Cada Palabra">Aa</button>
        <button type="button" class="rd-b" data-herr="codigo" title="Código en línea (letra monoespaciada)"><i class="fa-solid fa-code"></i></button>
        <button type="button" class="rd-b" data-herr="espacios" title="Quitar espacios dobles (selección o todo el documento)"><i class="fa-solid fa-broom"></i> Espacios</button>
        <button type="button" class="rd-b" data-herr="ordenar" title="Ordenar A→Z los párrafos o elementos de lista seleccionados (otra vez: Z→A)"><i class="fa-solid fa-arrow-down-a-z"></i></button>
    </div>
    <div class="rd-grupo-b" data-pest="herramientas">
        <button type="button" class="rd-b" data-herr="fecha" title="Insertar la fecha de hoy"><i class="fa-regular fa-calendar"></i> Fecha</button>
        <button type="button" class="rd-b" data-herr="hora" title="Insertar la hora actual"><i class="fa-regular fa-clock"></i> Hora</button>
        <select id="rd-h-simbolo" title="Insertar un símbolo" aria-label="Insertar símbolo"><option value="">Símbolo</option><?php foreach (['©', '®', '™', '°', '±', '×', '÷', '≤', '≥', '≠', '≈', '§', '¶', '€', '£', '¥', '→', '←', '↑', '↓', '✓', '✗', '•', '…', '²', '³', 'µ', 'Ø', 'Δ', 'Σ', 'π', '∞'] as $sy): ?><option value="<?php echo s($sy); ?>"><?php echo s($sy); ?></option><?php endforeach; ?></select>
        <button type="button" class="rd-b" data-herr="checklist" title="Lista de verificación: pone ☐ en los párrafos (otra vez ☑ y de nuevo ☐)"><i class="fa-regular fa-square-check"></i> Lista ☐</button>
    </div>
    <div class="rd-grupo-b" data-pest="herramientas">
        <select id="rd-h-nota" title="Cuadro de nota o aviso" aria-label="Cuadro de nota"><option value="">Cuadro de nota</option><option value="info">Información</option><option value="aviso">Advertencia</option><option value="peligro">Peligro</option><option value="ok">Correcto</option></select>
        <select id="rd-h-columnas" title="Diseño en columnas (tabla sin bordes)" aria-label="Columnas"><option value="">Columnas</option><option value="2">2 columnas</option><option value="3">3 columnas</option><option value="4">4 columnas</option></select>
        <select id="rd-h-estado" title="Etiqueta de estado" aria-label="Etiqueta de estado"><option value="">Etiqueta</option><option value="aprobado">Aprobado</option><option value="pendiente">Pendiente</option><option value="rechazado">Rechazado</option><option value="revision">En revisión</option></select>
        <button type="button" class="rd-b" data-herr="ejemplo" title="Insertar texto de ejemplo para probar el formato"><i class="fa-solid fa-paragraph"></i> Texto ejemplo</button>
        <button type="button" class="rd-b" data-herr="estadisticas" title="Palabras, caracteres, párrafos y tiempo de lectura"><i class="fa-solid fa-chart-simple"></i> Estadísticas</button>
    </div>

    <div class="rd-grupo-b" data-pest="herramientas">
        <label class="rd-opc" title="Guarda solo cada 30 segundos si hay cambios. Desactívalo para guardar únicamente con el botón Guardar"><input type="checkbox" id="rd-h-auto" checked> Autoguardado</label>
        <button type="button" class="rd-b" id="rd-x-ira" title="Ir a una página o a un título"><i class="fa-solid fa-location-crosshairs"></i> Ir a…</button>
        <button type="button" class="rd-b" id="rd-x-regla" title="Regla con sangrías (arrastra los triángulos)"><i class="fa-solid fa-ruler-horizontal"></i> Regla</button>
        <button type="button" class="rd-b" id="rd-x-dos" title="Vista de dos páginas"><i class="fa-solid fa-book-open"></i> Dos páginas</button>
        <button type="button" class="rd-b" id="rd-x-dictado" title="Dictado por voz (necesita un navegador con reconocimiento de voz)"><i class="fa-solid fa-microphone"></i> Dictado</button>
        <?php if (puede_descargar()): ?><select id="rd-x-expsel" title="Exportar solo el texto seleccionado" aria-label="Exportar selección"><option value="">Exportar selección</option><option value="docx">Word (.docx)</option><option value="odt">LibreOffice (.odt)</option><option value="pdf">PDF</option></select><?php endif; ?>
    </div>

    <div class="rd-grupo-b rd-tabla-op" id="rd-tabla-op" hidden>
        <span class="text-muted text-sm">Tabla:</span>
        <button type="button" class="rd-b" data-tabla="fila+" title="Agregar fila debajo"><i class="fa-solid fa-table-rows"></i> +</button>
        <button type="button" class="rd-b" data-tabla="col+" title="Agregar columna a la derecha"><i class="fa-solid fa-table-columns"></i> +</button>
        <button type="button" class="rd-b" data-tabla="fila-" title="Eliminar fila"><i class="fa-solid fa-table-rows"></i> −</button>
        <button type="button" class="rd-b" data-tabla="col-" title="Eliminar columna"><i class="fa-solid fa-table-columns"></i> −</button>
        <button type="button" class="rd-b" data-x-tabla="combinar" title="Combinar las celdas seleccionadas (de una fila o de una columna)"><i class="fa-solid fa-object-group"></i> Combinar</button>
        <button type="button" class="rd-b" data-x-tabla="dividir" title="Dividir la celda combinada"><i class="fa-solid fa-object-ungroup"></i> Dividir</button>
        <button type="button" class="rd-b" data-x-tabla="ordenar" title="Ordenar las filas por la columna del cursor (otra vez: descendente)"><i class="fa-solid fa-arrow-down-a-z"></i> Ordenar</button>
        <button type="button" class="rd-b" data-x-tabla="zebra" title="Alternar el color de las filas"><i class="fa-solid fa-bars-staggered"></i> Filas alternas</button>
        <select id="rd-x-formula" title="Fórmula: calcula con los números de arriba o de la izquierda y escribe el resultado en la celda" aria-label="Fórmula"><option value="">Fórmula Σ</option><option value="suma:arriba">Suma ↑</option><option value="suma:izq">Suma ←</option><option value="prom:arriba">Promedio ↑</option><option value="prom:izq">Promedio ←</option><option value="max:arriba">Máximo ↑</option><option value="max:izq">Máximo ←</option><option value="min:arriba">Mínimo ↑</option><option value="min:izq">Mínimo ←</option><option value="cuenta:arriba">Cantidad ↑</option><option value="cuenta:izq">Cantidad ←</option></select>
        <label class="rd-color" title="Color de fondo de la celda"><i class="fa-solid fa-fill-drip"></i><input type="color" id="rd-celda-color" value="#e2e8f0" aria-label="Color de celda"></label>
        <label class="rd-opc" title="Ancho de la tabla">Ancho <input type="number" id="rd-ta-w" min="1" step="any" style="width:7rem"></label>
        <label class="rd-opc" title="Alto mínimo de cada fila">Fila <input type="number" id="rd-ta-h" min="0" step="any" style="width:6rem"></label>
        <select id="rd-ta-un" aria-label="Unidad de la tabla"><option value="cm">cm</option><option value="mm">mm</option><option value="px">px</option><option value="%">%</option></select>
        <button type="button" class="rd-b" id="rd-x-punch-cfg" data-x-tabla="punch" title="Cambiar qué columnas y qué pendientes muestra esta tabla del Punch list" hidden><i class="fa-solid fa-eye"></i> Qué ver</button>
        <button type="button" class="rd-b" id="rd-x-sf-abrir" title="Abrir esta tabla en SheetFlow para editarla (aquí se actualiza al abrir, ver y exportar)" hidden><i class="fa-solid fa-table-cells"></i> Editar en SheetFlow</button>
        <button type="button" class="rd-b" id="rd-x-sf-conv" title="Crear una tabla de SheetFlow con estos datos y reemplazar esta tabla por ella" hidden><i class="fa-solid fa-right-to-bracket"></i> Pasar a SheetFlow</button>
        <button type="button" class="rd-b" data-tabla="borrar" title="Eliminar tabla"><i class="fa-solid fa-trash-can"></i></button>
    </div>
</div>

<div class="rd-panel" id="rd-p-tabla" hidden>
    <strong>Insertar tabla</strong>
    <label>Origen <select id="rd-t-origen">
        <option value="">En blanco</option>
        <optgroup label="Por partida" id="rd-t-partidas"></optgroup>
        <optgroup label="Tablas adicionales">
            <option value="@programa">Programa completo (partidas y actividades)</option>
            <option value="@partidas">Solo partidas</option>
            <option value="@materiales">Materiales</option>
            <option value="@restricciones">Restricciones</option>
            <option value="@festivos">Festivos y paros</option>
            <option value="@punchvista">Punch list (elegir qué ver, con fotos)</option>
            <option value="@punchlist">Punchlist (tabla simple)</option>
            <option value="@submittals">Submittals</option>
            <option value="@ordenes">Órdenes de cambio</option>
            <option value="@bitacora">Bitácora</option>
        </optgroup>
        <optgroup label="SheetFlow"><option value="@sfnueva">Nueva tabla de SheetFlow (en blanco)</option></optgroup>
        <?php if (!empty($hojasSheetflow)): ?><optgroup label="Tablas de SheetFlow"><?php foreach ($hojasSheetflow as $h): ?><option value="@sf:<?php echo (int)$h['id']; ?>"><?php echo s($h['nombre']); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
    </select></label>
    <span id="rd-t-medidas" class="rd-t-medidas">
    <label>Filas <input type="number" id="rd-t-filas" min="1" max="50" value="3"></label>
    <label>Columnas <input type="number" id="rd-t-cols" min="1" max="12" value="3"></label>
    <label><input type="checkbox" id="rd-t-enc" checked> Primera fila de encabezado</label>
    <label title="Crea la tabla en SheetFlow: se edita allá y aquí se actualiza sola"><input type="checkbox" id="rd-t-sfcomo"> Como tabla de SheetFlow</label>
    </span>
    <label>Ancho <input type="number" id="rd-t-ancho" min="1" step="any" placeholder="100%"></label>
    <select id="rd-t-un" aria-label="Unidad"><option value="%">%</option><option value="cm">cm</option><option value="mm">mm</option><option value="px">px</option></select>
    <button type="button" class="btn btn-sm btn-primary" id="rd-t-ok">Insertar</button>
</div>
<div class="rd-panel" id="rd-p-punch" hidden>
    <strong>Punch list</strong>
    <p class="text-sm text-muted">El módulo Punch list no forma parte de Acero 360. Usa una tabla de SheetFlow para llevar el control de pendientes.</p>
    <span id="rd-pu-cols" hidden></span>
    <select id="rd-pu-estado" hidden><option value="pendientes">Solo pendientes</option></select>
    <select id="rd-pu-sev" hidden><option value="">Todas</option></select>
    <input type="text" id="rd-pu-con" hidden>
    <input type="number" id="rd-pu-lim" value="50" hidden>
    <button type="button" id="rd-pu-ok" hidden></button>
</div>
<div class="rd-panel" id="rd-p-imagen" hidden>
    <strong>Insertar imagen</strong>
    <button type="button" class="btn btn-sm btn-ghost" id="rd-img-subir"><i class="fa-solid fa-upload"></i> Subir desde el equipo</button>
    <input type="file" id="rd-img-archivo" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
    <label>Elegir una del documento <select id="rd-img-lista"><option value="">— ninguna —</option></select></label>
    <span class="text-sm text-muted">Para ajustar el texto (a un lado, delante, detrás…) haz clic en la imagen.</span>
    <?php if (!empty($config['simbolos'])): ?>
        <label>o un símbolo de la biblioteca
            <select id="rd-img-simbolo"><option value="">— Elegir —</option><?php $cat = null; foreach ($config['simbolos'] as $sm): if ($sm['cat'] !== $cat): if ($cat !== null) echo '</optgroup>'; $cat = $sm['cat']; echo '<optgroup label="' . s($cat) . '">'; endif; ?><option value="<?php echo s($sm['src']); ?>"><?php echo s($sm['nombre']); ?></option><?php endforeach; if ($cat !== null) echo '</optgroup>'; ?></select>
        </label>
    <?php endif; ?>
    <?php if ($config['imagenes']): ?>
        <label>o un gráfico del Editor
            <select id="rd-img-editor"><option value="">— Elegir —</option><?php foreach ($config['imagenes'] as $im): ?><option value="<?php echo s($im['src']); ?>"><?php echo s($im['nombre']); ?></option><?php endforeach; ?></select>
        </label>
    <?php endif; ?>
</div>
<div class="rd-panel" id="rd-p-figura" hidden>
    <strong>Insertar figura</strong>
    <input type="hidden" id="rd-f-tipo" value="rect">
    <div class="rd-fig-galeria" id="rd-f-galeria" role="group" aria-label="Figuras"></div>
    <label>Relleno <input type="color" id="rd-f-relleno" value="#f97316"></label>
    <label>Borde <input type="color" id="rd-f-borde" value="#1e293b"></label>
    <label>Ancho <input type="number" id="rd-f-w" min="1" step="any" value="160"></label>
    <label>Alto <input type="number" id="rd-f-h" min="1" step="any" value="100"></label>
    <select id="rd-f-un" aria-label="Unidad"><option value="px">px</option><option value="cm">cm</option><option value="mm">mm</option></select>
    <label><input type="checkbox" id="rd-f-sinrelleno"> Sin relleno</label>
    <button type="button" class="btn btn-sm btn-primary" id="rd-f-ok">Insertar</button>
</div>
<div class="rd-panel" id="rd-p-buscar" hidden>
    <strong>Buscar y reemplazar</strong>
    <input type="text" id="rd-b-buscar" placeholder="Buscar" aria-label="Buscar"><input type="text" id="rd-b-reemp" placeholder="Reemplazar con" aria-label="Reemplazar con">
    <label><input type="checkbox" id="rd-b-mayus"> Mayúsculas</label>
    <label title="Usa expresiones regulares, p. ej. \d{4} o ^Art\."><input type="checkbox" id="rd-b-regex"> Regex</label>
    <button type="button" class="btn btn-sm btn-ghost" id="rd-b-sig">Siguiente</button>
    <button type="button" class="btn btn-sm btn-ghost" id="rd-b-rep">Reemplazar</button>
    <button type="button" class="btn btn-sm btn-ghost" id="rd-b-todo">Reemplazar todo</button>
    <span class="text-sm text-muted" id="rd-b-info"></span>
</div>
<div class="rd-panel" id="rd-p-pagina" hidden>
    <strong>Página</strong>
    <label>Hoja <select id="rd-papel"><?php foreach (RedactorDocumento::PAPELES as $k => $p): ?><option value="<?php echo s($k); ?>"<?php echo $documento->papel === $k ? ' selected' : ''; ?>><?php echo s($p[0]); ?></option><?php endforeach; ?></select></label>
    <label>Orientación <select id="rd-orient"><option value="vertical">Vertical</option><option value="horizontal"<?php echo $documento->orientacion === 'horizontal' ? ' selected' : ''; ?>>Horizontal</option></select></label>
    <label>Márgenes <select id="rd-margen-pre"><option value="">Personalizado</option><option value="2.54,2.54,2.54,2.54">Normal (2.54)</option><option value="1.27,1.27,1.27,1.27">Estrecho (1.27)</option><option value="2.54,2.54,3.81,3.81">Amplio (3.81 lados)</option><option value="2.54,2.54,4.5,2.54">Encuadernado (izq. 4.5)</option></select></label>
    <label>Sup. <input type="number" id="rd-m-sup" step="0.1" min="0" max="8" value="<?php echo (float)$documento->margen_sup; ?>"> cm</label>
    <label>Inf. <input type="number" id="rd-m-inf" step="0.1" min="0" max="8" value="<?php echo (float)$documento->margen_inf; ?>"> cm</label>
    <label>Izq. <input type="number" id="rd-m-izq" step="0.1" min="0" max="8" value="<?php echo (float)$documento->margen_izq; ?>"> cm</label>
    <label>Der. <input type="number" id="rd-m-der" step="0.1" min="0" max="8" value="<?php echo (float)$documento->margen_der; ?>"> cm</label>
    <label><input type="checkbox" id="rd-numerar"<?php echo $documento->numerar ? ' checked' : ''; ?>> Numerar páginas</label>
    <label><input type="checkbox" id="rd-encabezado"<?php echo $documento->encabezado ? ' checked' : ''; ?>> Encabezado (nombre · departamento · fecha)</label>
    <label>Corrector <select id="rd-idioma"><?php foreach (RedactorDocumento::IDIOMAS as $k => $n): ?><option value="<?php echo s($k); ?>"<?php echo $documento->idioma === $k ? ' selected' : ''; ?>><?php echo s($n); ?></option><?php endforeach; ?></select></label>
</div>

<div id="rd-imagen-op" class="rd-panel rd-flota" hidden>
    <strong>Imagen</strong>
    <?php foreach ([25, 50, 75, 100] as $p): ?><button type="button" class="btn btn-sm btn-ghost" data-img-ancho="<?php echo $p; ?>"><?php echo $p; ?>%</button><?php endforeach; ?>
    <label title="Ancho como porcentaje del ancho útil de la hoja">Ancho <input type="number" id="rd-img-pct" min="5" max="100" step="1"> %</label>
    <span class="rd-sep"></span>
    <label>Ancho <input type="number" id="rd-img-w" min="1" step="any"></label>
    <label>Alto <input type="number" id="rd-img-h" min="1" step="any"></label>
    <select id="rd-img-un" aria-label="Unidad"><option value="px">px</option><option value="cm">cm</option><option value="mm">mm</option></select>
    <label class="rd-opc" title="Mantener la proporción al cambiar ancho o alto"><input type="checkbox" id="rd-img-prop" checked> Proporcional</label>
    <button type="button" class="btn btn-sm btn-ghost" id="rd-img-orig" title="Volver al tamaño original (sin pasar del ancho útil)"><i class="fa-solid fa-rotate-left"></i> Original</button>
    <span class="rd-sep"></span>
    <button type="button" class="btn btn-sm btn-ghost" data-img-ajuste="linea" title="En línea con el texto"><i class="fa-solid fa-text-width"></i> En línea</button>
    <button type="button" class="btn btn-sm btn-ghost" data-img-ajuste="bloque" title="Arriba y abajo: el texto queda encima y debajo"><i class="fa-solid fa-grip-lines"></i> Arriba y abajo</button>
    <button type="button" class="btn btn-sm btn-ghost" data-img-ajuste="izq" title="A la izquierda, el texto rodea la imagen"><i class="fa-solid fa-align-left"></i> Izquierda</button>
    <button type="button" class="btn btn-sm btn-ghost" data-img-ajuste="der" title="A la derecha, el texto rodea la imagen"><i class="fa-solid fa-align-right"></i> Derecha</button>
    <button type="button" class="btn btn-sm btn-ghost" data-img-ajuste="detras" title="Detrás del texto (se arrastra)"><i class="fa-solid fa-layer-group"></i> Detrás</button>
    <button type="button" class="btn btn-sm btn-ghost" data-img-ajuste="frente" title="Delante del texto (se arrastra)"><i class="fa-regular fa-clone"></i> Delante</button>
    <span id="rd-img-pos" class="rd-pos" hidden>X <input type="number" id="rd-img-x" step="5"> Y <input type="number" id="rd-img-y" step="5"></span>
    <button type="button" class="btn btn-sm btn-danger" data-img-borrar title="Quitar la imagen"><i class="fa-solid fa-trash-can"></i></button>
</div>

<div class="rd-panel" id="rd-p-firma" hidden>
    <strong>Firmas</strong>
    <div class="rd-firma-col">
        <em>Mis firmas</em>
        <div id="rd-firmas-lista" class="rd-firmas-lista"></div>
        <button type="button" class="btn btn-sm btn-ghost" id="rd-firma-nueva"><i class="fa-solid fa-pen-nib"></i> Dibujar o escribir una firma</button>
        <label>Nombre bajo la firma <input type="text" id="rd-firma-nombre" maxlength="100" placeholder="Nombre completo"></label>
        <label>Cargo <input type="text" id="rd-firma-cargo" maxlength="100" placeholder="Cargo o puesto"></label>
        <span class="text-sm text-muted">Elige una firma de la lista para insertarla con su nombre, cargo y fecha donde esté el cursor.</span>
    </div>
    <div class="rd-firma-col">
        <em>Bloque para firmar a mano</em>
        <label>Firmantes <select id="rd-firma-n"><option>1</option><option selected>2</option><option>3</option><option>4</option></select></label>
        <button type="button" class="btn btn-sm btn-ghost" id="rd-firma-bloque"><i class="fa-solid fa-grip-lines"></i> Insertar líneas de firma</button>
    </div>
    <div class="rd-firma-col">
        <em>Firma electrónica del documento</em>
        <span class="text-sm text-muted">Deja constancia de quién aprobó este texto y cuándo. Si el contenido cambia después, la firma se marca como «modificado».</span>
        <label>Cargo <input type="text" id="rd-sello-cargo" maxlength="100" placeholder="Cargo o puesto"></label>
        <label>Tu contraseña <input type="password" id="rd-sello-pass" autocomplete="current-password"></label>
        <button type="button" class="btn btn-sm btn-primary" id="rd-sello-ok"><i class="fa-solid fa-stamp"></i> Firmar electrónicamente</button>
        <div id="rd-sellos" class="rd-sellos"></div>
    </div>
</div>
<div class="rd-panel" id="rd-p-refcruz" hidden>
    <strong>Referencia cruzada</strong>
    <select id="rd-x-ref-dest" aria-label="Destino"></select>
    <label>Escribir <select id="rd-x-ref-como"><option value="texto">Solo el nombre (Tabla 2)</option><option value="ver">Con «véase» (véase Tabla 2)</option></select></label>
    <button type="button" class="btn btn-sm btn-primary" id="rd-x-ref-ok">Insertar</button>
    <span class="text-sm text-muted">Es texto: si cambias la numeración, vuelve a insertarla.</span>
</div>
<div class="rd-panel" id="rd-p-ecuacion" hidden>
    <strong>Ecuación</strong>
    <input type="text" id="rd-x-ec-txt" maxlength="200" placeholder="Ej.: x = (-b +- sqrt(b^2 - 4*a*c)) / 2a" aria-label="Ecuación">
    <button type="button" class="btn btn-sm btn-primary" id="rd-x-ec-ok">Insertar</button>
    <span class="text-sm text-muted">Usa <code>^</code> para potencias (x^2, x^{n+1}), <code>_</code> para subíndices (A_1), <code>sqrt(…)</code>, <code>pi</code>, <code>alpha</code>, <code>&lt;=</code>, <code>&gt;=</code>, <code>!=</code>, <code>+-</code>, <code>sum</code>, <code>inf</code>.</span>
    <span id="rd-x-ec-vista" class="rd-ec-vista"></span>
</div>
<div class="rd-panel" id="rd-p-caja" hidden>
    <strong>Cuadro de texto</strong>
    <label>Posición <select id="rd-x-caja-lado"><option value="der">A la derecha</option><option value="izq">A la izquierda</option><option value="centro">Centrado</option></select></label>
    <label>Ancho <input type="number" id="rd-x-caja-w" min="15" max="100" value="40"> %</label>
    <label>Fondo <input type="color" id="rd-x-caja-fondo" value="#fef3c7"></label>
    <label>Borde <input type="color" id="rd-x-caja-borde" value="#d97706"></label>
    <button type="button" class="btn btn-sm btn-primary" id="rd-x-caja-ok">Insertar</button>
</div>
<div class="rd-panel" id="rd-p-portada" hidden>
    <strong>Portada</strong>
    <label>Diseño <select id="rd-x-por-tipo"><option value="formal">Formal centrada</option><option value="banda">Con banda de color</option><option value="minimal">Minimalista</option></select></label>
    <label>Título <input type="text" id="rd-x-por-titulo" maxlength="150"></label>
    <label>Subtítulo <input type="text" id="rd-x-por-sub" maxlength="150" placeholder="Opcional"></label>
    <label>Autor <input type="text" id="rd-x-por-autor" maxlength="100"></label>
    <label>Color <input type="color" id="rd-x-por-color" value="#f97316"></label>
    <button type="button" class="btn btn-sm btn-primary" id="rd-x-por-ok">Insertar al inicio</button>
</div>
<div class="rd-panel" id="rd-p-programa" hidden>
    <strong>Tabla del programa</strong>
    <label>Tabla <select id="rd-x-prog-origen">
        <optgroup label="Programa">
            <option value="@programa">Programa completo (partidas y actividades)</option>
            <option value="@partidas">Solo partidas</option>
        </optgroup>
        <optgroup label="Una partida (con sus actividades)" id="rd-x-prog-partidas"></optgroup>
        <optgroup label="Otras tablas">
            <option value="@materiales">Materiales</option>
            <option value="@restricciones">Restricciones</option>
            <option value="@punchvista">Punch list (elegir qué ver, con fotos)</option>
            <option value="@punchlist">Punchlist (tabla simple)</option>
            <option value="@submittals">Submittals</option>
            <option value="@ordenes">Órdenes de cambio</option>
            <option value="@bitacora">Bitácora</option>
            <option value="@festivos">Festivos y paros</option>
        </optgroup>
        <?php if (!empty($hojasSheetflow)): ?><optgroup label="Tablas de SheetFlow"><?php foreach ($hojasSheetflow as $h): ?><option value="@sf:<?php echo (int)$h['id']; ?>"><?php echo s($h['nombre']); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
    </select></label>
    <label title="Crea la tabla en SheetFlow: se edita allá y aquí se actualiza sola"><input type="checkbox" id="rd-x-prog-sf"> Como tabla de SheetFlow</label>
    <button type="button" class="btn btn-sm btn-primary" id="rd-x-prog-ok">Insertar</button>
    <span class="text-sm text-muted">Toma los datos tal como están ahora; no se actualizan solos.</span>
</div>
<div class="rd-panel" id="rd-p-estilos" hidden>
    <strong>Mis estilos</strong>
    <input type="text" id="rd-x-est-nombre" maxlength="40" placeholder="Nombre del estilo">
    <button type="button" class="btn btn-sm btn-primary" id="rd-x-est-guardar">Guardar el formato del párrafo actual</button>
    <div id="rd-x-est-lista" class="rd-est-lista"></div>
    <span class="text-sm text-muted">Se guardan en este navegador para este proyecto.</span>
</div>
<div class="rd-panel" id="rd-p-encpie" hidden>
    <strong>Encabezado, pie y marca de agua</strong>
    <label>Encabezado <input type="text" id="rd-x-enc" maxlength="200" value="<?php echo s($documento->encabezado_texto); ?>" placeholder="Vacío = automático (si está activo)"></label>
    <label>Pie <input type="text" id="rd-x-pie" maxlength="200" value="<?php echo s($documento->pie_texto); ?>" placeholder="Texto bajo cada página"></label>
    <label>Marca de agua <input type="text" id="rd-x-marca" maxlength="40" value="<?php echo s($documento->marca_agua); ?>" placeholder="BORRADOR, CONFIDENCIAL…"></label>
    <span class="rd-sugerencias">
        <button type="button" class="btn btn-sm btn-ghost" data-x-marca="BORRADOR">BORRADOR</button>
        <button type="button" class="btn btn-sm btn-ghost" data-x-marca="CONFIDENCIAL">CONFIDENCIAL</button>
        <button type="button" class="btn btn-sm btn-ghost" data-x-marca="COPIA CONTROLADA">COPIA CONTROLADA</button>
        <button type="button" class="btn btn-sm btn-ghost" data-x-marca="">Quitar</button>
    </span>
    <span class="text-sm text-muted">En el encabezado puedes usar {nombre}, {departamento} y {fecha}. La marca sale en PDF y Word (no en LibreOffice).</span>
</div>
<div class="rd-panel" id="rd-p-ira" hidden>
    <strong>Ir a</strong>
    <label>Título <select id="rd-x-ira-titulo"></select></label>
    <button type="button" class="btn btn-sm btn-ghost" id="rd-x-ira-ir">Ir al título</button>
    <label>Página <input type="number" id="rd-x-ira-pag" min="1" value="1"></label>
    <button type="button" class="btn btn-sm btn-ghost" id="rd-x-ira-pir">Ir a la página</button>
</div>
<div class="rd-panel" id="rd-p-versiones" hidden>
    <strong>Historial de versiones</strong>
    <input type="text" id="rd-x-ver-etq" maxlength="80" placeholder="Nombre de la versión (ej. Para revisión)">
    <button type="button" class="btn btn-sm btn-primary" id="rd-x-ver-guardar"><i class="fa-solid fa-floppy-disk"></i> Guardar versión ahora</button>
    <div id="rd-x-ver-lista" class="rd-ver-lista"></div>
    <span class="text-sm text-muted">Además se guarda una copia automática del texto anterior cada vez que guardas, si pasaron 10 minutos desde la última (se conservan 40).</span>
</div>
<div class="rd-panel" id="rd-p-stats" hidden>
    <strong>Estadísticas del documento</strong>
    <div id="rd-stats" class="rd-stats"></div>
</div>
<div class="rd-panel" id="rd-p-plantilla" hidden>
    <strong>Guardar como plantilla</strong>
    <input type="text" id="rd-pl-nombre" maxlength="100" placeholder="Nombre de la plantilla">
    <input type="text" id="rd-pl-desc" maxlength="200" placeholder="Descripción (opcional)">
    <button type="button" class="btn btn-sm btn-primary" id="rd-pl-ok">Guardar plantilla</button>
    <span class="text-sm text-muted">Se guarda el texto aceptando los cambios y sin comentarios, con la hoja y márgenes actuales.</span>
</div>

<dialog id="rd-dlg-firma" class="rd-dialogo">
    <h3>Nueva firma</h3>
    <div class="rd-tabs"><button type="button" class="activo" data-fp="dibujar">Dibujar</button><button type="button" data-fp="escribir">Escribir</button></div>
    <div id="rd-fp-dibujar"><canvas id="rd-firma-canvas" width="560" height="200" aria-label="Área para dibujar la firma"></canvas><p class="text-sm text-muted">Dibuja con el mouse o el dedo.</p></div>
    <div id="rd-fp-escribir" hidden><input type="text" id="rd-firma-texto" maxlength="40" placeholder="Escribe tu nombre"><canvas id="rd-firma-vista" width="560" height="200"></canvas></div>
    <label>Nombre de la firma <input type="text" id="rd-firma-etq" maxlength="60" value="Mi firma"></label>
    <p class="text-sm text-muted" id="rd-firma-error" hidden></p>
    <div class="rd-dlg-acciones"><button type="button" class="btn btn-ghost" id="rd-firma-limpiar">Borrar</button><button type="button" class="btn btn-ghost" id="rd-firma-cancelar">Cancelar</button><button type="button" class="btn btn-primary" id="rd-firma-guardar">Guardar firma</button></div>
</dialog>

<dialog id="rd-dlg-dibujo" class="rd-dialogo rd-dlg-dibujo">
    <h3>Dibujo</h3>
    <div class="rd-dib-barra">
        <div class="rd-dib-herr" id="rd-dib-herr" role="group" aria-label="Herramientas de dibujo">
            <button type="button" class="activo" data-dib="lapiz" title="Lápiz"><i class="fa-solid fa-pencil"></i></button>
            <button type="button" data-dib="linea" title="Línea"><i class="fa-solid fa-slash"></i></button>
            <button type="button" data-dib="flecha" title="Flecha"><i class="fa-solid fa-arrow-right"></i></button>
            <button type="button" data-dib="rect" title="Rectángulo"><i class="fa-regular fa-square"></i></button>
            <button type="button" data-dib="elipse" title="Elipse"><i class="fa-regular fa-circle"></i></button>
            <button type="button" data-dib="cota" title="Cota (línea con medida)"><i class="fa-solid fa-ruler-horizontal"></i></button>
            <button type="button" data-dib="texto" title="Texto"><i class="fa-solid fa-font"></i></button>
            <button type="button" data-dib="borrar" title="Borrar el trazo donde hagas clic"><i class="fa-solid fa-eraser"></i></button>
        </div>
        <label>Color <input type="color" id="rd-dib-color" value="#dc2626"></label>
        <label>Relleno <input type="color" id="rd-dib-relleno" value="#fde68a"></label>
        <label><input type="checkbox" id="rd-dib-conrelleno"> Con relleno</label>
        <label>Grosor <input type="number" id="rd-dib-grosor" min="1" max="30" value="3" style="width:5.5rem"></label>
        <label>Fondo <select id="rd-dib-fondo"><option value="blanco">Blanco</option><option value="cuadricula">Cuadrícula</option><option value="transparente">Transparente</option></select></label>
        <label>Tamaño <select id="rd-dib-tam"><option value="800x500">800 × 500</option><option value="800x800">800 × 800</option><option value="1100x600">1100 × 600</option></select></label>
    </div>
    <canvas id="rd-dib-canvas" width="800" height="500" aria-label="Lienzo de dibujo"></canvas>
    <div class="rd-dlg-acciones"><button type="button" class="btn btn-ghost" id="rd-dib-deshacer">Deshacer</button><button type="button" class="btn btn-ghost" id="rd-dib-limpiar">Limpiar</button><button type="button" class="btn btn-ghost" id="rd-dib-cancelar">Cancelar</button><button type="button" class="btn btn-primary" id="rd-dib-insertar">Insertar en el documento</button></div>
</dialog>

<dialog id="rd-dlg-enlace" class="rd-dialogo">
    <form method="dialog" id="rd-f-enlace">
        <h3>Hipervínculo</h3>
        <label>Texto <input type="text" id="rd-e-texto" maxlength="200" placeholder="Texto que se mostrará"></label>
        <label>Destino
            <select id="rd-e-tipo"><option value="ext">Página web</option><option value="int">Dentro de la plataforma</option><option value="mail">Correo electrónico</option></select>
        </label>
        <label id="rd-e-ext">Dirección <input type="text" id="rd-e-url" maxlength="400" placeholder="https://..."></label>
        <label id="rd-e-int" hidden>Documento o sección
            <select id="rd-e-dest"><?php
            $grupo = null;
            foreach ($config['destinos'] as $d) {
                if ($d['grupo'] !== $grupo) { if ($grupo !== null) echo '</optgroup>'; echo '<optgroup label="' . s($d['grupo']) . '">'; $grupo = $d['grupo']; }
                echo '<option value="' . s($d['url']) . '">' . s($d['etiqueta']) . '</option>';
            }
            if ($grupo !== null) echo '</optgroup>'; ?></select>
        </label>
        <p class="text-sm text-muted" id="rd-e-error" hidden></p>
        <div class="rd-dlg-acciones"><button type="button" class="btn btn-ghost" id="rd-e-cancelar">Cancelar</button><button type="submit" class="btn btn-primary" value="ok">Aplicar</button></div>
    </form>
</dialog>

<dialog id="rd-dlg-comparar" class="rd-dialogo rd-dlg-ancho">
    <h3 id="rd-x-cmp-tit">Comparación</h3>
    <p class="text-sm text-muted"><span class="rd-dif-del">Rojo tachado</span>: estaba en la versión y ya no está. <span class="rd-dif-ins">Verde</span>: es nuevo en el documento actual.</p>
    <div id="rd-x-cmp-cuerpo" class="rd-cmp-cuerpo"></div>
    <div class="rd-dlg-acciones"><button type="button" class="btn btn-primary" id="rd-x-cmp-cerrar">Cerrar</button></div>
</dialog>
<dialog id="rd-dlg-dos" class="rd-dialogo rd-dlg-ancho">
    <div class="rd-dos-barra"><button type="button" class="btn btn-sm btn-ghost" id="rd-x-dos-ant" aria-label="Anterior"><i class="fa-solid fa-chevron-left"></i></button><strong id="rd-x-dos-tit"></strong><button type="button" class="btn btn-sm btn-ghost" id="rd-x-dos-sig" aria-label="Siguiente"><i class="fa-solid fa-chevron-right"></i></button><button type="button" class="btn btn-sm btn-primary" id="rd-x-dos-cerrar">Cerrar</button></div>
    <div id="rd-x-dos-cuerpo" class="rd-dos-cuerpo"></div>
</dialog>

<div class="rd-area">
<aside class="rd-paginas" id="rd-paginas" aria-label="Navegador de páginas">
    <div class="rd-paginas-tit"><strong><i class="fa-regular fa-copy"></i> Páginas</strong> <span id="rd-pg-n" class="rd-contador">1</span></div>
    <button type="button" class="btn btn-sm btn-ghost" id="rd-pg-nueva" title="Agregar una página en blanco al final"><i class="fa-solid fa-plus"></i> Agregar página</button>
    <div id="rd-pg-lista" class="rd-pg-lista"></div>
    <p class="text-sm text-muted">Mover o eliminar una página afecta a los bloques que empiezan en ella.</p>
</aside>
<div class="rd-fondo" id="rd-fondo">
    <div class="rd-pagina" id="rd-pagina">
        <div class="rd-hoja" id="rd-hoja" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Contenido del documento" spellcheck="false"><?php echo $documento->contenido !== '' ? $documento->contenido : '<p><br></p>'; ?></div>
        <div class="rd-guias" id="rd-guias" aria-hidden="true"></div>
    </div>
</div>
<aside class="rd-lateral" id="rd-lateral" aria-label="Comentarios y cambios">
    <div class="rd-tabs"><button type="button" class="activo" data-lat="comentarios">Comentarios</button><button type="button" data-lat="cambios">Cambios</button></div>
    <div id="rd-lat-comentarios"></div>
    <div id="rd-lat-cambios" hidden></div>
</aside>
</div>

<div class="rd-estado" id="rd-barra-estado">
    <span id="rd-e-pagina">Página 1 de 1</span><span id="rd-e-palabras">0 palabras</span><span id="rd-e-corrector">Corrector: cargando…</span>
    <label>Zoom <select id="rd-zoom"><option value="0.6">60%</option><option value="0.8">80%</option><option value="1" selected>100%</option><option value="1.25">125%</option><option value="1.5">150%</option></select></label>
</div>
<div id="rd-menu-ort" class="rd-menu-ort" hidden></div>
    <script>window.RD_CONFIG = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
</section>

<?php template('footer'); ?>
