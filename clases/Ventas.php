<?php

namespace App;

/**
 * Ventas — ventas DIRECTAS, registradas sin pasar por una cotización.
 *
 * Una venta directa tiene la misma forma que una cotización (emisor, cliente,
 * fecha, renglones, descuentos, IVA, términos comerciales y logísticos) y usa
 * el mismo cálculo de totales (Cotizaciones::calcular), de modo que el
 * "Resumen de liquidación" es idéntico en ambos documentos.
 *
 * Inventario:
 *   • Al registrarse la venta descuenta el stock de sus renglones.
 *   • Si no alcanza, el stock queda NEGATIVO (se vende y se fabrica después):
 *     se avisa, pero no se bloquea.
 *   • Al editarla se devuelve lo anterior y se descuenta lo nuevo; al
 *     cancelarla o eliminarla el stock regresa al almacén.
 *
 * El listado de "Ventas" mezcla las ventas directas con las cotizaciones en
 * estado Vendido (ver historial()): es el registro de TODO lo vendido.
 */
class Ventas
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten en la tabla ventas. */
    protected static $columnasDB = [
        'id', 'cotizacion_id', 'folio', 'empresa_id', 'cliente_id', 'usuario_id', 'fecha',
        'estado', 'subtotal', 'descuento', 'aplica_iva', 'iva', 'total',
        'condiciones_envio', 'condiciones_compra', 'comentarios',
    ];

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** @var int Id de la última venta creada (para redirigir al detalle). */
    public static $ultimoId = 0;

    /** Estados admitidos por la columna ENUM. */
    public const ESTADOS = ['registrada', 'cancelada'];

    /** Estados con su etiqueta visual. */
    public const ESTILOS_ESTADO = [
        'registrada' => ['etiqueta' => 'Vendido',   'icono' => 'check_circle', 'clase' => 'badge--aprobado'],
        'cancelada'  => ['etiqueta' => 'Cancelada', 'icono' => 'cancel',       'clase' => 'badge--error'],
    ];

    /* --- Columnas propias ------------------------------------------------ */
    public $id;
    /** Cotización de origen; NULL en una venta directa. */
    public $cotizacion_id;
    public $folio;
    public $empresa_id;
    public $cliente_id;
    public $usuario_id;
    public $fecha;
    /** La venta no caduca: se conserva en 0 para compartir el PDF con las cotizaciones. */
    public $vigencia_dias = 0;
    public $estado;
    public $subtotal;
    public $descuento;
    public $aplica_iva;
    public $iva;
    public $total;
    public $condiciones_envio;
    public $condiciones_compra;
    public $comentarios;
    public $creado_en;

    /* --- Datos de unión (sólo lectura, vienen del JOIN) ------------------ */
    public $empresa_nombre;
    public $empresa_rfc;
    public $empresa_logo;
    public $empresa_direccion;
    public $empresa_ciudad;
    public $empresa_estado;
    public $empresa_cp;
    public $empresa_telefono;
    public $empresa_email;
    public $empresa_regimen;
    public $empresa_condiciones_envio;
    public $empresa_condiciones_compra;

    public $cliente_nombre;
    public $cliente_empresa;
    public $cliente_alias;
    public $cliente_rfc;
    public $cliente_telefono;
    public $cliente_email;
    public $cliente_ubicacion;
    public $cliente_vip;
    public $cliente_descuento;

    public $usuario_nombre;
    public $usuario_email;

    public function __construct(array $args = [])
    {
        $this->id         = $args['id'] ?? null;
        $this->folio      = $args['folio'] ?? '';
        $this->empresa_id = isset($args['empresa_id']) ? (int) $args['empresa_id'] : 0;
        $this->cliente_id = isset($args['cliente_id']) ? (int) $args['cliente_id'] : 0;
        $this->usuario_id = isset($args['usuario_id']) ? (int) $args['usuario_id'] : 0;
        $this->fecha      = $args['fecha'] ?? date('Y-m-d');
        $this->estado     = $args['estado'] ?? 'registrada';
        $this->subtotal   = isset($args['subtotal']) ? (float) $args['subtotal'] : 0.0;
        $this->descuento  = isset($args['descuento']) ? (float) $args['descuento'] : 0.0;
        $this->aplica_iva = isset($args['aplica_iva']) ? (int) $args['aplica_iva'] : 1;
        $this->iva        = isset($args['iva']) ? (float) $args['iva'] : 0.0;
        $this->total      = isset($args['total']) ? (float) $args['total'] : 0.0;
        $this->condiciones_envio  = $args['condiciones_envio'] ?? '';
        $this->condiciones_compra = $args['condiciones_compra'] ?? '';
        $this->comentarios = $args['comentarios'] ?? '';
        $this->creado_en   = $args['creado_en'] ?? '';
    }

    /* --------------------------------------------------------------------
     * CONEXIÓN / ERRORES
     * ------------------------------------------------------------------ */

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /** Devuelve y limpia los errores acumulados. */
    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /** Datos de presentación de un estado. */
    public static function datosEstado(string $estado): array
    {
        return self::ESTILOS_ESTADO[$estado] ?? self::ESTILOS_ESTADO['registrada'];
    }

    /** Devuelve los atributos persistibles, sin el id. */
    public function atributos(): array
    {
        $atributos = [];

        foreach (self::$columnasDB as $columna) {
            if ($columna === 'id') {
                continue;
            }
            $atributos[$columna] = $this->$columna;
        }

        return $atributos;
    }

    /* --------------------------------------------------------------------
     * FOLIO
     * ------------------------------------------------------------------ */

    /** Siguiente folio libre del año: VEN-2026-0001, VEN-2026-0002… */
    public static function siguienteFolio(): string
    {
        $prefijo  = 'VEN-' . date('Y') . '-';
        $posicion = strlen($prefijo) + 1;
        $patron   = $prefijo . '%';

        $consulta = self::$db->prepare(
            'SELECT MAX(CAST(SUBSTRING(folio, ?) AS UNSIGNED)) AS maximo
               FROM ventas
              WHERE folio LIKE ?'
        );
        $consulta->bind_param('is', $posicion, $patron);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        $ultimo = is_array($registro) ? (int) $registro['maximo'] : 0;

        return $prefijo . str_pad((string) ($ultimo + 1), 4, '0', STR_PAD_LEFT);
    }

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** Venta registrada nacida de una cotización, o null. */
    public static function buscarPorCotizacion(int $cotizacionId): ?self
    {
        $consulta = self::$db->prepare('SELECT id FROM ventas WHERE cotizacion_id = ? AND estado = "registrada"');
        $consulta->bind_param('i', $cotizacionId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ? self::buscarID((int) $fila['id']) : null;
    }

    /** Carga una venta con los datos del negocio, el cliente y el autor. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $consulta = self::$db->prepare(
            'SELECT v.*,
                    e.nombre    AS empresa_nombre,  e.rfc        AS empresa_rfc,
                    e.logo      AS empresa_logo,    e.direccion  AS empresa_direccion,
                    e.ciudad    AS empresa_ciudad,  e.estado     AS empresa_estado,
                    e.cp        AS empresa_cp,      e.telefono   AS empresa_telefono,
                    e.email     AS empresa_email,   e.regimen    AS empresa_regimen,
                    e.condiciones_envio  AS empresa_condiciones_envio,
                    e.condiciones_compra AS empresa_condiciones_compra,
                    cl.nombre      AS cliente_nombre,   cl.nombre_empresa AS cliente_empresa,
                    cl.alias       AS cliente_alias,    cl.rfc            AS cliente_rfc,
                    cl.telefono    AS cliente_telefono, cl.email          AS cliente_email,
                    cl.ubicacion   AS cliente_ubicacion, cl.vip           AS cliente_vip,
                    cl.descuento_pct AS cliente_descuento,
                    u.nombre  AS usuario_nombre,
                    u.email   AS usuario_email
               FROM ventas v
               JOIN empresas  e  ON e.id  = v.empresa_id
               JOIN clientes  cl ON cl.id = v.cliente_id
               LEFT JOIN usuarios u ON u.id = v.usuario_id
              WHERE v.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /**
     * Renglones de una venta con los datos del producto
     * (misma forma que Cotizaciones::detalles()).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function detalles(int $ventaId): array
    {
        $consulta = self::$db->prepare(
            'SELECT d.*, p.nombre AS producto_nombre, p.sku AS producto_sku,
                    p.unidad AS producto_unidad, p.imagen AS producto_imagen,
                    p.precio AS producto_precio, p.categoria AS producto_categoria
               FROM venta_detalles d
               JOIN productos p ON p.id = d.producto_id
              WHERE d.venta_id = ?
              ORDER BY d.id ASC'
        );
        $consulta->bind_param('i', $ventaId);
        $consulta->execute();
        $registros = $consulta->get_result();

        $detalles = [];
        while ($fila = $registros->fetch_assoc()) {
            $detalles[] = $fila;
        }

        $consulta->close();
        return $detalles;
    }

    /** Cuenta las ventas directas registradas de un cliente. */
    public static function contarPorCliente(int $clienteId): int
    {
        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM ventas WHERE cliente_id = ? AND estado = "registrada"'
        );
        $consulta->bind_param('i', $clienteId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return (int) ($fila['total'] ?? 0);
    }

    /* --------------------------------------------------------------------
     * HISTORIAL UNIFICADO (ventas directas + cotizaciones vendidas)
     * ------------------------------------------------------------------ */

    /**
     * Subconsulta que une TODO lo vendido con una forma común.
     *
     * Con estado = 'cancelada' sólo aparecen las ventas directas canceladas;
     * en cualquier otro caso, las ventas registradas y las cotizaciones
     * en estado Vendido.
     */
    private static function origenUnificado(bool $canceladas): string
    {
        // Una sola tabla: `ventas`. Las que nacieron de una cotización conservan
        // su ruta (id/folio de la cotización) para no cambiar los enlaces del
        // listado; venta_id / venta_folio identifican la operación contable.
        return 'SELECT IF(v.cotizacion_id IS NULL, "venta", "cotizacion") AS origen,
                       IF(v.cotizacion_id IS NULL, v.id, v.cotizacion_id) AS id,
                       v.id AS venta_id, v.folio AS venta_folio,
                       IF(v.cotizacion_id IS NULL, v.folio, c.folio) AS folio,
                       v.fecha, v.empresa_id, v.cliente_id, v.total, v.estado, v.creado_en,
                       e.nombre AS empresa_nombre,
                       cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                       cl.alias AS cliente_alias, cl.rfc AS cliente_rfc
                  FROM ventas v
                  JOIN empresas e  ON e.id  = v.empresa_id
                  JOIN clientes cl ON cl.id = v.cliente_id
                  LEFT JOIN cotizaciones c ON c.id = v.cotizacion_id
                 WHERE ' . ($canceladas
                    ? 'v.estado = "cancelada" AND v.cotizacion_id IS NULL'
                    : 'v.estado = "registrada"');
    }

    /** Condiciones WHERE (sobre la subconsulta unificada) y sus parámetros. */
    private static function condiciones(array $filtros): array
    {
        $donde   = ['1 = 1'];
        $tipos   = '';
        $valores = [];

        $termino = trim((string) ($filtros['q'] ?? ''));
        if ($termino !== '') {
            $donde[]  = '(t.folio LIKE ? OR t.cliente_nombre LIKE ? OR IFNULL(t.cliente_empresa, "") LIKE ?'
                      . ' OR IFNULL(t.cliente_alias, "") LIKE ? OR IFNULL(t.cliente_rfc, "") LIKE ?)';
            $tipos   .= 'sssss';
            $patron   = '%' . $termino . '%';
            $valores  = array_merge($valores, [$patron, $patron, $patron, $patron, $patron]);
        }

        $empresaId = (int) ($filtros['empresa_id'] ?? 0);
        if ($empresaId > 0) {
            $donde[]   = 't.empresa_id = ?';
            $tipos    .= 'i';
            $valores[] = $empresaId;
        }

        $clienteId = (int) ($filtros['cliente_id'] ?? 0);
        if ($clienteId > 0) {
            $donde[]   = 't.cliente_id = ?';
            $tipos    .= 'i';
            $valores[] = $clienteId;
        }

        $origen = (string) ($filtros['origen'] ?? '');
        if (in_array($origen, ['venta', 'cotizacion'], true)) {
            $donde[]   = 't.origen = ?';
            $tipos    .= 's';
            $valores[] = $origen;
        }

        $desde = (string) ($filtros['desde'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $donde[]   = 't.fecha >= ?';
            $tipos    .= 's';
            $valores[] = $desde;
        }

        $hasta = (string) ($filtros['hasta'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            $donde[]   = 't.fecha <= ?';
            $tipos    .= 's';
            $valores[] = $hasta;
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Historial paginado de todo lo vendido.
     *
     * @param array{q?: string, empresa_id?: int, cliente_id?: int, origen?: string,
     *              desde?: string, hasta?: string, estado?: string} $filtros
     * @return array<int, object>
     */
    public static function historial(array $filtros = [], int $limite = 15, int $offset = 0): array
    {
        $limite = max(1, $limite);
        $offset = max(0, $offset);
        $canceladas = ($filtros['estado'] ?? '') === 'cancelada';

        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT t.* FROM (' . self::origenUnificado($canceladas) . ') t
              WHERE ' . $donde . '
              ORDER BY t.fecha DESC, t.creado_en DESC, t.id DESC
              LIMIT ? OFFSET ?'
        );

        $tipos    .= 'ii';
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $lista = [];
        while ($fila = $registros->fetch_assoc()) {
            $lista[] = (object) $fila;
        }

        $consulta->close();
        return $lista;
    }

    /** Total de registros del historial (para la paginación). */
    public static function contarHistorial(array $filtros = []): int
    {
        $canceladas = ($filtros['estado'] ?? '') === 'cancelada';
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM (' . self::origenUnificado($canceladas) . ') t WHERE ' . $donde
        );

        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return (int) ($fila['total'] ?? 0);
    }

    /**
     * Tarjetas de resumen del historial (mismos filtros que el listado).
     *
     * @return array{registros: int, monto: float, directas: int, cotizaciones: int}
     */
    public static function resumenHistorial(array $filtros = []): array
    {
        $filtros['estado'] = '';
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT COUNT(*)                         AS registros,
                    COALESCE(SUM(t.total), 0)         AS monto,
                    COALESCE(SUM(t.origen = "venta"), 0)      AS directas,
                    COALESCE(SUM(t.origen = "cotizacion"), 0) AS cotizaciones
               FROM (' . self::origenUnificado(false) . ') t
              WHERE ' . $donde
        );

        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return [
            'registros'    => (int) ($fila['registros'] ?? 0),
            'monto'        => (float) ($fila['monto'] ?? 0),
            'directas'     => (int) ($fila['directas'] ?? 0),
            'cotizaciones' => (int) ($fila['cotizaciones'] ?? 0),
        ];
    }

    /**
     * Ventas (directas y nacidas de cotización) de una empresa en un periodo,
     * para el panel de ganancias.
     *
     * @return array{cantidad: int, directas: int, monto: float}
     */
    public static function totalesEmpresa(int $empresaId, string $desde = '', string $hasta = ''): array
    {
        $donde   = ['empresa_id = ?', 'estado = "registrada"'];
        $tipos   = 'i';
        $valores = [$empresaId];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $donde[]   = 'fecha >= ?';
            $tipos    .= 's';
            $valores[] = $desde;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            $donde[]   = 'fecha <= ?';
            $tipos    .= 's';
            $valores[] = $hasta;
        }

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS cantidad, COALESCE(SUM(cotizacion_id IS NULL), 0) AS directas,
                    COALESCE(SUM(total), 0) AS monto
               FROM ventas WHERE ' . implode(' AND ', $donde)
        );
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return [
            'cantidad' => (int) ($fila['cantidad'] ?? 0),
            'directas' => (int) ($fila['directas'] ?? 0),
            'monto'    => (float) ($fila['monto'] ?? 0),
        ];
    }

    /* --------------------------------------------------------------------
     * VENTA NACIDA DE UNA COTIZACIÓN (las llama Cotizaciones dentro de SU
     * transacción; ninguna abre ni cierra transacciones)
     * ------------------------------------------------------------------ */

    /** Venta ligada a la cotización, bloqueada; null si no existe. */
    private static function ventaDeCotizacion(int $cotizacionId): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM ventas WHERE cotizacion_id = ? FOR UPDATE');
        $consulta->bind_param('i', $cotizacionId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /** Copia los renglones de la cotización a la venta (reemplazando los previos). */
    private static function copiarRenglones(int $cotizacionId, int $ventaId): void
    {
        $borrar = self::$db->prepare('DELETE FROM venta_detalles WHERE venta_id = ?');
        $borrar->bind_param('i', $ventaId);
        $borrar->execute();
        $borrar->close();

        $copiar = self::$db->prepare(
            'INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio_unitario, descuento_pct, subtotal)
             SELECT ?, producto_id, cantidad, precio_unitario, descuento_pct, subtotal
               FROM cotizacion_detalles WHERE cotizacion_id = ? ORDER BY id'
        );
        $copiar->bind_param('ii', $ventaId, $cotizacionId);
        $copiar->execute();
        $copiar->close();
    }

    /** Encabezado de la cotización → venta (fecha, cliente, importes, términos). */
    private static function copiarEncabezado(Cotizaciones $cotizacion, int $ventaId, int $usuarioId): void
    {
        $consulta = self::$db->prepare(
            'UPDATE ventas SET empresa_id = ?, cliente_id = ?, fecha = ?, estado = "registrada",
                    subtotal = ?, descuento = ?, aplica_iva = ?, iva = ?, total = ?,
                    condiciones_envio = ?, condiciones_compra = ?, comentarios = ?
              WHERE id = ?'
        );
        $empresa = (int) $cotizacion->empresa_id;
        $cliente = (int) $cotizacion->cliente_id;
        $iva     = (int) $cotizacion->aplica_iva;
        $consulta->bind_param(
            'iisddiddsssi',
            $empresa, $cliente, $cotizacion->fecha,
            $cotizacion->subtotal, $cotizacion->descuento, $iva, $cotizacion->iva, $cotizacion->total,
            $cotizacion->condiciones_envio, $cotizacion->condiciones_compra, $cotizacion->comentarios,
            $ventaId
        );
        $consulta->execute();
        $consulta->close();
    }

    /**
     * La cotización pasa a "vendido": crea (o reactiva) SU venta y descuenta
     * el stock una sola vez. Repetirlo con la venta ya registrada no hace nada.
     */
    public static function generarDesdeCotizacion(int $cotizacionId, int $usuarioId): bool
    {
        $cotizacion = Cotizaciones::buscarID($cotizacionId);
        if (!$cotizacion) {
            self::$errores[] = 'La cotización ya no existe.';
            return false;
        }

        $venta = self::ventaDeCotizacion($cotizacionId);

        if ($venta && $venta['estado'] === 'registrada') {
            return true; // Idempotente: ya contabilizada y ya descontada.
        }

        if ($venta) {
            $ventaId = (int) $venta['id'];
        } else {
            $ventaId = 0;
            for ($intento = 0; $intento < 3 && $ventaId === 0; $intento++) {
                try {
                    $folio = self::siguienteFolio();
                    $insertar = self::$db->prepare(
                        'INSERT INTO ventas (cotizacion_id, folio, empresa_id, cliente_id, usuario_id, fecha)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $empresa = (int) $cotizacion->empresa_id;
                    $cliente = (int) $cotizacion->cliente_id;
                    $insertar->bind_param('isiiis', $cotizacionId, $folio, $empresa, $cliente, $usuarioId, $cotizacion->fecha);
                    $insertar->execute();
                    $ventaId = (int) self::$db->insert_id;
                    $insertar->close();
                } catch (\mysqli_sql_exception $excepcion) {
                    if (strpos($excepcion->getMessage(), 'uq_venta_folio') === false) {
                        throw $excepcion;
                    }
                }
            }
            if ($ventaId === 0) {
                self::$errores[] = 'No fue posible asignar un folio a la venta.';
                return false;
            }
        }

        self::copiarEncabezado($cotizacion, $ventaId, $usuarioId);
        self::copiarRenglones($cotizacionId, $ventaId);

        if (!Inventario::descontar(
            self::detalles($ventaId),
            0,
            $usuarioId,
            'Salida por la venta de la cotización ' . $cotizacion->folio,
            $ventaId
        )) {
            return false;
        }

        return self::sincronizarContabilidad($ventaId, $usuarioId);
    }

    /** Refleja la venta en su ingreso contable; propaga el error si el periodo está cerrado. */
    private static function sincronizarContabilidad(int $ventaId, int $usuarioId): bool
    {
        if (Contabilidad::sincronizarIngreso($ventaId, $usuarioId)) {
            return true;
        }

        self::$errores = array_merge(self::$errores, Contabilidad::getErrores());
        return false;
    }

    /** La cotización deja de estar vendida: la venta se cancela y el stock regresa. */
    public static function cancelarDeCotizacion(int $cotizacionId, int $usuarioId, string $nota): bool
    {
        $venta = self::ventaDeCotizacion($cotizacionId);

        if (!$venta || $venta['estado'] !== 'registrada') {
            return true;
        }

        $ventaId = (int) $venta['id'];

        if (!Inventario::devolver(self::detalles($ventaId), 0, $usuarioId, $nota, $ventaId)) {
            return false;
        }

        $cancelar = self::$db->prepare('UPDATE ventas SET estado = "cancelada" WHERE id = ?');
        $cancelar->bind_param('i', $ventaId);
        $cancelar->execute();
        $cancelar->close();

        return self::sincronizarContabilidad($ventaId, $usuarioId);
    }

    /** Se editó una cotización vendida: la venta y el stock siguen sus nuevos renglones. */
    public static function sincronizarDeCotizacion(int $cotizacionId, int $usuarioId): bool
    {
        $venta = self::ventaDeCotizacion($cotizacionId);

        if (!$venta || $venta['estado'] !== 'registrada') {
            return self::generarDesdeCotizacion($cotizacionId, $usuarioId);
        }

        $ventaId    = (int) $venta['id'];
        $cotizacion = Cotizaciones::buscarID($cotizacionId);

        if (!$cotizacion || !Inventario::devolver(
            self::detalles($ventaId),
            0,
            $usuarioId,
            'Se ajusta la venta ' . $venta['folio'] . ' por edición de la cotización',
            $ventaId
        )) {
            return false;
        }

        self::copiarEncabezado($cotizacion, $ventaId, $usuarioId);
        self::copiarRenglones($cotizacionId, $ventaId);

        if (!Inventario::descontar(
            self::detalles($ventaId),
            0,
            $usuarioId,
            'Salida por la venta de la cotización ' . $cotizacion->folio,
            $ventaId
        )) {
            return false;
        }

        return self::sincronizarContabilidad($ventaId, $usuarioId);
    }

    /* --------------------------------------------------------------------
     * VALIDACIÓN
     * ------------------------------------------------------------------ */

    /**
     * Valida el encabezado y los renglones tal como llegan del formulario.
     *
     * @return string[] Lista vacía cuando todo está correcto.
     */
    public static function validar(array $datos, array $lineas): array
    {
        $errores = [];

        if ((int) ($datos['empresa_id'] ?? 0) <= 0) {
            $errores[] = 'Debés elegir el negocio que emite la venta.';
        }
        if ((int) ($datos['cliente_id'] ?? 0) <= 0) {
            $errores[] = 'Debés elegir el cliente de la venta.';
        }

        $fecha = (string) ($datos['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $errores[] = 'La fecha de la venta no es válida.';
        }

        $descuento = (float) ($datos['descuento_pct'] ?? 0);
        if ($descuento < 0 || $descuento > 100) {
            $errores[] = 'El descuento global debe estar entre 0% y 100%.';
        }

        if (empty($lineas)) {
            $errores[] = 'Agregá al menos un producto a la venta.';
            return $errores;
        }

        foreach ($lineas as $productoId => $linea) {
            $producto = Productos::buscarID((int) $productoId);

            if (!$producto) {
                $errores[] = 'Uno de los productos ya no existe en el catálogo.';
                break;
            }
            if ((int) $producto->activo !== 1) {
                $errores[] = 'El producto "' . $producto->nombre . '" está inactivo.';
                break;
            }
            if ((int) ($linea['cantidad'] ?? 0) < 1) {
                $errores[] = 'La cantidad de "' . $producto->nombre . '" debe ser al menos 1.';
                break;
            }
            if (!is_numeric($linea['precio'] ?? '') || (float) $linea['precio'] < 0) {
                $errores[] = 'El precio de "' . $producto->nombre . '" no es válido.';
                break;
            }
            $pct = (float) ($linea['descuento_pct'] ?? 0);
            if ($pct < 0 || $pct > 100) {
                $errores[] = 'El descuento del renglón de "' . $producto->nombre . '" debe estar entre 0% y 100%.';
                break;
            }
        }

        return $errores;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

    /** Encabezado a partir de lo capturado (sin totales ni folio). */
    private static function desdeDatos(array $datos): self
    {
        return new self([
            'empresa_id'         => (int) $datos['empresa_id'],
            'cliente_id'         => (int) $datos['cliente_id'],
            'fecha'              => $datos['fecha'],
            'aplica_iva'         => !empty($datos['aplica_iva']) ? 1 : 0,
            'condiciones_envio'  => trim((string) ($datos['condiciones_envio'] ?? '')),
            'condiciones_compra' => trim((string) ($datos['condiciones_compra'] ?? '')),
            'comentarios'        => trim((string) ($datos['comentarios'] ?? '')),
        ]);
    }

    /**
     * Registra una venta con todos sus renglones y descuenta el inventario.
     *
     * @param array $datos     Encabezado ya validado.
     * @param array $lineas    [producto_id => [cantidad, precio, descuento_pct, no_recordar]]
     * @param int   $usuarioId Administrador que la registra.
     */
    public static function crear(array $datos, array $lineas, int $usuarioId): bool
    {
        $intentos = 0;
        self::$errores = [];

        do {
            $folio = self::siguienteFolio();

            try {
                self::$db->begin_transaction();

                $venta = self::desdeDatos($datos);
                $venta->folio      = $folio;
                $venta->usuario_id = $usuarioId;
                $venta->estado     = 'registrada';

                $totales = Cotizaciones::calcular(
                    $lineas,
                    (float) ($datos['descuento_pct'] ?? 0),
                    (bool) $venta->aplica_iva
                );

                if ($totales['renglones'] === 0) {
                    self::$db->rollBack();
                    self::$errores[] = 'La venta debe tener al menos un renglón.';
                    return false;
                }

                $venta->subtotal  = $totales['subtotal'];
                $venta->descuento = $totales['descuento'];
                $venta->iva       = $totales['iva'];
                $venta->total     = $totales['total'];

                if (!$venta->insertar() || !self::guardarDetalles((int) $venta->id, $lineas, $venta->cliente_id)) {
                    self::$db->rollBack();
                    return false;
                }

                // La salida de almacén ocurre en la misma transacción.
                if (!Inventario::descontar(
                    self::detalles((int) $venta->id),
                    0,
                    $usuarioId,
                    'Salida por la venta directa ' . $folio,
                    (int) $venta->id
                )) {
                    self::$db->rollBack();
                    return false;
                }

                if (!Contabilidad::sincronizarIngreso((int) $venta->id, $usuarioId)) {
                    self::$errores = array_merge(self::$errores, Contabilidad::getErrores());
                    self::$db->rollBack();
                    return false;
                }

                self::$db->commit();
                return true;
            } catch (\mysqli_sql_exception $excepcion) {
                self::$db->rollBack();
                $intentos++;

                // Folio repetido por concurrencia: se intenta con el siguiente.
                if ($intentos < 3 && strpos($excepcion->getMessage(), 'Duplicate') !== false) {
                    continue;
                }

                error_log('[Ventas::crear] ' . $excepcion->getMessage());
                self::$errores[] = 'No fue posible registrar la venta.';
                return false;
            }
        } while ($intentos < 3);

        self::$errores[] = 'No fue posible asignar un folio a la venta.';
        return false;
    }

    /**
     * Reemplaza el encabezado y los renglones de una venta existente
     * y sincroniza el inventario.
     */
    public static function actualizar(array $datos, array $lineas, int $usuarioId): bool
    {
        self::$errores = [];

        try {
            self::$db->begin_transaction();

            // Se bloquea la fila: dos ediciones simultáneas no mueven el stock a la vez.
            $id = (int) ($datos['id'] ?? 0);
            $bloqueo = self::$db->prepare('SELECT id FROM ventas WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $id);
            $bloqueo->execute();
            $bloqueo->close();

            $venta = self::buscarID($id);
            if (!$venta) {
                self::$db->rollBack();
                self::$errores[] = 'La venta ya no existe.';
                return false;
            }
            if ($venta->cotizacion_id !== null) {
                self::$db->rollBack();
                self::$errores[] = 'Esta venta proviene de una cotización: cancélala desde la cotización.';
                return false;
            }

            $estadoPrevio    = (string) $venta->estado;
            $detallesPrevios = self::detalles($id);

            $venta->empresa_id         = (int) $datos['empresa_id'];
            $venta->cliente_id         = (int) $datos['cliente_id'];
            $venta->fecha              = $datos['fecha'];
            $venta->aplica_iva         = !empty($datos['aplica_iva']) ? 1 : 0;
            $venta->estado             = in_array($datos['estado'] ?? '', self::ESTADOS, true)
                ? $datos['estado'] : $venta->estado;
            $venta->condiciones_envio  = trim((string) ($datos['condiciones_envio'] ?? ''));
            $venta->condiciones_compra = trim((string) ($datos['condiciones_compra'] ?? ''));
            $venta->comentarios        = trim((string) ($datos['comentarios'] ?? ''));

            $totales = Cotizaciones::calcular(
                $lineas,
                (float) ($datos['descuento_pct'] ?? 0),
                (bool) $venta->aplica_iva
            );

            if ($totales['renglones'] === 0) {
                self::$db->rollBack();
                self::$errores[] = 'La venta debe tener al menos un renglón.';
                return false;
            }

            $venta->subtotal  = $totales['subtotal'];
            $venta->descuento = $totales['descuento'];
            $venta->iva       = $totales['iva'];
            $venta->total     = $totales['total'];

            if (!$venta->modificar()) {
                self::$db->rollBack();
                return false;
            }

            // Los renglones se reconstruyen enteros.
            $borrar = self::$db->prepare('DELETE FROM venta_detalles WHERE venta_id = ?');
            $borrar->bind_param('i', $id);
            $borrar->execute();
            $borrar->close();

            if (!self::guardarDetalles($id, $lineas, (int) $venta->cliente_id)) {
                self::$db->rollBack();
                return false;
            }

            // Inventario: se devuelve lo que descontó la versión anterior y
            // se descuenta lo nuevo (sólo si la venta sigue registrada).
            if ($estadoPrevio === 'registrada'
                && !Inventario::devolver(
                    $detallesPrevios,
                    0,
                    $usuarioId,
                    'Se ajusta la venta ' . $venta->folio . ' (se devuelve lo anterior)',
                    $id
                )) {
                self::$db->rollBack();
                return false;
            }

            if ($venta->estado === 'registrada'
                && !Inventario::descontar(
                    self::detalles($id),
                    0,
                    $usuarioId,
                    'Salida por la venta directa ' . $venta->folio,
                    $id
                )) {
                self::$db->rollBack();
                return false;
            }

            if (!Contabilidad::sincronizarIngreso($id, $usuarioId)) {
                self::$errores = array_merge(self::$errores, Contabilidad::getErrores());
                self::$db->rollBack();
                return false;
            }

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Ventas::actualizar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible actualizar la venta.';
            return false;
        }
    }

    /**
     * Elimina la venta (los renglones caen en cascada) y devuelve el stock
     * si seguía registrada.
     */
    public static function eliminar(int $id, int $usuarioId): bool
    {
        self::$errores = [];

        try {
            self::$db->begin_transaction();

            $bloqueo = self::$db->prepare('SELECT id FROM ventas WHERE id = ? FOR UPDATE');
            $bloqueo->bind_param('i', $id);
            $bloqueo->execute();
            $bloqueo->close();

            $venta = self::buscarID($id);
            if (!$venta) {
                self::$db->rollBack();
                self::$errores[] = 'La venta ya no existe.';
                return false;
            }

            if ($venta->estado === 'registrada'
                && !Inventario::devolver(
                    self::detalles($id),
                    0,
                    $usuarioId,
                    'Se devuelve el stock de la venta ' . $venta->folio . ' (eliminada)',
                    $id
                )) {
                self::$db->rollBack();
                return false;
            }

            // El ingreso contable no se borra: queda cancelado (y sin venta de origen).
            $marcar = self::$db->prepare('UPDATE ventas SET estado = "cancelada" WHERE id = ?');
            $marcar->bind_param('i', $id);
            $marcar->execute();
            $marcar->close();
            if (!Contabilidad::sincronizarIngreso($id, $usuarioId)) {
                self::$errores = array_merge(self::$errores, Contabilidad::getErrores());
                self::$db->rollBack();
                return false;
            }

            $consulta = self::$db->prepare('DELETE FROM ventas WHERE id = ?');
            $consulta->bind_param('i', $id);
            $correcto = $consulta->execute();
            $consulta->close();

            if (!$correcto) {
                self::$db->rollBack();
                self::$errores[] = 'No fue posible eliminar la venta.';
                return false;
            }

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Ventas::eliminar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible eliminar la venta.';
            return false;
        }
    }

    /** Inserta el encabezado dentro de la transacción activa. */
    private function insertar(): bool
    {
        $atributos  = $this->atributos();
        $columnas   = implode(', ', array_keys($atributos));
        $marcadores = implode(', ', array_fill(0, count($atributos), '?'));

        $consulta = self::$db->prepare("INSERT INTO ventas ($columnas) VALUES ($marcadores)");
        $valores  = array_values($atributos);
        $consulta->bind_param(str_repeat('s', count($valores)), ...$valores);
        $consulta->execute();
        $consulta->close();

        $this->id = (int) self::$db->insert_id;
        self::$ultimoId = $this->id;

        return $this->id > 0;
    }

    /** Actualiza el encabezado dentro de la transacción activa. */
    private function modificar(): bool
    {
        $atributos    = $this->atributos();
        $asignaciones = [];

        foreach (array_keys($atributos) as $columna) {
            $asignaciones[] = "$columna = ?";
        }

        $consulta = self::$db->prepare(
            'UPDATE ventas SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
        );

        $valores   = array_values($atributos);
        $valores[] = (int) $this->id;
        $consulta->bind_param(str_repeat('s', count($atributos)) . 'i', ...$valores);
        $consulta->execute();
        $consulta->close();

        return true;
    }

    /**
     * Inserta los renglones y guarda los precios especiales del cliente
     * (salvo los marcados como "No recordar este precio").
     *
     * @param array $lineas [producto_id => [cantidad, precio, descuento_pct, no_recordar]]
     */
    private static function guardarDetalles(int $ventaId, array $lineas, int $clienteId): bool
    {
        $insertar = self::$db->prepare(
            'INSERT INTO venta_detalles
                (venta_id, producto_id, cantidad, precio_unitario, descuento_pct, subtotal)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($lineas as $productoId => $linea) {
            $productoId = (int) $productoId;
            $cantidad   = max(1, (int) ($linea['cantidad'] ?? 1));
            $precio     = max(0, round((float) ($linea['precio'] ?? 0), 2));
            $pctRenglon = min(100, max(0, (float) ($linea['descuento_pct'] ?? 0)));
            $bruto      = $cantidad * $precio;
            $subtotal   = round($bruto - ($bruto * $pctRenglon / 100), 2);

            $insertar->bind_param('iiiddd', $ventaId, $productoId, $cantidad, $precio, $pctRenglon, $subtotal);

            if (!$insertar->execute()) {
                $insertar->close();
                self::$errores[] = 'No fue posible guardar un renglón de la venta.';
                return false;
            }

            if (empty($linea['no_recordar']) && $clienteId > 0 && $productoId > 0) {
                PreciosCliente::guardar($clienteId, $productoId, $precio);
            }
        }

        $insertar->close();
        return true;
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
}
