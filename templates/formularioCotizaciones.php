<?php
/**
 * templates/formularioCotizaciones.php — Motor de cotización (alta y edición).
 *
 * Se reutiliza desde views/admin/cotizaciones/crear.php y editar.php para que
 * ambos escenarios tengan exactamente los mismos campos y el mismo diseño.
 *
 * Reproduce design/nueva_cotizaci_n:
 *   · encabezado (negocio, cliente, fecha y vigencia)
 *   · Partidas de la Cotización (catálogo con imagen + tabla de renglones)
 *   · Términos Comerciales y Logística (envío, compra y comentarios)
 *   · Resumen de Liquidación (subtotal, descuento, IVA 16% y total)
 *
 * Variables esperadas:
 *   $modo            — 'crear' | 'editar'
 *   $documento       — 'cotizacion' (por defecto) | 'venta' (venta directa)
 *   $cotizacion      — instancia de App\Cotizaciones o App\Ventas (nueva o existente)
 *   $errores         — array de mensajes de validación del servidor
 *   $empresas        — negocios activos (App\Empresas)
 *   $clientes        — clientes (App\Clientes)
 *   $catalogo        — productos activos con su precio de lista
 *   $lineasIniciales — renglones ya cargados (vacío en el alta)
 *   $descuentoGlobal — % de descuento por defecto, de la ficha del cliente
 *
 * El navegador sólo calcula una VISTA PREVIA en vivo: el servidor vuelve a
 * recalcular todos los importes al guardar.
 */

$modo             = $modo ?? 'crear';
$documento        = $documento ?? 'cotizacion';
$esVenta          = $documento === 'venta';
$cotizacion       = $cotizacion ?? ($esVenta ? new \App\Ventas() : new \App\Cotizaciones());
$errores          = $errores ?? [];
$empresas         = $empresas ?? [];
$clientes         = $clientes ?? [];
$catalogo         = $catalogo ?? [];
$lineasIniciales  = $lineasIniciales ?? [];
$descuentoGlobal  = (float) ($descuentoGlobal ?? 0);

$esEdicion = $modo === 'editar';
$nombreDoc = $esVenta ? 'venta' : 'cotización';
$estilosEstado = $esVenta ? \App\Ventas::ESTILOS_ESTADO : \App\Cotizaciones::ESTILOS_ESTADO;

/** Devuelve el primer mensaje de error que menciona un campo concreto. */
$errorDeCampo = static function (string $clave) use ($errores): string {
    foreach ($errores as $error) {
        if (stripos($error, $clave) !== false) {
            return $error;
        }
    }
    return '';
};

