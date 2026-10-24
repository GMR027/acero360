<?php
/**
 * templates/footer.php — Cierre de la estructura de la página.
 *
 * Cierra el <main>, el panel y el contenedor de la aplicación según cómo
 * se abrió en header.php, añade el pie de página, las alertas, el modal
 * genérico y el script de la aplicación.
 */

$mostrarSidebar = $GLOBALS['mostrarSidebar'] ?? true;
$pieTexto       = $GLOBALS['pieTexto'] ?? '';
?>
        </main>

        <?php if ($mostrarSidebar) : ?>
            <footer class="pie">
                <p><?= $pieTexto !== ''
                        ? escaparValores($pieTexto)
                        : 'Todos los derechos reservados · Acero 360 ' . date('Y'); ?>
                </p>
            </footer>
        </div><!-- /.app__panel -->
    </div><!-- /.app -->

    <?php
    // Navegación inferior de móvil (sólo iconos) y cajón lateral.
    template('bottomnav');
    ?>
<?php endif; ?>

<?php
// Alertas visuales y ventana modal compartida por todas las pantallas.
template('alertas');
template('modal');
?>

<?php foreach (($GLOBALS['scriptsPagina'] ?? []) as $scriptPagina) :
    // Auto-versionado: los ficheros locales sin ?v= reciben su filemtime, para
    // que una caché (CDN o navegador) nunca sirva la compilación anterior.
    if (strpos($scriptPagina, '?') === false && str_starts_with($scriptPagina, '/')) {
        $ficheroVersion = __DIR__ . '/..' . $scriptPagina;
        if (is_file($ficheroVersion)) {
            $scriptPagina .= '?v=' . filemtime($ficheroVersion);
        }
    }
?>
<script src="<?= escaparValores($scriptPagina) ?>"></script>
<?php endforeach; ?>
<?php $versionApp = @filemtime(__DIR__ . '/../build/js/app.js'); ?>
<script src="/build/js/app.js<?= $versionApp !== false ? '?v=' . $versionApp : '' ?>"></script>
</body>
</html>
