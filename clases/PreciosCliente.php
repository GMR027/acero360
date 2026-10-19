<?php

namespace App;

/**
 * PreciosCliente — precio acordado con un cliente para un producto concreto.
 *
 * En las cotizaciones hay DOS precios:
 *
 *   productos.precio       → precio de lista, fijo y de sólo lectura.
 *   precios_cliente.precio → precio especial negociado con ESE cliente.
 *
 * El motor de precios es el siguiente:
 *
 *   1. Al cargar un producto en el cotizador se usa el precio especial si
 *      existe, y en caso contrario el de lista.
 *   2. Si el usuario cambia el precio y NO marca "No recordar este precio",
 *      el importe se guarda aquí (UPSERT: una sola fila por cliente+producto).
 *   3. Si marca "No recordar este precio", el importe sólo queda como
 *      instantánea en cotizacion_detalles.precio_unitario.
 *
 * Los precios especiales NUNCA se borran automáticamente: son acuerdos
 * comerciales y sólo cambian cuando alguien vuelve a capturar uno.
 */
class PreciosCliente
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten (sin el id ni las fechas). */
    protected static $columnasDB = ['id', 'cliente_id', 'producto_id', 'precio'];

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    public $id;
    public $cliente_id;
    public $producto_id;
    public $precio;
    public $creado_en;
    public $actualizado_en;

    /** @var string Sólo lectura: nombre del producto (cuando se lista el acuerdo). */
    public $producto_nombre;
    public $producto_sku;
    public $producto_imagen;
    public $producto_precio;

    public function __construct(array $args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->cliente_id  = isset($args['cliente_id']) ? (int) $args['cliente_id'] : 0;
        $this->producto_id = isset($args['producto_id']) ? (int) $args['producto_id'] : 0;
        $this->precio      = isset($args['precio']) ? (float) $args['precio'] : 0.0;
        $this->creado_en   = $args['creado_en'] ?? '';
        $this->actualizado_en = $args['actualizado_en'] ?? '';
    }

    /* --------------------------------------------------------------------
     * CONEXIÓN
     * ------------------------------------------------------------------ */

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /**
     * Precio especial de un producto para un cliente.
     *
     * @return float|null null cuando no hay acuerdo guardado.
     */
    public static function precioDe(int $clienteId, int $productoId): ?float
    {
        if ($clienteId <= 0 || $productoId <= 0) {
            return null;
        }

        $consulta = self::$db->prepare(
            'SELECT precio FROM precios_cliente
              WHERE cliente_id = ? AND producto_id = ? LIMIT 1'
        );
        $consulta->bind_param('ii', $clienteId, $productoId);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? (float) $registro['precio'] : null;
    }

    /**
     * Mapa producto_id => precio especial de todo un cliente.
     *
     * Se usa para precargar el cotizador en una sola consulta.
     *
     * @return array<int, float>
     */
    public static function todosPorCliente(int $clienteId): array
    {
        $mapa = [];

        if ($clienteId <= 0) {
            return $mapa;
        }

        $consulta = self::$db->prepare(
            'SELECT producto_id, precio FROM precios_cliente WHERE cliente_id = ?'
        );
        $consulta->bind_param('i', $clienteId);
        $consulta->execute();
        $registros = $consulta->get_result();

        while ($fila = $registros->fetch_assoc()) {
            $mapa[(int) $fila['producto_id']] = (float) $fila['precio'];
        }

        $consulta->close();
        return $mapa;
    }

    /**
     * Acuerdos guardados para un cliente (listado en su ficha / detalle).
     *
     * @return array<int, PreciosCliente>
     */
    public static function porCliente(int $clienteId): array
    {
        $acuerdos = [];

        if ($clienteId <= 0) {
            return $acuerdos;
        }

        $consulta = self::$db->prepare(
            'SELECT pc.*, p.nombre AS producto_nombre, p.sku AS producto_sku,
                    p.imagen AS producto_imagen, p.precio AS producto_precio
               FROM precios_cliente pc
               JOIN productos p ON p.id = pc.producto_id
              WHERE pc.cliente_id = ?
              ORDER BY p.nombre ASC'
        );
        $consulta->bind_param('i', $clienteId);
        $consulta->execute();
        $registros = $consulta->get_result();

        while ($fila = $registros->fetch_assoc()) {
            $acuerdos[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $acuerdos;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

    /**
     * Guarda (o actualiza) el precio especial de un producto para un cliente.
     *
     * UPSERT sobre la clave única (cliente_id, producto_id): siempre hay
     * como mucho un acuerdo por pareja cliente-producto.
     *
     * Si el precio capturado es EXACTAMENTE el de lista no se escribe nada:
     * un importe igual al de lista no es un acuerdo comercial y sólo dejaría
     * filas de "precio especial" sin significado. Un acuerdo que YA existe
     * tampoco se borra (los precios especiales sólo cambian cuando alguien
     * vuelve a capturar uno).
     */
    public static function guardar(int $clienteId, int $productoId, float $precio): bool
    {
        if ($clienteId <= 0 || $productoId <= 0) {
            return false;
        }

        $precio = max(0, round($precio, 2));

        if ($precio === self::precioDeLista($productoId)) {
            return true;
        }

        try {
            $consulta = self::$db->prepare(
                'INSERT INTO precios_cliente (cliente_id, producto_id, precio)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE precio = VALUES(precio)'
            );
            $consulta->bind_param('iid', $clienteId, $productoId, $precio);

            $correcto = $consulta->execute();
            $consulta->close();

            if ($correcto) {
                return true;
            }
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[PreciosCliente::guardar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible guardar el precio especial del cliente.';
        return false;
    }

    /**
     * Precio de lista de un producto (0.00 cuando el producto no existe).
     */
    private static function precioDeLista(int $productoId): float
    {
        $consulta = self::$db->prepare(
            'SELECT precio FROM productos WHERE id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $productoId);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? round((float) $registro['precio'], 2) : 0.0;
    }

    /* --------------------------------------------------------------------
     * UTILIDADES
     * ------------------------------------------------------------------ */

    /**
     * Precio que debe mostrarse en el cotizador para un producto.
     *
     * @return array{precio: float, especial: bool}
     */
    public static function precioAPlicar(int $clienteId, int $productoId, float $precioLista): array
    {
        $especial = self::precioDe($clienteId, $productoId);

        if ($especial !== null) {
            return ['precio' => $especial, 'especial' => true];
        }

        return ['precio' => $precioLista, 'especial' => false];
    }

    /** Convierte un registro asociativo en una instancia de esta clase. */
    protected static function crearObjeto(array $registro): self
    {
        $objeto = new self();

        foreach ($registro as $clave => $valor) {
            if (property_exists($objeto, $clave)) {
                $objeto->$clave = $valor;
            }
        }

        return $objeto;
    }

    /** Devuelve y limpia los errores acumulados. */
    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }
}
