<?php
/**
 * pruebas/php/etapa28.php — Gastos: uso del CFDI, método de pago y sus avisos en el seguimiento.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa28.php
 *
 * Comprueba:
 *   · migración 029: existe gastos.uso_cfdi (y metodo_pago_sat admite «POR»);
 *   · la captura con factura guarda el uso del CFDI y el método de pago (PPD, PUE o
 *     «por definir») y rechaza lo que no esté en el catálogo del SAT;
 *   · un gasto SIN factura limpia uso y método (no hay comprobante del que hablar);
 *   · la edición cambia ambos campos y Gastos::metodoCfdi() define el método desde el aviso;
 *   · el centro de seguimiento avisa las facturas PPD y «por definir» (también las guardadas
 *     sin método), con la huella ligada al método; PUE no avisa y el aviso desaparece al
 *     pasar a PUE;
 *   · el XML leído entrega UsoCFDI y MetodoPago;
 *   · el formulario, el detalle y la acción rápida del seguimiento ofrecen los dos campos.
 *
 * (Reconstruida el 23-oct-2026 tras la colisión de nombres con la suite de impuestos.)
 *
 * Los nombres llevan «T28» para no chocar con los de las otras etapas.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\CfdiXml;
use App\Gastos;
use App\Seguimiento;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');
$empresa = (int) $uno('SELECT MIN(id) FROM empresas');
$_SESSION['id'] = $usuario;
$hoy = date('Y-m-d');

/** Limpia los gastos de esta etapa (en orden: primero lo que apunta a ellos). */
$limpiar = function () use ($db) {
    $db->query("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id IN (SELECT id FROM gastos WHERE beneficiario = 'T28 Proveedor')");
    $db->query("DELETE FROM gasto_reparto WHERE gasto_id IN (SELECT id FROM gastos WHERE beneficiario = 'T28 Proveedor')");
    $db->query("DELETE FROM gastos WHERE beneficiario = 'T28 Proveedor'");
};
$limpiar();

/** Gasto con factura; $extra manda sobre los valores por defecto. */
$crear = static function (array $extra) use ($empresa, $usuario, $hoy): int {
    $ok = Gastos::crear(array_merge([
        'fecha' => $hoy, 'empresa_id' => $empresa, 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T28 Proveedor',
        'concepto' => 'T28 Factura CFDI', 'subtotal' => '1000', 'iva_tasa' => '16', 'naturaleza' => 'gasto',
        'estado_pago' => 'pagado', 'fecha_pago' => $hoy, 'comprobante_fiscal' => 1, 'revision_fiscal' => 'validado',
    ], $extra), $usuario);

    return $ok ? (int) Gastos::$ultimoId : 0;
};
$campos = static fn (int $id): array => $GLOBALS['db']->query("SELECT uso_cfdi, metodo_pago_sat FROM gastos WHERE id = $id")->fetch_assoc();
/** Encuentra un aviso por su clave exacta. */
$aviso = static function (string $clave): ?array {
    foreach (Seguimiento::avisos() as $a) {
        if ($a['clave'] === $clave) {
            return $a;
        }
    }

    return null;
};

echo "Migración 029\n";
$columna = static fn (string $col): string => (string) $uno(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gastos' AND COLUMN_NAME = '$col'"
);
$prueba('existe gastos.uso_cfdi', $columna('uso_cfdi') === '1');

echo "Captura con factura\n";
$gPpd = $crear(['uso_cfdi' => 'G01', 'metodo_pago_sat' => 'PPD']);
$prueba('guarda el uso del CFDI y el método de pago', $gPpd > 0 && $campos($gPpd) === ['uso_cfdi' => 'G01', 'metodo_pago_sat' => 'PPD'],
    json_encode($gPpd ? $campos($gPpd) : Gastos::getErrores()));
