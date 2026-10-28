<?php

namespace App;

/**
 * Gastos — egresos del contribuyente.
 *
 * Reglas:
 *   • Un gasto se registra UNA vez. Si lo comparten los dos negocios, el
 *     reparto (gasto_reparto) solo alimenta los reportes por negocio: el total
 *     contable consolidado nunca cambia ni se duplica.
 *   • Una captura equivocada se cancela/elimina con motivo: no queda sumando ni
 *     como historial (la copia de 90 días vive en la Papelera y el motivo en la
 *     bitácora).
 *   • Un periodo cerrado no se modifica en silencio.
 *   • Un gasto con folio fiscal (UUID) viene de un CFDI descargado del SAT: su
 *     comprobante es auténtico, así que `revision_fiscal` arranca en «validado».
 *     Sin UUID (PDF, foto o captura a mano) queda en «por_validar»; la
 *     deducibilidad material la sigue revisando el contador. `no_deducible`
 *     siempre se respeta y nunca se cambia solo.
 *   • El mismo folio fiscal (UUID) no puede estar vigente en dos gastos. Un
 *     proveedor puede facturar varias veces el mismo servicio: el UUID es el
 *     único dato que identifica al documento, no el proveedor ni el importe.
 *   • El Uso del CFDI y el método de pago (PUE / PPD / por definir) son datos
 *     del comprobante: si el gasto es «sin factura» se limpian, y el centro de
 *     seguimiento vigila las facturas con pago en parcialidades o por definir.
 *   • Importes en centavos enteros; IVA = subtotal × tasa, redondeado a centavos.
 */
