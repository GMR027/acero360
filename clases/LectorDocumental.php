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
        'descuento'       => 'Descuento',
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
        if ($resultado['fuente'] === 'pdf_texto' || $resultado['fuente'] === 'ocr') {
            // El PDF con texto trae columnas separadas por varios espacios; el OCR las
            // colapsa a uno solo, así que ahí se acepta un único espacio como separador
            // y ningún renglón queda con confianza «alta».
            $ocr = $resultado['fuente'] === 'ocr';
            $resultado['conceptos'] = self::conceptosDeTexto($texto, $ocr ? '/\s+/u' : '/\s{2,}/u', $ocr ? 'media' : 'alta');
            if (!$resultado['conceptos'] && !$ocr) {
                $resultado['avisos'][] = 'El PDF no trae una tabla de productos legible: los renglones se capturan a mano.';
            }
        }
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
            'descuento'       => $alta((string) $d['descuento']),
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

        $emisorNombre   = self::nombreDeTexto($texto, 'emisor', $emisor);
        $receptorNombre = self::nombreDeTexto($texto, 'receptor', $receptor);

        // Folio comercial: «Folio: A-123» / «Serie A Folio 123» / «Serie y Folio: BEX 21324».
        // Se distingue del UUID. El valor puede traer la serie y el número con un
        // espacio, pero NUNCA se cruza a la línea de abajo: si «Serie y Folio:» no
        // trae valor, de otro modo se quedaría con la primera palabra de la columna
        // de al lado (el encabezado «RECEPTOR», por ejemplo).
        $folio = '';
        if (preg_match('/\bfolio(?![ \t]*fiscal)[ \t]*(?:interno[ \t]*)?[:#\-]?[ \t]*([A-Z0-9][A-Z0-9\-_\/]{0,39}(?:[ \t]+\d{1,10})?)\b/iu', $texto, $m)
            && !preg_match('/^[0-9A-F]{8}-/i', $m[1])) {
            $folio = $m[1];
            // La serie sólo se antepone si el valor parece tal: en «No. de serie del CSD»
            // el regex suelta «del» y con él se fabricaría un folio «del3260».
            $conector = '/^(del|de|la|el|los|las|y|o|en|con|por|para|al|the|of|and)$/iu';
            if (preg_match('/\bserie[ \t]*[:#\-]?[ \t]*([A-Z0-9]{1,10})\b/iu', $texto, $s)
                && !preg_match($conector, $s[1]) && stripos($folio, $s[1]) !== 0) {
                $folio = $s[1] . $folio;
            }
        }

        $fecha = self::fechaDelTexto($texto);

        $subtotal  = self::importe($texto, '(?:sub\s*-?\s*total)');
        $descuento = self::importe($texto, '(?:descuento(?:\s*global)?|dcto)');
        $iva       = self::importe($texto, '(?:i\.?\s?v\.?\s?a\.?(?:\s*(?:trasladado|16\s*%|8\s*%))?|impuestos?\s*trasladados?)');
        $total    = self::importe($texto, '(?<!sub)(?<!sub )(?<!sub-)total(?:\s*a\s*pagar)?');

        // Consistencia: si subtotal − descuento + IVA = total, los importes son mucho más fiables (también en OCR).
        $cuadra = $subtotal !== '' && $total !== ''
            && abs(((float) $subtotal - (float) $descuento) + (float) $iva - (float) $total) <= 0.02;
        $confImporte = $cuadra ? 'media' : $media;

        // Primero junto a la etiqueta «Moneda:», que es la del documento; después
        // cualquier MXN/USD/EUR del texto, que puede ser de otra columna.
        $moneda = '';
        if (preg_match('/\bmoneda\s*[:\-]?\s*([A-Z]{3})\b/iu', $texto, $m)) {
            $moneda = strtoupper($m[1]);
        } elseif (preg_match('/\b(MXN|USD|EUR)\b/', strtoupper($texto), $m)) {
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
            'descuento'       => $campo($descuento, $media),
            'iva'             => $campo($iva, $confImporte),
            'total'           => $campo($total, $confImporte),
            'moneda'          => $campo($moneda, $media),
            'forma_pago'      => $campo($forma, $media),
            'metodo_pago'     => $campo($metodo, $media),
        ];
    }

    /**
     * Nombre o razón social de una de las partes dentro de un texto plano.
     *
     * El orden importa: la etiqueta larga («Nombre del emisor:») tiene prioridad
     * sobre la corta («Emisor:»), porque en casi todos los formatos la corta
     * aparece ANTES y trae el RFC, no el nombre. Si el valor parece un RFC se
     * descarta y se sigue con el siguiente candidato.
     */
    private static function nombreDeTexto(string $texto, string $quien, string $rfc = ''): string
    {
        $candidatos = [];

        // La etiqueta larga va primero: en la mayoría de formatos es la única que trae el nombre.
        $largo = '/(?:nombre\s*(?:o\s*raz[oó]n\s*social\s*)?(?:del\s*)?' . $quien . '|raz[oó]n\s*social\s*(?:del\s*)?' . $quien . ')\s*[:\-]\s*([^\n]{3,120})/iu';
        if (preg_match($largo, $texto, $m)) {
            $candidatos[] = $m[1];
        }

        // PDFs de facturadores en línea: la cabecera del bloque va en una línea
        // («RECEPTOR   DATOS DEL CFDI») y el valor en la que sigue («Nombre: X»).
        $bloque = '/(?:^|\n)[ \t]*' . $quien . '[ \t]+[^\n]*\n(?:[^\n]*\n){0,2}?[ \t]*nombre\s*[:\-]\s*([^\n]{3,120})/iu';
        if (preg_match($bloque, $texto, $m)) {
            $candidatos[] = $m[1];
        }

        $corto = '/(?:^|[^\w])(' . $quien . '|cliente)\s*[:\-]\s*([^\n]{3,120})/iu';
        if (preg_match($corto, $texto, $m)) {
            $candidatos[] = $m[2];
        }

        // Último recurso, con el RFC como pista: muchos facturadores imprimen el
        // nombre y el RFC en líneas consecutivas y nada más («AUTOSERVICIO
        // EJEMPLO» / «EJE091119AA1»), a veces con las columnas de la derecha
        // apiladas en la misma línea, y el nombre puede ocupar dos líneas
        // («FONDO NACIONAL DE» / «INFRAESTRUCTURA» / «EJE970829AA2»).
        if ($rfc !== '') {
            $lineas = preg_split('/\R/u', $texto);
            foreach ($lineas as $posicion => $linea) {
                $donde = stripos((string) $linea, $rfc);
                if ($donde === false) {
                    continue;
                }

                $partes = [];
                // Lo que hubiera en la misma línea, antes del RFC (columna izquierda).
                $enLaLinea = trim((string) preg_replace('/\s{2,}.*/u', '', substr((string) $linea, 0, (int) $donde)));
                if ($enLaLinea !== '' && !str_ends_with($enLaLinea, ':') && !self::esEtiqueta($enLaLinea)) {
                    $partes[] = $enLaLinea;
                }

                // Y las líneas de arriba, mientras formen parte del mismo bloque.
                for ($k = $posicion - 1; $k >= 0 && $k >= $posicion - 3; $k--) {
                    $candidata = trim((string) $lineas[$k]);
                    if ($candidata === '') {
                        break; // se acabó el bloque
                    }
                    $recortada = trim((string) preg_replace('/\s{2,}.*/u', '', $candidata));
                    if ($recortada === '' || str_ends_with($recortada, ':') || self::esEtiqueta($recortada)) {
                        continue; // «Folio Fiscal: …», encabezados: no son nombre
                    }
                    array_unshift($partes, $recortada);
                }

                if ($partes) {
                    $candidatos[] = implode(' ', $partes);
                }
                break;
            }
        }

        foreach ($candidatos as $candidato) {
            $valor = trim((string) preg_replace('/\s*RFC.*$/iu', '', $candidato));
            // Los PDF alinean las columnas con espacios: lo que sigue a la segunda
            // tanda de espacios ya pertenece a la columna de al lado.
            $valor = trim((string) preg_replace('/\s{2,}.*/u', '', $valor));
            // «Nombre: GRUPO DEMO» conserva su etiqueta cuando viene de una línea suelta.
            $valor = trim((string) preg_replace('/^(nombre|razón social|razon social)\s*[:\-]\s*/iu', '', $valor));
            if (mb_strlen($valor) < 3 || preg_match('/\b[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}\b/', $valor) || preg_match('/^\d+$/', $valor)) {
                continue;
            }
            return mb_substr($valor, 0, 120);
        }

        return '';
    }

    /**
     * Renglones de una factura leyendo una tabla en texto plano.
     *
     * Cada línea se intenta leer en este orden:
     *
     *   1. Por la posición de la cabecera («Cantidad … Concepto … Precio Unitario
     *      … Importe»), que es lo que respeta el orden real de cada formato.
     *   2. De derecha a izquierda (importe, precio unitario y cantidad), que es lo
     *      que funciona cuando no hay cabecera o cuando el OCR perdió la alineación.
     *   3. Si ninguna de las dos da un renglón, la línea se une al concepto anterior:
     *      los conceptos largos ocupan varias líneas.
     *
     * En OCR la separación es de un solo espacio y ninguna línea queda con
     * confianza «alta».
     *
     * @param string $patronColumnas  expresión que separa las columnas de la tabla
     * @param string $confianzaMaxima alta o media; en OCR, media
     * @return array<int, array{descripcion: string, sku: string, cantidad: string, valor_unitario: string, importe: string, descuento: string, confianza: string}>
     */
    public static function conceptosDeTexto(string $texto, string $patronColumnas = '/\s{2,}/u', string $confianzaMaxima = 'alta'): array
    {
        $lineas = preg_split('/\R/u', $texto);
        $mapa = self::cabeceraDeTabla($lineas, $patronColumnas);

        $lista = [];
        $abierta = null;   // posición del último renglón: ahí enganchan las líneas siguientes
        $distancia = 999;  // líneas transcurridas desde el último renglón

        foreach ($lineas as $bruta) {
            if (count($lista) >= 100) {
                break;
            }

            $linea = trim((string) $bruta);
            if (mb_strlen($linea) < 6) {
                continue;
            }

            $columnas = self::partirColumnas($linea, $patronColumnas);
            $distancia++;

            $renglon = $mapa !== null ? self::renglonPorColumnas($columnas, $mapa, $confianzaMaxima) : null;
            if ($renglon === null) {
                $renglon = self::renglonClasico($columnas, $confianzaMaxima);
            }

            if ($renglon !== null) {
                $lista[] = $renglon;
                $abierta = count($lista) - 1;
                $distancia = 0;
                continue;
            }

            // La clave del artículo en el catálogo del SAT trae su nombre a continuación
            // («15101515 - Gasolina premium mayor o igual a 91 octanos»). No es parte de
            // la descripción del renglón, pero sí el nombre real del producto: se guarda
            // aparte para clasificar el gasto y para que la persona lo vea.
            if ($abierta !== null && $distancia <= 6 && self::esClaveSat($linea)) {
                if (trim((string) ($lista[$abierta]['nota'] ?? '')) === '') {
                    $lista[$abierta]['nota'] = self::textoDespuesDeClaveSat($linea);
                }
                continue;
            }

            // No es un renglón nuevo: es la continuación del concepto anterior
            // («…MARCO.-» + «Ángulo de 1 1/4" y CONTRAMARCO.- Ángulo de 1"»).
            if ($abierta !== null && $distancia <= 6 && self::esContinuacion($linea)) {
                $lista[$abierta]['descripcion'] = mb_substr($lista[$abierta]['descripcion'] . ' ' . self::continuarDescripcion($linea), 0, 255);
            }
        }

        return $lista;
    }

    /**
     * Localiza la cabecera de la tabla y devuelve la posición de cada columna,
     * o null si la tabla no trae cabecera (entonces se lee de derecha a izquierda).
     *
     * @param array<int, string> $lineas
     * @return array<string, int|null>|null
     */
    private static function cabeceraDeTabla(array $lineas, string $patron): ?array
    {
        foreach ($lineas as $linea) {
            $columnas = self::partirColumnas(trim((string) $linea), $patron);
            if (count($columnas) < 3) {
                continue;
            }

            $mapa = ['cantidad' => null, 'unidad' => null, 'concepto' => null, 'impuesto' => null, 'descuento' => null, 'precio' => null, 'importe' => null];

            foreach ($columnas as $posicion => $titulo) {
                $clave = self::normalizarTitulo((string) $titulo);

                if ($mapa['cantidad'] === null && str_starts_with($clave, 'cant')) {
                    $mapa['cantidad'] = $posicion;
                } elseif ($mapa['concepto'] === null && preg_match('/^(concepto|descripcion|articulo|detalle|producto)/', $clave)) {
                    $mapa['concepto'] = $posicion;
                } elseif ($mapa['impuesto'] === null && preg_match('/^(iva|isc|ieps|impuesto|impuestos|retencion)/', $clave)) {
                    $mapa['impuesto'] = $posicion;
                } elseif ($mapa['descuento'] === null && str_starts_with($clave, 'descuento')) {
                    $mapa['descuento'] = $posicion;
                } elseif ($mapa['precio'] === null && preg_match('/^(precio|costo|valor unitario)/', $clave)) {
                    $mapa['precio'] = $posicion;
                } elseif ($mapa['importe'] === null && preg_match('/^(importe|total)/', $clave)) {
                    $mapa['importe'] = $posicion;
                } elseif ($mapa['unidad'] === null && str_starts_with($clave, 'unidad')) {
                    $mapa['unidad'] = $posicion;
                }
            }

            if ($mapa['cantidad'] !== null && $mapa['concepto'] !== null && $mapa['importe'] !== null) {
                return $mapa;
            }
        }

        return null;
    }

    /** Separa las columnas de una línea y limpia cada una. */
    private static function partirColumnas(string $linea, string $patron): array
    {
        $brutas = preg_split($patron, $linea);

        return array_values(array_filter(array_map('trim', (array) $brutas), static fn (string $c): bool => $c !== ''));
    }

    /** Título de columna comparable: minúsculas, sin acentos ni puntuación («Precio Unitario» → «precio unitario»). */
    private static function normalizarTitulo(string $titulo): string
    {
        $titulo = mb_strtolower(trim($titulo), 'UTF-8');
        $titulo = strtr($titulo, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^a-z0-9]+/u', ' ', $titulo)));
    }

    /**
     * Arma un renglón leyendo cada columna por la posición que indica la cabecera.
     *
     * @param array<int, string> $columnas
     * @param array<string, int|null> $mapa
     * @return array<string, string>|null null si la línea no encaja con la cabecera
     */
    private static function renglonPorColumnas(array $columnas, array $mapa, string $confianzaMaxima): ?array
    {
        $cantidad  = self::aNumero($columnas[$mapa['cantidad']] ?? '');
        $importe   = self::aNumero($columnas[$mapa['importe']] ?? '');
        $unitario  = $mapa['precio'] !== null ? self::aNumero($columnas[$mapa['precio']] ?? '') : null;
        $descuento = $mapa['descuento'] !== null ? self::aNumero($columnas[$mapa['descuento']] ?? '') : null;

        if ($cantidad === null || $cantidad <= 0 || $importe === null) {
            return null;
        }

        // El concepto va desde su columna hasta la siguiente que no le pertenezca
        // (cantidad, unidad, descuento, precio o importe), venga antes o después.
        $inicio = (int) $mapa['concepto'];
        $posteriores = array_filter(
            $mapa,
            static fn ($p, $k): bool => $k !== 'concepto' && $p !== null && (int) $p > $inicio,
            ARRAY_FILTER_USE_BOTH
        );
        $fin = $posteriores ? (int) min($posteriores) : count($columnas);

        $fragmentos = [];
        for ($i = $inicio; $i < $fin && $i < count($columnas); $i++) {
            // Ni la unidad ni el impuesto del propio renglón son descripción del producto.
            // El impuesto puede venir como «IVA Trasladado» o «Impuesto trasladado», así
            // que se compara por el inicio y no por igualdad exacta.
            $titulo = self::normalizarTitulo($columnas[$i]);
            $palabras = count(array_filter(explode(' ', $titulo), static fn (string $p): bool => $p !== ''));
            if ($palabras <= 6
                && preg_match('/^(iva|isc|ieps|impuesto|impuestos|retencion|trasladado|unidad|pza|pieza|servicio)\b/', $titulo)) {
                continue;
            }
            $fragmentos[] = $columnas[$i];
        }
        $descripcion = trim(implode(' ', $fragmentos));

        if ($descripcion === '' || self::esEtiqueta($descripcion)) {
            return null;
        }

        return [
            'descripcion'    => mb_substr($descripcion, 0, 255),
            'sku'            => '',
            'cantidad'       => self::numero($cantidad),
            'valor_unitario' => ($unitario !== null && $unitario > 0) ? self::numero($unitario) : '',
            'importe'        => self::numero($importe),
            'descuento'      => ($descuento !== null && $descuento > 0) ? self::numero($descuento) : '',
            'nota'            => '',
            'confianza'      => self::confianzaDeRenglon($cantidad, $unitario, $importe, (float) ($descuento ?? 0), $confianzaMaxima),
        ];
    }

    /**
     * Arma un renglón leyendo la tabla de derecha a izquierda: la última columna es
     * el importe, la anterior el precio unitario y la anterior la cantidad; lo que
     * sobra a la izquierda es la descripción. Servicio para los formatos sin
     * cabecera y para el OCR, que no conserva la alineación de las columnas.
     *
     * @param array<int, string> $columnas
     * @return array<string, string>|null null si la línea no es un renglón
     */
    private static function renglonClasico(array $columnas, string $confianzaMaxima): ?array
    {
        $totalCol = count($columnas);
        if ($totalCol < 3) {
            return null;
        }

        $numericas = 0;
        for ($i = $totalCol - 1; $i >= 1 && $numericas < 3; $i--) {
            if (self::aNumero($columnas[$i]) === null) {
                break;
            }
            $numericas++;
        }

        // Sin cantidad no hay renglón: si se aceptan sólo dos columnas numéricas,
        // una línea de continuación con el impuesto al lado («…2" 16.00% $820.80»)
        // se colaría como si fuera un producto.
        $cantidad = $numericas >= 3 ? self::aNumero($columnas[$totalCol - 3]) : null;
        if ($cantidad === null || $cantidad <= 0) {
            return null;
        }

        $importe     = self::aNumero($columnas[$totalCol - 1]);
        $unitario    = self::aNumero($columnas[$totalCol - 2]);
        $descripcion = trim(implode(' ', array_slice($columnas, 0, $totalCol - $numericas)));

        if ($importe === null || self::esEtiqueta($descripcion)) {
            return null;
        }

        return [
            'descripcion'    => mb_substr($descripcion, 0, 255),
            'sku'            => '',
            'cantidad'       => self::numero($cantidad),
            'valor_unitario' => ($unitario !== null && $unitario > 0) ? self::numero($unitario) : '',
            'importe'        => self::numero($importe),
            'descuento'      => '',
            'nota'            => '',
            'confianza'      => self::confianzaDeRenglon($cantidad, $unitario, $importe, 0.0, $confianzaMaxima),
        ];
    }

    /**
     * Confianza de un renglón: «alta» sólo si los números cuadran solos
     * (cantidad × precio ≈ importe, con o sin descuento); en OCR nunca «alta».
     */
    private static function confianzaDeRenglon(?float $cantidad, ?float $unitario, float $importe, float $descuento, string $confianzaMaxima): string
    {
        $bruto = ($cantidad !== null && $unitario !== null && $cantidad > 0) ? $cantidad * $unitario : null;
        $margen = max(0.02, abs($importe) * 0.02);
        $cuadra = $bruto !== null
            && (abs($bruto - $importe) <= $margen || abs(($bruto - $descuento) - $importe) <= $margen);

        if (!$cuadra) {
            return 'media';
        }

        return $confianzaMaxima === 'media' ? 'media' : 'alta';
    }

    /** «15101515 - Gasolina premium…» es la clave del artículo en el catálogo del SAT. */
    private static function esClaveSat(string $linea): bool
    {
        return (bool) preg_match('/^\d{4,}\s*-\s*\S/u', $linea);
    }

    /** El nombre real del artículo, que viene después de la clave del SAT. */
    private static function textoDespuesDeClaveSat(string $linea): string
    {
        return self::continuarDescripcion(trim((string) preg_replace('/^\d{4,}\s*-\s*/u', '', $linea)));
    }

    /**
     * ¿Esta línea sigue al concepto anterior, en vez de ser un renglón nuevo o un total?
     *
     * Descarta las claves del SAT («30102304 - Perfiles de acero»), el tipo de
     * objeto de impuesto («02 - Sí objeto de impuesto.»), las líneas que sólo son
     * el porcentaje y el importe del impuesto («16.00% $141.924232») y las
     * etiquetas de totales y encabezados, que nunca son descripción de un producto.
     */
    private static function esContinuacion(string $linea): bool
    {
        if (self::esClaveSat($linea)) {
            return false; // clave del producto en el catálogo del SAT
        }
        if (preg_match('/^\d{1,2}\s*-\s*(s[ií]|no)\b/iu', $linea)) {
            return false; // «02 - Sí objeto de impuesto»
        }
        if (!preg_match('/\p{L}/u', $linea)) {
            return false; // sólo cifras y símbolos: es un importe o un porcentaje
        }

        return !self::esEtiqueta($linea);
    }

    /**
     * Limpia una línea de continuación para unirla al concepto: la columna de la
     * derecha trae el impuesto del propio renglón («16.00% $820.80»), que no es
     * parte de la descripción del producto.
     */
    private static function continuarDescripcion(string $linea): string
    {
        // Se quita tantas veces como columnas de impuesto puedan apilarse a la derecha.
        $limpia = trim($linea);
        for ($i = 0; $i < 3; $i++) {
            $sinImpuesto = trim((string) preg_replace('/(?:^|\s+)\d{1,2}[.,]\d{1,2}\s*%\s*\$?[\d.,]*$/', '', $limpia));
            if ($sinImpuesto === $limpia) {
                break;
            }
            $limpia = $sinImpuesto;
        }

        return $limpia;
    }

    /**
     * ¿Es una etiqueta de totales, impuestos o encabezado? Nunca es un producto
     * (en OCR «IVA 16 %: 184.00» se parte en tres columnas y se colaría).
     */
    private static function esEtiqueta(string $texto): bool
    {
        return (bool) preg_match(
            '/^(sub\s*-?\s*total|total|iva|impuestos?|descuento|dcto|subtotal|forma|metodos?|fecha|folio|uuid|clientes?|emisors?|receptors?|rfcs?|cant(?:idad)?|precio|costo|importe|art[ií]culos?|descripci[oó]n|conceptos?|unidad|productos?|empresa|regimen|c[oó]digo|sellos?(?:\s+digital(?:s)?)?|cadena|autorizaci[oó]n|pac|certificaci[oó]n|letra|observaciones?|nota)\b/iu',
            $texto
        );
    }

    /**
     * Convierte un importe con formato mexicano a número, o null si no lo es.
     * «1,566.00» → 1566.00 · «1.566,00» → 1566.00 · «250,50» → 250.50.
     *
     * Un texto con letras no es un importe aunque contenga dígitos: si no se
     * rechaza, «solera de 2"» se leería como la cantidad 2.
     */
    private static function aNumero(string $texto): ?float
    {
        $texto = trim(str_replace(["\xc2\xa0", '$'], '', $texto));
        if ($texto === '' || preg_match('/\p{L}/u', $texto)) {
            return null;
        }

        $limpio = (string) preg_replace('/[^0-9,.\-]/u', '', $texto);
        if (!preg_match('/\d/', $limpio)) {
            return null;
        }

        if (str_contains($limpio, ',') && str_contains($limpio, '.')) {
            $limpio = str_replace(',', '', $limpio);
        } elseif (str_contains($limpio, ',') && preg_match('/,\d{1,2}$/', $limpio)) {
            $limpio = str_replace(',', '.', $limpio);
        } else {
            $limpio = str_replace(',', '', $limpio);
        }

        return is_numeric($limpio) ? (float) $limpio : null;
    }

    /** Número a texto con dos decimales, el formato que espera la capa de negocio. */
    private static function numero(float $valor): string
    {
        return number_format($valor, 2, '.', '');
    }

    /**
     * Fecha de emisión en AAAA-MM-DD.
     *
     * Se busca primero junto a la etiqueta «Fecha y hora de emisión», que es la
     * fecha real del comprobante; si no, junto a «fecha»; y sólo al final en todo
     * el texto. Si se mirara de golpe se pueden tomar fechas de otras columnas (la
     * del repago de un CFDI de pagos, la del timbre…) y adelantar o atrasar el gasto.
     */
    private static function fechaDelTexto(string $texto): string
    {
        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
                  'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

        $ventanas = [];
        foreach ([
            '/fecha\s*(?:y\s*hora\s*)?de\s*emisi[oó]n[^\n]{0,40}(?:\n[^\n]*){0,2}/iu',
            '/fecha[^\n]{0,40}(?:\n[^\n]*){0,1}/iu',
        ] as $patron) {
            if (preg_match($patron, $texto, $m) && !in_array($m[0], $ventanas, true)) {
                $ventanas[] = $m[0];
            }
        }
        $ventanas[] = $texto;

        foreach ($ventanas as $ventana) {
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
