<?php
/**
 * views/admin/clientes/editar.php — Modificación de clientes.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 */

soloAdmin();

/** Cliente a editar. Si no existe, se muestra el 404. */
$cliente = \App\Clientes::buscarID((int) ($_GET['id'] ?? 0));

if (!$cliente) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);
$fotoPrevia = $cliente->foto;

if ($esEnvio) {
    $cliente->nombre         = trim($_POST['nombre'] ?? '');
    $cliente->nombre_empresa = trim($_POST['nombre_empresa'] ?? '');
    $cliente->alias          = trim($_POST['alias'] ?? '');
    $cliente->rfc            = trim($_POST['rfc'] ?? '');
    $cliente->telefono       = trim($_POST['telefono'] ?? '');
    $cliente->email          = trim($_POST['email'] ?? '');
    $cliente->ubicacion      = trim($_POST['ubicacion'] ?? '');
    $cliente->vip            = isset($_POST['vip']) ? 1 : 0;
    $cliente->descuento_pct  = $_POST['descuento_pct'] !== '' ? (float) $_POST['descuento_pct'] : 0;
    $cliente->notas          = trim($_POST['notas'] ?? '');

    $errores = $cliente->validar();

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
            $cliente->foto = $foto;
        }
    }

    if (empty($errores) && $cliente->actualizar()) {
        // La foto anterior sólo se borra cuando ya quedó sustituida.
        if ($cliente->foto !== $fotoPrevia) {
            \App\Imagenes::eliminar($fotoPrevia ?: null);
        }

        // Éxito: alerta visual que se muestra al cargar la lista.
        redirigir('/admin/clientes', 'exito', 'Cliente actualizado correctamente.');
    }

    // Si llegamos aquí, la validación o la escritura fallaron.
    $errores = $errores ?: \App\Clientes::getErrores();
}

template('header', [
    'titulo'     => 'Editar cliente',
    'subtitulo'  => 'Módulo de clientes',
    'rutaActiva' => '/admin/clientes',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">Modifica los datos de <strong><?= escaparValores($cliente->nombre) ?></strong>.</p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/clientes">Volver</a>
        <a class="btn btn--secundario" href="/admin/clientes/detalle?id=<?= (int) $cliente->id ?>">Ver detalle</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/clientes/editar?id=<?= (int) $cliente->id ?>" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioClientes', ['cliente' => $cliente, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>
