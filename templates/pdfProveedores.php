<?php
/**
 * templates/pdfProveedores.php — Documento HTML que alimenta el PDF de
 * "Exportar datos" (reporte anual de entradas de proveedores).
 *
 * Un solo documento con UNA sección por proveedor que tuvo entregas en el
 * año. Cada sección trae:
 *   encabezado → logotipo, nombre, RFC/teléfono/ubicación del proveedor
 *   tabla      → Fecha · Número de pedido · Categoría · Monto · Estado
 *   pie        → total del año, pagado y pendiente por pagar
 *
 * Al final va el resumen consolidado de todos los proveedores: es el número
 * que se consulta para decidir a quién se le paga primero.
 *
 * dompdf sólo entiende un subconjunto de CSS: por eso se usa una tabla para
 * maquetar y las imágenes se insertan en base64 (nunca sale a Internet).
 *
 * Variables esperadas:
 *   $secciones — salida de ProveedorEntradas::paraPdf($anio)
 *   $anio      — año cubierto por el reporte
 *   $fecha     — fecha de exportación en formato legible (dd/mm/aaaa)
 */

/** Etiqueta plural para el número de entregas de cada proveedor. */
$etiquetaEntradas = static function (int $cantidad): string {
    return number_format($cantidad) . ($cantidad === 1 ? ' entrega' : ' entregas');
};

