<?php

namespace App;

/**
 * ComplementosPago — ciclo de complementos de pago (CFDI tipo «P») recibidos
 * de proveedores, vinculados a las facturas originales y a los pagos ya registrados.
 *
 * Reglas:
 *   • Analizar solo LEE: nada se escribe hasta que la persona confirma.
 *   • El UUID del complemento es único: cargarlo dos veces se bloquea mostrando
 *     el registro existente (nunca se duplica el movimiento).
 *   • Cada DoctoRelacionado se resuelve contra su factura (UUID en gastos) y de
 *     ahí contra su compra; luego se VINCULA con el pago de pagos_compra que ya
 *     existe (coincidencia exacta de importe). Si no existe, solo se registra un
 *     pago nuevo cuando la persona lo pide expresamente: jamás un segundo pago
 *     por un movimiento que ya está.
 *   • Las discrepancias (importe del complemento ≠ pagos registrados) se calculan
 *     y se muestran ANTES de confirmar; el registro fallido las reporta sin escribir.
 *   • Cancelación lógica con motivo, auditoría de cada paso y respeto de periodos
 *     cerrados (lo garantiza PagosCompra, que es quien escribe pagos).
 */
class ComplementosPago
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Métodos de pago (SAT) → etiqueta interna de Contabilidad. */
    private const FORMAS_SAT = [
        '01' => 'Efectivo', '02' => 'Cheque', '03' => 'Transferencia',
        '04' => 'Tarjeta', '05' => 'Tarjeta', '06' => 'Tarjeta',
        '28' => 'Tarjeta', '29' => 'Tarjeta', '48' => 'Tarjeta',
    ];

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
     * Análisis de una lectura destino «complemento»: datos del CFDI de pago, sus
     * DoctoRelacionado resueltos contra facturas/compras/pagos y las discrepancias.
     *
     * @return array<string, mixed>|null null si la lectura no existe o no es de complementos
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
        // Un complemento puede llegar por su propia bandeja (destino «complemento»)
        // o por la carga por lotes (destino «compra»/«venta»): manda el CFDI.
        // Si no es tipo «P» y no viene de la bandeja de complementos, no es suyo.
        if ((string) $lectura['destino'] !== 'complemento' && (string) ($cfdi['datos']['tipo'] ?? '') !== 'P') {
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
                . 'si es una factura normal, usa «Compras inteligentes».';
        }

        // ¿De qué lado está? Si el EMISOR soy yo, es un complemento de un COBRO A
        // CLIENTE (FASE 6): aquí sólo se registran los de proveedores.
        $lado = Contabilidad::esRfcPropio((string) ($datos['emisor_rfc'] ?? '')) ? 'cliente' : 'proveedor';
        if ($lado === 'cliente') {
            $avisos[] = 'Este complemento es de un cobro a CLIENTE (el emisor eres tú): '
                . 'se registra en Ventas → Complementos de pago, no aquí.';
        }

        // El UUID del complemento ya registrado → se muestra, no se vuelve a crear.
        $duplicado = self::buscarPorUuid((string) ($datos['uuid'] ?? ''));

        // ¿Mismo archivo cargado antes? (huella idéntica en otra lectura de complemento).
        $sha = (string) $lectura['sha256'];
        if ($sha !== '') {
            $q = self::$db->prepare(
                "SELECT c.id, c.uuid FROM complementos_pago c
                   JOIN lecturas_documentales l ON l.id = c.lectura_id
                  WHERE l.sha256 = ? AND l.id <> ? AND c.estado = 'vigente' LIMIT 1"
            );
            $q->bind_param('si', $sha, $lecturaId);
            $q->execute();
            $otro = $q->get_result()->fetch_assoc();
            $q->close();
            if ($otro && !$duplicado) {
                $duplicado = $otro;
                $avisos[] = 'Este mismo archivo ya se cargó como complemento ' . $otro['uuid'] . '.';
            }
        }

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
            'doctos'      => $doctos,
            'avisos'      => $avisos,
            'fecha_pago'  => $complemento['fecha_pago'] ?? substr((string) ($datos['fecha'] ?? ''), 0, 10),
            'periodo_cerrado' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $complemento['fecha_pago'] ?? '') === 1
                && Contabilidad::periodoCerrado(Contabilidad::contribuyentePrincipal(), $complemento['fecha_pago']),
        ];
    }

    /**
     * Resuelve un DoctoRelacionado: factura (gasto por UUID) → compra → pagos ya
     * registrados, con la discrepancia entre lo que dice el complemento y lo pagado.
     *
     * @return array<string, mixed>
     */
    private static function resolverDocto(int $indice, array $docto, array $complemento): array
    {
        $importePagado = Contabilidad::centavos($docto['importe_pagado'] ?? 0);
        $fila = [
            'indice'          => $indice,
            'uuid'            => (string) $docto['uuid'],
            'serie'           => (string) $docto['serie'],
            'folio'           => (string) $docto['folio'],
            'num_parcialidad' => (string) $docto['num_parcialidad'],
            'saldo_anterior'  => (float) ($docto['saldo_anterior'] ?? 0),
            'importe_pagado'  => $importePagado / 100,
            'moneda'          => (string) $docto['moneda'],
            'datos'           => $docto,
            'gasto'           => null,
            'orden'           => null,
            'pagos'           => [],
            'coincidencia'    => null,
            'diferencia'      => 0.0,
            'estado'          => 'sin_factura',
            'nota'            => '',
            'puede_crear'     => false,
        ];
        if ($fila['uuid'] === '') {
            $fila['nota'] = 'El DoctoRelacionado no trae UUID: no se puede localizar la factura.';
            return $fila;
        }

        // 1. La factura original: primero como gasto de compra, luego como gasto suelto.
        $gasto = null;
        $q = self::$db->prepare("SELECT id, concepto, total, estado FROM gastos WHERE uuid_fiscal = ? AND estado = 'vigente' LIMIT 1");
        $q->bind_param('s', $fila['uuid']);
        $q->execute();
        $gasto = $q->get_result()->fetch_assoc();
        $q->close();

        $orden = null;
        if ($gasto) {
            $fila['gasto'] = ['id' => (int) $gasto['id'], 'concepto' => (string) $gasto['concepto']];
            $q = self::$db->prepare(
                'SELECT o.id, o.folio, o.estado, o.estado_cfdi, o.total_esperado, o.iva, p.nombre AS proveedor_nombre
                   FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id WHERE o.gasto_id = ? LIMIT 1'
            );
            $gastoId = (int) $gasto['id'];
            $q->bind_param('i', $gastoId);
            $q->execute();
            $orden = $q->get_result()->fetch_assoc();
            $q->close();
            if ($orden) {
                $total = PagosCompra::totalCompra($orden);
                $pagado = PagosCompra::pagado((int) $orden['id']);
                $fila['orden'] = [
                    'id' => (int) $orden['id'], 'folio' => (string) $orden['folio'],
                    'estado' => (string) $orden['estado'], 'estado_cfdi' => (string) $orden['estado_cfdi'],
                    'proveedor' => (string) $orden['proveedor_nombre'],
                    'total' => $total / 100, 'pagado' => $pagado / 100,
                    'saldo' => ($total - $pagado) / 100,
                    'estado_pago' => PagosCompra::estado($total, $pagado),
                ];
            }
        }

        // 2. Sin factura localizada: nada más que mostrar.
        if (!$gasto) {
            $fila['nota'] = 'No se encontró una factura con ese UUID en el sistema.';
            return $fila;
        }
        if (!$orden) {
            $fila['estado'] = 'sin_factura';
            $fila['nota'] = 'El gasto #' . (int) $gasto['id'] . ' no pertenece a una compra: no hay pagos de proveedor que vincular.';
            return $fila;
        }

        // 3. Pago existente: coincidencia EXACTA de importe entre los pagos vigentes
        //    de la compra que todavía no estén ligados a otro complemento.
        $candidatos = self::pagosCandidatos((int) $orden['id'], $importePagado, $complemento['fecha_pago'] ?? '');
        $fila['pagos'] = array_map(static fn (array $p): array => [
            'id' => (int) $p['id'], 'fecha' => (string) $p['fecha'],
            'importe' => (float) $p['importe'], 'metodo' => (string) ($p['metodo'] ?? ''),
        ], $candidatos['todos']);

        if ($candidatos['exacto'] !== null) {
            $fila['estado'] = 'vinculado';
            $fila['coincidencia'] = $candidatos['exacto'];
            $fila['nota'] = 'Coincide con el pago del ' . $candidatos['exacto']['fecha'] . ' por '
                . number_format((float) $candidatos['exacto']['importe'], 2) . '.';
            return $fila;
        }

        if ($candidatos['todos'] === []) {
            $fila['estado'] = 'sin_pago';
            $fila['diferencia'] = $importePagado / 100;
            $fila['puede_crear'] = true;
            $fila['nota'] = 'Este complemento reporta un pago que no está registrado en la compra.';
            return $fila;
        }

        // Hay pagos, pero ninguno coincide: discrepancia que se muestra antes de confirmar.
        $masCercano = null;
        $mejorDistancia = PHP_INT_MAX;
        foreach ($candidatos['todos'] as $p) {
            $distancia = abs(Contabilidad::centavos($p['importe']) - $importePagado);
            if ($distancia < $mejorDistancia) {
                $mejorDistancia = $distancia;
                $masCercano = $p;
            }
        }
        $fila['estado'] = 'discrepancia';
        $fila['diferencia'] = ($importePagado - Contabilidad::centavos($masCercano['importe'])) / 100;
        $fila['puede_crear'] = true;
        $fila['coincidencia'] = $masCercano;
        $fila['nota'] = 'El complemento dice ' . number_format($importePagado / 100, 2) . ' y el pago más cercano es de '
            . number_format((float) $masCercano['importe'], 2) . ' (diferencia '
            . number_format($fila['diferencia'], 2) . ').';

        return $fila;
    }

    /**
     * Pagos vigentes de la compra, con el que coincide EXACTO en importe (y, si hay
     * varios, el de la fecha del complemento primero).
     *
     * No se excluyen los ya vinculados a otro complemento: el vínculo es N:M y así un
     * pago puede estar respaldado por dos complementos sin duplicar NUNCA el pago.
     *
     * @return array{todos: array<int, array<string, mixed>>, exacto: ?array<string, mixed>}
     */
    private static function pagosCandidatos(int $ordenId, int $importeCentavos, string $fecha): array
    {
        $q = self::$db->prepare(
            "SELECT p.id, p.fecha, p.importe, p.metodo, p.referencia
               FROM pagos_compra p
              WHERE p.orden_id = ? AND p.estado = 'vigente'
           ORDER BY p.fecha, p.id"
        );
        $q->bind_param('i', $ordenId);
        $q->execute();
        $todos = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        $exacto = null;
        foreach ($todos as $p) {
            if (Contabilidad::centavos($p['importe']) === $importeCentavos) {
                // Con la misma fecha gana; si no, nos quedamos con el primero exacto
                // pero seguimos buscando uno con fecha idéntica al complemento.
                if ($exacto === null || (string) $p['fecha'] === $fecha) {
                    $exacto = $p;
                }
                if ((string) $p['fecha'] === $fecha) {
                    break;
                }
            }
        }

        return ['todos' => $todos, 'exacto' => $exacto];
    }

    /** Complemento ya registrado por su UUID (vigente); null si no existe. */
    public static function buscarPorUuid(string $uuid): ?array
    {
        $uuid = strtoupper(trim($uuid));
        if ($uuid === '') {
            return null;
        }
        $q = self::$db->prepare("SELECT id, uuid, fecha_pago, total, estado FROM complementos_pago WHERE uuid = ? LIMIT 1");
        $q->bind_param('s', $uuid);
        $q->execute();
        $fila = $q->get_result()->fetch_assoc();
        $q->close();

        return $fila ?: null;
    }

    /* --------------------------------------------------------------------
     * REGISTRO (la única escritura)
     * ------------------------------------------------------------------ */

    /**
     * Registra el complemento y vincula cada DoctoRelacionado con su compra y sus
     * pagos. Nunca crea un pago si ya hay uno que coincide, y nunca pasa de una
     * sola fila por complemento (el UUID es único).
     *
     * @param array<string, mixed> $p POST: doctos[indice][vincular], doctos[indice][crear_pago]
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
        if (($analisis['lado'] ?? 'proveedor') === 'cliente') {
            self::$errores[] = 'Ese complemento corresponde a un cobro a cliente: regístralo en Ventas → Complementos de pago.';
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
                'vincular'   => !empty($op['vincular']),
                'crear_pago' => !empty($op['crear_pago']),
            ];
        }

        try {
            self::$db->begin_transaction();

            // El UUID es la última línea de defensa ante dos cargas simultáneas.
            $consulta = self::$db->prepare(
                'INSERT INTO complementos_pago (contribuyente_id, lectura_id, uuid, folio_comercial, fecha_pago,
                        total, moneda, emisor_rfc, receptor_rfc, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $contribuyente = Contabilidad::contribuyentePrincipal();
            $folio = trim((string) ($datos['folio_comercial'] ?? '')) ?: null;
            $moneda = preg_match('/^[A-Z]{3}$/', strtoupper((string) ($datos['moneda'] ?? ''))) ? strtoupper((string) $datos['moneda']) : 'MXN';
            $emisor = strtoupper(mb_substr((string) ($datos['emisor_rfc'] ?? ''), 0, 13));
            $receptor = strtoupper(mb_substr((string) ($datos['receptor_rfc'] ?? ''), 0, 13));
            $monto = number_format($total / 100, 2, '.', '');
            $consulta->bind_param(
                'iisssdsssi',
                $contribuyente, $lecturaId, $uuid, $folio, $fecha, $monto, $moneda, $emisor, $receptor, $usuarioId
            );
            $consulta->execute();
            $complementoId = (int) self::$db->insert_id;
            $consulta->close();

            $creados = 0;
            $vinculados = 0;
            foreach ($analisis['doctos'] as $docto) {
                $op = $seleccion[$docto['indice']] ?? ['vincular' => false, 'crear_pago' => false];

                // Estado y vínculo: TODOS los DoctoRelacionado quedan registrados (son datos
                // del XML); solo el pago se crea o se liga cuando la persona lo eligió.
                $pagoId = null;
                if ($docto['orden'] === null) {
                    $estadoDocto = 'sin_factura';
                } elseif (!$op['vincular']) {
                    $estadoDocto = 'pendiente';
                } elseif ($docto['coincidencia'] !== null && $docto['estado'] === 'vinculado') {
                    $pagoId = (int) $docto['coincidencia']['id'];
                    $estadoDocto = 'vinculado';
                } elseif ($op['crear_pago'] && $docto['puede_crear']) {
                    $motivoFallo = '';
                    $pagoId = self::crearPagoDesdeComplemento($docto, $comp, $uuid, $usuarioId, $motivoFallo);
                    if ($pagoId !== null) {
                        $estadoDocto = 'vinculado';
                    } else {
                        // No se pudo (saldo o periodo): queda la discrepancia sin escribir de más.
                        $estadoDocto = 'discrepancia';
                        self::$errores[] = 'Compra ' . $docto['orden']['folio'] . ': no se registró el pago propuesto ('
                            . ($motivoFallo ?: 'revisar saldo y periodo') . ').';
                    }
                } else {
                    $estadoDocto = (string) $docto['estado'];   // sin_pago | discrepancia
                }

                $insertar = self::$db->prepare(
                    'INSERT INTO complemento_doctos (complemento_id, documento_uuid, serie, folio, moneda_dr, num_parcialidad,
                            saldo_anterior, importe_pagado, orden_id, gasto_id, estado, diferencia, datos)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ordenId = $docto['orden'] !== null ? (int) $docto['orden']['id'] : null;
                $gastoId = $docto['gasto'] !== null ? (int) $docto['gasto']['id'] : null;
                $serie = $docto['serie'] !== '' ? $docto['serie'] : null;
                $folioDocto = $docto['folio'] !== '' ? $docto['folio'] : null;
                $parcialidad = $docto['num_parcialidad'] !== '' ? $docto['num_parcialidad'] : null;
                $saldoAnterior = $docto['saldo_anterior'] > 0 ? number_format($docto['saldo_anterior'], 2, '.', '') : null;
                $pagado = number_format($docto['importe_pagado'], 2, '.', '');
                $diferencia = number_format($docto['diferencia'], 2, '.', '');
                $datosDocto = json_encode($docto['datos'], JSON_UNESCAPED_UNICODE);
                $insertar->bind_param(
                    'isssssddiisds',
                    $complementoId, $docto['uuid'], $serie, $folioDocto, $docto['moneda'], $parcialidad,
                    $saldoAnterior, $pagado, $ordenId, $gastoId, $estadoDocto, $diferencia, $datosDocto
                );
                $insertar->execute();
                $doctoId = (int) self::$db->insert_id;
                $insertar->close();
                $creados++;

                if ($pagoId !== null) {
                    $ligar = self::$db->prepare('INSERT INTO complemento_pagos (complemento_docto_id, pago_id) VALUES (?, ?)');
                    $ligar->bind_param('ii', $doctoId, $pagoId);
                    $ligar->execute();
                    $ligar->close();
                    $vinculados++;
                }
            }

            // La lectura deja de estar pendiente y queda trazada contra el complemento.
            self::cerrarLectura($lecturaId, $complementoId, $usuarioId);

            Auditoria::registrar('complemento_pago', $complementoId, 'crear', $usuarioId, null, [
                'uuid' => $uuid, 'fecha_pago' => $fecha, 'monto' => $monto,
                'doctos' => $creados, 'pagos_vinculados' => $vinculados, 'lectura_id' => $lecturaId,
            ]);
            self::$db->commit();

            self::$errores[] = 'Complemento registrado' . ($creados ? " con $creados factura(s) relacionada(s)" : '')
                . ($vinculados ? " y $vinculados pago(s) vinculado(s)" : '') . '.';
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            if ((int) $excepcion->getCode() === 1062) {
                self::$errores[] = 'Ese complemento ya estaba registrado (UUID repetido): no se cargó dos veces.';
            } else {
                error_log('[ComplementosPago::registrar] ' . $excepcion->getMessage());
                self::$errores[] = 'No fue posible registrar el complemento.';
            }
            return false;
        }
    }

    /**
     * Crea el pago que el complemento evidencia y que aún no existía. Devuelve su id
     * o null (con el error en getErrores()) si PagosCompra lo rechaza: saldo o periodo.
     */
    private static function crearPagoDesdeComplemento(array $docto, array $comp, string $uuidComplemento, int $usuarioId, string &$motivoFallo): ?int
    {
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $comp['fecha_pago']) === 1 ? $comp['fecha_pago'] : date('Y-m-d');
        $metodo = self::FORMAS_SAT[(string) ($comp['forma_pago'] ?? '')] ?? '';
        // Determinista: reenviar el mismo complemento nunca duplica este pago.
        $token = substr(hash('sha256', $uuidComplemento . '|' . $docto['indice']), 0, 32);

        $ok = PagosCompra::registrar(
            (int) $docto['orden']['id'],
            $fecha,
            number_format($docto['importe_pagado'], 2, '.', ''),
            $metodo,
            'Complemento ' . $uuidComplemento,
            $token,
            $usuarioId,
            true   // ya estamos dentro de la transacción del complemento
        );

        if (!$ok) {
            $motivoFallo = PagosCompra::getErrores()[0] ?? '';
            return null;
        }

        return PagosCompra::$ultimoId;
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
     * Cancela un complemento (no se borra): desvincula sus pagos y deja motivo.
     * Los pagos creados a partir de él se cancelan también (eran su respaldo).
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

            if (!$comp || (string) $comp['estado'] !== 'vigente') {
                self::$db->rollback();
                self::$errores[] = 'El complemento no existe o ya estaba cancelado.';
                return false;
            }
            if (Contabilidad::periodoCerrado((int) $comp['contribuyente_id'], (string) $comp['fecha_pago'])) {
                self::$db->rollback();
                self::$errores[] = Contabilidad::mensajePeriodoCerrado((string) $comp['fecha_pago']);
                return false;
            }

            // Los pagos que este complemento creó (referencia = su UUID) se cancelan:
            // sin el complemento no tendrían respaldo. Los que ya existían solo se desvinculan.
            $q = self::$db->prepare(
                'SELECT p.id, p.orden_id FROM pagos_compra p
                   JOIN complemento_pagos cp ON cp.pago_id = p.id
                  WHERE cp.complemento_docto_id IN (SELECT id FROM complemento_doctos WHERE complemento_id = ?)
                    AND p.estado = \'vigente\' AND p.referencia = ?'
            );
            $referencia = 'Complemento ' . (string) $comp['uuid'];
            $q->bind_param('is', $complementoId, $referencia);
            $q->execute();
            $creados = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();

            $cancelarPago = self::$db->prepare("UPDATE pagos_compra SET estado = 'cancelado', motivo_cancelacion = ? WHERE id = ?");
            foreach ($creados as $creado) {
                $pagoId = (int) $creado['id'];
                $cancelarPago->bind_param('si', $motivo, $pagoId);
                $cancelarPago->execute();
                Auditoria::registrar('pago_compra', $pagoId, 'cancelar', $usuarioId,
                    'Pago creado por el complemento que se cancela', ['orden' => (int) $creado['orden_id']]);
                PagosCompra::sincronizarGasto((int) $creado['orden_id']);
            }
            $cancelarPago->close();

            $desvincular = self::$db->prepare(
                'DELETE FROM complemento_pagos WHERE complemento_docto_id IN (SELECT id FROM complemento_doctos WHERE complemento_id = ?)'
            );
            $desvincular->bind_param('i', $complementoId);
            $desvincular->execute();
            $desvincular->close();

            $anular = self::$db->prepare("UPDATE complementos_pago SET estado = 'cancelado', motivo_cancelacion = ? WHERE id = ?");
            $anular->bind_param('si', $motivo, $complementoId);
            $anular->execute();
            $anular->close();

            Auditoria::registrar('complemento_pago', $complementoId, 'cancelar', $usuarioId, $motivo, [
                'pagos_cancelados' => count($creados),
            ]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[ComplementosPago::cancelar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cancelar el complemento.';
            return false;
        }
    }

    /** Últimos complementos de PROVEEDORES (el de ventas se lista en su propia pantalla). @return array<int, array<string, mixed>> */
    public static function recientes(int $limite = 20): array
    {
        $limite = max(1, $limite);
        $q = self::$db->prepare(
            'SELECT c.*, u.nombre AS usuario_nombre FROM complementos_pago c
               LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.lado = \'proveedor\'
              ORDER BY c.creado_en DESC LIMIT ?'
        );
        $q->bind_param('i', $limite);
        $q->execute();
        $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        return $filas;
    }

    /**
     * Facturas de un complemento ya registrado (para verlo después).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function doctos(int $complementoId): array
    {
        $q = self::$db->prepare(
            'SELECT d.*, o.folio AS orden_folio FROM complemento_doctos d
          LEFT JOIN ordenes_compra o ON o.id = d.orden_id
              WHERE d.complemento_id = ? ORDER BY d.id'
        );
        $q->bind_param('i', $complementoId);
        $q->execute();
        $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        return $filas;
    }
}
