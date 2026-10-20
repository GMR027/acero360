<?php
/**
 * templates/selectorPeriodo.php — Selector de año, mes y negocio (GET).
 * Variables: $accion (ruta del formulario), $periodo, $empresas, $conTodoElAnio (bool),
 *            $clientes/$cliente (opcional: filtro por cliente), $extra (campos ocultos opcionales: array nombre => valor)
 */
$conTodoElAnio = $conTodoElAnio ?? false;
$extra = $extra ?? [];
?>
<form class="buscador mb-lg" action="<?= escaparValores($accion) ?>" method="get" aria-label="Elegir periodo">
    <?php foreach ($extra as $nombre => $valor) : ?>
        <input type="hidden" name="<?= escaparValores($nombre) ?>" value="<?= escaparValores((string) $valor) ?>">
    <?php endforeach; ?>
    <label class="solo-lectores" for="sel-anio">Año</label>
    <select class="control control--compacto" id="sel-anio" name="anio">
        <?php foreach (\App\Contabilidad::aniosConMovimientos() as $a) : ?>
            <option value="<?= $a ?>"<?= $periodo['anio'] === $a ? ' selected' : '' ?>><?= $a ?></option>
        <?php endforeach; ?>
    </select>
    <label class="solo-lectores" for="sel-mes">Mes</label>
    <select class="control control--compacto" id="sel-mes" name="mes">
        <?php if ($conTodoElAnio) : ?><option value="0"<?= $periodo['mes'] === 0 ? ' selected' : '' ?>>Todo el año</option><?php endif; ?>
        <?php for ($m = 1; $m <= 12; $m++) : ?>
            <option value="<?= $m ?>"<?= $periodo['mes'] === $m ? ' selected' : '' ?>><?= nombreMes($m) ?></option>
        <?php endfor; ?>
    </select>
    <label class="solo-lectores" for="sel-empresa">Negocio</label>
    <select class="control control--compacto" id="sel-empresa" name="empresa">
        <option value="0">Ambos negocios (consolidado)</option>
        <?php foreach ($empresas as $e) : ?>
            <option value="<?= (int) $e->id ?>"<?= $periodo['empresa'] === (int) $e->id ? ' selected' : '' ?>><?= escaparValores($e->nombre) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($clientes)) : ?>
        <label class="solo-lectores" for="sel-cliente">Cliente</label>
        <select class="control control--compacto" id="sel-cliente" name="cliente">
            <option value="0">Todos los clientes</option>
            <?php foreach ($clientes as $c) : ?>
                <option value="<?= (int) $c['id'] ?>"<?= ($cliente ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= escaparValores($c['etiqueta']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <button class="btn btn--secundario btn--sm" type="submit">Ver periodo</button>
</form>
