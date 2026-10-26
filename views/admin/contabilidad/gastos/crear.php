<?php
/**
 * views/admin/contabilidad/gastos/crear.php — Alta de un gasto (un solo envío por formulario).
 */

soloAdmin();

$errores = [];
$gasto   = ['fecha' => date('Y-m-d'), 'iva_tasa' => '16', 'naturaleza' => 'gasto', 'estado_pago' => 'pendiente'];

/* Factura cargada con el botón «Registro compra inteligente» (Contabilidad) o desde
 * Compras Inteligentes (una factura que resultó ser un gasto): se precarga lo que el
 * motor documental leyó y pudo cotejar. Nada se guarda hasta que la persona revisa
 * los campos y envía el formulario. */
$propuesta      = null;
$lecturaId     = (int) ($_REQUEST['lectura'] ?? 0);
$lecturaArchivo = $lecturaId > 0 ? \App\Lecturas::buscarID($lecturaId) : null;

/* Una lectura sólo sirve UNA vez. Si ya se confirmó (o se descartó), no se
 * vuelve a precargar el formulario: antes se mostraba con la nota «nada se ha
 * registrado todavía» y el botón de guardar, lo que invitaba a duplicar. */
$lecturaUsada = ($lecturaArchivo !== null && (string) ($lecturaArchivo['estado'] ?? '') !== 'pendiente')
    ? (string) $lecturaArchivo['estado']
    : null;

/* Requisito: una factura YA relacionada con una compra no puede generar también un
 * gasto independiente (duplicaría el importe). Si el folio fiscal pertenece al gasto
 * de una orden, se muestra la compra y no se ofrece el formulario. */
$bloqueoCompra = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $lecturaArchivo !== null && $lecturaUsada === null) {
    $propuesta = \App\Lecturas::propuesta($lecturaId, 'gasto');

    if ($propuesta === null) {
        agregarAlerta('advertencia', 'No se encontró la lectura #' . $lecturaId . ': captura el gasto a mano.');
    } else {
        if (!empty($propuesta['uuid'])) {
            $operacion = \App\ComprasIntel::operacionPorUuid((string) $propuesta['uuid']);
            if ($operacion !== null && $operacion['tipo'] === 'compra') {
                $bloqueoCompra = $operacion;
            }
        }

        $formaSata = ['01' => 'Efectivo', '02' => 'Cheque', '03' => 'Transferencia', '04' => 'Tarjeta', '05' => 'Tarjeta', '06' => 'Tarjeta'];

        $proveedorId = $propuesta['proveedor'] ? (int) $propuesta['proveedor']['id'] : 0;
        $concepto = trim((string) ($propuesta['campos']['emisor_nombre']['valor'] ?? ''));
        if ($concepto === '' && $propuesta['sin_equivalente']) {
            $concepto = (string) $propuesta['sin_equivalente'][0]['descripcion'];
        }

        $gasto = array_merge($gasto, [
            'fecha'              => $propuesta['fecha'],
            'concepto'           => mb_substr($concepto !== '' ? 'Factura de ' . $concepto : '', 0, 255),
            'beneficiario_tipo'  => $proveedorId > 0 ? 'proveedor' : 'otro',
            'proveedor_id'       => $proveedorId,
            'beneficiario'       => $proveedorId > 0 ? '' : mb_substr((string) ($propuesta['campos']['emisor_nombre']['valor'] ?? ''), 0, 150),
            'categoria_id'       => $propuesta['categoria'] ? (int) $propuesta['categoria']['id'] : 0,
            'total'              => $propuesta['total'] > 0 ? number_format($propuesta['total'], 2, '.', '') : '',
            'iva_tasa'           => (string) (int) $propuesta['iva_tasa'],
            // El XML solo dice CÓMO se va a pagar, no cuándo se pagó: el estado de pago
            // arranca «pendiente» y la fecha de pago queda vacía para que la capture quien
            // sepa cuándo salió el dinero (nunca se inventa ni se marca pagado solo).
            'estado_pago'        => 'pendiente',
            'fecha_pago'         => '',
            'comprobante_fiscal' => '1',
            'uuid_fiscal'        => $propuesta['uuid'],
            // Resto de los datos del CFDI: quedan guardados en el gasto (sirven, entre
            // otras cosas, para no señalar como duplicada una factura distinta).
            'folio_comercial'    => $propuesta['folio'],
            'rfc_emisor'         => (string) ($propuesta['campos']['emisor_rfc']['valor'] ?? ''),
            'rfc_receptor'       => (string) ($propuesta['campos']['receptor_rfc']['valor'] ?? ''),
            'moneda'             => $propuesta['moneda'] !== '' ? $propuesta['moneda'] : 'MXN',
            'forma_pago_sat'     => $propuesta['forma_pago'],
            'metodo_pago_sat'    => $propuesta['metodo_pago'],
            'metodo_pago'        => $formaSata[substr($propuesta['forma_pago'], 0, 2)] ?? '',
            /* Los cuatro datos del CFDI van juntos y se autocompletan aunque no se haya
             * reconocido al proveedor ni a un producto: son datos del comprobante. */
            'uso_cfdi'           => in_array($propuesta['uso_cfdi'] ?? '', array_keys(\App\Gastos::USOS_CFDI), true) ? $propuesta['uso_cfdi'] : '',
            'tipo_cfdi'          => in_array($propuesta['tipo_cfdi'] ?? '', array_keys(\App\Contabilidad::TIPOS_CFDI), true) ? $propuesta['tipo_cfdi'] : '',
            'naturaleza'         => 'compra',
            'revision_fiscal'    => 'por_validar',
            'empresa_id'         => $propuesta['empresa'] ? (int) $propuesta['empresa']['id'] : 0,
        ]);
    }
}

