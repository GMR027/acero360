<?php

namespace App;

/**
 * ComplementosVenta — complementos de pago del lado de CLIENTES (FASE 6): los
 * XML (CFDI tipo «P») que respaldan los cobros de ventas ya facturadas.
 *
 * Qué es cada cosa (requisito 1), y que siguen separados (requisito 2):
 *   venta      → el hecho comercial (ventas) y su ingreso contable 1:1
 *   CFDI       → el comprobante emitido (ingresos.uuid_fiscal o el XML adjunto)
 *   cobro      → cada dinero recibido (cobros: fecha, importe, método, referencia)
 *   complemento→ el XML de pago que declara esos pagos ante el SAT
 *   Aquí NO se toca `ingresos.estado_facturacion`: sólo se leen cobros/facturas
 *   y se RELACIONA el complemento con lo que ya existe.
 *
 * Reglas:
 *   · Nunca se crea una venta ni un ingreso: sólo se relacionan los existentes
 *     (requisito 7). El cobro opcional se crea con Contabilidad::registrarCobro,
 *     que respeta saldo, periodo cerrado y auditoría (requisito 11).
 *   · Un cobro ya registrado NUNCA se duplica (requisito 8): si el complemento
 *     coincide con un cobro vigente se VINCULA; si ya hay cobros que no cuadran,
 *     no se crea nada: queda la discrepancia (requisito 9).
 *   · Discrepancias de importe y de saldo se calculan y se muestran ANTES de
 *     confirmar.
 *   · Lado correcto: el EMISOR soy yo (yo recibo el dinero y emito el
 *     complemento). Si el receptor soy yo, el complemento es de un pago a
 *     proveedor y se registra en Compras (requisito 1).
 *   · Emitir complementos propios exige PAC; como no hay, sólo se registran los
 *     emitidos fuera (requisito 12, ver puedeEmitir()).
 */
class ComplementosVenta
{
    /** Destino de la lectura documental de este lado (migración 036). */
    public const DESTINO = 'complemento_venta';

    /** Estados de cada factura relacionada del complemento. */
    public const ESTADOS = [
        'vinculado'    => ['etiqueta' => 'Vinculado',              'clase' => 'badge--aprobado'],
        'sin_pago'     => ['etiqueta' => 'Cobro sin registrar',    'clase' => 'badge--pendiente'],
        'discrepancia' => ['etiqueta' => 'Discrepancia',           'clase' => 'badge--error'],
        'sin_factura'  => ['etiqueta' => 'Sin factura localizada', 'clase' => 'badge--neutro'],
        'pendiente'    => ['etiqueta' => 'Sin vincular',           'clase' => 'badge--neutro'],
    ];

    /** Forma de pago del complemento (SAT) → método interno de cobro. */
    private const FORMA_A_COBRO = [
        '01' => 'Efectivo', '02' => 'Cheque', '03' => 'Transferencia',
        '04' => 'Tarjeta', '05' => 'Tarjeta', '06' => 'Tarjeta', '08' => 'Tarjeta',
        '28' => 'Tarjeta', '29' => 'Tarjeta',
        '09' => 'Otro', '12' => 'Otro', '99' => 'Otro',
    ];

    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

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

    /**
     * ¿Se pueden EMITIR complementos desde el sistema? Eso exige un PAC (sellado
     * y timbrado), que no está integrado: hoy sólo se registran y se relacionan
     * los complementos emitidos fuera (contador o portal del PAC).
     * Es la misma sonda que usa la validación de ventas.
     */
    public static function puedeEmitir(): bool
    {
        return VentasFiscal::pacDisponible() !== null;
    }

    /* ---------------------------------------------------------------- *
     * ANÁLISIS (sólo lectura) — requisitos 1, 5, 6 y 9
     * ---------------------------------------------------------------- */

