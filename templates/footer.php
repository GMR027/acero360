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
                        : 'Todos los derechos reservados · Cotizaciones ' . date('Y'); ?>
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

<script src="/build/js/app.js"></script>
</body>
</html>
