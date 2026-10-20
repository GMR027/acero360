<?php
/**
 * views/admin/ajustes-papelera.php — Papelera: ventas y cotizaciones eliminadas.
 * Se pueden restaurar durante 90 días; después se borran definitivamente de la base.
 */

soloAdmin();

$usuarioId = (int) ($_SESSION['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $item = \App\Papelera::buscarID($id);

    if (!$item) {
        redirigir('/admin/ajustes/papelera', 'error', 'Ese elemento ya no está en la papelera.');
    }

    if (($_POST['accion'] ?? '') === 'restaurar') {
        if (\App\Papelera::restaurar($id, $usuarioId)) {
            \App\Auditoria::registrar('papelera', $id, 'restaurar', $usuarioId, null, ['tipo' => $item['tipo'], 'etiqueta' => $item['etiqueta']]);
            redirigir('/admin/ajustes/papelera', 'exito', $item['etiqueta'] . ' restaurado.');
        }
        redirigir('/admin/ajustes/papelera', 'error', \App\Papelera::getErrores()[0] ?? 'No fue posible restaurarlo.');
    }

    if (($_POST['accion'] ?? '') === 'borrar') {
        \App\Papelera::borrarDefinitivo($id);
        \App\Auditoria::registrar('papelera', $id, 'borrar_definitivo', $usuarioId, null, ['tipo' => $item['tipo'], 'etiqueta' => $item['etiqueta']]);
        redirigir('/admin/ajustes/papelera', 'exito', $item['etiqueta'] . ' se borró definitivamente.');
    }

    redirigir('/admin/ajustes/papelera', 'error', 'Acción no válida.');
}

\App\Papelera::purgarVencidos();
$elementos = \App\Papelera::listar();

template('header', ['titulo' => 'Papelera', 'subtitulo' => 'Ventas y cotizaciones eliminadas', 'rutaActiva' => '/admin/ajustes']);
template('tabsAjustes', ['tab' => 'papelera']);
?>

<section class="tarjeta" aria-labelledby="titulo-papelera">
    <div class="ajustes-cabecera">
        <span class="ajustes-cabecera__icono"><span class="material-symbols-outlined" aria-hidden="true">delete</span></span>
        <div>
            <h2 class="titulo-5" id="titulo-papelera">Papelera</h2>
            <p class="texto-xs texto-secundario">Lo que eliminas de Ventas y Cotizaciones llega aquí. Puedes restaurarlo durante <?= \App\Papelera::DIAS ?> días; después se borra definitivamente.</p>
        </div>
    </div>

    <?php if (empty($elementos)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">delete</span>
            <p class="vacio__titulo">La papelera está vacía</p>
            <p>Las ventas y cotizaciones que elimines aparecerán aquí.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Elementos en la papelera</caption>
                <thead>
                    <tr>
                        <th scope="col">Elemento</th>
                        <th scope="col">Tipo</th>
                        <th scope="col" class="tabla__num">Total</th>
                        <th scope="col">Eliminado</th>
                        <th scope="col">Se borra en</th>
                        <th scope="col"><span class="solo-lectores">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($elementos as $e) : ?>
                    <tr>
                        <td><?= escaparValores($e['etiqueta']) ?></td>
                        <td><?= $e['tipo'] === 'venta' ? 'Venta' : 'Cotización' ?></td>
                        <td class="tabla__num cifra"><?= formatoMoneda($e['total']) ?></td>
                        <td><?= escaparValores(date('d/m/Y H:i', strtotime($e['eliminado_en']))) ?><?= $e['eliminado_por_nombre'] ? '<br><span class="texto-xs texto-secundario">' . escaparValores($e['eliminado_por_nombre']) . '</span>' : '' ?></td>
                        <td><?= max(0, (int) $e['dias_restantes']) ?> días</td>
                        <td>
                            <div class="tabla__acciones">
                                <form action="/admin/ajustes/papelera" method="post" data-validar>
                                    <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                                    <input type="hidden" name="accion" value="restaurar">
                                    <button type="submit" class="btn btn--secundario btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">restore_from_trash</span>
                                        <span>Restaurar</span>
                                    </button>
                                </form>
                                <form action="/admin/ajustes/papelera" method="post" data-validar
                                      data-confirmacion-sobre="<?= escaparValores($e['etiqueta']) ?>"
                                      data-confirmacion="¿Borrar definitivamente?"
                                      data-confirmacion-mensaje="Se borrará para siempre de la base de datos. No podrás recuperarlo."
                                      data-confirmacion-boton="Sí, borrar">
                                    <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                                    <input type="hidden" name="accion" value="borrar">
                                    <button type="submit" class="btn btn--peligro btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete_forever</span>
                                        <span>Borrar</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
