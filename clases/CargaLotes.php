<?php

namespace App;

/**
 * CargaLotes — bandeja de carga por lotes (FASE 9, paso 4).
 *
 * Sube de golpe las facturas de meses anteriores y el sistema detecta qué es
 * cada documento, sin registrar nada hasta que la persona lo confirma BOTÓN POR
 * BOTÓN. Dos carriles:
 *
 *   · gastos  → facturas de proveedores (lecturas destino «compra»): registrar
 *               como gasto contable, como gasto + compra de proveedor (para las
 *               métricas de compras), relacionar a una compra existente, dar de
 *               alta al proveedor, o descartar. Los CFDI tipo P se mandan a su
 *               flujo de complementos.
 *   · ventas  → facturas emitidas por el negocio (lecturas destino «venta»):
 *               crear la venta con la precarga inteligente o dar de alta al
 *               cliente si no existe (reutiliza el alta rápida de la FASE 9).
 *
 * REGLA DE PAGO (autorizada): un CFDI con método PUE y condiciones «Contado»
 * SUGIERERE marcar el gasto como pagado — la sugerencia es visible y editable
 * renglón por renglón; nunca se aplica en silencio. PPD o sin condiciones queda
 * «pendiente» (igual que el flujo de un solo documento).
 *
 * Todo reutiliza las vías de escritura existentes: Lecturas::preparar (archiva y
 * huella), Lecturas::propuesta, Gastos::crear (cierra la lectura), ComprasIntel
 * (proveedor, propuestaOrden, relacionar) y Lecturas::altaClienteDesdeLectura.
 * Los duplicados por UUID no se registran dos veces: se señalan con su origen.
 */
class CargaLotes
{
    /** @var ?\mysqli */
    private static $db = null;

    /** @var string[] */
    public static array $errores = [];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /** @return string[] */
    public static function getErrores(): array
    {
        return self::$errores;
    }

    /* ---------------------------------------------------------------- *
     * SUBIDA POR LOTES
     * ---------------------------------------------------------------- */

    /**
     * Sube varios archivos de golpe. Los archivos con el mismo nombre base
     * (p. ej. FACTA.pdf + FACTA.xml) van juntos en UNA lectura, como en el flujo
     * de un solo documento. Devuelve un resumen por archivo.
     *
     * Acepta la subida real de $_FILES (['name' => [...], 'tmp_name' => [...]])
     * o una lista de archivos ya normalizada (unión por documento).
     *
     * @return array{ok: int, total: int, errores: array<string, string>}
     */
    public static function subir(array $archivos, string $carril, int $usuarioId, bool $esSubida = true): array
    {
        self::$errores = [];
        $destino = $carril === 'ventas' ? 'venta' : 'compra';

        if (isset($archivos['name'])) {
            $archivos = Archivos::listaDeSubidas($archivos);
        }

        $grupos = [];
        foreach ($archivos as $archivo) {
            $base = preg_replace('/\.[^.]+$/', '', (string) ($archivo['name'] ?? '')) ?: 'documento';
            $grupos[$base][] = $archivo;
        }

        $ok = 0;
        $errores = [];
        foreach ($grupos as $base => $lista) {
            $id = Lecturas::preparar($lista, $destino, $usuarioId, $esSubida);
            if ($id !== null) {
                $ok++;
            } else {
                $errores[$base] = implode(' ', Lecturas::getErrores());
                Lecturas::getErrores();
            }
        }

        return ['ok' => $ok, 'total' => count($grupos), 'errores' => $errores];
    }

