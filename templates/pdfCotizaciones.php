<?php
/**
 * templates/pdfCotizaciones.php — Documento HTML que alimenta el PDF.
 *
 * Se usa desde views/admin/cotizaciones/pdf.php y views/admin/ventas/pdf.php:
 * cotizaciones y ventas directas comparten el mismo diseño de documento.
 *
 * dompdf sólo entiende un subconjunto de CSS: por eso este archivo usa
 * tablas para maquetar, no grid ni flexbox, y no hace peticiones externas.
 * Las imágenes se insertan en base64 para que dompdf no necesite leer
 * archivos del disco ni salir a Internet.
 *
 * Variables esperadas:
 *   $cotizacion — instancia de App\Cotizaciones o App\Ventas (con sus datos de unión)
 *   $detalles   — renglones de App\Cotizaciones::detalles()
 *
 * @var \App\Cotizaciones|\App\Ventas $cotizacion
 * @var array<int, array<string, mixed>> $detalles
 */

/** Ruta absoluta de una imagen de producto/logo, o '' si no existe. */
$archivoAbsoluto = static function (?string $archivo): string {
    $archivo = trim((string) $archivo);
    if ($archivo === '' || strpos($archivo, '..') !== false) {
        return '';
    }

    $ruta = dirname(__DIR__) . '/public/image/' . $archivo;
    return is_file($ruta) ? $ruta : '';
};

/** Imagen en base64 lista para src="", o '' si no hay archivo. */
$imagenBase64 = static function (?string $archivo) use ($archivoAbsoluto): string {
    $ruta = $archivoAbsoluto($archivo);
    if ($ruta === '') {
        return '';
    }

    $datos = file_get_contents($ruta);
    if ($datos === false) {
        return '';
    }

    $tipo = function_exists('mime_content_type') ? mime_content_type($ruta) : false;
    if (!is_string($tipo) || strpos($tipo, 'image/') !== 0) {
        $tipo = 'image/jpeg';
    }

    return 'data:' . $tipo . ';base64,' . base64_encode($datos);
};

/** Miniatura de un producto (300×300) ya convertida a base64. */
$miniaturaBase64 = static function (?string $archivo) use ($imagenBase64): string {
    $archivo = trim((string) $archivo);
    if ($archivo === '') {
        return '';
    }

    $mini = preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', $archivo);
    $datos = $mini !== null ? $imagenBase64($mini) : '';

    // Si no existe la miniatura, se usa la imagen completa.
    return $datos !== '' ? $datos : $imagenBase64($archivo);
};

/* --- Cálculos de presentación -------------------------------------------- */

$desglose           = \App\Cotizaciones::desgloseDescuento($cotizacion, $detalles);
$descuentoRenglones = $desglose['renglones'];
$descuentoGlobal    = $desglose['global'];
$pctGlobal          = $desglose['pct'];
$base               = round((float) $cotizacion->subtotal - (float) $cotizacion->descuento, 2);

$esVenta = $cotizacion instanceof \App\Ventas;
$tipoDoc = $esVenta ? 'Venta' : 'Cotización';
$estado  = $esVenta
    ? \App\Ventas::datosEstado($cotizacion->estado)
    : \App\Cotizaciones::datosEstado($cotizacion->estado);
$logo   = \App\Imagenes::logoPdfHtml($cotizacion->empresa_logo, (string) $cotizacion->empresa_nombre);

$vence = '';
if (!$esVenta && $cotizacion->fecha) {
    $vence = date('d/m/Y', strtotime($cotizacion->fecha . ' + ' . (int) $cotizacion->vigencia_dias . ' days'));
}

