<?php

namespace App;

/**
 * clases/PortalFotos.php — Fotos de entrega para el portal de clientes.
 *
 * El portal (sitio de Soluciones en Acero, otra aplicación sobre esta misma
 * base) NO puede leer el volumen public/image. Cuando un cliente con sesión
 * abre un pedido vendido, el portal verifica que la foto le pertenece y la
 * pide a este endpoint con una URL firmada:
 *
 *   GET /portal/entregas/foto?id=<entrega_fotos.id>&w=<ancho>&exp=<unix>&sig=<hmac>
 *
 *   sig = hash_hmac('sha256', "id|w|exp", PORTAL_FOTOS_SECRETO)
 *
 * Sin el secreto configurado (variable de entorno PORTAL_FOTOS_SECRETO, mínimo
 * 32 caracteres) el endpoint no existe (404). La firma vence en `exp` y no se
 * aceptan vigencias de más de VIGENCIA_MAX segundos hacia adelante.
 *
 * La imagen sale con la misma marca de agua que el panel (FotosEntrega::marcada).
 */
class PortalFotos
{
    /** Segundos máximos que puede durar una firma. */
    public const VIGENCIA_MAX = 600;

    public const ANCHO_MIN = 200;

    /** Secreto compartido con el portal, o null si la función está apagada. */
    public static function secreto(): ?string
    {
        $secreto = (string) getenv('PORTAL_FOTOS_SECRETO');
        return strlen($secreto) >= 32 ? $secreto : null;
    }

    public static function firma(int $id, int $ancho, int $expira, string $secreto): string
    {
        return hash_hmac('sha256', "{$id}|{$ancho}|{$expira}", $secreto);
    }

    /**
     * Comprueba la firma y la vigencia. Devuelve el ancho normalizado o null.
     */
    public static function validar(int $id, int $ancho, int $expira, string $firma, ?string $secreto, ?int $ahora = null): ?int
    {
        $ahora ??= time();

        if ($secreto === null || $id <= 0 || $firma === '') {
            return null;
        }
        if ($expira < $ahora || $expira > $ahora + self::VIGENCIA_MAX) {
            return null;
        }
        if (!hash_equals(self::firma($id, $ancho, $expira, $secreto), $firma)) {
            return null;
        }

        return max(self::ANCHO_MIN, min(FotosEntrega::ANCHO_MAX, $ancho));
    }

    /**
     * Contenido listo para enviar: ['contenido' => bytes, 'mime' => tipo] o null.
     * Con marca de agua; si el dibujo falla se entrega el original (igual que el panel).
     */
    public static function imagen(int $id, int $ancho): ?array
    {
        $foto = Entregas::fotoDeEntregaPorId($id);

        if (!$foto) {
            return null;
        }

        $marcada = FotosEntrega::marcada($foto, $ancho);
        if ($marcada !== null) {
            return $marcada;
        }

        $ruta    = Imagenes::CARPETA . '/' . basename((string) $foto['archivo']);
        $medidas = is_file($ruta) ? @getimagesize($ruta) : false;
        $tipo    = is_array($medidas) ? (string) ($medidas['mime'] ?? '') : '';
        $datos   = $tipo !== '' ? @file_get_contents($ruta) : false;

        return $datos === false || $tipo === '' ? null : ['contenido' => $datos, 'mime' => $tipo];
    }
}
