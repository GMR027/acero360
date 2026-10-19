<?php
/**
 * templates/campoRetrato.php — Campo de foto de perfil (usuarios y clientes).
 *
 * Muestra la foto actual (o la genérica perfilSinfoto.jpg cuando no hay),
 * el campo de carga con vista previa y, sólo si ya existe foto, la casilla
 * para quitarla. El archivo se procesa en la vista con
 * Imagenes::subirRetrato(), en modo 'retrato' (cuadro 500×500 recortado).
 *
 * Se reutiliza desde views/perfil.php, los formularios de usuarios y los
 * de clientes.
 *
 * Variables esperadas:
 *   $foto     — nombre del archivo guardado ('' si no hay foto)
 *   $prefijo  — prefijo de los id del campo (evita duplicados en la página)
 *   $etiqueta — texto de la etiqueta superior
 *   $errores  — mensajes de validación del servidor
 */

$foto     = $foto ?? '';
$prefijo  = $prefijo ?? 'perfil';
$etiqueta = $etiqueta ?? 'Foto de perfil';
$errores  = $errores ?? [];

$idFoto   = $prefijo . '-foto';
$idQuitar = $prefijo . '-quitar-foto';
$hayFoto  = $foto !== '';

/** Primer mensaje de error que menciona el campo de imagen. */
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

    <div class="carga-imagen carga-imagen--retrato">
        <div class="carga-imagen__zona">
            <!-- Siempre hay imagen: sin foto cargada se muestra perfilSinfoto.jpg -->
            <img class="carga-imagen__preview" src="<?= escaparValores(\App\Imagenes::retrato($foto)) ?>"
                 alt="<?= $hayFoto ? 'Foto de perfil' : 'Imagen genérica de perfil' ?>">

            <span class="campo__ayuda">
                Se guarda en un cuadro de 500×500 px y se muestra redonda.
                JPG, PNG o WebP de hasta 6 MB.
                <?php if ($hayFoto) : ?>
                    <br>Deja el campo vacío para conservar la foto actual.
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
                <span class="texto-xs texto-secundario">
                    Quitar foto y volver a la imagen genérica
                </span>
            </label>
        <?php endif; ?>
    </div>
</div>
