<?php

namespace App;

/**
 * ComprasIntel — «Compras Inteligentes»: leer el XML de una factura de compra
 * y ayudar a decidir QUÉ HACER con ella antes de escribir algo.
 *
 * Reglas de la fase:
 *   • Analizar solo LEE: nada se guarda en compras ni en contabilidad hasta que
 *     la persona decide (relacionar / compra nueva / revisar / cancelar).
 *   • Adjuntar una factura reutiliza CompraContable: la compra se envía a
 *     contabilidad UNA sola vez (uq_oc_gasto) y el XML se adjunta al gasto con
 *     las validaciones de siempre (UUID único, periodo abierto). Nunca se duplica
 *     el importe: si la factura ya está en esa misma compra se marca como ya
 *     relacionada y si está en OTRA operación se bloquea mostrándola.
 *   • Adjuntar NO toca recepciones ni inventario: eso solo pasa en el módulo de
 *     recepciones. Cargar una factura no mueve mercancía.
 *   • El proveedor se busca por RFC exacto y luego por nombre (mismo cotejo del
 *     motor CoincidenciasFactura). Si no existe, se PROPONE el alta y se crea
 *     únicamente cuando la persona confirma.
 *   • Toda acción queda en la bitácora (auditoría) y respeta los periodos
 *     cerrados (Gastos::crear y Archivos::subir lo verifican).
 */
