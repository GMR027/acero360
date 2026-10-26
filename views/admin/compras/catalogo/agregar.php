<?php

/**
 * views/admin/compras/catalogo/agregar.php — Alta rápida de un concepto de una
 * factura leída en el catálogo del PROVEEDOR que la emitió.
 *
 * Ruta protegida por el middleware "soloAdmin". No devuelve HTML: sólo datos,
 * para que el botón de la pantalla de lectura agregue el renglón sin recargar.
 * Recargar ahí perdería lo que ya se capturó en el formulario del gasto.
 *
 * El llave anti-falsificación la pide verificarCsrf() en index.php (cabecera
 * X-CSRF-Token, la misma que usa el botón de registro inteligente); no lleva
 * token de un solo uso porque el botón se puede apretar varias veces.
 */

soloAdmin();

header('Content-Type: application/json; charset=utf-8');

/** Termina la petición con una respuesta JSON. */
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$proveedorId = (int) ($_POST['proveedor_id'] ?? 0);
$descripcion = trim((string) ($_POST['descripcion'] ?? ''));
$sku         = trim((string) ($_POST['sku'] ?? ''));
$nota        = trim((string) ($_POST['nota'] ?? ''));
$costo       = (float) str_replace(',', '.', (string) ($_POST['costo'] ?? '0'));

if ($proveedorId <= 0 || $descripcion === '') {
    $responder(['ok' => false, 'error' => 'Falta el proveedor o la descripción del concepto.'], 422);
}
if (!is_finite($costo) || $costo < 0) {
    $responder(['ok' => false, 'error' => 'El precio unitario del documento no es un número válido.'], 422);
}

$proveedor = \App\Proveedores::buscarID($proveedorId);
if (!$proveedor) {
    $responder(['ok' => false, 'error' => 'Ese proveedor ya no existe en tu directorio.'], 404);
}
if ((int) $proveedor->activo !== 1) {
    $responder(['ok' => false, 'error' => 'El proveedor está desactivado: no se le pueden agregar productos nuevos.'], 422);
}

$respuesta = \App\ProductosProveedor::agregarDesdeFactura($proveedorId, [
    'descripcion'    => $descripcion,
    'sku'            => $sku,
    'nota'           => $nota,
    'valor_unitario' => $costo,
], (int) ($_SESSION['id'] ?? 0));

if ($respuesta['estado'] === 'error') {
    $responder(['ok' => false, 'error' => $respuesta['error']], 422);
}

$responder([
    'ok'        => true,
    // 'creado' = se dio de alta ahora · 'existente' = ya estaba en su catálogo.
    'estado'    => $respuesta['estado'],
    'id'        => $respuesta['id'],
    'nombre'    => $respuesta['nombre'],
    'activo'    => $respuesta['activo'],
    'proveedor' => (string) $proveedor->nombre,
    'ruta'      => '/admin/compras/catalogo/detalle?id=' . $respuesta['id'],
    'ruta_listado' => '/admin/compras/catalogo?proveedor=' . $proveedorId,
]);
