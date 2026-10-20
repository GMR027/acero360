<?php

namespace App;

/**
 * LectorDocumental — extrae datos de una factura o comprobante (solo LEE; no guarda nada).
 *
 * Prioridad de fuentes:
 *   1. XML de CFDI válido            → datos estructurados, confianza «alta».
 *   2. PDF con texto seleccionable   → pdftotext + patrones, confianza «media» (el UUID, por su formato fijo, «alta»).
 *   3. PDF escaneado o imagen        → OCR (tesseract), confianza «baja»; sube a «media» solo si los importes cuadran.
 *   4. Sin herramientas / sin texto  → campos vacíos: captura manual.
 *
 * Cada campo se devuelve como ['valor' => string, 'confianza' => alta|media|baja, 'origen' => texto].
 * Nada de lo que no sea «alta» debe guardarse sin que una persona lo revise.
 */
class LectorDocumental
{
    public const CAMPOS = [
        'folio_comercial' => 'Folio comercial de la factura',
        'uuid'            => 'UUID (folio fiscal)',
        'fecha'           => 'Fecha de emisión',
        'emisor_rfc'      => 'RFC del emisor',
        'emisor_nombre'   => 'Nombre o razón social del emisor',
        'receptor_rfc'    => 'RFC del receptor',
        'receptor_nombre' => 'Nombre o razón social del receptor',
        'subtotal'        => 'Subtotal',
        'iva'             => 'IVA desglosado',
        'total'           => 'Total',
        'moneda'          => 'Moneda',
        'forma_pago'      => 'Forma de pago',
        'metodo_pago'     => 'Método de pago',
    ];

    private const TIEMPO_MAXIMO = 40;

    /** ¿Hay OCR disponible en este servidor? */
    public static function ocrDisponible(): bool
    {
        return self::binario('tesseract') !== null;
    }

    /**
     * @param string $ruta   archivo ya validado (App\Archivos::validar)
     * @param string $familia xml | pdf | imagen
     * @return array{fuente: string, campos: array<string, array{valor: string, confianza: string, origen: string}>,
     *               conceptos: array<int, array<string, string>>, avisos: string[], texto: string}
     */
    public static function leer(string $ruta, string $familia): array
    {
        $resultado = ['fuente' => 'manual', 'campos' => [], 'conceptos' => [], 'avisos' => [], 'texto' => ''];

        if ($familia === 'xml') {
            return self::desdeXml((string) file_get_contents($ruta), $resultado);
        }

        $texto = '';
        if ($familia === 'pdf') {
            $texto = self::textoDePdf($ruta);
            if (mb_strlen(preg_replace('/\s+/u', '', $texto)) >= 40) {
                $resultado['fuente'] = 'pdf_texto';
            } else {
                $resultado['avisos'][] = 'El PDF no tiene texto seleccionable (parece escaneado).';
                $texto = self::ocrDePdf($ruta, $resultado['avisos']);
                $resultado['fuente'] = $texto !== '' ? 'ocr' : 'manual';
            }
        } else {
            $texto = self::ocr($ruta, $resultado['avisos']);
            $resultado['fuente'] = $texto !== '' ? 'ocr' : 'manual';
        }

        if ($resultado['fuente'] === 'manual') {
            $resultado['avisos'][] = 'No fue posible leer el documento automáticamente: captura los datos a mano en la pantalla de revisión.';
            return $resultado;
        }

        $resultado['texto'] = mb_substr($texto, 0, 20000);
        $resultado['campos'] = self::analizarTexto($texto, $resultado['fuente']);
        if ($resultado['fuente'] === 'ocr') {
            $resultado['avisos'][] = 'Los datos salen de OCR (reconocimiento óptico) y pueden contener errores: revisa cada campo contra el documento.';
        }

        return $resultado;
    }

    /* --------------------------------------------------------------------
     * XML
     * ------------------------------------------------------------------ */

