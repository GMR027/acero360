<?php

namespace App;

/**
 * IncidenciasEntrega — seguimiento de entregas después de la venta.
 *
 * Cuando una venta ya está entregada (total o parcialmente) pueden aparecer
 * incidencias: producto faltante, dañado o defectuoso, incompleto, cambio o
 * sustitución y devolución. El módulo:
 *
 *   • Registra la incidencia con sus renglones (producto + cantidad).
 *   • Devuelve el producto al inventario EN LA MISMA transacción (la venta ya
 *     lo descontó al registrarse): movimiento «entrada» con motivo
 *     «devolucion» ligado a la venta + renglón en `venta_devoluciones`, de
 *     modo que lo pendiente de entregar se ajusta solo
 *     (Entregas::pendientesDeVentas lo absorbe).
 *   • Si el cliente debe recibir un reemplazo, el renglón queda «a cambiar»
 *     y la pieza nueva se descuenta del inventario cuando se entrega
 *     (Inventario::entregarCambio, flujo existente de devoluciones y cambios).
 *   • Si la incidencia pide cancelar la factura, corrige la facturación del
 *     ingreso: da de baja el PDF/XML anteriores, sube los correctos y
 *     sincroniza subtotal/IVA/total/UUID con los datos del XML nuevo; el
 *     monto de la venta NO cambia. Los cobros ya registrados se revisan: si
 *     quedaron de más, el sistema lo muestra para que se ajusten al caso.
 *   • Lleva el ESTADO GENERAL del seguimiento por venta (`ventas.entrega_estado`)
 *     actualizado en cada operación: pendiente, entregado, entregado con
 *     incidencias pendientes y entregado con incidencias resueltas.
 *
 * El historial (entregas, devoluciones, cambios, incidencias y correcciones
 * de factura) vive en la tabla `auditoria`; las operaciones registran su
 * accion para armar la línea de tiempo de la pantalla.
 */
