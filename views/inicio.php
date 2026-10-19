<?php
/**
 * views/inicio.php — Ruta raíz (/).
 *
 * No hay sitio público en este proyecto: la raíz simplemente deriva al
 * panel de administración si ya hay sesión, o a la pantalla de acceso.
 */

sesionActiva();

if (isset($_SESSION['login']) && $_SESSION['login'] === true) {
    header('Location: /admin');
} else {
    header('Location: /login');
}
exit;
