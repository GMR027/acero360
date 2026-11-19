<?php
/**
 * pruebas/php/etapa55.php — Notificaciones del Centro de seguimiento en la app Android.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa55.php
 *
 * Comprueba la llave de avisos (emitir, usar, ligada a instalación y contraseña, revocar), que
 * es independiente de la llave de huella, y que pendientes() solo entrega avisos sin leer con
 * enlaces internos del panel.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\AvisosAndroid;
use App\HuellaAndroid;
use App\Seguimiento;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$usuario = (int) $uno('SELECT id FROM usuarios WHERE rol = 1 ORDER BY id LIMIT 1');
$instalacion = '55555555-5555-4555-8555-555555555555';
$otra = '66666666-6666-4666-8666-666666666666';
$db->query("DELETE FROM llaves_avisos_android WHERE instalacion IN ('$instalacion', '$otra')");

echo "Llave de avisos\n";
$llave = AvisosAndroid::emitir($usuario, $instalacion);
$prueba('se emite una llave de 64 hex', is_string($llave) && preg_match('/^[0-9a-f]{64}$/', $llave) === 1);
$prueba('solo se guarda el hash', (int) $uno("SELECT COUNT(*) FROM llaves_avisos_android WHERE llave_hash = '" . hash('sha256', $llave) . "'") === 1);
$prueba('la llave abre la lectura de avisos', (AvisosAndroid::usar($llave, $instalacion)['id'] ?? 0) == $usuario);
$prueba('en otra instalación no sirve', AvisosAndroid::usar($llave, $otra) === null);
$prueba('no sirve como llave de huella', HuellaAndroid::usar($llave, $instalacion) === null);
$prueba('llave mal formada → null', AvisosAndroid::usar('xyz', $instalacion) === null);

$segunda = AvisosAndroid::emitir($usuario, $instalacion);
$prueba('emitir otra revoca la anterior', AvisosAndroid::usar($llave, $instalacion) === null && AvisosAndroid::usar($segunda, $instalacion) !== null);

$db->query("UPDATE llaves_avisos_android SET firma_contrasena = REPEAT('0', 64) WHERE llave_hash = '" . hash('sha256', $segunda) . "'");
$prueba('con la contraseña cambiada deja de servir', AvisosAndroid::usar($segunda, $instalacion) === null);

$tercera = AvisosAndroid::emitir($usuario, $instalacion);
AvisosAndroid::revocar($usuario, $instalacion);
$prueba('revocar la invalida', AvisosAndroid::usar($tercera, $instalacion) === null);

echo "Avisos pendientes\n";
$p = AvisosAndroid::pendientes($usuario);
$sinLeer = count(array_filter(Seguimiento::paraUsuario($usuario), static fn ($a) => !$a['leida']));
$prueba('total = avisos sin leer del Centro de seguimiento', $p['total'] === $sinLeer, $p['total'] . ' vs ' . $sinLeer);
$prueba('como máximo 50 por respuesta', count($p['avisos']) <= 50);
$prueba('cada aviso trae id clave|huella, título y enlace', array_reduce($p['avisos'], static fn ($ok, $a) => $ok && str_contains($a['id'], '|') && $a['titulo'] !== '' && $a['enlace'] !== '', true));
$prueba('los enlaces son rutas internas del panel', array_reduce($p['avisos'], static fn ($ok, $a) => $ok && preg_match('#^/admin(/|\?|$)#', $a['enlace']) === 1, true));
if ($p['avisos']) {
    $clave = explode('|', $p['avisos'][0]['id'])[0];
    Seguimiento::marcarLeida($usuario, $clave);
    $despues = AvisosAndroid::pendientes($usuario);
    $prueba('un aviso leído ya no se entrega', !in_array($p['avisos'][0]['id'], array_column($despues['avisos'], 'id'), true));
    $db->query("DELETE FROM seguimiento_lecturas WHERE usuario_id = $usuario AND clave = '" . $db->real_escape_string($clave) . "'");
}

$db->query("DELETE FROM llaves_avisos_android WHERE instalacion IN ('$instalacion', '$otra')");
echo $fallos === 0 ? "Resultado: todas las pruebas pasaron\n" : "Resultado: $fallos fallas\n";
exit($fallos === 0 ? 0 : 1);
