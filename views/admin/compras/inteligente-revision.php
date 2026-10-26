<?php
/**
 * views/admin/compras/inteligente-revision.php — Decisión sobre una factura de compra leída.
 *
 * Muestra lo que trae el XML, el proveedor (o la propuesta de alta), la clasificación,
 * la operación a la que ya pertenece (si el UUID existe) y las compras candidatas con
 * su comparación de partidas. El modal de decisión ofrece:
 *   1. Relacionar con una compra existente  2. Iniciar una compra nueva
 *   3. Revisar sin registrar                4. Cancelar
 *
 * Esta pantalla NO escribe nada en compras ni en contabilidad: solo decide y llama a
 * ComprasIntel::relacionar() (única vía de escritura, con sus validaciones).
 */

soloAdmin();

$id = (int) ($_REQUEST['id'] ?? 0);
$analisis = \App\ComprasIntel::analizar($id);
if ($analisis === null) {
    paginaNoEncontrada();
}

$lectura = $analisis['lectura'];
$d = $analisis['datos'];
$proveedor = $analisis['proveedor'];
$clasificacion = $analisis['clasificacion'];
$duplicado = $analisis['duplicado'];
$ordenes = $analisis['ordenes'];
$coincidencias = $analisis['coincidencias'];
$propuesta = $analisis['propuesta'];
$fechaCorta = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $d['fecha'])
    ? date('d/m/Y', strtotime(substr((string) $d['fecha'], 0, 10))) : '—';

// La opción «compra nueva» solo se ofrece si ya hay proveedor (o se propone registrar uno).
$rutaNuevaCompra = $proveedor
    ? '/admin/compras/crear?compra_intel=' . $id : '';

template('header', ['titulo' => 'Decidir sobre la factura', 'subtitulo' => 'Compras inteligentes']);
template('tabsCompras', ['tab' => 'inteligente']);
?>

<?php foreach ($analisis['avisos'] as $aviso) : ?>
    <p class="texto-sm texto-error mb-md">⚠ <?= escaparValores($aviso) ?></p>
