<?php

namespace App;

/**
 * Archivos — documentos (PDF, imágenes, XML) ligados a un ingreso o a un gasto.
 *
 * Seguridad:
 *   • Los archivos viven FUERA de public/ (DOCUMENTOS_PATH o storage/documentos)
 *     con nombre aleatorio generado por el sistema: el nombre que manda el
 *     usuario solo se guarda como dato, nunca como ruta.
 *   • Se valida el contenido real (firma/finfo), no solo la extensión; solo se
 *     aceptan PDF, JPG, PNG, WebP y XML. Tamaño máximo configurable.
 *   • Un XML con DOCTYPE/entidades se rechaza (XXE). Se lee como CFDI solo de
 *     forma documental (no es validación ante el SAT).
 *   • No se admiten duplicados exactos (mismo SHA-256) en la misma operación ni
 *     el mismo UUID de CFDI en dos operaciones distintas.
 *   • Sustituir o dar de baja conserva el archivo anterior y deja motivo y bitácora.
 *   • La descarga exige sesión de administrador y resuelve la ruta con
 *     realpath dentro del almacén (sin recorridos de directorio).
 *   • Una operación en un periodo cerrado no admite cambios de documentos.
 */
class Archivos
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    public static int $ultimoId = 0;

    public const TIPOS = [
        'factura_pdf'      => 'Factura (PDF)',
        'xml'              => 'CFDI (XML)',
        'comprobante_pago' => 'Comprobante de pago',
        'ticket'           => 'Ticket o nota',
        'imagen'           => 'Imagen',
        'otro'             => 'Otro (por clasificar)',
    ];

    /** extensión => [familia, mimes aceptados] */
    private const FORMATOS = [
        'pdf'  => ['pdf',    ['application/pdf']],
        'jpg'  => ['imagen', ['image/jpeg']],
        'jpeg' => ['imagen', ['image/jpeg']],
        'png'  => ['imagen', ['image/png']],
        'webp' => ['imagen', ['image/webp']],
        'xml'  => ['xml',    ['text/xml', 'application/xml', 'text/plain']],
    ];

    /**
     * Convierte $_FILES['campo'] (uno o varios archivos) en una lista de archivos individuales,
     * sin los campos que el usuario dejó vacíos.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listaDeSubidas($subida): array
    {
        if (!is_array($subida) || !isset($subida['name'])) {
            return [];
        }
        if (!is_array($subida['name'])) {
            $subida = array_map(static fn ($v) => [$v], $subida);
        }
        $lista = [];
        foreach (array_keys($subida['name']) as $i) {
            if ((int) $subida['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $lista[] = ['name' => $subida['name'][$i], 'type' => $subida['type'][$i], 'tmp_name' => $subida['tmp_name'][$i],
                        'error' => $subida['error'][$i], 'size' => $subida['size'][$i]];
        }
        return $lista;
    }

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /** Carpeta raíz del almacén (se crea al primer uso). */
    public static function base(): string
    {
        $ruta = getenv('DOCUMENTOS_PATH') ?: dirname(__DIR__) . '/storage/documentos';
        if (!is_dir($ruta)) {
            @mkdir($ruta, 0750, true);
        }

        return rtrim($ruta, '/');
    }

    public static function maxBytes(): int
    {
        return max(1, (int) (getenv('DOCUMENTOS_MAX_MB') ?: 10)) * 1024 * 1024;
    }

    /* --------------------------------------------------------------------
     * ENTIDAD
     * ------------------------------------------------------------------ */

    /** @return array{contribuyente_id: int, fecha: string, total: string, uuid_fiscal: ?string, etiqueta: string}|null */
    private static function entidad(string $entidad, int $id): ?array
    {
        $sql = $entidad === 'ingreso'
            ? 'SELECT contribuyente_id, fecha, total, uuid_fiscal, folio AS etiqueta, estado FROM ingresos WHERE id = ?'
            : 'SELECT contribuyente_id, fecha, total, uuid_fiscal, beneficiario AS etiqueta, estado FROM gastos WHERE id = ?';
        $consulta = self::$db->prepare($sql);
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /* --------------------------------------------------------------------
     * SUBIR / SUSTITUIR
     * ------------------------------------------------------------------ */

    /**
     * Valida y guarda un archivo adjunto.
     *
     * @param array{name: string, tmp_name: string, size: int, error: int} $archivo entrada de $_FILES
     * @param int|null $reemplazaId documento activo al que sustituye
     * @param bool     $esSubida   true exige que sea una subida HTTP real (producción)
     */
    public static function subir(
        string $entidad,
        int $entidadId,
        array $archivo,
        string $tipo,
        int $usuarioId,
        ?int $reemplazaId = null,
        string $motivo = '',
        bool $esSubida = true
    ): bool {
        self::$errores = [];

        if (!in_array($entidad, ['ingreso', 'gasto'], true)) {
            self::$errores[] = 'Entidad no válida.';
            return false;
        }
        $tipo = isset(self::TIPOS[$tipo]) || $tipo === 'auto' ? $tipo : 'otro';

        $op = self::entidad($entidad, $entidadId);
        if (!$op) {
            self::$errores[] = 'La operación a la que se adjunta ya no existe.';
            return false;
        }
        if (Contabilidad::periodoCerrado((int) $op['contribuyente_id'], $op['fecha'])) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado($op['fecha']);
            return false;
        }

        $validado = self::validar($archivo, $esSubida);
        if ($validado === null) {
            return false;
        }
        ['ext' => $ext, 'familia' => $familia, 'mime' => $mime, 'contenido' => $contenido, 'sha256' => $sha, 'cfdi' => $cfdi] = $validado;

        // «auto»: el tipo se deduce del archivo (XML → CFDI, PDF → factura, imagen → imagen).
        if ($tipo === 'auto') {
            $tipo = ['xml' => 'xml', 'pdf' => 'factura_pdf', 'imagen' => 'imagen'][$familia] ?? 'otro';
        }

        // Coherencia entre el tipo elegido y el archivo.
        if ($familia === 'xml') {
            $tipo = 'xml';
        } elseif ($tipo === 'xml') {
            self::$errores[] = 'El tipo «CFDI (XML)» requiere un archivo .xml.';
            return false;
        } elseif ($tipo === 'factura_pdf' && $familia !== 'pdf') {
            self::$errores[] = 'El tipo «Factura (PDF)» requiere un archivo PDF.';
            return false;
        }

        // Duplicado exacto en la misma operación.
        $consulta = self::$db->prepare(
            'SELECT id FROM documentos WHERE entidad = ? AND entidad_id = ? AND sha256 = ? AND activo = 1 LIMIT 1'
        );
        $consulta->bind_param('sis', $entidad, $entidadId, $sha);
        $consulta->execute();
        if ($consulta->get_result()->num_rows > 0) {
            $consulta->close();
            self::$errores[] = 'Este mismo archivo ya está adjunto a la operación.';
            return false;
        }
        $consulta->close();

        $uuid = $cfdi['datos']['uuid'] ?? '';
        if ($uuid !== '') {
            $consulta = self::$db->prepare(
                'SELECT entidad, entidad_id FROM documentos WHERE uuid_fiscal = ? AND activo = 1
                    AND NOT (entidad = ? AND entidad_id = ?) LIMIT 1'
            );
            $consulta->bind_param('ssi', $uuid, $entidad, $entidadId);
            $consulta->execute();
            $otro = $consulta->get_result()->fetch_assoc();
            $consulta->close();
            if ($otro) {
                self::$errores[] = 'El UUID ' . $uuid . ' ya está adjunto a otra operación (' . $otro['entidad'] . ' #' . $otro['entidad_id'] . '): no se duplica.';
                return false;
            }
        }

        // Alertas documentales (no son validación ante el SAT).
        if ($familia === 'xml' && $cfdi['valido']) {
            $contribuyente = Contabilidad::contribuyente((int) $op['contribuyente_id']);
            $rfc = strtoupper((string) ($contribuyente['rfc'] ?? ''));
            $datos = $cfdi['datos'];
            $alertas = [];
            $rolRfc = $entidad === 'ingreso' ? $datos['emisor_rfc'] : $datos['receptor_rfc'];
            if ($rfc !== '' && $rolRfc !== $rfc) {
                $alertas[] = 'El RFC del ' . ($entidad === 'ingreso' ? 'emisor' : 'receptor') . ' (' . $rolRfc . ') no coincide con el del contribuyente (' . $rfc . ').';
            }
            if ($datos['total'] !== '' && Contabilidad::centavos($datos['total']) !== Contabilidad::centavos($op['total'])) {
                $alertas[] = 'El total del XML (' . $datos['total'] . ') no coincide con el de la operación (' . $op['total'] . ').';
            }
            $cfdi['datos']['alertas'] = $alertas;
        }

        // Almacenamiento: contribuyente / año / mes / tipo de operación / nombre aleatorio.
        $anio = substr($op['fecha'], 0, 4);
        $mes  = substr($op['fecha'], 5, 2);
        $relativa = (int) $op['contribuyente_id'] . "/$anio/$mes/" . ($entidad === 'ingreso' ? 'ventas' : 'gastos');
        $carpeta = self::base() . '/' . $relativa;
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0750, true)) {
            self::$errores[] = 'No fue posible preparar el almacén de documentos.';
            return false;
        }
        $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
        if (file_put_contents($carpeta . '/' . $nombre, $contenido) === false) {
            self::$errores[] = 'No fue posible guardar el archivo.';
            return false;
        }
        @chmod($carpeta . '/' . $nombre, 0640);
        $archivoRelativo = $relativa . '/' . $nombre;

        try {
            self::$db->begin_transaction();

            if ($reemplazaId) {
                $viejo = self::buscarID($reemplazaId);
                if (!$viejo || (int) $viejo['activo'] !== 1 || $viejo['entidad'] !== $entidad || (int) $viejo['entidad_id'] !== $entidadId) {
                    throw new \RuntimeException('El documento a sustituir no corresponde a esta operación.');
                }
            }

            $original = mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', basename((string) $archivo['name'])), 0, 255) ?: ('archivo.' . $ext);
            $uuidDoc = $uuid ?: null;
            $estado  = $familia === 'xml' ? $cfdi['estado'] : 'no_aplica';
            $json    = $familia === 'xml' && $cfdi['valido'] ? json_encode($cfdi['datos'], JSON_UNESCAPED_UNICODE) : null;
            $tamano  = strlen($contenido);
            $contribuyenteId = (int) $op['contribuyente_id'];

            $crear = self::$db->prepare(
                'INSERT INTO documentos (contribuyente_id, entidad, entidad_id, tipo, nombre_original, archivo, mime, tamano,
                        sha256, uuid_fiscal, estado_validacion, datos_cfdi, reemplaza_id, subido_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $crear->bind_param(
                'isissssissssii',
                $contribuyenteId, $entidad, $entidadId, $tipo, $original, $archivoRelativo, $mime, $tamano,
                $sha, $uuidDoc, $estado, $json, $reemplazaId, $usuarioId
            );
            $crear->execute();
            $id = (int) self::$db->insert_id;
            $crear->close();

            if ($reemplazaId) {
                $baja = self::$db->prepare(
                    "UPDATE documentos SET activo = 0, motivo_baja = ?, baja_en = NOW(), baja_por = ? WHERE id = ?"
                );
                $razon = trim($motivo) ?: 'Sustituido por el documento #' . $id;
                $baja->bind_param('sii', $razon, $usuarioId, $reemplazaId);
                $baja->execute();
                $baja->close();
            }

            Auditoria::registrar('documento', $id, $reemplazaId ? 'sustituir' : 'subir', $usuarioId, trim($motivo) ?: null, [
                'entidad' => $entidad, 'entidad_id' => $entidadId, 'tipo' => $tipo, 'sha256' => $sha,
            ]);

            self::$db->commit();
            self::$ultimoId = $id;
            return true;
        } catch (\Throwable $excepcion) {
            self::$db->rollBack();
            @unlink($carpeta . '/' . $nombre);
            error_log('[Archivos::subir] ' . $excepcion->getMessage());
            self::$errores[] = $excepcion instanceof \RuntimeException ? $excepcion->getMessage() : 'No fue posible registrar el documento.';
            return false;
        }
    }

    /**
     * Valida el archivo recibido (tamaño, extensión, contenido real, XML).
     *
     * @return array<string, mixed>|null null si no es aceptable (errores en getErrores())
     */
    private static function validar(array $archivo, bool $esSubida): ?array
    {
        $codigo = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($codigo !== UPLOAD_ERR_OK) {
            self::$errores[] = match ($codigo) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido.',
                UPLOAD_ERR_NO_FILE => 'Selecciona un archivo.',
                default => 'La subida del archivo falló. Inténtalo de nuevo.',
            };
            return null;
        }

        $temporal = (string) ($archivo['tmp_name'] ?? '');
        if ($temporal === '' || !is_file($temporal) || ($esSubida && !is_uploaded_file($temporal))) {
            self::$errores[] = 'El archivo recibido no es válido.';
            return null;
        }

        $tamano = (int) filesize($temporal);
        if ($tamano <= 0) {
            self::$errores[] = 'El archivo está vacío.';
            return null;
        }
        if ($tamano > self::maxBytes()) {
            self::$errores[] = 'El archivo excede el máximo de ' . (self::maxBytes() / 1048576) . ' MB.';
            return null;
        }

        $ext = strtolower(pathinfo((string) ($archivo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::FORMATOS[$ext])) {
            self::$errores[] = 'Formato no permitido. Solo PDF, JPG, PNG, WebP o XML.';
            return null;
        }
        [$familia, $permitidos] = self::FORMATOS[$ext];

        $contenido = (string) file_get_contents($temporal);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido);

        if ($familia === 'pdf') {
            if (strncmp($contenido, '%PDF-', 5) !== 0 || $mime !== 'application/pdf') {
                self::$errores[] = 'El archivo no es un PDF válido.';
                return null;
            }
            // Un PDF con JavaScript o acciones de lanzamiento no se acepta como comprobante.
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile)\b#', $contenido)) {
                self::$errores[] = 'El PDF contiene scripts o archivos incrustados y no se acepta.';
                return null;
            }
        } elseif ($familia === 'imagen') {
            $info = @getimagesizefromstring($contenido);
            if ($info === false || !in_array($mime, $permitidos, true) || $info[0] > 12000 || $info[1] > 12000) {
                self::$errores[] = 'El archivo no es una imagen válida (JPG, PNG o WebP).';
                return null;
            }
        } else { // xml
            if ($tamano > 5 * 1048576) {
                self::$errores[] = 'Un XML no puede exceder 5 MB.';
                return null;
            }
            if (!in_array($mime, $permitidos, true) || strncmp(ltrim($contenido, "\xEF\xBB\xBF \t\r\n"), '<', 1) !== 0) {
                self::$errores[] = 'El archivo no es un XML válido.';
                return null;
            }
            $cfdi = CfdiXml::leer($contenido);
            if (!$cfdi['valido']) {
                self::$errores[] = $cfdi['error'] ?? 'El XML no es válido.';
                return null;
            }
            $mime = 'application/xml';
        }

        return [
            'ext' => $ext === 'jpeg' ? 'jpg' : $ext, 'familia' => $familia, 'mime' => $mime, 'contenido' => $contenido,
            'sha256' => hash('sha256', $contenido), 'cfdi' => $cfdi ?? ['valido' => false, 'estado' => 'no_aplica', 'datos' => []],
        ];
    }

    /* --------------------------------------------------------------------
     * BAJA, LECTURA Y DESCARGA
     * ------------------------------------------------------------------ */

    /** Da de baja un documento (el archivo se conserva). Exige motivo. */
    public static function baja(int $id, string $motivo, int $usuarioId): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);
        $doc = self::buscarID($id);

        if (!$doc || (int) $doc['activo'] !== 1) {
            self::$errores[] = 'El documento no existe o ya fue dado de baja.';
            return false;
        }
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Escribe el motivo de la baja (mínimo 5 caracteres).';
            return false;
        }
        $op = self::entidad($doc['entidad'], (int) $doc['entidad_id']);
        if ($op && Contabilidad::periodoCerrado((int) $op['contribuyente_id'], $op['fecha'])) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado($op['fecha']);
            return false;
        }

        $consulta = self::$db->prepare('UPDATE documentos SET activo = 0, motivo_baja = ?, baja_en = NOW(), baja_por = ? WHERE id = ?');
        $consulta->bind_param('sii', $motivo, $usuarioId, $id);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('documento', $id, 'baja', $usuarioId, $motivo);
        return true;
    }

    /** @return array<string, mixed>|null */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM documentos WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if ($fila && $fila['datos_cfdi']) {
            $fila['cfdi'] = json_decode((string) $fila['datos_cfdi'], true) ?: [];
        }

        return $fila ?: null;
    }

    /**
     * Documentos de una operación (activos primero).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deOperacion(string $entidad, int $entidadId, bool $incluirBajas = true): array
    {
        $consulta = self::$db->prepare(
            'SELECT d.*, u.nombre AS subido_por_nombre FROM documentos d LEFT JOIN usuarios u ON u.id = d.subido_por
              WHERE d.entidad = ? AND d.entidad_id = ?' . ($incluirBajas ? '' : ' AND d.activo = 1') . '
              ORDER BY d.activo DESC, d.id DESC'
        );
        $consulta->bind_param('si', $entidad, $entidadId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        foreach ($filas as &$f) {
            $f['cfdi'] = $f['datos_cfdi'] ? (json_decode((string) $f['datos_cfdi'], true) ?: []) : [];
        }
        unset($f);

        return $filas;
    }

    /** Ruta absoluta del archivo, solo si está dentro del almacén. */
    public static function rutaAbsoluta(array $doc): ?string
    {
        $base = realpath(self::base());
        $ruta = realpath(self::base() . '/' . $doc['archivo']);

        if ($base === false || $ruta === false || strncmp($ruta, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($ruta)) {
            return null;
        }

        return $ruta;
    }

    /** Envía el archivo como descarga (siempre attachment). Termina la petición. */
    public static function descargar(int $id): void
    {
        $doc = self::buscarID($id);
        $ruta = $doc ? self::rutaAbsoluta($doc) : null;

        if (!$doc || $ruta === null) {
            \paginaNoEncontrada();
        }

        $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $doc['nombre_original']) ?: 'documento';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $doc['mime']);
        header('Content-Disposition: attachment; filename="' . $nombre . '"; filename*=UTF-8\'\'' . rawurlencode((string) $doc['nombre_original']));
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, no-store');
        readfile($ruta);
        exit;
    }

    /** Etiqueta del estado de validación documental. */
    public static function etiquetaValidacion(string $estado): array
    {
        return [
            'no_aplica'       => ['etiqueta' => 'No aplica',                   'clase' => 'badge--neutro'],
            'cfdi_con_timbre' => ['etiqueta' => 'CFDI con UUID (sin verificar SAT)', 'clase' => 'badge--aprobado'],
            'cfdi_sin_timbre' => ['etiqueta' => 'CFDI incompleto',             'clase' => 'badge--pendiente'],
            'xml_no_cfdi'     => ['etiqueta' => 'XML que no es CFDI',          'clase' => 'badge--error'],
        ][$estado] ?? ['etiqueta' => $estado, 'clase' => 'badge--neutro'];
    }
}