class IncidenciasEntrega
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Id de la última incidencia creada. */
    public static $ultimoId = 0;

    /** Motivo de la incidencia: etiqueta, icono y badge. */
    public const MOTIVOS = [
        'producto_faltante'  => ['etiqueta' => 'Producto faltante',            'icono' => 'direct_off',    'clase' => 'badge--pendiente'],
        'producto_danado'    => ['etiqueta' => 'Producto dañado o defectuoso', 'icono' => 'report_problem','clase' => 'badge--error'],
        'producto_incompleto'=> ['etiqueta' => 'Producto incompleto',          'icono' => 'inventory',     'clase' => 'badge--pendiente'],
        'cambio_sustitucion' => ['etiqueta' => 'Cambio o sustitución',         'icono' => 'swap_horiz',    'clase' => 'badge--info'],
        'devolucion'         => ['etiqueta' => 'Devolución',                   'icono' => 'assignment_return', 'clase' => 'badge--neutro'],
    ];

    /**
     * Motivos que SÍ devuelven piezas al inventario: lo que no salió completo
     * del almacén (faltante/incompleto) se corrige con una entrada de
     * inventario ligada a la venta. Los demás motivos (dañado, cambio,
     * devolución) sólo se NOTIFICAN: no mueven inventario.
     */
    public const MOTIVOS_INVENTARIO = ['producto_faltante', 'producto_incompleto'];

    /**
     * Motivos que exigen corregir la factura: si la venta ya está facturada,
     * hay que cancelar el CFDI actual y subir la correcta sólo por lo
     * entregado (p. ej. 8 de 10 piezas vendidas). La casilla se fuerza en
     * `registrar()` aunque el formulario no la mande.
     */
    public const MOTIVOS_CORRECCION = ['producto_faltante', 'producto_incompleto'];

    /** Estado de la incidencia. */
    public const ESTADOS = [
        'abierta'   => ['etiqueta' => 'Abierta',   'icono' => 'pending',  'clase' => 'badge--pendiente'],
        'resuelta'  => ['etiqueta' => 'Resuelta',  'icono' => 'check_circle', 'clase' => 'badge--aprobado'],
        'cancelada' => ['etiqueta' => 'Cancelada', 'icono' => 'cancel',   'clase' => 'badge--neutro'],
    ];

    /** Estados generales del seguimiento de la entrega (columna `ventas.entrega_estado`). */
    public const ESTADOS_ENTREGA = [
        'pendiente'                      => ['etiqueta' => 'Pendiente de entregar',       'icono' => 'schedule',      'clase' => 'badge--pendiente'],
        'entregado'                      => ['etiqueta' => 'Entregado',                   'icono' => 'check_circle',  'clase' => 'badge--aprobado'],
        'entregado_con_incidencias'      => ['etiqueta' => 'Entregado con incidencias pendientes', 'icono' => 'error', 'clase' => 'badge--error'],
        'entregado_incidencias_resueltas'=> ['etiqueta' => 'Entregado · incidencias resueltas',    'icono' => 'task_alt', 'clase' => 'badge--info'],
    ];

    /** Estados de la tabla `ventas.entrega_estado` sobre los que listado filtra. */
    public const FILTROS_ENTREGA = ['pendiente', 'entregado', 'entregado_con_incidencias', 'entregado_incidencias_resueltas'];

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
     * REGISTRAR INCIDENCIA
     * ------------------------------------------------------------------ */

    /**
     * Registra una incidencia de entrega de una venta.
     *
     * Renglones: producto_id => ['cantidad' => int, 'cambio' => bool|int].
     *
     * Regla de negocio por motivo:
     *   · **faltante / incompleto** → las piezas vuelven al inventario (entrada
     *     por devolución ligada a la venta) y, si la venta ya está facturada,
     *     la corrección de factura queda OBLIGATORIA (cancelar y subir la
     *     correcta sólo por lo entregado);
     *   · **dañado / cambio / devolución** → sólo NOTIFICACIÓN: no se mueve
     *     inventario.
     * En todos los motivos queda anotado en `venta_devoluciones`, de modo que
     * el pendiente de entrega se ajusta solo.
     *
     * @return bool false revierte todo (el llamador no debe continuar).
     */
    public static function registrar(
        int $ventaId,
        string $motivo,
        array $renglones,
        string $notas,
        bool $facturaRequerida,
        int $usuarioId,
        ?int $entregaId = null
    ): bool {
        self::$errores = [];
        self::$ultimoId = 0;

        if (!isset(self::MOTIVOS[$motivo])) {
            self::$errores[] = 'El motivo de la incidencia no es válido.';
            return false;
        }

        $filtrados = [];
        foreach ($renglones as $productoId => $r) {
            $cantidad = max(0, (int) ($r['cantidad'] ?? 0));
            $cambio   = max(0, (int) ($r['cambio'] ?? 0));
            if ($cantidad <= 0) {
                continue;
            }
            if ($cambio > $cantidad) {
                self::$errores[] = 'Las piezas a cambiar no pueden ser más que las afectadas.';
                return false;
            }
            $filtrados[(int) $productoId] = ['cantidad' => $cantidad, 'cambio' => $cambio];
        }
        if (!$filtrados) {
            self::$errores[] = 'Indica al menos un producto con piezas afectadas.';
            return false;
        }
        $notas = mb_substr(trim($notas), 0, 400);

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT folio, estado FROM ventas WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $ventaId);
            $bloqueo->execute();
            $venta = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if (!$venta || $venta['estado'] !== 'registrada') {
                self::$db->rollBack();
                self::$errores[] = 'Solo se registran incidencias de una venta registrada.';
                return false;
            }

            // No se afecta más de lo vendido que aún no se ha devuelto.
            $disponibles = Inventario::devolvibles($ventaId);
            $nombres = [];
            if ($filtrados) {
                $marcadores = implode(',', array_fill(0, count($filtrados), '?'));
                $nombresQ = self::$db->prepare("SELECT id, nombre FROM productos WHERE id IN ($marcadores)");
                $nombresQ->bind_param(str_repeat('i', count($filtrados)), ...array_keys($filtrados));
                $nombresQ->execute();
                foreach ($nombresQ->get_result() as $f) {
                    $nombres[(int) $f['id']] = (string) $f['nombre'];
                }
                $nombresQ->close();
            }
            foreach ($filtrados as $productoId => $r) {
                $info = $disponibles[$productoId] ?? null;
                if ($info === null) {
                    self::$db->rollBack();
                    self::$errores[] = 'El producto #' . $productoId . ' ya no está en la venta.';
                    return false;
                }
                if ($r['cantidad'] > (int) $info['disponible']) {
                    self::$db->rollBack();
                    self::$errores[] = 'No puedes afectar más piezas de las vendidas de «' . ($nombres[$productoId] ?? '#' . $productoId) . '» (quedan '
                        . (int) $info['disponible'] . ' sin devolver).';
                    return false;
                }
            }

            if ($entregaId !== null && $entregaId > 0) {
                $existe = self::$db->prepare('SELECT 1 FROM entregas WHERE id = ? AND venta_id = ?');
                $existe->bind_param('ii', $entregaId, $ventaId);
                $existe->execute();
                if ($existe->get_result()->num_rows === 0) {
                    $entregaId = null;
                }
                $existe->close();
            } else {
                $entregaId = null;
            }

            // Faltante/incompleto de una venta YA facturada: la corrección de
            // factura se fuerza (hay que cancelar el CFDI actual y subir el
            // correcto), aunque el formulario no haya marcado la casilla.
            if (in_array($motivo, self::MOTIVOS_CORRECCION, true) && !$facturaRequerida) {
                $facturada = self::$db->prepare(
                    "SELECT 1 FROM ingresos
                      WHERE venta_id = ? AND estado = 'vigente'
                        AND estado_facturacion = 'facturada' AND uuid_fiscal <> ''"
                );
                $facturada->bind_param('i', $ventaId);
                $facturada->execute();
                if ($facturada->get_result()->num_rows > 0) {
                    $facturaRequerida = true;
                }
                $facturada->close();
            }

            $crear = self::$db->prepare(
                'INSERT INTO entrega_incidencias (venta_id, entrega_id, motivo, estado, notas, factura_requerida, usuario_id)
                 VALUES (?, ?, ?, "abierta", ?, ?, ?)'
            );
            $entregaSql = $entregaId ?: null;
            $facturaSql = $facturaRequerida ? 1 : 0;
            $notasSql   = $notas !== '' ? $notas : null;
            $crear->bind_param('iissii', $ventaId, $entregaSql, $motivo, $notasSql, $facturaSql, $usuarioId);
            $crear->execute();
            $incidenciaId = (int) self::$db->insert_id;
            $crear->close();

            $renglon = self::$db->prepare(
                'INSERT INTO entrega_incidencia_renglones (incidencia_id, producto_id, cantidad, requiere_cambio)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($filtrados as $productoId => $r) {
                // Un cambio o sustitución siempre deja el reemplazo pendiente de entregar.
                if ($motivo === 'cambio_sustitucion') {
                    $r['cambio'] = $r['cantidad'];
                }
                $requiereSql = $r['cambio'] > 0 ? 1 : 0;
                $renglon->bind_param('iiii', $incidenciaId, $productoId, $r['cantidad'], $requiereSql);
                $renglon->execute();
            }
            $renglon->close();

            // Sólo faltante/incompleto devuelven piezas al inventario (lo que no
            // salió completo); dañado, cambio y devolución son sólo notificación.
            if (in_array($motivo, self::MOTIVOS_INVENTARIO, true)) {
                $piezas = array_map(static fn ($r) => $r['cantidad'], $filtrados);
                if (!Inventario::devolucionPorIncidencia($piezas, $ventaId, $usuarioId, 'Incidencia de entrega · ' . $venta['folio'])) {
                    self::$db->rollBack();
                    self::$errores = array_merge(self::$errores, Inventario::getErrores());
                    self::$errores[] = 'No fue posible devolver el producto al inventario.';
                    return false;
                }
            }

            // En TODOS los motivos queda anotado como devolución (lo afectado),
            // así el pendiente de entrega se ajusta solo y el sistema sabe qué
            // piezas ya no se pueden volver a afectar.
            $guardar = self::$db->prepare(
                'INSERT INTO venta_devoluciones (venta_id, producto_id, fecha, cantidad_devuelta, cantidad_cambio, nota, usuario_id)
                 VALUES (?, ?, CURDATE(), ?, ?, ?, ?)'
            );
            $notaDevolucion = mb_substr('Incidencia de entrega (' . self::MOTIVOS[$motivo]['etiqueta'] . ')' . ($notas !== '' ? ' · ' . $notas : ''), 0, 200);
            $usuarioSql = $usuarioId > 0 ? $usuarioId : null;
            foreach ($filtrados as $productoId => $r) {
                $devuelta = $r['cantidad'];
                $cambioDb = $r['cambio'];
                $guardar->bind_param('iiiisi', $ventaId, $productoId, $devuelta, $cambioDb, $notaDevolucion, $usuarioSql);
                $guardar->execute();
            }
            $guardar->close();

            Entregas::avanzarPreparaciones($ventaId);
            self::actualizarEstado($ventaId);

            Auditoria::registrar('seguimiento', $incidenciaId, 'registrar', $usuarioId, $notas !== '' ? $notas : null, [
                'venta' => $venta['folio'], 'motivo' => $motivo, 'factura_requerida' => $facturaRequerida, 'renglones' => $filtrados,
            ]);
            Auditoria::registrar('venta', $ventaId, 'incidencia', $usuarioId, self::MOTIVOS[$motivo]['etiqueta'], ['incidencia' => $incidenciaId]);

            self::$db->commit();
            self::$ultimoId = $incidenciaId;
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[IncidenciasEntrega::registrar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar la incidencia.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** @return array<int, array<string, mixed>> Incidencias de una venta, más recientes primero. */
    public static function deVenta(int $ventaId): array
    {
        $consulta = self::$db->prepare(
            "SELECT e.*, u.nombre AS usuario_nombre, r.nombre AS resuelta_por_nombre,
                    COALESCE(en.fecha, '') AS fecha_entrega
               FROM entrega_incidencias e
               LEFT JOIN usuarios u ON u.id = e.usuario_id
               LEFT JOIN usuarios r ON r.id = e.resuelta_por
               LEFT JOIN entregas en ON en.id = e.entrega_id
              WHERE e.venta_id = ? ORDER BY e.id DESC"
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $incidencias = [];
        foreach ($consulta->get_result() as $fila) {
            $fila['renglones'] = [];
            $fila['documento'] = $fila['factura_documento_id'] ? Archivos::buscarID((int) $fila['factura_documento_id']) : null;
            $incidencias[(int) $fila['id']] = $fila;
        }
        $consulta->close();

        if ($incidencias) {
            $consulta = self::$db->prepare(
                'SELECT r.*, p.nombre AS producto_nombre, p.sku
                   FROM entrega_incidencia_renglones r
                   JOIN productos p ON p.id = r.producto_id
                   JOIN entrega_incidencias e ON e.id = r.incidencia_id
                  WHERE e.venta_id = ? ORDER BY r.id'
            );
            $consulta->bind_param('i', $ventaId);
            $consulta->execute();
            foreach ($consulta->get_result() as $fila) {
                if (isset($incidencias[(int) $fila['incidencia_id']])) {
                    $incidencias[(int) $fila['incidencia_id']]['renglones'][] = $fila;
                }
            }
            $consulta->close();
        }

        return array_values($incidencias);
    }

    /** @return array<string, mixed>|null Renglones incluidos. */
    public static function buscar(int $id): ?array
    {
        $consulta = self::$db->prepare(
            "SELECT e.*, v.folio AS venta_folio, u.nombre AS usuario_nombre
               FROM entrega_incidencias e
               JOIN ventas v ON v.id = e.venta_id
               LEFT JOIN usuarios u ON u.id = e.usuario_id
              WHERE e.id = ?"
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$fila) {
            return null;
        }

        $renglones = self::$db->prepare(
            'SELECT r.*, p.nombre AS producto_nombre, p.sku FROM entrega_incidencia_renglones r
               JOIN productos p ON p.id = r.producto_id WHERE r.incidencia_id = ? ORDER BY r.id'
        );
        $renglones->bind_param('i', $id);
        $renglones->execute();
        $fila['renglones'] = $renglones->get_result()->fetch_all(MYSQLI_ASSOC);
        $renglones->close();

        if ($fila['factura_documento_id']) {
            $fila['documento'] = Archivos::buscarID((int) $fila['factura_documento_id']);
        }

        return $fila;
    }

    /* --------------------------------------------------------------------
     * RESOLVER / CANCELAR
     * ------------------------------------------------------------------ */

    /**
     * Cierra una incidencia: «resuelta» (con o sin documento de factura) o
     * «cancelada» (reportada por error, no cambia nada del negocio).
     *
     * La sustitución de factura —cuando se pide— se hace ANTES: abrió y cerró
     * sus propias transacciones (Archivos::subir y la sincronía del ingreso);
     * después se marca resuelta en la suya propia. Si entre ambos pasos algo
     * fallara, la incidencia sigue abierta y puede reintentarse sin duplicar
     * documentos (el UUID del CFDI correcto ya queda protegido).
     *
     * @return bool
     */
    public static function cerrar(int $id, string $nuevoEstado, string $resolucion, bool $facturaCancelada, array $archivos, int $usuarioId, bool $esSubida = true): bool
    {
        self::$errores = [];
        $resolucion = mb_substr(trim($resolucion), 0, 400);
        if (!isset(self::ESTADOS[$nuevoEstado]) || $nuevoEstado === 'abierta') {
            self::$errores[] = 'Estado de cierre no válido.';
            return false;
        }

        $documentoId = null;
        if ($nuevoEstado === 'resuelta' && $facturaCancelada) {
            if (!self::sustituirFactura($id, $archivos, $resolucion, $usuarioId, $esSubida)) {
                return false;
            }
            $documentoId = (int) self::$ultimoId;
        }

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare(
                'SELECT e.*, v.folio AS venta_folio, v.empresa_id FROM entrega_incidencias e
                   JOIN ventas v ON v.id = e.venta_id WHERE e.id = ? FOR UPDATE'
            );
            $bloqueo->bind_param('i', $id);
            $bloqueo->execute();
            $incidencia = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if (!$incidencia) {
                self::$db->rollBack();
                self::$errores[] = 'La incidencia ya no existe.';
                return false;
            }
            if ($incidencia['estado'] !== 'abierta') {
                self::$db->rollBack();
                self::$errores[] = 'Esta incidencia ya está cerrada.';
                return false;
            }

            $cerrar = self::$db->prepare(
                'UPDATE entrega_incidencias SET estado = ?, resolucion = ?, factura_documento_id = COALESCE(?, factura_documento_id),
                        resuelta_en = NOW(), resuelta_por = ? WHERE id = ?'
            );
            $resolucionSql = $resolucion !== '' ? $resolucion : null;
            $usuarioSql = $usuarioId > 0 ? $usuarioId : null;
            $cerrar->bind_param('sssii', $nuevoEstado, $resolucionSql, $documentoId, $usuarioSql, $id);
            $cerrar->execute();
            $cerrar->close();

            self::actualizarEstado((int) $incidencia['venta_id']);

            Auditoria::registrar('seguimiento', $id, $nuevoEstado === 'resuelta' ? 'resolver' : 'cancelar', $usuarioId,
                $resolucion !== '' ? $resolucion : null,
                ['venta' => $incidencia['venta_folio'], 'motivo' => $incidencia['motivo'], 'factura_correcta' => $documentoId]);

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[IncidenciasEntrega::cerrar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cerrar la incidencia.';
            return false;
        }
    }

    /**
     * Sustituye la factura del ingreso de la venta del seguimiento:
     *   1. Valida que el ingreso exista y que los periodos (viejo y nuevo) estén abiertos.
     *   2. Sube el nuevo XML y PDF con Archivos::subir (validación de duplicados por UUID incluida).
     *   3. En una transacción: da de baja los documentos anteriores, sincroniza el ingreso
     *      con los datos del XML (folio, UUID, fecha, subtotal, descuento, IVA y total) y
     *      guarda el documento correcto en la incidencia.
     *
     * Deja en self::$errores el motivo de cualquier rechazo. self::$ultimoId queda con
     * el id del documento XML nuevo.
     *
     * @param bool $esSubida false solo en pruebas por CLI (Archivos::subir exige
     *        subida HTTP real cuando es true; es el valor por defecto).
     *
     * @return bool
     */
    public static function sustituirFactura(int $incidenciaId, array $archivos, string $motivoAdjunto, int $usuarioId, bool $esSubida = true): bool
    {
        self::$errores = [];
        self::$ultimoId = 0;
        $motivoAdjunto = mb_substr(trim($motivoAdjunto), 0, 200);

        $pdf = $archivos['pdf'] ?? null;
        $xml = $archivos['xml'] ?? null;
        $fechaFactura = (string) ($archivos['fecha'] ?? '');

        if ($fechaFactura !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFactura)) {
            $fechaFactura = date('Y-m-d', strtotime($fechaFactura)) ?: '';
        }

        $consulta = self::$db->prepare(
            'SELECT e.*, v.id AS venta_id, v.folio AS venta_folio, i.id AS ingreso_id
               FROM entrega_incidencias e
               JOIN ventas v ON v.id = e.venta_id
               LEFT JOIN ingresos i ON i.venta_id = v.id
              WHERE e.id = ?'
        );
        $consulta->bind_param('i', $incidenciaId);
        $consulta->execute();
        $datos = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$datos) {
            self::$errores[] = 'La incidencia ya no existe.';
            return false;
        }
        if ($datos['estado'] !== 'abierta') {
            self::$errores[] = 'Esta incidencia ya está cerrada.';
            return false;
        }
        if (empty($datos['ingreso_id'])) {
            self::$errores[] = 'La venta aún no tiene un ingreso en contabilidad; primero registra la facturación de la venta.';
            return false;
        }
        if ($pdf === null || $xml === null) {
            self::$errores[] = 'Adjunta la factura correcta en PDF y en XML.';
            return false;
        }
        $ingresoId = (int) $datos['ingreso_id'];
        $ingreso = Contabilidad::buscarIngreso($ingresoId);
        if (!$ingreso || $ingreso['estado'] !== 'vigente') {
            self::$errores[] = 'El ingreso de la venta ya no está vigente.';
            return false;
        }
        foreach (array_unique([$ingreso['fecha'], $fechaFactura ?: $ingreso['fecha']]) as $fecha) {
            if (Contabilidad::periodoCerrado((int) $ingreso['contribuyente_id'], $fecha)) {
                self::$errores[] = Contabilidad::mensajePeriodoCerrado($fecha);
                return false;
            }
        }

        // Documentos actuales de la factura (PDF y XML): se identifican ANTES de
        // subir los nuevos, porque después de subirlos habría dos de cada tipo y
        // el mapa por tipo se quedaría con los recién subidos.
        $anteriores = [];
        $consulta = self::$db->prepare(
            "SELECT id FROM documentos WHERE entidad = 'ingreso' AND entidad_id = ? AND activo = 1 AND tipo IN ('factura_pdf', 'xml')"
        );
        $consulta->bind_param('i', $ingresoId);
        $consulta->execute();
        foreach ($consulta->get_result() as $d) {
            $anteriores[] = (int) $d['id'];
        }
        $consulta->close();

        // 2. Subida (cada una con su propia transacción, tras haber validado todo lo bloqueable).
        $razon = 'Factura correcta · incidencia de entrega · venta ' . $datos['venta_folio'] . ($motivoAdjunto !== '' ? ' · ' . $motivoAdjunto : '');
        if (!\App\Archivos::subir('ingreso', $ingresoId, $pdf, 'factura_pdf', $usuarioId, null, $razon, $esSubida)) {
            self::$errores[] = 'PDF: ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
            return false;
        }
        $pdfId = (int) \App\Archivos::$ultimoId;
        if (!\App\Archivos::subir('ingreso', $ingresoId, $xml, 'xml', $usuarioId, null, $razon, $esSubida)) {
            self::$errores[] = 'XML: ' . (\App\Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
            return false;
        }
        $xmlId   = (int) \App\Archivos::$ultimoId;
        $xmlDoc  = \App\Archivos::buscarID($xmlId);
        $cfdi    = (array) ($xmlDoc['cfdi'] ?? []);
        $uuid    = strtoupper(trim((string) ($cfdi['uuid'] ?? '')));
        $folioCfdi = trim((string) ($cfdi['folio_comercial'] ?? ''));
        $fechaCfdi = (string) ($cfdi['fecha'] ?? '');
        $fechaFactura = $fechaFactura !== '' ? $fechaFactura : substr($fechaCfdi, 0, 10);

        if ($fechaFactura !== '' && strtotime($fechaFactura) === false) {
            self::$errores[] = 'La fecha de facturación no es válida.';
            return false;
        }

        /* 3. Transacción: baja de los documentos anteriores + sincronía del ingreso. */
        try {
            self::$db->begin_transaction();

            $dar = self::$db->prepare("UPDATE documentos SET activo = 0, motivo_baja = ?, baja_en = NOW(), baja_por = ? WHERE id = ? AND activo = 1");
            $motivoBaja = 'Cancelada: se sustituyó por la factura correcta (incidencia de entrega #' . $incidenciaId . ')';
            $usuarioSql = $usuarioId > 0 ? $usuarioId : null;
            foreach ($anteriores as $dDocId) {
                $dar->bind_param('sii', $motivoBaja, $usuarioSql, $dDocId);
                $dar->execute();
            }
            $dar->close();

            // Datos de facturación: siempre folio/UUID/fecha del XML; montos también (la corrección
            // del seguimiento corrige la factura, no la venta: el importe original de la venta NO
            // se toca). La auditoría guardará qué cambió.
            $montos = [
                'subtotal' => $cfdi['subtotal'] !== '' ? number_format((float) $cfdi['subtotal'], 2, '.', '') : null,
                'descuento'=> $cfdi['descuento'] !== '' ? number_format((float) $cfdi['descuento'], 2, '.', '') : null,
                'iva'      => $cfdi['iva'] !== '' ? number_format((float) $cfdi['iva'], 2, '.', '') : null,
                'total'    => $cfdi['total'] !== '' ? number_format((float) $cfdi['total'], 2, '.', '') : null,
            ];
            $cambios = [];
            foreach (['folio' => $folioCfdi, 'uuid_fiscal' => $uuid, 'fecha_facturacion' => $fechaFactura] + $montos as $campo => $valor) {
                if ($valor === null || trim((string) $valor) === '') {
                    continue;
                }
                if (in_array($campo, ['subtotal', 'descuento', 'iva', 'total'], true)) {
                    if (Contabilidad::centavos($ingreso[$campo]) === Contabilidad::centavos((string) $valor)) {
                        continue;
                    }
                } else {
                    $actual = (string) ($ingreso[$campo] ?? '');
                    if ($campo === 'folio') { $actual = (string) ($ingreso['factura_folio'] ?? ''); }
                    if ($campo === 'uuid_fiscal') { $actual = strtoupper((string) $ingreso['uuid_fiscal']); }
                    if (trim($actual) === trim((string) $valor)) {
                        continue;
                    }
                }
                $cambios[$campo] = [$ingreso[$campo] ?? null, $valor];
            }

            $actualizar = self::$db->prepare(
                'UPDATE ingresos SET factura_folio = COALESCE(?, factura_folio), uuid_fiscal = COALESCE(?, uuid_fiscal),
                        fecha_facturacion = COALESCE(?, fecha_facturacion), subtotal = COALESCE(?, subtotal),
                        descuento = COALESCE(?, descuento), iva = COALESCE(?, iva), total = COALESCE(?, total),
                        estado_facturacion = "facturada", factura_documento_id = ?,
                        facturacion_usuario_id = ?, facturacion_en = NOW()
                  WHERE id = ?'
            );
            $folioDb = (!empty($cambios['folio']) || ($folioCfdi !== '' && (string) $ingreso['factura_folio'] !== $folioCfdi)) ? $folioCfdi : ($ingreso['factura_folio'] ?? '');
            $uuidDb = ($uuid !== '' && strtoupper((string) $ingreso['uuid_fiscal']) !== $uuid) ? $uuid : (string) $ingreso['uuid_fiscal'];
            $fechaDb = $fechaFactura !== '' ? $fechaFactura : (string) $ingreso['fecha_facturacion'];
            $subDb = $montos['subtotal'] ?? null;
            $desDb = $montos['descuento'] ?? null;
            $ivaDb = $montos['iva'] ?? null;
            $totDb = $montos['total'] ?? null;
            $docId = $xmlId;
            $actualizar->bind_param('sssssssiii', $folioDb, $uuidDb, $fechaDb, $subDb, $desDb, $ivaDb, $totDb, $docId, $usuarioSql, $ingresoId);
            $actualizar->execute();
            $actualizar->close();

            Auditoria::registrar('ingreso', $ingresoId, 'sustitucion', $usuarioId, $motivoAdjunto !== '' ? $motivoAdjunto : null, [
                'venta' => $datos['venta_folio'], 'incidencia' => $incidenciaId, 'uuid_anterior' => $ingreso['uuid_fiscal'],
                'cambios' => $cambios, 'xml_nuevo' => $xmlId, 'factura_pdf_nueva' => $pdfId,
            ]);

            self::$db->commit();
            self::$ultimoId = $xmlId;
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[IncidenciasEntrega::sustituirFactura] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible sustituir la factura.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * ESTADO GENERAL DE LA VENTA
     * ------------------------------------------------------------------ */

    /** Recalcula y guarda ventas.entrega_estado de la venta indicada.
     *
     * Regla (en orden):
     *   1. algo por entregar y sin cerrar     → pendiente
     *   2. todo entregado con incidencias abiertas → entregado_con_incidencias
     *   3. todo entregado: si alguna incidencia no quedó cancelada →
     *      entregado_incidencias_resueltas, si no → entregado
     */
    public static function actualizarEstado(int $ventaId): void
    {
        $abiertas = 0;
        $conIncidencias = false;
        $consulta = self::$db->prepare(
            "SELECT estado, COUNT(*) AS n FROM entrega_incidencias WHERE venta_id = ? GROUP BY estado"
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        foreach ($consulta->get_result() as $f) {
            if ($f['estado'] !== 'cancelada') {
                $conIncidencias = true;
            }
            if ($f['estado'] === 'abierta') {
                $abiertas += (int) $f['n'];
            }
        }
        $consulta->close();

        $pendiente = 0;
        $cerrada = false;
        $ver = self::$db->prepare('SELECT entrega_cerrada_en FROM ventas WHERE id = ?');
        $ver->bind_param('i', $ventaId);
        $ver->execute();
        $venta = $ver->get_result()->fetch_assoc();
        $ver->close();
        $cerrada = $venta && !empty($venta['entrega_cerrada_en']);

        if (!$cerrada) {
            $pendientes = Entregas::pendientesDeVentas([$ventaId])[$ventaId] ?? [];
            $pendiente = array_sum(array_column($pendientes, 'pendiente'));
        }

        $entregadoTodo = $cerrada || $pendiente <= 0;
        $estado = 'pendiente';
        if ($entregadoTodo) {
            $estado = $abiertas > 0 ? 'entregado_con_incidencias' : ($conIncidencias ? 'entregado_incidencias_resueltas' : 'entregado');
        }

        $guardar = self::$db->prepare('UPDATE ventas SET entrega_estado = ? WHERE id = ?');
        $guardar->bind_param('si', $estado, $ventaId);
        $guardar->execute();
        $guardar->close();
    }

    /* --------------------------------------------------------------------
     * DATOS DE LA VENTA PARA LA PANTALLA
     * ------------------------------------------------------------------ */

    /** Actualiza la fecha de entrega y las observaciones del seguimiento. */
    public static function actualizarDatos(int $ventaId, string $fecha, string $observaciones, int $usuarioId): bool
    {
        self::$errores = [];
        $observaciones = trim($observaciones);

        if ($fecha !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha))) {
            self::$errores[] = 'La fecha de entrega no es válida.';
            return false;
        }

        $ver = self::$db->prepare('SELECT entrega_fecha, entrega_observaciones FROM ventas WHERE id = ?');
        $ver->bind_param('i', $ventaId);
        $ver->execute();
        $antes = $ver->get_result()->fetch_assoc();
        $ver->close();

        if (!$antes) {
            self::$errores[] = 'La venta ya no existe.';
            return false;
        }

        $fechaSql = $fecha !== '' ? $fecha : null;
        $obsSql = $observaciones !== '' ? $observaciones : null;
        $guardar = self::$db->prepare('UPDATE ventas SET entrega_fecha = ?, entrega_observaciones = ? WHERE id = ?');
        $guardar->bind_param('ssi', $fechaSql, $obsSql, $ventaId);
        $guardar->execute();
        $guardar->close();

        if ($antes['entrega_fecha'] !== $fechaSql || (string) $antes['entrega_observaciones'] !== (string) $obsSql) {
            Auditoria::registrar('venta', $ventaId, 'seguimiento', $usuarioId, null, [
                'antes' => ['fecha' => $antes['entrega_fecha'], 'observaciones' => $antes['entrega_observaciones']],
                'despues' => ['fecha' => $fechaSql, 'observaciones' => $obsSql],
            ]);
        }

        return true;
    }

    /**
     * Ventas con seguimiento (todas las registradas), con su cliente, el estado
     * general, lo entregado y sus incidencias abiertas.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listar(array $filtros = [], int $limite = 15, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condicionesListado($filtros);

        $consulta = self::$db->prepare(
            "SELECT v.id, v.folio, v.fecha, v.total, v.entrega_fecha, v.entrega_estado, v.entrega_cerrada_en,
                    e.nombre AS empresa_nombre, e.logo AS empresa_logo,
                    cl.id AS cliente_id, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    cl.ubicacion AS cliente_ubicacion,
                    abiertas.abiertas AS incidencias_abiertas,
                    (SELECT COALESCE(SUM(d.cantidad), 0) FROM venta_detalles d WHERE d.venta_id = v.id) AS vendidas,
                    (SELECT COALESCE(SUM(er.cantidad), 0) FROM entrega_renglones er
                       JOIN entregas en ON en.id = er.entrega_id WHERE en.venta_id = v.id) AS entregadas
               FROM ventas v
               JOIN empresas e  ON e.id  = v.empresa_id
               JOIN clientes cl ON cl.id = v.cliente_id
               LEFT JOIN (SELECT venta_id, SUM(1) AS abiertas FROM entrega_incidencias WHERE estado = 'abierta' GROUP BY venta_id) abiertas
                      ON abiertas.venta_id = v.id
              WHERE $donde
              ORDER BY v.fecha DESC, v.id DESC LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    public static function contarListado(array $filtros = []): int
    {
        [$donde, $tipos, $valores] = self::condicionesListado($filtros);

        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS n
               FROM ventas v
               JOIN clientes cl ON cl.id = v.cliente_id
               LEFT JOIN (SELECT venta_id, SUM(1) AS abiertas FROM entrega_incidencias WHERE estado = 'abierta' GROUP BY venta_id) abiertas
                      ON abiertas.venta_id = v.id
              WHERE $donde"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $n;
    }

    /** Totales por estado (para los filtros del listado). @return array<string, int> */
    public static function conteoEstados(): array
    {
        $conteo = array_fill_keys(['pendiente', 'entregado', 'entregado_con_incidencias', 'entregado_incidencias_resueltas'], 0);
        $res = self::$db->query(
            "SELECT entrega_estado, COUNT(*) AS n FROM ventas WHERE estado = 'registrada' GROUP BY entrega_estado"
        );
        foreach ($res as $f) {
            if (isset($conteo[$f['entrega_estado']])) {
                $conteo[$f['entrega_estado']] = (int) $f['n'];
            }
        }

        return $conteo;
    }

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
    private static function condicionesListado(array $filtros): array
    {
        $donde   = ["v.estado = 'registrada'"];
        $tipos   = '';
        $valores = [];

        $estado = (string) ($filtros['estado'] ?? '');
        if (in_array($estado, self::FILTROS_ENTREGA, true)) {
            $donde[]   = 'v.entrega_estado = ?';
            $tipos    .= 's';
            $valores[] = $estado;
        }

        $termino = trim((string) ($filtros['q'] ?? ''));
        if ($termino !== '') {
            $donde[] = '(v.folio LIKE ? OR cl.nombre LIKE ? OR IFNULL(cl.nombre_empresa, "") LIKE ? OR IFNULL(cl.alias, "") LIKE ?)';
            $patron  = '%' . $termino . '%';
            $tipos  .= 'ssss';
            array_push($valores, $patron, $patron, $patron, $patron);
        }

        if ((int) ($filtros['empresa'] ?? 0) > 0) {
            $donde[]   = 'v.empresa_id = ?';
            $tipos    .= 'i';
            $valores[] = (int) $filtros['empresa'];
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Línea de tiempo del seguimiento de la venta: entregas, devoluciones,
     * cambios, incidencias y movimientos de facturación, del más reciente
     * al más antiguo.
     *
     * @return array<int, array{fecha: string, titulo: string, descripcion: string, usuario: ?string, tipo: string}>
     */
    public static function historial(int $ventaId): array
    {
        $lineas = [];

        $incidencias = self::deVenta($ventaId);
        foreach ($incidencias as $incidencia) {
            $motivo = self::MOTIVOS[$incidencia['motivo']]['etiqueta'] ?? $incidencia['motivo'];
            $piezas = [];
            foreach ($incidencia['renglones'] as $r) {
                $piezas[] = (int) $r['cantidad'] . ' × ' . $r['producto_nombre'] . ($r['requiere_cambio'] ? ' (a cambiar)' : '');
            }
            $lineas[] = [
                'fecha' => $incidencia['creado_en'],
                'tipo' => self::ESTADOS[$incidencia['estado']]['etiqueta'] . ' · ' . $motivo,
                'titulo' => $motivo,
                'descripcion' => trim(implode(', ', $piezas) . ' '
                    . ($incidencia['notas'] ?? '') . ' '
                    . ($incidencia['resolucion'] !== null && $incidencia['resolucion'] !== '' ? 'Resolución: ' . $incidencia['resolucion'] : '')),
                'usuario' => $incidencia['usuario_nombre'],
                'clase' => self::ESTADOS[$incidencia['estado']]['clase'],
            ];
        }

        foreach (Entregas::entregasDeVenta($ventaId) as $entrega) {
            $piezaListado = [];
            foreach ($entrega['renglones'] as $r) {
                $piezaListado[] = (int) $r['cantidad'] . ' × ' . $r['producto_nombre'];
            }
            $lineas[] = [
                'fecha' => $entrega['creado_en'],
                'tipo' => 'Entrega registrada',
                'titulo' => 'Entrega del ' . date('d/m/Y', strtotime($entrega['fecha'])),
                'descripcion' => trim(implode(', ', $piezaListado) . ' ' . ($entrega['notas'] ?? '')),
                'usuario' => $entrega['usuario_nombre'],
                'clase' => 'badge--aprobado',
            ];
        }

        foreach (Inventario::historialDevoluciones($ventaId) as $dev) {
            $lineas[] = [
                'fecha' => $dev['fecha'],
                'tipo' => 'Devolución al inventario',
                'titulo' => 'Devolución de ' . (int) $dev['cantidad_devuelta'] . ' × ' . $dev['producto_nombre']
                    . ((int) $dev['cantidad_cambio'] > 0 ? ' (cambio por entregar: ' . (int) $dev['cantidad_cambio'] . ')' : ''),
                'descripcion' => (string) ($dev['nota'] ?? ''),
                'usuario' => $dev['usuario_nombre'],
                'clase' => 'badge--info',
            ];
        }

        // Movimientos de contabilidad/auditoría ligados a la venta y a su ingreso (facturacion, sustitucion…).
        $consulta = self::$db->prepare(
            "SELECT a.creado_en, a.entidad, a.accion, a.motivo, a.detalle, u.nombre AS usuario_nombre
               FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE (a.entidad = 'venta' AND a.entidad_id = ?)
                 OR (a.entidad = 'ingreso' AND a.entidad_id = COALESCE((SELECT id FROM ingresos WHERE venta_id = ?), 0))
              ORDER BY a.id DESC LIMIT 200"
        );
        $consulta->bind_param('ii', $ventaId, $ventaId);
        $consulta->execute();
        $auditoria = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();
        foreach ($auditoria as $a) {
            if (in_array($a['accion'], ['crear', 'actualizar', 'eliminar', 'facturacion', 'sustitucion', 'incidencia', 'seguimiento', 'entrega', 'devolucion', 'cambio_entregado'], true)) {
                $etiquetas = [
                    'entrega' => 'Entrega registrada', 'devolucion' => 'Devolución', 'incidencia' => 'Incidencia de entrega',
                    'facturacion' => 'Facturación', 'sustitucion' => 'Sustitución de factura', 'seguimiento' => 'Seguimiento de entrega',
                    'cambio_entregado' => 'Cambio entregado',
                    'crear' => $a['entidad'] === 'ingreso' ? 'Ingreso creado en contabilidad' : 'Registro creado',
                    'actualizar' => 'Cambio en la venta', 'eliminar' => 'Venta eliminada',
                ];
                $lineas[] = [
                    'fecha' => $a['creado_en'],
                    'tipo' => $etiquetas[$a['accion']] ?? $a['accion'],
                    'titulo' => $etiquetas[$a['accion']] ?? $a['accion'],
                    'descripcion' => (string) ($a['motivo'] ?? ''),
                    'usuario' => $a['usuario_nombre'],
                    'clase' => in_array($a['accion'], ['sustitucion', 'facturacion'], true) ? 'badge--info' : 'badge--neutro',
                ];
            }
        }

        usort($lineas, static fn ($a, $b) => strtotime($b['fecha']) <=> strtotime($a['fecha']));

        return $lineas;
    }
}
