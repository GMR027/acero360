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
    // Nunca se sirven directamente carpetas internas (documentos fiscales, código, configuración).
    $interno = is_string($archivoEstatico)
        && preg_match('#^/(storage|vendor|clases|includes|config|mysql|scripts|pruebas|templates|views|cambios|helm|design|src|node_modules|desktop)(/|$)|^/\.|\.(env|md|sql|lock|log|sh|json|ya?ml|ini|example)$|^/(Dockerfile|Router\.php|gulpfile\.js|deploy\.sh)$#', $archivoEstatico);

    if (is_string($archivoEstatico) && !$interno && $archivoEstatico !== '/' && file_exists(__DIR__ . $archivoEstatico)) {
        return false;
    }
}

require __DIR__ . '/includes/app.php';
require __DIR__ . '/Router.php';

// Cabeceras de seguridad, llave anti-falsificación en todo POST y campo _csrf en los formularios.
cabecerasSeguridad();
verificarCsrf();
ob_start('inyectarCsrf');

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
// FASE 9: RFCs de facturación de un cliente (varios RFC por ficha).
$router->post('/admin/clientes/rfc-accion', 'views/admin/clientes/rfc-accion.php', 'soloAdmin');
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

// Herramientas personales — Calendario (recordatorios y eventos por día).
$router->get('/admin/calendario',         'views/admin/calendario/index.php',   'soloAdmin');
$router->post('/admin/calendario/crear',  'views/admin/calendario/crear.php',   'soloAdmin');
$router->get('/admin/calendario/editar',  'views/admin/calendario/editar.php',  'soloAdmin');
$router->post('/admin/calendario/editar', 'views/admin/calendario/editar.php',  'soloAdmin');
// Acciones sobre un evento ya creado: eliminar (sólo POST, con confirmación modal).
$router->post('/admin/calendario/accion', 'views/admin/calendario/accion.php', 'soloAdmin');

// Herramientas personales — Redactor (documentos de texto).
$router->get('/admin/redactor',                 'views/admin/redactor/index.php',    'soloAdmin');
$router->get('/admin/redactor/crear',           'views/admin/redactor/crear.php',    'soloAdmin');
$router->post('/admin/redactor/crear',          'views/admin/redactor/crear.php',    'soloAdmin');
$router->get('/admin/redactor/abrir',           'views/admin/redactor/abrir.php',    'soloAdmin');
$router->get('/admin/redactor/ver',             'views/admin/redactor/ver.php',      'soloAdmin');
$router->get('/admin/redactor/descargar',       'views/admin/redactor/descargar.php', 'soloAdmin');
$router->post('/admin/redactor/duplicar',       'views/admin/redactor/accion.php',   'soloAdmin');
$router->post('/admin/redactor/eliminar',       'views/admin/redactor/accion.php',   'soloAdmin');
$router->post('/admin/redactor/mover',          'views/admin/redactor/accion.php',   'soloAdmin');
$router->post('/admin/redactor/renombrar',      'views/admin/redactor/accion.php',   'soloAdmin');
$router->post('/admin/redactor/plantilla-eliminar', 'views/admin/redactor/plantilla-eliminar.php', 'soloAdmin');
$router->post('/admin/redactor/api/guardar',    'views/admin/redactor/api/guardar.php',    'soloAdmin');
$router->post('/admin/redactor/api/imagen',     'views/admin/redactor/api/imagen.php',     'soloAdmin');
$router->post('/admin/redactor/api/palabra',    'views/admin/redactor/api/palabra.php',    'soloAdmin');
$router->post('/admin/redactor/api/firma',      'views/admin/redactor/api/firma.php',      'soloAdmin');
$router->post('/admin/redactor/api/firma-borrar', 'views/admin/redactor/api/firma-borrar.php', 'soloAdmin');
$router->post('/admin/redactor/api/sellar',     'views/admin/redactor/api/sellar.php',     'soloAdmin');
$router->post('/admin/redactor/api/plantilla',  'views/admin/redactor/api/plantilla.php',  'soloAdmin');
$router->get('/admin/redactor/api/versiones',   'views/admin/redactor/api/versiones.php',  'soloAdmin');
$router->post('/admin/redactor/api/version',    'views/admin/redactor/api/version.php',    'soloAdmin');
$router->post('/admin/redactor/api/programa',   'views/admin/redactor/api/programa.php',   'soloAdmin');
$router->post('/admin/redactor/api/hoja',       'views/admin/redactor/api/hoja.php',       'soloAdmin');
$router->post('/admin/redactor/api/exportar-seleccion', 'views/admin/redactor/api/exportar-seleccion.php', 'soloAdmin');

