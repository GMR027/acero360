<?php
/**
 * views/admin/compras/inteligente.php — «Compras Inteligentes»: carga el XML de una
 * factura recibida y abre el análisis. GET: formulario y lecturas pendientes de compra.
 * POST: lee el archivo (Lecturas::preparar) y pasa a la pantalla de decisión.
 *
 * Nada se guarda en compras ni en contabilidad aquí: solo se lee el documento.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/compras/inteligente', 'error', 'Este formulario ya se envió; no se leyó el archivo dos veces.');
    }
    $archivos = \App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null);
    if (!$archivos) {
        redirigir('/admin/compras/inteligente', 'error', 'Elige el XML de la factura.');
    }
    $xmls = array_filter($archivos, static fn ($a) => strtolower(pathinfo((string) $a['name'], PATHINFO_EXTENSION)) === 'xml');
    if (!$xmls) {
        redirigir('/admin/compras/inteligente', 'error', 'Este flujo usa el XML del CFDI (los datos exactos). El PDF o la foto se registran desde Contabilidad.');
    }
    $id = \App\Lecturas::preparar(array_values($xmls), 'compra', (int) ($_SESSION['id'] ?? 0));
    if ($id === null) {
        redirigir('/admin/compras/inteligente', 'error', implode(' · ', \App\Lecturas::getErrores()));
    }
    redirigir('/admin/compras/inteligente/revision?id=' . $id);
}

template('header', ['titulo' => 'Compras inteligentes', 'subtitulo' => 'Compras y proveedores']);
template('tabsCompras', ['tab' => 'inteligente']);
?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Cargar factura de compra (XML)</h2>
    <form class="formulario" action="/admin/compras/inteligente" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <div class="formulario__rejilla">
            <div class="campo campo--completo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="archivo"
                       data-ayuda="El XML trae los datos exactos: emisor, RFC, UUID, importes e impuestos.">XML del CFDI</label>
                <input class="control" type="file" id="archivo" name="archivo[]" required accept=".xml">
                <span class="campo__ayuda">Al cargarlo solo se LEE el documento: después eliges si relacionarlo con una compra existente,
                    iniciar una compra nueva, revisarlo sin registrar o cancelarlo. No se mueve inventario ni se crea ningún registro automáticamente.</span>
            </div>
        </div>
        <div class="formulario__acciones">
            <button class="btn btn--primario" type="submit">Leer factura</button>
            <a class="btn btn--secundario" href="/admin/compras/pedidos">Ir a compras y pedidos</a>
        </div>
    </form>
</section>

<?php
$pendientes = array_values(array_filter(
    \App\Lecturas::pendientes(30),
    static fn ($p) => (string) $p['destino'] === 'compra'
));
if ($pendientes) : ?>
    <section class="tarjeta">
        <h2 class="titulo-5 mb-md">Facturas leídas sin decidir</h2>
        <ul class="lista-simple">
            <?php foreach ($pendientes as $p) : ?>
                <li><a href="/admin/compras/inteligente/revision?id=<?= (int) $p['id'] ?>"><?= escaparValores($p['nombre_original']) ?></a>
                    · <?= escaparValores(\App\Lecturas::FUENTES[$p['fuente']] ?? $p['fuente']) ?>
                    · <?= date('d/m/Y H:i', strtotime($p['creado_en'])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
