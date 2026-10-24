<?php

namespace App;

/**
 * CoincidenciasFactura — cruza lo que un documento (XML, PDF u OCR) dice con lo
 * que la plataforma ya tiene: negocios, clientes, proveedores y productos.
 *
 * Es el motor que comparten el botón de «Registro Venta Inteligente» y el de
 * «Registro Compra Inteligente»: las mismas reglas de cotejo, el mismo orden de
 * prioridad y la misma idea de confianza para los dos casos.
 *
 * Reglas de cotejo (de más a menos fiable):
 *   1. RFC exacto.
 *   2. Nombre exacto, sin distinguir mayúsculas, acentos ni puntuación.
 *   3. Nombre parecido: tokens cubiertos o similitud de texto desde el 85 %.
 *
 * Este clase SOLO propone: no crea ni modifica ningún registro. Un producto que
 * no coincida con el catálogo se devuelve en «sin_equivalente» y es la persona
 * quien decide qué hacer con él.
 */
class CoincidenciasFactura
{
    /** Similitud mínima para aceptar un nombre aproximado. */
    public const UMBRAL_SIMILITUD = 85.0;

    /**
     * Proporción mínima de palabras cubiertas (0–1) para aceptar un producto por
     * su nombre o por su ficha técnica. Se mide en AMBAS direcciones y gana la
     * mejor: que el concepto no traiga cosas raras (concepto → producto) y que
     * las palabras del producto aparezcan en la factura (producto → concepto).
     * La segunda es la que resuelve los conceptos narrativos de los facturadores
     * en línea: «TAPA METÁLICA REFORZADA PARA CISTERNA DE 70X70 CM. (…)» contiene
     * completo al producto «Tapa metálica 70x70 cm reforzada», pero no al 60x60.
     */
    public const UMBRAL_TOKENS = 0.85;

    protected static $db;
    protected static array $errores = [];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function getErrores(): array
    {
        $e = self::$errores;
        self::$errores = [];
        return $e;
    }

    /**
     * Cruza un documento ya leído con el contenido de la plataforma.
     *
     * @param array  $lectura salida de LectorDocumental::leer() (admite sha256 opcional,
     *                        que usa para detectar el mismo archivo ya adjunto)
     * @param string $destino 'venta' (factura que emití) | 'gasto' (factura que me dieron)
     * @return array<string, mixed> propuesta para precargar el formulario correspondiente
     */
    public static function analizar(array $lectura, string $destino): array
    {
        $campos = static fn (string $clave): string => (string) ($lectura['campos'][$clave]['valor'] ?? '');

        $subtotal  = self::numero($campos('subtotal'));
        $descuento = self::numero($campos('descuento'));
        $iva       = self::numero($campos('iva'));
        $total     = self::numero($campos('total'));
        $fecha     = self::fecha($campos('fecha'));

        $resultado = [
            'fuente'       => (string) ($lectura['fuente'] ?? 'manual'),
            'avisos'       => (array) ($lectura['avisos'] ?? []),
            'campos'       => (array) ($lectura['campos'] ?? []),
            'fecha'        => $fecha,
            'subtotal'     => $subtotal,
            'descuento'    => $descuento,
            'iva'          => $iva,
            'iva_tasa'     => self::ivaTasa($subtotal, $iva),
            'total'        => $total,
            'moneda'       => $campos('moneda'),
            'forma_pago'   => $campos('forma_pago'),
            'metodo_pago'  => $campos('metodo_pago'),
            'folio'        => $campos('folio_comercial'),
            'uuid'         => strtoupper($campos('uuid')),
            'empresa'      => null,
            'cliente'      => null,
            'proveedor'    => null,
            'categoria'    => null,
            'conceptos'    => [],
            'lineas'       => [],
            'sin_equivalente' => [],
            'duplicado'    => null,
            'duplicados'   => [],
        ];

        // El negocio propio por RFC: si el RFC es de los dos negocios a la vez no se
        // elige ninguno y se avisa (queda el selector del formulario a criterio de la persona).
        $resultado['empresa'] = self::negocio(
            $campos('emisor_rfc'), $campos('emisor_nombre'),
            $campos('receptor_rfc'), $campos('receptor_nombre'),
            $destino,
            $resultado['avisos']
        );

        // Sólo se busca por fecha si el documento la trae: si no, la de hoy se
        // convertiría en una falsa coincidencia con las ventas del día.
        $fechaDeducida = $campos('fecha') !== '' ? $resultado['fecha'] : '';
        if ($resultado['uuid'] !== '' || $resultado['folio'] !== '' || ($fechaDeducida !== '' && $resultado['total'] > 0)) {
            $resultado['duplicados'] = self::duplicados(
                $resultado['uuid'],
                $resultado['folio'],
                $fechaDeducida,
                $destino,
                (string) ($lectura['sha256'] ?? ''),
                $resultado['total'],
                // El tercero del documento: en una venta es el cliente, en un gasto el proveedor.
                $destino === 'venta' ? $campos('receptor_rfc') : $campos('emisor_rfc')
            );

            foreach ($resultado['duplicados'] as $coincidencia) {
                if ($coincidencia['criterio'] === 'folio fiscal (UUID)') {
                    $resultado['duplicado'] = (string) $coincidencia['id'];
                    break;
                }
            }
        }

        if ($destino === 'venta') {
            // En una factura emitida por mí, el RECEPTOR es el cliente.
            $resultado['cliente'] = self::cliente($campos('receptor_rfc'), $campos('receptor_nombre'), $resultado['avisos']);
        } else {
            // En una factura que me dieron, el EMISOR es quien me cobró.
            $resultado['proveedor'] = self::proveedor($campos('emisor_rfc'), $campos('emisor_nombre'), $resultado['avisos']);
            $resultado['categoria'] = self::categoria($campos('emisor_nombre') . ' ' . self::primerosConceptos($lectura['conceptos'] ?? []));
        }

        $resultado['conceptos'] = self::conceptos((array) ($lectura['conceptos'] ?? []), $resultado['empresa']);

        foreach ($resultado['conceptos'] as $concepto) {
            if ($concepto['producto'] === null) {
                $resultado['sin_equivalente'][] = $concepto;
            } else {
                $resultado['lineas'][] = self::lineaDelConcepto($concepto);
            }
        }

        return $resultado;
    }