// Herramientas personales — Editor (documentos gráficos con Konva).
$router->get('/admin/editor',               'views/admin/editor/index.php',    'soloAdmin');
$router->get('/admin/editor/crear',         'views/admin/editor/crear.php',    'soloAdmin');
$router->post('/admin/editor/crear',        'views/admin/editor/crear.php',    'soloAdmin');
$router->get('/admin/editor/abrir',         'views/admin/editor/abrir.php',    'soloAdmin');
$router->get('/admin/editor/ver',           'views/admin/editor/ver.php',      'soloAdmin');
$router->get('/admin/editor/descargar',     'views/admin/editor/descargar.php', 'soloAdmin');
$router->post('/admin/editor/duplicar',     'views/admin/editor/accion.php',   'soloAdmin');
$router->post('/admin/editor/eliminar',     'views/admin/editor/accion.php',   'soloAdmin');
$router->post('/admin/editor/api/guardar',  'views/admin/editor/api/guardar.php', 'soloAdmin');
$router->post('/admin/editor/api/imagen',   'views/admin/editor/api/imagen.php',  'soloAdmin');
$router->post('/admin/editor/api/pdf',      'views/admin/editor/api/pdf.php',     'soloAdmin');

// Herramientas personales — SheetFlow (tablas compartidas).
$router->get('/admin/sheetflow',            'views/admin/sheetflow/index.php',    'soloAdmin');
$router->get('/admin/sheetflow/crear',      'views/admin/sheetflow/crear.php',    'soloAdmin');
$router->post('/admin/sheetflow/crear',     'views/admin/sheetflow/crear.php',    'soloAdmin');
$router->get('/admin/sheetflow/abrir',      'views/admin/sheetflow/abrir.php',    'soloAdmin');
$router->get('/admin/sheetflow/ver',        'views/admin/sheetflow/ver.php',      'soloAdmin');
$router->get('/admin/sheetflow/descargar',  'views/admin/sheetflow/descargar.php', 'soloAdmin');
$router->post('/admin/sheetflow/duplicar',  'views/admin/sheetflow/accion.php',   'soloAdmin');
$router->post('/admin/sheetflow/eliminar',  'views/admin/sheetflow/accion.php',   'soloAdmin');
$router->post('/admin/sheetflow/mover',     'views/admin/sheetflow/accion.php',   'soloAdmin');
$router->post('/admin/sheetflow/renombrar', 'views/admin/sheetflow/accion.php',   'soloAdmin');
$router->post('/admin/sheetflow/api/guardar', 'views/admin/sheetflow/api/guardar.php', 'soloAdmin');
$router->post('/admin/sheetflow/api/imagen',  'views/admin/sheetflow/api/imagen.php',  'soloAdmin');
$router->get('/admin/sheetflow/api/version',  'views/admin/sheetflow/api/version.php', 'soloAdmin');
$router->get('/admin/sheetflow/api/datos',    'views/admin/sheetflow/api/datos.php',   'soloAdmin');