    /**
     * Revisión previa de un complemento de pago de cliente. No escribe nada.
     *
     * @return array<string, mixed>|null null si la lectura no es de este lado
     */
    public static function analizar(int $lecturaId): ?array
    {
        self::$errores = [];
        $lectura = Lecturas::buscarID($lecturaId);
        if (!$lectura) {
            return null;
        }

        $ruta = Archivos::base() . '/' . (string) $lectura['archivo'];
        $xml = is_file($ruta) ? (string) file_get_contents($ruta) : '';
        $cfdi = CfdiXml::leer($xml);
        // Mismo criterio que en Compras: vale el destino propio o un CFDI tipo «P»
        // que llegó por la carga por lotes.
        if ((string) $lectura['destino'] !== self::DESTINO && (string) ($cfdi['datos']['tipo'] ?? '') !== 'P') {
            return null;
        }
        $datos = $cfdi['datos'];
        $complemento = CfdiXml::complementoPago($xml);
        $avisos = $lectura['avisos_lista'];

        if (!$cfdi['valido']) {
            $avisos[] = $cfdi['error'] ?? 'No fue posible leer el XML del complemento.';
        }
        if ($complemento === null) {
            $avisos[] = 'El archivo no trae un complemento de pagos (nodo Pagos con DoctoRelacionado): '
                . 'si es una factura emitida, usa «Leer factura (XML, PDF o foto)» de la venta.';
        }

        // --- De qué lado es (requisito 1): emisor = yo → cobro a cliente.
        $emisorPropio = Contabilidad::esRfcPropio((string) ($datos['emisor_rfc'] ?? ''));
        $receptorPropio = Contabilidad::esRfcPropio((string) ($datos['receptor_rfc'] ?? ''));
        $lado = $emisorPropio ? 'cliente' : ($receptorPropio ? 'proveedor' : 'ajeno');
        if ($lado === 'proveedor') {
            $avisos[] = 'Este complemento es de un PAGO A PROVEEDOR (el receptor eres tú): '
                . 'se registra en Compras → Complementos de pago, no aquí.';
        } elseif ($lado === 'ajeno') {
            $avisos[] = 'Ni el emisor ni el receptor coincide con este negocio: '
                . 'el complemento no parece pertenecer a estas empresas.';
        }

        // UUID ya cargado (por este lado o por el otro): nunca se duplica.
        $duplicado = ComplementosPago::buscarPorUuid((string) ($datos['uuid'] ?? ''));

        $doctos = [];
        if ($complemento !== null) {
            foreach ($complemento['doctos'] as $i => $docto) {
                $doctos[] = self::resolverDocto($i, $docto, $complemento);
            }
        }

        return [
            'lectura'     => $lectura,
            'datos'       => $datos,
            'estado'      => $cfdi['estado'],
            'complemento' => $complemento,
            'duplicado'   => $duplicado,
            'lado'        => $lado,
            'lado_ok'     => $lado === 'cliente',
            'doctos'      => $doctos,
            'avisos'      => $avisos,
            'fecha_pago'  => $complemento['fecha_pago'] ?? substr((string) ($datos['fecha'] ?? ''), 0, 10),
            'periodo_cerrado' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $complemento['fecha_pago'] ?? '') === 1
                && Contabilidad::periodoCerrado(Contabilidad::contribuyentePrincipal(), $complemento['fecha_pago']),
            'puede_emitir' => self::puedeEmitir(),
        ];
    }

    /**
     * Resuelve un DoctoRelacionado: localiza la factura de venta por su UUID,
     * trae su ingreso con los cobros reales y compara importes y saldos.
     *
     * @return array<string, mixed>
     */
    private static function resolverDocto(int $indice, array $docto, array $complemento): array
    {
        $importePagado = Contabilidad::centavos($docto['importe_pagado'] ?? 0);
        $fechaComplemento = (string) ($complemento['fecha_pago'] ?? '');

        $fila = [
            'indice'          => $indice,
            'uuid'            => strtoupper((string) ($docto['uuid'] ?? '')),
            'serie'           => (string) ($docto['serie'] ?? ''),
            'folio'           => (string) ($docto['folio'] ?? ''),
            'num_parcialidad' => (string) ($docto['num_parcialidad'] ?? ''),
            'saldo_anterior'  => (float) ($docto['saldo_anterior'] ?? 0),
            'importe_pagado'  => $importePagado / 100,
            'moneda'          => (string) ($docto['moneda'] ?? 'MXN'),
            'datos'           => $docto,
            'ingreso'         => null,
            'venta'           => null,
            'cobros'          => [],
            'coincidencia'    => null,
            'diferencia'      => 0.0,
            'diferencia_saldo'=> 0.0,
            'saldo_ok'        => true,
            'estado'          => 'sin_factura',
            'nota'            => '',
            'puede_crear'     => false,
        ];

        if ($fila['uuid'] === '') {
            $fila['nota'] = 'El documento relacionado no trae UUID: no se puede localizar la factura.';
            return $fila;
        }

        /* --- 5. Localizar la factura original (venta) por el UUID del CFDI. */
        $ingresoId = self::ingresoPorUuid($fila['uuid']);
        if ($ingresoId === null) {
            $fila['nota'] = 'No se encontró una factura de venta con ese UUID en el sistema.';
            return $fila;
        }

        $ingreso = Contabilidad::buscarIngreso($ingresoId);
        if (!$ingreso) {
            $fila['nota'] = 'El ingreso relacionado con la factura ya no existe.';
            return $fila;
        }

        $venta = null;
        if (!empty($ingreso['venta_id'])) {
            $q = self::$db->prepare('SELECT folio, fecha, total, estado FROM ventas WHERE id = ? LIMIT 1');
            $ventaId = (int) $ingreso['venta_id'];
            $q->bind_param('i', $ventaId);
            $q->execute();
            $venta = $q->get_result()->fetch_assoc();
            $q->close();
        }

        $fila['ingreso'] = [
            'id'                 => (int) $ingreso['id'],
            'folio'              => (string) $ingreso['folio'],
            'venta_id'           => (int) ($ingreso['venta_id'] ?? 0),
            'cliente'            => trim((string) ($ingreso['cliente_nombre'] ?? '')) ?: (string) ($ingreso['cliente_empresa'] ?? ''),
            'total'              => (float) $ingreso['total'],
            'cobrado'            => (float) $ingreso['cobrado'],
            'saldo'              => (float) $ingreso['saldo'],
            'estado'             => (string) $ingreso['estado'],
            'estado_facturacion' => (string) $ingreso['estado_facturacion'],   // SEPARADO del cobro
            'estado_cobro'       => (string) $ingreso['estado_cobro'],
            'uuid_fiscal'        => (string) ($ingreso['uuid_fiscal'] ?? ''),
        ];
        $fila['venta'] = $venta ? [
            'folio'  => (string) $venta['folio'],
            'fecha'  => (string) $venta['fecha'],
            'total'  => (float) $venta['total'],
            'estado' => (string) $venta['estado'],
        ] : null;

        /* --- 3 y 4: los cobros reales, uno por uno, con su fecha e importe. */
        $cobros = Contabilidad::cobrosDeIngreso($ingresoId);
        $fila['cobros'] = array_map(static fn (array $c): array => [
            'id'                => (int) $c['id'],
            'fecha'             => (string) $c['fecha'],
            'importe'           => (float) $c['importe'],
            'metodo'            => (string) ($c['metodo'] ?? ''),
            'referencia'        => (string) ($c['referencia'] ?? ''),
            'estado'            => (string) $c['estado'],
            'complemento_uuid'  => (string) ($c['complemento_uuid'] ?? ''),
        ], $cobros);

        if ($ingreso['estado'] !== 'vigente') {
            $fila['estado'] = 'discrepancia';
            $fila['nota'] = 'El ingreso de esa factura está cancelado: no se le registran ni vinculan cobros.';
            return $fila;
        }

        $vigentes = array_values(array_filter($fila['cobros'], static fn (array $c): bool => $c['estado'] === 'vigente'));

        /* --- 8. Coincidencia EXACTA con un cobro vigente → se vincula (nunca se duplica). */
        foreach ($vigentes as $cobro) {
            if (Contabilidad::centavos($cobro['importe']) === $importePagado) {
                if ($fila['coincidencia'] === null || $cobro['fecha'] === $fechaComplemento) {
                    $fila['coincidencia'] = $cobro;
                }
                if ($cobro['fecha'] === $fechaComplemento) {
                    break;
                }
            }
        }

        /* --- 9. Saldos declarados vs la venta (comparación previa a confirmar):
         *     · el saldo anterior no puede exceder el total de la factura ni quedar
         *       negativo al descontar lo que dice haber pagado;
         *     · si ese pago todavía NO está en nuestros cobros, el saldo anterior
         *       debe coincidir con el que tenemos hoy antes de registrarlo. */
        $totalCents = Contabilidad::centavos($ingreso['total']);
        $cobradoCents = Contabilidad::centavos($ingreso['cobrado']);
        $declaradoAntes = Contabilidad::centavos($docto['saldo_anterior'] ?? 0);
        $notaSaldo = '';
        if ($declaradoAntes > 0) {
            $problema = '';
            if ($declaradoAntes > $totalCents + 1) {
                $problema = 'El saldo anterior declarado (' . number_format($declaradoAntes / 100, 2)
                    . ') excede el total de la venta (' . number_format($totalCents / 100, 2) . ')';
                $fila['diferencia_saldo'] = ($declaradoAntes - $totalCents) / 100;
            } elseif ($declaradoAntes - $importePagado < -1) {
                $problema = 'Declara pagado más (' . number_format($importePagado / 100, 2)
                    . ') de lo que había antes (' . number_format($declaradoAntes / 100, 2) . ')';
                $fila['diferencia_saldo'] = ($importePagado - $declaradoAntes) / 100;
            } elseif ($fila['coincidencia'] === null) {
                $esperado = $totalCents - $cobradoCents;
                $diferenciaSaldo = $declaradoAntes - $esperado;
                if (abs($diferenciaSaldo) > 1) {
                    $fila['diferencia_saldo'] = $diferenciaSaldo / 100;
                    $problema = 'El saldo anterior del complemento (' . number_format($declaradoAntes / 100, 2)
                        . ') no coincide con el saldo de la venta antes de registrarlo (' . number_format($esperado / 100, 2) . ')';
                }
            }
            if ($problema !== '') {
                $fila['saldo_ok'] = false;
                $notaSaldo = ' ⚠ ' . $problema . '.';
            }
        }

        if ($fila['coincidencia'] !== null) {
            $fila['estado'] = 'vinculado';
            $fila['nota'] = 'Coincide con el cobro del ' . date('d/m/Y', strtotime($fila['coincidencia']['fecha']))
                . ' por ' . number_format($fila['coincidencia']['importe'], 2)
                . ($fila['coincidencia']['complemento_uuid'] !== '' ? ' (ya vinculado a otro complemento)' : '') . '.' . $notaSaldo;
            return $fila;
        }

        /* --- Sin cobros: el complemento declara dinero que aún no está registrado. */
        if (!$vigentes) {
            if ($importePagado > Contabilidad::centavos($ingreso['saldo'])) {
                $fila['estado'] = 'discrepancia';
                $fila['diferencia'] = ($importePagado - Contabilidad::centavos($ingreso['saldo'])) / 100;
                $fila['nota'] = 'El complemento declara ' . number_format($importePagado / 100, 2)
                    . ' pero el saldo de la venta es sólo ' . number_format((float) $ingreso['saldo'], 2) . '. No se puede cobrar de más.'
                    . $notaSaldo;
                return $fila;
            }
            $fila['estado'] = 'sin_pago';
            $fila['diferencia'] = $importePagado / 100;
            $fila['puede_crear'] = true;
            $fila['nota'] = 'Este complemento declara un pago que todavía no está registrado como cobro (se crea sólo si lo pides).'
                . $notaSaldo;
            return $fila;
        }

        /* --- Hay cobros que no cuadran: discrepancia, y NUNCA se crea otro. */
        $masCercano = null;
        $mejor = PHP_INT_MAX;
        foreach ($vigentes as $cobro) {
            $distancia = abs(Contabilidad::centavos($cobro['importe']) - $importePagado);
            if ($distancia < $mejor) {
                $mejor = $distancia;
                $masCercano = $cobro;
            }
        }
        $fila['estado'] = 'discrepancia';
        $fila['diferencia'] = ($importePagado - Contabilidad::centavos($masCercano['importe'])) / 100;
        $fila['nota'] = 'El complemento dice ' . number_format($importePagado / 100, 2)
            . ' y el cobro más cercano es de ' . number_format($masCercano['importe'], 2)
            . ' (diferencia ' . number_format($fila['diferencia'], 2) . '): revisa los cobros antes de confirmar.'
            . $notaSaldo;

        return $fila;
    }

    /** Ingreso cuya factura (CFDI) tiene ese UUID: por `ingresos.uuid_fiscal` o por el XML adjunto. */
    private static function ingresoPorUuid(string $uuid): ?int
    {
        $uuid = strtoupper($uuid);
        $consulta = self::$db->prepare('SELECT id FROM ingresos WHERE uuid_fiscal = ? ORDER BY id LIMIT 1');
        $consulta->bind_param('s', $uuid);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();
        if ($fila) {
            return (int) $fila['id'];
        }

        $consulta = self::$db->prepare(
            "SELECT entidad_id FROM documentos
              WHERE entidad = 'ingreso' AND uuid_fiscal = ? AND activo = 1
                AND estado_validacion = 'cfdi_con_timbre' ORDER BY id DESC LIMIT 1"
        );
        $consulta->bind_param('s', $uuid);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? (int) $fila['entidad_id'] : null;
    }

    /* ---------------------------------------------------------------- *
     * ESCRITURA — requisitos 7, 8, 10, 11 y 12
     * ---------------------------------------------------------------- */

    /**
     * Registra el complemento y vincula cada factura relacionada con sus cobros.
     * Nunca crea una venta; nunca duplica un cobro registrado.
     *
     * @param array<string, mixed> $p POST: doctos[indice][vincular], doctos[indice][crear_cobro]
     */
    public static function registrar(int $lecturaId, array $p, int $usuarioId): bool
    {
        self::$errores = [];
        $analisis = self::analizar($lecturaId);
        if ($analisis === null) {
            self::$errores[] = 'La lectura ya no existe.';
            return false;
        }
        if ((string) $analisis['lectura']['estado'] !== 'pendiente') {
            self::$errores[] = 'Este complemento ya fue procesado (estado «' . $analisis['lectura']['estado'] . '»).';
            return false;
        }
        if ($analisis['complemento'] === null) {
            self::$errores[] = 'El archivo no trae complemento de pagos: no hay nada que registrar.';
            return false;
        }
        if ($analisis['duplicado'] !== null) {
            self::$errores[] = 'Ese complemento ya está registrado (UUID ' . $analisis['duplicado']['uuid'] . '): no se carga dos veces.';
            return false;
        }
        if (!$analisis['lado_ok']) {
            self::$errores[] = $analisis['lado'] === 'proveedor'
                ? 'Ese complemento es de un pago a proveedor: regístralo en Compras → Complementos de pago.'
                : 'Ese complemento no corresponde a este negocio.';
            return false;
        }
        if ($analisis['periodo_cerrado']) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado($analisis['fecha_pago']);
            return false;
        }

        $datos = $analisis['datos'];
        $comp = $analisis['complemento'];
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $comp['fecha_pago']) === 1 ? $comp['fecha_pago'] : date('Y-m-d');
        $total = Contabilidad::centavos($comp['monto'] ?: ($datos['total'] ?? 0));
        $uuid = strtoupper(trim((string) ($datos['uuid'] ?? '')));
        if ($uuid === '') {
            self::$errores[] = 'El complemento no trae UUID: no se puede registrar sin folio fiscal.';
            return false;
        }

        $seleccion = [];
        foreach ((array) ($p['doctos'] ?? []) as $indice => $op) {
            $seleccion[(int) $indice] = [
                'vincular'    => !empty($op['vincular']),
                'crear_cobro' => !empty($op['crear_cobro']),
            ];
        }

        try {
            self::$db->begin_transaction();

            // El UUID es la red de seguridad ante dos cargas simultáneas.
            $crear = self::$db->prepare(
                'INSERT INTO complementos_pago (contribuyente_id, lado, lectura_id, uuid, folio_comercial, fecha_pago,
                        total, moneda, emisor_rfc, receptor_rfc, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $contribuyente = Contabilidad::contribuyentePrincipal();
            $lado = 'cliente';
            $folio = trim((string) ($datos['folio_comercial'] ?? '')) ?: null;
            $moneda = preg_match('/^[A-Z]{3}$/', strtoupper((string) ($datos['moneda'] ?? ''))) ? strtoupper((string) $datos['moneda']) : 'MXN';
            $emisor = mb_substr(VentasFiscal::normalizarRfc((string) ($datos['emisor_rfc'] ?? '')), 0, 13);
            $receptor = mb_substr(VentasFiscal::normalizarRfc((string) ($datos['receptor_rfc'] ?? '')), 0, 13);
            $monto = number_format($total / 100, 2, '.', '');
            $crear->bind_param(
                'isisssdsssi',
                $contribuyente, $lado, $lecturaId, $uuid, $folio, $fecha, $monto, $moneda, $emisor, $receptor, $usuarioId
            );
            $crear->execute();
            $complementoId = (int) self::$db->insert_id;
            $crear->close();

            $creados = 0;
            $vinculados = 0;
            $cobrosCreados = 0;
            foreach ($analisis['doctos'] as $docto) {
                $op = $seleccion[$docto['indice']] ?? ['vincular' => false, 'crear_cobro' => false];

                $ingresoId = $docto['ingreso'] !== null ? (int) $docto['ingreso']['id'] : null;
                $cobroId = null;

                if ($ingresoId === null) {
                    $estadoDocto = 'sin_factura';
                } elseif (!$op['vincular']) {
                    $estadoDocto = 'pendiente';
                } elseif ($docto['coincidencia'] !== null && $docto['estado'] === 'vinculado') {
                    // 8. El cobro ya existe: sólo se vincula.
                    $cobroId = (int) $docto['coincidencia']['id'];
                    $estadoDocto = 'vinculado';
                } elseif ($op['crear_cobro'] && $docto['puede_crear']) {
                    $motivoFallo = '';
                    $cobroId = self::crearCobroDesdeComplemento($docto, $comp, $uuid, $usuarioId, $motivoFallo);
                    if ($cobroId !== null) {
                        $estadoDocto = 'vinculado';
                        $cobrosCreados++;
                    } else {
                        // Saldo o periodo: queda la discrepancia, sin escribir de más.
                        $estadoDocto = 'discrepancia';
                        self::$errores[] = 'Factura ' . $docto['folio'] . ': no se registró el cobro propuesto ('
                            . ($motivoFallo ?: 'revisar saldo y periodo') . ').';
                    }
                } else {
                    $estadoDocto = (string) $docto['estado'];   // sin_pago | discrepancia
                }

                $insertar = self::$db->prepare(
                    'INSERT INTO complemento_doctos (complemento_id, documento_uuid, serie, folio, moneda_dr, num_parcialidad,
                            saldo_anterior, importe_pagado, orden_id, ingreso_id, gasto_id, estado, diferencia, datos)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $serie = $docto['serie'] !== '' ? $docto['serie'] : null;
                $folioDocto = $docto['folio'] !== '' ? $docto['folio'] : null;
                $parcialidad = $docto['num_parcialidad'] !== '' ? $docto['num_parcialidad'] : null;
                $saldoAnterior = $docto['saldo_anterior'] > 0 ? number_format($docto['saldo_anterior'], 2, '.', '') : null;
                $pagado = number_format($docto['importe_pagado'], 2, '.', '');
                $ordenId = null;
                $gastoId = null;
                $diferencia = number_format($docto['diferencia'], 2, '.', '');
                $datosDocto = json_encode($docto['datos'], JSON_UNESCAPED_UNICODE);
                $insertar->bind_param(
                    'isssssddiiisds',
                    $complementoId, $docto['uuid'], $serie, $folioDocto, $docto['moneda'], $parcialidad,
                    $saldoAnterior, $pagado, $ordenId, $ingresoId, $gastoId, $estadoDocto, $diferencia, $datosDocto
                );
                $insertar->execute();
                $doctoId = (int) self::$db->insert_id;
                $insertar->close();
                $creados++;

                if ($cobroId !== null) {
                    $ligar = self::$db->prepare('INSERT INTO complemento_cobros (complemento_docto_id, cobro_id) VALUES (?, ?)');
                    $ligar->bind_param('ii', $doctoId, $cobroId);
                    $ligar->execute();
                    $ligar->close();
                    $vinculados++;
                }
            }

            // La lectura deja de estar pendiente y queda trazada contra el complemento.
            self::cerrarLectura($lecturaId, $complementoId, $usuarioId);

            Auditoria::registrar('complemento_pago', $complementoId, 'crear', $usuarioId, null, [
                'lado' => 'cliente', 'uuid' => $uuid, 'fecha_pago' => $fecha, 'monto' => $monto,
                'doctos' => $creados, 'cobros_vinculados' => $vinculados, 'cobros_creados' => $cobrosCreados,
                'lectura_id' => $lecturaId,
            ]);
            self::$db->commit();

            self::$errores[] = 'Complemento registrado' . ($creados ? " con $creados factura(s) relacionada(s)" : '')
                . ($vinculados ? " y $vinculados cobro(s) vinculado(s)" : '')
                . ($cobrosCreados ? " ($cobrosCreados cobro(s) nuevo(s))" : '') . '.';
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            if ((int) $excepcion->getCode() === 1062) {
                self::$errores[] = 'Ese complemento ya estaba registrado (UUID repetido): no se cargó dos veces.';
            } else {
                error_log('[ComplementosVenta::registrar] ' . $excepcion->getMessage());
                self::$errores[] = 'No fue posible registrar el complemento.';
            }
            return false;
        }
    }

    /**
     * Crea el cobro que el complemento declara y que no existía. Devuelve su id
     * o null (con el error en getErrores()) si Contabilidad lo rechaza.
     */
    private static function crearCobroDesdeComplemento(array $docto, array $comp, string $uuidComplemento, int $usuarioId, string &$motivoFallo): ?int
    {
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $comp['fecha_pago']) === 1 ? $comp['fecha_pago'] : date('Y-m-d');
        $metodo = self::FORMA_A_COBRO[(string) ($comp['forma_pago'] ?? '')] ?? '';

        $ok = Contabilidad::registrarCobro(
            (int) $docto['ingreso']['id'],
            $fecha,
            number_format($docto['importe_pagado'], 2, '.', ''),
            $metodo,
            'Complemento ' . $uuidComplemento,
            $usuarioId,
            true    // dentro de la transacción del complemento
        );
        if (!$ok) {
            $motivoFallo = Contabilidad::getErrores()[0] ?? '';
            return null;
        }

        return Contabilidad::$ultimoId;
    }

    /** Marca la lectura confirmada contra el complemento (deja de estar «sin decidir»). */
    private static function cerrarLectura(int $lecturaId, int $complementoId, int $usuarioId): void
    {
        $consulta = self::$db->prepare(
            "UPDATE lecturas_documentales SET estado = 'confirmada', entidad = 'complemento', entidad_id = ?,
                    confirmado_por = ?, confirmado_en = NOW()
              WHERE id = ? AND estado = 'pendiente'"
        );
        $consulta->bind_param('iii', $complementoId, $usuarioId, $lecturaId);
        $consulta->execute();
        $consulta->close();
    }

    /**
     * Cancela un complemento de ESTE lado (no se borra): desvincula sus cobros y
     * cancela los cobros que él mismo creó (eran su respaldo). Los cobros que ya
     * existían sólo se desvinculan: son dinero real recibido.
     */
    public static function cancelar(int $complementoId, string $motivo, int $usuarioId): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Escribe el motivo de la cancelación (mínimo 5 caracteres).';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $q = self::$db->prepare('SELECT * FROM complementos_pago WHERE id = ? FOR UPDATE');
            $q->bind_param('i', $complementoId);
            $q->execute();
            $comp = $q->get_result()->fetch_assoc();
            $q->close();

            if (!$comp || (string) $comp['lado'] !== 'cliente') {
                self::$db->rollback();
                self::$errores[] = 'Ese complemento no existe o no es de ventas.';
                return false;
            }
            if ((string) $comp['estado'] !== 'vigente') {
                self::$db->rollback();
                self::$errores[] = 'El complemento ya estaba cancelado.';
                return false;
            }
            if (Contabilidad::periodoCerrado((int) $comp['contribuyente_id'], (string) $comp['fecha_pago'])) {
                self::$db->rollback();
                self::$errores[] = Contabilidad::mensajePeriodoCerrado((string) $comp['fecha_pago']);
                return false;
            }

            // Los cobros que ÉL creó (referencia = su UUID) dejan de tener respaldo.
            $q = self::$db->prepare(
                'SELECT c.id, c.ingreso_id FROM cobros c
                   JOIN complemento_cobros cc ON cc.cobro_id = c.id
                  WHERE cc.complemento_docto_id IN (SELECT id FROM complemento_doctos WHERE complemento_id = ?)
                    AND c.estado = \'vigente\' AND c.referencia = ?'
            );
            $referencia = 'Complemento ' . (string) $comp['uuid'];
            $q->bind_param('is', $complementoId, $referencia);
            $q->execute();
            $creados = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();

            foreach ($creados as $creado) {
                if (!Contabilidad::cancelarCobro((int) $creado['id'], $motivo, $usuarioId)) {
                    self::$db->rollback();
                    self::$errores = array_merge(self::$errores, Contabilidad::getErrores());
                    return false;
                }
            }

            $desvincular = self::$db->prepare(
                'DELETE FROM complemento_cobros WHERE complemento_docto_id IN (SELECT id FROM complemento_doctos WHERE complemento_id = ?)'
            );
            $desvincular->bind_param('i', $complementoId);
            $desvincular->execute();
            $desvincular->close();

            $anular = self::$db->prepare("UPDATE complementos_pago SET estado = 'cancelado', motivo_cancelacion = ? WHERE id = ?");
            $anular->bind_param('si', $motivo, $complementoId);
            $anular->execute();
            $anular->close();

            Auditoria::registrar('complemento_pago', $complementoId, 'cancelar', $usuarioId, $motivo, [
                'lado' => 'cliente', 'cobros_cancelados' => count($creados),
            ]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[ComplementosVenta::cancelar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cancelar el complemento.';
            return false;
        }
    }

    /* ---------------------------------------------------------------- *
     * CONSULTAS PARA LAS VISTAS
     * ---------------------------------------------------------------- */

    /** Últimos complementos de clientes. @return array<int, array<string, mixed>> */
    public static function recientes(int $limite = 20): array
    {
        $limite = max(1, $limite);
        $q = self::$db->prepare(
            'SELECT c.*, u.nombre AS usuario_nombre FROM complementos_pago c
               LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.lado = \'cliente\'
              ORDER BY c.creado_en DESC LIMIT ?'
        );
        $q->bind_param('i', $limite);
        $q->execute();
        $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        return $filas;
    }

    /**
     * Facturas relacionadas de un complemento ya registrado (para verlo después).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function doctos(int $complementoId): array
    {
        $q = self::$db->prepare(
            'SELECT d.*, i.folio AS ingreso_folio, i.uuid_fiscal, i.estado_facturacion, i.estado_cobro,
                    v.folio AS venta_folio,
                    (SELECT COUNT(*) FROM complemento_cobros cc WHERE cc.complemento_docto_id = d.id) AS cobros_vinculados
               FROM complemento_doctos d
          LEFT JOIN ingresos i ON i.id = d.ingreso_id
          LEFT JOIN ventas v ON v.id = i.venta_id
              WHERE d.complemento_id = ?
           ORDER BY d.id'
        );
        $q->bind_param('i', $complementoId);
        $q->execute();
        $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        return $filas;
    }
}
