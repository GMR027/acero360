<?php
/**
 * views/admin/editor/ver.php — Vista de lectura de las láminas.
 *
 * Usa el PNG guardado; si la lámina nunca se exportó, la dibuja al vuelo
 * desde su JSON de Konva.
 */

soloAdmin();

$documento = \Model\EditorDocumento::find((int) ($_GET['id'] ?? 0));
if (!$documento) {
    redirigir('/admin/editor', 'error', 'El documento no existe o fue eliminado.');
}

$laminas = $documento->laminas();
[$ancho, $alto] = $documento->medidas();
$configFondo = $documento->configFondo();

$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';
$GLOBALS['scriptsPagina'][] = '/public/vendor/herramientas/konva.min.js';

template('header', [
    'titulo'    => $documento->nombre . ' · Editor',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="herr ed-pagina" id="editor">
    <div class="encabezado-pagina">
        <div>
            <h1 class="titulo-2"><?= s($documento->nombre) ?></h1>
            <p class="texto-secundario">
                <?= count($laminas) ?> <?= count($laminas) === 1 ? 'lámina' : 'láminas' ?> ·
                <?= s(\Model\EditorDocumento::pxAMm($ancho)) ?> × <?= s(\Model\EditorDocumento::pxAMm($alto)) ?> mm ·
                <?= s(formatear_fecha($documento->actualizado, 'd/m/Y H:i')) ?>
            </p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a href="/admin/editor/abrir?id=<?= (int) $documento->id ?>" class="btn btn--primario">
                <span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar
            </a>
            <details class="gr-descargar">
                <summary class="btn btn--texto">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span> Descargar
                </summary>
                <div class="gr-descargar-menu">
                    <a href="/admin/editor/descargar?id=<?= (int) $documento->id ?>&formato=pdf">
                        <span class="material-symbols-outlined">picture_as_pdf</span> PDF
                    </a>
                    <a href="/admin/editor/descargar?id=<?= (int) $documento->id ?>&formato=pdf&inline=1" target="_blank" rel="noopener">
                        <span class="material-symbols-outlined">print</span> Imprimir
                    </a>
                </div>
            </details>
            <a href="/admin/editor" class="btn btn--texto">← Documentos</a>
        </div>
    </div>

    <div class="ed-ver">
        <?php foreach ($laminas as $i => $l) : ?>
            <div class="ed-ver-hoja"
                 <?php if (empty($l->marcas_png)) : ?>data-json="<?= s((string) $l->marcas_json) ?>"<?php endif; ?>
                 style="aspect-ratio: <?= (int) $ancho ?> / <?= (int) $alto ?>">
                <?php if (!empty($l->marcas_png)) : ?>
                    <img src="/public/uploads/vplan/<?= s($l->marcas_png) ?>" alt="Lámina <?= $i + 1 ?>" loading="lazy">
                <?php else : ?>
                    <span class="text-muted text-sm">Dibujando lámina…</span>
                <?php endif; ?>
                <div class="ed-ver-num"><?= $i + 1 ?> · <?= s($l->nombre !== '' ? $l->nombre : 'Lámina ' . ($i + 1)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<script>
window.addEventListener('load', function () {
    if (typeof Konva === 'undefined') return;
    var ANCHO = <?= (int) $ancho ?>, ALTO = <?= (int) $alto ?>;
    var FONDO = {
        color: <?= json_encode($documento->fondo_color, JSON_UNESCAPED_UNICODE) ?>,
        tipo: <?= json_encode($documento->fondo, JSON_UNESCAPED_UNICODE) ?>,
        paso: <?= (int) ($configFondo['paso'] ?? 40) ?>,
        linea: <?= json_encode($configFondo['color'] ?? '#e2e8f0', JSON_UNESCAPED_UNICODE) ?>,
        imagen: <?= json_encode($documento->fondo_imagen ? '/public/uploads/vplan/' . $documento->fondo_imagen : null, JSON_UNESCAPED_UNICODE) ?>
    };
    var cache = {};

    function img(src) {
        return new Promise(function (res) {
            if (!src) return res(null);
            if (cache[src]) return res(cache[src]);
            var im = new Image();
            im.onload = function () { cache[src] = im; res(im); };
            im.onerror = function () { res(null); };
            im.src = src;
        });
    }

    function fondo(layer) {
        layer.add(new Konva.Rect({ x: 0, y: 0, width: ANCHO, height: ALTO, fill: FONDO.color, listening: false }));
        if (FONDO.tipo === 'cuadricula') {
            for (var x = FONDO.paso; x < ANCHO; x += FONDO.paso) layer.add(new Konva.Line({ points: [x, 0, x, ALTO], stroke: FONDO.linea, strokeWidth: 1, listening: false }));
            for (var y = FONDO.paso; y < ALTO; y += FONDO.paso) layer.add(new Konva.Line({ points: [0, y, ANCHO, y], stroke: FONDO.linea, strokeWidth: 1, listening: false }));
        } else if (FONDO.tipo === 'puntos') {
            for (var px = FONDO.paso; px < ANCHO; px += FONDO.paso)
                for (var py = FONDO.paso; py < ALTO; py += FONDO.paso)
                    layer.add(new Konva.Circle({ x: px, y: py, radius: 1.2, fill: FONDO.linea, listening: false }));
        }
        if (FONDO.tipo === 'imagen' && FONDO.imagen) {
            return img(FONDO.imagen).then(function (im) {
                if (!im) return;
                var esc = Math.max(ANCHO / im.width, ALTO / im.height);
                var w = im.width * esc, h = im.height * esc;
                layer.add(new Konva.Image({ image: im, x: (ANCHO - w) / 2, y: (ALTO - h) / 2, width: w, height: h, listening: false }));
            });
        }
        return Promise.resolve();
    }

    document.querySelectorAll('.ed-ver-hoja[data-json]').forEach(function (el) {
        var json = el.dataset.json || '';
        var div = document.createElement('div');
        div.style.cssText = 'position:fixed;left:-100000px;top:0;width:' + ANCHO + 'px;height:' + ALTO + 'px;overflow:hidden';
        document.body.appendChild(div);
        var st = new Konva.Stage({ container: div, width: ANCHO, height: ALTO });
        var f = new Konva.Layer({ listening: false });
        var c = new Konva.Layer({ listening: false });
        st.add(f, c);

        fondo(f).then(function () {
            if (!json) return;
            var nodo = null;
            try { nodo = Konva.Node.create(json); } catch (e) { nodo = null; }
            if (nodo) {
                nodo.getChildren().slice().forEach(function (n) { n.remove(); n.listening(false); c.add(n); });
                nodo.destroy();
            }
            return Promise.all(c.find('Image').map(function (n) {
                return img(n.getAttr('src')).then(function (im) { if (im) n.image(im); });
            }));
        }).then(function () {
            st.draw();
            var url = '';
            try { url = st.toDataURL({ pixelRatio: Math.min(2, 1400 / ANCHO), mimeType: 'image/png' }); } catch (e) { url = ''; }
            st.destroy();
            div.remove();
            if (url) {
                el.innerHTML = '<img src="' + url + '" alt="">' + el.querySelector('.ed-ver-num').outerHTML;
            } else {
                el.querySelector('span.text-muted').textContent = 'No se pudo dibujar la lámina.';
            }
        });
    });
});
</script>

<?php template('footer'); ?>
