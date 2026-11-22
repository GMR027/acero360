<?php
/**
 * scripts/publicar_entregas.php — registra un APK firmado de «Acero Entregas».
 *
 * Uso (en el servidor, con acceso a la base de datos y al almacén):
 *   php scripts/publicar_entregas.php <ruta.apk> <versión> ["notas de la versión"]
 *
 * Es independiente de scripts/publicar_android.php: usa la misma tabla
 * paquetes_android pero la columna aplicacion = 'entregas'. No sobrescribe
 * versiones: cada publicación debe tener un versionCode mayor.
 */
require __DIR__ . '/../includes/app.php';

[$script, $ruta, $version] = array_pad($argv, 3, '');
$notas = $argv[3] ?? '';

if ($ruta === '' || $version === '') {
    fwrite(STDERR, "Uso: php scripts/publicar_entregas.php <ruta.apk> <versión> [\"notas\"]\n");
    exit(2);
}

if (\App\AppAndroid::publicar($ruta, $version, $notas, null, 'entregas')) {
    $p = \App\AppAndroid::vigente('entregas');
    echo "Publicado: {$p['nombre_archivo']} ({$p['tamano']} bytes, código {$p['version_code']})\nSHA-256: {$p['sha256']}\n";
    exit(0);
}

fwrite(STDERR, implode("\n", \App\AppAndroid::getErrores()) . "\n");
exit(1);