$gPor = $crear(['uso_cfdi' => 'G03', 'metodo_pago_sat' => 'POR']);
$prueba('acepta «por definir»', $gPor > 0 && $campos($gPor)['metodo_pago_sat'] === 'POR', json_encode($gPor ? $campos($gPor) : Gastos::getErrores()));
$gPue = $crear(['metodo_pago_sat' => 'PUE']);
$prueba('acepta PUE', $gPue > 0 && $campos($gPue)['metodo_pago_sat'] === 'PUE', json_encode($gPue ? $campos($gPue) : Gastos::getErrores()));
$prueba('un uso fuera del catálogo no pasa', $crear(['uso_cfdi' => 'ZZ99']) === 0
    && (bool) array_filter(Gastos::getErrores(), fn ($e) => str_contains($e, 'uso del CFDI')));
$prueba('un método desconocido no pasa', $crear(['metodo_pago_sat' => 'XYZ']) === 0
    && (bool) array_filter(Gastos::getErrores(), fn ($e) => str_contains($e, 'método de pago')));

echo "Captura sin factura\n";
$gSin = $crear(['comprobante_fiscal' => 0, 'uso_cfdi' => 'G01', 'metodo_pago_sat' => 'PPD']);
$prueba('limpia uso y método (no hay comprobante)', $gSin > 0
    && $campos($gSin) === ['uso_cfdi' => null, 'metodo_pago_sat' => null], json_encode($gSin ? $campos($gSin) : Gastos::getErrores()));

echo "Edición y acción rápida\n";
$edicion = Gastos::actualizar($gPue, [
    'fecha' => $hoy, 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T28 Proveedor', 'concepto' => 'T28 Factura CFDI',
    'subtotal' => '1000', 'iva_tasa' => '16', 'estado_pago' => 'pagado', 'comprobante_fiscal' => 1,
    'uso_cfdi' => 'I02', 'metodo_pago_sat' => 'PPD',
], $usuario);
$prueba('la edición cambia ambos campos', $edicion && $campos($gPue) === ['uso_cfdi' => 'I02', 'metodo_pago_sat' => 'PPD'],
    json_encode($edicion ? $campos($gPue) : Gastos::getErrores()));
$prueba('metodoCfdi guarda PPD', Gastos::metodoCfdi($gPue, 'PPD', $usuario) && $campos($gPue)['metodo_pago_sat'] === 'PPD',
    implode('|', Gastos::getErrores()));
$prueba('metodoCfdi rechaza un valor no contemplado', !Gastos::metodoCfdi($gPue, 'XYZ', $usuario)
    && (bool) array_filter(Gastos::getErrores(), fn ($e) => str_contains($e, 'método de pago')));
$prueba('metodoCfdi rechaza un gasto inexistente', !Gastos::metodoCfdi(99999999, 'PUE', $usuario) && Gastos::getErrores() !== []);

echo "Centro de seguimiento\n";
$gParcial = $crear(['metodo_pago_sat' => 'PPD']);
$avParcial = $aviso("gasto_cfdi:$gParcial");
$prueba('avisa la factura con pago en parcialidades', $avParcial !== null && str_contains($avParcial['titulo'], 'parcialidades'),
    $avParcial['titulo'] ?? '(sin aviso)');
$avPorDef = $aviso('gasto_cfdi:' . $crear(['metodo_pago_sat' => 'POR']));
$prueba('avisa la factura con método por definir', $avPorDef !== null && str_contains($avPorDef['titulo'], 'por definir'),
    $avPorDef['titulo'] ?? '(sin aviso)');
$gSinMetodo = $crear(['metodo_pago_sat' => '']);
$avSinMetodo = $aviso("gasto_cfdi:$gSinMetodo");
$prueba('una factura guardada sin método también avisa', $avSinMetodo !== null && $avSinMetodo['metodo_cfdi'] === 'POR',
    json_encode($avSinMetodo));
