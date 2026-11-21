<?php

namespace App;

/**
 * Pedidos — lo que un cliente pide a un negocio antes de volverse cotización
 * o venta.
 *
 * Mismo cálculo de importes que las cotizaciones (Cotizaciones::calcular) y
 * mismos renglones (producto, cantidad, precio, descuento). Desde el listado
 * se convierte:
 *   · «Marcar como cotización» → crea una cotización pendiente con sus
 *     renglones (Cotizaciones::crear) y el pedido queda «cotizado».
 *   · «Marcar como venta» → si ya tiene cotización, esa cotización pasa a
 *     «vendido» (el flujo existente genera la venta y descuenta el
 *     inventario); si no, se registra una venta directa (Ventas::crear).
 *
 * Las dos conversiones usan los métodos existentes, que abren su propia
 * transacción; el pedido se actualiza después con un UPDATE condicionado al
 * estado previo, de modo que un doble clic no genera dos documentos.
 *
 * origen = 'portal' lo usará el portal público de clientes (otra aplicación
 * sobre esta misma base): esos pedidos llegan con usuario_id NULL.
 */
class Pedidos
{
    /** @var \mysqli */
    protected static $db;

    /** @var string[] */
    protected static $errores = [];

    /** Id del último pedido creado. */
    public static $ultimoId = 0;

    public const ESTADOS = ['pendiente', 'cotizado', 'vendido', 'cancelado'];

    public const ESTILOS_ESTADO = [
        'pendiente' => ['etiqueta' => 'Pendiente', 'icono' => 'schedule',      'clase' => 'badge--pendiente'],
        'cotizado'  => ['etiqueta' => 'Cotizado',  'icono' => 'request_quote', 'clase' => 'badge--info'],
        'vendido'   => ['etiqueta' => 'Vendido',   'icono' => 'check_circle',  'clase' => 'badge--aprobado'],
        'cancelado' => ['etiqueta' => 'Cancelado', 'icono' => 'cancel',        'clase' => 'badge--error'],
    ];

    public $id;
    public $folio = '';
    public $empresa_id;
    public $cliente_id;
    public $usuario_id;
    public $origen;
    public $fecha;
    public $estado;
    public $subtotal = 0;
    public $descuento_pct = 0;
    public $descuento = 0;
    public $aplica_iva;
    public $iva = 0;
    public $total = 0;
    public $condiciones_envio = '';
    public $condiciones_compra = '';
    public $comentarios = '';
    public $cotizacion_id;
    public $venta_id;
    public $cancelado_por;
    public $cancelado_en;
    public $creado_en;

    /* Datos de unión (los usa también templates/pdfCotizaciones.php). */
    public $vigencia_dias = 0;
    public $empresa_nombre;
    public $empresa_logo;
    public $empresa_direccion;
    public $empresa_ciudad;
    public $empresa_estado;
    public $empresa_cp;
    public $empresa_telefono;
    public $empresa_email;
    public $cliente_nombre;
    public $cliente_empresa;
    public $cliente_alias;
    public $cliente_telefono;
    public $cliente_email;
    public $cliente_ubicacion;
    public $cliente_vip;
    public $usuario_nombre;
    public $cotizacion_folio;
    public $cotizacion_estado;
    public $venta_folio;

    /** @var array<int, array<string, mixed>> Renglones (sólo en listar()). */
    public $renglones = [];

    public function __construct(array $args = [])
    {
        foreach ($args as $clave => $valor) {
            if (property_exists($this, $clave)) {
                $this->$clave = $valor;
            }
        }
        $this->fecha      = $this->fecha ?: date('Y-m-d');
        $this->estado     = $this->estado ?: 'pendiente';
        $this->origen     = $this->origen ?: 'admin';
        $this->aplica_iva = $this->aplica_iva === null ? 1 : (int) $this->aplica_iva;
    }

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    public static function datosEstado(string $estado): array
    {
        return self::ESTILOS_ESTADO[$estado] ?? self::ESTILOS_ESTADO['pendiente'];
    }