    /* --------------------------------------------------------------------
     * CONCEPTOS → RENGLONES
     * ------------------------------------------------------------------ */

    /**
     * Coteja cada concepto del documento con el catálogo de productos.
     *
     * @param array<int, array<string, string>> $conceptos
     * @return array<int, array<string, mixed>>
     */
    private static function conceptos(array $conceptos, ?array $empresa): array
    {
        $catalogo = self::catalogo($empresa['id'] ?? null);
        $lista    = [];

        foreach ($conceptos as $concepto) {
            $descripcion = trim((string) ($concepto['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }

            $sku     = strtoupper(trim((string) ($concepto['sku'] ?? '')));
            $producto = null;
            $criterio = '';

            // 1. SKU exacto del documento contra el del catálogo.
            if ($sku !== '') {
                foreach ($catalogo as $candidato) {
                    if ($candidato['sku'] !== '' && $candidato['sku'] === $sku) {
                        $producto = $candidato;
                        $criterio = 'SKU ' . $sku;
                        break;
                    }
                }
            }

            // 2. Nombre exacto y 3. nombre parecido.
            if ($producto === null) {
                $parado = self::productoParecido($catalogo, $descripcion);
                if ($parado !== null) {
                    [$producto, $criterio] = $parado;
                }
            }

            $cantidad    = self::numero((string) ($concepto['cantidad'] ?? ''));
            $unitario    = self::numero((string) ($concepto['valor_unitario'] ?? ''));
            $importe     = self::numero((string) ($concepto['importe'] ?? ''));
            $confianza   = (string) ($concepto['confianza'] ?? 'media');

            $lista[] = [
                'descripcion'    => $descripcion,
                'cantidad'       => $cantidad > 0 ? $cantidad : 1.0,
                'valor_unitario' => $unitario,
                'importe'        => $importe,
                'descuento'      => self::numero((string) ($concepto['descuento'] ?? '')),
                // Nombre real del artículo que sigue a la clave del SAT (si la trae).
                'nota'           => trim((string) ($concepto['nota'] ?? '')),
                'producto'       => $producto,
                'criterio'       => $criterio,
                'confianza'      => $producto !== null ? $confianza : 'baja',
            ];
        }

        return array_slice($lista, 0, 100);
    }

    /**
     * Producto del catálogo más parecido a la descripción del documento.
     *
     * @param array<int, array<string, mixed>> $catalogo
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private static function productoParecido(array $catalogo, string $descripcion): ?array
    {
        $objetivo = self::normalizar($descripcion);
        if ($objetivo === '') {
            return null;
        }

        $mejor = null;
        $mejorCriterio = '';
        $mejorPuntaje = 0.0;

        foreach ($catalogo as $producto) {
            if ($producto['clave'] === $objetivo) {
                return [$producto, 'nombre exacto'];
            }

            $similitud = self::similitud($objetivo, $producto['clave']);
            [$cobertura, $origen] = self::coberturaProducto($objetivo, $producto['clave'], $producto['ficha']);

            // Cada criterio tiene su propio umbral: el más generoso no cubre al otro.
            if ($similitud < self::UMBRAL_SIMILITUD && $cobertura < self::UMBRAL_TOKENS) {
                continue;
            }

            $puntaje = max($similitud, $cobertura * 100);
            if ($puntaje <= $mejorPuntaje) {
                continue;
            }

            $mejorPuntaje = $puntaje;
            $mejor = $producto;
            $mejorCriterio = $similitud > $cobertura * 100
                ? 'nombre parecido (' . number_format($similitud, 0) . ' %)'
                : $origen . ' (' . number_format($cobertura * 100, 0) . ' % de las palabras)';
        }

        if ($mejor === null) {
            return null;
        }

        return [$mejor, $mejorCriterio];
    }

    /**
     * Qué tan bien explica el producto al concepto del documento, probando las
     * direcciones útiles y quedándose con la mejor.
     *
     *   · concepto → nombre  «el concepto se explica con el nombre del producto»
     *   · concepto → ficha   «…o con su ficha técnica completa»
     *   · nombre → concepto  «el nombre del producto aparece entero en la factura»
     *
     * @return array{0: float, 1: string} proporción (0–1) y el texto del criterio
     */
    private static function coberturaProducto(string $concepto, string $nombre, string $ficha): array
    {
        $candidatos = [
            [self::coberturaTokens($concepto, $nombre), 'nombre parecido'],
            [self::coberturaTokens($concepto, $ficha),  'ficha del producto'],
            [self::coberturaTokens($nombre, $concepto), 'nombre en la factura'],
        ];

        $mejor = [0.0, 'nombre parecido'];
        foreach ($candidatos as [$valor, $origen]) {
            // Con «>» los empates los gana el criterio más específico de la lista.
            if ($valor > $mejor[0]) {
                $mejor = [$valor, $origen];
            }
        }

        return $mejor;
    }

    /** Renglón en el formato que espera el cotizador (templates/formularioCotizaciones.php). */
    private static function lineaDelConcepto(array $concepto): array
    {
        $producto = $concepto['producto'];
        $precio   = $concepto['valor_unitario'] > 0 ? $concepto['valor_unitario'] : (float) $producto['precio'];
        $descPct  = 0.0;

        // Descuento del documento como porcentaje del renglón, si lo trae.
        if ($concepto['descuento'] > 0 && ($concepto['cantidad'] * $precio) > 0) {
            $descPct = round($concepto['descuento'] / ($concepto['cantidad'] * $precio) * 100, 2);
            $descPct = min(100.0, max(0.0, $descPct));
        }

        return [
            'id'            => (int) $producto['id'],
            'nombre'        => (string) $producto['nombre'],
            'sku'           => (string) $producto['sku'],
            'categoria'     => (string) $producto['categoria'],
            'unidad'        => (string) $producto['unidad'],
            'imagen'        => (string) $producto['imagen'],
            'precio_lista'  => (float) $producto['precio'],
            'cantidad'      => max(1, (int) round($concepto['cantidad'])),
            'precio'        => round($precio, 2),
            'descuento_pct' => $descPct,
            'no_recordar'   => false,
            'origen'        => $concepto['criterio'],
        ];
    }

    /* --------------------------------------------------------------------
     * CLIENTES, PROVEEDORES Y NEGOCIOS
     * ------------------------------------------------------------------ */

    /** @return array{id: int, nombre: string, criterio: string}|null */
    private static function cliente(string $rfc, string $nombre, ?array &$avisos = null): ?array
    {
        return self::cotejar(self::tabla('SELECT id, nombre, nombre_empresa, rfc FROM clientes ORDER BY nombre ASC'), $rfc, $nombre, $avisos);
    }

    /** @return array{id: int, nombre: string, criterio: string}|null */
    private static function proveedor(string $rfc, string $nombre, ?array &$avisos = null): ?array
    {
        return self::cotejar(self::tabla('SELECT id, nombre, rfc FROM proveedores WHERE activo = 1 ORDER BY nombre ASC'), $rfc, $nombre, $avisos);
    }

    /** El negocio propio que emite (venta) o el que recibe (gasto) la factura. */
    private static function negocio(string $emisorRfc, string $emisorNombre, string $receptorRfc, string $receptorNombre, string $destino, ?array &$avisos = null): ?array
    {
        // En una venta emitida por mí el emisor soy yo; en un gasto, el receptor.
        $mioRfc    = $destino === 'venta' ? $emisorRfc : $receptorRfc;
        $mioNombre = $destino === 'venta' ? $emisorNombre : $receptorNombre;

        $candidatos = self::tabla('SELECT id, nombre, rfc FROM empresas WHERE activa = 1 ORDER BY nombre ASC');
        $coincidido = self::cotejar($candidatos, $mioRfc, $mioNombre, $avisos);
        if ($coincidido !== null) {
            return $coincidido;
        }

        // Sin coincidencia se propone el único negocio activo, si es que sólo hay uno.
        if (count($candidatos) === 1) {
            return ['id' => (int) $candidatos[0]['id'], 'nombre' => (string) $candidatos[0]['nombre'], 'criterio' => 'único negocio activo'];
        }

        return null;
    }

    /**
     * Busca el registro que más se parezca al nombre o al RFC que trae el documento.
     *
     * Si el RFC aparece en más de un registro no se elige ninguno: con dos negocios
     * que comparten el mismo RFC del contribuyente, precargar el primero sería
     * asignar un dato contable al azar. Se avisa y se sigue buscando por nombre.
     *
     * @param array<int, array<string, mixed>> $registros
     * @param string[]|null $avisos canal para las ambigüedades que encuentre
     * @return array{id: int, nombre: string, criterio: string}|null
     */
    private static function cotejar(array $registros, string $rfc, string $nombre, ?array &$avisos = null): ?array
    {
        $rfc = strtoupper(trim($rfc));
        $objetivo = self::normalizar($nombre);

        if ($rfc !== '') {
            $coincidencias = [];
            foreach ($registros as $registro) {
                if (strtoupper(trim((string) ($registro['rfc'] ?? ''))) === $rfc) {
                    $coincidencias[] = $registro;
                }
            }
            if (count($coincidencias) === 1) {
                $unico = $coincidencias[0];

                return ['id' => (int) $unico['id'], 'nombre' => (string) $unico['nombre'], 'criterio' => 'RFC ' . $rfc];
            }
            if (count($coincidencias) > 1 && $avisos !== null) {
                $nombres = array_map(static fn (array $r): string => (string) $r['nombre'], $coincidencias);
                $avisos[] = 'El RFC ' . $rfc . ' aparece en ' . count($coincidencias)
                    . ' registros (' . implode(', ', $nombres) . '); elige cuál corresponde al documento.';
            }
        }

        if ($objetivo === '') {
            return null;
        }

        $mejor = null;
        $mejorCriterio = '';
        $mejorPuntaje = 0.0;

        foreach ($registros as $registro) {
            $alias   = self::normalizar((string) ($registro['nombre'] ?? ''));
            $empresa = self::normalizar((string) ($registro['nombre_empresa'] ?? ''));

            foreach (array_filter([$alias, $empresa]) as $clave) {
                if ($clave === $objetivo) {
                    return ['id' => (int) $registro['id'], 'nombre' => (string) $registro['nombre'], 'criterio' => 'nombre exacto'];
                }

                $similitud = self::similitud($objetivo, $clave);
                // Igual que en productos: se mide en las dos direcciones.
                $cobertura = max(
                    self::coberturaTokens($objetivo, $clave),
                    self::coberturaTokens($clave, $objetivo)
                );

                if ($similitud < self::UMBRAL_SIMILITUD && $cobertura < self::UMBRAL_TOKENS) {
                    continue;
                }

                $puntaje = max($similitud, $cobertura * 100);
                if ($puntaje <= $mejorPuntaje) {
                    continue;
                }

                $mejorPuntaje = $puntaje;
                $mejor = $registro;
                $mejorCriterio = $similitud > $cobertura * 100
                    ? 'nombre parecido (' . number_format($similitud, 0) . ' %)'
                    : 'nombre parecido (' . number_format($cobertura * 100, 0) . ' % de las palabras)';
            }
        }

        if ($mejor === null) {
            return null;
        }

        return ['id' => (int) $mejor['id'], 'nombre' => (string) $mejor['nombre'], 'criterio' => $mejorCriterio];
    }

    /* --------------------------------------------------------------------
     * CATÁLOGO Y AUXILIARES
     * ------------------------------------------------------------------ */

    /**
     * Productos activos; si el negocio ya se identificó, sólo los suyos
     * (los de los demás no se pueden vender en su venta).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function catalogo(?int $empresaId): array
    {
        $donde = $empresaId ? 'WHERE activo = 1 AND empresa_id = ' . (int) $empresaId : 'WHERE activo = 1';

        $registros = self::tabla(
            'SELECT id, empresa_id, nombre, sku, categoria, unidad, precio, imagen, descripcion FROM productos ' . $donde . ' ORDER BY nombre ASC'
        );

        $catalogo = [];
        foreach ($registros as $registro) {
            $nombre  = (string) $registro['nombre'];
            $catalogo[] = [
                'id'         => (int) $registro['id'],
                'empresa_id' => (int) $registro['empresa_id'],
                'nombre'     => $nombre,
                'sku'        => strtoupper(trim((string) ($registro['sku'] ?? ''))),
                'categoria'  => (string) ($registro['categoria'] ?? ''),
                'unidad'     => (string) ($registro['unidad'] ?? 'pza'),
                'precio'     => (float) ($registro['precio'] ?? 0),
                'imagen'     => (string) ($registro['imagen'] ?? ''),
                'clave'      => self::normalizar($nombre),
                // Ficha técnica: sirve cuando el concepto de la factura es narrativo
                // y el nombre corto no basta para cotejar («…MARCO. Ángulo de 1 1/4»).
                'ficha'      => self::normalizar($nombre . ' ' . (string) ($registro['descripcion'] ?? '')),
            ];
        }

        return $catalogo;
    }

    /**
     * Reglas de detección del tipo de gasto: nombre de la categoría → expresión.
     *
     * Es el ÚNICO origen de verdad: lo consumen la lectura inteligente (este
     * método) y el formulario de gasto (templates/formularioGasto.php), que las
     * manda al navegador. Antes había dos listas —una en PHP y otra en
     * JavaScript— y se habían desincronizado: el formulario no sugería «acero»
     * ni «solera» y la lectura inteligente sí.
     *
     * Se evalúan en orden y gana la primera que coincida, así que las más
     * específicas van antes. Frontera entre las dos categorías de materiales:
     *
     *   · Material y acero        materia prima y mercancía de acero: planchas,
     *                             perfiles, ángulos, soleras, varillas, registros y tapas.
     *   · Ferretería y materiales lo del taller: tornillos, pintura, cable,
     *                             herramienta, discos y adhesivos.
     *
     * «Otros» y «Servicios» no llevan regla: son el reservorio y lo genérico.
     */
    public const REGLAS_CATEGORIA = [
        'Combustible y transporte'      => 'gasolin|diesel|combustible|caseta|peaje|cruc(?:e|eo)|autopista|pemex|oxxo\s*gas|uber|taxi|estacionamiento',
        'Ferretería y materiales'       => 'ferreter|home\s*depot|comex|pintura|tornillo|torniller|material|interceramic|ferremax|cable|clavo|herramient|disco\s+(?:de\s+)?cor(?:te|adas?)|amoladora|esmeril|adhesivo|silic[óo]n|luminaria',
        'Material y acero'              => 'acero|l[áa]mina|lamina|solera|perfi(?:l|les)|ángulo|angulo|varilla|redondo|plancha|pliego|tubo|cuadro|esquinero|registros?\s+(?:de|para)|tapas?\s+met|vialidad|met[áa]lica',
        'Impuestos y derechos'         => 'impuesto|\bsat\b|predial|licencia|permiso|derechos?|aranceles?|recargos?',
        'Luz, agua y gas'               => '\bcfe\b|\bluz\b|conagua|oomsapas|\bagua\b|\bgas\b',
        'Teléfono e internet'           => 'telcel|telmex|izzi|totalplay|megacable|internet|tel[eé]fono|\bat&t\b',
        'Papelería y oficina'           => 'papeler|office\s*max|oficina|toner|impresi',
        // Antes que «Insumos»: un «curso de soldadura» es capacitación, no un insumo.
        'Seguridad y capacitación'      => 'casco|guantes|chaleco|equipo de protecci|\bepp\b|higiene|curso|seminario|capacitaci|diplomado|entrenamiento',
        'Insumos de taller'             => 'insumos?|consumibles?|soldadura|electrodo',
        'Mantenimiento'                 => 'mantenimiento|reparaci|refaccion|servicio t[eé]cnico|alineaci|balanceo',
        'Honorarios'                    => 'honorarios|contador|notario|abogado|auditor',
        'Sueldos'                       => 'sueldo|n[oó]mina|salario',
        'Renta'                         => 'renta|arrendamiento',
        'Seguros'                       => 'seguro|p[oó]liza',
        'Gastos bancarios y comisiones' => 'comisi|inter[eé]s|cuota anual|mantenimiento de cuenta|transferencia bancaria|\bbanco\b|bbva|santander|banorte|hsbc|citibanamex',
        'Flete y maniobras'             => 'flete|maniobra|acarreo|mudanza|paqueter|transporte de mercanc',
        'Donativos'                     => 'donativo|teleton|telet[oó]n|cruz roja|donaci',
        'Alimentos y despensa'          => 'soriana|walmart|costco|super|despensa|caf[eé]|panader|restaurante|comida',
        'Retiros del propietario'       => 'retiro|disposici[oó]n',
    ];

    /**
     * Categoría de gasto más probable a partir del nombre del proveedor y sus conceptos.
     *
     * @return array{id: int, nombre: string}|null
     */
    private static function categoria(string $texto): ?array
    {
        $buscado = self::normalizar($texto);
        if ($buscado === '') {
            return null;
        }

        foreach (self::REGLAS_CATEGORIA as $nombre => $patron) {
            // El texto llega sin acentos (normalizar()); los patrones cubren ambas variantes.
            if (preg_match('/' . $patron . '/iu', $buscado)) {
                return ['id' => self::categoriaPorNombre($nombre), 'nombre' => $nombre];
            }
        }

        return null;
    }

    private static function categoriaPorNombre(string $nombre): int
    {
        $consulta = self::$db->prepare('SELECT id FROM categorias_gasto WHERE nombre = ? LIMIT 1');
        $consulta->bind_param('s', $nombre);
        $consulta->execute();
        $id = (int) ($consulta->get_result()->fetch_row()[0] ?? 0);
        $consulta->close();

        return $id;
    }

    /**
     * Registros que ya existen con esta misma factura.
     *
     * La pregunta no es «¿hay algo ese día?» ni «¿es el mismo cliente?»: un
     * cliente o un proveedor pueden tener varias facturas y varias ventas
     * pueden caer en el mismo día. Lo que identifica AL DOCUMENTO es esto:
     *
     *   1. mismo archivo (sha256)               el PDF/XML ya está adjunto en algún lado
     *   2. folio fiscal (UUID)                  es el mismo CFDI: el timbre es único
     *   3. folio de factura + fecha o importe   misma serie y folio, y que además cuadre
     *                                          el día o el monto (un folio suelto se
     *                                          repite entre proveedores)
     *   4. mismo importe + (fecha o RFC)        el mismo monto el mismo día, o el mismo
     *                                          monto facturado por el mismo tercero;
     *                                          sólo contra registros del mismo tipo
     *
     * Y sobre todo: nada que se CONTRADIGA. Si el registro ya trae otro folio
     * fiscal, otro folio u otro RFC que el del documento, son facturas distintas
     * aunque compartan fecha e importe. El RFC sólo sirve para descartar, nunca
     * por sí solo para señalar: el mismo proveedor factura muchas veces.
     *
     * @param string $destino 'venta' | 'gasto' (delimita el criterio 4)
     * @param float  $total   importe del documento (para los criterios 3 y 4)
     * @param string $rfc     RFC del tercero: receptor en una venta, emisor en un gasto
     *
     * @return array<int, array{tipo: string, id: int, ruta: string, etiqueta: string,
     *                          folio: string, fecha: string, total: float,
     *                          detalle: string, criterio: string}>
     */
    public static function duplicados(
        string $uuid,
        string $folio,
        string $fecha,
        string $destino,
        string $sha256 = '',
        float $total = 0.0,
        string $rfc = ''
    ): array {
        $encontrados = [];
        $destino = in_array($destino, ['venta', 'gasto'], true) ? $destino : '';
        $doc = [
            'uuid'  => strtoupper(trim($uuid)),
            'folio' => self::folioNormalizado($folio),
            'fecha' => preg_match('/^20\d{2}-\d{2}-\d{2}$/', $fecha) === 1 ? $fecha : '',
            'total' => round($total, 2),
            'rfc'   => strtoupper(trim($rfc)),
        ];

        // 1) El mismo archivo ya fue adjuntado antes (mismo PDF o XML).
        if ($sha256 !== '') {
            foreach (self::consultar("SELECT entidad, entidad_id FROM documentos WHERE activo = 1 AND sha256 = ? LIMIT 3", 's', $sha256) as $doc2) {
                $tipo = $doc2['entidad'] === 'gasto' ? 'gasto' : 'venta';
                // Los documentos de una venta se guardan contra su ingreso.
                $id = $tipo === 'venta' ? self::ventaDelIngreso((int) $doc2['entidad_id']) : (int) $doc2['entidad_id'];
                if ($id <= 0) {
                    continue;
                }
                $registro = self::datosDeRegistro($tipo, $id, 'mismo archivo');
                if ($registro) {
                    $encontrados[] = $registro;
                }
            }
        }

        // 2) Folio fiscal (UUID) en un gasto o en un ingreso (→ su venta).
        if ($doc['uuid'] !== '') {
            foreach (self::consultar("SELECT id FROM gastos WHERE (uuid_vigente = ? OR uuid_fiscal = ?) AND estado = 'vigente' LIMIT 2", 'ss', $doc['uuid'], $doc['uuid']) as $g) {
                $registro = self::datosDeRegistro('gasto', (int) $g['id'], 'folio fiscal (UUID)');
                if ($registro) {
                    $encontrados[] = $registro;
                }
            }
            foreach (self::consultar('SELECT venta_id FROM ingresos WHERE uuid_fiscal = ? AND venta_id IS NOT NULL LIMIT 2', 's', $doc['uuid']) as $i) {
                $registro = self::datosDeRegistro('venta', (int) $i['venta_id'], 'folio fiscal (UUID)');
                if ($registro) {
                    $encontrados[] = $registro;
                }
            }
        }

        // 3) Folio de factura (Serie + Folio): sólo cuenta si además cuadra la
        //    fecha o el importe, y si el registro no trae otro folio fiscal.
        if ($doc['folio'] !== '') {
            $porFolio = "SELECT id, fecha, total, rfc_emisor AS rfc, uuid_fiscal AS uuid, folio_comercial AS folio
                           FROM gastos WHERE REPLACE(UPPER(folio_comercial), ' ', '') = ? AND estado = 'vigente' LIMIT 5";
            foreach (self::consultar($porFolio, 's', $doc['folio']) as $g) {
                $criterio = self::criterioDeRegistro($g, $doc, true);
                if ($criterio === null) {
                    continue;
                }
                $registro = self::datosDeRegistro('gasto', (int) $g['id'], $criterio);
                if ($registro) {
                    $encontrados[] = $registro;
                }
            }
            $porFolioVenta = "SELECT i.venta_id AS id, i.fecha, i.total, cl.rfc, i.uuid_fiscal AS uuid, i.factura_folio AS folio
                                FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id
                               WHERE REPLACE(UPPER(i.factura_folio), ' ', '') = ? AND i.venta_id IS NOT NULL LIMIT 5";
            foreach (self::consultar($porFolioVenta, 's', $doc['folio']) as $i) {
                $criterio = self::criterioDeRegistro($i, $doc, true);
                if ($criterio === null) {
                    continue;
                }
                $registro = self::datosDeRegistro('venta', (int) $i['id'], $criterio);
                if ($registro) {
                    $encontrados[] = $registro;
                }
            }
        }

        // 4) Mismo importe: convence con la fecha del documento o con el RFC del
        //    tercero; nunca sólo por estar en el mismo día. Sólo se coteja con
        //    registros del mismo tipo que el documento (una compra no se compara
        //    con una venta: son libros distintos).
        if ($doc['total'] > 0 && $destino === 'gasto') {
            $porImporte = "SELECT id, fecha, total, rfc_emisor AS rfc, uuid_fiscal AS uuid, folio_comercial AS folio
                             FROM gastos WHERE ABS(total - ?) < 0.01 AND estado = 'vigente'
                            ORDER BY fecha DESC, id DESC LIMIT 10";
            foreach (self::consultar($porImporte, 'd', $doc['total']) as $g) {
                $criterio = self::criterioDeRegistro($g, $doc, false);
                if ($criterio === null) {
                    continue;
                }
                $registro = self::datosDeRegistro('gasto', (int) $g['id'], $criterio);
                if ($registro) {
                    $encontrados[] = $registro;
                }
            }
        } elseif ($doc['total'] > 0) {
            $porImporteVenta = "SELECT v.id, v.fecha, v.total, cl.rfc, i.uuid_fiscal AS uuid, i.factura_folio AS folio
                                  FROM ventas v
                                  LEFT JOIN clientes cl ON cl.id = v.cliente_id
                                  LEFT JOIN ingresos i ON i.venta_id = v.id
                                 WHERE v.estado = 'registrada' AND ABS(v.total - ?) < 0.01
                                ORDER BY v.fecha DESC, v.id DESC LIMIT 10";
            foreach (self::consultar($porImporteVenta, 'd', $doc['total']) as $v) {
                $criterio = self::criterioDeRegistro($v, $doc, false);
                if ($criterio === null) {
                    continue;
                }
                $registro = self::datosDeRegistro('venta', (int) $v['id'], $criterio);
                if ($registro) {
                    $encontrados[] = $registro;
                }
            }
        }

        // Sin repetir la misma fila si la encontró más de un criterio.
        $vistos = [];
        $unicos = [];
        foreach ($encontrados as $registro) {
            $clave = $registro['tipo'] . '#' . $registro['id'];
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $unicos[] = $registro;
        }

        return $unicos;
    }

    /**
     * ¿El registro encontrado corresponde a este documento?
     *
     * Primero se descarta lo que se contradice; después se pide la coincidencia
     * que toca según por qué lo haya encontrado la búsqueda: si ya coincidía el
     * folio ($porFolio) basta con que cuadre la fecha o el importe, y si ya
     * coincidía el importe hace falta la fecha o el mismo RFC del tercero.
     *
     * @param array{fecha?: string, total?: mixed, rfc?: string, uuid?: string, folio?: string} $fila registro existente
     * @param array{uuid: string, folio: string, fecha: string, total: float, rfc: string}      $doc  documento leído
     * @return string|null texto del criterio para el aviso, o null si no corresponde
     */
    private static function criterioDeRegistro(array $fila, array $doc, bool $porFolio): ?string
    {
        if (self::seContradice($fila, $doc)) {
            return null;
        }

        $mismoDia = $doc['fecha'] !== '' && (string) ($fila['fecha'] ?? '') === $doc['fecha'];
        $mismoImporte = $doc['total'] > 0
            && Contabilidad::centavos($fila['total'] ?? 0) === Contabilidad::centavos($doc['total']);

        if ($porFolio) {
            if ($mismoDia) {
                return 'folio de factura y misma fecha';
            }
            if ($mismoImporte) {
                return 'folio de factura y mismo importe';
            }

            return null;
        }

        if ($mismoDia) {
            return 'misma fecha e importe';
        }

        // Sin la fecha hace falta el RFC de los dos lados: el importe sólo
        // convence si es el mismo tercero el que lo factura.
        $delRegistro = strtoupper(trim((string) ($fila['rfc'] ?? '')));

        return $doc['rfc'] !== '' && $delRegistro === $doc['rfc'] ? 'mismo importe y RFC' : null;
    }

    /**
     * El registro trae otros datos de identificación que el documento.
     *
     * Dos UUID distintos, dos folios distintos o dos RFC distintos significan
     * facturas distintas, aunque compartan fecha e importe: no hay nada que
     * avisar, hay algo que descartar. Un lado vacío no contradice (falta el
     * dato, no es otro valor).
     *
     * @param array{fecha?: string, total?: mixed, rfc?: string, uuid?: string, folio?: string} $fila
     * @param array{uuid: string, folio: string, fecha: string, total: float, rfc: string}      $doc
     */
    private static function seContradice(array $fila, array $doc): bool
    {
        $otroUuid  = strtoupper(trim((string) ($fila['uuid'] ?? '')));
        $otroFolio = self::folioNormalizado((string) ($fila['folio'] ?? ''));
        $otroRfc   = strtoupper(trim((string) ($fila['rfc'] ?? '')));

        return ($doc['uuid'] !== '' && $otroUuid !== '' && $otroUuid !== $doc['uuid'])
            || ($doc['folio'] !== '' && $otroFolio !== '' && $otroFolio !== $doc['folio'])
            || ($doc['rfc'] !== '' && $otroRfc !== '' && $otroRfc !== $doc['rfc']);
    }

    /** Folio comparable: «BEX 21324» y «BEX21324» son el mismo folio. */
    private static function folioNormalizado(string $folio): string
    {
        return strtoupper(str_replace(' ', '', trim($folio)));
    }

    /** Venta a la que pertenece un ingreso (0 si no está ligado a ninguna). */
    private static function ventaDelIngreso(int $ingresoId): int
    {
        $filas = self::consultar('SELECT venta_id FROM ingresos WHERE id = ? LIMIT 1', 'i', $ingresoId);

        return (int) ($filas[0]['venta_id'] ?? 0);
    }

    /** Ficha de un registro ya existente, con todo lo que necesita el aviso. */
    private static function datosDeRegistro(string $tipo, int $id, string $criterio): ?array
    {
        if ($tipo === 'gasto') {
            // Un gasto cancelado ya no es «el que existe»: no se señala como duplicado.
            $filas = self::consultar("SELECT id, folio_comercial, fecha, total, concepto, beneficiario FROM gastos WHERE id = ? AND estado = 'vigente' LIMIT 1", 'i', $id);
            $fila = $filas[0] ?? null;
            if (!$fila) {
                return null;
            }

            return [
                'tipo'     => 'gasto',
                'id'       => (int) $fila['id'],
                'ruta'     => '/admin/contabilidad/gastos/detalle?id=' . (int) $fila['id'],
                'etiqueta' => 'Gasto #' . (int) $fila['id'],
                'folio'    => (string) ($fila['folio_comercial'] ?? ''),
                'fecha'    => (string) ($fila['fecha'] ?? ''),
                'total'    => (float) ($fila['total'] ?? 0),
                'detalle'  => trim((string) ($fila['concepto'] ?? '') . (!empty($fila['beneficiario']) ? ' · ' . $fila['beneficiario'] : '')),
                'criterio' => $criterio,
            ];
        }

        $filas = self::consultar(
            'SELECT v.id, v.folio, v.fecha, v.total, c.nombre AS cliente
               FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id
              WHERE v.id = ? LIMIT 1',
            'i',
            $id
        );
        $fila = $filas[0] ?? null;
        if (!$fila) {
            return null;
        }

        return [
            'tipo'     => 'venta',
            'id'       => (int) $fila['id'],
            'ruta'     => '/admin/ventas/detalle?id=' . (int) $fila['id'],
            'etiqueta' => 'Venta #' . (int) $fila['id'],
            'folio'    => (string) $fila['folio'],
            'fecha'    => (string) $fila['fecha'],
            'total'    => (float) ($fila['total'] ?? 0),
            'detalle'  => trim((string) ($fila['cliente'] ?? '')),
            'criterio' => $criterio,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function consultar(string $sql, string $tipos = '', mixed ...$valores): array
    {
        $consulta = self::$db->prepare($sql);
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Tasa de IVA redondeada a la única que usa el formulario: 0, 8 o 16. */
    private static function ivaTasa(float $subtotal, float $iva): float
    {
        if ($subtotal <= 0 || $iva <= 0) {
            return 0.0;
        }

        $tasa = round($iva / $subtotal * 100);
        if (abs($tasa - 16) <= 1) {
            return 16.0;
        }
        if (abs($tasa - 8) <= 1) {
            return 8.0;
        }

        return 0.0;
    }

    /** Fecha del documento en AAAA-MM-DD, o la de hoy si no se detectó. */
    private static function fecha(string $fecha): string
    {
        $fecha = trim($fecha);
        if (preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $fecha, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $fecha;
        }

        return date('Y-m-d');
    }

    private static function primerosConceptos(array $conceptos, int $maximo = 3): string
    {
        $textos = [];
        foreach (array_slice($conceptos, 0, $maximo) as $concepto) {
            $textos[] = (string) ($concepto['descripcion'] ?? '');
            // El nombre real del artículo está después de la clave del SAT: sin él,
            // una factura de gasolina diría sólo «Suprime (Ticket: 1759665)».
            if (!empty($concepto['nota'])) {
                $textos[] = (string) $concepto['nota'];
            }
        }

        return implode(' ', array_filter($textos));
    }

    /** @return array<int, array<string, mixed>> */
    private static function tabla(string $sql): array
    {
        $filas = [];
        $resultado = self::$db->query($sql);
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
    }

    /** Texto comparable: minúsculas, sin acentos, sin puntuación. */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        ]);
        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^a-z0-9]+/u', ' ', $texto)));

        // «60 x 60» y «60×60» son lo mismo que «60x60»: sin esto, las medidas nunca coinciden.
        return (string) preg_replace('/(\d) x (\d)/u', '$1x$2', $texto);
    }

    /** Similitud de 0 a 100 entre dos textos ya normalizados. */
    private static function similitud(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $porcentaje);

        // Un nombre que contiene al otro vale más que su similitud cruda
        // («pintura negra» está dentro de «pintura negra 1 galón»).
        $corta = min(mb_strlen($a), mb_strlen($b));
        $larga = max(mb_strlen($a), mb_strlen($b));
        if ($larga > 0 && (str_contains($a, $b) || str_contains($b, $a))) {
            $porcentaje = max($porcentaje, ($corta / $larga) * 100 + 20);
        }

        return min(99.0, round($porcentaje, 1));
    }

    /** Proporción de palabras del texto (mínimo 3 caracteres) que aparecen en el otro. */
    private static function coberturaTokens(string $a, string $b): float
    {
        $deA = array_filter(explode(' ', $a), static fn (string $p): bool => mb_strlen($p) >= 3);
        if (!$deA) {
            return 0.0;
        }

        $enB = ' ' . $b . ' ';
        $dentro = 0;
        foreach ($deA as $palabra) {
            if (str_contains($enB, ' ' . $palabra . ' ')) {
                $dentro++;
            }
        }

        // Una sola palabra compartida no basta («solera» aparece en muchos productos).
        if ($dentro < 2) {
            return 0.0;
        }

        return round($dentro / count($deA), 3);
    }

    /** Importe a número; 0 si el texto está vacío o no es un importe. */
    private static function numero(string $texto): float
    {
        $texto = trim(str_replace(["\xc2\xa0", '$'], '', $texto));
        $texto = (string) preg_replace('/[^0-9,.\-]/u', '', $texto);

        if ($texto === '' || !preg_match('/\d/', $texto)) {
            return 0.0;
        }
        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            $texto = str_replace(',', '', $texto);
        } elseif (str_contains($texto, ',') && preg_match('/,\d{1,2}$/', $texto)) {
            $texto = str_replace(',', '.', $texto);
        } else {
            $texto = str_replace(',', '', $texto);
        }

        return is_numeric($texto) ? (float) $texto : 0.0;
    }
}
