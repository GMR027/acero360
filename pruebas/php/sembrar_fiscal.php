<?php
/**
 * pruebas/php/sembrar_fiscal.php — datos de ejemplo para las pruebas en navegador de la Etapa 6
 * (mismo escenario que pruebas/php/etapa6.php: enero y febrero de 2026). Solo BD de prueba.
 *   DB_NAME=cotizaciones_test php pruebas/php/sembrar_fiscal.php <email> <contraseña>
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Solo contra cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\{CatalogoFiscal, Contabilidad, Gastos, RegistrosFiscales, Ventas};

[$email, $clave] = [$argv[1], $argv[2]];
foreach (['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros',
    'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones'] as $t) { $db->query("DELETE FROM $t"); }
$db->query('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
$db->query('DELETE FROM venta_cambios_entregados'); $db->query('DELETE FROM venta_devoluciones'); $db->query('DELETE FROM ventas'); $db->query('DELETE FROM cotizaciones'); $db->query('DELETE FROM catalogo_fiscal');
$db->query('DELETE FROM configuracion_fiscal WHERE id > (SELECT MIN(id) FROM (SELECT id FROM configuracion_fiscal) x)');
$db->query('UPDATE configuracion_fiscal SET vigente_hasta = NULL, tipo_persona = "fisica", confirmada_con_contador = 0');
$db->query("DELETE FROM usuarios WHERE email = '" . $db->real_escape_string($email) . "'");
$hash = password_hash($clave, PASSWORD_BCRYPT);
$db->query("INSERT INTO usuarios (nombre, email, contrasena, rol) VALUES ('Prueba fiscal', '" . $db->real_escape_string($email) . "', '$hash', 1)");
$usuario = (int) $db->insert_id;
$_SESSION['id'] = $usuario;
$db->query('UPDATE productos SET stock = 1000 WHERE id = 37');
$contrib = Contabilidad::contribuyentePrincipal();
$clientes = array_column($db->query('SELECT id FROM clientes LIMIT 2')->fetch_all(MYSQLI_ASSOC), 'id');

$raiz = dirname(__DIR__, 2);
foreach (['isr_pp_art106_2026', 'iva_tasas_2026', 'reglas_plataforma_2026'] as $archivo) {
    // El motor ya no filtra por validación fiscal ni comprobante: se importa el archivo tal cual.
    CatalogoFiscal::importar(file_get_contents("$raiz/mysql/catalogo_fiscal/$archivo.json"), $usuario);
    CatalogoFiscal::validar(CatalogoFiscal::$ultimoId, $usuario);
    CatalogoFiscal::activar(CatalogoFiscal::$ultimoId, $usuario, $clave, 'Catálogo inicial de la prueba');
}

$L = fn (int $c, float $p) => ['cantidad' => $c, 'precio' => $p, 'descuento_pct' => 0, 'no_recordar' => 1];
$venta = function (string $fecha, int $cliente, array $lineas) use ($usuario, $db) {
    Ventas::crear(['empresa_id' => 1, 'cliente_id' => $cliente, 'fecha' => $fecha, 'aplica_iva' => 1, 'descuento_pct' => 0], $lineas, $usuario);
    return (int) $db->query('SELECT id FROM ingresos ORDER BY id DESC LIMIT 1')->fetch_row()[0];
};
$i1 = $venta('2026-01-10', (int) $clientes[0], [37 => $L(10, 1000)]);
$i2 = $venta('2026-02-03', (int) $clientes[1], [37 => $L(2, 1000)]);
Contabilidad::registrarCobro($i1, '2026-01-20', 5800, 'Transferencia', '', $usuario);
Contabilidad::registrarCobro($i1, '2026-02-05', 5800, 'Transferencia', '', $usuario);
Contabilidad::registrarCobro($i2, '2026-02-10', 2320, 'Efectivo', '', $usuario);
$g = fn (array $d) => Gastos::crear($d + ['beneficiario' => 'Proveedor', 'iva_tasa' => '16', 'estado_pago' => 'pagado', 'comprobante_fiscal' => 1, 'revision_fiscal' => 'validado', 'empresa_id' => 1], $usuario);
$g(['fecha' => '2026-01-12', 'concepto' => 'Acero', 'subtotal' => '3000', 'fecha_pago' => '2026-01-15', 'naturaleza' => 'compra']);
$g(['fecha' => '2026-02-08', 'concepto' => 'Renta', 'subtotal' => '1000', 'fecha_pago' => '2026-02-09', 'naturaleza' => 'servicio']);
$g(['fecha' => '2026-02-09', 'concepto' => 'Sin validar', 'subtotal' => '500', 'fecha_pago' => '2026-02-09', 'revision_fiscal' => 'por_validar']);
$g(['fecha' => '2026-02-10', 'concepto' => 'Soldadora', 'subtotal' => '800', 'fecha_pago' => '2026-02-10', 'naturaleza' => 'activo']);
RegistrosFiscales::registrarRetencion($contrib, '2026-02-12', 'ISR', 100, 'Cliente SA', null, '', $usuario);
RegistrosFiscales::registrarRetencion($contrib, '2026-02-12', 'IVA', 50, 'Cliente SA', null, '', $usuario);
RegistrosFiscales::guardarDeclaracion($contrib, 2026, 1, 'ISR', 90.17, 0, 90.17, '2026-02-17', '2026-02-17', 'OP-1', '', $usuario);
echo json_encode(['usuario' => $usuario, 'i1' => $i1, 'i2' => $i2]);
