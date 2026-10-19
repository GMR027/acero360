<?php

/**
 * scripts/preparar_soluciones_en_acero.php — Deja lista la empresa
 * "Soluciones en Acero" con su catálogo real.
 *
 * La carpeta de origen es el sitio web del negocio:
 *
 *     /home/edgar/Descargas/solucionesEnAcero-master
 *
 * De ahí salen los 14 productos con su nombre, código, precio de lista,
 * especificaciones e imagen, además de los datos de cobertura que se
 * escriben en la ficha de la empresa.
 *
 * ETAPAS (en este orden):
 *
 *   1. Borra las cotizaciones de prueba (y sus renglones, en cascada).
 *   2. Borra precios especiales, clientes, productos y notas de prueba, y
 *      las empresas que no sean las dos del proyecto.
 *   3. Escribe en "Soluciones en Acero" los datos reales de contacto y
 *      cobertura, y su logo.
 *   4. Carga los 14 productos con imagen cuadrada JPG (1000×1000) y
 *      miniatura (300×300), con el mismo formato que usa la subida real.
 *
 * USO:
 *   php scripts/preparar_soluciones_en_acero.php --confirmar
 *
 * Sin "--confirmar" sólo se imprime el resumen de lo que se haría.
 *
 * La base de datos se modifica dentro de UNA SOLA transacción: si algo
 * falla, no queda nada a medias. Los archivos de imagen se tocan sólo
 * después del commit. Aun así conviene respaldar antes:
 *
 *   mysqldump -u root cotizaciones > respaldo.sql
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';

use App\Imagenes;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/* -------------------------------------------------------------------------
 * CATÁLOGO — tomado de src/pages/admin-listado.html (nombre, código, precio)
 * y de las páginas de categoría (especificaciones y tipo de uso).
 * ---------------------------------------------------------------------- */

/** Carpeta del sitio de origen (imágenes de producto y logo). */
const ORIGEN_SITIO = '/home/edgar/Descargas/solucionesEnAcero-master/src';

/** Nombres de las dos empresas que se conservan en la base de datos. */
const EMPRESAS_QUE_SE_CONSERVAN = ['Soluciones en Acero', 'Canastillas de la Baja'];

/**
 * Catálogo de Soluciones en Acero.
 *
 * @var array<int, array{sku: string, nombre: string, categoria: string,
 *                       precio: float, origen: string, descripcion: string}>
 */
