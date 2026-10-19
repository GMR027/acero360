<?php
/**
 * views/admin/inventario/index.php — Control de almacén.
 *
 * Pantalla con las DOS fuentes de movimiento que pide OPENCODE.md:
 *   • carga manual: cada renglón trae un campo de cantidad con su botón
 *     "Guardar" para subir o bajar existencias (fabricación nueva);
 *   • descuento automático: se produce al marcar una cotización como
 *     "Vendido" y queda documentado en la bitácora inferior.
 *
 * Incluye además el botón "Exportar Inventario" (PDF con una tabla por
 * empresa y los totales al pie de cada una).
 */

soloAdmin();

/** Filtros del listado. */
$termino   = trim($_GET['q'] ?? '');
$empresaId = (int) ($_GET['empresa'] ?? 0);
$estado    = $_GET['estado'] ?? 'todos';

if (!in_array($estado, ['todos', 'bajo'], true)) {
    $estado = 'todos';
}

$filtros = ['q' => $termino, 'empresa' => $empresaId, 'estado' => $estado];

/** Registros por página y cálculo de la paginación. */
$porPagina = 15;
$total     = \App\Inventario::contar($filtros);
$pagina    = paginacion($porPagina, $total);
$productos = \App\Inventario::listar($filtros, $pagina['limite'], $pagina['offset']);

$empresas = \App\Empresas::mostrarTodos(100, 0);
$movimientos = \App\Inventario::movimientos(15);

/** URL del listado conservando los filtros activos. */
$rutaBase = '/admin/inventario';
$parametros = [];
if ($termino !== '')     { $parametros['q'] = $termino; }
if ($empresaId > 0)      { $parametros['empresa'] = $empresaId; }
if ($estado !== 'todos') { $parametros['estado'] = $estado; }
if (!empty($parametros)) { $rutaBase .= '?' . http_build_query($parametros); }

$hayFiltros = $termino !== '' || $empresaId > 0 || $estado !== 'todos';

/** Etiqueta del movimiento según su tipo. */
$etiquetaTipo = static function (string $tipo): array {
    return \App\Inventario::TIPOS[$tipo]
        ?? ['etiqueta' => $tipo, 'icono' => 'swap_horiz', 'clase' => 'badge--neutro'];
};

template('header', [
    'titulo'    => 'Inventario',
    'subtitulo' => 'Existencias por negocio',
    'acciones'  => '<a class="btn btn--primario" href="/admin/inventario/pdf">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>'
        . '<span>Exportar Inventario</span></a>',
]);
?>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <!-- Buscador + filtros -->
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/inventario" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar producto en el inventario</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por SKU, nombre o categoría…"
                       value="<?= escaparValores($termino) ?>">
            </div>

            <label class="solo-lectores" for="empresa">Negocio</label>
            <select class="control control--compacto" id="empresa" name="empresa">
                <option value="0">Todos los negocios</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= (int) $empresa->id ?>"
                        <?= $empresaId === (int) $empresa->id ? 'selected' : '' ?>>
                        <?= escaparValores($empresa->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="estado">Existencias</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="todos"<?= $estado === 'todos' ? ' selected' : '' ?>>Todas</option>
                <!-- stock <= 0 cubre tanto el 0 (agotado) como los negativos
                     (piezas vendidas de más), que también hay que fabricar. -->
                <option value="bajo"<?= $estado === 'bajo' ? ' selected' : '' ?>>Sin existencias (0 o menos)</option>
            </select>

            <button class="btn btn--secundario btn--sm" type="submit">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>

            <?php if ($hayFiltros) : ?>
                <a class="btn btn--texto btn--sm" href="/admin/inventario"
                   aria-label="Restablecer los filtros">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'producto' : 'productos' ?>
        </span>
    </div>

    <?php if (empty($productos)) : ?>
        <!-- Estado vacío -->
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">warehouse</span>
            <p class="vacio__titulo"><?= $hayFiltros ? 'Sin coincidencias' : 'Aún no hay productos' ?></p>
            <p><?= $hayFiltros
                    ? 'Ningún producto coincide con los filtros aplicados.'
                    : 'Cargá el catálogo desde "Catálogo Productos" para llevar el control de almacén.' ?></p>
            <?php if ($hayFiltros) : ?>
                <a class="btn btn--secundario" href="/admin/inventario">Restablecer filtros</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <!-- Listado con carga manual de existencias -->
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Existencias de los productos por negocio</caption>
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Producto</th>
                        <th scope="col" class="tabla__col-secundaria">Precio de lista</th>
                        <th scope="col">Cantidad en inventario</th>
                        <th scope="col" class="tabla__col-secundaria">Monto en inventario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productos as $producto) :
                        $stock    = (int) $producto->stock;
                        $precio   = (float) $producto->precio;
                        $monto    = round($precio * $stock, 2);
                        $idCampo  = 'stock-' . (int) $producto->id;
                        ?>
                        <tr>
                            <td class="cifra"><?= escaparValores($producto->sku ?: '—') ?></td>
                            <td>
                                <div class="tabla__producto-textos">
                                    <strong><?= escaparValores($producto->nombre) ?></strong>
                                    <span class="texto-xs texto-secundario texto-una-linea">
                                        <?= escaparValores($producto->empresa_nombre) ?>
                                    </span>
                                </div>
                            </td>
                            <td class="tabla__col-secundaria tabla--numerica cifra">
                                <?= formatoMoneda($precio) ?>
                            </td>
                            <td>
                                <!-- Carga manual: se guarda el valor absoluto y
                                     el delta queda registrado en la bitácora. -->
                                <form class="inventario-cantidad" action="/admin/inventario/ajustar"
                                      method="post" data-validar>
                                    <input type="hidden" name="producto_id" value="<?= (int) $producto->id ?>">
                                    <!-- Se conservan los filtros para volver al mismo listado. -->
                                    <input type="hidden" name="q" value="<?= escaparValores($termino) ?>">
                                    <input type="hidden" name="empresa" value="<?= $empresaId ?>">
                                    <input type="hidden" name="estado" value="<?= escaparValores($estado) ?>">
                                    <input type="hidden" name="pagina" value="<?= (int) $pagina['paginaActual'] ?>">
                                    <label class="solo-lectores" for="<?= $idCampo ?>">
                                        Existencias de <?= escaparValores($producto->nombre) ?>
                                    </label>
                                    <input class="control inventario-cantidad__campo<?= $stock <= 0 ? ' inventario-cantidad__campo--vacio' : '' ?>"
                                           type="number" id="<?= $idCampo ?>" name="stock"
                                           value="<?= $stock ?>" min="-99999" max="99999" step="1" required>
                                    <button type="submit" class="btn btn--primario btn--sm btn--icono"
                                            title="Guardar existencias"
                                            aria-label="Guardar existencias de <?= escaparValores($producto->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">save</span>
                                    </button>
                                </form>
                            </td>
                            <td class="tabla__col-secundaria tabla--numerica cifra">
                                <?= $stock < 0 ? '<span class="texto-error">' . formatoMoneda($monto) . '</span>' : formatoMoneda($monto) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        template('paginacion', [
            'pagina'   => $pagina,
            'rutaBase' => $rutaBase,
            'total'    => $total,
        ]);
        ?>
    <?php endif; ?>