/* Regreso desde el alta rápida de un proveedor (botón «Regístralo» del propio
 * formulario): el proveedor recién creado queda YA elegido en «Pagado a» y el
 * resto de lo que se venía capturando lo restaura src/js/modulos/gastoProveedor.js
 * con el borrador que guardó en sessionStorage antes de salir. */
$proveedorNuevo = (int) ($_REQUEST['proveedor'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $proveedorNuevo > 0 && \App\Proveedores::buscarID($proveedorNuevo) !== null) {
    $gasto['beneficiario_tipo'] = 'proveedor';
    $gasto['proveedor_id']      = $proveedorNuevo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    if ($lecturaUsada !== null) {
        redirigir(
            '/admin/contabilidad/gastos',
            'error',
            'Esa lectura ya fue ' . $lecturaUsada . ': no se puede volver a usar para otro gasto. '
            . 'Captura el gasto a mano o sube la factura de nuevo.'
        );
    }
    $gasto = $_POST;
    $gasto['reparto'] = array_map(static fn ($e, $p) => ['empresa_id' => (int) $e, 'porcentaje' => (float) $p],
        array_keys((array) ($_POST['reparto'] ?? [])), array_values((array) ($_POST['reparto'] ?? [])));

    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/contabilidad/gastos', 'error', 'Este formulario ya se envió; no se registró el gasto dos veces.');
    }

    // Gasto con factura y XML adjunto: se leen los datos que la persona dejó vacíos (nunca se pisa lo que escribió).
    $datos = $_POST;
    // El enlace con la lectura que originó este gasto: sin él, la fila no recuerda de dónde salió
    // (y una lectura de «compra» que terminó aquí no podría cerrarse).
    if ($lecturaId > 0 && $lecturaArchivo !== null) {
        $datos['lectura_id'] = $lecturaId;
    }
    if (($datos['comprobante_fiscal'] ?? '') === '1') {
        foreach (\App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null) as $archivo) {
            if (strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION)) !== 'xml' || !is_readable((string) $archivo['tmp_name'])) {
                continue;
            }
            $lectura = \App\CfdiXml::leer((string) file_get_contents($archivo['tmp_name']));
            if (!$lectura['valido']) {
                continue;
            }
            $x = $lectura['datos'];
            if (trim((string) ($datos['uuid_fiscal'] ?? '')) === '') {
                $datos['uuid_fiscal'] = $x['uuid'];
            }
            if (trim((string) ($datos['total'] ?? '')) === '' && $x['total'] !== '') {
                $datos['total'] = $x['total'];
                $datos['iva_tasa'] = (float) $x['subtotal'] > 0 && (float) $x['total'] - (float) $x['subtotal'] < 0.005 ? '0' : ($datos['iva_tasa'] ?? '16');
            }
            if (trim((string) ($datos['beneficiario'] ?? '')) === '' && ($datos['beneficiario_tipo'] ?? '') === 'otro') {
                $datos['beneficiario'] = $x['emisor_nombre'];
            }
            if (trim((string) ($datos['concepto'] ?? '')) === '' && $x['emisor_nombre'] !== '') {
                $datos['concepto'] = 'Factura de ' . $x['emisor_nombre'];
            }
            // Folio de la factura y RFC de las dos partes: datos del documento, no de la persona.
            if (trim((string) ($datos['folio_comercial'] ?? '')) === '') {
                $datos['folio_comercial'] = trim(trim((string) ($x['serie'] ?? '')) . ' ' . trim((string) ($x['folio'] ?? '')));
            }
            if (trim((string) ($datos['rfc_emisor'] ?? '')) === '') {
                $datos['rfc_emisor'] = (string) ($x['emisor_rfc'] ?? '');
            }
            if (trim((string) ($datos['rfc_receptor'] ?? '')) === '') {
                $datos['rfc_receptor'] = (string) ($x['receptor_rfc'] ?? '');
            }
            // Uso del CFDI y método de pago: se completan solos si la persona los dejó vacíos.
            if (trim((string) ($datos['metodo_pago_sat'] ?? '')) === '' && in_array((string) $x['metodo_pago'], ['PUE', 'PPD'], true)) {
                $datos['metodo_pago_sat'] = $x['metodo_pago'];
            }
            if (trim((string) ($datos['uso_cfdi'] ?? '')) === '' && isset(\App\Gastos::USOS_CFDI[(string) ($x['uso_cfdi'] ?? '')])) {
                $datos['uso_cfdi'] = $x['uso_cfdi'];
            }
            // Forma de pago y tipo de comprobante: lo mismo, los otros dos datos del CFDI.
            if (trim((string) ($datos['forma_pago_sat'] ?? '')) === '' && isset(\App\VentasFiscal::FORMAS_PAGO[(string) ($x['forma_pago'] ?? '')])) {
                $datos['forma_pago_sat'] = $x['forma_pago'];
            }
            if (trim((string) ($datos['tipo_cfdi'] ?? '')) === '' && isset(\App\Contabilidad::TIPOS_CFDI[(string) ($x['tipo'] ?? '')])) {
                $datos['tipo_cfdi'] = $x['tipo'];
            }
            break;
        }
    }

    if (\App\Gastos::crear($datos, (int) ($_SESSION['id'] ?? 0))) {
        $nuevoId = \App\Gastos::$ultimoId;
        $subidos = 0;
        $fallos  = [];

        // Los documentos leídos con el botón inteligente —el XML y el PDF que se
        // subieron juntos— se adjuntan solos al gasto (quedan en `documentos` con
        // su CFDI, sin volver a pedirlos).
        if ($lecturaArchivo !== null) {
            $esperados = 1 + count($lecturaArchivo['leidos']['extras'] ?? []);
            $fallosLectura = \App\Lecturas::adjuntar($lecturaArchivo, 'gasto', $nuevoId, (int) ($_SESSION['id'] ?? 0));
            $subidos += max(0, $esperados - count($fallosLectura));
            $fallos = array_merge($fallos, $fallosLectura);
            // El cierre de la lectura (si era de «compra») lo hace Gastos::crear.
        }

        foreach (\App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null) as $archivo) {
            if (\App\Archivos::subir('gasto', $nuevoId, $archivo, 'auto', (int) ($_SESSION['id'] ?? 0))) {
                $subidos++;
            } else {
                $fallos[] = $archivo['name'] . ': ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
            }
        }
        if ($fallos) {
            redirigir('/admin/contabilidad/gastos/detalle?id=' . $nuevoId, 'error', 'Gasto registrado, pero no se adjuntó: ' . implode(' · ', $fallos));
        }
        redirigir('/admin/contabilidad/gastos/detalle?id=' . $nuevoId, 'exito', $subidos ? "Gasto registrado con $subidos archivo(s) adjunto(s)." : 'Gasto registrado.');
    }
    $errores = \App\Gastos::getErrores();
}

