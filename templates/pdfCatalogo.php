<?php
/**
 * templates/pdfCatalogo.php — Documento HTML que alimenta el PDF de
 * "Exportar catálogo de productos".
 *
 * Un solo documento con UNA sección por empresa activa. Cada sección trae:
 *   encabezado  → fecha de exportación, logotipo y nombre de la empresa
 *   tabla       → Imagen · Clave · Nombre · Costo  (columnas en horizontal)
 *   leyenda     → el aviso fiscal de ESA empresa (uno por sección, no uno
 *                 global: cada negocio fija sus propias condiciones de venta,
 *                 así que el lector nunca debe tener que adivinar a qué
 *                 empresa se aplica el pie de la última página)
 *
 * La tabla NO suma los costos: el catálogo es una lista de precios de
 * referencia para consultar en el campo, no un inventario valorizado (una
 * suma de costos invita a confundirla con lo que hay en almacén).
 *
 * dompdf sólo entiende un subconjunto de CSS: por eso se usan tablas para
 * maquetar y las imágenes se insertan en base64 (nunca sale a Internet).
 *
 * Variables esperadas:
 *   $secciones — salida de Productos::paraPdf()
 *   $fecha     — fecha de exportación en formato legible (dd/mm/aaaa)
 */

/** Ruta absoluta de una imagen de logo/producto, o '' si no existe. */
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

/** Leyenda fiscal que cierra CADA sección (una por empresa). */
$leyenda = 'Los precios no incluyen iva (16%), las ventas son con pago de contado.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Catálogo de productos</title>
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

        /* Tabla horizontal: las cuatro columnas van de izquierda a derecha.
           Renglones compactos a propósito: la celda de la imagen fija la
           altura de la fila, así que el miniatura va de 40 a 30 px y el
           relleno vertical de 0.3 a 0.12 rem. El resultado son ~26 px por
           renglón en vez de ~51, es decir, casi el doble de productos por
           página sin bajar de un tamaño de lectura cómodo. */
        table.items { width: 100%; border-collapse: collapse; }
        table.items th {
            padding: 0.18rem 0.25rem;
            background-color: #eaf1f8;
            border: 1px solid #c7dcef;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #4679a6;
            text-align: center;
        }
        table.items td {
            padding: 0.12rem 0.25rem;
            border: 1px solid #e4e6ea;
            font-size: 9.5px;
            line-height: 1.2;
            vertical-align: middle;
        }
        table.items .mini {
            width: 34px;
            height: 34px;
            text-align: center;
            vertical-align: middle;
        }
        table.items .mini img {
            width: 30px;
            height: 30px;
            display: inline-block;
        }
        table.items .sin-imagen {
            display: inline-block;
            width: 30px;
            height: 30px;
            line-height: 30px;
            background-color: #f2f4f7;
            border: 1px solid #e4e6ea;
            border-radius: 3px;
            font-size: 13px;
            color: #b9bcc4;
        }
        table.items .clave {
            font-family: Courier, monospace;
            font-size: 9px;
            font-weight: 700;
            color: #4679a6;
            text-align: center;
        }
        table.items .nombre { font-weight: 700; font-size: 10px; }
        table.items .costo  { text-align: right; font-size: 10.5px; font-weight: 700; }

        .sin-productos {
            padding: 0.5rem;
            border: 1px dashed #e4e6ea;
            font-size: 10px;
            color: #64646a;
            text-align: center;
        }

        .seccion-empresa { margin-bottom: 0.9rem; }

        .leyenda {
            margin-top: 0.3rem;
            padding: 0.45rem 0.6rem;
            background-color: #fff8e6;
            border: 1px solid #f0dfae;
            border-radius: 3px;
            font-size: 10.5px;
            font-weight: 700;
            color: #7a6115;
            text-align: center;
        }

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
            <h1>Catálogo de productos</h1>
            <p class="datos">Clave, descripción y costo por negocio · Precios en pesos mexicanos (MXN)</p>
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
        <!-- Encabezado: logo + nombre de la empresa -->
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
            <p class="sin-productos">Esta empresa no tiene productos registrados.</p>
        <?php else : ?>
            <table class="items">
                <thead>
                    <tr>
                        <th style="width:11%">Imagen</th>
                        <th style="width:17%">Clave</th>
                        <th style="width:47%">Nombre</th>
                        <th style="width:25%">Costo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productos as $producto) :
                        $imagen = $imagenBase64($producto['imagen'] !== '' ? $producto['imagen'] : null);
                        ?>
                        <tr>
                            <td class="mini">
                                <?php if ($imagen !== '') : ?>
                                    <img src="<?= $imagen ?>" alt="">
                                <?php else : ?>
                                    <span class="sin-imagen">·</span>
                                <?php endif; ?>
                            </td>
                            <td class="clave"><?= escaparValores($producto['sku'] !== '' ? $producto['sku'] : '—') ?></td>
                            <td class="nombre"><?= escaparValores($producto['nombre']) ?></td>
                            <td class="costo"><?= formatoMoneda($producto['precio']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Leyenda fiscal POR EMPRESA: va pegada a la tabla a la que se
             refiere, nunca una sola al final del PDF. -->
        <p class="leyenda"><?= escaparValores($leyenda) ?></p>
    </div>

<?php endforeach; ?>

<p class="pie">
    Catálogo generado el <?= escaparValores($fecha) ?> · Acero 360
</p>

</body>
</html>
