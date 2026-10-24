<?php

/**
 * Funciones auxiliares compartidas por toda la aplicación.
 *
 * Contiene:
 *  - Rutas y carpetas de trabajo
 *  - Renderizado de plantillas (template)
 *  - Escapado de salida y validación de IDs
 *  - Protección de rutas (sesión / rol / registro)
 *  - Sistema de alertas visuales tipo "toast" (sin alert() de JavaScript)
 *  - Paginación de listados
 */

/* ---------------------------------------------------------------------------
 * 1. CONSTANTES DE RUTAS
 * ------------------------------------------------------------------------- */

define('TEMPLATES_URL',   __DIR__ . '/../templates');
define('VIEWS_URL',       __DIR__ . '/../views');
define('CARPETA_IMAGEN',  __DIR__ . '/../public/image/');
define('RUTA_IMAGEN_URL', '/public/image/');

/* ---------------------------------------------------------------------------
 * 2. PLANTILLAS
 * ------------------------------------------------------------------------- */

/**
 * Incluye una plantilla de la carpeta templates/ pasándole datos asociativos.
 *
 * template('header', ['titulo' => 'Clientes']);
 */
function template(string $nombre, array $datos = []): void
{
    extract($datos); // Crea una variable por cada clave del arreglo.
    include TEMPLATES_URL . "/" . $nombre . '.php';
}

/**
 * Muestra la página 404 (views/404.php) y detiene la petición.
 *
 * Se usa cuando la ruta SÍ existe pero el registro que se pedía no
 * (p. ej. /admin/clientes/detalle?id=999): el Router no puede encargarse.
 */
function paginaNoEncontrada(): void
{
    http_response_code(404);
    include VIEWS_URL . '/404.php';
    exit;
}

/* ---------------------------------------------------------------------------
 * 3. SEGURIDAD Y VALIDACIÓN
 * ------------------------------------------------------------------------- */

/**
 * Escapa cualquier valor que se vaya a imprimir en HTML.
 * Evita que se inyecte código (XSS) desde datos guardados en la BD.
 */
