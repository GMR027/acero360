<?php
/**
 * views/admin/empresas/editar.php — Modificación de negocios.
 *
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 * Si no se adjunta un logo nuevo, se conserva el que ya tenía.
 */

soloAdmin();

$empresa = \App\Empresas::buscarID((int) ($_GET['id'] ?? 0));

if (!$empresa) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);
$logoPrevio = $empresa->logo;

if ($esEnvio) {
    $empresa->nombre             = trim($_POST['nombre'] ?? '');
    $empresa->rfc                = mb_strtoupper(trim($_POST['rfc'] ?? ''));
    $empresa->regimen            = trim($_POST['regimen'] ?? '');
    $empresa->direccion          = trim($_POST['direccion'] ?? '');
    $empresa->ciudad             = trim($_POST['ciudad'] ?? '');
    $empresa->estado             = trim($_POST['estado'] ?? '');
    $empresa->cp                 = trim($_POST['cp'] ?? '');
    $empresa->telefono           = trim($_POST['telefono'] ?? '');
    $empresa->email              = trim($_POST['email'] ?? '');
    $empresa->condiciones_envio  = trim($_POST['condiciones_envio'] ?? '');
    $empresa->condiciones_compra = trim($_POST['condiciones_compra'] ?? '');
    $empresa->activa             = isset($_POST['activa']) ? 1 : 0;

    $errores = $empresa->validar();

    // Sólo se procesa el logo si se adjuntó uno nuevo.
    $archivo = $_FILES['logo'] ?? null;
    if (is_array($archivo) && !empty($archivo['tmp_name'])) {
        $logo = \App\Imagenes::subir($archivo, 'logo', 'logo');
        if ($logo !== null) {
            $empresa->logo = $logo;
        } else {
            $errores[] = \App\Imagenes::ultimoError();
        }
    }

    if (empty($errores) && $empresa->actualizar()) {
        // La imagen anterior sólo se borra cuando ya quedó sustituida.
        if ($empresa->logo !== $logoPrevio) {
            \App\Imagenes::eliminar($logoPrevio ?: null);
        }
        redirigir('/admin/empresas', 'exito', 'Negocio actualizado correctamente.');
    }

    $errores = $errores ?: \App\Empresas::getErrores();
}

template('header', [
    'titulo'     => 'Editar negocio',
    'subtitulo'  => 'Módulo de empresas',
    'rutaActiva' => '/admin/ajustes',
]);
template('tabsAjustes', ['tab' => 'empresas']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Modifica los datos fiscales y las condiciones de <strong><?= escaparValores($empresa->nombre) ?></strong>.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/empresas">Volver</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/empresas/editar?id=<?= (int) $empresa->id ?>" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioEmpresas', ['empresa' => $empresa, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>
