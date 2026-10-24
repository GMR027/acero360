<?php
/**
 * views/admin/calendario/index.php — Tablero del módulo «Calendario».
 *
 * Herramienta personal compartida: muestra el calendario del mes activo con
 * botones para navegar entre meses y años. Al hacer clic sobre un día se
 * selecciona: a la derecha aparecen sus recordatorios y eventos y el
 * formulario para crear uno nuevo (fecha precargada con el día elegido).
 *
 * Además de los recordatorios, cada casilla marca con un icono de color lo que
 * pasó en los negocios ese día (EventosCalendario::marcadoresNegocio): venta
 * (verde), gasto (rojo), recepción de proveedor (azul), factura por pagar
 * (amarillo) y factura por cobrar (naranja). Son de SÓLO LECTURA: no se
 * guardan en calendario_eventos, se calculan al vuelo y no se pueden editar.
 *
 * El alta se envía a /admin/calendario/crear (sólo POST); aquí sólo se lee.
 * Ruta protegida por el middleware "soloAdmin".
 */

soloAdmin();

/** Fecha de hoy (una sola referencia para «hoy», el día activo y los enlaces). */
$hoy = new DateTime('today');

/** Mes y año activos: ?mes=1-12 y ?anio=2000-2100, con el mes actual por defecto. */
$anio = (int) ($_GET['anio'] ?? $hoy->format('Y'));
$anio = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) $hoy->format('Y');

$mes = isset($_GET['mes']) ? (int) $_GET['mes'] : (int) $hoy->format('n');
$mes = ($mes >= 1 && $mes <= 12) ? $mes : (int) $hoy->format('n');

$ultimoDia = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));
$esPeriodoActual = $anio === (int) $hoy->format('Y') && $mes === (int) $hoy->format('n');

/** Día seleccionado: el de la URL o, si es el mes en curso, el de hoy. */
$dia = isset($_GET['dia']) ? (int) $_GET['dia'] : ($esPeriodoActual ? (int) $hoy->format('j') : 0);
if ($dia < 1 || $dia > $ultimoDia) {
    $dia = $esPeriodoActual ? (int) $hoy->format('j') : 0;
}

$fechaSeleccionada = $dia > 0 ? sprintf('%04d-%02d-%02d', $anio, $mes, $dia) : '';

/** Eventos del mes, agrupados por día, más el conteo y el nivel más alto de cada día. */
$eventosMes   = \App\EventosCalendario::listarMes($anio, $mes);
$conteoPorDia = \App\EventosCalendario::contarPorDia($anio, $mes);
$pesoImportancia = ['bajo' => 1, 'medio' => 2, 'alto' => 3];

$porDia = [];
$importanciaPorDia = [];
foreach ($eventosMes as $eventoMes) {
    $porDia[$eventoMes->fecha][] = $eventoMes;

    $actual = $importanciaPorDia[$eventoMes->fecha] ?? 'bajo';
    if ($pesoImportancia[$eventoMes->importancia] > $pesoImportancia[$actual]) {
        $importanciaPorDia[$eventoMes->fecha] = $eventoMes->importancia;
    }
}

/** Cifras del resumen mensual. */
$resumen = \App\EventosCalendario::resumenMes($anio, $mes);

/**
 * Marcadores del negocio por día (ventas, gastos, recepciones, por pagar y
 * por cobrar). Son de sólo lectura: se calculan al vuelo desde cada tabla y
 * no se pueden editar ni borrar desde aquí.
 */
$marcadoresPorDia = \App\EventosCalendario::marcadoresNegocio($anio, $mes);

/**
 * Iconos de una casilla, en el orden de la leyenda.
 *
 * Cada tipo de marcador es UN solo icono (aunque ese día haya varias
 * operaciones: la cantidad va en el tooltip), así que siempre caben los cinco.
 *
 * @param array<string, array{n: int, importe: float|null}> $marcadores
 */
