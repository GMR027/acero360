<?php

namespace App;

/**
 * clases/AvisosAndroid.php — Notificaciones del Centro de seguimiento en la app Android.
 *
 * La app (AvisosAndroid.kt) consulta en segundo plano GET /app/android/avisos.json y muestra
 * cada aviso nuevo como notificación del teléfono. Sin sesión de PHP, se identifica con una
 * llave de SOLO LECTURA de avisos que el servidor emitió para esa instalación al activar las
 * notificaciones con sesión abierta. Las reglas de la llave (hash, vigencia, contraseña,
 * instalación) son las mismas que las de la huella: ver HuellaAndroid y la migración 055.
 */
class AvisosAndroid extends HuellaAndroid
{
    protected const TABLA = 'llaves_avisos_android';

    /** Máximo de avisos por respuesta: la app solo necesita los más importantes. */
    private const MAXIMO = 50;

    /**
     * Avisos sin leer del usuario, en el orden del Centro de seguimiento (prioridad primero).
     * Solo lo necesario para la notificación: clave + huella (identidad), textos y enlace.
     *
     * @return array{total:int, avisos:array<int, array<string, string>>}
     */
    public static function pendientes(int $usuarioId): array
    {
        $sinLeer = array_values(array_filter(Seguimiento::paraUsuario($usuarioId), static fn (array $a): bool => !$a['leida']));
        $avisos = [];
        foreach (array_slice($sinLeer, 0, self::MAXIMO) as $a) {
            $avisos[] = [
                'id'        => $a['clave'] . '|' . $a['huella'],
                'categoria' => Seguimiento::CATEGORIAS[$a['categoria']][0] ?? 'Seguimiento',
                'prioridad' => $a['prioridad'],
                'titulo'    => (string) $a['titulo'],
                'detalle'   => (string) ($a['detalle'] ?? ''),
                'enlace'    => self::enlaceSeguro((string) ($a['enlace'] ?? '')),
            ];
        }

        return ['total' => count($sinLeer), 'avisos' => $avisos];
    }

    /** Solo rutas internas del panel: la app abre el enlace dentro de su WebView. */
    private static function enlaceSeguro(string $enlace): string
    {
        return preg_match('#^/admin(/|\?|$)#', $enlace) && !str_contains($enlace, '//') ? $enlace : '/admin/seguimiento';
    }
}
