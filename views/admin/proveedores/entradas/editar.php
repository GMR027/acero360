<?php
/**
 * views/admin/proveedores/entradas/editar.php — Modificación de una entrada.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 */

soloAdmin();

/** Entrada a editar. Si no existe, se muestra el 404. */
$entrada = \App\ProveedorEntradas::buscarID((int) ($_GET['id'] ?? 0));

if (!$entrada) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$proveedores = \App\Proveedores::paraSelector();
$errores     = [];
$esEnvio     = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);
$fotoPrevia  = $entrada->foto;

if ($esEnvio) {
    $entrada->proveedor_id  = (int) ($_POST['proveedor_id'] ?? 0);
    $entrada->fecha         = trim($_POST['fecha'] ?? '');
    $entrada->numero_pedido = trim($_POST['numero_pedido'] ?? '');
    $entrada->categoria     = (int) ($_POST['categoria'] ?? 0);
    $entrada->monto         = (float) ($_POST['monto'] ?? 0);
    $entrada->descripcion   = trim($_POST['descripcion'] ?? '');
    $entrada->notas         = trim($_POST['notas'] ?? '');

    $errores = $entrada->validar();

    // Foto de entrega: archivo nuevo, casilla de quitar, o nada que tocar.
    if (empty($errores)) {
        $foto = \App\Imagenes::subirInforme(
            $_FILES['foto'] ?? null,
            !empty($_POST['quitar_foto']),
            $errores
        );

        if ($foto !== null) {
            $entrada->foto = $foto;
        }
    }

    if (empty($errores) && $entrada->actualizar()) {
        // La foto anterior sólo se borra cuando ya quedó sustituida.
        if ($entrada->foto !== $fotoPrevia) {
            \App\Imagenes::eliminar($fotoPrevia ?: null);
        }

        redirigir(
            '/admin/proveedores/entradas/detalle?id=' . (int) $entrada->id,
            'exito',
            'Entrada actualizada correctamente.'
        );
    }

    $errores = $errores ?: \App\ProveedorEntradas::getErrores();
}

template('header', [
    'titulo'    => 'Editar entrada',
    'subtitulo' => 'Reportes de proveedores',
    'rutaActiva' => '/admin/proveedores',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores/entradas">Volver</a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Modifica la entrada de <strong><?= escaparValores($entrada->proveedor_nombre) ?></strong>
            del <?= date('d/m/Y', strtotime($entrada->fecha)) ?>.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/proveedores/entradas/detalle?id=<?= (int) $entrada->id ?>">
            Ver detalle
        </a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/proveedores/entradas/editar?id=<?= (int) $entrada->id ?>"
          method="post" enctype="multipart/form-data" data-validar novalidate>
        <?php
        template('formularioProveedorEntradas', [
            'entrada'     => $entrada,
            'proveedores' => $proveedores,
            'errores'     => $errores,
        ]);
        ?>
    </form>
</section>

<?php template('footer'); ?>