$catalogo = [
    // --- Registros de ángulo ------------------------------------------------
    [
        'sku'         => 'RA-1',
        'nombre'      => 'Registro de ángulo 40x40 cm',
        'categoria'   => 'Registros de ángulo',
        'precio'      => 360,
        'origen'      => 'img/img-productos/ra1.png',
        'descripcion' => 'Registro compacto de ángulo.'
            . "\n" . 'Dimensión: 40x40 cm. Marco: 1 1/2". Contramarco: 1 1/4". Armado interior: Varillatec 1/4". Acabado: primer rojo.'
            . "\n" . 'Patas de anclaje en la parte posterior; división de armado: 13.3 cm.'
            . "\n" . 'Tipo de uso: ideal para instalaciones eléctricas o de telecomunicaciones.',
    ],
    [
        'sku'         => 'RA-3',
        'nombre'      => 'Registro de ángulo 40x60 cm',
        'categoria'   => 'Registros de ángulo',
        'precio'      => 360,
        'origen'      => 'img/img-productos/ra3.png',
        'descripcion' => 'Registro reforzado de ángulo.'
            . "\n" . 'Dimensión: 40x60 cm. Marco: 1 1/4". Contramarco: 1". Armado interior: Varillatec 1/4". Acabado: primer rojo.'
            . "\n" . 'Armado de largos: 13.3 cm, cortos: 15 cm.'
            . "\n" . 'Tipo de uso: ideal para instalaciones sanitarias.',
    ],
    [
        'sku'         => 'RA-4',
        'nombre'      => 'Registro de ángulo 40x60 cm con coladera',
        'categoria'   => 'Registros de ángulo',
        'precio'      => 550,
        'origen'      => 'img/img-productos/ra4.png',
        'descripcion' => 'Registro reforzado de ángulo con coladera.'
            . "\n" . 'Dimensión: 40x60 cm. Marco: 1 1/4". Contramarco: 1". Armado interior: Varillatec 1/4". Acabado: primer rojo.'
            . "\n" . 'Coladera armada con varillatec; armado de largos: 13.3 cm, cortos: 15 cm.'
            . "\n" . 'Tipo de uso: ideal para áreas de desagüe de líquidos.',
    ],
    [
        'sku'         => 'RA-5',
        'nombre'      => 'Registro de ángulo 47x67 cm',
        'categoria'   => 'Registros de ángulo',
        'precio'      => 550,
        'origen'      => 'img/img-productos/ra5.png',
        'descripcion' => 'Registro grande de ángulo.'
            . "\n" . 'Dimensión: 47x67 cm. Marco: 1 1/2". Contramarco: 1 1/4". Armado interior: Varillatec 1/4". Acabado: primer gris.'
            . "\n" . 'Armado de largos: 15.5 cm, cortos: 13.4 cm.'
            . "\n" . 'Tipo de uso: ideal para instalaciones sanitarias donde el registro de block '
            . 'requiera una medida interior libre de 40x60 cm.',
    ],
    [
        'sku'         => 'RA-6',
        'nombre'      => 'Registro de ángulo 60x60 cm',
        'categoria'   => 'Registros de ángulo',
        'precio'      => 450,
        'origen'      => 'img/img-productos/ra6.png',
        'descripcion' => 'Registro cuadrado de ángulo.'
            . "\n" . 'Dimensión: 60x60 cm. Marco: 1 1/4". Contramarco: 1". Armado interior: Varillatec 1/4". Acabado: primer rojo.'
            . "\n" . 'Separación de armado: 12 cm.'
            . "\n" . 'Tipo de uso: ideal para instalaciones sanitarias y para cisternas '
            . '(no de la marca Rotoplas) con tráfico ligero.',
    ],
    [
        'sku'         => 'RA-7',
        'nombre'      => 'Registro de ángulo 50x70 cm',
        'categoria'   => 'Registros de ángulo',
        'precio'      => 750,
        'origen'      => 'img/img-productos/ra7-1.png',
        'descripcion' => 'Registro de ángulo de gran formato.'
            . "\n" . 'Dimensión: 50x70 cm. Marco: 2". Contramarco: 1 1/2". Armado interior: Varillatec 5/16". Acabado: primer rojo.'
            . "\n" . 'Separación de largos: 16.5 cm; de cortos: 14 cm.',
    ],

    // --- Registros de solera ------------------------------------------------
    [
        'sku'         => 'RS-1',
        'nombre'      => 'Registro de solera 40x60 cm',
        'categoria'   => 'Registros de solera',
        'precio'      => 360,
        'origen'      => 'img/img-productos/rs1.png',
        'descripcion' => 'Registro de solera.'
            . "\n" . 'Dimensión: 40x60 cm. Marco: 2". Contramarco: 2". Armado interior: Varillatec 1/4". Acabado: primer rojo.'
            . "\n" . 'Armado de largos: 13.3 cm, cortos: 15 cm.'
            . "\n" . 'Tipo de uso: ideal para instalaciones sanitarias.',
    ],
    [
        'sku'         => 'RS-2',
        'nombre'      => 'Registro de solera 65x65 cm',
        'categoria'   => 'Registros de solera',
        'precio'      => 540,
        'origen'      => 'img/img-productos/rs2.png',
        'descripcion' => 'Registro de solera para tráfico pesado.'
            . "\n" . 'Dimensión: 65x65 cm. Marco: 2". Contramarco: 2". Armado interior: Varillatec 5/16". Acabado: primer rojo.'
            . "\n" . 'Separación de armado interior: 13 cm.'
            . "\n" . 'Tipo de uso: ideal para cisternas (no de la marca Rotoplas) e instalaciones '
            . 'sanitarias con tráfico pesado.',
    ],
    [
        'sku'         => 'RS-3',
        'nombre'      => 'Registro de solera 75x75 cm',
        'categoria'   => 'Registros de solera',
        'precio'      => 720,
        'origen'      => 'img/img-productos/rs3.png',
        'descripcion' => 'Registro de solera de gran tamaño.'
            . "\n" . 'Dimensión: 75x75 cm. Marco: 2". Contramarco: 2". Armado interior: Varillatec 5/16". Acabado: primer gris.'
            . "\n" . 'Separación de armado: 12.5 cm.'
            . "\n" . 'Tipo de uso: ideal para cisternas de la marca Rotoplas e instalaciones '
            . 'sanitarias con tráfico pesado.',
    ],

    // --- Tapas metálicas ----------------------------------------------------
    [
        'sku'         => 'TM-1',
        'nombre'      => 'Tapa metálica 60x60 cm sencilla',
        'categoria'   => 'Tapas metálicas',
        'precio'      => 650,
        'origen'      => 'img/img-productos/tm1.png',
        'descripcion' => 'Tapa metálica sencilla.'
            . "\n" . 'Dimensión: 60x60 cm. Color: gris. Acabado: primer gris.'
            . "\n" . 'Tipo de uso: ideal para cisternas (no de la marca Rotoplas) con tráfico ligero.',
    ],
    [
        'sku'         => 'TM-2',
        'nombre'      => 'Tapa metálica 60x60 cm reforzada',
        'categoria'   => 'Tapas metálicas',
        'precio'      => 1200,
        'origen'      => 'img/img-productos/tm2.png',
        'descripcion' => 'Tapa metálica reforzada.'
            . "\n" . 'Dimensión: 60x60 cm. Color: negra. Acabado: primer negro.'
            . "\n" . 'Tipo de uso: ideal para cisternas (no de la marca Rotoplas) con tráfico pesado.',
    ],
    [
        'sku'         => 'TM-3',
        'nombre'      => 'Tapa metálica 70x70 cm sencilla',
        'categoria'   => 'Tapas metálicas',
        'precio'      => 850,
        'origen'      => 'img/img-productos/tm3.png',
        'descripcion' => 'Tapa metálica sencilla.'
            . "\n" . 'Dimensión: 70x70 cm. Color: gris. Acabado: primer gris.'
            . "\n" . 'Tipo de uso: ideal para cisternas de la marca Rotoplas con tráfico ligero.',
    ],
    [
        'sku'         => 'TM-4',
        'nombre'      => 'Tapa metálica 70x70 cm reforzada',
        'categoria'   => 'Tapas metálicas',
        'precio'      => 1600,
        'origen'      => 'img/img-productos/tm4.png',
        'descripcion' => 'Tapa metálica reforzada.'
            . "\n" . 'Dimensión: 70x70 cm. Color: negra. Acabado: primer negro.'
            . "\n" . 'Tipo de uso: ideal para cisternas de la marca Rotoplas con tráfico pesado.',
    ],

    // --- Bases --------------------------------------------------------------
    [
        'sku'         => 'BB-1',
        'nombre'      => 'Base para boiler 40x40 cm',
        'categoria'   => 'Bases',
        'precio'      => 390,
        'origen'      => 'img/img-productos/BB.png',
        'descripcion' => 'Base de lámina para boiler y bombas hidráulicas.'
            . "\n" . 'Dimensión: 40x40 cm. Base de lámina; soportes de ángulo. Acabado: primer gris (color gris).'
            . "\n" . 'Tipo de uso: ideal para recibir bases de boiler y bombas.',
    ],
];