// Herramientas personales — Archivo (carpetas virtuales con documentos subidos).
$router->get('/admin/archivo',                 'views/admin/archivo/index.php',     'soloAdmin');
$router->post('/admin/archivo/subir',          'views/admin/archivo/subir.php',     'soloAdmin');
$router->post('/admin/archivo/crear-carpeta',  'views/admin/archivo/accion.php',    'soloAdmin');
$router->post('/admin/archivo/renombrar-carpeta', 'views/admin/archivo/accion.php', 'soloAdmin');
$router->post('/admin/archivo/eliminar-carpeta',  'views/admin/archivo/accion.php', 'soloAdmin');
$router->post('/admin/archivo/mover',          'views/admin/archivo/accion.php',    'soloAdmin');
$router->post('/admin/archivo/eliminar-archivo',  'views/admin/archivo/accion.php', 'soloAdmin');
$router->get('/admin/archivo/descargar',       'views/admin/archivo/descargar.php', 'soloAdmin');

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
$router->post('/admin/ventas/cambio',     'views/admin/ventas/cambio.php',     'soloAdmin');
// Validación previa y emisión de la venta (FASE 5): sólo POST. Sin PAC no se simula timbrado.
$router->post('/admin/ventas/fiscal',     'views/admin/ventas/fiscal.php',      'soloAdmin');
// FASE 9 · alta rápida de cliente con los datos de la factura (venta inteligente).
$router->post('/admin/ventas/inteligente-cliente', 'views/admin/ventas/inteligente-cliente.php', 'soloAdmin');
// Complementos de pago de clientes (FASE 6): se registran y se relacionan con los cobros.
$router->get('/admin/ventas/complemento',          'views/admin/ventas/complemento.php',           'soloAdmin');
$router->post('/admin/ventas/complemento',         'views/admin/ventas/complemento.php',           'soloAdmin');
$router->get('/admin/ventas/complemento/revision', 'views/admin/ventas/complemento-revision.php',  'soloAdmin');
$router->post('/admin/ventas/complemento/accion',  'views/admin/ventas/complemento-accion.php',    'soloAdmin');
$router->get('/admin/ventas/pdf',       'views/admin/ventas/pdf.php',        'soloAdmin');
// Baja de una venta directa: sólo POST y con confirmación (devuelve el stock).
$router->post('/admin/ventas/eliminar', 'views/admin/ventas/eliminar.php',   'soloAdmin');

// Inventario: carga manual de existencias + exportación a PDF por empresa.
$router->get('/admin/inventario',          'views/admin/inventario/index.php',  'soloAdmin');
$router->post('/admin/inventario',         'views/admin/inventario/index.php',  'soloAdmin');
$router->post('/admin/inventario/ajustar', 'views/admin/inventario/ajustar.php', 'soloAdmin');
$router->get('/admin/inventario/movimientos', 'views/admin/inventario/movimientos.php', 'soloAdmin');
$router->get('/admin/inventario/fotos',       'views/admin/inventario/fotos.php',       'soloAdmin');
$router->post('/admin/inventario/fotos',      'views/admin/inventario/fotos.php',       'soloAdmin');
$router->get('/admin/inventario/exportar',    'views/admin/inventario/exportar.php',    'soloAdmin');
$router->get('/admin/inventario/completo', 'views/admin/inventario/completo.php', 'soloAdmin');
$router->get('/admin/inventario/pdf',      'views/admin/inventario/pdf.php',    'soloAdmin');

