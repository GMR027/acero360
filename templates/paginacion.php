<?php
/**
 * templates/paginacion.php — Control de páginas de un listado.
 *
 * Variables esperadas:
 *   $pagina   ['paginaActual' => int, 'totalPaginas' => int]
 *   $rutaBase Ruta actual sin parámetro de página, p. ej. '/admin/clientes'
 *   $total    Número total de registros (opcional, para el texto de rango)
 *   $parametro Nombre del parámetro de la URL (opcional, por defecto «pagina»).
 *              Se usa cuando una pantalla tiene varias tablas paginadas: cada
 *              tabla se pagina con su propio parámetro y no se pisan.
 *
 * Uso:
 *   $pagina = paginacion(10, $totalRegistros);
 *   template('paginacion', ['pagina' => $pagina, 'rutaBase' => '/admin/clientes', 'total' => $total]);
 */

$pagina    = $pagina ?? ['paginaActual' => 1, 'totalPaginas' => 1];
$rutaBase  = $rutaBase ?? '/admin';
$total     = $total ?? 0;
$parametro = $parametro ?? 'pagina';
$porPagina = max(1, $pagina['limite'] ?? 10);

$actual = (int) $pagina['paginaActual'];
$ultima = max(1, (int) $pagina['totalPaginas']);

// Rango visible de registros en la página actual.
$desde = $total > 0 ? (($actual - 1) * $porPagina) + 1 : 0;
$hasta = min($total, $actual * $porPagina);

/**
 * Construye la URL de una página concreta.
 *
 * Si la ruta base ya lleva parámetros (p. ej. el texto de búsqueda `q`),
 * el número de página se añade con `&` para no romper la query string.
 */
$enlacePagina = function (int $num) use ($rutaBase, $parametro): string {
    if ($num <= 1) {
        return $rutaBase;
    }
    $separador = strpos($rutaBase, '?') === false ? '?' : '&';
    return $rutaBase . $separador . $parametro . '=' . $num;
};
?>
<nav class="paginacion" aria-label="Paginación de resultados">
    <span class="paginacion__rango cifra">
        Mostrando <?= $desde ?>–<?= $hasta ?> de <?= $total ?> registros
    </span>

    <div class="paginacion__botones">
        <?php if ($actual > 1) : ?>
            <a class="paginacion__boton" href="<?= escaparValores($enlacePagina($actual - 1)) ?>" rel="prev">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                <span class="solo-lectores">Página anterior</span>
            </a>
        <?php else : ?>
            <span class="paginacion__boton" aria-disabled="true">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
            </span>
        <?php endif; ?>

        <span class="paginacion__boton paginacion__boton--activo cifra" aria-current="page">
            <?= $actual ?> / <?= $ultima ?>
        </span>

        <?php if ($actual < $ultima) : ?>
            <a class="paginacion__boton" href="<?= escaparValores($enlacePagina($actual + 1)) ?>" rel="next">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                <span class="solo-lectores">Página siguiente</span>
            </a>
        <?php else : ?>
            <span class="paginacion__boton" aria-disabled="true">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
            </span>
        <?php endif; ?>
    </div>
</nav>
