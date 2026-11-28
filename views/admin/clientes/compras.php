<?php
/**
 * views/admin/clientes/compras.php — Compras de un cliente por mes o año.
 *
 * Responde la pregunta: «¿cuántas ventas y qué productos le he vendido a
 * este cliente en el mes o en el año?». Sólo lectura: no modifica ventas,
 * inventario ni contabilidad.
 */

soloAdmin();

$clienteId = (int) ($_GET['id'] ?? 0);
$cliente   = \App\Clientes::buscarID($clienteId);

if (!$cliente) {
    paginaNoEncontrada();
}

/** Periodo solicitado (mes, año, rango o todo). */
$periodoDatos = \App\VentasCliente::normalizarPeriodo($_GET);
$periodo = $periodoDatos['periodo'];
$anio    = $periodoDatos['anio'];
$mes     = $periodoDatos['mes'];
$desde   = $periodoDatos['desde'];
$hasta   = $periodoDatos['hasta'];

$empresaId = (int) ($_GET['empresa'] ?? 0);
$orden     = (string) ($_GET['orden'] ?? 'piezas');
if (!in_array($orden, ['piezas', 'importe'], true)) {
    $orden = 'piezas';
}

$resumen    = \App\VentasCliente::resumen($clienteId, $desde, $hasta, $empresaId);
$productos  = \App\VentasCliente::productos($clienteId, $desde, $hasta, $empresaId, $orden);
$totalDocs  = \App\VentasCliente::contarDocumentos($clienteId, $desde, $hasta, $empresaId);
$pagina     = paginacion(15, $totalDocs);
$documentos = \App\VentasCliente::documentos($clienteId, $desde, $hasta, $empresaId, $pagina['limite'], $pagina['offset']);
$serie      = $periodo === 'anio' ? \App\VentasCliente::serieMensual($clienteId, $anio, $empresaId) : [];

$anios = \App\VentasCliente::anios($clienteId);
foreach ([(int) date('Y'), $anio] as $anioVisible) {
    if (!in_array($anioVisible, $anios, true)) {
        $anios[] = $anioVisible;
    }
}
rsort($anios);

$empresas = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static fn ($empresa): bool => (int) $empresa->activa === 1
));

$m = static fn ($monto): string => formatoMoneda((float) $monto);

$parametros = [
    'id'      => $clienteId,
    'periodo' => $periodo,
    'anio'    => $anio,
    'mes'     => $mes,
    'desde'   => $desde,
    'hasta'   => $hasta,
    'empresa' => $empresaId ?: '',
    'orden'   => $orden,
];
$parametros = array_filter($parametros, static fn ($v): bool => $v !== '' && $v !== null);
$urlBase = '/admin/clientes/compras?' . http_build_query($parametros);
$urlPdf  = '/admin/clientes/compras/pdf?' . http_build_query($parametros);

$nombreMes = static fn (int $numero): string => \App\VentasCliente::nombreMes($numero);
$periodoTexto = $periodoDatos['etiqueta'];

$clienteNombre = $cliente->nombre_empresa ?: $cliente->nombre;
$empresaNombre = '';
foreach ($empresas as $empresa) {
    if ((int) $empresa->id === $empresaId) {
        $empresaNombre = (string) $empresa->nombre;
        break;
    }
}

$totalImporteProductos = array_sum(array_column($productos, 'importe'));

template('header', [
    'titulo'            => 'Compras de ' . $clienteNombre,
    'subtitulo'         => 'Actividad comercial del cliente',
    'rutaActiva'        => '/admin/clientes',
    'mostrarEncabezado' => false,
]);
?>

<section class="encabezado-pagina">
    <div>
        <h1 class="titulo-2"><?= escaparValores($clienteNombre) ?></h1>
        <p class="texto-secundario">
            <?= escaparValores($cliente->nombre) ?>
            <?php if (trim((string) $cliente->rfc) !== '') : ?> · <?= escaparValores($cliente->rfc) ?><?php endif; ?>
            <?php if ($empresaNombre !== '') : ?> · <?= escaparValores($empresaNombre) ?><?php endif; ?>
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="<?= escaparValores($urlPdf) ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">picture_as_pdf</span>
            <span>Exportar PDF</span>
        </a>
        <a class="btn btn--secundario" href="/admin/clientes/detalle?id=<?= $clienteId ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>
            <span>Volver a la ficha</span>
        </a>
    </div>
