<?php

namespace App;

/**
 * Contabilidad — ingresos (generados desde ventas), cobros y resumen del periodo.
 *
 * Reglas:
 *   • Cada venta tiene UN ingreso (ingresos.venta_id es único), creado y
 *     actualizado automáticamente por Ventas dentro de la misma transacción.
 *   • Nada contable se borra: lo que se cancela queda como «cancelado».
 *   • Un periodo cerrado no se modifica en silencio: sincronizarIngreso(),
 *     la facturación y los cobros devuelven error si la fecha cae en un
 *     periodo cerrado (se reabre con el procedimiento autorizado).
 *   • El estado de facturación es un dato EXPLÍCITO: sin_clasificar,
 *     facturada o no_facturada. Que haya o no un PDF adjunto no lo decide.
 *   • El IVA/ISR no se calculan aquí (motor fiscal en otra etapa): solo se
 *     consolidan importes registrados.
 *
 * Los importes se suman en centavos enteros para evitar errores de coma flotante.
 */
class Contabilidad
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Avisos no bloqueantes (p. ej. «la factura difiere de la venta»); se drenan con getAvisos(). */
    protected static $avisos = [];

    /** Id del último cobro registrado (antes de la auditoría, que también inserta). */
    public static int $ultimoId = 0;

    public const FACTURACION = [
        // 'sin_clasificar' es la clave interna histórica de «Pendiente de facturar».
        'sin_clasificar' => ['etiqueta' => 'Pendiente de facturar', 'clase' => 'badge--pendiente'],
        'facturada'      => ['etiqueta' => 'Facturada',             'clase' => 'badge--aprobado'],
        'no_facturada'   => ['etiqueta' => 'No facturada',          'clase' => 'badge--neutro'],
    ];

    public const METODOS_PAGO = ['Efectivo', 'Transferencia', 'Cheque', 'Tarjeta', 'Otro'];

    /**
     * Tipo de comprobante del CFDI (c_TipoDeComprobante).
     *
     * Es el ÚNICO catálogo del «tipo de CFDI»: lo comparten el formulario de
     * gasto y la facturación de una venta, para no repetir la lista en dos
     * clases y que las dos se desincronicen (el error que ya se pagó con las
     * reglas de categoría del gasto).
     */
    public const TIPOS_CFDI = [
        'I' => 'I · Ingreso (factura normal)',
        'E' => 'E · Egreso (nota de crédito)',
        'N' => 'N · Nómina',
        'P' => 'P · Pago (complemento de pagos)',
        'T' => 'T · Traslado',
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

    /** Devuelve los avisos pendientes y los limpia (sólo se leen una vez). @return string[] */
    public static function getAvisos(): array
    {
        $avisos = self::$avisos;
        self::$avisos = [];
        return $avisos;
    }

    /** Pesos → centavos enteros. */
    public static function centavos($importe): int
    {
        return (int) round(((float) $importe) * 100);
    }

    /* --------------------------------------------------------------------
     * CONTRIBUYENTE Y PERIODOS
     * ------------------------------------------------------------------ */

    /** Contribuyente al que pertenece una empresa (o el principal si no tiene). */
    public static function contribuyenteDeEmpresa(int $empresaId): int
    {
        $consulta = self::$db->prepare('SELECT contribuyente_id FROM empresas WHERE id = ?');
        $consulta->bind_param('i', $empresaId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return (int) ($fila['contribuyente_id'] ?? 0) ?: self::contribuyentePrincipal();
    }

    public static function contribuyentePrincipal(): int
    {
        return (int) self::$db->query('SELECT MIN(id) FROM contribuyentes WHERE activo = 1')->fetch_row()[0];
    }

    /**
     * ¿El RFC es de este negocio? (contribuyentes y empresas del sistema, con la
     * misma normalización que usa la validación de ventas).
     *
     * Permite distinguir de qué lado está un complemento de pago (FASE 6):
     * si el EMISOR soy yo → es un cobro a cliente (Ventas); si el RECEPTOR soy
     * yo → es un pago a proveedor (Compras). Se cachea por petición: los RFC
     * no cambian dentro de una misma operación.
     */
    public static function esRfcPropio(string $rfc): bool
    {
        static $propios = null;

        if ($propios === null) {
            $propios = [];
            $filas = self::$db->query('SELECT rfc FROM contribuyentes UNION SELECT rfc FROM empresas');
            while ($fila = $filas->fetch_assoc()) {
                $normal = VentasFiscal::normalizarRfc((string) ($fila['rfc'] ?? ''));
                if ($normal !== '') {
                    $propios[$normal] = true;
                }
            }
        }

        $consultado = VentasFiscal::normalizarRfc($rfc);
        return $consultado !== '' && isset($propios[$consultado]);
    }

    /** @return array<string, mixed>|null */
    public static function contribuyente(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM contribuyentes WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** ¿El mes de $fecha está cerrado para el contribuyente? */
    public static function periodoCerrado(int $contribuyenteId, string $fecha): bool
    {
        $anio = (int) substr($fecha, 0, 4);
        $mes  = (int) substr($fecha, 5, 2);

        $consulta = self::$db->prepare(
            "SELECT 1 FROM periodos_contables
              WHERE contribuyente_id = ? AND anio = ? AND mes = ? AND estado = 'cerrado'"
        );
        $consulta->bind_param('iii', $contribuyenteId, $anio, $mes);
        $consulta->execute();
        $cerrado = $consulta->get_result()->num_rows > 0;
        $consulta->close();

        return $cerrado;
    }

    /** Mensaje estándar de periodo cerrado. */
    public static function mensajePeriodoCerrado(string $fecha): string
    {
        return 'El periodo ' . substr($fecha, 0, 7) . ' está cerrado: reábrelo con el procedimiento autorizado antes de modificarlo.';
    }

    /**
     * Periodos cerrados (historial).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function periodosCerrados(): array
    {
        return self::$db->query(
            "SELECT p.*, u.nombre AS cerrado_por_nombre FROM periodos_contables p
               LEFT JOIN usuarios u ON u.id = p.cerrado_por
              WHERE p.estado = 'cerrado' ORDER BY p.anio DESC, p.mes DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    /* --------------------------------------------------------------------
     * INGRESOS (uno por venta; los llama Ventas dentro de su transacción)
     * ------------------------------------------------------------------ */

    /**
     * Crea o actualiza el ingreso de una venta. NO abre transacción.
     *
     * @return bool false si el periodo está cerrado y el cambio alteraría un
     *              registro cerrado (el llamador debe revertir su transacción).
     */
    public static function sincronizarIngreso(int $ventaId, ?int $usuarioId = null): bool
    {
        $consulta = self::$db->prepare(
            'SELECT v.*, e.contribuyente_id FROM ventas v JOIN empresas e ON e.id = v.empresa_id WHERE v.id = ?'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $venta = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$venta) {
            return true;
        }

        $contribuyente = (int) $venta['contribuyente_id'] ?: self::contribuyentePrincipal();
        $estado = $venta['estado'] === 'registrada' ? 'vigente' : 'cancelado';

        $consulta = self::$db->prepare('SELECT * FROM ingresos WHERE venta_id = ? FOR UPDATE');
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $ingreso = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        $concepto = 'Venta ' . $venta['folio'];

        if (!$ingreso) {
            if (self::periodoCerrado($contribuyente, $venta['fecha'])) {
                self::$errores[] = self::mensajePeriodoCerrado($venta['fecha']);
                return false;
            }

            $crear = self::$db->prepare(
                'INSERT INTO ingresos (venta_id, contribuyente_id, empresa_id, cliente_id, fecha, folio, concepto,
                                       subtotal, descuento, iva, total, estado, estado_facturacion, facturacion_origen)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "regla_iva")'
            );
            $inicial = self::facturacionInicial($venta);
            $crear->bind_param(
                'iiiisssssssss',
                $ventaId, $contribuyente, $venta['empresa_id'], $venta['cliente_id'], $venta['fecha'], $venta['folio'],
                $concepto, $venta['subtotal'], $venta['descuento'], $venta['iva'], $venta['total'], $estado, $inicial
            );
            $crear->execute();
            $id = (int) self::$db->insert_id;
            $crear->close();

            Auditoria::registrar('ingreso', $id, 'crear', $usuarioId, null, ['venta' => $venta['folio'], 'total' => $venta['total']]);
            Auditoria::registrar('ingreso', $id, 'facturacion', $usuarioId, 'Clasificación inicial por la regla de IVA', [
                'antes' => null, 'despues' => $inicial, 'origen' => 'regla_iva', 'aplica_iva' => (int) self::llevaIva($venta),
            ]);
            return true;
        }

        $cambios = [];
        foreach (['fecha', 'empresa_id', 'cliente_id'] as $campo) {
            if ((string) $ingreso[$campo] !== (string) $venta[$campo]) {
                $cambios[$campo] = [$ingreso[$campo], $venta[$campo]];
            }
        }

        /* Montos fijados por la factura emitida: una venta YA facturada no los
         * pisa desde aquí (la corrección se hace sustituyendo el CFDI desde el
         * seguimiento de la venta). Si factura y venta quedaron con importes
         * distintos, no se bloquea nada: se encola un aviso visible. */
        $conFactura = !empty($ingreso['factura_documento_id'])
            || ((string) $ingreso['estado_facturacion'] === 'facturada' && trim((string) ($ingreso['uuid_fiscal'] ?? '')) !== '');

        $montosDivergen = false;
        foreach (['subtotal', 'descuento', 'iva', 'total'] as $campo) {
            if (self::centavos($ingreso[$campo]) === self::centavos($venta[$campo])) {
                continue;
            }
            $montosDivergen = true;
            if (!$conFactura) {
                $cambios[$campo] = [$ingreso[$campo], $venta[$campo]];
            }
        }
        if ($conFactura && $montosDivergen && $estado === 'vigente' && $ingreso['estado'] === 'vigente') {
            self::$avisos[] = 'La factura de la venta ' . $venta['folio'] . ' quedó con importes distintos a los de la venta: '
                . 'facturado $' . number_format((float) $ingreso['total'], 2)
                . ' · venta $' . number_format((float) $venta['total'], 2)
                . '. El ingreso conserva los de la factura: para cambiarlos, sustituye el CFDI desde el seguimiento de la venta.';
        }
        if ($ingreso['estado'] !== $estado) {
            $cambios['estado'] = [$ingreso['estado'], $estado];
        }

        // Mientras nadie haya fijado la facturación a mano, la clasificación inicial sigue al IVA de la venta.
        $reclasificar = null;
        if ($ingreso['facturacion_origen'] === 'regla_iva' && $estado === 'vigente') {
            $nueva = self::facturacionInicial($venta);
            if ($nueva !== $ingreso['estado_facturacion']) {
                $reclasificar = $nueva;
                $cambios['estado_facturacion'] = [$ingreso['estado_facturacion'], $nueva];
            }
        }

        if (!$cambios) {
            return true;
        }

        // Un cambio toca el periodo viejo y el nuevo (si cambió la fecha).
        foreach (array_unique([$ingreso['fecha'], $venta['fecha']]) as $fecha) {
            if (self::periodoCerrado((int) $ingreso['contribuyente_id'], $fecha)) {
                self::$errores[] = self::mensajePeriodoCerrado($fecha);
                return false;
            }
        }

        $id = (int) $ingreso['id'];
        $camposMontos = $conFactura ? '' : ', subtotal = ?, descuento = ?, iva = ?, total = ?';
        $actualizar = self::$db->prepare(
            'UPDATE ingresos SET contribuyente_id = ?, empresa_id = ?, cliente_id = ?, fecha = ?, folio = ?, concepto = ?'
            . $camposMontos . ', estado = ? WHERE id = ?'
        );
        $parametros = [
            $contribuyente, $venta['empresa_id'], $venta['cliente_id'], $venta['fecha'], $venta['folio'], $concepto,
        ];
        $tipos = 'iiisss';
        if (!$conFactura) {
            $parametros[] = $venta['subtotal'];
            $parametros[] = $venta['descuento'];
            $parametros[] = $venta['iva'];
            $parametros[] = $venta['total'];
            $tipos .= 'ssss';
        }
        $parametros[] = $estado;
        $parametros[] = $id;
        $tipos .= 'si';
        $actualizar->bind_param($tipos, ...$parametros);
        $actualizar->execute();
        $actualizar->close();

        if ($reclasificar !== null) {
            $fijar = self::$db->prepare('UPDATE ingresos SET estado_facturacion = ? WHERE id = ?');
            $fijar->bind_param('si', $reclasificar, $id);
            $fijar->execute();
            $fijar->close();
            Auditoria::registrar('ingreso', $id, 'facturacion', $usuarioId, 'Se reclasificó porque cambió el IVA de la venta', [
                'antes' => $ingreso['estado_facturacion'], 'despues' => $reclasificar, 'origen' => 'regla_iva',
            ]);
        }

        Auditoria::registrar('ingreso', $id, 'actualizar', $usuarioId, 'Cambio en la venta ' . $venta['folio'], $cambios);
        return true;
    }

    /** Ingreso (y su facturación) de una venta; null si aún no existe. @return array<string, mixed>|null */
    public static function ingresoDeVenta(int $ventaId): ?array
    {
        $consulta = self::$db->prepare('SELECT id FROM ingresos WHERE venta_id = ?');
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? self::buscarIngreso((int) $fila['id']) : null;
    }

    /**
     * Ruta interna del detalle de un ingreso: todo lo que se puede indicar (facturación, CFDI y
     * cobros) vive en el detalle de su venta, así que ahí es donde se abre. El detalle del ingreso
     * sólo queda para los registros legados sin venta asociada (y de ahí redirige).
     *
     * @param string $ancla  Ancla opcional (#facturacion, #cobros, #documentos).
     */
    public static function rutaDetalle(array $ingreso, string $ancla = ''): string
    {
        $ruta = !empty($ingreso['venta_id'])
            ? '/admin/ventas/detalle?id=' . (int) $ingreso['venta_id']
            : '/admin/contabilidad/ingresos/detalle?id=' . (int) $ingreso['id'];

        return $ruta . ($ancla !== '' ? '#' . $ancla : '');
    }

    /** ¿La venta lleva IVA? (casilla de IVA activada o IVA con importe). */
    public static function llevaIva(array $venta): bool
    {
        return (int) ($venta['aplica_iva'] ?? 0) === 1 || self::centavos($venta['iva'] ?? 0) > 0;
    }

    /**
     * Forma de pago sugerida al registrar un cobro: con IVA → transferencia; sin IVA → efectivo.
     * Es solo un valor predeterminado: la forma real se elige al cobrar y no cambia el estado de cobro.
     */
    public static function metodoSugerido(array $venta): string
    {
        return self::llevaIva($venta) ? 'Transferencia' : 'Efectivo';
    }

    /**
     * Clasificación comercial inicial: con IVA → pendiente de facturar; sin IVA → no facturada.
     * Es solo un punto de partida; no prueba que exista (ni que falte) un CFDI.
     */
    public static function facturacionInicial(array $venta): string
    {
        return self::llevaIva($venta) ? 'sin_clasificar' : 'no_facturada';
    }

    /**
     * CFDI adjunto al ingreso que respalda una factura: activo y con timbre (revisión documental, no ante el SAT).
     *
     * @return array<string, mixed>|null
     */
    public static function cfdiDeIngreso(int $ingresoId): ?array
    {
        $consulta = self::$db->prepare(
            "SELECT id, nombre_original, uuid_fiscal FROM documentos
              WHERE entidad = 'ingreso' AND entidad_id = ? AND activo = 1 AND tipo IN ('xml', 'factura_pdf')
                AND estado_validacion = 'cfdi_con_timbre' AND uuid_fiscal IS NOT NULL
              ORDER BY id DESC LIMIT 1"
        );
        $consulta->bind_param('i', $ingresoId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /**
     * Historial de cambios de facturación de un ingreso (más reciente primero).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function historialFacturacion(int $ingresoId): array
    {
        $consulta = self::$db->prepare(
            "SELECT a.creado_en, a.motivo, a.detalle, u.nombre AS usuario_nombre
               FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.entidad = 'ingreso' AND a.entidad_id = ? AND a.accion = 'facturacion'
              ORDER BY a.id DESC"
        );
        $consulta->bind_param('i', $ingresoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        foreach ($filas as &$fila) {
            $fila['detalle'] = json_decode((string) $fila['detalle'], true) ?: [];
        }

        return $filas;
    }

    /** @return array<string, mixed>|null */
    public static function buscarIngreso(int $id): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT i.*, e.nombre AS empresa_nombre, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    cl.rfc AS cliente_rfc, v.cotizacion_id
               FROM ingresos i
               JOIN empresas e  ON e.id  = i.empresa_id
               JOIN clientes cl ON cl.id = i.cliente_id
               LEFT JOIN ventas v ON v.id = i.venta_id
              WHERE i.id = ?'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? self::conCobro($fila) : null;
    }

    /** Agrega cobrado, saldo y estado de cobro a un ingreso. */
    private static function conCobro(array $ingreso): array
    {
        $consulta = self::$db->prepare(
            "SELECT COALESCE(SUM(ROUND(importe * 100)), 0) AS c FROM cobros WHERE ingreso_id = ? AND estado = 'vigente'"
        );
        $id = (int) $ingreso['id'];
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $cobrado = (int) $consulta->get_result()->fetch_assoc()['c'];
        $consulta->close();

        $total = self::centavos($ingreso['total']);
        $ingreso['cobrado'] = $cobrado / 100.0;
        $ingreso['saldo']   = ($total - $cobrado) / 100.0;
        $ingreso['estado_cobro'] = $ingreso['estado'] === 'cancelado' ? 'cancelado'
            : ($cobrado <= 0 ? 'sin_cobro' : ($cobrado >= $total ? 'cobrado' : 'parcial'));

        return $ingreso;
    }

    /** Etiquetas del estado de cobro. */
    public static function etiquetaCobro(string $estado): array
    {
        return [
            'sin_cobro' => ['etiqueta' => 'Sin pagar', 'clase' => 'badge--pendiente'],
            'parcial'   => ['etiqueta' => 'Pago parcial', 'clase' => 'badge--info'],
            'cobrado'   => ['etiqueta' => 'Pagada', 'clase' => 'badge--aprobado'],
            'cancelado' => ['etiqueta' => 'Cancelada', 'clase' => 'badge--error'],
        ][$estado] ?? ['etiqueta' => $estado, 'clase' => 'badge--neutro'];
    }

    /**
     * Facturación explícita de un ingreso.
     *
     * @param string $estado sin_clasificar | facturada | no_facturada
     */
    public static function actualizarFacturacion(
        int $ingresoId,
        string $estado,
        string $folio,
        string $uuid,
        string $fechaFactura,
        int $usuarioId,
        bool $confirmaManual = false
    ): bool {
        self::$errores = [];
        $ingreso = self::buscarIngreso($ingresoId);

        if (!$ingreso) {
            self::$errores[] = 'El ingreso ya no existe.';
            return false;
        }
        if (!isset(self::FACTURACION[$estado])) {
            self::$errores[] = 'Estado de facturación no reconocido.';
            return false;
        }
        if ($ingreso['estado'] === 'cancelado') {
            self::$errores[] = 'Un ingreso cancelado no se puede reclasificar.';
            return false;
        }
        if (self::periodoCerrado((int) $ingreso['contribuyente_id'], $ingreso['fecha'])) {
            self::$errores[] = self::mensajePeriodoCerrado($ingreso['fecha']);
            return false;
        }

        $uuid = strtoupper(trim($uuid));
        if ($uuid !== '' && !preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
            self::$errores[] = 'El folio fiscal (UUID) no tiene el formato correcto.';
            return false;
        }
        if ($estado !== 'facturada' && ($uuid !== '' || trim($folio) !== '')) {
            self::$errores[] = 'El folio y el UUID de factura solo aplican a una venta marcada como facturada.';
            return false;
        }
        if ($fechaFactura !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFactura) || !strtotime($fechaFactura))) {
            self::$errores[] = 'La fecha de facturación no es válida.';
            return false;
        }

        if ($uuid !== '') {
            $consulta = self::$db->prepare('SELECT folio FROM ingresos WHERE uuid_fiscal = ? AND id <> ? LIMIT 1');
            $consulta->bind_param('si', $uuid, $ingresoId);
            $consulta->execute();
            $otro = $consulta->get_result()->fetch_assoc();
            $consulta->close();
            if ($otro) {
                self::$errores[] = 'Ese UUID ya está asignado a la venta ' . $otro['folio'] . '.';
                return false;
            }
        }

        // «Facturada» se respalda con un CFDI adjunto o con una confirmación manual explícita.
        $documentoId = null;
        $manual      = 0;
        if ($estado === 'facturada') {
            $cfdi = self::cfdiDeIngreso($ingresoId);
            if ($cfdi) {
                $documentoId = (int) $cfdi['id'];
                if ($uuid === '') {
                    $uuid = (string) $cfdi['uuid_fiscal'];
                } elseif ($uuid !== strtoupper((string) $cfdi['uuid_fiscal'])) {
                    self::$errores[] = 'El UUID no coincide con el del CFDI adjunto (' . $cfdi['uuid_fiscal'] . ').';
                    return false;
                }
            } elseif (!$confirmaManual) {
                self::$errores[] = 'No hay un CFDI adjunto que respalde la factura. Adjunta el XML o confirma de forma manual que ya se emitió.';
                return false;
            } else {
                $manual = 1;
            }
        }

        $folio = trim($folio) ?: null;
        $uuid  = $uuid ?: null;
        $fechaFactura = $fechaFactura ?: null;

        $consulta = self::$db->prepare(
            'UPDATE ingresos SET estado_facturacion = ?, factura_folio = ?, uuid_fiscal = ?, fecha_facturacion = ?,
                    facturacion_origen = "usuario", facturacion_manual = ?, factura_documento_id = ?,
                    facturacion_usuario_id = ?, facturacion_en = NOW()
              WHERE id = ?'
        );
        $consulta->bind_param('ssssiiii', $estado, $folio, $uuid, $fechaFactura, $manual, $documentoId, $usuarioId, $ingresoId);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('ingreso', $ingresoId, 'facturacion', $usuarioId, $manual ? 'Confirmación manual: sin CFDI verificable' : null, [
            'antes' => $ingreso['estado_facturacion'], 'despues' => $estado, 'uuid' => $uuid, 'folio' => $folio,
            'origen' => 'usuario', 'manual' => (bool) $manual, 'documento_id' => $documentoId,
        ]);
        return true;
    }

    /* --------------------------------------------------------------------
     * COBROS
     * ------------------------------------------------------------------ */

    /**
     * Cobros de un ingreso, con el complemento de pago (si alguno lo respalda).
     * La columna extra sólo añade información: los estados de cobro y de
     * facturación siguen siendo independientes (FASE 6, requisito 2).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cobrosDeIngreso(int $ingresoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT c.*, u.nombre AS usuario_nombre, cp.uuid AS complemento_uuid, cp.fecha_pago AS complemento_fecha
               FROM cobros c
               LEFT JOIN usuarios u ON u.id = c.usuario_id
          LEFT JOIN complemento_cobros cc ON cc.cobro_id = c.id
          LEFT JOIN complemento_doctos cd ON cd.id = cc.complemento_docto_id
          LEFT JOIN complementos_pago cp ON cp.id = cd.complemento_id AND cp.estado = \'vigente\'
              WHERE c.ingreso_id = ? ORDER BY c.fecha, c.id'
        );
        $consulta->bind_param('i', $ingresoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Registra un cobro (parcial o total). Nunca cobra más que el saldo.
     *
     * @param bool $dentroDeTransaccion true cuando el llamador (p. ej.
     *        ComplementosVenta) ya abrió transacción: no abre ni confirma;
     *        devuelve false con el error para que él decida.
     */
    public static function registrarCobro(
        int $ingresoId,
        string $fecha,
        $importe,
        string $metodo,
        string $referencia,
        int $usuarioId,
        bool $dentroDeTransaccion = false
    ): bool {
        self::$errores = [];

        try {
            if (!$dentroDeTransaccion) {
                self::$db->begin_transaction();
            }

            $bloqueo = self::$db->prepare('SELECT id FROM ingresos WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $ingresoId);
            $bloqueo->execute();
            $bloqueo->close();

            $ingreso = self::buscarIngreso($ingresoId);
            if (!$ingreso || $ingreso['estado'] !== 'vigente') {
                if (!$dentroDeTransaccion) {
                    self::$db->rollBack();
                }
                self::$errores[] = 'Solo se registran cobros de un ingreso vigente.';
                return false;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollBack();
                }
                self::$errores[] = 'La fecha del cobro no es válida.';
                return false;
            }
            if (!is_numeric($importe) || self::centavos($importe) <= 0) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollBack();
                }
                self::$errores[] = 'El importe del cobro debe ser mayor a cero.';
                return false;
            }
            if (self::centavos($importe) > self::centavos($ingreso['saldo'])) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollBack();
                }
                self::$errores[] = 'El cobro excede el saldo pendiente (' . number_format((float) $ingreso['saldo'], 2) . ').';
                return false;
            }
            if (self::periodoCerrado((int) $ingreso['contribuyente_id'], $fecha)) {
                if (!$dentroDeTransaccion) {
                    self::$db->rollBack();
                }
                self::$errores[] = self::mensajePeriodoCerrado($fecha);
                return false;
            }

            $monto = number_format(self::centavos($importe) / 100, 2, '.', '');
            $metodo = trim($metodo) ?: null;
            $referencia = trim($referencia) ?: null;
            $crear = self::$db->prepare(
                'INSERT INTO cobros (ingreso_id, fecha, importe, metodo, referencia, usuario_id) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $crear->bind_param('issssi', $ingresoId, $fecha, $monto, $metodo, $referencia, $usuarioId);
            $crear->execute();
            $cobroId = (int) self::$db->insert_id;
            $crear->close();
            self::$ultimoId = $cobroId;   // antes de la auditoría: su INSERT pisa el insert_id.

            Auditoria::registrar('cobro', $cobroId, 'crear', $usuarioId, null, ['ingreso' => $ingresoId, 'importe' => $monto]);
            if (!$dentroDeTransaccion) {
                self::$db->commit();
            }
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            if (!$dentroDeTransaccion) {
                self::$db->rollBack();
            }
            error_log('[Contabilidad::registrarCobro] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar el cobro.';
            return false;
        }
    }

    /** Cancela un cobro (no se borra). Exige motivo. */
    public static function cancelarCobro(int $cobroId, string $motivo, int $usuarioId): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Escribe el motivo de la cancelación (mínimo 5 caracteres).';
            return false;
        }

        $consulta = self::$db->prepare(
            'SELECT c.*, i.contribuyente_id FROM cobros c JOIN ingresos i ON i.id = c.ingreso_id WHERE c.id = ?'
        );
        $consulta->bind_param('i', $cobroId);
        $consulta->execute();
        $cobro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$cobro || $cobro['estado'] !== 'vigente') {
            self::$errores[] = 'El cobro no existe o ya estaba cancelado.';
            return false;
        }
        if (self::periodoCerrado((int) $cobro['contribuyente_id'], $cobro['fecha'])) {
            self::$errores[] = self::mensajePeriodoCerrado($cobro['fecha']);
            return false;
        }

        $cancelar = self::$db->prepare("UPDATE cobros SET estado = 'cancelado', motivo_cancelacion = ? WHERE id = ?");
        $cancelar->bind_param('si', $motivo, $cobroId);
        $cancelar->execute();
        $cancelar->close();

        Auditoria::registrar('cobro', $cobroId, 'cancelar', $usuarioId, $motivo, ['importe' => $cobro['importe']]);
        return true;
    }

    /** Cancela todos los cobros vigentes de un ingreso (todo o nada). */
    public static function cancelarCobrosDeIngreso(int $ingresoId, string $motivo, int $usuarioId): bool
    {
        self::$errores = [];
        $vigentes = array_filter(self::cobrosDeIngreso($ingresoId), static fn ($c) => $c['estado'] === 'vigente');
        if (!$vigentes) {
            self::$errores[] = 'Este ingreso no tiene cobros vigentes.';
            return false;
        }

        self::$db->begin_transaction();
        foreach ($vigentes as $c) {
            if (!self::cancelarCobro((int) $c['id'], $motivo, $usuarioId)) {
                self::$db->rollBack();
                return false;
            }
        }
        self::$db->commit();

        return true;
    }

    /* --------------------------------------------------------------------
     * LISTADOS
     * ------------------------------------------------------------------ */

    /**
     * Condiciones de los listados de ingresos. `facturacion_grupo` separa las dos
     * tablas de «Ventas y cobros»: 'con_factura' (facturadas y pendientes de
     * facturar) y 'sin_factura' (las marcadas como «No facturada»).
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condicionesIngresos(array $f): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];

        $anio = (int) ($f['anio'] ?? 0);
        $mes  = (int) ($f['mes'] ?? 0);
        if ($anio > 0) {
            $donde[] = 'YEAR(i.fecha) = ?';
            $tipos .= 'i';
            $valores[] = $anio;
        }
        if ($mes > 0) {
            $donde[] = 'MONTH(i.fecha) = ?';
            $tipos .= 'i';
            $valores[] = $mes;
        }
        if ((int) ($f['empresa'] ?? 0) > 0) {
            $donde[] = 'i.empresa_id = ?';
            $tipos .= 'i';
            $valores[] = (int) $f['empresa'];
        }
        if (isset(self::FACTURACION[$f['facturacion'] ?? ''])) {
            $donde[] = 'i.estado_facturacion = ?';
            $tipos .= 's';
            $valores[] = $f['facturacion'];
        }
        // Las dos tablas de «Ventas y cobros»: lo que se factura (o está por facturarse)
        // y lo que ya se marcó como «No facturada». Se puede combinar con el filtro de arriba.
        if (($f['facturacion_grupo'] ?? '') === 'sin_factura') {
            $donde[] = "i.estado_facturacion = 'no_facturada'";
        } elseif (($f['facturacion_grupo'] ?? '') === 'con_factura') {
            $donde[] = "i.estado_facturacion <> 'no_facturada'";
        }
        foreach (['desde' => 'i.fecha >= ?', 'hasta' => 'i.fecha <= ?'] as $campo => $sql) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$campo] ?? ''))) {
                $donde[] = $sql;
                $tipos .= 's';
                $valores[] = $f[$campo];
            }
        }
        if (($f['cobro'] ?? '') === 'pendiente') {
            $donde[] = "ROUND(i.total * 100) > COALESCE((SELECT SUM(ROUND(c.importe * 100)) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente'), 0)";
        }
        if (($f['estado'] ?? '') === 'cancelado') {
            $donde[] = "i.estado = 'cancelado'";
        } else {
            $donde[] = "i.estado = 'vigente'";
        }
        $termino = trim((string) ($f['q'] ?? ''));
        if ($termino !== '') {
            $donde[] = '(i.folio LIKE ? OR cl.nombre LIKE ? OR IFNULL(cl.nombre_empresa, "") LIKE ? OR IFNULL(i.uuid_fiscal, "") LIKE ?)';
            $tipos .= 'ssss';
            $patron = '%' . $termino . '%';
            array_push($valores, $patron, $patron, $patron, $patron);
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /** @return array<int, array<string, mixed>> */
    public static function listarIngresos(array $filtros, int $limite = 15, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condicionesIngresos($filtros);

        $consulta = self::$db->prepare(
            "SELECT i.*, e.nombre AS empresa_nombre, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa, cl.alias AS cliente_alias,
                    v.total AS venta_total,
                    (SELECT COALESCE(SUM(ROUND(c.importe * 100)), 0) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente') AS cobrado_centavos,
                    (SELECT COUNT(*) FROM documentos d WHERE d.entidad = 'ingreso' AND d.entidad_id = i.id AND d.activo = 1) AS documentos
               FROM ingresos i
               JOIN empresas e  ON e.id  = i.empresa_id
               JOIN clientes cl ON cl.id = i.cliente_id
               LEFT JOIN ventas v ON v.id = i.venta_id
              WHERE $donde ORDER BY i.fecha DESC, i.id DESC LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        foreach ($filas as &$fila) {
            $total = self::centavos($fila['total']);
            $cobrado = (int) $fila['cobrado_centavos'];
            $fila['cobrado'] = $cobrado / 100.0;
            $fila['saldo'] = ($total - $cobrado) / 100.0;
            $fila['estado_cobro'] = $fila['estado'] === 'cancelado' ? 'cancelado'
                : ($cobrado <= 0 ? 'sin_cobro' : ($cobrado >= $total ? 'cobrado' : 'parcial'));
        }
        unset($fila);

        return $filas;
    }

    public static function contarIngresos(array $filtros): int
    {
        [$donde, $tipos, $valores] = self::condicionesIngresos($filtros);
        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS n FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id WHERE $donde"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $n;
    }

    /** Grupos de la vista de documentos fiscales (cada venta cae en uno solo). */
    public const GRUPOS_FISCALES = [
        'pendientes' => ['etiqueta' => 'Ventas pendientes de facturar', 'clase' => 'badge--pendiente'],
        'emitidos'   => ['etiqueta' => 'CFDI emitidos',                 'clase' => 'badge--aprobado'],
        'cancelados' => ['etiqueta' => 'CFDI cancelados',               'clase' => 'badge--error'],
        'por_validar' => ['etiqueta' => 'Documentos por validar',       'clase' => 'badge--advertencia'],
        'sin_factura' => ['etiqueta' => 'Ventas sin facturación',       'clase' => 'badge--neutro'],
    ];

    /**
     * Reparte las ventas del periodo en los cinco grupos fiscales. Cada venta aparece una sola vez:
     *  • emitidos     facturada con CFDI con timbre vinculado y sin alertas (fecha = fecha de la factura);
     *  • por_validar  facturada por confirmación manual, o con CFDI sin timbre/con alertas;
     *  • cancelados   ventas canceladas que ya tenían factura (cancelación interna: no se verifica ante el SAT);
     *  • pendientes   con IVA, sin CFDI vinculado;  • sin_factura  sin IVA.
     *
     * @return array{grupos: array<string, array<int, array<string, mixed>>>, totales: array<string, array{operaciones: int, total: float}>}
     */
    public static function documentosFiscales(int $anio, int $mes, int $empresaId = 0, int $clienteId = 0): array
    {
        $donde = ['YEAR(COALESCE(i.fecha_facturacion, i.fecha)) = ?'];
        $tipos = 'i';
        $valores = [$anio];
        foreach ([[$mes, 'MONTH(COALESCE(i.fecha_facturacion, i.fecha)) = ?'], [$empresaId, 'i.empresa_id = ?'], [$clienteId, 'i.cliente_id = ?']] as [$v, $sql]) {
            if ($v > 0) {
                $donde[] = $sql;
                $tipos .= 'i';
                $valores[] = $v;
            }
        }

        $consulta = self::$db->prepare(
            "SELECT i.*, e.nombre AS empresa_nombre, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    d.estado_validacion AS cfdi_validacion,
                    JSON_LENGTH(COALESCE(JSON_EXTRACT(d.datos_cfdi, '$.alertas'), JSON_ARRAY())) AS cfdi_alertas
               FROM ingresos i JOIN empresas e ON e.id = i.empresa_id JOIN clientes cl ON cl.id = i.cliente_id
               LEFT JOIN documentos d ON d.id = i.factura_documento_id AND d.activo = 1
              WHERE " . implode(' AND ', $donde) . ' ORDER BY COALESCE(i.fecha_facturacion, i.fecha), i.id'
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $grupos = array_fill_keys(array_keys(self::GRUPOS_FISCALES), []);
        $centavos = array_fill_keys(array_keys(self::GRUPOS_FISCALES), 0);
        foreach ($filas as $f) {
            $facturada = $f['estado_facturacion'] === 'facturada';
            if ($f['estado'] === 'cancelado') {
                if (!$facturada) {
                    continue; // una venta cancelada sin factura no es un documento fiscal
                }
                $g = 'cancelados';
                $f['nota'] = 'Cancelada en el sistema; la cancelación ante el SAT no se verifica.';
            } elseif ($facturada) {
                $respaldado = !(int) $f['facturacion_manual'] && $f['cfdi_validacion'] === 'cfdi_con_timbre' && (int) $f['cfdi_alertas'] === 0;
                $g = $respaldado ? 'emitidos' : 'por_validar';
                $f['nota'] = $respaldado ? '' : ((int) $f['facturacion_manual'] ? 'Confirmada de forma manual, sin CFDI verificable.' : 'El CFDI vinculado tiene alertas o no trae timbre.');
            } else {
                $g = $f['estado_facturacion'] === 'no_facturada' ? 'sin_factura' : 'pendientes';
                $f['nota'] = '';
            }
            $f['fecha_fiscal'] = $g === 'emitidos' || $g === 'por_validar' || $g === 'cancelados' ? ($f['fecha_facturacion'] ?: $f['fecha']) : $f['fecha'];
            $grupos[$g][] = $f;
            $centavos[$g] += self::centavos($f['total']);
        }

        $totales = [];
        foreach ($grupos as $g => $lista) {
            $totales[$g] = ['operaciones' => count($lista), 'total' => $centavos[$g] / 100.0];
        }
        return ['grupos' => $grupos, 'totales' => $totales];
    }

    /**
     * Totales de los ingresos que cumplen los filtros (sin paginar), en pesos.
     *
     * @return array{n: int, subtotal: float, iva: float, total: float, cobrado: float, saldo: float}
     */
    public static function totalesIngresos(array $filtros): array
    {
        [$donde, $tipos, $valores] = self::condicionesIngresos($filtros);
        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(ROUND(i.subtotal * 100) - ROUND(i.descuento * 100)), 0) AS subtotal,
                    COALESCE(SUM(ROUND(i.iva * 100)), 0) AS iva, COALESCE(SUM(ROUND(i.total * 100)), 0) AS total,
                    COALESCE(SUM((SELECT COALESCE(SUM(ROUND(c.importe * 100)), 0) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente')), 0) AS cobrado
               FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id WHERE $donde"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $f = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return [
            'n' => (int) $f['n'], 'subtotal' => $f['subtotal'] / 100, 'iva' => $f['iva'] / 100, 'total' => $f['total'] / 100,
            'cobrado' => $f['cobrado'] / 100, 'saldo' => ($f['total'] - $f['cobrado']) / 100,
        ];
    }

    /** Mes inmediato anterior: [año, mes]. */
    public static function mesAnterior(int $anio, int $mes): array
    {
        return $mes <= 1 ? [$anio - 1, 12] : [$anio, $mes - 1];
    }

    /**
     * Variación porcentual contra el periodo anterior; null si no hay base para comparar.
     */
    public static function variacion(float $actual, float $anterior): ?float
    {
        return abs($anterior) < 0.005 ? null : round(($actual - $anterior) / abs($anterior) * 100, 1);
    }

    /**
     * Ventas que NO están marcadas como facturadas (sin clasificar y no
     * facturadas), con subtotales por estado y por negocio. Cada venta
     * aparece una sola vez.
     *
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, mixed>, por_negocio: array<int, array<string, mixed>>}
     */
    public static function sinFacturar(int $anio, int $mes, int $empresaId = 0, string $estadoFacturacion = '', int $clienteId = 0): array
    {
        $donde = ["i.estado = 'vigente'", "i.estado_facturacion <> 'facturada'", 'YEAR(i.fecha) = ?'];
        if (in_array($estadoFacturacion, ['sin_clasificar', 'no_facturada'], true)) {
            $donde[] = "i.estado_facturacion = '" . $estadoFacturacion . "'";
        }
        $tipos = 'i';
        $valores = [$anio];
        if ($mes > 0) {
            $donde[] = 'MONTH(i.fecha) = ?';
            $tipos .= 'i';
            $valores[] = $mes;
        }
        if ($empresaId > 0) {
            $donde[] = 'i.empresa_id = ?';
            $tipos .= 'i';
            $valores[] = $empresaId;
        }
        if ($clienteId > 0) {
            $donde[] = 'i.cliente_id = ?';
            $tipos .= 'i';
            $valores[] = $clienteId;
        }

        $consulta = self::$db->prepare(
            'SELECT i.*, e.nombre AS empresa_nombre, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa
               FROM ingresos i JOIN empresas e ON e.id = i.empresa_id JOIN clientes cl ON cl.id = i.cliente_id
              WHERE ' . implode(' AND ', $donde) . ' ORDER BY i.fecha, i.id'
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $t = ['operaciones' => 0, 'subtotal' => 0, 'descuento' => 0, 'iva' => 0, 'total' => 0, 'sin_clasificar' => 0, 'no_facturada' => 0];
        $negocios = [];
        foreach ($filas as $fila) {
            $base = self::centavos($fila['subtotal']) - self::centavos($fila['descuento']);
            $t['operaciones']++;
            $t['subtotal'] += $base;
            $t['descuento'] += self::centavos($fila['descuento']);
            $t['iva'] += self::centavos($fila['iva']);
            $t['total'] += self::centavos($fila['total']);
            $t[$fila['estado_facturacion']]++;

            $e = (int) $fila['empresa_id'];
            $negocios[$e] ??= ['nombre' => $fila['empresa_nombre'], 'operaciones' => 0, 'subtotal' => 0, 'iva' => 0, 'total' => 0];
            $negocios[$e]['operaciones']++;
            $negocios[$e]['subtotal'] += $base;
            $negocios[$e]['iva'] += self::centavos($fila['iva']);
            $negocios[$e]['total'] += self::centavos($fila['total']);
        }

        $aPesos = static function (array $arreglo): array {
            foreach (['subtotal', 'descuento', 'iva', 'total'] as $k) {
                if (isset($arreglo[$k])) {
                    $arreglo[$k] /= 100.0;
                }
            }
            return $arreglo;
        };

        return [
            'filas'       => $filas,
            'totales'     => $aPesos($t),
            'por_negocio' => array_map($aPesos, array_values($negocios)),
        ];
    }

    /* --------------------------------------------------------------------
     * RESUMEN DEL PERIODO
     * ------------------------------------------------------------------ */

    /**
     * Consolidado de un mes: ingresos, cobros, facturación y gastos, con el
     * detalle por negocio. Son importes REGISTRADOS, no estimaciones fiscales.
     *
     * @return array<string, mixed>
     */
    public static function resumenPeriodo(int $anio, int $mes, int $empresaId = 0): array
    {
        $filtroEmpresa = $empresaId > 0 ? ' AND i.empresa_id = ' . (int) $empresaId : '';

        $consulta = self::$db->prepare(
            "SELECT i.empresa_id, e.nombre AS empresa_nombre, i.estado_facturacion,
                    COUNT(*) AS operaciones,
                    SUM(ROUND(i.subtotal * 100)) AS subtotal, SUM(ROUND(i.descuento * 100)) AS descuento,
                    SUM(ROUND(i.iva * 100)) AS iva, SUM(ROUND(i.total * 100)) AS total
               FROM ingresos i JOIN empresas e ON e.id = i.empresa_id
              WHERE i.estado = 'vigente' AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? $filtroEmpresa
              GROUP BY i.empresa_id, e.nombre, i.estado_facturacion"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $r = [
            'anio' => $anio, 'mes' => $mes,
            'ventas' => ['operaciones' => 0, 'subtotal' => 0, 'descuento' => 0, 'iva' => 0, 'total' => 0],
            'facturacion' => [
                'facturada' => ['operaciones' => 0, 'total' => 0],
                'no_facturada' => ['operaciones' => 0, 'total' => 0],
                'sin_clasificar' => ['operaciones' => 0, 'total' => 0],
            ],
            'por_negocio' => [],
        ];
        foreach ($filas as $f) {
            $base = (int) $f['subtotal'] - (int) $f['descuento'];
            $r['ventas']['operaciones'] += (int) $f['operaciones'];
            $r['ventas']['subtotal'] += $base;
            $r['ventas']['descuento'] += (int) $f['descuento'];
            $r['ventas']['iva'] += (int) $f['iva'];
            $r['ventas']['total'] += (int) $f['total'];
            $r['facturacion'][$f['estado_facturacion']]['operaciones'] += (int) $f['operaciones'];
            $r['facturacion'][$f['estado_facturacion']]['total'] += (int) $f['total'];

            $e = (int) $f['empresa_id'];
            $r['por_negocio'][$e] ??= ['nombre' => $f['empresa_nombre'], 'ventas' => 0, 'iva_ventas' => 0, 'gastos' => 0, 'iva_gastos' => 0];
            $r['por_negocio'][$e]['ventas'] += $base;
            $r['por_negocio'][$e]['iva_ventas'] += (int) $f['iva'];
        }

        // Cobros recibidos en el mes (por fecha de cobro) de ingresos vigentes.
        $consulta = self::$db->prepare(
            "SELECT COALESCE(SUM(ROUND(c.importe * 100)), 0) AS cobrado
               FROM cobros c JOIN ingresos i ON i.id = c.ingreso_id
              WHERE c.estado = 'vigente' AND i.estado = 'vigente' AND YEAR(c.fecha) = ? AND MONTH(c.fecha) = ? $filtroEmpresa"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $r['cobrado_en_periodo'] = (int) $consulta->get_result()->fetch_assoc()['cobrado'];
        $consulta->close();

        // Saldo por cobrar de las ventas del mes.
        $consulta = self::$db->prepare(
            "SELECT COALESCE(SUM(ROUND(i.total * 100)), 0) - COALESCE(SUM(k.cobrado), 0) AS saldo
               FROM ingresos i
               LEFT JOIN (SELECT ingreso_id, SUM(ROUND(importe * 100)) AS cobrado FROM cobros WHERE estado = 'vigente' GROUP BY ingreso_id) k
                      ON k.ingreso_id = i.id
              WHERE i.estado = 'vigente' AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? $filtroEmpresa"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $r['por_cobrar'] = (int) $consulta->get_result()->fetch_assoc()['saldo'];
        $consulta->close();

        // Gastos: cada uno cuenta una sola vez; el reparto solo alimenta el detalle por negocio.
        $r['gastos'] = Gastos::resumenPeriodo($anio, $mes, $empresaId, $r['por_negocio']);
        $r['por_negocio'] = $r['gastos']['por_negocio'];
        unset($r['gastos']['por_negocio']);

        // Todo a pesos.
        foreach (['subtotal', 'descuento', 'iva', 'total'] as $k) {
            $r['ventas'][$k] /= 100.0;
        }
        foreach ($r['facturacion'] as &$x) {
            $x['total'] /= 100.0;
        }
        unset($x);
        $r['cobrado_en_periodo'] /= 100.0;
        $r['por_cobrar'] /= 100.0;
        foreach ($r['por_negocio'] as &$n) {
            foreach (['ventas', 'iva_ventas', 'gastos', 'iva_gastos'] as $k) {
                $n[$k] /= 100.0;
            }
        }
        unset($n);

        return $r;
    }

    /**
     * Lo que le falta hacer al usuario en el mes, en lenguaje llano (para la pantalla principal).
     *
     * @return array{por_cobrar: array{n: int, monto: float}, sin_indicar: int, gastos_por_pagar: array{n: int, monto: float}, gastos_sin_factura: int}
     */
    public static function pendientes(int $anio, int $mes, int $empresaId = 0): array
    {
        $filtro = $empresaId > 0 ? ' AND i.empresa_id = ' . (int) $empresaId : '';

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(ROUND(i.total * 100)) - SUM(COALESCE(k.cobrado, 0)), 0) AS saldo
               FROM ingresos i
               LEFT JOIN (SELECT ingreso_id, SUM(ROUND(importe * 100)) AS cobrado FROM cobros WHERE estado = 'vigente' GROUP BY ingreso_id) k ON k.ingreso_id = i.id
              WHERE i.estado = 'vigente' AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? $filtro
                AND ROUND(i.total * 100) > COALESCE(k.cobrado, 0)"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $porCobrar = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) FROM ingresos i WHERE i.estado = 'vigente' AND i.estado_facturacion = 'sin_clasificar'
                AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ? $filtro"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $sinIndicar = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        $consulta = self::$db->prepare(
            "SELECT COUNT(*), COALESCE(SUM(ROUND(total * 100)), 0) FROM gastos
              WHERE estado = 'vigente' AND estado_pago = 'pendiente' AND YEAR(fecha) = ? AND MONTH(fecha) = ?"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        [$nPagar, $montoPagar] = $consulta->get_result()->fetch_row();
        $consulta->close();

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) FROM gastos WHERE estado = 'vigente' AND comprobante_fiscal = 0 AND YEAR(fecha) = ? AND MONTH(fecha) = ?"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $sinFactura = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        return [
            'por_cobrar' => ['n' => (int) $porCobrar['n'], 'monto' => (float) $porCobrar['saldo'] / 100.0],
            'sin_indicar' => $sinIndicar,
            'gastos_por_pagar' => ['n' => (int) $nPagar, 'monto' => (float) $montoPagar / 100.0],
            'gastos_sin_factura' => $sinFactura,
        ];
    }

    /** Años con movimientos (ingresos o gastos), más el actual. */
    public static function aniosConMovimientos(): array
    {
        $anios = [(int) date('Y')];
        foreach (self::$db->query('SELECT DISTINCT YEAR(fecha) FROM ingresos UNION SELECT DISTINCT YEAR(fecha) FROM gastos')->fetch_all() as $f) {
            $anios[] = (int) $f[0];
        }
        $anios = array_values(array_unique($anios));
        rsort($anios);

        return $anios;
    }
}
