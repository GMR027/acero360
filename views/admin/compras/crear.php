<?php
/**
 * views/admin/compras/crear.php — Nueva compra (semiterminado o insumo). No toca el inventario.
 */

soloAdmin();

$errores = [];
$orden   = ['tipo' => 'semiterminado', 'proveedor_id' => (int) ($_GET['proveedor'] ?? 0), 'fecha_solicitud' => date('Y-m-d')];
$lineas  = [];

/* «Iniciar una compra nueva» desde Compras Inteligentes: se precarga el encabezado
 * y las partidas con lo que decía el XML. Nada se guarda hasta que se envía el
 * formulario y, al guardar, se adjunta el XML si la lectura sigue pendiente. */
$compraIntel = (int) ($_REQUEST['compra_intel'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $compraIntel > 0) {
    $propuesta = \App\ComprasIntel::propuestaOrden($compraIntel);
    if ($propuesta === null) {
        agregarAlerta('advertencia', 'No se pudo precargar la factura (¿ya se usó?): captura la compra a mano.');
        $compraIntel = 0;
    } else {
        $orden  = array_merge($orden, $propuesta['orden']);
        $lineas = $propuesta['lineas'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $orden  = \App\OrdenesCompra::encabezadoDePost($_POST);
    $lineas = \App\OrdenesCompra::lineasDePost($_POST);
    $compraIntel = (int) ($_POST['compra_intel'] ?? 0);

    if (\App\OrdenesCompra::crear($orden, $lineas, (int) ($_SESSION['id'] ?? 0))) {
        $mensaje = 'Compra registrada. El inventario no se modificó: solo cambia al recibir.';
        // Si nació desde Compras Inteligentes, se adjunta el XML a la compra recién creada.
        if ($compraIntel > 0 && \App\ComprasIntel::relacionar($compraIntel, \App\OrdenesCompra::$ultimoId, (int) ($_SESSION['id'] ?? 0))) {
            $mensaje = 'Compra registrada con su factura XML adjunta (y en contabilidad una sola vez). El inventario no se modificó: solo cambia al recibir.';
        } elseif ($compraIntel > 0) {
            $mensaje .= ' La factura no se pudo adjuntar: ' . implode(' · ', \App\ComprasIntel::getErrores());
        }
        redirigir('/admin/compras/detalle?id=' . \App\OrdenesCompra::$ultimoId, 'exito', $mensaje);
    }
    $errores = \App\OrdenesCompra::getErrores();
}

template('header', ['titulo' => 'Nueva compra', 'subtitulo' => 'Compras y proveedores']);
template('tabsCompras', ['tab' => 'pedidos']);
?>

<section class="tarjeta">
    <form class="formulario" action="/admin/compras/crear" method="post" novalidate>
        <?php if ($compraIntel > 0) : ?>
            <input type="hidden" name="compra_intel" value="<?= $compraIntel ?>">
        <?php endif; ?>
        <?php template('formularioOrdenCompra', [
            'orden' => $orden, 'lineas' => $lineas, 'errores' => $errores,
            'proveedores' => \App\Proveedores::paraSelector(),
            'catalogo' => \App\Productos::paraCotizador(),
            'categorias' => \App\CategoriasProveedor::mapa(),
            'empresas' => \App\Empresas::paraSelector(),
            'materiales' => \App\Materiales::listar(),
            'catalogos' => \App\ProductosProveedor::paraFormulario(array_column($lineas, 'proveedor_producto_id')),
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>
