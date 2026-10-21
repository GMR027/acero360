<?php

namespace App;

/**
 * CompraContable — enlaza una compra con la contabilidad existente SIN duplicar.
 *
 * Reglas:
 *   • Una compra tiene a lo más UN gasto (ordenes_compra.gasto_id es único). Enviarla a contabilidad dos
 *     veces, o adjuntar la factura después, reutiliza ese mismo gasto.
 *   • Los comprobantes se adjuntan al gasto con Archivos (mismo almacén y mismas validaciones documentales).
 *     El UUID de un CFDI no puede estar en dos operaciones.
 *   • Adjuntar un documento NO vuelve deducible el gasto: revision_fiscal queda «por validar». Un PDF o una
 *     foto no se toma por CFDI: la compra pasa a «con CFDI» solo con un XML con estructura de CFDI y timbre.
 *   • El IVA no se calcula: si la compra no lo trae, el gasto queda con IVA 0 y una nota para el contador.
 */
class CompraContable
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    public const ESTADOS_CFDI = [
        'pendiente_cfdi' => ['etiqueta' => 'CFDI pendiente',  'clase' => 'badge--pendiente'],
        'con_cfdi'       => ['etiqueta' => 'Con CFDI',        'clase' => 'badge--aprobado'],
        'no_facturada'   => ['etiqueta' => 'Sin factura',     'clase' => 'badge--neutro'],
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

    /** @return array<string, mixed>|null */
    public static function gastoDe(int $ordenId): ?array
    {
        $consulta = self::$db->prepare('SELECT gasto_id FROM ordenes_compra WHERE id = ?');
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return !empty($fila['gasto_id']) ? Gastos::buscarID((int) $fila['gasto_id']) : null;
    }

    /**
     * Crea el gasto de la compra (una sola vez) y devuelve su id; si ya existe devuelve el existente.
     * 0 si no se pudo (errores en getErrores()).
     */
    public static function enviarAContabilidad(int $ordenId, int $usuarioId): int
    {
        self::$errores = [];

        try {
            self::$db->begin_transaction();
            $consulta = self::$db->prepare('SELECT * FROM ordenes_compra WHERE id = ? FOR UPDATE');
            $consulta->bind_param('i', $ordenId);
            $consulta->execute();
            $orden = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$orden || $orden['estado'] === 'cancelada') {
                self::$db->rollback();
                self::$errores[] = 'La compra no existe o está cancelada.';
                return 0;
            }
            if (!empty($orden['gasto_id'])) {
                $previo = Gastos::buscarID((int) $orden['gasto_id']);
                if ($previo && $previo['estado'] === 'vigente') {
                    self::$db->commit();
                    return (int) $orden['gasto_id'];   // ya existe y está vigente: no se crea otro
                }
                // Un gasto cancelado no cuenta como duplicado: la compra puede volver a enviarse y se enlaza al nuevo.
                $desligar = self::$db->prepare('UPDATE ordenes_compra SET gasto_id = NULL WHERE id = ?');
                $desligar->bind_param('i', $ordenId);
                $desligar->execute();
                $desligar->close();
            }

            $partidas = OrdenesCompra::partidas($ordenId);
            $subtotal = (string) $orden['total_esperado'];
            if (Contabilidad::centavos($subtotal) <= 0) {
                self::$db->rollback();
                self::$errores[] = 'La compra necesita un importe mayor a cero para enviarse a contabilidad.';
                return 0;
            }

            $ivaCapturado = $orden['iva'] !== null;
            $tasa = $ivaCapturado ? round(((float) $orden['iva'] / (float) $subtotal) * 100, 2) : 0.0;
            $primera = trim((string) ($partidas[0]['descripcion'] ?? '')) ?: trim((string) ($partidas[0]['producto_nombre'] ?? ($partidas[0]['material_nombre'] ?? '')));
            $mas = count($partidas) > 1 ? ' (+' . (count($partidas) - 1) . ' más)' : '';
            $notas = 'Origen: compra ' . $orden['folio'] . ($orden['referencia'] ? ' · referencia ' . $orden['referencia'] : '') . '.';
            if (!$ivaCapturado) {
                $notas .= ' IVA no especificado en la compra: se registró en 0; el contador debe validarlo.';
            }

            $datos = [
                'fecha' => $orden['fecha_solicitud'],
                'beneficiario_tipo' => 'proveedor',
                'proveedor_id' => (int) $orden['proveedor_id'],
                'concepto' => mb_substr('Compra ' . $orden['folio'] . ': ' . ($primera ?: 'sin descripción') . $mas, 0, 255),
                'naturaleza' => $orden['tipo'] === 'insumo' ? 'insumo' : 'compra',
                'empresa_id' => $orden['empresa_id'],
                'subtotal' => $subtotal,
                'iva_tasa' => (string) $tasa,
                'estado_pago' => 'pendiente',
                'comprobante_fiscal' => $orden['estado_cfdi'] === 'con_cfdi' ? '1' : '0',
                'revision_fiscal' => 'por_validar',
                'observaciones' => $notas,
            ];
            if (!Gastos::crear($datos, $usuarioId, true)) {
                self::$db->rollback();
                self::$errores = Gastos::getErrores() ?: ['No fue posible crear el gasto de la compra.'];
                return 0;
            }
            $gastoId = Gastos::$ultimoId;

            // Importes exactos de la compra (sin diferencias de redondeo por la tasa derivada).
            $iva = $ivaCapturado ? number_format((float) $orden['iva'], 2, '.', '') : '0.00';
            $total = number_format((Contabilidad::centavos($subtotal) + Contabilidad::centavos($iva)) / 100, 2, '.', '');
            $ajustar = self::$db->prepare('UPDATE gastos SET iva = ?, total = ? WHERE id = ?');
            $ajustar->bind_param('ssi', $iva, $total, $gastoId);
            $ajustar->execute();
            $ajustar->close();

            $enlazar = self::$db->prepare('UPDATE ordenes_compra SET gasto_id = ? WHERE id = ? AND gasto_id IS NULL');
            $enlazar->bind_param('ii', $gastoId, $ordenId);
            $enlazar->execute();
            $enlazar->close();

            PagosCompra::sincronizarGasto($ordenId);
            Auditoria::registrar('orden_compra', $ordenId, 'enviar_contabilidad', $usuarioId, null, ['gasto_id' => $gastoId]);
            self::$db->commit();
            return $gastoId;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[CompraContable::enviarAContabilidad] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible enviar la compra a contabilidad.';
            return 0;
        }
    }

    /**
     * Adjunta una factura o comprobante a la compra (la envía a contabilidad si aún no lo estaba).
     *
     * @param array{name: string, tmp_name: string, size: int, error: int} $archivo entrada de $_FILES
     */
    public static function adjuntarComprobante(int $ordenId, array $archivo, string $tipo, int $usuarioId, bool $esSubida = true): bool
    {
        self::$errores = [];

        // Se valida el archivo ANTES de crear nada en contabilidad.
        if (Archivos::validar($archivo, $esSubida) === null) {
            self::$errores = Archivos::getErrores();
            return false;
        }

        $gastoId = self::enviarAContabilidad($ordenId, $usuarioId);
        if ($gastoId === 0) {
            return false;
        }
        if (!Archivos::subir('gasto', $gastoId, $archivo, $tipo, $usuarioId, null, '', $esSubida)) {
            self::$errores = Archivos::getErrores();
            return false;
        }
        $documentoId = Archivos::$ultimoId;

        return self::sincronizarCfdi($ordenId, $gastoId, $documentoId, $usuarioId);
    }

    /**
     * Si el documento es un CFDI con timbre, lo refleja en el gasto y en la compra. Un PDF o una foto no cambia nada.
     */
    private static function sincronizarCfdi(int $ordenId, int $gastoId, int $documentoId, int $usuarioId): bool
    {
        $doc = Archivos::buscarID($documentoId);
        if (!$doc || $doc['estado_validacion'] !== 'cfdi_con_timbre') {
            return true;
        }
        $d = json_decode((string) $doc['datos_cfdi'], true) ?: [];
        $uuid = strtoupper((string) ($d['uuid'] ?? ''));

        // El mismo folio fiscal no puede estar vigente en dos gastos.
        if ($uuid !== '') {
            $consulta = self::$db->prepare("SELECT id FROM gastos WHERE uuid_fiscal = ? AND estado = 'vigente' AND id <> ? LIMIT 1");
            $consulta->bind_param('si', $uuid, $gastoId);
            $consulta->execute();
            $otro = $consulta->get_result()->fetch_assoc();
            $consulta->close();
            if ($otro) {
                Archivos::baja($documentoId, 'UUID ya registrado en el gasto #' . $otro['id'], $usuarioId);
                self::$errores[] = 'Ese CFDI (UUID ' . $uuid . ') ya está registrado en el gasto #' . $otro['id'] . ': no se incorporó dos veces.';
                return false;
            }
        }

        try {
            self::$db->begin_transaction();
            $actualizar = self::$db->prepare(
                "UPDATE gastos SET comprobante_fiscal = 1,
                        uuid_fiscal       = COALESCE(uuid_fiscal, NULLIF(?, '')),
                        folio_comercial   = COALESCE(folio_comercial, NULLIF(?, '')),
                        moneda            = IF(moneda = 'MXN' AND ? <> '', ?, moneda),
                        rfc_emisor        = COALESCE(rfc_emisor, NULLIF(?, '')),
                        rfc_receptor      = COALESCE(rfc_receptor, NULLIF(?, '')),
                        forma_pago_sat    = COALESCE(forma_pago_sat, NULLIF(?, '')),
                        metodo_pago_sat   = COALESCE(metodo_pago_sat, NULLIF(?, ''))
                  WHERE id = ?"
            );
            $folio = (string) ($d['folio_comercial'] ?? '');
            $moneda = strtoupper((string) ($d['moneda'] ?? ''));
            $emisor = (string) ($d['emisor_rfc'] ?? '');
            $receptor = (string) ($d['receptor_rfc'] ?? '');
            $forma = (string) ($d['forma_pago'] ?? '');
            $metodo = in_array(strtoupper((string) ($d['metodo_pago'] ?? '')), ['PUE', 'PPD'], true) ? strtoupper((string) $d['metodo_pago']) : '';
            $actualizar->bind_param('ssssssssi', $uuid, $folio, $moneda, $moneda, $emisor, $receptor, $forma, $metodo, $gastoId);
            $actualizar->execute();
            $actualizar->close();

            $compra = self::$db->prepare("UPDATE ordenes_compra SET estado_cfdi = 'con_cfdi' WHERE id = ?");
            $compra->bind_param('i', $ordenId);
            $compra->execute();
            $compra->close();

            Auditoria::registrar('orden_compra', $ordenId, 'cfdi_adjunto', $usuarioId, null, ['gasto_id' => $gastoId, 'documento_id' => $documentoId, 'uuid' => $uuid]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[CompraContable::sincronizarCfdi] ' . $excepcion->getMessage());
            self::$errores[] = 'El documento quedó adjunto, pero no fue posible reflejar el CFDI en la compra.';
            return false;
        }
    }

    /**
     * Cambia el estado documental a mano: «sin factura» o «pendiente de CFDI». «Con CFDI» solo lo da un XML con timbre.
     */
    public static function definirEstadoCfdi(int $ordenId, string $estado, int $usuarioId): bool
    {
        self::$errores = [];
        if (!in_array($estado, ['pendiente_cfdi', 'no_facturada'], true)) {
            self::$errores[] = 'Solo se puede marcar «CFDI pendiente» o «Sin factura»; «Con CFDI» se obtiene al adjuntar el XML.';
            return false;
        }

        $gasto = self::gastoDe($ordenId);
        if ($gasto) {
            $cfdi = array_filter(Archivos::deOperacion('gasto', (int) $gasto['id'], false), static fn ($d) => $d['estado_validacion'] === 'cfdi_con_timbre');
            if ($cfdi) {
                self::$errores[] = 'La compra ya tiene un CFDI adjunto; no se puede cambiar a «' . self::ESTADOS_CFDI[$estado]['etiqueta'] . '».';
                return false;
            }
        }

        $consulta = self::$db->prepare('UPDATE ordenes_compra SET estado_cfdi = ? WHERE id = ?');
        $consulta->bind_param('si', $estado, $ordenId);
        $consulta->execute();
        $consulta->close();
        Auditoria::registrar('orden_compra', $ordenId, 'estado_cfdi', $usuarioId, null, ['estado' => $estado]);

        return true;
    }

    /** Documentos adjuntos de la compra (los del gasto). @return array<int, array<string, mixed>> */
    public static function documentos(int $ordenId): array
    {
        $gasto = self::gastoDe($ordenId);
        return $gasto ? Archivos::deOperacion('gasto', (int) $gasto['id'], true) : [];
    }
}
