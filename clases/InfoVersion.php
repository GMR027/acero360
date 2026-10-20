<?php

namespace App;

/**
 * InfoVersion — versión instalada, compilación, infraestructura y última versión publicada.
 *
 * Todo sale de fuentes reales, nada se escribe a mano en varios lados:
 *   • Versión       → archivo VERSION de la raíz (única fuente; deploy.sh y la app de escritorio la leen).
 *   • Compilación   → build-info.json, que genera el Dockerfile con los datos que pasa deploy.sh
 *                     (tag de la imagen, commit, fecha). En desarrollo se usa el git local y la fecha de build/.
 *   • Infraestructura → PHP, servidor web, MySQL, contenedor y espacio de Kubernetes en ejecución.
 *   • Última publicada → etiquetas numéricas del repositorio de la imagen en Docker Hub (con caché de 6 h).
 * Solo lectura; si no hay red, el estado dice «sin verificar» en lugar de inventar un dato.
 */
class InfoVersion
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    private const RAIZ = __DIR__ . '/..';
    private const CACHE_HORAS = 6;
    private const IMAGEN_POR_DEFECTO = 'gmr27/cotizaciones';

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    private static function rutaCache(): string
    {
        return self::RAIZ . '/storage/cache-version.json';
    }

    /**
     * Datos de la compilación en ejecución.
     *
     * @return array{origen: string, version: string, imagen: string, tag: ?string, commit: ?string, compilado: ?string}
     */
    public static function compilacion(): array
    {
        $archivo = self::RAIZ . '/build-info.json';
        $datos = is_readable($archivo) ? json_decode((string) file_get_contents($archivo), true) : null;
        if (is_array($datos) && !empty($datos['version'])) {
            return [
                'origen'    => 'imagen',
                'version'   => (string) $datos['version'],
                'imagen'    => (string) ($datos['imagen'] ?? self::IMAGEN_POR_DEFECTO),
                'tag'       => ($datos['tag'] ?? '') !== '' ? (string) $datos['tag'] : null,
                'commit'    => ($datos['commit'] ?? '') !== '' ? (string) $datos['commit'] : null,
                'compilado' => ($datos['compilado'] ?? '') !== '' ? (string) $datos['compilado'] : null,
            ];
        }

        // Copia de desarrollo: no hay imagen; el commit sale del git local y la fecha de los recursos compilados.
        $commit = null;
        if (is_dir(self::RAIZ . '/.git') && function_exists('exec')) {
            $salida = [];
            @exec('git -C ' . escapeshellarg(self::RAIZ) . ' rev-parse --short HEAD 2>/dev/null', $salida);
            $commit = $salida[0] ?? null;
        }
        $recursos = self::RAIZ . '/build/js/app.js';

        return [
            'origen'    => 'desarrollo',
            'version'   => ACERO360_VERSION,
            'imagen'    => self::IMAGEN_POR_DEFECTO,
            'tag'       => null,
            'commit'    => $commit,
            'compilado' => is_file($recursos) ? gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($recursos)) : null,
        ];
    }

    /** @return array<string, string> datos del entorno donde corre la aplicación ahora mismo */
    public static function infraestructura(): array
    {
        $mysql = '';
        $comentario = '';
        try {
            $fila = self::$db->query('SELECT VERSION() AS v, @@version_comment AS c')->fetch_assoc();
            $mysql = (string) ($fila['v'] ?? '');
            $comentario = (string) ($fila['c'] ?? '');
        } catch (\Throwable $e) {
            $mysql = '';
        }
        $espacio = '/var/run/secrets/kubernetes.io/serviceaccount/namespace';
        $enKubernetes = getenv('KUBERNETES_SERVICE_HOST') !== false;

        return [
            'php'          => PHP_VERSION,
            'servidor_web' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI),
            'mysql'        => $mysql,
            'mysql_motor'  => $comentario,
            'sistema'      => php_uname('s') . ' ' . php_uname('r'),
            'contenedor'   => (string) gethostname(),
            'plataforma'   => $enKubernetes ? 'Kubernetes' . (is_readable($espacio) ? ' · espacio ' . trim((string) file_get_contents($espacio)) : '') : 'Servidor propio / desarrollo',
            'zona_horaria' => date_default_timezone_get(),
        ];
    }

    /**
     * Etiqueta numérica más reciente de la imagen en Docker Hub.
     *
     * @return array{ok: bool, tag?: int, publicada?: string, consultado: string, error?: string, desactualizado?: bool}
     */
    public static function ultimaPublicada(bool $forzar = false): array
    {
        $cache = null;
        if (is_readable(self::rutaCache())) {
            $leido = json_decode((string) file_get_contents(self::rutaCache()), true);
            $cache = is_array($leido) && isset($leido['consultado_ts']) ? $leido : null;
        }
        $edad = $cache ? time() - (int) $cache['consultado_ts'] : PHP_INT_MAX;
        if ($cache && $cache['ok'] && ($edad < self::CACHE_HORAS * 3600) && !($forzar && $edad > 60)) {
            return $cache;
        }

        $repositorio = self::compilacion()['imagen'];
        $resultado = ['ok' => false, 'consultado' => gmdate('Y-m-d\TH:i:s\Z'), 'consultado_ts' => time(), 'error' => 'Repositorio no válido.'];
        if (preg_match('#^[a-z0-9._-]+/[a-z0-9._-]+$#', $repositorio)) {
            $contexto = stream_context_create(['http' => ['timeout' => 4, 'header' => "Accept: application/json\r\nUser-Agent: Acero360\r\n", 'ignore_errors' => true]]);
            $cuerpo = @file_get_contents('https://hub.docker.com/v2/repositories/' . $repositorio . '/tags?page_size=100&ordering=last_updated', false, $contexto);
            $json = $cuerpo !== false ? json_decode($cuerpo, true) : null;
            if (is_array($json) && isset($json['results']) && is_array($json['results'])) {
                $mejor = null;
                foreach ($json['results'] as $etiqueta) {
                    if (preg_match('/^\d+$/', (string) ($etiqueta['name'] ?? '')) && ($mejor === null || (int) $etiqueta['name'] > (int) $mejor['name'])) {
                        $mejor = $etiqueta;
                    }
                }
                $resultado = $mejor
                    ? ['ok' => true, 'tag' => (int) $mejor['name'], 'publicada' => (string) ($mejor['last_updated'] ?? ''), 'consultado' => $resultado['consultado'], 'consultado_ts' => time()]
                    : ['ok' => false, 'consultado' => $resultado['consultado'], 'consultado_ts' => time(), 'error' => 'El repositorio no tiene etiquetas numéricas.'];
            } else {
                $resultado['error'] = 'No se pudo consultar Docker Hub desde el servidor.';
            }
        }

        if (!$resultado['ok'] && $cache && $cache['ok']) {
            $cache['desactualizado'] = true; // se muestra el último dato bueno, avisando que no es de ahora
            return $cache;
        }
        if (is_dir(dirname(self::rutaCache())) && is_writable(dirname(self::rutaCache()))) {
            @file_put_contents(self::rutaCache(), json_encode($resultado));
        }

        return $resultado;
    }

    /**
     * Estado de actualización: al_dia | disponible | desarrollo | sin_verificar.
     *
     * @return array{clave: string, texto: string}
     */
    public static function estado(array $compilacion, array $ultima): array
    {
        if (!$ultima['ok']) {
            return ['clave' => 'sin_verificar', 'texto' => ($ultima['error'] ?? 'No se pudo verificar') . ' No se puede saber si hay una versión más reciente.'];
        }
        if ($compilacion['origen'] === 'desarrollo' || $compilacion['tag'] === null || !ctype_digit($compilacion['tag'])) {
            return ['clave' => 'desarrollo', 'texto' => 'Esta es una copia de desarrollo, no una imagen desplegada. La última imagen publicada es la ' . $ultima['tag'] . '.'];
        }
        if ((int) $compilacion['tag'] >= $ultima['tag']) {
            return ['clave' => 'al_dia', 'texto' => 'Estás en la última versión publicada.'];
        }

        return ['clave' => 'disponible', 'texto' => 'Hay una imagen más reciente publicada (' . $ultima['tag'] . ' frente a la ' . $compilacion['tag'] . '). Se aplica al desplegar con deploy.sh.'];
    }

    /**
     * Requisitos que la aplicación necesita y lo que hay en este entorno.
     *
     * @return array<int, array{nombre: string, requerido: string, detectado: string, ok: ?bool, nota: string}>
     */
    public static function compatibilidad(array $infra): array
    {
        $filas = [];

        $composer = json_decode((string) @file_get_contents(self::RAIZ . '/composer.json'), true);
        $requierePhp = (string) ($composer['require']['php'] ?? '');
        if (preg_match('/(\d+\.\d+(\.\d+)?)/', $requierePhp, $m)) {
            $filas[] = ['nombre' => 'PHP', 'requerido' => '≥ ' . $m[1], 'detectado' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, $m[1], '>='), 'nota' => 'Mínimo que declara composer.json.'];
        }

        $faltan = array_values(array_filter(['mysqli', 'gd', 'zip', 'mbstring'], static fn ($e) => !extension_loaded($e)));
        $filas[] = ['nombre' => 'Extensiones de PHP', 'requerido' => 'mysqli, gd, zip, mbstring', 'detectado' => $faltan ? 'faltan: ' . implode(', ', $faltan) : 'todas presentes', 'ok' => !$faltan, 'nota' => 'Base de datos, imágenes, PDF y textos.'];

        if ($infra['mysql'] !== '') {
            $esMaria = stripos($infra['mysql'] . $infra['mysql_motor'], 'mariadb') !== false;
            $filas[] = ['nombre' => 'Base de datos', 'requerido' => 'MySQL ≥ 8.0', 'detectado' => $infra['mysql'] . ($esMaria ? ' (MariaDB)' : ''), 'ok' => !$esMaria && version_compare($infra['mysql'], '8.0', '>='), 'nota' => 'La aplicación se probó con MySQL 8.'];
        }

        $aplicadas = null;
        try {
            $aplicadas = array_column(self::$db->query('SELECT nombre FROM schema_migraciones')->fetch_all(MYSQLI_ASSOC), 'nombre');
        } catch (\Throwable $e) {
            $aplicadas = null;
        }
        $archivos = array_map('basename', glob(self::RAIZ . '/mysql/migraciones/[0-9][0-9][0-9]_*.sql') ?: []);
        $archivos = array_values(array_filter($archivos, static fn ($n) => !str_contains($n, 'reversion')));
        if ($aplicadas !== null) {
            $pendientes = array_diff($archivos, $aplicadas);
            $filas[] = ['nombre' => 'Esquema de la base', 'requerido' => count($archivos) . ' migraciones', 'detectado' => count($aplicadas) . ' aplicadas' . ($pendientes ? ' · pendientes: ' . implode(', ', $pendientes) : ''), 'ok' => !$pendientes, 'nota' => 'Las migraciones se aplican solas al desplegar (scripts/migrar.php).'];
        }

        $paquete = null;
        try {
            $paquete = PaquetesEscritorio::vigente();
        } catch (\Throwable $e) {
            $paquete = null;
        }
        $filas[] = ['nombre' => 'Aplicación de escritorio', 'requerido' => 'Misma versión del servidor', 'detectado' => $paquete ? 'publicada ' . $paquete['version'] : 'ninguna publicada', 'ok' => $paquete ? version_compare((string) $paquete['version'], ACERO360_VERSION, '>=') : null,
            'nota' => $paquete ? 'Es el instalador que se descarga en Aplicación de escritorio.' : 'Aún no se publica un instalador.'];

        return $filas;
    }

    /** Todo junto para la pantalla. */
    public static function informe(bool $forzar = false): array
    {
        $compilacion = self::compilacion();
        $ultima = self::ultimaPublicada($forzar);
        $infra = self::infraestructura();

        return [
            'plataforma'    => 'Acero 360',
            'version'       => ACERO360_VERSION,
            'compilacion'   => $compilacion,
            'ultima'        => $ultima,
            'estado'        => self::estado($compilacion, $ultima),
            'infra'         => $infra,
            'compatibilidad' => self::compatibilidad($infra),
        ];
    }
}
