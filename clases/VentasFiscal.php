<?php

namespace App;

/**
 * VentasFiscal — validación previa a facturar una venta (receptor, conceptos,
 * importes, método/forma de pago y emitibilidad).
 *
 * Flujo real de ventas en Acero 360 (verificado en el código):
 *   · Venta → Contabilidad::sincronizarIngreso() crea UN ingreso por venta (1:1).
 *   · La facturación se marca en «Facturación y cobro» (ingresos/accion) con folio,
 *     UUID y, opcionalmente, el CFDI adjunto.
 *   · NO existe integración con PAC: no hay sellado, timbrado ni credenciales en el
 *     código (sólo se LEEN CFDI entrantes). Por eso aquí NO se simula un timbrado:
 *     emitir() sólo devuelve pendiente_pac=true y no escribe nada. El punto de
 *     conexión para un PAC real queda definido en pacDisponible().
 *
 * Reglas:
 *   · validar() SOLO lee: clasifica cada verificación como correcto / advertencia /
 *     error obligatorio y nunca toca la base.
 *   · No se avanza a facturar si hay un solo error obligatorio (puedeFacturar).
 *   · emitir() conserva la venta ante cualquier fallo y jamás crea un ingreso ni
 *     una factura duplicada; si ya hay un CFDI válido, lo relaciona y guarda su UUID.
 *   · Respeto de auditoría, permisos (rutas soloAdmin) y periodos cerrados.
 */