/* -------------------------------------------------------------------------
 * FICHA DE LA EMPRESA — datos tomados del sitio (index.html y footer)
 * ---------------------------------------------------------------------- */

/**
 * Datos reales de "Soluciones en Acero".
 *
 * Se conserva sólo lo que el sitio publica: ciudad, estado, teléfono
 * (WhatsApp público) y condiciones de envío/compra. El RFC, el régimen, el
 * domicilio y el correo NO aparecen en el sitio, así que se dejan vacíos
 * para que se capturen desde el módulo "Empresas & Razón" en lugar de
 * conservar los valores ficticios del semilla de prueba.
 *
 * @return array<string, string|null>
 */
function fichaSolucionesEnAcero(): array
{
    return [
        'rfc'                => null,
        'regimen'            => null,
        'direccion'          => null,
        'ciudad'             => 'La Paz',
        'estado'             => 'Baja California Sur',
        'cp'                 => null,
        'telefono'           => '612 000 0000',
        'email'              => null,
        'condiciones_envio'  => 'Entregamos a través de nuestros distribuidores en los municipios '
            . 'de Baja California Sur: Loreto, Ciudad Insurgentes, La Paz, Todos Santos, '
            . 'Cabo San Lucas y San José del Cabo. *Consulta las restricciones de entrega y '
            . 'disponibilidad según tu solicitud.',
        'condiciones_compra' => 'Los precios están expresados en pesos mexicanos (MXN) y pueden '
            . 'modificarse sin previo aviso. Para confirmar tu pedido envía el comprobante de pago '
            . 'junto con la orden de compra por WhatsApp o correo electrónico.',
    ];
}

