<?php

/**
 * includes/herramientas.php — Puente de compatibilidad para las herramientas
 * portadas de ProjectWeb (Redactor, SheetFlow y Editor).
 *
 * Las clases viven en sus propios espacios de nombres:
 *   Model\*   -> /models
 *   Classes\* -> /clases/herramientas
 * para no mezclarlas con las clases de negocio de Acero 360 (App\*).
 *
 * Aquí sólo están los ayudantes que usaban esas clases y las vistas
 * originales: escapado, fechas, descargas y respuestas JSON.
 */

/** Escapa texto para HTML (alias corto usado por las vistas portadas). */
function s($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

/** Traducción: la aplicación es sólo en español, así que devuelve el texto tal cual. */
function t(string $texto): string
{
    return $texto;
}

/** Fecha legible con el formato indicado (acepta fechas MySQL y timestamps). */
function formatear_fecha($fecha, string $formato = 'd/m/Y'): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '' || $fecha === '0000-00-00' || $fecha === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($fecha);
    return $ts === false ? $fecha : date($formato, $ts);
}

/** Nombre de archivo seguro para las descargas. */
function slug_archivo(string $texto): string
{
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
    $texto = preg_replace('/[^A-Za-z0-9]+/', '_', $texto) ?? '';
    $texto = trim($texto, '_');
    return $texto !== '' ? mb_substr($texto, 0, 80) : 'archivo';
}

/** Respuesta JSON (nunca pasa por el inyector de CSRF del HTML). */
function json_respuesta(array $datos, int $codigo = 200): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Envía un archivo generado en memoria como descarga (o lo abre en el navegador si $inline). */
function enviar_descarga(string $datos, string $tipo, string $nombre, bool $inline = false): void
{
    if (!headers_sent()) {
        header('Content-Type: ' . $tipo);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $nombre) . '"');
        header('Content-Length: ' . strlen($datos));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $datos;
    exit;
}

/** Todos los administradores de Acero 360 pueden descargar. */
function puede_descargar(): bool
{
    return true;
}

/**
 * Las herramientas son compartidas, como Notas: cualquier administrador
 * con sesión puede crear, editar y borrar. Se conserva la firma original
 * ($proyecto) para no tocar las vistas portadas.
 */
function puede_gestionar_proyecto($proyecto = null): bool
{
    return true;
}

/** ¿Hay sesión iniciada? (equivale al is_auth() de ProjectWeb). */
function is_auth(): bool
{
    return !empty($_SESSION['login']);
}

/** Idioma de la interfaz. */
function idioma_actual(): string
{
    return 'es';
}

/** La aplicación no tiene modo demo. */
function es_demo(): bool
{
    return false;
}
