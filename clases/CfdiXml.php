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

        $emisor   = $xpath->query('//*[local-name()="Emisor"]')->item(0);
        $receptor = $xpath->query('//*[local-name()="Receptor"]')->item(0);
        $timbre   = $xpath->query('//*[local-name()="TimbreFiscalDigital"]')->item(0);

        $uuid = strtoupper($atributo($timbre, 'UUID'));
        if ($uuid !== '' && !preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
            $uuid = '';
        }

        $datos = [
            'version'         => $atributo($comprobante, 'Version') ?: $atributo($comprobante, 'version'),
            'fecha'           => $atributo($comprobante, 'Fecha') ?: $atributo($comprobante, 'fecha'),
            'tipo'            => $atributo($comprobante, 'TipoDeComprobante') ?: $atributo($comprobante, 'tipoDeComprobante'),
            'moneda'          => $atributo($comprobante, 'Moneda') ?: 'MXN',
            'subtotal'        => $atributo($comprobante, 'SubTotal') ?: $atributo($comprobante, 'subTotal'),
            'total'           => $atributo($comprobante, 'Total') ?: $atributo($comprobante, 'total'),
            'serie'           => $atributo($comprobante, 'Serie'),
            'folio'           => $atributo($comprobante, 'Folio'),
            'emisor_rfc'      => strtoupper($atributo($emisor, 'Rfc') ?: $atributo($emisor, 'rfc')),
            'emisor_nombre'   => $atributo($emisor, 'Nombre') ?: $atributo($emisor, 'nombre'),
            'receptor_rfc'    => strtoupper($atributo($receptor, 'Rfc') ?: $atributo($receptor, 'rfc')),
            'receptor_nombre' => $atributo($receptor, 'Nombre') ?: $atributo($receptor, 'nombre'),
            'uuid'            => $uuid,
            'forma_pago'      => $atributo($comprobante, 'FormaPago') ?: $atributo($comprobante, 'formaDePago'),
            'metodo_pago'     => $atributo($comprobante, 'MetodoPago') ?: $atributo($comprobante, 'metodoDePago'),
            'iva'             => self::iva($xpath, $atributo),
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

    /** @return array<int, array{descripcion: string, cantidad: string, valor_unitario: string, importe: string}> */
    private static function conceptos(\DOMXPath $xpath, callable $atributo): array
    {
        $lista = [];
        foreach ($xpath->query('/*/*[local-name()="Conceptos"]/*[local-name()="Concepto"]') as $c) {
            $lista[] = [
                'descripcion'    => $atributo($c, 'Descripcion'),
                'cantidad'       => $atributo($c, 'Cantidad'),
                'valor_unitario' => $atributo($c, 'ValorUnitario'),
                'importe'        => $atributo($c, 'Importe'),
            ];
            if (count($lista) >= 100) {
                break;
            }
        }

        return $lista;
    }
}
