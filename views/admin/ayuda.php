<?php
/**
 * views/admin/ayuda.php — Manual de usuario dentro de la plataforma (contenido en includes/manual.php).
 */

soloAdmin();

$secciones = require __DIR__ . '/../../includes/manual.php';

template('header', ['titulo' => 'Ayuda', 'subtitulo' => 'Manual de usuario: cómo hacer cada cosa paso a paso', 'rutaActiva' => '/admin/ayuda']);
?>

<nav class="chip-grupo mb-lg" aria-label="Temas del manual">
    <?php foreach ($secciones as $s) : ?>
        <a class="chip" href="#<?= escaparValores($s['id']) ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= escaparValores($s['icono']) ?></span>
            <span><?= escaparValores($s['titulo']) ?></span></a>
    <?php endforeach; ?>
</nav>

<?php foreach ($secciones as $s) : ?>
    <section class="tarjeta mb-lg" id="<?= escaparValores($s['id']) ?>" style="scroll-margin-top:1rem">
        <h2 class="titulo-4 mb-sm">
            <span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:middle"><?= escaparValores($s['icono']) ?></span>
            <?= escaparValores($s['titulo']) ?>
        </h2>
        <p class="texto-secundario mb-md"><?= escaparValores($s['intro']) ?></p>

        <?php foreach ($s['pasos'] as [$titulo, $lista]) : ?>
            <h3 class="titulo-5 mt-md mb-sm"><?= escaparValores($titulo) ?></h3>
            <?php $ordenada = count($lista) > 1 && $titulo !== 'Glosario'; ?>
            <<?= $ordenada ? 'ol' : 'ul' ?> style="padding-left:1.25rem;line-height:1.6">
                <?php foreach ($lista as $paso) : ?><li><?= escaparValores($paso) ?></li><?php endforeach; ?>
            </<?= $ordenada ? 'ol' : 'ul' ?>>
        <?php endforeach; ?>

        <?php if ($s['consejos']) : ?>
            <p class="nota nota--info mt-md" role="note"><span class="material-symbols-outlined nota__icono" aria-hidden="true">lightbulb</span>
                <span><strong>Consejos:</strong> <?= escaparValores(implode(' · ', $s['consejos'])) ?></span></p>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php template('footer'); ?>
