<?php

namespace App;

/**
 * OrdenesCompra — solicitudes de material a proveedores y su recepción.
 *
 * Reglas:
 *   • Crear o editar una orden NO toca el inventario.
 *   • Cada recepción (parcial o total) suma al inventario SOLO lo recibido,
 *     dentro de una transacción con la orden bloqueada y un token de un solo
 *     uso: reenviar el formulario o volver a consultar/exportar no duplica.
 *   • El costo recibido queda en recepcion_partidas (histórico); cambiar
 *     productos.costo_actual después nunca lo altera.
 *   • Cerrar una orden con faltantes es válido; una orden cerrada solo se
 *     corrige reabriéndola con motivo (queda en la bitácora de auditoría).
 *
 * Los importes se calculan en centavos enteros para evitar errores de coma flotante.
 */
class OrdenesCompra
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Id de la última orden creada (para redirigir al detalle). */
    public static $ultimoId = 0;

    public const ESTADOS = [
        'solicitada' => ['etiqueta' => 'Solicitada', 'clase' => 'badge--pendiente'],
        'parcial'    => ['etiqueta' => 'Recibida parcial', 'clase' => 'badge--pendiente'],
        'completa'   => ['etiqueta' => 'Recibida completa', 'clase' => 'badge--aprobado'],
        'cerrada'    => ['etiqueta' => 'Cerrada', 'clase' => 'badge--neutro'],
        'cancelada'  => ['etiqueta' => 'Cancelada', 'clase' => 'badge--error'],
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

    /** Importe en pesos de cantidad × costo, calculado en centavos. */
    public static function importe(int $cantidad, $costo): float
    {
        return ((int) round(((float) $costo) * 100) * $cantidad) / 100;
    }

    /** Convierte los arreglos paralelos del formulario en partidas. */
    public static function lineasDePost(array $post): array
    {
        $lineas = [];
        foreach ((array) ($post['producto_id'] ?? []) as $i => $productoId) {
            if ((int) $productoId === 0 && (int) ($post['cantidad'][$i] ?? 0) <= 1 && (float) ($post['costo'][$i] ?? 0) === 0.0) {
                continue; // renglón vacío
            }
            $lineas[] = [
                'producto_id' => (int) $productoId,
                'cantidad'    => (int) ($post['cantidad'][$i] ?? 0),
                'costo'       => str_replace(',', '.', trim((string) ($post['costo'][$i] ?? '0'))),
                'descripcion' => trim((string) ($post['descripcion'][$i] ?? '')),
            ];
        }
        return $lineas;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Encabezado de una orden con el nombre del proveedor, o null. */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT o.*, p.nombre AS proveedor_nombre, p.rfc AS proveedor_rfc,
                    p.telefono AS proveedor_telefono, p.email AS proveedor_email
               FROM ordenes_compra o
               JOIN proveedores p ON p.id = o.proveedor_id
              WHERE o.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /**
     * Partidas con lo recibido hasta hoy.
     *
     * Por partida: solicitada, recibida, pendiente (negativa = excedente),
     * costo esperado, costo final (promedio ponderado de lo recibido) y la
     * diferencia económica = importe recibido − (recibida × costo esperado).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function partidas(int $ordenId): array
    {
        $consulta = self::$db->prepare(
            'SELECT c.*, pr.nombre AS producto_nombre, pr.sku AS producto_sku, pr.imagen AS producto_imagen,
                    pr.unidad AS producto_unidad,
                    COALESCE(SUM(rp.cantidad_recibida), 0) AS recibida,
                    COALESCE(SUM(ROUND(rp.cantidad_recibida * rp.costo_unitario * 100)), 0) AS recibido_centavos
               FROM orden_compra_partidas c
               JOIN productos pr ON pr.id = c.producto_id
               LEFT JOIN recepcion_partidas rp ON rp.partida_id = c.id
              WHERE c.orden_id = ?
              GROUP BY c.id
              ORDER BY c.id'
        );
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $partidas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $solicitada = (int) $fila['cantidad_solicitada'];
            $recibida   = (int) $fila['recibida'];
            $recibido   = (int) $fila['recibido_centavos'];
            $esperadoRecibido = (int) round((float) $fila['costo_esperado'] * 100) * $recibida;

            $fila['solicitada']       = $solicitada;
            $fila['recibida']         = $recibida;
            $fila['pendiente']        = $solicitada - $recibida;
            $fila['importe_recibido'] = $recibido / 100;
            $fila['costo_final']      = $recibida > 0 ? round($recibido / 100 / $recibida, 2) : null;
            $fila['diferencia']       = ($recibido - $esperadoRecibido) / 100;
            $partidas[] = $fila;
        }
        $consulta->close();

        return $partidas;
    }

    /**
     * Historial de recepciones con sus renglones.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recepciones(int $ordenId): array
    {
        $consulta = self::$db->prepare(
            'SELECT r.*, u.nombre AS usuario_nombre
               FROM recepciones r
               LEFT JOIN usuarios u ON u.id = r.usuario_id
              WHERE r.orden_id = ? ORDER BY r.id'
        );
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $resultado = $consulta->get_result();

        $recepciones = [];
        while ($fila = $resultado->fetch_assoc()) {
            $fila['renglones'] = [];
            $recepciones[(int) $fila['id']] = $fila;
        }
        $consulta->close();

        if ($recepciones) {
            $consulta = self::$db->prepare(
                'SELECT rp.*, pr.nombre AS producto_nombre, pr.sku AS producto_sku
                   FROM recepcion_partidas rp
                   JOIN productos pr ON pr.id = rp.producto_id
                   JOIN recepciones r ON r.id = rp.recepcion_id
                  WHERE r.orden_id = ? ORDER BY rp.id'
            );
            $consulta->bind_param('i', $ordenId);
            $consulta->execute();
            $resultado = $consulta->get_result();
            while ($fila = $resultado->fetch_assoc()) {
                $recepciones[(int) $fila['recepcion_id']]['renglones'][] = $fila;
            }
            $consulta->close();
        }

        return array_values($recepciones);
    }

    /**
     * Totales para el reporte de revisión antes del pago.
     *
     * @return array{esperado: float, recibido: float, diferencia: float,
     *               piezas_solicitadas: int, piezas_recibidas: int, piezas_pendientes: int}
     */
    public static function totales(array $partidas): array
    {
        $esperado = 0;
        $recibido = 0;
        $solicitadas = 0;
        $recibidas = 0;
        $pendientes = 0;

        foreach ($partidas as $partida) {
            $esperado    += (int) round((float) $partida['importe_esperado'] * 100);
            $recibido    += (int) round((float) $partida['importe_recibido'] * 100);
            $solicitadas += $partida['solicitada'];
            $recibidas   += $partida['recibida'];
            $pendientes  += max(0, $partida['pendiente']);
        }

        return [
            'esperado'           => $esperado / 100,
            'recibido'           => $recibido / 100,
            'diferencia'         => array_sum(array_column($partidas, 'diferencia')),
            'piezas_solicitadas' => $solicitadas,
            'piezas_recibidas'   => $recibidas,
            'piezas_pendientes'  => $pendientes,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function listar(array $filtros, int $limite, int $offset): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            "SELECT o.*, p.nombre AS proveedor_nombre
               FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id
              WHERE $donde ORDER BY o.fecha_solicitud DESC, o.id DESC LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    public static function contar(array $filtros): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS total FROM ordenes_compra o JOIN proveedores p ON p.id = o.proveedor_id WHERE $donde"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
    private static function condiciones(array $filtros): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];

        $termino = trim((string) ($filtros['q'] ?? ''));
        if ($termino !== '') {
            $donde[] = '(o.folio LIKE ? OR p.nombre LIKE ?)';
            $tipos .= 'ss';
            $valores[] = $valores[] = '%' . $termino . '%';
        }
        if (isset(self::ESTADOS[$filtros['estado'] ?? ''])) {
            $donde[] = 'o.estado = ?';
            $tipos .= 's';
            $valores[] = $filtros['estado'];
        }
        if ((int) ($filtros['proveedor'] ?? 0) > 0) {
            $donde[] = 'o.proveedor_id = ?';
            $tipos .= 'i';
            $valores[] = (int) $filtros['proveedor'];
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /* --------------------------------------------------------------------
     * ESCRITURA — ORDEN
     * ------------------------------------------------------------------ */

    /**
     * Valida los datos de la orden.
     *
     * @param array<int, array{producto_id: int, cantidad: int, costo: mixed, descripcion?: string}> $lineas
     * @return string[]
     */
    public static function validar(array $datos, array $lineas): array
    {
        $errores = [];

        $proveedor = (int) ($datos['proveedor_id'] ?? 0);
        $consulta = self::$db->prepare('SELECT activo FROM proveedores WHERE id = ?');
        $consulta->bind_param('i', $proveedor);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$fila) {
            $errores[] = 'Elige un proveedor válido.';
        } elseif ((int) $fila['activo'] !== 1) {
            $errores[] = 'El proveedor está desactivado.';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($datos['fecha_solicitud'] ?? ''))
            || !strtotime((string) $datos['fecha_solicitud'])) {
            $errores[] = 'La fecha de solicitud no es válida.';
        }

        $folio = trim((string) ($datos['folio'] ?? ''));
        if ($folio !== '') {
            if (mb_strlen($folio) > 20 || !preg_match('/^[\p{L}\p{N}._\/-]+$/u', $folio)) {
                $errores[] = 'El folio puede tener hasta 20 letras, números, guiones o diagonales, sin espacios.';
            } else {
                $propia = (int) ($datos['id'] ?? 0);
                $consulta = self::$db->prepare('SELECT COUNT(*) FROM ordenes_compra WHERE folio = ? AND id <> ?');
                $consulta->bind_param('si', $folio, $propia);
                $consulta->execute();
                $repetido = (int) $consulta->get_result()->fetch_row()[0];
                $consulta->close();
                if ($repetido > 0) {
                    $errores[] = "Ya existe una orden con el folio $folio. Elige otro o déjalo vacío para que se asigne solo.";
                }
            }
        }

        if (empty($lineas)) {
            $errores[] = 'Agrega al menos un producto a la orden.';
        }

        foreach ($lineas as $indice => $linea) {
            $n = $indice + 1;
            if ((int) $linea['producto_id'] <= 0) {
                $errores[] = "Partida $n: elige un producto.";
            }
            if ((int) $linea['cantidad'] <= 0) {
                $errores[] = "Partida $n: la cantidad debe ser mayor a cero.";
            }
            if (!is_numeric($linea['costo']) || (float) $linea['costo'] < 0) {
                $errores[] = "Partida $n: el costo unitario debe ser un número mayor o igual a cero.";
            }
        }

        return $errores;
    }

    public static function crear(array $datos, array $lineas, int $usuarioId): bool
    {
        self::$errores = self::validar($datos, $lineas);
        if (self::$errores) {
            return false;
        }

        try {
            self::$db->begin_transaction();

            $folio = trim((string) ($datos['folio'] ?? '')) ?: self::siguienteFolio();
            $total = 0;
            foreach ($lineas as $linea) {
                $total += (int) round(self::importe((int) $linea['cantidad'], $linea['costo']) * 100);
            }
            $totalPesos = number_format($total / 100, 2, '.', '');
            $observaciones = trim((string) ($datos['observaciones'] ?? '')) ?: null;

            $consulta = self::$db->prepare(
                'INSERT INTO ordenes_compra (folio, proveedor_id, fecha_solicitud, total_esperado, observaciones, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $proveedorId = (int) $datos['proveedor_id'];
            $consulta->bind_param('sisssi', $folio, $proveedorId, $datos['fecha_solicitud'], $totalPesos, $observaciones, $usuarioId);
            $consulta->execute();
            $ordenId = (int) self::$db->insert_id;
            $consulta->close();

            self::guardarPartidas($ordenId, $lineas);
            Auditoria::registrar('orden_compra', $ordenId, 'crear', $usuarioId, null, ['folio' => $folio, 'total' => $totalPesos]);

            self::$db->commit();
            self::$ultimoId = $ordenId;
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::crear] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible guardar la orden de compra.';
            return false;
        }
    }

    /** Edita una orden mientras no tenga recepciones ni esté cerrada/cancelada. */
    public static function actualizar(int $id, array $datos, array $lineas, int $usuarioId): bool
    {
        self::$errores = self::validar($datos, $lineas);
        if (self::$errores) {
            return false;
        }

        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($id);

            if (!$orden || $orden['estado'] !== 'solicitada' || self::contarRecepciones($id) > 0) {
                self::$db->rollback();
                self::$errores[] = 'Solo se puede editar una orden solicitada que aún no tenga recepciones.';
                return false;
            }

            $total = 0;
            foreach ($lineas as $linea) {
                $total += (int) round(self::importe((int) $linea['cantidad'], $linea['costo']) * 100);
            }
            $totalPesos = number_format($total / 100, 2, '.', '');
            $observaciones = trim((string) ($datos['observaciones'] ?? '')) ?: null;
            $proveedorId = (int) $datos['proveedor_id'];

            $folio = trim((string) ($datos['folio'] ?? '')) ?: $orden['folio']; // vacío = conserva el actual

            $consulta = self::$db->prepare(
                'UPDATE ordenes_compra SET folio = ?, proveedor_id = ?, fecha_solicitud = ?, total_esperado = ?, observaciones = ?
                  WHERE id = ?'
            );
            $consulta->bind_param('sisssi', $folio, $proveedorId, $datos['fecha_solicitud'], $totalPesos, $observaciones, $id);
            $consulta->execute();
            $consulta->close();

            $borrar = self::$db->prepare('DELETE FROM orden_compra_partidas WHERE orden_id = ?');
            $borrar->bind_param('i', $id);
            $borrar->execute();
            $borrar->close();

            self::guardarPartidas($id, $lineas);
            Auditoria::registrar('orden_compra', $id, 'editar', $usuarioId, null, ['total' => $totalPesos, 'folio' => $folio]);

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::actualizar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible actualizar la orden de compra.';
            return false;
        }
    }

    /** Cancela una orden sin recepciones. */
    public static function cancelar(int $id, int $usuarioId): bool
    {
        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($id);

            if (!$orden || $orden['estado'] !== 'solicitada' || self::contarRecepciones($id) > 0) {
                self::$db->rollback();
                self::$errores[] = 'Solo se puede cancelar una orden solicitada sin recepciones.';
                return false;
            }

            $consulta = self::$db->prepare("UPDATE ordenes_compra SET estado = 'cancelada' WHERE id = ?");
            $consulta->bind_param('i', $id);
            $consulta->execute();
            $consulta->close();

            Auditoria::registrar('orden_compra', $id, 'cancelar', $usuarioId);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::cancelar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cancelar la orden.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * ESCRITURA — RECEPCIÓN, CIERRE Y REAPERTURA
     * ------------------------------------------------------------------ */

    /**
     * Registra una recepción y suma al inventario lo realmente recibido.
     *
     * @param array<int, array{cantidad: mixed, costo: mixed}> $renglones partida_id => datos.
     */
    public static function recibir(
        int $ordenId,
        string $fecha,
        array $renglones,
        string $token,
        bool $actualizaCosto,
        string $notas,
        int $usuarioId
    ): bool {
        self::$errores = [];

        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            self::$errores[] = 'El formulario de recepción no es válido. Vuelve a abrirlo.';
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            self::$errores[] = 'La fecha de recepción no es válida.';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($ordenId);

            if (!$orden || !in_array($orden['estado'], ['solicitada', 'parcial'], true)) {
                self::$db->rollback();
                self::$errores[] = 'La orden no admite más recepciones (debe estar solicitada o con recepción parcial).';
                return false;
            }

            $partidas = [];
            foreach (self::partidas($ordenId) as $partida) {
                $partidas[(int) $partida['id']] = $partida;
            }

            $aInsertar = [];
            $agrupadas = [];
            foreach ($renglones as $partidaId => $datos) {
                $cantidad = (int) ($datos['cantidad'] ?? 0);
                if ($cantidad === 0) {
                    continue;
                }
                $partidaId = (int) $partidaId;
                if (!isset($partidas[$partidaId]) || $cantidad < 0) {
                    self::$db->rollback();
                    self::$errores[] = 'Hay una cantidad recibida inválida.';
                    return false;
                }
                $costo = $datos['costo'] ?? '';
                if (!is_numeric($costo) || (float) $costo < 0) {
                    self::$db->rollback();
                    self::$errores[] = 'El costo unitario de «' . $partidas[$partidaId]['producto_nombre'] . '» no es válido.';
                    return false;
                }
                $aInsertar[$partidaId] = [$cantidad, number_format((float) $costo, 2, '.', '')];
                $productoId = (int) $partidas[$partidaId]['producto_id'];
                $agrupadas[$productoId] = ($agrupadas[$productoId] ?? 0) + $cantidad;
            }

            if (!$aInsertar) {
                self::$db->rollback();
                self::$errores[] = 'Captura al menos una cantidad recibida mayor a cero.';
                return false;
            }

            $notas = trim($notas) ?: null;
            $flag = $actualizaCosto ? 1 : 0;
            $consulta = self::$db->prepare(
                'INSERT INTO recepciones (orden_id, fecha, token, actualiza_costo, notas, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $consulta->bind_param('issisi', $ordenId, $fecha, $token, $flag, $notas, $usuarioId);
            try {
                $consulta->execute();
            } catch (\mysqli_sql_exception $duplicada) {
                self::$db->rollback();
                self::$errores[] = 'Esta recepción ya fue registrada; no se volvió a sumar al inventario.';
                return false;
            }
            $recepcionId = (int) self::$db->insert_id;
            $consulta->close();

            $insertar = self::$db->prepare(
                'INSERT INTO recepcion_partidas (recepcion_id, partida_id, producto_id, cantidad_recibida, costo_unitario)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($aInsertar as $partidaId => [$cantidad, $costo]) {
                $productoId = (int) $partidas[$partidaId]['producto_id'];
                $insertar->bind_param('iiiis', $recepcionId, $partidaId, $productoId, $cantidad, $costo);
                $insertar->execute();

                if ($actualizaCosto) {
                    $actualizar = self::$db->prepare('UPDATE productos SET costo_actual = ? WHERE id = ?');
                    $actualizar->bind_param('si', $costo, $productoId);
                    $actualizar->execute();
                    $actualizar->close();
                }
            }
            $insertar->close();

            if (!Inventario::entradaPorRecepcion($agrupadas, $recepcionId, $usuarioId, 'Recepción ' . $orden['folio'])) {
                self::$db->rollback();
                self::$errores = Inventario::getErrores() ?: ['No fue posible sumar al inventario.'];
                return false;
            }

            self::recalcularEstado($ordenId);
            Auditoria::registrar('orden_compra', $ordenId, 'recepcion', $usuarioId, null, [
                'recepcion_id' => $recepcionId, 'piezas' => array_sum($agrupadas),
            ]);

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::recibir] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar la recepción.';
            return false;
        }
    }

    /** Cierra la orden (aun con faltantes). */
    public static function cerrar(int $id, string $motivo, int $usuarioId): bool
    {
        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($id);

            if (!$orden || !in_array($orden['estado'], ['solicitada', 'parcial', 'completa'], true)) {
                self::$db->rollback();
                self::$errores[] = 'La orden no se puede cerrar en su estado actual.';
                return false;
            }

            $motivo = trim($motivo) ?: null;
            $consulta = self::$db->prepare(
                "UPDATE ordenes_compra SET estado = 'cerrada', cerrada_en = NOW(), cerrada_por = ?, motivo_cierre = ? WHERE id = ?"
            );
            $consulta->bind_param('isi', $usuarioId, $motivo, $id);
            $consulta->execute();
            $consulta->close();

            Auditoria::registrar('orden_compra', $id, 'cerrar', $usuarioId, $motivo);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::cerrar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible cerrar la orden.';
            return false;
        }
    }

    /** Reabre una orden cerrada. El motivo es obligatorio y queda auditado. */
    public static function reabrir(int $id, string $motivo, int $usuarioId): bool
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            self::$errores[] = 'Para reabrir una orden cerrada escribe el motivo (mínimo 5 caracteres).';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $orden = self::bloquear($id);

            if (!$orden || $orden['estado'] !== 'cerrada') {
                self::$db->rollback();
                self::$errores[] = 'Solo se puede reabrir una orden cerrada.';
                return false;
            }

            $consulta = self::$db->prepare(
                'UPDATE ordenes_compra SET cerrada_en = NULL, cerrada_por = NULL, motivo_cierre = NULL WHERE id = ?'
            );
            $consulta->bind_param('i', $id);
            $consulta->execute();
            $consulta->close();

            self::recalcularEstado($id);
            Auditoria::registrar('orden_compra', $id, 'reabrir', $usuarioId, $motivo);
            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollback();
            error_log('[OrdenesCompra::reabrir] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible reabrir la orden.';
            return false;
        }
    }

    /* --------------------------------------------------------------------
     * INTERNOS
     * ------------------------------------------------------------------ */

    /** Bloquea la fila de la orden hasta el fin de la transacción. */
    private static function bloquear(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM ordenes_compra WHERE id = ? FOR UPDATE');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    private static function contarRecepciones(int $ordenId): int
    {
        $consulta = self::$db->prepare('SELECT COUNT(*) AS n FROM recepciones WHERE orden_id = ?');
        $consulta->bind_param('i', $ordenId);
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $n;
    }

    /** solicitada → parcial → completa según lo recibido. No toca cerrada/cancelada. */
    private static function recalcularEstado(int $ordenId): void
    {
        $partidas = self::partidas($ordenId);
        $recibidas = array_sum(array_column($partidas, 'recibida'));
        $pendiente = array_sum(array_map(static fn ($p) => max(0, $p['pendiente']), $partidas));

        $estado = $recibidas === 0 ? 'solicitada' : ($pendiente > 0 ? 'parcial' : 'completa');

        $consulta = self::$db->prepare(
            "UPDATE ordenes_compra SET estado = ? WHERE id = ? AND estado NOT IN ('cancelada')"
        );
        $consulta->bind_param('si', $estado, $ordenId);
        $consulta->execute();
        $consulta->close();
    }

    private static function guardarPartidas(int $ordenId, array $lineas): void
    {
        $consulta = self::$db->prepare(
            'INSERT INTO orden_compra_partidas
                (orden_id, producto_id, descripcion, cantidad_solicitada, costo_esperado, importe_esperado)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lineas as $linea) {
            $productoId = (int) $linea['producto_id'];
            $cantidad   = (int) $linea['cantidad'];
            $costo      = number_format((float) $linea['costo'], 2, '.', '');
            $importe    = number_format(self::importe($cantidad, $linea['costo']), 2, '.', '');
            $descripcion = trim((string) ($linea['descripcion'] ?? '')) ?: null;
            $consulta->bind_param('iisiss', $ordenId, $productoId, $descripcion, $cantidad, $costo, $importe);
            $consulta->execute();
        }
        $consulta->close();
    }

    /** OC-AAAA-NNNN; el índice único es la última defensa ante carreras. */
    private static function siguienteFolio(): string
    {
        $anio = date('Y');
        $prefijo = "OC-$anio-";
        $consulta = self::$db->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(folio, 9) AS UNSIGNED)), 0) AS n
               FROM ordenes_compra WHERE folio LIKE ? FOR UPDATE'
        );
        $patron = $prefijo . '%';
        $consulta->bind_param('s', $patron);
        $consulta->execute();
        $n = (int) $consulta->get_result()->fetch_assoc()['n'];
        $consulta->close();

        return $prefijo . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
