<?php
/**
 * views/admin/usuarios/editar.php — Modificación de usuarios.
 *
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 */

soloAdmin();

$usuario = \App\Usuarios::buscarID((int) ($_GET['id'] ?? 0));

if (!$usuario) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);
$fotoPrevia = $usuario->foto;

if ($esEnvio) {
    $usuario->nombre     = trim($_POST['nombre'] ?? '');
    $usuario->email      = trim($_POST['email'] ?? '');
    $usuario->contrasena = $_POST['contrasena'] ?? '';
    $usuario->telefono   = trim($_POST['telefono'] ?? '');

    $errores = $usuario->validar(false);

    // Foto de perfil: archivo nuevo, casilla de quitar, o nada que tocar.
    // Sólo se procesa si los demás datos ya son válidos, para no dejar un
    // archivo sin referencia cuando el envío se rechaza.
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

    if (empty($errores) && $usuario->actualizar()) {
        // La foto anterior sólo se borra cuando ya quedó sustituida.
        if ($usuario->foto !== $fotoPrevia) {
            \App\Imagenes::eliminar($fotoPrevia ?: null);
        }

        // Si el usuario editado es el conectado, se refrescan los datos de sesión.
        if ((int) $usuario->id === (int) ($_SESSION['id'] ?? 0)) {
            $_SESSION['nombre'] = $usuario->nombre;
            $_SESSION['email']  = $usuario->email;
        }

        redirigir('/admin/usuarios', 'exito', 'Usuario actualizado correctamente.');
    }

    $errores = $errores ?: \App\Usuarios::getErrores();
}

template('header', [
    'titulo'     => 'Editar usuario',
    'subtitulo'  => 'Módulo de usuarios',
    'rutaActiva' => '/admin/ajustes',
]);
template('tabsAjustes', ['tab' => 'usuarios']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">Modifica los datos de <strong><?= escaparValores($usuario->nombre) ?></strong>.</p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/usuarios">Volver</a>
        <a class="btn btn--secundario" href="/admin/perfil">Mi perfil</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/usuarios/editar?id=<?= (int) $usuario->id ?>" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioUsuarios', ['usuario' => $usuario, 'errores' => $errores, 'esAlta' => false]); ?>
    </form>
</section>

<?php template('footer'); ?>