    /* ---------------------------------------------------------------- *
     * BANDEJA DE PENDIENTES (sólo lectura: nada se registra hasta decidir)
     * ---------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> filas de análisis del carril */
    public static function pendientes(string $carril): array
    {
        $destino = $carril === 'ventas' ? 'venta' : 'compra';
        $q = self::$db->prepare(
            "SELECT id, destino, nombre_original, fuente, creado_en, mime FROM lecturas_documentales
              WHERE estado = 'pendiente' AND destino = ? ORDER BY id DESC"
        );
        $q->bind_param('s', $destino);
        $q->execute();
        $lecturas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        $filas = [];
        foreach ($lecturas as $lectura) {
            $filas[] = self::analizarFila($lectura, $carril);
        }

        return $filas;
    }

    /** @return array<string, mixed> */
    private static function analizarFila(array $lectura, string $carril): array
    {
        $fila = [
            'id'        => (int) $lectura['id'],
            'nombre'    => (string) $lectura['nombre_original'],
            'fuente'    => (string) $lectura['fuente'],
            'creado_en' => (string) $lectura['creado_en'],
            'clase'     => 'factura',          // factura | complemento | ajeno | duplicado | manual
        ];
        $cfdi = Lecturas::cfdi((int) $lectura['id']);
        $fila['cfdi'] = $cfdi;

        if ($carril === 'ventas') {
            $prop = Lecturas::propuesta((int) $lectura['id'], 'venta');
            $fila['propuesta_venta'] = $prop;
            $fila['cliente'] = $prop['cliente'] ?? null;
            $fila['total'] = $prop['total'] ?? 0.0;
            $fila['fecha'] = $prop['fecha'] ?? '';
            $fila['clase'] = $prop === null ? 'manual' : 'factura';
            return $fila;
        }

        // --- carril de gastos ---
        if ($cfdi === null) {
            // PDF o imagen sin XML: los datos vienen con confianza baja; nada se
            // registra en automático, se revisa a mano como siempre.
            $fila['clase'] = 'manual';
            return $fila;
        }
        $fila['total'] = (float) ($cfdi['total'] ?: 0);
        $fila['fecha'] = substr((string) $cfdi['fecha'], 0, 10);
        $fila['emisor'] = ['rfc' => (string) $cfdi['emisor_rfc'], 'nombre' => (string) $cfdi['emisor_nombre']];
        $fila['condiciones_pago'] = (string) ($cfdi['condiciones_pago'] ?? '');
        $fila['metodo_pago'] = (string) ($cfdi['metodo_pago'] ?? '');
        $fila['folio'] = (string) ($cfdi['folio_comercial'] ?? '');
        $fila['uuid'] = (string) $cfdi['uuid'];

        // ¿Complemento de pago (CFDI tipo P)? A su propio flujo, nada de gastos.
        if (($cfdi['tipo'] ?? '') === 'P') {
            $fila['clase'] = 'complemento';
            $fila['lado_cliente'] = Contabilidad::esRfcPropio((string) $cfdi['emisor_rfc']);
            // Ya registrado en cualquier lado (proveedor o cliente): no se carga dos veces.
            $q = self::$db->prepare("SELECT id FROM complementos_pago WHERE uuid = ? AND estado = 'vigente' LIMIT 1");
            $uuidComp = (string) $cfdi['uuid'];
            $q->bind_param('s', $uuidComp);
            $q->execute();
            $previo = $q->get_result()->fetch_assoc();
            $q->close();
            $fila['duplicado'] = $previo ? ['id' => (int) $previo['id']] : null;
            return $fila;
        }

        // ¿La factura es de otro receptor? No es un gasto de este negocio.
        $rfcReceptor = (string) $cfdi['receptor_rfc'];
        if ($rfcReceptor !== '' && !Contabilidad::esRfcPropio($rfcReceptor)) {
            $fila['clase'] = 'ajeno';
            $fila['receptor'] = $rfcReceptor;
            return $fila;
        }

        // ¿El folio fiscal ya está registrado en alguna operación? Nunca dos veces.
        $duplicado = $cfdi['uuid'] !== '' ? ComprasIntel::operacionPorUuid($cfdi['uuid']) : null;
        if ($duplicado !== null) {
            $fila['clase'] = 'duplicado';
            $fila['duplicado'] = $duplicado;
            return $fila;
        }

        // Proveedor, compras candidatas y clasificación (todo del análisis existente).
        $an = ComprasIntel::analizar((int) $lectura['id']);
        $fila['proveedor'] = $an['proveedor'] ?? null;
        $fila['propuesta_proveedor'] = $an['propuesta'] ?? null;
        $fila['clasificacion'] = $an['clasificacion'] ?? null;
        $fila['ordenes'] = [];
        foreach (($an['ordenes'] ?? []) as $orden) {
            $fila['ordenes'][] = [
                'id'       => (int) ($orden['id'] ?? 0),
                'folio'    => (string) ($orden['folio'] ?? ''),
                'total'    => (float) ($orden['total'] ?? 0),
                'coincidencias' => (int) (($orden['comparacion']['coincidencias'] ?? 0)),
            ];
        }

        // Regla de pago (visible y editable): PUE + «Contado» sugiere pagado.
        $fila['sugiere_pagado'] = $fila['metodo_pago'] === 'PUE'
            && mb_strtoupper($fila['condiciones_pago']) === 'CONTADO';

        return $fila;
    }

