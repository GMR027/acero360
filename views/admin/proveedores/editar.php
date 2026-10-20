<?php
/**
 * views/admin/proveedores/editar.php — Modificación de proveedores.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 */

soloAdmin();

/** Proveedor a editar. Si no existe, se muestra el 404. */
$proveedor = \App\Proveedores::buscarID((int) ($_GET['id'] ?? 0));

if (!$proveedor) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $proveedor->nombre         = trim($_POST['nombre'] ?? '');
    $proveedor->rfc            = trim($_POST['rfc'] ?? '');
    $proveedor->telefono       = trim($_POST['telefono'] ?? '');
    $proveedor->ubicacion      = trim($_POST['ubicacion'] ?? '');
    $proveedor->contacto       = trim($_POST['contacto'] ?? '');
    $proveedor->email          = trim($_POST['email'] ?? '');
    $proveedor->observaciones  = trim($_POST['observaciones'] ?? '');
    $proveedor->activo         = isset($_POST['activo']) ? 1 : 0;
    $proveedor->tipo_proveedor = trim($_POST['tipo_proveedor'] ?? \App\Proveedores::TIPOS[0]);

    $errores = $proveedor->validar();

    if (empty($errores) && $proveedor->actualizar()) {
        redirigir('/admin/proveedores', 'exito', 'Proveedor actualizado correctamente.');
    }

    $errores = $errores ?: \App\Proveedores::getErrores();
}

template('header', [
    'titulo'    => 'Editar proveedor',
    'subtitulo' => 'Módulo de proveedores',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores">Volver</a>'
        . '<a class="btn btn--secundario" href="/admin/proveedores/detalle?id=' . (int) $proveedor->id . '">'
        . 'Ver detalle</a>',
]);
template('tabsCompras', ['tab' => 'proveedores']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Modifica los datos de <strong><?= escaparValores($proveedor->nombre) ?></strong>.
        </p>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/proveedores/editar?id=<?= (int) $proveedor->id ?>"
          method="post" data-validar novalidate>
        <?php template('formularioProveedores', ['proveedor' => $proveedor, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>