</section>

<?php template('tabsCliente', ['clienteId' => $clienteId, 'tab' => 'compras']); ?>

<section class="tarjeta mb-lg" aria-labelledby="titulo-periodo-compras">
    <header class="tarjeta__cabecera">
        <h2 class="tarjeta__titulo" id="titulo-periodo-compras">
            <span class="material-symbols-outlined" aria-hidden="true">date_range</span>
            Periodo
        </h2>
        <span class="badge badge--neutro badge--sin-punto"><?= escaparValores($periodoTexto) ?></span>
    </header>
    <div class="tarjeta__cuerpo">
        <nav class="chip-grupo mb-md" aria-label="Periodos rápidos">
            <?php
            $rapidos = [
                'mes_actual'   => ['Este mes', date('Y'), date('n')],
                'mes_anterior' => ['Mes anterior', date('Y', strtotime('first day of last month')), date('n', strtotime('first day of last month'))],
                'anio_actual'  => ['Este año', date('Y'), 1],
                'anio_anterior'=> ['Año anterior', (int) date('Y') - 1, 1],
                'todo'         => ['Todo', 0, 0],
            ];
            foreach ($rapidos as $clave => $datos) :
                [$texto, $anioR, $mesR] = $datos;
                if ($clave === 'todo') {
                    $urlRapida = '/admin/clientes/compras?' . http_build_query([
                        'id' => $clienteId, 'periodo' => 'todo', 'empresa' => $empresaId ?: '',
                    ]);
                    $activo = $periodo === 'todo';
                } elseif ($clave === 'anio_actual' || $clave === 'anio_anterior') {
                    $urlRapida = '/admin/clientes/compras?' . http_build_query([
                        'id' => $clienteId, 'periodo' => 'anio', 'anio' => $anioR, 'empresa' => $empresaId ?: '',
                    ]);
                    $activo = $periodo === 'anio' && $anio === (int) $anioR;
                } else {
                    $urlRapida = '/admin/clientes/compras?' . http_build_query([
                        'id' => $clienteId, 'periodo' => 'mes', 'anio' => $anioR, 'mes' => $mesR, 'empresa' => $empresaId ?: '',
                    ]);
                    $activo = $periodo === 'mes' && $anio === (int) $anioR && $mes === (int) $mesR;
                }
                ?>
                <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= escaparValores($urlRapida) ?>"><?= escaparValores($texto) ?></a>
            <?php endforeach; ?>
        </nav>

        <form class="buscador buscador--filtros" action="/admin/clientes/compras" method="get" data-validar>
            <input type="hidden" name="id" value="<?= $clienteId ?>">
            <label class="solo-lectores" for="periodo">Periodo</label>
            <select class="control control--compacto" id="periodo" name="periodo">
                <option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>Mes</option>
                <option value="anio" <?= $periodo === 'anio' ? 'selected' : '' ?>>Año</option>
                <option value="rango" <?= $periodo === 'rango' ? 'selected' : '' ?>>Rango</option>
                <option value="todo" <?= $periodo === 'todo' ? 'selected' : '' ?>>Todo</option>
            </select>

            <label class="solo-lectores" for="mes">Mes</label>
            <select class="control control--compacto" id="mes" name="mes">
                <?php for ($i = 1; $i <= 12; $i++) : ?>
                    <option value="<?= $i ?>" <?= $mes === $i ? 'selected' : '' ?>><?= escaparValores($nombreMes($i)) ?></option>
                <?php endfor; ?>
            </select>

            <label class="solo-lectores" for="anio">Año</label>
            <select class="control control--compacto" id="anio" name="anio">
                <?php foreach ($anios as $a) : ?>
                    <option value="<?= (int) $a ?>" <?= $anio === (int) $a ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="desde">Desde</label>
            <input class="control control--compacto" type="date" id="desde" name="desde" value="<?= escaparValores($desde) ?>">

            <label class="solo-lectores" for="hasta">Hasta</label>
            <input class="control control--compacto" type="date" id="hasta" name="hasta" value="<?= escaparValores($hasta) ?>">

            <label class="solo-lectores" for="empresa">Negocio</label>
            <select class="control control--compacto" id="empresa" name="empresa">
                <option value="0">Todos los negocios</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= (int) $empresa->id ?>" <?= $empresaId === (int) $empresa->id ? 'selected' : '' ?>>
                        <?= escaparValores($empresa->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="solo-lectores" for="orden">Ordenar productos</label>
            <select class="control control--compacto" id="orden" name="orden">
                <option value="piezas" <?= $orden === 'piezas' ? 'selected' : '' ?>>Por piezas</option>
                <option value="importe" <?= $orden === 'importe' ? 'selected' : '' ?>>Por importe</option>
            </select>

            <button type="submit" class="btn btn--secundario btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">filter_list</span>
                <span>Aplicar</span>
            </button>
        </form>
    </div>
</section>

<section class="rejilla-stats mb-lg" aria-label="Resumen de compras del cliente">
    <div class="stat">
        <span class="stat__etiqueta">Ventas</span>
        <span class="stat__valor"><?= number_format($resumen['ventas']) ?></span>
        <span class="stat__pie">Documentos registrados</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Piezas</span>
        <span class="stat__valor"><?= number_format($resumen['piezas']) ?></span>
        <span class="stat__pie">Unidades vendidas</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Total vendido</span>
        <span class="stat__valor"><?= $m($resumen['total']) ?></span>
        <span class="stat__pie">IVA incluido</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Ticket promedio</span>
        <span class="stat__valor"><?= $m($resumen['ticket_promedio']) ?></span>
        <span class="stat__pie"><?= $resumen['ultima'] !== '' ? 'Última compra: ' . date('d/m/Y', strtotime($resumen['ultima'])) : 'Sin compras en el periodo' ?></span>
    </div>
</section>

<div class="rejilla-2 mb-lg">
    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
                Desglose del periodo
            </h2>
        </header>
        <div class="tarjeta__cuerpo">
            <dl class="ficha">
                <div class="ficha__fila"><dt>Subtotal de partidas</dt><dd class="cifra"><?= $m($resumen['subtotal']) ?></dd></div>
                <div class="ficha__fila"><dt>Descuentos</dt><dd class="cifra">-<?= $m($resumen['descuento']) ?></dd></div>
                <div class="ficha__fila"><dt>Base sin IVA</dt><dd class="cifra"><?= $m($resumen['base']) ?></dd></div>
                <div class="ficha__fila"><dt>IVA</dt><dd class="cifra"><?= $m($resumen['iva']) ?></dd></div>
                <div class="ficha__fila"><dt><strong>Total</strong></dt><dd class="cifra"><strong><?= $m($resumen['total']) ?></strong></dd></div>
            </dl>
            <p class="campo__ayuda mb-0">No incluye ventas canceladas. Las cotizaciones que aún no se venden no cuentan como venta.</p>
        </div>
    </section>

    <section class="tarjeta">
        <header class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">
                <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                Productos comprados
            </h2>
            <span class="badge badge--neutro badge--sin-punto"><?= number_format(count($productos)) ?> producto(s)</span>
        </header>
        <div class="tarjeta__cuerpo">
            <?php if (!$productos) : ?>
                <div class="vacio">
                    <span class="material-symbols-outlined vacio__icono" aria-hidden="true">inventory_2</span>
                    <p class="vacio__titulo">Sin productos en el periodo</p>
                    <p>Cuando haya ventas registradas, aquí aparecerá el desglose por producto.</p>
                </div>
            <?php else : ?>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <caption class="solo-lectores">Productos comprados por el cliente</caption>
                        <thead>
                            <tr>
                                <th scope="col">Producto</th>
                                <th scope="col" class="tabla--numerica">Piezas</th>
                                <th scope="col" class="tabla--numerica">Ventas</th>
                                <th scope="col" class="tabla--numerica">Importe</th>
                                <th scope="col" class="tabla--numerica">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productos as $producto) :
                                $pct = $totalImporteProductos > 0 ? $producto['importe'] / $totalImporteProductos * 100 : 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="tabla__producto">
                                            <div class="tabla__producto-textos">
                                                <strong><?= escaparValores($producto['nombre']) ?></strong>
                                                <span class="texto-xs texto-secundario">
                                                    <?= escaparValores($producto['sku'] ?: '—') ?>
                                                    · <?= escaparValores($producto['unidad']) ?>
                                                    <?php if ($producto['ultima'] !== '') : ?>
                                                        · última <?= date('d/m/Y', strtotime($producto['ultima'])) ?>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="tabla--numerica cifra"><?= number_format($producto['piezas']) ?></td>
                                    <td class="tabla--numerica cifra"><?= number_format($producto['ventas']) ?></td>
                                    <td class="tabla--numerica cifra"><?= $m($producto['importe']) ?></td>
                                    <td class="tabla--numerica cifra"><?= number_format($pct, 1) ?> %</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="campo__ayuda mb-0">Importes de partidas sin IVA; el descuento global se muestra en el desglose del periodo.</p>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if ($serie) : ?>
