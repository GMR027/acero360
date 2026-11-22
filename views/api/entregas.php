<?php
/**
 * views/api/entregas.php — API JSON/PDF de la app Android «Acero Entregas».
 *
 * Todas las rutas /api/entregas/v1/* llegan aquí. La autenticación es por
 * Bearer token (tabla tokens_entregas), no por sesión ni CSRF. La app nunca
 * recibe precios, importes, IVA ni datos fiscales.
 */

use App\Archivos;
use App\Documentos;
use App\EntregasApp;

/** Responde JSON y termina. */
function apiResponder(array $datos, int $estado = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($estado);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(array $errores, int $estado = 422): void
{
    apiResponder(['ok' => false, 'error' => implode(' ', $errores), 'errores' => array_values($errores)], $estado);
}

/** Cuerpo JSON o formulario. */
function apiDatos(): array
{
    $tipo = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($tipo, 'application/json')) {
        $crudo = file_get_contents('php://input');
        $datos = json_decode((string) $crudo, true);
        return is_array($datos) ? $datos : [];
    }
    return $_POST;
}

function apiBinario(string $contenido, string $mime, string $nombre, bool $descarga = true): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre) ?: 'documento';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($descarga ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($contenido));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    echo $contenido;
    exit;
}

/** Ruta relativa dentro de /api/entregas/v1/. */
$ruta = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$prefijo = '/api/entregas/v1/';
$accion = str_starts_with($ruta, $prefijo) ? substr($ruta, strlen($prefijo)) : '';
$accion = trim($accion, '/');
$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($accion === 'login' && $metodo === 'POST') {
        $datos = apiDatos();
        $resultado = EntregasApp::login(
            (string) ($datos['email'] ?? ''),
            (string) ($datos['password'] ?? $datos['contrasena'] ?? ''),
            (string) ($datos['dispositivo'] ?? ''),
            (string) ($datos['device_id'] ?? '')
        );
        if ($resultado === null) {
            apiError(EntregasApp::getErrores(), 401);
        }
        apiResponder(['ok' => true] + $resultado);
    }

    if ($accion === 'version') {
        apiResponder([
            'ok'      => true,
            'version' => '1.0.0',
            'api'     => '1.0',
        ]);
    }

    $usuario = EntregasApp::autenticar();
    if ($usuario === null) {
        apiError(EntregasApp::getErrores(), 401);
    }

    if ($accion === 'logout' && $metodo === 'POST') {
        EntregasApp::logout(EntregasApp::tokenDeRequest());
        apiResponder(['ok' => true, 'mensaje' => 'Sesión cerrada.']);
    }

    if ($accion === 'yo' && $metodo === 'GET') {
        apiResponder(['ok' => true, 'usuario' => $usuario]);
    }

    if ($accion === 'config' && $metodo === 'GET') {
        apiResponder(['ok' => true] + EntregasApp::config());
    }

    if ($accion === 'motivos' && $metodo === 'GET') {
        apiResponder(['ok' => true] + EntregasApp::motivos());
    }

    if ($accion === 'pendientes' && $metodo === 'GET') {
        $datos = EntregasApp::pendientes([
            'q'       => (string) ($_GET['q'] ?? ''),
            'empresa' => (int) ($_GET['empresa'] ?? 0),
        ]);
        apiResponder(['ok' => true] + $datos);
    }

    if ($accion === 'venta' && $metodo === 'GET') {
        $venta = EntregasApp::venta((int) ($_GET['id'] ?? 0));
        if ($venta === null) {
            apiError(EntregasApp::getErrores(), 404);
        }
        apiResponder(['ok' => true] + $venta);
    }

    if ($accion === 'entrega' && $metodo === 'POST') {
        $datos = apiDatos();
        $fotos = Archivos::listaDeSubidas($_FILES['fotos'] ?? null);
        $meta = json_decode((string) ($datos['fotos_meta'] ?? '[]'), true);
        $resultado = EntregasApp::registrarEntrega((int) $usuario['id'], $datos, $fotos, is_array($meta) ? $meta : []);
        if ($resultado === null) {
            apiError(EntregasApp::getErrores(), 422);
        }
        apiResponder(['ok' => true, 'mensaje' => 'Entrega registrada.'] + $resultado, 201);
    }

    if ($accion === 'incidencia' && $metodo === 'POST') {
        $datos = apiDatos();
        $resultado = EntregasApp::registrarIncidencia((int) $usuario['id'], $datos);
        if ($resultado === null) {
            apiError(EntregasApp::getErrores(), 422);
        }
        apiResponder(['ok' => true, 'mensaje' => 'Incidencia registrada.'] + $resultado, 201);
    }

    if ($accion === 'intento' && $metodo === 'POST') {
        $datos = apiDatos();
        $fotos = Archivos::listaDeSubidas($_FILES['fotos'] ?? null);
        $meta = json_decode((string) ($datos['fotos_meta'] ?? '[]'), true);
        $resultado = EntregasApp::registrarIntento((int) $usuario['id'], $datos, $fotos, is_array($meta) ? $meta : []);
        if ($resultado === null) {
            apiError(EntregasApp::getErrores(), 422);
        }
        apiResponder(['ok' => true, 'mensaje' => 'Visita registrada.'] + $resultado, 201);
    }

    if ($accion === 'historial' && $metodo === 'GET') {
        $datos = EntregasApp::historial([
            'usuario' => (int) ($_GET['usuario'] ?? 0),
            'desde'   => (string) ($_GET['desde'] ?? ''),
            'hasta'   => (string) ($_GET['hasta'] ?? ''),
        ]);
        apiResponder(['ok' => true] + $datos);
    }

    if ($accion === 'foto' && $metodo === 'GET') {
        $foto = EntregasApp::foto(
            (int) ($_GET['id'] ?? 0),
            (string) ($_GET['tipo'] ?? 'entrega'),
            (int) ($_GET['w'] ?? 1600)
        );
        if ($foto === null) {
            apiError(['La foto no existe.'], 404);
        }
        apiBinario($foto['contenido'], $foto['mime'], 'foto.jpg', false);
    }

    if ($accion === 'pdf/preparacion' && $metodo === 'GET') {
        $rawVentas = $_GET['ventas'] ?? [];
        $ids = is_array($rawVentas)
            ? array_map('intval', $rawVentas)
            : array_map('intval', explode(',', (string) $rawVentas));
        $pdf = EntregasApp::pdfPreparacion($ids);
        if ($pdf === null) {
            apiError(EntregasApp::getErrores(), 422);
        }
        apiBinario($pdf, 'application/pdf', 'preparacion-' . date('Ymd-Hi') . '.pdf');
    }

    if ($accion === 'pdf/entrega' && $metodo === 'GET') {
        $pdf = EntregasApp::pdfEntrega((int) ($_GET['venta'] ?? $_GET['id'] ?? 0));
        if ($pdf === null) {
            apiError(EntregasApp::getErrores(), 422);
        }
        apiBinario($pdf, 'application/pdf', 'hoja-entrega-' . (int) ($_GET['venta'] ?? $_GET['id'] ?? 0) . '.pdf');
    }

    if ($accion === 'pdf/acta' && $metodo === 'GET') {
        $pdf = EntregasApp::pdfActa(
            (int) ($_GET['venta'] ?? $_GET['id'] ?? 0),
            (int) ($_GET['entrega'] ?? 0)
        );
        if ($pdf === null) {
            apiError(EntregasApp::getErrores(), 422);
        }
        apiBinario($pdf, 'application/pdf', 'acta-entrega-' . (int) ($_GET['venta'] ?? $_GET['id'] ?? 0) . '.pdf');
    }

    apiError(['La ruta de la API no existe.'], 404);
} catch (\Throwable $excepcion) {
    error_log('[API entregas] ' . $excepcion->getMessage() . ' @ ' . $excepcion->getFile() . ':' . $excepcion->getLine());
    apiError(['Ocurrió un error en el servidor. Inténtalo de nuevo.'], 500);
}
