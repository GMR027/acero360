<?php

/**
 * Conexión a la base de datos MySQL.
 *
 * Los datos se leen de variables de entorno para no tener credenciales
 * fijas en el código. Si no existen, se usan los valores por defecto
 * de un entorno local de desarrollo.
 *
 *   DB_HOST     localhost
 *   DB_USER     root
 *   DB_PASSWORD (vacío)
 *   DB_NAME     cotizaciones
 */
function database(): mysqli
{
    // Asegura que las variables de .env estén disponibles para getenv().
    require_once __DIR__ . '/entorno.php';

    $host     = getenv('DB_HOST')     ?: 'localhost';
    $user     = getenv('DB_USER')     ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $name     = getenv('DB_NAME')     ?: 'cotizaciones';

    // Charset utf8mb4 para soportar acentos y signos españoles sin problemas.
    $db = new mysqli($host, $user, $password, $name, 3306);
    $db->set_charset('utf8mb4');

    if ($db->connect_error) {
        // Nunca se filtra el detalle del error al usuario final.
        http_response_code(500);
        echo 'Error al conectar con la base de datos. Contacta al administrador.';
        exit;
    }

    return $db;
}
