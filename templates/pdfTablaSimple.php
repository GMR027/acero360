<?php
/**
 * templates/pdfTablaSimple.php — PDF genérico de una tabla (reportes de inventario).
 * Variables: $titulo, $subtitulo, $encabezados (string[]), $filas (array de arrays), $pie (opcional)
 */
$numerico = static fn ($v) => is_int($v) || is_float($v);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= escaparValores($titulo) ?></title>
    <style>
        @page { margin: 1.2cm 1cm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #4d4c53; }
        h1 { margin: 0 0 .2rem; font-size: 15px; color: #4679a6; } p { margin: 0 0 .5rem; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: .25rem; background: #eaf1f8; border: 1px solid #c7dcef; color: #4679a6; font-size: 8px; text-transform: uppercase; }
        td { padding: .22rem; border: 1px solid #e4e6ea; } .num { text-align: right; }
        .aviso { margin-top: .6rem; padding: .4rem; background: #fff7e0; border: 1px solid #f0d58a; }
    </style>
</head>
<body>
    <h1><?= escaparValores($titulo) ?></h1>
    <p><?= escaparValores($subtitulo) ?> · Emitido <?= date('d/m/Y H:i') ?> · <?= count($filas) ?> renglón(es)</p>
    <table>
        <thead><tr><?php foreach ($encabezados as $e) : ?><th><?= escaparValores($e) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
            <?php foreach ($filas as $fila) : ?>
                <tr><?php foreach ($fila as $v) : ?><td class="<?= $numerico($v) ? 'num' : '' ?>"><?= $numerico($v) ? number_format((float) $v, is_int($v) ? 0 : 2) : escaparValores((string) $v) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if (!$filas) : ?><tr><td colspan="<?= count($encabezados) ?>" style="text-align:center">Sin registros con estos filtros.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if (!empty($pie)) : ?><p class="aviso"><?= escaparValores($pie) ?></p><?php endif; ?>
</body>
</html>
