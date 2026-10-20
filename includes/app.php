<?php

/**
 * Bootstrap de la aplicación.
 *
 * Orden de carga:
 *   1. Funciones auxiliares (rutas, plantillas, seguridad, alertas)
 *   2. Conexión a la base de datos
 *   3. Autoload de Composer (clases bajo el namespace App\)
 *   4. Inyección de la conexión en cada clase de negocio (setDB)
 *
 * Se usa __DIR__ en todas las rutas para que el archivo funcione
 * sin importar desde dónde se incluya (evita el problema relativo
 * que tiene canastillasWep en su includes/app.php).
 */

require __DIR__ . '/funciones.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../vendor/autoload.php';

$db = database();

/* Clases de negocio que reciben la conexión a la BD.
 * Se añade una línea por cada clase creada en /clases.
 * (App\Imagenes queda fuera: sólo manipula archivos, no consulta la base.)
 * Si creas una clase nueva, recuérdala aquí: sin setDB() no tendrá
 * conexión y cualquier consulta fallará en tiempo de ejecución. */
$clasesConectadas = [
    App\Usuarios::class,
    App\Empresas::class,
    App\Clientes::class,
    App\Productos::class,
    App\PreciosCliente::class,
    App\Cotizaciones::class,
    App\Inventario::class,
    App\Ventas::class,
    App\CategoriasProveedor::class,
    App\Proveedores::class,
    App\ProveedorEntradas::class,
    App\Notas::class,
    App\Configuracion::class,
    App\Auditoria::class,
    App\OrdenesCompra::class,
    App\Entregas::class,
    App\Contabilidad::class,
    App\Gastos::class,
    App\Archivos::class,
    App\ArchivoMensual::class,
    App\CatalogoFiscal::class,
    App\RegistrosFiscales::class,
    App\MotorFiscal::class,
    App\CierrePeriodo::class,
    App\Estadisticas::class,
];

foreach ($clasesConectadas as $clase) {
    if (class_exists($clase)) {
        $clase::setDB($db);
    }
}