    /* ---------------------------------------------------------------- *
     * ACCIONES POR RENGLÓN (cada una reutiliza la vía de escritura existente)
     * ---------------------------------------------------------------- */

    /**
     * Registra la factura como GASTO contable (sin compra). Reutiliza la
     * propuesta de gasto y Gastos::crear — que además cierra la lectura.
     * @return array{ok: bool, gasto_id?: int, errores: string[]}
     */
    public static function registrarGasto(int $lecturaId, bool $pagado, int $usuarioId): array
    {
        self::$errores = [];
        $prop = Lecturas::propuesta($lecturaId, 'gasto');
        if ($prop === null) {
            return ['ok' => false, 'errores' => ['La lectura ya no está pendiente o no se pudo analizar.']];
        }
        if ((string) ($prop['uuid'] ?? '') !== '' && ComprasIntel::operacionPorUuid((string) $prop['uuid']) !== null) {
            return ['ok' => false, 'errores' => ['Ese folio fiscal ya está registrado: no se carga dos veces.']];
        }

        $formaSata = ['01' => 'Efectivo', '02' => 'Cheque', '03' => 'Transferencia', '04' => 'Tarjeta',
            '05' => 'Tarjeta', '06' => 'Tarjeta', '28' => 'Tarjeta', '29' => 'Tarjeta'];
        $proveedorId = $prop['proveedor'] ? (int) $prop['proveedor']['id'] : 0;
        $emisor = trim((string) ($prop['campos']['emisor_nombre']['valor'] ?? ''));
        // La tarjeta de débito viene como 28 y el sistema la maneja como 05. Si
        // una factura PPD no declara forma, es «99 · Por definir».
        $formaPago = VentasFiscal::normalizarFormaPago((string) $prop['forma_pago']);
        if ($formaPago === '' && strtoupper((string) $prop['metodo_pago']) === 'PPD') {
            $formaPago = '99';
        }

        $datos = [
            'fecha'              => (string) $prop['fecha'],
            'concepto'           => mb_substr('Factura de ' . $emisor, 0, 255),
            'beneficiario_tipo'  => $proveedorId > 0 ? 'proveedor' : 'otro',
            'proveedor_id'       => $proveedorId,
            'beneficiario'       => $proveedorId > 0 ? '' : mb_substr($emisor, 0, 150),
            'categoria_id'       => $prop['categoria'] ? (int) $prop['categoria']['id'] : 0,
            'total'              => $prop['total'] > 0 ? number_format($prop['total'], 2, '.', '') : '0.00',
            'iva_tasa'           => (string) (int) $prop['iva_tasa'],
            // La regla PUE+Contado se aplica AQUÍ y sólo si la persona lo dejó marcado.
            'estado_pago'        => $pagado ? 'pagado' : 'pendiente',
            'fecha_pago'         => $pagado ? (string) $prop['fecha'] : '',
            'comprobante_fiscal' => '1',
            'uuid_fiscal'        => (string) $prop['uuid'],
            'folio_comercial'    => (string) $prop['folio'],
            'rfc_emisor'         => (string) ($prop['campos']['emisor_rfc']['valor'] ?? ''),
            'rfc_receptor'       => (string) ($prop['campos']['receptor_rfc']['valor'] ?? ''),
            'moneda'             => $prop['moneda'] !== '' ? $prop['moneda'] : 'MXN',
            'forma_pago_sat'     => $formaPago,
            'metodo_pago_sat'    => (string) $prop['metodo_pago'],
            'metodo_pago'        => $formaSata[substr($formaPago, 0, 2)] ?? '',
            'naturaleza'         => 'compra',
            'revision_fiscal'    => 'por_validar',
            'empresa_id'         => $prop['empresa'] ? (int) $prop['empresa']['id'] : 0,
            'lectura_id'         => $lecturaId,
        ];

        if (!Gastos::crear($datos, $usuarioId)) {
            return ['ok' => false, 'errores' => Gastos::getErrores() ?: ['No fue posible registrar el gasto.']];
        }

        return ['ok' => true, 'gasto_id' => (int) Gastos::$ultimoId, 'errores' => []];
    }

