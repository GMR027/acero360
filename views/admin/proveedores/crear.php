<?php
/**
 * views/admin/proveedores/crear.php — Alta de proveedores.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * El alta y la edición comparten el mismo formulario:
 * templates/formularioProveedores.php
 */

soloAdmin();

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $proveedor = new \App\Proveedores([
        'nombre'         => trim($_POST['nombre'] ?? ''),
        'rfc'            => trim($_POST['rfc'] ?? ''),
        'telefono'       => trim($_POST['telefono'] ?? ''),
        'ubicacion'      => trim($_POST['ubicacion'] ?? ''),
        'tipo_proveedor' => trim($_POST['tipo_proveedor'] ?? ''),
    ]);

    if ($proveedor->tipo_proveedor === '') {
        $proveedor->tipo_proveedor = \App\Proveedores::TIPOS[0];
    }

    $errores = $proveedor->validar();

    if (empty($errores) && $proveedor->crear()) {
        redirigir('/admin/proveedores', 'exito', 'Proveedor creado correctamente.');
    }

    $errores = $errores ?: \App\Proveedores::getErrores();
} else {
    $proveedor = new \App\Proveedores();
}

template('header', [
    'titulo'    => 'Nuevo proveedor',
    'subtitulo' => 'Módulo de proveedores',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores">Volver</a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Alta de un proveedor para poder registrar sus entregas. Los campos marcados con
            asterisco son obligatorios.
        </p>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/proveedores/crear" method="post"
          data-validar novalidate>
        <?php template('formularioProveedores', ['proveedor' => $proveedor, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>