    /** Siguiente folio del año: PED-2026-0001… (máximo numérico, no COUNT). */
    public static function siguienteFolio(): string
    {
        $prefijo  = 'PED-' . date('Y') . '-';
        $posicion = strlen($prefijo) + 1;
        $patron   = $prefijo . '%';

        $consulta = self::$db->prepare(
            'SELECT MAX(CAST(SUBSTRING(folio, ?) AS UNSIGNED)) AS maximo FROM pedidos WHERE folio LIKE ?'
        );
        $consulta->bind_param('is', $posicion, $patron);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $prefijo . str_pad((string) (((int) ($fila['maximo'] ?? 0)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    private const SELECT = '
        SELECT p.*,
               e.nombre AS empresa_nombre, e.logo AS empresa_logo, e.direccion AS empresa_direccion,
               e.ciudad AS empresa_ciudad, e.estado AS empresa_estado, e.cp AS empresa_cp,
               e.telefono AS empresa_telefono, e.email AS empresa_email,
               cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa, cl.alias AS cliente_alias,
               cl.telefono AS cliente_telefono, cl.email AS cliente_email, cl.ubicacion AS cliente_ubicacion,
               cl.vip AS cliente_vip,
               u.nombre AS usuario_nombre,
               c.folio AS cotizacion_folio, c.estado AS cotizacion_estado,
               v.folio AS venta_folio
          FROM pedidos p
          JOIN empresas e  ON e.id  = p.empresa_id
          JOIN clientes cl ON cl.id = p.cliente_id
          LEFT JOIN usuarios u     ON u.id = p.usuario_id
          LEFT JOIN cotizaciones c ON c.id = p.cotizacion_id
          LEFT JOIN ventas v       ON v.id = p.venta_id';

    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        $consulta = self::$db->prepare(self::SELECT . ' WHERE p.id = ? LIMIT 1');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? new self($fila) : null;
    }

    /** Renglones con los datos del producto (mismo formato que Cotizaciones::detalles). */
    public static function detalles(int $pedidoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT d.*, p.nombre AS producto_nombre, p.sku AS producto_sku,
                    p.unidad AS producto_unidad, p.imagen AS producto_imagen,
                    p.precio AS producto_precio, p.categoria AS producto_categoria,
                    p.stock AS producto_stock
               FROM pedido_detalles d
               JOIN productos p ON p.id = d.producto_id
              WHERE d.pedido_id = ?
              ORDER BY d.id ASC'
        );
        $consulta->bind_param('i', $pedidoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Cláusula WHERE de listar()/contar().
     *
     * @param array{empresa_id?: int, estado?: string, q?: string} $filtros
     */
    private static function condiciones(array $filtros): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];

        if ((int) ($filtros['empresa_id'] ?? 0) > 0) {
            $donde[] = 'p.empresa_id = ?';
            $tipos .= 'i';
            $valores[] = (int) $filtros['empresa_id'];
        }
        $estado = (string) ($filtros['estado'] ?? '');
        if ($estado === 'abiertos') {
            $donde[] = "p.estado IN ('pendiente', 'cotizado')";
        } elseif (in_array($estado, self::ESTADOS, true)) {
            $donde[] = 'p.estado = ?';
            $tipos .= 's';
            $valores[] = $estado;
        }
        $termino = trim((string) ($filtros['q'] ?? ''));
        if ($termino !== '') {
            $patron = '%' . $termino . '%';
            $donde[] = '(p.folio LIKE ? OR cl.nombre LIKE ? OR IFNULL(cl.nombre_empresa, "") LIKE ? OR IFNULL(cl.alias, "") LIKE ?)';
            $tipos .= 'ssss';
            array_push($valores, $patron, $patron, $patron, $patron);
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /** @return self[] Pedidos con sus renglones cargados en ->renglones. */
    public static function listar(array $filtros = [], int $limite = 15, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $tipos .= 'ii';
        $valores[] = max(1, $limite);
        $valores[] = max(0, $offset);

        $consulta = self::$db->prepare(self::SELECT . " WHERE $donde ORDER BY p.fecha DESC, p.id DESC LIMIT ? OFFSET ?");
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        $pedidos = [];
        foreach ($filas as $fila) {
            $pedidos[(int) $fila['id']] = new self($fila);
        }
        if ($pedidos === []) {
            return [];
        }

        // Renglones de toda la página en una sola consulta.
        $ids = implode(',', array_map('intval', array_keys($pedidos)));
        $resultado = self::$db->query(
            "SELECT d.pedido_id, d.producto_id, d.cantidad, p.nombre AS producto_nombre, p.sku AS producto_sku,
                    p.unidad AS producto_unidad, p.stock AS producto_stock
               FROM pedido_detalles d JOIN productos p ON p.id = d.producto_id
              WHERE d.pedido_id IN ($ids) ORDER BY d.id"
        );
        while ($r = $resultado->fetch_assoc()) {
            $pedidos[(int) $r['pedido_id']]->renglones[] = $r;
        }
        $resultado->free();

        return array_values($pedidos);
    }

    public static function contar(array $filtros = []): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare(
            "SELECT COUNT(*) AS total FROM pedidos p JOIN clientes cl ON cl.id = p.cliente_id WHERE $donde"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /** Cuántos pedidos pendientes hay por negocio (para los chips del listado). */
    public static function pendientesPorEmpresa(): array
    {
        $mapa = [];
        $resultado = self::$db->query("SELECT empresa_id, COUNT(*) AS n FROM pedidos WHERE estado = 'pendiente' GROUP BY empresa_id");
        while ($f = $resultado->fetch_assoc()) {
            $mapa[(int) $f['empresa_id']] = (int) $f['n'];
        }
        $resultado->free();
        return $mapa;
    }

    /**
     * Faltantes de inventario de unos renglones (formato de listar()).
     * La existencia es una sola por producto (productos.stock).
     *
     * @return array<int, array{nombre: string, requerido: int, disponible: int, faltante: int}>
     */
    public static function faltantes(array $renglones): array
    {
        $faltan = [];
        foreach ($renglones as $r) {
            $stock = (int) ($r['producto_stock'] ?? 0);
            $req   = (int) $r['cantidad'];
            if ($req > $stock) {
                $faltan[] = ['nombre' => (string) $r['producto_nombre'], 'requerido' => $req,
                    'disponible' => $stock, 'faltante' => $req - max(0, $stock)];
            }
        }
        return $faltan;
    }

    /**
     * Un pedido «cotizado» cuya cotización ya se vendió desde el Histórico
     * queda como «vendido» y enlazado a esa venta.
     */
    public static function sincronizarConVentas(): void
    {
        try {
            self::$db->query(
                "UPDATE pedidos p
                   JOIN ventas v ON v.cotizacion_id = p.cotizacion_id AND v.estado = 'registrada'
                    SET p.estado = 'vendido', p.venta_id = v.id
                  WHERE p.estado = 'cotizado' AND p.cotizacion_id IS NOT NULL"
            );
        } catch (\mysqli_sql_exception $e) {
            error_log('[Pedidos::sincronizarConVentas] ' . $e->getMessage());
        }
    }

    /* --------------------------------------------------------------------
     * VALIDACIÓN Y ESCRITURA
     * ------------------------------------------------------------------ */

    /** Mismas reglas que una venta (negocio, cliente, fecha, renglones). */
    public static function validar(array $datos, array $lineas): array
    {
        $errores = Ventas::validar($datos, $lineas);

        return array_map(
            static fn (string $e): string => str_replace(['de la venta', 'a la venta', 'emite la venta'], ['del pedido', 'al pedido', 'recibe el pedido'], $e),
            $errores
        );
    }

    /**
     * Crea el pedido con sus renglones.
     *
     * @param array $lineas [producto_id => [cantidad, precio, descuento_pct, no_recordar]]
     */
    public static function crear(array $datos, array $lineas, ?int $usuarioId, string $origen = 'admin'): bool
    {
        self::$errores = [];

        for ($intento = 0; $intento < 3; $intento++) {
            try {
                self::$db->begin_transaction();

                $totales = Cotizaciones::calcular($lineas, (float) ($datos['descuento_pct'] ?? 0), !empty($datos['aplica_iva']));
                if ($totales['renglones'] === 0) {
                    self::$db->rollback();
                    self::$errores[] = 'El pedido debe tener al menos un producto.';
                    return false;
                }

                $folio = self::siguienteFolio();
                $consulta = self::$db->prepare(
                    'INSERT INTO pedidos (folio, empresa_id, cliente_id, usuario_id, origen, fecha, subtotal, descuento_pct,
                                          descuento, aplica_iva, iva, total, condiciones_envio, condiciones_compra, comentarios)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $empresa = (int) $datos['empresa_id'];
                $cliente = (int) $datos['cliente_id'];
                $usuario = $usuarioId ?: null;
                $origen  = $origen === 'portal' ? 'portal' : 'admin';
                $fecha   = (string) $datos['fecha'];
                $pct     = min(100, max(0, (float) ($datos['descuento_pct'] ?? 0)));
                $iva     = !empty($datos['aplica_iva']) ? 1 : 0;
                [$envio, $compra, $comentarios] = self::textos($datos);
                $consulta->bind_param(
                    'siiissdddiddsss',
                    $folio, $empresa, $cliente, $usuario, $origen, $fecha,
                    $totales['subtotal'], $pct, $totales['descuento'], $iva, $totales['iva'], $totales['total'],
                    $envio, $compra, $comentarios
                );
                $consulta->execute();
                $consulta->close();
                $id = (int) self::$db->insert_id;

                self::guardarDetalles($id, $cliente, $lineas);

                self::$db->commit();
                self::$ultimoId = $id;
                return true;
            } catch (\mysqli_sql_exception $e) {
                self::$db->rollback();
                if (strpos($e->getMessage(), 'Duplicate') !== false && strpos($e->getMessage(), 'folio') !== false) {
                    continue; // folio tomado en paralelo: se intenta con el siguiente
                }
                error_log('[Pedidos::crear] ' . $e->getMessage());
                break;
            }
        }

        self::$errores[] = 'No fue posible guardar el pedido.';
        return false;
    }

    /** Reemplaza encabezado y renglones; sólo mientras el pedido siga pendiente. */
    public static function actualizar(int $id, array $datos, array $lineas): bool
    {
        self::$errores = [];
        $pedido = self::buscarID($id);
        if (!$pedido || $pedido->estado !== 'pendiente') {
            self::$errores[] = 'Sólo se puede editar un pedido pendiente.';
            return false;
        }

        try {
            self::$db->begin_transaction();
            $totales = Cotizaciones::calcular($lineas, (float) ($datos['descuento_pct'] ?? 0), !empty($datos['aplica_iva']));

            $consulta = self::$db->prepare(
                "UPDATE pedidos SET empresa_id = ?, cliente_id = ?, fecha = ?, subtotal = ?, descuento_pct = ?, descuento = ?,
                        aplica_iva = ?, iva = ?, total = ?, condiciones_envio = ?, condiciones_compra = ?, comentarios = ?
                  WHERE id = ? AND estado = 'pendiente'"
            );
            $empresa = (int) $datos['empresa_id'];
            $cliente = (int) $datos['cliente_id'];
            $fecha   = (string) $datos['fecha'];
            $pct     = min(100, max(0, (float) ($datos['descuento_pct'] ?? 0)));
            $iva     = !empty($datos['aplica_iva']) ? 1 : 0;
            [$envio, $compra, $comentarios] = self::textos($datos);
            $consulta->bind_param(
                'iisdddiddsssi',
                $empresa, $cliente, $fecha, $totales['subtotal'], $pct, $totales['descuento'],
                $iva, $totales['iva'], $totales['total'], $envio, $compra, $comentarios, $id
            );
            $consulta->execute();
            $consulta->close();

            $borrar = self::$db->prepare('DELETE FROM pedido_detalles WHERE pedido_id = ?');
            $borrar->bind_param('i', $id);
            $borrar->execute();
            $borrar->close();

            self::guardarDetalles($id, $cliente, $lineas);

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $e) {
            self::$db->rollback();
            error_log('[Pedidos::actualizar] ' . $e->getMessage());
        }

        self::$errores[] = 'No fue posible actualizar el pedido.';
        return false;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private static function textos(array $datos): array
    {
        $limpio = static function ($v): ?string {
            $v = trim((string) $v);
            return $v === '' ? null : $v;
        };
        return [$limpio($datos['condiciones_envio'] ?? ''), $limpio($datos['condiciones_compra'] ?? ''), $limpio($datos['comentarios'] ?? '')];
    }

    /** Inserta los renglones (transacción abierta) y recuerda los precios especiales. */
    private static function guardarDetalles(int $pedidoId, int $clienteId, array $lineas): void
    {
        $insertar = self::$db->prepare(
            'INSERT INTO pedido_detalles (pedido_id, producto_id, cantidad, precio_unitario, descuento_pct, subtotal)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lineas as $productoId => $linea) {
            $productoId = (int) $productoId;
            $cantidad   = max(1, (int) ($linea['cantidad'] ?? 1));
            $precio     = max(0, round((float) ($linea['precio'] ?? 0), 2));
            $pct        = min(100, max(0, (float) ($linea['descuento_pct'] ?? 0)));
            $bruto      = $cantidad * $precio;
            $subtotal   = round($bruto - ($bruto * $pct / 100), 2);

            $insertar->bind_param('iiiddd', $pedidoId, $productoId, $cantidad, $precio, $pct, $subtotal);
            $insertar->execute();

            if (empty($linea['no_recordar']) && $clienteId > 0) {
                PreciosCliente::guardar($clienteId, $productoId, $precio);
            }
        }
        $insertar->close();
    }

    /**
     * Datos y renglones del pedido en el formato de Cotizaciones::crear /
     * Ventas::crear. Los precios no se vuelven a «recordar»: ya se recordaron
     * al guardar el pedido.
     */
    private static function paraDocumento(self $pedido): array
    {
        $lineas = [];
        foreach (self::detalles((int) $pedido->id) as $d) {
            $lineas[(int) $d['producto_id']] = [
                'cantidad'      => (int) $d['cantidad'],
                'precio'        => (float) $d['precio_unitario'],
                'descuento_pct' => (float) $d['descuento_pct'],
                'no_recordar'   => true,
            ];
        }

        $comentarios = trim((string) $pedido->comentarios);
        $datos = [
            'empresa_id'         => (int) $pedido->empresa_id,
            'cliente_id'         => (int) $pedido->cliente_id,
            'fecha'              => date('Y-m-d'),
            'vigencia_dias'      => 15,
            'estado'             => 'pendiente',
            'aplica_iva'         => (int) $pedido->aplica_iva,
            'descuento_pct'      => (float) $pedido->descuento_pct,
            'condiciones_envio'  => (string) $pedido->condiciones_envio,
            'condiciones_compra' => (string) $pedido->condiciones_compra,
            'comentarios'        => trim('Pedido ' . $pedido->folio . ($comentarios !== '' ? ". \n" . $comentarios : '')),
        ];

        return [$datos, $lineas];
    }

    /** «Marcar como cotización»: crea la cotización y enlaza el pedido. */
    public static function marcarCotizacion(int $id, int $usuarioId): bool
    {
        self::$errores = [];
        $pedido = self::buscarID($id);
        if (!$pedido) {
            self::$errores[] = 'El pedido ya no existe.';
            return false;
        }
        if ($pedido->estado !== 'pendiente') {
            self::$errores[] = 'El pedido ' . $pedido->folio . ' ya está ' . mb_strtolower(self::datosEstado($pedido->estado)['etiqueta']) . '.';
            return false;
        }

        [$datos, $lineas] = self::paraDocumento($pedido);
        $errores = Cotizaciones::validar($datos, $lineas);
        if ($errores !== []) {
            self::$errores = $errores;
            return false;
        }

        // Reserva el pedido antes de crear el documento: un segundo clic ya no lo encuentra pendiente.
        if (!self::reservar($id, 'pendiente', 'cotizado')) {
            return false;
        }

        if (!Cotizaciones::crear($datos, $lineas, $usuarioId)) {
            self::reservar($id, 'cotizado', 'pendiente');
            self::$errores = array_merge(['No se pudo crear la cotización.'], Cotizaciones::getErrores());
            return false;
        }

        self::enlazar($id, 'cotizacion_id', (int) Cotizaciones::$ultimoId);
        return true;
    }

    /** «Marcar como venta»: vende su cotización o registra una venta directa. */
    public static function marcarVenta(int $id, int $usuarioId): bool
    {
        self::$errores = [];
        $pedido = self::buscarID($id);
        if (!$pedido) {
            self::$errores[] = 'El pedido ya no existe.';
            return false;
        }
        if (!in_array($pedido->estado, ['pendiente', 'cotizado'], true)) {
            self::$errores[] = 'El pedido ' . $pedido->folio . ' ya está ' . mb_strtolower(self::datosEstado($pedido->estado)['etiqueta']) . '.';
            return false;
        }

        $previo = $pedido->estado;
        $cotizacionId = (int) $pedido->cotizacion_id;

        if ($cotizacionId > 0) {
            if (!self::reservar($id, $previo, 'vendido')) {
                return false;
            }
            if (!Cotizaciones::cambiarEstado($cotizacionId, 'vendido', $usuarioId)) {
                self::reservar($id, 'vendido', $previo);
                self::$errores = array_merge(['No se pudo vender la cotización ' . $pedido->cotizacion_folio . '.'], Cotizaciones::getErrores());
                return false;
            }
            $venta = Ventas::buscarPorCotizacion($cotizacionId);
            if ($venta) {
                self::enlazar($id, 'venta_id', (int) $venta->id);
            }
            return true;
        }

        [$datos, $lineas] = self::paraDocumento($pedido);
        $errores = Ventas::validar($datos, $lineas);
        if ($errores !== []) {
            self::$errores = $errores;
            return false;
        }
        if (!self::reservar($id, $previo, 'vendido')) {
            return false;
        }
        if (!Ventas::crear($datos, $lineas, $usuarioId)) {
            self::reservar($id, 'vendido', $previo);
            self::$errores = array_merge(['No se pudo registrar la venta.'], Ventas::getErrores());
            return false;
        }

        self::enlazar($id, 'venta_id', (int) Ventas::$ultimoId);
        return true;
    }

    /**
     * Elimina un pedido sin venta vinculada. Sus renglones se borran en cascada
     * y el pedido desaparece del portal del cliente. La cotización, si existe,
     * no se modifica.
     */
    public static function eliminar(int $id): bool
    {
        self::$errores = [];

        try {
            self::$db->begin_transaction();

            $consulta = self::$db->prepare(
                'SELECT id, folio, estado, venta_id, cotizacion_id FROM pedidos WHERE id = ? FOR UPDATE'
            );
            $consulta->bind_param('i', $id);
            $consulta->execute();
            $pedido = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$pedido) {
                self::$db->rollBack();
                self::$errores[] = 'El pedido ya no existe.';
                return false;
            }

            $ventaVinculada = (int) ($pedido['venta_id'] ?? 0) > 0
                || $pedido['estado'] === 'vendido'
                || self::ventaPorCotizacion((int) ($pedido['cotizacion_id'] ?? 0));

            if ($ventaVinculada) {
                self::$db->rollBack();
                self::$errores[] = 'El pedido ' . $pedido['folio'] . ' tiene una venta vinculada y no se puede eliminar. Cancela o elimina primero la venta.';
                return false;
            }

            $borrar = self::$db->prepare('DELETE FROM pedidos WHERE id = ?');
            $borrar->bind_param('i', $id);
            $borrar->execute();
            $eliminados = $borrar->affected_rows;
            $borrar->close();

            if ($eliminados !== 1) {
                self::$db->rollBack();
                self::$errores[] = 'No fue posible eliminar el pedido.';
                return false;
            }

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $e) {
            self::$db->rollBack();
            error_log('[Pedidos::eliminar] ' . $e->getMessage());
        }

        self::$errores[] = 'No fue posible eliminar el pedido.';
        return false;
    }

    /** ¿La cotización de este pedido ya generó una venta registrada? */
    private static function ventaPorCotizacion(int $cotizacionId): bool
    {
        if ($cotizacionId <= 0) {
            return false;
        }

        $consulta = self::$db->prepare(
            "SELECT id FROM ventas WHERE cotizacion_id = ? AND estado = 'registrada' LIMIT 1"
        );
        $consulta->bind_param('i', $cotizacionId);
        $consulta->execute();
        $venta = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $venta !== null;
    }

    /** Cancela un pedido pendiente o cotizado (la cotización generada no se toca). */
    public static function cancelar(int $id, int $usuarioId): bool
    {
        self::$errores = [];
        $consulta = self::$db->prepare(
            "UPDATE pedidos SET estado = 'cancelado', cancelado_por = ?, cancelado_en = NOW()
              WHERE id = ? AND estado IN ('pendiente', 'cotizado')"
        );
        $consulta->bind_param('ii', $usuarioId, $id);
        $consulta->execute();
        $cambio = $consulta->affected_rows === 1;
        $consulta->close();

        if (!$cambio) {
            self::$errores[] = 'Sólo se puede cancelar un pedido pendiente o cotizado.';
        }
        return $cambio;
    }

    /** Cambia el estado sólo si sigue en el esperado (evita la doble conversión). */
    private static function reservar(int $id, string $de, string $a): bool
    {
        $consulta = self::$db->prepare('UPDATE pedidos SET estado = ? WHERE id = ? AND estado = ?');
        $consulta->bind_param('sis', $a, $id, $de);
        $consulta->execute();
        $cambio = $consulta->affected_rows === 1;
        $consulta->close();

        if (!$cambio) {
            self::$errores[] = 'El pedido cambió mientras tanto; recarga la página.';
        }
        return $cambio;
    }

    private static function enlazar(int $id, string $columna, int $valor): void
    {
        if (!in_array($columna, ['cotizacion_id', 'venta_id'], true) || $valor <= 0) {
            return;
        }
        $consulta = self::$db->prepare("UPDATE pedidos SET $columna = ? WHERE id = ?");
        $consulta->bind_param('ii', $valor, $id);
        $consulta->execute();
        $consulta->close();
    }
}
