<?php
/**
 * views/admin/cotizaciones/editar.php — Modificación de una cotización.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Reutiliza templates/formularioCotizaciones.php junto con el alta.
 *
 * Los renglones se reconstruyen enteros al guardar: así las altas, bajas y
 * cambios de cantidad o precio quedan reflejados sin sobras. Los precios
 * especiales del cliente sólo se actualizan si el renglón NO está marcado
 * como "No recordar este precio".
 */

soloAdmin();

$id      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$previa  = \App\Cotizaciones::buscarID($id);

if (!$previa) {
    // Igual que el resto del panel: un folio inexistente es una 404.
    paginaNoEncontrada();
}

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

/** Negocios activos (el que emite la cotización). */
$empresas = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static function ($empresa): bool {
        return (int) $empresa->activa === 1;
    }
));

/** Todos los clientes, ordenados con los VIP primero. */
$clientes = \App\Clientes::mostrarTodos(1000, 0, '');

/** Productos activos del catálogo, con su precio de lista. */
$catalogo = \App\Productos::paraCotizador();

if ($esEnvio) {
    $datos = [
        'id'                 => $id,
        'empresa_id'         => (int) ($_POST['empresa_id'] ?? 0),
        'cliente_id'         => (int) ($_POST['cliente_id'] ?? 0),
        'fecha'              => trim((string) ($_POST['fecha'] ?? '')),
        'vigencia_dias'      => (int) ($_POST['vigencia_dias'] ?? 15),
        'aplica_iva'         => isset($_POST['aplica_iva']) ? 1 : 0,
        'descuento_pct'      => is_numeric($_POST['descuento_pct'] ?? null)
            ? (float) $_POST['descuento_pct'] : 0.0,
        'condiciones_envio'  => (string) ($_POST['condiciones_envio'] ?? ''),
        'condiciones_compra' => (string) ($_POST['condiciones_compra'] ?? ''),
        'comentarios'        => (string) ($_POST['comentarios'] ?? ''),
        'estado'             => (string) ($_POST['estado'] ?? $previa->estado),
    ];

    $lineas = \App\Cotizaciones::leerLineasPost($_POST);

    $errores = \App\Cotizaciones::validar($datos, $lineas);

    if (empty($errores) && \App\Cotizaciones::actualizar($datos, $lineas)) {
        redirigir(
            '/admin/cotizaciones/detalle?id=' . $id,
            'exito',
            'Cotización ' . $previa->folio . ' actualizada correctamente.'
        );
    }

    $errores = $errores ?: \App\Cotizaciones::getErrores();

    $cotizacion      = new \App\Cotizaciones($datos);
    $cotizacion->folio = $previa->folio;
    $descuentoGlobal = $datos['descuento_pct'];
    $lineasIniciales = \App\Cotizaciones::lineasDesdePost($lineas);
} else {
    $detallesGuardados = \App\Cotizaciones::detalles($id);
    $cotizacion        = $previa;
    $lineasIniciales   = \App\Cotizaciones::lineasDesdeDetalles($detallesGuardados);
    $descuentoGlobal   = \App\Cotizaciones::desgloseDescuento($previa, $detallesGuardados)['pct'];
}

template('header', [
    'titulo'     => 'Editar cotización ' . $previa->folio,
    'subtitulo'  => 'Generador de cotización',
    'rutaActiva' => '/admin/cotizaciones',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/cotizaciones/detalle?id=' . $id . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">visibility</span>'
        . '<span>Ver detalle</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Modificá renglones, precios, cantidades y condiciones. Al guardar se recalculan
            todos los importes y se conserva el folio original.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/cotizaciones">Histórico</a>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <form class="formulario cotizador" action="/admin/cotizaciones/editar" method="post"
          data-validar novalidate data-cotizador>
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <?php template('formularioCotizaciones', [
            'modo'            => 'editar',
            'cotizacion'      => $cotizacion,
            'errores'         => $errores,
            'empresas'        => $empresas,
            'clientes'        => $clientes,
            'catalogo'        => $catalogo,
            'lineasIniciales' => $lineasIniciales,
            'descuentoGlobal' => $descuentoGlobal,
        ]); ?>

        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/cotizaciones/detalle?id=<?= (int) $id ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-cotizador-guardar>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">save</span>
                <span>Guardar cambios</span>
            </button>
        </div>
    </form>
</section>

<?php template('footer'); ?>
