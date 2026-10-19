<?php

namespace App;

/**
 * CatalogoFiscal — reglas y tarifas fiscales VERSIONADAS.
 *
 * Ciclo de vida de una versión:  borrador → validada → activa → reemplazada.
 *   1. importar():  guarda el archivo como borrador (con fuente oficial, vigencia y huella).
 *                   Nunca modifica una versión existente: siempre crea la siguiente.
 *   2. validar():   formato y datos + pruebas de regresión (casos del archivo + comparación
 *                   contra la versión activa). Solo una versión sin errores pasa a «validada».
 *   3. activar():   requiere autorización explícita (contraseña del administrador y motivo).
 *                   La versión activa anterior queda «reemplazada» y se conserva intacta.
 *   4. Los cálculos guardados (calculos_fiscales) apuntan a la versión usada: cambiar la
 *      tarifa después no reescribe los resultados históricos.
 *
 * No se descarga nada automáticamente: los archivos se importan de forma controlada.
 * Importes en centavos enteros.
 */
class CatalogoFiscal
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    public static int $ultimoId = 0;

    public const TIPOS = [
        'isr_pagos_provisionales_art106' => 'ISR · pagos provisionales (art. 106 LISR)',
        'iva_tasas'                      => 'IVA · tasa general',
        'reglas_plataforma'              => 'Criterios de la plataforma (deducciones y acreditamiento)',
    ];

    public const ESTADOS = [
        'borrador'    => ['etiqueta' => 'Borrador',    'clase' => 'badge--pendiente'],
        'validada'    => ['etiqueta' => 'Validada',    'clase' => 'badge--info'],
        'activa'      => ['etiqueta' => 'Activa',      'clase' => 'badge--aprobado'],
        'reemplazada' => ['etiqueta' => 'Reemplazada', 'clase' => 'badge--neutro'],
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
     * LECTURA
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null con `doc` ya decodificado */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM catalogo_fiscal WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? self::decodificar($fila) : null;
    }

    /** Versión ACTIVA de un tipo para un ejercicio, o null. */
    public static function activa(string $tipo, int $ejercicio): ?array
    {
        $consulta = self::$db->prepare("SELECT * FROM catalogo_fiscal WHERE tipo = ? AND ejercicio = ? AND estado = 'activa' LIMIT 1");
        $consulta->bind_param('si', $tipo, $ejercicio);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? self::decodificar($fila) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listar(): array
    {
        $filas = self::$db->query(
            'SELECT c.id, c.tipo, c.ejercicio, c.version, c.nombre, c.estado, c.vigencia_desde, c.vigencia_hasta,
                    c.fuente_url, c.creado_en, c.activada_en, u.nombre AS creado_por_nombre
               FROM catalogo_fiscal c LEFT JOIN usuarios u ON u.id = c.creado_por
              ORDER BY c.ejercicio DESC, c.tipo, c.version DESC'
        )->fetch_all(MYSQLI_ASSOC);

        return $filas;
    }

    private static function decodificar(array $fila): array
    {
        $fila['doc'] = json_decode((string) $fila['datos'], true) ?: [];
        $fila['validacion'] = $fila['resultado_validacion'] ? (json_decode((string) $fila['resultado_validacion'], true) ?: []) : [];

        return $fila;
    }

    /* --------------------------------------------------------------------
     * IMPORTAR (siempre crea una versión nueva en borrador)
     * ------------------------------------------------------------------ */

    public static function importar(string $json, int $usuarioId): bool
    {
        self::$errores = [];

        if (strlen($json) > 2 * 1048576) {
            self::$errores[] = 'El archivo es demasiado grande (máximo 2 MB).';
            return false;
        }

        $doc = json_decode($json, true);
        if (!is_array($doc)) {
            self::$errores[] = 'El archivo no es un JSON válido.';
            return false;
        }

        $tipo = (string) ($doc['tipo'] ?? '');
        $ejercicio = (int) ($doc['ejercicio'] ?? 0);
        $fuente = (array) ($doc['fuente'] ?? []);

        if (!isset(self::TIPOS[$tipo])) {
            self::$errores[] = 'Tipo de catálogo no reconocido: «' . $tipo . '».';
        }
        if ($ejercicio < 2000 || $ejercicio > 2100) {
            self::$errores[] = 'El ejercicio fiscal no es válido.';
        }
        foreach (['documento', 'url'] as $campo) {
            if (trim((string) ($fuente[$campo] ?? '')) === '') {
                self::$errores[] = "Falta la fuente oficial ($campo): toda regla debe citar de dónde sale.";
            }
        }
        if (!preg_match('#^https?://#i', (string) ($fuente['url'] ?? ''))) {
            self::$errores[] = 'La URL de la fuente debe empezar con http:// o https://.';
        }
        foreach (['vigencia_desde', 'vigencia_hasta'] as $campo) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($doc[$campo] ?? '')) || !strtotime((string) $doc[$campo])) {
                self::$errores[] = "Falta la vigencia ($campo) o no es una fecha válida.";
            }
        }
        if (self::$errores) {
            return false;
        }
        if ($doc['vigencia_desde'] > $doc['vigencia_hasta']) {
            self::$errores[] = 'La vigencia inicia después de terminar.';
            return false;
        }

        // Se normaliza el JSON para que la huella sea estable.
        $normalizado = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $huella = hash('sha256', $normalizado);

        try {
            self::$db->begin_transaction();

            $consulta = self::$db->prepare('SELECT COALESCE(MAX(version), 0) AS v FROM catalogo_fiscal WHERE tipo = ? AND ejercicio = ? FOR UPDATE');
            $consulta->bind_param('si', $tipo, $ejercicio);
            $consulta->execute();
            $version = (int) $consulta->get_result()->fetch_assoc()['v'] + 1;
            $consulta->close();

            $nombre = mb_substr((string) ($doc['nombre'] ?? self::TIPOS[$tipo]), 0, 200);
            $documento = mb_substr((string) $fuente['documento'], 0, 255);
            $url = mb_substr((string) $fuente['url'], 0, 500);
            $publicacion = mb_substr((string) ($fuente['publicacion'] ?? ''), 0, 200) ?: null;
            $sha = preg_match('/^[a-f0-9]{64}$/', (string) ($fuente['sha256_pdf'] ?? '')) ? $fuente['sha256_pdf'] : null;

            $crear = self::$db->prepare(
                'INSERT INTO catalogo_fiscal (tipo, ejercicio, version, nombre, fuente_documento, fuente_url, fuente_publicacion,
                        sha256_fuente, vigencia_desde, vigencia_hasta, datos, sha256_datos, creado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $crear->bind_param(
                'siisssssssssi',
                $tipo, $ejercicio, $version, $nombre, $documento, $url, $publicacion, $sha,
                $doc['vigencia_desde'], $doc['vigencia_hasta'], $normalizado, $huella, $usuarioId
            );
            $crear->execute();
            $id = (int) self::$db->insert_id;
            $crear->close();

            Auditoria::registrar('catalogo_fiscal', $id, 'importar', $usuarioId, null, [
                'tipo' => $tipo, 'ejercicio' => $ejercicio, 'version' => $version, 'sha256' => $huella,
            ]);

            self::$db->commit();
            self::$ultimoId = $id;
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[CatalogoFiscal::importar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible guardar la versión.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * VALIDAR (formato + datos + pruebas de regresión)
     * ------------------------------------------------------------------ */

    /**
     * @return array{ok: bool, errores: string[], avisos: string[], pruebas: array{total: int, falladas: int}, comparacion: array<string, mixed>}
     */
    public static function evaluar(array $doc, ?array $activaPrevia = null): array
    {
        $errores = [];
        $avisos = [];
        $pruebas = ['total' => 0, 'falladas' => 0];
        $comparacion = [];

        switch ($doc['tipo'] ?? '') {
            case 'isr_pagos_provisionales_art106':
                [$e, $a] = self::validarTarifas($doc);
                array_push($errores, ...$e);
                array_push($avisos, ...$a);

                if (!$errores) {
                    $casos = (array) ($doc['casos_prueba'] ?? []);
                    if (!$casos) {
                        $avisos[] = 'El archivo no trae casos de prueba: la regresión solo compara contra la versión activa.';
                    }
                    foreach ($casos as $caso) {
                        $pruebas['total']++;
                        $calculado = MotorFiscal::impuestoSegunTarifa(
                            Contabilidad::centavos($caso['utilidad'] ?? 0),
                            (array) ($doc['tarifas'][(string) ($caso['mes'] ?? 0)] ?? [])
                        );
                        if ($calculado !== Contabilidad::centavos($caso['impuesto'] ?? -1)) {
                            $pruebas['falladas']++;
                            $errores[] = sprintf(
                                'Caso fallido: mes %s, utilidad %s → esperado %s, calculado %s.',
                                $caso['mes'] ?? '?', $caso['utilidad'] ?? '?', $caso['impuesto'] ?? '?', number_format($calculado / 100, 2)
                            );
                        }
                    }

                    if ($activaPrevia && ($activaPrevia['doc']['tipo'] ?? '') === $doc['tipo']) {
                        $diferencias = 0;
                        $revisados = 0;
                        foreach ([1, 3, 6, 10, 12] as $mes) {
                            foreach ([100000, 500000, 5000000, 50000000, 500000000] as $util) {
                                $revisados++;
                                $nuevo = MotorFiscal::impuestoSegunTarifa($util, (array) $doc['tarifas'][(string) $mes]);
                                $viejo = MotorFiscal::impuestoSegunTarifa($util, (array) ($activaPrevia['doc']['tarifas'][(string) $mes] ?? []));
                                $diferencias += $nuevo !== $viejo ? 1 : 0;
                            }
                        }
                        $comparacion = ['contra_version' => (int) $activaPrevia['version'], 'revisados' => $revisados, 'diferencias' => $diferencias];
                        if ($diferencias > 0) {
                            $avisos[] = "Frente a la versión activa (v{$activaPrevia['version']}) cambian $diferencias de $revisados resultados de referencia. "
                                . 'Los cálculos ya guardados conservan la versión con la que se hicieron.';
                        }
                    }
                }
                break;

            case 'iva_tasas':
                $tasa = $doc['tasa_general'] ?? null;
                if (!is_numeric($tasa) || (float) $tasa <= 0 || (float) $tasa > 30) {
                    $errores[] = 'La tasa general debe ser un número mayor a 0 y menor o igual a 30.';
                } elseif ((float) $tasa !== 16.0) {
                    $avisos[] = 'La tasa general difiere del 16 % vigente: confirma la fuente antes de activar.';
                }
                $pruebas['total']++;
                if (!$errores && MotorFiscal::ivaDe(100000, (string) $tasa) !== (int) round(100000 * (float) $tasa / 100)) {
                    $pruebas['falladas']++;
                    $errores[] = 'La prueba de cálculo de IVA no coincide.';
                }
                break;

            case 'reglas_plataforma':
                foreach (['gasto_requiere_validacion', 'gasto_requiere_comprobante', 'iva_se_prorratea_por_cobro'] as $clave) {
                    if (!array_key_exists($clave, $doc) || !is_bool($doc[$clave])) {
                        $errores[] = "La regla «$clave» debe ser verdadero o falso.";
                    }
                }
                if (($doc['base_ingresos'] ?? '') !== 'flujo_efectivo') {
                    $errores[] = 'Solo se soporta la base de ingresos «flujo_efectivo».';
                }
                foreach (['naturalezas_excluidas_deduccion', 'naturalezas_excluidas_iva_acreditable'] as $clave) {
                    foreach ((array) ($doc[$clave] ?? []) as $n) {
                        if (!isset(Gastos::NATURALEZAS[$n])) {
                            $errores[] = "«$n» no es una naturaleza de gasto conocida ($clave).";
                        }
                    }
                }
                $avisos[] = 'Estos criterios son de la plataforma, no texto de ley: deben revisarse con el contador.';
                break;

            default:
                $errores[] = 'Tipo de catálogo no reconocido.';
        }

        return [
            'ok' => !$errores, 'errores' => $errores, 'avisos' => array_values(array_unique($avisos)),
            'pruebas' => $pruebas, 'comparacion' => $comparacion,
        ];
    }

    /** @return array{0: string[], 1: string[]} errores y avisos de la estructura de tarifas */
    private static function validarTarifas(array $doc): array
    {
        $errores = [];
        $avisos = [];
        $tarifas = (array) ($doc['tarifas'] ?? []);

        for ($mes = 1; $mes <= 12; $mes++) {
            $filas = array_values((array) ($tarifas[(string) $mes] ?? []));
            if (count($filas) < 2) {
                $errores[] = "Falta la tarifa del mes $mes.";
                continue;
            }

            $previa = null;
            foreach ($filas as $i => $f) {
                foreach (['limite_inferior', 'cuota_fija', 'porcentaje'] as $campo) {
                    if (!isset($f[$campo]) || !is_numeric($f[$campo])) {
                        $errores[] = "Mes $mes, renglón " . ($i + 1) . ": «$campo» no es numérico.";
                        continue 2;
                    }
                }
                $li = Contabilidad::centavos($f['limite_inferior']);
                $cf = Contabilidad::centavos($f['cuota_fija']);
                $pct = (float) $f['porcentaje'];
                $ultima = $i === count($filas) - 1;

                if ($pct <= 0 || $pct > 100) {
                    $errores[] = "Mes $mes, renglón " . ($i + 1) . ': porcentaje fuera de rango.';
                }
                if ($ultima && $f['limite_superior'] !== null) {
                    $errores[] = "Mes $mes: el último tramo debe quedar abierto («En adelante»).";
                }
                if (!$ultima && !is_numeric($f['limite_superior'] ?? null)) {
                    $errores[] = "Mes $mes, renglón " . ($i + 1) . ': falta el límite superior.';
                    continue;
                }

                if ($previa === null) {
                    if ($li !== 1) {
                        $errores[] = "Mes $mes: el primer límite inferior debe ser 0.01.";
                    }
                } else {
                    $ls = Contabilidad::centavos($previa['limite_superior']);
                    if ($li !== $ls + 1) {
                        $errores[] = "Mes $mes, renglón " . ($i + 1) . ': el límite inferior no continúa al superior del renglón anterior.';
                    }
                    if ($pct <= (float) $previa['porcentaje']) {
                        $errores[] = "Mes $mes, renglón " . ($i + 1) . ': el porcentaje debe ser creciente.';
                    }
                    // La cuota fija debe encadenarse: cf_prev + (ls_prev − li_prev) × pct_prev (tolerancia de redondeo del SAT).
                    $esperada = Contabilidad::centavos($previa['cuota_fija'])
                        + (int) round((Contabilidad::centavos($previa['limite_superior']) - Contabilidad::centavos($previa['limite_inferior'])) * (float) $previa['porcentaje'] / 100);
                    if (abs($cf - $esperada) > 10 * $mes + 5) {
                        $errores[] = "Mes $mes, renglón " . ($i + 1) . ': la cuota fija no es consistente con el tramo anterior.';
                    }
                }
                $previa = $f;
            }

            // Cada mes debe ser el mes 1 multiplicado por n (propiedad del Anexo 8).
            if ($mes > 1 && !$errores && isset($tarifas['1'])) {
                foreach ($filas as $i => $f) {
                    $base = $tarifas['1'][$i] ?? null;
                    if (!$base || (float) $base['porcentaje'] !== (float) $f['porcentaje']) {
                        $errores[] = "Mes $mes: los porcentajes no coinciden con los del mes 1.";
                        break;
                    }
                    if ($base['limite_superior'] !== null && abs(Contabilidad::centavos($f['limite_superior']) - $mes * Contabilidad::centavos($base['limite_superior'])) > 15 * $mes) {
                        $errores[] = "Mes $mes: un límite superior no equivale al del mes 1 × $mes.";
                        break;
                    }
                }
            }
        }

        if (($doc['vigencia_desde'] ?? '') !== (($doc['ejercicio'] ?? 0) . '-01-01')) {
            $avisos[] = 'La vigencia no inicia el 1 de enero del ejercicio.';
        }

        return [array_values(array_unique($errores)), $avisos];
    }

    /** Valida una versión y la marca «validada» si no tiene errores. */
    public static function validar(int $id, int $usuarioId): bool
    {
        self::$errores = [];
        $version = self::buscarID($id);

        if (!$version || !in_array($version['estado'], ['borrador', 'validada'], true)) {
            self::$errores[] = 'Solo se valida una versión en borrador.';
            return false;
        }

        $previa = self::activa($version['tipo'], (int) $version['ejercicio']);
        $resultado = self::evaluar($version['doc'], $previa);
        $json = json_encode($resultado, JSON_UNESCAPED_UNICODE);
        $estado = $resultado['ok'] ? 'validada' : 'borrador';

        $consulta = self::$db->prepare(
            'UPDATE catalogo_fiscal SET resultado_validacion = ?, estado = ?, validada_en = IF(?, NOW(), NULL) WHERE id = ?'
        );
        $ok = $resultado['ok'] ? 1 : 0;
        $consulta->bind_param('ssii', $json, $estado, $ok, $id);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('catalogo_fiscal', $id, 'validar', $usuarioId, null, [
            'ok' => $resultado['ok'], 'errores' => count($resultado['errores']), 'pruebas' => $resultado['pruebas'],
        ]);

        if (!$resultado['ok']) {
            self::$errores = $resultado['errores'];
        }

        return $resultado['ok'];
    }

    /* --------------------------------------------------------------------
     * ACTIVAR (requiere autorización)
     * ------------------------------------------------------------------ */

    /** Confirma la identidad del administrador antes de una operación sensible. */
    public static function autorizar(int $usuarioId, string $contrasena): bool
    {
        $consulta = self::$db->prepare('SELECT contrasena FROM usuarios WHERE id = ? AND rol = 1');
        $consulta->bind_param('i', $usuarioId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila !== null && $contrasena !== '' && password_verify($contrasena, (string) $fila['contrasena']);
    }

    /**
     * Activa una versión validada. La activa anterior pasa a «reemplazada» sin tocarse.
     *
     * @param string $contrasena confirmación de identidad del administrador
     */
    public static function activar(int $id, int $usuarioId, string $contrasena, string $motivo): bool
    {
        self::$errores = [];
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 10) {
            self::$errores[] = 'Escribe el motivo de la activación (mínimo 10 caracteres).';
            return false;
        }
        if (!self::autorizar($usuarioId, $contrasena)) {
            Auditoria::registrar('catalogo_fiscal', $id, 'activar_rechazada', $usuarioId, 'Contraseña de autorización incorrecta');
            self::$errores[] = 'Autorización rechazada: la contraseña no es correcta.';
            return false;
        }

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT * FROM catalogo_fiscal WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $id);
            $bloqueo->execute();
            $version = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if (!$version || $version['estado'] !== 'validada') {
                self::$db->rollBack();
                self::$errores[] = 'Solo se activa una versión validada sin errores.';
                return false;
            }

            // Se vuelve a evaluar al activar: la validación no puede ser vieja.
            $resultado = self::evaluar(json_decode((string) $version['datos'], true) ?: [], self::activa($version['tipo'], (int) $version['ejercicio']));
            if (!$resultado['ok']) {
                self::$db->rollBack();
                self::$errores = $resultado['errores'];
                return false;
            }

            $anterior = self::$db->prepare("SELECT id FROM catalogo_fiscal WHERE tipo = ? AND ejercicio = ? AND estado = 'activa' FOR UPDATE");
            $anterior->bind_param('si', $version['tipo'], $version['ejercicio']);
            $anterior->execute();
            $previaId = (int) ($anterior->get_result()->fetch_assoc()['id'] ?? 0);
            $anterior->close();

            if ($previaId > 0) {
                self::$db->query("UPDATE catalogo_fiscal SET estado = 'reemplazada' WHERE id = $previaId");
            }

            $activar = self::$db->prepare(
                "UPDATE catalogo_fiscal SET estado = 'activa', activada_por = ?, activada_en = NOW(), motivo_activacion = ?, reemplaza_id = ? WHERE id = ?"
            );
            $reemplaza = $previaId ?: null;
            $activar->bind_param('isii', $usuarioId, $motivo, $reemplaza, $id);
            $activar->execute();
            $activar->close();

            Auditoria::registrar('catalogo_fiscal', $id, 'activar', $usuarioId, $motivo, ['reemplaza' => $previaId ?: null]);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[CatalogoFiscal::activar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible activar la versión.';
            return false;
        }
    }
}