<section class="tarjeta mb-lg">
    <header class="tarjeta__cabecera">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
            Compras por mes · <?= $anio ?>
        </h2>
    </header>
    <div class="tarjeta__cuerpo">
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Compras mensuales del cliente</caption>
                <thead>
                    <tr>
                        <th scope="col">Mes</th>
                        <th scope="col" class="tabla--numerica">Ventas</th>
                        <th scope="col" class="tabla--numerica">Piezas</th>
                        <th scope="col" class="tabla--numerica">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($serie as $fila) : ?>
                        <tr>
                            <td><?= escaparValores($nombreMes($fila['mes'])) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format($fila['ventas']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format($fila['piezas']) ?></td>
                            <td class="tabla--numerica cifra"><?= $m($fila['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <h2 class="tarjeta__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">history</span>
            Ventas del periodo
        </h2>
        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($totalDocs) ?> <?= $totalDocs === 1 ? 'venta' : 'ventas' ?>
        </span>
    </div>

    <?php if (!$documentos) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">point_of_sale</span>
            <p class="vacio__titulo">Sin ventas en el periodo</p>
            <p>Cambia el mes, el año o el rango para consultar otro periodo.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla tabla--documentos">
                <caption class="solo-lectores">Ventas registradas del cliente en el periodo</caption>
                <thead>
                    <tr>
                        <th scope="col">Folio</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Negocio</th>
                        <th scope="col" class="tabla--numerica">Piezas</th>
                        <th scope="col" class="tabla--numerica">Total</th>
                        <th scope="col">Entrega</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documentos as $documento) :
                        $estadoEntrega = \App\IncidenciasEntrega::ESTADOS_ENTREGA[$documento['entrega_estado'] ?? ''] ?? null;
                        $etiquetaEntrega = [
                            'pendiente' => 'Por entregar',
                            'entregado' => 'Entregado',
                            'entregado_con_incidencias' => 'Con incidencias',
                            'entregado_incidencias_resueltas' => 'Incidencias resueltas',
                        ][$documento['entrega_estado'] ?? ''] ?? ($estadoEntrega['etiqueta'] ?? '');
                        ?>
                        <tr>
                            <td class="doc-folio">
                                <a href="/admin/ventas/detalle?id=<?= (int) $documento['id'] ?>"><strong><?= escaparValores($documento['folio']) ?></strong></a>
                                <?php if (!empty($documento['cotizacion_id'])) : ?>
                                    <span class="badge badge--neutro">Cotización</span>
                                <?php else : ?>
                                    <span class="badge badge--info">Venta directa</span>
                                <?php endif; ?>
                                <?php if (!empty($documento['proyecto_folio'])) : ?>
                                    <span class="badge badge--aprobado" title="<?= escaparValores((string) ($documento['proyecto_nombre'] ?? '')) ?>">
                                        <?= escaparValores((string) $documento['proyecto_folio']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="doc-fecha"><?= date('d/m/Y', strtotime($documento['fecha'])) ?></td>
                            <td class="doc-negocio"><?= escaparValores($documento['empresa_nombre']) ?></td>
                            <td class="tabla--numerica cifra"><?= number_format((int) $documento['piezas']) ?></td>
                            <td class="tabla--numerica cifra doc-total"><strong><?= $m($documento['total']) ?></strong></td>
                            <td class="doc-estado">
                                <?php if ($estadoEntrega) : ?>
                                    <span class="badge <?= escaparValores($estadoEntrega['clase']) ?>"><?= escaparValores($etiquetaEntrega) ?></span>
                                <?php else : ?>
                                    <span class="texto-sm texto-secundario">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="tabla--numerica">
                                <a class="btn btn--texto btn--sm" href="/admin/ventas/detalle?id=<?= (int) $documento['id'] ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">fact_check</span>
                                    <span>Expediente</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php template('paginacion', [
            'pagina'   => $pagina,
            'rutaBase' => $urlBase,
            'total'    => $totalDocs,
        ]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
