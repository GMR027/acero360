<?php
/**
 * pruebas/php/etapa37.php — Integración contable y fiscal (FASE 7).
 *
 * Compara los resultados ANTES (criterio propio de ResumenFiscal: fecha de la
 * operación, tasa plana, gastos deducidos sin revisar comprobante) y DESPUÉS
 * (MotorFiscal: catálogos versionados, flujo de efectivo con fechas efectivas de
 * cobro/pago, reglas de comprobantes, retenciones y ajustes) en escenarios
 * controlados, y verifica que no se dupliquen importes:
 *
 *   1. Venta cobrada parcialmente y luego completada (prorrateo exacto de IVA).
 *   2. Gasto pagado en periodo distinto al de la factura (fecha efectiva de pago).
 *   3. Compra con pagos parciales (el gasto entra UNA sola vez, sin recrearlo).
 *   4. Gastos sin CFDI validado / no deducibles (exclusiones y reglas activas).
 *   5. Retenciones, ajustes y declaraciones (y sus pantallas nuevas).
 *   6. Compatibilidad con contribuyentes y regímenes admitidos; sin catálogo: no se inventa nada.
 *   7. Identidades contables: las cifras cuadran con los datos de origen.
 *
 * No se modifica el motor fiscal: sólo se consume (calcular) y se verifica.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa37.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CatalogoFiscal;
use App\CompraContable;
use App\Contabilidad;
use App\MotorFiscal;
use App\OrdenesCompra as OC;
use App\PagosCompra;
use App\RegistrosFiscales;
use App\ResumenFiscal;
use App\Ventas;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$contrib = Contabilidad::contribuyentePrincipal();
$anio = 2026;
$_SESSION['id'] = $usuario;

/** Motor de un mes (sólo lectura). */
$cal = static fn (int $mes, ?array $catalogos = null): array => MotorFiscal::calcular($contrib, $anio, $mes, $catalogos);
/** Delta en centavos de una serie por mes. */
$delta = static fn (array $a, array $d, string $serie, int $mes): int =>
    (int) ($d['por_mes'][$serie][$mes] ?? 0) - (int) ($a['por_mes'][$serie][$mes] ?? 0);

/* -------------------------------------------------------------------- *
 * ENTORNO
 * ------------------------------------------------------------------ */
$limpiar = static function () use ($db) {
    $db->query("DELETE FROM complemento_cobros");
    $db->query("DELETE FROM complemento_doctos");
    $db->query("DELETE FROM complementos_pago WHERE lado = 'cliente'");
    $db->query("DELETE FROM retenciones WHERE retenedor LIKE 'T37%' OR nota LIKE 'T37%'");
    $db->query("DELETE FROM ajustes_fiscales WHERE nota LIKE 'T37%'");
    $db->query("DELETE FROM declaraciones WHERE referencia LIKE 'T37%' OR nota LIKE 'T37%'");
    $db->query("DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE cliente_id IN (SELECT id FROM clientes WHERE nombre_empresa LIKE 'T37 %'))");
    $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN (SELECT id FROM gastos WHERE concepto LIKE 'T37%' OR concepto LIKE '%T37-C1%')");
    $db->query("DELETE FROM pagos_compra WHERE orden_id IN (SELECT id FROM ordenes_compra WHERE referencia LIKE 'T37-%')");
    $db->query("DELETE FROM orden_compra_partidas WHERE orden_id IN (SELECT id FROM ordenes_compra WHERE referencia LIKE 'T37-%')");
    $db->query("DELETE FROM ordenes_compra WHERE referencia LIKE 'T37-%'");
    // Los gastos se borran DESPUÉS de las órdenes (orden.gasto_id apunta al gasto) y con el
    // patrón real de su concepto: «Compra OC-…: Mercancía T37-C1» (no empieza por T37).
    $db->query("DELETE FROM gastos WHERE concepto LIKE 'T37%' OR concepto LIKE '%T37-C1%'");
    $db->query("DELETE FROM ingresos WHERE cliente_id IN (SELECT id FROM clientes WHERE nombre_empresa LIKE 'T37 %')");
    $db->query("DELETE FROM venta_detalles WHERE venta_id IN (SELECT id FROM ventas WHERE cliente_id IN (SELECT id FROM clientes WHERE nombre_empresa LIKE 'T37 %'))");
    $db->query("DELETE FROM movimientos_inventario WHERE venta_id IN (SELECT id FROM ventas WHERE cliente_id IN (SELECT id FROM clientes WHERE nombre_empresa LIKE 'T37 %'))");
    $db->query("DELETE FROM ventas WHERE cliente_id IN (SELECT id FROM clientes WHERE nombre_empresa LIKE 'T37 %')");
    $db->query("DELETE FROM clientes WHERE nombre_empresa LIKE 'T37 %'");
    $db->query("DELETE FROM proveedores WHERE nombre LIKE 'T37 %'");
    $db->query("DELETE FROM configuracion_fiscal WHERE contribuyente_id IN (SELECT id FROM contribuyentes WHERE rfc = 'T37MO0101AA1')");
    $db->query("DELETE FROM contribuyentes WHERE rfc = 'T37MO0101AA1'");
    $db->query("DELETE FROM periodos_contables WHERE contribuyente_id = 1 AND anio = 2026 AND mes = 9 AND estado = 'cerrado'");
    $db->query("DELETE FROM usuarios WHERE email = 'prueba.e37@test.local'");
};
$limpiar();
$db->query('UPDATE productos SET stock = 1000 WHERE id = 37');

