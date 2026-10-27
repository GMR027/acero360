<?php
/**
 * views/admin/sheetflow/crear.php — Alta de una tabla de SheetFlow.
 *
 * Se puede empezar en blanco, desde una plantilla (incluida la «tabla
 * inteligente» que diseña las columnas) o importando .sheetflow / .xlsx /
 * .ods / .csv.
 */

soloAdmin();

$hoja = new \Model\SheetflowHoja(['fecha' => date('Y-m-d'), 'creado_por' => (int) ($_SESSION['id'] ?? 0)]);
$alertas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hoja = new \Model\SheetflowHoja([
        'nombre'       => $_POST['nombre'] ?? '',
        'fecha'        => $_POST['fecha'] ?? date('Y-m-d'),
        'departamento' => $_POST['departamento'] ?? '',
        'descripcion'  => $_POST['descripcion'] ?? '',
        'creado_por'   => (int) ($_SESSION['id'] ?? 0),
    ]);

    $alertas = $hoja->validar();
    $datos = null;
    $subida = $_FILES['archivo'] ?? null;

    try {
        if ($subida && ($subida['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if (($subida['size'] ?? 0) > 25 * 1024 * 1024) {
                throw new \RuntimeException('El archivo supera los 25 MB');
            }
            $datos = \Classes\Sheetflow::importar($subida['tmp_name'], (string) $subida['name']);
        } elseif ($subida && ($subida['error'] ?? 0) !== UPLOAD_ERR_NO_FILE) {
            $alertas['error'][] = 'No se pudo subir el archivo';
        } elseif (($_POST['plantilla'] ?? '') === 'inteligente') {
            $datos = \Classes\Sheetflow::inteligente(json_decode((string) ($_POST['int_cols'] ?? ''), true), (int) ($_POST['int_filas'] ?? 10));
        } else {
            $datos = \Classes\Sheetflow::plantilla((string) ($_POST['plantilla'] ?? '')) ?? \Classes\Sheetflow::vacia();
        }
    } catch (\Throwable $e) {
        $alertas['error'][] = $e instanceof \RuntimeException ? $e->getMessage() : 'No se pudo leer el archivo';
        if (!$e instanceof \RuntimeException) {
            error_log('SheetFlow importar: ' . $e->getMessage());
        }
    }

    if (!$alertas) {
        $hoja->datos = json_encode($datos, JSON_UNESCAPED_UNICODE);
        $id = $hoja->guardar()['id'];
        redirigir('/admin/sheetflow/abrir?id=' . (int) $id, 'exito', 'Tabla creada.');
    }
}

$departamentos = \Model\SheetflowHoja::departamentos();
$plantillas = \Classes\Sheetflow::plantillas();

