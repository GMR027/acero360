<?php

namespace App;

/**
 * Almacenamiento — uso de espacio en disco y copias de seguridad (respaldos).
 *
 * Dos responsabilidades, ambas de solo lectura salvo la escritura en `configuracion`:
 *
 *   1. OCUPACIÓN. Suma el peso de la base de datos (information_schema) y de cada carpeta
 *      de archivos de la plataforma (documentos fiscales, Archivo compartido, imágenes,
 *      firmas y la propia carpeta de respaldos). Es un cálculo en vivo: no se guarda nada.
 *      Recorrer carpetas caras se limita con self::MAX_ARCHIVOS para no colgar la página.
 *
 *   2. RESPALDOS. Reutiliza los scripts ya probados del proyecto (scripts/respaldar.sh y
 *      scripts/restaurar.sh) en lugar de reimplementar el volcado: son los mismos que
 *      valida pruebas/probar_respaldo.sh.
 *      • Respaldar genera <carpeta>/AAAAMMDD-HHMMSS con base.sql.gz, archivos.tar.gz,
 *        SHA256SUMS y MANIFIESTO.txt, y aplica la retención configurada.
 *      • Restaurar exige verificar las huellas antes de tocar nada y se niega a pisar una
 *        base que ya tenga tablas, así que SIEMPRE restaura en una base nueva y extrae los
 *        archivos a una carpeta aparte: nada vivo se toca.
 *      • El respaldo automático (1 al día, 23:00) se dispara desde la propia aplicación:
 *        al cargar cualquier pantalla se comprueba si ya pasó la hora y falta el de hoy.
 *        La comparación usa el reloj del servidor y sólo escribe en `configuracion`, así
 *        que nunca se respalda dos veces el mismo día aunque entren varios usuarios.
 *        Para que el respaldo ocurra aunque nadie entre a la plataforma, el cron del
 *        servidor puede ejecutar directamente scripts/respaldar.sh (lo indica la pestaña).
 *
 * Los binarios que usan los scripts (mysqldump, mysql, tar, gzip, sha256sum) se comprueban
 * antes de cada operación; en un contenedor sin cliente de MySQL la pestaña lo dice en lugar
 * de fallar en medio de un respaldo.
 */
