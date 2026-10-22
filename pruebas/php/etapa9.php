<?php
/**
 * pruebas/php/etapa9.php — registro y publicación de paquetes de escritorio.
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa9.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
$almacen = sys_get_temp_dir() . '/escr-prueba-' . bin2hex(random_bytes(4));
putenv("DOCUMENTOS_PATH=$almacen");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\PaquetesEscritorio as P;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$db->query('DELETE FROM paquetes_escritorio');
$usuario = (int) $uno('SELECT id FROM usuarios LIMIT 1');

$falso = sys_get_temp_dir() . '/falso-' . bin2hex(random_bytes(3)) . '.deb';
file_put_contents($falso, "!<arch>\ndebian-binary   0           0     0     100644  4         `\n2.0\n" . str_repeat('A', 4096));
$noDeb = sys_get_temp_dir() . '/malo-' . bin2hex(random_bytes(3)) . '.deb';
file_put_contents($noDeb, str_repeat('X', 4096));

echo "Publicar\n";
$prueba('un archivo que no es .deb se rechaza', !P::publicar($noDeb, '1.0.0', 'amd64', '', null) && str_contains(implode(' ', P::getErrores()), '.deb'));
$prueba('una versión con formato inválido se rechaza', !P::publicar($falso, 'abc', 'amd64', '', null)); P::getErrores();
$prueba('una arquitectura desconocida se rechaza', !P::publicar($falso, '1.0.0', 'mips', '', null)); P::getErrores();
$prueba('publica 1.0.0 (amd64)', P::publicar($falso, '1.0.0', 'amd64', 'Primera versión', null), implode('|', P::getErrores()));
$p = P::vigente('amd64');
$prueba('registra tamaño, SHA-256 y nombre', (int) $p['tamano'] === filesize($falso) && $p['sha256'] === hash_file('sha256', $falso) && $p['nombre_archivo'] === 'cotizaciones-escritorio_1.0.0_amd64.deb');
$prueba('queda fuera de public/ (en el almacén protegido)', is_file("$almacen/escritorio/{$p['nombre_archivo']}") && !str_contains("$almacen/escritorio", '/public/'));
$prueba('una versión publicada NO se sobrescribe', !P::publicar($falso, '1.0.0', 'amd64', '', null) && str_contains(implode(' ', P::getErrores()), 'ya está publicada'));
$prueba('el alta queda en la bitácora', (int) $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'paquete_escritorio' AND accion = 'publicar'") >= 1);

echo "Sustituir y retirar\n";
file_put_contents($falso, "!<arch>\ndebian-binary   0           0     0     100644  4         `\n2.0\n" . str_repeat('B', 4096));
$prueba('publica 1.0.1 y pasa a ser la vigente', P::publicar($falso, '1.0.1', 'amd64', 'Corrección', null) && P::vigente('amd64')['version'] === '1.0.1');
$v1 = (int) $uno("SELECT id FROM paquetes_escritorio WHERE version = '1.0.1'");
$prueba('retirar exige motivo', !P::retirar($v1, 'corto', $usuario)); P::getErrores();
$prueba('retira 1.0.1 con motivo', P::retirar($v1, 'Falla al abrir en Ubuntu 22.04', $usuario));
$prueba('al retirar, vuelve a ofrecerse la anterior (1.0.0)', P::vigente('amd64')['version'] === '1.0.0');
$prueba('la versión retirada se conserva en el registro', (int) $uno('SELECT COUNT(*) FROM paquetes_escritorio') === 2 && $uno("SELECT estado FROM paquetes_escritorio WHERE id = $v1") === 'retirada');
$prueba('no se retira dos veces', !P::retirar($v1, 'Otro motivo suficientemente largo', $usuario)); P::getErrores();

echo "Integridad\n";
$v0 = P::vigente('amd64');
$ruta = "$almacen/escritorio/{$v0['nombre_archivo']}";
file_put_contents($ruta, 'ALTERADO', FILE_APPEND);
$prueba('un archivo alterado ya no coincide con su SHA-256', !hash_equals($v0['sha256'], hash_file('sha256', $ruta)));

$limpiar = function (string $d) use (&$limpiar) { foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $limpiar($f) : unlink($f); } @rmdir($d); };
$limpiar($almacen); @unlink($falso); @unlink($noDeb);

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
