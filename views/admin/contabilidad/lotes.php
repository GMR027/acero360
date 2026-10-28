<?php
/**
 * views/admin/contabilidad/lotes.php — Carga por lotes (FASE 9, paso 4).
 *
 * Sube de golpe las facturas de meses anteriores (gastos y ventas por separado)
 * y el sistema detecta qué es cada documento. NADA se registra hasta decidir
 * botón por botón:
 *   · factura de proveedor → solo gasto · gasto + compra (métricas de proveedor)
 *     · relacionar a una compra existente · alta de proveedor;
 *   · complemento de pago (tipo P) → su propio flujo de complementos;
 *   · duplicado por folio fiscal → se señala con su origen, no se repite;
 *   · PDF sin XML → se revisa a mano (nada se adivina).
 * Regla de pago visible: PUE + «Contado» SUGIERE pagado; se puede desmarcar por
 * renglón. Las ventas se precargan en «Registro Venta Inteligente» y el cliente
 * nuevo se da de alta con un botón.
 */

soloAdmin();

$carril = ($_GET['carril'] ?? 'gastos') === 'ventas' ? 'ventas' : 'gastos';
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = '/admin/contabilidad/lotes?carril=' . $carril;

/**
 * Formulario de descarte de una lectura del lote (con su token de un solo uso).
 * Se usa en TODAS las clases —factura, duplicado, complemento, ajeno y manual—
 * para que ninguna fila se quede sin forma de salir de la bandeja.
 */
$formDescartar = static function (int $lecturaId, string $carril): string {
    ob_start(); ?>
    <form action="/admin/contabilidad/lotes-accion" method="post" data-validar
          data-confirmacion-sobre="Lectura"
          data-confirmacion="¿Descartar este documento?"
          data-confirmacion-mensaje="Dejará de aparecer en la bandeja. No se registra nada y queda la bitácora."
          data-confirmacion-boton="Sí, descartar">
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <input type="hidden" name="accion" value="descartar">
        <input type="hidden" name="carril" value="<?= $carril ?>">
        <input type="hidden" name="lectura" value="<?= $lecturaId ?>">
        <button class="btn btn--peligro btn--sm" type="submit">Descartar</button>
    </form>
    <?php return (string) ob_get_clean();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'subir') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($destino, 'error', 'Este formulario ya se envió; sube los archivos de nuevo.');
    }
    $subida = \App\CargaLotes::subir($_FILES['archivos'] ?? null, $carril, $usuario);
    $mensaje = $subida['ok'] . ' de ' . $subida['total'] . ' documento(s) leídos y listos para revisar.';
    if ($subida['errores']) {
        $mensaje .= ' No se pudieron leer: ' . implode(' · ', array_keys($subida['errores'])) . '.';
    }
    redirigir($destino, $subida['ok'] > 0 ? 'exito' : 'error', $mensaje);
}

$filas = \App\CargaLotes::pendientes($carril);
$porClase = [];
foreach ($filas as $f) {
    $porClase[$f['clase']] = ($porClase[$f['clase']] ?? 0) + 1;
}
$registrables = array_filter($filas, static fn ($f) => $f['clase'] === 'factura');
// Lo que el atajo de limpieza sí puede descartar sin tocar nada útil.
$descartables = array_filter($filas, static fn ($f) => in_array($f['clase'], ['duplicado', 'ajeno'], true)
    || ($f['clase'] === 'complemento' && !empty($f['duplicado'])));

template('header', ['titulo' => 'Carga por lotes', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'lotes']);
?>

<p class="campo__ayuda mb-md">
    Sube varios archivos de golpe (XML con su PDF si lo tienes). Los archivos con el mismo nombre van juntos como un
    solo documento. El sistema lee cada XML y te dice qué es; nada se registra hasta que lo confirmas.
    <?= $carril === 'gastos'
        ? 'Un gasto con <strong>PUE y «Contado»</strong> se sugiere <strong>pagado</strong> (editable renglón por renglón); PPD queda pendiente hasta su complemento.'
        : 'Las facturas emitidas por el negocio se precargan en «Registro Venta Inteligente»; si el cliente no existe, se da de alta con un botón.' ?>
</p>

