<?php

/**
 * Router — enrutador simple de peticiones GET/POST.
 *
 * Uso:
 *   $router->get('/admin/usuarios', 'views/admin/usuarios/index.php', 'soloAdmin');
 *   $router->post('/admin/usuarios', 'views/admin/usuarios/index.php', 'soloAdmin');
 *
 * El tercer parámetro ($middleware) es el nombre de una función PHP definida
 * en includes/funciones.php. Se ejecuta ANTES de incluir la vista y, si la
 * función redirige (header + exit), la petición se corta ahí mismo.
 *
 * Este router es una versión endurecida del usado en canastillasWep:
 *  - normaliza barras finales
 *  - descarta la query string correctamente
 *  - devuelve una vista 404 en lugar de un echo suelto
 */
class Router
{
    /** @var array<string, array<string, array{archivo: string, middleware: string}>> Rutas indexadas por método y URL. */
    protected array $routes = [];

    /** Registra una ruta GET. */
    public function get(string $url, string $archivo, string $middleware = ''): void
    {
        $this->routes['GET'][$this->normalizar($url)] = [
            'archivo'    => $archivo,
            'middleware' => $middleware,
        ];
    }

    /** Registra una ruta POST. */
    public function post(string $url, string $archivo, string $middleware = ''): void
    {
        $this->routes['POST'][$this->normalizar($url)] = [
            'archivo'    => $archivo,
            'middleware' => $middleware,
        ];
    }

    /**
     * Comprueba la petición contra las rutas registradas.
     *
     * @return string|null Archivo de la vista a incluir, o null si no hay vista.
     */
    public function comprobarRutas(): ?string
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $ruta   = $this->normalizar($this->rutaActual());

        // Reintenta como POST si el servidor reescribe los formularios (failsafe).
        $coincidencia = $this->routes[$metodo][$ruta] ?? null;

        if (!$coincidencia) {
            // Endurecimiento de la protección de rutas: una URL bajo /admin
            // que no exista NO debe mostrarse a un visitante sin sesión.
            if (strpos($ruta, '/admin') === 0 && function_exists('soloAdmin')) {
                soloAdmin();
            }

            http_response_code(404);
            return 'views/404.php';
        }

        // Ejecuta el middleware de protección de rutas (si está definido).
        if (!empty($coincidencia['middleware']) && function_exists($coincidencia['middleware'])) {
            $coincidencia['middleware']();
        }

        return $coincidencia['archivo'];
    }

    /** Devuelve el path actual de la petición sin query string. */
    protected function rutaActual(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $ruta = parse_url($uri, PHP_URL_PATH);
        return is_string($ruta) ? $ruta : '/';
    }

    /**
     * Normaliza una ruta: minúsculas no se fuerzan (las URLs son sensibles a
     * mayúsculas), pero sí se elimina la barra final salvo que sea la raíz.
     */
    protected function normalizar(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '/') {
            return '/';
        }
        return rtrim($url, '/');
    }
}