$iconosCelda = static function (array $marcadores): string {
    if ($marcadores === []) {
        return '';
    }

    $html = '<span class="calendario__iconos">';

    foreach (\App\EventosCalendario::TIPOS_NEGOCIO as $tipo => $datos) {
        if (empty($marcadores[$tipo])) {
            continue;
        }
        $n = (int) $marcadores[$tipo]['n'];

        $titulo = $datos['titulo'] . ': ' . $n . ($n === 1 ? '' : ' ' . $datos['plural']);

        $html .= '<span class="calendario__icono calendario__icono--' . $datos['color'] . '"'
            . ' title="' . escaparValores($titulo) . '" aria-hidden="true">'
            . '<span class="material-symbols-outlined">' . $datos['icono'] . '</span></span>';
    }

    return $html . '</span>';
};

/** Texto legible de los marcadores del día (para el aria-label de la casilla). */
$marcadoresTexto = static function (array $marcadores): string {
    $partes = [];
    foreach (\App\EventosCalendario::TIPOS_NEGOCIO as $tipo => $datos) {
        if (empty($marcadores[$tipo])) {
            continue;
        }
        $n = (int) $marcadores[$tipo]['n'];
        $partes[] = $n . ' ' . ($n === 1 ? $datos['etiqueta'] : $datos['plural']);
    }
    return implode(', ', $partes);
};

/** Navegación entre meses y años (siempre desde el día 1 del mes destino). */
$urlMes = static function (int $nuevoAnio, int $nuevoMes): string {
    return '/admin/calendario?anio=' . $nuevoAnio . '&mes=' . $nuevoMes;
};

$periodo       = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
$anterior      = $periodo->modify('-1 month');
$siguiente     = $periodo->modify('+1 month');
$anteriorAnio  = $periodo->modify('-1 year');
$siguienteAnio = $periodo->modify('+1 year');

/** Rejilla del mes: lunes primero (desplazamiento 0 cuando el día 1 cae en lunes). */
$primerDiaSemana = (int) date('w', mktime(0, 0, 0, $mes, 1, $anio)); // 0 = domingo
$desplazamiento  = ($primerDiaSemana + 6) % 7;
$celdasTotales   = (int) (ceil(($desplazamiento + $ultimoDia) / 7) * 7);

$diasSemana = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];