$empresa = trim((string) $cotizacion->empresa_direccion);
if (trim((string) $cotizacion->empresa_ciudad) !== '') {
    $empresa .= ($empresa !== '' ? ', ' : '') . $cotizacion->empresa_ciudad;
}
if (trim((string) $cotizacion->empresa_estado) !== '') {
    $empresa .= ($empresa !== '' ? ', ' : '') . $cotizacion->empresa_estado;
}
if (trim((string) $cotizacion->empresa_cp) !== '') {
    $empresa .= ($empresa !== '' ? ' C.P. ' : 'C.P. ') . $cotizacion->empresa_cp;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= $tipoDoc ?> <?= escaparValores($cotizacion->folio) ?></title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.5rem;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #4d4c53;
        }

        h1, h2, h3 { margin: 0; font-weight: 700; color: #4679a6; }
        p { margin: 0 0 0.4rem; }

        .barrasup {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.1rem;
        }
        .barrasup td { vertical-align: top; padding: 0; }

        .marca { font-size: 17px; font-weight: 700; color: #4679a6; }
        /* Logo: tamaño calculado en PHP (Imagenes::logoPdf, «contain» dentro de una caja configurable por empresa);
           aquí solo el margen. */
        .logo  { display: block; margin-bottom: 0.4rem; }
        .datos { font-size: 10px; color: #6b6b70; line-height: 1.5; }

        .documento { text-align: right; }
        .documento .folio {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            background-color: #eaf1f8;
            border: 1px solid #c7dcef;
            border-radius: 3px;
            font-size: 14px;
            font-weight: 700;
            color: #4679a6;
        }
        .documento .estado {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            background-color: #f7f7f8;
            border: 1px solid #e4e6ea;
            border-radius: 3px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b6b70;
        }

        .caja {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0.7rem;
        }
        .caja th {
            text-align: left;
            padding: 0.3rem 0.4rem;
            background-color: #f7f7f8;
            border: 1px solid #e4e6ea;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b6b70;
            vertical-align: top;
        }
        .caja td {
            padding: 0.3rem 0.4rem;
            border: 1px solid #e4e6ea;
            font-size: 10.5px;
            line-height: 1.35;
            vertical-align: top;
        }

        .titulo-seccion {
            margin: 0.8rem 0 0.3rem;
            padding-bottom: 0.2rem;
            border-bottom: 2px solid #c7dcef;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th {
            padding: 0.3rem;
            background-color: #eaf1f8;
            border: 1px solid #c7dcef;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #4679a6;
            text-align: left;
        }
        table.items td {
            padding: 0.3rem;
            border: 1px solid #e4e6ea;
            font-size: 10px;
            line-height: 1.3;
            vertical-align: middle;
        }
        table.items .num { text-align: right; }
        table.items .centro { text-align: center; }
        table.items .nombre { font-weight: 700; font-size: 10.5px; }
        table.items .sku { font-size: 9px; color: #64646a; }
        table.items .clave {
            font-family: Courier, monospace;
            font-size: 9.5px;
            font-weight: 700;
            color: #4679a6;
        }

        /* Miniatura del producto en su propia columna. */
        table.items .mini {
            display: block;
            width: 3rem;      /* 48px: la miniatura del documento */
            height: 3rem;
            margin: 0 auto;
            object-fit: cover;
            background-color: #f7f7f8;
            border: 1px solid #e4e6ea;
            border-radius: 3px;
        }

        .sello-especial {
            display: inline-block;
            padding: 0 0.3rem;
            background-color: #faede3;
            border: 1px solid #f2cfb3;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 700;
            color: #b46424;
        }

        table.totales { width: 100%; border-collapse: collapse; }
        table.totales td { padding: 0.3rem 0.5rem; border-bottom: 1px dashed #e4e6ea; }
        table.totales .etiqueta { font-size: 10.5px; }
        table.totales .importe { text-align: right; font-weight: 700; white-space: nowrap; }
        table.totales .negativo { color: #e11d48; }
        table.totales .fila-total td {
            border-top: 2px solid #c7dcef;
            border-bottom: 0;
            padding-top: 0.5rem;
            font-size: 13px;
            font-weight: 700;
            color: #4679a6;
        }

        .condicion { margin-bottom: 0.4rem; }
        .condicion strong { display: block; font-size: 10px; color: #4679a6; }
        .condicion span { font-size: 10px; }

        .letra {
            margin-top: 0.4rem;
            padding: 0.4rem 0.5rem;
            background-color: #f7f7f8;
            border: 1px solid #e4e6ea;
            font-size: 10px;
            font-style: italic;
        }

        .pie {
            margin-top: 0.6rem;
            padding-top: 0.35rem;
            border-top: 1px solid #e4e6ea;
            font-size: 9px;
            color: #64646a;
            text-align: center;
        }

        .sin-margen { margin-bottom: 0.2rem; }
    </style>
</head>
<body>

<!-- Cabecera: negocio emisor + folio del documento -->
<table class="barrasup">
    <tr>
        <td>
            <?php if ($logo !== '') : ?>
                <?= $logo ?>
            <?php endif; ?>
            <div class="marca"><?= escaparValores($cotizacion->empresa_nombre) ?></div>
            <div class="datos">
                <?php if ($empresa !== '') : ?>
                    <?= escaparValores($empresa) ?><br>
                <?php endif; ?>
                <?php if (trim((string) $cotizacion->empresa_telefono) !== '') : ?>
                    Tel. <?= escaparValores($cotizacion->empresa_telefono) ?><br>
                <?php endif; ?>
                <?php if (trim((string) $cotizacion->empresa_email) !== '') : ?>
                    <?= escaparValores($cotizacion->empresa_email) ?>
                <?php endif; ?>
            </div>
        </td>
        <td class="documento">
            <div class="folio"><?= escaparValores($cotizacion->folio) ?></div>
            <p class="datos sin-margen" style="margin-top:0.5rem">
                <strong><?= $tipoDoc ?></strong><br>
                Fecha: <?= date('d/m/Y', strtotime($cotizacion->fecha)) ?>
                <?php if (!$esVenta) : ?>
                    <br>Vigencia: <?= (int) $cotizacion->vigencia_dias ?> días
                    <?php if ($vence !== '') : ?>
                        <br>Vence: <?= $vence ?>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <span class="estado"><?= escaparValores($estado['etiqueta']) ?></span>
        </td>
    </tr>
</table>

<!-- Cliente: una sola fila con las cuatro parejas etiqueta/valor,
     para que el bloque ocupe la menor altura posible. -->
<h2 class="titulo-seccion">Cliente</h2>
<table class="caja">
    <tr>
        <th style="width:7%">Cliente</th>
        <td style="width:22%">
            <strong><?= escaparValores($cotizacion->cliente_nombre) ?></strong>
            <?php if ((int) $cotizacion->cliente_vip === 1) : ?>
                <span class="sello-especial">VIP</span>
            <?php endif; ?>
            <?php if (trim((string) $cotizacion->cliente_empresa) !== '') : ?><br>
                <?= escaparValores($cotizacion->cliente_empresa) ?>
            <?php endif; ?>
        </td>
        <th style="width:8%">Contacto</th>
        <td style="width:24%">
            <?php if (trim((string) $cotizacion->cliente_telefono) !== '') : ?>
                <?= escaparValores($cotizacion->cliente_telefono) ?><br>
            <?php endif; ?>
            <?php if (trim((string) $cotizacion->cliente_email) !== '') : ?>
                <?= escaparValores($cotizacion->cliente_email) ?><br>
            <?php endif; ?>
            <?php if (trim((string) $cotizacion->cliente_ubicacion) !== '') : ?>
                <?= escaparValores($cotizacion->cliente_ubicacion) ?>
            <?php endif; ?>
        </td>
        <th style="width:8%">Moneda</th>
        <td style="width:7%">MXN</td>
    </tr>
</table>

<!-- Renglones: #, clave, imagen, nombre, unidad, precio, cantidad e importe -->
<h2 class="titulo-seccion">Partidas de la <?= $esVenta ? 'venta' : 'cotización' ?></h2>
<table class="items">
    <thead>
        <tr>
            <th class="centro" style="width:4%">#</th>
            <th style="width:9%">Clave</th>
            <th class="centro" style="width:9%">Imagen</th>
            <th style="width:33%">Nombre</th>
            <th class="centro" style="width:7%">Unidad</th>
            <th class="num" style="width:12%">Precio</th>
            <th class="num" style="width:10%">Cantidad</th>
            <th class="num" style="width:16%">Importe</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($detalles as $indice => $detalle) :
            $especial = (float) $detalle['precio_unitario'] !== (float) $detalle['producto_precio'];
            $descuento = (float) $detalle['descuento_pct'];
            $miniatura = $miniaturaBase64($detalle['producto_imagen']);
            $clave = trim((string) $detalle['producto_sku']);
            ?>
            <tr>
                <td class="centro"><?= $indice + 1 ?></td>
                <td class="clave"><?= $clave !== '' ? escaparValores($clave) : '—' ?></td>
                <td class="centro">
                    <?php if ($miniatura !== '') : ?>
                        <img class="mini" src="<?= $miniatura ?>" alt="">
                    <?php else : ?>
                        <span class="sku">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="nombre"><?= escaparValores($detalle['producto_nombre']) ?></span>
                    <?php if ($especial) : ?>
                        <span class="sello-especial">Precio especial</span>
                    <?php endif; ?>
                    <?php if ($descuento > 0) : ?><br>
                        <span class="sku">Descuento <?= number_format($descuento, 2) ?> % por partida</span>
                    <?php endif; ?>
                </td>
                <td class="centro"><?= escaparValores($detalle['producto_unidad']) ?></td>
                <td class="num"><?= formatoMoneda($detalle['precio_unitario']) ?></td>
                <td class="num"><?= number_format((int) $detalle['cantidad']) ?></td>
                <td class="num"><strong><?= formatoMoneda($detalle['subtotal']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Condiciones comerciales -->
<?php
$hayCondiciones = trim((string) $cotizacion->condiciones_envio) !== ''
    || trim((string) $cotizacion->condiciones_compra) !== ''
    || trim((string) $cotizacion->comentarios) !== '';
?>

<?php if ($hayCondiciones) : ?>
    <h2 class="titulo-seccion">Términos comerciales y logística</h2>

    <?php if (trim((string) $cotizacion->condiciones_envio) !== '') : ?>
        <div class="condicion">
            <strong>Entrega</strong>
            <span><?= nl2br(escaparValores($cotizacion->condiciones_envio)) ?></span>
        </div>
    <?php endif; ?>

    <?php if (trim((string) $cotizacion->condiciones_compra) !== '') : ?>
        <div class="condicion">
            <strong>Pago</strong>
            <span><?= nl2br(escaparValores($cotizacion->condiciones_compra)) ?></span>
        </div>
    <?php endif; ?>

    <?php if (trim((string) $cotizacion->comentarios) !== '') : ?>
        <div class="condicion">
            <strong>Comentarios adicionales</strong>
            <span><?= nl2br(escaparValores($cotizacion->comentarios)) ?></span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Resumen -->
<h2 class="titulo-seccion">Resumen de liquidación</h2>
<table class="totales">
    <tr>
        <td class="etiqueta">Subtotal bruto de partidas</td>
        <td class="importe"><?= formatoMoneda($cotizacion->subtotal) ?></td>
    </tr>
    <?php if ($descuentoRenglones > 0) : ?>
        <tr>
            <td class="etiqueta">Descuento por renglón</td>
            <td class="importe negativo">-<?= formatoMoneda($descuentoRenglones) ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($descuentoGlobal > 0) : ?>
        <tr>
            <td class="etiqueta">
                Descuento global
                <?php if ($pctGlobal > 0) : ?>
                    (<?= number_format($pctGlobal, 2) ?>%)
                <?php endif; ?>
            </td>
            <td class="importe negativo">-<?= formatoMoneda($descuentoGlobal) ?></td>
        </tr>
    <?php endif; ?>
    <tr>
        <td class="etiqueta">Base gravable</td>
        <td class="importe"><?= formatoMoneda($base) ?></td>
    </tr>
    <tr>
        <td class="etiqueta">I.V.A. federal trasladado (16%)</td>
        <td class="importe"><?= formatoMoneda($cotizacion->iva) ?></td>
    </tr>
    <tr class="fila-total">
        <td class="etiqueta">Total neto autorizado</td>
        <td class="importe"><?= formatoMoneda($cotizacion->total) ?></td>
    </tr>
</table>

<p class="letra"><?= escaparValores(numeroALetras((float) $cotizacion->total)) ?></p>

<p class="pie">
    <?= $tipoDoc ?> <?= escaparValores($cotizacion->folio) ?> ·
    <?= escaparValores($cotizacion->empresa_nombre) ?> ·
    Documento generado el <?= date('d/m/Y H:i') ?>.
    <?php if (!$esVenta) : ?>Los precios aquí expresados están sujetos a la vigencia indicada.<?php endif; ?>
</p>

</body>
</html>
