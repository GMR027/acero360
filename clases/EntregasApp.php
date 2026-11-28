<?php

namespace App;

/**
 * EntregasApp — API de la aplicación Android «Acero Entregas».
 *
 * La app es un cliente móvil de la misma plataforma: usa los usuarios de
 * `usuarios`, las ventas confirmadas de `ventas`, y las clases Entregas,
 * IncidenciasEntrega, IntentosEntrega y Documentos. NO devuelve precios,
 * importes, IVA ni datos fiscales.
 */
class EntregasApp
{
    /** @var \mysqli */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    /** Días que dura un token de la app. */
    public const TOKEN_DIAS = 30;

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
     * AUTENTICACIÓN
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null */
    public static function login(string $email, string $password, string $dispositivo = '', string $deviceId = ''): ?array
    {
        self::$errores = [];
        $email = mb_strtolower(trim($email));
        if ($email === '' || $password === '') {
            self::$errores[] = 'Escribe tu correo y contraseña.';
            return null;
        }

        $consulta = self::$db->prepare(
            'SELECT id, nombre, email, telefono, foto, contrasena FROM usuarios WHERE email = ? LIMIT 1'
        );
        $consulta->bind_param('s', $email);
        $consulta->execute();
        $usuario = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$usuario || !password_verify($password, (string) $usuario['contrasena'])) {
            usleep(350000);
            self::$errores[] = 'El correo o la contraseña no son correctos.';
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $huella = hash('sha256', (string) $usuario['contrasena']);
        $dispositivo = mb_substr(trim($dispositivo), 0, 120) ?: null;
        $deviceId = mb_substr(trim($deviceId), 0, 80) ?: null;
        $expira = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_DIAS . ' days'));

        // Limpia tokens viejos del mismo usuario para no acumular basura.
        $limpiar = self::$db->prepare(
            'DELETE FROM tokens_entregas WHERE usuario_id = ? AND (expira_en < NOW() OR revocado_en IS NOT NULL)'
        );
        $limpiar->bind_param('i', $usuario['id']);
        $limpiar->execute();
        $limpiar->close();

        $crear = self::$db->prepare(
            'INSERT INTO tokens_entregas (usuario_id, token_hash, huella, dispositivo, device_id, expira_en)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $crear->bind_param('isssss', $usuario['id'], $hash, $huella, $dispositivo, $deviceId, $expira);
        $crear->execute();
        $crear->close();

        unset($usuario['contrasena']);
        $usuario['id'] = (int) $usuario['id'];

        return [
            'token'     => $token,
            'expira_en' => $expira,
            'usuario'   => $usuario,
        ];
    }

    /** Token que llegó en Authorization: Bearer ... */
    public static function tokenDeRequest(): string
    {
        $cabecera = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($cabecera === '' && function_exists('apache_request_headers')) {
            $cabeceras = apache_request_headers();
            $cabecera = (string) ($cabeceras['Authorization'] ?? $cabeceras['authorization'] ?? '');
        }
        if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($cabecera), $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    /**
     * Valida el token y devuelve el usuario. Actualiza `ultimo_uso`.
     *
     * @return array<string, mixed>|null
     */
    public static function autenticar(?string $token = null): ?array
    {
        self::$errores = [];
        $token = $token ?? self::tokenDeRequest();
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            self::$errores[] = 'Inicia sesión para continuar.';
            return null;
        }

        $hash = hash('sha256', $token);
        $consulta = self::$db->prepare(
            'SELECT t.id AS token_id, t.huella, t.expira_en, t.revocado_en,
                    u.id, u.nombre, u.email, u.telefono, u.foto, u.contrasena
               FROM tokens_entregas t
               JOIN usuarios u ON u.id = t.usuario_id
              WHERE t.token_hash = ? LIMIT 1'
        );
        $consulta->bind_param('s', $hash);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$fila || $fila['revocado_en'] !== null || strtotime((string) $fila['expira_en']) < time()) {
            self::$errores[] = 'La sesión caducó. Inicia sesión de nuevo.';
            return null;
        }
        if (!hash_equals((string) $fila['huella'], hash('sha256', (string) $fila['contrasena']))) {
            $revocar = self::$db->prepare('UPDATE tokens_entregas SET revocado_en = NOW() WHERE id = ?');
            $revocar->bind_param('i', $fila['token_id']);
            $revocar->execute();
            $revocar->close();
            self::$errores[] = 'Tu contraseña cambió. Inicia sesión de nuevo.';
            return null;
        }

        $usar = self::$db->prepare('UPDATE tokens_entregas SET ultimo_uso = NOW() WHERE id = ?');
        $usar->bind_param('i', $fila['token_id']);
        $usar->execute();
        $usar->close();

        unset($fila['contrasena'], $fila['huella'], $fila['expira_en'], $fila['revocado_en']);
        $fila['id'] = (int) $fila['id'];
        $fila['token_id'] = (int) $fila['token_id'];

        return $fila;
    }

    public static function logout(string $token): void
    {
        $hash = hash('sha256', $token);
        $consulta = self::$db->prepare(
            'UPDATE tokens_entregas SET revocado_en = NOW() WHERE token_hash = ? AND revocado_en IS NULL'
        );
        $consulta->bind_param('s', $hash);
        $consulta->execute();
        $consulta->close();
    }

    /* --------------------------------------------------------------------
     * PENDIENTES Y DETALLE
     * ------------------------------------------------------------------ */

    /**
     * Todas las ventas confirmadas con piezas pendientes, agrupadas por
     * cliente. Sin precios ni importes.
     *
     * @param array{q?: string, empresa?: int} $filtros
     * @return array<string, mixed>
     */
    public static function pendientes(array $filtros = []): array
    {
        self::$errores = [];
        $donde = ['v.estado = "registrada"', 'v.entrega_cerrada_en IS NULL'];
        $tipos = '';
        $valores = [];

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $donde[] = '(v.folio LIKE ? OR cl.nombre LIKE ? OR IFNULL(cl.nombre_empresa, "") LIKE ? OR IFNULL(cl.alias, "") LIKE ?)';
            $tipos .= 'ssss';
            $patron = '%' . $q . '%';
            array_push($valores, $patron, $patron, $patron, $patron);
        }
        $empresa = (int) ($filtros['empresa'] ?? 0);
        if ($empresa > 0) {
            $donde[] = 'v.empresa_id = ?';
            $tipos .= 'i';
            $valores[] = $empresa;
        }

        $sql = 'SELECT v.id, v.folio, v.fecha, v.entrega_fecha, v.entrega_estado,
                       e.id AS empresa_id, e.nombre AS empresa_nombre,
                       cl.id AS cliente_id, cl.nombre AS cliente_nombre,
                       cl.nombre_empresa AS cliente_empresa, cl.telefono AS cliente_telefono,
                       cl.ubicacion AS cliente_ubicacion, cl.ubicacion_mapa AS cliente_mapa,
                       py.folio AS proyecto_folio, py.nombre AS proyecto_nombre,
                       (SELECT COUNT(*) FROM entrega_incidencias i
                         WHERE i.venta_id = v.id AND i.estado = "abierta") AS incidencias_abiertas
                  FROM ventas v
                  JOIN empresas e ON e.id = v.empresa_id
                  JOIN clientes cl ON cl.id = v.cliente_id
                  LEFT JOIN proyectos_entrega py ON py.id = v.proyecto_id
                 WHERE ' . implode(' AND ', $donde) . '
                 ORDER BY COALESCE(NULLIF(cl.nombre_empresa, ""), cl.nombre), v.id';
        $consulta = self::$db->prepare($sql);
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $ventas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        if (!$ventas) {
            return ['clientes' => [], 'totales' => ['clientes' => 0, 'ventas' => 0, 'piezas' => 0]];
        }

        $ids = array_map('intval', array_column($ventas, 'id'));
        $pendientes = Entregas::pendientesDeVentas($ids);

        $clientes = [];
        foreach ($ventas as $venta) {
            $lineas = array_values($pendientes[(int) $venta['id']] ?? []);
            $pendiente = 0;
            $vendido = 0;
            $entregado = 0;
            $lineasVenta = [];
            foreach ($lineas as $linea) {
                $pendiente += max(0, (int) $linea['pendiente']);
                $vendido += (int) $linea['vendido'];
                $entregado += (int) $linea['entregado'];
                $lineasVenta[] = [
                    'producto_id' => (int) $linea['producto_id'],
                    'sku'         => (string) ($linea['sku'] ?? ''),
                    'nombre'      => (string) $linea['nombre'],
                    'unidad'      => (string) ($linea['unidad'] ?? 'pza'),
                    'imagen'      => (string) ($linea['imagen'] ?? ''),
                    'vendido'     => (int) $linea['vendido'],
                    'entregado'   => (int) $linea['entregado'],
                    'pendiente'   => (int) $linea['pendiente'],
                ];
            }
            if ($pendiente <= 0) {
                continue;
            }

            $clienteId = (int) $venta['cliente_id'];
            if (!isset($clientes[$clienteId])) {
                $clientes[$clienteId] = [
                    'cliente_id' => $clienteId,
                    'nombre'     => trim((string) ($venta['cliente_empresa'] ?: $venta['cliente_nombre'])),
                    'contacto'   => (string) $venta['cliente_nombre'],
                    'telefono'   => (string) ($venta['cliente_telefono'] ?? ''),
                    'direccion'  => (string) ($venta['cliente_ubicacion'] ?? ''),
                    'mapa'       => (string) ($venta['cliente_mapa'] ?? ''),
                    'piezas'     => 0,
                    'ventas'     => [],
                    'productos'  => [],
                ];
            }

            $clientes[$clienteId]['ventas'][] = [
                'id'                 => (int) $venta['id'],
                'folio'              => (string) $venta['folio'],
                'fecha'              => (string) $venta['fecha'],
                'entrega_fecha'      => $venta['entrega_fecha'],
                'entrega_estado'     => (string) $venta['entrega_estado'],
                'empresa_id'         => (int) $venta['empresa_id'],
                'empresa_nombre'     => (string) $venta['empresa_nombre'],
                'proyecto_folio'     => (string) ($venta['proyecto_folio'] ?? ''),
                'proyecto_nombre'    => (string) ($venta['proyecto_nombre'] ?? ''),
                'piezas'             => $vendido,
                'entregadas'         => $entregado,
                'pendiente'          => $pendiente,
                'incidencias_abiertas' => (int) $venta['incidencias_abiertas'],
                'lineas'             => $lineasVenta,
            ];
            $clientes[$clienteId]['piezas'] += $pendiente;

            foreach ($lineas as $linea) {
                $productoId = (int) $linea['producto_id'];
                if ((int) $linea['pendiente'] <= 0) {
                    continue;
                }
                $clientes[$clienteId]['productos'][$productoId] ??= [
                    'producto_id' => $productoId,
                    'sku'         => (string) ($linea['sku'] ?? ''),
                    'nombre'      => (string) $linea['nombre'],
                    'unidad'      => (string) ($linea['unidad'] ?? 'pza'),
                    'imagen'      => (string) ($linea['imagen'] ?? ''),
                    'total'       => 0,
                ];
                $clientes[$clienteId]['productos'][$productoId]['total'] += (int) $linea['pendiente'];
            }
        }

        $salida = [];
        foreach ($clientes as $cliente) {
            $cliente['productos'] = array_values($cliente['productos']);
            $salida[] = $cliente;
        }

        return [
            'clientes' => $salida,
            'totales'  => [
                'clientes' => count($salida),
                'ventas'   => array_sum(array_map(static fn (array $c): int => count($c['ventas']), $salida)),
                'piezas'   => array_sum(array_map(static fn (array $c): int => (int) $c['piezas'], $salida)),
            ],
            'generado_en' => date('c'),
        ];
    }

    /**
     * Detalle de una venta para entregar. Solo datos logísticos.
     *
     * @return array<string, mixed>|null
     */
    public static function venta(int $ventaId): ?array
    {
        self::$errores = [];
        $consulta = self::$db->prepare(
            'SELECT v.id, v.folio, v.fecha, v.entrega_fecha, v.entrega_observaciones, v.entrega_estado,
                    v.condiciones_envio, v.comentarios, v.estado,
                    e.id AS empresa_id, e.nombre AS empresa_nombre, e.logo AS empresa_logo,
                    cl.id AS cliente_id, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    cl.telefono AS cliente_telefono, cl.email AS cliente_email,
                    cl.ubicacion AS cliente_ubicacion, cl.ubicacion_mapa AS cliente_mapa,
                    py.folio AS proyecto_folio, py.nombre AS proyecto_nombre
               FROM ventas v
               JOIN empresas e ON e.id = v.empresa_id
               JOIN clientes cl ON cl.id = v.cliente_id
               LEFT JOIN proyectos_entrega py ON py.id = v.proyecto_id
              WHERE v.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $venta = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$venta) {
            self::$errores[] = 'La venta no existe.';
            return null;
        }
        if ($venta['estado'] !== 'registrada') {
            self::$errores[] = 'La venta no está registrada.';
            return null;
        }

        $lineas = array_values(Entregas::pendientesDeVentas([$ventaId])[$ventaId] ?? []);
        $pendienteTotal = 0;
        $vendidoTotal = 0;
        $entregadoTotal = 0;
        foreach ($lineas as &$linea) {
            $linea['producto_id'] = (int) $linea['producto_id'];
            $linea['vendido'] = (int) $linea['vendido'];
            $linea['entregado'] = (int) $linea['entregado'];
            $linea['pendiente'] = (int) $linea['pendiente'];
            unset($linea['venta_id']);
            $pendienteTotal += $linea['pendiente'];
            $vendidoTotal += $linea['vendido'];
            $entregadoTotal += $linea['entregado'];
        }
        unset($linea);

        $historial = [];
        foreach (Entregas::entregasDeVenta($ventaId) as $entrega) {
            $historial[] = [
                'id'             => (int) $entrega['id'],
                'fecha'          => (string) $entrega['fecha'],
                'creado_en'      => (string) $entrega['creado_en'],
                'notas'          => (string) ($entrega['notas'] ?? ''),
                'recibio_nombre' => (string) ($entrega['recibio_nombre'] ?? ''),
                'origen'         => (string) ($entrega['origen'] ?? 'web'),
                'usuario_nombre' => (string) ($entrega['usuario_nombre'] ?? ''),
                'ubicacion'      => (string) ($entrega['ubicacion_maps'] ?? ''),
                'renglones'      => array_map(static fn (array $r): array => [
                    'producto_nombre' => (string) $r['producto_nombre'],
                    'cantidad'        => (int) $r['cantidad'],
                ], $entrega['renglones'] ?? []),
                'fotos'          => count($entrega['fotos'] ?? []),
            ];
        }

        $incidencias = [];
        foreach (IncidenciasEntrega::deVenta($ventaId) as $incidencia) {
            $incidencias[] = [
                'id'             => (int) $incidencia['id'],
                'motivo'         => (string) $incidencia['motivo'],
                'motivo_etiqueta'=> (string) (IncidenciasEntrega::MOTIVOS[$incidencia['motivo']]['etiqueta'] ?? $incidencia['motivo']),
                'estado'         => (string) $incidencia['estado'],
                'notas'          => (string) ($incidencia['notas'] ?? ''),
                'creado_en'      => (string) $incidencia['creado_en'],
                'renglones'      => array_map(static fn (array $r): array => [
                    'producto_nombre'  => (string) ($r['producto_nombre'] ?? ''),
                    'cantidad'         => (int) $r['cantidad'],
                    'requiere_cambio'  => (int) $r['requiere_cambio'] === 1,
                ], $incidencia['renglones'] ?? []),
            ];
        }

        $intentos = [];
        foreach (IntentosEntrega::deVenta($ventaId) as $intento) {
            $intentos[] = [
                'id'             => (int) $intento['id'],
                'fecha'          => (string) $intento['fecha'],
                'motivo'         => (string) $intento['motivo'],
                'motivo_etiqueta'=> (string) (IntentosEntrega::MOTIVOS[$intento['motivo']]['etiqueta'] ?? $intento['motivo']),
                'notas'          => (string) ($intento['notas'] ?? ''),
                'usuario_nombre' => (string) ($intento['usuario_nombre'] ?? ''),
                'fotos'          => count($intento['fotos'] ?? []),
            ];
        }

        return [
            'venta' => [
                'id'                  => (int) $venta['id'],
                'folio'               => (string) $venta['folio'],
                'fecha'               => (string) $venta['fecha'],
                'entrega_fecha'       => $venta['entrega_fecha'],
                'entrega_observaciones' => (string) ($venta['entrega_observaciones'] ?? ''),
                'entrega_estado'      => (string) $venta['entrega_estado'],
                'condiciones_envio'   => (string) ($venta['condiciones_envio'] ?? ''),
                'comentarios'         => (string) ($venta['comentarios'] ?? ''),
                'empresa_id'          => (int) $venta['empresa_id'],
                'empresa_nombre'      => (string) $venta['empresa_nombre'],
                'empresa_logo'        => (string) ($venta['empresa_logo'] ?? ''),
                'proyecto_folio'      => (string) ($venta['proyecto_folio'] ?? ''),
                'proyecto_nombre'     => (string) ($venta['proyecto_nombre'] ?? ''),
            ],
            'cliente' => [
                'id'        => (int) $venta['cliente_id'],
                'nombre'    => (string) $venta['cliente_nombre'],
                'empresa'   => (string) ($venta['cliente_empresa'] ?? ''),
                'telefono'  => (string) ($venta['cliente_telefono'] ?? ''),
                'email'     => (string) ($venta['cliente_email'] ?? ''),
                'direccion' => (string) ($venta['cliente_ubicacion'] ?? ''),
                'mapa'      => (string) ($venta['cliente_mapa'] ?? ''),
            ],
            'lineas'   => $lineas,
            'historial'=> $historial,
            'incidencias' => $incidencias,
            'intentos' => $intentos,
            'resumen'  => [
                'vendido'   => $vendidoTotal,
                'entregado' => $entregadoTotal,
                'pendiente' => $pendienteTotal,
                'incidencias_abiertas' => count(array_filter($incidencias, static fn (array $i): bool => $i['estado'] === 'abierta')),
            ],
            'puede_entregar' => $pendienteTotal > 0,
        ];
    }

    /* --------------------------------------------------------------------
     * REGISTRO DE ENTREGA, INCIDENCIA E INTENTO
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null */
    public static function registrarEntrega(int $usuarioId, array $datos, array $fotos = [], array $metadatos = []): ?array
    {
        self::$errores = [];
        $ventaId = (int) ($datos['venta_id'] ?? 0);
        $token = strtolower(trim((string) ($datos['token'] ?? '')));
        $fecha = trim((string) ($datos['fecha'] ?? ''));
        $notas = (string) ($datos['notas'] ?? '');
        $recibio = (string) ($datos['recibio_nombre'] ?? '');
        $ubicacion = (string) ($datos['ubicacion'] ?? '');

        if ($ventaId <= 0) {
            self::$errores[] = 'Falta la venta.';
            return null;
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            self::$errores[] = 'El token de la entrega no es válido.';
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            self::$errores[] = 'La fecha de entrega no es válida.';
            return null;
        }

        $cantidadesRaw = $datos['cantidades'] ?? [];
        if (is_string($cantidadesRaw)) {
            $cantidadesRaw = json_decode($cantidadesRaw, true) ?: [];
        }
        $cantidades = [];
        foreach ((array) $cantidadesRaw as $productoId => $cantidad) {
            $cantidad = (int) $cantidad;
            if ($cantidad > 0) {
                $cantidades[(int) $productoId] = $cantidad;
            }
        }
        if (!$cantidades) {
            self::$errores[] = 'Captura al menos una pieza entregada.';
            return null;
        }

        // Idempotencia: si el teléfono perdió la respuesta, devolvemos la misma entrega.
        $repetida = self::$db->prepare('SELECT id, venta_id FROM entregas WHERE token = ? LIMIT 1');
        $repetida->bind_param('s', $token);
        $repetida->execute();
        $fila = $repetida->get_result()->fetch_assoc();
        $repetida->close();
        if ($fila) {
            if ((int) $fila['venta_id'] !== $ventaId) {
                self::$errores[] = 'El token de la entrega ya se usó en otra venta.';
                return null;
            }
            return ['entrega_id' => (int) $fila['id'], 'duplicado' => true] + self::estadoVenta($ventaId);
        }

        $ok = Entregas::registrarEntrega(
            $ventaId, null, $cantidades, $fecha, $token, $notas, $usuarioId,
            $fotos, $metadatos, $ubicacion, $recibio, 'app'
        );
        if (!$ok) {
            self::$errores = array_merge(self::$errores, Entregas::getErrores());
            return null;
        }

        $nueva = self::$db->prepare('SELECT id FROM entregas WHERE token = ? LIMIT 1');
        $nueva->bind_param('s', $token);
        $nueva->execute();
        $entrega = $nueva->get_result()->fetch_assoc();
        $nueva->close();

        return ['entrega_id' => (int) ($entrega['id'] ?? 0), 'duplicado' => false]
            + self::estadoVenta($ventaId);
    }

    /** @return array<string, mixed>|null */
    public static function registrarIncidencia(int $usuarioId, array $datos): ?array
    {
        self::$errores = [];
        $ventaId = (int) ($datos['venta_id'] ?? 0);
        $motivo = (string) ($datos['motivo'] ?? '');
        $token = strtolower(trim((string) ($datos['token'] ?? '')));
        $notas = (string) ($datos['notas'] ?? '');
        $entregaId = (int) ($datos['entrega_id'] ?? 0);

        $renglonesRaw = $datos['renglones'] ?? [];
        if (is_string($renglonesRaw)) {
            $renglonesRaw = json_decode($renglonesRaw, true) ?: [];
        }
        $renglones = [];
        foreach ((array) $renglonesRaw as $r) {
            $productoId = (int) ($r['producto_id'] ?? $r['id'] ?? 0);
            $cantidad = (int) ($r['cantidad'] ?? 0);
            if ($productoId > 0 && $cantidad > 0) {
                $renglones[$productoId] = [
                    'cantidad' => $cantidad,
                    'cambio'   => (int) ($r['cambio'] ?? 0),
                ];
            }
        }

        if ($ventaId <= 0 || $motivo === '' || !$renglones) {
            self::$errores[] = 'Indica el motivo y al menos un producto afectado.';
            return null;
        }

        $ok = IncidenciasEntrega::registrar(
            $ventaId, $motivo, $renglones, $notas, false, $usuarioId,
            $entregaId > 0 ? $entregaId : null, $token, 'app'
        );
        if (!$ok) {
            self::$errores = array_merge(self::$errores, IncidenciasEntrega::getErrores());
            return null;
        }

        return ['incidencia_id' => IncidenciasEntrega::$ultimoId, 'duplicado' => false] + self::estadoVenta($ventaId);
    }

    /** @return array<string, mixed>|null */
    public static function registrarIntento(int $usuarioId, array $datos, array $fotos = [], array $metadatos = []): ?array
    {
        self::$errores = [];
        $ventaId = (int) ($datos['venta_id'] ?? 0);
        $motivo = (string) ($datos['motivo'] ?? '');
        $token = strtolower(trim((string) ($datos['token'] ?? '')));
        $notas = (string) ($datos['notas'] ?? '');
        $ubicacion = (string) ($datos['ubicacion'] ?? '');

        if ($ventaId <= 0 || $motivo === '') {
            self::$errores[] = 'Indica la venta y el motivo de la visita.';
            return null;
        }

        $ok = IntentosEntrega::registrar(
            $ventaId, $motivo, $notas, $ubicacion, $usuarioId, $token, $fotos, $metadatos, 'app'
        );
        if (!$ok) {
            self::$errores = array_merge(self::$errores, IntentosEntrega::getErrores());
            return null;
        }

        return ['intento_id' => IntentosEntrega::$ultimoId, 'duplicado' => false] + self::estadoVenta($ventaId);
    }

    /** @return array<string, mixed> */
    public static function estadoVenta(int $ventaId): array
    {
        $consulta = self::$db->prepare('SELECT folio, entrega_estado FROM ventas WHERE id = ? LIMIT 1');
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $venta = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        $pendientes = Entregas::pendientesDeVentas([$ventaId])[$ventaId] ?? [];
        return [
            'venta' => [
                'id'     => $ventaId,
                'folio'  => (string) ($venta['folio'] ?? ''),
                'entrega_estado' => (string) ($venta['entrega_estado'] ?? 'pendiente'),
            ],
            'pendientes' => array_map(static fn (array $l): array => [
                'producto_id' => (int) $l['producto_id'],
                'nombre'      => (string) $l['nombre'],
                'pendiente'   => (int) $l['pendiente'],
            ], array_values($pendientes)),
        ];
    }

    /* --------------------------------------------------------------------
     * HISTORIAL
     * ------------------------------------------------------------------ */

    /**
     * Historial de TODO el equipo (la app no separa por usuario), con filtros.
     *
     * @param array{usuario?: int, desde?: string, hasta?: string} $filtros
     * @return array<string, mixed>
     */
    public static function historial(array $filtros = []): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];

        $usuario = (int) ($filtros['usuario'] ?? 0);
        if ($usuario > 0) {
            $donde[] = 'en.usuario_id = ?';
            $tipos .= 'i';
            $valores[] = $usuario;
        }
        $desde = trim((string) ($filtros['desde'] ?? ''));
        if ($desde !== '' && strtotime($desde)) {
            $donde[] = 'en.fecha >= ?';
            $tipos .= 's';
            $valores[] = date('Y-m-d', strtotime($desde));
        }
        $hasta = trim((string) ($filtros['hasta'] ?? ''));
        if ($hasta !== '' && strtotime($hasta)) {
            $donde[] = 'en.fecha <= ?';
            $tipos .= 's';
            $valores[] = date('Y-m-d', strtotime($hasta));
        }

        $sql = 'SELECT en.id, en.venta_id, en.fecha, en.creado_en, en.notas, en.recibio_nombre, en.origen,
                       v.folio, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                       u.nombre AS usuario_nombre,
                       (SELECT COUNT(*) FROM entrega_fotos ef WHERE ef.entrega_id = en.id) AS fotos
                  FROM entregas en
                  JOIN ventas v ON v.id = en.venta_id
                  JOIN clientes cl ON cl.id = v.cliente_id
                  LEFT JOIN usuarios u ON u.id = en.usuario_id
                 WHERE ' . implode(' AND ', $donde) . '
                 ORDER BY en.id DESC LIMIT 200';
        $consulta = self::$db->prepare($sql);
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $entregas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        if ($entregas) {
            $ids = array_map('intval', array_column($entregas, 'id'));
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $detalle = self::$db->prepare(
                "SELECT er.entrega_id, er.cantidad, p.nombre
                   FROM entrega_renglones er JOIN productos p ON p.id = er.producto_id
                  WHERE er.entrega_id IN ($marcadores) ORDER BY er.id"
            );
            $detalle->bind_param(str_repeat('i', count($ids)), ...$ids);
            $detalle->execute();
            $porEntrega = [];
            foreach ($detalle->get_result() as $r) {
                $porEntrega[(int) $r['entrega_id']][] = [
                    'nombre' => (string) $r['nombre'],
                    'cantidad' => (int) $r['cantidad'],
                ];
            }
            $detalle->close();
            foreach ($entregas as &$entrega) {
                $entrega['renglones'] = $porEntrega[(int) $entrega['id']] ?? [];
            }
            unset($entrega);
        }

        $consulta = self::$db->query(
            'SELECT i.id, i.venta_id, i.fecha, i.motivo, i.notas, i.usuario_id,
                    v.folio, cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    u.nombre AS usuario_nombre,
                    (SELECT COUNT(*) FROM intento_fotos f WHERE f.intento_id = i.id) AS fotos
               FROM intentos_entrega i
               JOIN ventas v ON v.id = i.venta_id
               JOIN clientes cl ON cl.id = v.cliente_id
               LEFT JOIN usuarios u ON u.id = i.usuario_id
              ORDER BY i.id DESC LIMIT 200'
        );
        $intentos = [];
        while ($intento = $consulta->fetch_assoc()) {
            $intento['motivo_etiqueta'] = IntentosEntrega::MOTIVOS[$intento['motivo']]['etiqueta'] ?? $intento['motivo'];
            $intentos[] = $intento;
        }
        $consulta->free();

        return [
            'entregas' => $entregas,
            'intentos' => $intentos,
            'generado_en' => date('c'),
        ];
    }

    /* --------------------------------------------------------------------
     * FOTO Y PDF
     * ------------------------------------------------------------------ */

    /** @return array{contenido: string, mime: string}|null */
    public static function foto(int $fotoId, string $tipo = 'entrega', int $ancho = 1600): ?array
    {
        $ancho = max(400, min(1600, $ancho));
        if ($tipo === 'intento') {
            $consulta = self::$db->prepare(
                'SELECT f.*, i.fecha AS entrega_fecha, i.creado_en AS entrega_creado_en,
                        i.ubicacion_maps, i.ubicacion_lat, i.ubicacion_lon,
                        cl.ubicacion AS cliente_ubicacion, e.logo AS empresa_logo
                   FROM intento_fotos f
                   JOIN intentos_entrega i ON i.id = f.intento_id
                   JOIN ventas v ON v.id = i.venta_id
                   JOIN clientes cl ON cl.id = v.cliente_id
                   JOIN empresas e ON e.id = v.empresa_id
                  WHERE f.id = ? LIMIT 1'
            );
        } else {
            $consulta = self::$db->prepare(
                'SELECT f.*, en.fecha AS entrega_fecha, en.creado_en AS entrega_creado_en,
                        en.ubicacion_maps, en.ubicacion_lat, en.ubicacion_lon,
                        cl.ubicacion AS cliente_ubicacion, e.logo AS empresa_logo
                   FROM entrega_fotos f
                   JOIN entregas en ON en.id = f.entrega_id
                   JOIN ventas v ON v.id = en.venta_id
                   JOIN clientes cl ON cl.id = v.cliente_id
                   JOIN empresas e ON e.id = v.empresa_id
                  WHERE f.id = ? LIMIT 1'
            );
        }
        $consulta->bind_param('i', $fotoId);
        $consulta->execute();
        $foto = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$foto) {
            return null;
        }

        $marcada = FotosEntrega::marcada($foto, $ancho);
        if ($marcada !== null) {
            return $marcada;
        }

        $ruta = Imagenes::CARPETA . '/' . basename((string) $foto['archivo']);
        if (!is_file($ruta)) {
            return null;
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($ruta) : 'image/jpeg';
        return ['contenido' => (string) file_get_contents($ruta), 'mime' => $mime ?: 'image/jpeg'];
    }

    /** @param int[] $ventaIds */
    public static function pdfPreparacion(array $ventaIds): ?string
    {
        self::$errores = [];
        $ventaIds = array_values(array_unique(array_filter(array_map('intval', $ventaIds))));
        if (!$ventaIds) {
            self::$errores[] = 'Selecciona al menos un pedido.';
            return null;
        }
        if (!Entregas::ventasPorIds($ventaIds)) {
            self::$errores[] = 'Los pedidos ya no están disponibles.';
            return null;
        }
        return Documentos::pdfListaEntrega(0, 'preparacion', $ventaIds);
    }

    public static function pdfEntrega(int $ventaId): ?string
    {
        self::$errores = [];
        $pdf = Documentos::pdfEntregaDosCopias($ventaId);
        if ($pdf === null) {
            self::$errores = array_merge(self::$errores, Documentos::getErrores());
        }
        return $pdf;
    }

    public static function pdfActa(int $ventaId, int $entregaId = 0): ?string
    {
        self::$errores = [];
        $pdf = Documentos::pdfActaEntregaVenta($ventaId, $entregaId);
        if ($pdf === null) {
            self::$errores = array_merge(self::$errores, Documentos::getErrores());
        }
        return $pdf;
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'fecha_servidor'       => date('c'),
            'max_fotos'            => Entregas::MAX_FOTOS,
            'max_foto_mb'          => (int) ceil(Imagenes::TAMANO_MAXIMO_ENTREGA / 1048576),
            'max_fotos_total_mb'   => Entregas::MAX_FOTOS_TOTAL_MB,
            'version_api'          => '1.0',
        ];
    }

    /** @return array<string, mixed> */
    public static function motivos(): array
    {
        return [
            'incidencias' => array_map(static fn (array $m, string $clave): array => [
                'clave' => $clave, 'etiqueta' => $m['etiqueta'],
            ], IncidenciasEntrega::MOTIVOS, array_keys(IncidenciasEntrega::MOTIVOS)),
            'intentos' => array_map(static fn (array $m, string $clave): array => [
                'clave' => $clave, 'etiqueta' => $m['etiqueta'],
            ], IntentosEntrega::MOTIVOS, array_keys(IntentosEntrega::MOTIVOS)),
        ];
    }
}
