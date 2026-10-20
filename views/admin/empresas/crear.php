<?php
/**
 * views/admin/empresas/crear.php — Alta de negocios.
 *
 * El formulario admite carga de logo, así que necesita multipart/form-data.
 * Alta y edición comparten templates/formularioEmpresas.php.
 */

soloAdmin();

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $empresa = new \App\Empresas([
        'nombre'             => trim($_POST['nombre'] ?? ''),
        'rfc'                => mb_strtoupper(trim($_POST['rfc'] ?? '')),
        'regimen'            => trim($_POST['regimen'] ?? ''),
        'direccion'          => trim($_POST['direccion'] ?? ''),
        'ciudad'             => trim($_POST['ciudad'] ?? ''),
        'estado'             => trim($_POST['estado'] ?? ''),
        'cp'                 => trim($_POST['cp'] ?? ''),
        'telefono'           => trim($_POST['telefono'] ?? ''),
        'email'              => trim($_POST['email'] ?? ''),
        'condiciones_envio'  => trim($_POST['condiciones_envio'] ?? ''),
        'condiciones_compra' => trim($_POST['condiciones_compra'] ?? ''),
        'activa'             => isset($_POST['activa']) ? 1 : 0,
    ]);

    $errores = $empresa->validar();

    // Subida del logo: si falla, se avisa sin borrar los demás datos.
    $archivo = $_FILES['logo'] ?? null;
    if (is_array($archivo) && !empty($archivo['tmp_name'])) {
        $logo = \App\Imagenes::subir($archivo, 'logo', 'logo');
        if ($logo !== null) {
            $empresa->logo = $logo;
        } else {
            $errores[] = \App\Imagenes::ultimoError();
        }
    }

    if (empty($errores) && $empresa->crear()) {
        redirigir('/admin/empresas', 'exito', 'Negocio registrado correctamente.');
    }

    $errores = $errores ?: \App\Empresas::getErrores();
} else {
    $empresa = new \App\Empresas();
}

template('header', [
    'titulo'     => 'Nuevo negocio',
    'subtitulo'  => 'Módulo de empresas',
    'rutaActiva' => '/admin/ajustes',
]);
?>

<section class="encabezado-pagina">
    <div>
        <!-- <h1 class="titulo-2">Nuevo negocio</h1> -->
        <p class="texto-secundario">
            Registra la razón social que emitirá las cotizaciones. Los campos marcados con
            asterisco son obligatorios.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/empresas">Volver</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/empresas/crear" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioEmpresas', ['empresa' => $empresa, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>
