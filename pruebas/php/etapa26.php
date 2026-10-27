<?php
/**
 * pruebas/php/etapa26.php — Almacenamiento: ocupación de disco y copias de seguridad.
 *
 * Comprueba:
 *   · formatearBytes y porcentaje (incluido el caso «1.0 MB», que no debe perder el cero);
 *   · ocupación: suma las áreas y las ordena de mayor a menor;
 *   · carpeta de respaldos configurable, pero sólo con rutas absolutas;
 *   · crear una copia: los cuatro archivos, permisos 600, manifiesto y huellas verificables;
 *   · que el respaldo usa la MISMA base con la que está conectada la aplicación
 *     (el entorno manda sobre .env: era el bug que hacía respaldar otra base);
 *   · retención (sólo quedan las últimas N copias);
 *   · respaldo automático: no corre si está apagado, si aún no es la hora o si ya corrió hoy;
 *   · restaurar: crea una base NUEVA con las mismas tablas y nunca pisa la actual;
 *   · que restaurar se niega sin huellas SHA-256.
 *
 * Los respaldos se generan en /tmp para no ensuciar la carpeta del proyecto.
 *
 * DB_NAME=cotizaciones_test php pruebas/php/etapa26.php
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') { fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\Almacenamiento as A;
use App\Configuracion;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];

$carpetaPrueba = sys_get_temp_dir() . '/a360-respaldos-' . getmypid();
$limpiar = function () use ($db, $carpetaPrueba) {
    foreach ($db->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'cotizaciones_restaurada_%'")->fetch_all() as $f) {
        $db->query('DROP DATABASE IF EXISTS `' . $f[0] . '`');
    }
    foreach (glob($carpetaPrueba . '/*') ?: [] as $d) {
        foreach (glob("$d/*") ?: [] as $archivo) { @unlink($archivo); }
        @rmdir($d);
    }
    @rmdir($carpetaPrueba);
    foreach (glob(__DIR__ . '/../../storage/restauracion-*') ?: [] as $d) {
        foreach (glob("$d/*/*") ?: [] as $archivo) { @unlink($archivo); }
        @rmdir($d);
    }
};
$limpiar();
$guardar = [];
foreach ([A::CFG_AUTOMATICO, A::CFG_HORA, A::CFG_RETENER, A::CFG_CARPETA, A::CFG_ULTIMO] as $clave) {
    $guardar[$clave] = Configuracion::obtener($clave, '__ausente__');
}

echo "Formateo\n";
$prueba('bytes por debajo de 1 KB', A::formatearBytes(0) === '0 B' && A::formatearBytes(512) === '512 B');
$prueba('kilobytes', A::formatearBytes(1536) === '1.5 KB');
$prueba('el cero decimal se conserva (1.0 MB, no «1. MB»)', A::formatearBytes(1048576) === '1.0 MB', A::formatearBytes(1048576));
$prueba('de cifras enteras salen sin decimal', A::formatearBytes(157286400) === '150 MB', A::formatearBytes(157286400));
$prueba('gigabytes', A::formatearBytes(1073741824) === '1.0 GB');
$prueba('porcentaje', A::porcentaje(25, 100) === 25 && A::porcentaje(1, 3) === 33);
$prueba('porcentaje sin total no divide entre cero', A::porcentaje(10, 0) === 0);

echo "Ocupación\n";
$ocup = A::ocupacion();
$claves = array_column($ocup['areas'], 'clave');
$prueba('trae las cinco áreas', count($claves) === 5 && !array_diff(['base', 'documentos', 'archivo', 'imagenes', 'respaldos'], $claves));
$prueba('el total es la suma de las áreas', $ocup['total'] === array_sum(array_column($ocup['areas'], 'bytes')));
$prueba('base + archivos = total', $ocup['base'] + $ocup['archivos'] === $ocup['total']);
$bytes = array_column($ocup['areas'], 'bytes');
$ordenado = $bytes;
rsort($ordenado);
$prueba('las áreas vienen de mayor a menor', $bytes === $ordenado);
$prueba('la base de datos ocupa algo', $ocup['base'] > 0);