<nav class="chip-grupo ajustes-tabs mb-lg" aria-label="Carril de carga">
    <a class="chip<?= $carril === 'gastos' ? ' chip--activo' : '' ?>" href="/admin/contabilidad/lotes?carril=gastos"<?= $carril === 'gastos' ? ' aria-current="page"' : '' ?>>
        <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span><span>Facturas de proveedores (<?= count($filas) ?>)</span></a>
    <a class="chip<?= $carril === 'ventas' ? ' chip--activo' : '' ?>" href="/admin/contabilidad/lotes?carril=ventas"<?= $carril === 'ventas' ? ' aria-current="page"' : '' ?>>
        <span class="material-symbols-outlined" aria-hidden="true">sell</span><span>Facturas emitidas (<?= count($filas) ?>)</span></a>
</nav>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Subir <?= $carril === 'gastos' ? 'facturas de proveedores' : 'facturas emitidas por el negocio' ?></h2>
    <form class="buscador" action="/admin/contabilidad/lotes?carril=<?= $carril ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <input type="hidden" name="accion" value="subir">
        <input class="control" type="file" name="archivos[]" multiple required
               accept=".xml,.pdf,application/xml,text/xml,application/pdf" aria-label="Archivos del lote">
        <button class="btn btn--primario" type="submit">
            <span class="material-symbols-outlined" aria-hidden="true">upload_file</span><span>Leer documentos</span></button>
    </form>
    <p class="campo__ayuda mt-sm">Se leen XML y PDF. El PDF solo no se registra en automático (sus datos salen con poca
        confianza): si no hay XML, el documento queda para revisar a mano.</p>
</section>

<?php if ($carril === 'gastos' && ($registrables || $descartables)) : ?>
    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Atajos del lote</h2>
        <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center">
            <?php if ($registrables) : ?>
                <form action="/admin/contabilidad/lotes-accion" method="post" data-validar
                      data-confirmacion-sobre="Registro en lote"
                      data-confirmacion="¿Registrar <?= count($registrables) ?> factura(s) de proveedor como gasto contable?"
                      data-confirmacion-mensaje="Se usará la sugerencia de pago de cada renglón (PUE + Contado ⇒ pagado). Las que no sean facturas limpias no se tocan."
                      data-confirmacion-boton="Sí, registrar todo">
                    <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                    <input type="hidden" name="accion" value="todo">
                    <button class="btn btn--secundario" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true">done_all</span>
                        <span>Registrar todas las facturas limpias como gasto (<?= count($registrables) ?>)</span></button>
                </form>
            <?php endif; ?>
            <?php if ($descartables) : ?>
                <form action="/admin/contabilidad/lotes-accion" method="post" data-validar
                      data-confirmacion-sobre="Limpiar la bandeja"
                      data-confirmacion="¿Descartar <?= count($descartables) ?> documento(s) que no se van a registrar?"
                      data-confirmacion-mensaje="Se descartan los ya registrados, los que no son de este negocio y los complementos ya cargados. Las facturas limpias y las que se revisan a mano no se tocan."
                      data-confirmacion-boton="Sí, descartar">
                    <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                    <input type="hidden" name="accion" value="descartar_resto">
                    <button class="btn btn--peligro" type="submit">
                        <span class="material-symbols-outlined" aria-hidden="true">delete_sweep</span>
                        <span>Descartar lo que no se va a registrar (<?= count($descartables) ?>)</span></button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!$filas) : ?>
    <p class="nota" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">inbox</span>
        <span>No hay documentos pendientes en este carril: sube un lote arriba o revisa el módulo de
            <a href="<?= $carril === 'gastos' ? '/admin/compras/inteligente' : '/admin/contabilidad/lectura' ?>">lecturas</a>.</span></p>
