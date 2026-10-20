<?php
/**
 * views/admin/contabilidad/lectura/revisar.php — Pantalla de revisión de una lectura documental.
 * Muestra lo leído (con su confianza), permite corregirlo, asociarlo a un registro existente y confirmar.
 */

soloAdmin();

$id = (int) ($_REQUEST['id'] ?? 0);
$lectura = \App\Lecturas::buscarID($id);
if (!$lectura) {
    paginaNoEncontrada();
}
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = $lectura['destino'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $retorno = '/admin/contabilidad/lectura/revisar?id=' . $id;
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($retorno, 'error', 'Este formulario ya se envió.');
    }
    if (($_POST['accion'] ?? '') === 'descartar') {
        \App\Lecturas::descartar($id, $usuario);
        redirigir('/admin/contabilidad/lectura', 'exito', 'Lectura descartada; no se guardó ningún dato.');
    }
    if (\App\Lecturas::confirmar($id, $_POST, $usuario)) {
        $nuevo = \App\Lecturas::$ultimoId;
        redirigir($destino === 'gasto' ? '/admin/contabilidad/gastos/detalle?id=' . $nuevo : '/admin/contabilidad/ingresos/detalle?id=' . $nuevo,
            'exito', 'Datos confirmados y documento asociado. Quedó registrado quién lo confirmó y cuándo.');
    }
    // Con errores se vuelve a mostrar lo que la persona había escrito.
    $errores = \App\Lecturas::getErrores();
    $valores = [];
    foreach (array_keys(\App\LectorDocumental::CAMPOS) as $c) {
        $valores[$c] = trim((string) ($_POST[$c] ?? ''));
    }
    $post = $_POST;
} else {
    $errores = [];
    $valores = \App\Lecturas::valoresIniciales($lectura);
    $post = [];
    if ($destino === 'gasto') {
        $post['beneficiario'] = $valores['emisor_nombre'];
        $post['concepto'] = $lectura['leidos']['conceptos'][0]['descripcion'] ?? ($valores['folio_comercial'] !== '' ? 'Factura ' . $valores['folio_comercial'] : '');
    }
}

if ($lectura['estado'] !== 'pendiente') {
    redirigir('/admin/contabilidad/lectura', 'error', 'Esa lectura ya fue ' . $lectura['estado'] . '.');
}

$campos = $lectura['leidos']['campos'];
$discrepancias = \App\Lecturas::discrepancias($valores, $lectura);
$duplicados = \App\Lecturas::duplicados($valores, $lectura);
$coincidencias = \App\Lecturas::coincidencias($valores, $destino);
$etiquetaConfianza = ['alta' => ['Leído del XML / formato fijo', 'badge--aprobado'], 'media' => ['Revisar', 'badge--pendiente'], 'baja' => ['Dudoso: verifica', 'badge--rechazado']];
$v = static fn (string $c): string => escaparValores($valores[$c] ?? '');
$objetivoPre = (int) ($post['objetivo_id'] ?? ($_GET['ingreso'] ?? 0));
$modo = (string) ($post['modo'] ?? ($coincidencias && $coincidencias[0]['puntos'] >= 100 ? 'existente' : 'nuevo'));
if ($objetivoPre === 0 && $coincidencias && $modo === 'existente') {
    $objetivoPre = (int) $coincidencias[0]['id'];
}

