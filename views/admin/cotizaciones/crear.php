<?php
/**
 * views/admin/cotizaciones/crear.php — Alta de una cotización.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * El alta y la edición comparten templates/formularioCotizaciones.php.
 *
 * El cotizador del navegador calcula los importes sólo como vista previa:
 * aquí se vuelven a recalcular con Cotizaciones::calcular() antes de guardar.
 */

soloAdmin();

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
        'estado'             => 'pendiente',
    ];

    $lineas = \App\Cotizaciones::leerLineasPost($_POST);

    $errores = \App\Cotizaciones::validar($datos, $lineas);

    if (empty($errores) && \App\Cotizaciones::crear($datos, $lineas, (int) ($_SESSION['id'] ?? 0))) {
        $folio = '';
        $nueva = \App\Cotizaciones::buscarID(\App\Cotizaciones::$ultimoId);
        if ($nueva) {
            $folio = $nueva->folio;
        }

        redirigir(
            '/admin/cotizaciones/detalle?id=' . \App\Cotizaciones::$ultimoId,
            'exito',
            'Cotización ' . $folio . ' creada correctamente.'
        );
    }

    $errores = $errores ?: \App\Cotizaciones::getErrores();

    // Se reconstruye el formulario con lo capturado y los renglones cargados.
    $cotizacion = new \App\Cotizaciones($datos);
    $descuentoGlobal = $datos['descuento_pct'];
    $lineasIniciales = \App\Cotizaciones::lineasDesdePost($lineas);
} else {
    // Primera carga: negocio por defecto, condiciones precargadas y fecha de hoy.
    $primera = $empresas[0] ?? null;

    $cotizacion = new \App\Cotizaciones([
        'empresa_id'         => $primera ? (int) $primera->id : 0,
        'fecha'              => date('Y-m-d'),
        'vigencia_dias'      => 15,
        'aplica_iva'         => 1,
        'condiciones_envio'  => $primera ? (string) $primera->condiciones_envio : '',
        'condiciones_compra' => $primera ? (string) $primera->condiciones_compra : '',
    ]);

    $descuentoGlobal = 0.0;
    $lineasIniciales = [];
}

template('header', [
    'titulo'     => 'Nueva cotización',
    'subtitulo'  => 'Generador de cotización',
    'rutaActiva' => '/admin/cotizaciones/crear',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/cotizaciones">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">receipt_long</span>'
        . '<span>Histórico</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <!-- <h1 class="titulo-2">Nueva cotización</h1> -->
        <p class="texto-secundario">
            Elige el negocio y el cliente, carga los productos y ajusta precios, cantidades,
            descuentos e IVA. El folio se asigna solo al guardar.
        </p>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <form class="formulario cotizador" action="/admin/cotizaciones/crear" method="post"
          data-validar novalidate data-cotizador>
        <?php template('formularioCotizaciones', [
            'modo'            => 'crear',
            'cotizacion'      => $cotizacion,
            'errores'         => $errores,
            'empresas'        => $empresas,
            'clientes'        => $clientes,
            'catalogo'        => $catalogo,
            'lineasIniciales' => $lineasIniciales,
            'descuentoGlobal' => $descuentoGlobal,
        ]); ?>

        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/cotizaciones">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-cotizador-guardar>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">save</span>
                <span>Guardar cotización</span>
            </button>
        </div>
    </form>
</section>

<?php template('footer'); ?>