<?php else : ?>
    <section class="tarjeta tarjeta--sin-relleno">
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Documentos pendientes del lote (<?= count($filas) ?>)</caption>
                <thead><tr>
                    <th scope="col">Documento</th>
                    <th scope="col">Fecha</th>
                    <th scope="col"><?= $carril === 'gastos' ? 'Proveedor' : 'Cliente' ?></th>
                    <th scope="col" class="tabla--numerica">Total</th>
                    <th scope="col">Detección</th>
                    <th scope="col">Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($filas as $f) : ?>
                    <tr>
                        <td>
                            <strong><?= escaparValores($f['nombre']) ?></strong>
                            <?php if (!empty($f['folio'])) : ?><br><span class="texto-xs texto-secundario"><?= escaparValores($f['folio']) ?></span><?php endif; ?>
                        </td>
                        <td><?= escaparValores((string) ($f['fecha'] ?? '')) ?></td>

                        <?php if ($carril === 'gastos') : ?>
                            <td class="texto-sm"><?= escaparValores((string) ($f['emisor']['nombre'] ?? '—')) ?>
                                <?php if (!empty($f['proveedor'])) : ?>
                                    <br><span class="badge badge--aprobado">Proveedor: <?= escaparValores((string) ($f['proveedor']['nombre'] ?? '')) ?></span>
                                <?php elseif ($f['clase'] === 'factura') : ?>
                                    <br><span class="badge badge--pendiente">Proveedor sin ficha (se crea al registrar)</span>
                                <?php endif; ?></td>
                        <?php else : ?>
                            <td class="texto-sm"><?= escaparValores((string) ($f['cliente']['nombre_empresa'] ?? $f['cliente']['nombre'] ?? '—')) ?>
                                <?php if (empty($f['cliente']) && $f['clase'] === 'factura') : ?>
                                    <br><span class="badge badge--pendiente">Cliente sin ficha</span>
                                <?php endif; ?></td>
                        <?php endif; ?>

                        <td class="tabla--numerica cifra"><?= ($f['total'] ?? 0) > 0 ? formatoMoneda((float) $f['total']) : '—' ?></td>
                        <td><?php
                            $etiquetas = [
                                'factura'     => '<span class="badge badge--aprobado">Factura lista</span>',
                                'complemento' => '<span class="badge badge--info">Complemento de pago</span>',
                                'duplicado'   => '<span class="badge badge--error">Ya registrada</span>',
                                'ajeno'       => '<span class="badge badge--neutro">No es de este negocio</span>',
                                'manual'      => '<span class="badge badge--pendiente">Revisar a mano (sin XML)</span>',
                            ];
                            echo $etiquetas[$f['clase']] ?? '';
                            if ($f['clase'] === 'factura' && !empty($f['sugiere_pagado'])) {
                                echo ' <span class="badge badge--info">PUE + Contado</span>';
                            }
                        ?></td>
                        <td>
                            <?php if ($f['clase'] === 'factura' && $carril === 'gastos') : ?>
                                <div style="display:flex;flex-direction:column;gap:.35rem">
                                    <form action="/admin/contabilidad/lotes-accion" method="post">
                                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                        <input type="hidden" name="accion" value="gasto_solo">
                                        <input type="hidden" name="carril" value="<?= $carril ?>">
                                        <input type="hidden" name="lectura" value="<?= (int) $f['id'] ?>">
                                        <label class="comprobacion texto-xs">
                                            <input type="checkbox" name="pagado" value="1"<?= !empty($f['sugiere_pagado']) ? ' checked' : '' ?>>
                                            Ya está pagada<?= !empty($f['sugiere_pagado']) ? ' (sugerido por PUE + Contado)' : '' ?>
                                        </label>
                                        <button class="btn btn--secundario btn--sm" type="submit">Solo gasto</button>
                                    </form>
                                    <form action="/admin/contabilidad/lotes-accion" method="post" data-validar
                                          data-confirmacion-sobre="Compra de proveedor"
                                          data-confirmacion="¿Registrar la compra y su gasto?"
                                          data-confirmacion-mensaje="Se crea la compra con los conceptos del XML (métricas de proveedor) y su gasto en contabilidad, una sola vez."
                                          data-confirmacion-boton="Sí, registrar">
                                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                        <input type="hidden" name="accion" value="compra_nueva">
                                        <input type="hidden" name="carril" value="<?= $carril ?>">
                                        <input type="hidden" name="lectura" value="<?= (int) $f['id'] ?>">
                                        <button class="btn btn--primario btn--sm" type="submit">Gasto y compra</button>
                                    </form>
                                    <?php if (!empty($f['ordenes'])) : ?>
                                        <form action="/admin/contabilidad/lotes-accion" method="post" class="texto-xs">
                                            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                            <input type="hidden" name="accion" value="relacionar">
                                            <input type="hidden" name="carril" value="<?= $carril ?>">
                                            <input type="hidden" name="lectura" value="<?= (int) $f['id'] ?>">
                                            <label class="solo-lectores" for="orden-<?= (int) $f['id'] ?>">Compra existente</label>
                                            <select class="control control--compacto" id="orden-<?= (int) $f['id'] ?>" name="orden">
                                                <?php foreach ($f['ordenes'] as $o) : ?>
                                                    <option value="<?= (int) $o['id'] ?>"><?= escaparValores($o['folio']) ?>
                                                        · <?= formatoMoneda($o['total']) ?> · <?= (int) $o['coincidencias'] ?> partida(s) igual(es)</option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn--secundario btn--sm" type="submit">Relacionar</button>
                                        </form>
                                    <?php endif; ?>
                                    <?= $formDescartar((int) $f['id'], $carril) ?>
                                </div>
                            <?php elseif ($f['clase'] === 'factura' && $carril === 'ventas') : ?>
                                <div style="display:flex;flex-direction:column;gap:.35rem">
                                    <?php if (empty($f['cliente'])) : ?>
                                        <form action="/admin/contabilidad/lotes-accion" method="post">
                                            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                                            <input type="hidden" name="accion" value="alta_cliente">
                                            <input type="hidden" name="carril" value="<?= $carril ?>">
                                            <input type="hidden" name="lectura" value="<?= (int) $f['id'] ?>">
                                            <button class="btn btn--primario btn--sm" type="submit">Registrar cliente</button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="btn btn--secundario btn--sm" href="/admin/ventas/crear?lectura=<?= (int) $f['id'] ?>">Crear venta</a>
                                    <?= $formDescartar((int) $f['id'], $carril) ?>
                                </div>
                            <?php elseif ($f['clase'] === 'complemento') : ?>
                                <div style="display:flex;flex-direction:column;gap:.35rem">
                                    <a class="btn btn--primario btn--sm" href="<?= !empty($f['lado_cliente'])
                                        ? '/admin/ventas/complemento/revision?id=' . (int) $f['id']
                                        : '/admin/compras/complemento/revision?id=' . (int) $f['id'] ?>">
                                        Revisar complemento</a>
                                    <?php if (!empty($f['duplicado'])) : ?>
                                        <p class="texto-xs texto-secundario">Ya registrado antes: no se carga dos veces.</p>
                                    <?php endif; ?>
                                    <?= $formDescartar((int) $f['id'], $carril) ?>
                                </div>
                            <?php elseif ($f['clase'] === 'duplicado') : ?>
                                <div style="display:flex;flex-direction:column;gap:.35rem">
                                    <a class="btn btn--secundario btn--sm" href="<?= escaparValores((string) ($f['duplicado']['ruta'] ?? '#')) ?>">Ver el existente</a>
                                    <p class="texto-xs texto-secundario"><?= escaparValores((string) ($f['duplicado']['etiqueta'] ?? '')) ?></p>
                                    <?= $formDescartar((int) $f['id'], $carril) ?>
                                </div>
                            <?php elseif ($f['clase'] === 'ajeno') : ?>
                                <div style="display:flex;flex-direction:column;gap:.35rem">
                                    <p class="texto-xs">El receptor es <?= escaparValores((string) ($f['receptor'] ?? '')) ?>, no este negocio.</p>
                                    <?= $formDescartar((int) $f['id'], $carril) ?>
                                </div>
                            <?php else : ?>
                                <div style="display:flex;flex-direction:column;gap:.35rem">
                                    <a class="btn btn--secundario btn--sm" href="<?= $carril === 'gastos'
                                        ? '/admin/compras/inteligente/revision?id=' . (int) $f['id']
                                        : '/admin/contabilidad/lectura/revisar?id=' . (int) $f['id'] ?>">Revisar a mano</a>
                                    <?= $formDescartar((int) $f['id'], $carril) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php template('footer'); ?>
