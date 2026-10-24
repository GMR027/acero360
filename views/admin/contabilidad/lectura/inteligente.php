<?php

/**
 * views/admin/contabilidad/lectura/inteligente.php — Lectura desde los botones
 * «Registro Venta Inteligente» y «Registro Compra Inteligente».
 *
 * Ruta protegida por el middleware "soloAdmin". No devuelve HTML: sólo datos,
 * para que el navegador abra el formulario de venta o de gasto con lo leído
 * ya precargado. Nada se guarda como venta o gasto hasta que la persona
 * revisa y envía ese formulario.
 */

soloAdmin();

header('Content-Type: application/json; charset=utf-8');

/** Termina la petición con una respuesta JSON. */
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!consumirToken($_POST['token'] ?? null)) {
    $responder(['ok' => false, 'error' => 'Este documento ya se envió; elige el archivo otra vez.'], 409);
}

$destino = in_array($_POST['destino'] ?? '', ['venta', 'gasto'], true) ? $_POST['destino'] : '';
if ($destino === '') {
    $responder(['ok' => false, 'error' => 'Indica si el documento es de una venta o de un gasto.'], 422);
}

$id = \App\Lecturas::preparar(
    \App\Archivos::listaDeSubidas($_FILES['archivo'] ?? null),
    $destino,
    (int) ($_SESSION['id'] ?? 0)
);

if ($id === null) {
    $responder(['ok' => false, 'error' => implode(' · ', \App\Lecturas::getErrores()) ?: 'No fue posible leer el documento.'], 422);
}

$propuesta = \App\Lecturas::propuesta($id, $destino);
if ($propuesta === null) {
    $responder(['ok' => false, 'error' => 'El documento se leyó, pero no se pudo preparar la propuesta.'], 500);
}

$coincidencias = $propuesta['duplicados'] ?? [];
$destinoRuta = $destino === 'venta'
    ? '/admin/ventas/crear?lectura=' . $id
    : '/admin/contabilidad/gastos/crear?lectura=' . $id;

if ($coincidencias) {
    // Antes de abrir el formulario: el navegador muestra el aviso con un enlace
    // a cada registro que ya existe, y la persona decide si sigue o lo revisa.
    $responder([
        'ok'            => true,
        'duplicado'     => true,
        'ruta'          => $destinoRuta,
        'coincidencias' => $coincidencias,
    ]);
}

$responder([
    'ok'   => true,
    'ruta' => $destinoRuta,
]);
