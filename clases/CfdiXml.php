<?php

namespace App;

/**
 * CfdiXml — lectura documental de un CFDI en XML.
 *
 * Solo LEE los datos del archivo (UUID, RFC, totales, fecha…). NO verifica el
 * timbre ni la vigencia ante el SAT: un XML con estructura de CFDI y UUID es
 * «cfdi_con_timbre» solo en sentido documental.
 *
 * Seguridad: se rechaza cualquier XML con DOCTYPE o entidades (XXE, billion
 * laughs) y se desactiva el acceso a red.
 */
class CfdiXml
{
    /**
     * @return array{valido: bool, estado: string, error: ?string, datos: array<string, mixed>}
     *         estado: cfdi_con_timbre | cfdi_sin_timbre | xml_no_cfdi
     */
    public static function leer(string $xml): array
    {
        $vacio = ['valido' => false, 'estado' => 'xml_no_cfdi', 'error' => null, 'datos' => []];

        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            return ['error' => 'El XML contiene DOCTYPE o entidades, no permitidas por seguridad.'] + $vacio;
        }

        $anterior = libxml_use_internal_errors(true);
        $documento = new \DOMDocument();
        $cargado = $documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if (!$cargado) {
            return ['error' => 'El archivo no es un XML bien formado.'] + $vacio;
        }

        $xpath = new \DOMXPath($documento);
        $comprobante = $xpath->query('/*[local-name()="Comprobante"]')->item(0);

        if (!$comprobante instanceof \DOMElement) {
            return ['error' => 'Es un XML, pero no un CFDI (falta el nodo Comprobante).'] + $vacio;
        }

        $atributo = static fn (?\DOMNode $nodo, string $nombre): string =>
            $nodo instanceof \DOMElement ? trim($nodo->getAttribute($nombre)) : '';

        /* Nunca «?:» con el resultado de $atributo: en PHP «0» es falso, así que un
         * comprobante con SubTotal="0" o Descuento="0" (los CFDI de pagos y las
         * facturas sin descuento) se leería como si no viniera el dato. */
        $primero = static fn (string ...$valores): string => array_reduce(
            $valores,
            static fn (string $acumulado, string $valor): string => $acumulado !== '' ? $acumulado : $valor,
            ''
        );

        $emisor   = $xpath->query('//*[local-name()="Emisor"]')->item(0);
        $receptor = $xpath->query('//*[local-name()="Receptor"]')->item(0);
        $timbre   = $xpath->query('//*[local-name()="TimbreFiscalDigital"]')->item(0);

        $uuid = strtoupper($atributo($timbre, 'UUID'));
        if ($uuid !== '' && !preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
            $uuid = '';
        }

        $datos = [
            'version'         => $primero($atributo($comprobante, 'Version'), $atributo($comprobante, 'version')),
            'fecha'           => $primero($atributo($comprobante, 'Fecha'), $atributo($comprobante, 'fecha')),
            'tipo'            => $primero($atributo($comprobante, 'TipoDeComprobante'), $atributo($comprobante, 'tipoDeComprobante')),
            'moneda'          => $primero($atributo($comprobante, 'Moneda'), 'MXN'),
            'subtotal'        => $primero($atributo($comprobante, 'SubTotal'), $atributo($comprobante, 'subTotal')),
            'total'           => $primero($atributo($comprobante, 'Total'), $atributo($comprobante, 'total')),
            'serie'           => $atributo($comprobante, 'Serie'),
            'folio'           => $atributo($comprobante, 'Folio'),
            'emisor_rfc'      => strtoupper($primero($atributo($emisor, 'Rfc'), $atributo($emisor, 'rfc'))),
            'emisor_nombre'   => $primero($atributo($emisor, 'Nombre'), $atributo($emisor, 'nombre')),
            'emisor_regimen'  => $atributo($emisor, 'RegimenFiscal'),
            'receptor_rfc'    => strtoupper($primero($atributo($receptor, 'Rfc'), $atributo($receptor, 'rfc'))),
            'receptor_nombre' => $primero($atributo($receptor, 'Nombre'), $atributo($receptor, 'nombre')),
            // CFDI 4.0: la ficha fiscal del receptor (para pre-alta de clientes y validar facturación).
            'receptor_cp'     => $primero($atributo($receptor, 'DomicilioFiscalReceptor'), $atributo($receptor, 'DomicilioFiscal')),
            'receptor_regimen' => $atributo($receptor, 'RegimenFiscalReceptor'),
            'uso_cfdi'        => strtoupper($atributo($receptor, 'UsoCFDI')),
            'uuid'            => $uuid,
            'forma_pago'      => $primero($atributo($comprobante, 'FormaPago'), $atributo($comprobante, 'formaDePago')),
            'metodo_pago'     => $primero($atributo($comprobante, 'MetodoPago'), $atributo($comprobante, 'metodoDePago')),
            'descuento'       => $primero($atributo($comprobante, 'Descuento'), $atributo($comprobante, 'descuento')),
            // Datos que el CFDI trae y el registro por lotes usa: cómo se pagó y dónde se expidió.
            'condiciones_pago' => $atributo($comprobante, 'CondicionesDePago'),
            'lugar_expedicion' => $atributo($comprobante, 'LugarExpedicion'),
            'tipo_cambio'      => $primero($atributo($comprobante, 'TipoCambio'), $atributo($comprobante, 'TipoDeCambio')),
            'exportacion'      => $atributo($comprobante, 'Exportacion'),
            'iva'             => self::iva($xpath, $atributo),
            // Otros impuestos trasladados (IEPS, ISR trasladado…) y retenidos (ISR/IVA retenido):
            // no entran al IVA, pero el contador los necesita para cuadrar la factura.
            'otros_impuestos' => self::otrosImpuestos($xpath, $atributo),
            'retenciones'     => self::retenciones($xpath, $atributo),
            'conceptos'       => self::conceptos($xpath, $atributo),
        ];
        // El folio comercial (Serie+Folio) NO es el UUID fiscal: se guardan por separado.
        $datos['folio_comercial'] = trim($datos['serie'] . $datos['folio']);