/* -------------------------------------------------------------------------
 * AUXILIARES
 * ---------------------------------------------------------------------- */

/** Clave de archivo de un producto: la misma que usa scripts/imagenes_prueba.php. */
function claveDeArchivo(string $sku): string
{
    $clave = strtolower(preg_replace('/[^a-z0-9]/i', '', $sku));
    return $clave !== '' ? $clave : 'producto';
}

/** Imprime una línea del avance. */
function avanzar(string $mensaje): void
{
    echo '  ' . $mensaje . PHP_EOL;
}

/* -------------------------------------------------------------------------
 * PROGRAMA
 * ---------------------------------------------------------------------- */

$soloListar = !in_array('--confirmar', $argv, true);

echo PHP_EOL . '== Preparar "Soluciones en Acero" ==' . PHP_EOL . PHP_EOL;

if ($soloListar) {
    echo 'Modo consulta: no se modifica nada.' . PHP_EOL . PHP_EOL;
    echo 'Se borrarían:' . PHP_EOL;
    avanzar('todas las cotizaciones de prueba');
    avanzar('todos los clientes de prueba');
    avanzar('todas las notas de prueba');
    avanzar('todos los productos del catálogo');
    avanzar('las empresas que no sean "Soluciones en Acero" ni "Canastillas de la Baja"');
    echo PHP_EOL . 'Se cargarían:' . PHP_EOL;
    avanzar(count($catalogo) . ' productos de Soluciones en Acero');
    avanzar('la ficha de contacto y cobertura de Soluciones en Acero');
    avanzar('el logo del sitio');
    echo PHP_EOL . 'Ejecuta con --confirmar para aplicar los cambios.' . PHP_EOL . PHP_EOL;
    exit(0);
}

$db = database();
$origen = rtrim(ORIGEN_SITIO, '/');

if (!is_dir($origen)) {
    fwrite(STDERR, "No existe la carpeta de origen: $origen\n");
    exit(1);
}

/* 1. Qué imágenes se pueden borrar después (las de los productos que salen). */
$imagenesAnteriores = [];
$resultado = $db->query('SELECT imagen FROM productos WHERE imagen IS NOT NULL AND imagen <> ""');
while ($fila = $resultado->fetch_assoc()) {
    $imagenesAnteriores[] = (string) $fila['imagen'];
}

/* 2. Resumen previo. */
$conteos = [];
$resultado = $db->query(
    'SELECT "cotizaciones" AS tabla, COUNT(*) AS n FROM cotizaciones
      UNION ALL SELECT "clientes", COUNT(*) FROM clientes
      UNION ALL SELECT "notas", COUNT(*) FROM notas
      UNION ALL SELECT "productos", COUNT(*) FROM productos
      UNION ALL SELECT "empresas", COUNT(*) FROM empresas'
);
while ($fila = $resultado->fetch_assoc()) {
    $conteos[(string) $fila['tabla']] = (int) $fila['n'];
}

avanzar('Antes  — cotizaciones: ' . $conteos['cotizaciones']
    . ', clientes: ' . $conteos['clientes']
    . ', notas: ' . $conteos['notas']
    . ', productos: ' . $conteos['productos']
    . ', empresas: ' . $conteos['empresas']);

/* 3. Transacción: limpieza + ficha + productos. */
$db->begin_transaction();

