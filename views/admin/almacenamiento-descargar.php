<?php
/**
 * views/admin/almacenamiento-descargar.php — Descarga una copia de seguridad completa.
 *
 * Empaqueta la carpeta del respaldo (base.sql.gz, archivos.tar.gz, SHA256SUMS y
 * MANIFIESTO.txt) en un único .tar.gz y lo envía como adjunto. Ese archivo es el
 * que se guarda fuera del clúster y el que se puede volver a subir desde
 * Ajustes → Almacenamiento para restaurar en otra instalación.
 *
 * Solo administradores con sesión. El sello se valida contra el formato
 * AAAAMMDD-HHMMSS y la ruta se resuelve dentro de la carpeta de respaldos.
 */

soloAdmin();

$sello = (string) ($_GET['sello'] ?? '');
$carpeta = \App\Almacenamiento::rutaCopia($sello);
if ($carpeta === null) {
    redirigir('/admin/ajustes/almacenamiento', 'error', 'Esa copia no existe o no tiene huellas de verificación.');
}

$tar = '/usr/bin/tar';
if (!is_executable($tar)) {
    $tar = '/bin/tar';
}
if (!is_executable($tar)) {
    redirigir('/admin/ajustes/almacenamiento', 'error', 'Esta máquina no tiene «tar»: no se puede preparar la descarga.');
}

\App\Auditoria::registrar('respaldo', null, 'descargar', (int) ($_SESSION['id'] ?? 0), null, ['sello' => $sello]);

set_time_limit(0);

// El buffer global inyecta CSRF en el HTML: aquí se envía un binario.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="respaldo-' . $sello . '.tar.gz"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no');

// tar escribe el paquete directamente a la salida: no se duplica en disco ni en memoria.
$comando = escapeshellarg($tar) . ' -C ' . escapeshellarg(dirname($carpeta)) . ' -czf - ' . escapeshellarg(basename($carpeta));
passthru($comando, $codigo);
exit($codigo === 0 ? 0 : 1);
