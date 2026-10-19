<?php
/**
 * templates/campoFoto.php — Campo de fotografía de un acta (foto de entrega).
 *
 * Se usa en la venta (foto de la entrega al cliente) y en la entrada de un
 * proveedor (foto de la entrega de material). A diferencia del retrato de
 * perfil, aquí NO se recorta: la imagen conserva su proporción original
 * (modo 'informe', lado máximo de 1600 px).
 *
 * El archivo se procesa en la vista con Imagenes::subirInforme().
 *
 * Variables esperadas:
 *   $foto     — nombre del archivo guardado ('' si no hay)
 *   $prefijo  — prefijo de los id del campo (evita duplicados en la página)
 *   $etiqueta — texto de la etiqueta superior
 *   $ayuda    — texto de ayuda bajo la vista previa
 *   $errores  — mensajes de validación del servidor
 */

$foto     = $foto ?? '';
$prefijo  = $prefijo ?? 'entrega';
$etiqueta = $etiqueta ?? 'Foto de entrega';
$ayuda    = $ayuda ?? 'JPG, PNG o WebP de hasta 6 MB. La proporción de la foto se conserva sin recortar.';
$errores  = $errores ?? [];

$idFoto   = $prefijo . '-foto';
$idQuitar = $prefijo . '-quitar-foto';
$hayFoto  = $foto !== '';

/** Primer mensaje de error que menciona la imagen. */
$errorImagen = '';
foreach ($errores as $error) {
    if (stripos($error, 'imagen') !== false || stripos($error, 'foto') !== false) {
        $errorImagen = $error;
        break;
    }
}
?>

<div class="campo campo--completo">
    <span class="campo__etiqueta"><?= escaparValores($etiqueta) ?></span>

    <div class="carga-imagen carga-imagen--informe">
        <div class="carga-imagen__zona">
            <?php if ($hayFoto) : ?>
                <img class="carga-imagen__preview carga-imagen__preview--amplia"
                     src="<?= escaparValores(\App\Imagenes::url($foto)) ?>"
                     alt="<?= escaparValores($etiqueta) ?>">
            <?php else : ?>
                <span class="carga-imagen__placeholder" aria-hidden="true">photo_camera</span>
            <?php endif; ?>

            <span class="campo__ayuda">
                <?= escaparValores($ayuda) ?>
                <?php if ($hayFoto) : ?>
                    <br>Deja el campo vacío para conservar la fotografía actual.
                <?php endif; ?>
            </span>
        </div>

        <label class="campo__etiqueta" for="<?= $idFoto ?>">Archivo de imagen</label>
        <input class="control carga-imagen__input" type="file" id="<?= $idFoto ?>" name="foto"
               accept="image/jpeg,image/png,image/webp" data-preview>
        <?php if ($errorImagen !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorImagen) ?></p>
        <?php endif; ?>

        <?php if ($hayFoto) : ?>
            <label class="comprobacion" for="<?= $idQuitar ?>">
                <input type="checkbox" id="<?= $idQuitar ?>" name="quitar_foto" value="1">
                <span class="texto-xs texto-secundario">Quitar la fotografía</span>
            </label>
        <?php endif; ?>
    </div>
</div>
