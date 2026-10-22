<?php
/**
 * views/admin/editor/crear.php — Alta de un documento del Editor.
 *
 * Tamaños predeterminados (Carta, Oficio, Legal, Tabloide, A4, A3, A5) o
 * personalizado en milímetros. El lienzo se guarda en píxeles a 96 dpi.
 */

soloAdmin();

$documento = new \Model\EditorDocumento([
    'nombre'      => '',
    'orientacion' => 'vertical',
    'fondo'       => 'liso',
]);
$alertas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tamano = (string) ($_POST['tamano'] ?? 'carta');
    $preset = \Model\EditorDocumento::TAMANOS[$tamano] ?? \Model\EditorDocumento::TAMANOS['carta'];
    $orientacion = ($_POST['orientacion'] ?? 'vertical') === 'horizontal' ? 'horizontal' : 'vertical';

    if ($tamano === 'personalizado') {
        $mmAncho = (float) ($_POST['ancho_mm'] ?? 0);
        $mmAlto = (float) ($_POST['alto_mm'] ?? 0);
    } else {
        $mmAncho = (float) $preset[1];
        $mmAlto = (float) $preset[2];
    }

    if ($orientacion === 'horizontal' && $mmAlto > $mmAncho) {
        [$mmAncho, $mmAlto] = [$mmAlto, $mmAncho];
    }

    $fondo = (string) ($_POST['fondo'] ?? 'liso');
    if (!isset(\Model\EditorDocumento::FONDOS[$fondo]) || $fondo === 'imagen') {
        $fondo = 'liso';
    }

    $documento = new \Model\EditorDocumento([
        'nombre'      => trim((string) ($_POST['nombre'] ?? '')),
        'ancho'       => \Model\EditorDocumento::mm($mmAncho),
        'alto'        => \Model\EditorDocumento::mm($mmAlto),
        'orientacion' => $orientacion,
        'fondo'       => $fondo,
        'fondo_color' => (string) ($_POST['fondo_color'] ?? '#ffffff'),
        'fondo_config' => json_encode([
            'paso'  => max(8, min(120, (int) ($_POST['paso'] ?? 40))),
            'color' => (string) ($_POST['linea_color'] ?? '#e2e8f0'),
        ]),
        'creado_por'  => (int) ($_SESSION['id'] ?? 0),
    ]);

    $alertas = $documento->validar();

    if (!$alertas && ($mmAncho < 30 || $mmAlto < 30 || $mmAncho > 1500 || $mmAlto > 1500)) {
        $alertas['error'][] = 'El tamaño debe estar entre 30 y 1500 mm por lado';
    }

    if (!$alertas) {
        $documento->id = $documento->guardar()['id'];
        $lamina = new \Model\EditorLamina([
            'documento_id' => (int) $documento->id,
            'orden'        => 1,
            'nombre'       => 'Lámina 1',
            'marcas_json'  => '',
        ]);
        $lamina->guardar();
        redirigir('/admin/editor/abrir?id=' . (int) $documento->id, 'exito', 'Documento creado.');
    }
}

$tam = (string) ($_POST['tamano'] ?? 'carta');
$fondo = (string) ($_POST['fondo'] ?? 'liso');
$orientacion = (string) ($_POST['orientacion'] ?? 'vertical');

