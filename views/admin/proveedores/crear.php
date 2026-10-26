<?php
/**
 * views/admin/proveedores/crear.php — Alta de proveedores.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * El alta y la edición comparten el mismo formulario:
 * templates/formularioProveedores.php
 *
 * Con `regresar=<ruta>` la alta se ofrece desde otro formulario —el botón
 * «Regístralo» de /admin/contabilidad/gastos/crear— y, al guardarse, se vuelve
 * a esa ruta con el proveedor nuevo ya elegido. Sólo se acepta la ruta exacta
 * de creación de gasto (con su lectura opcional) para que el parámetro no se
 * convierta en una redirección abierta.
 */

soloAdmin();

/** Ruta de origen válida, o cadena vacía si no la hay (alta «normal»). */
$regresar = trim((string) ($_REQUEST['regresar'] ?? ''));
$regresar = preg_match('#^/admin/contabilidad/gastos/crear(\?lectura=\d+)?$#', $regresar) === 1 ? $regresar : '';

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $proveedor = new \App\Proveedores([
        'nombre'         => trim($_POST['nombre'] ?? ''),
        'rfc'            => trim($_POST['rfc'] ?? ''),
        'telefono'       => trim($_POST['telefono'] ?? ''),
        'ubicacion'      => trim($_POST['ubicacion'] ?? ''),
        'contacto'       => trim($_POST['contacto'] ?? ''),
        'email'          => trim($_POST['email'] ?? ''),
        'observaciones'  => trim($_POST['observaciones'] ?? ''),
        'activo'         => isset($_POST['activo']) ? 1 : 0,
        'tipo_proveedor' => trim($_POST['tipo_proveedor'] ?? ''),
    ]);

    if ($proveedor->tipo_proveedor === '') {
        $proveedor->tipo_proveedor = \App\Proveedores::TIPOS[0];
    }

    $errores = $proveedor->validar();

    if (empty($errores) && $proveedor->crear()) {
        if ($regresar !== '') {
            // Vuelta al gasto que pidió esta alta: ahí queda el proveedor nuevo
            // seleccionado y el borrador de lo capturado se restaura en el cliente.
            $separador = strpos($regresar, '?') === false ? '?' : '&';
            redirigir(
                $regresar . $separador . 'proveedor=' . (int) $proveedor->id . '&proveedor_nuevo=1',
                'exito',
                'Proveedor creado: ya está elegido en tu gasto. Revisa los datos y guarda cuando quieras.'
            );
        }
        redirigir('/admin/proveedores', 'exito', 'Proveedor creado correctamente.');
    }

    $errores = $errores ?: \App\Proveedores::getErrores();
} else {
    $proveedor = new \App\Proveedores();

    /* Precarga con lo que se capturó en el gasto: el texto escrito en el
     * buscador de proveedores y el RFC emisor del CFDI, si el gasto lo trae. */
    $nombreSugerido = trim((string) ($_GET['nombre'] ?? ''));
    $rfcSugerido    = (string) preg_replace('/\s+/', '', (string) ($_GET['rfc'] ?? ''));
    $rfcSugerido    = strtoupper($rfcSugerido);

    // Si lo único que se escribió fue un RFC, va al campo de RFC y no al nombre.
    if ($rfcSugerido === '' && $nombreSugerido !== ''
        && preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', strtoupper($nombreSugerido)) === 1) {
        $rfcSugerido    = strtoupper($nombreSugerido);
        $nombreSugerido = '';
    }

    if ($nombreSugerido !== '') {
        $proveedor->nombre = mb_substr($nombreSugerido, 0, 150);
    }
    if ($rfcSugerido !== '') {
        $proveedor->rfc = $rfcSugerido;
    }
}

/** Adónde se vuelve: al gasto que pidió la alta, o al directorio de proveedores. */
$volver = $regresar !== '' ? $regresar : '/admin/proveedores';

template('header', [
    'titulo'    => 'Nuevo proveedor',
    'subtitulo' => $regresar !== '' ? 'Alta desde el registro del gasto' : 'Módulo de proveedores',
    'acciones'  => '<a class="btn btn--secundario" href="' . escaparValores($volver) . '">Volver</a>',
]);
template('tabsCompras', ['tab' => 'proveedores']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            <?php if ($regresar !== '') : ?>
                Este proveedor no estaba en tu directorio. Regístralo aquí con los datos que traías
                en el gasto y, al guardarlo, volverás al formulario del gasto con lo que ya habías
                capturado.
            <?php else : ?>
                Alta de un proveedor para poder registrar sus entregas. Los campos marcados con
                asterisco son obligatorios.
            <?php endif; ?>
        </p>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/proveedores/crear" method="post"
          data-validar novalidate>
        <?php if ($regresar !== '') : ?>
            <!-- Lleva la ruta de regreso en el envío (el action no la trae en la URL). -->
            <input type="hidden" name="regresar" value="<?= escaparValores($regresar) ?>">
        <?php endif; ?>
        <?php template('formularioProveedores', ['proveedor' => $proveedor, 'errores' => $errores, 'cancelar' => $volver]); ?>
    </form>
</section>

<?php template('footer'); ?>
