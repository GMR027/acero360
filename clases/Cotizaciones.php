<?php

namespace App;

/**
 * Cotizaciones — encabezado y renglones de una cotización.
 *
 * El totaje SIEMPRE se recalcula en el servidor a partir de los renglones:
 * el navegador sólo hace la vista previa en vivo.
 *
 * Orden de cálculo (coincide con "Resumen de Liquidación" del diseño):
 *
 *   subtotal      Σ (cantidad × precio unitario)      ← bruto de partidas
 *   descuento     Σ descuento por renglón  +  descuento global (%)
 *   base          subtotal − descuento
 *   iva           base × 16%   (sólo si la cotización tiene IVA activo)
 *   total         base + iva
 *
 * El descuento global por defecto lo trae la ficha del cliente
 * (clientes.descuento_pct), pero puede ajustarse renglón por renglón.
 *
 * Cada renglón guarda su propia instantánea de precio
 * (cotizacion_detalles.precio_unitario): si más adelante cambia la lista o
 * el acuerdo, la cotización emitida no se altera.
 */
class Cotizaciones
{
    /** @var mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** Columnas que se persisten en la tabla cotizaciones. */
    protected static $columnasDB = [
        'id', 'folio', 'empresa_id', 'cliente_id', 'usuario_id', 'fecha',
        'vigencia_dias', 'estado', 'subtotal', 'descuento', 'aplica_iva',
        'iva', 'total', 'condiciones_envio', 'condiciones_compra', 'comentarios',
    ];

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** @var int Id de la última cotización creada (para redirigir al detalle). */
    public static $ultimoId = 0;

    /** Porcentaje de IVA trasladado. */
    public const IVA = 16.0;

    /** Estados admitidos por la columna ENUM. */
    public const ESTADOS = ['pendiente', 'vendido', 'cancelado'];

    /** Estados admitidos por la columna ENUM, con su etiqueta visual. */
    public const ESTILOS_ESTADO = [
        'pendiente' => ['etiqueta' => 'Pendiente', 'icono' => 'schedule',     'clase' => 'badge--pendiente'],
        'vendido'   => ['etiqueta' => 'Vendido',   'icono' => 'check_circle', 'clase' => 'badge--aprobado'],
        'cancelado' => ['etiqueta' => 'Cancelado', 'icono' => 'cancel',       'clase' => 'badge--error'],
    ];

    /** Meses del año para las etiquetas de periodo (índice 0 = enero). */
    public const MESES = [
        'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
    ];