echo "Carpeta de respaldos\n";
Configuracion::guardar(A::CFG_CARPETA, '');
$prueba('vacío usa la carpeta por defecto', A::carpetaRespaldos() === A::RAIZ . '/respaldos');
Configuracion::guardar(A::CFG_CARPETA, '/ruta/absoluta');
$prueba('una ruta absoluta se respeta', A::carpetaRespaldos() === '/ruta/absoluta');
Configuracion::guardar(A::CFG_CARPETA, 'ruta/relativa');
$prueba('una relativa se rechaza (no se resolvería contra la web)', A::carpetaRespaldos() === A::RAIZ . '/respaldos');
Configuracion::guardar(A::CFG_CARPETA, $carpetaPrueba);
$prueba('la carpeta de prueba se usa', A::carpetaRespaldos() === $carpetaPrueba);
$prueba('sin copias, la lista sale vacía', A::listar() === []);

echo "Herramientas del servidor\n";
$faltan = A::faltantes();
$prueba('devuelve un arreglo', is_array($faltan));
$prueba('en este entorno hay mysqldump y tar (si faltan, el resto se salta)', in_array('mysqldump', $faltan, true) || in_array('tar', $faltan, true)
    ? false : true, 'faltan: ' . implode(', ', $faltan));

if ($faltan) {
    echo "Sin las herramientas no se puede respaldar: se omite la parte de copias.\n";
} else {
    echo "Crear copia\n";
    $r = A::crear();
    $prueba('el respaldo termina bien', $r['ok'], $r['mensaje']);
    $carpeta = $r['carpeta'];
    $prueba('devuelve la carpeta creada', $carpeta !== null && is_dir($carpeta));
    $archivos = ['base.sql.gz', 'archivos.tar.gz', 'SHA256SUMS', 'MANIFIESTO.txt'];
    foreach ($archivos as $archivo) {
        $prueba("incluye $archivo", is_readable("$carpeta/$archivo") && filesize("$carpeta/$archivo") > 0);
    }
    $prueba('los archivos no son legibles por otros usuarios', posix_getpwuid(fileowner("$carpeta/base.sql.gz"))['name'] === get_current_user()
        ? true : (int) substr(sprintf('%o', fileperms("$carpeta/base.sql.gz")), -3) === 600);
    $manifiesto = (string) file_get_contents("$carpeta/MANIFIESTO.txt");
    $prueba('el manifiesto lista las migraciones', str_contains($manifiesto, 'Migraciones:'));
    $prueba('el manifiesto cuenta las tablas', (int) ($uno("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")) > 0
        && str_contains($manifiesto, 'Tablas:'));
    $prueba('RESPALDA LA BASE CONECTADA, no la del .env (bug de precedencia)',
        str_contains($manifiesto, 'Base:         ' . getenv('DB_NAME')), trim(explode("\n", $manifiesto)[2]));
    $prueba('el volcado es gzip válido', (bool) @gzopen("$carpeta/base.sql.gz", 'rb'));
    [$ok, $salida] = [(int) shell_exec('cd ' . escapeshellarg($carpeta) . ' && sha256sum -c SHA256SUMS >/dev/null 2>&1; echo $?'), ''];
    $prueba('las huellas SHA-256 verifican', $ok === 0);
    $prueba('el listado la muestra con sus datos', count(A::listar()) === 1 && A::listar()[0]['sello'] === basename($carpeta)
        && A::listar()[0]['incompleta'] === false);
    $prueba('el resumen cuenta la copia', A::resumen()['copias'] === 1 && A::resumen()['bytes'] > 0);
    $prueba('último queda registrado', A::ultimo()[1] === 'ok');

    echo "Retención\n";
    Configuracion::guardar(A::CFG_RETENER, '2');
    $prueba('retener se limita a 1..365', A::retener() === 2);
    foreach (['-5' => 1, '9999' => 365] as $valor => $esperado) {
        Configuracion::guardar(A::CFG_RETENER, (string) $valor);
        $prueba("retener $valor → $esperado", A::retener() === $esperado, (string) A::retener());
    }
    Configuracion::guardar(A::CFG_RETENER, '2');
    usleep(1100000);
    A::crear();
    usleep(1100000);
    A::crear();
    $prueba('al respaldar se borran las más antiguas', count(A::listar()) === 2,
        'quedaron ' . count(A::listar()) . ' con retener=' . A::retener() . ' en ' . A::carpetaRespaldos() . ' [' . implode(', ', array_column(A::listar(), 'sello')) . ']');

    echo "Respaldo automático\n";
    Configuracion::guardar(A::CFG_AUTOMATICO, '0');
    $prueba('apagado no hace nada', A::respaldoDelDia() === null);
    Configuracion::guardar(A::CFG_AUTOMATICO, '1');
    Configuracion::guardar(A::CFG_HORA, '23:59');
    Configuracion::guardar(A::CFG_ULTIMO, '');
    $prueba('si aún no es la hora, espera', A::respaldoDelDia() === null, 'hora actual ' . date('H:i'));
    Configuracion::guardar(A::CFG_HORA, '00:00');
    $selloAntes = A::listar()[0]['sello'] ?? '';
    usleep(1100000);   // los sellos tienen precisión de segundo: hay que esperar a que cambie
    $r = A::respaldoDelDia();
    $prueba('pasada la hora genera el respaldo del día', $r !== null && $r['ok'] && (A::listar()[0]['sello'] ?? '') !== $selloAntes,
        $r['mensaje'] ?? '(sin respuesta)');
    $prueba('  y queda registrado como último', A::ultimo()[1] === 'ok');
    $prueba('  y el mensaje aclara que fue el automático', $r !== null && str_contains($r['mensaje'], 'automático'));
    $prueba('ya respaldado hoy no repite', A::respaldoDelDia() === null);
    putenv('RESPALDO_ORIGEN=cron');
    usleep(1100000);
    A::crear();
    putenv('RESPALDO_ORIGEN');
    Configuracion::guardar(A::CFG_ULTIMO, '');
    $prueba('no duplica el respaldo diario que ya hizo el CronJob', A::respaldoDelDia() === null);
    Configuracion::guardar(A::CFG_HORA, '23:00');

    echo "Restaurar\n";
    Configuracion::guardar(A::CFG_RETENER, '14');
    $sello = A::listar()[0]['sello'];
    $tablas = (int) $uno('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
    $r = A::restaurar($sello);
    $prueba('la restauración termina bien', $r['ok'], $r['mensaje']);
    $prueba('crea una base NUEVA', $r['base'] !== null && str_starts_with($r['base'], 'cotizaciones_restaurada_'));
    $prueba('  con las mismas tablas que el origen', $r['base'] !== null
        && (int) $uno("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$r['base']}'") === $tablas);
    $prueba('  y la base viva sigue intacta', (int) $uno('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === $tablas);
    $prueba('  con los mismos registros', $r['base'] !== null
        && (int) $uno("SELECT COUNT(*) FROM `{$r['base']}`.usuarios") === (int) $uno('SELECT COUNT(*) FROM usuarios'));
    $prueba('extrae los archivos a una carpeta aparte', $r['carpeta'] !== null && is_dir($r['carpeta']) && count(scandir($r['carpeta'])) > 2);
    $prueba('un segundo restaurar no pisa el primero', A::restaurar($sello)['base'] !== $r['base']);
    $prueba('rechaza un sello inventado', A::restaurar('no-es-un-sello')['ok'] === false);
    $sinHuellas = $carpetaPrueba . '/20990101-000000';
    mkdir($sinHuellas, 0700, true);
    file_put_contents("$sinHuellas/base.sql.gz", 'x');
    $prueba('se niega a restaurar sin SHA256SUMS', A::restaurar('20990101-000000')['ok'] === false);

    echo "Hora\n";
    Configuracion::guardar(A::CFG_HORA, '23:00');
    $prueba('una hora válida se conserva', A::hora() === '23:00');
    Configuracion::guardar(A::CFG_HORA, '7:30');
    $prueba('una hora mal formateada cae en 23:00', A::hora() === '23:00', A::hora());
    Configuracion::guardar(A::CFG_HORA, '25:00');
    $prueba('fuera de rango cae en 23:00', A::hora() === '23:00');
    Configuracion::guardar(A::CFG_HORA, '02:30');
    $prueba('la línea de cron usa la hora elegida', str_starts_with(A::lineaCron(), '30 2 * * *'));
    $prueba('la línea de cron apunta al script y a la carpeta',
        str_contains(A::lineaCron(), 'respaldar.sh') && str_contains(A::lineaCron(), escapeshellarg($carpetaPrueba)),
        'cron=[' . A::lineaCron() . '] esperaba=[' . escapeshellarg($carpetaPrueba) . '] carpeta config=[' . A::carpetaRespaldos() . ']');
}

echo "Limpieza\n";
foreach ($guardar as $clave => $valor) {
    if ($valor === '__ausente__') {
        $db->query("DELETE FROM configuracion WHERE clave = '" . $db->real_escape_string($clave) . "'");
    } else {
        Configuracion::guardar($clave, $valor);
    }
}
$limpiar();
$prueba('no queda nada de la prueba', A::listar() === []
    && (int) $uno("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name LIKE 'cotizaciones_restaurada_%'") === 0);

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos prueba(s) fallaron\n";
exit($fallos === 0 ? 0 : 1);
