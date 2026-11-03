<?php
/**
 * views/admin/sheetflow/abrir.php — Editor de una tabla de SheetFlow.
 *
 * Rejilla con fórmulas, formatos, imágenes y totales. Guarda por AJAX
 * (con control de versión para no pisar cambios de otra sesión).
 */

soloAdmin();

use Classes\Sheetflow;

$hoja = \Model\SheetflowHoja::find((int) ($_GET['id'] ?? 0));
if (!$hoja) {
    redirigir('/admin/sheetflow', 'error', 'La tabla no existe o fue eliminada.');
}

$departamentos = \Model\SheetflowHoja::departamentos();
$config = [
    'id'       => (int) $hoja->id,
    'proyecto' => 0,
    'version'  => (string) $hoja->actualizado,
    'datos'    => $hoja->contenido(),
    'max'      => ['cols' => \Classes\Sheetflow::MAX_COLS, 'filas' => \Classes\Sheetflow::MAX_FILAS],
    'api'      => [
        'guardar' => '/admin/sheetflow/api/guardar',
        'externo' => '/admin/sheetflow/api/externo',
        'versiones' => '/admin/sheetflow/api/versiones',
        'compartir' => '/admin/sheetflow/api/compartir',
    ],
    'simbolos' => [],
];

$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';
$GLOBALS['scriptsPagina'][] = '/public/js/pantone.js?v=' . filemtime(__DIR__ . '/../../../public/js/pantone.js');
$GLOBALS['scriptsPagina'][] = '/public/vendor/herramientas/sheetflow.js?v=' . filemtime(__DIR__ . '/../../../public/vendor/herramientas/sheetflow.js');
$GLOBALS['scriptsPagina'][] = '/public/vendor/herramientas/qrcode.min.js?v=' . filemtime(__DIR__ . '/../../../public/vendor/herramientas/qrcode.min.js');
$GLOBALS['scriptsPagina'][] = '/public/vendor/herramientas/jsbarcode.min.js?v=' . filemtime(__DIR__ . '/../../../public/vendor/herramientas/jsbarcode.min.js');

