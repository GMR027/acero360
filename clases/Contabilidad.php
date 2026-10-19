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

    public const FACTURACION = [
        'sin_clasificar' => ['etiqueta' => 'Sin clasificar', 'clase' => 'badge--pendiente'],
        'facturada'      => ['etiqueta' => 'Facturada',      'clase' => 'badge--aprobado'],
        'no_facturada'   => ['etiqueta' => 'No facturada',   'clase' => 'badge--neutro'],
    ];

    public const METODOS_PAGO = ['Efectivo', 'Transferencia', 'Cheque', 'Tarjeta', 'Otro'];

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
                                       subtotal, descuento, iva, total, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $crear->bind_param(
                'iiiissssssss',
                $ventaId, $contribuyente, $venta['empresa_id'], $venta['cliente_id'], $venta['fecha'], $venta['folio'],
                $concepto, $venta['subtotal'], $venta['descuento'], $venta['iva'], $venta['total'], $estado
            );
            $crear->execute();
            $id = (int) self::$db->insert_id;
            $crear->close();

            Auditoria::registrar('ingreso', $id, 'crear', $usuarioId, null, ['venta' => $venta['folio'], 'total' => $venta['total']]);
            return true;
        }

        $cambios = [];
        foreach (['fecha', 'empresa_id', 'cliente_id'] as $campo) {
            if ((string) $ingreso[$campo] !== (string) $venta[$campo]) {
                $cambios[$campo] = [$ingreso[$campo], $venta[$campo]];
            }
        }
        foreach (['subtotal', 'descuento', 'iva', 'total'] as $campo) {
            if (self::centavos($ingreso[$campo]) !== self::centavos($venta[$campo])) {
                $cambios[$campo] = [$ingreso[$campo], $venta[$campo]];
            }
        }
        if ($ingreso['estado'] !== $estado) {
            $cambios['estado'] = [$ingreso['estado'], $estado];
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

        $actualizar = self::$db->prepare(
            'UPDATE ingresos SET contribuyente_id = ?, empresa_id = ?, cliente_id = ?, fecha = ?, folio = ?, concepto = ?,
                    subtotal = ?, descuento = ?, iva = ?, total = ?, estado = ? WHERE id = ?'
        );
        $id = (int) $ingreso['id'];
        $actualizar->bind_param(
            'iiissssssssi',
            $contribuyente, $venta['empresa_id'], $venta['cliente_id'], $venta['fecha'], $venta['folio'], $concepto,
            $venta['subtotal'], $venta['descuento'], $venta['iva'], $venta['total'], $estado, $id
        );
        $actualizar->execute();
        $actualizar->close();

        Auditoria::registrar('ingreso', $id, 'actualizar', $usuarioId, 'Cambio en la venta ' . $venta['folio'], $cambios);
        return true;
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
            'sin_cobro' => ['etiqueta' => 'Sin cobrar', 'clase' => 'badge--pendiente'],
            'parcial'   => ['etiqueta' => 'Cobro parcial', 'clase' => 'badge--info'],
            'cobrado'   => ['etiqueta' => 'Cobrada', 'clase' => 'badge--aprobado'],
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
        int $usuarioId
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

        $folio = trim($folio) ?: null;
        $uuid  = $uuid ?: null;
        $fechaFactura = $fechaFactura ?: null;

        $consulta = self::$db->prepare(
            'UPDATE ingresos SET estado_facturacion = ?, factura_folio = ?, uuid_fiscal = ?, fecha_facturacion = ? WHERE id = ?'
        );
        $consulta->bind_param('ssssi', $estado, $folio, $uuid, $fechaFactura, $ingresoId);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('ingreso', $ingresoId, 'facturacion', $usuarioId, null, [
            'antes' => $ingreso['estado_facturacion'], 'despues' => $estado, 'uuid' => $uuid,
        ]);
        return true;
    }

    /* --------------------------------------------------------------------
     * COBROS
     * ------------------------------------------------------------------ */

    /** @return array<int, array<string, mixed>> */
    public static function cobrosDeIngreso(int $ingresoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT c.*, u.nombre AS usuario_nombre FROM cobros c LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.ingreso_id = ? ORDER BY c.fecha, c.id'
        );
        $consulta->bind_param('i', $ingresoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Registra un cobro (parcial o total). Nunca cobra más que el saldo. */
    public static function registrarCobro(
        int $ingresoId,
        string $fecha,
        $importe,
        string $metodo,
        string $referencia,
        int $usuarioId
    ): bool {
        self::$errores = [];

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT id FROM ingresos WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $ingresoId);
            $bloqueo->execute();
            $bloqueo->close();

            $ingreso = self::buscarIngreso($ingresoId);
            if (!$ingreso || $ingreso['estado'] !== 'vigente') {
                self::$db->rollBack();
                self::$errores[] = 'Solo se registran cobros de un ingreso vigente.';
                return false;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
                self::$db->rollBack();
                self::$errores[] = 'La fecha del cobro no es válida.';
                return false;
            }
            if (!is_numeric($importe) || self::centavos($importe) <= 0) {
                self::$db->rollBack();
                self::$errores[] = 'El importe del cobro debe ser mayor a cero.';
                return false;
            }
            if (self::centavos($importe) > self::centavos($ingreso['saldo'])) {
                self::$db->rollBack();
                self::$errores[] = 'El cobro excede el saldo pendiente (' . number_format((float) $ingreso['saldo'], 2) . ').';
                return false;
            }
            if (self::periodoCerrado((int) $ingreso['contribuyente_id'], $fecha)) {
                self::$db->rollBack();
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

            Auditoria::registrar('cobro', $cobroId, 'crear', $usuarioId, null, ['ingreso' => $ingresoId, 'importe' => $monto]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
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

    /* --------------------------------------------------------------------
     * LISTADOS
     * ------------------------------------------------------------------ */

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
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
            "SELECT i.*, e.nombre AS empresa_nombre, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    (SELECT COALESCE(SUM(ROUND(c.importe * 100)), 0) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente') AS cobrado_centavos,
                    (SELECT COUNT(*) FROM documentos d WHERE d.entidad = 'ingreso' AND d.entidad_id = i.id AND d.activo = 1) AS documentos
               FROM ingresos i
               JOIN empresas e  ON e.id  = i.empresa_id
               JOIN clientes cl ON cl.id = i.cliente_id
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

    /**
     * Ventas que NO están marcadas como facturadas (sin clasificar y no
     * facturadas), con subtotales por estado y por negocio. Cada venta
     * aparece una sola vez.
     *
     * @return array{filas: array<int, array<string, mixed>>, totales: array<string, mixed>, por_negocio: array<int, array<string, mixed>>}
     */
    public static function sinFacturar(int $anio, int $mes, int $empresaId = 0): array
    {
        $donde = ["i.estado = 'vigente'", "i.estado_facturacion <> 'facturada'", 'YEAR(i.fecha) = ?'];
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
