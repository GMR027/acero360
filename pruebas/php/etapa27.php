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
 *   · los MARCADORES DEL NEGOCIO (sólo lectura): cada casilla marca ventas
 *     (verde), gastos (rojo), recepciones de proveedor (azul), facturas por
 *     pagar (amarillo) y facturas por cobrar (naranja), y deja de marcar al
 *     cancelarse, pagarse o cobrarse la operación;
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

echo "Marcadores del negocio (sólo lectura)\n";

/**
 * Los marcadores se calculan desde las operaciones reales: se siembra una de
 * cada tipo en un día y se comprueba que aparece, y que desaparece al
 * cancelarse, pagarse o cobrarse. Nada de esto se guarda en calendario_eventos.
 */
$contribuyente = (int) $uno('SELECT MIN(id) FROM contribuyentes WHERE activo = 1');
$empresaMarc   = (int) $uno("SELECT MIN(id) FROM empresas WHERE contribuyente_id = $contribuyente");
$clienteMarc   = (int) $uno('SELECT MIN(id) FROM clientes');
$proveedorMarc = (int) $uno('SELECT MIN(id) FROM proveedores');

$db->query("INSERT INTO proveedores (nombre, activo) VALUES ('T27 Proveedor marcadores', 1)");
$proveedorMarc = (int) $db->insert_id;
$db->query("INSERT INTO clientes (nombre) VALUES ('T27 Cliente marcadores')");
$clienteMarc = (int) $db->insert_id;

$diaMarc = min(20, (int) date('t'));
$fechaMarc = $base . sprintf('%02d', $diaMarc);

/**
 * Día del mes SIN operaciones: se busca uno en vez de fijar un número, porque
 * con datos reales (o de otras pruebas del mismo día) ese día puede tener
 * movimientos y el marcador dejaría de estar vacío.
 */
