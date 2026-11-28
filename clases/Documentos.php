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
    public static function html(Cotizaciones|Ventas|Pedidos $cotizacion, array $detalles): string
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
    public static function pdf(Cotizaciones|Ventas|Pedidos $cotizacion, array $detalles): ?string
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
    private static function componer(string $html, string $origen, string $orientacion = self::ORIENTACION): ?string
    {
        // Mientras dompdf maqueta (un documento grande tarda bastante), la
        // sesión queda libre para que las demás pestañas del usuario no se
        // queden esperando.
        liberarSesion();

        // Si el mismo documento ya se compuso, se sirve tal cual: el HTML es
        // el mismo, así que el PDF sale idéntico.
        $clave = \Classes\CachePdf::clave($html, self::ANCHO_PAGINA, $orientacion);
        $guardado = \Classes\CachePdf::obtener($clave);
        if ($guardado !== null) {
            return $guardado;
        }

        try {
            $opciones = new \Dompdf\Options();
            $opciones->set('isRemoteEnabled', false);   // nunca sale a Internet
            $opciones->set('isHtml5ParserEnabled', true);
            $opciones->set('defaultFont', 'DejaVu Sans');
            $opciones->set('chroot', dirname(__DIR__, 2) . '/public');

            $dompdf = new \Dompdf\Dompdf($opciones);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper(self::ANCHO_PAGINA, $orientacion);
            $dompdf->render();

            $bytes = $dompdf->output();
            if (!is_string($bytes) || $bytes === '') {
                self::$errores[] = 'El PDF de ' . $origen . ' quedó vacío.';
                return null;
            }

            \Classes\CachePdf::guardar($clave, $bytes);

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
    public static function descargarTabla(string $titulo, string $subtitulo, array $encabezados, array $filas, string $nombre, string $pie = '', string $volver = '/admin/inventario', bool $horizontal = false): void
    {
        ob_start();
        \template('pdfTablaSimple', ['titulo' => $titulo, 'subtitulo' => $subtitulo, 'encabezados' => $encabezados, 'filas' => $filas, 'pie' => $pie]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'el reporte', $horizontal ? 'landscape' : self::ORIENTACION);
        if ($bytes === null) {
            \redirigir($volver, 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, $nombre);
    }

    /** PDF del reporte fiscal (ventas, gastos, impuestos y proveedores). Solo lectura. */
    public static function descargarReporteFiscal(array $datos, string $nombre, string $volver): void
    {
        ob_start();
        \template('pdfFiscalMovimientos', $datos);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'el reporte', 'landscape');
        if ($bytes === null) {
            \redirigir($volver, 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, $nombre);
    }

    /** PDF del Preliminar básico de impuestos (simulador con las facturas). Solo lectura. */
    public static function descargarPreliminarFiscal(array $datos, string $nombre, string $volver): void
    {
        ob_start();
        \template('pdfFiscalPreliminar', $datos);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'el preliminar', 'landscape');
        if ($bytes === null) {
            \redirigir($volver, 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, $nombre);
    }

    /**
     * PDF de las compras de un cliente en un periodo.
     *
     * @param array<string, mixed> $reporte resumen, productos, documentos y serie
     */
    public static function descargarComprasCliente(
        Clientes $cliente,
        array $reporte,
        string $periodoTexto,
        string $empresaNombre,
        string $nombre,
        string $volver
    ): void {
        ob_start();
        \template('pdfComprasCliente', [
            'cliente'       => $cliente,
            'reporte'       => $reporte,
            'periodoTexto'  => $periodoTexto,
            'empresaNombre' => $empresaNombre,
            'fecha'         => date('d/m/Y H:i'),
        ]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'las compras del cliente');
        if ($bytes === null) {
            \redirigir($volver, 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, $nombre);
    }

    /** PDF del estado de un proyecto de entrega. Solo lectura. */
    public static function descargarProyectoEntrega(ProyectosEntrega $proyecto, array $progreso): void
    {
        ob_start();
        \template('pdfProyectoEntrega', [
            'proyecto' => $proyecto,
            'progreso' => $progreso,
            'fecha'    => date('d/m/Y H:i'),
        ]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'el proyecto de entrega');
        if ($bytes === null) {
            \redirigir(
                '/admin/proyectos/detalle?id=' . (int) $proyecto->id,
                'error',
                self::$errores[0] ?? 'No fue posible generar el PDF.'
            );
        }

        self::enviar($bytes, 'proyecto-' . $proyecto->folio);
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

    /**
     * PDF del primer apartado de Análisis Financiero: medidor, resumen,
     * inventario, proveedores pendientes y gastos operativos del mes.
     * Solo lectura: exportar no modifica ninguna cifra.
     */
    public static function descargarAnalisisFinanciero(array $datos, string $nombre, string $volver): void
    {
        ob_start();
        \template('pdfAnalisisFinanciero', $datos);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'el análisis financiero');
        if ($bytes === null) {
            \redirigir($volver, 'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        self::enviar($bytes, $nombre);
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
    public static function descargarEntrega(int $preparacionId, string $tipo, array $ventaIds = []): void
    {
        $contexto = self::contextoEntrega($preparacionId, $tipo, $ventaIds);

        ob_start();
        \template('pdfEntregas', $contexto + ['imprimir' => false]);
        $html = (string) ob_get_clean();

        $bytes = self::componer($html, 'la preparación');
        if ($bytes === null) {
            \redirigir($preparacionId ? '/admin/entregas/detalle?id=' . $preparacionId : '/admin/entregas',
                'error', self::$errores[0] ?? 'No fue posible generar el PDF.');
        }

        $referencia = $contexto['prep']['folio'] ?? 'seleccion-' . date('Ymd-Hi');
        self::enviar($bytes, ($contexto['tipo'] === 'entrega' ? 'entrega-' : 'preparacion-') . $referencia);
    }

    /**
     * PDF de una lista de preparación o de una hoja de entrega consolidada.
     * Devuelve los bytes para que la API de la app los sirva con su token.
     *
     * @param int[] $ventaIds
     */
    public static function pdfListaEntrega(int $preparacionId, string $tipo, array $ventaIds = []): ?string
    {
        $contexto = self::contextoEntrega($preparacionId, $tipo, $ventaIds);

        ob_start();
        \template('pdfEntregas', $contexto + ['imprimir' => false]);
        $html = (string) ob_get_clean();

        return self::componer($html, $tipo === 'entrega' ? 'la hoja de entrega' : 'la preparación');
    }

    /**
     * Hoja de entrega en DOS copias dentro de una hoja carta horizontal.
     * Cada copia ocupa media hoja (5.5 × 8.5 pulgadas) y se separa con un
     * corte vertical. Es el formato que usa la app de entregas.
     */
    public static function pdfEntregaDosCopias(int $ventaId): ?string
    {
        $venta = Ventas::buscarID($ventaId);
        if (!$venta) {
            self::$errores[] = 'La venta no existe.';
            return null;
        }

        $lineas = array_values(array_filter(
            Entregas::pendientesDeVentas([$ventaId])[$ventaId] ?? [],
            static fn (array $linea): bool => (int) $linea['pendiente'] > 0
        ));
        if (!$lineas) {
            self::$errores[] = 'La venta no tiene productos por entregar.';
            return null;
        }

        ob_start();
        \template('pdfEntregaDosCopias', [
            'venta'  => $venta,
            'lineas' => $lineas,
            'fecha'  => date('d/m/Y H:i'),
        ]);
        $html = (string) ob_get_clean();

        return self::componer($html, 'la hoja de entrega', 'landscape');
    }

    /**
     * Acta de entrega de una venta (todas sus entregas o una sola).
     */
    public static function pdfActaEntregaVenta(int $ventaId, int $entregaId = 0): ?string
    {
        $venta = Ventas::buscarID($ventaId);
        if (!$venta) {
            self::$errores[] = 'La venta no existe.';
            return null;
        }

        $entregas = Entregas::entregasDeVenta($ventaId);
        if (!$entregas) {
            self::$errores[] = 'Esta venta todavía no tiene entregas registradas para el acta.';
            return null;
        }

        if ($entregaId > 0) {
            $entregas = array_values(array_filter(
                $entregas,
                static fn (array $entrega): bool => (int) $entrega['id'] === $entregaId
            ));
            if (!$entregas) {
                self::$errores[] = 'La entrega indicada no pertenece a esta venta.';
                return null;
            }
        }

        $incidencias = IncidenciasEntrega::deVenta($ventaId);
        if ($entregaId > 0) {
            $incidencias = array_values(array_filter(
                $incidencias,
                static fn (array $incidencia): bool => (int) ($incidencia['entrega_id'] ?? 0) === $entregaId
            ));
        }

        return self::pdfActaEntrega($venta, $entregas, $incidencias);
    }

    /**
     * Versión para imprimir: la misma hoja del PDF como página con botón de impresión.
     * Solo lee; nunca registra entregas ni toca existencias.
     */
    public static function imprimirEntrega(int $preparacionId, string $tipo, array $ventaIds = []): void
    {
        $contexto = self::contextoEntrega($preparacionId, $tipo, $ventaIds);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        \template('pdfEntregas', $contexto + ['imprimir' => true]);
        exit;
    }

    /** @return array{prep: ?array, ventas: array, grupos: array, tipo: string} */
    private static function contextoEntrega(int $preparacionId, string $tipo, array $ventaIds): array
    {
        $prep = null;
        if ($preparacionId > 0) {
            $prep = Entregas::buscarPreparacion($preparacionId);
            if (!$prep) {
                \paginaNoEncontrada();
            }
            $ventas = Entregas::ventasDePreparacion($preparacionId);
        } else {
            $ventas = Entregas::ventasPorIds($ventaIds);
            if (!$ventas) {
                \redirigir('/admin/entregas', 'error', 'Elige al menos un pedido para exportarlo.');
            }
        }

        return [
            'prep'   => $prep,
            'ventas' => $ventas,
            'grupos' => Entregas::productosAgrupados($ventas),
            'tipo'   => $tipo === 'entrega' ? 'entrega' : 'preparacion',
        ];
    }

    /* --------------------------------------------------------------------
     * ACTA DE ENTREGA (seguimiento de una venta)
     * ------------------------------------------------------------------ */

    /** Área útil de la hoja carta del acta (márgenes de 1.3 cm × 1.1 cm), en puntos. */
    private const ACTA_ALTO_UTIL  = 792 - 2 * 36.85;
    private const ACTA_ANCHO_UTIL = 612 - 2 * 31.18;
    /** Separación entre fotos (horizontal y vertical) y tope de alto de cada foto, en puntos. */
    private const ACTA_HUECO      = 6.0;
    private const ACTA_ALTO_MAX   = 380.0;
    private const ACTA_ALTO_MIN   = 42.0;
    /** Lado mayor de las fotos incrustadas: de sobra para la hoja y el PDF pesa y tarda menos. */
    private const ACTA_LADO_FOTO  = 1100;
    /** Tamaño (pt) del pie de cada foto, según quepan 1–2 o 3+ columnas. */
    private const ACTA_FUENTE_PIE          = 8.5;
    private const ACTA_FUENTE_PIE_COMPACTA = 8.0;

    /**
     * HTML del acta: datos del negocio emisor y del cliente, cada entrega con
     * sus productos, incidencias, comentarios, fotos y ubicación.
     *
     * @param Ventas              $venta        Venta de la que se emite el acta.
     * @param array<int, array>   $entregas     Entregas::entregasDeVenta().
     * @param array<int, array>   $incidencias  IncidenciasEntrega::deVenta().
     * @param array<string,mixed> $distribucion columnas, alto (pt) de cada foto,
     *                                          fuente (pt del pie) y medir
     *                                          (true = sin fotos, para medir el texto).
     */
    public static function htmlActaEntrega(Ventas $venta, array $entregas, array $incidencias, array $distribucion = []): string
    {
        ob_start();

        \template('pdfActaEntrega', [
            'venta'        => $venta,
            'entregas'     => self::fotosParaActa($entregas),
            'incidencias'  => $incidencias,
            'fecha'        => date('d/m/Y H:i'),
            'distribucion' => $distribucion + [
                'columnas' => 2,
                'alto'     => 220.0,
                'medir'    => false,
                'ancho'    => self::ACTA_ANCHO_UTIL,
                'hueco'    => self::ACTA_HUECO,
                'fuente'   => self::ACTA_FUENTE_PIE,
            ],
        ]);

        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * PDF del acta de entrega como cadena de bytes, en UNA sola hoja.
     *
     * 1. Se compone el acta sin fotos para medir cuánto ocupa el texto.
     * 2. Con el espacio que sobra se elige cuántas columnas y qué alto llevan
     *    las fotos (la combinación que las muestra más grandes).
     * 3. Se compone de verdad y se comprueba que salga una sola página; si el
     *    cálculo se quedó corto, se reducen las fotos y se vuelve a intentar.
     * Si ni el texto cabe en una hoja (muchas entregas o productos), el acta
     * fluye en varias hojas sin dejar huecos: ninguna entrega salta completa
     * a la página siguiente.
     */
    public static function pdfActaEntrega(Ventas $venta, array $entregas, array $incidencias): ?string
    {
        \liberarSesion();

        $entregas = self::fotosParaActa($entregas);
        $medicion = self::htmlActaEntrega($venta, $entregas, $incidencias, ['medir' => true]);
        if ($medicion === '') {
            self::$errores[] = 'No se pudo componer el acta de entrega.';
            return null;
        }

        // El PDF depende del texto y de las fotos (nombre y fecha de cada archivo).
        $huella = [];
        foreach ($entregas as $entrega) {
            foreach ($entrega['fotos'] ?? [] as $foto) {
                $huella[] = ($foto['archivo'] ?? '') . '@' . ($foto['_pdf']['firma'] ?? '');
            }
        }
        $clave = \Classes\CachePdf::clave($medicion . '|acta-v5|' . implode(',', $huella), self::ANCHO_PAGINA, self::ORIENTACION);
        $guardado = \Classes\CachePdf::obtener($clave);
        if ($guardado !== null) {
            return $guardado;
        }

        $medida = self::renderizarActa($medicion);
        if ($medida === null) {
            return null;
        }

        $conFotos = $huella !== [];
        if (!$conFotos) {
            $bytes = $medida['bytes'];
        } elseif ($medida['paginas'] > 1 || $medida['fin'] === null) {
            // El texto solo ya no cabe: varias hojas con fotos a tamaño normal.
            $final = self::renderizarActa(self::htmlActaEntrega($venta, $entregas, $incidencias));
            $bytes = $final['bytes'] ?? null;
        } else {
            // Pie de cada foto (fecha + ubicación recortada a 72 letras, como en la plantilla).
            foreach ($entregas as $i => $entrega) {
                $manual = (string) ($entrega['ubicacion_maps'] ?? '');
                $lat = $manual !== '' && ($entrega['ubicacion_lat'] ?? null) !== null ? (float) $entrega['ubicacion_lat'] : null;
                $lon = $manual !== '' && ($entrega['ubicacion_lon'] ?? null) !== null ? (float) $entrega['ubicacion_lon'] : null;
                foreach ($entrega['fotos'] ?? [] as $j => $foto) {
                    if (!empty($foto['_pdf'])) {
                        $texto = FotosEntrega::descripcionUbicacion($foto, (string) $venta->cliente_ubicacion, $manual, $lat, $lon)['texto'];
                        $entregas[$i]['fotos'][$j]['_pdf']['pie'] = "Fecha de registro de la entrega: 00/00/0000 00:00\n"
                            . mb_strimwidth($texto, 0, 72, '…');
                    }
                }
            }
            $libre = self::ACTA_ALTO_UTIL - $medida['fin'] - 4;
            $dist  = self::distribucionActa($entregas, $libre);
            $bytes = null;
            $buena = null; // última composición que cupo en una hoja

            for ($intento = 0; $intento < 7; $intento++) {
                $final = self::renderizarActa(self::htmlActaEntrega($venta, $entregas, $incidencias, $dist));
                if ($final === null) {
                    return null;
                }

                if ($final['paginas'] <= 1) {
                    $buena = $final['bytes'];
                    // ¿Sobra espacio abajo? Se agrandan las fotos para aprovecharlo.
                    $sobra = self::ACTA_ALTO_UTIL - (float) ($final['fin'] ?? self::ACTA_ALTO_UTIL) - 4;
                    $tope  = min(self::ACTA_ALTO_MAX, $dist['tope']);
                    if ($sobra < 12 || $dist['alto'] >= $tope - 1) {
                        break;
                    }
                    $dist['anterior'] = $dist['alto'];
                    $dist['alto'] = round(min($tope, $dist['alto'] + $sobra / $dist['filas'] * 0.97), 1);
                    continue;
                }

                if ($buena !== null) {
                    break; // el agrandado ya no cupo: se queda la anterior
                }
                $bytes = $final['bytes'];
                if ($dist['alto'] <= self::ACTA_ALTO_MIN) {
                    break;
                }
                $dist['alto'] = max(self::ACTA_ALTO_MIN, round($dist['alto'] * 0.85, 1));
            }
            $bytes = $buena ?? $bytes;
        }

        if (!is_string($bytes) || $bytes === '') {
            self::$errores[] = 'El PDF del acta de entrega quedó vacío.';
            return null;
        }

        \Classes\CachePdf::guardar($clave, $bytes);

        return $bytes;
    }

    /**
     * Compone un HTML del acta y devuelve los bytes, las páginas y dónde
     * termina el contenido de la primera hoja (pt desde el borde del área útil).
     *
     * @return array{bytes: string, paginas: int, fin: float|null}|null
     */
    private static function renderizarActa(string $html): ?array
    {
        try {
            $opciones = new \Dompdf\Options();
            $opciones->set('isRemoteEnabled', false);
            $opciones->set('isHtml5ParserEnabled', true);
            $opciones->set('defaultFont', 'DejaVu Sans');
            $opciones->set('chroot', dirname(__DIR__, 2) . '/public');

            $dompdf = new \Dompdf\Dompdf($opciones);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper(self::ANCHO_PAGINA, self::ORIENTACION);

            // dompdf libera el árbol al terminar: la posición de la marca final
            // se toma mientras dibuja (solo cuenta si cae en la primera hoja).
            $fin = null;
            $dompdf->setCallbacks([[
                'event' => 'begin_frame',
                'f'     => static function ($frame, $canvas) use (&$fin): void {
                    $nodo = $frame->get_node();
                    if ($nodo instanceof \DOMElement && $nodo->getAttribute('id') === 'acta-fin'
                        && (int) $canvas->get_page_number() === 1) {
                        $y = $frame->get_position('y');
                        $fin = is_numeric($y) ? (float) $y - 36.85 : null;
                    }
                },
            ]]);
            $dompdf->render();

            return [
                'bytes'   => (string) $dompdf->output(),
                'paginas' => (int) $dompdf->getCanvas()->get_page_count(),
                'fin'     => $fin,
            ];
        } catch (\Throwable $excepcion) {
            error_log('[Documentos::renderizarActa] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible generar el PDF del acta de entrega.';
            return null;
        }
    }

    /**
     * Columnas y alto de las fotos que mejor llenan $libre puntos de alto.
     * Se prueba de 1 a 6 columnas y gana la que deja las fotos con más área
     * visible (respetando la proporción de cada una).
     *
     * @return array{columnas: int, alto: float, filas: int, tope: float, fuente: float}
     */
    private static function distribucionActa(array $entregas, float $libre): array
    {
        $grupos = [];
        foreach ($entregas as $entrega) {
            $fotos = array_values(array_filter($entrega['fotos'] ?? [], static fn ($f) => !empty($f['_pdf'])));
            if ($fotos) {
                $grupos[] = $fotos;
            }
        }
        $total = array_sum(array_map('count', $grupos));
        $mejor = ['columnas' => min(6, max(2, $total)), 'alto' => self::ACTA_ALTO_MIN, 'puntos' => -1.0, 'filas' => 1, 'tope' => self::ACTA_ALTO_MAX, 'fuente' => self::ACTA_FUENTE_PIE];

        for ($columnas = 1; $columnas <= 6; $columnas++) {
            if ($columnas > max(1, $total)) {
                break;
            }
            $ancho = (self::ACTA_ANCHO_UTIL - ($columnas - 1) * self::ACTA_HUECO) / $columnas;
            $fuente = $columnas >= 3 ? self::ACTA_FUENTE_PIE_COMPACTA : self::ACTA_FUENTE_PIE;
            $filas = 0;
            $pies = 0.0;
            foreach ($grupos as $fotos) {
                foreach (array_chunk($fotos, $columnas) as $fila) {
                    $filas++;
                    $lineas = 1;
                    foreach ($fila as $foto) {
                        $lineas = max($lineas, self::lineasPie((string) ($foto['_pdf']['pie'] ?? ''), $ancho, $fuente));
                    }
                    $pies += $lineas * $fuente * 1.25 + self::ACTA_HUECO;
                }
                $pies += 6; // margen de cada tabla de fotos
            }
            if ($filas === 0) {
                break;
            }

            $alto = min(self::ACTA_ALTO_MAX, $ancho * 1.4, ($libre - $pies) / $filas);
            if ($alto < self::ACTA_ALTO_MIN) {
                continue;
            }

            $puntos = 0.0;
            foreach ($grupos as $fotos) {
                foreach ($fotos as $foto) {
                    $w = (float) $foto['_pdf']['ancho'];
                    $h = (float) $foto['_pdf']['alto'];
                    $escala = min($ancho / $w, $alto / $h);
                    $puntos += $w * $h * $escala * $escala;
                }
            }
            if ($puntos > $mejor['puntos'] * 1.02) {
                $mejor = ['columnas' => $columnas, 'alto' => $alto, 'puntos' => $puntos, 'filas' => $filas, 'tope' => $ancho * 1.4, 'fuente' => $fuente];
            }
        }

        return [
            'columnas' => $mejor['columnas'],
            'alto'     => round($mejor['alto'], 1),
            'filas'    => max(1, $mejor['filas']),
            'tope'     => $mejor['tope'],
            'fuente'   => $mejor['fuente'],
        ];
    }

    /** Líneas que ocupa el pie de una foto en una columna de $ancho puntos. */
    private static function lineasPie(string $texto, float $ancho, float $fuente): int
    {
        $porLinea = max(8, (int) floor($ancho / ($fuente * 0.52)));
        $lineas = 0;
        foreach (explode("\n", $texto) as $renglon) {
            $lineas += max(1, (int) ceil(mb_strlen($renglon) / $porLinea));
        }
        return max(1, $lineas);
    }

    /**
     * Añade a cada foto `_pdf` = {src, ancho, alto, firma}: la imagen reducida
     * e incrustada en base64 y su proporción. Se calcula una vez por archivo.
     */
    private static function fotosParaActa(array $entregas): array
    {
        static $memoria = [];

        foreach ($entregas as $i => $entrega) {
            foreach (($entrega['fotos'] ?? []) as $j => $foto) {
                if (isset($foto['_pdf'])) {
                    continue;
                }
                $archivo = basename(trim((string) ($foto['archivo'] ?? '')));
                $ruta = Imagenes::CARPETA . '/' . $archivo;
                if ($archivo === '' || !is_file($ruta)) {
                    $entregas[$i]['fotos'][$j]['_pdf'] = null;
                    continue;
                }
                $firma = $archivo . ':' . (int) filemtime($ruta) . ':' . (int) filesize($ruta);
                $memoria[$firma] ??= self::fotoReducida($ruta);
                $datos = $memoria[$firma];
                $entregas[$i]['fotos'][$j]['_pdf'] = $datos ? $datos + ['firma' => $firma] : null;
            }
        }

        return $entregas;
    }

    /** @return array{src: string, ancho: int, alto: int}|null */
    private static function fotoReducida(string $ruta): ?array
    {
        $contenido = (string) @file_get_contents($ruta);
        $medidas = @getimagesizefromstring($contenido);
        if ($contenido === '' || !$medidas || $medidas[0] < 1 || $medidas[1] < 1) {
            return null;
        }
        [$ancho, $alto] = $medidas;
        $mime = (string) ($medidas['mime'] ?? 'image/jpeg');

        $lado = max($ancho, $alto);
        if (($lado > self::ACTA_LADO_FOTO || $mime === 'image/webp') && function_exists('imagecreatefromstring')) {
            $imagen = @imagecreatefromstring($contenido);
            if ($imagen !== false) {
                $escala = min(1, self::ACTA_LADO_FOTO / $lado);
                $nuevo = imagescale($imagen, max(1, (int) round($ancho * $escala)), max(1, (int) round($alto * $escala)));
                if ($nuevo !== false) {
                    ob_start();
                    imagejpeg($nuevo, null, 82);
                    $reducido = (string) ob_get_clean();
                    if ($reducido !== '') {
                        [$contenido, $mime, $ancho, $alto] = [$reducido, 'image/jpeg', imagesx($nuevo), imagesy($nuevo)];
                    }
                }
            }
        }

        return [
            'src'   => 'data:' . $mime . ';base64,' . base64_encode($contenido),
            'ancho' => (int) $ancho,
            'alto'  => (int) $alto,
        ];
    }

    /**
     * Envía el acta al navegador como descarga.
     *
     * @param int $ventaId   Venta de la que se emite el acta.
     * @param int $entregaId 0 = todas las entregas de la venta; > 0 = sólo esa.
     */
    public static function descargarActaEntrega(int $ventaId, int $entregaId = 0): void
    {
        $venta = Ventas::buscarID($ventaId);
        if (!$venta) {
            \paginaNoEncontrada();
        }

        $destino = '/admin/entregas-seguimiento/venta?id=' . $ventaId;
        $entregas = Entregas::entregasDeVenta($ventaId);

        if (!$entregas) {
            \redirigir($destino, 'error', 'Esta venta todavía no tiene entregas registradas para el acta.');
        }

        if ($entregaId > 0) {
            $entregas = array_values(array_filter(
                $entregas,
                static fn (array $entrega): bool => (int) $entrega['id'] === $entregaId
            ));
            if (!$entregas) {
                \redirigir($destino, 'error', 'La entrega indicada no pertenece a esta venta.');
            }
        }

        $incidencias = IncidenciasEntrega::deVenta($ventaId);
        if ($entregaId > 0) {
            $incidencias = array_values(array_filter(
                $incidencias,
                static fn (array $incidencia): bool => (int) ($incidencia['entrega_id'] ?? 0) === $entregaId
            ));
        }

        $bytes = self::pdfActaEntrega($venta, $entregas, $incidencias);
        if ($bytes === null) {
            \redirigir($destino, 'error', self::$errores[0] ?? 'No fue posible generar el acta de entrega.');
        }

        $nombre = 'acta-entrega-' . $venta->folio;
        if ($entregaId > 0) {
            $nombre .= '-entrega-' . $entregaId;
        }

        self::enviar($bytes, $nombre);
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
            'resumen'     => OrdenesCompra::resumen($ordenId),
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
     * Envía un PDF al navegador como DESCARGA (igual que los CSV y el Excel).
     *
     * Antes se mandaba «inline»: el PDF se abría en el visor del navegador y el
     * botón «Exportar PDF» no descargaba nada, mientras que CSV/Excel sí
     * descargaban. Ahora todos los reportes se comportan igual.
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
        header('Content-Disposition: attachment; filename="' . $nombre . '.pdf"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');

        echo $bytes;
        exit;
    }

    /**
     * Envía el PDF al navegador: lo muestra en pantalla para descargarlo.
     *
     * La petición termina aquí mismo (exit), igual que un stream de dompdf.
     */
    public static function descargar(Cotizaciones|Ventas|Pedidos $cotizacion, array $detalles): void
    {
        $bytes = self::pdf($cotizacion, $detalles);

        if ($bytes === null) {
            \redirigir(
                ($cotizacion instanceof Pedidos ? '/admin/pedidos/detalle?id=' : ($cotizacion instanceof Ventas ? '/admin/ventas/detalle?id=' : '/admin/cotizaciones/detalle?id='))
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
