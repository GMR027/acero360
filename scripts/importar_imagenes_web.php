<?php
/**
 * scripts/importar_imagenes_web.php — Carga en bloque imágenes del catálogo web.
 *
 * Sube con el mismo procesamiento que el formulario del panel:
 *   - imagen de uso de un producto (modo 'informe', conserva proporción)
 *   - portada de una categoría (modo 'cuadrada')
 *
 * Uso:
 *   php scripts/importar_imagenes_web.php <carpeta> [--reemplazar] [base]
 *
 * La carpeta trae las imágenes y un archivo mapa.txt, una línea por imagen:
 *   uso        RA-1                Set-Ra1.png
 *   portada    registros-de-angulo Ra3-P.png
 * (# inicia comentario). Sin --reemplazar no toca lo que ya tiene imagen.
 *
 * En el clúster:
 *   kubectl -n cotizaciones cp ./carpeta deploy/cotizaciones:/tmp/imagenes-web   (o el nombre del pod)
 *   kubectl -n cotizaciones exec deploy/cotizaciones -- php scripts/importar_imagenes_web.php /tmp/imagenes-web
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = array_slice($argv, 1);
$reemplazar = in_array('--reemplazar', $args, true);
$args = array_values(array_filter($args, fn ($a) => $a !== '--reemplazar'));
$carpeta = rtrim((string) ($args[0] ?? ''), '/');
if (isset($args[1])) {
    putenv('DB_NAME=' . $args[1]);
}

if ($carpeta === '' || !is_file("$carpeta/mapa.txt")) {
    fwrite(STDERR, "Uso: php scripts/importar_imagenes_web.php <carpeta con mapa.txt> [--reemplazar] [base]\n");
    exit(2);
}

require __DIR__ . '/../config/entorno.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../includes/app.php';

use App\Imagenes;

$ok = 0;
$omitidas = 0;
$errores = 0;

foreach (file("$carpeta/mapa.txt", FILE_IGNORE_NEW_LINES) as $n => $linea) {
    $linea = trim(preg_replace('/#.*/', '', $linea));
    if ($linea === '') {
        continue;
    }
    $partes = preg_split('/\s+/', $linea, 3);
    if (count($partes) !== 3 || !in_array($partes[0], ['uso', 'portada'], true)) {
        fwrite(STDERR, "Línea " . ($n + 1) . ": formato inválido ($linea)\n");
        $errores++;
        continue;
    }
    [$tipo, $clave, $nombreArchivo] = $partes;
    $ruta = $carpeta . '/' . basename($nombreArchivo);

    if (!is_file($ruta)) {
        fwrite(STDERR, "  ✗ $clave: no existe $ruta\n");
        $errores++;
        continue;
    }

    [$tabla, $columna, $campoClave, $prefijo, $modo] = $tipo === 'uso'
        ? ['productos', 'imagen_uso', 'sku', 'producto_uso', 'informe']
        : ['categorias_producto', 'imagen', 'slug', 'categoria', 'cuadrada'];

    $consulta = $db->prepare("SELECT id, $columna AS actual FROM $tabla WHERE $campoClave = ? LIMIT 1");
    $consulta->bind_param('s', $clave);
    $consulta->execute();
    $fila = $consulta->get_result()->fetch_assoc();
    $consulta->close();

    if (!$fila) {
        fwrite(STDERR, "  ✗ $clave: no existe en $tabla.$campoClave\n");
        $errores++;
        continue;
    }
    if (!empty($fila['actual']) && !$reemplazar) {
        echo "  -  $clave: ya tiene imagen ({$fila['actual']}), se omite\n";
        $omitidas++;
        continue;
    }

    // Copia temporal: Imagenes::subir trabaja con un elemento tipo $_FILES.
    $temporal = tempnam(sys_get_temp_dir(), 'imgweb');
    copy($ruta, $temporal);
    $nuevo = Imagenes::subir([
        'name' => basename($ruta),
        'type' => (string) mime_content_type($ruta),
        'tmp_name' => $temporal,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($ruta),
    ], $prefijo, $modo, 20 * 1024 * 1024);
    @unlink($temporal);

    if ($nuevo === null) {
        fwrite(STDERR, "  ✗ $clave: " . Imagenes::ultimoError() . "\n");
        $errores++;
        continue;
    }

    $id = (int) $fila['id'];
    $actualizar = $db->prepare("UPDATE $tabla SET $columna = ? WHERE id = ?");
    $actualizar->bind_param('si', $nuevo, $id);
    $actualizar->execute();
    $actualizar->close();
    if (!empty($fila['actual'])) {
        Imagenes::eliminar($fila['actual']);
    }

    echo "  ✓ $tipo $clave → $nuevo\n";
    $ok++;
}

echo "Resultado: $ok importadas, $omitidas omitidas, $errores con error\n";
exit($errores === 0 ? 0 : 1);
