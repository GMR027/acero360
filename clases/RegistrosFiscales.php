<?php

namespace App;

/**
 * RegistrosFiscales — configuración fiscal con historial y los datos de entrada del motor
 * que no salen de las ventas ni de los gastos:
 *   • retenciones de ISR/IVA que los clientes le hicieron
 *   • ajustes (saldo a favor de IVA anterior, PTU pagada, pérdidas por amortizar, otros acreditamientos)
 *   • declaraciones: lo que se DECLARÓ y PAGÓ realmente (para comparar con la estimación)
 *
 * Reglas: nada se borra (se cancela con motivo y bitácora). Retenciones y ajustes de un mes cerrado
 * no se tocan; las declaraciones sí pueden registrarse después del cierre (son hechos externos y
 * ocurren a posteriori) pero quedan en auditoría.
 */
class RegistrosFiscales
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    public const CONCEPTOS_AJUSTE = [
        'saldo_favor_iva_anterior'  => 'Saldo a favor de IVA de periodos anteriores (solo ese mes)',
        'otros_ajustes_iva'         => 'Otros ajustes que disminuyen el IVA a cargo (solo ese mes)',
        'ptu_pagada'                => 'PTU pagada a trabajadores (disminuye la utilidad desde ese mes)',
        'perdidas_por_amortizar'    => 'Pérdidas fiscales por amortizar (disminuyen la utilidad desde ese mes)',
        'otros_acreditamientos_isr' => 'Otros acreditamientos contra el ISR (desde ese mes)',
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

    private static function mesCerrado(int $contribuyenteId, int $anio, int $mes): bool
    {
        return Contabilidad::periodoCerrado($contribuyenteId, sprintf('%04d-%02d-01', $anio, $mes));
    }

    /* --------------------------------------------------------------------
     * CONFIGURACIÓN FISCAL (con historial)
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null configuración que rige en $fecha */
    public static function configuracion(int $contribuyenteId, ?string $fecha = null): ?array
    {
        $fecha ??= date('Y-m-d');
        $consulta = self::$db->prepare(
            'SELECT * FROM configuracion_fiscal WHERE contribuyente_id = ? AND vigente_desde <= ?
                AND (vigente_hasta IS NULL OR vigente_hasta >= ?) ORDER BY vigente_desde DESC, id DESC LIMIT 1'
        );
        $consulta->bind_param('iss', $contribuyenteId, $fecha, $fecha);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function historialConfiguracion(int $contribuyenteId): array
    {
        $consulta = self::$db->prepare(
            'SELECT c.*, u.nombre AS usuario_nombre FROM configuracion_fiscal c LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.contribuyente_id = ? ORDER BY c.vigente_desde DESC, c.id DESC'
        );
        $consulta->bind_param('i', $contribuyenteId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Cambio controlado de la configuración: contraseña, motivo y bitácora; conserva el historial. */
    public static function cambiarConfiguracion(
        int $contribuyenteId,
        string $vigenteDesde,
        string $tipoPersona,
        string $regimen,
        bool $confirmada,
        string $motivo,
        int $usuarioId,
        string $contrasena
    ): bool {
        self::$errores = [];
        $regimen = trim($regimen);
        $motivo = trim($motivo);

        if (!in_array($tipoPersona, ['fisica', 'moral'], true)) {
            self::$errores[] = 'Tipo de persona no válido.';
        }
        if ($regimen === '' || mb_strlen($regimen) > 120) {
            self::$errores[] = 'Indica el régimen fiscal.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigenteDesde) || !strtotime($vigenteDesde)) {
            self::$errores[] = 'La fecha de inicio de vigencia no es válida.';
        }
        if (mb_strlen($motivo) < 10) {
            self::$errores[] = 'Escribe el motivo del cambio (mínimo 10 caracteres).';
        }
        if (self::$errores) {
            return false;
        }
        if (!CatalogoFiscal::autorizar($usuarioId, $contrasena)) {
            Auditoria::registrar('configuracion_fiscal', $contribuyenteId, 'cambio_rechazado', $usuarioId, 'Contraseña de autorización incorrecta');
            self::$errores[] = 'Autorización rechazada: la contraseña no es correcta.';
            return false;
        }
        // Se permite registrar una persona moral, pero el motor fiscal se niega a calcularla (ver MotorFiscal).

        try {
            self::$db->begin_transaction();

            $ultima = self::$db->prepare('SELECT id, vigente_desde FROM configuracion_fiscal WHERE contribuyente_id = ? AND vigente_hasta IS NULL FOR UPDATE');
            $ultima->bind_param('i', $contribuyenteId);
            $ultima->execute();
            $actual = $ultima->get_result()->fetch_assoc();
            $ultima->close();

            if ($actual && $vigenteDesde <= $actual['vigente_desde']) {
                self::$db->rollBack();
                self::$errores[] = 'La nueva configuración debe iniciar después de la vigente (' . $actual['vigente_desde'] . ').';
                return false;
            }

            // Un periodo cerrado no cambia de reglas con efecto retroactivo.
            $cerrado = self::$db->prepare(
                "SELECT anio, mes FROM periodos_contables WHERE contribuyente_id = ? AND estado = 'cerrado'
                    AND STR_TO_DATE(CONCAT(anio, '-', mes, '-01'), '%Y-%m-%d') >= ? LIMIT 1"
            );
            $primerDia = substr($vigenteDesde, 0, 8) . '01';
            $cerrado->bind_param('is', $contribuyenteId, $primerDia);
            $cerrado->execute();
            $choque = $cerrado->get_result()->fetch_assoc();
            $cerrado->close();
            if ($choque) {
                self::$db->rollBack();
                self::$errores[] = sprintf('El periodo %04d-%02d ya está cerrado: la nueva configuración no puede aplicarse a un periodo cerrado.', $choque['anio'], $choque['mes']);
                return false;
            }

            if ($actual) {
                $cerrar = self::$db->prepare('UPDATE configuracion_fiscal SET vigente_hasta = DATE_SUB(?, INTERVAL 1 DAY) WHERE id = ?');
                $actualId = (int) $actual['id'];
                $cerrar->bind_param('si', $vigenteDesde, $actualId);
                $cerrar->execute();
                $cerrar->close();
            }

            $crear = self::$db->prepare(
                'INSERT INTO configuracion_fiscal (contribuyente_id, vigente_desde, tipo_persona, regimen, confirmada_con_contador, motivo_cambio, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $conf = $confirmada ? 1 : 0;
            $crear->bind_param('isssisi', $contribuyenteId, $vigenteDesde, $tipoPersona, $regimen, $conf, $motivo, $usuarioId);
            $crear->execute();
            $id = (int) self::$db->insert_id;
            $crear->close();

            Auditoria::registrar('configuracion_fiscal', $id, 'cambio', $usuarioId, $motivo, [
                'desde' => $vigenteDesde, 'persona' => $tipoPersona, 'regimen' => $regimen,
            ]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[RegistrosFiscales::cambiarConfiguracion] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible guardar la configuración.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * RETENCIONES
     * ------------------------------------------------------------------ */

    public static function registrarRetencion(
        int $contribuyenteId, string $fecha, string $impuesto, $importe, string $retenedor, ?int $ingresoId, string $nota, int $usuarioId
    ): bool {
        self::$errores = [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            self::$errores[] = 'La fecha de la retención no es válida.';
        }
        if (!in_array($impuesto, ['ISR', 'IVA'], true)) {
            self::$errores[] = 'El impuesto debe ser ISR o IVA.';
        }
        if (!is_numeric($importe) || Contabilidad::centavos($importe) <= 0) {
            self::$errores[] = 'El importe retenido debe ser mayor a cero.';
        }
        if (trim($retenedor) === '') {
            self::$errores[] = 'Indica quién hizo la retención.';
        }
        if (self::$errores) {
            return false;
        }
        if (self::mesCerrado($contribuyenteId, (int) substr($fecha, 0, 4), (int) substr($fecha, 5, 2))) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado($fecha);
            return false;
        }

        $monto = number_format(Contabilidad::centavos($importe) / 100, 2, '.', '');
        $nota = trim($nota) ?: null;
        $retenedor = mb_substr(trim($retenedor), 0, 150);
        $consulta = self::$db->prepare(
            'INSERT INTO retenciones (contribuyente_id, fecha, impuesto, importe, retenedor, ingreso_id, nota, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $consulta->bind_param('issssisi', $contribuyenteId, $fecha, $impuesto, $monto, $retenedor, $ingresoId, $nota, $usuarioId);
        $consulta->execute();
        $id = (int) self::$db->insert_id;
        $consulta->close();

        Auditoria::registrar('retencion', $id, 'crear', $usuarioId, null, ['impuesto' => $impuesto, 'importe' => $monto]);
        return true;
    }

    public static function cancelarRetencion(int $id, string $motivo, int $usuarioId): bool
    {
        return self::cancelar('retenciones', 'retencion', $id, $motivo, $usuarioId, 'fecha');
    }

    public static function cancelarAjuste(int $id, string $motivo, int $usuarioId): bool
    {
        return self::cancelar('ajustes_fiscales', 'ajuste_fiscal', $id, $motivo, $usuarioId, null);
    }

    private static function cancelar(string $tabla, string $entidad, int $id, string $motivo, int $usuarioId, ?string $campoFecha): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Escribe el motivo de la cancelación (mínimo 5 caracteres).';
            return false;
        }

        $consulta = self::$db->prepare("SELECT * FROM $tabla WHERE id = ?");
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$fila || $fila['estado'] !== 'vigente') {
            self::$errores[] = 'El registro no existe o ya está cancelado.';
            return false;
        }
        $anio = $campoFecha ? (int) substr($fila[$campoFecha], 0, 4) : (int) $fila['anio'];
        $mes = $campoFecha ? (int) substr($fila[$campoFecha], 5, 2) : (int) $fila['mes'];
        if (self::mesCerrado((int) $fila['contribuyente_id'], $anio, $mes)) {
            self::$errores[] = sprintf('El periodo %04d-%02d está cerrado: reábrelo con el procedimiento autorizado.', $anio, $mes);
            return false;
        }

        $cancelar = self::$db->prepare("UPDATE $tabla SET estado = 'cancelado', motivo_cancelacion = ? WHERE id = ?");
        $cancelar->bind_param('si', $motivo, $id);
        $cancelar->execute();
        $cancelar->close();

        Auditoria::registrar($entidad, $id, 'cancelar', $usuarioId, $motivo, ['importe' => $fila['importe']]);
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public static function retenciones(int $contribuyenteId, int $anio): array
    {
        $consulta = self::$db->prepare(
            'SELECT * FROM retenciones WHERE contribuyente_id = ? AND YEAR(fecha) = ? ORDER BY fecha DESC, id DESC'
        );
        $consulta->bind_param('ii', $contribuyenteId, $anio);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /* --------------------------------------------------------------------
     * AJUSTES FISCALES
     * ------------------------------------------------------------------ */

    public static function registrarAjuste(int $contribuyenteId, int $anio, int $mes, string $concepto, $importe, string $nota, int $usuarioId): bool
    {
        self::$errores = [];

        if (!isset(self::CONCEPTOS_AJUSTE[$concepto])) {
            self::$errores[] = 'Concepto de ajuste no reconocido.';
        }
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            self::$errores[] = 'El periodo del ajuste no es válido.';
        }
        if (!is_numeric($importe) || Contabilidad::centavos($importe) <= 0) {
            self::$errores[] = 'El importe del ajuste debe ser mayor a cero.';
        }
        if (self::$errores) {
            return false;
        }
        if (self::mesCerrado($contribuyenteId, $anio, $mes)) {
            self::$errores[] = sprintf('El periodo %04d-%02d está cerrado: reábrelo con el procedimiento autorizado.', $anio, $mes);
            return false;
        }

        $monto = number_format(Contabilidad::centavos($importe) / 100, 2, '.', '');
        $nota = trim($nota) ?: null;
        $consulta = self::$db->prepare(
            'INSERT INTO ajustes_fiscales (contribuyente_id, anio, mes, concepto, importe, nota, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $consulta->bind_param('iiisssi', $contribuyenteId, $anio, $mes, $concepto, $monto, $nota, $usuarioId);
        $consulta->execute();
        $id = (int) self::$db->insert_id;
        $consulta->close();

        Auditoria::registrar('ajuste_fiscal', $id, 'crear', $usuarioId, null, ['concepto' => $concepto, 'importe' => $monto]);
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public static function ajustes(int $contribuyenteId, int $anio): array
    {
        $consulta = self::$db->prepare('SELECT * FROM ajustes_fiscales WHERE contribuyente_id = ? AND anio = ? ORDER BY mes DESC, id DESC');
        $consulta->bind_param('ii', $contribuyenteId, $anio);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /* --------------------------------------------------------------------
     * DECLARACIONES (importes reales)
     * ------------------------------------------------------------------ */

    /** Registra o corrige lo declarado y pagado de un impuesto/periodo. */
    public static function guardarDeclaracion(
        int $contribuyenteId, int $anio, int $mes, string $impuesto,
        $declarado, $saldoFavor, $pagado, string $fechaPresentacion, string $fechaPago, string $referencia, string $nota, int $usuarioId
    ): bool {
        self::$errores = [];

        if (!in_array($impuesto, ['ISR', 'IVA'], true)) {
            self::$errores[] = 'El impuesto debe ser ISR o IVA.';
        }
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            self::$errores[] = 'El periodo no es válido.';
        }
        foreach (['declarado' => $declarado, 'saldo a favor' => $saldoFavor, 'pagado' => $pagado] as $nombre => $v) {
            if (!is_numeric($v) || Contabilidad::centavos($v) < 0) {
                self::$errores[] = "El importe $nombre debe ser un número igual o mayor a cero.";
            }
        }
        foreach (['fechaPresentacion' => $fechaPresentacion, 'fechaPago' => $fechaPago] as $v) {
            if ($v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v))) {
                self::$errores[] = 'Una de las fechas no es válida.';
                break;
            }
        }
        if (self::$errores) {
            return false;
        }

        $f = static fn ($v) => number_format(Contabilidad::centavos($v) / 100, 2, '.', '');
        [$d, $s, $p] = [$f($declarado), $f($saldoFavor), $f($pagado)];
        $fp = $fechaPresentacion ?: null;
        $fg = $fechaPago ?: null;
        $ref = trim($referencia) ?: null;
        $nota = trim($nota) ?: null;

        $consulta = self::$db->prepare(
            'INSERT INTO declaraciones (contribuyente_id, anio, mes, impuesto, importe_declarado, saldo_favor, importe_pagado,
                    fecha_presentacion, fecha_pago, referencia, nota, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE importe_declarado = VALUES(importe_declarado), saldo_favor = VALUES(saldo_favor),
                    importe_pagado = VALUES(importe_pagado), fecha_presentacion = VALUES(fecha_presentacion),
                    fecha_pago = VALUES(fecha_pago), referencia = VALUES(referencia), nota = VALUES(nota), usuario_id = VALUES(usuario_id)'
        );
        $consulta->bind_param('iiissssssssi', $contribuyenteId, $anio, $mes, $impuesto, $d, $s, $p, $fp, $fg, $ref, $nota, $usuarioId);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('declaracion', null, 'guardar', $usuarioId, null, [
            'periodo' => sprintf('%04d-%02d', $anio, $mes), 'impuesto' => $impuesto, 'declarado' => $d, 'pagado' => $p,
            'periodo_cerrado' => self::mesCerrado($contribuyenteId, $anio, $mes),
        ]);
        return true;
    }

    /** @return array<string, array<string, mixed>> clave "IMPUESTO-mes" */
    public static function declaraciones(int $contribuyenteId, int $anio): array
    {
        $consulta = self::$db->prepare('SELECT * FROM declaraciones WHERE contribuyente_id = ? AND anio = ? ORDER BY mes, impuesto');
        $consulta->bind_param('ii', $contribuyenteId, $anio);
        $consulta->execute();
        $mapa = [];
        foreach ($consulta->get_result() as $fila) {
            $mapa[$fila['impuesto'] . '-' . (int) $fila['mes']] = $fila;
        }
        $consulta->close();

        return $mapa;
    }
}
