<?php
/**
 * views/admin/pedidos/formulario.php — Lógica compartida de alta y edición.
 *
 * Lo incluyen crear.php y editar.php tras fijar $modo y, en edición, $pedido.
 * Reutiliza el cotizador (templates/formularioCotizaciones.php, documento
 * 'pedido'): precio de lista o especial del cliente, descuento e IVA.
 */

soloAdmin();

$esEdicion = $modo === 'editar';
$errores   = [];

$empresas = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static fn ($e): bool => (int) $e->activa === 1
));
$clientes = \App\Clientes::mostrarTodos(1000, 0, '');
$catalogo = \App\Productos::paraCotizador();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $datos = [
        'empresa_id'         => (int) ($_POST['empresa_id'] ?? 0),
        'cliente_id'         => (int) ($_POST['cliente_id'] ?? 0),
        'fecha'              => trim((string) ($_POST['fecha'] ?? '')),
        'aplica_iva'         => isset($_POST['aplica_iva']) ? 1 : 0,
        'descuento_pct'      => is_numeric($_POST['descuento_pct'] ?? null) ? (float) $_POST['descuento_pct'] : 0.0,
        'condiciones_envio'  => (string) ($_POST['condiciones_envio'] ?? ''),
        'condiciones_compra' => (string) ($_POST['condiciones_compra'] ?? ''),
        'comentarios'        => (string) ($_POST['comentarios'] ?? ''),
    ];
    $lineas  = \App\Cotizaciones::leerLineasPost($_POST);
    $errores = \App\Pedidos::validar($datos, $lineas);

    if (empty($errores)) {
        $usuarioId = (int) ($_SESSION['id'] ?? 0);
        $ok = $esEdicion
            ? \App\Pedidos::actualizar((int) $pedido->id, $datos, $lineas)
            : \App\Pedidos::crear($datos, $lineas, $usuarioId);
        if ($ok) {
            $id = $esEdicion ? (int) $pedido->id : (int) \App\Pedidos::$ultimoId;
            $guardado = \App\Pedidos::buscarID($id);
            redirigir(
                '/admin/pedidos?empresa=' . (int) $datos['empresa_id'],
                'exito',
                'Pedido ' . ($guardado ? $guardado->folio : '') . ($esEdicion ? ' actualizado.' : ' creado.')
            );
        }
        $errores = \App\Pedidos::getErrores();
    }

    $base = $esEdicion ? ['id' => $pedido->id, 'folio' => $pedido->folio, 'estado' => $pedido->estado] : [];
    $pedido = new \App\Pedidos(array_merge($base, $datos));
    $descuentoGlobal = $datos['descuento_pct'];
    $lineasIniciales = \App\Cotizaciones::lineasDesdePost($lineas);
} elseif ($esEdicion) {
    $lineasIniciales = \App\Cotizaciones::lineasDesdeDetalles(\App\Pedidos::detalles((int) $pedido->id));
    $descuentoGlobal = (float) $pedido->descuento_pct;
} else {
    $empresaId = (int) ($_GET['empresa'] ?? 0);
    $elegida = null;
    foreach ($empresas as $e) {
        if ((int) $e->id === $empresaId) {
            $elegida = $e;
        }
    }
    $elegida = $elegida ?? ($empresas[0] ?? null);
    $pedido = new \App\Pedidos([
        'empresa_id'         => $elegida ? (int) $elegida->id : 0,
        'cliente_id'         => (int) ($_GET['cliente'] ?? 0),
        'fecha'              => date('Y-m-d'),
        'aplica_iva'         => 1,
        'condiciones_envio'  => $elegida ? (string) $elegida->condiciones_envio : '',
        'condiciones_compra' => $elegida ? (string) $elegida->condiciones_compra : '',
    ]);
    $descuentoGlobal = 0.0;
    $lineasIniciales = [];
}

template('header', [
    'titulo'     => $esEdicion ? 'Editar pedido ' . $pedido->folio : 'Nuevo pedido',
    'subtitulo'  => 'Pedidos de clientes',
    'rutaActiva' => '/admin/pedidos',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/pedidos">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>'
        . '<span>Pedidos</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Elige el negocio y el cliente y carga los productos con la cantidad que pidió. Los precios
            salen de la lista o del precio especial del cliente. Después, desde la tabla de pedidos,
            márcalo como cotización o como venta.
        </p>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <form class="formulario cotizador" method="post" data-validar novalidate data-cotizador
          action="<?= $esEdicion ? '/admin/pedidos/editar?id=' . (int) $pedido->id : '/admin/pedidos/crear' ?>">
        <?php template('formularioCotizaciones', [
            'modo'            => $modo,
            'documento'       => 'pedido',
            'cotizacion'      => $pedido,
            'errores'         => $errores,
            'empresas'        => $empresas,
            'clientes'        => $clientes,
            'catalogo'        => $catalogo,
            'lineasIniciales' => $lineasIniciales,
            'descuentoGlobal' => $descuentoGlobal,
        ]); ?>

        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/pedidos">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-cotizador-guardar>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">save</span>
                <span>Guardar pedido</span>
            </button>
        </div>
    </form>
</section>

<?php template('footer'); ?>