/** Estado inicial del cotizador (lo consume src/js/modulos/cotizador.js). */
$estadoInicial = [
    'lineas'    => $lineasIniciales,
    'descuento' => $descuentoGlobal,
    'iva'       => (int) $cotizacion->aplica_iva === 1,
];
$estadoJson = json_encode($estadoInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$estadoJson = $estadoJson === false ? '{"lineas":[],"descuento":0,"iva":true}' : $estadoJson;
?>

<!-- Errores generales del formulario -->
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

<noscript>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span>El cotizador necesita JavaScript activo para cargar los renglones y calcular los totales.</span>
    </div>
</noscript>

<!-- --------------------------------------------------------------------
     1. ENCABEZADO DE LA COTIZACIÓN
     ------------------------------------------------------------------ -->
<fieldset class="cotizador-seccion">
    <legend class="cotizador-seccion__titulo">
        <span class="material-symbols-outlined" aria-hidden="true">badge</span>
        Datos de la <?= $nombreDoc ?>
    </legend>

    <div class="formulario__rejilla">
        <div class="campo<?= $errorDeCampo('negocio') !== '' ? ' campo--completo' : '' ?>">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="empresa_id">Negocio que emite</label>
            <select class="control" id="empresa_id" name="empresa_id" required>
                <option value="">Selecciona un negocio…</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= (int) $empresa->id ?>"
                        <?= (int) $cotizacion->empresa_id === (int) $empresa->id ? 'selected' : '' ?>
                        data-envio="<?= escaparValores((string) $empresa->condiciones_envio) ?>"
                        data-compra="<?= escaparValores((string) $empresa->condiciones_compra) ?>">
                        <?= escaparValores($empresa->nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($errorDeCampo('negocio') !== '') : ?>
                <p class="campo__error"><?= escaparValores($errorDeCampo('negocio')) ?></p>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="cliente_id">Cliente</label>
            <!-- data-buscador: src/js/modulos/selectorBusqueda.js convierte este
                 <select> en un input de búsqueda con lista filtrada (por nombre,
                 empresa, alias o RFC). Sin JavaScript queda el selector normal:
                 el <select> real se mantiene en el DOM y viaja en el envío. -->
            <select class="control" id="cliente_id" name="cliente_id" required
                    data-buscador
                    data-buscador-placeholder="Busca por nombre, empresa, alias o RFC…"
                    data-descuento-cliente>
                <option value="">Selecciona un cliente…</option>
                <?php foreach ($clientes as $cliente) :
                    $etiqueta = trim((string) $cliente->nombre);
                    if (trim((string) $cliente->nombre_empresa) !== '') {
                        $etiqueta .= ' — ' . $cliente->nombre_empresa;
                    }
                    if ((int) $cliente->vip === 1) {
                        $etiqueta .= ' (VIP)';
                    }
                    ?>
                    <option value="<?= (int) $cliente->id ?>"
                        data-rfc="<?= escaparValores(trim((string) ($cliente->rfc ?? ''))) ?>"
                        data-alias="<?= escaparValores(trim((string) ($cliente->alias ?? ''))) ?>"
                        data-descuento="<?= (float) $cliente->descuento_pct ?>"
                        <?= (int) $cotizacion->cliente_id === (int) $cliente->id ? 'selected' : '' ?>>
                        <?= escaparValores($etiqueta) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="campo__ayuda">Escribe y elige de la lista; el descuento de la ficha se precarga en el resumen.</p>
        </div>

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha">Fecha</label>
            <input class="control" type="date" id="fecha" name="fecha" required
                   value="<?= escaparValores($cotizacion->fecha ?: date('Y-m-d')) ?>">
        </div>

        <?php if (!$esVenta) : ?>
        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="vigencia_dias">Vigencia (días)</label>
            <select class="control" id="vigencia_dias" name="vigencia_dias" required>
                <?php foreach ([15, 30, 45, 60, 90] as $dias) : ?>
                    <option value="<?= $dias ?>" <?= (int) $cotizacion->vigencia_dias === $dias ? 'selected' : '' ?>>
                        <?= $dias ?> días
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($esEdicion) : ?>
            <div class="campo">
                <label class="campo__etiqueta" for="estado">Estado</label>
                <select class="control" id="estado" name="estado">
                    <?php foreach ($estilosEstado as $clave => $datos) : ?>
                        <option value="<?= $clave ?>" <?= $cotizacion->estado === $clave ? 'selected' : '' ?>>
                            <?= $datos['etiqueta'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo">
                <label class="campo__etiqueta" for="folio-cotizacion">Folio</label>
                <input class="control" type="text" id="folio-cotizacion"
                       value="<?= escaparValores($cotizacion->folio) ?>" readonly>
            </div>
        <?php endif; ?>
    </div>
</fieldset>

<!-- --------------------------------------------------------------------
     2. PARTIDAS DE LA COTIZACIÓN
     ------------------------------------------------------------------ -->
<fieldset class="cotizador-seccion">
    <legend class="cotizador-seccion__titulo">
        <span class="material-symbols-outlined" aria-hidden="true">list_alt</span>
        Partidas de la <?= $nombreDoc ?>
    </legend>

    <!-- Buscador del catálogo: filtra las tarjetas en el navegador.
         NO es un <form>: estaría anidado dentro del cotizador y el navegador
         lo descartaría. Los campos tampoco llevan name, así que no se envían. -->
    <div class="tabla-toolbar tabla-toolbar--sin-borde" data-cotizador-buscador>
        <div class="buscador" role="search">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="buscar-producto">Buscar producto</label>
                <input class="control" type="search" id="buscar-producto"
                       maxlength="80" autocomplete="off"
                       placeholder="Buscar por nombre, SKU o categoría…"
                       data-cotizador-busqueda>
            </div>

            <label class="solo-lectores" for="catalogo-empresa">Negocio</label>
            <select class="control control--compacto" id="catalogo-empresa" data-cotizador-catalogo-empresa>
                <option value="0">Todos los negocios</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= (int) $empresa->id ?>"><?= escaparValores($empresa->nombre) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="btn btn--primario btn--sm" data-cotizador-agregar title="Agrega el primer producto de la lista (o haz clic en la tarjeta del producto que quieras)">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add_circle</span>
                <span>Agregar el primer resultado</span>
            </button>
        </div>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <span data-cotizador-visibles><?= count($catalogo) ?></span> productos
        </span>
    </div>

    <!-- Catálogo visual: cada tarjeta agrega el producto a la tabla. -->
    <div class="cotizador-catalogo" id="catalogo" role="list">
        <?php if (empty($catalogo)) : ?>
            <div class="vacio">
                <span class="material-symbols-outlined vacio__icono" aria-hidden="true">inventory_2</span>
                <p class="vacio__titulo">Sin productos activos</p>
                <p>Carga productos en el catálogo para poder <?= $esVenta ? 'venderlos' : 'cotizarlos' ?>.</p>
                <a class="btn btn--primario" href="/admin/productos/crear">Nuevo producto</a>
            </div>
        <?php else : foreach ($catalogo as $producto) :
            $archivo  = (string) ($producto['imagen'] ?? '');
            $urlImagen = $archivo !== '' ? \App\Imagenes::urlMiniatura($archivo) : '';
            $sinImagen = $urlImagen === '';
            ?>
            <button type="button"
                    class="cotizador-carta"
                    role="listitem"
                    data-cotizador-producto
                    data-id="<?= (int) $producto['id'] ?>"
                    data-empresa="<?= (int) $producto['empresa_id'] ?>"
                    data-empresas="<?= escaparValores(implode(',', $producto['empresas'] ?? [(int) $producto['empresa_id']])) ?>"
                    data-nombre="<?= escaparValores($producto['nombre']) ?>"
                    data-sku="<?= escaparValores((string) $producto['sku']) ?>"
                    data-categoria="<?= escaparValores((string) ($producto['categoria'] ?? '')) ?>"
                    data-unidad="<?= escaparValores((string) $producto['unidad']) ?>"
                    data-precio="<?= (float) $producto['precio'] ?>"
                    data-imagen="<?= escaparValores($archivo) ?>"
                    data-buscar="<?= escaparValores(mb_strtolower(
                        $producto['nombre'] . ' ' . $producto['sku'] . ' ' . ($producto['categoria'] ?? '')
                    )) ?>">
                <span class="cotizador-carta__img<?= $sinImagen ? ' cotizador-carta__img--vacia' : '' ?>"
                      aria-hidden="true">
                    <?php if ($sinImagen) : ?>
                        <span class="material-symbols-outlined">inventory_2</span>
                    <?php else : ?>
                        <img src="<?= escaparValores($urlImagen) ?>" alt="" width="300" height="300" loading="lazy">
                    <?php endif; ?>
                </span>

                <span class="cotizador-carta__nombre"><?= escaparValores($producto['nombre']) ?></span>

                <span class="cotizador-carta__meta">
                    <?php if (trim((string) $producto['sku']) !== '') : ?>
                        <span class="cotizador-carta__sku"><?= escaparValores($producto['sku']) ?></span>
                    <?php endif; ?>
                    <span class="cotizador-carta__precio"><?= formatoMoneda($producto['precio']) ?></span>
                </span>

                <span class="cotizador-carta__unidad">por <?= escaparValores($producto['unidad']) ?></span>
            </button>
        <?php endforeach; endif; ?>
    </div>

    <p class="campo__ayuda" data-cotizador-sin-resultados hidden>
        Ningún producto coincide con la búsqueda.
    </p>

    <!-- Renglones cargados: los construye src/js/modulos/cotizador.js -->
    <div class="tabla-contenedor">
        <table class="tabla cotizador-tabla" id="tabla-renglones">
            <caption class="solo-lectores">Renglones cargados en la <?= $nombreDoc ?></caption>
            <thead>
                <tr>
                    <th scope="col" class="cotizador-tabla__num">#</th>
                    <th scope="col">Producto</th>
                    <th scope="col" class="cotizador-tabla__derecha">Precio lista</th>
                    <th scope="col" class="cotizador-tabla__ancha">Precio cliente</th>
                    <th scope="col" class="cotizador-tabla__cantidad">Cantidad</th>
                    <th scope="col" class="cotizador-tabla__descuento">Desc. (%)</th>
                    <th scope="col" class="cotizador-tabla__derecha">Total</th>
                    <th scope="col" class="cotizador-tabla__accion"><span class="solo-lectores">Acción</span></th>
                </tr>
            </thead>
            <tbody id="cuerpo-renglones">
                <!-- Las filas se generan en el navegador. -->
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" class="cotizador-tabla__pie">
                        <span data-cotizador-vacio>La <?= $nombreDoc ?> todavía no tiene renglones.</span>
                    </td>
                    <td class="cotizador-tabla__derecha">
                        <strong data-cotizador-subtotal><?= formatoMoneda(0) ?></strong>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</fieldset>

<!-- --------------------------------------------------------------------
     3. TÉRMINOS COMERCIALES Y LOGÍSTICA
     ------------------------------------------------------------------ -->
<fieldset class="cotizador-seccion">
    <legend class="cotizador-seccion__titulo">
        <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
        Términos comerciales y logística
    </legend>

    <div class="formulario__rejilla">
        <div class="campo campo--completo">
            <label class="campo__etiqueta" for="condiciones_envio">Condiciones de envío / entrega</label>
            <textarea class="control" id="condiciones_envio" name="condiciones_envio" rows="3"
                      placeholder="Días hábiles, flete, destino, citas de descarga…"><?= escaparValores($cotizacion->condiciones_envio) ?></textarea>
            <p class="campo__ayuda">Se llena con las condiciones del negocio seleccionado.</p>
        </div>

        <div class="campo campo--completo">
            <label class="campo__etiqueta" for="condiciones_compra">Condiciones de compra / pago</label>
            <textarea class="control" id="condiciones_compra" name="condiciones_compra" rows="3"
                      placeholder="Anticipo, crédito, forma de pago, facturación…"><?= escaparValores($cotizacion->condiciones_compra) ?></textarea>
        </div>

        <div class="campo campo--completo">
            <label class="campo__etiqueta" for="comentarios">Comentarios adicionales<?= $esVenta ? '' : ' para el cliente' ?></label>
            <textarea class="control" id="comentarios" name="comentarios" rows="3"
                      placeholder="Notas especiales que aparecerán en el PDF…"><?= escaparValores($cotizacion->comentarios) ?></textarea>
        </div>
    </div>
</fieldset>

<!-- --------------------------------------------------------------------
     4. RESUMEN DE LIQUIDACIÓN
     ------------------------------------------------------------------ -->
<section class="cotizador-resumen" aria-label="Resumen de liquidación">
    <header class="cotizador-resumen__cabecera">
        <h2 class="cotizador-resumen__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">calculate</span>
            Resumen de liquidación
        </h2>
        <span class="badge badge--neutro">Moneda: MXN</span>
    </header>

    <dl class="cotizador-resumen__cuerpo">
        <div class="cotizador-resumen__fila">
            <dt>Subtotal bruto de partidas</dt>
            <dd data-resumen="subtotal"><?= formatoMoneda(0) ?></dd>
        </div>

        <div class="cotizador-resumen__fila">
            <dt>
                <label for="descuento_pct">Descuento global</label>
                <span class="cotizador-resumen__ayuda">De la ficha del cliente, editable</span>
            </dt>
            <dd class="cotizador-resumen__control">
                <input class="control control--moneda" type="number" id="descuento_pct"
                       name="descuento_pct" min="0" max="100" step="0.5" value="<?= (float) $descuentoGlobal ?>">
                <span class="cotizador-resumen__porciento">%</span>
                <span class="cotizador-resumen__negativo" data-resumen="descuento">-<?= formatoMoneda(0) ?></span>
            </dd>
        </div>

        <div class="cotizador-resumen__fila">
            <dt>Base gravable (subtotal con descuento)</dt>
            <dd data-resumen="base"><?= formatoMoneda(0) ?></dd>
        </div>

        <div class="cotizador-resumen__fila">
            <dt>
                <label class="comprobacion" for="aplica_iva">
                    <input type="checkbox" id="aplica_iva" name="aplica_iva" value="1"
                           data-resumen-check <?= (int) $cotizacion->aplica_iva === 1 ? 'checked' : '' ?>>
                    <span>Aplicar I.V.A. federal trasladado (16%)</span>
                </label>
            </dt>
            <dd data-resumen="iva"><?= formatoMoneda(0) ?></dd>
        </div>

        <div class="cotizador-resumen__fila cotizador-resumen__fila--total">
            <dt>Total neto autorizado</dt>
            <dd><strong data-resumen="total"><?= formatoMoneda($cotizacion->total) ?></strong></dd>
        </div>
    </dl>

    <p class="cotizador-resumen__letra" data-resumen="letra"></p>
</section>

<!-- Estado inicial que lee el módulo del cotizador. -->
<div id="cotizador-estado" hidden
     data-estado="<?= escaparValores($estadoJson) ?>"
     data-ruta-precios="/admin/cotizaciones/precios-cliente"
     data-msg-agregado="Producto agregado a la <?= $nombreDoc ?>"
     data-msg-duplicado="Ese producto ya está en la <?= $nombreDoc ?>"
     data-msg-sin-resultados="Ningún producto coincide con la búsqueda"
     data-msg-precios="Precios actualizados para el nuevo cliente"
     data-msg-vacio="La <?= $nombreDoc ?> necesita al menos un renglón"
     data-msg-precio="El precio del renglón no es válido"
     data-msg-cantidad="La cantidad debe ser al menos 1"></div>
