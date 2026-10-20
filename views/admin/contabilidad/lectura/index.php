<?php
/**
 * views/admin/contabilidad/lectura/index.php — Lectura inteligente de facturas y comprobantes.
 * GET: formulario de carga y lecturas pendientes. POST: lee el archivo y pasa a la pantalla de revisión
 * (nada se guarda como gasto o factura hasta que una persona confirma).
 */

soloAdmin();

$destino = in_array($_REQUEST['destino'] ?? '', ['gasto', 'venta'], true) ? $_REQUEST['destino'] : 'gasto';
$ingresoPreseleccionado = (int) ($_REQUEST['ingreso'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/contabilidad/lectura?destino=' . $destino, 'error', 'Este formulario ya se envió; no se leyó el archivo dos veces.');
    }
    $id = \App\Lecturas::preparar(\App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null), $destino, (int) ($_SESSION['id'] ?? 0));
    if ($id === null) {
        redirigir('/admin/contabilidad/lectura?destino=' . $destino . ($ingresoPreseleccionado ? '&ingreso=' . $ingresoPreseleccionado : ''), 'error', implode(' · ', \App\Lecturas::getErrores()));
    }
    redirigir('/admin/contabilidad/lectura/revisar?id=' . $id . ($ingresoPreseleccionado ? '&ingreso=' . $ingresoPreseleccionado : ''));
}

template('header', ['titulo' => 'Leer factura o comprobante', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
$ocr = \App\LectorDocumental::ocrDisponible();
?>

<section class="tarjeta mb-lg">
    <form class="formulario" action="/admin/contabilidad/lectura" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <input type="hidden" name="ingreso" value="<?= $ingresoPreseleccionado ?: '' ?>">
        <div class="formulario__rejilla">
            <div class="campo"><label class="campo__etiqueta" for="destino" data-ayuda="Elige si la factura es de algo que pagaste (gasto) o de algo que vendiste.">¿De qué es el documento?</label>
                <select class="control" id="destino" name="destino">
                    <option value="gasto"<?= $destino === 'gasto' ? ' selected' : '' ?>>De un gasto (factura que me dieron)</option>
                    <option value="venta"<?= $destino === 'venta' ? ' selected' : '' ?>>De una venta (factura que emití)</option>
                </select></div>
            <div class="campo campo--completo"><label class="campo__etiqueta campo__etiqueta--obligatorio" for="archivo" data-ayuda="Mejor el XML del CFDI: trae los datos exactos. También sirven PDF y fotos.">Archivo o archivos</label>
                <input class="control" type="file" id="archivo" name="archivo[]" multiple required accept=".xml,.pdf,.jpg,.jpeg,.png,.webp">
                <span class="campo__ayuda">Lo ideal es el XML (datos exactos); puedes subir XML + PDF de la misma factura. Si solo tienes PDF o foto, se lee el texto
                    <?= $ocr ? 'o se aplica OCR' : '(el OCR para fotos y PDF escaneados no está disponible en este servidor: esos casos se capturan a mano)' ?>.
                    Ahí se te mostrará todo para que lo revises antes de guardar.</span></div>
        </div>
        <div class="formulario__acciones">
            <button class="btn btn--primario" type="submit">Leer documento</button>
            <a class="btn btn--secundario" href="/admin/contabilidad/gastos/crear">Capturar un gasto a mano</a>
        </div>
    </form>
</section>

<?php $pendientes = \App\Lecturas::pendientes(); if ($pendientes) : ?>
    <section class="tarjeta">
        <h2 class="titulo-5 mb-md">Lecturas sin confirmar</h2>
        <ul class="lista-simple">
            <?php foreach ($pendientes as $p) : ?>
                <li><a href="/admin/contabilidad/lectura/revisar?id=<?= (int) $p['id'] ?>"><?= escaparValores($p['nombre_original']) ?></a>
                    · <?= $p['destino'] === 'gasto' ? 'gasto' : 'venta' ?> · <?= escaparValores(\App\Lecturas::FUENTES[$p['fuente']] ?? $p['fuente']) ?>
                    · <?= date('d/m/Y H:i', strtotime($p['creado_en'])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
