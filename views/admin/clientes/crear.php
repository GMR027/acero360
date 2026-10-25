<?php
/**
 * views/admin/clientes/crear.php — Alta de clientes.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * El alta y la edición comparten el mismo formulario: templates/formularioClientes.php
 */

soloAdmin();

$errores  = [];
$esEnvio  = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $cliente = new \App\Clientes([
        'nombre'         => trim($_POST['nombre'] ?? ''),
        'nombre_empresa' => trim($_POST['nombre_empresa'] ?? ''),
        'alias'          => trim($_POST['alias'] ?? ''),
        'rfc'            => trim($_POST['rfc'] ?? ''),
        'codigo_postal'  => trim($_POST['codigo_postal'] ?? ''),
        'regimen_fiscal' => trim($_POST['regimen_fiscal'] ?? ''),
        'uso_cfdi'       => trim($_POST['uso_cfdi'] ?? ''),
        'telefono'       => trim($_POST['telefono'] ?? ''),
        'email'          => trim($_POST['email'] ?? ''),
        'ubicacion'      => trim($_POST['ubicacion'] ?? ''),
        'vip'            => isset($_POST['vip']) ? 1 : 0,
        'descuento_pct'  => ($_POST['descuento_pct'] ?? '') !== '' ? (float) $_POST['descuento_pct'] : 0,
        'notas'          => trim($_POST['notas'] ?? ''),
    ]);

    $errores = $cliente->validar();

    // Foto de perfil: sólo se procesa si los demás datos ya son válidos,
    // para no dejar un archivo sin referencia cuando el envío se rechaza.
    if (empty($errores)) {
        $foto = \App\Imagenes::subirRetrato(
            $_FILES['foto'] ?? null,
            !empty($_POST['quitar_foto']),
            $errores
        );

        if ($foto !== null) {
            $cliente->foto = $foto;
        }
    }

    if (empty($errores) && $cliente->crear()) {
        /* FASE 9: detección de «el mismo cliente con otro RFC»: si el nombre o el
         * correo coinciden con una ficha existente se avisa (sin bloquear), porque
         * lo correcto suele ser agregar el RFC a ESA ficha y no duplicar la persona. */
        $duplicados = \App\ClientesRfc::posiblesDuplicados(
            (string) $cliente->nombre, (string) $cliente->nombre_empresa, (string) $cliente->email, (int) $cliente->id
        );
        $mensaje = 'Cliente creado correctamente.';
        if ($duplicados !== []) {
            $nombres = array_map(
                static fn (array $d): string => '«' . trim((string) ($d['nombre_empresa'] ?: $d['nombre'])) . '» (ficha #' . (int) $d['id'] . ')',
                array_slice($duplicados, 0, 2)
            );
            $mensaje .= ' Ojo: parece coincidir con ' . implode(' y ', $nombres)
                . ' por ' . ($duplicados[0]['por'] === 'correo' ? 'correo' : 'nombre')
                . '. Si es el mismo cliente con otro RFC, agrégalo en SU ficha en vez de duplicar la persona.';
        }
        // Éxito: alerta visual que se muestra al cargar la lista.
        redirigir('/admin/clientes', 'exito', $mensaje);
    }

    // Si llegamos aquí, la validación o la escritura fallaron.
    $errores = $errores ?: \App\Clientes::getErrores();
} else {
    $cliente = new \App\Clientes();
}

template('header', [
    'titulo'     => 'Nuevo cliente',
    'subtitulo'  => 'Módulo de clientes',
    'rutaActiva' => '/admin/clientes',
]);
?>

<section class="encabezado-pagina">
    <div>
        <!-- op -->
        <p class="texto-secundario">
            Alta de un cliente para poder cotizarle. Los campos marcados con asterisco son obligatorios.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/clientes">Volver</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/clientes/crear" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioClientes', ['cliente' => $cliente, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>
