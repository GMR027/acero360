<?php
/**
 * pruebas/php/etapa56.php — Distribuidores autorizados (migración 056).
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa56.php
 *
 * Comprueba la carga inicial (los 42 de la lista fija del sitio), la
 * validación, el alta/edición/baja, los filtros del listado y las URLs de
 * las fachadas heredadas y subidas.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';

use App\Distribuidores;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0];
$db->query("DELETE FROM distribuidores WHERE nombre LIKE 'ZZ etapa56%'");

$empresa = (int) $uno("SELECT id FROM empresas WHERE nombre = 'Soluciones en Acero' ORDER BY id LIMIT 1");

echo "Carga inicial\n";
$prueba('42 distribuidores de Soluciones en Acero', (int) $uno("SELECT COUNT(*) FROM distribuidores WHERE empresa_id = $empresa AND nombre NOT LIKE 'ZZ%'") >= 42);
$prueba('todos publicados y con orden 1..42', $uno('SELECT COUNT(*) FROM distribuidores WHERE publicado = 1 AND orden BETWEEN 1 AND 42') >= 42);
$prueba('acentos intactos', (int) $uno("SELECT COUNT(*) FROM distribuidores WHERE nombre = 'Polibaños' AND municipio = 'La Paz'") === 1);
$municipios = Distribuidores::municipios();
$prueba('municipios en el orden del sitio', array_slice($municipios, 0, 3) === ['La Paz', 'Cabo San Lucas', 'San Jose del Cabo'], implode(', ', $municipios));

echo "Validación\n";
$d = new Distribuidores(['empresa_id' => $empresa]);
$errores = $d->validar();
$prueba('nombre, municipio y dirección obligatorios', count($errores) === 3, implode(' | ', $errores));

$d = new Distribuidores([
    'empresa_id' => $empresa, 'nombre' => "  ZZ etapa56   uno ", 'municipio' => 'Loreto',
    'direccion' => "Calle 1\nCentro", 'maps' => 'javascript:alert(1)', 'telefono' => '612-abc',
]);
$errores = $d->validar();
$prueba('Maps debe ser https', (bool) array_filter($errores, fn ($e) => str_contains($e, 'Maps')));
$prueba('teléfono con letras se rechaza', (bool) array_filter($errores, fn ($e) => str_contains($e, 'teléfono')));
$prueba('espacios y saltos se normalizan', $d->nombre === 'ZZ etapa56 uno' && $d->direccion === 'Calle 1 Centro');

$d->maps = 'https://maps.app.goo.gl/abc';
$d->telefono = '(612) 123-4567';
$d->empresa_id = 999999;
$prueba('negocio inexistente se rechaza', count($d->validar()) === 1);

$d->empresa_id = $empresa;
$d->nombre = str_repeat('x', 121);
$prueba('nombre de más de 120 se rechaza', count($d->validar()) === 1);
$d->nombre = 'ZZ etapa56 uno';
$prueba('datos correctos pasan', $d->validar() === []);

echo "Alta, edición y baja\n";
$siguiente = Distribuidores::siguienteOrden();
$prueba('se crea', $d->crear() && $d->id > 0);
$prueba('sin orden queda al final', (int) $d->orden === $siguiente);
$guardado = Distribuidores::buscarID($d->id);
$prueba('opcionales vacíos se guardan como NULL', $uno("SELECT sucursal IS NULL AND imagen IS NULL FROM distribuidores WHERE id = {$d->id}") == 1);
$prueba('se lee con el nombre del negocio', $guardado && $guardado->empresa === 'Soluciones en Acero');

$guardado->sincronizar(['nombre' => 'ZZ etapa56 dos', 'sucursal' => 'Centro', 'municipio' => 'Loreto',
    'direccion' => 'Calle 2', 'maps' => '', 'telefono' => '', 'orden' => '5', 'publicado' => '0', 'empresa_id' => $empresa]);
$prueba('sincronizar toma publicado = 0 y el orden', $guardado->publicado === 0 && $guardado->orden === 5);
$prueba('se actualiza', $guardado->validar() === [] && $guardado->actualizar());
$prueba('oculto no cuenta como publicado', (int) $uno("SELECT publicado FROM distribuidores WHERE id = {$d->id}") === 0);
$prueba('cambiarPublicado lo vuelve a mostrar', $guardado->cambiarPublicado(true) && (int) $uno("SELECT publicado FROM distribuidores WHERE id = {$d->id}") === 1);

echo "Listado\n";
$prueba('buscar por sucursal', Distribuidores::contarTodos('ZZ etapa56') === 1 && Distribuidores::contarTodos('', '', $empresa) >= 43);
$prueba('filtrar por municipio', Distribuidores::contarTodos('', 'Loreto') === (int) $uno("SELECT COUNT(*) FROM distribuidores WHERE municipio = 'Loreto'"));
$lista = Distribuidores::mostrarTodos(5, 0);
$prueba('en el orden del sitio', count($lista) === 5 && (int) $lista[0]->orden <= (int) $lista[4]->orden);

echo "Fachadas\n";
$heredado = new Distribuidores(['imagen' => 'distribuidores/lapaz/el Clavo.jpg']);
$prueba('heredada → URL del sitio', $heredado->urlImagen() === Distribuidores::SITIO_URL . '/build/img/distribuidores/lapaz/el%20Clavo.jpg', $heredado->urlImagen());
$subida = new Distribuidores(['imagen' => 'distribuidor-0123456789.jpg']);
$prueba('subida → public/image y miniatura', $subida->urlImagen() === '/public/image/distribuidor-0123456789.jpg'
    && $subida->urlImagen(true) === '/public/image/distribuidor-0123456789_mini.jpg');
$prueba('sin fachada → vacío', (new Distribuidores())->urlImagen() === '');

$prueba('se elimina', $guardado->eliminar() && Distribuidores::buscarID($d->id) === null);
$prueba('eliminar dos veces falla sin excepción', $guardado->eliminar() === false);

echo $fallos === 0 ? "\nTodo bien.\n" : "\n{$fallos} fallo(s).\n";
exit($fallos === 0 ? 0 : 1);
