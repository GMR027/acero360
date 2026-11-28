<?php
/**
 * templates/formularioProyecto.php — Alta y edición de un proyecto de entrega.
 *
 * Variables:
 *   $modo      — 'crear' | 'editar'
 *   $proyecto  — App\ProyectosEntrega
 *   $partidas  — partidas actuales (o las enviadas con errores)
 *   $clientes  — App\Clientes::mostrarTodos()
 *   $empresas  — negocios activos
 *   $catalogo  — App\Productos::paraCotizador()
 *   $errores   — string[]
 *   $accion    — URL del POST
 *   $volver    — URL para cancelar
 */

$modo     = $modo ?? 'crear';
$proyecto = $proyecto ?? new \App\ProyectosEntrega();
$partidas = $partidas ?? [];
$clientes = $clientes ?? [];
$empresas = $empresas ?? [];
$catalogo = $catalogo ?? [];
$errores  = $errores ?? [];
$accion   = $accion ?? '/admin/proyectos/crear';
$volver   = $volver ?? '/admin/proyectos';

if (!$partidas) {
    $partidas = [['producto_id' => 0, 'cantidad_comprometida' => 1, 'precio_unitario' => '', 'notas' => '']];
}

/** Opciones del catálogo, con los negocios en los que se puede vender cada producto. */
$opcionesProducto = static function (int $seleccionado) use ($catalogo): string {
    $html = '<option value="">Selecciona un producto…</option>';
    foreach ($catalogo as $producto) {
        $empresasProducto = $producto['empresas'] ?? [];
        if (!$empresasProducto) {
            $empresasProducto = [(int) $producto['empresa_id']];
        }
        $etiqueta = trim((string) ($producto['sku'] ?? '')) !== ''
            ? $producto['sku'] . ' · ' . $producto['nombre']
            : $producto['nombre'];
        $html .= '<option value="' . (int) $producto['id'] . '"'
            . ' data-empresas="' . escaparValores(implode(',', array_map('intval', $empresasProducto))) . '"'
            . ((int) $producto['id'] === $seleccionado ? ' selected' : '') . '>'
            . escaparValores($etiqueta . ' (' . ($producto['unidad'] ?? 'pza') . ')')
            . '</option>';
    }
    return $html;
};

/** Una fila de partida. */
$filaPartida = static function (array $partida) use ($opcionesProducto): string {
    $productoId = (int) ($partida['producto_id'] ?? 0);
    $cantidad   = max(1, (int) ($partida['cantidad_comprometida'] ?? 1));
    $precio     = $partida['precio_unitario'] !== null && $partida['precio_unitario'] !== ''
        ? number_format((float) $partida['precio_unitario'], 2, '.', '')
        : '';
    $notas      = (string) ($partida['notas'] ?? '');

    return '<tr data-partida>'
        . '<td><select class="control" name="producto_id[]" data-partida-producto aria-label="Producto de la partida">'
        . $opcionesProducto($productoId)
        . '</select></td>'
        . '<td><input class="control" type="number" name="cantidad_comprometida[]" min="1" step="1" value="' . $cantidad . '" required data-partida-cantidad aria-label="Cantidad comprometida"></td>'
        . '<td><input class="control" type="number" name="precio_unitario[]" min="0" step="0.01" value="' . escaparValores($precio) . '" placeholder="Opcional" aria-label="Precio acordado"></td>'
        . '<td><input class="control" type="text" name="partida_notas[]" maxlength="255" value="' . escaparValores($notas) . '" placeholder="Opcional" aria-label="Notas de la partida"></td>'
        . '<td class="tabla--centro"><button class="btn btn--peligro btn--sm btn--icono" type="button" data-quitar-partida title="Quitar partida" aria-label="Quitar partida">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span></button></td>'
        . '</tr>';
};
?>
<?php if (!empty($errores)) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span>
            <?php foreach ($errores as $indice => $error) : ?>
                <?= $indice > 0 ? '<br>' : '' ?><?= escaparValores($error) ?>
            <?php endforeach; ?>
        </span>
    </div>
<?php endif; ?>