template('header', ['titulo' => 'Nuevo gasto', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);

/* Proveedor elegido en «Pagado a»: con él se ofrece, en la lista de «Conceptos sin
 * producto equivalente», agregar cada renglón directo a SU catálogo (es lo que hace
 * que la próxima lectura de una factura suya ya reconozca esos conceptos). Sólo si
 * está activo: un proveedor desactivado no admite productos nuevos. */
$proveedorElegido = 0;
$proveedorNombre  = '';
if (($gasto['beneficiario_tipo'] ?? '') === 'proveedor' && (int) ($gasto['proveedor_id'] ?? 0) > 0) {
    $filaProveedor = \App\Proveedores::buscarID((int) $gasto['proveedor_id']);
    if ($filaProveedor && (int) $filaProveedor->activo === 1) {
        $proveedorElegido = (int) $filaProveedor->id;
        $proveedorNombre  = (string) $filaProveedor->nombre;
    }
}
?>

<section class="tarjeta">
    <?php if ($bloqueoCompra) : ?>
        <!-- Factura ya relacionada con una compra: no se ofrece el formulario (req. de la fase 3)
             para que no existan dos registros con el mismo importe. -->
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><strong>Esta factura ya pertenece a la compra <?= escaparValores($bloqueoCompra['orden']['folio'] ?? '') ?>.</strong>
                Ya está registrada en contabilidad con ese importe, así que aquí NO se crea un gasto nuevo
                (se duplicaría el gasto). Revisa la compra o su gasto desde los enlaces.</span>
        </div>
        <div class="formulario__acciones">
            <a class="btn btn--primario" href="<?= escaparValores($bloqueoCompra['ruta']) ?>">Ver la operación relacionada</a>
            <a class="btn btn--secundario" href="/admin/compras/inteligente">Volver a Compras inteligentes</a>
        </div>
    <?php elseif ($lecturaUsada !== null) : ?>
        <!-- La lectura ya se usó: no se ofrece el formulario para no duplicar el gasto. -->
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><strong>Esta lectura ya fue <?= escaparValores($lecturaUsada === 'confirmada' ? 'confirmada' : 'descartada') ?>.</strong>
                Cada factura leída se registra una sola vez: este formulario ya no está disponible para evitar un gasto duplicado.
                <?php if ($lecturaUsada === 'confirmada') : ?>Consulta el gasto que se creó desde la lista de egresos.<?php endif; ?></span>
        </div>
        <div class="formulario__acciones">
            <a class="btn btn--primario" href="/admin/contabilidad/gastos">Ver los gastos</a>
            <a class="btn btn--secundario" href="/admin/contabilidad/lectura">Subir otra factura</a>
        </div>
    <?php else : ?>
    <?php if (!empty($_GET['continuar']) && $propuesta && !empty($propuesta['duplicados'])) : ?>
        <!-- La persona eligió «Registrar de todos modos»: se abre el formulario sin el aviso,
             pero con la advertencia a la vista. El servidor vuelve a comprobar el duplicado. -->
        <div class="nota nota--advertencia mb-lg" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span>
            <span><strong>Continuaste con un documento que ya tiene registro.</strong>
                Al guardar se comprobará otra vez: si el folio fiscal (UUID) coincide con otro gasto, no se permitirá duplicarlo.</span>
        </div>
    <?php endif; ?>
    <?php if ($propuesta && !empty($propuesta['duplicados']) && empty($_GET['continuar'])) : ?>
        <!-- Coincidencias que ya existen: las consume src/js/modulos/inteligente.js
             para abrir el aviso con su botón de «Ver gasto» / «Ver venta». -->
        <div hidden
             data-coincidencias="<?= escaparValores(json_encode($propuesta['duplicados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
             data-ruta="/admin/contabilidad/gastos/crear?lectura=<?= (int) $lecturaId ?>"
             data-origen="Registro compra inteligente"></div>
    <?php endif; ?>
    <?php if ($propuesta) : ?>
        <?php template('avisoLectura', [
            'propuesta' => $propuesta, 'nombreDoc' => 'gasto',
            'proveedorId' => $proveedorElegido, 'proveedorNombre' => $proveedorNombre,
        ]); ?>
        <?php /* Distingue los tres tiempos del documento: la fecha del CFDI es de EMISIÓN,
                 no de pago; el pago lo captura quien sepa cuándo salió el dinero. */ ?>
        <div class="nota mb-lg" role="note">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">event</span>
            <span><strong>Fechas y estados del documento:</strong>
                emisión <strong><?= escaparValores($propuesta['fecha'] ?: 'sin detectar') ?></strong> (la del CFDI) ·
                pago <strong><?= $gasto['estado_pago'] === 'pagado' && !empty($gasto['fecha_pago']) ? escaparValores($gasto['fecha_pago']) : 'sin registrar' ?></strong>
                (la decide quien pague) ·
                estado del gasto <strong><?= ($gasto['estado_pago'] ?? 'pendiente') === 'pagado' ? 'pagado' : 'por pagar' ?></strong>
                · revisión fiscal: <strong>por validar</strong> (tener el XML no lo vuelve deducible; lo valida tu contador).
            </span>
        </div>
    <?php endif; ?>
    <form class="formulario" id="form-gasto" action="/admin/contabilidad/gastos/crear<?= $lecturaId > 0 ? '?lectura=' . $lecturaId : '' ?>" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <?php template('formularioGasto', [
            'gasto' => $gasto, 'errores' => $errores, 'proveedores' => \App\Proveedores::paraSelector(),
            'categorias' => \App\Gastos::categorias(), 'empresas' => \App\Empresas::mostrarTodos(100, 0),
            'cancelar' => '/admin/contabilidad/gastos', 'permiteArchivos' => true,
            // Sólo aquí (y no en la edición) se ofrece registrar un proveedor que aún no existe.
            'permiteAltaProveedor' => true,
            'reglasCategoria' => \App\CoincidenciasFactura::REGLAS_CATEGORIA,
        ]); ?>
    </form>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
