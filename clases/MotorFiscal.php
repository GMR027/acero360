<?php

namespace App;

/**
 * MotorFiscal — estimación determinista de IVA mensual e ISR provisional.
 *
 * ES UNA ESTIMACIÓN ADMINISTRATIVA. No sustituye al contador ni a la declaración.
 * Usa solo las reglas ACTIVAS del catálogo fiscal (versionadas y con fuente oficial);
 * si falta alguna, no inventa valores: devuelve el aviso y deja ese impuesto sin calcular.
 *
 * Base de cálculo: FLUJO DE EFECTIVO (único método soportado).
 *   IVA  = IVA trasladado en lo EFECTIVAMENTE COBRADO del mes
 *          − IVA acreditable de gastos EFECTIVAMENTE PAGADOS del mes (que cumplan las reglas)
 *          − IVA retenido − saldo a favor anterior y otros ajustes.
 *   ISR provisional (art. 106 LISR) = tarifa del mes aplicada a
 *          (ingresos cobrados acumulados desde enero − deducciones pagadas acumuladas − PTU − pérdidas)
 *          − pagos provisionales anteriores − ISR retenido − otros acreditamientos.
 *   IVA e ISR no comparten base ni cifras: se calculan por separado.
 *
 * Importes en centavos enteros; el IVA de un cobro parcial se prorratea en acumulado para
 * que la suma de los cobros de una venta sea exactamente el IVA de la venta.
 *
 * CONFIRMACIÓN DE PAGO: una factura entra al cálculo únicamente cuando sus cobros vigentes
 * cubren su total. Con pagos en parcialidades (o sin cobro) se trata como SIN PAGAR y no
 * aporta ingreso ni IVA trasladado; al confirmarse el pago, sus cobros se reconocen en el mes
 * en que se recibieron. Lo excluido sale en 'facturas_excluidas' y en los avisos.
 */