try {
    // Las cotizaciones cuelgan de clientes y empresas: se borran primero.
    $db->query('DELETE FROM cotizaciones');
    avanzar('Cotizaciones de prueba borradas');

    $db->query('DELETE FROM precios_cliente');
    $db->query('DELETE FROM productos');
    avanzar('Productos y precios especiales borrados');

    $db->query('DELETE FROM clientes');
    avanzar('Clientes de prueba borrados');

    $db->query('DELETE FROM notas');
    avanzar('Notas de prueba borradas');

    // Sólo se conservan los dos negocios del proyecto.
    $conservada1 = EMPRESAS_QUE_SE_CONSERVAN[0];
    $conservada2 = EMPRESAS_QUE_SE_CONSERVAN[1];
    $borrarEmpresas = $db->prepare('DELETE FROM empresas WHERE nombre NOT IN (?, ?)');
    $borrarEmpresas->bind_param('ss', $conservada1, $conservada2);
    $borrarEmpresas->execute();
    $empresasBorradas = $borrarEmpresas->affected_rows;
    $borrarEmpresas->close();
    avanzar("Empresas fuera del proyecto borradas: $empresasBorradas");

    // Empresa que se prepara.
    $empresa = $db->prepare(
        'SELECT id FROM empresas WHERE nombre = ? LIMIT 1'
    );
    $nombreEmpresa = 'Soluciones en Acero';
    $empresa->bind_param('s', $nombreEmpresa);
    $empresa->execute();
    $idEmpresa = (int) ($empresa->get_result()->fetch_assoc()['id'] ?? 0);
    $empresa->close();

    if ($idEmpresa === 0) {
        throw new RuntimeException('No se encontró la empresa "Soluciones en Acero".');
    }

    $ficha = fichaSolucionesEnAcero();
    $actualizar = $db->prepare(
        'UPDATE empresas
            SET rfc = ?, regimen = ?, direccion = ?, ciudad = ?, estado = ?, cp = ?,
                telefono = ?, email = ?, condiciones_envio = ?, condiciones_compra = ?
          WHERE id = ?'
    );
    $rfc         = $ficha['rfc'];
    $regimen     = $ficha['regimen'];
    $direccion   = $ficha['direccion'];
    $ciudad      = $ficha['ciudad'];
    $estado      = $ficha['estado'];
    $cp          = $ficha['cp'];
    $telefono    = $ficha['telefono'];
    $email       = $ficha['email'];
    $envio       = $ficha['condiciones_envio'];
    $compra      = $ficha['condiciones_compra'];
    $actualizar->bind_param(
        'ssssssssssi',
        $rfc, $regimen, $direccion, $ciudad, $estado, $cp,
        $telefono, $email, $envio, $compra,
        $idEmpresa
    );
    $actualizar->execute();
    $actualizar->close();
    avanzar('Ficha de "Soluciones en Acero" actualizada (La Paz, Baja California Sur)');

    // Altas del catálogo.
    $insertar = $db->prepare(
        'INSERT INTO productos
            (empresa_id, nombre, sku, categoria, unidad, descripcion, precio, activo)
         VALUES (?, ?, ?, ?, "pza", ?, ?, 1)'
    );

    $ids = [];   // sku => id recién creado
    foreach ($catalogo as $item) {
        $precio      = (float) $item['precio'];
        $sku         = $item['sku'];
        $nombre      = $item['nombre'];
        $categoria   = $item['categoria'];
        $descripcion = $item['descripcion'];
        $insertar->bind_param(
            'issssd',
            $idEmpresa,
            $nombre,
            $sku,
            $categoria,
            $descripcion,
            $precio
        );
        $insertar->execute();
        $ids[$sku] = (int) $insertar->insert_id;
    }
    $insertar->close();
    avanzar('Productos cargados: ' . count($ids));

    $db->commit();
} catch (\Throwable $excepcion) {
    $db->rollback();
    fwrite(STDERR, 'Se canceló la operación: ' . $excepcion->getMessage() . PHP_EOL);
    exit(1);
}

/* 4. Imágenes de los productos que salieron del catálogo. */
foreach ($imagenesAnteriores as $archivo) {
    Imagenes::eliminar($archivo);
}
avanzar('Imágenes anteriores borradas: ' . count($imagenesAnteriores));

/* 5. Imágenes del catálogo nuevo (mismo formato que la subida real). */
$gerenciador = new ImageManager(new Driver());
$copia       = Imagenes::CARPETA;