// Proveedores: directorio de proveedores y entradas (reporte fotográfico).
// --- Contabilidad, documentos y archivo mensual (Etapa 5) ---
$router->get('/admin/contabilidad',                         'views/admin/contabilidad/index.php',                'soloAdmin');
$router->get('/admin/seguimiento',  'views/admin/seguimiento.php', 'soloAdmin');
$router->post('/admin/seguimiento', 'views/admin/seguimiento.php', 'soloAdmin');
$router->get('/admin/seguimiento/campana',  'views/admin/seguimiento-campana.php', 'soloAdmin');
$router->post('/admin/seguimiento/campana', 'views/admin/seguimiento-campana.php', 'soloAdmin');
$router->get('/admin/ajustes',  'views/admin/ajustes.php', 'soloAdmin');
$router->post('/admin/ajustes', 'views/admin/ajustes.php', 'soloAdmin');
$router->get('/admin/ayuda',                                'views/admin/ayuda.php',                             'soloAdmin');
$router->get('/admin/contabilidad/mas',                     'views/admin/contabilidad/mas.php',                  'soloAdmin');
// FASE 9: carga por lotes (facturas de proveedores y emitidas, por separado).
$router->get('/admin/contabilidad/lotes',                   'views/admin/contabilidad/lotes.php',                'soloAdmin');
$router->post('/admin/contabilidad/lotes',                  'views/admin/contabilidad/lotes.php',                'soloAdmin');
$router->post('/admin/contabilidad/lotes-accion',           'views/admin/contabilidad/lotes-accion.php',         'soloAdmin');
$router->get('/admin/contabilidad/ingresos',                'views/admin/contabilidad/ingresos/index.php',       'soloAdmin');
$router->get('/admin/contabilidad/ingresos/detalle',        'views/admin/contabilidad/ingresos/detalle.php',     'soloAdmin');
$router->post('/admin/contabilidad/ingresos/accion',        'views/admin/contabilidad/ingresos/accion.php',      'soloAdmin');
$router->get('/admin/contabilidad/ingresos/exportar',      'views/admin/contabilidad/ingresos/exportar.php',    'soloAdmin');
$router->get('/admin/contabilidad/gastos',                  'views/admin/contabilidad/gastos/index.php',         'soloAdmin');
$router->get('/admin/contabilidad/gastos/crear',            'views/admin/contabilidad/gastos/crear.php',         'soloAdmin');
$router->post('/admin/contabilidad/gastos/crear',           'views/admin/contabilidad/gastos/crear.php',         'soloAdmin');
$router->get('/admin/contabilidad/gastos/editar',           'views/admin/contabilidad/gastos/editar.php',        'soloAdmin');
$router->post('/admin/contabilidad/gastos/editar',          'views/admin/contabilidad/gastos/editar.php',        'soloAdmin');
$router->get('/admin/contabilidad/gastos/detalle',          'views/admin/contabilidad/gastos/detalle.php',       'soloAdmin');
$router->post('/admin/contabilidad/gastos/accion',          'views/admin/contabilidad/gastos/accion.php',        'soloAdmin');
$router->post('/admin/contabilidad/gastos/masivo',          'views/admin/contabilidad/gastos/masivo.php',        'soloAdmin');
$router->get('/admin/contabilidad/gastos/exportar',        'views/admin/contabilidad/gastos/exportar.php',      'soloAdmin');
$router->get('/admin/contabilidad/lectura',               'views/admin/contabilidad/lectura/index.php',        'soloAdmin');
$router->post('/admin/contabilidad/lectura',              'views/admin/contabilidad/lectura/index.php',        'soloAdmin');
$router->post('/admin/contabilidad/lectura/inteligente',   'views/admin/contabilidad/lectura/inteligente.php',   'soloAdmin');
$router->get('/admin/contabilidad/lectura/revisar',       'views/admin/contabilidad/lectura/revisar.php',      'soloAdmin');
$router->post('/admin/contabilidad/lectura/revisar',      'views/admin/contabilidad/lectura/revisar.php',      'soloAdmin');
$router->get('/admin/contabilidad/documentos',              'views/admin/contabilidad/documentos/index.php',     'soloAdmin');
$router->post('/admin/contabilidad/documentos/subir',       'views/admin/contabilidad/documentos/subir.php',     'soloAdmin');
$router->get('/admin/contabilidad/documentos/descargar',    'views/admin/contabilidad/documentos/descargar.php', 'soloAdmin');
$router->post('/admin/contabilidad/documentos/baja',        'views/admin/contabilidad/documentos/baja.php',      'soloAdmin');
$router->get('/admin/contabilidad/sin-facturar',            'views/admin/contabilidad/sin-facturar/index.php',   'soloAdmin');
$router->get('/admin/contabilidad/sin-facturar/exportar',   'views/admin/contabilidad/sin-facturar/exportar.php','soloAdmin');
$router->get('/admin/contabilidad/zip',                     'views/admin/contabilidad/zip.php',                  'soloAdmin');
$router->get('/admin/contabilidad/periodos',                'views/admin/contabilidad/periodos.php',             'soloAdmin');

