<?php
/**
 * views/admin/contabilidad/gastos/crear.php — Alta de un gasto (un solo envío por formulario).
 */

soloAdmin();

$errores = [];
$gasto   = ['fecha' => date('Y-m-d'), 'iva_tasa' => '16', 'naturaleza' => 'gasto', 'estado_pago' => 'pendiente'];

/* Factura cargada con el botón «Registro compra inteligente» (Contabilidad):
 * se precarga lo que el motor documental leyó y pudo cotejar. Nada se guarda
 * hasta que la persona revisa los campos y envía el formulario. */
$propuesta      = null;
$lecturaId     = (int) ($_REQUEST['lectura'] ?? 0);
$lecturaArchivo = $lecturaId > 0 ? \App\Lecturas::buscarID($lecturaId) : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $lecturaArchivo !== null) {
    $propuesta = \App\Lecturas::propuesta($lecturaId, 'gasto');

    if ($propuesta === null) {
        agregarAlerta('advertencia', 'No se encontró la lectura #' . $lecturaId . ': captura el gasto a mano.');
    } else {
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
            'comprobante_fiscal' => '1',
            'uuid_fiscal'        => $propuesta['uuid'],
            'metodo_pago'        => $formaSata[substr($propuesta['forma_pago'], 0, 2)] ?? '',
            'naturaleza'         => 'compra',
            'revision_fiscal'    => 'por_validar',
            'empresa_id'         => $propuesta['empresa'] ? (int) $propuesta['empresa']['id'] : 0,
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $gasto = $_POST;
    $gasto['reparto'] = array_map(static fn ($e, $p) => ['empresa_id' => (int) $e, 'porcentaje' => (float) $p],
        array_keys((array) ($_POST['reparto'] ?? [])), array_values((array) ($_POST['reparto'] ?? [])));

    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/contabilidad/gastos', 'error', 'Este formulario ya se envió; no se registró el gasto dos veces.');
    }

    // Gasto con factura y XML adjunto: se leen los datos que la persona dejó vacíos (nunca se pisa lo que escribió).
    $datos = $_POST;
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
            break;
        }
    }

    if (\App\Gastos::crear($datos, (int) ($_SESSION['id'] ?? 0))) {
        $nuevoId = \App\Gastos::$ultimoId;
        $subidos = 0;
        $fallos  = [];

        // El documento leído con el botón inteligente se adjunta solo al gasto
        // (queda en `documentos` con su CFDI, sin volver a pedirlo).
        if ($lecturaArchivo !== null) {
            $rutaLectura = \App\Archivos::base() . '/' . $lecturaArchivo['archivo'];
            if (is_readable($rutaLectura)) {
                $adjunto = [
                    'name'     => (string) $lecturaArchivo['nombre_original'],
                    'type'     => (string) $lecturaArchivo['mime'],
                    'tmp_name' => $rutaLectura,
                    'error'    => UPLOAD_ERR_OK,
                    'size'     => (int) filesize($rutaLectura),
                ];
                if (\App\Archivos::subir('gasto', $nuevoId, $adjunto, 'auto', (int) ($_SESSION['id'] ?? 0), null, '', false)) {
                    $subidos++;
                } else {
                    $fallos[] = $lecturaArchivo['nombre_original'] . ': ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
                }
            }
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
?>

<section class="tarjeta">
    <?php if ($propuesta && !empty($propuesta['duplicados'])) : ?>
        <!-- Coincidencias que ya existen: las consume src/js/modulos/inteligente.js
             para abrir el aviso con su botón de «Ver gasto» / «Ver venta». -->
        <div hidden
             data-coincidencias="<?= escaparValores(json_encode($propuesta['duplicados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
             data-ruta="/admin/contabilidad/gastos/crear?lectura=<?= (int) $lecturaId ?>"
             data-origen="Registro compra inteligente"></div>
    <?php endif; ?>
    <?php if ($propuesta) : ?>
        <?php template('avisoLectura', ['propuesta' => $propuesta, 'nombreDoc' => 'gasto']); ?>
    <?php endif; ?>
    <form class="formulario" action="/admin/contabilidad/gastos/crear<?= $lecturaId > 0 ? '?lectura=' . $lecturaId : '' ?>" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <?php template('formularioGasto', [
            'gasto' => $gasto, 'errores' => $errores, 'proveedores' => \App\Proveedores::paraSelector(),
            'categorias' => \App\Gastos::categorias(), 'empresas' => \App\Empresas::mostrarTodos(100, 0),
            'cancelar' => '/admin/contabilidad/gastos', 'permiteArchivos' => true,
            'reglasCategoria' => \App\CoincidenciasFactura::REGLAS_CATEGORIA,
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>