    private static function desdeXml(string $xml, array $resultado): array
    {
        $cfdi = CfdiXml::leer($xml);
        if (!$cfdi['valido']) {
            $resultado['avisos'][] = $cfdi['error'] ?? 'El XML no es un CFDI válido.';
            return $resultado;
        }

        $d = $cfdi['datos'];
        $resultado['fuente'] = 'xml';
        if ($cfdi['estado'] !== 'cfdi_con_timbre') {
            $resultado['avisos'][] = 'El XML no trae timbre fiscal (UUID) o le faltan datos: revisa los campos vacíos. No se verifica ante el SAT.';
        }

        $alta = static fn (string $v): array => ['valor' => $v, 'confianza' => $v === '' ? 'baja' : 'alta', 'origen' => 'XML del CFDI'];
        $fecha = $d['fecha'] !== '' ? substr($d['fecha'], 0, 10) : '';

        $resultado['campos'] = [
            'folio_comercial' => $alta((string) $d['folio_comercial']),
            'uuid'            => $alta((string) $d['uuid']),
            'fecha'           => $alta($fecha),
            'emisor_rfc'      => $alta((string) $d['emisor_rfc']),
            'emisor_nombre'   => $alta((string) $d['emisor_nombre']),
            'receptor_rfc'    => $alta((string) $d['receptor_rfc']),
            'receptor_nombre' => $alta((string) $d['receptor_nombre']),
            'subtotal'        => $alta((string) $d['subtotal']),
            'iva'             => $alta((string) $d['iva']),
            'total'           => $alta((string) $d['total']),
            'moneda'          => $alta((string) $d['moneda']),
            'forma_pago'      => $alta((string) $d['forma_pago']),
            'metodo_pago'     => $alta((string) $d['metodo_pago']),
        ];
        $resultado['conceptos'] = $d['conceptos'];
        if ($d['tipo'] !== '' && $d['tipo'] !== 'I') {
            $resultado['avisos'][] = 'El tipo de comprobante es «' . $d['tipo'] . '» (no es un ingreso/factura normal): confirma que corresponde.';
        }

        return $resultado;
    }