class ComprasIntel
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Categorías de REGLAS_CATEGORIA que describen gastos operativos, no mercancía. */
    private const CATEGORIAS_GASTO = [
        'Combustible y transporte', 'Impuestos y derechos', 'Luz, agua y gas', 'Teléfono e internet',
        'Papelería y oficina', 'Mantenimiento', 'Seguridad y capacitación', 'Servicios', 'Honorarios',
        'Renta', 'Alimentos y despensa', 'Seguros', 'Donativos', 'Retiros del propietario',
    ];

    /** Categorías que sí son compra de material/mercancía. */
    private const CATEGORIAS_MATERIAL = ['Material y acero', 'Ferretería y materiales', 'Insumos de taller'];

    /** Tipos de comprobante que este flujo sabe relacionar con una compra. */
    private const TIPOS_ADMITIDOS = ['I', ''];

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

    /* --------------------------------------------------------------------
     * ANÁLISIS (solo lectura)
     * ------------------------------------------------------------------ */

    /**
     * Análisis completo de una lectura destino «compra»: datos del CFDI, proveedor,
     * clasificación, factura ya registrada y compras candidatas con su comparación.
     *
     * @return array<string, mixed>|null null si la lectura no existe o no es de compras
     */
    public static function analizar(int $lecturaId): ?array
    {
        self::$errores = [];
        $lectura = Lecturas::buscarID($lecturaId);
        if (!$lectura || (string) $lectura['destino'] !== 'compra') {
            return null;
        }

        $ruta = self::rutaLectura($lectura);
        $xml = is_file($ruta) ? (string) file_get_contents($ruta) : '';
        $cfdi = CfdiXml::leer($xml);
        $d = $cfdi['datos'];
        $avisos = $lectura['avisos_lista'];

        if (!$cfdi['valido']) {
            $avisos[] = $cfdi['error'] ?? 'No fue posible leer el XML de la factura.';
        }

        // Tipo de comprobante: este flujo solo relaciona FACTURAS de compra (tipo I).
        $tipo = strtoupper((string) ($d['tipo'] ?? ''));
        if ($tipo !== '' && !in_array($tipo, self::TIPOS_ADMITIDOS, true)) {
            $etiquetas = ['P' => 'un CFDI de PAGO (complemento)', 'E' => 'una nota de egreso', 'N' => 'una nota de crédito', 'T' => 'un ticket de pago'];
            $avisos[] = 'El comprobante es ' . ($etiquetas[$tipo] ?? 'de tipo «' . $tipo . '»')
                . ', no una factura de compra: este flujo no lo relaciona con órdenes.';
        }

        // Moneda: los importes del sistema se trabajan en pesos.
        if ($d['moneda'] !== '' && strtoupper($d['moneda']) !== 'MXN') {
            $avisos[] = 'La factura está en ' . strtoupper($d['moneda']) . ' y la plataforma trabaja en MXN: revisa los importes antes de relacionarla.';
        }

        // El receptor debe ser uno de nuestros negocios o contribuyentes.
        $receptor = strtoupper((string) $d['receptor_rfc']);
        if ($receptor !== '' && !self::esNuestroRfc($receptor)) {
            $avisos[] = 'El RFC receptor (' . $receptor . ') no corresponde a ninguno de tus negocios: confirma que la factura sea tuya.';
        }

        // Motor compartido de cotejo: proveedor (RFC/nombre), categoría, productos y duplicados.
        $coincidencias = CoincidenciasFactura::analizar([
            'fuente'    => (string) $lectura['fuente'],
            'campos'    => (array) ($lectura['leidos']['campos'] ?? []),
            'conceptos' => (array) ($lectura['leidos']['conceptos'] ?? []),
            'avisos'    => $avisos,
            'sha256'    => (string) $lectura['sha256'],
        ], 'gasto');

        // Búsqueda directa por RFC (el requisito): si el motor no lo emparejó por nombre, lo intentamos aquí.
        $proveedorFila = null;
        if (!empty($coincidencias['proveedor']['id'])) {
            $proveedorFila = Proveedores::buscarID((int) $coincidencias['proveedor']['id']);
        }
        if ($proveedorFila === null && $d['emisor_rfc'] !== '') {
            $proveedorFila = self::proveedorPorRfc(strtoupper($d['emisor_rfc']));
            if ($proveedorFila !== null) {
                $coincidencias['proveedor'] = [
                    'id' => (int) $proveedorFila->id, 'nombre' => $proveedorFila->nombre, 'criterio' => 'RFC ' . $d['emisor_rfc'],
                ];
            }
        }

        // ¿La factura ya está en alguna operación (UUID, mismo archivo u otro criterio)?
        $duplicado = self::operacionPorUuid($d['uuid'] ?? '');

        // Compras candidatas del proveedor, con la comparación de cada una contra el XML.
        $ordenes = [];
        if ($proveedorFila !== null) {
            $ordenes = self::ordenesCandidatas((int) $proveedorFila->id, $d);
        }

        return [
            'lectura'       => $lectura,
            'datos'         => $d,
            'estado'        => $cfdi['estado'],
            'valido'        => (bool) $cfdi['valido'],
            'coincidencias' => $coincidencias,
            'proveedor'     => $proveedorFila === null ? null : [
                'id'            => (int) $proveedorFila->id,
                'nombre'        => (string) $proveedorFila->nombre,
                'rfc'           => (string) $proveedorFila->rfc,
                'tipo'          => (string) $proveedorFila->tipo_proveedor,
                'activo'        => (int) $proveedorFila->activo === 1,
                'criterio'      => (string) ($coincidencias['proveedor']['criterio'] ?? ''),
                'ruta'          => '/admin/proveedores/detalle?id=' . (int) $proveedorFila->id,
            ],
            'clasificacion'  => self::clasificar($coincidencias, $proveedorFila, $d),
            'duplicado'      => $duplicado,
            'ordenes'        => $ordenes,
            'propuesta'      => self::propuestaProveedor($d, $coincidencias),
            'avisos'         => $avisos,
        ];
    }

    /**
     * Compras del proveedor (no canceladas) ordenadas por prioridad para relacionar:
     * primero las que esperan CFDI y están abiertas; después las demás.
     *
     * @return array<int, array<string, mixed>> cada orden con su 'comparacion' contra el XML
     */
    private static function ordenesSinComparar(int $proveedorId): array
    {
        $ordenes = OrdenesCompra::listar(['proveedor' => $proveedorId, 'activas' => true], 40, 0);

        $prioridad = static function (array $a, array $b): int {
            $cf = [(string) $a['estado_cfdi'] === 'pendiente_cfdi' ? 0 : 1, (string) $b['estado_cfdi'] === 'pendiente_cfdi' ? 0 : 1];
            if ($cf[0] !== $cf[1]) {
                return $cf[0] <=> $cf[1];
            }
            $orden = ['solicitada' => 0, 'parcial' => 1, 'sin_recepcion' => 2, 'completa' => 3, 'cerrada' => 4];
            $ea = $orden[(string) $a['estado']] ?? 9;
            $eb = $orden[(string) $b['estado']] ?? 9;
            if ($ea !== $eb) {
                return $ea <=> $eb;
            }

            return strcmp((string) $b['fecha_solicitud'], (string) $a['fecha_solicitud']);
        };
        usort($ordenes, $prioridad);

        return $ordenes;
    }

    /** Compras candidatas del proveedor con su comparación contra los conceptos del XML. */
    private static function ordenesCandidatas(int $proveedorId, array $datos): array
    {
        $lista = [];
        foreach (self::ordenesSinComparar($proveedorId) as $orden) {
            $orden['comparacion'] = self::comparar($orden, (array) ($datos['conceptos'] ?? []), $datos);
            $lista[] = $orden;
        }

        return $lista;
    }

    /**
     * Compara las partidas de una compra contra los conceptos del XML:
     * coincidencias (SKU o descripción parecida) y diferencias de cantidad, precio e importe.
     *
     * @param array<int, array<string, string>> $conceptos    conceptos del CfdiXml
     * @param array<string, mixed>              $datosFactura datos del CFDI (total, iva) para el comparativo
     * @return array{partidas: array<int, array<string, mixed>>, sin_partida: array<int, array<string, string>>,
     *               totales: array{compra: float, factura: float, diferencia: float, iva_compra: float,
     *                               iva_factura: float, dif_iva: float}, coincidencias: int, diferencias: int}
     */
    public static function comparar(array $orden, array $conceptos, array $datosFactura = []): array
    {
        $partidas = OrdenesCompra::partidas((int) $orden['id']);
        $usados = [];
        $filas = [];
        $coincidencias = 0;
        $diferencias = 0;

        foreach ($partidas as $p) {
            $nombre = trim((string) ($p['nombre_mostrado'] ?: $p['descripcion']));
            $skus = array_filter([trim((string) ($p['codigo'] ?? '')), trim((string) ($p['producto_sku'] ?? ''))]);
            $mejor = null;
            $mejorIndice = -1;
            $mejorPuntaje = 0.0;
            $criterio = 'sin coincidencia';

            foreach ($conceptos as $i => $c) {
                if (isset($usados[$i])) {
                    continue;
                }
                $skuConcepto = strtoupper(trim((string) ($c['sku'] ?? '')));
                if ($skuConcepto !== '' && in_array($skuConcepto, array_map('strtoupper', $skus), true)) {
                    $mejor = $c;
                    $mejorIndice = $i;
                    $mejorPuntaje = 100.0;
                    $criterio = 'SKU ' . $skuConcepto;
                    break;
                }
                $puntaje = CoincidenciasFactura::similitudTextos($nombre, (string) ($c['descripcion'] ?? ''));
                if ($puntaje >= CoincidenciasFactura::UMBRAL_SIMILITUD && $puntaje > $mejorPuntaje) {
                    $mejor = $c;
                    $mejorIndice = $i;
                    $mejorPuntaje = $puntaje;
                    $criterio = 'nombre parecido (' . number_format($puntaje, 0) . ' %)';
                }
            }

            $cantidadXml = $mejor !== null ? (float) $mejor['cantidad'] : null;
            $precioXml = $mejor !== null ? (float) $mejor['valor_unitario'] : null;
            $importeXml = $mejor !== null ? (float) $mejor['importe'] : null;

            $difCantidad = $cantidadXml === null ? null : round($cantidadXml - (float) $p['solicitada'], 3);
            $difPrecio = $precioXml === null ? null : Contabilidad::centavos($precioXml) - Contabilidad::centavos($p['costo_esperado']);
            $difImporte = $importeXml === null ? null : Contabilidad::centavos($importeXml) - Contabilidad::centavos($p['importe_esperado']);

            if ($mejor !== null) {
                $usados[$mejorIndice] = true;
                $coincidencias++;
                if (($difCantidad !== null && abs($difCantidad) > 0.0005) || ($difPrecio !== null && $difPrecio !== 0) || ($difImporte !== null && $difImporte !== 0)) {
                    $diferencias++;
                }
            }

            $filas[] = [
                'partida'       => $p,
                'concepto'      => $mejor,
                'coincidencia'  => $mejor !== null,
                'criterio'      => $criterio,
                'dif_cantidad'  => $difCantidad,
                'dif_precio'    => $difPrecio === null ? null : $difPrecio / 100,
                'dif_importe'   => $difImporte === null ? null : $difImporte / 100,
            ];
        }

        $sinPartida = [];
        foreach ($conceptos as $i => $c) {
            if (!isset($usados[$i])) {
                $sinPartida[] = $c;
            }
        }

        // Totales: compra (esperado + IVA) contra factura (total del CFDI).
        $compraTotal = Contabilidad::centavos($orden['total_esperado'] ?? 0) + Contabilidad::centavos($orden['iva'] ?? 0);
        $compraIva = Contabilidad::centavos($orden['iva'] ?? 0);
        $facturaTotal = Contabilidad::centavos($datosFactura['total'] ?? 0);
        $facturaIva = Contabilidad::centavos($datosFactura['iva'] ?? 0);

        return [
            'partidas'      => $filas,
            'sin_partida'   => $sinPartida,
            'totales'       => [
                'compra'      => $compraTotal / 100,
                'factura'     => $facturaTotal / 100,
                'diferencia'  => ($facturaTotal - $compraTotal) / 100,
                'iva_compra'  => $compraIva / 100,
                'iva_factura' => $facturaIva / 100,
                'dif_iva'     => ($facturaIva - $compraIva) / 100,
            ],
            'coincidencias' => $coincidencias,
            'diferencias'   => $diferencias,
        ];
    }

    /* --------------------------------------------------------------------
     * CLASIFICACIÓN
     * ------------------------------------------------------------------ */

    /**
     * ¿Es una compra de materiales/mercancía o un gasto operativo?
     *
     * Se apoya en las coincidencias reales (conceptos contra el catálogo), la
     * categoría deducida del nombre del emisor y el tipo de proveedor. Cuando no
     * hay señales suficientes se devuelve «indeterminado» con confianza baja:
     * es la señal para PREGUNTAR a la persona (nunca decidir en su lugar).
     *
     * @return array{clave: string, etiqueta: string, confianza: string, motivo: string, categoria: ?string}
     */
    private static function clasificar(array $coincidencias, ?Proveedores $proveedor, array $datos): array
    {
        $tipo = strtoupper((string) ($datos['tipo'] ?? ''));
        if ($tipo !== '' && !in_array($tipo, self::TIPOS_ADMITIDOS, true)) {
            return [
                'clave' => 'indeterminado', 'etiqueta' => 'No es una factura de compra', 'confianza' => 'baja',
                'motivo' => 'El comprobante no es de tipo «I» (factura): no se clasifica como compra.',
                'categoria' => null,
            ];
        }

        $categoria = $coincidencias['categoria']['nombre'] ?? null;
        $conCatalogo = count((array) ($coincidencias['lineas'] ?? []));
        $sinEquivalente = count((array) ($coincidencias['sin_equivalente'] ?? []));

        if ($conCatalogo > 0) {
            return [
                'clave' => 'material', 'etiqueta' => 'Posible compra de materiales o mercancía', 'confianza' => 'alta',
                'motivo' => $conCatalogo . ' de los conceptos coinciden con productos de tu catálogo.',
                'categoria' => $categoria,
            ];
        }

        if ($categoria !== null && in_array($categoria, self::CATEGORIAS_MATERIAL, true)) {
            return [
                'clave' => 'material', 'etiqueta' => 'Posible compra de materiales o mercancía', 'confianza' => 'media',
                'motivo' => 'Por el nombre del emisor y los conceptos parece «' . $categoria . '».',
                'categoria' => $categoria,
            ];
        }

        if ($categoria !== null && in_array($categoria, self::CATEGORIAS_GASTO, true)) {
            return [
                'clave' => 'gasto', 'etiqueta' => 'Parece un gasto operativo', 'confianza' => 'media',
                'motivo' => 'Por el nombre del emisor parece «' . $categoria . '»: si no es mercancía para vender, '
                    . 'regístralo como gasto desde Contabilidad y no como orden de compra.',
                'categoria' => $categoria,
            ];
        }

        if ($proveedor !== null && in_array((string) $proveedor->tipo_proveedor, ['Material', 'Producto'], true)) {
            return [
                'clave' => 'material', 'etiqueta' => 'Posible compra de materiales o mercancía', 'confianza' => 'media',
                'motivo' => 'El proveedor «' . $proveedor->nombre . '» está clasificado como ' . strtolower((string) $proveedor->tipo_proveedor) . '.',
                'categoria' => $categoria,
            ];
        }

        if (!$sinEquivalente && !($coincidencias['conceptos'] ?? [])) {
            return [
                'clave' => 'indeterminado', 'etiqueta' => 'No se pudo clasificar', 'confianza' => 'baja',
                'motivo' => 'El XML no trae conceptos legibles ni hay coincidencias: elige tú qué hacer con este documento.',
                'categoria' => $categoria,
            ];
        }

        return [
            'clave' => 'indeterminado', 'etiqueta' => 'Clasificación no segura', 'confianza' => 'baja',
            'motivo' => 'No hay señales suficientes para saber si es mercancía o gasto: revisa los conceptos y elige una opción.',
            'categoria' => $categoria,
        ];
    }

    /* --------------------------------------------------------------------
     * DUPLICADOS / REUTILIZACIÓN DE VÍNCULOS
     * ------------------------------------------------------------------ */

    /**
     * ¿El folio fiscal ya pertenece a alguna operación? Devuelve la operación
     * relacionada para que se MUESTRE en lugar de crear otra.
     *
     * @return array{tipo: string, gasto_id: ?int, orden: ?array, venta: ?bool, etiqueta: string, ruta: string}|null
     */
    public static function operacionPorUuid(string $uuid): ?array
    {
        $uuid = strtoupper(trim($uuid));
        if ($uuid === '') {
            return null;
        }

        $gasto = null;
        $q = self::$db->prepare("SELECT id, concepto FROM gastos WHERE (uuid_vigente = ? OR uuid_fiscal = ?) AND estado = 'vigente' LIMIT 1");
        $q->bind_param('ss', $uuid, $uuid);
        $q->execute();
        $gasto = $q->get_result()->fetch_assoc();
        $q->close();

        if ($gasto) {
            $gastoId = (int) $gasto['id'];
            $orden = null;
            $q = self::$db->prepare('SELECT id, folio FROM ordenes_compra WHERE gasto_id = ? LIMIT 1');
            $q->bind_param('i', $gastoId);
            $q->execute();
            $orden = $q->get_result()->fetch_assoc();
            $q->close();

            if ($orden) {
                return [
                    'tipo' => 'compra', 'gasto_id' => $gastoId,
                    'orden' => ['id' => (int) $orden['id'], 'folio' => (string) $orden['folio']],
                    'venta' => false,
                    'etiqueta' => 'Ya está en la compra ' . $orden['folio'],
                    'ruta' => '/admin/compras/detalle?id=' . (int) $orden['id'] . '#factura',
                ];
            }

            return [
                'tipo' => 'gasto', 'gasto_id' => $gastoId, 'orden' => null, 'venta' => false,
                'etiqueta' => 'Ya está en el gasto contable #' . $gastoId,
                'ruta' => '/admin/contabilidad/gastos/detalle?id=' . $gastoId,
            ];
        }

        // Mismo archivo o UUID adjunto a un documento (gasto o ingreso).
        $q = self::$db->prepare('SELECT entidad, entidad_id FROM documentos WHERE uuid_fiscal = ? AND activo = 1 LIMIT 1');
        $q->bind_param('s', $uuid);
        $q->execute();
        $doc = $q->get_result()->fetch_assoc();
        $q->close();
        if ($doc && (string) $doc['entidad'] === 'gasto') {
            return [
                'tipo' => 'gasto', 'gasto_id' => (int) $doc['entidad_id'], 'orden' => null, 'venta' => false,
                'etiqueta' => 'El XML está adjunto al gasto #' . (int) $doc['entidad_id'],
                'ruta' => '/admin/contabilidad/gastos/detalle?id=' . (int) $doc['entidad_id'],
            ];
        }
        if ($doc) {
            return [
                'tipo' => 'venta', 'gasto_id' => null, 'orden' => null, 'venta' => true,
                'etiqueta' => 'El XML está adjunto a un ingreso de venta',
                'ruta' => '/admin/contabilidad/ingresos/detalle?id=' . (int) $doc['entidad_id'],
            ];
        }

        $q = self::$db->prepare('SELECT id, folio FROM ingresos WHERE uuid_fiscal = ? LIMIT 1');
        $q->bind_param('s', $uuid);
        $q->execute();
        $ingreso = $q->get_result()->fetch_assoc();
        $q->close();
        if ($ingreso) {
            return [
                'tipo' => 'venta', 'gasto_id' => null, 'orden' => null, 'venta' => true,
                'etiqueta' => 'Ya está facturado en la venta ' . $ingreso['folio'],
                'ruta' => '/admin/contabilidad/ingresos/detalle?id=' . (int) $ingreso['id'],
            ];
        }

        return null;
    }

    /* --------------------------------------------------------------------
     * PROVEEDOR
     * ------------------------------------------------------------------ */

    /** Proveedor por RFC exacto (respetando mayúsculas); null si no existe. */
    private static function proveedorPorRfc(string $rfc): ?Proveedores
    {
        $consulta = self::$db->prepare('SELECT id FROM proveedores WHERE UPPER(TRIM(IFNULL(rfc, ""))) = ? LIMIT 1');
        $consulta->bind_param('s', $rfc);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? Proveedores::buscarID((int) $fila['id']) : null;
    }

    /**
     * Alta propuesta a partir del XML: nombre y RFC del emisor y un tipo razonable
     * según la clasificación. SOLO es una propuesta: nada se guarda aquí.
     *
     * @return array{nombre: string, rfc: string, tipo_proveedor: string, existe: bool}
     */
    private static function propuestaProveedor(array $datos, array $coincidencias): array
    {
        $clasificacion = self::clasificar($coincidencias, null, $datos);

        return [
            'nombre'        => trim((string) ($datos['emisor_nombre'] ?? '')),
            'rfc'           => strtoupper(trim((string) ($datos['emisor_rfc'] ?? ''))),
            'tipo_proveedor' => $clasificacion['clave'] === 'gasto'
                ? (in_array($clasificacion['categoria'] ?? '', ['Combustible y transporte'], true) ? 'Servicio' : 'Servicio')
                : 'Material',
            'existe'        => false,
        ];
    }

    /**
     * Registra el proveedor PROPUESTO por la lectura (confirmación explícita de la persona).
     * Si ya existe uno con el mismo RFC, devuelve ese en lugar de duplicarlo.
     *
     * @param array{nombre?: string, rfc?: string, tipo_proveedor?: string} $datos
     * @return int|null id del proveedor (creado o existente); null con errores en getErrores()
     */
    public static function registrarProveedor(int $lecturaId, array $datos, int $usuarioId): ?int
    {
        self::$errores = [];
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $rfc = strtoupper(trim((string) ($datos['rfc'] ?? '')));
        $tipo = (string) ($datos['tipo_proveedor'] ?? 'Material');

        if ($nombre === '') {
            self::$errores[] = 'Escribe el nombre del proveedor.';
            return null;
        }
        if (!in_array($tipo, Proveedores::TIPOS, true)) {
            $tipo = 'Material';
        }

        if ($rfc !== '') {
            $existente = self::proveedorPorRfc($rfc);
            if ($existente !== null) {
                Auditoria::registrar('proveedor', (int) $existente->id, 'alta_reutilizada', $usuarioId,
                    'Compras Inteligentes: ya existía un proveedor con el RFC ' . $rfc, ['lectura_id' => $lecturaId]);

                return (int) $existente->id;
            }
        }

        $proveedor = new Proveedores([
            'nombre' => $nombre, 'rfc' => $rfc, 'tipo_proveedor' => $tipo, 'activo' => 1,
        ]);
        if ($proveedor->validar()) {
            self::$errores = Proveedores::getErrores() ?: ['Los datos del proveedor no son válidos.'];
            return null;
        }
        if (!$proveedor->crear()) {
            self::$errores = Proveedores::getErrores() ?: ['No fue posible guardar el proveedor.'];
            return null;
        }

        Auditoria::registrar('proveedor', (int) $proveedor->id, 'crear', $usuarioId,
            'Alta desde Compras Inteligentes', ['lectura_id' => $lecturaId, 'rfc' => $rfc, 'origen' => 'XML']);

        return (int) $proveedor->id;
    }

    /* --------------------------------------------------------------------
     * RELACIONAR (la única acción que escribe en compras/contabilidad)
     * ------------------------------------------------------------------ */

    /**
     * Adjunta el XML de la lectura a la compra elegida: la envía a contabilidad una
     * sola vez, marca «Con CFDI» y deja la lectura confirmada contra la orden.
     *
     * Reglas verificadas antes de escribir:
     *   1. la lectura está pendiente y es de compras;
     *   2. la compra existe y no está cancelada;
     *   3. si el XML trae RFC emisor, debe coincidir con el proveedor de la compra;
     *   4. si el UUID ya existe: se reutiliza el vínculo si es de ESTA compra, o se
     *      bloquea mostrando la operación a la que pertenece (nunca se duplica);
     *   5. los periodos cerrados los rechazan Gastos::crear / Archivos::subir.
     */
    public static function relacionar(int $lecturaId, int $ordenId, int $usuarioId): bool
    {
        self::$errores = [];

        $lectura = Lecturas::buscarID($lecturaId);
        if (!$lectura || (string) $lectura['destino'] !== 'compra') {
            self::$errores[] = 'La lectura ya no existe.';
            return false;
        }
        if ((string) $lectura['estado'] !== 'pendiente') {
            self::$errores[] = 'Esta factura ya fue procesada (estado «' . $lectura['estado'] . '»): no se vuelve a relacionar.';
            return false;
        }

        $orden = OrdenesCompra::buscarID($ordenId);
        if (!$orden || (string) $orden['estado'] === 'cancelada') {
            self::$errores[] = 'La compra elegida ya no existe o está cancelada.';
            return false;
        }

        $ruta = self::rutaLectura($lectura);
        $cfdi = CfdiXml::leer(is_file($ruta) ? (string) file_get_contents($ruta) : '');
        $datos = $cfdi['datos'];
        $uuid = strtoupper(trim((string) ($datos['uuid'] ?? '')));
        $tipo = strtoupper((string) ($datos['tipo'] ?? ''));

        if ($cfdi['valido'] && $tipo !== '' && !in_array($tipo, self::TIPOS_ADMITIDOS, true)) {
            self::$errores[] = 'El documento no es una factura de compra (tipo «' . $tipo . '»): no se relaciona con la orden.';
            return false;
        }

        // El emisor debe ser el proveedor de la compra (si el XML lo trae).
        $rfcXml = strtoupper((string) ($datos['emisor_rfc'] ?? ''));
        $rfcOrden = strtoupper(trim((string) ($orden['proveedor_rfc'] ?? '')));
        if ($rfcXml !== '' && $rfcOrden !== '' && $rfcXml !== $rfcOrden) {
            self::$errores[] = 'La factura es del proveedor con RFC ' . $rfcXml . ', pero la compra ' . $orden['folio']
                . ' es de «' . $orden['proveedor_nombre'] . '» (' . $rfcOrden . '). Elige la compra correcta.';
            return false;
        }

        // Reutilizar o bloquear según a quién pertenece ya el folio fiscal.
        if ($uuid !== '') {
            $operacion = self::operacionPorUuid($uuid);
            $gastoActual = CompraContable::gastoDe($ordenId);

            if ($operacion !== null) {
                $esEstaCompra = $operacion['tipo'] === 'compra' && !empty($operacion['orden']['id'])
                    && (int) $operacion['orden']['id'] === $ordenId;

                if (!$esEstaCompra) {
                    self::$errores[] = 'Ese folio fiscal ya está registrado (' . $operacion['etiqueta']
                        . '), así que no se vuelve a guardar el importe. Abre la operación relacionada.';
                    return false;
                }
                // Ya estaba en ESTA compra: solo se confirma la lectura (idempotente).
                self::marcarConfirmada($lectura, $orden, $uuid, $usuarioId, true);

                return true;
            }

            // La compra ya tiene OTRO CFDI vigente: no se encadena un segundo folio fiscal.
            if ($gastoActual && (string) $gastoActual['estado'] === 'vigente'
                && trim((string) ($gastoActual['uuid_fiscal'] ?? '')) !== ''
                && strtoupper((string) $gastoActual['uuid_fiscal']) !== $uuid) {
                self::$errores[] = 'La compra ' . $orden['folio'] . ' ya tiene el folio fiscal '
                    . strtoupper((string) $gastoActual['uuid_fiscal']) . ': no se adjunta un segundo CFDI. '
                    . 'Si es otro documento, retíralo antes desde el gasto.';
                return false;
            }
        }

        // Adjuntar: envía la compra a contabilidad (una sola vez) y sube el XML al gasto.
        if (!is_file($ruta)) {
            self::$errores[] = 'El archivo original de la factura ya no está disponible en el almacén.';
            return false;
        }
        $archivo = [
            'name' => (string) $lectura['nombre_original'], 'tmp_name' => $ruta,
            'size' => (int) filesize($ruta), 'error' => UPLOAD_ERR_OK,
        ];
        if (!CompraContable::adjuntarComprobante($ordenId, $archivo, 'xml', $usuarioId, false)) {
            self::$errores = CompraContable::getErrores() ?: ['No fue posible adjuntar la factura a la compra.'];
            return false;
        }

        self::marcarConfirmada($lectura, $orden, $uuid, $usuarioId, false);
        Auditoria::registrar('orden_compra', $ordenId, 'factura_inteligente', $usuarioId,
            'Factura relacionada desde Compras Inteligentes',
            ['lectura_id' => $lecturaId, 'uuid' => $uuid, 'archivo' => $lectura['nombre_original']]);

        return true;
    }

    /** Deja la lectura confirmada contra la orden (qué factura, a qué compra y quién). */
    private static function marcarConfirmada(array $lectura, array $orden, string $uuid, int $usuarioId, bool $yaRelacionada): void
    {
        $datos = json_encode([
            'accion' => $yaRelacionada ? 'reutilizada' : 'relacionada',
            'orden' => (string) $orden['folio'], 'uuid' => $uuid,
        ], JSON_UNESCAPED_UNICODE);
        $ordenId = (int) $orden['id'];
        $lecturaId = (int) $lectura['id'];
        $consulta = self::$db->prepare(
            "UPDATE lecturas_documentales SET estado = 'confirmada', entidad = 'orden_compra', entidad_id = ?,
                    datos_confirmados = ?, confirmado_por = ?, confirmado_en = NOW()
              WHERE id = ? AND estado = 'pendiente'"
        );
        $consulta->bind_param('issi', $ordenId, $datos, $usuarioId, $lecturaId);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('lectura', (int) $lectura['id'], 'confirmar', $usuarioId,
            'Compras Inteligentes: factura ' . ($yaRelacionada ? 'ya ' : '') . 'relacionada con la compra ' . $orden['folio'],
            ['uuid' => $uuid]);
    }

    /**
     * Cierra una lectura de «compra» que resolvió en OTRA parte (p. ej. la persona
     * decidió que era un gasto y lo guardó en contabilidad): deja de estar pendiente
     * de decisión en Compras Inteligentes y queda trazada contra su registro.
     *
     * @param 'gasto'|'venta'|'orden_compra' $entidad
     */
    public static function cerrarLectura(array $lectura, string $entidad, int $entidadId, int $usuarioId): bool
    {
        if (!in_array($entidad, ['gasto', 'venta', 'orden_compra'], true) || $entidadId <= 0) {
            return false;
        }
        $lecturaId = (int) $lectura['id'];
        $consulta = self::$db->prepare(
            "UPDATE lecturas_documentales SET estado = 'confirmada', entidad = ?, entidad_id = ?,
                    confirmado_por = ?, confirmado_en = NOW()
              WHERE id = ? AND destino = 'compra' AND estado = 'pendiente'"
        );
        $consulta->bind_param('siii', $entidad, $entidadId, $usuarioId, $lecturaId);
        $consulta->execute();
        $cambio = $consulta->affected_rows > 0;
        $consulta->close();

        if ($cambio) {
            Auditoria::registrar('lectura', $lecturaId, 'confirmar', $usuarioId,
                'Compras Inteligentes: la factura se resolvió como ' . $entidad . ' #' . $entidadId,
                ['destino_original' => 'compra']);
        }

        return $cambio;
    }

    /* --------------------------------------------------------------------
     * COMPRA NUEVA (precarga del formulario)
     * ------------------------------------------------------------------ */

    /**
     * Precarga para /admin/compras/crear a partir de la lectura: encabezado con
     * los datos del CFDI y una línea por concepto. Solo propone; quien guarda.
     *
     * @return array{orden: array<string, mixed>, lineas: array<int, array<string, mixed>>}|null
     */
    public static function propuestaOrden(int $lecturaId): ?array
    {
        $lectura = Lecturas::buscarID($lecturaId);
        if (!$lectura || (string) $lectura['destino'] !== 'compra') {
            return null;
        }
        $cfdi = CfdiXml::leer(is_file(self::rutaLectura($lectura)) ? (string) file_get_contents(self::rutaLectura($lectura)) : '');
        $d = $cfdi['datos'];

        $fecha = substr((string) $d['fecha'], 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = date('Y-m-d');
        }

        $proveedor = self::proveedorPorRfc(strtoupper((string) $d['emisor_rfc']));
        $subtotal = (float) ($d['subtotal'] ?: 0);
        $iva = (float) ($d['iva'] ?: 0);
        $conIva = $iva > 0;
        $tasa = $conIva && $subtotal > 0 ? round($iva / $subtotal * 100, 2) : null;

        $lineas = [];
        foreach ((array) ($d['conceptos'] ?? []) as $c) {
            $descripcion = trim((string) ($c['descripcion'] ?? ''));
            $cantidad = trim((string) ($c['cantidad'] ?? '')) ?: '1';
            $costo = trim((string) ($c['valor_unitario'] ?? '')) ?: '0';
            if ($descripcion === '' || (float) $cantidad <= 0) {
                continue;
            }
            $lineas[] = [
                'producto_id' => 0, 'proveedor_producto_id' => 0, 'material_id' => 0, 'material_nuevo' => '',
                'cantidad' => $cantidad, 'costo' => $costo, 'descripcion' => $descripcion, 'unidad' => '',
                'controla' => 0, 'aplica_iva' => $conIva ? 1 : 0,
            ];
        }
        if (!$lineas) {
            return null;
        }

        return [
            'orden' => [
                'tipo'            => 'insumo',
                'proveedor_id'    => $proveedor !== null ? (int) $proveedor->id : 0,
                'fecha_solicitud' => $fecha,
                'folio'           => '',
                'referencia'      => trim((string) ($d['folio_comercial'] ?? '')),
                'categoria_id'    => 0,
                'empresa_id'      => 0,
                'estado_cfdi'     => 'pendiente_cfdi',
                'iva'             => $conIva ? number_format($iva, 2, '.', '') : '',
                'iva_tasa'        => $tasa !== null ? (string) $tasa : '',
                'observaciones'   => 'Alta desde Compras Inteligentes'
                    . ($d['uuid'] !== '' ? ' · UUID ' . $d['uuid'] : ''),
            ],
            'lineas' => $lineas,
        ];
    }

    /** Ruta absoluta del original guardado de la lectura. */
    private static function rutaLectura(array $lectura): string
    {
        return Archivos::base() . '/' . (string) $lectura['archivo'];
    }

    /** ¿El RFC pertenece a un contribuyente o a alguna empresa del sistema? */
    private static function esNuestroRfc(string $rfc): bool
    {
        $consulta = self::$db->prepare(
            'SELECT 1 FROM contribuyentes WHERE UPPER(TRIM(IFNULL(rfc, ""))) = ? LIMIT 1'
        );
        $consulta->bind_param('s', $rfc);
        $consulta->execute();
        $encontrado = $consulta->get_result()->num_rows > 0;
        $consulta->close();
        if ($encontrado) {
            return true;
        }

        $consulta = self::$db->prepare('SELECT 1 FROM empresas WHERE UPPER(TRIM(IFNULL(rfc, ""))) = ? LIMIT 1');
        $consulta->bind_param('s', $rfc);
        $consulta->execute();
        $encontrado = $consulta->get_result()->num_rows > 0;
        $consulta->close();

        return $encontrado;
    }
}
