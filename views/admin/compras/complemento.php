<?php
/**
 * views/admin/compras/complemento.php — Complementos de pago (CFDI tipo «P»): carga
 * el XML del proveedor y lista los ya registrados. GET: formulario e historial.
 * POST: lee el archivo (Lecturas::preparar) y pasa a la pantalla de revisión.
 *
 * Nada se registra aquí: los pagos y sus vínculos se confirman en la revisión.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/compras/complemento', 'error', 'Este formulario ya se envió; no se leyó el archivo dos veces.');
    }
    $archivos = \App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null);
    if (!$archivos) {
        redirigir('/admin/compras/complemento', 'error', 'Elige el XML del complemento de pago.');
    }
    $xmls = array_filter($archivos, static fn ($a) => strtolower(pathinfo((string) $a['name'], PATHINFO_EXTENSION)) === 'xml');
    if (!$xmls) {
        redirigir('/admin/compras/complemento', 'error', 'El complemento de pago es un XML del CFDI (tipo «P»).');
    }
    $id = \App\Lecturas::preparar(array_values($xmls), 'complemento', (int) ($_SESSION['id'] ?? 0));
    if ($id === null) {
        redirigir('/admin/compras/complemento', 'error', implode(' · ', \App\Lecturas::getErrores()));
    }
    redirigir('/admin/compras/complemento/revision?id=' . $id);
}

$recientes = \App\ComplementosPago::recientes(20);
$pendientes = array_values(array_filter(
    \App\Lecturas::pendientes(30),
    static fn ($p) => (string) $p['destino'] === 'complemento'
));

template('header', ['titulo' => 'Complementos de pago', 'subtitulo' => 'Compras y proveedores']);
template('tabsCompras', ['tab' => 'complemento']);
?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Cargar complemento de pago (XML)</h2>
    <form class="formulario" action="/admin/compras/complemento" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <div class="formulario__rejilla">
            <div class="campo campo--completo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="archivo"
                       data-ayuda="El complemento (CFDI tipo «P») emite el proveedor cuando recibe tu pago.">XML del complemento</label>
                <input class="control" type="file" id="archivo" name="archivo[]" required accept=".xml">
                <span class="campo__ayuda">Al cargarlo solo se LEE: después verás sus facturas relacionadas (DoctoRelacionado),
                    los pagos que ya registraste y cualquier discrepancia. Se confirma hasta que tú lo valides:
                    nunca se crea un segundo pago por un movimiento que ya exista.</span>
            </div>
        </div>
        <div class="formulario__acciones">
            <button class="btn btn--primario" type="submit">Leer complemento</button>
            <a class="btn btn--secundario" href="/admin/compras/pagos">Ir a pagos</a>
        </div>
    </form>
</section>

<?php if ($pendientes) : ?>
    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Complementos leídos sin confirmar</h2>
        <ul class="lista-simple">
            <?php foreach ($pendientes as $p) : ?>
                <li><a href="/admin/compras/complemento/revision?id=<?= (int) $p['id'] ?>"><?= escaparValores($p['nombre_original']) ?></a>
                    · <?= date('d/m/Y H:i', strtotime($p['creado_en'])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="tarjeta">
    <h2 class="titulo-5 mb-md">Últimos complementos registrados</h2>
    <?php if (!$recientes) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">receipt</span>
            <p class="vacio__titulo">Aún no se ha registrado ningún complemento de pago</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Complementos de pago registrados</caption>
            <thead><tr><th scope="col">UUID</th><th scope="col">Fecha de pago</th>
                <th scope="col" class="tabla--numerica">Monto</th><th scope="col" class="tabla--centro">Estado</th>
                <th scope="col">Registró</th></tr></thead>
            <tbody>
            <?php foreach ($recientes as $c) : $cancelado = (string) $c['estado'] === 'cancelado'; ?>
                <tr<?= $cancelado ? ' style="opacity:.6"' : '' ?>>
                    <td><strong><?= escaparValores(substr((string) $c['uuid'], 0, 8)) ?>…</strong>
                        <?= $c['folio_comercial'] ? '<span class="texto-xs texto-secundario"> · ' . escaparValores($c['folio_comercial']) . '</span>' : '' ?></td>
                    <td><?= date('d/m/Y', strtotime($c['fecha_pago'])) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda((float) $c['total']) ?></td>
                    <td class="tabla--centro"><span class="badge <?= $cancelado ? 'badge--error' : 'badge--aprobado' ?>">
                        <?= $cancelado ? 'Cancelado' : 'Vigente' ?></span></td>
                    <td class="texto-sm"><?= escaparValores((string) ($c['usuario_nombre'] ?? '—')) ?></td>
                </tr>
                <?php if ($cancelado && $c['motivo_cancelacion']) : ?>
                    <tr<?= ' style="opacity:.6"' ?>><td colspan="5" class="texto-xs texto-secundario">Motivo: <?= escaparValores($c['motivo_cancelacion']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
