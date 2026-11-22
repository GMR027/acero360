<?php

namespace App;

/**
 * IntentosEntrega — visitas en las que NO se pudo entregar un pedido.
 *
 * No es una incidencia de producto: no mueve inventario, no cambia importes
 * ni exige corregir factura. Sirve para que el repartidor deje constancia de
 * que estuvo en el domicilio y no pudo entregar (cliente ausente, dirección
 * incorrecta, rechazo, sin acceso u otro motivo).
 *
 * La venta sigue pendiente de entrega; la oficina puede reprogramarla.
 * Las fotos son opcionales y conservan la fecha/hora y el GPS capturados por
 * la app antes de comprimirlas.
 */
class IntentosEntrega
{
    /** @var \mysqli */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    /** Id del último intento creado (o encontrado por token). */
    public static int $ultimoId = 0;

    /** @var array<string, array{etiqueta: string, icono: string}> */
    public const MOTIVOS = [
        'cliente_ausente'      => ['etiqueta' => 'Cliente ausente',              'icono' => 'person_off'],
        'direccion_incorrecta' => ['etiqueta' => 'Dirección incorrecta',         'icono' => 'wrong_location'],
        'rechazo_cliente'      => ['etiqueta' => 'El cliente rechazó el pedido', 'icono' => 'block'],
        'sin_acceso'           => ['etiqueta' => 'Sin acceso al lugar',          'icono' => 'lock'],
        'otro'                 => ['etiqueta' => 'Otro motivo',                  'icono' => 'help'],
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

    /**
     * Registra una visita fallida. El token de la app evita duplicados si se
     * reintenta el envío después de perder la respuesta del servidor.
     *
     * @param array<int, array> $fotos entradas de $_FILES ya separadas.
     * @param array<int, array> $metadatosFotos fecha/GPS leídos por la app.
     */
    public static function registrar(
        int $ventaId,
        string $motivo,
        string $notas,
        string $ubicacion,
        int $usuarioId,
        string $token,
        array $fotos = [],
        array $metadatosFotos = [],
        string $origen = 'app'
    ): bool {
        self::$errores = [];
        self::$ultimoId = 0;

        if (!isset(self::MOTIVOS[$motivo])) {
            self::$errores[] = 'El motivo de la visita no es válido.';
            return false;
        }
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            self::$errores[] = 'El token de la visita no es válido.';
            return false;
        }
        $notas = mb_substr(trim($notas), 0, 500);
        $fotos = array_values(array_filter($fotos, 'is_array'));
        $metadatosFotos = array_values(array_filter($metadatosFotos, 'is_array'));

        $normalizada = FotosEntrega::normalizarUbicacion($ubicacion);
        if ($normalizada['error'] !== '') {
            self::$errores[] = $normalizada['error'];
            return false;
        }
        $ubicacion = $normalizada['valor'];
        $coordenadas = $ubicacion !== '' ? FotosEntrega::coordenadasDeUbicacion($ubicacion) : null;

        if ($motivo === 'otro' && $notas === '') {
            self::$errores[] = 'Describe brevemente el motivo de la visita.';
            return false;
        }
        if (count($fotos) > Entregas::MAX_FOTOS) {
            self::$errores[] = 'Se admiten como máximo ' . Entregas::MAX_FOTOS . ' fotos por visita.';
            return false;
        }

        $repetido = self::$db->prepare('SELECT id, venta_id FROM intentos_entrega WHERE token = ? LIMIT 1');
        $repetido->bind_param('s', $token);
        $repetido->execute();
        $fila = $repetido->get_result()->fetch_assoc();
        $repetido->close();
        if ($fila) {
            if ((int) $fila['venta_id'] !== $ventaId) {
                self::$errores[] = 'El token de la visita ya se usó en otra venta.';
                return false;
            }
            self::$ultimoId = (int) $fila['id'];
            return true;
        }

        $archivos = [];
        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT folio, estado, entrega_cerrada_en FROM ventas WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $ventaId);
            $bloqueo->execute();
            $venta = $bloqueo->get_result()->fetch_assoc();
            $bloqueo->close();