template('header', [
    'titulo'    => 'Nueva tabla · SheetFlow',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="herr" id="sheetflow">
    <div class="encabezado-pagina">
        <div>
            <p class="texto-secundario">Elige el nombre y cómo quieres empezar. Después podrás
                agregar columnas, fórmulas, imágenes y totales.</p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a href="/admin/sheetflow" class="btn btn--texto">← Volver</a>
        </div>
    </div>

    <div class="card">
        <?php foreach (($alertas['error'] ?? []) as $e) : ?>
            <div class="alerta alerta--error"><?= s($e) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="/admin/sheetflow/crear" enctype="multipart/form-data" class="formulario form-crear">
            <div class="form-grid sf-datos">
                <div class="campo sf-d-nombre">
                    <label for="nombre">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" maxlength="150"
                           value="<?= s($hoja->nombre) ?>" placeholder="Ej: Presupuesto de obra" required autofocus>
                </div>
                <div class="campo sf-d-fecha">
                    <label for="fecha">Fecha *</label>
                    <input type="date" id="fecha" name="fecha" value="<?= s($hoja->fecha) ?>" required>
                </div>
                <div class="campo sf-d-depto">
                    <label for="departamento">Carpeta</label>
                    <input type="text" id="departamento" name="departamento" maxlength="100"
                           list="sf-deptos" value="<?= s($hoja->departamento) ?>" placeholder="Ej: Compras" autocomplete="off">
                    <datalist id="sf-deptos">
                        <?php foreach ($departamentos as $dp) : ?><option value="<?= s($dp) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="campo sf-d-desc">
                    <label for="descripcion">Descripción</label>
                    <input type="text" id="descripcion" name="descripcion" maxlength="300"
                           value="<?= s($hoja->descripcion) ?>" placeholder="Opcional: para qué sirve la tabla">
                </div>
            </div>

            <h3 class="mt-2">¿Cómo quieres empezar?</h3>
            <div class="rd-plantillas">
                <label class="rd-plantilla">
                    <input type="radio" name="plantilla" value="" checked>
                    <span><span class="material-symbols-outlined" aria-hidden="true">description</span>
                        <strong>En blanco</strong><small>4 columnas × 10 renglones</small></span>
                </label>
                <label class="rd-plantilla">
                    <input type="radio" name="plantilla" value="inteligente">
                    <span><span class="material-symbols-outlined" aria-hidden="true">auto_fix_high</span>
                        <strong>Tabla inteligente</strong><small>Define columnas con tipo y color</small></span>
                </label>
                <?php foreach ($plantillas as $k => [$n, $desc, $ico]) : ?>
                    <label class="rd-plantilla">
                        <input type="radio" name="plantilla" value="<?= s($k) ?>">
                        <span><span class="material-symbols-outlined" aria-hidden="true">grid_on</span>
                            <strong><?= s($n) ?></strong><small><?= s($desc) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="campo campo--completo mt-2 sf-int" id="sf-inteligente" hidden>
                <div class="sf-int-tit">
                    <strong>Diseña las columnas</strong>
                    <span class="text-sm text-muted">Puedes cambiarlas después dentro de la tabla.</span>
                </div>
                <div class="sf-int-previa" id="sf-int-previa" aria-label="Vista previa del encabezado"></div>
                <div class="sf-int-cab"><span>#</span><span>Nombre</span><span>Tipo</span><span>Color</span><span></span></div>
                <div id="sf-int-cols" class="sf-int-cols"></div>
                <div class="sf-int-pie">
                    <div class="sf-int-rapido"><span class="text-sm text-muted">Agregar:</span><span id="sf-int-rapido"></span></div>
                    <label class="text-sm">Renglones iniciales
                        <input type="number" name="int_filas" id="sf-int-filas" min="1" max="200" value="10" style="width:7rem">
                    </label>
                </div>
                <input type="hidden" name="int_cols" id="sf-int-json">
            </div>

            <div class="campo campo--completo mt-2" id="sf-bloque-archivo">
                <label for="archivo">…o importa un archivo (.sheetflow, .xlsx, .ods o .csv)</label>
                <input type="file" id="archivo" name="archivo" accept=".sheetflow,.xlsx,.ods,.csv,text/csv">
                <small class="texto-secundario">Se usa la primera hoja; la primera fila son los encabezados.
                    Si eliges un archivo, se ignora la plantilla. Máx. 25 MB, 30 columnas y 1 000 renglones.</small>
            </div>

            <div class="herramientas-pie-form">
                <button type="submit" class="btn btn--primario">
                    <span class="material-symbols-outlined" aria-hidden="true">grid_on</span> Crear y abrir
                </button>
            </div>
        </form>
    </div>
</section>

<script>
(function () {
    const TIPOS = <?= json_encode(\Classes\Sheetflow::TIPOS, JSON_UNESCAPED_UNICODE) ?>;
    const caja = document.getElementById('sf-int-cols');
    const json = document.getElementById('sf-int-json');
    const panel = document.getElementById('sf-inteligente');
    const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const ICO = { texto: 'text_fields', numero: 'tag', moneda: 'attach_money', porcentaje: 'percent', fecha: 'event', lista: 'list', casilla: 'check_box', semaforo: 'traffic' };
    const previa = document.getElementById('sf-int-previa');
    const rapido = document.getElementById('sf-int-rapido');
    const cols = [
        { n: 'Concepto', t: 'texto', op: '', bg: '#334155' },
        { n: 'Fecha', t: 'fecha', op: '', bg: '#334155' },
        { n: 'Estado', t: 'semaforo', op: '', bg: '#334155' }
    ];

    rapido.innerHTML = Object.keys(TIPOS).map(k => '<button type="button" class="btn btn--texto btn--sm" data-a="' + k + '">'
        + '<span class="material-symbols-outlined" aria-hidden="true">' + ICO[k] + '</span> ' + esc(TIPOS[k]) + '</button>').join('');

    rapido.addEventListener('click', e => {
        const b = e.target.closest('button');
        if (!b || cols.length >= 30) return;
        cols.push({ n: TIPOS[b.dataset.a], t: b.dataset.a, op: b.dataset.a === 'lista' ? 'Sí, No' : '', bg: cols.length ? cols[cols.length - 1].bg : '#334155' });
        pintar();
        const u = caja.querySelectorAll('.sf-n');
        u[u.length - 1].focus();
        u[u.length - 1].select();
    });

    function pintar() {
        caja.innerHTML = cols.map((c, i) => '<div class="sf-int-col" data-i="' + i + '">'
            + '<span class="sf-int-num">' + (i + 1) + '</span>'
            + '<input type="text" class="sf-n" maxlength="60" placeholder="Nombre" value="' + esc(c.n) + '">'
            + '<select class="sf-t">' + Object.keys(TIPOS).map(k => '<option value="' + k + '"' + (k === c.t ? ' selected' : '') + '>' + esc(TIPOS[k]) + '</option>').join('') + '</select>'
            + '<input type="color" class="sf-c" title="Color del encabezado" value="' + esc(c.bg) + '">'
            + '<span class="sf-int-acc">'
            + '<button type="button" class="btn btn--texto btn--sm" data-m="-1"' + (i ? '' : ' disabled') + ' title="Mover a la izquierda"><span class="material-symbols-outlined">arrow_upward</span></button>'
            + '<button type="button" class="btn btn--texto btn--sm" data-m="1"' + (i < cols.length - 1 ? '' : ' disabled') + ' title="Mover a la derecha"><span class="material-symbols-outlined">arrow_downward</span></button>'
            + '<button type="button" class="btn btn--texto btn--sm" data-q="1" title="Quitar"' + (cols.length > 1 ? '' : ' disabled') + '><span class="material-symbols-outlined">delete</span></button></span>'
            + (c.t === 'lista' ? '<input type="text" class="sf-o" placeholder="Opciones separadas por comas" value="' + esc(c.op) + '">' : '')
            + '</div>').join('');
        guardar();
    }

    function guardar() {
        json.value = JSON.stringify(cols.map(c => ({
            n: c.n,
            t: c.t,
            bg: c.bg,
            op: c.t === 'lista' ? c.op.split(',').map(x => x.trim()).filter(Boolean) : []
        })));
        previa.innerHTML = cols.map(c => '<span style="background:' + esc(c.bg) + '">' + esc(c.n || 'Sin nombre') + '</span>').join('');
    }

    caja.addEventListener('input', e => {
        const f = e.target.closest('.sf-int-col');
        if (!f) return;
        const c = cols[+f.dataset.i];
        if (e.target.classList.contains('sf-n')) c.n = e.target.value;
        else if (e.target.classList.contains('sf-o')) c.op = e.target.value;
        else if (e.target.classList.contains('sf-c')) c.bg = e.target.value;
        else if (e.target.classList.contains('sf-t')) { c.t = e.target.value; pintar(); return; }
        guardar();
    });

    caja.addEventListener('click', e => {
        const b = e.target.closest('button');
        if (!b) return;
        const i = +b.closest('.sf-int-col').dataset.i;
        if (b.dataset.q) cols.splice(i, 1);
        else { const j = i + +b.dataset.m; [cols[i], cols[j]] = [cols[j], cols[i]]; }
        pintar();
    });

    document.querySelectorAll('input[name=plantilla]').forEach(r => r.addEventListener('change', () => {
        panel.hidden = document.querySelector('input[name=plantilla]:checked').value !== 'inteligente';
    }));
    pintar();
})();
</script>

<?php template('footer'); ?>
