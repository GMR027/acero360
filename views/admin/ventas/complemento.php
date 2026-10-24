<?php
/**
 * views/admin/ventas/complemento.php — Complementos de pago de CLIENTES (FASE 6).
 *
 * Ruta protegida por el middleware "soloAdmin".
 *
 * Aquí se CARGA y se REGISTRA el complemento de pago de un cobro (el XML que el
 * SAT exige cuando cobras en parcialidades). Se distingue lo que son las cuatro
 * piezas del flujo: la venta (hecho comercial), el CFDI emitido, cada cobro
 * (dinero recibido con fecha e importe) y el complemento (XML de pago).
 *
 * GET: formulario e historial. POST: lee el XML (Lecturas::preparar) y pasa a la
 * revisión. Nada se registra hasta confirmar en la pantalla de revisión.
 *
 * Nota: emitir complementos propios requiere un PAC (sellado/timbrado) que no
 * está integrado; aquí sólo se registran y se relacionan los emitidos fuera.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/ventas/complemento', 'error', 'Este formulario ya se envió; no se leyó el archivo dos veces.');
    }
    $archivos = \App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null);
    if (!$archivos) {
        redirigir('/admin/ventas/complemento', 'error', 'Elige el XML del complemento de pago.');
    }
    $xmls = array_filter($archivos, static fn ($a) => strtolower(pathinfo((string) $a['name'], PATHINFO_EXTENSION)) === 'xml');
    if (!$xmls) {
        redirigir('/admin/ventas/complemento', 'error', 'El complemento de pago es un XML del CFDI (tipo «P»).');
    }
    $id = \App\Lecturas::preparar(array_values($xmls), \App\ComplementosVenta::DESTINO, (int) ($_SESSION['id'] ?? 0));
    if ($id === null) {
        redirigir('/admin/ventas/complemento', 'error', implode(' · ', \App\Lecturas::getErrores()));
    }
    redirigir('/admin/ventas/complemento/revision?id=' . $id);
}

/* Contexto opcional: llegar desde el detalle de una venta. */
$ingresoContexto = (int) ($_GET['ingreso'] ?? 0);
$ventaContexto = null;
if ($ingresoContexto > 0) {
    $ingresoCtx = \App\Contabilidad::buscarIngreso($ingresoContexto);
    if ($ingresoCtx && !empty($ingresoCtx['venta_id'])) {
        $venta = \App\Ventas::buscarID((int) $ingresoCtx['venta_id']);
        $ventaContexto = $venta ? ['id' => (int) $venta->id, 'folio' => (string) $venta->folio] : null;
    }
}

$pendientes = array_values(array_filter(
    \App\Lecturas::pendientes(30),
    static fn ($p) => (string) $p['destino'] === \App\ComplementosVenta::DESTINO
));
$recientes = \App\ComplementosVenta::recientes(20);
$puedeEmitir = \App\ComplementosVenta::puedeEmitir();

template('header', ['titulo' => 'Complementos de pago', 'subtitulo' => 'Ventas y cobros', 'rutaActiva' => '/admin/ventas']);
?>

<?php if ($ventaContexto) : ?>
    <p class="mb-md"><a class="btn btn--texto btn--sm" href="/admin/ventas/detalle?id=<?= (int) $ventaContexto['id'] ?>#cobros">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>
        <span>Volver a la venta <?= escaparValores($ventaContexto['folio']) ?></span></a></p>
