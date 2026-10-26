<?php

namespace App;

/**
 * ProductosProveedor — catálogo de productos SEMITERMINADOS que ofrece cada proveedor.
 *
 * No son productos propios: conservan su identidad, código y costo. Reglas:
 *   • Un proveedor tiene muchos; el mismo artículo puede existir en otro proveedor con otro costo.
 *   • Nombre y código no se repiten dentro del mismo proveedor (se avisa cuál ya existe en vez de duplicar).
 *   • Nunca se borran: se desactivan. Los inactivos no se ofrecen en compras nuevas pero siguen en el historial.
 *   • El costo del catálogo es el vigente; cambiarlo NO altera compras anteriores (cada compra guarda el suyo)
 *     y queda en la bitácora de auditoría.
 *   • La relación con los productos propios es opcional, N:M y sin cantidades (no es un sistema de producción).
 */
class ProductosProveedor
{
    /** @var \mysqli Conexión inyectada desde includes/app.php. */
    protected static $db;

    /** @var string[] Mensajes de la última operación. */
    protected static $errores = [];

    /** Id del último registro creado (para redirigir a su ficha). */
    public static int $ultimoId = 0;

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

    /* --------------------------------------------------------------------
     * LECTURA
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare(
            'SELECT pp.*, p.nombre AS proveedor_nombre, p.activo AS proveedor_activo,
                    (SELECT COUNT(*) FROM proveedor_producto_relaciones r WHERE r.proveedor_producto_id = pp.id) AS relaciones
               FROM proveedor_productos pp JOIN proveedores p ON p.id = pp.proveedor_id
              WHERE pp.id = ? LIMIT 1'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    /**
     * Filtros: q (nombre, código o descripción), proveedor, estado ('activos'|'inactivos'), relacion ('con'|'sin').
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listar(array $filtros, int $limite = 15, int $offset = 0): array
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare(
            "SELECT pp.*, p.nombre AS proveedor_nombre, p.activo AS proveedor_activo,
                    (SELECT COUNT(*) FROM proveedor_producto_relaciones r WHERE r.proveedor_producto_id = pp.id) AS relaciones,
                    (SELECT GROUP_CONCAT(pr.nombre ORDER BY pr.nombre SEPARATOR '||') FROM proveedor_producto_relaciones r
                       JOIN productos pr ON pr.id = r.producto_id WHERE r.proveedor_producto_id = pp.id) AS relacionados
               FROM proveedor_productos pp JOIN proveedores p ON p.id = pp.proveedor_id
              WHERE $donde ORDER BY pp.activo DESC, p.nombre ASC, pp.nombre ASC LIMIT ? OFFSET ?"
        );
        $valores[] = $limite;
        $valores[] = $offset;
        $consulta->bind_param($tipos . 'ii', ...$valores);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    public static function contar(array $filtros): int
    {
        [$donde, $tipos, $valores] = self::condiciones($filtros);
        $consulta = self::$db->prepare(
            "SELECT COUNT(*) FROM proveedor_productos pp JOIN proveedores p ON p.id = pp.proveedor_id WHERE $donde"
        );
        if ($tipos !== '') {
            $consulta->bind_param($tipos, ...$valores);
        }
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_row()[0];
        $consulta->close();

        return $total;
    }

    /** @return array{0: string, 1: string, 2: array<int, mixed>} */
    private static function condiciones(array $f): array
    {
        $donde = ['1 = 1'];
        $tipos = '';
        $valores = [];

        $termino = trim((string) ($f['q'] ?? ''));
        if ($termino !== '') {
            $donde[] = '(pp.nombre LIKE ? OR pp.codigo LIKE ? OR pp.descripcion LIKE ?)';
            $tipos .= 'sss';
            $valores[] = $valores[] = $valores[] = '%' . $termino . '%';
        }
        if ((int) ($f['proveedor'] ?? 0) > 0) {
            $donde[] = 'pp.proveedor_id = ?';
            $tipos .= 'i';
            $valores[] = (int) $f['proveedor'];
        }
        if (($f['estado'] ?? '') === 'activos') {
            $donde[] = 'pp.activo = 1';
        } elseif (($f['estado'] ?? '') === 'inactivos') {
            $donde[] = 'pp.activo = 0';
        }
        if (($f['relacion'] ?? '') === 'con') {
            $donde[] = 'EXISTS (SELECT 1 FROM proveedor_producto_relaciones r WHERE r.proveedor_producto_id = pp.id)';
        } elseif (($f['relacion'] ?? '') === 'sin') {
            $donde[] = 'NOT EXISTS (SELECT 1 FROM proveedor_producto_relaciones r WHERE r.proveedor_producto_id = pp.id)';
        }
        if ((int) ($f['producto'] ?? 0) > 0) {
            $donde[] = 'EXISTS (SELECT 1 FROM proveedor_producto_relaciones r WHERE r.proveedor_producto_id = pp.id AND r.producto_id = ?)';
            $tipos .= 'i';
            $valores[] = (int) $f['producto'];
        }

        return [implode(' AND ', $donde), $tipos, $valores];
    }

