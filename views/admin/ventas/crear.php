<?php
/**
 * views/admin/ventas/crear.php — Alta de una venta directa (sin cotización).
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Comparte templates/formularioCotizaciones.php con las cotizaciones.
 * Al guardar descuenta el inventario; si no alcanza queda en negativo y
 * se avisa (a veces se vende y se fabrica en unos días).
 *
 * El cotizador del navegador calcula los importes sólo como vista previa:
 * aquí se vuelven a recalcular con Cotizaciones::calcular() antes de guardar.
 */

soloAdmin();

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

/** Negocios activos (el que emite la venta). */
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
        'aplica_iva'         => isset($_POST['aplica_iva']) ? 1 : 0,
        'descuento_pct'      => is_numeric($_POST['descuento_pct'] ?? null)
            ? (float) $_POST['descuento_pct'] : 0.0,
        'condiciones_envio'  => (string) ($_POST['condiciones_envio'] ?? ''),
        'condiciones_compra' => (string) ($_POST['condiciones_compra'] ?? ''),
        'comentarios'        => (string) ($_POST['comentarios'] ?? ''),
    ];

    $lineas = \App\Cotizaciones::leerLineasPost($_POST);

    $errores = \App\Ventas::validar($datos, $lineas);

    // Faltantes ANTES de descontar: después el stock ya estaría restado.
    $faltantes = empty($errores) ? \App\Inventario::faltantesDeLineas($lineas) : [];

    if (empty($errores) && \App\Ventas::crear($datos, $lineas, (int) ($_SESSION['id'] ?? 0))) {
        $nueva = \App\Ventas::buscarID(\App\Ventas::$ultimoId);
        $folio = $nueva ? $nueva->folio : '';

        if (!empty($faltantes)) {
            $partes = array_map(static function (array $f): string {
                return sprintf('«%s» pidió %d y había %d', $f['nombre'], $f['requerido'], $f['disponible']);
            }, $faltantes);
            agregarAlerta(
                'advertencia',
                'Inventario insuficiente: ' . implode('; ', $partes)
                . '. Quedó en negativo hasta que se cargue el material.'
            );
        }

        redirigir(
            '/admin/ventas/detalle?id=' . \App\Ventas::$ultimoId,
            'exito',
            'Venta ' . $folio . ' registrada correctamente.'
        );
    }

    $errores = $errores ?: \App\Ventas::getErrores();

    // Se reconstruye el formulario con lo capturado y los renglones cargados.
    $cotizacion = new \App\Ventas($datos);
    $descuentoGlobal = $datos['descuento_pct'];
    $lineasIniciales = \App\Cotizaciones::lineasDesdePost($lineas);
} else {
    // Primera carga: negocio por defecto, condiciones precargadas y fecha de hoy.
    $primera = $empresas[0] ?? null;

    $cotizacion = new \App\Ventas([
        'empresa_id'         => $primera ? (int) $primera->id : 0,
        'fecha'              => date('Y-m-d'),
        'aplica_iva'         => 1,
        'condiciones_envio'  => $primera ? (string) $primera->condiciones_envio : '',
        'condiciones_compra' => $primera ? (string) $primera->condiciones_compra : '',
    ]);

    $descuentoGlobal = 0.0;
    $lineasIniciales = [];
}

template('header', [
    'titulo'     => 'Nueva venta',
    'subtitulo'  => 'Venta directa',
    'rutaActiva' => '/admin/ventas',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/ventas">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">point_of_sale</span>'
        . '<span>Ventas</span></a>',
]);
?>

<section class="encabezado-pagina">
    <div>
        <!-- <h1 class="titulo-2">Nueva cotización</h1> -->
        <p class="texto-secundario">
            Registra una venta que no pasó por cotización: negocio, cliente, fecha, productos y
            términos. Al guardar se descuenta el inventario (si falta material queda en negativo).
        </p>
    </div>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <form class="formulario cotizador" action="/admin/ventas/crear" method="post"
          data-validar novalidate data-cotizador>
        <?php template('formularioCotizaciones', [
            'modo'            => 'crear',
            'documento'       => 'venta',
            'cotizacion'      => $cotizacion,
            'errores'         => $errores,
            'empresas'        => $empresas,
            'clientes'        => $clientes,
            'catalogo'        => $catalogo,
            'lineasIniciales' => $lineasIniciales,
            'descuentoGlobal' => $descuentoGlobal,
        ]); ?>

        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/ventas">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-cotizador-guardar>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">save</span>
                <span>Registrar venta</span>
            </button>
        </div>
    </form>
</section>

<?php template('footer'); ?>