template('header', [
    'titulo'    => 'Nuevo documento · Editor',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="herr" id="editor">
    <div class="encabezado-pagina">
        <div>
            <p class="texto-secundario">Elige el tamaño de la página. Todas las láminas del documento
                comparten ese tamaño; después puedes cambiarlo desde el editor.</p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a href="/admin/editor" class="btn btn--texto">← Volver</a>
        </div>
    </div>

    <div class="card">
        <?php foreach (($alertas['error'] ?? []) as $e) : ?>
            <div class="alerta alerta--error"><?= s($e) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="/admin/editor/crear" class="formulario" id="ed-form-crear">
            <div class="form-grid">
                <div class="campo campo--completo">
                    <label for="nombre">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" maxlength="150" required autofocus
                           value="<?= s($documento->nombre) ?>" placeholder="Ej: Plano de cimentación">
                </div>

                <div class="campo">
                    <label for="tamano">Tamaño de página</label>
                    <select id="tamano" name="tamano">
                        <?php foreach (\Model\EditorDocumento::TAMANOS as $k => $t) : ?>
                            <option value="<?= s($k) ?>"<?= $tam === $k ? ' selected' : '' ?>><?= s($t[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="orientacion">Orientación</label>
                    <select id="orientacion" name="orientacion">
                        <option value="vertical"<?= $orientacion === 'vertical' ? ' selected' : '' ?>>Vertical</option>
                        <option value="horizontal"<?= $orientacion === 'horizontal' ? ' selected' : '' ?>>Horizontal</option>
                    </select>
                </div>

                <div class="campo ed-solo-personalizado" hidden>
                    <label for="ancho_mm">Ancho (mm)</label>
                    <input type="number" id="ancho_mm" name="ancho_mm" min="30" max="1500" step="0.1"
                           value="<?= s($_POST['ancho_mm'] ?? '210') ?>">
                </div>
                <div class="campo ed-solo-personalizado" hidden>
                    <label for="alto_mm">Alto (mm)</label>
                    <input type="number" id="alto_mm" name="alto_mm" min="30" max="1500" step="0.1"
                           value="<?= s($_POST['alto_mm'] ?? '297') ?>">
                </div>

                <div class="campo">
                    <label for="fondo">Fondo</label>
                    <select id="fondo" name="fondo">
                        <?php foreach (\Model\EditorDocumento::FONDOS as $k => $n) : if ($k === 'imagen') continue; ?>
                            <option value="<?= s($k) ?>"<?= $fondo === $k ? ' selected' : '' ?>><?= s($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="fondo_color">Color del papel</label>
                    <input type="color" id="fondo_color" name="fondo_color" value="<?= s($_POST['fondo_color'] ?? '#ffffff') ?>">
                </div>

                <div class="campo ed-solo-fondo">
                    <label for="linea_color">Color de la cuadrícula</label>
                    <input type="color" id="linea_color" name="linea_color" value="<?= s($_POST['linea_color'] ?? '#e2e8f0') ?>">
                </div>
                <div class="campo ed-solo-fondo">
                    <label for="paso">Separación (px)</label>
                    <input type="number" id="paso" name="paso" min="8" max="120" value="<?= s($_POST['paso'] ?? 40) ?>">
                </div>
            </div>

            <div class="ed-previa-marco">
                <div class="ed-previa" id="ed-previa">
                    <span id="ed-previa-medidas">216 × 279 mm</span>
                </div>
            </div>

            <div class="herramientas-pie-form">
                <button type="submit" class="btn btn--primario">
                    <span class="material-symbols-outlined" aria-hidden="true">design_services</span> Crear y abrir
                </button>
            </div>
        </form>
    </div>
</section>

<script>
(function () {
    const TAMANOS = <?= json_encode(array_map(fn($t) => [$t[1], $t[2]], \Model\EditorDocumento::TAMANOS), JSON_UNESCAPED_UNICODE) ?>;
    const selTam = document.getElementById('tamano');
    const selOri = document.getElementById('orientacion');
    const selFondo = document.getElementById('fondo');
    const previa = document.getElementById('ed-previa');
    const medidas = document.getElementById('ed-previa-medidas');
    const campos = document.querySelectorAll('.ed-solo-personalizado');
    const fondos = document.querySelectorAll('.ed-solo-fondo');

    function mm() {
        const k = selTam.value;
        let w, h;
        if (k === 'personalizado') {
            w = parseFloat(document.getElementById('ancho_mm').value) || 210;
            h = parseFloat(document.getElementById('alto_mm').value) || 297;
        } else {
            [w, h] = TAMANOS[k];
        }
        if (selOri.value === 'horizontal' && h > w) [w, h] = [h, w];
        return [w, h];
    }

    function pintar() {
        campos.forEach(c => c.hidden = selTam.value !== 'personalizado');
        const esFondo = selFondo.value === 'cuadricula' || selFondo.value === 'puntos';
        fondos.forEach(c => c.hidden = !esFondo);
        const [w, h] = mm();
        medidas.textContent = Math.round(w) + ' × ' + Math.round(h) + ' mm';
        const max = 220, esc = Math.min(max / w, max / h);
        previa.style.width = (w * esc) + 'px';
        previa.style.height = (h * esc) + 'px';
        previa.style.background = '#fff';
        if (esFondo) {
            const paso = Math.max(4, parseFloat(document.getElementById('paso').value || 40) * esc * 0.8);
            const col = document.getElementById('linea_color').value;
            previa.style.backgroundImage = selFondo.value === 'cuadricula'
                ? 'linear-gradient(' + col + ' 1px, transparent 1px), linear-gradient(90deg, ' + col + ' 1px, transparent 1px)'
                : 'radial-gradient(' + col + ' 1.2px, transparent 1.2px)';
            previa.style.backgroundSize = paso + 'px ' + paso + 'px';
        } else {
            previa.style.backgroundImage = 'none';
        }
    }

    document.getElementById('ed-form-crear').addEventListener('input', pintar);
    selTam.addEventListener('change', pintar);
    selOri.addEventListener('change', pintar);
    selFondo.addEventListener('change', pintar);
    pintar();
})();
</script>

<?php template('footer'); ?>