    /**
     * Semiterminados activos de un proveedor, para elegirlos al armar una compra (con su costo vigente).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paraSelector(int $proveedorId): array
    {
        $consulta = self::$db->prepare(
            'SELECT id, nombre, codigo, descripcion, costo_unitario FROM proveedor_productos
              WHERE proveedor_id = ? AND activo = 1 ORDER BY nombre'
        );
        $consulta->bind_param('i', $proveedorId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Catálogos para el formulario de compra, por proveedor: solo productos activos de proveedores activos, más los ids
     * indicados (los que ya usa la compra que se edita, aunque hoy estén inactivos). Cada uno trae los ids de los
     * productos propios con los que se relaciona (para proponer el producto propio sin escribirlo).
     *
     * @param int[] $incluirIds
     * @return array<int, array<int, array<string, mixed>>> proveedor_id => lista
     */
    public static function paraFormulario(array $incluirIds = []): array
    {
        $extra = implode(',', array_map('intval', array_filter($incluirIds))) ?: '0';
        $filas = self::$db->query(
            "SELECT pp.id, pp.proveedor_id, pp.nombre, pp.codigo, pp.costo_unitario, pp.activo,
                    (SELECT GROUP_CONCAT(r.producto_id) FROM proveedor_producto_relaciones r WHERE r.proveedor_producto_id = pp.id) AS propios
               FROM proveedor_productos pp JOIN proveedores p ON p.id = pp.proveedor_id
              WHERE (pp.activo = 1 AND p.activo = 1) OR pp.id IN ($extra)
              ORDER BY pp.nombre"
        )->fetch_all(MYSQLI_ASSOC);

        $mapa = [];
        foreach ($filas as $f) {
            $mapa[(int) $f['proveedor_id']][] = [
                'id'     => (int) $f['id'],
                'nombre' => $f['nombre'],
                'codigo' => (string) $f['codigo'],
                'costo'  => number_format((float) $f['costo_unitario'], 2, '.', ''),
                'activo' => (int) $f['activo'] === 1,
                'propios' => array_map('intval', array_filter(explode(',', (string) $f['propios']))),
            ];
        }

        return $mapa;
    }

    /* --------------------------------------------------------------------
     * ESCRITURA
     * ------------------------------------------------------------------ */

    /**
     * Valida y normaliza los datos del formulario.
     *
     * @return array{0: string[], 1: array<string, mixed>}
     */
    public static function validar(array $d, int $id = 0): array
    {
        $errores = [];
        $proveedorId = (int) ($d['proveedor_id'] ?? 0);
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $descripcion = trim((string) ($d['descripcion'] ?? ''));
        $codigo = trim((string) ($d['codigo'] ?? ''));
        $costo = str_replace(',', '.', trim((string) ($d['costo_unitario'] ?? '')));

        $consulta = self::$db->prepare('SELECT activo FROM proveedores WHERE id = ?');
        $consulta->bind_param('i', $proveedorId);
        $consulta->execute();
        $proveedor = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$proveedor) {
            $errores[] = 'Elige un proveedor válido.';
        } elseif ((int) $proveedor['activo'] !== 1) {
            // Un registro existente puede conservar a su proveedor inactivo; los nuevos no.
            $actual = $id > 0 ? (int) (self::buscarID($id)['proveedor_id'] ?? 0) : 0;
            if ($actual !== $proveedorId) {
                $errores[] = 'El proveedor está desactivado: no se le pueden agregar productos nuevos.';
            }
        }
        if ($nombre === '' || mb_strlen($nombre) > 150) {
            $errores[] = 'El nombre del producto es obligatorio (hasta 150 caracteres).';
        }
        if (mb_strlen($descripcion) > 255) {
            $errores[] = 'La descripción puede tener hasta 255 caracteres.';
        }
        if (mb_strlen($codigo) > 40) {
            $errores[] = 'El código puede tener hasta 40 caracteres.';
        }
        if ($costo === '' || !is_numeric($costo) || (float) $costo < 0 || (float) $costo > 9999999999.99) {
            $errores[] = 'El costo unitario de compra debe ser un número mayor o igual a cero.';
            $costo = '0';
        }

