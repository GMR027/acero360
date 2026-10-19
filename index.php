<?php

/**
 * index.php — Front controller de la aplicación.
 *
 * Todas las peticiones HTTP entran aquí (gracias a .htaccess) y se resuelven
 * con el Router. Cada ruta declara su vista y, cuando aplica, el middleware
 * que protege el acceso (soloAdmin, soloRegistroAbierto, ...).
 *
 * IMPORTANTE: toda ruta bajo /admin DEBE llevar el middleware 'soloAdmin'.
 */

/* ---------------------------------------------------------------------------
 * SERVIDOR DE DESARROLLO (php -S localhost:8000 index.php)
 * En local no hay Apache ni .htaccess, así que este guard devuelve false
 * para que los archivos estáticos (CSS, JS, imágenes) los sirva el propio
 * servidor en lugar del front controller. En producción no tiene efecto.
 * ------------------------------------------------------------------------- */
if (PHP_SAPI === 'cli-server') {
    $archivoEstatico = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($archivoEstatico) && $archivoEstatico !== '/' && file_exists(__DIR__ . $archivoEstatico)) {
        return false;
    }
}

require __DIR__ . '/includes/app.php';
require __DIR__ . '/Router.php';

$router = new Router();

/* ---------------------------------------------------------------------------
 * RUTAS PÚBLICAS (sin sesión)
 * ------------------------------------------------------------------------- */
$router->get('/',           'views/inicio.php');
$router->get('/login',      'views/login.php');
$router->post('/login',     'views/login.php');
$router->get('/logout',     'views/logout.php');

// Registro de nuevos administradores. Cerrado por defecto:
// requiere la variable de entorno REGISTRO_ABIERTO=1.
$router->get('/registro',   'views/registro.php',   'soloRegistroAbierto');
$router->post('/registro',  'views/registro.php',   'soloRegistroAbierto');

/* ---------------------------------------------------------------------------
 * RUTAS PROTEGIDAS — PANEL DE ADMINISTRACIÓN (rol 1)
 * ------------------------------------------------------------------------- */

// Panel principal y perfil del usuario.
$router->get('/admin',            'views/admin/index.php',       'soloAdmin');
$router->get('/admin/perfil',     'views/perfil.php',            'soloAdmin');
$router->post('/admin/perfil',    'views/perfil.php',            'soloAdmin');

// CRUD de usuarios.
$router->get('/admin/usuarios',        'views/admin/usuarios/index.php',  'soloAdmin');
$router->post('/admin/usuarios',       'views/admin/usuarios/index.php',  'soloAdmin');
$router->get('/admin/usuarios/crear',  'views/admin/usuarios/crear.php',  'soloAdmin');
$router->post('/admin/usuarios/crear', 'views/admin/usuarios/crear.php',  'soloAdmin');
$router->get('/admin/usuarios/editar', 'views/admin/usuarios/editar.php', 'soloAdmin');
$router->post('/admin/usuarios/editar','views/admin/usuarios/editar.php', 'soloAdmin');
// Baja de usuarios: sólo POST y con protección de "no borrarse a uno mismo".
$router->post('/admin/usuarios/eliminar', 'views/admin/usuarios/eliminar.php', 'soloAdmin');

// CRUD de clientes (fichas seleccionables en las cotizaciones).
$router->get('/admin/clientes',         'views/admin/clientes/index.php',   'soloAdmin');
$router->post('/admin/clientes',        'views/admin/clientes/index.php',   'soloAdmin');
$router->get('/admin/clientes/crear',   'views/admin/clientes/crear.php',   'soloAdmin');
$router->post('/admin/clientes/crear',  'views/admin/clientes/crear.php',   'soloAdmin');
$router->get('/admin/clientes/editar',  'views/admin/clientes/editar.php',  'soloAdmin');
$router->post('/admin/clientes/editar', 'views/admin/clientes/editar.php',  'soloAdmin');
$router->get('/admin/clientes/detalle', 'views/admin/clientes/detalle.php', 'soloAdmin');
// El borrado sólo acepta POST: nunca se elimina con un simple enlace.
$router->post('/admin/clientes/eliminar', 'views/admin/clientes/eliminar.php', 'soloAdmin');