    /* --------------------------------------------------------------------
     * Texto → campos
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{valor: string, confianza: string, origen: string}>
     */
    public static function analizarTexto(string $texto, string $fuente): array
    {
        $ocr   = $fuente === 'ocr';
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $media = $ocr ? 'baja' : 'media';
        $origen = $ocr ? 'OCR' : 'Texto del PDF';

        $campo = static fn (string $v, string $confianza) => [
            'valor' => $v, 'confianza' => $v === '' ? 'baja' : $confianza, 'origen' => $v === '' ? 'No encontrado' : $origen,
        ];

        // UUID: formato fijo; en un PDF con texto es fiable, en OCR no (confunde 0/O, 8/B…).
        $uuid = '';
        if (preg_match('/\b([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})\b/', $texto, $m)) {
            $uuid = strtoupper($m[1]);
        }

        // RFC emisor / receptor: etiquetados; si no, el primero y el segundo RFC del texto.
        $patronRfc = '[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}';
        $emisor = $receptor = '';
        if (preg_match('/(?:RFC\s*(?:del\s*)?emisor|emisor)[^\n]{0,40}?\b(' . $patronRfc . ')\b/iu', $texto, $m)) {
            $emisor = strtoupper($m[1]);
        }
        if (preg_match('/(?:RFC\s*(?:del\s*)?receptor|receptor|cliente)[^\n]{0,40}?\b(' . $patronRfc . ')\b/iu', $texto, $m)) {
            $receptor = strtoupper($m[1]);
        }
        $etiquetadoRfc = $emisor !== '' && $receptor !== '';
        if ($emisor === '' || $receptor === '') {
            preg_match_all('/\b(' . $patronRfc . ')\b/u', strtoupper($texto), $todos);
            $todos = array_values(array_unique($todos[1] ?? []));
            $emisor   = $emisor !== '' ? $emisor : ($todos[0] ?? '');
            $receptor = $receptor !== '' ? $receptor : (($todos[1] ?? '') !== $emisor ? ($todos[1] ?? '') : '');
        }

        $emisorNombre = $receptorNombre = '';
        if (preg_match('/(?:nombre\s*(?:o\s*raz[oó]n\s*social\s*)?del\s*emisor|raz[oó]n\s*social\s*(?:del\s*)?emisor|emisor)\s*[:\-]\s*([^\n]{3,120})/iu', $texto, $m)) {
            $emisorNombre = trim(preg_replace('/\s*RFC.*$/iu', '', $m[1]));
        }
        if (preg_match('/(?:nombre\s*(?:o\s*raz[oó]n\s*social\s*)?del\s*receptor|raz[oó]n\s*social\s*(?:del\s*)?receptor|receptor|cliente)\s*[:\-]\s*([^\n]{3,120})/iu', $texto, $m)) {
            $receptorNombre = trim(preg_replace('/\s*RFC.*$/iu', '', $m[1]));
        }

        // Folio comercial: «Folio: A-123» / «Serie A Folio 123». Se distingue del UUID.
        $folio = '';
        if (preg_match('/\bfolio(?!\s*fiscal)\s*(?:interno\s*)?[:#\-]?\s*([A-Z0-9][A-Z0-9\-_\/]{0,39})\b/iu', $texto, $m) && !preg_match('/^[0-9A-F]{8}-/i', $m[1])) {
            $folio = $m[1];
            if (preg_match('/\bserie\s*[:#\-]?\s*([A-Z0-9]{1,10})\b/iu', $texto, $s) && stripos($folio, $s[1]) !== 0) {
                $folio = $s[1] . $folio;
            }
        }

        $fecha = self::fechaDelTexto($texto);

        $subtotal = self::importe($texto, '(?:sub\s*-?\s*total)');
        $iva      = self::importe($texto, '(?:i\.?\s?v\.?\s?a\.?(?:\s*(?:trasladado|16\s*%|8\s*%))?|impuestos?\s*trasladados?)');
        $total    = self::importe($texto, '(?<!sub)(?<!sub )(?<!sub-)total(?:\s*a\s*pagar)?');

        // Consistencia: si subtotal + IVA = total, los importes son mucho más fiables (también en OCR).
        $cuadra = $subtotal !== '' && $iva !== '' && $total !== ''
            && abs((float) $subtotal + (float) $iva - (float) $total) <= 0.02;
        $confImporte = $cuadra ? 'media' : $media;

        $moneda = '';
        if (preg_match('/\b(MXN|USD|EUR)\b/', strtoupper($texto), $m)) {
            $moneda = $m[1];
        } elseif (preg_match('/pesos\s*mexicanos|moneda\s*[:\-]?\s*peso/iu', $texto)) {
            $moneda = 'MXN';
        }

        $forma = '';
        if (preg_match('/forma\s*de\s*pago\s*[:\-]?\s*(\d{2})\b/iu', $texto, $m)) {
            $forma = $m[1];
        }
        $metodo = '';
        if (preg_match('/m[eé]todo\s*de\s*pago\s*[:\-]?\s*(?:[^\n]{0,30}?\b)?(PUE|PPD)\b/iu', $texto, $m)) {
            $metodo = strtoupper($m[1]);
        } elseif (preg_match('/\b(PUE|PPD)\b/', $texto, $m)) {
            $metodo = $m[1];
        }

        return [
            'folio_comercial' => $campo($folio, $media),
            'uuid'            => $campo($uuid, $ocr ? 'baja' : 'alta'),
            'fecha'           => $campo($fecha, $media),
            'emisor_rfc'      => $campo($emisor, $etiquetadoRfc ? $media : 'baja'),
            'emisor_nombre'   => $campo($emisorNombre, 'baja'),
            'receptor_rfc'    => $campo($receptor, $etiquetadoRfc ? $media : 'baja'),
            'receptor_nombre' => $campo($receptorNombre, 'baja'),
            'subtotal'        => $campo($subtotal, $confImporte),
            'iva'             => $campo($iva, $confImporte),
            'total'           => $campo($total, $confImporte),
            'moneda'          => $campo($moneda, $media),
            'forma_pago'      => $campo($forma, $media),
            'metodo_pago'     => $campo($metodo, $media),
        ];
    }

