<?php
/**
 * views/admin/entregas/entregar.php — Registro de una entrega (total o parcial) de UNA venta.
 *
 * No toca el inventario (la salida ya ocurrió con la venta). El token de un
 * solo uso evita duplicar la entrega si se reenvía el formulario.
 * Junto con las piezas se adjuntan (opcional) una o varias fotos del acta:
 * el campo múltiple llega como $_FILES['fotos'] y Entregas::registrarEntrega
 * las guarda con la entrega, o no se guarda nada.
 * Si viene `volver=seguimiento` (modal del hub de Operación Comercial), al
 * terminar se regresa al seguimiento de la venta en vez de la ruta de preparación.
 */

soloAdmin();

$ventaId = (int) ($_GET['venta'] ?? $_POST['venta'] ?? 0);
$prepId  = (int) ($_GET['prep'] ?? $_POST['prep'] ?? 0) ?: null;
$venta   = \App\Ventas::buscarID($ventaId);

if (!$venta) {
    paginaNoEncontrada();
}

$alSeguimiento = (string) ($_GET['volver'] ?? $_POST['volver'] ?? '') === 'seguimiento';
$volver = $alSeguimiento
    ? '/admin/entregas-seguimiento/venta?id=' . $ventaId
    : ($prepId ? '/admin/entregas/detalle?id=' . $prepId : '/admin/entregas/clientes');

if ($venta->estado !== 'registrada') {
    redirigir($volver, 'error', 'Solo se entregan ventas registradas.');
}

$errores = [];

// PHP descartó el cuerpo entero por post_max_size: llega un POST sin campos
// (ni el token). Se explica en vez de reabrir el formulario como si nada.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $errores[] = 'La carga superó el límite del servidor: las fotos juntas no pueden exceder '
        . \App\Entregas::MAX_FOTOS_TOTAL_MB . ' MB. Reduce la cantidad o el tamaño e inténtalo de nuevo.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $piezas = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $productoId => $cantidad) {
        $piezas[(int) $productoId] = (int) $cantidad;
    }

    // Una o varias fotos del acta: el campo múltiple llega como $_FILES['fotos'].
    $fotos = \App\Archivos::listaDeSubidas($_FILES['fotos'] ?? null);

    if (\App\Entregas::registrarEntrega(
        $ventaId, $prepId, $piezas, (string) ($_POST['fecha'] ?? ''), (string) ($_POST['token'] ?? ''),
        (string) ($_POST['notas'] ?? ''), (int) ($_SESSION['id'] ?? 0), $fotos
    )) {
        $conFotos = \App\Entregas::$fotosGuardadas;
        redirigir($volver, 'exito', 'Entrega registrada. El inventario no cambió.'
            . ($conFotos ? ' Se guardaron ' . $conFotos . ' foto' . ($conFotos === 1 ? '' : 's') . ' del acta.' : ''));
    }
    $errores = \App\Entregas::getErrores();
}

$lineas    = array_values(\App\Entregas::pendientesDeVentas([$ventaId])[$ventaId] ?? []);
$historial = \App\Entregas::entregasDeVenta($ventaId);
$token     = bin2hex(random_bytes(16));

template('header', ['titulo' => 'Entrega · ' . $venta->folio, 'subtitulo' => $venta->cliente_nombre, 'rutaActiva' => '/admin/entregas']);
?>

<section class="tarjeta mb-lg">
    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <?php template('formEntregaVenta', [
        'ventaId'      => $ventaId,
        'lineas'       => $lineas,
        'token'        => $token,
        'volver'       => $alSeguimiento ? 'seguimiento' : '',
        'prepId'       => (int) ($prepId ?? 0),
        'conAcciones'  => true,
        'hrefCancelar' => $volver,
    ]); ?>
</section>

<?php if ($historial) : ?>
    <section class="tarjeta tarjeta--sin-relleno">
        <h2 class="titulo-5" style="padding:1rem 1rem 0">Entregas anteriores de esta venta</h2>
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr><th scope="col">Fecha</th><th scope="col">Piezas</th><th scope="col">Registró</th></tr></thead>
                <tbody>
                    <?php foreach ($historial as $h) : ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($h['fecha'])) ?></td>
                            <td><?php foreach ($h['renglones'] as $r) : ?><?= (int) $r['cantidad'] ?> × <?= escaparValores($r['producto_nombre']) ?><br><?php endforeach; ?></td>
                            <td><?= escaparValores($h['usuario_nombre'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
