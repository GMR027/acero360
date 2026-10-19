<?php
/**
 * views/admin/proveedores/entradas/crear.php — Registro de una entrada de
 * material (reporte fotográfico).
 *
 * Ruta protegida por el middleware "soloAdmin".
 * El alta y la edición comparten el mismo formulario:
 * templates/formularioProveedorEntradas.php
 */

soloAdmin();

$proveedores = \App\Proveedores::paraSelector();
$preseleccion = (int) ($_GET['proveedor_id'] ?? 0);

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $entrada = new \App\ProveedorEntradas([
        'proveedor_id'  => (int) ($_POST['proveedor_id'] ?? 0),
        'fecha'         => trim($_POST['fecha'] ?? ''),
        'numero_pedido' => trim($_POST['numero_pedido'] ?? ''),
        'categoria'     => (int) ($_POST['categoria'] ?? 0),
        'monto'         => $_POST['monto'] ?? '',
        'descripcion'   => trim($_POST['descripcion'] ?? ''),
        'notas'         => trim($_POST['notas'] ?? ''),
        'usuario_id'    => (int) ($_SESSION['id'] ?? 0),
    ]);

    $errores = $entrada->validar();

    // La fotografía sólo se procesa si los demás datos ya son válidos,
    // para no dejar un archivo sin referencia cuando el envío se rechaza.
    $foto = null;
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

    if (empty($errores) && $entrada->crear()) {
        redirigir(
            '/admin/proveedores/entradas/detalle?id=' . \App\ProveedorEntradas::$ultimoId,
            'exito',
            'Entrada registrada correctamente.'
        );
    }

    // Si se llegó hasta aquí, algo falló: la foto subida no tiene dueño.
    if (is_string($foto) && $foto !== '') {
        \App\Imagenes::eliminar($foto);
    }

    $errores = $errores ?: \App\ProveedorEntradas::getErrores();
} else {
    $entrada = new \App\ProveedorEntradas([
        'fecha'       => date('Y-m-d'),
        'proveedor_id' => $preseleccion,
    ]);
}

template('header', [
    'titulo'    => 'Nueva entrada',
    'subtitulo' => 'Reportes de proveedores',
    'rutaActiva' => '/admin/proveedores',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores/entradas">Volver</a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Documenta la entrega de material: encabezado del proveedor, descripción, fotografía
            y notas adicionales.
        </p>
    </div>
</section>

<?php if (empty($proveedores)) : ?>
    <!-- Sin proveedores todavía: no se puede abrir una entrada. -->
    <section class="tarjeta">
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">local_shipping</span>
            <p class="vacio__titulo">Primero registra un proveedor</p>
            <p>Una entrada siempre apunta a un proveedor del directorio.</p>
            <a class="btn btn--primario" href="/admin/proveedores/crear">Nuevo proveedor</a>
        </div>
    </section>
<?php else : ?>
    <section class="tarjeta">
        <form class="formulario" action="/admin/proveedores/entradas/crear" method="post"
              enctype="multipart/form-data" data-validar novalidate>
            <?php
            template('formularioProveedorEntradas', [
                'entrada'     => $entrada,
                'proveedores' => $proveedores,
                'errores'     => $errores,
            ]);
            ?>
        </form>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
