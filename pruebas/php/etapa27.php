<?php
/**
 * pruebas/php/etapa27.php — Herramienta «Calendario»: recordatorios y eventos.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa27.php
 *
 * Comprueba:
 *   · que la migración 028 creó la tabla y que la clase quedó conectada;
 *   · validaciones (nombre, fecha, hora e importancia);
 *   · alta con y sin negocio y con y sin hora (todo el día);
 *   · lecturas del módulo: eventos del mes, conteo por día y resumen;
 *   · edición, cambio de día y baja;
 *   · que un negocio inexistente NO guarda (clave foránea);
 *   · enlace en el menú lateral, en el cajón del celular y rutas registradas.
 *
 * Los nombres llevan «T27» para no chocar con los de las otras etapas.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\EventosCalendario;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$empresa = (int) $uno('SELECT MIN(id) FROM empresas');

$db->query("DELETE FROM calendario_eventos WHERE nombre LIKE 'T27 %'");

echo "Migración 028\n";
$anio = (int) date('Y');
$mes  = (int) date('n');
$prueba('existe calendario_eventos', (int) $db->query("SHOW TABLES LIKE 'calendario_eventos'")->num_rows === 1);
$prueba('la clase quedó conectada', is_array(EventosCalendario::contarPorDia($anio, $mes)));

// Días con ceros a la izquierda: así arma fechas válidas 'Y-m-d'.
$dias = array_map(
    static fn (int $d): string => sprintf('%02d', $d),
    [min(3, (int) date('t')), min(8, (int) date('t')), min(14, (int) date('t'))]
);
$base = sprintf('%04d-%02d-', $anio, $mes);

echo "Validaciones\n";
$sinNombre = new EventosCalendario(['usuario_id' => $usuario, 'fecha' => $base . $dias[0], 'nombre' => '   ']);
$prueba('el nombre es obligatorio', in_array('El nombre del evento es obligatorio.', $sinNombre->validar(), true));

$fechaMala = new EventosCalendario(['usuario_id' => $usuario, 'fecha' => '2026-13-45', 'nombre' => 'T27 fecha']);
$prueba('rechaza una fecha inexistente', in_array('La fecha del evento no es válida.', $fechaMala->validar(), true));

$horaMala = new EventosCalendario([
    'usuario_id' => $usuario, 'fecha' => $base . $dias[0], 'nombre' => 'T27 hora', 'hora' => '25:99:99',
]);
$prueba('rechaza una hora inválida', in_array('La hora del evento no es válida.', $horaMala->validar(), true));
$prueba('distingue una hora real de una imposible',
    EventosCalendario::horaValida('10:30') && EventosCalendario::horaValida('23:59:59')
    && !EventosCalendario::horaValida('25:99:99') && !EventosCalendario::horaValida('7:5'));

$importanciaMala = new EventosCalendario([
    'usuario_id' => $usuario, 'fecha' => $base . $dias[0], 'nombre' => 'T27 nivel', 'importancia' => 'critico',
]);
$prueba('sólo admite bajo / medio / alto', in_array('El nivel de importancia seleccionado no es válido.', $importanciaMala->validar(), true));

echo "Alta\n";
$conHora = new EventosCalendario([
    'usuario_id'  => $usuario,
    'fecha'       => $base . $dias[0],
    'hora'        => '10:30:00',
    'nombre'      => 'T27 Visita con cliente',
    'descripcion' => 'Llevar muestras',
    'empresa_id'  => $empresa,
    'importancia' => 'alto',
]);
$prueba('valida sin errores', !$conHora->validar(), implode('|', $conHora->validar()));
$prueba('guarda el evento', $conHora->crear() && (int) $conHora->id > 0);
$prueba('persiste en la BD', (int) $uno('SELECT COUNT(*) FROM calendario_eventos WHERE id=' . (int) $conHora->id) === 1);

$todoElDia = new EventosCalendario([
    'usuario_id'  => $usuario,
    'fecha'       => $base . $dias[0],
    'nombre'      => 'T27 Recordatorio sin hora',
    'importancia' => 'bajo',
]);
$prueba('acepta un evento de todo el día (sin hora)', $todoElDia->crear());
$prueba('el negocio queda NULL cuando no se elige', $uno('SELECT empresa_id FROM calendario_eventos WHERE id=' . (int) $todoElDia->id) === null);
$prueba('la hora vacía se guarda NULL, no 00:00:00', $uno('SELECT hora FROM calendario_eventos WHERE id=' . (int) $todoElDia->id) === null);

$otroDia = new EventosCalendario([
    'usuario_id'  => $usuario,
    'fecha'       => $base . $dias[1],
    'hora'        => '18:00',
    'nombre'      => 'T27 Cierre de mes',
    'importancia' => 'medio',
]);
$prueba('guarda un segundo evento', $otroDia->crear());

$negocioFalso = new EventosCalendario([
    'usuario_id' => $usuario, 'fecha' => $base . $dias[2],
    'nombre' => 'T27 negocio inexistente', 'empresa_id' => 999999,
]);
$prueba('no guarda con un negocio inexistente', !$negocioFalso->crear());

echo "Lecturas\n";
$eventos = EventosCalendario::listarMes($anio, $mes);
$ids     = array_map(fn ($e) => (int) $e->id, $eventos);
$prueba('lista los eventos del mes', in_array((int) $conHora->id, $ids, true));
$prueba('no mezcla otros meses', count(array_filter($eventos, fn ($e) => !str_starts_with($e->fecha, $base))) === 0);

$conId = array_values(array_filter($eventos, fn ($e) => (int) $e->id === (int) $conHora->id))[0] ?? null;
$prueba('trae el nombre del negocio', $conId !== null && trim((string) $conId->negocio) !== '');
$prueba('trae el autor de la captura', $conId !== null && trim((string) $conId->autor) !== '');

$conteo = EventosCalendario::contarPorDia($anio, $mes);
$prueba('cuenta los eventos por día', (int) ($conteo[$base . $dias[0]] ?? 0) === 2, json_encode($conteo));

$resumen = EventosCalendario::resumenMes($anio, $mes);
$prueba('resume el mes (eventos)', $resumen['eventos'] >= 2, json_encode($resumen));
$prueba('resume el mes (con hora)', $resumen['con_hora'] >= 2);
$prueba('resume el mes (importancia alta)', $resumen['alta'] >= 1);

echo "Edición y baja\n";
$edicion = EventosCalendario::buscarID($conHora->id);
$prueba('se relee por id', $edicion !== null);

$edicion->nombre      = 'T27 Visita reprogramada';
$edicion->importancia = 'bajo';
$edicion->fecha       = $base . $dias[2];
$edicion->hora        = '';
$edicion->empresa_id  = null;
$prueba('actualiza los datos', $edicion->actualizar());
$prueba('el cambio se refleja en la BD', $uno("SELECT nombre FROM calendario_eventos WHERE id=" . (int) $conHora->id) === 'T27 Visita reprogramada');
$prueba('el cambio de día se nota en el conteo', (int) (EventosCalendario::contarPorDia($anio, $mes)[$base . $dias[2]] ?? 0) >= 1);

$rereleo = EventosCalendario::buscarID($conHora->id);
$prueba('al releer perdió la hora y el negocio', $rereleo !== null && trim((string) $rereleo->hora) === '' && trim((string) $rereleo->negocio) === '');
$prueba('conserva el nivel de importancia', $rereleo !== null && $rereleo->importancia === 'bajo');

$prueba('elimina el evento', EventosCalendario::buscarID($conHora->id)->eliminar());
$prueba('ya no está en la BD', (int) $uno('SELECT COUNT(*) FROM calendario_eventos WHERE id=' . (int) $conHora->id) === 0);
$prueba('relee un id inexistente como null', EventosCalendario::buscarID(99999999) === null);

echo "Integración\n";
$menu = file_get_contents(__DIR__ . '/../../templates/sidebar.php');
$prueba('el menú lateral tiene «Calendario»', is_string($menu) && str_contains($menu, "'/admin/calendario'") && str_contains($menu, "'Calendario'"));
$prueba('el menú lo pone en «Herramientas personales»', is_string($menu)
    && strpos($menu, "'grupo'         => 'Herramientas personales'") !== false
    && strpos($menu, "'grupo'         => 'Herramientas personales'") < strpos($menu, "'/admin/calendario'"));
$prueba('usa el icono de calendario', is_string($menu) && str_contains($menu, "'calendar_month'"));

$barraInferior = file_get_contents(__DIR__ . '/../../templates/bottomnav.php');
$prueba('el cajón de móvil lo marca como activo', is_string($barraInferior) && str_contains($barraInferior, '/admin/calendario'));

$rutas = file_get_contents(__DIR__ . '/../../index.php');
$prueba('registra la ruta del calendario', is_string($rutas) && str_contains($rutas, "'/admin/calendario'"));
$prueba('registra el alta por POST', is_string($rutas) && str_contains($rutas, "'/admin/calendario/crear'"));
$prueba('registra la edición y la baja por POST', is_string($rutas) && str_contains($rutas, "'/admin/calendario/editar'") && str_contains($rutas, "'/admin/calendario/accion'"));
$prueba('toda ruta nueva lleva soloAdmin', is_string($rutas) && preg_match_all("#/admin/calendario[^']*'\s*,\s*'[^']*'\s*,\s*'soloAdmin'#", $rutas) >= 4);

$db->query("DELETE FROM calendario_eventos WHERE nombre LIKE 'T27 %'");

echo $fallos ? "\nResultado: $fallos fallos\n" : "\nResultado: TODO OK\n";
exit($fallos ? 1 : 0);