    /** Fecha de emisión en AAAA-MM-DD, si hay una cercana a una etiqueta «fecha». */
    private static function fechaDelTexto(string $texto): string
    {
        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
                  'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

        $ventana = $texto;
        if (preg_match('/fecha[^\n]{0,40}(?:\n[^\n]*){0,1}/iu', $texto, $m)) {
            $ventana = $m[0] . "\n" . $texto;
        }
        if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2})?)?\b/', $ventana, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "$m[1]-$m[2]-$m[3]";
        }
        if (preg_match('#\b(\d{1,2})[/\-.](\d{1,2})[/\-.](20\d{2})\b#', $ventana, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/\b(\d{1,2})\s*(?:de)?\s*(' . implode('|', array_keys($meses)) . ')\s*(?:de|del)?\s*(20\d{2})\b/iu', $ventana, $m)) {
            $mes = $meses[mb_strtolower($m[2])];
            if (checkdate($mes, (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $mes, $m[1]);
            }
        }

        return '';
    }

    /** Importe que sigue a una etiqueta (la última aparición: los totales van al final). */
    private static function importe(string $texto, string $etiqueta): string
    {
        if (!preg_match_all('/' . $etiqueta . '[^\n\d\-]{0,30}\$?\s*\$?\s*([0-9]{1,3}(?:[, ][0-9]{3})*(?:\.[0-9]{1,2})|[0-9]+(?:\.[0-9]{1,2})?)\b/iu', $texto, $m)) {
            return '';
        }
        $valor = str_replace([',', ' '], '', end($m[1]));

        return is_numeric($valor) ? number_format((float) $valor, 2, '.', '') : '';
    }

    /* --------------------------------------------------------------------
     * Herramientas externas (sin shell: argumentos como arreglo)
     * ------------------------------------------------------------------ */

    private static function textoDePdf(string $ruta): string
    {
        if (self::binario('pdftotext') === null) {
            return '';
        }
        [$codigo, $salida] = self::ejecutar([self::binario('pdftotext'), '-layout', '-l', '5', '-enc', 'UTF-8', $ruta, '-']);

        return $codigo === 0 ? $salida : '';
    }

    /** OCR de las primeras páginas de un PDF escaneado. */
    private static function ocrDePdf(string $ruta, array &$avisos): string
    {
        if (!self::ocrDisponible() || self::binario('pdftoppm') === null) {
            $avisos[] = 'El OCR no está disponible en este servidor.';
            return '';
        }
        $carpeta = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(6));
        if (!@mkdir($carpeta, 0700)) {
            return '';
        }
        $texto = '';
        [$codigo] = self::ejecutar([self::binario('pdftoppm'), '-r', '200', '-l', '2', '-png', $ruta, $carpeta . '/p']);
        if ($codigo === 0) {
            foreach (glob($carpeta . '/p*.png') ?: [] as $pagina) {
                $texto .= "\n" . self::ocr($pagina, $avisos);
            }
        }
        foreach (glob($carpeta . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($carpeta);

        return trim($texto);
    }

    private static function ocr(string $imagen, array &$avisos): string
    {
        $tesseract = self::binario('tesseract');
        if ($tesseract === null) {
            $avisos[] = 'El OCR no está disponible en este servidor: el documento se conserva y los datos se capturan a mano.';
            return '';
        }
        [, $idiomas] = self::ejecutar([$tesseract, '--list-langs']);
        $lang = str_contains($idiomas, 'spa') ? 'spa+eng' : 'eng';
        [$codigo, $salida] = self::ejecutar([$tesseract, $imagen, 'stdout', '-l', $lang, '--psm', '6']);

        return $codigo === 0 ? trim($salida) : '';
    }

    private static function binario(string $nombre): ?string
    {
        static $cache = [];
        if (!array_key_exists($nombre, $cache)) {
            $cache[$nombre] = null;
            foreach (['/usr/bin', '/usr/local/bin', '/bin'] as $dir) {
                if (is_executable("$dir/$nombre")) {
                    $cache[$nombre] = "$dir/$nombre";
                    break;
                }
            }
        }

        return $cache[$nombre];
    }

    /** @return array{0: int, 1: string} código de salida y salida estándar */
    private static function ejecutar(array $comando): array
    {
        $proceso = @proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, null, ['LC_ALL' => 'C.UTF-8']);
        if (!is_resource($proceso)) {
            return [-1, ''];
        }
        stream_set_blocking($tuberias[1], false);
        stream_set_blocking($tuberias[2], false);

        $salida = '';
        $inicio = time();
        while (true) {
            $salida .= (string) stream_get_contents($tuberias[1]);
            stream_get_contents($tuberias[2]);
            $estado = proc_get_status($proceso);
            if (!$estado['running']) {
                break;
            }
            if (time() - $inicio > self::TIEMPO_MAXIMO) {
                proc_terminate($proceso, 9);
                break;
            }
            usleep(50000);
        }
        $salida .= (string) stream_get_contents($tuberias[1]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);
        $codigo = isset($estado['exitcode']) && $estado['exitcode'] >= 0 ? $estado['exitcode'] : $codigo;

        return [(int) $codigo, $salida];
    }
}
