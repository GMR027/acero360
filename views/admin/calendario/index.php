<?php
/**
 * views/admin/calendario/index.php — Tablero del módulo «Calendario».
 *
 * Herramienta personal compartida: un calendario del mes activo (a todo el
 * ancho) con botones para navegar entre meses y años, y debajo la AGENDA del
 * mes, que lista día por día lo que pasó en los negocios y los recordatorios
 * capturados.
 *
 * Cada casilla marca con un icono de color lo que pasó ese día
 * (EventosCalendario::marcadoresNegocio): venta (verde), gasto (rojo),
 * recepción de proveedor (azul), factura por pagar (amarillo) y factura por
 * cobrar (naranja). Son de SÓLO LECTURA: no se guardan en calendario_eventos,
 * se calculan al vuelo y no se pueden editar. Usan Material Icons (el set
 * clásico, estilo relleno), que va cargado aparte en templates/header.php.
 *
 * ALTA: se abre en ventana modal haciendo clic una segunda vez sobre la
 * casilla ya elegida, o con el botón de la cabecera. El formulario vive oculto
 * al final de la página y el módulo del calendario lo mueve al modal.
 *
 * VER: el botón con icono (ojo) de cada tarjeta de la Agenda abre el detalle
 * de ese día en modal; sus datos viajan en el atributo data-agenda-dia.
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
            . '<span class="material-icons">' . $datos['icono'] . '</span></span>';
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
        <button type="button" class="btn btn--primario" data-alta-calendario
                data-fecha="<?= escaparValores($fechaSeleccionada !== '' ? $fechaSeleccionada : $hoy->format('Y-m-d')) ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>
            <span>Nuevo recordatorio o evento</span>
        </button>
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
                   data-fecha="<?= $fechaCelda ?>"
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

        <footer class="calendario__leyenda" aria-label="Leyenda">
            <span><span class="calendario__punto calendario__punto--bajo" aria-hidden="true"></span> Bajo</span>
            <span><span class="calendario__punto calendario__punto--medio" aria-hidden="true"></span> Medio</span>
            <span><span class="calendario__punto calendario__punto--alto" aria-hidden="true"></span> Alto</span>
            <?php foreach (\App\EventosCalendario::TIPOS_NEGOCIO as $datos) : ?>
                <span title="<?= escaparValores($datos['titulo']) ?>">
                    <span class="calendario__icono calendario__icono--<?= $datos['color'] ?>" aria-hidden="true">
                        <span class="material-icons"><?= $datos['icono'] ?></span>
                    </span>
                    <?= escaparValores($datos['etiqueta']) ?>
                </span>
            <?php endforeach; ?>
            <span class="texto-secundario">El punto marca la importancia de un recordatorio; el icono, lo que pasó en los negocios ese día.</span>
        </footer>
    </section>

</div>

<!-- Agenda del mes: día por día, lo que pasó en los negocios y los recordatorios -->
<section class="tarjeta tarjeta--sin-relleno mt-lg" aria-label="Agenda del mes">
    <div class="calendario-agenda__cabecera">
        <h2 class="titulo-4">Agenda de <?= escaparValores(nombreMes($mes)) ?> de <?= $anio ?></h2>
        <p class="texto-secundario">Cada día con movimiento, en orden. «Ver» abre el detalle del día.</p>
    </div>

    <?php
    // Un solo listado por fecha: los días con operaciones del negocio y los que
    // tienen recordatorios, en orden.
    $fechasAgenda = array_unique(array_merge(array_keys($porDia), array_keys($marcadoresPorDia)));
    sort($fechasAgenda);
    ?>

    <?php if (!$fechasAgenda) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">calendar_month</span>
            <p class="vacio__titulo">Sin movimiento este mes</p>
            <p>Cuando haya ventas, gastos o recordatorios aparecerán aquí, día por día.</p>
        </div>
    <?php else : ?>
        <div class="calendario-agenda">
            <?php foreach ($fechasAgenda as $fechaDia) :
                $eventosDelDia = $porDia[$fechaDia] ?? [];
                $negocioDelDia = $marcadoresPorDia[$fechaDia] ?? [];
                $numeroDia = (int) substr($fechaDia, 8, 2);
                $esHoy = $fechaDia === $hoy->format('Y-m-d');
                $esElegido = $fechaDia === $fechaSeleccionada;

                // Lo que muestra el botón «Ver»: se guarda en la propia tarjeta
                // y el módulo del calendario lo lee al abrir el modal.
                $filasNegocio = [];
                foreach (\App\EventosCalendario::TIPOS_NEGOCIO as $tipo => $datos) {
                    if (empty($negocioDelDia[$tipo])) {
                        continue;
                    }
                    $filasNegocio[] = [
                        'tipo'     => $tipo,
                        'etiqueta' => $datos['etiqueta'],
                        'plural'   => $datos['plural'],
                        'icono'    => $datos['icono'],
                        'color'    => $datos['color'],
                        'n'        => (int) $negocioDelDia[$tipo]['n'],
                        'importe'  => $negocioDelDia[$tipo]['importe'],
                    ];
                }
                $detalleDia = json_encode([
                    'fecha'      => $fechaDia,
                    'titulo'     => $fechaAmable($fechaDia, true),
                    'hoy'        => $esHoy,
                    'negocio'    => $filasNegocio,
                    'eventos'    => count($eventosDelDia),
                    'rutaDia'    => '/admin/calendario?anio=' . $anio . '&mes=' . $mes . '&dia=' . $numeroDia,
                ], JSON_UNESCAPED_UNICODE);
                ?>
                <article class="agenda-dia<?= $esHoy ? ' agenda-dia--hoy' : '' ?><?= $esElegido ? ' agenda-dia--elegido' : '' ?>"
                         id="agenda-<?= $fechaDia ?>" data-agenda-dia="<?= escaparValores($detalleDia) ?>">
                    <header class="agenda-dia__cabecera">
                        <span class="agenda-dia__fecha">
                            <span class="agenda-dia__numero"><?= $numeroDia ?></span>
                            <span class="agenda-dia__nombre">
                                <?= escaparValores($fechaAmable($fechaDia)) ?><?= $esHoy ? ' (hoy)' : '' ?>
                            </span>
                        </span>
                        <button type="button" class="btn btn--secundario btn--sm btn--icono" data-ver-dia
                                aria-label="Ver el detalle de <?= escaparValores($fechaAmable($fechaDia)) ?>"
                                title="Ver el detalle del día">
                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.25rem">visibility</span>
                        </button>
                    </header>

                    <?php if ($filasNegocio !== []) : ?>
                        <div class="agenda-dia__negocio">
                            <?php foreach ($filasNegocio as $fila) : ?>
                                <span class="agenda-dia__marcador" title="<?= escaparValores($fila['n'] . ' ' . ($fila['n'] === 1 ? $fila['etiqueta'] : $fila['plural'])) ?>">
                                    <span class="material-icons agenda-dia__icono agenda-dia__icono--<?= $fila['color'] ?>"
                                          aria-hidden="true"><?= $fila['icono'] ?></span>
                                    <span class="agenda-dia__cifra">
                                        <strong><?= number_format($fila['n']) ?></strong>
                                        <?= $fila['n'] === 1 ? $fila['etiqueta'] : $fila['plural'] ?>
                                        <?php if ($fila['importe'] !== null) : ?>
                                            <span class="agenda-dia__importe"><?= formatoMoneda($fila['importe']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($eventosDelDia !== []) : ?>
                        <div class="agenda-dia__eventos">
                            <?php foreach ($eventosDelDia as $eventoAgenda) : ?>
                                <div class="agenda-dia__item">
                                    <?= $tarjetaEvento($eventoAgenda, true) ?>
                                    <?= $botonEliminar($eventoAgenda) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Formulario de alta: vive OCULTO en la página y el módulo del calendario lo
     mueve al modal cuando se pide crear un evento (clic sobre el día ya
     elegido o el botón del panel). Se mueve y no se copia para que los id de
     los campos sigan siendo únicos y la validación en cliente siga igual. -->
<div data-alta-calendario-contenedor hidden>
    <form class="formulario" action="/admin/calendario/crear" method="post" data-validar novalidate
          data-formulario-alta>
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
</div>

<?php template('footer'); ?>
