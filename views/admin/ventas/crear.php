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

    /* Factura cargada con el botón «Registro Venta Inteligente» (Contabilidad):
     * se precarga lo que el motor documental leyó y pudo cotejar. Nada se guarda
     * hasta que la persona revisa los campos y envía el formulario. */
    $propuesta = null;
    $lecturaId = (int) ($_GET['lectura'] ?? 0);
    if ($lecturaId > 0) {
        $propuesta = \App\Lecturas::propuesta($lecturaId, 'venta');
        if ($propuesta === null) {
            agregarAlerta('advertencia', 'No se encontró la lectura #' . $lecturaId . ': captura la venta a mano.');
        }
    }

    // Negocio: el que emitió la factura; si no se identificó, el primero de la lista.
    $empresaPrecargada = 0;
    foreach ($empresas as $candidata) {
        if ($propuesta && $propuesta['empresa'] && (int) $candidata->id === (int) $propuesta['empresa']['id']) {
            $empresaPrecargada = (int) $candidata->id;
            break;
        }
    }
    if ($empresaPrecargada === 0) {
        $empresaPrecargada = $primera ? (int) $primera->id : 0;
    }
    $empresaElegida = null;
    foreach ($empresas as $candidata) {
        if ((int) $candidata->id === $empresaPrecargada) {
            $empresaElegida = $candidata;
            break;
        }
    }

    // Cliente: el RECEPTOR de la factura, cuando ya existe en la ficha.
    $clientePrecargado = 0;
    if ($propuesta && $propuesta['cliente']) {
        foreach ($clientes as $candidato) {
            if ((int) $candidato->id === (int) $propuesta['cliente']['id']) {
                $clientePrecargado = (int) $candidato->id;
                break;
            }
        }
    }

    // El descuento del documento se pasa a descuento global del cotizador.
    $descuentoGlobal = 0.0;
    if ($propuesta && $propuesta['subtotal'] > 0 && $propuesta['descuento'] > 0) {
        $descuentoGlobal = min(100.0, max(0.0, round($propuesta['descuento'] / $propuesta['subtotal'] * 100, 2)));
    }

    $cotizacion = new \App\Ventas([
        'empresa_id'         => $empresaPrecargada,
        'cliente_id'         => $clientePrecargado,
        'fecha'              => $propuesta ? $propuesta['fecha'] : date('Y-m-d'),
        'aplica_iva'         => $propuesta ? ($propuesta['iva'] > 0 ? 1 : 0) : 1,
        'condiciones_envio'  => $empresaElegida ? (string) $empresaElegida->condiciones_envio : '',
        'condiciones_compra' => $empresaElegida ? (string) $empresaElegida->condiciones_compra : '',
    ]);

    $lineasIniciales = $propuesta ? $propuesta['lineas'] : [];
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

<?php if ($propuesta) : ?>
    <?php if (!empty($propuesta['duplicados'])) : ?>
        <!-- Coincidencias que ya existen: las consume src/js/modulos/inteligente.js
             para abrir el aviso con su botón de «Ver venta» / «Ver gasto». -->
        <div hidden
             data-coincidencias="<?= escaparValores(json_encode($propuesta['duplicados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
             data-ruta="/admin/ventas/crear?lectura=<?= (int) $lecturaId ?>"
             data-origen="Registro Venta Inteligente"></div>
    <?php endif; ?>
    <?php template('avisoLectura', ['propuesta' => $propuesta, 'nombreDoc' => 'venta']); ?>
<?php endif; ?>

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