// Notas personales — bitácora única, compartida por todos los administradores.
$router->get('/admin/notas',         'views/admin/notas/index.php',  'soloAdmin');
$router->get('/admin/notas/crear',   'views/admin/notas/crear.php',  'soloAdmin');
$router->post('/admin/notas/crear',  'views/admin/notas/crear.php',  'soloAdmin');
$router->get('/admin/notas/editar',  'views/admin/notas/editar.php', 'soloAdmin');
$router->post('/admin/notas/editar', 'views/admin/notas/editar.php', 'soloAdmin');
// Acciones sobre una nota ya creada: fijar, marcar hecha o eliminar (sólo POST).
$router->post('/admin/notas/accion', 'views/admin/notas/accion.php', 'soloAdmin');

// CRUD de empresas / negocios (los dos negocios del proyecto).
$router->get('/admin/empresas',        'views/admin/empresas/index.php',  'soloAdmin');
$router->post('/admin/empresas',       'views/admin/empresas/index.php',  'soloAdmin');
$router->get('/admin/empresas/crear',  'views/admin/empresas/crear.php',  'soloAdmin');
$router->post('/admin/empresas/crear', 'views/admin/empresas/crear.php',  'soloAdmin');
$router->get('/admin/empresas/editar', 'views/admin/empresas/editar.php', 'soloAdmin');
$router->post('/admin/empresas/editar','views/admin/empresas/editar.php', 'soloAdmin');
$router->post('/admin/empresas/eliminar', 'views/admin/empresas/eliminar.php', 'soloAdmin');

// CRUD de productos (con imagen cuadrada vía Intervention Image).
$router->get('/admin/productos',        'views/admin/productos/index.php',  'soloAdmin');
$router->post('/admin/productos',       'views/admin/productos/index.php',  'soloAdmin');
$router->get('/admin/productos/crear',  'views/admin/productos/crear.php',  'soloAdmin');
$router->post('/admin/productos/crear', 'views/admin/productos/crear.php',  'soloAdmin');
$router->get('/admin/productos/editar', 'views/admin/productos/editar.php', 'soloAdmin');
$router->post('/admin/productos/editar','views/admin/productos/editar.php', 'soloAdmin');
$router->post('/admin/productos/eliminar', 'views/admin/productos/eliminar.php', 'soloAdmin');
// Exportación del catálogo completo (una tabla por negocio) a PDF.
$router->get('/admin/productos/pdf', 'views/admin/productos/pdf.php', 'soloAdmin');

// Cotizaciones: creación, edición, detalle e histórico.
$router->get('/admin/cotizaciones',         'views/admin/cotizaciones/index.php',   'soloAdmin');
$router->post('/admin/cotizaciones',        'views/admin/cotizaciones/index.php',   'soloAdmin');
$router->get('/admin/cotizaciones/crear',   'views/admin/cotizaciones/crear.php',   'soloAdmin');
$router->post('/admin/cotizaciones/crear',  'views/admin/cotizaciones/crear.php',   'soloAdmin');
$router->get('/admin/cotizaciones/editar',  'views/admin/cotizaciones/editar.php',  'soloAdmin');
$router->post('/admin/cotizaciones/editar', 'views/admin/cotizaciones/editar.php',  'soloAdmin');
$router->get('/admin/cotizaciones/detalle', 'views/admin/cotizaciones/detalle.php', 'soloAdmin');
// Acciones sobre una cotización ya creada: sólo POST (nunca un simple enlace).
$router->post('/admin/cotizaciones/eliminar', 'views/admin/cotizaciones/eliminar.php', 'soloAdmin');
$router->post('/admin/cotizaciones/estado',   'views/admin/cotizaciones/estado.php',   'soloAdmin');
// Duplicar: copia renglones y condiciones en un folio nuevo, listo para editar.
$router->post('/admin/cotizaciones/duplicar', 'views/admin/cotizaciones/duplicar.php', 'soloAdmin');
// Exportación a PDF.
$router->get('/admin/cotizaciones/pdf',       'views/admin/cotizaciones/pdf.php',      'soloAdmin');
// Precios acordados con un cliente, en JSON (los consume el cotizador con fetch).
$router->get('/admin/cotizaciones/precios-cliente', 'views/admin/cotizaciones/preciosCliente.php', 'soloAdmin');