if (!is_dir($copia) && !mkdir($copia, 0775, true) && !is_dir($copia)) {
    fwrite(STDERR, "No se pudo crear la carpeta $copia\n");
    exit(1);
}

$generadas = 0;
$fallidas  = 0;

foreach ($catalogo as $item) {
    $sku   = $item['sku'];
    $id    = $ids[$sku] ?? 0;
    $ruta  = $origen . '/' . $item['origen'];

    if ($id === 0 || !is_file($ruta)) {
        $fallidas++;
        fwrite(STDERR, "Sin imagen para $sku (" . $item['origen'] . ")\n");
        continue;
    }

    $clave    = claveDeArchivo($sku);
    $nombre   = 'producto-' . $clave . '.jpg';
    $mini     = 'producto-' . $clave . '_mini.jpg';

    try {
        $gerenciador->read($ruta)
            ->cover(1000, 1000)
            ->encodeByMediaType('image/jpeg', 85)
            ->save($copia . '/' . $nombre);

        $gerenciador->read($ruta)
            ->cover(300, 300)
            ->encodeByMediaType('image/jpeg', 85)
            ->save($copia . '/' . $mini);
    } catch (\Throwable $excepcion) {
        $fallidas++;
        fwrite(STDERR, "No se pudo procesar la imagen de $sku: " . $excepcion->getMessage() . PHP_EOL);
        continue;
    }

    $sentencia = $db->prepare('UPDATE productos SET imagen = ? WHERE id = ?');
    $sentencia->bind_param('si', $nombre, $id);
    $sentencia->execute();
    $sentencia->close();
    $generadas++;
}

avanzar("Imágenes generadas: $generadas (fallos: $fallidas)");

/* 6. Logo de la empresa (cuadrado 300×300, como la subida real). */
$logoOrigen = $origen . '/logo/logo.png';
$logoNombre = 'logo-soluciones-en-acero.png';

if (is_file($logoOrigen)) {
    try {
        $gerenciador->read($logoOrigen)
            ->contain(300, 300, 'transparent')
            ->encodeByMediaType('image/png')
            ->save($copia . '/' . $logoNombre);

        $sentencia = $db->prepare('UPDATE empresas SET logo = ? WHERE id = ?');
        $sentencia->bind_param('si', $logoNombre, $idEmpresa);
        $sentencia->execute();
        $sentencia->close();
        avanzar("Logo guardado: $logoNombre");
    } catch (\Throwable $excepcion) {
        fwrite(STDERR, 'No se pudo guardar el logo: ' . $excepcion->getMessage() . PHP_EOL);
        $fallidas++;
    }
} else {
    fwrite(STDERR, "No se encontró el logo en $logoOrigen\n");
    $fallidas++;
}

/* 7. Barrido final: fuera todo archivo "producto-*" que ya nadie referencia. */
$referenciadas = [];
$resultado = $db->query('SELECT imagen FROM productos WHERE imagen IS NOT NULL AND imagen <> ""');
while ($fila = $resultado->fetch_assoc()) {
    $referenciadas[(string) $fila['imagen']] = true;
    $referenciadas[preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', (string) $fila['imagen'])] = true;
}

$huerfanos = 0;
foreach (glob($copia . '/producto-*') ?: [] as $ruta) {
    if (!isset($referenciadas[basename($ruta)])) {
        @unlink($ruta);
        $huerfanos++;
    }
}
if ($huerfanos > 0) {
    avanzar("Archivos huérfanos eliminados: $huerfanos");
}

/* 8. Estado final. */
echo PHP_EOL . 'Después:' . PHP_EOL;
$resultado = $db->query(
    'SELECT "empresas" AS tabla, COUNT(*) AS n FROM empresas
      UNION ALL SELECT "productos", COUNT(*) FROM productos
      UNION ALL SELECT "clientes", COUNT(*) FROM clientes
      UNION ALL SELECT "notas", COUNT(*) FROM notas
      UNION ALL SELECT "cotizaciones", COUNT(*) FROM cotizaciones'
);
while ($fila = $resultado->fetch_assoc()) {
    avanzar($fila['tabla'] . ': ' . $fila['n']);
}

echo PHP_EOL;
exit($fallidas > 0 ? 1 : 0);