        $incompleto = $datos['total'] === '' || $datos['emisor_rfc'] === '' || $datos['receptor_rfc'] === '';

        return [
            'valido' => true,
            'estado' => ($uuid !== '' && !$incompleto) ? 'cfdi_con_timbre' : 'cfdi_sin_timbre',
            'error'  => null,
            'datos'  => $datos,
        ];
    }

    /** IVA trasladado (impuesto 002) del comprobante; '' si no viene desglosado. */
    private static function iva(\DOMXPath $xpath, callable $atributo): string
    {
        $suma = 0.0;
        $hay  = false;
        foreach ($xpath->query('/*/*[local-name()="Impuestos"]/*[local-name()="Traslados"]/*[local-name()="Traslado"]') as $traslado) {
            if ($atributo($traslado, 'Impuesto') === '002' && $atributo($traslado, 'Importe') !== '') {
                $suma += (float) $atributo($traslado, 'Importe');
                $hay = true;
            }
        }
        if (!$hay) {
            $total = $xpath->query('/*/*[local-name()="Impuestos"]')->item(0);
            $texto = $atributo($total, 'TotalImpuestosTrasladados');
            return $texto !== '' ? $texto : '';
        }

        return number_format($suma, 2, '.', '');
    }

    /**
     * Impuestos trasladados distintos del IVA (002): IEPS (003), ISR trasladado (001)…
     * Se devuelven ya sumados como cadena decimal, o '' si la factura solo trae IVA.
     */
    private static function otrosImpuestos(\DOMXPath $xpath, callable $atributo): string
    {
        $suma = 0.0;
        $hay = false;
        foreach ($xpath->query('/*/*[local-name()="Impuestos"]/*[local-name()="Traslados"]/*[local-name()="Traslado"]') as $traslado) {
            $impuesto = $atributo($traslado, 'Impuesto');
            if ($impuesto !== '' && $impuesto !== '002' && $atributo($traslado, 'Importe') !== '') {
                $suma += (float) $atributo($traslado, 'Importe');
                $hay = true;
            }
        }

        return $hay ? number_format($suma, 2, '.', '') : '';
    }

    /**
     * Retenciones del comprobante (ISR 001 / IVA 004), ya sumadas.
     * Son dinero que el cliente te retiene: bajan tu ingreso, no son IVA trasladado.
     */
    private static function retenciones(\DOMXPath $xpath, callable $atributo): string
    {
        $suma = 0.0;
        $hay = false;
        foreach ($xpath->query('/*/*[local-name()="Impuestos"]/*[local-name()="Retenciones"]/*[local-name()="Retencion"]') as $retencion) {
            if ($atributo($retencion, 'Importe') !== '') {
                $suma += (float) $atributo($retencion, 'Importe');
                $hay = true;
            }
        }
        if ($hay) {
            return number_format($suma, 2, '.', '');
        }
        // Algunos CFDI solo traen el total de retenciones.
        $total = $xpath->query('/*/*[local-name()="Impuestos"]')->item(0);
        $texto = $atributo($total, 'TotalRetenciones');

        return $texto !== '' ? $texto : '';
    }

    /** @return array<int, array{descripcion: string, sku: string, cantidad: string, valor_unitario: string, importe: string, descuento: string}> */
    private static function conceptos(\DOMXPath $xpath, callable $atributo): array
    {
        $lista = [];
        foreach ($xpath->query('/*/*[local-name()="Conceptos"]/*[local-name()="Concepto"]') as $c) {
            $lista[] = [
                'descripcion'    => $atributo($c, 'Descripcion'),
                'sku'            => $atributo($c, 'NoIdentificacion') ?: $atributo($c, 'noIdentificacion'),
                'cantidad'       => $atributo($c, 'Cantidad'),
                'valor_unitario' => $atributo($c, 'ValorUnitario'),
                'importe'        => $atributo($c, 'Importe'),
                'descuento'      => $atributo($c, 'Descuento'),
                // Claves del catálogo SAT: sirven para clasificar el gasto (p. ej. acero →
                // compra de material) y para emparejar con el catálogo interno de productos.
                'clave_prod_serv' => $atributo($c, 'ClaveProdServ'),
                'clave_unidad'   => $atributo($c, 'ClaveUnidad'),
                'objeto_imp'     => $atributo($c, 'ObjetoImp'),
            ];
            if (count($lista) >= 100) {
                break;
            }
        }

        return $lista;
    }

    /**
     * Lee el complemento de PAGOS (CFDI tipo «P», namespace pago:/pago10:) de un XML.
     *
     * Devuelve la fecha y el monto del pago más todos los DoctoRelacionado: cada
     * factura original con su parcialidad, saldo anterior e importe pagado. Eso es
     * lo que permite localizar las facturas y vincular el complemento a los pagos
     * registrados. Si el XML no trae complemento de pagos, devuelve null.
     *
     * @return array{fecha_pago: string, monto: string, moneda: string, forma_pago: string,
     *               doctos: array<int, array<string, string>>}|null
     */
    public static function complementoPago(string $xml): ?array
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            return null;
        }

        $anterior = libxml_use_internal_errors(true);
        $documento = new \DOMDocument();
        $cargado = $documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$cargado) {
            return null;
        }

        $xpath = new \DOMXPath($documento);
        $comprobante = $xpath->query('/*[local-name()="Comprobante"]')->item(0);
        if (!$comprobante instanceof \DOMElement) {
            return null;
        }
        // Solo un CFDI de pago lleva complemento de pagos con DoctoRelacionado.
        $pagos = $xpath->query('//*[local-name()="Pagos"]/*[local-name()="Pago"]');
        if ($pagos->length === 0) {
            return null;
        }

        $atributo = static fn (?\DOMNode $nodo, string $nombre): string =>
            $nodo instanceof \DOMElement ? trim($nodo->getAttribute($nombre)) : '';

        $primero = static fn (string ...$valores): string => array_reduce(
            $valores,
            static fn (string $acumulado, string $valor): string => $acumulado !== '' ? $acumulado : $valor,
            ''
        );

        $primerPago = $pagos->item(0);
        $resultado = [
            'fecha_pago' => $atributo($primerPago, 'FechaPago'),
            'monto'      => $atributo($primerPago, 'Monto'),
            'moneda'     => $primero($atributo($primerPago, 'MonedaP'), 'MXN'),
            'forma_pago' => $atributo($primerPago, 'FormaPagoP'),
            'doctos'     => [],
        ];
        // La fecha del complemento también puede estar en el Comprobante (si hay varios pagos).
        if ($resultado['fecha_pago'] === '') {
            $resultado['fecha_pago'] = $primero(
                $atributo($comprobante, 'Fecha'),
                $atributo($comprobante, 'fecha')
            );
        }

        $doctos = $xpath->query('//*[local-name()="DoctoRelacionado"]');
        foreach ($doctos as $docto) {
            $uuid = strtoupper($atributo($docto, 'IdDocumento'));
            if ($uuid !== '' && !preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
                continue; // un DoctoRelacionado sin UUID válido no sirve para localizar la factura.
            }
            $resultado['doctos'][] = [
                'uuid'            => $uuid,
                'serie'           => $atributo($docto, 'Serie'),
                'folio'           => $atributo($docto, 'Folio'),
                'moneda'          => $primero($atributo($docto, 'MonedaDR'), 'MXN'),
                'num_parcialidad' => $atributo($docto, 'NumParcialidad'),
                'saldo_anterior'  => $atributo($docto, 'ImpSaldoAnt'),
                'importe_pagado'  => $atributo($docto, 'ImpPagado'),
                // Si la versión del complemento los trae (retenidos / IVA del DR).
                'iva'             => $primero($atributo($docto, 'ImpIVA'), $atributo($docto, 'IVA')),
                'retencion_isr'   => $atributo($docto, 'ImpRetencionISR'),
                'retencion_iva'   => $atributo($docto, 'ImpRetencionIVA'),
            ];
        }

        return $resultado['doctos'] === [] ? null : $resultado;
    }
}
