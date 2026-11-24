<?php
/**
 * views/admin/compras/resumen.php — Resumen de compras, pensado como punto de partida: el recorrido de una compra (pedir, recibir, facturar, pagar)
 * y, debajo, lo que está pendiente en cada paso con el botón para atenderlo.
 * Solo consulta: abrirla nunca modifica inventario, pagos ni contabilidad.
 */

soloAdmin();

$ind = \App\OrdenesCompra::indicadores();
$porRecibir = \App\OrdenesCompra::pendientesDeRecibir(0, 10);
$ultimas = \App\OrdenesCompra::listar([], 10, 0);
$sinCfdi = \App\OrdenesCompra::listar(['activas' => true, 'cfdi' => 'pendiente_cfdi'], 10, 0);
$conSaldo = \App\OrdenesCompra::listar(['pago' => 'con_saldo'], 10, 0);

$accionesCompras = '<a class="btn btn--secundario" href="/admin/compras/inteligente">'
    . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">document_scanner</span>'
    . '<span>Importar factura</span></a>'
    . '<a class="btn btn--primario" href="/admin/compras/crear">'
    . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_box</span>'
    . '<span>Nueva compra</span></a>';

template('header', [
    'titulo'    => 'Compras y proveedores',
    'subtitulo' => 'Del pedido al pago',
    'acciones'  => $accionesCompras,
]);
template('tabsCompras', ['tab' => 'resumen']);

/**
 * Los cuatro pasos del abastecimiento, con la cifra que importa en cada uno.
 * El primero es una llamada a la acción; los demás llevan a su lista.
 */
$pasos = [
    [
        'numero'      => '1',
        'etiqueta'    => 'Pedir',
        'icono'       => 'add_box',
        'tipo'        => 'accion',
        'valor'       => 'Nueva compra',
        'pie'         => 'Pide al proveedor',
        'enlace'      => '/admin/compras/crear',
        'textoEnlace' => 'Empezar',
        'alerta'      => false,
    ],
    [
        'numero'      => '2',
        'etiqueta'    => 'Recibir',
        'icono'       => 'inventory_2',
        'tipo'        => 'numero',
        'valor'       => number_format((int) $ind['por_recibir']),
        'pie'         => (int) $ind['por_recibir'] === 1
            ? 'compra con piezas por recibir'
            : 'compras con piezas por recibir',
        'enlace'      => '#por-recibir',
        'textoEnlace' => 'Ver recepciones',
        'alerta'      => (int) $ind['por_recibir'] > 0,
    ],
    [
        'numero'      => '3',
        'etiqueta'    => 'Adjuntar factura',
        'icono'       => 'receipt_long',
        'tipo'        => 'numero',
        'valor'       => number_format((int) $ind['sin_cfdi']),
        'pie'         => formatoMoneda((float) $ind['importe_sin_cfdi']) . ' sin factura',
        'enlace'      => '#por-facturar',
        'textoEnlace' => 'Ver facturas',
        'alerta'      => (int) $ind['sin_cfdi'] > 0,
    ],
    [
        'numero'      => '4',
        'etiqueta'    => 'Pagar',
        'icono'       => 'payments',
        'tipo'        => 'importe',
        'valor'       => formatoMoneda((float) $ind['saldo']),
        'pie'         => number_format((int) $ind['con_saldo'])
            . ((int) $ind['con_saldo'] === 1 ? ' compra con saldo' : ' compras con saldo'),
        'enlace'      => '#por-pagar',
        'textoEnlace' => 'Ver pagos',
        'alerta'      => (float) $ind['saldo'] > 0,
    ],
];
?>

