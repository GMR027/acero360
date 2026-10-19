<?php
/**
 * views/admin/inventario/ajustar.php — Carga manual de existencias.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 * Fija el stock de UN producto al valor escrito por el administrador:
 * si sube queda como "Entrada manual" y si baja como "Ajuste manual",
 * siempre con su registro en la bitácora (Inventario::ajustar()).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$productoId = (int) ($_POST['producto_id'] ?? 0);
$stockCrudo = trim((string) ($_POST['stock'] ?? ''));

/** Vuelve al listado conservando los filtros que tenía el usuario. */
$regresar = static function () : string {
    $parametros = [];
    if (trim((string) ($_POST['q'] ?? '')) !== '') {
        $parametros['q'] = trim((string) $_POST['q']);
    }
    if ((int) ($_POST['empresa'] ?? 0) > 0) {
        $parametros['empresa'] = (int) $_POST['empresa'];
    }
    if (($_POST['estado'] ?? 'todos') !== 'todos') {
        $parametros['estado'] = (string) $_POST['estado'];
    }
    if ((int) ($_POST['pagina'] ?? 1) > 1) {
        $parametros['pagina'] = (int) $_POST['pagina'];
    }

    return '/admin/inventario' . (!empty($parametros) ? '?' . http_build_query($parametros) : '');
};

$ruta = $regresar();

$producto = \App\Productos::buscarID($productoId);

if (!$producto) {
    redirigir($ruta, 'error', 'El producto seleccionado ya no existe.');
}

if ($stockCrudo === '' || !preg_match('/^-?\d+$/', $stockCrudo)) {
    redirigir($ruta, 'error', 'Indica la cantidad en inventario (un número entero).');
}

$stock = (int) $stockCrudo;

if ($stock < -99999 || $stock > 99999) {
    redirigir($ruta, 'error', 'La cantidad debe estar entre -99,999 y 99,999 piezas.');
}

if (\App\Inventario::ajustar($productoId, $stock, (int) ($_SESSION['id'] ?? 0))) {
    redirigir(
        $ruta,
        'exito',
        'Inventario de «' . $producto->nombre . '» actualizado a ' . number_format($stock) . ' piezas.'
    );
}

$errores = \App\Inventario::getErrores();
redirigir($ruta, 'error', $errores[0] ?? 'No fue posible actualizar el inventario.');
