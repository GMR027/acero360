<?php
/**
 * templates/pdfInventario.php — Documento HTML que alimenta el PDF de
 * "Exportar Inventario".
 *
 * Un solo documento con UNA sección por empresa activa. Cada sección trae:
 *   encabezado  → fecha de exportación, logotipo y nombre de la empresa
 *   tabla       → Código · Producto · Precio de lista · Cantidad en
 *                 inventario · Monto total en inventario
 *   pie         → total de productos y total en importe de ESA empresa
 *
 * dompdf sólo entiende un subconjunto de CSS: por eso se usan tablas para
 * maquetar y las imágenes se insertan en base64 (nunca sale a Internet).
 *
 * Variables esperadas:
 *   $secciones — salida de Inventario::paraPdf()
 *   $fecha     — fecha de exportación en formato legible (dd/mm/aaaa)
 */

/** Ruta absoluta de una imagen de logo, o '' si no existe. */
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

/** Etiqueta plural para el número de productos de cada empresa. */
$etiquetaProductos = static function (int $cantidad): string {
    return number_format($cantidad) . ($cantidad === 1 ? ' producto' : ' productos');
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Inventario</title>
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

        h1 { margin: 0; font-size: 17px; font-weight: 700; color: #4679a6; }
        p  { margin: 0; }

        .documento-titulo {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.1rem;
        }
        .documento-titulo td { vertical-align: bottom; padding: 0; }
        .documento-titulo .derecha { text-align: right; font-size: 10px; color: #6b6b70; }

        .barrasup {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0.4rem;
        }
        .barrasup td { vertical-align: top; padding: 0; }
        .barrasup .derecha { text-align: right; }

        .logo {
            max-height: 54px;
            max-width: 190px;
            display: block;
            margin-bottom: 0.4rem;
        }
        .marca { font-size: 17px; font-weight: 700; color: #4679a6; }
        .datos { font-size: 10px; color: #6b6b70; line-height: 1.5; }

        .exportacion {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            background-color: #eaf1f8;
            border: 1px solid #c7dcef;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 700;
            color: #4679a6;
        }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th {
            padding: 0.3rem;
            background-color: #eaf1f8;
            border: 1px solid #c7dcef;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #4679a6;
            text-align: center;
        }
        table.items td {
            padding: 0.3rem;
            border: 1px solid #e4e6ea;
            font-size: 10px;
            line-height: 1.3;
            vertical-align: middle;
        }
        table.items .num  { text-align: right; }
        table.items .clave {
            font-family: Courier, monospace;
            font-size: 9.5px;
            font-weight: 700;
            color: #4679a6;
            text-align: center;
        }
        table.items .nombre { font-weight: 700; font-size: 10.5px; }

        table.items tr.total td {
            background-color: #f7f7f8;
            border-top: 2px solid #c7dcef;
            font-size: 10.5px;
            font-weight: 700;
            color: #4679a6;
        }
        table.items tr.total .num { font-size: 12px; }

        .sin-productos {
            padding: 0.5rem;
            border: 1px dashed #e4e6ea;
            font-size: 10px;
            color: #64646a;
            text-align: center;
        }

        .seccion-empresa { margin-bottom: 1.4rem; }

        .pie {
            margin-top: 1rem;
            padding-top: 0.35rem;
            border-top: 1px solid #e4e6ea;
            font-size: 9px;
            color: #64646a;
            text-align: center;
        }
    </style>
</head>
<body>

<!-- Cabecera del documento -->
<table class="documento-titulo">
    <tr>
        <td>
            <h1>Inventario de productos</h1>
            <p class="datos">Existencias valorizadas por negocio · Precios de lista en pesos mexicanos (MXN)</p>
        </td>
        <td class="derecha">
            Fecha de exportación<br>
            <strong><?= escaparValores($fecha) ?></strong>
        </td>
    </tr>
</table>

<?php foreach ($secciones as $indice => $seccion) :
    $logo      = $imagenBase64($seccion['logo']);
    $productos = $seccion['productos'];
    ?>

    <?php if ($indice > 0 && count($productos) > 8) : ?>
        <!-- Sólo se salta de página si la tabla siguiente no cabe en lo que resta. -->
        <div style="page-break-after: always;"></div>
    <?php endif; ?>

    <div class="seccion-empresa">
        <!-- Encabezado: logo + nombre de la empresa + fecha de exportación -->
        <table class="barrasup">
            <tr>
                <td>
                    <?php if ($logo !== '') : ?>
                        <img class="logo" src="<?= $logo ?>" alt="<?= escaparValores($seccion['nombre']) ?>">
                    <?php endif; ?>
                    <div class="marca"><?= escaparValores($seccion['nombre']) ?></div>
                </td>
                <td class="derecha">
                    <span class="exportacion">Exportado el <?= escaparValores($fecha) ?></span>
                </td>
            </tr>
        </table>

        <?php if (empty($productos)) : ?>
            <p class="sin-productos">Esta empresa no tiene productos que cumplan los criterios de inventario.</p>
        <?php else : ?>
            <table class="items">
                <thead>
                    <tr>
                        <th style="width:12%">Código</th>
                        <th style="width:40%">Producto</th>
                        <th style="width:17%">Precio de lista</th>
                        <th style="width:14%">Cantidad en inventario</th>
                        <th style="width:17%">Monto total en inventario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productos as $producto) : ?>
                        <tr>
                            <td class="clave"><?= escaparValores($producto['sku'] !== '' ? $producto['sku'] : '—') ?></td>
                            <td class="nombre"><?= escaparValores($producto['nombre']) ?></td>
                            <td class="num"><?= formatoMoneda($producto['precio']) ?></td>
                            <td class="num"><?= number_format($producto['stock']) ?></td>
                            <td class="num"><?= formatoMoneda($producto['monto']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <!-- Totales SÓLO de esta empresa. -->
                    <tr class="total">
                        <td colspan="3">
                            Totales de <?= escaparValores($seccion['nombre']) ?>
                            (<?= $etiquetaProductos(count($productos)) ?>)
                        </td>
                        <td class="num"><?= number_format($seccion['piezas']) ?></td>
                        <td class="num"><?= formatoMoneda($seccion['importe']) ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

<?php endforeach; ?>

<p class="pie">
    Inventario generado el <?= escaparValores($fecha) ?> · Cotizaciones Multi-Negocio
</p>

</body>
</html>
