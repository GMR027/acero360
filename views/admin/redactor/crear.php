<?php
/**
 * views/admin/redactor/crear.php — Alta de un documento del Redactor.
 *
 * Se puede empezar en blanco, con una plantilla incluida o con una plantilla
 * propia guardada desde un documento.
 */

soloAdmin();

$documento = new \Model\RedactorDocumento([
    'fecha'      => date('Y-m-d'),
    'creado_por' => (int) ($_SESSION['id'] ?? 0),
]);
$alertas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $documento = new \Model\RedactorDocumento([
        'nombre'       => $_POST['nombre'] ?? '',
        'fecha'        => $_POST['fecha'] ?? '',
        'departamento' => $_POST['departamento'] ?? '',
        'idioma'       => $_POST['idioma'] ?? 'es',
        'papel'        => $_POST['papel'] ?? 'carta',
        'orientacion'  => $_POST['orientacion'] ?? 'vertical',
        'creado_por'   => (int) ($_SESSION['id'] ?? 0),
    ]);
    $alertas = $documento->validar();

    if (!$alertas) {
        $clave = (string) ($_POST['plantilla'] ?? '');
        if ($clave !== '' && ($pl = \Classes\RedactorPlantillas::aplicar($clave, 'Acero 360', $documento, (string) ($_SESSION['nombre'] ?? '')))) {
            $documento->contenido = $pl['contenido'];
            if (isset($pl['papel'])) {
                foreach (['papel', 'orientacion', 'margen_sup', 'margen_inf', 'margen_izq', 'margen_der'] as $c) {
                    $documento->$c = $pl[$c];
                }
            }
        }

        // Documento creado desde una tabla de SheetFlow: se inserta vinculada.
        $tabla = (int) ($_POST['tabla'] ?? 0);
        if ($tabla && ($hoja = \Model\SheetflowHoja::find($tabla))) {
            $documento->contenido = '<h2>' . htmlspecialchars($hoja->nombre, ENT_QUOTES, 'UTF-8') . '</h2>'
                . \Classes\Sheetflow::htmlRedactor($hoja) . '<p><br></p>';
        }

        $documento->id = $documento->guardar()['id'];
        redirigir('/admin/redactor/abrir?id=' . (int) $documento->id, 'exito', 'Documento creado.');
    }
}

$departamentos = \Model\RedactorDocumento::departamentos();
$incluidas = \Classes\RedactorPlantillas::incluidas();
$propias = \Classes\RedactorPlantillas::todas();

// Data-confirm de «Eliminar plantilla» → modal estilizado (herramientas.js).
$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';

