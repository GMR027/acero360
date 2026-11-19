<?php

/**
 * scripts/importar_imagenes_distribuidores.php — Pasa las fachadas heredadas
 * de los distribuidores (migración 056) a public/image.
 *
 * La migración cargó los distribuidores con la ruta de su foto en el sitio
 * (distribuidores/lapaz/…). Mientras no se importen, el sitio las sigue
 * sirviendo desde su propia carpeta. Este script toma cada una, la procesa
 * igual que una subida desde el panel (800×520 + miniatura) y deja en la
 * tabla el archivo nuevo.
 *
 * USO:
 *   php scripts/importar_imagenes_distribuidores.php [--origen=RUTA|URL] [--confirmar]
 *
 *   --origen  Carpeta con las imágenes del sitio (…/solucionesEnAcero/src/img)
 *             o URL base (por omisión, https://solucionesenacero.iguzman.com.mx/build/img).
 *   Sin --confirmar sólo se lista lo que se haría.
 *
 * Es idempotente: sólo toca filas cuya imagen aún empieza con «distribuidores/».
 * Si una imagen no se encuentra, la fila se queda como está.
 */

require __DIR__ . '/../config/entorno.php';
require __DIR__ . '/../includes/app.php';

use App\Distribuidores;
use App\Imagenes;

$opciones = getopt('', ['origen:', 'confirmar']);
$origen = rtrim((string) ($opciones['origen'] ?? Distribuidores::SITIO_URL . '/build/img'), '/');
$confirmar = isset($opciones['confirmar']);
$esUrl = (bool) preg_match('#^https?://#i', $origen);

if (!$esUrl && !is_dir($origen)) {
    fwrite(STDERR, "No existe la carpeta de origen: {$origen}\n");
    exit(1);
}

$filas = $db->query(
    "SELECT id, nombre, imagen FROM distribuidores WHERE imagen LIKE 'distribuidores/%' ORDER BY orden, id"
)->fetch_all(MYSQLI_ASSOC);

echo count($filas) . " fachadas heredadas · origen: {$origen}" . ($confirmar ? '' : ' · (simulación: agrega --confirmar)') . "\n";

$importadas = 0;
$fallidas = 0;
foreach ($filas as $fila) {
    $ruta = (string) $fila['imagen'];
    if (str_contains($ruta, '..')) {
        echo "  omitida  #{$fila['id']} ruta no válida\n";
        $fallidas++;
        continue;
    }

    // Lee la imagen original a un archivo temporal.
    $temporal = tempnam(sys_get_temp_dir(), 'dist');
    if ($esUrl) {
        $url = $origen . '/' . implode('/', array_map('rawurlencode', explode('/', $ruta)));
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE    => Imagenes::TAMANO_MAXIMO,
        ]);
        $contenido = curl_exec($curl);
        $codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $ok = is_string($contenido) && $codigo === 200 && file_put_contents($temporal, $contenido) !== false;
    } else {
        $ok = is_file($origen . '/' . $ruta) && copy($origen . '/' . $ruta, $temporal);
    }

    $tipo = $ok ? (new finfo(FILEINFO_MIME_TYPE))->file($temporal) : '';
    if (!$ok || !in_array($tipo, Imagenes::TIPOS, true)) {
        echo "  falta    #{$fila['id']} {$ruta}\n";
        @unlink($temporal);
        $fallidas++;
        continue;
    }

    if (!$confirmar) {
        echo "  importar #{$fila['id']} {$fila['nombre']} ← {$ruta}\n";
        @unlink($temporal);
        $importadas++;
        continue;
    }

    $nueva = Imagenes::subir([
        'name' => basename($ruta), 'type' => $tipo, 'tmp_name' => $temporal,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($temporal),
    ], 'distribuidor', 'fachada');
    @unlink($temporal);

    if ($nueva === null) {
        echo "  error    #{$fila['id']} {$ruta}: " . Imagenes::ultimoError() . "\n";
        $fallidas++;
        continue;
    }

    // Sólo si la fila sigue con la ruta heredada (nadie la cambió mientras tanto).
    $consulta = $db->prepare('UPDATE distribuidores SET imagen = ? WHERE id = ? AND imagen = ?');
    $id = (int) $fila['id'];
    $consulta->bind_param('sis', $nueva, $id, $ruta);
    $consulta->execute();
    $cambio = $consulta->affected_rows === 1;
    $consulta->close();

    if ($cambio) {
        echo "  ok       #{$id} {$fila['nombre']} → {$nueva}\n";
        $importadas++;
    } else {
        Imagenes::eliminar($nueva);
        echo "  omitida  #{$id} cambió mientras se importaba\n";
    }
}

echo ($confirmar ? 'Importadas' : 'Por importar') . ": {$importadas} · sin importar: {$fallidas}\n";
exit($fallidas > 0 ? 1 : 0);
