<?php
/**
 * views/admin/clientes/editar.php — Modificación de clientes.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 */

soloAdmin();

/** Cliente a editar. Si no existe, se muestra el 404. */
$cliente = \App\Clientes::buscarID((int) ($_GET['id'] ?? 0));

if (!$cliente) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);
$fotoPrevia = $cliente->foto;

if ($esEnvio) {
    $cliente->nombre         = trim($_POST['nombre'] ?? '');
    $cliente->nombre_empresa = trim($_POST['nombre_empresa'] ?? '');
    $cliente->alias          = trim($_POST['alias'] ?? '');
    $cliente->rfc            = trim($_POST['rfc'] ?? '');
    $cliente->codigo_postal  = trim($_POST['codigo_postal'] ?? '');
    $cliente->regimen_fiscal = trim($_POST['regimen_fiscal'] ?? '');
    $cliente->uso_cfdi       = trim($_POST['uso_cfdi'] ?? '');
    $cliente->telefono       = trim($_POST['telefono'] ?? '');
    $cliente->email          = trim($_POST['email'] ?? '');
    $cliente->ubicacion      = trim($_POST['ubicacion'] ?? '');
    $cliente->vip            = isset($_POST['vip']) ? 1 : 0;
    $cliente->descuento_pct  = $_POST['descuento_pct'] !== '' ? (float) $_POST['descuento_pct'] : 0;
    $cliente->notas          = trim($_POST['notas'] ?? '');

    $errores = $cliente->validar();

    // Foto de perfil: archivo nuevo, casilla de quitar, o nada que tocar.
    // Sólo se procesa si los demás datos ya son válidos, para no dejar un
    // archivo sin referencia cuando el envío se rechaza.
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

    if (empty($errores) && $cliente->actualizar()) {
        // La foto anterior sólo se borra cuando ya quedó sustituida.
        if ($cliente->foto !== $fotoPrevia) {
            \App\Imagenes::eliminar($fotoPrevia ?: null);
        }

        // Éxito: alerta visual que se muestra al cargar la lista.
        redirigir('/admin/clientes', 'exito', 'Cliente actualizado correctamente.');
    }

    // Si llegamos aquí, la validación o la escritura fallaron.
    $errores = $errores ?: \App\Clientes::getErrores();
}

template('header', [
    'titulo'     => 'Editar cliente',
    'subtitulo'  => 'Módulo de clientes',
    'rutaActiva' => '/admin/clientes',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">Modifica los datos de <strong><?= escaparValores($cliente->nombre) ?></strong>.</p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/clientes">Volver</a>
        <a class="btn btn--secundario" href="/admin/clientes/detalle?id=<?= (int) $cliente->id ?>">Ver detalle</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/clientes/editar?id=<?= (int) $cliente->id ?>" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioClientes', ['cliente' => $cliente, 'errores' => $errores]); ?>
    </form>
</section>

<?php /* FASE 9: RFCs de facturación del cliente. clientes.rfc es el ACTIVO; aquí
         se administran todos (p. ej. su persona física y su empresa). */ ?>
<?php $rfcs = \App\ClientesRfc::de((int) $cliente->id); ?>
<section class="tarjeta mb-lg" id="rfcs-facturacion">
    <h2 class="titulo-5 mb-sm">
        <span class="material-symbols-outlined" aria-hidden="true">badge</span>
        RFCs de facturación (<?= count($rfcs) ?>)
    </h2>
    <p class="campo__ayuda mb-md">
        El RFC con el que se factura es el marcado como <strong>activo</strong>. Un cliente puede tener varios
        (p. ej. su persona física y su empresa): al facturar se elige cuál usar. No se puede duplicar un RFC
        que ya pertenezca a otro cliente.
    </p>

    <?php if ($rfcs) : ?>
        <div class="tabla-contenedor mb-md">
            <table class="tabla">
                <caption class="solo-lectores">RFCs registrados de <?= escaparValores(trim($cliente->nombre_empresa ?: $cliente->nombre)) ?></caption>
                <thead><tr><th scope="col">RFC</th><th scope="col">Razón social</th><th scope="col">CP</th>
                    <th scope="col">Régimen</th><th scope="col">Uso CFDI</th><th scope="col">Estado</th>
                    <th scope="col"><span class="solo-lectores">Acción</span></th></tr></thead>
                <tbody>
                <?php foreach ($rfcs as $r) : ?>
                    <tr>
                        <td><strong><?= escaparValores((string) $r['rfc']) ?></strong></td>
                        <td class="texto-sm"><?= escaparValores((string) ($r['razon_social'] ?? '—')) ?></td>
                        <td><?= escaparValores((string) ($r['codigo_postal'] ?? '—')) ?></td>
                        <td><?= escaparValores((string) ($r['regimen_fiscal'] ?? '—')) ?></td>
                        <td><?= escaparValores((string) ($r['uso_cfdi'] ?? '—')) ?></td>
                        <td><?= (int) $r['es_principal'] === 1
                            ? '<span class="badge badge--aprobado">Activo para facturar</span>'
                            : '<span class="badge badge--neutro">Secundario</span>' ?></td>
                        <td class="tabla--numerica">
                            <?php if ((int) $r['es_principal'] !== 1) : ?>
                                <form action="/admin/clientes/rfc-accion" method="post" data-validar
                                      data-confirmacion-sobre="RFC secundario"
                                      data-confirmacion="¿Quitar el RFC <?= escaparValores((string) $r['rfc']) ?> de esta ficha?"
                                      data-confirmacion-boton="Sí, quitar">
                                    <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                    <input type="hidden" name="accion" value="quitar">
                                    <input type="hidden" name="rfc_id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="cliente" value="<?= (int) $cliente->id ?>">
                                    <button class="btn btn--peligro btn--sm" type="submit">Quitar</button>
                                </form>
                            <?php else : ?>
                                <span class="texto-xs texto-secundario">No se quita: es el activo.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form class="formulario" action="/admin/clientes/rfc-accion" method="post" novalidate>
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <input type="hidden" name="accion" value="agregar">
        <input type="hidden" name="cliente" value="<?= (int) $cliente->id ?>">
        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="rfc-nuevo">RFC</label>
                <input class="control" type="text" id="rfc-nuevo" name="rfc" maxlength="20" style="text-transform:uppercase" required>
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="rfc-razon">Razón social de ese RFC</label>
                <input class="control" type="text" id="rfc-razon" name="razon_social" maxlength="150"
                       placeholder="Ej. la empresa del mismo cliente">
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="rfc-cp">Código postal</label>
                <input class="control" type="text" id="rfc-cp" name="codigo_postal" maxlength="5" inputmode="numeric">
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="rfc-regimen">Régimen fiscal</label>
                <input class="control" type="text" id="rfc-regimen" name="regimen_fiscal" maxlength="4" inputmode="numeric">
            </div>
            <div class="campo">
                <label class="campo__etiqueta" for="rfc-uso">Uso del CFDI</label>
                <input class="control" type="text" id="rfc-uso" name="uso_cfdi" maxlength="4" placeholder="G03">
            </div>
        </div>
        <div class="formulario__acciones">
            <button class="btn btn--secundario" type="submit">Agregar RFC a esta ficha</button>
        </div>
    </form>
</section>

<?php template('footer'); ?>
