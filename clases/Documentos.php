<?php

namespace App;

/**
 * Documentos — generación de los PDF de la aplicación (cotización,
 * inventario, catálogo de productos y balance anual de proveedores).
 *
 * El HTML de la cotización (y el de la venta directa, que comparte plantilla)
 * alimenta la exportación del botón "Exportar PDF".
 *
 * Se usa dompdf, tal como pide OPENCODE.md. Los documentos salen de
 * templates/pdfCotizaciones.php y templates/pdfInventario.php, que sólo
 * usan tablas e imágenes en base64 para no depender de archivos externos
 * ni de una conexión a Internet.
 */
class Documentos
{
    /** Tamaño de la imagen incrustada si el logo no existe. */
    private const ANCHO_PAGINA  = 'letter';
    private const ORIENTACION  = 'portrait';

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /**
     * HTML completo (documento entero) de una cotización.
     */
    public static function html(Cotizaciones|Ventas $cotizacion, array $detalles): string
    {
        ob_start();

        \template('pdfCotizaciones', [
            'cotizacion' => $cotizacion,
            'detalles'   => $detalles,
        ]);

        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * PDF de una cotización como cadena de bytes.
     *
     * @return string|null null si dompdf no pudo generarlo.
     */
    public static function pdf(Cotizaciones|Ventas $cotizacion, array $detalles): ?string
    {
        $html = self::html($cotizacion, $detalles);

        if ($html === '') {
            self::$errores[] = 'No se pudo componer el documento de la cotización.';
            return null;
        }

        return self::componer($html, 'la cotización');
    }

    /* --------------------------------------------------------------------
     * INVENTARIO (Exportar Inventario)
     * ------------------------------------------------------------------ */

    /**
     * HTML completo del inventario por empresa.
     */
    public static function htmlInventario(): string
    {
        ob_start();

        \template('pdfInventario', [
            'secciones' => Inventario::paraPdf(),
            'fecha'     => date('d/m/Y'),
        ]);

        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * PDF del inventario como cadena de bytes.
     *
     * @return string|null null si dompdf no pudo generarlo.
     */
    public static function pdfInventario(): ?string
    {
        $html = self::htmlInventario();

        if ($html === '') {
            self::$errores[] = 'No se pudo componer el documento de inventario.';
            return null;
        }

        return self::componer($html, 'el inventario');
    }

    /**
     * Envía el PDF del inventario al navegador (se abre en pestaña nueva
     * desde el botón "Exportar Inventario"). La petición termina aquí (exit).
     */
    public static function descargarInventario(): void
    {
        $bytes = self::pdfInventario();

        if ($bytes === null) {
            \redirigir('/admin/inventario', 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        $nombre = 'inventario-' . date('Y-m-d');

        self::enviar($bytes, $nombre);
    }

    /* --------------------------------------------------------------------
     * CATÁLOGO DE PRODUCTOS (Exportar catálogo de productos)
     * ------------------------------------------------------------------ */

    /**
     * HTML completo del catálogo: una tabla por empresa con imagen, clave,
     * nombre y costo de cada producto.
     */
    public static function htmlCatalogo(): string
    {
        ob_start();

        \template('pdfCatalogo', [
            'secciones' => Productos::paraPdf(),
            'fecha'     => date('d/m/Y'),
        ]);

        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /** PDF del catálogo como cadena de bytes. */
    public static function pdfCatalogo(): ?string
    {
        $html = self::htmlCatalogo();

        if ($html === '') {
            self::$errores[] = 'No se pudo componer el catálogo de productos.';
            return null;
        }

        return self::componer($html, 'el catálogo de productos');
    }

    /**
     * Envía el PDF del catálogo al navegador (pestaña nueva). Fin de la
     * petición.
     */
    public static function descargarCatalogo(): void
    {
        $bytes = self::pdfCatalogo();

        if ($bytes === null) {
            \redirigir('/admin/productos', 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, 'catalogo-productos-' . date('Y-m-d'));
    }

    /* --------------------------------------------------------------------
     * BALANCE ANUAL DE PROVEEDORES (Exportar datos)
     * ------------------------------------------------------------------ */

    /**
     * HTML del reporte anual: una sección por proveedor con todas sus
     * entregas del año y sus totales pagado / pendiente.
     */
    public static function htmlBalanceProveedores(int $anio): string
    {
        ob_start();

        \template('pdfProveedores', [
            'secciones' => ProveedorEntradas::paraPdf($anio),
            'anio'      => $anio,
            'fecha'     => date('d/m/Y'),
        ]);

        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /** PDF del reporte anual de proveedores como cadena de bytes. */
    public static function pdfBalanceProveedores(int $anio): ?string
    {
        $html = self::htmlBalanceProveedores($anio);

        if ($html === '') {
            self::$errores[] = 'No se pudo componer el reporte de proveedores.';
            return null;
        }

        return self::componer($html, 'el reporte anual de proveedores');
    }

    /**
     * Envía el PDF anual de proveedores al navegador (pestaña nueva).
     * Fin de la petición.
     *
     * @param int $anio Año cubierto por el reporte (el activo por defecto).
     */
    public static function descargarBalanceProveedores(int $anio): void
    {
        $bytes = self::pdfBalanceProveedores($anio);

        if ($bytes === null) {
            \redirigir('/admin/proveedores', 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, 'entradas-proveedores-' . $anio);
    }

    /**
     * Renderiza un HTML con dompdf.
     *
     * @param string $html    Documento compuesto por la plantilla.
     * @param string $origen  Texto para los mensajes de error ("la cotización").
     *
     * @return string|null null si dompdf no pudo generarlo.
     */
    private static function componer(string $html, string $origen): ?string
    {
        try {
            $opciones = new \Dompdf\Options();
            $opciones->set('isRemoteEnabled', false);   // nunca sale a Internet
            $opciones->set('isHtml5ParserEnabled', true);
            $opciones->set('defaultFont', 'DejaVu Sans');
            $opciones->set('chroot', dirname(__DIR__, 2) . '/public');

            $dompdf = new \Dompdf\Dompdf($opciones);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper(self::ANCHO_PAGINA, self::ORIENTACION);
            $dompdf->render();

            $bytes = $dompdf->output();
            if (!is_string($bytes) || $bytes === '') {
                self::$errores[] = 'El PDF de ' . $origen . ' quedó vacío.';
                return null;
            }

            return $bytes;
        } catch (\Throwable $excepcion) {
            error_log('[Documentos::componer] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible generar el PDF de ' . $origen . '.';
            return null;
        }
    }

    /* --------------------------------------------------------------------
     * CONTABILIDAD
     * ------------------------------------------------------------------ */

    /**
     * PDF de ventas sin facturar. Solo lectura.
     *
     * @param array<string, mixed> $sf      Contabilidad::sinFacturar()
     * @param array<string, int>   $periodo anio / mes / empresa
     */
    public static function descargarSinFacturar(array $sf, array $periodo): void
    {
        ob_start();
        \template('pdfSinFacturar', ['sf' => $sf, 'periodo' => $periodo]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'el reporte de ventas sin facturar');
        if ($bytes === null) {
            \redirigir('/admin/contabilidad/sin-facturar', 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, 'ventas-sin-facturar-' . $periodo['anio'] . ($periodo['mes'] ? sprintf('-%02d', $periodo['mes']) : ''));
    }

    /**
     * PDF genérico de una tabla (reportes de inventario). Solo lectura.
     *
     * @param string[] $encabezados
     * @param array<int, array<int, scalar|null>> $filas
     */
    public static function descargarTabla(string $titulo, string $subtitulo, array $encabezados, array $filas, string $nombre, string $pie = '', string $volver = '/admin/inventario'): void
    {
        ob_start();
        \template('pdfTablaSimple', ['titulo' => $titulo, 'subtitulo' => $subtitulo, 'encabezados' => $encabezados, 'filas' => $filas, 'pie' => $pie]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'el reporte');
        if ($bytes === null) {
            \redirigir($volver, 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, $nombre);
    }

    /** PDF de estadísticas. Solo lectura. */
    public static function descargarEstadisticas(array $reporte): void
    {
        ob_start();
        \template('pdfEstadisticas', ['r' => $reporte]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'las estadísticas');
        if ($bytes === null) {
            \redirigir('/admin/estadisticas', 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        $p = $reporte['parametros'];
        self::enviar($bytes, 'estadisticas-' . $p['tipo'] . '-' . $p['anio']);
    }

    /** PDF del resumen de cierre (fotografía guardada). Solo lectura. */
    public static function descargarCierre(array $snapshot, array $estado): void
    {
        ob_start();
        \template('pdfCierre', ['s' => $snapshot, 'estado' => $estado]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'el resumen de cierre');
        if ($bytes === null) {
            \redirigir('/admin/contabilidad/cierre', 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, 'cierre-' . $snapshot['periodo']);
    }

    /* --------------------------------------------------------------------
     * PANTALLA DE ENTREGA
     * ------------------------------------------------------------------ */

    /**
     * PDF de una preparación: 'preparacion' (productos agrupados para alistar)
     * o 'entrega' (por cliente, con firma). Solo lectura.
     */
    public static function descargarEntrega(int $preparacionId, string $tipo): void
    {
        $prep = Entregas::buscarPreparacion($preparacionId);
        if (!$prep) {
            \paginaNoEncontrada();
        }

        $ventas = Entregas::ventasDePreparacion($preparacionId);

        ob_start();
        \template('pdfEntregas', [
            'prep'   => $prep,
            'ventas' => $ventas,
            'grupos' => Entregas::productosAgrupados($ventas),
            'tipo'   => $tipo === 'entrega' ? 'entrega' : 'preparacion',
        ]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'la preparación');
        if ($bytes === null) {
            \redirigir('/admin/entregas/detalle?id=' . $preparacionId, 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, ($tipo === 'entrega' ? 'entrega-' : 'preparacion-') . $prep['folio']);
    }

    /* --------------------------------------------------------------------
     * ÓRDENES DE COMPRA
     * ------------------------------------------------------------------ */

    /**
     * Sirve el PDF de una orden de compra. $tipo = 'orden' (lo solicitado) o
     * 'cierre' (solicitado vs. recibido, para revisar antes del pago).
     * Solo lee: generar el documento nunca modifica inventario ni estados.
     */
    public static function descargarOrdenCompra(int $ordenId, string $tipo): void
    {
        $orden = OrdenesCompra::buscarID($ordenId);
        if (!$orden) {
            \paginaNoEncontrada();
        }

        $partidas = OrdenesCompra::partidas($ordenId);

        ob_start();
        \template('pdfOrdenCompra', [
            'orden'       => $orden,
            'partidas'    => $partidas,
            'totales'     => OrdenesCompra::totales($partidas),
            'recepciones' => OrdenesCompra::recepciones($ordenId),
            'tipo'        => $tipo === 'cierre' ? 'cierre' : 'orden',
        ]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'la orden de compra');
        if ($bytes === null) {
            \redirigir('/admin/compras/detalle?id=' . $ordenId, 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, ($tipo === 'cierre' ? 'cierre-' : 'orden-') . $orden['folio']);
    }

    /**
     * Envía un PDF al navegador: lo muestra en pantalla para descargarlo.
     *
     * @param string $bytes  Contenido del PDF.
     * @param string $nombre Nombre del archivo sin extensión.
     */
    private static function enviar(string $bytes, string $nombre): void
    {
        $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre);
        $nombre = $nombre !== '' ? $nombre : 'documento';

        // Limpia cualquier búfer activo: dompdf envía las cabeceras a continuación.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $nombre . '.pdf"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, max-age=0, must-revalidate');

        echo $bytes;
        exit;
    }

    /**
     * Envía el PDF al navegador: lo muestra en pantalla para descargarlo.
     *
     * La petición termina aquí mismo (exit), igual que un stream de dompdf.
     */
    public static function descargar(Cotizaciones|Ventas $cotizacion, array $detalles): void
    {
        $bytes = self::pdf($cotizacion, $detalles);

        if ($bytes === null) {
            \redirigir(
                ($cotizacion instanceof Ventas ? '/admin/ventas/detalle?id=' : '/admin/cotizaciones/detalle?id=')
                    . (int) $cotizacion->id,
                'error',
                self::$errores[0] ?? 'No fue posible generar el PDF.'
            );
        }

        $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $cotizacion->folio);
        self::enviar($bytes, $nombre !== '' ? $nombre : 'cotizacion');
    }

    /** Devuelve y limpia los errores acumulados. */
    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }
}