    /* --- Columnas propias ------------------------------------------------ */
    public $id;
    public $folio;
    public $empresa_id;
    public $cliente_id;
    public $usuario_id;
    public $fecha;
    public $vigencia_dias;
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
        $this->id        = $args['id'] ?? null;
        $this->folio     = $args['folio'] ?? '';
        $this->empresa_id = isset($args['empresa_id']) ? (int) $args['empresa_id'] : 0;
        $this->cliente_id = isset($args['cliente_id']) ? (int) $args['cliente_id'] : 0;
        $this->usuario_id = isset($args['usuario_id']) ? (int) $args['usuario_id'] : 0;
        $this->fecha     = $args['fecha'] ?? date('Y-m-d');
        $this->vigencia_dias = isset($args['vigencia_dias']) ? (int) $args['vigencia_dias'] : 15;
        $this->estado    = $args['estado'] ?? 'pendiente';
        $this->subtotal  = isset($args['subtotal']) ? (float) $args['subtotal'] : 0.0;
        $this->descuento = isset($args['descuento']) ? (float) $args['descuento'] : 0.0;
        $this->aplica_iva = isset($args['aplica_iva']) ? (int) $args['aplica_iva'] : 1;
        $this->iva       = isset($args['iva']) ? (float) $args['iva'] : 0.0;
        $this->total     = isset($args['total']) ? (float) $args['total'] : 0.0;
        $this->condiciones_envio  = $args['condiciones_envio'] ?? '';
        $this->condiciones_compra = $args['condiciones_compra'] ?? '';
        $this->comentarios = $args['comentarios'] ?? '';
        $this->creado_en  = $args['creado_en'] ?? '';
    }

    /* --------------------------------------------------------------------
     * CONEXIÓN
     * ------------------------------------------------------------------ */

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /* --------------------------------------------------------------------
     * ESCUDO DE CÁLCULO
     * ------------------------------------------------------------------ */

    /**
     * Recalcula el total de la cotización a partir de sus renglones.
     *
     * @param array<int, array{cantidad: int, precio: float, descuento_pct?: float}> $lineas
     * @return array{
     *   subtotal: float, descuento_lineas: float, descuento_global: float,
     *   descuento: float, base: float, iva: float, total: float, renglones: int
     * }
     */
    public static function calcular(
        array $lineas,
        float $descuentoGlobalPct = 0.0,
        bool $aplicaIva = true
    ): array {
        $subtotal      = 0.0;
        $descuentoLineas = 0.0;
        $renglones     = 0;

        foreach ($lineas as $linea) {
            $cantidad = max(1, (int) ($linea['cantidad'] ?? 1));
            $precio   = max(0, (float) ($linea['precio'] ?? 0));
            $pctRenglon = min(100, max(0, (float) ($linea['descuento_pct'] ?? 0)));

            $bruto = $cantidad * $precio;
            $subtotal      += $bruto;
            $descuentoLineas += $bruto * $pctRenglon / 100;
            $renglones++;
        }

        $subtotal        = round($subtotal, 2);
        $descuentoLineas = round($descuentoLineas, 2);
        $descuentoGlobalPct = min(100, max(0, $descuentoGlobalPct));

        $baseTrasDescuento = round($subtotal - $descuentoLineas, 2);
        $descuentoGlobal   = round($baseTrasDescuento * $descuentoGlobalPct / 100, 2);

        $descuento = round($descuentoLineas + $descuentoGlobal, 2);
        $base      = round($subtotal - $descuento, 2);
        $iva       = $aplicaIva ? round($base * self::IVA / 100, 2) : 0.0;

        return [
            'subtotal'         => $subtotal,
            'descuento_lineas' => $descuentoLineas,
            'descuento_global' => $descuentoGlobal,
            'descuento'        => $descuento,
            'base'             => $base,
            'iva'              => $iva,
            'total'            => round($base + $iva, 2),
            'renglones'        => $renglones,
        ];
    }

    /**
     * Desglose del descuento de una cotización ya guardada.
     *
     * Separa lo que corresponde a los descuentos de cada renglón de lo que
     * corresponde al descuento global, y devuelve el porcentaje global con la
     * MISMA fórmula con la que se calculó al guardar (ver calcular()): el
     * descuento global siempre se aplicó sobre el subtotal bruto menos el
     * descuento de los renglones.
     *
     * Con el total del encabezado no basta: tomarlo directamente arroja un
     * porcentaje mayor y, al volver a guardar la cotización sin tocar nada,
     * los importes cambiarían sin que nadie lo pida.
     *
     * Es el cálculo que usan el detalle, el PDF y el formulario de edición.
     *
     * @param  array<int, array<string, mixed>> $detalles Salida de detalles().
     * @return array{renglones: float, global: float, base: float, pct: float}
     */
    public static function desgloseDescuento(object $cotizacion, array $detalles): array
    {
        $renglones = 0.0;

        foreach ($detalles as $detalle) {
            $bruto      = (int) $detalle['cantidad'] * (float) $detalle['precio_unitario'];
            $renglones += $bruto - (float) $detalle['subtotal'];
        }

        $renglones = round($renglones, 2);
        $global    = round((float) $cotizacion->descuento - $renglones, 2);
        $base      = round((float) $cotizacion->subtotal - $renglones, 2);
        $pct       = $base > 0 ? round(($global / $base) * 100, 2) : 0.0;

        return [
            'renglones' => $renglones,
            'global'    => $global,
            'base'      => $base,
            'pct'       => $pct,
        ];
    }

    /* --------------------------------------------------------------------
     * FOLIO
     * ------------------------------------------------------------------ */

    /**
     * Siguiente folio del año: FOL-2026-0001, FOL-2026-0002…
     *
     * Se genera dentro de la transacción de alta; si por concurrencia
     * se repitiera, crear() reintenta con el folio siguiente.
     */
    /**
     * Folio libre del año en curso: FOL-<año>-<NNNN>.
     *
     * Se calcula con el MÁXIMO numérico de los folios existentes y no con un
     * COUNT(*): si se borra una cotización, un COUNT dejaría de coincidir con
     * los folios realmente emitidos y la siguiente alta intentaría reutilizar
     * un folio que ya está en uso (y fallaría por la clave única).
     */
    public static function siguienteFolio(): string
    {
        $anio    = date('Y');
        $prefijo = 'FOL-' . $anio . '-';

        // El prefijo tiene 9 caracteres ("FOL-2026-"), el folio empieza en la 10.
        $posicion = strlen($prefijo) + 1;
        $patron   = $prefijo . '%';

        $consulta = self::$db->prepare(
            'SELECT MAX(CAST(SUBSTRING(folio, ?) AS UNSIGNED)) AS maximo
               FROM cotizaciones
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

    /** Columnas de unión compartidas por buscarID, listar y contar. */
    private const JOIN_COMPLETO = '
        FROM cotizaciones c
        JOIN empresas  e ON e.id = c.empresa_id
        JOIN clientes  cl ON cl.id = c.cliente_id
        LEFT JOIN usuarios u ON u.id = c.usuario_id';

    /** Carga una cotización con los datos del negocio, el cliente y el autor. */
    public static function buscarID($id): ?self
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $consulta = self::$db->prepare(
            'SELECT c.*,
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
               FROM cotizaciones c
               JOIN empresas  e  ON e.id  = c.empresa_id
               JOIN clientes  cl ON cl.id = c.cliente_id
               LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $registro = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $registro ? self::crearObjeto($registro) : null;
    }

    /**
     * Histórico paginado con filtros.
     *
     * @param array{q?: string, empresa_id?: int, cliente_id?: int,
     *              estado?: string, desde?: string, hasta?: string} $filtros
     */
    public static function listar(array $filtros = [], int $limite = 15, int $offset = 0): array
    {
        $limite  = max(1, (int) $limite);
        $offset  = max(0, (int) $offset);
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            "SELECT c.*,
                    e.nombre  AS empresa_nombre, e.logo AS empresa_logo,
                    cl.nombre AS cliente_nombre, cl.nombre_empresa AS cliente_empresa,
                    cl.alias  AS cliente_alias,  cl.rfc  AS cliente_rfc,
                    cl.descuento_pct AS cliente_descuento,
                    u.nombre  AS usuario_nombre
               " . self::JOIN_COMPLETO . "
              WHERE $donde
              ORDER BY c.fecha DESC, c.id DESC
              LIMIT ? OFFSET ?"
        );

        $valores[] = $limite;
        $valores[] = $offset;
        $tipos    .= 'ii';

        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $registros = $consulta->get_result();

        $lista = [];
        while ($fila = $registros->fetch_assoc()) {
            $lista[] = self::crearObjeto($fila);
        }

        $consulta->close();
        return $lista;
    }

    /** Cuenta las cotizaciones que cumplen los filtros del histórico. */
    public static function contar(array $filtros = []): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);

        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total ' . self::JOIN_COMPLETO . " WHERE $donde"
        );

        if ($tipos === '') {
            $consulta->execute();
        } else {
            $consulta->bind_param($tipos, ...$valores);
            $consulta->execute();
        }

        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /**
     * Cláusula WHERE del histórico.
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function condiciones(array $filtros): array
    {
        $donde   = ['1 = 1'];
        $tipos   = '';
        $valores = [];

        $termino = trim((string) ($filtros['q'] ?? ''));
        if ($termino !== '') {
            $donde[]  = '(c.folio LIKE ? OR cl.nombre LIKE ? OR IFNULL(cl.nombre_empresa, "") LIKE ?'
                      . ' OR IFNULL(cl.alias, "") LIKE ? OR IFNULL(cl.rfc, "") LIKE ?)';
            $tipos   .= 'sssss';
            $patron   = '%' . $termino . '%';
            $valores  = array_merge($valores, [$patron, $patron, $patron, $patron, $patron]);
        }

        $empresaId = (int) ($filtros['empresa_id'] ?? 0);
        if ($empresaId > 0) {
            $donde[] = 'c.empresa_id = ?';
            $tipos  .= 'i';
            $valores[] = $empresaId;
        }

        $clienteId = (int) ($filtros['cliente_id'] ?? 0);
        if ($clienteId > 0) {
            $donde[] = 'c.cliente_id = ?';
            $tipos  .= 'i';
            $valores[] = $clienteId;
        }

        $estado = (string) ($filtros['estado'] ?? '');
        if (in_array($estado, self::ESTADOS, true)) {
            $donde[] = 'c.estado = ?';
            $tipos  .= 's';
            $valores[] = $estado;
        }

        $desde = (string) ($filtros['desde'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $donde[] = 'c.fecha >= ?';
            $tipos  .= 's';
            $valores[] = $desde;
        }

        $hasta = (string) ($filtros['hasta'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            $donde[] = 'c.fecha <= ?';
            $tipos  .= 's';
            $valores[] = $hasta;
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /** Tarjetas de resumen del histórico. */
    public static function estadisticas(): array
    {
        $consulta = self::$db->prepare(
            'SELECT COUNT(*)                       AS total,
                    SUM(estado = "pendiente")       AS pendientes,
                    SUM(estado = "vendido")         AS vendidas,
                    SUM(estado = "cancelado")       AS canceladas,
                    COALESCE(SUM(CASE WHEN estado = "vendido" THEN total END), 0) AS monto_vendido,
                    COALESCE(SUM(total), 0)         AS monto_total
               FROM cotizaciones'
        );
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return [
            'total'       => (int) ($fila['total'] ?? 0),
            'pendientes'  => (int) ($fila['pendientes'] ?? 0),
            'vendidas'    => (int) ($fila['vendidas'] ?? 0),
            'canceladas'  => (int) ($fila['canceladas'] ?? 0),
            'monto_vendido' => (float) ($fila['monto_vendido'] ?? 0),
            'monto_total' => (float) ($fila['monto_total'] ?? 0),
        ];
    }

    /**
     * Años con cotizaciones registrados, del más reciente al más antiguo.
     *
     * Alimenta el selector de periodo del panel de ganancias. Siempre se
     * incluye el año en curso para que el filtro tenga opciones aunque la
     * base todavía no tenga cotizaciones.
     *
     * @return int[]
     */
    public static function anios(): array
    {
        $anios   = [(int) date('Y')];
        $consulta = self::$db->query('SELECT DISTINCT YEAR(fecha) AS anio FROM cotizaciones');

        while ($consulta && ($fila = $consulta->fetch_assoc())) {
            $anio = (int) ($fila['anio'] ?? 0);
            if ($anio > 1900) {
                $anios[] = $anio;
            }
        }

        if ($consulta) {
            $consulta->close();
        }

        $anios = array_values(array_unique($anios));
        rsort($anios);

        return $anios;
    }

    /**
     * Rango de fechas (y su etiqueta) de un filtro por año, semestre o mes.
     *
     * Prioridad: mes > semestre > año. Sin año seleccionado el periodo queda
     * abierto y se contabiliza el histórico completo.
     *
     * @return array{desde: string, hasta: string, etiqueta: string}
     */
    public static function rangoPeriodo(int $anio, int $semestre = 0, int $mes = 0): array
    {
        if ($anio <= 0) {
            return ['desde' => '', 'hasta' => '', 'etiqueta' => 'Histórico completo'];
        }

        if ($mes >= 1 && $mes <= 12) {
            $desde    = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta    = date('Y-m-t', strtotime($desde));
            $etiqueta = self::MESES[$mes - 1] . ' de ' . $anio;
        } elseif ($semestre === 1 || $semestre === 2) {
            $primerMes = $semestre === 1 ? 1 : 7;
            $ultimoMes = $semestre === 1 ? 6 : 12;
            $desde     = sprintf('%04d-%02d-01', $anio, $primerMes);
            $hasta     = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anio, $ultimoMes)));
            $etiqueta  = ($semestre === 1 ? '1.er' : '2.º') . ' semestre de ' . $anio;
        } else {
            $desde    = sprintf('%04d-01-01', $anio);
            $hasta    = sprintf('%04d-12-31', $anio);
            $etiqueta = 'Año ' . $anio;
        }

        return compact('desde', 'hasta', 'etiqueta');
    }

    /**
     * Ganancias y ventas de un negocio dentro de un periodo.
     *
     * "Ganancias" es el TOTAL de las ventas registradas del negocio en el
     * periodo (directas y nacidas de una cotización): es el dinero efectivamente
     * ganado. A la vez se devuelve `ventas` (el mismo importe CON IVA) y
     * `ventas_n` (cuántas ventas lo suman), que es lo que la tarjeta del panel
     * enseña como «Ventas del periodo». Las demás cifras acompañan para dar
     * contexto (lo que sigue en cartera y lo que se ha cotizado en general)
     * sin tener que salir del panel.
     *
     * @param  string $desde Límite inferior 'Y-m-d' o '' para no filtrar.
     * @param  string $hasta Límite superior 'Y-m-d' o '' para no filtrar.
     * @return array<string, float|int>
     */
    public static function ganancias(int $empresaId, string $desde = '', string $hasta = ''): array
    {
        $donde   = ['empresa_id = ?'];
        $tipos   = 'i';
        $valores = [$empresaId];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $donde[]  = 'fecha >= ?';
            $tipos   .= 's';
            $valores[] = $desde;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            $donde[]  = 'fecha <= ?';
            $tipos   .= 's';
            $valores[] = $hasta;
        }

        $consulta = self::$db->prepare(
            'SELECT COUNT(*)                                        AS cotizaciones,
                    COALESCE(SUM(estado = "pendiente"), 0)           AS pendientes,
                    COALESCE(SUM(estado = "vendido"), 0)             AS vendidas,
                    COALESCE(SUM(estado = "cancelado"), 0)           AS canceladas,
                    COALESCE(SUM(CASE WHEN estado = "vendido"
                                 THEN total ELSE 0 END), 0)          AS ganancias,
                    COALESCE(SUM(CASE WHEN estado = "pendiente"
                                 THEN total ELSE 0 END), 0)          AS cartera,
                    COALESCE(SUM(total), 0)                          AS cotizado
               FROM cotizaciones
              WHERE ' . implode(' AND ', $donde)
        );

        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        // Fuente única: `ventas` (directas y nacidas de una cotización). Sumar
        // además las cotizaciones vendidas contaría dos veces la misma operación.
        $ventas = Ventas::totalesEmpresa($empresaId, $desde, $hasta);

        return [
            'cotizaciones' => (int) ($fila['cotizaciones'] ?? 0),
            'pendientes'   => (int) ($fila['pendientes'] ?? 0),
            'vendidas'     => (int) ($fila['vendidas'] ?? 0),
            'canceladas'   => (int) ($fila['canceladas'] ?? 0),
            'directas'     => $ventas['directas'],
            'ventas'       => $ventas['monto'],
            'ventas_n'     => $ventas['cantidad'],
            'ganancias'    => $ventas['monto'],
            'cartera'      => (float) ($fila['cartera'] ?? 0),
            'cotizado'     => (float) ($fila['cotizado'] ?? 0),
        ];
    }

    /**
     * Renglones de una cotización con los datos del producto.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function detalles(int $cotizacionId): array
    {
        $consulta = self::$db->prepare(
            'SELECT d.*, p.nombre AS producto_nombre, p.sku AS producto_sku,
                    p.unidad AS producto_unidad, p.imagen AS producto_imagen,
                    p.precio AS producto_precio, p.categoria AS producto_categoria
               FROM cotizacion_detalles d
               JOIN productos p ON p.id = d.producto_id
              WHERE d.cotizacion_id = ?
              ORDER BY d.id ASC'
        );
        $consulta->bind_param('i', $cotizacionId);
        $consulta->execute();
        $registros = $consulta->get_result();

        $detalles = [];
        while ($fila = $registros->fetch_assoc()) {
            $detalles[] = $fila;
        }

        $consulta->close();
        return $detalles;
    }

    /** Cuenta las cotizaciones de un cliente (histórico por cliente). */
    public static function contarPorCliente(int $clienteId): int
    {
        $consulta = self::$db->prepare(
            'SELECT COUNT(*) AS total FROM cotizaciones WHERE cliente_id = ?'
        );
        $consulta->bind_param('i', $clienteId);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        return $total;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

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

    /**
     * Da de alta una cotización con todos sus renglones.
     *
     * @param array $datos Encabezado ya validado (empresa, cliente, fechas…).
     * @param array $lineas Renglones como vienen del formulario:
     *                      [producto_id => [cantidad, precio, descuento_pct, no_recordar]]
     * @param int   $usuarioId Administrador que la emite.
     */
    public static function crear(array $datos, array $lineas, int $usuarioId): bool
    {
        $intentos = 0;
        self::$errores = [];

        do {
            $folio = self::siguienteFolio();

            try {
                self::$db->begin_transaction();

                $cotizacion = new self([
                    'folio'             => $folio,
                    'empresa_id'        => (int) $datos['empresa_id'],
                    'cliente_id'        => (int) $datos['cliente_id'],
                    'usuario_id'        => $usuarioId,
                    'fecha'             => $datos['fecha'],
                    'vigencia_dias'     => (int) $datos['vigencia_dias'],
                    'estado'            => $datos['estado'] ?? 'pendiente',
                    'aplica_iva'        => !empty($datos['aplica_iva']) ? 1 : 0,
                    'condiciones_envio' => trim((string) ($datos['condiciones_envio'] ?? '')),
                    'condiciones_compra'=> trim((string) ($datos['condiciones_compra'] ?? '')),
                    'comentarios'       => trim((string) ($datos['comentarios'] ?? '')),
                ]);

                $totales = self::calcular(
                    $lineas,
                    (float) ($datos['descuento_pct'] ?? 0),
                    (bool) $cotizacion->aplica_iva
                );

                if ($totales['renglones'] === 0) {
                    self::$db->rollBack();
                    self::$errores[] = 'La cotización debe tener al menos un renglón.';
                    return false;
                }

                $cotizacion->subtotal  = $totales['subtotal'];
                $cotizacion->descuento = $totales['descuento'];
                $cotizacion->iva       = $totales['iva'];
                $cotizacion->total     = $totales['total'];

                if (!$cotizacion->insertar()) {
                    self::$db->rollBack();
                    return false;
                }

                if (!self::guardarDetalles((int) $cotizacion->id, $lineas)) {
                    self::$db->rollBack();
                    return false;
                }

                // Si nace directamente como vendida, el stock sale aquí mismo.
                if ($cotizacion->estado === 'vendido') {
                    if (!Ventas::generarDesdeCotizacion((int) $cotizacion->id, $usuarioId)) {
                        self::$errores = array_merge(self::$errores, Ventas::getErrores(), Inventario::getErrores());
                        self::$db->rollBack();
                        return false;
                    }
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

                error_log('[Cotizaciones::crear] ' . $excepcion->getMessage());
                self::$errores[] = 'No fue posible guardar la cotización.';
                return false;
            }
        } while ($intentos < 3);

        self::$errores[] = 'No fue posible asignar un folio a la cotización.';
        return false;
    }

    /**
     * Reemplaza el encabezado y los renglones de una cotización existente.
     *
     * Los precios especiales que se hayan capturado aquí también se guardan
     * (salvo que el renglón esté marcado como "No recordar este precio").
     */
    public static function actualizar(array $datos, array $lineas): bool
    {
        self::$errores = [];

        try {
            self::$db->begin_transaction();

            $cotizacion = self::buscarID((int) ($datos['id'] ?? 0));
            if (!$cotizacion) {
                self::$db->rollBack();
                self::$errores[] = 'La cotización ya no existe.';
                return false;
            }

            // Se recuerda el estado y los renglones previos: si la cotización
            // está vendida, el almacén ya descontó esas cantidades y hay que
            // revertirlas antes de aplicar las nuevas.
            $estadoPrevio  = (string) $cotizacion->estado;
            $detallesPrevios = self::detalles((int) $cotizacion->id);

            $cotizacion->empresa_id         = (int) $datos['empresa_id'];
            $cotizacion->cliente_id         = (int) $datos['cliente_id'];
            $cotizacion->fecha              = $datos['fecha'];
            $cotizacion->vigencia_dias      = (int) $datos['vigencia_dias'];
            $cotizacion->aplica_iva         = !empty($datos['aplica_iva']) ? 1 : 0;
            $cotizacion->estado             = in_array($datos['estado'] ?? '', self::ESTADOS, true)
                ? $datos['estado'] : $cotizacion->estado;
            $cotizacion->condiciones_envio  = trim((string) ($datos['condiciones_envio'] ?? ''));
            $cotizacion->condiciones_compra = trim((string) ($datos['condiciones_compra'] ?? ''));
            $cotizacion->comentarios        = trim((string) ($datos['comentarios'] ?? ''));

            $totales = self::calcular(
                $lineas,
                (float) ($datos['descuento_pct'] ?? 0),
                (bool) $cotizacion->aplica_iva
            );

            if ($totales['renglones'] === 0) {
                self::$db->rollBack();
                self::$errores[] = 'La cotización debe tener al menos un renglón.';
                return false;
            }

            $cotizacion->subtotal  = $totales['subtotal'];
            $cotizacion->descuento = $totales['descuento'];
            $cotizacion->iva       = $totales['iva'];
            $cotizacion->total     = $totales['total'];

            if (!$cotizacion->modificar()) {
                self::$db->rollBack();
                return false;
            }

            // Los renglones se reconstruyen enteros: es la forma más simple
            // de reflejar altas, bajas y cambios de cantidad/precio.
            if (!self::borrarDetalles((int) $cotizacion->id)
                || !self::guardarDetalles((int) $cotizacion->id, $lineas)) {
                self::$db->rollBack();
                return false;
            }

            // Sincronización del inventario: sólo se mueve el stock si la
            // cotización está (o deja de estar) en estado "vendido".
            $usuarioSesion = (int) ($_SESSION['id'] ?? 0);
            $ok = true;
            if ($estadoPrevio === 'vendido' && $cotizacion->estado === 'vendido') {
                $ok = Ventas::sincronizarDeCotizacion((int) $cotizacion->id, $usuarioSesion);
            } elseif ($estadoPrevio === 'vendido') {
                $ok = Ventas::cancelarDeCotizacion(
                    (int) $cotizacion->id,
                    $usuarioSesion,
                    'Se devuelve el stock del folio ' . $cotizacion->folio . ' (ya no está vendido)'
                );
            } elseif ($cotizacion->estado === 'vendido') {
                $ok = Ventas::generarDesdeCotizacion((int) $cotizacion->id, $usuarioSesion);
            }
            if (!$ok) {
                self::$errores = array_merge(self::$errores, Ventas::getErrores(), Inventario::getErrores());
                self::$db->rollBack();
                return false;
            }

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Cotizaciones::actualizar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible actualizar la cotización.';
            return false;
        }
    }

    /** Inserta el encabezado (ya calculado) dentro de la transacción activa. */
    private function insertar(): bool
    {
        $atributos   = $this->atributos();
        $columnas    = implode(', ', array_keys($atributos));
        $marcadores  = implode(', ', array_fill(0, count($atributos), '?'));

        $consulta = self::$db->prepare(
            "INSERT INTO cotizaciones ($columnas) VALUES ($marcadores)"
        );

        $valores = array_values($atributos);
        $tipos   = str_repeat('s', count($valores));
        $consulta->bind_param($tipos, ...$valores);
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
            'UPDATE cotizaciones SET ' . implode(', ', $asignaciones) . ' WHERE id = ?'
        );

        $valores   = array_values($atributos);
        $valores[] = (int) $this->id;
        $tipos     = str_repeat('s', count($atributos)) . 'i';
        $consulta->bind_param($tipos, ...$valores);
        $consulta->execute();
        $consulta->close();

        return true;
    }

    /** Borra los renglones previos (sólo se usa antes de reconstruirlos). */
    private static function borrarDetalles(int $cotizacionId): bool
    {
        $consulta = self::$db->prepare(
            'DELETE FROM cotizacion_detalles WHERE cotizacion_id = ?'
        );
        $consulta->bind_param('i', $cotizacionId);
        $consulta->execute();
        $consulta->close();

        return true;
    }

    /**
     * Inserta los renglones y, si aplica, guarda los precios especiales.
     *
     * @param array $lineas [producto_id => [cantidad, precio, descuento_pct, no_recordar]]
     */
    private static function guardarDetalles(int $cotizacionId, array $lineas): bool
    {
        $cabecera = self::buscarID($cotizacionId);
        $clienteId = $cabecera ? (int) $cabecera->cliente_id : 0;

        $insertar = self::$db->prepare(
            'INSERT INTO cotizacion_detalles
                (cotizacion_id, producto_id, cantidad, precio_unitario, descuento_pct, subtotal)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($lineas as $productoId => $linea) {
            $productoId = (int) $productoId;
            $cantidad   = max(1, (int) ($linea['cantidad'] ?? 1));
            $precio     = max(0, round((float) ($linea['precio'] ?? 0), 2));
            $pctRenglon = min(100, max(0, (float) ($linea['descuento_pct'] ?? 0)));
            $bruto      = $cantidad * $precio;
            $subtotal   = round($bruto - ($bruto * $pctRenglon / 100), 2);

            $insertar->bind_param(
                'iiiddd',
                $cotizacionId, $productoId, $cantidad, $precio, $pctRenglon, $subtotal
            );

            if (!$insertar->execute()) {
                $insertar->close();
                self::$errores[] = 'No fue posible guardar un renglón de la cotización.';
                return false;
            }

            // Precio especial: se guarda salvo que el usuario pida no recordarlo.
            $noRecordar = !empty($linea['no_recordar']);
            if (!$noRecordar && $clienteId > 0 && $productoId > 0) {
                PreciosCliente::guardar($clienteId, $productoId, $precio);
            }
        }

        $insertar->close();
        return true;
    }

    /* --------------------------------------------------------------------
     * ENTRADA / SALIDA DE LOS RENGLONES
     * ------------------------------------------------------------------ */

    /**
     * Normaliza los renglones tal como llegan del formulario.
     *
     * El cotizador envía `lineas[producto_id][campo]`; aquí se convierten en
     * un arreglo limpio y con tipos correctos para calcular y guardar.
     *
     * @param array $post $_POST completo.
     * @return array<int, array{cantidad: int, precio: float, descuento_pct: float, no_recordar: bool}>
     */
    public static function leerLineasPost(array $post): array
    {
        $lineas = [];

        $brutas = $post['lineas'] ?? [];
        if (!is_array($brutas)) {
            return $lineas;
        }

        foreach ($brutas as $productoId => $linea) {
            if (!is_array($linea) || (int) $productoId <= 0) {
                continue;
            }

            $precio = $linea['precio'] ?? 0;
            $pct    = $linea['descuento_pct'] ?? 0;

            $lineas[(int) $productoId] = [
                'cantidad'     => (int) ($linea['cantidad'] ?? 1),
                'precio'       => is_numeric($precio) ? (float) $precio : 0.0,
                'descuento_pct'=> is_numeric($pct) ? (float) $pct : 0.0,
                'no_recordar'  => !empty($linea['no_recordar']),
            ];
        }

        return $lineas;
    }

    /**
     * Renglones guardados en el formato que espera el cotizador del navegador.
     *
     * @param  array $detalles Salida de Cotizaciones::detalles().
     * @return array<int, array<string, mixed>>
     */
    public static function lineasDesdeDetalles(array $detalles): array
    {
        $lineas = [];

        foreach ($detalles as $detalle) {
            $productoId = (int) $detalle['producto_id'];

            $lineas[] = [
                'id'            => $productoId,
                'nombre'        => (string) $detalle['producto_nombre'],
                'sku'           => (string) $detalle['producto_sku'],
                'categoria'     => (string) ($detalle['producto_categoria'] ?? ''),
                'unidad'        => (string) $detalle['producto_unidad'],
                'imagen'        => (string) ($detalle['producto_imagen'] ?? ''),
                'precio_lista'  => (float) $detalle['producto_precio'],
                'cantidad'      => (int) $detalle['cantidad'],
                'precio'        => (float) $detalle['precio_unitario'],
                'descuento_pct' => (float) $detalle['descuento_pct'],
                // El precio recordado depende del cliente elegido, no del renglón.
                'no_recordar'   => false,
            ];
        }

        return $lineas;
    }

    /**
     * Renglones capturados pero no guardados (validación fallida), completados
     * con los datos del catálogo para poder volver a pintar el formulario.
     *
     * @param  array $lineas Salida de Cotizaciones::leerLineasPost().
     * @return array<int, array<string, mixed>>
     */
    public static function lineasDesdePost(array $lineas): array
    {
        $lista = [];

        foreach ($lineas as $productoId => $linea) {
            $producto = Productos::buscarID($productoId);

            if (!$producto) {
                continue;
            }

            $lista[] = [
                'id'            => $productoId,
                'nombre'        => (string) $producto->nombre,
                'sku'           => (string) $producto->sku,
                'categoria'     => (string) $producto->categoria,
                'unidad'        => (string) $producto->unidad,
                'imagen'        => (string) $producto->imagen,
                'precio_lista'  => (float) $producto->precio,
                'cantidad'      => $linea['cantidad'],
                'precio'        => $linea['precio'],
                'descuento_pct' => $linea['descuento_pct'],
                'no_recordar'   => $linea['no_recordar'],
            ];
        }

        return $lista;
    }

    /**
     * Elimina la cotización (los renglones caen en cascata).
     *
     * Los precios especiales guardados se conservan: son acuerdos comerciales.
     * Si estaba marcada como vendida, antes se devuelve el stock al almacén
     * para no dejar un descuento huérfano en el inventario.
     */
    public static function eliminar(int $id): bool
    {
        try {
            self::$db->begin_transaction();

            $cotizacion = self::buscarID($id);
            if (!$cotizacion) {
                self::$db->rollBack();
                self::$errores[] = 'La cotización ya no existe.';
                return false;
            }

            if ($cotizacion->estado === 'vendido') {
                if (!Ventas::cancelarDeCotizacion(
                    $id,
                    (int) ($_SESSION['id'] ?? 0),
                    'Se devuelve el stock del folio ' . $cotizacion->folio . ' (cotización eliminada)'
                )) {
                    self::$errores = array_merge(self::$errores, Ventas::getErrores(), Inventario::getErrores());
                    self::$db->rollBack();
                    return false;
                }
            }

            // Antes de borrar, copia completa a la papelera (se puede restaurar 90 días).
            $cliente = Clientes::buscarID($cotizacion->cliente_id);
            $copiada = Papelera::copiarCotizacion($id);
            $etiqueta = $cotizacion->folio . ' · ' . ($cliente ? $cliente->nombre : 'cliente') . ' · ' . $cotizacion->fecha;
            if ($copiada === null || !Papelera::guardar('cotizacion', $id, $etiqueta, (float) $cotizacion->total, $copiada, (int) ($_SESSION['id'] ?? 0))) {
                self::$db->rollBack();
                self::$errores = array_merge(self::$errores, Papelera::getErrores());
                return false;
            }

            $consulta = self::$db->prepare('DELETE FROM cotizaciones WHERE id = ?');
            $consulta->bind_param('i', $id);
            $correcto = $consulta->execute();
            $consulta->close();

            if (!$correcto) {
                self::$db->rollBack();
                self::$errores[] = 'No fue posible eliminar la cotización.';
                return false;
            }

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Cotizaciones::eliminar] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible eliminar la cotización.';
        return false;
    }

    /**
     * Cambia el estado (pendiente / vendido / cancelado) y mueve el stock.
     *
     * Ésta es la puerta de entrada "segura": abre su propia transacción y
     * delega en aplicarEstadoEnTransaccion().
     *
     * @param int      $id        Cotización.
     * @param string   $estado    Estado destino.
     * @param int|null $usuarioId Administrador que hace el cambio
     *                            (por defecto, el de la sesión).
     */
    public static function cambiarEstado(int $id, string $estado, ?int $usuarioId = null): bool
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            self::$errores[] = 'Estado no reconocido.';
            return false;
        }

        $usuarioId = $usuarioId ?? (int) ($_SESSION['id'] ?? 0);

        try {
            self::$db->begin_transaction();

            if (!self::aplicarEstadoEnTransaccion($id, $estado, $usuarioId)) {
                self::$db->rollBack();
                return false;
            }

            self::$db->commit();
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            self::$db->rollBack();
            error_log('[Cotizaciones::cambiarEstado] ' . $excepcion->getMessage());
        }

        self::$errores[] = 'No fue posible cambiar el estado.';
        return false;
    }

    /**
     * Marca la cotización y descuenta/devuelve el stock en consecuencia.
     *
     * REQUIERE una transacción abierta por el llamador (la comparten Cotizaciones
     * y Ventas para que estado, almacén y bitácora sean indivisibles).
     *
     * El movimiento sólo ocurre si el estado REALMENTE cambia: así repetir la
     * operación nunca descuenta dos veces el mismo pedido.
     *
     * @return bool false si la cotización no existe o la escritura falla.
     */
    public static function aplicarEstadoEnTransaccion(int $id, string $estado, int $usuarioId): bool
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            self::$errores[] = 'Estado no reconocido.';
            return false;
        }

        // Se bloquea la fila: dos peticiones simultáneas no pueden mover
        // el stock dos veces a la vez.
        $consulta = self::$db->prepare(
            'SELECT estado, folio FROM cotizaciones WHERE id = ? FOR UPDATE'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $previa = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$previa) {
            self::$errores[] = 'La cotización ya no existe.';
            return false;
        }

        $previo = (string) $previa['estado'];
        $folio  = (string) $previa['folio'];

        $actualizar = self::$db->prepare('UPDATE cotizaciones SET estado = ? WHERE id = ?');
        $actualizar->bind_param('si', $estado, $id);
        $correcto = $actualizar->execute();
        $actualizar->close();

        if (!$correcto) {
            self::$errores[] = 'No fue posible cambiar el estado.';
            return false;
        }

        if ($previo === $estado) {
            return true; // Sin transición: no se toca el almacén.
        }

        if ($estado === 'vendido') {
            $ok = Ventas::generarDesdeCotizacion($id, $usuarioId);
        } elseif ($previo === 'vendido') {
            $ok = Ventas::cancelarDeCotizacion(
                $id,
                $usuarioId,
                'Se devuelve el stock del folio ' . $folio . ' (ya no está vendido)'
            );
        } else {
            $ok = true;
        }

        if (!$ok) {
            self::$errores = array_merge(self::$errores, Ventas::getErrores(), Inventario::getErrores());
        }

        return $ok;
    }

    /* --------------------------------------------------------------------
     * VALIDACIÓN
     * ------------------------------------------------------------------ */

    /** Devuelve y limpia los errores acumulados. */
    public static function getErrores(): array
    {
        $errores = self::$errores;
        self::$errores = [];
        return $errores;
    }

    /**
     * Valida el encabezado y los renglones tal como llegan del formulario.
     *
     * @param array $datos Encabezado.
     * @param array $lineas [producto_id => [...]].
     * @return string[] Lista vacía cuando todo está correcto.
     */
    public static function validar(array $datos, array $lineas): array
    {
        $errores = [];

        if ((int) ($datos['empresa_id'] ?? 0) <= 0) {
            $errores[] = 'Debés elegir el negocio que emite la cotización.';
        }
        if ((int) ($datos['cliente_id'] ?? 0) <= 0) {
            $errores[] = 'Debés elegir el cliente al que se le cotiza.';
        }

        $fecha = (string) ($datos['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $errores[] = 'La fecha de la cotización no es válida.';
        }

        $vigencia = (int) ($datos['vigencia_dias'] ?? 0);
        if ($vigencia < 1 || $vigencia > 365) {
            $errores[] = 'La vigencia debe estar entre 1 y 365 días.';
        }

        $descuento = (float) ($datos['descuento_pct'] ?? 0);
        if ($descuento < 0 || $descuento > 100) {
            $errores[] = 'El descuento global debe estar entre 0% y 100%.';
        }

        if (empty($lineas)) {
            $errores[] = 'Agrega al menos un producto a la cotización.';
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
     * CONVERSIÓN DE ESTADO / PRESENTACIÓN
     * ------------------------------------------------------------------ */

    /**
     * Datos de presentación de un estado.
     *
     * @return array{etiqueta: string, icono: string, clase: string}
     */
    public static function datosEstado(string $estado): array
    {
        return self::ESTILOS_ESTADO[$estado] ?? self::ESTILOS_ESTADO['pendiente'];
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