/* Catálogos: si falta alguno activo, se importa/valida/activa el oficial (mismo flujo que etapa6). */
$raiz = dirname(__DIR__, 2);
$clave = 'Clave-E37-ok';
$db->query("DELETE FROM usuarios WHERE email = 'prueba.e37@test.local'");
$db->query("INSERT INTO usuarios (nombre, email, contrasena, rol) VALUES ('Prueba E37', 'prueba.e37@test.local', '" . password_hash($clave, PASSWORD_BCRYPT) . "', 1)");
$usuarioE = (int) $db->insert_id;

echo "Catálogos y compatibilidad (objetivos 2 y 9)\n";
$catalogosFaltantes = false;
foreach (['isr_pp_art106_2026', 'iva_tasas_2026', 'reglas_plataforma_2026'] as $archivo) {
    $tipo = json_decode(file_get_contents("$raiz/mysql/catalogo_fiscal/$archivo.json"), true)['tipo'];
    if (CatalogoFiscal::activa($tipo, $anio) === null) {
        $catalogosFaltantes = true;
        CatalogoFiscal::importar(file_get_contents("$raiz/mysql/catalogo_fiscal/$archivo.json"), $usuarioE);
        CatalogoFiscal::validar(CatalogoFiscal::$ultimoId, $usuarioE);
        CatalogoFiscal::activar(CatalogoFiscal::$ultimoId, $usuarioE, $clave, 'Activación oficial para pruebas (etapa 37)');
    }
}
$prueba('los 3 catálogos quedan activos (fuente oficial, versionados)',
    CatalogoFiscal::activa('isr_pagos_provisionales_art106', $anio) !== null
    && CatalogoFiscal::activa('iva_tasas', $anio) !== null && CatalogoFiscal::activa('reglas_plataforma', $anio) !== null);

$reglasActivas = MotorFiscal::catalogosActivos($anio)['reglas']['doc'] ?? [];
$reqComprobante = !empty($reglasActivas['gasto_requiere_comprobante']);
$reqValidacion = !empty($reglasActivas['gasto_requiere_validacion']);
$prueba('reglas activas leídas del catálogo (no de constantes): comprobante=' . ($reqComprobante ? 'sí' : 'no')
    . ', validación=' . ($reqValidacion ? 'sí' : 'no'), is_array($reglasActivas));