<?php endforeach; ?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Lo que dice la factura</h2>
    <div class="formulario__rejilla">
        <div class="campo"><span class="campo__etiqueta">Emisor</span>
            <span><?= escaparValores($d['emisor_nombre'] ?: '—') ?></span></div>
        <div class="campo"><span class="campo__etiqueta">RFC</span>
            <span><?= escaparValores($d['emisor_rfc'] ?: '—') ?></span></div>
        <div class="campo"><span class="campo__etiqueta">UUID fiscal</span>
            <span><?= escaparValores($d['uuid'] ?: 'sin timbre') ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Fecha de emisión</span>
            <span><?= escaparValores($fechaCorta) ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Moneda</span>
            <span><?= escaparValores($d['moneda'] ?: 'MXN') ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Subtotal</span>
            <span class="cifra"><?= $d['subtotal'] !== '' ? formatoMoneda((float) $d['subtotal']) : '—' ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Descuento</span>
            <span class="cifra"><?= (float) ($d['descuento'] ?: 0) > 0 ? formatoMoneda((float) $d['descuento']) : '—' ?></span></div>
        <div class="campo"><span class="campo__etiqueta">IVA</span>
            <span class="cifra"><?= $d['iva'] !== '' ? formatoMoneda((float) $d['iva']) : '—' ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Otros impuestos</span>
            <span class="cifra"><?= (float) ($d['otros_impuestos'] ?: 0) > 0 ? formatoMoneda((float) $d['otros_impuestos']) : '—' ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Retenciones</span>
            <span class="cifra"><?= (float) ($d['retenciones'] ?: 0) > 0 ? formatoMoneda((float) $d['retenciones']) : '—' ?></span></div>
        <div class="campo"><span class="campo__etiqueta">Total</span>
            <span class="cifra"><strong><?= $d['total'] !== '' ? formatoMoneda((float) $d['total']) : '—' ?></strong></span></div>
        <div class="campo"><span class="campo__etiqueta">Conceptos</span>
            <span><?= count((array) $d['conceptos']) ?></span></div>
    </div>

    <?php if ($d['conceptos']) : ?>
        <div class="tabla-contenedor mt-md"><table class="tabla">
            <caption class="solo-lectores">Conceptos de la factura</caption>
            <thead><tr><th scope="col">Descripción</th><th scope="col">Cant.</th>
                <th scope="col" class="tabla--numerica">Unitario</th><th scope="col" class="tabla--numerica">Importe</th></tr></thead>
            <tbody>
            <?php foreach ((array) $d['conceptos'] as $c) : ?>
                <tr>
                    <td><?= escaparValores($c['descripcion'] ?: '—') ?><?= $c['sku'] ? ' <span class="texto-xs texto-secundario">(' . escaparValores($c['sku']) . ')</span>' : '' ?></td>
                    <td><?= escaparValores($c['cantidad']) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda((float) $c['valor_unitario']) ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda((float) $c['importe']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <?php /* Los mismos conceptos sin equivalente que ya ofrece el formulario de gasto,
             con el botón para agregarlos al catálogo del proveedor emisor. */ ?>
    <?php template('sinEquivalente', [
        'conceptos'       => (array) ($coincidencias['sin_equivalente'] ?? []),
        'nombreDoc'       => 'compra',
        'proveedorId'     => $proveedor ? (int) $proveedor['id'] : 0,
        'proveedorNombre' => $proveedor ? (string) $proveedor['nombre'] : '',
    ]); ?>
</section>

<?php if ($duplicado) : ?>
    <section class="tarjeta mb-lg">
        <h2 class="titulo-5 mb-md">Esta factura ya existe</h2>
        <p class="texto-sm"><?= escaparValores($duplicado['etiqueta']) ?>: no se guardará de nuevo el importe.</p>
        <p><a class="btn btn--secundario btn--sm" href="<?= escaparValores($duplicado['ruta']) ?>">Ver la operación relacionada</a></p>
    </section>
<?php endif; ?>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Clasificación y proveedor</h2>
    <p class="texto-sm mb-md">
        <span class="badge <?= $clasificacion['clave'] === 'material' ? 'badge--aprobado' : ($clasificacion['clave'] === 'gasto' ? 'badge--pendiente' : 'badge--neutro') ?>">
            <?= escaparValores($clasificacion['etiqueta']) ?></span>
        · <?= escaparValores($clasificacion['motivo']) ?>
        <?php if ($clasificacion['categoria']) : ?> (categoría: <?= escaparValores($clasificacion['categoria']) ?>)<?php endif; ?>
    </p>

    <?php if ($proveedor) : ?>
        <p class="texto-sm">Proveedor: <a href="<?= escaparValores($proveedor['ruta']) ?>"><strong><?= escaparValores($proveedor['nombre']) ?></strong></a>
            <?= $proveedor['rfc'] ? ' · ' . escaparValores($proveedor['rfc']) : '' ?>
            · <?= escaparValores($proveedor['criterio']) ?></p>
    <?php else : ?>
        <p class="texto-sm mb-md">No encontré un proveedor con este RFC. Puedes registrarlo ahora (queda con tus demás proveedores):</p>
        <form class="formulario" action="/admin/compras/inteligente/accion" method="post" data-validar
              data-confirmacion-sobre="Alta de proveedor"
              data-confirmacion="¿Registrar al proveedor «<?= escaparValores($propuesta['nombre']) ?>»?"
              data-confirmacion-mensaje="Se creará con el RFC del XML. Podrás editarlo después desde Proveedores."
              data-confirmacion-boton="Sí, registrarlo">
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
            <input type="hidden" name="accion" value="registrar_proveedor">
            <input type="hidden" name="lectura" value="<?= $id ?>">
            <div class="formulario__rejilla">
                <div class="campo campo--completo"><label class="campo__etiqueta" for="prov-nombre">Nombre</label>
                    <input class="control" id="prov-nombre" name="nombre" maxlength="150" required value="<?= escaparValores($propuesta['nombre']) ?>"></div>
                <div class="campo"><label class="campo__etiqueta" for="prov-rfc">RFC</label>
                    <input class="control" id="prov-rfc" name="rfc" maxlength="20" value="<?= escaparValores($propuesta['rfc']) ?>"></div>
                <div class="campo"><label class="campo__etiqueta" for="prov-tipo">Tipo</label>
                    <select class="control" id="prov-tipo" name="tipo_proveedor">
                        <?php foreach (\App\Proveedores::TIPOS as $t) : ?>
                            <option value="<?= $t ?>"<?= $propuesta['tipo_proveedor'] === $t ? ' selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select></div>
            </div>
            <div class="formulario__acciones"><button class="btn btn--secundario" type="submit">Registrar proveedor</button></div>
        </form>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg" id="compras">
    <h2 class="titulo-5 mb-md">Compras de este proveedor</h2>
    <?php if (!$proveedor) : ?>
        <p class="texto-sm texto-secundario">Primero registra el proveedor para ver sus compras, o inicia una compra nueva.</p>
    <?php elseif (!$ordenes) : ?>
        <p class="texto-sm texto-secundario">Este proveedor aún no tiene compras registradas: usa «Iniciar una compra nueva».</p>
    <?php else : ?>
        <p class="texto-sm texto-secundario mb-md">Compara cada compra con la factura. Al relacionar, el XML se adjunta a la compra y a su gasto en contabilidad (una sola vez); no se modifica la recepción ni el inventario.</p>
        <?php foreach ($ordenes as $o) : $comp = $o['comparacion']; $cfdiO = \App\CompraContable::ESTADOS_CFDI[$o['estado_cfdi']]; ?>
            <article class="tarjeta mb-md">
                <p class="texto-sm">
                    <a href="/admin/compras/detalle?id=<?= (int) $o['id'] ?>"><strong><?= escaparValores($o['folio']) ?></strong></a>
                    · <span class="badge <?= \App\OrdenesCompra::ESTADOS[$o['estado']]['clase'] ?? 'badge--neutro' ?>"><?= escaparValores(\App\OrdenesCompra::ESTADOS[$o['estado']]['etiqueta'] ?? $o['estado']) ?></span>
                    · <span class="badge <?= $cfdiO['clase'] ?>"><?= escaparValores($cfdiO['etiqueta']) ?></span>
                    · <?= escaparValores(date('d/m/Y', strtotime($o['fecha_solicitud']))) ?>
                    · <?= $o['referencia'] ? 'Ref. ' . escaparValores($o['referencia']) . ' · ' : '' ?>
                    total <strong class="cifra"><?= formatoMoneda($comp['totales']['compra']) ?></strong>
                    vs factura <strong class="cifra"><?= formatoMoneda($comp['totales']['factura']) ?></strong>
                    <?php $dif = $comp['totales']['diferencia']; ?>
                    <span class="<?= abs($dif) > 0.005 ? 'texto-error' : 'texto-secundario' ?>">
                        (<?= ($dif > 0 ? '+' : '') . number_format($dif, 2) ?>)</span>
                </p>

                <div class="tabla-contenedor"><table class="tabla">
                    <caption class="solo-lectores">Comparación de partidas</caption>
                    <thead><tr><th scope="col">Partida de la compra</th><th scope="col" class="tabla--numerica">Cant. comprada</th>
                        <th scope="col" class="tabla--numerica">Cant. facturada</th><th scope="col" class="tabla--numerica">Precio comprado</th>
                        <th scope="col" class="tabla--numerica">Precio facturado</th><th scope="col">Coincidencia</th></tr></thead>
                    <tbody>
                    <?php foreach ($comp['partidas'] as $fila) : $p = $fila['partida']; $c = $fila['concepto']; ?>
                        <tr>
                            <td><?= escaparValores($p['nombre_mostrado']) ?></td>
                            <td class="tabla--numerica cifra"><?= escaparValores((string) $p['solicitada']) ?></td>
                            <td class="tabla--numerica cifra<?= $fila['dif_cantidad'] !== null && abs($fila['dif_cantidad']) > 0.0005 ? ' texto-error' : '' ?>">
                                <?= $c ? escaparValores((string) $c['cantidad']) : '—' ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda((float) $p['costo_esperado']) ?></td>
                            <td class="tabla--numerica cifra<?= $fila['dif_precio'] !== null && $fila['dif_precio'] != 0 ? ' texto-error' : '' ?>">
                                <?= $c ? formatoMoneda((float) $c['valor_unitario']) : '—' ?></td>
                            <td><?= $fila['coincidencia']
                                ? '<span class="badge badge--aprobado">' . escaparValores($fila['criterio']) . '</span>'
                                : '<span class="badge badge--pendiente">sin factura</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($comp['sin_partida'] as $c) : ?>
                        <tr>
                            <td colspan="5"><span class="texto-secundario">Sin partida en la compra: <?= escaparValores($c['descripcion']) ?></span></td>
                            <td><span class="badge badge--pendiente">de más</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>

                <?php if ($duplicado) : ?>
                    <p class="texto-xs texto-secundario">La factura ya pertenece a otra operación: no se puede relacionar de nuevo.</p>
                <?php else : ?>
                    <form action="/admin/compras/inteligente/accion" method="post" data-validar
                          data-confirmacion-sobre="Compra <?= escaparValores($o['folio']) ?>"
                          data-confirmacion="¿Adjuntar esta factura a la compra <?= escaparValores($o['folio']) ?>?"
                          data-confirmacion-mensaje="El XML se adjunta al gasto de la compra (se crea UNA vez si no existía) y la compra pasa a «Con CFDI». No se toca el inventario ni las recepciones."
                          data-confirmacion-boton="Sí, relacionar">
                        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                        <input type="hidden" name="accion" value="relacionar">
                        <input type="hidden" name="lectura" value="<?= $id ?>">
                        <input type="hidden" name="orden" value="<?= (int) $o['id'] ?>">
                        <button class="btn btn--primario btn--sm" type="submit">Relacionar con esta compra</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">¿Qué hacer con esta factura?</h2>
    <div class="formulario__acciones">
        <?php if ($proveedor && !$duplicado) : ?>
            <a class="btn btn--primario" href="#compras">1. Relacionar con una compra existente</a>
        <?php endif; ?>
        <?php if ($rutaNuevaCompra && !$duplicado) : ?>
            <a class="btn btn--secundario" href="<?= escaparValores($rutaNuevaCompra) ?>">2. Iniciar una compra nueva</a>
        <?php endif; ?>
        <?php /* La factura puede resultar un gasto operativo (gasolina, teléfono, luz, servicios):
                 en ese caso se abre el formulario de Gastos precargado, sin obligar a crear una
                 orden de compra ni un proveedor de materiales. */ ?>
        <a class="btn btn--secundario" href="/admin/contabilidad/gastos/crear?lectura=<?= $id ?>">
            Registrar como gasto</a>
        <a class="btn btn--secundario" href="/admin/compras/inteligente">3. Revisar sin registrar</a>
        <form action="/admin/compras/inteligente/accion" method="post" data-validar
              data-confirmacion-sobre="Factura leída"
              data-confirmacion="¿Cancelar la lectura?"
              data-confirmacion-mensaje="Se descarta el análisis; no se guarda nada. El archivo se conserva en el almacén con su huella para detectar duplicados."
              data-confirmacion-boton="Sí, cancelar">
            <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
            <input type="hidden" name="accion" value="cancelar">
            <input type="hidden" name="lectura" value="<?= $id ?>">
            <button class="btn btn--texto" type="submit">4. Cancelar</button>
        </form>
    </div>
    <?php if ($clasificacion['clave'] === 'indeterminado') : ?>
        <p class="texto-sm mt-md"><strong>No pude clasificar la factura con seguridad</strong> (<?= escaparValores($clasificacion['motivo']) ?>):
            revisa los conceptos y elige tú: si es material, relaciónala con una compra o inicia una; si es un gasto operativo
            (gasolina, teléfono, luz, servicios), regístralo desde <a href="/admin/contabilidad/gastos/crear">Contabilidad → Gastos</a>.</p>
    <?php endif; ?>
    <?php if ($clasificacion['clave'] === 'gasto') : ?>
        <p class="texto-sm mt-md"><strong>Por lo que parece, esto es un gasto operativo</strong>, no material para vender:
            usa «Registrar como gasto» (se abre el formulario de Contabilidad con los datos del XML). No necesitas
            orden de compra ni proveedor de materiales para registrarlo.</p>
    <?php endif; ?>
</section>

<script>
/* Abre el diálogo de decisión al entrar (si el modal de la app está disponible).
 * Las mismas opciones quedan visibles arriba: sin JS la pantalla sigue siendo usable. */
window.addEventListener('load', function () {
    if (typeof abrirModal !== 'function') { return; }
    var cuerpo = document.createElement('div');
    var parrafo = document.createElement('p');
    parrafo.className = 'texto-sm';
    parrafo.textContent = <?= json_encode('La factura de ' . ($d['emisor_nombre'] ?: $d['emisor_rfc'] ?: 'este emisor')
        . ' (' . ($d['total'] !== '' ? formatoMoneda((float) $d['total']) : 'importe sin definir') . ') fue leída. ¿Qué hacemos con ella? Elige una opción; no se guarda nada hasta que confirmes.') ?>;
    cuerpo.appendChild(parrafo);

    <?php if ($clasificacion['clave'] === 'indeterminado') : ?>
    var aviso = document.createElement('p');
    aviso.className = 'texto-sm texto-error';
    aviso.textContent = 'No se pudo clasificar con seguridad: revisa los conceptos antes de decidir.';
    cuerpo.appendChild(aviso);
    <?php endif; ?>

    var acciones = [];
    <?php if ($rutaNuevaCompra && !$duplicado) : ?>
    var nueva = document.createElement('button');
    nueva.type = 'button';
    nueva.className = 'btn btn--secundario';
    nueva.textContent = 'Iniciar una compra nueva';
    nueva.addEventListener('click', function () { window.location.assign(<?= json_encode($rutaNuevaCompra) ?>); });
    acciones.push(nueva);
    <?php endif; ?>
    <?php /* Si el documento parece un gasto operativo, la vía principal es el formulario de Gastos. */ ?>
    var gasto = document.createElement('button');
    gasto.type = 'button';
    gasto.className = 'btn btn--secundario';
    gasto.textContent = <?= json_encode($clasificacion['clave'] === 'gasto' ? 'Registrar como gasto (recomendado)' : 'Registrar como gasto') ?>;
    gasto.addEventListener('click', function () { window.location.assign('/admin/contabilidad/gastos/crear?lectura=<?= $id ?>'); });
    acciones.push(gasto);
    var revisar = document.createElement('button');
    revisar.type = 'button';
    revisar.className = 'btn btn--secundario';
    revisar.textContent = 'Revisar sin registrar';
    revisar.addEventListener('click', function () { window.location.assign('/admin/compras/inteligente'); });
    acciones.push(revisar);

    abrirModal({
        sobreTitulo: 'Compras inteligentes',
        titulo: '¿Qué hacer con esta factura?',
        cuerpo: cuerpo,
        acciones: acciones,
        textoConfirmar: <?= json_encode($proveedor && !$duplicado ? 'Relacionar con una compra' : 'Cerrar') ?>,
        alConfirmar: function () {
            var seccion = document.getElementById('compras');
            if (seccion) { seccion.scrollIntoView({ block: 'start' }); }
            else { window.location.assign('/admin/compras/inteligente'); }
        }
    });
});
</script>

<?php template('footer'); ?>
