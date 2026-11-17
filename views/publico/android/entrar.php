<?php
/**
 * views/publico/android/entrar.php — GET /app/android/entrar?v=1.2.3&id=<uuid>
 * Primera página que abre la app Android: recuerda la versión instalada en una cookie y
 * continúa al panel (o al inicio de sesión, si no hay sesión).
 */

sesionActiva();
\App\AppAndroid::recordarApp((string) ($_GET['v'] ?? ''), strtolower((string) ($_GET['id'] ?? '')));

$destino = !empty($_SESSION['login']) ? '/admin' : '/login';
header('Location: ' . $destino);
exit;