class Gastos
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    public static int $ultimoId = 0;

    /** Gasto vigente que ya tiene el UUID enviado (0 si no hubo duplicado). */
    public static int $duplicadoId = 0;

    public const NATURALEZAS = [
        'gasto' => 'Gasto operativo', 'compra' => 'Compra de mercancía', 'insumo' => 'Insumos y consumibles',
        'servicio' => 'Servicio', 'activo' => 'Activo / equipo', 'impuesto' => 'Impuestos y derechos', 'otro' => 'Otro',
    ];

    public const BENEFICIARIOS = [
        'proveedor' => 'Proveedor', 'estacion' => 'Estación de servicio', 'otro' => 'Otro',
    ];

    /**
     * Clasificación contable que se deduce del uso del CFDI.
     *
     * El formulario ya no pregunta la clasificación: el uso del comprobante dice si
     * se trata de mercancía (G01), de activo (I01–I08) o de un gasto operativo.
     */
    public static function naturalezaDeUsoCfdi(string $uso): string
    {
        $uso = strtoupper(trim($uso));
        if ($uso === 'G01') {
            return 'compra';
        }
        if (preg_match('/^I0[1-8]$/', $uso) === 1) {
            return 'activo';
        }
        return 'gasto';
    }

    public const REVISION = [
        'por_validar'  => ['etiqueta' => 'Por validar', 'clase' => 'badge--pendiente'],
        'validado'     => ['etiqueta' => 'Validado',    'clase' => 'badge--aprobado'],
        'no_deducible' => ['etiqueta' => 'No deducible', 'clase' => 'badge--neutro'],
    ];

    /** Uso del CFDI del receptor (catálogo SAT vigente del UsoCFDI, sólo los usos que le tocan a un gasto). */
    public const USOS_CFDI = [
        'G01' => 'G01 · Adquisición de mercancías',
        'G02' => 'G02 · Devoluciones, descuentos o bonificaciones',
        'G03' => 'G03 · Gastos en general',
        'I01' => 'I01 · Construcciones',
        'I02' => 'I02 · Mobiliario y equipo de oficina',
        'I03' => 'I03 · Equipo de transporte',
        'I04' => 'I04 · Equipo de computación y accesorios',
        'I05' => 'I05 · Dados, troqueles, moldes y herramental',
        'I06' => 'I06 · Comunicaciones telefónicas',
        'I07' => 'I07 · Comunicaciones satelitales',
        'I08' => 'I08 · Otra maquinaria y equipo',
        'D01' => 'D01 · Honorarios médicos, dentales y gastos hospitalarios',
        'D02' => 'D02 · Gastos médicos por incapacidad o discapacidad',
        'D03' => 'D03 · Gastos funerales',
        'D04' => 'D04 · Donativos',
        'D05' => 'D05 · Intereses reales de créditos hipotecarios (casa habitación)',
        'D06' => 'D06 · Aportaciones voluntarias al SAR',
        'D07' => 'D07 · Primas de seguros de gastos médicos',
        'D08' => 'D08 · Gastos de transportación escolar obligatoria',
        'D09' => 'D09 · Depósitos en cuentas de ahorro y planes de pensiones',
        'D10' => 'D10 · Pagos por servicios educativos (colegiaturas)',
        'S01' => 'S01 · Sin efectos fiscales',
    ];

    /**
     * Método de pago del CFDI tal como se captura en la factura del proveedor.
     *
     * «POR» (por definir) no es un valor del SAT: marca la factura cuyo método
     * todavía no se decide —se sabe que se pagará, pero no si en una sola
     * exhibición o en parcialidades—, y por eso el centro de seguimiento lo
     * vigila junto con PPD. El CFDI leído de un XML sólo puede traer PUE o PPD.
     */
    public const METODOS_CFDI = [
        'PPD' => 'PPD · Pago en parcialidades',
        'POR' => 'Por definir (aún no se decide)',
        'PUE' => 'PUE · Pago en una sola exhibición',
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

    /**
     * Situación documental de la factura de un gasto. Distingue lo que la persona INDICÓ de lo que hay ADJUNTO:
     * solo un XML con estructura de CFDI y UUID cuenta como «CFDI leído»; una foto o PDF suelto es solo un adjunto.
     *
     * @return array{clave: string, etiqueta: string, clase: string, detalle: string}
     */
    public static function estadoFactura(array $gasto): array
    {
        $docs = Archivos::deOperacion('gasto', (int) $gasto['id'], false);
        $cfdi = array_filter($docs, static fn ($d) => $d['estado_validacion'] === 'cfdi_con_timbre');
        $xmlDudoso = array_filter($docs, static fn ($d) => in_array($d['estado_validacion'], ['cfdi_sin_timbre', 'xml_no_cfdi'], true));
        $hayAdjuntos = count($docs) > 0;
        $conFactura = (int) $gasto['comprobante_fiscal'] === 1;

        if (!$conFactura) {
            return ['clave' => 'sin_factura', 'etiqueta' => 'Sin factura', 'clase' => 'badge--neutro',
                'detalle' => $hayAdjuntos ? 'Hay un comprobante adjunto, pero no es una factura: no vuelve deducible el gasto.' : 'No se recibió factura; no se resta de impuestos.'];
        }
        if ($cfdi) {
            return ['clave' => 'cfdi_leido', 'etiqueta' => 'CFDI adjunto (sin verificar ante el SAT)', 'clase' => 'badge--aprobado',
                'detalle' => 'El XML tiene estructura de CFDI y UUID. Aún no se verifica ante el SAT.'];
        }
        if ($xmlDudoso) {
            return ['clave' => 'cfdi_dudoso', 'etiqueta' => 'XML adjunto incompleto', 'clase' => 'badge--pendiente',
                'detalle' => 'El XML adjunto no es un CFDI completo; sube el XML correcto.'];
        }

        return ['clave' => 'factura_pendiente', 'etiqueta' => $hayAdjuntos ? 'Con factura: solo PDF o imagen adjunta' : 'Con factura: falta adjuntarla',
            'clase' => 'badge--pendiente', 'detalle' => 'Marcado «con factura», pero no hay un XML de CFDI que lo respalde. Un PDF o una foto no basta para validarla.'];
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
        foreach (['desde' => 'g.fecha >= ?', 'hasta' => 'g.fecha <= ?'] as $campo => $sql) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$campo] ?? ''))) {
                $donde[] = $sql;
                $tipos .= 's';
                $valores[] = $f[$campo];
            }
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
     * Totales de los gastos que cumplen los filtros (sin paginar), en pesos.
     *
     * @return array{n: int, subtotal: float, iva: float, total: float, por_pagar: float, sin_comprobante: int}
     */
    public static function totales(array $filtros): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(ROUND(g.subtotal * 100)), 0) AS subtotal, COALESCE(SUM(ROUND(g.iva * 100)), 0) AS iva,
                    COALESCE(SUM(ROUND(g.total * 100)), 0) AS total,
                    COALESCE(SUM(CASE WHEN g.estado_pago = 'pendiente' THEN ROUND(g.total * 100) ELSE 0 END), 0) AS por_pagar,
                    COALESCE(SUM(g.comprobante_fiscal = 0), 0) AS sin_comprobante
               FROM gastos g WHERE $donde"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $f = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return [
            'n' => (int) $f['n'], 'subtotal' => $f['subtotal'] / 100, 'iva' => $f['iva'] / 100, 'total' => $f['total'] / 100,
            'por_pagar' => $f['por_pagar'] / 100, 'sin_comprobante' => (int) $f['sin_comprobante'],
        ];
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
     * Un CFDI con timbre se adjuntó al gasto: se marca «validado» si seguía «por validar».
     * Nunca toca «no_deducible» (esa decisión es del contador). Devuelve true si cambió.
     */
    public static function marcarValidadoPorCfdi(int $gastoId): bool
    {
        $consulta = self::$db->prepare(
            "UPDATE gastos SET revision_fiscal = 'validado'
              WHERE id = ? AND estado = 'vigente' AND revision_fiscal = 'por_validar'"
        );
        $consulta->bind_param('i', $gastoId);
        $ok = $consulta->execute() && $consulta->affected_rows > 0;
        $consulta->close();

        return $ok;
    }

    /**
     * Valida y normaliza los datos del formulario.
     *
     * @return array{0: string[], 1: array<string, mixed>} errores y datos listos para guardar
     */
    public static function normalizar(array $d, ?array $previo = null): array
    {
        $errores = [];
        self::$duplicadoId = 0;

        // Formulario sencillo: el usuario captura el TOTAL pagado y si lleva IVA; el subtotal se despeja.
        $totalCapturado = str_replace(',', '.', trim((string) ($d['total'] ?? '')));
        if (trim((string) ($d['subtotal'] ?? '')) === '' && $totalCapturado !== '') {
            if (is_numeric($totalCapturado) && (float) $totalCapturado > 0) {
                $tasaCapturada = (float) str_replace(',', '.', (string) ($d['iva_tasa'] ?? '16'));
                $d['subtotal'] = number_format(round((float) $totalCapturado / (1 + $tasaCapturada / 100), 2), 2, '.', '');
            }
        }
        // «Ya lo pagué» sin fecha de pago: se conserva la que ya tenía el gasto (el
        // formulario ya no pide fecha de pago) o, en un alta, se toma la fecha del gasto.
        if (($d['estado_pago'] ?? '') === 'pagado' && trim((string) ($d['fecha_pago'] ?? '')) === '') {
            $d['fecha_pago'] = !array_key_exists('fecha_pago', $d) && !empty($previo['fecha_pago'])
                ? (string) $previo['fecha_pago']
                : (string) ($d['fecha'] ?? '');
        }

        $fecha = (string) ($d['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            $errores[] = 'La fecha del gasto no es válida.';
        }

        // «Pagado a»: solo un Proveedor exige elegir del directorio; estación u otro llevan una descripción libre.
        $tipoBen = (string) ($d['beneficiario_tipo'] ?? '');
        if ($tipoBen === '') {
            $tipoBen = !empty($d['proveedor_id']) ? 'proveedor' : (string) ($previo['beneficiario_tipo'] ?? 'otro');
        }
        if (!isset(self::BENEFICIARIOS[$tipoBen])) {
            $errores[] = 'Elige a quién se le pagó: proveedor, estación de servicio u otro.';
            $tipoBen = 'otro';
        }
        $proveedorId = $tipoBen === 'proveedor' ? ((int) ($d['proveedor_id'] ?? 0) ?: null) : null;
        $beneficiario = trim((string) ($d['beneficiario'] ?? ''));
        if ($tipoBen === 'proveedor') {
            if (!$proveedorId) {
                $errores[] = 'Busca el proveedor por nombre o RFC —o regístralo si todavía no existe— (o cambia «Pagado a» a Otro).';
            } else {
                $consulta = self::$db->prepare('SELECT nombre FROM proveedores WHERE id = ?');
                $consulta->bind_param('i', $proveedorId);
                $consulta->execute();
                $nombreProveedor = $consulta->get_result()->fetch_assoc()['nombre'] ?? null;
                $consulta->close();
                if ($nombreProveedor === null) {
                    $errores[] = 'El proveedor elegido ya no existe.';
                } elseif ($beneficiario === '' || (int) ($previo['proveedor_id'] ?? 0) !== $proveedorId) {
                    $beneficiario = (string) $nombreProveedor;
                }
            }
        } elseif ($beneficiario === '' && $tipoBen === 'estacion') {
            $beneficiario = self::BENEFICIARIOS['estacion'];
        } elseif ($beneficiario === '') {
            $errores[] = 'Describe a quién se le pagó.';
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

        // La clasificación contable ya no se captura: en un alta se deduce del uso del
        // CFDI y, al editar, se conserva la del gasto para no cambiarla sin querer.
        $naturaleza = trim((string) ($d['naturaleza'] ?? ''));
        if ($naturaleza === '') {
            $naturaleza = $previo !== null
                ? (string) ($previo['naturaleza'] ?? 'gasto')
                : self::naturalezaDeUsoCfdi((string) ($d['uso_cfdi'] ?? ''));
        }
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
            if ($fila = $consulta->get_result()->fetch_assoc()) {
                self::$duplicadoId = (int) $fila['id'];
                $errores[] = 'Ese folio fiscal (UUID) ya está registrado en el gasto #' . self::$duplicadoId . ': un pago no se registra dos veces.';
            }
            $consulta->close();
        }

        // «¿Hay factura?»: respuesta explícita. Vacío = sin responder (error); ausente en un llamado programático = se conserva.
        if (array_key_exists('comprobante_fiscal', $d)) {
            $factura = (string) $d['comprobante_fiscal'];
            if (!in_array($factura, ['0', '1'], true)) {
                $errores[] = 'Indica si el gasto tiene factura (CFDI) o no.';
                $factura = '0';
            }
        } else {
            $factura = (string) ($previo['comprobante_fiscal'] ?? '0');
        }

        $revision = (string) ($d['revision_fiscal'] ?? ($previo['revision_fiscal'] ?? 'por_validar'));
        $revision = isset(self::REVISION[$revision]) ? $revision : 'por_validar';
        // Un CFDI con folio fiscal viene del SAT: el comprobante es auténtico y el gasto
        // queda «validado» sin que nadie tenga que marcarlo. La deducibilidad material la
        // sigue decidiendo el contador: `no_deducible` nunca se cambia automáticamente.
        if ($revision === 'por_validar' && $uuid !== '') {
            $revision = 'validado';
        }

        // Uso del CFDI y método de pago del CFDI: son datos del comprobante, así que un gasto
        // SIN factura no los guarda (se limpian aunque el formulario los traiga ocultos).
        $metodoSat = strtoupper(trim((string) ($d['metodo_pago_sat'] ?? '')));
        if ($metodoSat !== '' && !isset(self::METODOS_CFDI[$metodoSat])) {
            $errores[] = 'Elige el método de pago del CFDI: PUE, PPD o por definir.';
            $metodoSat = '';
        }
        $usoCfdi = strtoupper(trim((string) ($d['uso_cfdi'] ?? '')));
        if (!isset(self::USOS_CFDI[$usoCfdi])) {
            if ($usoCfdi !== '') {
                $errores[] = 'El uso del CFDI no pertenece al catálogo del SAT.';
            }
            $usoCfdi = '';
        }
        // Forma de pago y tipo de comprobante: los otros dos datos del CFDI. Se
        // capturan en el formulario y también los trae la lectura inteligente.
        $formaSat = VentasFiscal::normalizarFormaPago((string) ($d['forma_pago_sat'] ?? ''));
        if ($formaSat !== '' && !isset(VentasFiscal::FORMAS_PAGO[$formaSat])) {
            $errores[] = 'La forma de pago "' . $formaSat . '" no es una clave del catálogo del SAT.';
            $formaSat = '';
        }
        $tipoCfdi = strtoupper(trim((string) ($d['tipo_cfdi'] ?? '')));
        if ($tipoCfdi !== '' && !isset(Contabilidad::TIPOS_CFDI[$tipoCfdi])) {
            $errores[] = 'El tipo de comprobante "' . $tipoCfdi . '" no es una clave del catálogo del SAT.';
            $tipoCfdi = '';
        }
        if ($factura === '0') {
            $metodoSat = '';
            $usoCfdi = '';
            $formaSat = '';
            $tipoCfdi = '';
        }

        return [$errores, [
            'fecha' => $fecha, 'proveedor_id' => $proveedorId, 'beneficiario_tipo' => $tipoBen, 'beneficiario' => $beneficiario,
            'concepto' => trim((string) ($d['concepto'] ?? '')),
            'categoria_id' => (int) ($d['categoria_id'] ?? 0) ?: null, 'naturaleza' => $naturaleza,
            'empresa_id' => $empresaId, 'compartido' => $compartido, 'reparto' => $reparto,
            'subtotal' => $importes['subtotal'], 'iva_tasa' => number_format((float) $tasa, 2, '.', ''),
            'iva' => $importes['iva'], 'total' => $importes['total'],
            'estado_pago' => $pago, 'fecha_pago' => $fechaPago ?: null,
            // El método de pago interno ya no se pide en el formulario (lo dice la forma
            // de pago del CFDI): al editar se conserva el que tuviera el gasto.
            'metodo_pago' => trim((string) ($d['metodo_pago'] ?? ($previo['metodo_pago'] ?? ''))) ?: null,
            'comprobante_fiscal' => (int) $factura,
            'uuid_fiscal' => $uuid ?: null, 'revision_fiscal' => $revision,
            'observaciones' => trim((string) ($d['observaciones'] ?? '')) ?: null,
            // Datos de la factura (lectura documental). El folio comercial NO es el UUID.
            'folio_comercial' => mb_substr(trim((string) ($d['folio_comercial'] ?? '')), 0, 40) ?: null,
            'moneda' => preg_match('/^[A-Z]{3}$/', strtoupper(trim((string) ($d['moneda'] ?? '')))) ? strtoupper(trim((string) $d['moneda'])) : 'MXN',
            'forma_pago_sat' => $formaSat ?: null,
            'metodo_pago_sat' => $metodoSat ?: null,
            'uso_cfdi' => $usoCfdi ?: null,
            'tipo_cfdi' => $tipoCfdi ?: null,
            'rfc_emisor' => strtoupper(mb_substr(trim((string) ($d['rfc_emisor'] ?? '')), 0, 13)) ?: null,
            'rfc_receptor' => strtoupper(mb_substr(trim((string) ($d['rfc_receptor'] ?? '')), 0, 13)) ?: null,
            'lectura_id' => (int) ($d['lectura_id'] ?? 0) ?: null,
        ]];
    }

    /**
     * @param bool $dentroDeTransaccion true cuando el llamador ya abrió una transacción (p. ej. la compra que crea
     *        su gasto): no abre, confirma ni revierte; ante un error devuelve false y el llamador revierte.
     */
    public static function crear(array $datos, int $usuarioId, bool $dentroDeTransaccion = false): bool
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
            if (!$dentroDeTransaccion) {
                self::$db->begin_transaction();
            }

            $consulta = self::$db->prepare(
                'INSERT INTO gastos (contribuyente_id, fecha, proveedor_id, beneficiario_tipo, beneficiario, concepto, categoria_id, naturaleza,
                        empresa_id, compartido, subtotal, iva_tasa, iva, total, estado_pago, fecha_pago, metodo_pago,
                        comprobante_fiscal, uuid_fiscal, revision_fiscal, observaciones, usuario_id,
                        folio_comercial, moneda, forma_pago_sat, metodo_pago_sat, uso_cfdi, tipo_cfdi, rfc_emisor, rfc_receptor, lectura_id)
                 VALUES (' . implode(', ', array_fill(0, 31, '?')) . ')'
            );
            $consulta->bind_param(
                'isisssisii' . 'sssssss' . 'isssi' . 'ssssssssi',
                $contribuyente, $g['fecha'], $g['proveedor_id'], $g['beneficiario_tipo'], $g['beneficiario'], $g['concepto'], $g['categoria_id'],
                $g['naturaleza'], $g['empresa_id'], $g['compartido'], $g['subtotal'], $g['iva_tasa'], $g['iva'], $g['total'],
                $g['estado_pago'], $g['fecha_pago'], $g['metodo_pago'], $g['comprobante_fiscal'], $g['uuid_fiscal'],
                $g['revision_fiscal'], $g['observaciones'], $usuarioId,
                $g['folio_comercial'], $g['moneda'], $g['forma_pago_sat'], $g['metodo_pago_sat'], $g['uso_cfdi'], $g['tipo_cfdi'],
                $g['rfc_emisor'], $g['rfc_receptor'], $g['lectura_id']
            );
            $consulta->execute();
            $id = (int) self::$db->insert_id;
            $consulta->close();

            self::guardarReparto($id, $g['reparto']);
            Auditoria::registrar('gasto', $id, 'crear', $usuarioId, null, ['total' => $g['total'], 'compartido' => $g['compartido']]);

            // Una lectura de «compra» que resolvió como gasto deja de estar pendiente de decisión
            // en Compras Inteligentes (si no, seguiría apareciendo como «sin decidir»). Para las
            // lecturas de gasto/venta no hace nada: esas las cierra su propio flujo de confirmación.
            if (!empty($g['lectura_id'])) {
                ComprasIntel::cerrarLectura(['id' => (int) $g['lectura_id']], 'gasto', $id, $usuarioId);
            }

            if (!$dentroDeTransaccion) {
                self::$db->commit();
            }
            self::$ultimoId = $id;
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            if (!$dentroDeTransaccion) {
                self::$db->rollBack();
            }
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
                'UPDATE gastos SET fecha = ?, proveedor_id = ?, beneficiario_tipo = ?, beneficiario = ?, concepto = ?, categoria_id = ?, naturaleza = ?,
                        empresa_id = ?, compartido = ?, subtotal = ?, iva_tasa = ?, iva = ?, total = ?, estado_pago = ?,
                        fecha_pago = ?, metodo_pago = ?, comprobante_fiscal = ?, uuid_fiscal = ?, revision_fiscal = ?,
                        forma_pago_sat = ?, metodo_pago_sat = ?, uso_cfdi = ?, tipo_cfdi = ?, observaciones = ? WHERE id = ?'
            );
            $consulta->bind_param(
                'sisssisiisssssssisssssssi',
                $g['fecha'], $g['proveedor_id'], $g['beneficiario_tipo'], $g['beneficiario'], $g['concepto'], $g['categoria_id'], $g['naturaleza'],
                $g['empresa_id'], $g['compartido'], $g['subtotal'], $g['iva_tasa'], $g['iva'], $g['total'],
                $g['estado_pago'], $g['fecha_pago'], $g['metodo_pago'], $g['comprobante_fiscal'], $g['uuid_fiscal'],
                $g['revision_fiscal'], $g['forma_pago_sat'], $g['metodo_pago_sat'], $g['uso_cfdi'], $g['tipo_cfdi'], $g['observaciones'], $id
            );
            $consulta->execute();
            $consulta->close();

            self::guardarReparto($id, $g['reparto']);

            $cambios = [];
            foreach (['fecha', 'subtotal', 'iva', 'total', 'estado_pago', 'revision_fiscal', 'concepto',
                      'metodo_pago_sat', 'uso_cfdi', 'forma_pago_sat', 'tipo_cfdi'] as $campo) {
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

    /**
     * Define el método de pago del CFDI de un gasto (PUE, PPD o «por definir»).
     * Lo usa el centro de seguimiento para resolver un aviso sin abrir el gasto.
     */
    public static function metodoCfdi(int $id, string $metodo, int $usuarioId): bool
    {
        self::$errores = [];
        $metodo = strtoupper(trim($metodo));
        $gasto = self::buscarID($id);

        if (!$gasto || $gasto['estado'] !== 'vigente') {
            self::$errores[] = 'El gasto no existe o está cancelado.';
            return false;
        }
        if (!isset(self::METODOS_CFDI[$metodo])) {
            self::$errores[] = 'Elige el método de pago del CFDI: PUE, PPD o por definir.';
            return false;
        }
        if (Contabilidad::periodoCerrado((int) $gasto['contribuyente_id'], $gasto['fecha'])) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado($gasto['fecha']);
            return false;
        }

        $consulta = self::$db->prepare('UPDATE gastos SET metodo_pago_sat = ? WHERE id = ?');
        $consulta->bind_param('si', $metodo, $id);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('gasto', $id, 'metodo_cfdi', $usuarioId, null, ['anterior' => $gasto['metodo_pago_sat'], 'nuevo' => $metodo]);
        return true;
    }

    /** Marca como pagado un gasto pendiente (fecha de pago = hoy). */
    public static function marcarPagado(int $id, string $metodo, int $usuarioId): bool
    {
        self::$errores = [];
        $gasto = self::buscarID($id);

        if (!$gasto || $gasto['estado'] !== 'vigente') {
            self::$errores[] = 'El gasto no existe o está cancelado.';
            return false;
        }
        if ($gasto['estado_pago'] === 'pagado') {
            self::$errores[] = 'El gasto ya estaba pagado.';
            return false;
        }
        if (Contabilidad::periodoCerrado((int) $gasto['contribuyente_id'], $gasto['fecha'])) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado($gasto['fecha']);
            return false;
        }

        $metodo = in_array($metodo, Contabilidad::METODOS_PAGO, true) ? $metodo : null;
        $consulta = self::$db->prepare("UPDATE gastos SET estado_pago = 'pagado', fecha_pago = CURDATE(), metodo_pago = COALESCE(?, metodo_pago) WHERE id = ?");
        $consulta->bind_param('si', $metodo, $id);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('gasto', $id, 'pagar', $usuarioId, null, ['metodo' => $metodo]);
        return true;
    }

    /**
     * Cancela un gasto por error del usuario: lo elimina junto con sus documentos.
     *
     * «Cancelar» y «eliminar» son la misma operación para un gasto: una captura
     * equivocada no debe quedar sumando ni siquiera como historial. La copia de
     * 90 días queda en la Papelera y el motivo en la bitácora. Se conserva el
     * nombre por compatibilidad con las llamadas existentes.
     */
    public static function cancelar(int $id, string $motivo, int $usuarioId): bool
    {
        return self::eliminar($id, $usuarioId, $motivo);
    }

    /** Documentos adjuntos que borró la última llamada a eliminar(). */
    public static int $documentosEliminados = 0;

    /**
     * Elimina el gasto Y sus documentos adjuntos (filas y archivos del servidor).
     *
     * Es la operación de «cancelar» un gasto capturado por error, y también la
     * del borrado masivo: el registro desaparece. Exige motivo (queda en la
     * bitácora), no toca meses ya cerrados y deja sin enlaces rotos todo lo que
     * apuntaba al gasto:
     *
     *   · `documentos` y sus archivos        se borran con el gasto;
     *   · `gasto_reparto`                    cae en cascada (FK);
     *   · `ordenes_compra.gasto_id`          queda NULL (FK) y la compra pierde
     *                                         su estado de CFDI al borrar el XML;
     *   · `lecturas_documentales`            se desenlazan y, si se habían
     *                                         confirmado, vuelven a estar
     *                                         disponibles para releer el archivo.
     *
     * Los archivos sólo se borran después de confirmar la transacción: si algo
     * falla, sigue habiendo fila y archivo (nunca al revés).
     *
     * @param int    $usuarioId quién elimina
     * @param string $motivo    mínimo 5 caracteres; se guarda en la bitácora
     * @return bool true si se eliminó; el detalle del fallo va en getErrores()
     */
    public static function eliminar(int $id, int $usuarioId, string $motivo): bool
    {
        self::$errores = [];
        self::$documentosEliminados = 0;

        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Escribe el motivo de la eliminación (mínimo 5 caracteres).';
            return false;
        }

        $adjuntos = [];
        $rutas = [];

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT id FROM gastos WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $id);
            $bloqueo->execute();
            $bloqueo->close();

            $gasto = self::buscarID($id);
            if (!$gasto) {
                self::$db->rollBack();
                self::$errores[] = 'El gasto ya no existe.';
                return false;
            }
            if (Contabilidad::periodoCerrado((int) $gasto['contribuyente_id'], $gasto['fecha'])) {
                self::$db->rollBack();
                self::$errores[] = Contabilidad::mensajePeriodoCerrado($gasto['fecha']);
                return false;
            }

            // Antes de tocar nada, copia completa a la papelera: durante 90 días se puede
            // restaurar desde Ajustes → Papelera (los archivos adjuntos sí se pierden).
            $etiqueta = '#' . $id . ' · ' . $gasto['beneficiario'] . ' · ' . date('d/m/Y', strtotime($gasto['fecha']));
            $copia = Papelera::copiarGasto($id);
            if ($copia === null || !Papelera::guardar('gasto', $id, $etiqueta, (float) $gasto['total'], $copia, $usuarioId)) {
                self::$db->rollBack();
                self::$errores = array_merge(self::$errores, Papelera::getErrores());
                return false;
            }

            $orden = self::$db->prepare('SELECT id FROM ordenes_compra WHERE gasto_id = ? LIMIT 1');
            $orden->bind_param('i', $id);
            $orden->execute();
            $filaOrden = $orden->get_result()->fetch_assoc();
            $orden->close();
            $ordenId = $filaOrden ? (int) $filaOrden['id'] : null;

            // Documentos: primero se anotan las rutas y después se borran las filas.
            $adjuntos = Archivos::deOperacion('gasto', $id, true);
            foreach ($adjuntos as $adjunto) {
                $ruta = Archivos::rutaAbsoluta($adjunto);
                if ($ruta !== null) {
                    $rutas[] = $ruta;
                }
            }
            $borrarDocs = self::$db->prepare("DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id = ?");
            $borrarDocs->bind_param('i', $id);
            $borrarDocs->execute();
            $borrarDocs->close();

            // La compra que apuntaba a este gasto pasa a «sin CFDI» (y abajo se desliga).
            CompraContable::sincronizarDocumentos($id, $usuarioId);

            // Las lecturas confirmadas contra este gasto quedan sin enlace: si la
            // captura era errónea se puede volver a leer y registrar el archivo.
            $lecturas = self::$db->prepare(
                "UPDATE lecturas_documentales SET entidad = NULL, entidad_id = NULL,
                        estado = IF(estado = 'confirmada', 'descartada', estado),
                        confirmado_por = ?, confirmado_en = NOW()
                 WHERE entidad = 'gasto' AND entidad_id = ?"
            );
            $lecturas->bind_param('ii', $usuarioId, $id);
            $lecturas->execute();
            $lecturas->close();

            $borrarGasto = self::$db->prepare('DELETE FROM gastos WHERE id = ?');
            $borrarGasto->bind_param('i', $id);
            $borrarGasto->execute();
            $borrarGasto->close();

            Auditoria::registrar('gasto', $id, 'eliminar', $usuarioId, $motivo, [
                'fecha'       => $gasto['fecha'],
                'beneficiario' => $gasto['beneficiario'],
                'total'       => $gasto['total'],
                'documentos'  => count($adjuntos),
                'orden_compra' => $ordenId,
            ]);

            self::$db->commit();
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Gastos::eliminar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible eliminar el gasto.';
            return false;
        }

        // Ya no hay fila que apunte a estos archivos: se borran del disco (mejor esfuerzo).
        foreach ($rutas as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
        self::$documentosEliminados = count($adjuntos);

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
