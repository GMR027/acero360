<?php
/**
 * scripts/migrar.php — aplica las migraciones de mysql/migraciones/NNN_*.sql
 * que todavía no consten en la tabla schema_migraciones.
 *
 * Uso:  php scripts/migrar.php [nombre_de_base]
 * Sin argumento usa DB_NAME del entorno (.env). Cada archivo corre una sola vez.
 */

require __DIR__ . '/../config/entorno.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$base = $argv[1] ?? (getenv('DB_NAME') ?: 'cotizaciones');

// En el clúster la base puede tardar en estar lista: MIGRAR_REINTENTOS intentos, cada 5 s (por defecto 1).
$intentos = max(1, (int) (getenv('MIGRAR_REINTENTOS') ?: 1));
$db = null;
for ($i = 1; $i <= $intentos; $i++) {
    try {
        $db = new mysqli(getenv('DB_HOST') ?: 'localhost', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', $base, 3306);
        $esquema = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'usuarios'")->fetch_row()[0];
        if ((int) $esquema === 0) {
            throw new RuntimeException('La base está vacía (falta el esquema inicial): no se aplica ninguna migración.');
        }
        break;
    } catch (Throwable $error) {
        fwrite(STDERR, "Intento $i/$intentos: " . $error->getMessage() . "\n");
        if ($i === $intentos) {
            exit(3);
        }
        sleep(5);
    }
}
$db->set_charset('utf8mb4');

$db->query('CREATE TABLE IF NOT EXISTS schema_migraciones (
    nombre VARCHAR(120) NOT NULL PRIMARY KEY,
    aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$aplicadas = array_column($db->query('SELECT nombre FROM schema_migraciones')->fetch_all(MYSQLI_ASSOC), 'nombre');
$archivos = glob(__DIR__ . '/../mysql/migraciones/[0-9][0-9][0-9]_*.sql');
sort($archivos);

foreach ($archivos as $archivo) {
    $nombre = basename($archivo);
    if (str_contains($nombre, 'reversion') || in_array($nombre, $aplicadas, true)) {
        continue;
    }

    echo "Aplicando {$nombre} en {$base}... ";
    $db->multi_query(file_get_contents($archivo));
    do {
        if ($resultado = $db->store_result()) {
            $resultado->free();
        }
    } while ($db->more_results() && $db->next_result());

    $registrar = $db->prepare('INSERT INTO schema_migraciones (nombre) VALUES (?)');
    $registrar->bind_param('s', $nombre);
    $registrar->execute();
    echo "ok\n";
}
echo "Migraciones al día.\n";