class VentasFiscal
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación de escritura. */
    protected static $errores = [];

    /* ---------------------------------------------------------------- *
     * CLASIFICACIÓN DE LAS VALIDACIONES (requisito 8)
     * ---------------------------------------------------------------- */

    public const ESTADOS = [
        'correcto'    => ['etiqueta' => 'Correcto',          'clase' => 'badge--aprobado',  'icono' => 'check_circle'],
        'advertencia' => ['etiqueta' => 'Advertencia',       'clase' => 'badge--pendiente', 'icono' => 'warning'],
        'error'       => ['etiqueta' => 'Error obligatorio', 'clase' => 'badge--error',     'icono' => 'error'],
    ];

    public const GRUPOS = [
        'emisor'    => 'Datos del emisor (tu negocio)',
        'receptor'  => 'Datos fiscales del receptor (cliente)',
        'conceptos' => 'Conceptos de la venta',
        'importes'  => 'Importes y impuestos',
        'pago'      => 'Método y forma de pago',
        'emision'   => 'Capacidad de facturación',
    ];

    /* ---------------------------------------------------------------- *
     * CATÁLOGOS SAT (recortados; sincronización con catalogo_fiscal pendiente)
     * ---------------------------------------------------------------- */

    /** Regímenes aceptables en RegimenFiscalReceptor (CFDI 4.0). */
    public const REGIMENES = [
        '601', '603', '604', '605', '606', '607', '608', '609', '610', '611',
        '612', '613', '614', '615', '616', '617', '620', '621', '622', '623',
        '624', '625', '626', '628', '629', '630',
    ];

    /** Usos del CFDI aceptables en UsoCFDIReceptor (CFDI 4.0). */
    public const USOS_CFDI = [
        'G01', 'G02', 'G03', 'D01', 'D02', 'D03', 'D04', 'D05', 'D06', 'D07', 'D08', 'D09', 'D10',
        'I01', 'I02', 'I03', 'I04', 'I05', 'I06', 'I07', 'I08', 'S01', 'CP01', 'CN01', 'P01',
    ];

    /** Métodos de pago del CFDI. */
    public const METODOS = [
        'PUE' => 'Pago en una sola exhibición',
        'PPD' => 'Pago en parcialidades o diferido',
    ];

    /**
     * Formas de pago (c_FormaPago).
     *
     * La tarjeta de débito es la 28 en el CFDI y la 05 en el catálogo que usa el
     * sistema; ambas se aceptan y `normalizarFormaPago()` las unifica en la 05
     * para que una factura con 28 no quede como una forma distinta. La 99 es
     * «por definir» y es válida: una factura PPD puede declararla (o omitirla).
     */
    public const FORMAS_PAGO = [
        '01' => 'Efectivo', '02' => 'Cheque nominativo', '03' => 'Transferencia electrónica',
        '04' => 'Tarjeta de crédito', '05' => 'Tarjeta de débito', '06' => 'Crédito en tienda',
        '08' => 'Voucher', '09' => 'Dinero electrónico', '12' => 'Dinero electrónico autorizado',
        '13' => 'Tarjeta de servicios', '14' => 'Aplicación de anticipos', '15' => 'Intercambio',
        '17' => 'Obligación fiscal', '18' => 'Convenio de deuda', '19' => 'Novación de deuda',
        '20' => 'Confusión de deuda', '21' => 'Pago a favor de terceros', '23' => 'Dación en pago',
        '24' => 'Pago por mediación', '25' => 'Compensación', '26' => 'Novación',
        '27' => 'Confusión', '28' => 'Tarjeta de débito', '29' => 'Reembolso',
        '30' => 'Descuento', '31' => 'Pago por cobranza', '99' => 'Por definir',
    ];

    /**
     * Claves equivalentes entre la factura y el sistema. La tarjeta de débito
     * viene como 28 en los CFDI y el sistema la maneja como 05.
     * @var array<string, string>
     */
    public const FORMAS_PAGO_EQUIVALENTES = ['28' => '05'];

    /** Clave de forma de pago unificada al catálogo del sistema (28 → 05). */
    public static function normalizarFormaPago(string $clave): string
    {
        $clave = trim($clave);

        return self::FORMAS_PAGO_EQUIVALENTES[$clave] ?? $clave;
    }

    /** Método de cobro interno (Contabilidad::METODOS_PAGO) → forma de pago SAT. */
    public const FORMA_POR_COBRO = [
        'Efectivo' => '01', 'Cheque' => '02', 'Transferencia' => '03', 'Tarjeta' => '04',
    ];

    /** RFCs del receptor que no requieren datos fiscales plenos. */
    private const RFC_PUBLICO = 'XAXX010101000';
    private const RFC_EXTRANJERO = 'XEXX010101000';

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

    /* ---------------------------------------------------------------- *
     * UTILIDADES DE RFC (requisito 2: RFC normalizado)
     * ---------------------------------------------------------------- */

    /** Mayúsculas y sin espacios, guiones ni puntos: la forma de comparar RFCs. */
    public static function normalizarRfc(string $rfc): string
    {
        return strtoupper(preg_replace('/[\s\-.]/', '', trim($rfc)) ?? '');
    }

    /**
     * Estructura de un RFC de Persona Física (12), Persona Moral (13) o de los
     * especiales del SAT (XAXX010101000 / XEXX010101000).
     *
     * El dígito verificador NO se calcula aquí: sin la rutina oficial del SAT
     * cualquier implementación propia rechazaría RFCs válidos (verificado contra
     * los RFCs reales de este negocio: ningún algoritmo casero los valida).
     * Queda pendiente para la fase con librería de sellado/PAC.
     */
    public static function rfcConFormato(string $rfc): bool
    {
        $rfc = self::normalizarRfc($rfc);
        if (in_array($rfc, [self::RFC_PUBLICO, self::RFC_EXTRANJERO], true)) {
            return true;
        }
        // 3 o 4 caracteres alfabéticos + 6 dígitos de fecha + 3 de homoclave.
        return preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc) === 1;
    }

    /**
     * Cliente existente cuyo RFC (normalizado) coincide. Sirve para reutilizar
     * fichas y evitar clientes duplicados.
     *
     * @return array<string, mixed>|null
     */
    public static function buscarClientePorRfc(string $rfc, int $excluirId = 0): ?array
    {
        $normalizado = self::normalizarRfc($rfc);
        if ($normalizado === '' || $excluirId < 0) {
            return null;
        }

        // FASE 9: primero el registro de RFCs del cliente (un cliente puede tener
        // varios: persona física y empresa). Si no está ahí, el RFC activo de la ficha.
        $porRegistro = ClientesRfc::buscarClientePorRfc($normalizado, $excluirId);
        if ($porRegistro !== null) {
            return $porRegistro;
        }

        // Se normaliza en la consulta igual que en el formulario: con espacios,
        // guiones o minúsculas debe dar el mismo resultado.
        $consulta = self::$db->prepare(
            'SELECT id, nombre, nombre_empresa, rfc, codigo_postal, regimen_fiscal, uso_cfdi
               FROM clientes
              WHERE UPPER(REPLACE(REPLACE(REPLACE(rfc, "-", ""), " ", ""), ".", "")) = ?
              LIMIT 1'
        );
        $consulta->bind_param('s', $normalizado);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if ($fila && $excluirId > 0 && (int) $fila['id'] === $excluirId) {
            return null;   // es el mismo cliente que se está editando
        }

        return $fila ?: null;
    }

    /* ---------------------------------------------------------------- *
     * VALIDACIÓN (sólo lectura) — requisitos 3 a 9
     * ---------------------------------------------------------------- */

    /**
     * Toda la revisión previa de una venta. Devuelve null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public static function validar(int $ventaId): ?array
    {
        $datos = self::datosDeVenta($ventaId);
        if (!$datos) {
            return null;
        }

        $venta    = $datos['venta'];
        $empresa  = $datos['empresa'];
        $cliente  = $datos['cliente'];
        $ingreso  = $datos['ingreso'];
        $detalles = Ventas::detalles($ventaId);
        $items    = [];
        $errores  = [];
        $adv      = [];

        $agregar = static function (string $grupo, string $clave, string $estado, string $detalle) use (&$items, &$errores, &$adv) {
            $items[] = ['grupo' => $grupo, 'clave' => $clave, 'estado' => $estado, 'detalle' => $detalle];
            if ($estado === 'error') {
                $errores[] = $detalle;
            } elseif ($estado === 'advertencia') {
                $adv[] = $detalle;
            }
        };

        /* ---------------------------------------------------------- *
         * EMISOR (tu negocio) — campos obligatorios del comprobante
         * ---------------------------------------------------------- */
        $rfcEmisor = self::normalizarRfc((string) ($empresa['rfc'] ?? ''));
        if ($rfcEmisor === '') {
            $rfcEmisor = self::normalizarRfc((string) ($datos['contribuyente_rfc'] ?? ''));
        }
        if ($rfcEmisor === '') {
            $agregar('emisor', 'rfc', 'error', 'Falta el RFC del emisor (el negocio no tiene RFC ni contribuyente con RFC).');
        } elseif (!self::rfcConFormato($rfcEmisor)) {
            $agregar('emisor', 'rfc', 'error', 'El RFC del emisor "' . $rfcEmisor . '" no tiene el formato de un RFC.');
        } elseif (self::normalizarRfc((string) ($empresa['rfc'] ?? '')) !== ''
            && self::normalizarRfc((string) ($datos['contribuyente_rfc'] ?? '')) !== ''
            && self::normalizarRfc((string) ($empresa['rfc'] ?? '')) !== self::normalizarRfc((string) ($datos['contribuyente_rfc'] ?? ''))
        ) {
            $agregar('emisor', 'rfc', 'advertencia', 'El RFC del negocio difiere del RFC del contribuyente: revisa cuál va en el CFDI.');
        } else {
            $agregar('emisor', 'rfc', 'correcto', 'RFC del emisor completo y con formato válido (' . $rfcEmisor . ').');
        }

        $razonEmisor = trim((string) ($empresa['nombre'] ?? ''));
        if (mb_strlen($razonEmisor) < 3) {
            $agregar('emisor', 'nombre', 'error', 'Falta el nombre o razón social del emisor en el negocio.');
        } else {
            $agregar('emisor', 'nombre', 'correcto', 'Emisor: ' . $razonEmisor . '.');
        }

        $cpEmisor = trim((string) ($empresa['cp'] ?? ''));
        if ($cpEmisor === '' || preg_match('/^\d{4,5}$/', $cpEmisor) !== 1 || $cpEmisor === '00000') {
            $agregar('emisor', 'cp', 'error', 'El código postal fiscal del emisor falta o no tiene 4 o 5 dígitos.');
        } else {
            $agregar('emisor', 'cp', 'correcto', 'Código postal fiscal del emisor: ' . $cpEmisor . '.');
        }

        $regimenEmisor = trim((string) ($empresa['regimen'] ?? ''));
        if ($regimenEmisor === '') {
            $agregar('emisor', 'regimen', 'error', 'Falta el régimen fiscal del emisor.');
        } elseif (preg_match('/^[0-9A-Z]{2,4}$/', $regimenEmisor) !== 1) {
            $agregar('emisor', 'regimen', 'advertencia', 'El régimen del emisor está guardado como texto: confirma la clave del catálogo SAT que corresponde.');
        } elseif (!in_array($regimenEmisor, self::REGIMENES, true)) {
            $agregar('emisor', 'regimen', 'advertencia', 'La clave de régimen del emisor "' . $regimenEmisor . '" no está en el catálogo cargado.');
        } else {
            $agregar('emisor', 'regimen', 'correcto', 'Régimen fiscal del emisor: ' . $regimenEmisor . '.');
        }

        /* ---------------------------------------------------------- *
         * RECEPTOR (cliente) — requisito 3
         * ---------------------------------------------------------- */
        $rfcReceptor = self::normalizarRfc((string) ($cliente['rfc'] ?? ''));
        if ($rfcReceptor === '') {
            $agregar('receptor', 'rfc', 'error', 'Falta el RFC del receptor (cliente): es obligatorio para emitir el CFDI.');
        } elseif (!self::rfcConFormato($rfcReceptor)) {
            $agregar('receptor', 'rfc', 'error', 'El RFC "' . $rfcReceptor . '" del receptor no tiene el formato de un RFC.');
        } elseif ($rfcEmisor !== '' && $rfcReceptor === $rfcEmisor) {
            $agregar('receptor', 'rfc', 'advertencia', 'El RFC del receptor es el mismo que el del emisor: revisa si es correcto.');
        } elseif (in_array($rfcReceptor, [self::RFC_PUBLICO, self::RFC_EXTRANJERO], true)) {
            $agregar('receptor', 'rfc', 'correcto', 'RFC de receptor genérico del SAT (' . $rfcReceptor . ').');
        } else {
            $agregar('receptor', 'rfc', 'correcto', 'RFC del receptor con formato válido (' . $rfcReceptor . ').');
        }

        $razonReceptor = trim((string) ($cliente['nombre_empresa'] ?? '')) ?: trim((string) ($cliente['nombre'] ?? ''));
        if (mb_strlen($razonReceptor) < 3) {
            $agregar('receptor', 'nombre', 'error', 'Falta el nombre o razón social del receptor (cliente).');
        } else {
            $agregar('receptor', 'nombre', 'correcto', 'Receptor: ' . $razonReceptor . '.');
        }

        $cpReceptor = trim((string) ($cliente['codigo_postal'] ?? ''));
        if ($cpReceptor === '') {
            $agregar('receptor', 'cp', 'error', 'Falta el código postal fiscal del receptor (DomicilioFiscalReceptor).');
        } elseif (preg_match('/^\d{5}$/', $cpReceptor) !== 1 || $cpReceptor === '00000') {
            $agregar('receptor', 'cp', 'error', 'El código postal fiscal "' . $cpReceptor . '" debe ser de 5 dígitos.');
        } else {
            $agregar('receptor', 'cp', 'correcto', 'Código postal fiscal del receptor: ' . $cpReceptor . '.');
        }

        $regimenReceptor = trim((string) ($cliente['regimen_fiscal'] ?? ''));
        if ($regimenReceptor === '') {
            $agregar('receptor', 'regimen', 'error', 'Falta el régimen fiscal del receptor (RegimenFiscalReceptor).');
        } elseif (preg_match('/^[0-9A-Z]{3,4}$/', $regimenReceptor) !== 1) {
            $agregar('receptor', 'regimen', 'error', 'El régimen fiscal "' . $regimenReceptor . '" no es una clave del catálogo SAT.');
        } elseif (!in_array($regimenReceptor, self::REGIMENES, true)) {
            $agregar('receptor', 'regimen', 'advertencia', 'La clave de régimen "' . $regimenReceptor . '" no está en el catálogo cargado: confírmala con el SAT antes de timbrar.');
        } else {
            $agregar('receptor', 'regimen', 'correcto', 'Régimen fiscal del receptor: ' . $regimenReceptor . '.');
        }

        // El uso del CFDI que trae la factura emitida (si se leyó con «Registro Venta
        // Inteligente») manda sobre el de la ficha del cliente: es el que declaró ESTE
        // comprobante. Sin dato en el ingreso sigue mandando el del cliente.
        $usoCfdi = $ingreso !== null ? strtoupper(trim((string) ($ingreso['uso_cfdi'] ?? ''))) : '';
        if ($usoCfdi === '') {
            $usoCfdi = strtoupper(trim((string) ($cliente['uso_cfdi'] ?? '')));
        }
        if ($usoCfdi === '') {
            $agregar('receptor', 'uso', 'error', 'Falta el uso del CFDI del receptor (UsoCFDIReceptor).');
        } elseif (preg_match('/^[0-9A-Z]{3,4}$/', $usoCfdi) !== 1) {
            $agregar('receptor', 'uso', 'error', 'El uso del CFDI "' . $usoCfdi . '" no es una clave del catálogo SAT.');
        } elseif (!in_array($usoCfdi, self::USOS_CFDI, true)) {
            $agregar('receptor', 'uso', 'advertencia', 'La clave de uso del CFDI "' . $usoCfdi . '" no está en el catálogo cargado: confírmala antes de timbrar.');
        } elseif ($rfcReceptor === self::RFC_PUBLICO && !in_array($usoCfdi, ['G03', 'S01'], true)) {
            $agregar('receptor', 'uso', 'advertencia',
                'Para un RFC de público en general el uso habitual es G03 (o S01 sin efectos fiscales); el declarado es ' . $usoCfdi . ': revísalo.');
        } else {
            $agregar('receptor', 'uso', 'correcto', 'Uso del CFDI del receptor: ' . $usoCfdi . '.');
        }

        /* ---------------------------------------------------------- *
         * CONCEPTOS — requisito 4
         * ---------------------------------------------------------- */
        $crudos = self::detallesCrudos($ventaId);
        if (!$detalles) {
            $agregar('conceptos', 'vacio', 'error', 'La venta no tiene conceptos: no se puede facturar sin renglones.');
        } elseif (count($crudos) !== count($detalles)) {
            $agregar('conceptos', 'productos', 'error', 'Hay renglones con productos que ya no existen en el catálogo (' . count($crudos) . ' guardados, ' . count($detalles) . ' resolubles).');
        } else {
            $agregar('conceptos', 'vacio', 'correcto', 'La venta tiene ' . count($detalles) . ' concepto(s).');
        }

        $conceptos = [];
        foreach ($detalles as $detalle) {
            $descripcion = trim((string) $detalle['producto_nombre']);
            $cantidad    = (int) $detalle['cantidad'];
            $precio      = (float) $detalle['precio_unitario'];
            $pct         = (float) $detalle['descuento_pct'];
            $lineaOk     = true;

            if ($descripcion === '') {
                $agregar('conceptos', 'descripcion_' . (int) $detalle['id'], 'error', 'Un concepto no tiene descripción.');
                $lineaOk = false;
            }
            if ($cantidad < 1) {
                $agregar('conceptos', 'cantidad_' . (int) $detalle['id'], 'error', 'La cantidad del concepto "' . $descripcion . '" debe ser al menos 1.');
                $lineaOk = false;
            }
            if ($precio < 0) {
                $agregar('conceptos', 'precio_' . (int) $detalle['id'], 'error', 'El precio del concepto "' . $descripcion . '" no puede ser negativo.');
                $lineaOk = false;
            }
            if ($pct < 0 || $pct > 100) {
                $agregar('conceptos', 'descuento_' . (int) $detalle['id'], 'error', 'El descuento del concepto "' . $descripcion . '" debe estar entre 0 y 100%.');
                $lineaOk = false;
            }

            $bruto    = round($cantidad * $precio, 2);
            $esperado = round($bruto - ($bruto * $pct / 100), 2);
            if (abs($esperado - (float) $detalle['subtotal']) > 0.01) {
                $agregar('conceptos', 'subtotal_' . (int) $detalle['id'], 'error',
                    'El importe del concepto "' . $descripcion . '" no coincide con cantidad × precio: se esperaba ' . number_format($esperado, 2)
                    . ' y está guardado ' . number_format((float) $detalle['subtotal'], 2) . '.');
                $lineaOk = false;
            }
            if (trim((string) $detalle['producto_unidad']) === '') {
                $agregar('conceptos', 'unidad_' . (int) $detalle['id'], 'advertencia',
                    'El concepto "' . $descripcion . '" no tiene unidad: el CFDI pide unidad o clave de unidad.');
            }
            if ($lineaOk && trim((string) $detalle['producto_unidad']) !== '') {
                $agregar('conceptos', 'ok_' . (int) $detalle['id'], 'correcto',
                    'Concepto "' . $descripcion . '": ' . $cantidad . ' × ' . number_format($precio, 2)
                    . ($pct > 0 ? ' − ' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%' : '') . '.');
            }

            $conceptos[] = [
                'descripcion' => $descripcion,
                'cantidad'    => $cantidad,
                'precio'      => $precio,
                'descuento'   => $pct,
                'importe'     => (float) $detalle['subtotal'],
            ];
        }

        /* ---------------------------------------------------------- *
         * IMPORTES, DESCUENTOS, IVA, OTROS IMPUESTOS Y TOTAL — req. 4
         * ---------------------------------------------------------- */
        $brutoEsperado   = 0.0;
        $dctoLineas      = 0.0;
        foreach ($crudos as $fila) {
            $bruto = (int) $fila['cantidad'] * (float) $fila['precio_unitario'];
            $brutoEsperado += $bruto;
            $dctoLineas += $bruto * (float) $fila['descuento_pct'] / 100;
        }
        $brutoEsperado = round($brutoEsperado, 2);
        $dctoLineas    = round($dctoLineas, 2);

        $subtotal = (float) $venta['subtotal'];
        $descuento = (float) $venta['descuento'];
        $ivaGuardado = (float) $venta['iva'];
        $total = (float) $venta['total'];
        $aplicaIva = (int) $venta['aplica_iva'] === 1;

        if (abs($subtotal - $brutoEsperado) > 0.01) {
            $agregar('importes', 'subtotal', 'error',
                'El subtotal de la venta no coincide con la suma de sus conceptos: guardado ' . number_format($subtotal, 2)
                . ', esperado ' . number_format($brutoEsperado, 2) . '.');
        } else {
            $agregar('importes', 'subtotal', 'correcto', 'Subtotal cuadrado con los conceptos (' . number_format($subtotal, 2) . ').');
        }

        if ($descuento < 0 || $descuento > $subtotal + 0.01) {
            $agregar('importes', 'descuento', 'error', 'El descuento (' . number_format($descuento, 2) . ') no puede ser negativo ni mayor al subtotal.');
        } elseif ($descuento + 0.01 < $dctoLineas) {
            $agregar('importes', 'descuento', 'error',
                'El descuento total (' . number_format($descuento, 2) . ') es menor que la suma de los descuentos por concepto (' . number_format($dctoLineas, 2) . ').');
        } else {
            $agregar('importes', 'descuento', 'correcto', 'Descuentos coherentes (' . number_format($descuento, 2) . ').');
        }

        $base = round($subtotal - $descuento, 2);
        if ($base < -0.01) {
            $agregar('importes', 'base', 'error', 'La base gravable queda negativa: el descuento excede al subtotal.');
        } elseif (abs($base) <= 0.01 && $total > 0.01) {
            $agregar('importes', 'base', 'error', 'La venta no tiene base gravable pero declara un total de ' . number_format($total, 2) . '.');
        }

        $ivaEsperado = ($aplicaIva && $base > 0) ? round($base * Cotizaciones::IVA / 100, 2) : 0.0;
        if (abs($ivaGuardado - $ivaEsperado) > 0.01) {
            $agregar('importes', 'iva', 'error',
                'El IVA no corresponde a la operación: guardado ' . number_format($ivaGuardado, 2)
                . ', esperado ' . number_format($ivaEsperado, 2)
                . ($aplicaIva ? ' (' . Cotizaciones::IVA . '% sobre ' . number_format($base, 2) . ').' : ' (la venta está marcada sin IVA).'));
        } else {
            $agregar('importes', 'iva', 'correcto', 'IVA coherente (' . number_format($ivaGuardado, 2) . ').');
        }

        $totalEsperado = round($base + $ivaGuardado, 2);
        if (abs($total - $totalEsperado) > 0.01) {
            $agregar('importes', 'total', 'error',
                'El total no cuadra: guardado ' . number_format($total, 2) . ', esperado ' . number_format($totalEsperado, 2) . '.');
        } elseif ($total <= 0) {
            $agregar('importes', 'total', 'error', 'El total de la venta debe ser mayor a cero para facturarse.');
        } else {
            $agregar('importes', 'total', 'correcto', 'Total correcto (' . number_format($total, 2) . ').');
        }

        if ($ingreso) {
            $descuadre = [];
            foreach (['subtotal', 'descuento', 'iva', 'total'] as $campo) {
                if (Contabilidad::centavos($ingreso[$campo] ?? 0) !== Contabilidad::centavos($venta[$campo] ?? 0)) {
                    $descuadre[] = $campo;
                }
            }
            if ($descuadre) {
                $agregar('importes', 'ingreso', 'error',
                    'El ingreso contable de la venta está desincronizado (' . implode(', ', $descuadre) . '): revisa la venta antes de facturar.');
            } else {
                $agregar('importes', 'ingreso', 'correcto', 'El ingreso contable refleja los mismos importes que la venta.');
            }

            $otrosImpuestos = (float) ($ingreso['otros_impuestos'] ?? 0);
            if ($otrosImpuestos > 0) {
                $agregar('importes', 'otros_impuestos', 'advertencia',
                    'Hay otros impuestos declarados de ' . number_format($otrosImpuestos, 2)
                    . ' que todavía no se reflejan en el total: son un pendiente del motor fiscal.');
            } else {
                $agregar('importes', 'otros_impuestos', 'correcto', 'Sin otros impuestos pendientes de trasladar.');
            }
        }

        /* ---------------------------------------------------------- *
         * MÉTODO Y FORMA DE PAGO vs DATOS REALES — requisito 5
         * ---------------------------------------------------------- */
        $cobros = self::cobrosDeVenta($ingreso ? (int) $ingreso['id'] : 0);
        $cobrado = 0.0;
        foreach ($cobros as $cobro) {
            $cobrado += (float) $cobro['importe'];
        }
        $cobrado = round($cobrado, 2);
        $metodo = $ingreso ? mb_strtoupper(trim((string) $ingreso['metodo_pago'])) : '';
        $forma  = $ingreso ? self::normalizarFormaPago((string) $ingreso['forma_pago']) : '';
        $condiciones = mb_strtolower(trim(
            (string) ($venta['condiciones_compra'] ?? '') . ' ' . (string) ($venta['condiciones_envio'] ?? '')
            . ' ' . (string) ($venta['comentarios'] ?? '')
        ));
        $hablaDeCredito = preg_match('/cr[ée]dito|parcialidad|financiamiento|mensual|mensualidad|plazo/', $condiciones) === 1;

        // Propuestas a partir de los datos reales de la operación.
        $propuestaMetodo = 'PUE';
        if ($cobrado > 0 && $cobrado + 0.01 < $total) {
            $propuestaMetodo = 'PPD';                 // ya hay parcialidades reales
        } elseif ($hablaDeCredito && $cobrado + 0.01 < $total) {
            $propuestaMetodo = 'PPD';                 // condiciones de crédito sin liquidar
        }
        $formaCobro = '';
        foreach ($cobros as $cobro) {
            $metodoCobro = (string) ($cobro['metodo'] ?? '');
            if (isset(self::FORMA_POR_COBRO[$metodoCobro])) {
                $formaCobro = self::FORMA_POR_COBRO[$metodoCobro];
                break;
            }
        }
        $propuestaForma = $formaCobro !== '' ? $formaCobro
            : (self::FORMA_POR_COBRO[Contabilidad::metodoSugerido($venta)] ?? '01');
        if ($propuestaMetodo === 'PPD') {
            $propuestaForma = '99';
        }

        if ($ingreso === null) {
            $agregar('pago', 'ingreso', 'error', 'La venta no tiene ingreso contable asociado: no se puede declarar la facturación.');
        } else {
            if ($metodo === '') {
                $agregar('pago', 'metodo', 'error', 'Falta el método de pago del CFDI (PUE o PPD).');
            } elseif (!isset(self::METODOS[$metodo])) {
                $agregar('pago', 'metodo', 'error', 'El método de pago "' . $metodo . '" no es PUE ni PPD.');
            } else {
                $agregar('pago', 'metodo', 'correcto', 'Método de pago: ' . self::METODOS[$metodo] . '.');
            }

            if ($forma === '') {
                if ($metodo === 'PUE') {
                    $agregar('pago', 'forma', 'error', 'Con método PUE es obligatorio declarar la forma de pago (no puede quedar por definir).');
                } else {
                    $agregar('pago', 'forma', 'advertencia', 'Sin forma de pago declarada: en PPD se define al timbrar cada parcialidad (propuesta: ' . self::FORMAS_PAGO[$propuestaForma] . ').');
                }
            } elseif (preg_match('/^\d{2}$/', $forma) !== 1) {
                $agregar('pago', 'forma', 'error', 'La forma de pago "' . $forma . '" debe ser una clave de 2 dígitos del SAT.');
            } elseif (!isset(self::FORMAS_PAGO[$forma])) {
                $agregar('pago', 'forma', 'advertencia', 'La clave de forma de pago "' . $forma . '" no está en el catálogo cargado.');
            } elseif ($metodo === 'PUE' && $forma === '99') {
                $agregar('pago', 'forma', 'error', 'Con método PUE no puede declararse la forma de pago "99 Por definir": elige la forma real.');
            } else {
                $agregar('pago', 'forma', 'correcto', 'Forma de pago: ' . self::FORMAS_PAGO[$forma] . '.');
            }

            // Coherencia con los datos reales (cobros y condiciones).
            if ($cobrado > $total + 0.01) {
                $agregar('pago', 'cobros', 'error',
                    'Los cobros registrados (' . number_format($cobrado, 2) . ') superan el total de la venta (' . number_format($total, 2) . ').');
            } elseif ($cobrado > 0) {
                $agregar('pago', 'cobros', 'correcto', 'Cobrado ' . number_format($cobrado, 2) . ' de ' . number_format($total, 2) . '.');
            }

            if ($metodo === 'PUE' && $cobrado > 0 && $cobrado + 0.01 < $total) {
                $agregar('pago', 'coherencia', 'advertencia',
                    'Declara pago en una sola exhibición pero la venta sólo está cobrada por ' . number_format($cobrado, 2)
                    . ' de ' . number_format($total, 2) . ': revisa si corresponde PPD.');
            } elseif ($metodo === 'PPD' && $cobrado + 0.01 >= $total && $total > 0) {
                $agregar('pago', 'coherencia', 'advertencia',
                    'Declara pago en parcialidades pero la venta ya está cobrada al 100%: lo normal sería PUE.');
            } elseif ($metodo === 'PUE' && $hablaDeCredito && $cobrado + 0.01 < $total) {
                $agregar('pago', 'coherencia', 'advertencia',
                    'Las condiciones hablan de crédito o parcialidades y declara PUE sin estar cobrada: revisa el método de pago.');
            } elseif ($metodo !== '' && $forma !== '' && $cobrado > 0) {
                $agregar('pago', 'coherencia', 'correcto', 'Método, forma y cobros de la operación son coherentes.');
            }

            $metodosCobro = [];
            foreach ($cobros as $cobro) {
                $m = trim((string) ($cobro['metodo'] ?? ''));
                if ($m !== '' && $m !== 'Otro') {
                    $metodosCobro[] = $m;
                }
            }
            if ($metodosCobro && $forma !== '') {
                $esperada = self::FORMA_POR_COBRO[$metodosCobro[0]] ?? '';
                if ($esperada !== '' && $forma !== '' && $forma !== $esperada && $forma !== '99') {
                    $agregar('pago', 'forma_cobros', 'advertencia',
                        'Los cobros son en ' . mb_strtolower($metodosCobro[0]) . ' y declaraste la forma de pago '
                        . $forma . ' (' . (self::FORMAS_PAGO[$forma] ?? '?') . ').');
                }
            }
        }

        /* ---------------------------------------------------------- *
         * CAPACIDAD DE FACTURACIÓN — requisitos 6, 10, 11, 12, 13
         * ---------------------------------------------------------- */
        if ((string) $venta['estado'] !== 'registrada') {
            $agregar('emision', 'estado', 'error', 'La venta está cancelada: no puede facturarse.');
        } else {
            $agregar('emision', 'estado', 'correcto', 'La venta está registrada y vigente.');
        }

        $contribuyente = (int) ($empresa['contribuyente_id'] ?? 0) ?: Contabilidad::contribuyentePrincipal();
        if (Contabilidad::periodoCerrado($contribuyente, (string) $venta['fecha'])) {
            $agregar('emision', 'periodo', 'error', Contabilidad::mensajePeriodoCerrado((string) $venta['fecha']));
        } else {
            $agregar('emision', 'periodo', 'correcto', 'El periodo contable de la venta está abierto.');
        }

        $cfdi = null;
        $uuid = '';
        if ($ingreso) {
            $cfdi = Contabilidad::cfdiDeIngreso((int) $ingreso['id']);
            $uuid = strtoupper(trim((string) ($ingreso['uuid_fiscal'] ?: ($cfdi['uuid_fiscal'] ?? ''))));
        }
        $facturada = $ingreso !== null && (string) $ingreso['estado_facturacion'] === 'facturada';

        if ($facturada && $uuid !== '') {
            $agregar('emision', 'cfdi', 'error',
                'La venta ya tiene un CFDI válido vinculado (UUID ' . $uuid . '): sólo se permite un proceso de corrección o sustitución autorizado.');
        } elseif ($facturada) {
            $agregar('emision', 'cfdi', 'advertencia',
                'La venta ya está marcada como facturada sin CFDI (confirmación manual).');
        } elseif ($cfdi !== null || $uuid !== '') {
            $agregar('emision', 'cfdi', 'correcto',
                $cfdi !== null
                    ? 'Hay un CFDI válido con timbre listo para relacionar (UUID ' . ($cfdi['uuid_fiscal'] ?: $uuid) . ').'
                    : 'Hay un UUID de CFDI guardado listo para relacionar (' . $uuid . ').');
        } else {
            $agregar('emision', 'cfdi', 'correcto', 'La venta no tiene CFDI previo: puede facturarse por primera vez.');
        }

        if ($ingreso === null) {
            $agregar('emision', 'ingreso', 'error', 'Falta el ingreso contable de la venta.');
        }

        $puedeFacturar = true;
        foreach ($items as $item) {
            if ($item['estado'] === 'error') {
                $puedeFacturar = false;
                break;
            }
        }

        $resumen = ['correcto' => 0, 'advertencia' => 0, 'error' => 0];
        foreach ($items as $item) {
            $resumen[$item['estado']]++;
        }

        return [
            'venta'      => $venta,
            'empresa'    => $empresa,
            'cliente'    => $cliente,
            'ingreso'    => $ingreso,
            'items'      => $items,
            'resumen'    => $resumen,
            'errores'    => $errores,
            'advertencias' => $adv,
            'puede_facturar' => $puedeFacturar,
            'cfdi'       => $cfdi,
            'uuid'       => $uuid,
            'facturada'  => $facturada,
            'cobrado'    => $cobrado,
            'condiciones_credito' => $hablaDeCredito,
            'propuestas' => ['metodo' => $propuestaMetodo, 'forma' => $propuestaForma],
            'conceptos'  => $conceptos,
        ];
    }

    /* ---------------------------------------------------------------- *
     * ESCRITURA — datos fiscales del receptor y método/forma de pago
     * ---------------------------------------------------------------- */

    /**
     * Completa los datos fiscales del receptor (cliente de la venta) enviados en
     * el modal de revisión. Busca el RFC normalizado ANTES de escribir: si ese
     * RFC ya pertenece a otro cliente, se reutiliza la ficha existente y NO se
     * crea un duplicado (requisito 2).
     *
     * @param array<string, mixed> $datos rfc, codigo_postal, regimen_fiscal, uso_cfdi
     */
    public static function completarReceptor(int $ventaId, array $datos, int $usuarioId): bool
    {
        self::$errores = [];
        $fila = self::datosDeVenta($ventaId);
        if (!$fila) {
            self::$errores[] = 'La venta ya no existe.';
            return false;
        }
        $clienteId = (int) ($fila['cliente']['id'] ?? 0);
        if ($clienteId <= 0) {
            self::$errores[] = 'La venta no tiene cliente asociado.';
            return false;
        }

        $nuevoRfc = array_key_exists('rfc', $datos) ? self::normalizarRfc((string) $datos['rfc']) : null;
        if ($nuevoRfc !== null && $nuevoRfc !== '' && !self::rfcConFormato($nuevoRfc)) {
            self::$errores[] = 'El RFC "' . $nuevoRfc . '" no tiene el formato de un RFC.';
            return false;
        }

        if ($nuevoRfc !== null && $nuevoRfc !== '') {
            $existente = self::buscarClientePorRfc($nuevoRfc, $clienteId);
            if ($existente !== null) {
                $nombreExistente = trim((string) ($existente['nombre_empresa'] ?: $existente['nombre']));
                self::$errores[] = 'Ese RFC ya está registrado al cliente «' . $nombreExistente
                    . '» (ficha #' . (int) $existente['id'] . '): se reutiliza esa ficha y no se crea un cliente duplicado.';
                return false;
            }
        }

        $campos = [];
        foreach (['codigo_postal' => 'código postal', 'regimen_fiscal' => 'régimen fiscal', 'uso_cfdi' => 'uso del CFDI'] as $campo => $etiqueta) {
            if (!array_key_exists($campo, $datos)) {
                continue;
            }
            $valor = mb_strtoupper(trim((string) $datos[$campo]));
            if ($campo === 'codigo_postal') {
                $valor = trim((string) $datos[$campo]);
                if ($valor !== '' && preg_match('/^\d{5}$/', $valor) !== 1) {
                    self::$errores[] = 'El código postal fiscal debe tener 5 dígitos.';
                    return false;
                }
            } elseif ($valor !== '' && preg_match('/^[0-9A-Z]{3,4}$/', $valor) !== 1) {
                self::$errores[] = 'El ' . $etiqueta . ' "' . $valor . '" no es una clave del catálogo SAT.';
                return false;
            }
            $campos[$campo] = $valor;
        }
        if ($nuevoRfc !== null) {
            $campos['rfc'] = $nuevoRfc;
        }
        if (!$campos) {
            return true;   // nada que completar
        }

        $cliente = Clientes::buscarID($clienteId);
        if (!$cliente) {
            self::$errores[] = 'El cliente de la venta ya no existe.';
            return false;
        }

        $antes = [];
        $despues = [];
        foreach ($campos as $campo => $valor) {
            $actual = (string) ($cliente->$campo ?? '');
            if ($actual === $valor) {
                continue;
            }
            $antes[$campo] = $actual;
            $despues[$campo] = $valor;
            $cliente->$campo = $valor;
        }

        if (!$antes) {
            return true;   // ya estaba todo completo
        }
        if (!$cliente->actualizar()) {
            self::$errores = array_merge(self::$errores, Clientes::getErrores());
            return false;
        }

        Auditoria::registrar('cliente', $clienteId, 'datos_fiscales', $usuarioId, 'Completados desde la revisión previa a facturar la venta ' . ($fila['venta']['folio'] ?? ''), [
            'venta'  => (string) ($fila['venta']['folio'] ?? $ventaId),
            'antes'  => $antes,
            'despues' => $despues,
        ]);

        // FASE 9: el RFC con el que se factura queda registrado en la ficha del
        // cliente (con sus datos) y marcado como el ACTIVO; así un cliente puede
        // facturar con varios RFC sin que se pierda ninguno.
        $rfcActivo = $nuevoRfc !== null && $nuevoRfc !== '' ? $nuevoRfc : (string) ($cliente->rfc ?? '');
        if ($rfcActivo !== '') {
            ClientesRfc::sincronizarPrincipal($clienteId, $rfcActivo, [
                'codigo_postal'  => (string) ($cliente->codigo_postal ?? ''),
                'regimen_fiscal' => (string) ($cliente->regimen_fiscal ?? ''),
                'uso_cfdi'       => (string) ($cliente->uso_cfdi ?? ''),
            ], $usuarioId);
        }

        return true;
    }

    /**
     * Declara el método (PUE/PPD) y la forma de pago del CFDI en el ingreso de
     * la venta. La coherencia con los cobros reales la juzga validar().
     */
    public static function guardarPago(int $ventaId, string $metodo, string $forma, int $usuarioId = 0): bool
    {
        self::$errores = [];
        $metodo = mb_strtoupper(trim($metodo));
        $forma  = self::normalizarFormaPago($forma);

        if ($metodo === '' || !isset(self::METODOS[$metodo])) {
            self::$errores[] = 'El método de pago debe ser PUE o PPD.';
            return false;
        }
        if ($forma !== '' && preg_match('/^\d{2}$/', $forma) !== 1) {
            self::$errores[] = 'La forma de pago debe ser una clave de 2 dígitos del SAT.';
            return false;
        }

        $ingreso = Contabilidad::ingresoDeVenta($ventaId);
        if (!$ingreso) {
            self::$errores[] = 'La venta no tiene ingreso contable asociado.';
            return false;
        }
        if ($ingreso['estado'] !== 'vigente') {
            self::$errores[] = 'El ingreso de la venta está cancelado.';
            return false;
        }
        if (Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], (string) $ingreso['fecha'])) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado((string) $ingreso['fecha']);
            return false;
        }

        $antes = ['metodo_pago' => (string) $ingreso['metodo_pago'], 'forma_pago' => (string) $ingreso['forma_pago']];
        if ($antes['metodo_pago'] === $metodo && $antes['forma_pago'] === $forma) {
            return true;
        }

        $consulta = self::$db->prepare('UPDATE ingresos SET metodo_pago = ?, forma_pago = ? WHERE id = ?');
        $consulta->bind_param('ssi', $metodo, $forma, $ingreso['id']);
        $consulta->execute();
        $consulta->close();

        Auditoria::registrar('ingreso', (int) $ingreso['id'], 'datos_cfdi', $usuarioId ?: null, 'Método y forma de pago declarados para el CFDI', [
            'antes' => $antes, 'despues' => ['metodo_pago' => $metodo, 'forma_pago' => $forma],
        ]);
        return true;
    }

    /**
     * Los cuatro datos del CFDI (método, forma, uso y tipo de comprobante) que
     * trae un documento leído con «Registro Venta Inteligente», guardados en el
     * ingreso de la venta creada con él.
     *
     * Aquí manda el DOCUMENTO, no la persona: sólo se escriben los valores que
     * pertenezcan al catálogo del SAT, y un dato ilegible no bloquea nada (queda
     * vacío y se captura a mano en «Facturación y cobro»). Las claves que no
     * vengan en $datos no se tocan: lo que la persona ya haya declarado se conserva.
     *
     * @param array<string, mixed> $datos metodo_pago, forma_pago, uso_cfdi y/o tipo_cfdi
     */
    public static function guardarDatosLeidos(int $ventaId, array $datos, int $usuarioId = 0): bool
    {
        self::$errores = [];
        $ingreso = Contabilidad::ingresoDeVenta($ventaId);
        if (!$ingreso) {
            self::$errores[] = 'La venta no tiene ingreso contable asociado.';
            return false;
        }
        if ($ingreso['estado'] !== 'vigente') {
            self::$errores[] = 'El ingreso de la venta está cancelado.';
            return false;
        }
        if (Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], (string) $ingreso['fecha'])) {
            self::$errores[] = Contabilidad::mensajePeriodoCerrado((string) $ingreso['fecha']);
            return false;
        }

        $catalogos = [
            'metodo_pago' => self::METODOS,
            'forma_pago'  => self::FORMAS_PAGO,
            'uso_cfdi'    => self::USOS_CFDI,
            'tipo_cfdi'   => Contabilidad::TIPOS_CFDI,
        ];
        $antes = [];
        $despues = [];
        foreach ($catalogos as $campo => $catalogo) {
            if (!array_key_exists($campo, $datos)) {
                continue;
            }
            $valor = strtoupper(trim((string) $datos[$campo]));
            if ($campo === 'forma_pago') {
                $valor = self::normalizarFormaPago($valor);
            }
            // Catálogo asociativo (clave => etiqueta) o lista simple de claves: los dos
            // estilos ya conviven en este archivo (USOS_CFDI es lista, METODOS no).
            $enCatalogo = $valor !== '' && (array_key_exists($valor, $catalogo) || in_array($valor, $catalogo, true));
            if (!$enCatalogo || (string) ($ingreso[$campo] ?? '') === $valor) {
                continue;   // vacío, fuera de catálogo o ya guardado: no se toca
            }
            $antes[$campo] = (string) ($ingreso[$campo] ?? '');
            $despues[$campo] = $valor;
        }
        if (!$despues) {
            return true;
        }

        $columnas = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($despues)));
        $valores = array_values($despues);
        $valores[] = (int) $ingreso['id'];
        $q = self::$db->prepare("UPDATE ingresos SET $columnas WHERE id = ?");
        $q->bind_param(str_repeat('s', count($despues)) . 'i', ...$valores);
        $q->execute();
        $q->close();

        Auditoria::registrar('ingreso', (int) $ingreso['id'], 'datos_cfdi', $usuarioId ?: null,
            'Datos del CFDI leídos de la factura', ['antes' => $antes, 'despues' => $despues]);
        return true;
    }

    /* ---------------------------------------------------------------- *
     * EMISIÓN — requisitos 6, 10, 11 y 12
     * ---------------------------------------------------------------- */

    /**
     * Punto de conexión con el PAC. Hoy no existe integración con ninguno:
     * no hay sellado, timbrado ni credenciales en el código, así que devuelve
     * null y el intento de emisión queda documentado como pendiente externo.
     * Si en el futuro se agrega una clase \App\Pac con ::disponible(), este
     * método la usará sin tocar el resto del flujo (aquí NO se simula timbre).
     *
     * @return array<string, mixed>|null
     */
    public static function pacDisponible(): ?array
    {
        if (class_exists(Pac::class) && is_callable([Pac::class, 'disponible'])) {
            $pac = Pac::disponible();
            return is_array($pac) && $pac ? $pac : null;
        }
        return null;
    }

    /**
     * Intento de facturación de la venta.
     *
     * 1. Revalida: sin pasar todas las verificaciones no se avanza.
     * 2. Si ya hay un CFDI válido vinculado y no está autorizada una
     *    corrección/sustitución, se rechaza (requisito 6).
     * 3. Si hay un CFDI con timbre adjunto, lo RELACIONA con la venta y
     *    conserva su UUID (requisito 12) — no necesita PAC.
     * 4. Si hay que emitir un CFDI nuevo y no hay PAC, sólo informa
     *    pendiente_pac: no se escribe nada, la venta se conserva y no se
     *    crea ningún ingreso ni factura duplicada (requisitos 10 y 11).
     *
     * @return array{ok: bool, pendiente_pac: bool, cfdi: ?array, errores: string[]}
     */
    public static function emitir(int $ventaId, bool $sustitucion = false, string $motivo = '', int $usuarioId = 0): array
    {
        self::$errores = [];
        $revision = self::validar($ventaId);
        if ($revision === null) {
            return ['ok' => false, 'pendiente_pac' => false, 'cfdi' => null, 'errores' => ['La venta ya no existe.']];
        }

        $folio = (string) $revision['venta']['folio'];
        $ingreso = $revision['ingreso'];
        if (!$ingreso) {
            return ['ok' => false, 'pendiente_pac' => false, 'cfdi' => null,
                'errores' => ['La venta no tiene ingreso contable asociado.']];
        }

        // --- 6. Ya hay CFDI válido: sólo con corrección/sustitución autorizada.
        // Va ANTES del resto de validaciones porque es el motivo más específico
        // de rechazo (y la sustitución autorizada sólo cubre ese bloqueo).
        $sustituida = false;
        if ($revision['facturada'] && $revision['uuid'] !== '') {
            if (!$sustitucion) {
                Auditoria::registrar('venta', $ventaId, 'emision', $usuarioId, 'Rechazada: CFDI ya vinculado', [
                    'venta' => $folio, 'uuid' => $revision['uuid'],
                ]);
                return ['ok' => false, 'pendiente_pac' => false, 'cfdi' => null, 'errores' => [
                    'La venta ya tiene un CFDI válido vinculado (UUID ' . $revision['uuid']
                    . '). Sólo un proceso de corrección o sustitución autorizado puede reemitirla.',
                ]];
            }
            if (mb_strlen(trim($motivo)) < 5) {
                return ['ok' => false, 'pendiente_pac' => false, 'cfdi' => null,
                    'errores' => ['Indica el motivo de la corrección o sustitución (mínimo 5 caracteres).']];
            }
            Auditoria::registrar('venta', $ventaId, 'sustitucion', $usuarioId, trim($motivo), [
                'venta' => $folio, 'uuid_anterior' => $revision['uuid'],
            ]);
            $sustituida = true;
        }

        // --- Validación integral: sin errores se avanza; la sustitución sólo
        //     levanta el bloqueo del CFDI previo, nada más.
        $errores = $revision['errores'];
        if ($sustituida) {
            $errores = array_values(array_filter(
                $errores,
                static fn (string $error): bool => !str_contains($error, 'ya tiene un CFDI válido vinculado')
            ));
        }
        if ($errores !== []) {
            return ['ok' => false, 'pendiente_pac' => false, 'cfdi' => null, 'errores' => $errores];
        }

        // --- 12. Un CFDI con timbre ya adjunto: relacionarlo y conservar su UUID.
        $relacionado = null;
        if ($revision['cfdi'] !== null && !$revision['facturada']) {
            $uuidCfdi = strtoupper((string) $revision['cfdi']['uuid_fiscal']);
            $ok = Contabilidad::actualizarFacturacion(
                (int) $ingreso['id'],
                'facturada',
                trim((string) $ingreso['factura_folio']) ?: 'CFDI',
                $uuidCfdi,
                (string) ($ingreso['fecha_facturacion'] ?: substr((string) $revision['venta']['fecha'], 0, 10)),
                $usuarioId,
                false   // hay CFDI con timbre: no es confirmación manual
            );
            if (!$ok) {
                return ['ok' => false, 'pendiente_pac' => false, 'cfdi' => null, 'errores' => Contabilidad::getErrores()];
            }
            $relacionado = ['uuid' => $uuidCfdi, 'documento_id' => (int) $revision['cfdi']['id']];
            Auditoria::registrar('venta', $ventaId, 'emision', $usuarioId, 'CFDI existente relacionado con la venta', [
                'venta' => $folio, 'uuid' => $uuidCfdi, 'documento_id' => (int) $revision['cfdi']['id'],
            ]);
            return ['ok' => true, 'pendiente_pac' => false, 'cfdi' => $relacionado,
                'errores' => [], 'mensaje' => 'CFDI relacionado con la venta (UUID ' . $uuidCfdi . ').'];
        }

        // --- 10/11. Emitir un CFDI nuevo: sin PAC no se timbra nada.
        if (self::pacDisponible() === null) {
            Auditoria::registrar('venta', $ventaId, 'emision', $usuarioId, 'Intento de emisión sin PAC configurado', [
                'venta' => $folio, 'resultado' => 'no_emitida', 'ingreso_id' => (int) $ingreso['id'],
            ]);
            return ['ok' => false, 'pendiente_pac' => true, 'cfdi' => null, 'errores' => [
                'La validación previa está aprobada, pero no hay integración con un PAC: no se timbró el CFDI. '
                . 'La venta se conserva intacta y no se crearon facturas ni ingresos duplicados. '
                . 'La emisión real queda pendiente de configurar la integración externa (pendiente documentado).',
            ]];
        }

        return ['ok' => false, 'pendiente_pac' => false, 'cfdi' => null,
            'errores' => ['El PAC configurado no devolvió una respuesta válida. La venta se conserva sin timbrar.']];
    }

    /* ---------------------------------------------------------------- *
     * CONSULTAS AUXILIARES
     * ---------------------------------------------------------------- */

    /** @return array<string, mixed>|null venta + negocio + cliente + contribuyente + ingreso */
    private static function datosDeVenta(int $ventaId): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT v.*, e.nombre AS e_nombre, e.rfc AS e_rfc, e.cp AS e_cp, e.regimen AS e_regimen,
                    e.contribuyente_id AS e_contribuyente_id,
                    c.id AS c_id, c.nombre AS c_nombre, c.nombre_empresa AS c_nombre_empresa,
                    c.rfc AS c_rfc, c.codigo_postal AS c_codigo_postal,
                    c.regimen_fiscal AS c_regimen_fiscal, c.uso_cfdi AS c_uso_cfdi,
                    ct.rfc AS ct_rfc, ct.razon_social AS ct_razon_social
               FROM ventas v
               JOIN empresas e  ON e.id = v.empresa_id
               JOIN clientes c  ON c.id = v.cliente_id
          LEFT JOIN contribuyentes ct ON ct.id = COALESCE(e.contribuyente_id,
                        (SELECT MIN(id) FROM contribuyentes WHERE activo = 1))
              WHERE v.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();
        if (!$fila) {
            return null;
        }

        $venta = [];
        foreach ($fila as $clave => $valor) {
            if (str_starts_with($clave, 'e_') || str_starts_with($clave, 'c_') || str_starts_with($clave, 'ct_')) {
                continue;
            }
            $venta[$clave] = $valor;
        }

        return [
            'venta' => $venta,
            'empresa' => [
                'id' => (int) $fila['empresa_id'], 'nombre' => $fila['e_nombre'], 'rfc' => $fila['e_rfc'],
                'cp' => $fila['e_cp'], 'regimen' => $fila['e_regimen'],
                'contribuyente_id' => (int) ($fila['e_contribuyente_id'] ?? 0),
            ],
            'cliente' => [
                'id' => (int) $fila['c_id'], 'nombre' => $fila['c_nombre'], 'nombre_empresa' => $fila['c_nombre_empresa'],
                'rfc' => $fila['c_rfc'], 'codigo_postal' => $fila['c_codigo_postal'],
                'regimen_fiscal' => $fila['c_regimen_fiscal'], 'uso_cfdi' => $fila['c_uso_cfdi'],
            ],
            'contribuyente_rfc' => (string) ($fila['ct_rfc'] ?? ''),
            'ingreso' => Contabilidad::ingresoDeVenta($ventaId),
        ];
    }

    /** Renglones crudos (sin join con productos) para recalcular importes. @return array<int, array<string, mixed>> */
    private static function detallesCrudos(int $ventaId): array
    {
        $consulta = self::$db->prepare('SELECT * FROM venta_detalles WHERE venta_id = ? ORDER BY id');
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Cobros vigentes de la venta. @return array<int, array<string, mixed>> */
    private static function cobrosDeVenta(int $ingresoId): array
    {
        if ($ingresoId <= 0) {
            return [];
        }
        $consulta = self::$db->prepare(
            "SELECT c.importe, c.metodo, c.fecha FROM cobros c
              WHERE c.ingreso_id = ? AND c.estado = 'vigente' ORDER BY c.fecha, c.id"
        );
        $consulta->bind_param('i', $ingresoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }
}