    /**
     * Registra la factura como GASTO + COMPRA de proveedor (alimenta las métricas
     * de compras por proveedor). Si el proveedor no existe se da de alta con los
     * datos del XML; la compra se crea desde la propuesta de la factura y el XML
     * se relaciona a ella (ComprasIntel::relacionar: una sola vez, un solo gasto).
     * @return array{ok: bool, orden_id?: int, gasto_id?: int, proveedor_nuevo?: bool, errores: string[]}
     */
    public static function registrarCompra(int $lecturaId, int $usuarioId): array
    {
        self::$errores = [];
        $cfdi = Lecturas::cfdi($lecturaId);
        if ($cfdi === null) {
            return ['ok' => false, 'errores' => ['La factura ya no está pendiente o no se pudo leer su XML.']];
        }
        $uuid = (string) $cfdi['uuid'];
        if ($uuid !== '' && ComprasIntel::operacionPorUuid($uuid) !== null) {
            return ['ok' => false, 'errores' => ['Ese folio fiscal ya está registrado: no se carga dos veces.']];
        }

        // Proveedor: el existente por RFC o el alta confirmada con los datos del XML.
        $proveedorNuevo = false;
        $an = ComprasIntel::analizar($lecturaId);
        if (($an['proveedor'] ?? null) === null) {
            $id = ComprasIntel::registrarProveedor($lecturaId, [
                'nombre' => (string) $cfdi['emisor_nombre'],
                'rfc'    => (string) $cfdi['emisor_rfc'],
            ], $usuarioId);
            if ($id === null) {
                return ['ok' => false, 'errores' => ComprasIntel::getErrores() ?: ['No fue posible dar de alta al proveedor.']];
            }
            $proveedorNuevo = true;
            ComprasIntel::getErrores();
        }

        // Compra nueva desde la propuesta de la factura y su XML relacionado.
        $prop = ComprasIntel::propuestaOrden($lecturaId);
        if ($prop === null) {
            return ['ok' => false, 'errores' => ['La factura no trae conceptos con cantidad: no se puede crear la compra.']];
        }
        if (!OrdenesCompra::crear($prop['orden'], $prop['lineas'], $usuarioId)) {
            return ['ok' => false, 'errores' => OrdenesCompra::getErrores() ?: ['No fue posible crear la compra.']];
        }
        $ordenId = (int) OrdenesCompra::$ultimoId;

        if (!ComprasIntel::relacionar($lecturaId, $ordenId, $usuarioId)) {
            return ['ok' => false, 'orden_id' => $ordenId, 'proveedor_nuevo' => $proveedorNuevo,
                'errores' => ComprasIntel::getErrores() ?: ['La compra quedó creada pero el XML no se pudo adjuntar.']];
        }

        $orden = OrdenesCompra::buscarID($ordenId);
        return [
            'ok' => true, 'orden_id' => $ordenId,
            'gasto_id' => !empty($orden['gasto_id']) ? (int) $orden['gasto_id'] : null,
            'proveedor_nuevo' => $proveedorNuevo, 'errores' => [],
        ];
    }