        // Sin duplicados innecesarios dentro del mismo proveedor.
        if ($proveedor && $nombre !== '') {
            $existente = self::duplicado($proveedorId, 'nombre', $nombre, $id);
            if ($existente) {
                $errores[] = 'Este proveedor ya tiene un producto llamado «' . $existente['nombre'] . '»' . ((int) $existente['activo'] === 0 ? ' (inactivo: puedes reactivarlo)' : '') . '. Edítalo en vez de crear otro.';
            }
        }
        if ($proveedor && $codigo !== '') {
            $existente = self::duplicado($proveedorId, 'codigo', $codigo, $id);
            if ($existente) {
                $errores[] = 'Este proveedor ya usa el código «' . $codigo . '» en «' . $existente['nombre'] . '».';
            }
        }

        return [$errores, [
            'proveedor_id'   => $proveedorId,
            'nombre'         => $nombre,
            'descripcion'    => $descripcion !== '' ? $descripcion : null,
            'codigo'         => $codigo !== '' ? $codigo : null,
            'costo_unitario' => number_format((float) $costo, 2, '.', ''),
            // Sin indicar el estado, un producto nuevo queda activo; el formulario lo manda siempre de forma explícita.
            'activo'         => array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1,
        ]];
    }

    /** @return array<string, mixed>|null */
    private static function duplicado(int $proveedorId, string $campo, string $valor, int $exceptoId): ?array
    {
        $consulta = self::$db->prepare("SELECT id, nombre, activo FROM proveedor_productos WHERE proveedor_id = ? AND $campo = ? AND id <> ? LIMIT 1");
        $consulta->bind_param('isi', $proveedorId, $valor, $exceptoId);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        return $fila ?: null;
    }

    public static function crear(array $datos, int $usuarioId): bool
    {
        [$errores, $d] = self::validar($datos);
        self::$errores = $errores;
        if ($errores) {
            return false;
        }

        try {
            $consulta = self::$db->prepare(
                'INSERT INTO proveedor_productos (proveedor_id, nombre, descripcion, imagen, codigo, costo_unitario, activo, creado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $imagen = ($datos['imagen'] ?? '') !== '' ? (string) $datos['imagen'] : null;
            $consulta->bind_param('isssssii', $d['proveedor_id'], $d['nombre'], $d['descripcion'], $imagen, $d['codigo'], $d['costo_unitario'], $d['activo'], $usuarioId);
            $consulta->execute();
            self::$ultimoId = (int) self::$db->insert_id;
            $consulta->close();

            Auditoria::registrar('proveedor_producto', self::$ultimoId, 'crear', $usuarioId, null, [
                'proveedor_id' => $d['proveedor_id'], 'nombre' => $d['nombre'], 'costo' => $d['costo_unitario'],
            ]);
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[ProductosProveedor::crear] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible guardar el producto (¿ya existe con ese nombre o código para el proveedor?).';
            return false;
        }
    }

    /**
     * Alta rápida desde la lectura de una factura: agrega un concepto del
     * documento al catálogo del PROVEEDOR que la emitió.
     *
     * A diferencia de crear(), el duplicado NO es un error: es la respuesta
     * correcta cuando se intenta agregar dos veces el mismo concepto (dos filas
     * de la misma factura, o la misma factura otra vez), así el botón de la
     * pantalla puede decir «ya estaba» y seguir. Se compara sin acentos ni
     * mayúsculas, porque «Manzana» y «MANZANA» son el mismo producto.
     *
     * @param array<string, mixed> $concepto  salida de CoincidenciasFactura
     *                                        (descripcion, valor_unitario, sku, nota)
     * @return array{estado: string, id: int, nombre: string, activo: int, error: string}
     *         estado: 'creado' | 'existente' | 'error'
     */
    public static function agregarDesdeFactura(int $proveedorId, array $concepto, int $usuarioId): array
    {
        $nombre  = mb_substr(trim((string) ($concepto['descripcion'] ?? '')), 0, 150);
        $codigo  = mb_substr(strtoupper(trim((string) ($concepto['sku'] ?? ''))), 0, 40);
        $costo   = (float) ($concepto['valor_unitario'] ?? 0);
        $detalle = mb_substr(trim((string) ($concepto['nota'] ?? '')), 0, 255);

        $respuesta = static fn (string $estado, int $id = 0, string $nombreOut = '', int $activo = 1, string $error = ''): array => [
            'estado' => $estado, 'id' => $id, 'nombre' => $nombreOut, 'activo' => $activo, 'error' => $error,
        ];

        if ($nombre === '') {
            return $respuesta('error', 0, '', 1, 'El concepto no trae descripción: escríbele un nombre en el catálogo.');
        }

        // ¿Ya está en el catálogo de este proveedor? (comparación normalizada)
        $normalizado = CoincidenciasFactura::normalizar($nombre);
        $consulta = self::$db->prepare('SELECT id, nombre, codigo, activo FROM proveedor_productos WHERE proveedor_id = ?');
        $consulta->bind_param('i', $proveedorId);
        $consulta->execute();
        $existentes = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        foreach ($existentes as $existente) {
            $mismoNombre = CoincidenciasFactura::normalizar((string) $existente['nombre']) === $normalizado;
            $mismoCodigo = $codigo !== '' && strtoupper((string) ($existente['codigo'] ?? '')) === $codigo;
            if ($mismoNombre || $mismoCodigo) {
                return $respuesta('existente', (int) $existente['id'], (string) $existente['nombre'], (int) $existente['activo']);
            }
        }

        $datos = [
            'proveedor_id'   => $proveedorId,
            'nombre'         => $nombre,
            'codigo'         => $codigo,
            'descripcion'    => $detalle,
            'costo_unitario' => number_format($costo, 2, '.', ''),
            'activo'         => 1,
        ];

        if (self::crear($datos, $usuarioId)) {
            return $respuesta('creado', self::$ultimoId, $nombre);
        }

        // Carrera entre dos pestañas: si entre la consulta y el INSERT apareció,
        // sigue sin ser un error.
        foreach ($existentes as $existente) {
            if (CoincidenciasFactura::normalizar((string) $existente['nombre']) === $normalizado) {
                return $respuesta('existente', (int) $existente['id'], (string) $existente['nombre'], (int) $existente['activo']);
            }
        }

        return $respuesta('error', 0, $nombre, 1, self::$errores[0] ?? 'No fue posible guardar el producto.');
    }

    public static function actualizar(int $id, array $datos, int $usuarioId): bool
    {
        $previo = self::buscarID($id);
        if (!$previo) {
            self::$errores[] = 'El producto ya no existe.';
            return false;
        }
        [$errores, $d] = self::validar($datos, $id);
        self::$errores = $errores;
        if ($errores) {
            return false;
        }

        try {
            $consulta = self::$db->prepare(
                'UPDATE proveedor_productos SET proveedor_id = ?, nombre = ?, descripcion = ?, codigo = ?, costo_unitario = ?, activo = ?,
                        imagen = CASE WHEN ? = 0 THEN imagen ELSE NULLIF(?, \'\') END WHERE id = ?'
            );
            // Imagen: sin clave = conservar la actual; cadena vacía = quitarla; nombre = reemplazarla.
            $cambiaImagen = array_key_exists('imagen', $datos) ? 1 : 0;
            $imagenNueva  = (string) ($datos['imagen'] ?? '');
            $consulta->bind_param('issssiisi', $d['proveedor_id'], $d['nombre'], $d['descripcion'], $d['codigo'], $d['costo_unitario'], $d['activo'], $cambiaImagen, $imagenNueva, $id);
            $consulta->execute();
            $consulta->close();
            if ($cambiaImagen && (string) ($previo['imagen'] ?? '') !== '' && $previo['imagen'] !== $imagenNueva) {
                Imagenes::eliminar($previo['imagen']);
            }

            $cambios = [];
            foreach (['proveedor_id', 'nombre', 'descripcion', 'codigo', 'costo_unitario', 'activo'] as $campo) {
                if ((string) $previo[$campo] !== (string) $d[$campo]) {
                    $cambios[$campo] = ['antes' => $previo[$campo], 'despues' => $d[$campo]];
                }
            }
            if ($cambios) {
                Auditoria::registrar('proveedor_producto', $id, isset($cambios['costo_unitario']) ? 'cambiar_costo' : 'editar', $usuarioId, null, $cambios);
            }
            return true;
        } catch (\mysqli_sql_exception $excepcion) {
            error_log('[ProductosProveedor::actualizar] ' . $excepcion->getMessage());
            self::$errores[] = 'No fue posible actualizar el producto.';
            return false;
        }
    }

    /** Activa o desactiva (no se borra: las compras históricas conservan su referencia). */
    public static function cambiarEstado(int $id, bool $activo, int $usuarioId): bool
    {
        $previo = self::buscarID($id);
        if (!$previo) {
            self::$errores[] = 'El producto ya no existe.';
            return false;
        }
        if ($activo && (int) $previo['proveedor_activo'] !== 1) {
            self::$errores[] = 'No se puede reactivar: el proveedor está desactivado.';
            return false;
        }
        $valor = $activo ? 1 : 0;
        if ((int) $previo['activo'] === $valor) {
            return true;
        }
        $consulta = self::$db->prepare('UPDATE proveedor_productos SET activo = ? WHERE id = ?');
        $consulta->bind_param('ii', $valor, $id);
        $consulta->execute();
        $consulta->close();
        Auditoria::registrar('proveedor_producto', $id, $activo ? 'activar' : 'desactivar', $usuarioId);

        return true;
    }

    /* --------------------------------------------------------------------
     * RELACIÓN CON PRODUCTOS PROPIOS
     * ------------------------------------------------------------------ */

    /** Productos propios relacionados con un semiterminado. @return array<int, array<string, mixed>> */
    public static function productosRelacionados(int $id): array
    {
        $consulta = self::$db->prepare(
            'SELECT p.id, p.nombre, p.sku, p.costo_actual, p.activo FROM proveedor_producto_relaciones r
               JOIN productos p ON p.id = r.producto_id WHERE r.proveedor_producto_id = ? ORDER BY p.nombre'
        );
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /**
     * Semiterminados (y de qué proveedor) que se relacionan con un producto propio.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deProducto(int $productoId): array
    {
        $consulta = self::$db->prepare(
            'SELECT pp.id, pp.nombre, pp.codigo, pp.costo_unitario, pp.activo, pr.id AS proveedor_id, pr.nombre AS proveedor_nombre
               FROM proveedor_producto_relaciones r
               JOIN proveedor_productos pp ON pp.id = r.proveedor_producto_id
               JOIN proveedores pr ON pr.id = pp.proveedor_id
              WHERE r.producto_id = ? ORDER BY pr.nombre, pp.nombre'
        );
        $consulta->bind_param('i', $productoId);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Relaciona un semiterminado con un producto propio (idempotente: repetirlo no duplica). */
    public static function relacionar(int $id, int $productoId, int $usuarioId): bool
    {
        if (!self::buscarID($id)) {
            self::$errores[] = 'El producto del proveedor ya no existe.';
            return false;
        }
        $existe = self::$db->prepare('SELECT COUNT(*) FROM productos WHERE id = ?');
        $existe->bind_param('i', $productoId);
        $existe->execute();
        $hay = (int) $existe->get_result()->fetch_row()[0];
        $existe->close();
        if ($hay === 0) {
            self::$errores[] = 'Elige un producto propio válido.';
            return false;
        }

        $consulta = self::$db->prepare('INSERT IGNORE INTO proveedor_producto_relaciones (proveedor_producto_id, producto_id, creado_por) VALUES (?, ?, ?)');
        $consulta->bind_param('iii', $id, $productoId, $usuarioId);
        $consulta->execute();
        $nuevo = self::$db->affected_rows > 0;
        $consulta->close();
        if ($nuevo) {
            Auditoria::registrar('proveedor_producto', $id, 'relacionar', $usuarioId, null, ['producto_id' => $productoId]);
        }

        return true;
    }

    public static function desrelacionar(int $id, int $productoId, int $usuarioId): bool
    {
        $consulta = self::$db->prepare('DELETE FROM proveedor_producto_relaciones WHERE proveedor_producto_id = ? AND producto_id = ?');
        $consulta->bind_param('ii', $id, $productoId);
        $consulta->execute();
        $quitado = self::$db->affected_rows > 0;
        $consulta->close();
        if ($quitado) {
            Auditoria::registrar('proveedor_producto', $id, 'desrelacionar', $usuarioId, null, ['producto_id' => $productoId]);
        }

        return true;
    }
}
