<?php
/**
 * scripts/publicar_escritorio.php — registra un paquete .deb de la aplicación de escritorio.
 *
 * Uso (en el servidor, con acceso a la base de datos y al almacén):
 *   php scripts/publicar_escritorio.php <ruta.deb> <versión> [amd64] ["notas de la versión"]
 *
 * No despliega nada ni toca el clúster: solo copia el archivo al almacén protegido y lo registra.
 * Una versión ya publicada no se sobrescribe (usa un número nuevo).
 */
require __DIR__ . '/../includes/app.php';

[$script, $ruta, $version] = array_pad($argv, 3, '');
$arquitectura = $argv[3] ?? 'amd64';
$notas = $argv[4] ?? '';

if ($ruta === '' || $version === '') {
    fwrite(STDERR, "Uso: php scripts/publicar_escritorio.php <ruta.deb> <versión> [amd64] [\"notas\"]\n");
    exit(2);
}

if (\App\PaquetesEscritorio::publicar($ruta, $version, $arquitectura, $notas, null)) {
    $p = \App\PaquetesEscritorio::vigente($arquitectura);
    echo "Publicado: {$p['nombre_archivo']} ({$p['tamano']} bytes)\nSHA-256: {$p['sha256']}\n";
    exit(0);
}

fwrite(STDERR, implode("\n", \App\PaquetesEscritorio::getErrores()) . "\n");
exit(1);