    /** Alta del proveedor de la factura (confirmación explícita). @return array{ok: bool, proveedor_id?: int, errores: string[]} */
    public static function altaProveedor(int $lecturaId, int $usuarioId): array
    {
        self::$errores = [];
        $cfdi = Lecturas::cfdi($lecturaId);
        if ($cfdi === null) {
            return ['ok' => false, 'errores' => ['La factura ya no está pendiente o no se pudo leer su XML.']];
        }
        $id = ComprasIntel::registrarProveedor($lecturaId, [
            'nombre' => (string) $cfdi['emisor_nombre'],
            'rfc'    => (string) $cfdi['emisor_rfc'],
        ], $usuarioId);
        if ($id === null) {
            return ['ok' => false, 'errores' => ComprasIntel::getErrores() ?: ['No fue posible dar de alta al proveedor.']];
        }
        return ['ok' => true, 'proveedor_id' => (int) $id, 'errores' => []];
    }

    /** Descarta la lectura (queda auditada, no se registró nada). */
    public static function descartar(int $lecturaId, int $usuarioId): bool
    {
        self::$errores = [];
        if (!Lecturas::descartar($lecturaId, $usuarioId)) {
            self::$errores[] = 'Esta lectura ya no estaba pendiente.';
            return false;
        }
        return true;
    }

    /**
     * Atajo «limpiar la bandeja»: descarta del carril de gastos lo que ya no
     * tiene sentido conservar —duplicados por folio, facturas ajenas y
     * complementos ya registrados— sin tocar las facturas limpias ni las que se
     * revisan a mano. Todo queda auditado, igual que el descarte por renglón.
     * @return array{descartados: int, errores: array<string, string>}
     */
    public static function descartarResto(int $usuarioId): array
    {
        self::$errores = [];
        $descartados = 0;
        $errores = [];
        foreach (self::pendientes('gastos') as $fila) {
            $clase = (string) $fila['clase'];
            $sobra = in_array($clase, ['duplicado', 'ajeno'], true)
                || ($clase === 'complemento' && !empty($fila['duplicado']));
            if (!$sobra) {
                continue;
            }
            if (self::descartar((int) $fila['id'], $usuarioId)) {
                $descartados++;
            } else {
                $errores[$fila['nombre']] = implode(' ', self::getErrores());
            }
        }

        return ['descartados' => $descartados, 'errores' => $errores];
    }

    /**
     * Botón «registrar todo lo que ya está bien»: registra como GASTO todas las
     * filas del carril de gastos que sean facturas sin duplicar, respetando la
     * sugerencia de pago (PUE + Contado). Se detiene por fila con error y
     * devuelve el resumen de lo que sí quedó registrado.
     * @return array{registrados: int, errores: array<string, string>}
     */
    public static function registrarTodo(int $usuarioId): array
    {
        self::$errores = [];
        $registrados = 0;
        $errores = [];
        foreach (self::pendientes('gastos') as $fila) {
            if ($fila['clase'] !== 'factura') {
                continue;
            }
            $r = self::registrarGasto((int) $fila['id'], (bool) $fila['sugiere_pagado'], $usuarioId);
            if ($r['ok']) {
                $registrados++;
            } else {
                $errores[$fila['nombre']] = implode(' ', $r['errores']);
            }
        }

        return ['registrados' => $registrados, 'errores' => $errores];
    }
}
