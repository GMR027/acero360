<?php

namespace App;

/**
 * Gastos — egresos del contribuyente.
 *
 * Reglas:
 *   • Un gasto se registra UNA vez. Si lo comparten los dos negocios, el
 *     reparto (gasto_reparto) solo alimenta los reportes por negocio: el total
 *     contable consolidado nunca cambia ni se duplica.
 *   • Nunca se borra: se cancela con motivo (queda en la bitácora).
 *   • Un periodo cerrado no se modifica en silencio.
 *   • Tener un comprobante adjunto NO vuelve deducible un gasto ni acreditable
 *     su IVA: `revision_fiscal` arranca en «por_validar» y se resuelve a mano.
 *   • Importes en centavos enteros; IVA = subtotal × tasa, redondeado a centavos.
 */
class Gastos
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    public static int $ultimoId = 0;

    public const NATURALEZAS = [
        'gasto' => 'Gasto operativo', 'compra' => 'Compra de mercancía', 'insumo' => 'Insumos y consumibles',
        'servicio' => 'Servicio', 'activo' => 'Activo / equipo', 'impuesto' => 'Impuestos y derechos', 'otro' => 'Otro',
    ];

    public const REVISION = [
        'por_validar'  => ['etiqueta' => 'Por validar', 'clase' => 'badge--pendiente'],
        'validado'     => ['etiqueta' => 'Validado',    'clase' => 'badge--aprobado'],
        'no_deducible' => ['etiqueta' => 'No deducible', 'clase' => 'badge--neutro'],
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

    /** @return array{subtotal: string, iva: string, total: string} importes con 2 decimales. */
    public static function calcular($subtotal, $tasa): array
    {
        $base = Contabilidad::centavos($subtotal);
        $iva  = (int) round($base * ((float) $tasa) / 100);

        return [
            'subtotal' => number_format($base / 100, 2, '.', ''),
            'iva'      => number_format($iva / 100, 2, '.', ''),
            'total'    => number_format(($base + $iva) / 100, 2, '.', ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function categorias(): array
    {
        return self::$db->query('SELECT id, nombre FROM categorias_gasto WHERE activa = 1 ORDER BY nombre')->fetch_all(MYSQLI_ASSOC);
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT g.*, e.nombre AS empresa_nombre, c.nombre AS categoria_nombre
               FROM gastos g
               LEFT JOIN empresas e ON e.id = g.empresa_id
               LEFT JOIN categorias_gasto c ON c.id = g.categoria_id
              WHERE g.id = ?'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if ($fila) {
            $fila['reparto'] = self::reparto($id);
        }

        return $fila ?: null;
    }

    /** @return array<int, array{empresa_id: int, nombre: string, porcentaje: float}> */
    public static function reparto(int $gastoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT r.empresa_id, e.nombre, r.porcentaje FROM gasto_reparto r JOIN empresas e ON e.id = r.empresa_id
              WHERE r.gasto_id = ? ORDER BY r.empresa_id'
        );
        $consulta->bind_param('i', $gastoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        foreach ($filas as &$f) {
            $f['empresa_id'] = (int) $f['empresa_id'];
            $f['porcentaje'] = (float) $f['porcentaje'];
        }
        unset($f);

        return $filas;
    }

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
    private static function condiciones(array $f): array
    {
        $donde = [($f['estado'] ?? '') === 'cancelado' ? "g.estado = 'cancelado'" : "g.estado = 'vigente'"];
        $tipos = '';
        $valores = [];

        if ((int) ($f['anio'] ?? 0) > 0) {
            $donde[] = 'YEAR(g.fecha) = ?';
            $tipos .= 'i';
            $valores[] = (int) $f['anio'];
        }
        if ((int) ($f['mes'] ?? 0) > 0) {
            $donde[] = 'MONTH(g.fecha) = ?';
            $tipos .= 'i';
            $valores[] = (int) $f['mes'];
        }
        if ((int) ($f['empresa'] ?? 0) > 0) {
            $donde[] = '(g.empresa_id = ? OR EXISTS (SELECT 1 FROM gasto_reparto r WHERE r.gasto_id = g.id AND r.empresa_id = ?))';
            $tipos .= 'ii';
            $valores[] = (int) $f['empresa'];
            $valores[] = (int) $f['empresa'];
        }
        if (in_array($f['pago'] ?? '', ['pendiente', 'pagado'], true)) {
            $donde[] = 'g.estado_pago = ?';
            $tipos .= 's';
            $valores[] = $f['pago'];
        }
        if (($f['sin_comprobante'] ?? '') === '1') {
            $donde[] = 'g.comprobante_fiscal = 0';
        }
        $termino = trim((string) ($f['q'] ?? ''));
        if ($termino !== '') {
            $donde[] = '(g.beneficiario LIKE ? OR g.concepto LIKE ? OR IFNULL(g.uuid_fiscal, "") LIKE ?)';
            $tipos .= 'sss';
            $patron = '%' . $termino . '%';
            array_push($valores, $patron, $patron, $patron);
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /** @return array<int, array<string, mixed>> */
    public static function listar(array $filtros, int $limite = 15, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            "SELECT g.*, e.nombre AS empresa_nombre, c.nombre AS categoria_nombre,
                    (SELECT COUNT(*) FROM documentos d WHERE d.entidad = 'gasto' AND d.entidad_id = g.id AND d.activo = 1) AS documentos
               FROM gastos g
               LEFT JOIN empresas e ON e.id = g.empresa_id
               LEFT JOIN categorias_gasto c ON c.id = g.categoria_id
              WHERE $donde ORDER BY g.fecha DESC, g.id DESC LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    public static function contar(array $filtros): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare("SELECT COUNT(*) AS n FROM gastos g WHERE $donde");
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $n;
    }

    /**
     * Resumen de gastos de un mes. Cada gasto suma UNA vez en el total; el
     * detalle por negocio reparte subtotal e IVA en centavos (el residuo va al
     * último negocio) para que la suma de negocios coincida con el total.
     *
     * @param array<int, array<string, mixed>> $porNegocio acumulado de ingresos (se completa y devuelve)
     * @return array<string, mixed> importes en CENTAVOS
     */
    public static function resumenPeriodo(int $anio, int $mes, int $empresaId, array $porNegocio): array
    {
        $consulta = self::$db->prepare(
            "SELECT * FROM gastos WHERE estado = 'vigente' AND YEAR(fecha) = ? AND MONTH(fecha) = ?"
        );
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $gastos = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $r = [
            'operaciones' => 0, 'subtotal' => 0, 'iva' => 0, 'total' => 0,
            'pagado' => 0, 'pendiente' => 0, 'sin_comprobante' => 0, 'por_validar' => 0,
            'por_negocio' => $porNegocio,
        ];
        $nombres = [];
        foreach (self::$db->query('SELECT id, nombre FROM empresas')->fetch_all(MYSQLI_ASSOC) as $e) {
            $nombres[(int) $e['id']] = $e['nombre'];
        }

        foreach ($gastos as $g) {
            $base = Contabilidad::centavos($g['subtotal']);
            $iva  = Contabilidad::centavos($g['iva']);
            $total = Contabilidad::centavos($g['total']);

            // Asignación por negocio (empresa_id => [base, iva]).
            $asignacion = [];
            if ((int) $g['compartido'] === 1) {
                $reparto = self::reparto((int) $g['id']);
                $restoBase = $base;
                $restoIva = $iva;
                foreach ($reparto as $i => $parte) {
                    $ultimo = $i === array_key_last($reparto);
                    $b = $ultimo ? $restoBase : (int) round($base * $parte['porcentaje'] / 100);
                    $v = $ultimo ? $restoIva : (int) round($iva * $parte['porcentaje'] / 100);
                    $restoBase -= $b;
                    $restoIva -= $v;
                    $asignacion[$parte['empresa_id']] = [$b, $v];
                }
            } else {
                $asignacion[(int) $g['empresa_id']] = [$base, $iva];
            }

            if ($empresaId > 0) {
                if (!isset($asignacion[$empresaId])) {
                    continue;
                }
                [$base, $iva] = $asignacion[$empresaId];
                $total = $base + $iva;
            }

            $r['operaciones']++;
            $r['subtotal'] += $base;
            $r['iva'] += $iva;
            $r['total'] += $total;
            $r[$g['estado_pago'] === 'pagado' ? 'pagado' : 'pendiente'] += $total;
            $r['sin_comprobante'] += (int) $g['comprobante_fiscal'] === 0 ? 1 : 0;
            $r['por_validar'] += $g['revision_fiscal'] === 'por_validar' ? 1 : 0;

            foreach ($asignacion as $e => [$b, $v]) {
                if ($empresaId > 0 && $e !== $empresaId) {
                    continue;
                }
                $r['por_negocio'][$e] ??= ['nombre' => $e > 0 ? ($nombres[$e] ?? 'Negocio') : 'General (sin negocio)',
                    'ventas' => 0, 'iva_ventas' => 0, 'gastos' => 0, 'iva_gastos' => 0];
                $r['por_negocio'][$e]['gastos'] += $b;
                $r['por_negocio'][$e]['iva_gastos'] += $v;
            }
        }

        return $r;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

    /**
     * Valida y normaliza los datos del formulario.
     *
     * @return array{0: string[], 1: array<string, mixed>} errores y datos listos para guardar
     */
    public static function normalizar(array $d, ?array $previo = null): array
    {
        $errores = [];

        // Formulario sencillo: el usuario captura el TOTAL pagado y si lleva IVA; el subtotal se despeja.
        $totalCapturado = str_replace(',', '.', trim((string) ($d['total'] ?? '')));
        if (trim((string) ($d['subtotal'] ?? '')) === '' && $totalCapturado !== '') {
            if (is_numeric($totalCapturado) && (float) $totalCapturado > 0) {
                $tasaCapturada = (float) str_replace(',', '.', (string) ($d['iva_tasa'] ?? '16'));
                $d['subtotal'] = number_format(round((float) $totalCapturado / (1 + $tasaCapturada / 100), 2), 2, '.', '');
            }
        }
        // «Ya lo pagué» sin fecha de pago: se toma la fecha del gasto.
        if (($d['estado_pago'] ?? '') === 'pagado' && trim((string) ($d['fecha_pago'] ?? '')) === '') {
            $d['fecha_pago'] = (string) ($d['fecha'] ?? '');
        }

        $fecha = (string) ($d['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            $errores[] = 'La fecha del gasto no es válida.';
        }

        $proveedorId = (int) ($d['proveedor_id'] ?? 0) ?: null;
        $beneficiario = trim((string) ($d['beneficiario'] ?? ''));
        if ($proveedorId && $beneficiario === '') {
            $consulta = self::$db->prepare('SELECT nombre FROM proveedores WHERE id = ?');
            $consulta->bind_param('i', $proveedorId);
            $consulta->execute();
            $beneficiario = (string) ($consulta->get_result()->fetch_assoc()['nombre'] ?? '');
            $consulta->close();
        }
        if ($beneficiario === '') {
            $errores[] = 'Escribe a quién le compraste o pagaste.';
        }
        if (trim((string) ($d['concepto'] ?? '')) === '') {
            $errores[] = 'Escribe en qué fue el gasto.';
        }

        $subtotal = str_replace(',', '.', trim((string) ($d['subtotal'] ?? '')));
        if (!is_numeric($subtotal) || (float) $subtotal <= 0) {
            $errores[] = 'Escribe cuánto pagaste (debe ser mayor a cero).';
            $subtotal = '0';
        }
        $tasa = str_replace(',', '.', trim((string) ($d['iva_tasa'] ?? '16')));
        if (!is_numeric($tasa) || (float) $tasa < 0 || (float) $tasa > 30) {
            $errores[] = 'La tasa de IVA debe estar entre 0 y 30.';
            $tasa = '0';
        }
        $importes = self::calcular($subtotal, $tasa);

        $naturaleza = (string) ($d['naturaleza'] ?? 'gasto');
        if (!isset(self::NATURALEZAS[$naturaleza])) {
            $errores[] = 'Naturaleza del gasto no reconocida.';
        }

        $compartido = !empty($d['compartido']) ? 1 : 0;
        $empresaId = $compartido ? null : ((int) ($d['empresa_id'] ?? 0) ?: null);
        $reparto = [];
        if ($compartido) {
            $suma = 0;
            foreach ((array) ($d['reparto'] ?? []) as $eid => $pct) {
                $pct = (float) str_replace(',', '.', (string) $pct);
                if ($pct < 0) {
                    $errores[] = 'Los porcentajes de reparto no pueden ser negativos.';
                }
                if ($pct > 0) {
                    $reparto[(int) $eid] = $pct;
                    $suma += (int) round($pct * 100);
                }
            }
            if (count($reparto) < 1 || $suma !== 10000) {
                $errores[] = 'El reparto entre negocios debe sumar exactamente 100 %.';
            }
        }

        $pago = (string) ($d['estado_pago'] ?? 'pendiente');
        $pago = in_array($pago, ['pendiente', 'pagado'], true) ? $pago : 'pendiente';
        $fechaPago = (string) ($d['fecha_pago'] ?? '');
        if ($pago === 'pagado') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago) || !strtotime($fechaPago)) {
                $errores[] = 'Un gasto pagado necesita la fecha de pago.';
            }
        } else {
            $fechaPago = '';
        }

        $uuid = strtoupper(trim((string) ($d['uuid_fiscal'] ?? '')));
        if ($uuid !== '' && !preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
            $errores[] = 'El folio fiscal (UUID) no tiene el formato correcto.';
        }
        if ($uuid !== '') {
            $consulta = self::$db->prepare("SELECT id FROM gastos WHERE uuid_fiscal = ? AND estado = 'vigente' AND id <> ? LIMIT 1");
            $propio = (int) ($previo['id'] ?? 0);
            $consulta->bind_param('si', $uuid, $propio);
            $consulta->execute();
            if ($consulta->get_result()->num_rows > 0) {
                $errores[] = 'Ese UUID ya está registrado en otro gasto.';
            }
            $consulta->close();
        }

        $revision = (string) ($d['revision_fiscal'] ?? ($previo['revision_fiscal'] ?? 'por_validar'));
        $revision = isset(self::REVISION[$revision]) ? $revision : 'por_validar';

        return [$errores, [
            'fecha' => $fecha, 'proveedor_id' => $proveedorId, 'beneficiario' => $beneficiario,
            'concepto' => trim((string) ($d['concepto'] ?? '')),
            'categoria_id' => (int) ($d['categoria_id'] ?? 0) ?: null, 'naturaleza' => $naturaleza,
            'empresa_id' => $empresaId, 'compartido' => $compartido, 'reparto' => $reparto,
            'subtotal' => $importes['subtotal'], 'iva_tasa' => number_format((float) $tasa, 2, '.', ''),
            'iva' => $importes['iva'], 'total' => $importes['total'],
            'estado_pago' => $pago, 'fecha_pago' => $fechaPago ?: null,
            'metodo_pago' => trim((string) ($d['metodo_pago'] ?? '')) ?: null,
            'comprobante_fiscal' => !empty($d['comprobante_fiscal']) ? 1 : 0,
            'uuid_fiscal' => $uuid ?: null, 'revision_fiscal' => $revision,
            'observaciones' => trim((string) ($d['observaciones'] ?? '')) ?: null,
        ]];
    }

    public static function crear(array $datos, int $usuarioId): bool
    {
        [$errores, $g] = self::normalizar($datos);
        self::$errores = $errores;
        if ($errores) {
            return false;
        }

        $contribuyente = $g['empresa_id'] ? Contabilidad::contribuyenteDeEmpresa((int) $g['empresa_id']) : Contabilidad::contribuyentePrincipal();
        if (Contabilidad::periodoCerrado($contribuyente, $g['fecha'])) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado($g['fecha']);
            return false;
        }

        try {
            self::$db->begin_transaction();

            $consulta = self::$db->prepare(
                'INSERT INTO gastos (contribuyente_id, fecha, proveedor_id, beneficiario, concepto, categoria_id, naturaleza,
                        empresa_id, compartido, subtotal, iva_tasa, iva, total, estado_pago, fecha_pago, metodo_pago,
                        comprobante_fiscal, uuid_fiscal, revision_fiscal, observaciones, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $consulta->bind_param(
                'isissisiisssssssisssi',
                $contribuyente, $g['fecha'], $g['proveedor_id'], $g['beneficiario'], $g['concepto'], $g['categoria_id'],
                $g['naturaleza'], $g['empresa_id'], $g['compartido'], $g['subtotal'], $g['iva_tasa'], $g['iva'], $g['total'],
                $g['estado_pago'], $g['fecha_pago'], $g['metodo_pago'], $g['comprobante_fiscal'], $g['uuid_fiscal'],
                $g['revision_fiscal'], $g['observaciones'], $usuarioId
            );
            $consulta->execute();
            $id = (int) self::$db->insert_id;
            $consulta->close();

            self::guardarReparto($id, $g['reparto']);
            Auditoria::registrar('gasto', $id, 'crear', $usuarioId, null, ['total' => $g['total'], 'compartido' => $g['compartido']]);

            self::$db->commit();
            self::$ultimoId = $id;
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Gastos::crear] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible guardar el gasto.';
            return false;
        }
    }

    public static function actualizar(int $id, array $datos, int $usuarioId): bool
    {
        $previo = self::buscarID($id);
        if (!$previo) {
            self::$errores = ['El gasto ya no existe.'];
            return false;
        }
        if ($previo['estado'] === 'cancelado') {
            self::$errores = ['Un gasto cancelado no se puede editar.'];
            return false;
        }

        [$errores, $g] = self::normalizar($datos, $previo);
        self::$errores = $errores;
        if ($errores) {
            return false;
        }

        // El cambio toca el periodo original y el nuevo (si cambió la fecha).
        foreach (array_unique([$previo['fecha'], $g['fecha']]) as $fecha) {
            if (Contabilidad::periodoCerrado((int) $previo['contribuyente_id'], $fecha)) {
                self::$errores[] = Contabilidad::mensajePeriodoCerrado($fecha);
                return false;
            }
        }

        try {
            self::$db->begin_transaction();

            $consulta = self::$db->prepare(
                'UPDATE gastos SET fecha = ?, proveedor_id = ?, beneficiario = ?, concepto = ?, categoria_id = ?, naturaleza = ?,
                        empresa_id = ?, compartido = ?, subtotal = ?, iva_tasa = ?, iva = ?, total = ?, estado_pago = ?,
                        fecha_pago = ?, metodo_pago = ?, comprobante_fiscal = ?, uuid_fiscal = ?, revision_fiscal = ?,
                        observaciones = ? WHERE id = ?'
            );
            $consulta->bind_param(
                'sissisiisssssssisssi',
                $g['fecha'], $g['proveedor_id'], $g['beneficiario'], $g['concepto'], $g['categoria_id'], $g['naturaleza'],
                $g['empresa_id'], $g['compartido'], $g['subtotal'], $g['iva_tasa'], $g['iva'], $g['total'],
                $g['estado_pago'], $g['fecha_pago'], $g['metodo_pago'], $g['comprobante_fiscal'], $g['uuid_fiscal'],
                $g['revision_fiscal'], $g['observaciones'], $id
            );
            $consulta->execute();
            $consulta->close();

            self::guardarReparto($id, $g['reparto']);

            $cambios = [];
            foreach (['fecha', 'subtotal', 'iva', 'total', 'estado_pago', 'revision_fiscal', 'concepto'] as $campo) {
                if ((string) $previo[$campo] !== (string) $g[$campo]) {
                    $cambios[$campo] = [$previo[$campo], $g[$campo]];
                }
            }
            Auditoria::registrar('gasto', $id, 'actualizar', $usuarioId, null, $cambios);

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Gastos::actualizar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible actualizar el gasto.';
            return false;
        }
    }

    /** Cancela un gasto (no se borra). Exige motivo. */
    public static function cancelar(int $id, string $motivo, int $usuarioId): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);
        $gasto = self::buscarID($id);

        if (!$gasto || $gasto['estado'] !== 'vigente') {
            self::$errores[] = 'El gasto no existe o ya está cancelado.';
            return false;
        }
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Escribe el motivo de la cancelación (mínimo 5 caracteres).';
            return false;
        }
        if (Contabilidad::periodoCerrado((int) $gasto['contribuyente_id'], $gasto['fecha'])) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado($gasto['fecha']);
            return false;
        }

        $consulta = self::$db->prepare(
            "UPDATE gastos SET estado = 'cancelado', motivo_cancelacion = ?, cancelado_en = NOW(), cancelado_por = ? WHERE id = ?"
        );
        $consulta->bind_param('sii', $motivo, $usuarioId, $id);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('gasto', $id, 'cancelar', $usuarioId, $motivo, ['total' => $gasto['total']]);
        return true;
    }

    /** @param array<int, float> $reparto empresa_id => porcentaje */
    private static function guardarReparto(int $gastoId, array $reparto): void
    {
        $borrar = self::$db->prepare('DELETE FROM gasto_reparto WHERE gasto_id = ?');
        $borrar->bind_param('i', $gastoId);
        $borrar->execute();
        $borrar->close();

        $insertar = self::$db->prepare('INSERT INTO gasto_reparto (gasto_id, empresa_id, porcentaje) VALUES (?, ?, ?)');
        foreach ($reparto as $empresaId => $pct) {
            $valor = number_format($pct, 2, '.', '');
            $insertar->bind_param('iis', $gastoId, $empresaId, $valor);
            $insertar->execute();
        }
        $insertar->close();
    }
}
