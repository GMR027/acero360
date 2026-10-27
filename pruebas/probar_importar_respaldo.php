<?php
/**
 * pruebas/probar_importar_respaldo.php — Importación de una copia EXTERNA (.tar.gz).
 *
 * No toca la carpeta real de respaldos: apunta `respaldo_carpeta` a un directorio
 * temporal mientras dure la prueba y lo restaura al terminar. Comprueba:
 *   • un paquete válido se verifica y queda como copia del clúster;
 *   • un segundo paquete con el mismo sello no lo pisa (usa el siguiente segundo);
 *   • un paquete alterado se rechaza y no entra;
 *   • una ruta con «..» se rechaza.
 *
 * Uso: php pruebas/probar_importar_respaldo.php
 */

require dirname(__DIR__) . '/includes/app.php';

$ok = 0;
$mal = 0;
function verifica(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $mal;
    if ($condicion) {
        $ok++;
        echo "  ✓ $titulo\n";
    } else {
        $mal++;
        echo "  ✗ $titulo" . ($detalle !== '' ? " ($detalle)" : '') . "\n";
    }
}

$tmp = sys_get_temp_dir() . '/respaldo-import-' . bin2hex(random_bytes(6));
$configPrevia = \App\Configuracion::obtener(\App\Almacenamiento::CFG_CARPETA, '');
\App\Configuracion::guardar(\App\Almacenamiento::CFG_CARPETA, $tmp);

/** Crea un paquete con el formato de scripts/respaldar.sh. */
function paquete(string $base, string $sello, string $nombre = 'paquete.tar.gz'): string
{
    $carpeta = "$base/origen/$sello";
    @mkdir($carpeta, 0700, true);
    file_put_contents("$carpeta/base.sql.gz", gzencode("-- respaldo de prueba\n"));
    file_put_contents("$carpeta/archivos.tar.gz", gzencode(''));
    file_put_contents("$carpeta/MANIFIESTO.txt", "Fecha: 2099-01-01T01:02:03-06:00\nTablas: 3\n");
    exec('cd ' . escapeshellarg($carpeta) . ' && sha256sum base.sql.gz archivos.tar.gz > SHA256SUMS');
    exec('tar -C ' . escapeshellarg("$base/origen") . ' -czf ' . escapeshellarg("$base/$nombre") . ' ' . escapeshellarg($sello));
    exec('rm -rf ' . escapeshellarg("$base/origen"));

    return "$base/$nombre";
}

echo "Importación de copias externas\n";

try {
    $paquete = paquete($tmp, '20990101-010203');
    $r = \App\Almacenamiento::importarPaquete($paquete);
    verifica('un paquete válido se importa', $r['ok'], $r['mensaje']);
    verifica('se guarda con el sello de la carpeta', ($r['sello'] ?? '') === '20990101-010203');
    verifica('queda en la carpeta de respaldos', is_file("$tmp/20990101-010203/base.sql.gz"));
    $lista = \App\Almacenamiento::listar();
    verifica('aparece en la lista de copias', count(array_filter($lista, static fn ($c) => $c['sello'] === '20990101-010203')) === 1);

    $r2 = \App\Almacenamiento::importarPaquete($paquete);
    verifica('un sello repetido no pisa la copia anterior', $r2['ok'] && $r2['sello'] === '20990101-010204', (string) ($r2['sello'] ?? ''));
    verifica('la copia original sigue intacta', is_file("$tmp/20990101-010203/base.sql.gz"));

    $alterado = "$tmp/alterado.tar.gz";
    copy($paquete, $alterado);
    $datos = file_get_contents($alterado);
    $datos[strlen($datos) - 30] = chr(ord($datos[strlen($datos) - 30]) ^ 0xFF);
    file_put_contents($alterado, $datos);
    $r3 = \App\Almacenamiento::importarPaquete($alterado);
    verifica('un paquete alterado se rechaza', !$r3['ok'], $r3['mensaje']);

    $malicioso = "$tmp/malicioso.tar.gz";
    exec('cd ' . escapeshellarg($tmp) . ' && tar -czf ' . escapeshellarg($malicioso) . ' --transform=' . escapeshellarg('s#^#../#') . ' 20990101-010203');
    $r4 = \App\Almacenamiento::importarPaquete($malicioso);
    verifica('una ruta con «..» se rechaza sin extraer', !$r4['ok'], $r4['mensaje']);
} finally {
    \App\Configuracion::guardar(\App\Almacenamiento::CFG_CARPETA, $configPrevia);
    exec('rm -rf ' . escapeshellarg($tmp));
}

echo "\nResultado: $ok correctas · $mal fallos\n";
exit($mal === 0 ? 0 : 1);