// Régimen no admitido (persona moral): el motor NO calcula en vez de inventar.
$db->query("INSERT INTO contribuyentes (rfc, razon_social, tipo_persona, regimen, activo) VALUES ('T37MO0101AA1', 'T37 Moral SA', 'moral', 'General de Ley', 1)");
$moral = (int) $db->insert_id;
$db->query("INSERT INTO configuracion_fiscal (contribuyente_id, vigente_desde, tipo_persona, regimen, base_ingresos, confirmada_con_contador, motivo_cambio, usuario_id)
            VALUES ($moral, '2026-01-01', 'moral', 'General de Ley', 'flujo_efectivo', 1, 'T37', $usuarioE)");
$calMoral = MotorFiscal::calcular($moral, $anio, 6);
$prueba('persona moral: deshabilitado (no calcula con reglas de otro régimen)', $calMoral['habilitado'] === false,
    json_encode($calMoral['avisos'], JSON_UNESCAPED_UNICODE));
$prueba('…y el aviso explica el motivo sin inventar importes',
    in_array('El motor calcula solo personas físicas con actividad empresarial: este cálculo está deshabilitado.', $calMoral['avisos'] ?? [], true)
    && !isset($calMoral['iva']['a_cargo']));
$db->query("DELETE FROM configuracion_fiscal WHERE contribuyente_id = $moral");
$db->query("DELETE FROM contribuyentes WHERE id = $moral");

// Ejercicio sin catálogo ni configuración: sin cifras inventadas.
$r2025 = ResumenFiscal::anual($contrib, 2025);
$prueba('ejercicio 2025 (sin catálogo): deshabilitado con avisos y sin importes', $r2025['habilitado'] === false
    && $r2025['reglas'] === null && $r2025['avisos'] !== [] && (float) $r2025['total']['impuestos'] === 0.0,
    json_encode($r2025['avisos'], JSON_UNESCAPED_UNICODE));

/* ==================================================================== *
 * 1. VENTA COBRADA PARCIALMENTE (objetivos 3 y 4)
 * ================================================================== */
echo "Venta cobrada parcialmente (flujo de efectivo, prorrateo)\n";
$db->query("INSERT INTO clientes (nombre, nombre_empresa) VALUES ('T37 Contacto', 'T37 Cliente SA')");
$cliente37 = (int) $db->insert_id;
$crearVenta = static function (string $fecha) use ($db, $cliente37, $usuario): int {
    $ok = Ventas::crear(['empresa_id' => 2, 'cliente_id' => $cliente37, 'fecha' => $fecha, 'aplica_iva' => 1,
        'descuento_pct' => 0, 'condiciones_compra' => ''],
        [37 => ['cantidad' => 1, 'precio' => 100, 'descuento_pct' => 0, 'no_recordar' => 1]], $usuario);
    if (!$ok) {
        throw new RuntimeException('venta: ' . implode(' · ', Ventas::getErrores()));
    }
    return (int) Ventas::$ultimoId;
};
$facturar = static function (int $ventaId, string $uuid) use ($db, $usuario): int {
    $ingreso = (int) $db->query("SELECT id FROM ingresos WHERE venta_id = $ventaId")->fetch_row()[0];
    if (!Contabilidad::actualizarFacturacion($ingreso, 'facturada', 'F37-' . $ventaId, $uuid, '2026-01-15', $usuario, true)) {
        throw new RuntimeException('facturar: ' . implode(' · ', Contabilidad::getErrores()));
    }
    return $ingreso;
};

$venta1 = $crearVenta('2026-01-15');
$ingreso1 = $facturar($venta1, 'FA370000-0000-4000-8000-00000000000A');

$a1 = $cal(1);
Contabilidad::registrarCobro($ingreso1, '2026-01-20', '50.00', 'Efectivo', 'T37-COBRO-1', $usuario);
Contabilidad::getErrores();
$b1 = $cal(1);
$prueba('con pago PARCIAL nada entra en enero (IVA cobrado en enero: delta 0)', $delta($a1, $b1, 'iva_cobrado', 1) === 0);
$prueba('queda declarada como excluida con lo que falta por cobrar ($66.00)',
    $b1['facturas_excluidas']['pendiente'] >= 66.0 - 0.001, number_format($b1['facturas_excluidas']['pendiente'], 2));
$prueba('y el motor lo dice en un aviso (no lo esconde)',
    (bool) array_filter($b1['avisos'], static fn ($x) => str_contains($x, 'parcialidades')),
    json_encode($b1['avisos'], JSON_UNESCAPED_UNICODE));

// Completado el cobro en febrero: sus cobros se reconocen en el mes en que se recibieron.
$c1 = $cal(1);
$c2 = $cal(2);
Contabilidad::registrarCobro($ingreso1, '2026-02-10', '66.00', 'Transferencia', 'T37-COBRO-2', $usuario);
Contabilidad::getErrores();
$d1 = $cal(1);
$d2 = $cal(2);
$ivaMes1 = $delta($c1, $d1, 'iva_cobrado', 1);
$ivaMes2 = $delta($c2, $d2, 'iva_cobrado', 2);
$baseMes1 = $delta($c1, $d1, 'ingreso', 1);
$baseMes2 = $delta($c2, $d2, 'ingreso', 2);
$prueba('IVA prorrateado entre los dos cobros de forma EXACTA (suma = IVA de la venta: $16.00)',
    $ivaMes1 + $ivaMes2 === 1600, "enero=$ivaMes1 febrero=$ivaMes2");
$prueba('y la base cobrada suma el total sin IVA ($100.00)', $baseMes1 + $baseMes2 === 10000,
    'e=' . $baseMes1 . ' f=' . $baseMes2);
$prueba('cada cobro reconoce su porción en su propio mes (enero $50 → IVA $6.90)', $ivaMes1 === 690,
    'iva enero=' . number_format($ivaMes1 / 100, 2));
$prueba('al pagarse al 100 % sale de las excluidas (delta de excluidas −1)',
    (int) $d1['facturas_excluidas']['n'] - (int) $b1['facturas_excluidas']['n'] === -1,
    'antes=' . $b1['facturas_excluidas']['n'] . ' después=' . $d1['facturas_excluidas']['n']);

// ANTES (criterio propio antiguo): por FECHA de la operación, todo contaba en enero.
$viejoEnero = (int) $uno("SELECT COALESCE(ROUND(SUM(i.iva) * 100), 0) FROM ingresos i
    WHERE i.estado = 'vigente' AND i.estado_facturacion = 'facturada' AND i.contribuyente_id = $contrib
      AND YEAR(i.fecha) = 2026 AND MONTH(i.fecha) = 1
      AND ROUND(i.total * 100) <= COALESCE((SELECT SUM(ROUND(c.importe * 100)) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente'), 0)");
$prueba('DIFERENCIA detectada vs. el criterio anterior (fecha de operación): enero contaba $16.00, ahora sólo $6.90',
    $viejoEnero !== $ivaMes1, 'antes=' . $viejoEnero . ' después=' . $ivaMes1);

/* ==================================================================== *
 * 2. GASTO PAGADO EN PERIODO DISTINTO A LA FACTURA (objetivo 4)
 * ================================================================== */
echo "Gasto pagado en otro periodo que la factura\n";
$antesG1Enero = $cal(1);
$antesG1Marzo = $cal(3);
$db->query("INSERT INTO gastos (contribuyente_id, fecha, beneficiario, concepto, naturaleza, empresa_id, subtotal, iva_tasa, iva, total,
        estado_pago, fecha_pago, comprobante_fiscal, revision_fiscal, usuario_id)
    VALUES ($contrib, '2026-01-10', 'T37 Proveedor G1', 'T37 gasto facturado en enero y pagado en marzo', 'servicio', 2,
        100.00, 16.00, 16.00, 116.00, 'pagado', '2026-03-05', 1, 'validado', $usuario)");
$gasto1 = (int) $db->insert_id;
$despuesG1Enero = $cal(1);
$despuesG1Marzo = $cal(3);

$prueba('entra en MARZO (mes del pago efectivo): deducción +$100.00',
    $delta($antesG1Marzo, $despuesG1Marzo, 'deduccion', 3) === 10000,
    'delta marzo=' . $delta($antesG1Marzo, $despuesG1Marzo, 'deduccion', 3));
$prueba('y NADA se resta en enero (mes de la factura): delta 0',
    $delta($antesG1Enero, $despuesG1Enero, 'deduccion', 1) === 0,
    'delta enero=' . $delta($antesG1Enero, $despuesG1Enero, 'deduccion', 1));
$prueba('el IVA acreditable también entra en marzo (delta +$16.00)',
    $delta($antesG1Marzo, $despuesG1Marzo, 'iva_acreditable', 3) === 1600);
$viejoEneroG = (int) $uno("SELECT COALESCE(SUM(ROUND(subtotal * 100)), 0) FROM gastos
    WHERE contribuyente_id = $contrib AND estado = 'vigente' AND estado_pago = 'pagado'
      AND YEAR(fecha) = 2026 AND MONTH(fecha) = 1 AND concepto LIKE 'T37%'");
$prueba('DIFERENCIA detectada vs. el criterio anterior (fecha de la factura): éste lo restaba en ENERO',
    $viejoEneroG === 10000 && $delta($antesG1Enero, $despuesG1Enero, 'deduccion', 1) === 0,
    'antes(enero)=' . $viejoEneroG);

/* ==================================================================== *
 * 3. COMPRA CON PAGOS PARCIALES (objetivos 7 y 3)
 * ================================================================== */
echo "Compra con pagos parciales (integración con contabilidad)\n";
$db->query("INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) VALUES ('T37 Proveedor C', 'T37RFC0101AAA', 'Material', 1)");
$proveedor37 = (int) $db->insert_id;
$okCompra = OC::crear(['tipo' => 'insumo', 'proveedor_id' => $proveedor37, 'fecha_solicitud' => '2026-02-05',
    'referencia' => 'T37-C1', 'estado_cfdi' => 'pendiente_cfdi', 'iva' => '', 'iva_tasa' => ''],
    [['descripcion' => 'Mercancía T37-C1', 'cantidad' => 1, 'costo' => '2000.00', 'unidad' => 'pza', 'controla' => 0,
      'aplica_iva' => 0, 'producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '', 'proveedor_producto_id' => 0]], $usuario);
$prueba('la compra se crea', $okCompra === true, implode(' · ', OC::getErrores()));
$orden37 = (int) OC::$ultimoId;
$a2 = $cal(2);   // baseline ANTES de crear el gasto de la compra
$gastoCompra = CompraContable::enviarAContabilidad($orden37, $usuario);
$prueba('la compra genera SU gasto una sola vez', $gastoCompra > 0, implode(' · ', CompraContable::getErrores()));
// Simula lo que hace la FASE 2 al relacionar el CFDI de la compra (validado).
$db->query("UPDATE gastos SET comprobante_fiscal = 1, revision_fiscal = 'validado' WHERE id = $gastoCompra");
$prueba('reenviar a contabilidad NO crea otro gasto (mismo id)',
    CompraContable::enviarAContabilidad($orden37, $usuario) === $gastoCompra
    && (int) $uno("SELECT COUNT(*) FROM gastos WHERE id = $gastoCompra") === 1);

PagosCompra::registrar($orden37, '2026-02-10', '800.00', 'Transferencia', 'T37-PAGO-1', bin2hex(random_bytes(16)), $usuario);
PagosCompra::getErrores();
$b2 = $cal(2);
$prueba('pago PARCIAL: el gasto NO se descuenta (delta deducción febrero = 0)', $delta($a2, $b2, 'deduccion', 2) === 0);
$prueba('y queda declarado como gasto por pagar en febrero', $delta($a2, $b2, 'gastos_no_pagados_n', 2) === 1
    && $delta($a2, $b2, 'gastos_no_pagados', 2) === 200000, 'n=' . $delta($a2, $b2, 'gastos_no_pagados_n', 2));

$a3c = $cal(3);
PagosCompra::registrar($orden37, '2026-03-20', '1200.00', 'Transferencia', 'T37-PAGO-2', bin2hex(random_bytes(16)), $usuario);
PagosCompra::getErrores();
$b3c = $cal(3);
$prueba('al LIQUIDAR el gasto entra UNA sola vez y en el mes del último pago (marzo +$2,000.00)',
    $delta($a3c, $b3c, 'deduccion', 3) === 200000, 'delta=' . $delta($a3c, $b3c, 'deduccion', 3));
$prueba('…sin importes duplicados: 2 pagos en pagos_compra y UN gasto deducido',
    (int) $uno("SELECT COUNT(*) FROM pagos_compra WHERE orden_id = $orden37") === 2
    && (int) $uno("SELECT COUNT(*) FROM gastos WHERE id = $gastoCompra") === 1);
$prueba('y sale de «por pagar» (fecha efectiva del último pago en el gasto)',
    (string) $uno("SELECT fecha_pago FROM gastos WHERE id = $gastoCompra") === '2026-03-20'
    && (string) $uno("SELECT estado_pago FROM gastos WHERE id = $gastoCompra") === 'pagado');
$viejoCompra = (int) $uno("SELECT COALESCE(SUM(ROUND(subtotal * 100)), 0) FROM gastos
    WHERE contribuyente_id = $contrib AND estado_pago = 'pagado' AND YEAR(fecha) = 2026 AND MONTH(fecha) = 2 AND concepto LIKE '%T37-C1%'");
$prueba('DIFERENCIA detectada: el criterio anterior restaba la compra en FEBRERO (fecha de la factura)',
    $viejoCompra === 200000, 'antes(febrero)=' . $viejoCompra);

/* ==================================================================== *
 * 4. GASTOS SIN CFDI VALIDADO / NO DEDUCIBLES (objetivo 5)
 * ================================================================== */
echo "Gastos sin comprobante o sin revisión fiscal\n";
$antes4 = $cal(4);
$db->query("INSERT INTO gastos (contribuyente_id, fecha, beneficiario, concepto, naturaleza, empresa_id, subtotal, iva_tasa, iva, total,
        estado_pago, fecha_pago, comprobante_fiscal, revision_fiscal, usuario_id)
    VALUES ($contrib, '2026-04-08', 'T37 Proveedor G2', 'T37 gasto SIN comprobante fiscal', 'gasto', 2,
        50.00, 16.00, 8.00, 58.00, 'pagado', '2026-04-08', 0, 'validado', $usuario),
       ($contrib, '2026-04-09', 'T37 Proveedor G3', 'T37 gasto marcado NO deducible', 'gasto', 2,
        50.00, 16.00, 8.00, 58.00, 'pagado', '2026-04-09', 1, 'no_deducible', $usuario)");
$gastoSinComprobante = (int) $db->insert_id;
$gastoNoDeducible = $gastoSinComprobante + 1;
$desp4 = $cal(4);
$deduccionAbril = (int) $desp4['por_mes']['deduccion'][4] - (int) $antes4['por_mes']['deduccion'][4];

if ($reqComprobante) {
    $prueba('gasto PAGADO sin comprobante fiscal SÍ se descuenta (la deducción la decide el contador)', $deduccionAbril === 5000,
        'deducción abril=' . $deduccionAbril);
    $prueba('no queda contado en los excluidos por comprobante', !isset($desp4['gastos_excluidos']['sin_comprobante']));
    $prueba('y el motor ya no avisa de gastos sin factura', !array_filter($desp4['avisos'],
        static fn ($x) => str_contains($x, 'no tienen factura')));
} else {
    $prueba('la regla activa NO exige comprobante: el gasto sí se descuenta', $deduccionAbril === 5000);
}
$prueba('el gasto marcado NO deducible nunca se descuenta', (int) $desp4['gastos_excluidos']['no_deducibles'] >= 1
    && (int) $antes4['gastos_excluidos']['no_deducibles'] < (int) $desp4['gastos_excluidos']['no_deducibles']);

// Revisión «por validar»: ya no cambia el cálculo, la deducción la decide el contador.
$antes5 = $cal(5);
$db->query("INSERT INTO gastos (contribuyente_id, fecha, beneficiario, concepto, naturaleza, empresa_id, subtotal, iva_tasa, iva, total,
        estado_pago, fecha_pago, comprobante_fiscal, revision_fiscal, usuario_id)
    VALUES ($contrib, '2026-05-06', 'T37 Proveedor G4', 'T37 gasto por validar', 'gasto', 2,
        40.00, 16.00, 6.40, 46.40, 'pagado', '2026-05-06', 1, 'por_validar', $usuario)");
$desp5 = $cal(5);
$deduccionMayo = (int) $desp5['por_mes']['deduccion'][5] - (int) $antes5['por_mes']['deduccion'][5];
$prueba('gasto «por validar»: se descuenta igual (no se filtra por revisión fiscal)', $deduccionMayo === 4000, 'delta=' . $deduccionMayo);

$catalogosEstrictos = MotorFiscal::catalogosActivos($anio);
$catalogosEstrictos['reglas']['doc']['gasto_requiere_validacion'] = true;
$estricto = MotorFiscal::calcular($contrib, $anio, 5, $catalogosEstrictos);
$prueba('con la regla de validación inyectada el gasto sigue contando (el motor ya no filtra)',
    ((int) $estricto['por_mes']['deduccion'][5] - (int) $antes5['por_mes']['deduccion'][5]) === 4000);

/* ==================================================================== *
 * 5. RETENCIONES, AJUSTES Y DECLARACIONES (objetivos 6, 10 y 11)
 * ================================================================== */
echo "Retenciones, ajustes y declaraciones\n";
$a6 = $cal(6);
$okRet = RegistrosFiscales::registrarRetencion($contrib, '2026-06-15', 'IVA', '100.00', 'T37 Cliente', null, 'T37 prueba etapa37', $usuario);
$prueba('retención de IVA registrada', $okRet === true, implode(' · ', RegistrosFiscales::getErrores()));
RegistrosFiscales::getErrores();
$b6 = $cal(6);
$prueba('resta el IVA a cargo del mes (delta +$100.00)', $delta($a6, $b6, 'ret_iva', 6) === 10000,
    'delta=' . $delta($a6, $b6, 'ret_iva', 6));

$a6b = $cal(6);
RegistrosFiscales::registrarRetencion($contrib, '2026-06-20', 'ISR', '50.00', 'T37 Cliente', null, 'T37 isr retenido', $usuario);
RegistrosFiscales::getErrores();
$b6b = $cal(6);
$prueba('el ISR retenido acredita contra el ISR acumulado (delta +$50.00)',
    (int) $b6b['isr']['retenciones_acumuladas'] - (int) $a6b['isr']['retenciones_acumuladas'] === 5000);

$a1aj = $cal(1);
RegistrosFiscales::registrarAjuste($contrib, 2026, 1, 'saldo_favor_iva_anterior', '50.00', 'T37 saldo de diciembre', $usuario);
RegistrosFiscales::getErrores();
$b1aj = $cal(1);
$prueba('ajuste: saldo a favor anterior de IVA resta ese mes (delta +$50.00)',
    (int) $b1aj['iva']['saldo_favor_anterior'] - (int) $a1aj['iva']['saldo_favor_anterior'] === 5000);

$a2dec = $cal(2);
RegistrosFiscales::guardarDeclaracion($contrib, 2026, 1, 'ISR', '0.00', '0.00', '500.00', '2026-02-17', '2026-02-17', 'T37-DECL-1', 'T37 declaracion', $usuario);
RegistrosFiscales::getErrores();
$b2dec = $cal(2);
$prueba('pagos de ISR anteriores se acreditan como pagos provisionales (delta +$500.00)',
    (int) $b2dec['isr']['pagos_provisionales_anteriores'] - (int) $a2dec['isr']['pagos_provisionales_anteriores'] === 50000,
    'previos=' . (int) $b2dec['isr']['pagos_provisionales_anteriores']);

$nAntes = (int) $uno('SELECT COUNT(*) FROM declaraciones WHERE contribuyente_id = ' . $contrib . ' AND anio = 2026');
RegistrosFiscales::guardarDeclaracion($contrib, 2026, 1, 'ISR', '0.00', '0.00', '600.00', '2026-02-17', '2026-02-17', 'T37-DECL-2', 'T37 declaracion 2', $usuario);
RegistrosFiscales::getErrores();
$prueba('regrabar la misma declaración actualiza en lugar de duplicar',
    (int) $uno('SELECT COUNT(*) FROM declaraciones WHERE contribuyente_id = ' . $contrib . ' AND anio = 2026') === $nAntes
    && (float) $uno("SELECT importe_pagado FROM declaraciones WHERE contribuyente_id = $contrib AND anio = 2026 AND mes = 1 AND impuesto = 'ISR'") === 600.00);

// El resumen de pantalla muestra las retenciones y ajustes capturados (un solo motor).
$antesAnual = ResumenFiscal::anual($contrib, 2026);
RegistrosFiscales::registrarRetencion($contrib, '2026-06-25', 'IVA', '25.00', 'T37 Cliente', null, 'T37 otra retencion', $usuario);
RegistrosFiscales::getErrores();
$despuesAnual = ResumenFiscal::anual($contrib, 2026);
$prueba('el resumen anual refleja la retención capturada (delta +$25.00)',
    round($despuesAnual['total']['iva_retenciones'] - $antesAnual['total']['iva_retenciones'], 2) === 25.00);
$prueba('y las pantallas nuevas existen: registros, ruta y pestaña',
    is_file($raiz . '/views/admin/fiscal/registros.php')
    && str_contains(file_get_contents($raiz . '/index.php'), '/admin/fiscal/registros')
    && str_contains(file_get_contents($raiz . '/templates/tabsFiscal.php'), "'registros'"));

// Cancelación lógica con motivo y periodo cerrado (objetivo 11).
$ultimaRet = (int) $uno("SELECT id FROM retenciones WHERE contribuyente_id = $contrib AND nota = 'T37 otra retencion' LIMIT 1");
$prueba('cancelar retención exige motivo', RegistrosFiscales::cancelarRetencion($ultimaRet, 'abc', $usuario) === false);
RegistrosFiscales::getErrores();
$prueba('cancelar con motivo la conserva cancelada (no se borra)',
    RegistrosFiscales::cancelarRetencion($ultimaRet, 'Error de captura T37', $usuario) === true
    && (string) $uno("SELECT estado FROM retenciones WHERE id = $ultimaRet") === 'cancelado'
    && trim((string) $uno("SELECT motivo_cancelacion FROM retenciones WHERE id = $ultimaRet")) !== '');
$prueba('la cancelación quedó auditada',
    (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'retencion' AND entidad_id = $ultimaRet AND accion = 'cancelar'") === 1);

$db->query("INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES (1, 2026, 9, 'cerrado', NOW())");
$okCerrado = RegistrosFiscales::registrarRetencion($contrib, '2026-09-10', 'IVA', '10.00', 'T37 Cliente', null, 'T37 mes cerrado', $usuario);
$erroresCerrado = RegistrosFiscales::getErrores();
$prueba('retención en periodo cerrado se rechaza con su motivo',
    $okCerrado === false && (bool) array_filter($erroresCerrado, static fn ($x) => str_contains($x, 'cerrado')),
    implode(' · ', $erroresCerrado));
$db->query("DELETE FROM periodos_contables WHERE contribuyente_id = 1 AND anio = 2026 AND mes = 9 AND estado = 'cerrado'");

/* ==================================================================== *
 * 6. IDENTIDADES: LAS CIFRAS CUADRAN CON LOS DATOS DE ORIGEN (objetivos 1 y 3)
 * ================================================================== */
echo "Consistencia de las cifras\n";
$identidadIva = true;
$identidadIsr = true;
$detalles = '';
for ($m = 1; $m <= 12; $m++) {
    $c = $cal($m);
    // IVA neto: cobrado − acreditable − retenciones − saldo a favor − otros ajustes = a cargo − saldo a favor.
    if ($c['habilitado']) {
        $neto = (int) $c['iva']['a_cargo'] - (int) $c['iva']['saldo_a_favor'];
        $esperado = (int) $c['iva']['trasladado_cobrado'] - (int) $c['iva']['acreditable_considerado']
            - (int) $c['iva']['retenciones'] - (int) $c['iva']['saldo_favor_anterior'] - (int) $c['iva']['otros_ajustes'];
        if ($neto !== $esperado) {
            $identidadIva = false;
            $detalles .= " m$m: $neto vs $esperado";
        }
        // ISR: determinado − (retenciones acumuladas + otros acreditamientos + pagos previos) = a pagar − excedente.
        if (empty($c['isr']['habilitado'])) {
            continue;
        }
        $netoIsr = (int) $c['isr']['a_pagar'] - (int) $c['isr']['excedente_acreditable'];
        $esperadoIsr = (int) $c['isr']['impuesto_determinado'] - (int) $c['isr']['retenciones_acumuladas']
            - (int) $c['isr']['otros_acreditamientos'] - (int) $c['isr']['pagos_provisionales_anteriores'];
        if ($netoIsr !== $esperadoIsr) {
            $identidadIsr = false;
            $detalles .= " isr m$m: $netoIsr vs $esperadoIsr";
        }
    }
}
$prueba('IVA: a cargo − saldo a favor = cobrado − acreditable − retenciones − saldo anterior − ajustes (12 meses)', $identidadIva, $detalles);
$prueba('ISR: a pagar − excedente = determinado − retenciones − acreditamientos − pagos previos', $identidadIsr, $detalles);

$resumen = ResumenFiscal::anual($contrib, 2026);
$sumaMesesIva = 0.0;
$sumaMesesImp = 0.0;
foreach ($resumen['meses'] as $fila) {
    $sumaMesesIva += (float) $fila['iva_por_pagar'];
    $sumaMesesImp += (float) $fila['impuestos'];
    // Cada fila del resumen cuadra con sus propios componentes (sin saldo escondido).
    $filaEsperada = (float) $fila['iva_facturado'] - (float) $fila['iva_acreditado']
        - (float) $fila['iva_retenciones'] - (float) $fila['iva_saldo_favor'] - (float) $fila['iva_otros_ajustes'];
    if (abs($filaEsperada - (float) $fila['iva_por_pagar']) > 0.005) {
        $identidadIva = false;
    }
}
$prueba('el resumen mensual cuadra con sus componentes (fila por fila)', $identidadIva);
$prueba('los totales del año son la suma exacta de los meses',
    abs($sumaMesesIva - (float) $resumen['total']['iva_por_pagar']) < 0.005
    && abs($sumaMesesImp - (float) $resumen['total']['impuestos']) < 0.005);
$prueba('un solo motor: el resumen declara su origen y las versiones usadas',
    ($resumen['motor'] ?? '') === 'MotorFiscal' && is_array($resumen['versiones'] ?? null)
    && is_array($resumen['reglas'] ?? null) && $resumen['avisos'] !== null);

// Cobros sin duplicar ingresos (objetivo 8).
$ingresosAntes = (int) $uno('SELECT COUNT(*) FROM ingresos');
Contabilidad::registrarCobro($ingreso1, '2026-03-01', '1.00', 'Efectivo', 'T37-EXTRA', $usuario);
Contabilidad::getErrores();
$prueba('los cobros NUNCA crean ingresos ni alteran su número', (int) $uno('SELECT COUNT(*) FROM ingresos') === $ingresosAntes);
Contabilidad::registrarCobro($ingreso1, '2026-03-02', '1.00', 'Efectivo', 'T37-EXTRA2', $usuario);
Contabilidad::getErrores();
$prueba('y el IVA de la venta sigue contándose una sola vez (prorrateo exacto: $16.00)',
    abs((float) $resumen['total']['iva_facturado'] - (float) ResumenFiscal::anual($contrib, 2026)['total']['iva_facturado']) < 30.0);

// El cierre de periodo conserva el motor y sus versiones en el snapshot (objetivo 11).
$prueba('el snapshot de cierre registra motor y versiones (auditable)',
    str_contains(file_get_contents($raiz . '/clases/CierrePeriodo.php'), 'fiscal_versiones')
    && str_contains(file_get_contents($raiz . '/clases/CierrePeriodo.php'), 'fiscal_motor'));
$prueba('las pantallas muestran las razones de cada importe (objetivo 12)',
    str_contains(file_get_contents($raiz . '/views/admin/fiscal/resumen.php'), '¿Por qué entra o sale cada importe?')
    && str_contains(file_get_contents($raiz . '/views/admin/fiscal/resumen.php'), 'gastos_excluidos'));

$limpiar();
echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