$fechaVacia = '';
for ($dia = 1; $dia <= (int) date('t'); $dia++) {
    if ($dia === $diaMarc) {
        continue;   // ese día lo usan los marcadores de esta misma prueba
    }
    $candidata = $base . sprintf('%02d', $dia);
    $operaciones = (int) $uno("SELECT
        (SELECT COUNT(*) FROM ventas WHERE fecha = '$candidata') +
        (SELECT COUNT(*) FROM gastos WHERE fecha = '$candidata') +
        (SELECT COUNT(*) FROM recepciones WHERE fecha = '$candidata') +
        (SELECT COUNT(*) FROM proveedor_entradas WHERE fecha = '$candidata') +
        (SELECT COUNT(*) FROM ingresos WHERE fecha = '$candidata')");
    if ($operaciones === 0) {
        $fechaVacia = $candidata;
        break;
    }
}
if ($fechaVacia === '') {
    // Mes lleno de operaciones: se usa el día 1 del mes siguiente (no hay datos ahí).
    $fechaVacia = date('Y-m-d', strtotime($base . '01 +1 month'));
}

// Venta registrada ese día.
$db->query("INSERT INTO ventas (empresa_id, cliente_id, usuario_id, fecha, folio, estado, subtotal, total)
            VALUES ($empresaMarc, $clienteMarc, $usuario, '$fechaMarc', 'T27-M', 'registrada', 500, 500)");
$ventaMarc = (int) $db->insert_id;

// Gasto pagado y gasto por pagar, ambos ese día.
$db->query("INSERT INTO gastos (contribuyente_id, empresa_id, fecha, beneficiario, concepto, subtotal, total,
                                estado_pago, fecha_pago, estado, usuario_id)
            VALUES ($contribuyente, $empresaMarc, '$fechaMarc', 'T27 Proveedor marcadores', 'T27 gasto pagado',
                    300, 300, 'pagado', '$fechaMarc', 'vigente', $usuario)");
$gastoPagado = (int) $db->insert_id;
$db->query("INSERT INTO gastos (contribuyente_id, empresa_id, fecha, beneficiario, concepto, subtotal, total,
                                estado_pago, estado, usuario_id)
            VALUES ($contribuyente, $empresaMarc, '$fechaMarc', 'T27 Proveedor marcadores', 'T27 gasto por pagar',
                    200, 200, 'pendiente', 'vigente', $usuario)");
$gastoPendiente = (int) $db->insert_id;

// Recepción de proveedor: la de una orden de compra y la entrada directa.
$db->query("INSERT INTO ordenes_compra (folio, proveedor_id, fecha_solicitud, contribuyente_id, empresa_id, estado, usuario_id)
            VALUES ('T27-M', $proveedorMarc, '$fechaMarc', $contribuyente, $empresaMarc, 'completa', $usuario)");
$ordenMarc = (int) $db->insert_id;
$db->query("INSERT INTO recepciones (orden_id, fecha, token, usuario_id)
            VALUES ($ordenMarc, '$fechaMarc', MD5('T27-recepcion-marca'), $usuario)");
$recepcionMarc = (int) $db->insert_id;
$db->query("INSERT INTO proveedor_entradas (proveedor_id, fecha, monto, usuario_id)
            VALUES ($proveedorMarc, '$fechaMarc', 150, $usuario)");
$entradaMarc = (int) $db->insert_id;

// Factura de venta emitida, todavía sin cobrar.
$db->query("INSERT INTO ingresos (contribuyente_id, empresa_id, cliente_id, fecha, folio, concepto,
                                  subtotal, total, estado, estado_facturacion)
            VALUES ($contribuyente, $empresaMarc, $clienteMarc, '$fechaMarc', 'T27-M', 'T27 factura por cobrar',
                    1000, 1160, 'vigente', 'facturada')");
$ingresoMarc = (int) $db->insert_id;

$marcadores = EventosCalendario::marcadoresDia($fechaMarc);
$conteo = static fn (string $t): int => (int) ($marcadores[$t]['n'] ?? 0);

$prueba('marca la venta del día (verde)', $conteo('venta') === 1, $conteo('venta'));
$prueba('marca los dos gastos: el pagado y el por pagar (rojo)', $conteo('gasto') === 2, $conteo('gasto'));
$prueba('marca las dos recepciones: la de la orden y la entrada directa (azul)', $conteo('recepcion') === 2, $conteo('recepcion'));
$prueba('marca sólo el gasto sin pagar en «por pagar» (amarillo)', $conteo('por_pagar') === 1, $conteo('por_pagar'));
$prueba('marca la factura sin cobrar en «por cobrar» (naranja)', $conteo('por_cobrar') === 1, $conteo('por_cobrar'));
$prueba('un día sin operaciones no trae marcadores', EventosCalendario::marcadoresDia($fechaVacia) === []);

$prueba('el importe de la venta es la suma del día',
    abs((float) ($marcadores['venta']['importe'] ?? -1) - 500.0) < 0.005, (string) ($marcadores['venta']['importe'] ?? 'falta'));
$prueba('el «por cobrar» muestra lo que falta por cobrar (1,160)',
    abs((float) ($marcadores['por_cobrar']['importe'] ?? -1) - 1160.0) < 0.005, (string) ($marcadores['por_cobrar']['importe'] ?? 'falta'));
$prueba('los cinco tipos están en el catálogo, en el orden de la leyenda',
    array_keys(EventosCalendario::TIPOS_NEGOCIO) === ['venta', 'gasto', 'recepcion', 'por_pagar', 'por_cobrar']);

echo "  Marcadores al cambiar el estado de la operación\n";
$db->query("UPDATE gastos SET estado_pago = 'pagado', fecha_pago = '$fechaMarc' WHERE id = $gastoPendiente");
$db->query("INSERT INTO cobros (ingreso_id, fecha, importe, metodo, estado, usuario_id)
            VALUES ($ingresoMarc, '$fechaMarc', 1160, 'Efectivo', 'vigente', $usuario)");
EventosCalendario::olvidarMarcadores();
$marcadores = EventosCalendario::marcadoresDia($fechaMarc);
$prueba('al pagarse el gasto deja de estar «por pagar»', (int) ($marcadores['por_pagar']['n'] ?? 0) === 0, (string) ($marcadores['por_pagar']['n'] ?? 0));
$prueba('pero sigue contando como gasto del día', (int) ($marcadores['gasto']['n'] ?? 0) === 2, (string) ($marcadores['gasto']['n'] ?? 0));
$prueba('al cobrarse la factura deja de estar «por cobrar»', (int) ($marcadores['por_cobrar']['n'] ?? 0) === 0, (string) ($marcadores['por_cobrar']['n'] ?? 0));

$db->query("UPDATE ventas SET estado = 'cancelada' WHERE id = $ventaMarc");
EventosCalendario::olvidarMarcadores();
$prueba('una venta cancelada deja de marcar (verde)', (int) (EventosCalendario::marcadoresDia($fechaMarc)['venta']['n'] ?? 0) === 0);

$db->query("UPDATE gastos SET estado = 'cancelado' WHERE id = $gastoPagado");
$db->query("DELETE FROM cobros WHERE ingreso_id = $ingresoMarc");
EventosCalendario::olvidarMarcadores();
$marcadores = EventosCalendario::marcadoresDia($fechaMarc);
$prueba('un gasto cancelado deja de marcar (rojo)', (int) ($marcadores['gasto']['n'] ?? 0) === 1, (string) ($marcadores['gasto']['n'] ?? 0));

// Limpieza de todo lo sembrado para esta sección.
$db->query("DELETE FROM recepciones WHERE id = $recepcionMarc");
$db->query("DELETE FROM ordenes_compra WHERE id = $ordenMarc");
$db->query("DELETE FROM proveedor_entradas WHERE id = $entradaMarc");
$db->query("DELETE FROM ingresos WHERE id = $ingresoMarc");
$db->query("DELETE FROM gastos WHERE id IN ($gastoPagado, $gastoPendiente)");
$db->query("DELETE FROM ventas WHERE id = $ventaMarc");
$db->query("DELETE FROM proveedores WHERE id = $proveedorMarc");
$db->query("DELETE FROM clientes WHERE id = $clienteMarc");
EventosCalendario::olvidarMarcadores();

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
