<?php
/**
 * views/admin/contabilidad/gastos/masivo.php — acciones sobre varios gastos a la vez (solo POST).
 *
 * Ruta protegida por el middleware "soloAdmin". Hoy sólo «eliminar»: quita los
 * gastos marcados en la lista y, con ellos, sus documentos adjuntos (filas y
 * archivos). Cada gasto se valida por su cuenta —motivo, periodo cerrado, que
 * siga ahí— y al final se informa lo que sí y lo que no se pudo, de modo que un
 * gasto cerrado no impide eliminar los demás.
 *
 * Se vuelve a la lista con los mismos filtros con los que se marcaron.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

/** Filtros de la lista, sólo los conocidos y con su formato (para volver igual). */
$filtros = [];
if (preg_match('/^\d{1,5}$/', (string) ($_POST['anio'] ?? '')))   { $filtros['anio'] = (string) $_POST['anio']; }
if (preg_match('/^\d{1,2}$/', (string) ($_POST['mes'] ?? '')))    { $filtros['mes'] = (string) $_POST['mes']; }
if (preg_match('/^\d{1,5}$/', (string) ($_POST['empresa'] ?? ''))) { $filtros['empresa'] = (string) $_POST['empresa']; }
if (isset($filtros['anio']) && ($filtros['anio'] === '0' || $filtros['anio'] === '')) {
    unset($filtros['anio']);
}
$q = trim((string) ($_POST['q'] ?? ''));
if ($q !== '') { $filtros['q'] = mb_substr($q, 0, 80); }
if (in_array($_POST['pago'] ?? '', ['pendiente', 'pagado'], true)) { $filtros['pago'] = (string) $_POST['pago']; }
if (($_POST['estado'] ?? '') === 'cancelado') { $filtros['estado'] = 'cancelado'; }
if (($_POST['sin_comprobante'] ?? '') === '1') { $filtros['sin_comprobante'] = '1'; }
foreach (['desde', 'hasta'] as $rango) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST[$rango] ?? ''))) {
        $filtros[$rango] = (string) $_POST[$rango];
    }
}
if (($_POST['todos'] ?? '') === '1') { $filtros['todos'] = '1'; }

$destino = '/admin/contabilidad/gastos' . ($filtros ? '?' . http_build_query($filtros) : '');

if (($_POST['accion'] ?? '') !== 'eliminar') {
    redirigir($destino, 'error', 'Esa acción sobre los gastos seleccionados no existe.');
}

$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['gastos'] ?? [])))));
$motivo = trim((string) ($_POST['motivo'] ?? ''));
$usuario = (int) ($_SESSION['id'] ?? 0);

if (!$ids) {
    redirigir($destino, 'error', 'Marca al menos un gasto de la lista.');
}
if (count($ids) > 200) {
    redirigir($destino, 'error', 'Sólo se pueden eliminar hasta 200 gastos por vez: márcalos por partes.');
}
if (mb_strlen($motivo) < 5) {
    redirigir($destino, 'error', 'Escribe el motivo de la eliminación (mínimo 5 caracteres).');
}

$eliminados = 0;
$documentos = 0;
$errores = [];

foreach ($ids as $id) {
    if (\App\Gastos::eliminar($id, $usuario, $motivo)) {
        $eliminados++;
        $documentos += \App\Gastos::$documentosEliminados;
        continue;
    }
    $errores[] = 'Gasto #' . $id . ': ' . (\App\Gastos::getErrores()[0] ?? 'no se pudo eliminar.');
}

if ($eliminados === 0) {
    $mensaje = 'No se eliminó ningún gasto.';
} else {
    $mensaje = ($eliminados === 1 ? 'Se eliminó 1 gasto' : 'Se eliminaron ' . $eliminados . ' gastos')
        . ' con ' . $documentos . ($documentos === 1 ? ' documento adjunto' : ' documentos adjuntos') . '.';
}
if ($errores) {
    $mostrados = array_slice($errores, 0, 3);
    $mensaje .= ' ' . implode(' · ', $mostrados)
        . (count($errores) > 3 ? ' (y ' . (count($errores) - 3) . ' más).' : '');
}

redirigir($destino, $errores ? ($eliminados ? 'advertencia' : 'error') : 'exito', $mensaje);