// --- Aplicación de escritorio (Etapa 9) ---
$router->get('/admin/escritorio',            'views/admin/escritorio/index.php',      'soloAdmin');
$router->get('/admin/escritorio/descargar',  'views/admin/escritorio/descargar.php',  'soloAdmin');
$router->post('/admin/escritorio/retirar',   'views/admin/escritorio/retirar.php',    'soloAdmin');

// --- Estadísticas (Etapa 7) ---
$router->post('/admin/ajustes/tema', 'views/admin/ajustes-tema.php', 'soloAdmin');
// Papelera: ventas y cotizaciones eliminadas (se restauran durante 90 días).
$router->get('/admin/ajustes/papelera',  'views/admin/ajustes-papelera.php', 'soloAdmin');
$router->post('/admin/ajustes/papelera', 'views/admin/ajustes-papelera.php', 'soloAdmin');
// Almacenamiento: espacio ocupado y copias de seguridad (respaldo automático diario y restauración).
$router->get('/admin/ajustes/almacenamiento',  'views/admin/ajustes-almacenamiento.php', 'soloAdmin');
$router->post('/admin/ajustes/almacenamiento', 'views/admin/ajustes-almacenamiento.php', 'soloAdmin');
$router->get('/admin/estadisticas',          'views/admin/estadisticas/index.php',    'soloAdmin');
$router->get('/admin/estadisticas/exportar', 'views/admin/estadisticas/exportar.php', 'soloAdmin');

// --- Fiscal y cierre mensual (Etapa 6) ---
$router->get('/admin/contabilidad/cierre',          'views/admin/contabilidad/cierre.php',          'soloAdmin');
$router->post('/admin/contabilidad/cierre/accion',  'views/admin/contabilidad/cierre-accion.php',   'soloAdmin');
$router->get('/admin/contabilidad/cierre/exportar', 'views/admin/contabilidad/cierre-exportar.php', 'soloAdmin');
$router->get('/admin/fiscal',                       'views/admin/fiscal/resumen.php',               'soloAdmin');
$router->post('/admin/fiscal',                      'views/admin/fiscal/resumen.php',               'soloAdmin');
$router->get('/admin/fiscal/documentos',           'views/admin/fiscal/documentos.php',            'soloAdmin');
$router->get('/admin/fiscal/movimientos',           'views/admin/fiscal/movimientos.php',           'soloAdmin');
$router->get('/admin/fiscal/exportar',              'views/admin/fiscal/exportar.php',              'soloAdmin');
$router->get('/admin/fiscal/resumen',               'views/admin/fiscal/resumen.php',               'soloAdmin');
$router->post('/admin/fiscal/resumen',              'views/admin/fiscal/resumen.php',               'soloAdmin');
$router->get('/admin/fiscal/configuracion',         'views/admin/fiscal/configuracion.php',         'soloAdmin');
$router->post('/admin/fiscal/configuracion',        'views/admin/fiscal/configuracion.php',         'soloAdmin');
// Retenciones, ajustes y declaraciones: captura de los datos que lee MotorFiscal (FASE 7).
$router->get('/admin/fiscal/registros',             'views/admin/fiscal/registros.php',             'soloAdmin');
$router->post('/admin/fiscal/registros',            'views/admin/fiscal/registros.php',             'soloAdmin');