template('header', ['titulo' => 'Revisar datos leídos', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
?>

<section class="tarjeta mb-lg">
    <p><strong><?= escaparValores($lectura['nombre_original']) ?></strong> · Fuente de los datos: <strong><?= escaparValores(\App\Lecturas::FUENTES[$lectura['fuente']] ?? $lectura['fuente']) ?></strong></p>
    <p class="campo__ayuda">Nada se ha guardado todavía. Revisa los campos, corrige lo que haga falta y confirma. El archivo original se conserva.</p>
    <?php foreach ($lectura['avisos_lista'] as $aviso) : ?>
        <p class="nota nota--advertencia mt-md" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span><span><?= escaparValores($aviso) ?></span></p>
    <?php endforeach; ?>
    <?php foreach ($errores as $e) : ?>
        <p class="nota nota--error mt-md" role="alert"><span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span><span><?= escaparValores($e) ?></span></p>
    <?php endforeach; ?>
    <?php foreach ($duplicados as $d) : ?>
        <p class="nota nota--error mt-md" role="alert"><span class="material-symbols-outlined nota__icono" aria-hidden="true">content_copy</span><span><strong>Posible duplicado:</strong> <?= escaparValores($d) ?> No se podrá confirmar.</span></p>
    <?php endforeach; ?>
</section>

<form class="formulario" action="/admin/contabilidad/lectura/revisar" method="post" novalidate>
    <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Datos del documento</h2>
        <p class="campo__ayuda mb-md">El <strong>folio comercial</strong> (el número que ves impreso, p. ej. A-123) y el <strong>UUID</strong> (folio fiscal del SAT) son datos distintos y se guardan por separado.</p>
        <div class="formulario__rejilla">
            <?php foreach (\App\LectorDocumental::CAMPOS as $campo => $etiqueta) :
                $c = $campos[$campo]['confianza'] ?? 'baja';
                $leido = (string) ($campos[$campo]['valor'] ?? '');
                $dudoso = $c !== 'alta' && $leido !== ''; ?>
                <div class="campo">
                    <label class="campo__etiqueta" for="f_<?= $campo ?>"><?= escaparValores($etiqueta) ?>
                        <?php if ($lectura['fuente'] !== 'manual') : ?>
                            <span class="badge <?= $etiquetaConfianza[$leido === '' ? 'baja' : $c][1] ?>"><?= $leido === '' ? 'No encontrado' : $etiquetaConfianza[$c][0] ?></span>
                        <?php endif; ?></label>
                    <input class="control" type="<?= $campo === 'fecha' ? 'date' : 'text' ?>" id="f_<?= $campo ?>" name="<?= $campo ?>" value="<?= $v($campo) ?>" maxlength="160">
                    <?php if ($dudoso) : ?>
                        <label class="comprobacion"><input type="checkbox" name="revisado[<?= $campo ?>]" value="1"<?= isset($post['revisado'][$campo]) ? ' checked' : '' ?>>
                            Revisé este dato contra el documento</label>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($lectura['leidos']['conceptos']) : ?>
            <h3 class="titulo-6 mt-lg mb-md">Conceptos del XML</h3>
            <div class="tabla-contenedor"><table class="tabla"><thead><tr><th>Descripción</th><th>Cantidad</th><th>Valor unitario</th><th>Importe</th></tr></thead><tbody>
                <?php foreach ($lectura['leidos']['conceptos'] as $c) : ?>
                    <tr><td><?= escaparValores($c['descripcion']) ?></td><td><?= escaparValores($c['cantidad']) ?></td><td><?= escaparValores($c['valor_unitario']) ?></td><td><?= escaparValores($c['importe']) ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>

    <?php if ($discrepancias) : ?>
        <section class="tarjeta mb-lg">
            <h2 class="titulo-5 mb-md">Discrepancias</h2>
            <ul class="lista-simple"><?php foreach ($discrepancias as $d) : ?><li><?= escaparValores($d) ?></li><?php endforeach; ?></ul>
            <label class="comprobacion mt-md"><input type="checkbox" name="acepta_discrepancias" value="1"<?= !empty($post['acepta_discrepancias']) ? ' checked' : '' ?>>
                Las revisé y quiero continuar así</label>
        </section>
    <?php endif; ?>

    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md"><?= $destino === 'gasto' ? '¿A qué gasto corresponde?' : '¿A qué venta corresponde?' ?></h2>
        <?php if ($coincidencias) : ?>
            <p class="campo__ayuda mb-md">Registros existentes que podrían ser este documento (por UUID, folio, RFC, fecha e importe). Asociarlo no crea nada duplicado.</p>
            <div class="tabla-contenedor"><table class="tabla"><thead><tr><th></th><th>Fecha</th><th><?= $destino === 'gasto' ? 'Beneficiario' : 'Cliente' ?></th><th><?= $destino === 'gasto' ? 'Concepto' : 'Venta' ?></th><th>Total</th><th>Coincide en</th></tr></thead><tbody>
                <?php foreach ($coincidencias as $c) : ?>
                    <tr><td><input type="radio" name="objetivo_id" value="<?= (int) $c['id'] ?>" aria-label="Elegir"<?= $objetivoPre === (int) $c['id'] ? ' checked' : '' ?>></td>
                        <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td><td><?= escaparValores((string) $c['tercero']) ?></td><td><?= escaparValores((string) $c['concepto']) ?></td>
                        <td><?= formatoMoneda($c['total']) ?></td><td><?= escaparValores(implode(', ', $c['motivos'])) ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
        <?php else : ?>
            <p class="campo__ayuda mb-md">No se encontraron registros parecidos.</p>
        <?php endif; ?>

        <?php if ($destino === 'gasto') : ?>
            <fieldset class="campo mt-md"><legend class="campo__etiqueta">Qué hacer con el documento</legend>
                <label class="comprobacion"><input type="radio" name="modo" value="existente"<?= $modo === 'existente' ? ' checked' : '' ?>> Asociarlo al gasto elegido arriba (solo adjunta el archivo y completa los datos de factura que falten)</label>
                <label class="comprobacion"><input type="radio" name="modo" value="nuevo"<?= $modo !== 'existente' ? ' checked' : '' ?>> Crear un gasto nuevo con estos datos</label>
            </fieldset>
            <div class="formulario__rejilla mt-md">
                <div class="campo"><label class="campo__etiqueta" for="beneficiario">¿A quién le pagaste? (gasto nuevo)</label>
                    <input class="control" type="text" id="beneficiario" name="beneficiario" maxlength="150" value="<?= escaparValores((string) ($post['beneficiario'] ?? '')) ?>"></div>
                <div class="campo"><label class="campo__etiqueta" for="concepto">¿En qué fue? (gasto nuevo)</label>
                    <input class="control" type="text" id="concepto" name="concepto" maxlength="255" value="<?= escaparValores((string) ($post['concepto'] ?? '')) ?>"></div>
                <div class="campo"><label class="campo__etiqueta" for="categoria_id">Tipo de gasto</label>
                    <select class="control" id="categoria_id" name="categoria_id"><option value="">— Elige un tipo —</option>
                        <?php foreach (\App\Gastos::categorias() as $cat) : ?><option value="<?= (int) $cat['id'] ?>"<?= (int) ($post['categoria_id'] ?? 0) === (int) $cat['id'] ? ' selected' : '' ?>><?= escaparValores($cat['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="campo"><label class="campo__etiqueta" for="estado_pago">¿Ya lo pagaste?</label>
                    <select class="control" id="estado_pago" name="estado_pago"><option value="pendiente">No, todavía lo debo</option><option value="pagado"<?= ($post['estado_pago'] ?? '') === 'pagado' ? ' selected' : '' ?>>Sí, ya lo pagué</option></select></div>
            </div>
            <?php if ($coincidencias) : ?>
                <label class="comprobacion mt-md"><input type="checkbox" name="acepta_nuevo" value="1"<?= !empty($post['acepta_nuevo']) ? ' checked' : '' ?>>
                    Es un gasto distinto de los registros parecidos (solo para «gasto nuevo»)</label>
            <?php endif; ?>
        <?php else : ?>
            <?php $enLista = in_array($objetivoPre, array_map(static fn ($c) => (int) $c['id'], $coincidencias), true); ?>
            <?php if (!$coincidencias && !$objetivoPre) : ?>
                <div class="campo mt-md"><label class="campo__etiqueta" for="objetivo_id">Número interno de la venta (ingreso)</label>
                    <input class="control" type="number" id="objetivo_id" name="objetivo_id" min="1" value=""><span class="campo__ayuda">Búscalo en Contabilidad → Ingresos. No se crean ventas desde un documento.</span></div>
            <?php elseif ($objetivoPre && !$enLista) : ?>
                <input type="hidden" name="objetivo_id" value="<?= $objetivoPre ?>">
                <p class="campo__ayuda">Se asociará a la venta (ingreso) #<?= $objetivoPre ?>.</p>
            <?php endif; ?>
            <?php if (($lectura['leidos']['familia'] ?? '') !== 'xml') : ?>
                <label class="comprobacion mt-md"><input type="checkbox" name="confirma_manual" value="1"<?= !empty($post['confirma_manual']) ? ' checked' : '' ?>>
                    No hay XML: confirmo bajo mi responsabilidad que la factura ya se emitió (queda registrado como confirmación manual)</label>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <div class="formulario__acciones">
        <button class="btn btn--primario" type="submit" name="accion" value="confirmar"<?= $duplicados ? ' disabled' : '' ?>>Confirmar datos</button>
        <button class="btn btn--secundario" type="submit" name="accion" value="descartar" formnovalidate>Descartar lectura</button>
    </div>
</form>

<?php template('footer'); ?>
