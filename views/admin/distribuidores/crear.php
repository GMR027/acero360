<?php
/** views/admin/distribuidores/crear.php — Alta de un distribuidor. */

soloAdmin();

$errores = [];
$empresas = \App\Empresas::paraSelector();
$distribuidor = new \App\Distribuidores([
    // Por omisión, el negocio del sitio público.
    'empresa_id' => (function (array $empresas): ?int {
        foreach ($empresas as $empresa) {
            if ($empresa['nombre'] === 'Soluciones en Acero') {
                return $empresa['id'];
            }
        }
        return $empresas[0]['id'] ?? null;
    })($empresas),
    'municipio' => trim($_GET['municipio'] ?? ''),
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $errores = $distribuidor->sincronizar($_POST, $_FILES);
    $errores = array_merge($errores, $distribuidor->validar());
    $guardado = empty($errores) && $distribuidor->crear();
    $distribuidor->cerrarFormulario($guardado);
    if ($guardado) {
        redirigir('/admin/distribuidores', 'exito', 'Distribuidor «' . $distribuidor->nombre . '» agregado.');
    }
    $errores = $errores ?: \App\Distribuidores::getErrores();
}

$municipios = \App\Distribuidores::municipios();

template('header', [
    'titulo'     => 'Nuevo distribuidor',
    'subtitulo'  => 'Distribuidores',
    'rutaActiva' => '/admin/distribuidores',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/distribuidores">Volver</a>',
]);
?>
<section class="tarjeta">
    <form class="formulario" action="/admin/distribuidores/crear" method="post" enctype="multipart/form-data" data-validar novalidate>
        <?php require __DIR__ . '/formulario.php'; ?>
    </form>
</section>
<?php template('footer'); ?>