// --- Pantalla de entrega (Etapa 4) ---
$router->get('/admin/entregas',          'views/admin/entregas/index.php',    'soloAdmin');
$router->get('/admin/entregas/clientes', 'views/admin/entregas/clientes.php', 'soloAdmin');
$router->get('/admin/entregas/ver',      'views/admin/entregas/ver.php',      'soloAdmin');
$router->post('/admin/entregas/crear',   'views/admin/entregas/crear.php',    'soloAdmin');
$router->get('/admin/entregas/detalle',  'views/admin/entregas/detalle.php',  'soloAdmin');
$router->post('/admin/entregas/estado',  'views/admin/entregas/estado.php',   'soloAdmin');
$router->get('/admin/entregas/entregar', 'views/admin/entregas/entregar.php', 'soloAdmin');
$router->post('/admin/entregas/entregar','views/admin/entregas/entregar.php', 'soloAdmin');
$router->get('/admin/entregas/pdf',      'views/admin/entregas/pdf.php',      'soloAdmin');
$router->get('/admin/entregas/imprimir', 'views/admin/entregas/imprimir.php', 'soloAdmin');

// --- Órdenes de compra y recepciones (Etapa 2) ---
$router->get('/admin/compras',          'views/admin/compras/resumen.php', 'soloAdmin');
$router->get('/admin/compras/pedidos',  'views/admin/compras/pedidos.php', 'soloAdmin');
$router->get('/admin/compras/recepciones', 'views/admin/compras/recepciones.php', 'soloAdmin');
$router->post('/admin/compras/recepciones', 'views/admin/compras/recepciones.php', 'soloAdmin');
$router->get('/admin/compras/facturas', 'views/admin/compras/facturas.php', 'soloAdmin');
// Catálogo de productos semiterminados por proveedor.
$router->get('/admin/compras/catalogo',          'views/admin/compras/catalogo/index.php',   'soloAdmin');
$router->get('/admin/compras/catalogo/crear',    'views/admin/compras/catalogo/crear.php',   'soloAdmin');
$router->post('/admin/compras/catalogo/crear',   'views/admin/compras/catalogo/crear.php',   'soloAdmin');
$router->get('/admin/compras/catalogo/editar',   'views/admin/compras/catalogo/editar.php',  'soloAdmin');
$router->post('/admin/compras/catalogo/editar',  'views/admin/compras/catalogo/editar.php',  'soloAdmin');
$router->get('/admin/compras/catalogo/detalle',  'views/admin/compras/catalogo/detalle.php', 'soloAdmin');
$router->post('/admin/compras/catalogo/accion',  'views/admin/compras/catalogo/accion.php',  'soloAdmin');
$router->post('/admin/compras/comprobante', 'views/admin/compras/comprobante.php', 'soloAdmin');
// Compras Inteligentes: leer el XML de una factura y decidir su destino.
$router->get('/admin/compras/inteligente',          'views/admin/compras/inteligente.php',           'soloAdmin');
$router->post('/admin/compras/inteligente',         'views/admin/compras/inteligente.php',           'soloAdmin');
$router->get('/admin/compras/inteligente/revision', 'views/admin/compras/inteligente-revision.php',  'soloAdmin');
$router->post('/admin/compras/inteligente/accion',  'views/admin/compras/inteligente-accion.php',    'soloAdmin');
// Complementos de pago (CFDI tipo «P») recibidos de proveedores.
$router->get('/admin/compras/complemento',          'views/admin/compras/complemento.php',           'soloAdmin');
$router->post('/admin/compras/complemento',         'views/admin/compras/complemento.php',           'soloAdmin');
$router->get('/admin/compras/complemento/revision', 'views/admin/compras/complemento-revision.php',  'soloAdmin');
$router->post('/admin/compras/complemento/accion',  'views/admin/compras/complemento-accion.php',    'soloAdmin');
$router->get('/admin/compras/pagos',    'views/admin/compras/pagos.php',   'soloAdmin');
$router->post('/admin/compras/pago',    'views/admin/compras/pago.php',    'soloAdmin');
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