<section class="compras-flujo" aria-labelledby="titulo-flujo">
    <div class="compras-flujo__cabecera">
        <div>
            <h2 class="compras-flujo__titulo" id="titulo-flujo">Flujo de una compra</h2>
            <p class="compras-flujo__sub">Pedir, recibir, facturar y pagar. Toca un paso para atender lo que está pendiente.</p>
        </div>
    </div>

    <div class="compras-flujo__rejilla">
        <?php foreach ($pasos as $paso) : ?>
            <a class="compras-paso<?= $paso['alerta'] ? ' compras-paso--alerta' : '' ?>"
               href="<?= escaparValores($paso['enlace']) ?>">
                <span class="compras-paso__cabecera">
                    <span class="compras-paso__numero"><?= $paso['numero'] ?></span>
                    <span class="compras-paso__icono" aria-hidden="true">
                        <span class="material-symbols-outlined"><?= $paso['icono'] ?></span>
                    </span>
                </span>

                <span class="compras-paso__cuerpo">
                    <span class="compras-paso__etiqueta">
                        <?= escaparValores($paso['etiqueta']) ?>
                        <?php if ($paso['alerta']) : ?>
                            <span class="compras-paso__punto" title="Con pendientes"></span>
                        <?php endif; ?>
                    </span>

                    <?php if ($paso['tipo'] === 'accion') : ?>
                        <span class="compras-paso__accion"><?= escaparValores($paso['valor']) ?></span>
                    <?php else : ?>
                        <strong class="compras-paso__valor cifra<?= $paso['tipo'] === 'importe' ? ' compras-paso__valor--importe' : '' ?>"><?= $paso['valor'] ?></strong>
                    <?php endif; ?>

                    <span class="compras-paso__pie"><?= escaparValores($paso['pie']) ?></span>
                </span>

                <span class="compras-paso__enlace">
                    <?= escaparValores($paso['textoEnlace']) ?>
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="compras-seccion" id="ultimas" aria-labelledby="titulo-ultimas">
    <div class="compras-seccion__cabecera">
        <div class="compras-seccion__texto">
            <h2 class="compras-seccion__titulo" id="titulo-ultimas">
                <span class="compras-seccion__icono" aria-hidden="true">
                    <span class="material-symbols-outlined">shopping_cart</span>
                </span>
                Compras y pedidos recientes
            </h2>
            <p class="compras-seccion__sub">Las últimas 10 compras registradas.</p>
        </div>
        <a class="btn btn--texto btn--sm compras-seccion__enlace" href="/admin/compras/pedidos">
            <span>Ver todas las compras</span>
            <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
        </a>
    </div>

    <div class="tarjeta compras-caja">
        <?php if (!$ultimas) : ?>
            <div class="compras-vacio">
                <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
                <p>Aún no hay compras. Empieza con «Nueva compra».</p>
                <a class="btn btn--primario btn--sm" href="/admin/compras/crear">Nueva compra</a>
            </div>
        <?php else : ?>
            <div class="tabla-contenedor tabla-contenedor--scroll">
                <table class="tabla compras-tabla compras-tabla--ancha" data-sin-tarjetas>
                    <caption class="solo-lectores">Últimas compras</caption>
                    <thead>
                        <tr>
                            <th scope="col">Compra</th>
                            <th scope="col">Proveedor</th>
                            <th scope="col">Fecha</th>
                            <th scope="col" class="tabla--numerica">Total</th>
                            <th scope="col" class="tabla--centro">Recepción</th>
                            <th scope="col" class="tabla--centro">Factura</th>
                            <th scope="col" class="tabla--centro">Pago</th>
                            <th scope="col" class="tabla--centro">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ultimas as $o) :
                        $est = \App\OrdenesCompra::ESTADOS[$o['estado']];
                        $cfdi = \App\CompraContable::ESTADOS_CFDI[$o['estado_cfdi']];
                        $pago = \App\PagosCompra::ESTADOS[$o['estado_pago']]; ?>
                        <tr>
                            <td><strong class="compras-folio cifra"><?= escaparValores($o['folio']) ?></strong></td>
                            <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                            <td><span class="compras-fecha cifra"><?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?></span></td>
                            <td class="tabla--numerica cifra compras-importe"><?= formatoMoneda($o['total_compra']) ?></td>
                            <td class="tabla--centro">
                                <span class="compras-estado">
                                    <span class="badge <?= $est['clase'] ?>"><?= escaparValores($est['etiqueta']) ?></span>
                                    <?php if (trim((string) $o['motivo_cierre']) !== '') : ?>
                                        <span class="compras-nota" title="Nota de cierre: <?= escaparValores($o['motivo_cierre']) ?>">
                                            <span class="material-symbols-outlined" aria-hidden="true">info</span>
                                            <span class="solo-lectores">Con nota de cierre</span>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="tabla--centro"><span class="badge <?= $cfdi['clase'] ?>"><?= escaparValores($cfdi['etiqueta']) ?></span></td>
                            <td class="tabla--centro">
                                <?= $o['estado'] === 'cancelada'
                                    ? '<span class="texto-secundario">—</span>'
                                    : '<span class="badge ' . $pago['clase'] . '">' . escaparValores($pago['etiqueta']) . '</span>' ?>
                            </td>
                            <td class="tabla--numerica">
                                <a class="btn btn--secundario btn--sm compras-accion"
                                   href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>"
                                   aria-label="Ver la compra <?= escaparValores($o['folio']) ?>"
                                   title="Ver la compra <?= escaparValores($o['folio']) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                                    <span class="movil-oculto">Ver</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="compras-desliza">
                <span class="material-symbols-outlined" aria-hidden="true">swipe</span>
                Desliza la tabla para ver todas las columnas.
            </p>
        <?php endif; ?>
    </div>