template('header', [
    'titulo'    => 'Nuevo documento · Redactor',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="herr" id="redactor">
    <div class="encabezado-pagina">
        <div>
            <p class="texto-secundario">Elige el formato de hoja y, si quieres, una plantilla.
                Después podrás escribir, insertar imágenes, tablas y firmas.</p>
        </div>
        <div class="encabezado-pagina__acciones">
            <a href="/admin/redactor" class="btn btn--texto">← Volver</a>
        </div>
    </div>

    <div class="card">
        <?php foreach (($alertas['error'] ?? []) as $e) : ?>
            <div class="alerta alerta--error"><?= s($e) ?></div>
        <?php endforeach; ?>

        <!-- Formulario propio de borrado de plantillas. Va FUERA del form
             principal: un <form> anidado lo descartaría el navegador. Su
             botón lo referencia con form="rd-form-plantilla-eliminar". -->
        <form method="POST" action="/admin/redactor/plantilla-eliminar" class="en-linea"
              id="rd-form-plantilla-eliminar" formnovalidate
              data-confirm="¿Eliminar la plantilla seleccionada?|Se quitará de la lista de plantillas. Esta acción no se puede deshacer.|Eliminar"></form>

        <form method="POST" action="/admin/redactor/crear" class="formulario">
            <input type="hidden" name="tabla" value="<?= (int) ($_GET['tabla'] ?? 0) ?>">
            <div class="form-grid">
                <div class="campo campo--completo">
                    <label for="nombre">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" maxlength="150"
                           value="<?= s($documento->nombre) ?>" placeholder="Ej: Memoria de cálculo" required autofocus>
                </div>
                <div class="campo">
                    <label for="fecha">Fecha *</label>
                    <input type="date" id="fecha" name="fecha" value="<?= s($documento->fecha) ?>" required>
                </div>
                <div class="campo">
                    <label for="departamento">Carpeta</label>
                    <input type="text" id="departamento" name="departamento" maxlength="100" list="rd-deptos"
                           value="<?= s($documento->departamento) ?>" placeholder="Ej: Ingeniería" autocomplete="off">
                    <datalist id="rd-deptos">
                        <?php foreach ($departamentos as $dp) : ?><option value="<?= s($dp) ?>"><?php endforeach; ?>
                    </datalist>
                    <small class="texto-secundario">Elige una de la lista o escribe una nueva.</small>
                </div>
                <div class="campo">
                    <label for="idioma">Corrector ortográfico</label>
                    <select id="idioma" name="idioma">
                        <?php foreach (\Model\RedactorDocumento::IDIOMAS as $k => $n) : ?>
                            <option value="<?= s($k) ?>"<?= $documento->idioma === $k ? ' selected' : '' ?>><?= s($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label for="papel">Tamaño de hoja</label>
                    <select id="papel" name="papel">
                        <?php foreach (\Model\RedactorDocumento::PAPELES as $k => $p) : ?>
                            <option value="<?= s($k) ?>"<?= $documento->papel === $k ? ' selected' : '' ?>><?= s($p[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label for="orientacion">Orientación</label>
                    <select id="orientacion" name="orientacion">
                        <option value="vertical">Vertical</option>
                        <option value="horizontal"<?= $documento->orientacion === 'horizontal' ? ' selected' : '' ?>>Horizontal</option>
                    </select>
                </div>
            </div>

            <h3 class="mt-2">Plantilla</h3>
            <p class="texto-secundario text-sm">Parte de una plantilla o de una hoja en blanco.
                Las marcas (fecha, nombre, usuario) se llenan solas.</p>
            <div class="rd-plantillas">
                <label class="rd-plantilla">
                    <input type="radio" name="plantilla" value="" checked>
                    <span><span class="material-symbols-outlined" aria-hidden="true">description</span>
                        <strong>En blanco</strong><small>Hoja vacía</small></span>
                </label>
                <?php foreach ($incluidas as $k => [$n, $desc, $ico]) : ?>
                    <label class="rd-plantilla">
                        <input type="radio" name="plantilla" value="inc:<?= s($k) ?>">
                        <span><span class="material-symbols-outlined" aria-hidden="true">article</span>
                            <strong><?= s($n) ?></strong><small><?= s($desc) ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php foreach ($propias as $pl) : ?>
                    <label class="rd-plantilla rd-plantilla-propia">
                        <input type="radio" name="plantilla" value="pro:<?= (int) $pl['id'] ?>">
                        <span><span class="material-symbols-outlined" aria-hidden="true">star</span>
                            <strong><?= s($pl['nombre']) ?></strong>
                            <small><?= s($pl['descripcion'] !== '' ? $pl['descripcion'] : 'Plantilla guardada') ?></small></span>
                        <button type="submit" class="btn btn--peligro btn--sm"
                                form="rd-form-plantilla-eliminar" formnovalidate
                                name="eliminar_plantilla" value="<?= (int) $pl['id'] ?>"
                                title="Eliminar plantilla">
                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                        </button>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="herramientas-pie-form">
                <button type="submit" class="btn btn--primario">
                    <span class="material-symbols-outlined" aria-hidden="true">edit</span> Crear y abrir
                </button>
            </div>
        </form>
    </div>
</section>

<?php template('footer'); ?>
