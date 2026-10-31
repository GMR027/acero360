<?php
/**
 * pruebas/php/etapa6.php — catálogo fiscal versionado, motor (IVA/ISR por flujo de efectivo), la regla de
 * pago (una factura en parcialidades o sin pagar no entra hasta liquidarse), configuración fiscal y
 * cierre/reapertura mensual. Los montos esperados están calculados A MANO
 * (ver comentarios) con las tarifas oficiales del Anexo 8 RMF 2026.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa6.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test (no se prueba sobre la base real).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{CatalogoFiscal, CierrePeriodo, Contabilidad, Gastos, MotorFiscal, RegistrosFiscales, Ventas};

$raiz = dirname(__DIR__, 2);
$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$pesos = fn ($c) => $c === null ? 'null' : number_format($c / 100, 2, '.', '');

// ---- estado limpio y usuario de prueba con contraseña conocida -------------------------------
foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros',
    'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones'] as $t) {
    $db->query("DELETE FROM $t");
}
$db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
$db->query('DELETE FROM venta_cambios_entregados'); $db->query('DELETE FROM venta_devoluciones'); $db->query('DELETE FROM ventas'); $db->query('DELETE FROM cotizaciones');
$db->query('DELETE FROM catalogo_fiscal');
$db->query('DELETE FROM configuracion_fiscal WHERE id > (SELECT MIN(id) FROM (SELECT id FROM configuracion_fiscal) x)');
$db->query('UPDATE configuracion_fiscal SET vigente_hasta = NULL, tipo_persona = "fisica", confirmada_con_contador = 0');
$db->query("DELETE FROM usuarios WHERE email = 'prueba.e6@test.local'");
$hash = password_hash('Clave-E6-ok', PASSWORD_BCRYPT);
$db->query("INSERT INTO usuarios (nombre, email, contrasena, rol) VALUES ('Prueba E6', 'prueba.e6@test.local', '$hash', 1)");
$usuario = (int) $db->insert_id;
$_SESSION['id'] = $usuario;
$contrib = Contabilidad::contribuyentePrincipal();
$db->query('UPDATE productos SET stock = 1000 WHERE id = 37');
$clientes = array_column($db->query('SELECT id FROM clientes LIMIT 2')->fetch_all(MYSQLI_ASSOC), 'id');
$claveBuena = 'Clave-E6-ok';

$archivoIsr = file_get_contents("$raiz/mysql/catalogo_fiscal/isr_pp_art106_2026.json");

echo "Catálogo fiscal versionado\n";
$prueba('rechaza un archivo que no es JSON', !CatalogoFiscal::importar('esto no es json', $usuario)); CatalogoFiscal::getErrores();
$sinFuente = json_decode($archivoIsr, true); unset($sinFuente['fuente']);
$prueba('rechaza reglas sin fuente oficial', !CatalogoFiscal::importar(json_encode($sinFuente), $usuario)
    && str_contains(implode(' ', CatalogoFiscal::getErrores()), 'fuente'));
$prueba('importa la tarifa oficial como borrador v1', CatalogoFiscal::importar($archivoIsr, $usuario), implode('|', CatalogoFiscal::getErrores()));
$v1 = CatalogoFiscal::$ultimoId;
$prueba('queda en «borrador» con fuente, URL y huella del PDF', CatalogoFiscal::buscarID($v1)['estado'] === 'borrador'
    && str_contains(CatalogoFiscal::buscarID($v1)['fuente_url'], 'sat.gob.mx') && strlen((string) CatalogoFiscal::buscarID($v1)['doc']['fuente']['sha256_pdf']) === 64);
$prueba('no se puede activar un borrador sin validar', !CatalogoFiscal::activar($v1, $usuario, $claveBuena, 'Activación de prueba')); CatalogoFiscal::getErrores();
$prueba('valida: 12 meses × 11 tramos y los 40 casos independientes', CatalogoFiscal::validar($v1, $usuario), implode('|', CatalogoFiscal::getErrores()));
$val = CatalogoFiscal::buscarID($v1)['validacion'];
$prueba('→ 40 pruebas de regresión, 0 falladas', $val['pruebas']['total'] === 40 && $val['pruebas']['falladas'] === 0, json_encode($val['pruebas']));
$prueba('activar exige la contraseña', !CatalogoFiscal::activar($v1, $usuario, 'incorrecta', 'Activación de prueba')); CatalogoFiscal::getErrores();
$prueba('activar exige motivo', !CatalogoFiscal::activar($v1, $usuario, $claveBuena, 'corto')); CatalogoFiscal::getErrores();
$prueba('activar con autorización', CatalogoFiscal::activar($v1, $usuario, $claveBuena, 'Tarifa oficial 2026 (DOF 28-dic-2025)'), implode('|', CatalogoFiscal::getErrores()));
$prueba('→ queda ACTIVA', CatalogoFiscal::activa('isr_pagos_provisionales_art106', 2026)['id'] == $v1);

$huellaV1 = $uno("SELECT sha256_datos FROM catalogo_fiscal WHERE id = $v1");
$alterado = json_decode($archivoIsr, true);
$alterado['tarifas']['10'][2]['cuota_fija'] = '4300.00';           // dato manipulado
$prueba('importa una versión alterada (v2)…', CatalogoFiscal::importar(json_encode($alterado), $usuario));
$v2 = CatalogoFiscal::$ultimoId;
$prueba('…pero NO pasa la validación (cuota fija inconsistente / casos fallan)', !CatalogoFiscal::validar($v2, $usuario)); CatalogoFiscal::getErrores();
$prueba('→ sigue en borrador y no se activa', CatalogoFiscal::buscarID($v2)['estado'] === 'borrador'
    && !CatalogoFiscal::activar($v2, $usuario, $claveBuena, 'Intento de activar una versión inválida')); CatalogoFiscal::getErrores();
$prueba('la versión activa sigue siendo v1', CatalogoFiscal::activa('isr_pagos_provisionales_art106', 2026)['id'] == $v1);

CatalogoFiscal::importar($archivoIsr, $usuario);
$v3 = CatalogoFiscal::$ultimoId;
$prueba('versión idéntica v3 validada, sin diferencias contra la activa', CatalogoFiscal::validar($v3, $usuario)
    && CatalogoFiscal::buscarID($v3)['validacion']['comparacion']['diferencias'] === 0);
$prueba('activar v3 reemplaza a v1 SIN tocarla', CatalogoFiscal::activar($v3, $usuario, $claveBuena, 'Reimportación de la misma tarifa')
    && CatalogoFiscal::buscarID($v1)['estado'] === 'reemplazada' && $uno("SELECT sha256_datos FROM catalogo_fiscal WHERE id = $v1") === $huellaV1);
foreach (['iva_tasas_2026', 'reglas_plataforma_2026'] as $archivo) {
    // El motor ya no filtra por validación fiscal ni comprobante: se activa el archivo tal cual.
    CatalogoFiscal::importar(file_get_contents("$raiz/mysql/catalogo_fiscal/$archivo.json"), $usuario);
    $id = CatalogoFiscal::$ultimoId;
    CatalogoFiscal::validar($id, $usuario);
    CatalogoFiscal::activar($id, $usuario, $claveBuena, 'Catálogo inicial de prueba');
}
$prueba('IVA y criterios de la plataforma activos', CatalogoFiscal::activa('iva_tasas', 2026) !== null && CatalogoFiscal::activa('reglas_plataforma', 2026) !== null);

echo "Aritmética del motor (contra cálculo manual)\n";
$mes10 = CatalogoFiscal::activa('isr_pagos_provisionales_art106', 2026)['doc']['tarifas']['10'];
$prueba('utilidad $100,000 mes 10 → $7,290.16 (4,209.50 + 28,314.89 × 10.88 %)', MotorFiscal::impuestoSegunTarifa(10000000, $mes10) === 729016, $pesos(MotorFiscal::impuestoSegunTarifa(10000000, $mes10)));
$mes1 = CatalogoFiscal::activa('isr_pagos_provisionales_art106', 2026)['doc']['tarifas']['1'];
$prueba('utilidad $2,000 mes 1 → $90.17', MotorFiscal::impuestoSegunTarifa(200000, $mes1) === 9017);
$prueba('utilidad 0 o negativa → 0', MotorFiscal::impuestoSegunTarifa(0, $mes1) === 0 && MotorFiscal::impuestoSegunTarifa(-500, $mes1) === 0);
$prueba('IVA 16 % de $1,000 → $160.00 y de $33.33 → $5.33', MotorFiscal::ivaDe(100000, '16.00') === 16000 && MotorFiscal::ivaDe(3333, '16.00') === 533);

echo "Motor: flujo de efectivo (enero y febrero de 2026)\n";
$L = fn (int $c, float $p) => ['cantidad' => $c, 'precio' => $p, 'descuento_pct' => 0, 'no_recordar' => 1];
$venta = function (string $fecha, int $cliente, array $lineas) use ($usuario) {
    $ok = Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $fecha, 'aplica_iva' => 1, 'descuento_pct' => 0], $lineas, $usuario);
    return $ok ? (int) $GLOBALS['db']->query('SELECT id FROM ingresos ORDER BY id DESC LIMIT 1')->fetch_row()[0] : 0;
};
$i1 = $venta('2026-01-10', (int) $clientes[0], [37 => $L(10, 1000)]);   // $10,000 + IVA 1,600 = 11,600
$i2 = $venta('2026-02-03', (int) $clientes[1], [37 => $L(2, 1000)]);    //  $2,000 + IVA   320 =  2,320
$prueba('ventas e ingresos creados (11,600 y 2,320)', $i1 > 0 && $i2 > 0 && Contabilidad::buscarIngreso($i1)['total'] === '11600.00' && Contabilidad::buscarIngreso($i2)['total'] === '2320.00');

Contabilidad::registrarCobro($i1, '2026-01-20', 5800, 'Transferencia', '', $usuario);   // mitad: base 5,000 + IVA 800
Contabilidad::registrarCobro($i1, '2026-02-05', 5800, 'Transferencia', '', $usuario);   // resto: base 5,000 + IVA 800
Contabilidad::registrarCobro($i2, '2026-02-10', 2320, 'Efectivo', '', $usuario);        // base 2,000 + IVA 320

$g = fn (array $d) => Gastos::crear($d + ['beneficiario' => 'Proveedor', 'iva_tasa' => '16', 'estado_pago' => 'pagado', 'comprobante_fiscal' => 1, 'revision_fiscal' => 'validado', 'empresa_id' => 1], $usuario);
$g(['fecha' => '2026-01-12', 'concepto' => 'Acero', 'subtotal' => '3000', 'fecha_pago' => '2026-01-15', 'naturaleza' => 'compra']);          // IVA 480
$g(['fecha' => '2026-02-08', 'concepto' => 'Renta', 'subtotal' => '1000', 'fecha_pago' => '2026-02-09', 'naturaleza' => 'servicio']);        // IVA 160
$g(['fecha' => '2026-02-09', 'concepto' => 'Sin validar', 'subtotal' => '500', 'fecha_pago' => '2026-02-09', 'revision_fiscal' => 'por_validar']);
$g(['fecha' => '2026-02-10', 'concepto' => 'Soldadora', 'subtotal' => '800', 'fecha_pago' => '2026-02-10', 'naturaleza' => 'activo']);          // IVA 128, sin deducción
RegistrosFiscales::registrarRetencion($contrib, '2026-02-12', 'ISR', 100, 'Cliente SA', null, '', $usuario);
RegistrosFiscales::registrarRetencion($contrib, '2026-02-12', 'IVA', 50, 'Cliente SA', null, '', $usuario);

$ene = MotorFiscal::calcular($contrib, 2026, 1);
$prueba('enero · IVA trasladado cobrado $800 (la mitad del IVA de la venta)', $ene['iva']['trasladado_cobrado'] === 80000, $pesos($ene['iva']['trasladado_cobrado']));
$prueba('enero · IVA acreditable $480 → IVA a cargo $320', $ene['iva']['acreditable_considerado'] === 48000 && $ene['iva']['a_cargo'] === 32000, $pesos($ene['iva']['a_cargo']));
$prueba('enero · ingresos 5,000, deducciones 3,000, utilidad 2,000', $ene['isr']['ingresos_acumulados'] === 500000 && $ene['isr']['deducciones_acumuladas'] === 300000 && $ene['isr']['utilidad_fiscal'] === 200000);
$prueba('enero · ISR provisional estimado $90.17', $ene['isr']['a_pagar'] === 9017, $pesos($ene['isr']['a_pagar']));
$prueba('el cobro de febrero NO entra en enero (flujo de efectivo)', $ene['isr']['ingresos_acumulados'] === 500000);

RegistrosFiscales::guardarDeclaracion($contrib, 2026, 1, 'ISR', 90.17, 0, 90.17, '2026-02-17', '2026-02-17', 'OP-1', '', $usuario);

$feb = MotorFiscal::calcular($contrib, 2026, 2);
$prueba('febrero · IVA trasladado cobrado $1,120 (800 de V1 + 320 de V2)', $feb['iva']['trasladado_cobrado'] === 112000, $pesos($feb['iva']['trasladado_cobrado']));
$prueba('febrero · IVA acreditable $368 (160 + 80 del gasto sin validar + 128)', $feb['iva']['acreditable_considerado'] === 36800, $pesos($feb['iva']['acreditable_considerado']));
$prueba('febrero · IVA a cargo = 1,120 − 368 − 50 retenido = $702', $feb['iva']['a_cargo'] === 70200 && $feb['iva']['saldo_a_favor'] === 0, $pesos($feb['iva']['a_cargo']));
$prueba('febrero · ingresos acumulados 12,000; deducciones 4,500 (sólo el activo queda fuera)', $feb['isr']['ingresos_acumulados'] === 1200000 && $feb['isr']['deducciones_acumuladas'] === 450000);
$prueba('febrero · utilidad 7,500 → ISR determinado $404.33 con la tarifa del mes 2', $feb['isr']['impuesto_determinado'] === 40433, $pesos($feb['isr']['impuesto_determinado']));
$prueba('febrero · acredita 100 retenido + 90.17 pago de enero → a pagar $214.16', $feb['isr']['a_pagar'] === 21416 && $feb['isr']['pagos_provisionales_anteriores'] === 9017, $pesos($feb['isr']['a_pagar']));
$prueba('ya no avisa de gastos «por validar»: todo gasto pagado resta', !array_filter($feb['avisos'], fn ($a) => str_contains($a, 'por validar')));
$prueba('avisa que la configuración no está confirmada con el contador', (bool) array_filter($feb['avisos'], fn ($a) => str_contains($a, 'contador')));

$mar = MotorFiscal::calcular($contrib, 2026, 3);
$prueba('marzo · avisa que falta el pago provisional de febrero (se tomaría como $0)', $mar['isr']['meses_sin_pago_registrado'] === [2], json_encode($mar['isr']['meses_sin_pago_registrado']));
$prueba('marzo · sin movimientos: IVA a cargo 0', $mar['iva']['a_cargo'] === 0);

echo "Motor: una factura en parcialidades no se considera hasta liquidarse\n";
// Venta de mayo $3,000 + IVA 480 = $3,480, pagada en dos partes de $1,740.
$parcial = $venta('2026-05-05', (int) $clientes[0], [37 => $L(3, 1000)]);
$prueba('venta de mayo creada (3,480)', $parcial > 0 && Contabilidad::buscarIngreso($parcial)['total'] === '3480.00');
Contabilidad::registrarCobro($parcial, '2026-05-10', 1740, 'Transferencia', '', $usuario);   // primera mitad

$may = MotorFiscal::calcular($contrib, 2026, 5);
$prueba('con pago parcial: mayo no cuenta nada (IVA trasladado 0)', $may['iva']['trasladado_cobrado'] === 0, $pesos($may['iva']['trasladado_cobrado']));
$prueba('…ni ingresos acumulados (siguen 12,000)', $may['isr']['ingresos_acumulados'] === 1200000, $pesos($may['isr']['ingresos_acumulados']));
$prueba('declara 1 factura excluida con 1,740 por cobrar', $may['facturas_excluidas']['n'] === 1 && $may['facturas_excluidas']['pendiente'] == 1740.0, json_encode($may['facturas_excluidas']));
$prueba('avisa que está en parcialidades', (bool) array_filter($may['avisos'], fn ($a) => str_contains($a, 'parcialidades')));

Contabilidad::registrarCobro($parcial, '2026-05-20', 1740, 'Transferencia', '', $usuario);   // resto: ya pagada al 100 %
$may2 = MotorFiscal::calcular($contrib, 2026, 5);
$prueba('al confirmarse el pago entra completa: IVA trasladado 480', $may2['iva']['trasladado_cobrado'] === 48000, $pesos($may2['iva']['trasladado_cobrado']));
$prueba('…y su base sin IVA suma 3,000', $may2['isr']['ingresos_acumulados'] === 1500000, $pesos($may2['isr']['ingresos_acumulados']));
$prueba('ya no hay factura excluida ni aviso de parcialidades', $may2['facturas_excluidas']['n'] === 0 && !array_filter($may2['avisos'], fn ($a) => str_contains($a, 'parcialidades')));

RegistrosFiscales::registrarAjuste($contrib, 2026, 2, 'saldo_favor_iva_anterior', 100, '', $usuario);
$feb2 = MotorFiscal::calcular($contrib, 2026, 2);
$prueba('un saldo a favor anterior de $100 baja el IVA a cargo a $602', $feb2['iva']['a_cargo'] === 60200);
RegistrosFiscales::registrarAjuste($contrib, 2026, 2, 'ptu_pagada', 1000, '', $usuario);
$feb3 = MotorFiscal::calcular($contrib, 2026, 2);
$prueba('PTU pagada de $1,000 baja la utilidad a 6,500 (ISR del mes distinto del anterior)', $feb3['isr']['utilidad_fiscal'] === 650000 && $feb3['isr']['impuesto_determinado'] < $feb['isr']['impuesto_determinado']);
$db->query('DELETE FROM ajustes_fiscales');

echo "Pagos reales y comparación\n";
RegistrosFiscales::guardarDeclaracion($contrib, 2026, 2, 'IVA', 702, 0, 700, '2026-03-17', '2026-03-17', '', '', $usuario);
$feb4 = MotorFiscal::calcular($contrib, 2026, 2);
$prueba('lo declarado ($702) coincide con la estimación: diferencia 0', $feb4['comparacion']['iva'] === 0);
$prueba('pendiente de pago de IVA = declarado − pagado = $2', $feb4['pendiente_de_pago']['iva'] === 200);
RegistrosFiscales::guardarDeclaracion($contrib, 2026, 2, 'ISR', 300, 0, 300, '', '', '', '', $usuario);
$prueba('si se declaró $300 de ISR contra $214.16 estimados: diferencia −$85.84', MotorFiscal::calcular($contrib, 2026, 2)['comparacion']['isr'] === -8584);

echo "Sin reglas activas no se inventa nada\n";
$db->query("UPDATE catalogo_fiscal SET estado = 'reemplazada' WHERE tipo = 'isr_pagos_provisionales_art106' AND estado = 'activa'");
$sinIsr = MotorFiscal::calcular($contrib, 2026, 2);
$prueba('sin tarifa ISR activa: ISR sin calcular y con aviso', $sinIsr['isr']['habilitado'] === false && $sinIsr['isr']['a_pagar'] === null
    && (bool) array_filter($sinIsr['avisos'], fn ($a) => str_contains($a, 'ISR')));
$prueba('→ el IVA sí se calcula con su regla', $sinIsr['iva']['a_cargo'] === 70200);
$db->query("UPDATE catalogo_fiscal SET estado = 'activa' WHERE id = $v3");

echo "Cálculos guardados conservan su versión\n";
$idCalculo = MotorFiscal::guardarCalculo(MotorFiscal::calcular($contrib, 2026, 2), 'guardado', $usuario);
$antes = $uno("SELECT sha256 FROM calculos_fiscales WHERE id = $idCalculo");
$db->query("UPDATE catalogo_fiscal SET estado = 'reemplazada' WHERE id = $v3");
CatalogoFiscal::importar($archivoIsr, $usuario);
CatalogoFiscal::validar(CatalogoFiscal::$ultimoId, $usuario);
CatalogoFiscal::activar(CatalogoFiscal::$ultimoId, $usuario, $claveBuena, 'Versión posterior de la misma tarifa');
$prueba('el cálculo guardado apunta a la versión usada y su huella no cambia', (int) $uno("SELECT catalogo_isr_id FROM calculos_fiscales WHERE id = $idCalculo") === $v3
    && $uno("SELECT sha256 FROM calculos_fiscales WHERE id = $idCalculo") === $antes);

echo "Configuración fiscal con historial\n";
$cfg = RegistrosFiscales::configuracion($contrib, '2026-02-15');
$prueba('inicial: persona física, Actividades Empresariales y Profesionales, flujo de efectivo', $cfg['tipo_persona'] === 'fisica'
    && str_contains($cfg['regimen'], 'Actividades Empresariales') && $cfg['base_ingresos'] === 'flujo_efectivo');
$prueba('cambiar sin contraseña se rechaza', !RegistrosFiscales::cambiarConfiguracion($contrib, '2026-06-01', 'fisica', 'Actividades Empresariales y Profesionales', true, 'Confirmado con el contador', $usuario, 'mala')); RegistrosFiscales::getErrores();
$prueba('cambio autorizado conserva el historial', RegistrosFiscales::cambiarConfiguracion($contrib, '2026-06-01', 'fisica', 'Actividades Empresariales y Profesionales', true, 'Confirmado con el contador', $usuario, $claveBuena)
    && count(RegistrosFiscales::historialConfiguracion($contrib)) === 2
    && RegistrosFiscales::configuracion($contrib, '2026-03-01')['confirmada_con_contador'] == 0
    && RegistrosFiscales::configuracion($contrib, '2026-07-01')['confirmada_con_contador'] == 1);
$prueba('enero–mayo conservan la configuración original', RegistrosFiscales::configuracion($contrib, '2026-05-31')['vigente_hasta'] === '2026-05-31');
$db->query("UPDATE configuracion_fiscal SET tipo_persona = 'moral' WHERE vigente_desde = '2026-01-01'");
$prueba('una persona moral deshabilita el cálculo', MotorFiscal::calcular($contrib, 2026, 2)['habilitado'] === false);
$db->query("UPDATE configuracion_fiscal SET tipo_persona = 'fisica' WHERE vigente_desde = '2026-01-01'");

echo "Cierre mensual\n";
$ver = fn ($mes) => array_column(CierrePeriodo::verificaciones($contrib, 2026, $mes), null, 'codigo');
$prueba('enero: ventas sin clasificar bloquean el cierre (error)', ($ver(1)['sin_clasificar']['nivel'] ?? '') === 'error');
$prueba('cerrar con errores se rechaza', !CierrePeriodo::cerrar($contrib, 2026, 1, $usuario, [], '')); CierrePeriodo::getErrores();
Contabilidad::actualizarFacturacion($i1, 'no_facturada', '', '', '', $usuario);
$prueba('enero ya no tiene errores', !array_filter(CierrePeriodo::verificaciones($contrib, 2026, 1), fn ($v) => $v['nivel'] === 'error'), json_encode(array_column(CierrePeriodo::verificaciones($contrib, 2026, 1), 'codigo')));
$prueba('cierra enero', CierrePeriodo::cerrar($contrib, 2026, 1, $usuario, [], 'Cierre de enero'), implode('|', CierrePeriodo::getErrores()));
$ene = CierrePeriodo::periodo($contrib, 2026, 1);
$prueba('guarda la fotografía con la estimación simple de impuestos de enero', $ene['estado'] === 'cerrado'
    && isset($ene['snapshot']['fiscal']['impuestos'], $ene['snapshot']['fiscal']['iva_por_pagar'], $ene['snapshot']['fiscal']['isr_por_pagar']));
$prueba('enero cerrado: un gasto de enero no se edita', !Gastos::actualizar((int) $uno("SELECT id FROM gastos WHERE concepto = 'Acero'"), ['fecha' => '2026-01-12', 'beneficiario' => 'X', 'concepto' => 'Acero', 'subtotal' => '1', 'iva_tasa' => '16'], $usuario)
    && str_contains(implode(' ', Gastos::getErrores()), 'cerrado'));
$prueba('enero cerrado: no se cancela un cobro de enero', !Contabilidad::cancelarCobro((int) $uno("SELECT id FROM cobros ORDER BY id LIMIT 1"), 'Intento de cambio', $usuario)); Contabilidad::getErrores();
$prueba('enero cerrado: no se registra una retención de enero', !RegistrosFiscales::registrarRetencion($contrib, '2026-01-20', 'ISR', 10, 'X', null, '', $usuario)); RegistrosFiscales::getErrores();
$prueba('pero sí se registra el pago real de enero después del cierre', RegistrosFiscales::guardarDeclaracion($contrib, 2026, 1, 'IVA', 320, 0, 320, '', '', '', '', $usuario));

$prueba('febrero: bloquea por ventas sin clasificar', ($ver(2)['sin_clasificar']['nivel'] ?? '') === 'error');
Contabilidad::actualizarFacturacion($i2, 'facturada', 'F-1', '', '2026-02-04', $usuario, true);
$v = $ver(2);
$prueba('febrero: avisos esperados (sin validar, factura sin adjuntar, sin comprobante no, mes anterior cerrado)', isset($v['gastos_por_validar'], $v['facturas_sin_adjuntar']) && !isset($v['mes_anterior_abierto']), implode(',', array_keys($v)));
$prueba('cerrar febrero SIN reconocer los avisos se rechaza', !CierrePeriodo::cerrar($contrib, 2026, 2, $usuario, [], '')
    && str_contains(implode(' ', CierrePeriodo::getErrores()), 'reconocer'));
$avisos = array_keys(array_filter($v, fn ($x) => $x['nivel'] === 'aviso'));
$prueba('cierra febrero reconociendo los avisos', CierrePeriodo::cerrar($contrib, 2026, 2, $usuario, $avisos, 'Cierre de febrero'), implode('|', CierrePeriodo::getErrores()));
$prueba('los avisos reconocidos quedan en el resumen', CierrePeriodo::periodo($contrib, 2026, 2)['snapshot']['avisos_reconocidos'] === $avisos || count(CierrePeriodo::periodo($contrib, 2026, 2)['snapshot']['avisos_reconocidos']) === count($avisos));
$prueba('no se puede cerrar un mes futuro', !CierrePeriodo::cerrar($contrib, 2027, 1, $usuario, [], '')); CierrePeriodo::getErrores();

echo "Reapertura controlada\n";
$prueba('reabrir enero con febrero cerrado se rechaza', !CierrePeriodo::reabrir($contrib, 2026, 1, $usuario, $claveBuena, 'Corrección de una factura de enero')
    && str_contains(implode(' ', CierrePeriodo::getErrores()), 'posterior'));
$prueba('reabrir exige motivo', !CierrePeriodo::reabrir($contrib, 2026, 2, $usuario, $claveBuena, 'corto')); CierrePeriodo::getErrores();
$prueba('reabrir exige la contraseña', !CierrePeriodo::reabrir($contrib, 2026, 2, $usuario, 'mala', 'Corrección de un gasto de febrero')); CierrePeriodo::getErrores();
$prueba('el intento con mala contraseña quedó en la bitácora', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'periodo' AND accion = 'reapertura_rechazada'") >= 1);
$prueba('reabre febrero con contraseña y motivo', CierrePeriodo::reabrir($contrib, 2026, 2, $usuario, $claveBuena, 'Corrección de un gasto de febrero'), implode('|', CierrePeriodo::getErrores()));
$prueba('febrero vuelve a aceptar cambios', Gastos::actualizar((int) $uno("SELECT id FROM gastos WHERE concepto = 'Renta'"), ['fecha' => '2026-02-08', 'beneficiario' => 'Proveedor', 'concepto' => 'Renta', 'subtotal' => '1000', 'iva_tasa' => '16',
    'estado_pago' => 'pagado', 'fecha_pago' => '2026-02-09', 'comprobante_fiscal' => 1, 'revision_fiscal' => 'validado', 'empresa_id' => 1], $usuario));
$hist = CierrePeriodo::historial($contrib, 2026, 2);
$prueba('el historial conserva cierre y reapertura con su motivo', count($hist) === 2 && $hist[0]['accion'] === 'reapertura' && str_contains($hist[0]['motivo'], 'Corrección'));
$prueba('ahora sí se puede reabrir enero', CierrePeriodo::reabrir($contrib, 2026, 1, $usuario, $claveBuena, 'Corrección de una factura de enero'));

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