</section>

<section class="compras-seccion" id="por-recibir" aria-labelledby="titulo-por-recibir">
    <div class="compras-seccion__cabecera">
        <div class="compras-seccion__texto">
            <h2 class="compras-seccion__titulo" id="titulo-por-recibir">
                <span class="compras-seccion__icono" aria-hidden="true">
                    <span class="material-symbols-outlined">inventory_2</span>
                </span>
                Piezas por recibir
                <?php if ($porRecibir) : ?>
                    <span class="badge badge--pendiente badge--sin-punto"><?= count($porRecibir) ?> partidas</span>
                <?php endif; ?>
            </h2>
            <p class="compras-seccion__sub">Lo que pediste y todavía no llega completo. «Recibir» te deja anotar cuánto llegó.</p>
        </div>
        <a class="btn btn--texto btn--sm compras-seccion__enlace" href="/admin/compras/recepciones">
            <span>Ver recepciones</span>
            <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
        </a>
    </div>

    <div class="tarjeta compras-caja">
        <?php if (!$porRecibir) : ?>
            <div class="compras-vacio compras-vacio--ok">
                <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                <p>No hay piezas pendientes de recibir.</p>
            </div>
        <?php else : ?>
            <div class="tabla-contenedor">
                <table class="tabla compras-tabla compras-tabla--recibir">
                    <caption class="solo-lectores">Piezas pendientes de recibir</caption>
                    <thead>
                        <tr>
                            <th scope="col">Compra</th>
                            <th scope="col">Proveedor</th>
                            <th scope="col">Artículo</th>
                            <th scope="col" class="tabla--numerica">Faltan</th>
                            <th scope="col"><span class="solo-lectores">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($porRecibir as $f) : ?>
                        <tr>
                            <td><strong class="compras-folio cifra"><?= escaparValores($f['folio']) ?></strong></td>
                            <td><?= escaparValores($f['proveedor_nombre']) ?></td>
                            <td>
                                <span class="compras-articulo"><?= escaparValores($f['nombre_mostrado']) ?></span>
                                <?php if (!$f['producto_id'] && !$f['material_id']) : ?>
                                    <span class="badge badge--neutro badge--sin-punto">Sin relacionar</span>
                                <?php endif; ?>
                            </td>
                            <td class="tabla--numerica">
                                <span class="badge badge--pendiente badge--sin-punto compras-faltan"><?= (int) $f['pendiente'] ?> de <?= (int) $f['solicitada'] ?></span>
                            </td>
                            <td class="tabla--numerica">
                                <a class="btn btn--primario btn--sm compras-accion"
                                   href="/admin/compras/recibir?id=<?= (int) $f['orden_id'] ?>"
                                   aria-label="Recibir piezas de la compra <?= escaparValores($f['folio']) ?>"
                                   title="Recibir piezas de la compra <?= escaparValores($f['folio']) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                                    <span>Recibir</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="compras-doble">
    <section class="compras-seccion" id="por-facturar" aria-labelledby="titulo-por-facturar">
        <div class="compras-seccion__cabecera">
            <div class="compras-seccion__texto">
                <h2 class="compras-seccion__titulo" id="titulo-por-facturar">
                    <span class="compras-seccion__icono" aria-hidden="true">
                        <span class="material-symbols-outlined">receipt_long</span>
                    </span>
                    Facturas por adjuntar
                    <?php if ((int) $ind['sin_cfdi'] > 0) : ?>
                        <span class="badge badge--pendiente badge--sin-punto"><?= number_format((int) $ind['sin_cfdi']) ?></span>
                    <?php endif; ?>
                </h2>
                <p class="compras-seccion__sub">Compras que aún no tienen su factura (CFDI).</p>
            </div>
            <a class="btn btn--texto btn--sm compras-seccion__enlace" href="/admin/compras/facturas?cfdi=pendiente_cfdi">
                <span>Ver todas</span>
                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </a>
        </div>

        <div class="tarjeta compras-caja compras-caja--tarjetas">
            <?php if (!$sinCfdi) : ?>
                <div class="compras-vacio compras-vacio--ok">
                    <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                    <p>Todas las compras tienen su documentación al día.</p>
                </div>
            <?php else : ?>
                <div class="tabla-contenedor">
                    <table class="tabla compras-tabla compras-tabla--compacta">
                        <caption class="solo-lectores">Compras pendientes de factura</caption>
                        <thead>
                            <tr>
                                <th scope="col">Compra</th>
                                <th scope="col">Proveedor</th>
                                <th scope="col" class="tabla--numerica">Total</th>
                                <th scope="col"><span class="solo-lectores">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sinCfdi as $o) : ?>
                            <tr>
                                <td><strong class="compras-folio cifra"><?= escaparValores($o['folio']) ?></strong></td>
                                <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                                <td class="tabla--numerica cifra compras-importe"><?= formatoMoneda($o['total_compra']) ?></td>
                                <td class="tabla--numerica">
                                    <a class="btn btn--secundario btn--sm compras-accion"
                                       href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#factura"
                                       aria-label="Adjuntar factura a la compra <?= escaparValores($o['folio']) ?>"
                                       title="Adjuntar factura a la compra <?= escaparValores($o['folio']) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true">upload_file</span>
                                        <span>Adjuntar</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="compras-caja__pie">
                    <span>Total pendiente de comprobar</span>
                    <strong class="cifra"><?= formatoMoneda((float) $ind['importe_sin_cfdi']) ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="compras-seccion" id="por-pagar" aria-labelledby="titulo-por-pagar">
        <div class="compras-seccion__cabecera">
            <div class="compras-seccion__texto">
                <h2 class="compras-seccion__titulo" id="titulo-por-pagar">
                    <span class="compras-seccion__icono" aria-hidden="true">
                        <span class="material-symbols-outlined">payments</span>
                    </span>
                    Pagos por realizar
                    <?php if ((int) $ind['con_saldo'] > 0) : ?>
                        <span class="badge badge--pendiente badge--sin-punto"><?= number_format((int) $ind['con_saldo']) ?></span>
                    <?php endif; ?>
                </h2>
                <p class="compras-seccion__sub">Compras con saldo: lo que todavía se le debe al proveedor.</p>
            </div>
            <a class="btn btn--texto btn--sm compras-seccion__enlace" href="/admin/compras/pagos">
                <span>Ver pagos</span>
                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </a>
        </div>

        <div class="tarjeta compras-caja compras-caja--tarjetas">
            <?php if (!$conSaldo) : ?>
                <div class="compras-vacio compras-vacio--ok">
                    <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                    <p>No hay saldos pendientes con proveedores.</p>
                </div>
            <?php else : ?>
                <div class="tabla-contenedor">
                    <table class="tabla compras-tabla compras-tabla--compacta">
                        <caption class="solo-lectores">Compras con saldo</caption>
                        <thead>
                            <tr>
                                <th scope="col">Compra</th>
                                <th scope="col">Proveedor</th>
                                <th scope="col" class="tabla--numerica">Saldo</th>
                                <th scope="col"><span class="solo-lectores">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($conSaldo as $o) : ?>
                            <tr>
                                <td><strong class="compras-folio cifra"><?= escaparValores($o['folio']) ?></strong></td>
                                <td><?= escaparValores($o['proveedor_nombre']) ?></td>
                                <td class="tabla--numerica cifra compras-importe"><?= formatoMoneda($o['saldo']) ?></td>
                                <td class="tabla--numerica">
                                    <a class="btn btn--secundario btn--sm compras-accion"
                                       href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>#pagos"
                                       aria-label="Registrar pago de la compra <?= escaparValores($o['folio']) ?>"
                                       title="Registrar pago de la compra <?= escaparValores($o['folio']) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true">payments</span>
                                        <span>Pagar</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="compras-caja__pie">
                    <span>Total saldo exigible</span>
                    <strong class="cifra"><?= formatoMoneda((float) $ind['saldo']) ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php template('footer'); ?>