</section>

<!-- Bitácora: quién subió o bajó cada producto y por qué -->
<section class="tarjeta">
    <header class="tarjeta__cabecera">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">history</span>
            Últimos movimientos de almacén
        </h2>
    </header>

    <div class="tarjeta__cuerpo">
        <?php if (empty($movimientos)) : ?>
            <p class="texto-secundario">Todavía no hay movimientos: empieza cargando las existencias de un producto.</p>
        <?php else : ?>
            <div class="tabla-contenedor">
                <table class="tabla">
                    <caption class="solo-lectores">Bitácora de movimientos de inventario</caption>
                    <thead>
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Producto</th>
                            <th scope="col" class="tabla--centro">Tipo</th>
                            <th scope="col" class="tabla__col-secundaria">Motivo</th>
                            <th scope="col" class="tabla__col-secundaria">Registró</th>
                            <th scope="col">Cantidad</th>
                            <th scope="col" class="tabla__col-secundaria">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimientos as $movimiento) :
                            $tipo   = $etiquetaTipo((string) $movimiento->tipo);
                            $suma   = $movimiento->tipo === 'entrada';
                            $cantidad = (int) $movimiento->cantidad;
                            ?>
                            <tr>
                                <td class="texto-sm"><?= date('d/m/Y', strtotime($movimiento->creado_en)) ?></td>
                                <td>
                                    <div class="tabla__producto-textos">
                                        <strong><?= escaparValores($movimiento->producto_nombre) ?></strong>
                                        <span class="texto-xs texto-secundario texto-una-linea">
                                            <?= escaparValores($movimiento->producto_sku ?: '—') ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="tabla--centro">
                                    <span class="badge <?= $tipo['clase'] ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $tipo['icono'] ?></span>
                                        <?= $tipo['etiqueta'] ?>
                                    </span>
                                </td>
                                <td class="tabla__col-secundaria texto-sm texto-secundario">
                                    <?= escaparValores($movimiento->notas ?: '—') ?>
                                </td>
                                <td class="tabla__col-secundaria texto-sm">
                                    <?= escaparValores($movimiento->usuario_nombre ?: '—') ?>
                                </td>
                                <td class="tabla--numerica cifra">
                                    <?= $suma ? '+' : '−' ?><?= number_format($cantidad) ?>
                                </td>
                                <td class="tabla__col-secundaria tabla--numerica cifra">
                                    <?= number_format((int) $movimiento->saldo) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="texto-sm texto-secundario mt-md">
            <strong>Entrada manual</strong> = fabricación nueva cargada aquí.
            <strong>Salida por venta</strong> = descuento automático al marcar una cotización como <em>Vendido</em>.
            <strong>Ajuste manual</strong> = corrección a la baja hecha desde esta pantalla.
        </p>
    </div>
</section>

<?php template('footer'); ?>
