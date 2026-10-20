<?php
/**
 * views/admin/contabilidad/gastos/crear.php — Alta de un gasto (un solo envío por formulario).
 */

soloAdmin();

$errores = [];
$gasto   = ['fecha' => date('Y-m-d'), 'iva_tasa' => '16', 'naturaleza' => 'gasto', 'estado_pago' => 'pendiente'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $gasto = $_POST;
    $gasto['reparto'] = array_map(static fn ($e, $p) => ['empresa_id' => (int) $e, 'porcentaje' => (float) $p],
        array_keys((array) ($_POST['reparto'] ?? [])), array_values((array) ($_POST['reparto'] ?? [])));

    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir('/admin/contabilidad/gastos', 'error', 'Este formulario ya se envió; no se registró el gasto dos veces.');
    }

    if (\App\Gastos::crear($_POST, (int) ($_SESSION['id'] ?? 0))) {
        $nuevoId = \App\Gastos::$ultimoId;
        $subidos = 0;
        $fallos  = [];
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
    <form class="formulario" action="/admin/contabilidad/gastos/crear" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <?php template('formularioGasto', [
            'gasto' => $gasto, 'errores' => $errores, 'proveedores' => \App\Proveedores::paraSelector(),
            'categorias' => \App\Gastos::categorias(), 'empresas' => \App\Empresas::mostrarTodos(100, 0),
            'cancelar' => '/admin/contabilidad/gastos', 'permiteArchivos' => true,
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>
