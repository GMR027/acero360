<?php
/**
 * scripts/publicar_android.php — registra un APK firmado de la aplicación Android.
 *
 * Uso (en el servidor, con acceso a la base de datos y al almacén):
 *   php scripts/publicar_android.php <ruta.apk> <versión> ["notas de la versión"]
 *
 * No despliega nada: copia el APK al almacén protegido y lo registra. La versión debe ser
 * MAYOR que la última publicada (Android no instala una «actualización» con número menor).
 */
require __DIR__ . '/../includes/app.php';

[$script, $ruta, $version] = array_pad($argv, 3, '');
$notas = $argv[3] ?? '';

if ($ruta === '' || $version === '') {
    fwrite(STDERR, "Uso: php scripts/publicar_android.php <ruta.apk> <versión> [\"notas\"]\n");
    exit(2);
}

if (\App\AppAndroid::publicar($ruta, $version, $notas, null)) {
    $p = \App\AppAndroid::vigente();
    echo "Publicado: {$p['nombre_archivo']} ({$p['tamano']} bytes, código {$p['version_code']})\nSHA-256: {$p['sha256']}\n";
    exit(0);
}

fwrite(STDERR, implode("\n", \App\AppAndroid::getErrores()) . "\n");
exit(1);