template('header', [
    'titulo'    => $hoja->nombre . ' · SheetFlow',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="herr" id="sheetflow">
    <div class="encabezado-pagina">
        <div>
            <h1 class="titulo-2" id="sf-titulo"><?= s($hoja->nombre) ?></h1>
            <p class="texto-secundario">SheetFlow · tabla compartida</p>
        </div>
        <div class="encabezado-pagina__acciones">
            <span id="sf-estado" class="text-sm text-muted" role="status"></span>
            <button type="button" id="sf-guardar" class="btn btn--primario">
                <span class="material-symbols-outlined" aria-hidden="true">save</span> Guardar
            </button>
            <details class="gr-descargar">
                <summary class="btn btn--texto">
                    <span class="material-symbols-outlined" aria-hidden="true">ios_share</span> Exportar
                </summary>
                <div class="gr-descargar-menu">
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=xlsx"><span class="material-symbols-outlined">table_chart</span> Excel (.xlsx)</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=csv"><span class="material-symbols-outlined">description</span> CSV</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=sheetflow"><span class="material-symbols-outlined">grid_on</span> SheetFlow (.sheetflow)</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=pdf"><span class="material-symbols-outlined">picture_as_pdf</span> PDF</a>
                    <a href="/admin/sheetflow/descargar?id=<?= (int) $hoja->id ?>&formato=pdf&inline=1" target="_blank" rel="noopener"><span class="material-symbols-outlined">print</span> Imprimir</a>
                </div>
            </details>
            <a href="/admin/sheetflow/ver?id=<?= (int) $hoja->id ?>" class="btn btn--texto">
                <span class="material-symbols-outlined" aria-hidden="true">visibility</span> Ver
            </a>
            <button type="button" id="sf-versiones" class="btn btn--texto" title="Historial de versiones de esta tabla">
                <span class="material-symbols-outlined" aria-hidden="true">history</span> Versiones
            </button>
            <button type="button" id="sf-compartir" class="btn btn--texto" title="Crear un enlace público de solo lectura">
                <span class="material-symbols-outlined" aria-hidden="true">share</span> Compartir
            </button>
            <a href="/admin/sheetflow" class="btn btn--texto">← Tablas</a>
        </div>
    </div>

    <div class="card rd-datos">
        <label>Nombre <input type="text" id="sf-nombre" maxlength="150" value="<?= s($hoja->nombre) ?>"></label>
        <label>Fecha <input type="date" id="sf-fecha" value="<?= s($hoja->fecha) ?>"></label>
        <label>Carpeta <input type="text" id="sf-depto" maxlength="100" list="sf-deptos" value="<?= s($hoja->departamento) ?>" autocomplete="off"></label>
        <label>Descripción <input type="text" id="sf-desc" maxlength="300" value="<?= s($hoja->descripcion) ?>"></label>
        <datalist id="sf-deptos"><?php foreach ($departamentos as $dp) : ?><option value="<?= s($dp) ?>"><?php endforeach; ?></datalist>
    </div>

<div class="sf-pestanas" id="sf-pestanas" role="tablist" aria-label="Pestañas de herramientas">
    <button type="button" role="tab" class="activo" data-pest="inicio"><i class="fa-solid fa-house"></i> Inicio</button>
    <button type="button" role="tab" data-pest="insertar"><i class="fa-solid fa-plus"></i> Insertar</button>
    <button type="button" role="tab" data-pest="datos"><i class="fa-solid fa-table-list"></i> Datos</button>
    <button type="button" role="tab" data-pest="vista"><i class="fa-regular fa-eye"></i> Vista</button>
</div>
<div class="sf-barra" id="sf-barra" role="toolbar" aria-label="Herramientas de la tabla">
    <div class="sf-panel" data-panel="inicio">
        <div class="sf-grupo" title="Deshacer / rehacer">
            <button type="button" class="sf-b" id="sf-deshacer" title="Deshacer (Ctrl+Z)"><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" class="sf-b" id="sf-rehacer" title="Rehacer (Ctrl+Y)"><i class="fa-solid fa-rotate-right"></i></button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Fuente</span>
            <button type="button" class="sf-b" id="sf-fs-menos" title="Reducir tamaño de letra"><i class="fa-solid fa-minus"></i></button>
            <input type="number" id="sf-fs" min="6" max="72" step="1" value="13" title="Tamaño de letra (px) de las celdas elegidas" aria-label="Tamaño de letra">
            <button type="button" class="sf-b" id="sf-fs-mas" title="Aumentar tamaño de letra"><i class="fa-solid fa-plus"></i></button>
            <button type="button" class="sf-b" data-fmt="b" title="Negrita (Ctrl+B)"><i class="fa-solid fa-bold"></i></button>
            <button type="button" class="sf-b" data-fmt="i" title="Cursiva (Ctrl+I)"><i class="fa-solid fa-italic"></i></button>
            <button type="button" class="sf-b" data-fmt="u" title="Subrayado (Ctrl+U)"><i class="fa-solid fa-underline"></i></button>
            <label class="sf-color" title="Color de la letra"><i class="fa-solid fa-font"></i><input type="color" id="sf-c-letra" value="#111111"></label>
            <label class="sf-color" title="Color de relleno de la celda"><i class="fa-solid fa-fill-drip"></i><input type="color" id="sf-c-fondo" value="#fff7c2"></label>
            <button type="button" class="sf-b" id="sf-sin-relleno" title="Quitar relleno"><i class="fa-solid fa-ban"></i></button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Alinear</span>
            <button type="button" class="sf-b" data-alin="izquierda" title="Alinear a la izquierda"><i class="fa-solid fa-align-left"></i></button>
            <button type="button" class="sf-b" data-alin="centro" title="Centrar"><i class="fa-solid fa-align-center"></i></button>
            <button type="button" class="sf-b" data-alin="derecha" title="Alinear a la derecha"><i class="fa-solid fa-align-right"></i></button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Celda</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-bar-poner" title="Dibuja una barra de progreso con el valor de la celda (0 a 100)"><i class="fa-solid fa-bars-progress"></i> Barra</button>
            <button type="button" class="sf-b" id="sf-bar-quitar" title="Quita la barra de progreso"><i class="fa-solid fa-xmark"></i></button>
            <button type="button" class="sf-b sf-b-txt" id="sf-spark-poner" title="Dibuja una minigráfica con los valores de un rango o una lista"><i class="fa-solid fa-chart-line"></i> Minigráfica…</button>
            <button type="button" class="sf-b" id="sf-spark-quitar" title="Quita la minigráfica"><i class="fa-solid fa-xmark"></i></button>
            <button type="button" class="sf-b" id="sf-nota-poner" title="Añade una nota a la celda elegida"><i class="fa-regular fa-comment-dots"></i></button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Reglas</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-regla" title="Colorea las celdas del rango elegido según su valor"><i class="fa-solid fa-wand-magic-sparkles"></i> Condicional…</button>
            <button type="button" class="sf-b" id="sf-regla-quitar" title="Quita las reglas que tocan el rango elegido"><i class="fa-solid fa-eraser"></i></button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Análisis</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-grafica-poner" title="Crea una gráfica de barras, líneas o pastel a partir de un rango"><i class="fa-solid fa-chart-column"></i> Gráfica…</button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Bordes</span>
            <select id="sf-b-lado" title="Dónde poner el borde en las celdas elegidas">
                <option value="todos">Todos</option><option value="exterior">Exterior</option><option value="arriba">Arriba</option><option value="abajo">Abajo</option>
                <option value="izquierda">Izquierda</option><option value="derecha">Derecha</option><option value="ninguno">Sin bordes</option>
            </select>
            <select id="sf-b-estilo" title="Estilo de línea"><option value="solid">Continuo</option><option value="dashed">Guiones</option><option value="dotted">Puntos</option><option value="double">Doble</option></select>
            <select id="sf-b-grosor" title="Grosor (px)"><option value="1">1 px</option><option value="2">2 px</option><option value="3">3 px</option><option value="4">4 px</option><option value="6">6 px</option></select>
            <label class="sf-color" title="Color del borde"><i class="fa-regular fa-square"></i><input type="color" id="sf-b-color" value="#1f3a5f"></label>
            <button type="button" class="sf-b sf-b-txt" id="sf-b-aplicar" title="Aplicar el borde"><i class="fa-solid fa-border-all"></i> Aplicar</button>
            <button type="button" class="sf-b" id="sf-limpiar-fmt" title="Quitar todo el formato de las celdas elegidas (conserva el contenido)"><i class="fa-solid fa-eraser"></i></button>
        </div>
    </div>
    <div class="sf-panel" data-panel="insertar" hidden>
        <div class="sf-grupo">
            <span class="sf-etq">Renglones</span>
            <button type="button" class="sf-b sf-b-txt" data-acc="fila-arriba" title="Insertar renglón arriba"><i class="fa-solid fa-arrow-up"></i> Arriba</button>
            <button type="button" class="sf-b sf-b-txt" data-acc="fila-abajo" title="Insertar renglón abajo"><i class="fa-solid fa-arrow-down"></i> Abajo</button>
            <button type="button" class="sf-b sf-b-txt" data-acc="fila-borrar" title="Eliminar los renglones elegidos"><i class="fa-solid fa-trash-can"></i> Eliminar</button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Columnas</span>
            <button type="button" class="sf-b sf-b-txt" data-acc="col-izq" title="Insertar columna a la izquierda"><i class="fa-solid fa-arrow-left"></i> Izquierda</button>
            <button type="button" class="sf-b sf-b-txt" data-acc="col-der" title="Insertar columna a la derecha"><i class="fa-solid fa-arrow-right"></i> Derecha</button>
            <button type="button" class="sf-b sf-b-txt" data-acc="col-borrar" title="Eliminar las columnas elegidas"><i class="fa-solid fa-trash-can"></i> Eliminar</button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Combinar</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-combinar" title="Combina el rango elegido en una sola celda visible"><i class="fa-solid fa-object-group"></i> Combinar</button>
            <button type="button" class="sf-b sf-b-txt" id="sf-separar" title="Separa las celdas combinadas del rango elegido"><i class="fa-solid fa-object-ungroup"></i> Separar</button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Enlace</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-lk-poner" title="Convierte el texto de la celda en un enlace"><i class="fa-solid fa-link"></i> Poner enlace…</button>
            <button type="button" class="sf-b" id="sf-lk-quitar" title="Quita el enlace de las celdas elegidas"><i class="fa-solid fa-link-slash"></i></button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Códigos</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-qr-poner" title="Genera un código QR a partir del texto de la celda (o de una dirección que escribas)"><i class="fa-solid fa-qrcode"></i> QR…</button>
            <button type="button" class="sf-b sf-b-txt" id="sf-barcode-poner" title="Genera un código de barras CODE128 a partir del texto de la celda"><i class="fa-solid fa-barcode"></i> Barras…</button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Imagen</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-img-poner" title="Pone una imagen en la celda elegida"><i class="fa-regular fa-image"></i> Insertar en celda…</button>
            <?php if (!empty($config['simbolos'])): ?>
            <select id="sf-img-simbolo" class="sf-b" title="Pone un símbolo de la biblioteca en la celda elegida"><option value="">Símbolo…</option><?php $cat = null; foreach ($config['simbolos'] as $sm): if ($sm['cat'] !== $cat): if ($cat !== null) echo '</optgroup>'; $cat = $sm['cat']; echo '<optgroup label="' . s($cat) . '">'; endif; ?><option value="<?php echo s($sm['src']); ?>"><?php echo s($sm['nombre']); ?></option><?php endforeach; if ($cat !== null) echo '</optgroup>'; ?></select>
            <?php endif; ?>
            <button type="button" class="sf-b sf-b-txt" id="sf-img-quitar" title="Quita la imagen de las celdas elegidas"><i class="fa-solid fa-xmark"></i> Quitar</button>
            <input type="file" id="sf-img-archivo" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
        </div>
    </div>
    <div class="sf-panel" data-panel="datos" hidden>
        <div class="sf-grupo">
            <span class="sf-etq">Columna</span>
            <select id="sf-tipo" title="Tipo de dato de la columna">
                <?php foreach (Sheetflow::TIPOS as $k => $n): ?><option value="<?php echo s($k); ?>"><?php echo s($n); ?></option><?php endforeach; ?>
            </select>
            <input type="text" id="sf-opciones" class="sf-b" hidden placeholder="Opciones: una, dos, tres" title="Opciones de la lista desplegable, separadas por comas (máx. 20)">
            <select id="sf-total" title="Total al pie de la columna (solo columnas numéricas; «Cuenta» en cualquiera)">
                <?php foreach (Sheetflow::TOTALES as $k => $n): ?><option value="<?php echo s($k); ?>"><?php echo s($k === '' ? 'Sin total' : 'Total: ' . mb_strtolower($n)); ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="sf-b sf-b-txt" id="sf-ancho-auto" title="Vuelve al ancho automático de las columnas elegidas"><i class="fa-solid fa-arrows-left-right"></i> Ancho auto</button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Ordenar</span>
            <button type="button" class="sf-b sf-b-txt" data-acc="orden-asc" title="Ordenar A→Z / menor a mayor"><i class="fa-solid fa-arrow-down-a-z"></i> A→Z</button>
            <button type="button" class="sf-b sf-b-txt" data-acc="orden-desc" title="Ordenar Z→A / mayor a menor"><i class="fa-solid fa-arrow-down-z-a"></i> Z→A</button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Resumen</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-subtotales" title="Agrupa por una columna y agrega subtotales al final de la tabla">
                <i class="fa-solid fa-layer-group"></i> Subtotales…
            </button>
            <button type="button" class="sf-b sf-b-txt" id="sf-pivote" title="Crea una tabla resumen (filas × columnas) en una hoja nueva">
                <i class="fa-solid fa-table-cells"></i> Tabla dinámica…
            </button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Filtrar</span>
            <input type="search" id="sf-filtro" class="sf-b" placeholder="Texto…" autocomplete="off" title="Muestra solo los renglones que contienen el texto (no borra datos)">
            <label class="sf-chk" title="Busca solo en la columna elegida"><input type="checkbox" id="sf-filtro-col"> columna elegida</label>
            <button type="button" class="sf-b" id="sf-filtro-limpiar" title="Quitar el filtro"><i class="fa-solid fa-filter-circle-xmark"></i></button>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Fórmulas</span>
            <select id="sf-fn" title="Inserta una función en la celda elegida">
                <option value="">Insertar función…</option>
                <optgroup label="Condicionales">
                    <option value="=SI(A2>0;&quot;Sí&quot;;&quot;No&quot;)">SI (prueba; si es verdad; si no)</option>
                    <option value="=Y(A2>0;B2>0)">Y (todas se cumplen)</option>
                    <option value="=O(A2>0;B2>0)">O (alguna se cumple)</option>
                    <option value="=NO(A2>0)">NO (invierte)</option>
                    <option value="=SI.ERROR(A2/B2;0)">SI.ERROR (valor si hay error)</option>
                    <option value="=SUMAR.SI(A2:A10;&quot;&gt;5&quot;;B2:B10)">SUMAR.SI (suma si cumple)</option>
                    <option value="=CONTAR.SI(A2:A10;&quot;&gt;5&quot;)">CONTAR.SI (cuenta si cumple)</option>
                    <option value="=PROMEDIO.SI(A2:A10;&quot;&gt;5&quot;;B2:B10)">PROMEDIO.SI (promedia si cumple)</option>
                </optgroup>
                <optgroup label="Matemáticas">
                    <option value="=SUMA(A2:A10)">SUMA</option><option value="=PROMEDIO(A2:A10)">PROMEDIO</option><option value="=MIN(A2:A10)">MIN</option>
                    <option value="=MAX(A2:A10)">MAX</option><option value="=CONTAR(A2:A10)">CONTAR</option><option value="=REDONDEAR(A2;2)">REDONDEAR</option>
                </optgroup>
            </select>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Datos externos</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-ext-insertar" title="Trae un dato del inventario a la celda elegida">
                <i class="fa-solid fa-link"></i> Insertar dato…
            </button>
            <button type="button" class="sf-b sf-b-txt" id="sf-ext-actualizar" title="Consulta otra vez los datos vinculados de esta tabla">
                <i class="fa-solid fa-rotate"></i> Actualizar
            </button>
            <button type="button" class="sf-b sf-b-txt" id="sf-ext-quitar" title="Quita el vínculo de las celdas elegidas y deja el valor actual">
                <i class="fa-solid fa-link-slash"></i> Quitar vínculo
            </button>
            <span class="sf-etq" id="sf-ext-estado" title="Última actualización de los datos externos en este navegador"></span>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Listas</span>
            <button type="button" class="sf-b sf-b-txt" id="sf-lista-insertar" title="Trae una lista completa (inventario, clientes, ventas, compras, gastos o cotizaciones) desde la celda elegida">
                <i class="fa-solid fa-table-list"></i> Insertar lista…
            </button>
        </div>
    </div>
    <div class="sf-panel" data-panel="vista" hidden>
        <div class="sf-grupo">
            <span class="sf-etq">Encabezado</span>
            <label class="sf-color" title="Color del encabezado"><i class="fa-solid fa-fill-drip"></i><input type="color" id="sf-c-enc" value="#1f3a5f"></label>
            <label class="sf-color" title="Color del texto del encabezado"><i class="fa-solid fa-font"></i><input type="color" id="sf-c-txt" value="#ffffff"></label>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Tabla</span>
            <label class="sf-chk"><input type="checkbox" id="sf-zebra"> Renglones alternos</label>
            <label class="sf-chk"><input type="checkbox" id="sf-bordes"> Líneas de cuadrícula</label>
        </div>
        <div class="sf-grupo">
            <span class="sf-etq">Zoom</span>
            <input type="range" id="sf-zoom" min="50" max="200" step="10" value="100" aria-label="Zoom" style="width:14rem"><span id="sf-zoom-v" class="mono">100%</span>
        </div>
    </div>
</div>

<div class="sf-formula">
    <span class="sf-ref" id="sf-ref">A2</span>
    <span class="sf-fx">fx</span>
    <input type="text" id="sf-fx" autocomplete="off" spellcheck="false" placeholder="Contenido; = para fórmula. La fila 1 es el encabezado: los datos empiezan en la 2. Ej.: =A2+B2">
</div>

<div class="sf-area" id="sf-area" tabindex="0" aria-label="Tabla editable">
    <div class="sf-scroll"><table class="sf-grid" id="sf-grid"></table></div>
</div>
<div class="sf-estadistica" id="sf-estadistica" aria-live="polite"></div>
<div class="sf-graficas" id="sf-graficas"></div>
<p class="text-muted text-sm sf-ayuda">Clic para elegir · arrastra o Mayús+flechas para varias celdas · Enter o doble clic para editar (en fecha abre calendario; en casilla y semáforo cambia el valor) · Supr borra · Ctrl+C / Ctrl+V copian y pegan desde Excel · Fórmulas <code>+ − * / ^ &amp;</code>, <code>SUMA PROMEDIO MIN MAX CONTAR REDONDEAR</code> y condicionales <code>SI Y O NO SI.ERROR SUMAR.SI CONTAR.SI PROMEDIO.SI</code>. En <em>Inicio</em> tienes formato condicional, barras, minigráficas, gráficas, notas y bordes; en <em>Insertar</em>, enlaces, códigos QR y de barras, imágenes, combinación de celdas y filas/columnas; en <em>Datos</em>, tipos de columna, filtros, subtotales, tabla dinámica y datos externos de inventario, clientes, ventas, compras, gastos, cotizaciones, entregas y fiscal. Arriba, <em>Versiones</em> guarda el historial y <em>Compartir</em> crea un enlace de solo lectura. <strong>La fila 1 es el encabezado; la primera celda con datos es A2, B2…</strong></p>

    <div class="card sf-usos">
        <h3><span class="material-symbols-outlined" aria-hidden="true">share</span> Usar esta tabla en el Redactor</h3>
        <p class="texto-secundario">
            En un documento del Redactor elige <em>Insertar › Tabla › Origen › SheetFlow</em>.
            La tabla queda vinculada: si cambias los datos aquí, el documento muestra la última versión
            al abrirlo o al exportarlo.
        </p>
        <p>
            <a class="btn btn--texto btn--sm" href="/admin/redactor/crear?tabla=<?= (int) $hoja->id ?>">
                <span class="material-symbols-outlined" aria-hidden="true">edit_document</span>
                Crear un documento con esta tabla
            </a>
        </p>
    </div>

    <script>
        window.SF_CONFIG = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
</section>

<?php template('footer'); ?>
