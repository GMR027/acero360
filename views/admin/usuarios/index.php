<?php
/**
 * views/admin/usuarios/index.php — Listado de administradores.
 *
 * Búsqueda por nombre o correo + paginación + acciones de editar y eliminar.
 */

soloAdmin();

/** Texto de búsqueda actual. */
$termino = trim($_GET['q'] ?? '');

/** Registros por página y cálculo de la paginación. */
$porPagina = 10;
$total     = \App\Usuarios::contarTodos($termino);
$pagina    = paginacion($porPagina, $total);
$usuarios  = \App\Usuarios::mostrarTodos($pagina['limite'], $pagina['offset'], $termino);

/** URL del listado conservando la búsqueda activa. */
$rutaBase = '/admin/usuarios' . ($termino !== '' ? '?q=' . rawurlencode($termino) : '');

/** Identificador del usuario conectado (no puede borrarse a sí mismo). */
$sesionId = (int) ($_SESSION['id'] ?? 0);

template('header', [
    'titulo'    => 'Usuarios y perfil',
    'subtitulo' => 'Módulo de usuarios',
    'acciones'  => '<a class="btn btn--primario" href="/admin/usuarios/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">person_add</span>'
        . '<span>Nuevo usuario</span></a>',
]);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <!-- Buscador -->
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/usuarios" method="get" role="search" data-validar>
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar usuario</label>
                <input class="control" type="search" id="q" name="q" maxlength="80"
                       placeholder="Buscar por nombre o correo…"
                       value="<?= escaparValores($termino) ?>">
            </div>

            <button type="submit" class="btn btn--secundario btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">search</span>
                <span>Buscar</span>
            </button>

            <?php if ($termino !== '') : ?>
                <a class="btn btn--texto btn--sm" href="/admin/usuarios"
                   aria-label="Limpiar la búsqueda">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">close</span>
                    <span>Limpiar</span>
                </a>
            <?php endif; ?>
        </form>

        <span class="badge badge--neutro badge--sin-punto cifra">
            <?= number_format($total) ?> <?= $total === 1 ? 'usuario' : 'usuarios' ?>
        </span>
    </div>

    <?php if (empty($usuarios)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">manage_accounts</span>
            <p class="vacio__titulo"><?= $termino !== '' ? 'Sin coincidencias' : 'Aún no hay usuarios' ?></p>
            <p><?= $termino !== ''
                    ? 'Ningún usuario coincide con "' . escaparValores($termino) . '".'
                    : 'Registra al primer administrador del panel.' ?></p>
            <a class="btn btn--primario" href="/admin/usuarios/crear">Nuevo usuario</a>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Listado de usuarios administradores</caption>
                <thead>
                    <tr>
                        <th scope="col">Usuario</th>
                        <th scope="col">Correo</th>
                        <th scope="col">Teléfono</th>
                        <th scope="col" class="tabla--centro">Rol</th>
                        <th scope="col">Alta</th>
                        <th scope="col" class="tabla--numerica">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $usuario) :
                        $esElMismo = (int) $usuario->id === $sesionId;
                        ?>
                        <tr>
                            <td>
                                <div class="tabla__producto-textos">
                                    <strong><?= escaparValores($usuario->nombre) ?></strong>
                                    <?php if ($esElMismo) : ?>
                                        <span class="texto-xs texto-secundario">Tú</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= escaparValores($usuario->email) ?></td>
                            <td class="cifra"><?= escaparValores($usuario->telefono ?: '—') ?></td>
                            <td class="tabla--centro">
                                <span class="badge badge--info">Admin</span>
                            </td>
                            <td class="texto-sm texto-secundario cifra">
                                <?= $usuario->creado_en ? escaparValores(date('d/m/Y', strtotime($usuario->creado_en))) : '—' ?>
                            </td>
                            <td class="tabla--numerica">
                                <div class="tabla__acciones">
                                    <a class="btn btn--secundario btn--sm btn--icono"
                                       href="/admin/usuarios/editar?id=<?= (int) $usuario->id ?>"
                                       title="Editar" aria-label="Editar <?= escaparValores($usuario->nombre) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>
                                    </a>

                                    <?php if (!$esElMismo) : ?>
                                        <!-- Borrado con confirmación modal (nunca window.confirm). -->
                                        <form action="/admin/usuarios/eliminar" method="post" data-validar
                                              data-confirmacion-sobre="Usuarios del panel"
                                              data-confirmacion="¿Eliminar este usuario?"
                                              data-confirmacion-mensaje="Se borrará el acceso de «<?= escaparValores($usuario->nombre) ?>» al panel. Esta acción no se puede deshacer."
                                              data-confirmacion-boton="Sí, eliminar">
                                            <input type="hidden" name="id" value="<?= (int) $usuario->id ?>">
                                            <button type="submit" class="btn btn--peligro btn--sm btn--icono"
                                                    title="Eliminar" aria-label="Eliminar <?= escaparValores($usuario->nombre) ?>">
                                                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        template('paginacion', [
            'pagina'   => $pagina,
            'rutaBase' => $rutaBase,
            'total'    => $total,
        ]);
        ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
