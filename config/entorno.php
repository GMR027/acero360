<?php

/**
 * config/entorno.php — Carga el archivo `.env` de la raíz del proyecto.
 *
 * PHP no lee `.env` por sí solo, así que este cargador simple pone los
 * valores en el entorno del proceso con putenv() antes de que cualquier
 * configuración los consulte con getenv().
 *
 * Reglas:
 *   · una línea = CLAVE=valor; las líneas que empiezan con # son comentarios
 *   · las comillas simples o dobles alrededor del valor se quitan
 *   · NO se sobrescribe una variable que ya exista en el entorno real
 *     (así que en un servidor con variables de entorno definidas, el .env
 *     queda como respaldo y no como fuente de verdad)
 *
 * Es idempotente: se puede incluir cuantas veces se quiera.
 */
function cargarEntorno(): void
{
    static $cargado = false;

    if ($cargado) {
        return;
    }
    $cargado = true;

    $ruta = dirname(__DIR__) . '/.env';

    if (!is_file($ruta) || !is_readable($ruta)) {
        return;
    }

    $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lineas === false) {
        return;
    }

    foreach ($lineas as $linea) {
        $linea = trim($linea);

        if ($linea === '' || $linea[0] === '#') {
            continue;
        }

        $igual = strpos($linea, '=');
        if ($igual === false) {
            continue;
        }

        $clave = trim(substr($linea, 0, $igual));
        $valor = trim(substr($linea, $igual + 1));

        if ($clave === '') {
            continue;
        }

        // Quita comillas simples o dobles envolventes.
        $primerCaracter  = substr($valor, 0, 1);
        $ultimoCaracter  = substr($valor, -1);
        if (strlen($valor) > 1 && $primerCaracter === $ultimoCaracter
            && ($primerCaracter === '"' || $primerCaracter === "'")) {
            $valor = substr($valor, 1, -1);
        }

        // El entorno real manda sobre el archivo.
        if (getenv($clave) !== false) {
            continue;
        }

        putenv($clave . '=' . $valor);
        $_ENV[$clave] = $valor;
        $_SERVER[$clave] = $valor;
    }
}

cargarEntorno();