// Ventas: todo lo vendido (ventas directas + cotizaciones vendidas). Las ventas
// directas se registran sin cotización y descuentan el inventario.
$router->get('/admin/ventas',           'views/admin/ventas/index.php',      'soloAdmin');
$router->post('/admin/ventas',          'views/admin/ventas/index.php',      'soloAdmin');
$router->get('/admin/ventas/crear',     'views/admin/ventas/crear.php',      'soloAdmin');
$router->post('/admin/ventas/crear',    'views/admin/ventas/crear.php',      'soloAdmin');
$router->get('/admin/ventas/detalle',   'views/admin/ventas/detalle.php',    'soloAdmin');
$router->get('/admin/ventas/editar',    'views/admin/ventas/editar.php',     'soloAdmin');
$router->post('/admin/ventas/editar',   'views/admin/ventas/editar.php',     'soloAdmin');
$router->get('/admin/ventas/devolucion',  'views/admin/ventas/devolucion.php', 'soloAdmin');
$router->post('/admin/ventas/devolucion', 'views/admin/ventas/devolucion.php', 'soloAdmin');
$router->get('/admin/ventas/pdf',       'views/admin/ventas/pdf.php',        'soloAdmin');
// Baja de una venta directa: sólo POST y con confirmación (devuelve el stock).
$router->post('/admin/ventas/eliminar', 'views/admin/ventas/eliminar.php',   'soloAdmin');

// Inventario: carga manual de existencias + exportación a PDF por empresa.
$router->get('/admin/inventario',          'views/admin/inventario/index.php',  'soloAdmin');
$router->post('/admin/inventario',         'views/admin/inventario/index.php',  'soloAdmin');
$router->post('/admin/inventario/ajustar', 'views/admin/inventario/ajustar.php', 'soloAdmin');
$router->get('/admin/inventario/pdf',      'views/admin/inventario/pdf.php',    'soloAdmin');

// Proveedores: directorio de proveedores y entradas (reporte fotográfico).
// --- Pantalla de entrega (Etapa 4) ---
$router->get('/admin/entregas',          'views/admin/entregas/index.php',    'soloAdmin');
$router->get('/admin/entregas/clientes', 'views/admin/entregas/clientes.php', 'soloAdmin');
$router->post('/admin/entregas/crear',   'views/admin/entregas/crear.php',    'soloAdmin');
$router->get('/admin/entregas/detalle',  'views/admin/entregas/detalle.php',  'soloAdmin');
$router->post('/admin/entregas/estado',  'views/admin/entregas/estado.php',   'soloAdmin');
$router->get('/admin/entregas/entregar', 'views/admin/entregas/entregar.php', 'soloAdmin');
$router->post('/admin/entregas/entregar','views/admin/entregas/entregar.php', 'soloAdmin');
$router->get('/admin/entregas/pdf',      'views/admin/entregas/pdf.php',      'soloAdmin');

// --- Órdenes de compra y recepciones (Etapa 2) ---
$router->get('/admin/compras',          'views/admin/compras/index.php',   'soloAdmin');
$router->get('/admin/compras/crear',    'views/admin/compras/crear.php',   'soloAdmin');
$router->post('/admin/compras/crear',   'views/admin/compras/crear.php',   'soloAdmin');
$router->get('/admin/compras/editar',   'views/admin/compras/editar.php',  'soloAdmin');
$router->post('/admin/compras/editar',  'views/admin/compras/editar.php',  'soloAdmin');
$router->get('/admin/compras/detalle',  'views/admin/compras/detalle.php', 'soloAdmin');
$router->get('/admin/compras/recibir',  'views/admin/compras/recibir.php', 'soloAdmin');
$router->post('/admin/compras/recibir', 'views/admin/compras/recibir.php', 'soloAdmin');
$router->post('/admin/compras/accion',  'views/admin/compras/accion.php',  'soloAdmin');
$router->get('/admin/compras/pdf',      'views/admin/compras/pdf.php',     'soloAdmin');