<form action="<?= escaparValores($accion) ?>" method="post" data-validar id="form-proyecto">
    <?php if ($proyecto->id) : ?>
        <input type="hidden" name="id" value="<?= (int) $proyecto->id ?>">
    <?php endif; ?>

    <fieldset class="cotizador-seccion">
        <legend class="cotizador-seccion__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">assignment</span>
            Datos del proyecto
        </legend>

        <div class="formulario__rejilla">
            <div class="campo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="cliente_id">Cliente</label>
                <select class="control" id="cliente_id" name="cliente_id" required
                        data-buscador
                        data-buscador-placeholder="Busca por nombre, empresa, alias o RFC…">
                    <option value="">Selecciona un cliente…</option>
                    <?php foreach ($clientes as $cliente) :
                        $etiqueta = trim((string) $cliente->nombre);
                        if (trim((string) $cliente->nombre_empresa) !== '') {
                            $etiqueta .= ' — ' . $cliente->nombre_empresa;
                        }
                        ?>
                        <option value="<?= (int) $cliente->id ?>" <?= (int) $proyecto->cliente_id === (int) $cliente->id ? 'selected' : '' ?>>
                            <?= escaparValores($etiqueta) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="empresa_id">Negocio</label>
                <select class="control" id="empresa_id" name="empresa_id" required>
                    <option value="">Selecciona un negocio…</option>
                    <?php foreach ($empresas as $empresa) : ?>
                        <option value="<?= (int) $empresa->id ?>" <?= (int) $proyecto->empresa_id === (int) $empresa->id ? 'selected' : '' ?>>
                            <?= escaparValores($empresa->nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo campo--completo">
                <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre del proyecto</label>
                <input class="control" type="text" id="nombre" name="nombre" maxlength="160" required
                       value="<?= escaparValores($proyecto->nombre) ?>"
                       placeholder="Ej. Vialidad X · Canastillas para el tramo 3">
            </div>

            <div class="campo">
                <label class="campo__etiqueta" for="fecha_inicio">Fecha de inicio</label>
                <input class="control" type="date" id="fecha_inicio" name="fecha_inicio"
                       value="<?= escaparValores((string) $proyecto->fecha_inicio) ?>">
            </div>

            <div class="campo">
                <label class="campo__etiqueta" for="fecha_compromiso">Fecha compromiso</label>
                <input class="control" type="date" id="fecha_compromiso" name="fecha_compromiso"
                       value="<?= escaparValores((string) $proyecto->fecha_compromiso) ?>">
            </div>

            <div class="campo">
                <label class="campo__etiqueta" for="estado">Estado</label>
                <select class="control" id="estado" name="estado">
                    <?php foreach (\App\ProyectosEntrega::ESTADOS as $clave => $datos) : ?>
                        <option value="<?= escaparValores($clave) ?>" <?= $proyecto->estado === $clave ? 'selected' : '' ?>>
                            <?= escaparValores($datos['etiqueta']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo campo--completo">
                <label class="campo__etiqueta" for="descripcion">Descripción</label>
                <textarea class="control" id="descripcion" name="descripcion" rows="3"
                          placeholder="Alcance, ubicación de la obra, condiciones especiales…"><?= escaparValores($proyecto->descripcion) ?></textarea>
            </div>

            <div class="campo campo--completo">
                <label class="campo__etiqueta" for="notas">Notas internas</label>
                <textarea class="control" id="notas" name="notas" rows="3"
                          placeholder="Notas que no se imprimen en el PDF del proyecto…"><?= escaparValores($proyecto->notas) ?></textarea>
            </div>
        </div>
    </fieldset>

    <fieldset class="cotizador-seccion">
        <legend class="cotizador-seccion__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">list_alt</span>
            Partidas del proyecto
        </legend>

        <p class="campo__ayuda">
            Captura los productos y la cantidad comprometida. Puedes agregar varios productos
            (por ejemplo, canastillas C-1 y C-3); una misma venta puede incluir ambos.
        </p>

        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Productos comprometidos en el proyecto</caption>
                <thead>
                    <tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="tabla--numerica">Cantidad comprometida</th>
                        <th scope="col" class="tabla--numerica">Precio acordado</th>
                        <th scope="col">Notas</th>
                        <th scope="col" class="tabla--centro"><span class="solo-lectores">Quitar</span></th>
                    </tr>
                </thead>
                <tbody data-partidas>
                    <?php foreach ($partidas as $partida) : ?>
                        <?= $filaPartida($partida) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="formulario__acciones" style="justify-content:flex-start">
            <button class="btn btn--secundario btn--sm" type="button" data-agregar-partida>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_circle</span>
                <span>Agregar partida</span>
            </button>
        </div>
    </fieldset>

    <div class="formulario__acciones">
        <a class="btn btn--secundario" href="<?= escaparValores($volver) ?>">Cancelar</a>
        <button class="btn btn--primario" type="submit">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">save</span>
            <span><?= $modo === 'editar' ? 'Guardar cambios' : 'Crear proyecto' ?></span>
        </button>
    </div>
</form>

<template id="proyecto-partida-plantilla">
    <?= $filaPartida(['producto_id' => 0, 'cantidad_comprometida' => 1, 'precio_unitario' => '', 'notas' => '']) ?>
</template>