/** Nombres en español (strftime depende del idioma del servidor y está obsoleto). */
$nombresDia    = [0 => 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$nombresMeses  = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** "Viernes 23 de octubre" (+ " de 2026" cuando se pide el año). */
$fechaAmable = static function (string $fecha, bool $conAnio = false) use ($nombresDia, $nombresMeses): string {
    $momento = new DateTimeImmutable($fecha);
    $texto   = ucfirst($nombresDia[(int) $momento->format('w')]) . ' ' . (int) $momento->format('j')
        . ' de ' . $nombresMeses[(int) $momento->format('n')];
    return $conAnio ? $texto . ' de ' . $momento->format('Y') : $texto;
};

/** Hora amable: "10:30 AM"; cadena vacía si el evento es de todo el día. */
$horaAmable = static function ($hora): string {
    $hora = trim((string) $hora);
    if ($hora === '') {
        return '';
    }
    $momento = DateTimeImmutable::createFromFormat('H:i:s', strlen($hora) === 5 ? $hora . ':00' : $hora);
    return $momento ? $momento->format('g:i A') : $hora;
};

/**
 * Tarjeta de un recordatorio o evento.
 *
 * @param \App\EventosCalendario $evento
 * @param bool                   $compacta Agenda del mes: oculta la descripción
 */
$tarjetaEvento = static function (\App\EventosCalendario $evento, bool $compacta) use ($horaAmable): string {
    $importancia = \App\EventosCalendario::datosImportancia($evento->importancia);
    $negocio     = trim((string) $evento->negocio);
    $hora        = $horaAmable($evento->hora);

    $html  = '<article class="evento-tarjeta evento-tarjeta--' . escaparValores($evento->importancia) . '">';
    $html .= '<header class="evento-tarjeta__cabecera">';
    $html .= '<span class="badge ' . $importancia['clase'] . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem">' . $importancia['icono'] . '</span>'
        . ' ' . $importancia['etiqueta'] . '</span>';

    if ($hora !== '') {
        $html .= '<span class="evento-tarjeta__hora">'
            . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem">schedule</span>'
            . ' ' . escaparValores($hora) . '</span>';
    }

    if ($negocio !== '') {
        $html .= '<span class="evento-tarjeta__negocio">'
            . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem">corporate_fare</span>'
            . ' ' . escaparValores($negocio) . '</span>';
    }

    $html .= '</header>';
    $html .= '<h3 class="evento-tarjeta__nombre">' . escaparValores($evento->nombre) . '</h3>';

    if (!$compacta && trim((string) $evento->descripcion) !== '') {
        $html .= '<p class="evento-tarjeta__descripcion">' . escaparValores($evento->descripcion) . '</p>';
    }

    $html .= '<footer class="evento-tarjeta__acciones">';
    $html .= '<a class="btn btn--texto btn--sm" href="/admin/calendario/editar?id=' . (int) $evento->id . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">edit</span>'
        . '<span>Editar</span></a>';
    $html .= '</footer>';
    $html .= '</article>';

    return $html;
};

/**
 * Baja de un evento: siempre POST y con confirmación modal (nunca window.confirm).
 * El campo «destino» regresa al mes y al día exactos desde donde se borró.
 */
$botonEliminar = static function (\App\EventosCalendario $evento) use ($anio, $mes, $dia): string {
    $destino = '/admin/calendario?anio=' . $anio . '&mes=' . $mes . ($dia > 0 ? '&dia=' . $dia : '');

    return '<form action="/admin/calendario/accion" method="post" data-validar'
        . ' data-confirmacion-sobre="Calendario"'
        . ' data-confirmacion="¿Eliminar este evento?"'
        . ' data-confirmacion-mensaje="Se borrará «' . escaparValores($evento->nombre) . '» del calendario."'
        . ' data-confirmacion-boton="Sí, eliminar">'
        . '<input type="hidden" name="id" value="' . (int) $evento->id . '">'
        . '<input type="hidden" name="accion" value="eliminar">'
        . '<input type="hidden" name="destino" value="' . escaparValores($destino) . '">'
        . '<button type="submit" class="btn btn--peligro btn--sm btn--icono" title="Eliminar"'
        . ' aria-label="Eliminar ' . escaparValores($evento->nombre) . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>'
        . '</button></form>';
};

/** Evento precargado para el alta: la fecha es la del día elegido. */
$eventoNuevo = new \App\EventosCalendario([
    'fecha'       => $fechaSeleccionada,
    'importancia' => 'medio',
]);

/** Eventos del día seleccionado (el panel de la derecha). */
$eventosDia = $fechaSeleccionada !== '' ? ($porDia[$fechaSeleccionada] ?? []) : [];

/** Agenda inferior: todos los días del mes que tienen eventos, en orden. */
$agenda = $porDia;
ksort($agenda);

template('header', [
    'titulo'    => 'Calendario',
    'subtitulo' => 'Herramientas personales',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Recordatorios y eventos del equipo. Elige un día del calendario para
            ver lo que hay en esa fecha o para capturar uno nuevo.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="<?= escaparValores($urlMes((int) $hoy->format('Y'), (int) $hoy->format('n'))) ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">today</span>
            <span>Hoy</span>
        </a>
    </div>
</section>

<!-- Resumen del mes -->
<section class="rejilla-stats mb-lg" aria-label="Resumen del mes">
    <div class="stat">
        <span class="stat__etiqueta">Eventos del mes</span>
        <span class="stat__valor"><?= number_format($resumen['eventos']) ?></span>
        <span class="stat__pie"><?= escaparValores(nombreMes($mes)) . ' ' . $anio ?></span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Con hora</span>
        <span class="stat__valor"><?= number_format($resumen['con_hora']) ?></span>
        <span class="stat__pie">Con hora fija</span>
    </div>
    <div class="stat">
        <span class="stat__etiqueta">Importancia alta</span>
        <span class="stat__valor"><?= number_format($resumen['alta']) ?></span>
        <span class="stat__pie">Los más urgentes</span>
    </div>
</section>

<div class="calendario-cuerpo">
    <!-- Calendario del mes activo -->
    <section class="tarjeta calendario" aria-label="Calendario de <?= escaparValores(nombreMes($mes)) . ' ' . $anio ?>">
        <header class="calendario__cabecera">
            <h2 class="calendario__titulo">
                <?= escaparValores(nombreMes($mes)) ?> <span class="calendario__anio"><?= $anio ?></span>
            </h2>

            <nav class="calendario__navegacion" aria-label="Navegar el calendario">
                <a class="btn btn--secundario btn--sm btn--icono"
                   href="<?= escaparValores($urlMes((int) $anteriorAnio->format('Y'), $mes)) ?>"
                   aria-label="Año anterior" title="Año anterior">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">keyboard_double_arrow_left</span>
                </a>
                <a class="btn btn--secundario btn--sm btn--icono"
                   href="<?= escaparValores($urlMes((int) $anterior->format('Y'), (int) $anterior->format('n'))) ?>"
                   aria-label="Mes anterior" title="Mes anterior">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">chevron_left</span>
                </a>
                <span class="calendario__periodo" aria-live="polite">
                    <?= escaparValores(nombreMes($mes)) ?> de <?= $anio ?>
                </span>
                <a class="btn btn--secundario btn--sm btn--icono"
                   href="<?= escaparValores($urlMes((int) $siguiente->format('Y'), (int) $siguiente->format('n'))) ?>"
                   aria-label="Mes siguiente" title="Mes siguiente">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">chevron_right</span>
                </a>
                <a class="btn btn--secundario btn--sm btn--icono"
                   href="<?= escaparValores($urlMes((int) $siguienteAnio->format('Y'), $mes)) ?>"
                   aria-label="Año siguiente" title="Año siguiente">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">keyboard_double_arrow_right</span>
                </a>
            </nav>
        </header>

        <div class="calendario__rejilla" role="group" aria-label="Días de <?= escaparValores(nombreMes($mes)) ?> de <?= $anio ?>">
            <?php foreach ($diasSemana as $diaSemana) : ?>
                <span class="calendario__semana" aria-hidden="true"><?= $diaSemana ?></span>
            <?php endforeach; ?>

            <?php for ($celda = 0; $celda < $celdasTotales; $celda++) :
                $numeroDia = $celda - $desplazamiento + 1;

                if ($numeroDia < 1 || $numeroDia > $ultimoDia) : ?>
                    <span class="calendario__dia calendario__dia--fuera" aria-hidden="true"></span>
                <?php continue;
                endif;

                $fechaCelda  = sprintf('%04d-%02d-%02d', $anio, $mes, $numeroDia);
                $cantidad    = $conteoPorDia[$fechaCelda] ?? 0;
                $negocio     = $marcadoresPorDia[$fechaCelda] ?? [];
                $esHoy       = $fechaCelda === $hoy->format('Y-m-d');
                $esSeleccion = $fechaCelda === $fechaSeleccionada;

                $clases  = 'calendario__dia';
                $clases .= $esHoy ? ' calendario__dia--hoy' : '';
                $clases .= $esSeleccion ? ' calendario__dia--seleccionado' : '';
                $clases .= $cantidad > 0 ? ' calendario__dia--con-eventos' : '';

                $etiquetaDia = $fechaAmable($fechaCelda);
                if ($negocio !== []) {
                    $etiquetaDia .= ', ' . $marcadoresTexto($negocio);
                }
                $etiquetaDia .= $cantidad > 0
                    ? ', ' . $cantidad . ($cantidad === 1 ? ' evento' : ' eventos')
                    : ', sin eventos';
                ?>
                <a class="<?= $clases ?>"
                   href="/admin/calendario?anio=<?= $anio ?>&mes=<?= $mes ?>&dia=<?= $numeroDia ?>"
                   aria-label="<?= escaparValores($etiquetaDia) ?>"
                   <?= $esSeleccion ? 'aria-current="date"' : '' ?>>
                    <span class="calendario__numero"><?= $numeroDia ?></span>
                    <?= $iconosCelda($negocio) ?>
                    <?php if ($cantidad > 0) : ?>
                        <span class="calendario__puntos" aria-hidden="true">
                            <?= str_repeat(
                                '<span class="calendario__punto calendario__punto--' . escaparValores($importanciaPorDia[$fechaCelda] ?? 'bajo') . '"></span>',
                                min(3, $cantidad)
                            ) ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endfor; ?>
        </div>

        <footer class="calendario__leyenda">
            <span><span class="calendario__punto calendario__punto--bajo" aria-hidden="true"></span> Bajo</span>
            <span><span class="calendario__punto calendario__punto--medio" aria-hidden="true"></span> Medio</span>
            <span><span class="calendario__punto calendario__punto--alto" aria-hidden="true"></span> Alto</span>
            <span class="texto-secundario">El punto del día marca el nivel más alto que tiene.</span>
        </footer>

        <footer class="calendario__leyenda" aria-label="Marcadores del negocio">
            <?php foreach (\App\EventosCalendario::TIPOS_NEGOCIO as $datos) : ?>
                <span title="<?= escaparValores($datos['titulo']) ?>">
                    <span class="calendario__icono calendario__icono--<?= $datos['color'] ?>" aria-hidden="true">
                        <span class="material-symbols-outlined"><?= $datos['icono'] ?></span>
                    </span>
                    <?= escaparValores($datos['etiqueta']) ?>
                </span>
            <?php endforeach; ?>
            <span class="texto-secundario">Lo que pasó en tus negocios ese día. No se pueden editar ni borrar.</span>
        </footer>
    </section>

    <!-- Día seleccionado: sus eventos y el alta del nuevo -->
    <aside class="calendario-panel" aria-label="Día seleccionado">
        <?php if ($fechaSeleccionada === '') : ?>
            <div class="tarjeta">
                <div class="vacio">
                    <span class="material-symbols-outlined vacio__icono" aria-hidden="true">touch_app</span>
                    <p class="vacio__titulo">Elige un día</p>
                    <p>Haz clic sobre cualquier casilla del calendario para ver sus eventos o para capturar un recordatorio nuevo.</p>
                </div>
            </div>
        <?php else : ?>
            <?php $negocioDia = $marcadoresPorDia[$fechaSeleccionada] ?? []; ?>

            <?php if ($negocioDia !== []) : ?>
                <!-- Lo que pasó ese día en los negocios (sólo lectura) -->
                <section class="tarjeta calendario-negocio" aria-label="Negocio del <?= escaparValores($fechaAmable($fechaSeleccionada)) ?>">
                    <header class="calendario-negocio__cabecera">
                        <h2 class="calendario-negocio__titulo">Lo que pasó ese día</h2>
                        <span class="badge badge--neutro badge--sin-punto">Sólo lectura</span>
                    </header>

                    <div class="calendario-negocio__resumen">
                        <?php foreach (\App\EventosCalendario::TIPOS_NEGOCIO as $tipo => $datos) :
                            if (empty($negocioDia[$tipo])) {
                                continue;
                            }
                            $n       = (int) $negocioDia[$tipo]['n'];
                            $importe = $negocioDia[$tipo]['importe'];
                            ?>
                            <div class="calendario-negocio__fila">
                                <span class="calendario-negocio__concepto">
                                    <span class="material-symbols-outlined resumen-dia__icono--<?= $datos['color'] ?>"
                                          aria-hidden="true"><?= $datos['icono'] ?></span>
                                    <?= $n ?> <?= $n === 1 ? $datos['etiqueta'] : $datos['plural'] ?>
                                </span>
                                <?php if ($importe !== null) : ?>
                                    <span class="calendario-negocio__cifra"><?= formatoMoneda($importe) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="tarjeta calendario-dia" aria-label="Eventos del <?= escaparValores($fechaAmable($fechaSeleccionada)) ?>">
                <header class="calendario-dia__cabecera">
                    <h2 class="calendario-dia__titulo"><?= escaparValores($fechaAmable($fechaSeleccionada, true)) ?></h2>
                    <span class="badge badge--neutro badge--sin-punto">
                        <?= number_format(count($eventosDia)) ?> <?= count($eventosDia) === 1 ? 'evento' : 'eventos' ?>
                    </span>
                </header>

                <?php if (!empty($eventosDia)) : ?>
                    <div class="calendario-dia__lista">
                        <?php foreach ($eventosDia as $eventoDia) : ?>
                            <div class="calendario-dia__item">
                                <?= $tarjetaEvento($eventoDia, false) ?>
                                <?= $botonEliminar($eventoDia) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="texto-secundario calendario-dia__vacio">
                        No hay nada registrado este día. Captura abajo el primer recordatorio o evento.
                    </p>
                <?php endif; ?>
            </section>

            <section class="tarjeta calendario-alta" aria-label="Nuevo recordatorio o evento">
                <h2 class="titulo-5 mb-md">Nuevo recordatorio o evento</h2>
                <form class="formulario" action="/admin/calendario/crear" method="post" data-validar novalidate>
                    <input type="hidden" name="anio" value="<?= $anio ?>">
                    <input type="hidden" name="mes" value="<?= $mes ?>">
                    <input type="hidden" name="dia" value="<?= $dia ?>">
                    <?php template('formularioCalendario', [
                        'evento'       => $eventoNuevo,
                        'errores'      => [],
                        'rutaCancelar' => '/admin/calendario?anio=' . $anio . '&mes=' . $mes,
                        'idMensaje'    => 'errores-calendario-alta',
                    ]); ?>
                </form>
            </section>
        <?php endif; ?>
    </aside>
</div>

<!-- Agenda completa del mes -->
<section class="tarjeta tarjeta--sin-relleno mt-lg" aria-label="Agenda del mes">
    <div class="calendario-agenda__cabecera">
        <h2 class="titulo-4">Agenda de <?= escaparValores(nombreMes($mes)) ?> de <?= $anio ?></h2>
    </div>

    <?php if (empty($agenda)) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">calendar_month</span>
            <p class="vacio__titulo">Sin eventos este mes</p>
            <p>Elige un día del calendario para capturar el primer recordatorio o evento.</p>
        </div>
    <?php else : ?>
        <div class="calendario-agenda">
            <?php foreach ($agenda as $fechaDia => $eventosDelDia) :
                $numeroDia = (int) substr($fechaDia, 8, 2);
                $esHoy = $fechaDia === $hoy->format('Y-m-d');
                ?>
                <article class="agenda-dia<?= $esHoy ? ' agenda-dia--hoy' : '' ?>">
                    <header class="agenda-dia__cabecera">
                        <a class="agenda-dia__fecha"
                           href="/admin/calendario?anio=<?= $anio ?>&mes=<?= $mes ?>&dia=<?= $numeroDia ?>">
                            <span class="agenda-dia__numero"><?= $numeroDia ?></span>
                            <span class="agenda-dia__nombre">
                                <?= escaparValores($fechaAmable($fechaDia)) ?><?= $esHoy ? ' (hoy)' : '' ?>
                            </span>
                        </a>
                        <span class="badge badge--neutro badge--sin-punto">
                            <?= number_format(count($eventosDelDia)) ?>
                        </span>
                    </header>

                    <div class="agenda-dia__eventos">
                        <?php foreach ($eventosDelDia as $eventoAgenda) : ?>
                            <div class="agenda-dia__item">
                                <?= $tarjetaEvento($eventoAgenda, true) ?>
                                <?= $botonEliminar($eventoAgenda) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>
