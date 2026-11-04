<?php

namespace Classes;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Único lugar donde se arma Dompdf (antes cada exportador repetía Options + papel + render) */
class Pdf
{
    /**
     * Dompdf listo para renderizar (con el HTML ya cargado y el papel puesto).
     * $papel: nombre ('letter', 'tabloid', 'a4') o [0, 0, ancho_pt, alto_pt]. $opciones: se suman a la fuente por defecto.
     */
    public static function preparar(string $html, string|array $papel = 'letter', string $orientacion = 'portrait', array $opciones = []): Dompdf
    {
        // dompdf trabaja de un solo hilo y con el candado de la sesión puesto:
        // se suelta aquí para que el resto de peticiones del usuario no esperen.
        liberarSesion();

        $o = new Options();
        foreach (['defaultFont' => 'DejaVu Sans'] + $opciones as $clave => $valor) $o->set($clave, $valor);
        $dompdf = new Dompdf($o);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($papel, $orientacion);
        return $dompdf;
    }

    /** PDF como bytes */
    public static function generar(string $html, string|array $papel = 'letter', string $orientacion = 'portrait', array $opciones = []): string
    {
        // Mismo HTML + mismo papel + mismas opciones = mismo PDF: si ya está
        // en caché no se vuelve a maquetar.
        $efectivas = ['defaultFont' => 'DejaVu Sans'] + $opciones;
        $clave = CachePdf::clave($html, $papel, $orientacion, $efectivas);
        $guardado = CachePdf::obtener($clave);
        if ($guardado !== null) {
            return $guardado;
        }

        $dompdf = self::preparar($html, $papel, $orientacion, $opciones);
        $dompdf->render();
        $pdf = $dompdf->output();

        if (is_string($pdf) && $pdf !== '') {
            CachePdf::guardar($clave, $pdf);
        }

        return $pdf;
    }

    /** PDF guardado en $ruta; devuelve la ruta */
    public static function guardar(string $ruta, string $html, string|array $papel = 'letter', string $orientacion = 'portrait', array $opciones = []): string
    {
        file_put_contents($ruta, self::generar($html, $papel, $orientacion, $opciones));
        return $ruta;
    }
}