$router->get('/admin/proveedores',           'views/admin/proveedores/index.php',           'soloAdmin');
$router->post('/admin/proveedores',          'views/admin/proveedores/index.php',           'soloAdmin');
$router->get('/admin/proveedores/crear',     'views/admin/proveedores/crear.php',           'soloAdmin');
$router->post('/admin/proveedores/crear',    'views/admin/proveedores/crear.php',           'soloAdmin');
$router->get('/admin/proveedores/editar',    'views/admin/proveedores/editar.php',          'soloAdmin');
$router->post('/admin/proveedores/editar',   'views/admin/proveedores/editar.php',          'soloAdmin');
$router->get('/admin/proveedores/detalle',   'views/admin/proveedores/detalle.php',         'soloAdmin');
$router->post('/admin/proveedores/eliminar', 'views/admin/proveedores/eliminar.php',        'soloAdmin');
// CRUD de categorías de compra: catálogo ÚNICO que se ofrece en las entradas
// de todos los proveedores (antes era una lista fija escrita en el código).
$router->get('/admin/proveedores/categorias',           'views/admin/proveedores/categorias/index.php',   'soloAdmin');
$router->post('/admin/proveedores/categorias',          'views/admin/proveedores/categorias/index.php',   'soloAdmin');
$router->get('/admin/proveedores/categorias/crear',     'views/admin/proveedores/categorias/crear.php',   'soloAdmin');
$router->post('/admin/proveedores/categorias/crear',    'views/admin/proveedores/categorias/crear.php',   'soloAdmin');
$router->get('/admin/proveedores/categorias/editar',    'views/admin/proveedores/categorias/editar.php',  'soloAdmin');
$router->post('/admin/proveedores/categorias/editar',   'views/admin/proveedores/categorias/editar.php',  'soloAdmin');
$router->post('/admin/proveedores/categorias/eliminar', 'views/admin/proveedores/categorias/eliminar.php', 'soloAdmin');
// Reporte anual de entradas por proveedor (año activo), en un solo PDF.
$router->get('/admin/proveedores/pdf',       'views/admin/proveedores/pdf.php',             'soloAdmin');

$router->get('/admin/proveedores/entradas',           'views/admin/proveedores/entradas/index.php',   'soloAdmin');
$router->post('/admin/proveedores/entradas',          'views/admin/proveedores/entradas/index.php',   'soloAdmin');
$router->get('/admin/proveedores/entradas/crear',     'views/admin/proveedores/entradas/crear.php',   'soloAdmin');
$router->post('/admin/proveedores/entradas/crear',    'views/admin/proveedores/entradas/crear.php',   'soloAdmin');
$router->get('/admin/proveedores/entradas/editar',    'views/admin/proveedores/entradas/editar.php',  'soloAdmin');
$router->post('/admin/proveedores/entradas/editar',   'views/admin/proveedores/entradas/editar.php',  'soloAdmin');
$router->get('/admin/proveedores/entradas/detalle',   'views/admin/proveedores/entradas/detalle.php', 'soloAdmin');
$router->post('/admin/proveedores/entradas/eliminar', 'views/admin/proveedores/entradas/eliminar.php', 'soloAdmin');
// Marcar / desmarcar una entrada como pagada (sólo POST: cambia el estado de pago).
$router->post('/admin/proveedores/entradas/pago',     'views/admin/proveedores/entradas/pago.php',     'soloAdmin');

/* ---------------------------------------------------------------------------
 * RESOLUCIÓN
 * ------------------------------------------------------------------------- */
$vista = $router->comprobarRutas();

if ($vista) {
    include __DIR__ . '/' . $vista;
}
