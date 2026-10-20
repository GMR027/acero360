<?php
/**
 * views/admin/seguimiento-campana.php — datos de la campana de avisos (JSON).
 *   GET  → { n: sin leer, avisos: [los 8 más importantes: sin leer primero] }
 *   POST → marca un aviso (clave) o todos (todas=1) como leídos; solo escribe en seguimiento_lecturas.
 * Los avisos se calculan en vivo con una clave estable: recargar la página nunca duplica una alerta.
 */

soloAdmin();

$usuarioId = (int) ($_SESSION['id'] ?? 0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // El encabezado propio impide que otro sitio dispare la acción con un formulario.
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'campana') {
        http_response_code(400);
        echo json_encode(['error' => 'Solicitud no válida.']);
        exit;
    }
    \App\Seguimiento::marcarLeida($usuarioId, !empty($_POST['todas']) ? null : (string) ($_POST['clave'] ?? ''));
}

$todos = \App\Seguimiento::paraUsuario($usuarioId);   // ya ordenados: urgente → informativa
usort($todos, static fn ($a, $b) => $a['leida'] <=> $b['leida']);   // estable: sin leer primero
$n = count(array_filter($todos, static fn ($a) => !$a['leida']));
$_SESSION['seguimiento_n'] = $n;
$_SESSION['seguimiento_t'] = time();

$avisos = [];
foreach (array_slice($todos, 0, 8) as $a) {
    [$etiqueta, $icono] = \App\Seguimiento::NIVELES[$a['prioridad']];
    $avisos[] = [
        'clave' => $a['clave'], 'titulo' => $a['titulo'], 'detalle' => $a['detalle'], 'enlace' => $a['enlace'],
        'abrir' => \App\Seguimiento::etiquetaAbrir($a['clave']), 'leida' => $a['leida'],
        'prioridad' => $a['prioridad'], 'nivel' => $etiqueta, 'icono' => $icono,
    ];
}

echo json_encode(['n' => $n, 'avisos' => $avisos], JSON_UNESCAPED_UNICODE);