            if (!$venta || $venta['estado'] !== 'registrada' || $venta['entrega_cerrada_en'] !== null) {
                self::$db->rollBack();
                self::$errores[] = 'La venta ya no está pendiente de entrega.';
                return false;
            }

            $crear = self::$db->prepare(
                'INSERT INTO intentos_entrega
                    (venta_id, fecha, motivo, notas, ubicacion_maps, ubicacion_lat, ubicacion_lon, token, usuario_id)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
            );
            $notasSql = $notas !== '' ? $notas : null;
            $ubicacionSql = $ubicacion !== '' ? $ubicacion : null;
            $lat = $coordenadas['lat'] ?? null;
            $lon = $coordenadas['lon'] ?? null;
            $crear->bind_param('isssddsi', $ventaId, $motivo, $notasSql, $ubicacionSql, $lat, $lon, $token, $usuarioId);
            $crear->execute();
            $intentoId = (int) self::$db->insert_id;
            $crear->close();

            if ($fotos) {
                self::guardarFotos($intentoId, $fotos, $usuarioId, $archivos, $metadatosFotos);
            }

            Auditoria::registrar('venta', $ventaId, 'intento_entrega', $usuarioId, null, [
                'intento_id' => $intentoId,
                'motivo'     => $motivo,
                'fotos'      => count($archivos),
                'origen'     => $origen === 'app' ? 'app' : 'web',
            ]);

            self::$db->commit();
            self::$ultimoId = $intentoId;
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            self::borrarFotos($archivos);
            error_log('[IntentosEntrega::registrar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible registrar la visita.';
            return false;
        } catch (\RuntimeException $rechazo) {
            self::$db->rollBack();
            self::borrarFotos($archivos);
            self::$errores[] = $rechazo->getMessage();
            return false;
        }
    }

    /** @param string[] $guardadas */
    private static function guardarFotos(int $intentoId, array $fotos, int $usuarioId, array &$guardadas, array $metadatosFotos): void
    {
        $insertar = self::$db->prepare(
            'INSERT INTO intento_fotos (intento_id, archivo, tomada_en, latitud, longitud, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($fotos as $indice => $foto) {
            if (empty($foto['tmp_name'])) {
                continue;
            }
            $metadatos = FotosEntrega::combinarMetadatos(
                FotosEntrega::metadatos($foto),
                FotosEntrega::metadatosDeFormulario($metadatosFotos[$indice] ?? null)
            );
            $nombre = Imagenes::subir($foto, 'intento', 'informe', Imagenes::TAMANO_MAXIMO_ENTREGA);
            if ($nombre === null) {
                $insertar->close();
                throw new \RuntimeException(Imagenes::ultimoError() ?: 'No fue posible guardar una foto de la visita.');
            }
            $guardadas[] = $nombre;
            $tomada = $metadatos['tomada_en'];
            $lat = $metadatos['latitud'];
            $lon = $metadatos['longitud'];
            $insertar->bind_param('issddi', $intentoId, $nombre, $tomada, $lat, $lon, $usuarioId);
            $insertar->execute();
        }
        $insertar->close();
    }

    private static function borrarFotos(array $archivos): void
    {
        foreach ($archivos as $archivo) {
            Imagenes::eliminar($archivo);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function deVenta(int $ventaId): array
    {
        $consulta = self::$db->prepare(
            'SELECT i.*, u.nombre AS usuario_nombre
               FROM intentos_entrega i
               LEFT JOIN usuarios u ON u.id = i.usuario_id
              WHERE i.venta_id = ? ORDER BY i.fecha DESC, i.id DESC'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $intentos = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        if ($intentos) {
            $ids = array_column($intentos, 'id');
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $fotos = self::$db->prepare(
                "SELECT * FROM intento_fotos WHERE intento_id IN ($marcadores) ORDER BY id"
            );
            $fotos->bind_param(str_repeat('i', count($ids)), ...$ids);
            $fotos->execute();
            $porIntento = [];
            foreach ($fotos->get_result() as $foto) {
                $porIntento[(int) $foto['intento_id']][] = $foto;
            }
            $fotos->close();
            foreach ($intentos as &$intento) {
                $intento['fotos'] = $porIntento[(int) $intento['id']] ?? [];
            }
            unset($intento);
        }

        return $intentos;
    }
}