$huellaAntes = $avParcial['huella'] ?? '';
Gastos::metodoCfdi($gParcial, 'POR', $usuario); Gastos::getErrores();
$huellaDespues = $aviso("gasto_cfdi:$gParcial")['huella'] ?? '';
$prueba('la huella refleja el método', $huellaAntes === 'metodoPPD' && $huellaDespues === 'metodoPOR', "$huellaAntes → $huellaDespues");
$gSoloPue = $crear(['metodo_pago_sat' => 'PUE']);
$prueba('una factura PUE no genera aviso', $aviso("gasto_cfdi:$gSoloPue") === null);
$gCambia = $crear(['metodo_pago_sat' => 'PPD']);
$prueba('el aviso desaparece al pasar a PUE', $aviso("gasto_cfdi:$gCambia") !== null
    && Gastos::metodoCfdi($gCambia, 'PUE', $usuario) && $aviso("gasto_cfdi:$gCambia") === null);
Gastos::getErrores();
$prueba('el botón del aviso dice «Abrir gasto»', Seguimiento::etiquetaAbrir('gasto_cfdi:1') === 'Abrir gasto');
$prueba('una factura que no existe no genera el aviso', $aviso('gasto_cfdi:' . ($gCambia + 1000000)) === null);

echo "Lectura del XML\n";
$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Serie="A" Folio="77"'
    . ' Fecha="' . $hoy . 'T10:00:00" TipoDeComprobante="E" Moneda="MXN" SubTotal="1000.00" Total="1160.00" MetodoPago="PPD">'
    . '<cfdi:Emisor Rfc="XAXX010101000" Nombre="Proveedor de prueba" RegimenFiscalReceptor="601"/>'
    . '<cfdi:Receptor Rfc="XAXX010101000" Nombre="Cliente de prueba" RegimenFiscalReceptor="601"'
    . ' DomicilioFiscalReceptor="25000" UsoCFDI="G03"/>'
    . '</cfdi:Comprobante>';
$leido = CfdiXml::leer($xml);
$prueba('el XML trae el uso del CFDI y el método de pago',
    ($leido['datos']['uso_cfdi'] ?? '') === 'G03' && ($leido['datos']['metodo_pago'] ?? '') === 'PPD',
    json_encode([$leido['error'] ?? null, $leido['datos']['uso_cfdi'] ?? null, $leido['datos']['metodo_pago'] ?? null]));

echo "Pantallas\n";
$formulario = (string) file_get_contents(__DIR__ . '/../../templates/formularioGasto.php');
$prueba('el formulario trae el uso del CFDI y el método de pago',
    str_contains($formulario, 'id="uso_cfdi"') && str_contains($formulario, 'id="metodo_pago_sat"'));
$prueba('ofrece las tres opciones del método', array_keys(Gastos::METODOS_CFDI) === ['PPD', 'POR', 'PUE']
    && str_contains($formulario, 'Gastos::METODOS_CFDI') && str_contains($formulario, 'Gastos::USOS_CFDI'));
$prueba('los dos campos dependen de «Con factura»',
    str_contains($formulario, 'data-cfdi') && str_contains($formulario, 'alternarCfdi'));
$detalle = (string) file_get_contents(__DIR__ . '/../../views/admin/contabilidad/gastos/detalle.php');
$prueba('el detalle muestra ambos datos', str_contains($detalle, 'Uso del CFDI') && str_contains($detalle, 'Método de pago'));
$seguimiento = (string) file_get_contents(__DIR__ . '/../../views/admin/seguimiento.php');
$prueba('el seguimiento ofrece definir el método de pago',
    str_contains($seguimiento, "'gasto_cfdi'") && str_contains($seguimiento, 'Definir método de pago')
    && str_contains($seguimiento, 'Gastos::METODOS_CFDI'));
$accion = (string) file_get_contents(__DIR__ . '/../../views/admin/contabilidad/gastos/accion.php');
$prueba('la acción resuelve el método de pago del CFDI',
    str_contains($accion, "'metodo_cfdi'") && str_contains($accion, 'Gastos::metodoCfdi'));

$limpiar();

echo $fallos ? "\nResultado: $fallos fallos\n" : "\nResultado: TODO OK\n";
exit($fallos ? 1 : 0);
