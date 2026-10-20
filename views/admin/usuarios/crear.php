<?php
/**
 * views/admin/usuarios/crear.php — Alta de administradores.
 *
 * Alta y edición comparten templates/formularioUsuarios.php.
 */

soloAdmin();

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $usuario = new \App\Usuarios([
        'nombre'     => trim($_POST['nombre'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'contrasena' => $_POST['contrasena'] ?? '',
        'telefono'   => trim($_POST['telefono'] ?? ''),
        'rol'        => 1,
    ]);

    $errores = $usuario->validar(true);

    // Foto de perfil: sólo se procesa si los demás datos ya son válidos,
    // para no dejar un archivo sin referencia cuando el envío se rechaza.
    if (empty($errores)) {
        $foto = \App\Imagenes::subirRetrato(
            $_FILES['foto'] ?? null,
            !empty($_POST['quitar_foto']),
            $errores
        );

        if ($foto !== null) {
            $usuario->foto = $foto;
        }
    }

    if (empty($errores) && $usuario->crear()) {
        redirigir('/admin/usuarios', 'exito', 'Usuario creado correctamente.');
    }

    $errores = $errores ?: \App\Usuarios::getErrores();
} else {
    $usuario = new \App\Usuarios();
}

template('header', [
    'titulo'     => 'Nuevo usuario',
    'subtitulo'  => 'Módulo de usuarios',
    'rutaActiva' => '/admin/ajustes',
]);
template('tabsAjustes', ['tab' => 'usuarios']);
?>

<section class="encabezado-pagina">
    <div>
        <!-- <h1 class="titulo-2">Nuevo usuario</h1> -->
        <p class="texto-secundario">
            Da de alta un administrador del panel. Los campos marcados con asterisco son obligatorios.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/usuarios">Volver</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/usuarios/crear" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioUsuarios', ['usuario' => $usuario, 'errores' => $errores, 'esAlta' => true]); ?>
    </form>
</section>

<?php template('footer'); ?>