/** Resumen consolidado de todas las secciones del reporte. */
$consolidado = ['total' => 0.0, 'pagado' => 0.0, 'pendiente' => 0.0, 'entradas' => 0];
foreach ($secciones as $seccion) {
    $consolidado['total']     = round($consolidado['total'] + $seccion['total'], 2);
    $consolidado['pagado']    = round($consolidado['pagado'] + $seccion['pagado'], 2);
    $consolidado['pendiente'] = round($consolidado['pendiente'] + $seccion['pendiente'], 2);
    $consolidado['entradas'] += count($seccion['entradas']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Entradas de proveedores <?= (int) $anio ?></title>
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
        table.items .fecha { text-align: center; white-space: nowrap; }
        table.items .clave {
            font-family: Courier, monospace;
            font-size: 9.5px;
            font-weight: 700;
            color: #4679a6;
            text-align: center;
        }
        table.items .monto { text-align: right; font-weight: 700; font-size: 11px; }
        table.items .estado { text-align: center; }

        table.items tr.pagado td { background-color: #f3fbf5; }
        table.items tr.pendiente td { background-color: #fffaf0; }

        table.items tr.total td {
            background-color: #f7f7f8;
            border-top: 2px solid #c7dcef;
            font-size: 10.5px;
            font-weight: 700;
            color: #4679a6;
        }
        table.items tr.total .monto { font-size: 12px; color: #4679a6; }

        .etiqueta-estado {
            display: inline-block;
            padding: 0.1rem 0.4rem;
            border-radius: 3px;
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .etiqueta-estado.si  { background-color: #dcfce7; color: #166534; }
        .etiqueta-estado.no  { background-color: #fef3c7; color: #92400e; }

        .ficha-proveedor {
            margin: 0 0 0.35rem;
            font-size: 10px;
            color: #6b6b70;
            line-height: 1.5;
        }

        .sin-entradas {
            padding: 0.5rem;
            border: 1px dashed #e4e6ea;
            font-size: 10px;
            color: #64646a;
            text-align: center;
        }

        .seccion-proveedor { margin-bottom: 1.4rem; }

        .resumen {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.6rem;
        }
        .resumen th, .resumen td {
            padding: 0.45rem;
            border: 1px solid #c7dcef;
            font-size: 10px;
            text-align: center;
        }
        .resumen th {
            background-color: #eaf1f8;
            color: #4679a6;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .resumen .importe { font-size: 13px; font-weight: 700; color: #4679a6; }
        .resumen .debe    { color: #92400e; }

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
            <h1>Entradas de proveedores · Año <?= (int) $anio ?></h1>
            <p class="datos">Reporte anual de pedidos, montos y estado de pago por proveedor · Pesos mexicanos (MXN)</p>
        </td>
        <td class="derecha">
            Fecha de exportación<br>
            <strong><?= escaparValores($fecha) ?></strong>
        </td>
    </tr>
</table>

<?php if (empty($secciones)) : ?>
    <p class="sin-entradas">
        No hay entradas registradas en el año <?= (int) $anio ?>.
    </p>
<?php else : ?>

    <?php foreach ($secciones as $indice => $seccion) : ?>

        <?php if ($indice > 0 && count($seccion['entradas']) > 6) : ?>
            <!-- Sólo se salta de página si la tabla siguiente no cabe en lo que resta. -->
            <div style="page-break-after: always;"></div>
        <?php endif; ?>

        <div class="seccion-proveedor">
            <table class="barrasup">
                <tr>
                    <td>
                        <div class="marca"><?= escaparValores($seccion['nombre']) ?></div>
                        <p class="ficha-proveedor">
                            <?php if ($seccion['rfc'] !== '') : ?>
                                RFC <?= escaparValores($seccion['rfc']) ?> ·
                            <?php endif; ?>
                            <?php if ($seccion['telefono'] !== '') : ?>
                                Tel. <?= escaparValores($seccion['telefono']) ?> ·
                            <?php endif; ?>
                            <?= escaparValores($seccion['ubicacion'] !== '' ? $seccion['ubicacion'] : '—') ?><br>
                            Tipo: <?= escaparValores($seccion['tipo']) ?>
                        </p>
                    </td>
                    <td class="derecha">
                        <span class="exportacion">Año <?= (int) $anio ?></span>
                    </td>
                </tr>
            </table>

            <?php if (empty($seccion['entradas'])) : ?>
                <p class="sin-entradas">Sin entregas en el año.</p>
            <?php else : ?>
                <table class="items">
                    <thead>
                        <tr>
                            <th style="width:14%">Fecha</th>
                            <th style="width:22%">Número de pedido</th>
                            <th style="width:24%">Categoría</th>
                            <th style="width:20%">Monto</th>
                            <th style="width:20%">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($seccion['entradas'] as $entrada) : ?>
                            <tr class="<?= $entrada['pagado'] ? 'pagado' : 'pendiente' ?>">
                                <td class="fecha"><?= date('d/m/Y', strtotime($entrada['fecha'])) ?></td>
                                <td class="clave"><?= escaparValores($entrada['numero_pedido'] !== '' ? $entrada['numero_pedido'] : '—') ?></td>
                                <td><?= escaparValores($entrada['categoria'] !== '' ? $entrada['categoria'] : '—') ?></td>
                                <td class="monto"><?= formatoMoneda($entrada['monto']) ?></td>
                                <td class="estado">
                                    <span class="etiqueta-estado <?= $entrada['pagado'] ? 'si' : 'no' ?>">
                                        <?= $entrada['pagado'] ? 'Pagado' : 'Pendiente' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total">
                            <td colspan="3">
                                <?= $etiquetaEntradas(count($seccion['entradas'])) ?> de
                                <?= escaparValores($seccion['nombre']) ?>
                            </td>
                            <td class="monto"><?= formatoMoneda($seccion['total']) ?></td>
                            <td class="estado">Debe <?= formatoMoneda($seccion['pendiente']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        </div>

    <?php endforeach; ?>

    <!-- Resumen consolidado de todos los proveedores del año -->
    <table class="resumen">
        <thead>
            <tr>
                <th style="width:28%">Entregas</th>
                <th style="width:24%">Total del año</th>
                <th style="width:24%">Pagado</th>
                <th style="width:24%">Pendiente por pagar</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= number_format($consolidado['entradas']) ?></td>
                <td class="importe"><?= formatoMoneda($consolidado['total']) ?></td>
                <td class="importe"><?= formatoMoneda($consolidado['pagado']) ?></td>
                <td class="importe debe"><?= formatoMoneda($consolidado['pendiente']) ?></td>
            </tr>
        </tbody>
    </table>

<?php endif; ?>

<p class="pie">
    Reporte del año <?= (int) $anio ?> generado el <?= escaparValores($fecha) ?> · Cotizaciones Multi-Negocio
</p>

</body>
</html>