class Almacenamiento
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Raíz de la aplicación (un nivel arriba de /clases). */
    public const RAIZ = __DIR__ . '/..';

    /** Máximo de archivos recorridos por carpeta al medir; evita colgar la página en discos grandes. */
    private const MAX_ARCHIVOS = 200000;

    /** Segundos que se le dan a un respaldo/restauración manual antes de abortar. */
    private const TIEMPO_MAXIMO = 600;

    /** Claves de configuración que usa la pestaña. */
    public const CFG_AUTOMATICO = 'respaldo_automatico';
    public const CFG_HORA       = 'respaldo_hora';
    public const CFG_RETENER    = 'respaldo_retener';
    public const CFG_CARPETA    = 'respaldo_carpeta';
    public const CFG_ULTIMO     = 'respaldo_ultimo';

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /* --------------------------------------------------------------------
     * 1. OCUPACIÓN
     * ------------------------------------------------------------------ */

    /**
     * Desglose del espacio ocupado, de mayor a menor.
     *
     * @return array{areas: array<int, array{clave: string, etiqueta: string, bytes: int, nota: string, ruta: string}>,
     *               total: int, base: int, archivos: int}
     */
    public static function ocupacion(): array
    {
        $areas = [
            [
                'clave' => 'base', 'etiqueta' => 'Base de datos', 'bytes' => self::pesoBaseDatos(),
                'nota' => 'Cotizaciones, ventas, clientes, productos, facturas y todo el historial.',
                'ruta' => getenv('DB_NAME') ?: 'cotizaciones',
            ],
            [
                'clave' => 'documentos', 'etiqueta' => 'Documentos fiscales', 'bytes' => self::pesoDir(self::rutaDocumentos()),
                'nota' => 'PDF y XML de facturas de ventas y compras, tickets y comprobantes.',
                'ruta' => self::relativa(self::rutaDocumentos()),
            ],
            [
                'clave' => 'archivo', 'etiqueta' => 'Archivo compartido', 'bytes' => self::pesoDir(self::rutaArchivo()),
                'nota' => 'Los archivos que subes a la herramienta Archivo (Word, Excel, PDF, planos…).',
                'ruta' => self::relativa(self::rutaArchivo()),
            ],
            [
                'clave' => 'imagenes', 'etiqueta' => 'Imágenes', 'bytes' => self::pesoDir(self::rutaImagenes()),
                'nota' => 'Fotos de productos, logotipos, firmas y dibujos del Editor.',
                'ruta' => self::relativa(self::rutaImagenes()),
            ],
            [
                'clave' => 'respaldos', 'etiqueta' => 'Copias de seguridad', 'bytes' => self::pesoDir(self::carpetaRespaldos()),
                'nota' => 'Las copias que guardas desde esta misma pestaña.',
                'ruta' => self::relativa(self::carpetaRespaldos()),
            ],
        ];

        usort($areas, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $base = $areas[0]['clave'] === 'base' ? $areas[0]['bytes'] : self::pesoBaseDatos();
        $total = array_sum(array_column($areas, 'bytes'));

        return ['areas' => $areas, 'total' => $total, 'base' => $base, 'archivos' => $total - $base];
    }

    /** Peso de la base de datos actual (datos + índices), en bytes. */
    private static function pesoBaseDatos(): int
    {
        $fila = self::$db->query(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetch_row();

        return (int) $fila[0];
    }

    /** Peso total de una carpeta, recorriéndola una sola vez. 0 si no existe. */
    private static function pesoDir(string $ruta): int
    {
        if (!is_dir($ruta)) {
            return 0;
        }

        $bytes = 0;
        $vistos = 0;
        $pila = [$ruta];
        while ($pila && $vistos < self::MAX_ARCHIVOS) {
            $actual = array_pop($pila);
            $dir = @opendir($actual);
            if ($dir === false) {
                continue;
            }
            while (($nombre = readdir($dir)) !== false && $vistos < self::MAX_ARCHIVOS) {
                if ($nombre === '.' || $nombre === '..') {
                    continue;
                }
                $rutaItem = $actual . '/' . $nombre;
                if (is_dir($rutaItem)) {
                    $pila[] = $rutaItem;
                    continue;
                }
                $vistos++;
                $bytes += (int) @filesize($rutaItem);
            }
            closedir($dir);
        }

        return $bytes;
    }

    /** 1 048 576 → «1 MB» (una cifra significativa; bytes por debajo de 1 KB se muestran enteros). */
    public static function formatearBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $unidades = ['KB', 'MB', 'GB', 'TB'];
        $valor = (float) $bytes;
        foreach ($unidades as $unidad) {
            $valor /= 1024;
            if ($valor < 1024) {
                break;
            }
        }

        // Sólo se quita el separador de miles: el cero decimal de «1.0 MB» es parte de la cifra.
        return rtrim(number_format($valor, $valor < 10 ? 1 : 0, '.', ','), ',') . ' ' . $unidad;
    }

    /** Porcentaje de un área sobre el total (para la barra). Redondeado a entero. */
    public static function porcentaje(int $parte, int $total): int
    {
        return $total > 0 ? (int) round($parte * 100 / $total) : 0;
    }

    /** Tamaño máximo que PHP acepta en una subida (el menor entre upload_max_filesize y post_max_size). */
    public static function limiteSubida(): int
    {
        $valores = [];
        foreach (['upload_max_filesize', 'post_max_size'] as $clave) {
            $texto = strtolower(trim((string) ini_get($clave)));
            if ($texto === '') {
                continue;
            }
            $unidad = substr($texto, -1);
            $numero = (float) $texto;
            $valores[] = (int) ($numero * match ($unidad) {
                'g' => 1073741824,
                'm' => 1048576,
                'k' => 1024,
                default => 1,
            });
        }

        return $valores ? min($valores) : 67108864;
    }

    private static function relativa(string $ruta): string
    {
        $absoluta = realpath($ruta) ?: $ruta;
        $raiz = realpath(self::RAIZ) ?: self::RAIZ;

        return strncmp($absoluta, $raiz, strlen($raiz)) === 0 ? ltrim(substr($absoluta, strlen($raiz)), '/') : $absoluta;
    }

    public static function rutaDocumentos(): string
    {
        return getenv('DOCUMENTOS_PATH') ?: self::RAIZ . '/storage/documentos';
    }

    public static function rutaArchivo(): string
    {
        return getenv('ARCHIVO_PATH') ?: self::RAIZ . '/storage/archivo';
    }

    public static function rutaImagenes(): string
    {
        return self::RAIZ . '/public/image';
    }

    /* --------------------------------------------------------------------
     * 2. RESPALDOS
     * ------------------------------------------------------------------ */

    /** Carpeta donde viven las copias: la de la configuración o, si no, /respaldos de la raíz. */
    public static function carpetaRespaldos(): string
    {
        $ruta = trim((string) Configuracion::obtener(self::CFG_CARPETA, ''));
        if ($ruta === '') {
            return self::RAIZ . '/respaldos';
        }
        // Sólo rutas absolutas: una ruta relativa se resolvería contra la raíz del servidor web.
        return $ruta[0] === '/' ? $ruta : self::RAIZ . '/respaldos';
    }

    /**
     * Binarios que los scripts necesitan. Devuelve los que faltan (vacío = todo presente).
     *
     * @return array<int, string>
     */
    public static function faltantes(): array
    {
        $falta = [];
        foreach (['mysqldump', 'mysql', 'tar', 'gzip', 'sha256sum'] as $binario) {
            if (self::rutaBinario($binario) === null) {
                $falta[] = $binario;
            }
        }

        return $falta;
    }

    /** Ruta absoluta de un binario o null si no está instalado. */
    private static function rutaBinario(string $nombre): ?string
    {
        foreach (['/usr/bin', '/usr/local/bin', '/bin'] as $dir) {
            if (is_executable("$dir/$nombre")) {
                return "$dir/$nombre";
            }
        }

        return null;
    }

    /**
     * Copias existentes, de la más reciente a la más antigua.
     *
     * @return array<int, array{carpeta: string, sello: string, fecha: ?string, origen: string, bytes: int,
     *                          base: string, tablas: string, migraciones: string, incompleta: bool}>
     */
    public static function listar(): array
    {
        $carpeta = self::carpetaRespaldos();
        if (!is_dir($carpeta)) {
            return [];
        }

        $salida = [];
        foreach (scandir($carpeta) ?: [] as $nombre) {
            if (!preg_match('/^\d{8}-\d{6}$/', $nombre)) {
                continue;
            }
            $ruta = $carpeta . '/' . $nombre;
            if (!is_dir($ruta)) {
                continue;
            }

            $manifiesto = ['Fecha' => null, 'Origen' => '', 'Base' => '', 'Tablas' => '', 'Migraciones' => ''];
            if (is_readable("$ruta/MANIFIESTO.txt")) {
                foreach (file("$ruta/MANIFIESTO.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
                    $partes = explode(':', $linea, 2);
                    if (count($partes) === 2 && isset($manifiesto[trim($partes[0])])) {
                        $manifiesto[trim($partes[0])] = trim($partes[1]);
                    }
                }
            }

            // Sin base.sql.gz el respaldo no sirve para nada: se marca como incompleto.
            $incompleta = !is_readable("$ruta/base.sql.gz") || filesize("$ruta/base.sql.gz") === 0;

            $salida[] = [
                'carpeta' => $ruta,
                'sello' => $nombre,
                'fecha' => $manifiesto['Fecha'] !== null && ($t = strtotime($manifiesto['Fecha'])) ? date('d/m/Y H:i', $t) : null,
                'origen' => (string) $manifiesto['Origen'],
                'bytes' => self::pesoDir($ruta),
                'base' => (string) $manifiesto['Base'],
                'tablas' => (string) $manifiesto['Tablas'],
                'migraciones' => (string) $manifiesto['Migraciones'],
                'incompleta' => $incompleta,
            ];
        }

        usort($salida, static fn (array $a, array $b): int => strcmp($b['sello'], $a['sello']));

        return $salida;
    }

    /** Tamaño de la carpeta de respaldos en disco y cuántas copias hay. */
    public static function resumen(): array
    {
        $lista = self::listar();

        return ['copias' => count($lista), 'bytes' => array_sum(array_column($lista, 'bytes')), 'ultimo' => $lista[0] ?? null];
    }

    /**
     * Genera una copia de seguridad ahora (base + archivos + huellas + manifiesto).
     *
     * @return array{ok: bool, carpeta: ?string, mensaje: string}
     */
    public static function crear(): array
    {
        $script = self::RAIZ . '/scripts/respaldar.sh';
        if (!is_executable($script)) {
            return ['ok' => false, 'carpeta' => null, 'mensaje' => 'No existe el script de respaldo (scripts/respaldar.sh).'];
        }
        if ($falta = self::faltantes()) {
            return ['ok' => false, 'carpeta' => null, 'mensaje' => 'Faltan herramientas en el servidor: ' . implode(', ', $falta) . '.'];
        }

        $destino = self::carpetaRespaldos();
        if (!is_dir($destino) && !@mkdir($destino, 0700, true)) {
            return ['ok' => false, 'carpeta' => null, 'mensaje' => 'No se pudo crear la carpeta de respaldos: ' . $destino . '.'];
        }

        $retener = self::retener();
        // getenv() sin argumentos devuelve el entorno actual (incluye DB_NAME del servidor):
        // proc_open REEMPLAZA el entorno, así que hay que pasarlo entero para que el script
        // respalde la misma base con la que está conectada la aplicación.
        $entorno = getenv();
        $entorno['RETENER'] = (string) $retener;
        [$codigo, $salida] = self::ejecutar(['bash', $script, $destino], $entorno);

        // El script imprime en la última línea la carpeta creada.
        $lineas = array_values(array_filter(explode("\n", trim($salida))));
        $ultima = (string) end($lineas);
        $carpeta = is_dir($ultima) ? $ultima : null;

        if ($codigo !== 0 || $carpeta === null) {
            return ['ok' => false, 'carpeta' => null, 'mensaje' => 'El respaldo no terminó bien. ' . trim((string) ($lineas ? implode(' · ', array_slice($lineas, -2)) : ''))];
        }

        self::guardarUltimo('ok', $carpeta);

        return ['ok' => true, 'carpeta' => $carpeta, 'mensaje' => 'Copia creada (' . self::formatearBytes(self::pesoDir($carpeta)) . '). Se conservan las últimas ' . $retener . '.'];
    }

    /**
     * Restaura una copia en una base NUEVA y extrae los archivos a una carpeta aparte.
     *
     * El script verifica las huellas SHA-256 antes de empezar y se niega a importar sobre una
     * base que ya tenga tablas, así que nunca se pisa la información viva: el resultado es una
     * base nueva (cotizaciones_restaurada_AAAAMMDD) y una carpeta con los archivos, para que
     * revises y decidas cuándo y cómo usarlos.
     *
     * @return array{ok: bool, base: ?string, carpeta: ?string, mensaje: string}
     */
    public static function restaurar(string $sello): array
    {
        if (!preg_match('/^\d{8}-\d{6}$/', $sello)) {
            return ['ok' => false, 'base' => null, 'carpeta' => null, 'mensaje' => 'La copia indicada no es válida.'];
        }
        $origen = self::carpetaRespaldos() . '/' . $sello;
        if (!is_dir($origen) || !is_readable("$origen/SHA256SUMS")) {
            return ['ok' => false, 'base' => null, 'carpeta' => null, 'mensaje' => 'Esa copia no tiene huellas (SHA256SUMS): no se puede verificar, así que no se restaura.'];
        }

        $script = self::RAIZ . '/scripts/restaurar.sh';
        if (!is_executable($script)) {
            return ['ok' => false, 'base' => null, 'carpeta' => null, 'mensaje' => 'No existe el script de restauración (scripts/restaurar.sh).'];
        }
        if ($falta = self::faltantes()) {
            return ['ok' => false, 'base' => null, 'carpeta' => null, 'mensaje' => 'Faltan herramientas en el servidor: ' . implode(', ', $falta) . '.'];
        }

        $base = 'cotizaciones_restaurada_' . date('Ymd');
        $sufijo = 1;
        while (self::baseExiste($base)) {
            $base = 'cotizaciones_restaurada_' . date('Ymd') . '_' . (++$sufijo);
        }

        $archivos = self::carpetaRespaldos() . '/restauraciones/' . $sello . '-' . date('Ymd-His');
        [$codigo, $salida] = self::ejecutar(['bash', $script, $origen, $base, $archivos], getenv());

        if ($codigo !== 0) {
            return ['ok' => false, 'base' => null, 'carpeta' => null, 'mensaje' => trim((string) $salida) ?: 'La restauración no terminó bien.'];
        }

        return [
            'ok' => true, 'base' => $base, 'carpeta' => $archivos,
            'mensaje' => "Restaurada en la base «{$base}». Los archivos quedaron en " . self::relativa($archivos)
                . '. Nada de lo que estabas usando se tocó: revisa la copia y, si quieres usarla, aplica la aplicación a esa base y copia los archivos a su lugar.',
        ];
    }

    /** Ruta de una copia existente y verificable, o null si el sello no es válido o no está. */
    public static function rutaCopia(string $sello): ?string
    {
        if (!preg_match('/^\d{8}-\d{6}$/', $sello)) {
            return null;
        }
        $ruta = self::carpetaRespaldos() . '/' . $sello;

        return is_dir($ruta) && is_file("$ruta/SHA256SUMS") ? $ruta : null;
    }

    /**
     * Importa una copia EXTERNA (un .tar.gz subido desde el navegador) al almacén del clúster.
     *
     * Acepta el paquete tal como lo genera «Descargar» en esta pestaña (con la carpeta
     * AAAAMMDD-HHMMSS dentro) o el contenido del respaldo en la raíz del tar. Nada se
     * restaura aquí: se verifica, se guarda junto a las demás copias y desde la lista se
     * restaura igual que una copia hecha en el clúster.
     *
     * Seguridad: rechaza rutas absolutas, «..», enlaces y dispositivos; exige
     * base.sql.gz, archivos.tar.gz y SHA256SUMS, y comprueba las huellas antes de guardar.
     *
     * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $archivo Fila de $_FILES.
     * @return array{ok: bool, sello: ?string, mensaje: string}
     */
    public static function importarExterno(array $archivo): array
    {
        $fallo = static fn (string $mensaje): array => ['ok' => false, 'sello' => null, 'mensaje' => $mensaje];

        $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return $fallo('Selecciona el archivo .tar.gz de la copia que quieres importar.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $fallo('El archivo supera el límite de subida del servidor (' . self::formatearBytes(self::limiteSubida()) . ').');
        }
        if ($error !== UPLOAD_ERR_OK) {
            return $fallo('La subida no se completó (código ' . $error . '). Inténtalo de nuevo.');
        }

        $temporal = (string) ($archivo['tmp_name'] ?? '');
        $nombre = (string) ($archivo['name'] ?? 'copia.tar.gz');
        if (!is_uploaded_file($temporal)) {
            return $fallo('El archivo no llegó al servidor.');
        }
        if (!preg_match('/\.(tar\.gz|tgz)$/i', $nombre)) {
            return $fallo('La copia debe ser un archivo .tar.gz (el que genera el botón «Descargar»).');
        }

        return self::importarPaquete($temporal);
    }

    /**
     * Instala un paquete .tar.gz ya recibido (subida HTTP o archivo local de una prueba).
     * Es la parte segura de importarExterno, sin depender de $_FILES.
     *
     * @return array{ok: bool, sello: ?string, mensaje: string}
     */
    public static function importarPaquete(string $temporal): array
    {
        $fallo = static fn (string $mensaje): array => ['ok' => false, 'sello' => null, 'mensaje' => $mensaje];

        if (!is_file($temporal) || (int) @filesize($temporal) === 0) {
            return $fallo('El archivo de la copia está vacío o no existe.');
        }
        if (self::faltantes()) {
            return $fallo('Faltan herramientas en el servidor: ' . implode(', ', self::faltantes()) . '.');
        }

        $entrada = fopen($temporal, 'rb');
        $firma = $entrada ? (string) fread($entrada, 2) : '';
        if ($entrada) {
            fclose($entrada);
        }
        if ($firma !== "\x1f\x8b") {
            return $fallo('El archivo no es un .tar.gz válido.');
        }

        $trabajo = self::RAIZ . '/storage/importacion-' . bin2hex(random_bytes(6));
        if (!@mkdir($trabajo, 0700, true)) {
            return $fallo('No se pudo preparar un espacio temporal para la copia.');
        }
        $limpiar = static fn () => self::borrarArbol($trabajo);

        $paquete = $trabajo . '/paquete.tar.gz';
        if (!@copy($temporal, $paquete)) {
            $limpiar();
            return $fallo('No se pudo guardar el archivo recibido.');
        }

        // 1) Listar sin extraer: nombres y tipo de cada entrada.
        $tar = self::rutaBinario('tar') ?? 'tar';
        [$codigo, $listado] = self::ejecutar([$tar, '-tzf', $paquete], getenv());
        if ($codigo !== 0) {
            $limpiar();
            return $fallo('El archivo no es un tar.gz válido o está dañado.');
        }
        $nombres = array_values(array_filter(array_map('trim', explode("\n", $listado)), static fn (string $n): bool => $n !== ''));
        [, $detalle] = self::ejecutar([$tar, '-tvzf', $paquete], getenv());
        $tipos = array_values(array_filter(array_map('trim', explode("\n", $detalle)), static fn (string $l): bool => $l !== ''));
        if (!$nombres || count($nombres) !== count($tipos)) {
            $limpiar();
            return $fallo('El archivo no tiene el formato de una copia de seguridad.');
        }
        foreach ($nombres as $indice => $entrada) {
            if ($entrada === '' || $entrada[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $entrada)) {
                $limpiar();
                return $fallo('La copia contiene rutas no permitidas; se rechazó sin extraer nada.');
            }
            $tipo = $tipos[$indice][0] ?? '';
            if ($tipo !== '-' && $tipo !== 'd') {
                $limpiar();
                return $fallo('La copia contiene enlaces o dispositivos; por seguridad no se importa.');
            }
        }

        // 2) Extraer en un directorio aislado y localizar la carpeta del respaldo.
        $extraido = $trabajo . '/extraido';
        if (!@mkdir($extraido, 0700, true)) {
            $limpiar();
            return $fallo('No se pudo preparar la extracción de la copia.');
        }
        [$codigo] = self::ejecutar([$tar, '-xzf', $paquete, '-C', $extraido, '--no-same-owner', '--no-same-permissions'], getenv());
        if ($codigo !== 0) {
            $limpiar();
            return $fallo('No se pudo extraer la copia; puede estar dañada.');
        }

        $raiz = $extraido;
        $primeros = array_values(array_unique(array_map(static fn (string $n): string => explode('/', ltrim($n, './'))[0], $nombres)));
        if (count($primeros) === 1) {
            $candidato = $extraido . '/' . $primeros[0];
            if (is_dir($candidato) && is_file("$candidato/base.sql.gz")) {
                $raiz = $candidato;
            }
        }
        if (!is_file("$raiz/base.sql.gz") || !is_file("$raiz/archivos.tar.gz") || !is_file("$raiz/SHA256SUMS")) {
            $limpiar();
            return $fallo('La copia no trae base.sql.gz, archivos.tar.gz y SHA256SUMS: no es una copia de esta plataforma.');
        }

        // 3) Verificar las huellas ANTES de guardarla: si no cuadran, no entra al almacén.
        $sha = self::rutaBinario('sha256sum') ?? 'sha256sum';
        [$codigo] = self::ejecutar(['bash', '-c', 'cd ' . escapeshellarg($raiz) . ' && ' . escapeshellarg($sha) . ' -c SHA256SUMS'], getenv());
        if ($codigo !== 0) {
            $limpiar();
            return $fallo('La copia está dañada o fue alterada: las huellas SHA-256 no coinciden. No se guardó nada.');
        }

        // 4) Sello: el nombre de la carpeta o, si viene suelta, la fecha del manifiesto.
        $sello = basename($raiz);
        $momento = null;
        if (!preg_match('/^\d{8}-\d{6}$/', $sello)) {
            if (is_readable("$raiz/MANIFIESTO.txt")) {
                foreach (file("$raiz/MANIFIESTO.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
                    if (stripos($linea, 'Fecha:') === 0) {
                        $momento = strtotime(trim(substr($linea, 6))) ?: null;
                        break;
                    }
                }
            }
            $momento = $momento ?: time();
            $sello = date('Ymd-His', $momento);
        }
        $destino = self::carpetaRespaldos() . '/' . $sello;
        if ($momento !== null || is_dir($destino)) {
            $momento = $momento ?: (strtotime(preg_replace('/(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/', '$1-$2-$3 $4:$5:$6', $sello)) ?: time());
            $intentos = 0;
            while (is_dir($destino) && $intentos < 120) {
                $momento++;
                $sello = date('Ymd-His', $momento);
                $destino = self::carpetaRespaldos() . '/' . $sello;
                $intentos++;
            }
        }
        if (is_dir($destino)) {
            $limpiar();
            return $fallo('Ya existe una copia con el sello ' . $sello . '. Renómbrala o bórrala antes de importar.');
        }

        if (!is_dir(self::carpetaRespaldos()) && !@mkdir(self::carpetaRespaldos(), 0700, true)) {
            $limpiar();
            return $fallo('No se pudo crear la carpeta de respaldos del clúster.');
        }
        [$codigo] = self::ejecutar(['cp', '-a', '--', $raiz, $destino], getenv());
        if ($codigo !== 0 || !is_file("$destino/base.sql.gz")) {
            self::borrarArbol($destino);
            $limpiar();
            return $fallo('No se pudo guardar la copia en el almacén del clúster.');
        }
        @chmod($destino, 0700);
        foreach (glob($destino . '/*') ?: [] as $ruta) {
            @chmod($ruta, is_dir($ruta) ? 0700 : 0600);
        }
        $limpiar();

        return [
            'ok' => true, 'sello' => $sello,
            'mensaje' => 'Copia externa verificada y guardada en el clúster como ' . $sello
                . ' (' . self::formatearBytes(self::pesoDir($destino)) . '). Ya puedes restaurarla en una base nueva desde la lista.',
        ];
    }

    /** Borra un árbol de archivos sin seguir enlaces simbólicos. */
    private static function borrarArbol(string $ruta): void
    {
        if (!is_dir($ruta) || is_link($ruta)) {
            @unlink($ruta);
            return;
        }
        foreach (scandir($ruta) ?: [] as $nombre) {
            if ($nombre === '.' || $nombre === '..') {
                continue;
            }
            self::borrarArbol($ruta . '/' . $nombre);
        }
        @rmdir($ruta);
    }

    /** ¿Existe ya esa base de datos? */
    private static function baseExiste(string $base): bool
    {
        $consulta = self::$db->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
        $consulta->bind_param('s', $base);
        $consulta->execute();
        $existe = (int) $consulta->get_result()->fetch_row()[0] > 0;
        $consulta->close();

        return $existe;
    }

    /* --------------------------------------------------------------------
     * 3. RESPALDO AUTOMÁTICO DIARIO
     * ------------------------------------------------------------------ */

    public static function automatico(): bool
    {
        return Configuracion::obtener(self::CFG_AUTOMATICO, '1') === '1';
    }

    /** Hora del respaldo automático, en formato 24 h (por defecto 23:00). */
    public static function hora(): string
    {
        $hora = trim((string) Configuracion::obtener(self::CFG_HORA, '23:00'));

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) ? $hora : '23:00';
    }

    /** Cuántas copias se conservan (entre 1 y 365). */
    public static function retener(): int
    {
        $n = (int) Configuracion::obtener(self::CFG_RETENER, '14');

        return max(1, min(365, $n ?: 14));
    }

    /**
     * Comprueba si toca el respaldo del día y, si es así, lo genera.
     *
     * Se llama desde la vista de Ajustes → Almacenamiento. Sólo actúa si el respaldo automático
     * está activo, ya pasó la hora de hoy y no se ha respaldado hoy. Devuelve null cuando no
     * tocaba nada, para no mostrar un mensaje sin sentido.
     *
     * @return array{ok: bool, mensaje: string}|null
     */
    public static function respaldoDelDia(): ?array
    {
        if (!self::automatico() || self::faltantes()) {
            return null;
        }
        if (date('H:i') < self::hora()) {
            return null;
        }
        $ultimo = trim((string) Configuracion::obtener(self::CFG_ULTIMO, ''));
        if ($ultimo !== '' && substr($ultimo, 0, 10) === date('Y-m-d')) {
            return null;   // ya se respaldó hoy: una copia por día, ni más
        }
        // El CronJob del clúster no escribe en `configuracion`: si ya hay una copia
        // hecha por él hoy, se anota y no se duplica (un respaldo diario, no dos).
        $carpeta = self::carpetaRespaldos();
        $hoy = date('Ymd');
        if (is_dir($carpeta)) {
            foreach (scandir($carpeta) ?: [] as $nombre) {
                if ($nombre === '.' || $nombre === '..' || strncmp($nombre, $hoy . '-', 9) !== 0 || !is_dir($carpeta . '/' . $nombre)) {
                    continue;
                }
                $origen = '';
                if (is_readable($carpeta . '/' . $nombre . '/MANIFIESTO.txt')) {
                    foreach (file($carpeta . '/' . $nombre . '/MANIFIESTO.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
                        if (stripos($linea, 'Origen:') === 0) {
                            $origen = strtolower(trim(substr($linea, 7)));
                            break;
                        }
                    }
                }
                if ($origen === 'cron') {
                    self::guardarUltimo('ok', $carpeta . '/' . $nombre);
                    return null;
                }
            }
        }

        $resultado = self::crear();
        $resultado['mensaje'] = ($resultado['ok'] ? 'Respaldo automático del día: ' : 'El respaldo automático de hoy falló: ') . $resultado['mensaje'];

        return $resultado;
    }

    /** Anota el resultado del último respaldo para no repetirlo el mismo día. */
    private static function guardarUltimo(string $estado, string $carpeta): void
    {
        Configuracion::guardar(self::CFG_ULTIMO, date('Y-m-d H:i:s') . ' ' . $estado . ' ' . basename($carpeta));
    }

    /** [fecha, estado, carpeta] del último respaldo registrado, o [null, null, null]. */
    public static function ultimo(): array
    {
        $valor = trim((string) Configuracion::obtener(self::CFG_ULTIMO, ''));
        if ($valor === '' || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\w+)\s+(.*)$/', $valor, $m)) {
            return [null, null, null];
        }

        return [$m[1], $m[2], $m[3]];
    }

    /** Línea de cron para quien quiera el respaldo garantizado aunque nadie entre a la plataforma. */
    public static function lineaCron(): string
    {
        [$hora, $minuto] = array_map('intval', explode(':', self::hora()));

        return sprintf('%d %d * * * %s %s %s >> %s 2>&1', $minuto, $hora,
            self::rutaBinario('bash') ?? 'bash',
            escapeshellarg(self::RAIZ . '/scripts/respaldar.sh'),
            escapeshellarg(self::carpetaRespaldos()),
            escapeshellarg(self::RAIZ . '/storage/respaldo.log'));
    }

    /**
     * Ejecuta un comando externo esperando el resultado, con tiempo máximo.
     *
     * @param array<int, string> $comando
     * @param array<string, string> $variables
     * @return array{0: int, 1: string} código de salida y salida (salida estándar + errores)
     */
    private static function ejecutar(array $comando, array $variables): array
    {
        $proceso = @proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, null, $variables);
        if (!is_resource($proceso)) {
            return [-1, 'No se pudo arrancar el proceso.'];
        }
        stream_set_blocking($tuberias[1], false);
        stream_set_blocking($tuberias[2], false);

        $salida = '';
        $inicio = time();
        while (true) {
            $salida .= (string) stream_get_contents($tuberias[1]);
            $salida .= (string) stream_get_contents($tuberias[2]);
            $estado = proc_get_status($proceso);
            if (!$estado['running']) {
                break;
            }
            if (time() - $inicio > self::TIEMPO_MAXIMO) {
                proc_terminate($proceso, 9);
                $salida .= "\nSe agotó el tiempo de espera (" . self::TIEMPO_MAXIMO . ' s).';
                break;
            }
            usleep(100000);
        }
        $salida .= (string) stream_get_contents($tuberias[1]);
        $salida .= (string) stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);
        $codigo = isset($estado['exitcode']) && $estado['exitcode'] >= 0 ? $estado['exitcode'] : $codigo;

        return [(int) $codigo, $salida];
    }
}