<?php endif; ?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Cargar complemento de pago (XML)</h2>

    <p class="texto-sm mb-md">
        <strong>Cómo encaja cada cosa:</strong> la <strong>venta</strong> es lo que vendiste y su ingreso contable;
        el <strong>CFDI</strong> es la factura que emitiste (su UUID); cada <strong>cobro</strong> es un dinero que
        recibiste con fecha, importe y referencia; el <strong>complemento de pago</strong> es el XML con el que el SAT
        se avisa esos cobros. Al cargarlo sólo se <em>relaciona</em> con lo que ya existe:
        <strong>nunca se crea una venta</strong> ni se duplica un cobro registrado.
    </p>

    <?php if (!$puedeEmitir) : ?>
        <p class="nota nota--advertencia mb-md" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
            <span>No hay integración con un PAC: <strong>desde aquí no se emiten complementos</strong>. Sólo se
                registran y se relacionan los que se emitieron fuera (contador o portal del PAC). La emisión propia
                queda pendiente de esa integración externa.</span>
        </p>
    <?php endif; ?>

    <form class="formulario" action="/admin/ventas/complemento" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <?php if ($ingresoContexto > 0) : ?><input type="hidden" name="ingreso" value="<?= $ingresoContexto ?>"><?php endif; ?>
        <div class="formulario__rejilla">
            <div class="campo campo--completo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="archivo"
                       data-ayuda="XML (CFDI tipo «P») emitido por tu negocio para avisar un cobro.">XML del complemento</label>
                <input class="control" type="file" id="archivo" name="archivo[]" required accept=".xml">
                <span class="campo__ayuda">Al cargarlo sólo se LEE: después verás sus facturas relacionadas
                    (DoctoRelacionado), tus cobros registrados, los importes y saldos comparados y cualquier discrepancia
                    antes de confirmar.</span>
            </div>
        </div>
        <div class="formulario__acciones">
            <button class="btn btn--primario" type="submit">Leer complemento</button>
            <a class="btn btn--secundario" href="/admin/ventas">Ir a ventas</a>
        </div>
    </form>
</section>

<?php if ($pendientes) : ?>
    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Complementos leídos sin confirmar</h2>
        <ul class="lista-simple">
            <?php foreach ($pendientes as $p) : ?>
                <li><a href="/admin/ventas/complemento/revision?id=<?= (int) $p['id'] ?>"><?= escaparValores($p['nombre_original']) ?></a>
                    · <?= date('d/m/Y H:i', strtotime($p['creado_en'])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="tarjeta">
    <h2 class="titulo-5 mb-md">Últimos complementos registrados</h2>
    <?php if (!$recientes) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">receipt_long</span>
            <p class="vacio__titulo">Aún no se ha registrado ningún complemento de pago de clientes</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Complementos de pago de clientes registrados</caption>
            <thead><tr><th scope="col">UUID</th><th scope="col">Fecha de pago</th>
                <th scope="col" class="tabla--numerica">Monto</th><th scope="col" class="tabla--centro">Estado</th>
                <th scope="col">Registró</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
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
                    <td class="tabla--numerica">
                        <?php if (!$cancelado && \App\Contabilidad::periodoCerrado((int) $c['contribuyente_id'], (string) $c['fecha_pago']) === false) : ?>
                            <form action="/admin/ventas/complemento/accion" method="post" data-validar
                                  data-confirmacion-sobre="Complemento de pago"
                                  data-confirmacion="¿Cancelar este complemento?"
                                  data-confirmacion-mensaje="Se desvinculan sus cobros y sólo se cancelan los cobros que él mismo creó."
                                  data-confirmacion-boton="Sí, cancelar">
                                <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                <input type="hidden" name="accion" value="cancelar">
                                <input type="hidden" name="complemento" value="<?= (int) $c['id'] ?>">
                                <label class="solo-lectores" for="mcp-<?= (int) $c['id'] ?>">Motivo</label>
                                <input class="control control--compacto" type="text" id="mcp-<?= (int) $c['id'] ?>"
                                       name="motivo" placeholder="Motivo" maxlength="200" required minlength="5">
                                <button class="btn btn--peligro btn--sm" type="submit">Cancelar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($cancelado && $c['motivo_cancelacion']) : ?>
                    <tr style="opacity:.6"><td colspan="6" class="texto-xs texto-secundario">Motivo: <?= escaparValores($c['motivo_cancelacion']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