function escaparValores($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

/**
 * Valida que el parámetro "id" de la URL sea un entero positivo.
 * Si no lo es, redirige a la ruta indicada (por defecto /admin).
 */
function validarID($id, string $ruta = '/admin')
{
    $id = filter_var($id, FILTER_VALIDATE_INT);

    if (!$id || $id < 1) {
        header('Location: ' . $ruta);
        exit;
    }

    return $id;
}

/** Inicia la sesión de PHP si aún no está activa. */
function sesionActiva(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // La cookie de sesión no la lee JavaScript y no viaja desde otros sitios.
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => conexionSegura(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** True cuando la petición llegó por HTTPS (directo o detrás del ingress del clúster). */
function conexionSegura(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/* ---------------------------------------------------------------------------
 * 3.1 PROTECCIÓN CONTRA PETICIONES FALSIFICADAS (CSRF) Y CABECERAS
 * ------------------------------------------------------------------------- */

/** Llave de la sesión: viaja en cada formulario POST y en las llamadas fetch. */
function tokenCsrf(): string
{
    sesionActiva();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Rechaza todo POST que no traiga la llave de la sesión (campo _csrf o cabecera
 * X-CSRF-Token). Así, una página ajena no puede mandar órdenes con tu sesión.
 */
function verificarCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    sesionActiva();
    $enviado = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $esperado = (string) ($_SESSION['csrf'] ?? '');

    if ($esperado !== '' && $enviado !== '' && hash_equals($esperado, $enviado)) {
        // Cada formulario se puede enviar una sola vez: evita ventas, gastos o pagos duplicados
        // por doble clic, por recargar la página o por volver atrás y reenviar.
        $forma = (string) ($_POST['_forma'] ?? '');
        if ($forma === '' || isset($_SESSION['formas'][$forma])) {
            unset($_SESSION['formas'][$forma]);
            return;
        }

        $destino = !empty($_SESSION['login']) ? '/admin' : '/login';
        redirigir($destino, 'advertencia', 'Esa operación ya se había enviado. Revisa el listado antes de repetirla, por si ya quedó registrada.');
    }

    error_log('[CSRF] POST rechazado en ' . ($_SERVER['REQUEST_URI'] ?? ''));
    if (!empty($_SESSION['login'])) {
        redirigir('/admin', 'error', 'La página caducó o la petición no es válida. Vuelve a abrir el formulario e inténtalo de nuevo.');
    }
    redirigir('/login', 'advertencia', 'La página caducó. Vuelve a intentarlo.');
}

/** Agrega el campo _csrf a cada formulario POST de la página que se va a enviar. */
function inyectarCsrf(string $html): string
{
    if (stripos($html, '<form') === false) {
        return $html;
    }

    foreach (headers_list() as $cabecera) {
        if (stripos($cabecera, 'content-type:') === 0 && stripos($cabecera, 'text/html') === false) {
            return $html;
        }
    }

    $csrf = tokenCsrf();

    return preg_replace_callback(
        '/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>/i',
        static function (array $m) use ($csrf): string {
            $forma = bin2hex(random_bytes(8));
            $_SESSION['formas'][$forma] = time();
            if (count($_SESSION['formas']) > 400) {
                $_SESSION['formas'] = array_slice($_SESSION['formas'], -400, null, true);
            }
            return $m[0] . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="_forma" value="' . $forma . '">';
        },
        $html
    ) ?? $html;
}

/** Cabeceras de seguridad de toda respuesta de la aplicación. */
function cabecerasSeguridad(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; "
        . "img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; frame-src 'self'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

    if (conexionSegura()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/* ---------------------------------------------------------------------------
 * 4. PROTECCIÓN DE RUTAS (middlewares del Router)
 * ------------------------------------------------------------------------- */

/**
 * Exige haber iniciado sesión. Si no hay sesión, redirige a /login.
 * Devuelve true cuando la sesión es válida.
 */
function iniciarSesion(): bool
{
    sesionActiva();

    if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
        header('Location: /login');
        exit;
    }

    return true;
}

/**
 * Middleware principal del panel de administración.
 * Requiere sesión iniciada Y rol administrador (1).
 */
function soloAdmin(): void
{
    iniciarSesion();

    if ((int) ($_SESSION['rol'] ?? 0) !== 1 || !sesionSigueVigente()) {
        header('Location: /login');
        exit;
    }
}

/**
 * Comprueba en la base que quien tiene la sesión abierta siga existiendo, siga
 * siendo administrador y no haya cambiado su contraseña desde otro lugar. Si algo
 * de eso cambió, la sesión se cierra en la siguiente petición.
 */
function sesionSigueVigente(): bool
{
    global $db;
    static $revisada = null;

    if ($revisada !== null) {
        return $revisada;
    }
    if (!isset($db) || !($db instanceof mysqli)) {
        return $revisada = true;
    }

    $id = (int) ($_SESSION['id'] ?? 0);
    $consulta = $db->prepare('SELECT rol, contrasena FROM usuarios WHERE id = ? LIMIT 1');
    $consulta->bind_param('i', $id);
    $consulta->execute();
    $usuario = $consulta->get_result()->fetch_assoc();
    $consulta->close();

    $huella = $usuario ? hash('sha256', (string) $usuario['contrasena']) : '';

    if (!$usuario || (int) $usuario['rol'] !== 1) {
        $vigente = false;
    } elseif (!isset($_SESSION['huella'])) {
        $_SESSION['huella'] = $huella; // sesiones abiertas antes de esta mejora
        $vigente = true;
    } else {
        $vigente = hash_equals((string) $_SESSION['huella'], $huella);
    }

    if (!$vigente) {
        $_SESSION = [];
        session_destroy();
        sesionActiva();
        agregarAlerta('advertencia', 'Tu sesión se cerró porque tu acceso cambió. Inicia sesión de nuevo.');
    }

    return $revisada = $vigente;
}

/**
 * Middleware de la ruta de registro.
 *
 * El registro está DESHABILITADO POR DEFECTO porque, según OPENCODE.md,
 * "los usuarios que se registren serán admin": dejarlo público permitiría
 * que cualquier visitante creara un administrador.
 *
 * Se habilita definiendo la variable de entorno:  REGISTRO_ABIERTO=1
 */
function soloRegistroAbierto(): void
{
    $abierto = getenv('REGISTRO_ABIERTO');

    if ($abierto !== '1') {
        sesionActiva();
        agregarAlerta('advertencia', 'El registro de usuarios está deshabilitado. Contacta al administrador.');
        header('Location: /login');
        exit;
    }
}

/* ---------------------------------------------------------------------------
 * 5. ALERTAS VISUALES (sustituyen a alert / confirm / prompt)
 * ------------------------------------------------------------------------- */

/**
 * Guarda una alerta en sesión para que sea mostrada en la próxima carga.
 * Se usa junto con redirigir() para dar retroalimentación tras un POST.
 *
 * Tipos válidos: 'exito' | 'error' | 'advertencia' | 'info'
 */
function agregarAlerta(string $tipo, string $texto): void
{
    sesionActiva();
    $_SESSION['alertas'][] = [
        'tipo'  => $tipo,
        'texto' => $texto,
    ];
}

/**
 * Devuelve las alertas pendientes y las limpia (solo se leen una vez).
 *
 * @return array<int, array{tipo: string, texto: string}>
 */
function obtenerAlertas(): array
{
    sesionActiva();

    $alertas = $_SESSION['alertas'] ?? [];
    unset($_SESSION['alertas']);

    return $alertas;
}

/**
 * Redirige a otra ruta cerrando la petición actual.
 * El primer parámetro es la ruta; el segundo y tercero son la alerta opcional.
 */
function redirigir(string $ruta, string $tipo = '', string $texto = ''): void
{
    if ($tipo !== '' && $texto !== '') {
        agregarAlerta($tipo, $texto);
    }

    header('Location: ' . $ruta);
    exit;
}

/* ---------------------------------------------------------------------------
 * 6. PAGINACIÓN
 * ------------------------------------------------------------------------- */

/**
 * Calcula los parámetros de paginación de un listado.
 *
 * @param int    $registrosPorPagina Cuántos registros caben por página.
 * @param int    $totalRegistros     Total de registros existentes.
 * @param string $parametro          Nombre del parámetro GET (por defecto "pagina").
 *
 * @return array{limite: int, offset: int, paginaActual: int, totalPaginas: int}
 */
function paginacion(int $registrosPorPagina, int $totalRegistros, string $parametro = 'pagina'): array
{
    $paginaActual = isset($_GET[$parametro]) ? max(1, (int) $_GET[$parametro]) : 1;
    $totalPaginas = (int) ceil($totalRegistros / max(1, $registrosPorPagina));

    if ($totalPaginas > 0 && $paginaActual > $totalPaginas) {
        $paginaActual = $totalPaginas;
    }

    return [
        'limite'       => $registrosPorPagina,
        'offset'       => ($paginaActual - 1) * $registrosPorPagina,
        'paginaActual' => $paginaActual,
        'totalPaginas' => $totalPaginas,
    ];
}

/* ---------------------------------------------------------------------------
 * 7. CONSULTAS SEGUROS PARA EL PANEL
 * ------------------------------------------------------------------------- */

/**
 * Comprueba si una tabla existe en la base de datos activa.
 * Evita que el panel falle mientras se están creando los módulos.
 */
function tablaExiste(mysqli $db, string $tabla): bool
{
    $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    if ($tabla === '') {
        return false;
    }

    $consulta = $db->query(
        "SELECT COUNT(*) AS total FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = '$tabla'"
    );

    if (!$consulta) {
        return false;
    }

    $fila = $consulta->fetch_assoc();
    $consulta->free();

    return (int) ($fila['total'] ?? 0) > 0;
}

/**
 * Devuelve el número de filas de una tabla, o 0 si la tabla aún no existe.
 */
function contarRegistros(mysqli $db, string $tabla): int
{
    if (!tablaExiste($db, $tabla)) {
        return 0;
    }

    $resultado = $db->query("SELECT COUNT(*) AS total FROM `$tabla`");
    if (!$resultado) {
        return 0;
    }

    $fila = $resultado->fetch_assoc();
    $resultado->free();

    return (int) ($fila['total'] ?? 0);
}

/* ---------------------------------------------------------------------------
 * 8. FORMATO
 * ------------------------------------------------------------------------- */

/** Formatea un monto como moneda mexicana: $1,234.50 */
function formatoMoneda($monto): string
{
    $monto = (float) $monto;
    return ($monto < 0 ? '-' : '') . '$' . number_format(abs($monto), 2);
}

/** Etiqueta de estado de cotización con su clase CSS asociada. */
function etiquetaEstado(string $estado): array
{
    $mapa = [
        'pendiente' => ['texto' => 'Pendiente', 'clase' => 'badge--pendiente'],
        'vendido'   => ['texto' => 'Vendido',   'clase' => 'badge--aprobado'],
        'cancelado' => ['texto' => 'Cancelado', 'clase' => 'badge--error'],
    ];

    return $mapa[$estado] ?? ['texto' => $estado, 'clase' => 'badge--neutro'];
}

/* ---------------------------------------------------------------------------
 * 9. IMPORTE CON LETRA (para el PDF de la cotización)
 * ------------------------------------------------------------------------- */

/**
 * Escribe un monto en letras, como se exige en los documentos fiscales.
 *
 *   123714 → "Ciento veintitrés mil setecientos catorce pesos 00/100 M.N."
 *
 * El texto se devuelve tal cual (sin capitalizar) para que cada vista
 * decida cómo presentarlo.
 */
function numeroALetras($monto): string
{
    $unidades = [
        '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho',
        'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince',
        'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
        'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco',
        'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];
    $decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta',
        'setenta', 'ochenta', 'noventa'];
    $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos',
        'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    /** Escrito de un número de 1 a 999. */
    $grupo = static function (int $n) use ($unidades, $decenas, $centenas): string {
        if ($n === 0) {
            return '';
        }
        if ($n === 100) {
            return 'cien';
        }

        $partes = [];
        $c = intdiv($n, 100);
        $r = $n % 100;

        if ($c > 0) {
            $partes[] = $centenas[$c];
        }

        if ($r > 0) {
            if ($r < 30) {
                $partes[] = $unidades[$r];
            } else {
                $d = intdiv($r, 10);
                $u = $r % 10;
                $partes[] = $u > 0
                    ? $decenas[$d] . ' y ' . $unidades[$u]
                    : $decenas[$d];
            }
        }

        return implode(' ', $partes);
    };

    $monto   = round((float) $monto, 2);
    $entero  = (int) floor($monto);
    $centavo = (int) round(($monto - $entero) * 100);

    if ($entero === 0 && $centavo === 0) {
        return 'Cero pesos 00/100 M.N.';
    }

    if ($entero === 0) {
        return 'Cero pesos ' . str_pad((string) $centavo, 2, '0', STR_PAD_LEFT) . '/100 M.N.';
    }

    $partes = [];

    $millones = intdiv($entero, 1000000);
    $resto    = $entero % 1000000;
    $miles    = intdiv($resto, 1000);
    $unidadesResto = $resto % 1000;

    if ($millones > 0) {
        $partes[] = $millones === 1
            ? 'un millón'
            : $grupo($millones) . ' millones';
    }
    if ($miles > 0) {
        $partes[] = $miles === 1 ? 'mil' : $grupo($miles) . ' mil';
    }
    if ($unidadesResto > 0) {
        $partes[] = $grupo($unidadesResto);
    }

    $texto = implode(' ', $partes);

    // "veintiuno" y "uno" finales se enlazan con el sustantivo: veintiún pesos.
    $texto = preg_replace('/\bveintiuno\b/u', 'veintiún', $texto);
    $texto = preg_replace('/\buno\b$/u', 'un', $texto);

    // Mayúscula inicial + formato de centavos exigido en el documento.
    $texto = ucfirst($texto) . ' pesos '
        . str_pad((string) $centavo, 2, '0', STR_PAD_LEFT) . '/100 M.N.';

    return $texto;
}

/* ---------------------------------------------------------------------------
 * 8. FORMULARIOS DE UN SOLO USO Y PERIODO CONTABLE
 * ------------------------------------------------------------------------- */

/**
 * Token de un solo uso para un formulario: reenviarlo (doble clic, recargar el
 * POST) no repite la operación. Se guarda en la sesión y se consume al validar.
 */
function tokenFormulario(): string
{
    sesionActiva();
    $token = bin2hex(random_bytes(16));
    $_SESSION['tokens_formulario'][$token] = time();

    // Se conservan solo los 50 más recientes.
    if (count($_SESSION['tokens_formulario']) > 50) {
        asort($_SESSION['tokens_formulario']);
        $_SESSION['tokens_formulario'] = array_slice($_SESSION['tokens_formulario'], -50, null, true);
    }

    return $token;
}

/** Consume el token: true la primera vez, false si ya se usó o no existe. */
function consumirToken(?string $token): bool
{
    sesionActiva();

    if (!is_string($token) || !isset($_SESSION['tokens_formulario'][$token])) {
        return false;
    }

    unset($_SESSION['tokens_formulario'][$token]);
    return true;
}

/**
 * Modo de la pantalla en el celular. «completo»: Inventario, Cotizaciones, Ventas, Entrega, Compras y proveedores, Ajustes y Calendario se usan
 * tal cual. «info»: el resto solo informa (el CSS oculta filtros, altas, ediciones y bajas; nada se quita de la vista).
 * Las pantallas de captura (crear, editar, recibir, devolución, PDF…) nunca se ocultan: dejarían la página vacía.
 */
function modoMovil(): string
{
    $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

    if (strpos($ruta, '/admin') !== 0 || $ruta === '/admin/fiscal/configuracion') {
        return 'completo';
    }
    if (preg_match('#^/admin/(inventario|cotizaciones|ventas|entregas|compras|proveedores|ajustes|usuarios|perfil|empresas|escritorio|ayuda|calendario)(/|$)#', $ruta)) {
        return 'completo';
    }
    if (preg_match('#/(crear|editar|recibir|devolucion|cambio|pdf|exportar|subir|baja)$#', $ruta)) {
        return 'completo';
    }

    return 'info';
}

/** Temas visuales válidos (Ajustes → Apariencia). «auto» sigue al sistema operativo. */
const TEMAS_VALIDOS = ['claro', 'oscuro', 'auto'];

/**
 * Tema elegido por quien usa la sesión: cookie del navegador (se aplica sin parpadeo) y, si no hay,
 * la preferencia guardada en su usuario (así lo recuerda en otro equipo). Por defecto «auto».
 */
function temaPreferido(): string
{
    $cookie = (string) ($_COOKIE['a360_tema'] ?? '');
    if (in_array($cookie, TEMAS_VALIDOS, true)) {
        return $cookie;
    }
    sesionActiva();
    $id = (int) ($_SESSION['id'] ?? 0);
    if ($id > 0) {
        $guardado = \App\Configuracion::obtener('tema_usuario_' . $id, 'auto');
        if (in_array($guardado, TEMAS_VALIDOS, true)) {
            return $guardado;
        }
    }

    return 'auto';
}

/**
 * Año, mes y negocio elegidos en el selector de periodo (querystring).
 *
 * @return array{anio: int, mes: int, empresa: int}
 */
function periodoSeleccionado(bool $mesObligatorio = true): array
{
    $anio = (int) ($_GET['anio'] ?? date('Y'));
    $anio = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) date('Y');

    $mes = isset($_GET['mes']) ? (int) $_GET['mes'] : ($mesObligatorio ? (int) date('n') : 0);
    $mes = ($mes >= 1 && $mes <= 12) ? $mes : ($mesObligatorio ? (int) date('n') : 0);

    return ['anio' => $anio, 'mes' => $mes, 'empresa' => max(0, (int) ($_GET['empresa'] ?? 0))];
}

/** Nombre del mes en español. */
function nombreMes(int $mes): string
{
    return [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][$mes] ?? '';
}