class MotorFiscal
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /* --------------------------------------------------------------------
     * ARITMÉTICA PURA (sin base de datos)
     * ------------------------------------------------------------------ */

    /** Impuesto de la tarifa para una utilidad en centavos: cuota fija + (utilidad − límite inferior) × %. */
    public static function impuestoSegunTarifa(int $utilidad, array $filas): int
    {
        if ($utilidad <= 0 || !$filas) {
            return 0;
        }

        foreach ($filas as $fila) {
            $inferior = Contabilidad::centavos($fila['limite_inferior']);
            $superior = $fila['limite_superior'] === null ? null : Contabilidad::centavos($fila['limite_superior']);

            if ($utilidad >= $inferior && ($superior === null || $utilidad <= $superior)) {
                $porcentaje = (int) round(((float) $fila['porcentaje']) * 100); // puntos base: 10.88 % → 1088
                $excedente = $utilidad - $inferior;

                // Mitad hacia arriba, solo con enteros.
                return Contabilidad::centavos($fila['cuota_fija']) + intdiv($excedente * $porcentaje * 2 + 10000, 20000);
            }
        }

        return 0;
    }

    /** IVA de una base en centavos con la tasa dada en % (mitad hacia arriba). */
    public static function ivaDe(int $base, string $tasa): int
    {
        return intdiv($base * (int) round(((float) $tasa) * 100) * 2 + 10000, 20000);
    }

    /* --------------------------------------------------------------------
     * REGLAS
     * ------------------------------------------------------------------ */

    /**
     * @return array{isr: ?array, iva: ?array, reglas: ?array, avisos: string[]}
     */
    public static function catalogosActivos(int $anio): array
    {
        $avisos = [];
        $isr = CatalogoFiscal::activa('isr_pagos_provisionales_art106', $anio);
        $iva = CatalogoFiscal::activa('iva_tasas', $anio);
        $reglas = CatalogoFiscal::activa('reglas_plataforma', $anio);

        foreach ([['ISR', $isr], ['IVA', $iva], ['criterios de la plataforma', $reglas]] as [$nombre, $c]) {
            if (!$c) {
                $avisos[] = "No hay un catálogo de $nombre ACTIVO para $anio: ese cálculo queda deshabilitado hasta importar, validar y activar uno en Fiscal → Catálogo.";
            }
        }

        return ['isr' => $isr, 'iva' => $iva, 'reglas' => $reglas, 'avisos' => $avisos];
    }

    /* --------------------------------------------------------------------
     * CÁLCULO DEL PERIODO
     * ------------------------------------------------------------------ */

    /**
     * Estima IVA e ISR del mes. No escribe nada.
     *
     * @return array<string, mixed> importes en CENTAVOS, más avisos y las versiones de reglas usadas
     */
    public static function calcular(int $contribuyenteId, int $anio, int $mes, ?array $catalogos = null): array
    {
        $db = self::$db;
        $catalogos ??= self::catalogosActivos($anio);
        $avisos = $catalogos['avisos'];
        $finMes = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anio, $mes)));

        $r = [
            'contribuyente_id' => $contribuyenteId, 'anio' => $anio, 'mes' => $mes, 'habilitado' => true,
            'versiones' => [
                'isr' => $catalogos['isr']['id'] ?? null, 'iva' => $catalogos['iva']['id'] ?? null, 'reglas' => $catalogos['reglas']['id'] ?? null,
            ],
        ];

        $configuracion = RegistrosFiscales::configuracion($contribuyenteId, $finMes);
        if (!$configuracion) {
            $avisos[] = 'No hay configuración fiscal vigente para este periodo.';
            $r['habilitado'] = false;
        } else {
            $r['configuracion'] = [
                'tipo_persona' => $configuracion['tipo_persona'], 'regimen' => $configuracion['regimen'],
                'base' => $configuracion['base_ingresos'], 'confirmada_con_contador' => (int) $configuracion['confirmada_con_contador'] === 1,
            ];
            if ($configuracion['tipo_persona'] !== 'fisica') {
                $avisos[] = 'El motor calcula solo personas físicas con actividad empresarial: este cálculo está deshabilitado.';
                $r['habilitado'] = false;
            }
            if ((int) $configuracion['confirmada_con_contador'] !== 1) {
                $avisos[] = 'La configuración fiscal (régimen y base de flujo de efectivo) aún no está confirmada con el contador.';
            }
        }
        if (!$r['habilitado'] || !$catalogos['reglas']) {
            $r['avisos'] = $avisos;
            $r['habilitado'] = false;
            return $r;
        }

        $reglas = $catalogos['reglas']['doc'];
        $tasaIva = $catalogos['iva']['doc']['tasa_general'] ?? null;

        /* --- 1. Cobros prorrateados: base e IVA cobrados, mes a mes ------------------
         * SÓLO de facturas pagadas al 100 %: una con pagos en parcialidades (o sin cobro
         * confirmado) se trata como sin pagar y no aporta nada. Al confirmarse el pago,
         * sus cobros se reconocen en el mes en que se recibieron (prorrateo de IVA). */
        $liquidado = [];
        $pendiente = [];
        $mesExclusion = [];              // ingreso_id => mes (por FECHA de la factura) para declarar lo excluido
        $facturasExcluidas = [];         // ingreso_id => ['pendiente' => centavos, 'mes' => 1..12]
        $consulta = $db->prepare(
            "SELECT i.id, i.fecha, ROUND(i.total * 100) AS total,
                    COALESCE((SELECT SUM(ROUND(c.importe * 100)) FROM cobros c WHERE c.ingreso_id = i.id AND c.estado = 'vigente'), 0) AS cobrado
               FROM ingresos i
              WHERE i.estado = 'vigente' AND i.contribuyente_id = ?"
        );
        $consulta->bind_param('i', $contribuyenteId);
        $consulta->execute();
        foreach ($consulta->get_result() as $f) {
            $id = (int) $f['id'];
            $liquidado[$id] = (int) $f['cobrado'] >= (int) $f['total'];
            $pendiente[$id] = max(0, (int) $f['total'] - (int) $f['cobrado']);
            $mesExclusion[$id] = (int) substr((string) $f['fecha'], 5, 2);
            // Toda factura NO cobrada al 100 % queda declarada como excluida aunque no
            // tenga ni un cobro (así nada se cae en silencio: requisito de mostrar razones).
            if (!$liquidado[$id]) {
                $facturasExcluidas[$id] = ['pendiente' => $pendiente[$id], 'mes' => $mesExclusion[$id]];
            }
        }
        $consulta->close();

        $consulta = $db->prepare(
            "SELECT c.id, c.ingreso_id, c.fecha, c.importe, i.total, i.iva
               FROM cobros c JOIN ingresos i ON i.id = c.ingreso_id
              WHERE c.estado = 'vigente' AND i.estado = 'vigente' AND i.contribuyente_id = ? AND c.fecha <= ?
              ORDER BY c.ingreso_id, c.fecha, c.id"
        );
        $consulta->bind_param('is', $contribuyenteId, $finMes);
        $consulta->execute();
        $cobros = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $ingresoMes = array_fill(1, 12, 0);   // base cobrada (sin IVA) por mes
        $ivaMes = array_fill(1, 12, 0);       // IVA cobrado por mes
        $acumulado = [];                       // ingreso_id => [cobrado acumulado, iva acumulado]
        foreach ($cobros as $c) {
            $id = (int) $c['ingreso_id'];
            if (!($liquidado[$id] ?? false)) {
                // Pago parcial o sin cobro confirmado: se trata como sin pagar y no entra
                // (su exclusión ya quedó registrada arriba con su mes y saldo pendiente).
                continue;
            }
            $total = Contabilidad::centavos($c['total']);
            $iva = Contabilidad::centavos($c['iva']);
            [$cobradoAntes, $ivaAntes] = $acumulado[$id] ?? [0, 0];
            $cobradoAhora = $cobradoAntes + Contabilidad::centavos($c['importe']);
            $ivaAhora = $total > 0 ? (int) round($iva * min($cobradoAhora, $total) / $total) : 0;
            $acumulado[$id] = [$cobradoAhora, $ivaAhora];

            if ((int) substr($c['fecha'], 0, 4) === $anio) {
                $m = (int) substr($c['fecha'], 5, 2);
                $ivaCobro = $ivaAhora - $ivaAntes;
                $ivaMes[$m] += $ivaCobro;
                $ingresoMes[$m] += Contabilidad::centavos($c['importe']) - $ivaCobro;
            }
        }

        // Lo excluido por pago incompleto, para que el cálculo lo declare en vez de callarlo.
        $pendienteExcluido = array_sum(array_column($facturasExcluidas, 'pendiente'));
        $r['facturas_excluidas'] = [
            'n'         => count($facturasExcluidas),
            'pendiente' => $pendienteExcluido / 100.0,
        ];
        if ($facturasExcluidas) {
            $avisos[] = count($facturasExcluidas) . ' factura(s) con pagos en parcialidades (faltan '
                . number_format($pendienteExcluido / 100, 2, '.', '') . ' por cobrar) no se consideran: entran cuando se paguen al 100 %.';
        }

        /* --- 2. Gastos pagados que cumplen las reglas ------------------------------- */
        $consulta = $db->prepare(
            "SELECT id, fecha_pago, subtotal, iva, naturaleza, revision_fiscal, comprobante_fiscal
               FROM gastos WHERE estado = 'vigente' AND estado_pago = 'pagado' AND contribuyente_id = ?
                AND YEAR(fecha_pago) = ? AND MONTH(fecha_pago) <= ?"
        );
        $consulta->bind_param('iii', $contribuyenteId, $anio, $mes);
        $consulta->execute();
        $gastos = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $deduccionMes = array_fill(1, 12, 0);
        $ivaAcreditableMes = array_fill(1, 12, 0);
        $excluidos = ['no_validados' => 0, 'sin_comprobante' => 0, 'naturaleza' => 0, 'no_deducibles' => 0];
        $potencialIva = 0;

        foreach ($gastos as $g) {
            $m = (int) substr($g['fecha_pago'], 5, 2);
            $iva = Contabilidad::centavos($g['iva']);
            $subtotal = Contabilidad::centavos($g['subtotal']);
            if ($m === $mes) {
                $potencialIva += $g['revision_fiscal'] === 'no_deducible' ? 0 : $iva;
            }

            if ($g['revision_fiscal'] === 'no_deducible') {
                $excluidos['no_deducibles']++;
                continue;
            }
            if (!empty($reglas['gasto_requiere_validacion']) && $g['revision_fiscal'] !== 'validado') {
                $excluidos['no_validados']++;
                continue;
            }
            if (!empty($reglas['gasto_requiere_comprobante']) && (int) $g['comprobante_fiscal'] !== 1) {
                $excluidos['sin_comprobante']++;
                continue;
            }

            if (!in_array($g['naturaleza'], (array) ($reglas['naturalezas_excluidas_deduccion'] ?? []), true)) {
                $deduccionMes[$m] += $subtotal;
            } else {
                $excluidos['naturaleza']++;
            }
            if (!in_array($g['naturaleza'], (array) ($reglas['naturalezas_excluidas_iva_acreditable'] ?? []), true)) {
                $ivaAcreditableMes[$m] += $iva;
            }
        }

        /* --- 3. Retenciones y ajustes ----------------------------------------------- */
        $retIva = array_fill(1, 12, 0);
        $retIsr = array_fill(1, 12, 0);
        $consulta = $db->prepare(
            "SELECT MONTH(fecha) AS m, impuesto, SUM(ROUND(importe * 100)) AS c FROM retenciones
              WHERE estado = 'vigente' AND contribuyente_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) <= ? GROUP BY MONTH(fecha), impuesto"
        );
        $consulta->bind_param('iii', $contribuyenteId, $anio, $mes);
        $consulta->execute();
        foreach ($consulta->get_result() as $f) {
            if ($f['impuesto'] === 'IVA') {
                $retIva[(int) $f['m']] += (int) $f['c'];
            } else {
                $retIsr[(int) $f['m']] += (int) $f['c'];
            }
        }
        $consulta->close();

        $ajuste = ['saldo_favor_iva_anterior' => array_fill(1, 12, 0), 'otros_ajustes_iva' => array_fill(1, 12, 0),
            'ptu_pagada' => array_fill(1, 12, 0), 'perdidas_por_amortizar' => array_fill(1, 12, 0), 'otros_acreditamientos_isr' => array_fill(1, 12, 0)];
        $consulta = $db->prepare(
            "SELECT mes, concepto, SUM(ROUND(importe * 100)) AS c FROM ajustes_fiscales
              WHERE estado = 'vigente' AND contribuyente_id = ? AND anio = ? AND mes <= ? GROUP BY mes, concepto"
        );
        $consulta->bind_param('iii', $contribuyenteId, $anio, $mes);
        $consulta->execute();
        foreach ($consulta->get_result() as $f) {
            $ajuste[$f['concepto']][(int) $f['mes']] += (int) $f['c'];
        }
        $consulta->close();

        $declaraciones = RegistrosFiscales::declaraciones($contribuyenteId, $anio);

        /* --- 3b. Series por mes (centavos) y reglas aplicadas ------------------------
         * Exponen exactamente lo mismo que calculó este motor para que las pantallas y
         * el adaptador de ResumenFiscal no hagan SU propia cuenta (un solo motor). */
        $gastosNoPagadosN = array_fill(1, 12, 0);
        $gastosNoPagados = array_fill(1, 12, 0);
        $consulta = $db->prepare(
            "SELECT MONTH(COALESCE(fecha_pago, fecha)) AS m, COUNT(*) AS n, SUM(ROUND(total * 100)) AS t
               FROM gastos
              WHERE estado = 'vigente' AND estado_pago = 'pendiente' AND contribuyente_id = ?
                AND YEAR(COALESCE(fecha_pago, fecha)) = ?
              GROUP BY MONTH(COALESCE(fecha_pago, fecha))"
        );
        $consulta->bind_param('ii', $contribuyenteId, $anio);
        $consulta->execute();
        foreach ($consulta->get_result() as $f) {
            $gastosNoPagadosN[(int) $f['m']] = (int) $f['n'];
            $gastosNoPagados[(int) $f['m']] = (int) $f['t'];
        }
        $consulta->close();

        $porMes = [
            'ingreso' => $ingresoMes, 'iva_cobrado' => $ivaMes,
            'deduccion' => $deduccionMes, 'iva_acreditable' => $ivaAcreditableMes,
            'ret_iva' => $retIva, 'ret_isr' => $retIsr,
            'saldo_favor_iva' => $ajuste['saldo_favor_iva_anterior'], 'ajuste_iva' => $ajuste['otros_ajustes_iva'],
            'ptu' => $ajuste['ptu_pagada'], 'perdidas' => $ajuste['perdidas_por_amortizar'],
            'otros_isr' => $ajuste['otros_acreditamientos_isr'],
            'facturas_excluidas_n' => array_fill(1, 12, 0), 'facturas_excluidas' => array_fill(1, 12, 0),
            'gastos_no_pagados_n' => $gastosNoPagadosN, 'gastos_no_pagados' => $gastosNoPagados,
        ];
        foreach ($facturasExcluidas as $excluida) {
            $mx = max(1, min(12, (int) $excluida['mes']));
            $porMes['facturas_excluidas_n'][$mx]++;
            $porMes['facturas_excluidas'][$mx] += (int) $excluida['pendiente'];
        }
        $r['por_mes'] = $porMes;

        // Reglas y régimen aplicados (versionados en el catálogo): la pantalla los muestra
        // junto a cada exclusión para que se vea el porqué de cada importe fuera del cálculo.
        $r['reglas'] = [
            'base_ingresos' => $configuracion['base_ingresos'] ?? null,
            'tipo_persona' => $configuracion['tipo_persona'] ?? null,
            'regimen' => $configuracion['regimen'] ?? null,
            'gasto_requiere_validacion' => !empty($reglas['gasto_requiere_validacion']),
            'gasto_requiere_comprobante' => !empty($reglas['gasto_requiere_comprobante']),
            'naturalezas_excluidas_deduccion' => (array) ($reglas['naturalezas_excluidas_deduccion'] ?? []),
            'naturalezas_excluidas_iva_acreditable' => (array) ($reglas['naturalezas_excluidas_iva_acreditable'] ?? []),
            'iva_prorrateado_por_cobro' => !empty($reglas['iva_se_prorratea_por_cobro']),
            'tasa_iva_general' => $tasaIva,
        ];

        /* --- 4. IVA del mes ---------------------------------------------------------- */
        $iva = [
            'tasa_general' => $tasaIva,
            'trasladado_cobrado' => $ivaMes[$mes],
            'acreditable_considerado' => $ivaAcreditableMes[$mes],
            'acreditable_potencial' => $potencialIva,
            'retenciones' => $retIva[$mes],
            'saldo_favor_anterior' => $ajuste['saldo_favor_iva_anterior'][$mes],
            'otros_ajustes' => $ajuste['otros_ajustes_iva'][$mes],
        ];
        $netoIva = $iva['trasladado_cobrado'] - $iva['acreditable_considerado'] - $iva['retenciones']
            - $iva['saldo_favor_anterior'] - $iva['otros_ajustes'];
        $iva['a_cargo'] = max(0, $netoIva);
        $iva['saldo_a_favor'] = max(0, -$netoIva);
        $iva['habilitado'] = $catalogos['iva'] !== null;
        if (!$catalogos['iva']) {
            $iva['a_cargo'] = $iva['saldo_a_favor'] = null;
        }
        $r['iva'] = $iva;

        /* --- 5. ISR provisional acumulado ------------------------------------------- */
        $sumaHasta = static fn (array $arreglo): int => array_sum(array_slice($arreglo, 0, $mes));
        $ingresos = $sumaHasta($ingresoMes);
        $deducciones = $sumaHasta($deduccionMes);
        $ptu = $sumaHasta($ajuste['ptu_pagada']);
        $perdidas = $sumaHasta($ajuste['perdidas_por_amortizar']);
        $utilidad = max(0, $ingresos - $deducciones - $ptu - $perdidas);

        $pagosPrevios = 0;
        $faltantes = [];
        for ($m = 1; $m < $mes; $m++) {
            $d = $declaraciones['ISR-' . $m] ?? null;
            if ($d) {
                $pagosPrevios += Contabilidad::centavos($d['importe_pagado']);
            } else {
                $faltantes[] = $m;
            }
        }
        if ($faltantes) {
            $avisos[] = 'No has anotado pagos de ISR de los meses ' . implode(', ', $faltantes)
                . '. Si ya pagaste alguno, anótalo en «Retenciones y declaraciones» → «Declaraciones y pagos al SAT»; si no, el ISR de este cálculo saldrá más alto de lo real.';
        }

        $isr = [
            'ingresos_acumulados' => $ingresos, 'deducciones_acumuladas' => $deducciones, 'ptu_pagada' => $ptu,
            'perdidas_amortizadas' => $perdidas, 'utilidad_fiscal' => $utilidad,
            'retenciones_acumuladas' => $sumaHasta($retIsr), 'otros_acreditamientos' => $sumaHasta($ajuste['otros_acreditamientos_isr']),
            'pagos_provisionales_anteriores' => $pagosPrevios, 'meses_sin_pago_registrado' => $faltantes,
            'habilitado' => $catalogos['isr'] !== null,
        ];
        if ($catalogos['isr']) {
            $isr['impuesto_determinado'] = self::impuestoSegunTarifa($utilidad, (array) ($catalogos['isr']['doc']['tarifas'][(string) $mes] ?? []));
            $acreditable = $isr['retenciones_acumuladas'] + $isr['otros_acreditamientos'] + $pagosPrevios;
            $diferencia = $isr['impuesto_determinado'] - $acreditable;
            $isr['acreditable_total'] = $acreditable;
            $isr['a_pagar'] = max(0, $diferencia);
            $isr['excedente_acreditable'] = max(0, -$diferencia);
        } else {
            $isr['impuesto_determinado'] = $isr['a_pagar'] = $isr['excedente_acreditable'] = $isr['acreditable_total'] = null;
        }
        $r['isr'] = $isr;

        /* --- 6. Gastos que no entraron, para que se vea por qué ---------------------- */
        $r['gastos_excluidos'] = $excluidos;
        if ($excluidos['no_validados'] > 0) {
            $avisos[] = $excluidos['no_validados'] . ' gasto(s) pagado(s) siguen «por validar» y no se restan en el cálculo.';
        }
        if ($excluidos['sin_comprobante'] > 0) {
            $avisos[] = $excluidos['sin_comprobante'] . ' gasto(s) pagado(s) no tienen factura y no se restan de tus impuestos.';
        }

        /* --- 7. Pagos reales registrados y comparación ------------------------------ */
        $realIsr = $declaraciones['ISR-' . $mes] ?? null;
        $realIva = $declaraciones['IVA-' . $mes] ?? null;
        $r['pagos_reales'] = [
            'isr' => $realIsr ? ['declarado' => Contabilidad::centavos($realIsr['importe_declarado']), 'pagado' => Contabilidad::centavos($realIsr['importe_pagado'])] : null,
            'iva' => $realIva ? ['declarado' => Contabilidad::centavos($realIva['importe_declarado']), 'saldo_favor' => Contabilidad::centavos($realIva['saldo_favor']),
                'pagado' => Contabilidad::centavos($realIva['importe_pagado'])] : null,
        ];
        $r['comparacion'] = [
            'isr' => ($realIsr && $isr['a_pagar'] !== null) ? $isr['a_pagar'] - Contabilidad::centavos($realIsr['importe_declarado']) : null,
            'iva' => ($realIva && $iva['a_cargo'] !== null)
                ? ($iva['a_cargo'] - $iva['saldo_a_favor']) - (Contabilidad::centavos($realIva['importe_declarado']) - Contabilidad::centavos($realIva['saldo_favor']))
                : null,
        ];
        $r['pendiente_de_pago'] = [
            'isr' => $isr['a_pagar'] !== null ? max(0, ($realIsr ? Contabilidad::centavos($realIsr['importe_declarado']) : $isr['a_pagar']) - ($realIsr ? Contabilidad::centavos($realIsr['importe_pagado']) : 0)) : null,
            'iva' => $iva['a_cargo'] !== null ? max(0, ($realIva ? Contabilidad::centavos($realIva['importe_declarado']) : $iva['a_cargo']) - ($realIva ? Contabilidad::centavos($realIva['importe_pagado']) : 0)) : null,
        ];

        $r['avisos'] = $avisos;
        $r['aviso_legal'] = 'Estimación administrativa calculada con las reglas instaladas. Valídala con tu contador antes de presentar declaraciones.';

        return $r;
    }

    /**
     * Guarda una fotografía del cálculo con las versiones de reglas usadas.
     *
     * @return int id del cálculo guardado, 0 si falló
     */
    public static function guardarCalculo(array $calculo, string $tipo, int $usuarioId): int
    {
        $db = self::$db;
        $entradas = json_encode(['contribuyente_id' => $calculo['contribuyente_id'], 'anio' => $calculo['anio'], 'mes' => $calculo['mes'], 'versiones' => $calculo['versiones']], JSON_UNESCAPED_UNICODE);
        $resultado = json_encode($calculo, JSON_UNESCAPED_UNICODE);
        $huella = hash('sha256', $resultado);

        $consulta = $db->prepare(
            'INSERT INTO calculos_fiscales (contribuyente_id, anio, mes, tipo, catalogo_isr_id, catalogo_iva_id, catalogo_reglas_id, entradas, resultado, sha256, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $contribuyente = (int) $calculo['contribuyente_id'];
        $anio = (int) $calculo['anio'];
        $mes = (int) $calculo['mes'];
        $isr = $calculo['versiones']['isr'];
        $iva = $calculo['versiones']['iva'];
        $reglas = $calculo['versiones']['reglas'];
        $consulta->bind_param('iiisiiisssi', $contribuyente, $anio, $mes, $tipo, $isr, $iva, $reglas, $entradas, $resultado, $huella, $usuarioId);
        $consulta->execute();
        $id = (int) $db->insert_id;
        $consulta->close();

        Auditoria::registrar('calculo_fiscal', $id, 'guardar', $usuarioId, null, ['periodo' => sprintf('%04d-%02d', $anio, $mes), 'tipo' => $tipo, 'sha256' => $huella]);

        return $id;
    }
}
