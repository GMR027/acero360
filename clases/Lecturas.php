<?php

namespace App;

/**
 * Lecturas — flujo de «lectura inteligente» de facturas y comprobantes.
 *
 *   preparar()   guarda el original en una carpeta de lecturas y extrae los datos (LectorDocumental)
 *   revision     pantalla donde la persona corrige y confirma (nada se guarda antes)
 *   confirmar()  crea el gasto, o asocia el documento a un gasto/venta existente, sin duplicar
 *
 * Reglas: todo dato que no sea de confianza «alta» exige revisión explícita; si hay discrepancias o
 * posibles duplicados hace falta aceptarlos a propósito; quién confirmó y cuándo queda registrado.
 */
class Lecturas
{
    protected static $db;
    protected static array $errores = [];
    public static int $ultimoId = 0;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    public static function getErrores(): array
    {
        $e = self::$errores;
        self::$errores = [];
        return $e;
    }

    public const FUENTES = [
        'xml'       => 'XML del CFDI (datos estructurados)',
        'pdf_texto' => 'Texto del PDF',
        'ocr'       => 'OCR (reconocimiento óptico)',
        'manual'    => 'Captura manual',
    ];

    /** Carpeta de originales pendientes y confirmados (dentro del almacén, fuera de public/). */
    private static function carpeta(): string
    {
        $ruta = Archivos::base() . '/lecturas';
        if (!is_dir($ruta)) {
            @mkdir($ruta, 0750, true);
        }

        return $ruta;
    }

    /* --------------------------------------------------------------------
     * PREPARAR
     * ------------------------------------------------------------------ */

    /**
     * @param array<int, array<string, mixed>> $archivos entradas de $_FILES ya separadas (Archivos::listaDeSubidas)
     * @return int|null id de la lectura (null si ningún archivo es aceptable; errores en getErrores())
     */
    public static function preparar(array $archivos, string $destino, int $usuarioId, bool $esSubida = true): ?int
    {
        self::$errores = [];
        if (!in_array($destino, ['gasto', 'venta', 'compra', 'complemento', 'complemento_venta'], true)) {
            self::$errores[] = 'Elige si el documento es de un gasto, de una venta, de una compra, de un complemento de compra o de un complemento de venta.';
            return null;
        }
        if (!$archivos) {
            self::$errores[] = 'Elige al menos un archivo.';
            return null;
        }
        if (count($archivos) > 5) {
            self::$errores[] = 'Sube como máximo 5 archivos a la vez (por ejemplo XML + PDF de la misma factura).';
            return null;
        }

        $validos = [];
        foreach ($archivos as $archivo) {
            $v = Archivos::validar($archivo, $esSubida);
            if ($v === null) {
                self::$errores[] = ($archivo['name'] ?? 'archivo') . ': ' . (Archivos::getErrores()[0] ?? 'no es válido');
                Archivos::getErrores();
                continue;
            }
            $v['nombre'] = (string) $archivo['name'];
            $validos[] = $v;
        }
        if (!$validos) {
            return null;
        }

        // Fuente principal: XML > PDF > imagen.
        $orden = ['xml' => 0, 'pdf' => 1, 'imagen' => 2];
        usort($validos, static fn ($a, $b) => $orden[$a['familia']] <=> $orden[$b['familia']]);

        $guardados = [];
        foreach ($validos as $v) {
            $nombre = bin2hex(random_bytes(16)) . '.' . $v['ext'];
            if (file_put_contents(self::carpeta() . '/' . $nombre, $v['contenido']) === false) {
                self::$errores[] = 'No fue posible guardar el archivo recibido.';
                return null;
            }
            @chmod(self::carpeta() . '/' . $nombre, 0640);
            $guardados[] = $v + ['ruta' => 'lecturas/' . $nombre, 'completa' => self::carpeta() . '/' . $nombre];
        }

        $principal = $guardados[0];
        $lectura = LectorDocumental::leer($principal['completa'], $principal['familia']);

        // Documentos complementarios (p. ej. el PDF de un XML): se cruzan con la fuente principal.
        $extras = [];
        foreach (array_slice($guardados, 1) as $g) {
            $extras[] = ['archivo' => $g['ruta'], 'nombre' => $g['nombre'], 'mime' => $g['mime'], 'sha256' => $g['sha256'], 'familia' => $g['familia']];
            if ($g['familia'] === 'xml') {
                $lectura['avisos'][] = 'Subiste más de un XML: solo se leyó «' . $principal['nombre'] . '»; los demás se ignoran.';
                continue;
            }
            $otro = LectorDocumental::leer($g['completa'], $g['familia']);
            foreach (['uuid', 'total'] as $clave) {
                $a = $lectura['campos'][$clave]['valor'] ?? '';
                $b = $otro['campos'][$clave]['valor'] ?? '';
                if ($a !== '' && $b !== '' && ($clave === 'uuid' ? $a !== $b : Contabilidad::centavos($a) !== Contabilidad::centavos($b))) {
                    $lectura['avisos'][] = 'Discrepancia: el ' . ($clave === 'uuid' ? 'UUID' : 'total') . ' del XML (' . $a . ') no coincide con el de «' . $g['nombre'] . '» (' . $b . ').';
                }
            }
        }

        $contribuyente = Contabilidad::contribuyentePrincipal();
        $datos = json_encode([
            'campos' => $lectura['campos'], 'conceptos' => $lectura['conceptos'], 'extras' => $extras, 'familia' => $principal['familia'],
        ], JSON_UNESCAPED_UNICODE);
        $avisos = json_encode($lectura['avisos'], JSON_UNESCAPED_UNICODE);

        $consulta = self::$db->prepare(
            'INSERT INTO lecturas_documentales (contribuyente_id, destino, nombre_original, archivo, mime, sha256, fuente,
                    datos_leidos, avisos, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $original = mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', basename($principal['nombre'])), 0, 255);
        $consulta->bind_param(
            'issssssssi',
            $contribuyente, $destino, $original, $principal['ruta'], $principal['mime'], $principal['sha256'],
            $lectura['fuente'], $datos, $avisos, $usuarioId
        );
        $consulta->execute();
        $id = (int) self::$db->insert_id;
        $consulta->close();

        Auditoria::registrar('lectura', $id, 'leer', $usuarioId, null, ['fuente' => $lectura['fuente'], 'destino' => $destino]);
        self::$ultimoId = $id;

        return $id;
    }

    /** @return array<string, mixed>|null */
    public static function buscarID(int $id): ?array
    {
        $consulta = self::$db->prepare('SELECT * FROM lecturas_documentales WHERE id = ?');
        $consulta->bind_param('i', $id);
        $consulta->execute();
        $fila = $consulta->get_result()->fetch_assoc();
        $consulta->close();
        if (!$fila) {
            return null;
        }
        $fila['leidos']  = json_decode((string) $fila['datos_leidos'], true) ?: ['campos' => [], 'conceptos' => [], 'extras' => []];
        $fila['avisos_lista'] = json_decode((string) $fila['avisos'], true) ?: [];

        return $fila;
    }

    /** Valores iniciales del formulario de revisión a partir de lo leído. */
    public static function valoresIniciales(array $lectura): array
    {
        $v = [];
        foreach (array_keys(LectorDocumental::CAMPOS) as $campo) {
            $v[$campo] = (string) ($lectura['leidos']['campos'][$campo]['valor'] ?? '');
        }

        return $v;
    }

    /**
     * Propuesta para precargar el formulario de una venta o de un gasto.
     *
     * Se apoya en lo que ya se leyó y guardó al preparar la lectura: no vuelve a
     * abrir el archivo. El cruce contra clientes, proveedores, negocios y
     * productos lo hace CoincidenciasFactura, el mismo motor que usan los
     * botones de registro inteligente.
     *
     * @return array<string, mixed>|null null si la lectura no existe
     */
    public static function propuesta(int $id, string $destino): ?array
    {
        $lectura = self::buscarID($id);
        if (!$lectura) {
            return null;
        }
        // Una factura de compra también puede proponerse como gasto (es el mismo
        // cotejo: emisor = a quien le pagué). En sentido inverso no se permite.
        $origen = (string) $lectura['destino'];
        $compatible = $origen === $destino || ($destino === 'gasto' && $origen === 'compra');
        if (!$compatible) {
            return null;
        }

        $analisis = CoincidenciasFactura::analizar([
            'fuente'    => (string) $lectura['fuente'],
            'campos'    => (array) ($lectura['leidos']['campos'] ?? []),
            'conceptos' => (array) ($lectura['leidos']['conceptos'] ?? []),
            'avisos'    => (array) ($lectura['avisos_lista'] ?? []),
            // Con la huella del archivo se detecta también el mismo PDF/XML ya adjunto.
            'sha256'    => (string) ($lectura['sha256'] ?? ''),
        ], $destino);

        return $analisis + [
            'lectura_id' => (int) $lectura['id'],
            'destino'    => (string) $lectura['destino'],
            'nombre'     => (string) $lectura['nombre_original'],
            'creado_en'  => (string) $lectura['creado_en'],
            'estado'     => (string) $lectura['estado'],
            'archivo'    => (string) $lectura['archivo'],
            'mime'       => (string) $lectura['mime'],
        ];
    }

    /* --------------------------------------------------------------------
     * DISCREPANCIAS, DUPLICADOS Y COINCIDENCIAS
     * ------------------------------------------------------------------ */

    /** @return string[] */
    public static function discrepancias(array $v, array $lectura): array
    {
        $a = [];
        $sub = $v['subtotal'] ?? '';
        $iva = $v['iva'] ?? '';
        $tot = $v['total'] ?? '';
        if (is_numeric($sub) && is_numeric($iva) && is_numeric($tot) && abs((float) $sub + (float) $iva - (float) $tot) > 0.02) {
            $a[] = 'Subtotal + IVA (' . number_format((float) $sub + (float) $iva, 2) . ') no es igual al total (' . number_format((float) $tot, 2) . ').';
        }
        $contribuyente = Contabilidad::contribuyente((int) $lectura['contribuyente_id']);
        $rfc = strtoupper((string) ($contribuyente['rfc'] ?? ''));
        $rol = $lectura['destino'] === 'gasto' ? 'receptor_rfc' : 'emisor_rfc';
        if ($rfc !== '' && ($v[$rol] ?? '') !== '' && strtoupper($v[$rol]) !== $rfc) {
            $a[] = 'El RFC del ' . ($lectura['destino'] === 'gasto' ? 'receptor' : 'emisor') . ' (' . $v[$rol] . ') no coincide con el del contribuyente (' . $rfc . ').';
        }
        if (($v['fecha'] ?? '') !== '' && strtotime($v['fecha']) > strtotime('+1 day')) {
            $a[] = 'La fecha del documento es futura.';
        }
        if (($v['moneda'] ?? 'MXN') !== '' && strtoupper($v['moneda']) !== 'MXN') {
            $a[] = 'La moneda es ' . strtoupper($v['moneda']) . ': los importes se guardarían sin conversión a pesos.';
        }
        if (($v['uuid'] ?? '') !== '' && !preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', strtoupper($v['uuid']))) {
            $a[] = 'El UUID no tiene el formato correcto.';
        }
        if (($v['folio_comercial'] ?? '') !== '' && strtoupper($v['folio_comercial']) === strtoupper($v['uuid'] ?? '')) {
            $a[] = 'El folio comercial es igual al UUID: son datos distintos, revisa cuál es cuál.';
        }
        foreach ($lectura['avisos_lista'] as $aviso) {
            if (str_starts_with($aviso, 'Discrepancia')) {
                $a[] = $aviso;
            }
        }

        return $a;
    }

    /**
     * Documento ya existente (mismo archivo o mismo UUID) — duplicado real.
     *
     * @return string[]
     */
    public static function duplicados(array $v, array $lectura, ?int $exceptoLectura = null): array
    {
        $d = [];
        $uuid = strtoupper(trim($v['uuid'] ?? ''));
        $sha  = $lectura['sha256'];
        $exc  = $exceptoLectura ?? (int) $lectura['id'];

        $q = self::$db->prepare("SELECT entidad, entidad_id FROM documentos WHERE activo = 1 AND (sha256 = ?" . ($uuid !== '' ? ' OR uuid_fiscal = ?' : '') . ') LIMIT 3');
        $uuid !== '' ? $q->bind_param('ss', $sha, $uuid) : $q->bind_param('s', $sha);
        $q->execute();
        foreach ($q->get_result() as $f) {
            $d[] = 'Este documento ya está adjunto al ' . ($f['entidad'] === 'gasto' ? 'gasto' : 'ingreso') . ' #' . (int) $f['entidad_id'] . '.';
        }
        $q->close();

        if ($uuid !== '') {
            foreach ([['gastos', 'gasto', "estado = 'vigente'"], ['ingresos', 'ingreso', "estado = 'vigente'"]] as [$tabla, $nombre, $cond]) {
                $q = self::$db->prepare("SELECT id FROM $tabla WHERE uuid_fiscal = ? AND $cond LIMIT 1");
                $q->bind_param('s', $uuid);
                $q->execute();
                if ($f = $q->get_result()->fetch_assoc()) {
                    $d[] = "El UUID $uuid ya está registrado en el $nombre #" . (int) $f['id'] . '.';
                }
                $q->close();
            }
        }

        $q = self::$db->prepare("SELECT id, entidad, entidad_id FROM lecturas_documentales WHERE sha256 = ? AND estado = 'confirmada' AND id <> ? LIMIT 1");
        $q->bind_param('si', $sha, $exc);
        $q->execute();
        if ($f = $q->get_result()->fetch_assoc()) {
            $d[] = 'Este mismo archivo ya se leyó y confirmó antes (lectura #' . (int) $f['id'] . ').';
        }
        $q->close();

        return array_values(array_unique($d));
    }

    /**
     * Registros existentes que podrían ser el mismo documento (UUID, RFC, fecha, importe).
     *
     * @return array<int, array<string, mixed>> con 'motivos' (string[]) y 'puntos'
     */
    public static function coincidencias(array $v, string $destino): array
    {
        $uuid  = strtoupper(trim($v['uuid'] ?? ''));
        $total = is_numeric($v['total'] ?? '') ? number_format((float) $v['total'], 2, '.', '') : null;
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['fecha'] ?? '') ? $v['fecha'] : null;
        $folio = trim($v['folio_comercial'] ?? '');
        $dias  = $destino === 'gasto' ? 15 : 45;
        $filas = [];

        if ($destino === 'gasto') {
            $sql = "SELECT id, fecha, beneficiario AS tercero, concepto, total, uuid_fiscal, folio_comercial AS folio, rfc_emisor AS rfc
                      FROM gastos WHERE estado = 'vigente' AND (";
        } else {
            $sql = "SELECT i.id, i.fecha, COALESCE(NULLIF(cl.nombre_empresa, ''), cl.nombre) AS tercero, i.folio AS concepto, i.total,
                           i.uuid_fiscal, i.factura_folio AS folio, cl.rfc AS rfc, i.estado_facturacion
                      FROM ingresos i JOIN clientes cl ON cl.id = i.cliente_id WHERE i.estado = 'vigente' AND (";
        }
        $col = $destino === 'gasto' ? '' : 'i.';
        $cond = [];
        $tipos = '';
        $vals = [];
        if ($uuid !== '') {
            $cond[] = "{$col}uuid_fiscal = ?"; $tipos .= 's'; $vals[] = $uuid;
        }
        if ($folio !== '') {
            $cond[] = $destino === 'gasto' ? 'folio_comercial = ?' : 'i.factura_folio = ?'; $tipos .= 's'; $vals[] = $folio;
        }
        if ($total !== null) {
            $c = "ABS({$col}total - ?) < 0.01";
            $tipos .= 's'; $vals[] = $total;
            if ($fecha !== null) {
                $c .= " AND {$col}fecha BETWEEN DATE_SUB(?, INTERVAL $dias DAY) AND DATE_ADD(?, INTERVAL $dias DAY)";
                $tipos .= 'ss'; $vals[] = $fecha; $vals[] = $fecha;
            }
            $cond[] = "($c)";
        }
        if (!$cond) {
            return [];
        }
        $q = self::$db->prepare($sql . implode(' OR ', $cond) . ") ORDER BY {$col}fecha DESC LIMIT 10");
        $q->bind_param($tipos, ...$vals);
        $q->execute();
        foreach ($q->get_result() as $f) {
            $motivos = [];
            $puntos = 0;
            if ($uuid !== '' && strtoupper((string) $f['uuid_fiscal']) === $uuid) { $motivos[] = 'mismo UUID'; $puntos += 100; }
            if ($folio !== '' && (string) $f['folio'] === $folio) { $motivos[] = 'mismo folio comercial'; $puntos += 30; }
            if ($total !== null && Contabilidad::centavos($f['total']) === Contabilidad::centavos($total)) { $motivos[] = 'mismo importe'; $puntos += 25; }
            if ($fecha !== null && abs(strtotime($f['fecha']) - strtotime($fecha)) <= $dias * 86400) { $motivos[] = 'fecha cercana'; $puntos += 10; }
            $rfcDoc = strtoupper($destino === 'gasto' ? ($v['emisor_rfc'] ?? '') : ($v['receptor_rfc'] ?? ''));
            if ($rfcDoc !== '' && strtoupper((string) $f['rfc']) === $rfcDoc) { $motivos[] = 'mismo RFC'; $puntos += 20; }
            $f['motivos'] = $motivos;
            $f['puntos'] = $puntos;
            $filas[] = $f;
        }
        $q->close();
        usort($filas, static fn ($a, $b) => $b['puntos'] <=> $a['puntos']);

        return $filas;
    }

    /* --------------------------------------------------------------------
     * CONFIRMAR
     * ------------------------------------------------------------------ */

    /**
     * Confirma una lectura revisada.
     *
     * @param array<string, mixed> $p POST del formulario de revisión
     */
    public static function confirmar(int $id, array $p, int $usuarioId): bool
    {
        self::$errores = [];
        $lectura = self::buscarID($id);
        if (!$lectura || $lectura['estado'] !== 'pendiente') {
            self::$errores[] = 'Esta lectura ya no está pendiente de revisión.';
            return false;
        }
        // Una lectura de compra o de complemento NO se confirma aquí: su flujo es propio
        // (Compras Inteligentes, Complementos de compra o de venta), nunca un gasto o venta sueltos.
        if (in_array((string) $lectura['destino'], ['compra', 'complemento', 'complemento_venta'], true)) {
            self::$errores[] = 'Esta factura se revisa en Compras Inteligentes o en Complementos de pago, no en la lectura de gastos.';
            return false;
        }

        // 1. Valores finales: lo que la persona dejó en cada campo.
        $v = [];
        foreach (array_keys(LectorDocumental::CAMPOS) as $campo) {
            $v[$campo] = trim((string) ($p[$campo] ?? ''));
        }
        $v['uuid'] = strtoupper($v['uuid']);

        // 2. Todo campo no «alto» con valor exige revisión explícita (o haberlo corregido).
        $revisados = array_keys((array) ($p['revisado'] ?? []));
        $pendientes = [];
        foreach ($lectura['leidos']['campos'] as $campo => $d) {
            $editado = $v[$campo] !== (string) $d['valor'];
            if ($d['confianza'] !== 'alta' && $v[$campo] !== '' && !$editado && !in_array($campo, $revisados, true)) {
                $pendientes[] = LectorDocumental::CAMPOS[$campo] ?? $campo;
            }
        }
        if ($pendientes) {
            self::$errores[] = 'Marca como revisados estos campos de lectura dudosa (o corrígelos): ' . implode(', ', $pendientes) . '.';
            return false;
        }

        // 3. Discrepancias y duplicados: se aceptan a propósito, nunca en silencio.
        $discrepancias = self::discrepancias($v, $lectura);
        if ($discrepancias && empty($p['acepta_discrepancias'])) {
            self::$errores[] = 'Hay discrepancias en el documento: corrígelas o marca que las revisaste.';
            return false;
        }
        $duplicados = self::duplicados($v, $lectura);
        if ($duplicados) {
            self::$errores[] = 'No se confirma porque el documento ya existe: ' . implode(' ', $duplicados);
            return false;
        }

        $modo = (string) ($p['modo'] ?? 'nuevo');
        $objetivo = (int) ($p['objetivo_id'] ?? 0);
        $coincidencias = self::coincidencias($v, $lectura['destino']);

        $resultado = $lectura['destino'] === 'gasto'
            ? self::confirmarGasto($lectura, $v, $p, $modo, $objetivo, $coincidencias, $usuarioId)
            : self::confirmarVenta($lectura, $v, $p, $objetivo, $usuarioId);
        if ($resultado === null) {
            return false;
        }
        [$entidad, $entidadId, $notas] = $resultado;

        $confirmados = json_encode(['valores' => $v, 'revisados' => $revisados, 'discrepancias_aceptadas' => $discrepancias,
            'modo' => $modo, 'notas' => $notas], JSON_UNESCAPED_UNICODE);
        $q = self::$db->prepare("UPDATE lecturas_documentales SET estado = 'confirmada', entidad = ?, entidad_id = ?, datos_confirmados = ?,
                confirmado_por = ?, confirmado_en = NOW() WHERE id = ?");
        $q->bind_param('sisii', $entidad, $entidadId, $confirmados, $usuarioId, $id);
        $q->execute();
        $q->close();
        Auditoria::registrar('lectura', $id, 'confirmar', $usuarioId, null, ['entidad' => $entidad, 'entidad_id' => $entidadId, 'fuente' => $lectura['fuente']]);

        self::$ultimoId = $entidadId;
        return true;
    }

    /** @return array{0: string, 1: int, 2: string[]}|null */
    private static function confirmarGasto(array $lectura, array $v, array $p, string $modo, int $objetivo, array $coincidencias, int $usuarioId): ?array
    {
        $notas = [];
        if ($modo === 'existente') {
            $gasto = $objetivo ? Gastos::buscarID($objetivo) : null;
            if (!$gasto || $gasto['estado'] !== 'vigente') {
                self::$errores[] = 'Elige el gasto vigente al que se asocia el documento.';
                return null;
            }
            if (Contabilidad::periodoCerrado((int) $gasto['contribuyente_id'], $gasto['fecha'])) {
                self::$errores[] = Contabilidad::mensajePeriodoCerrado($gasto['fecha']);
                return null;
            }
            if ($v['uuid'] !== '' && !empty($gasto['uuid_fiscal']) && strtoupper($gasto['uuid_fiscal']) !== $v['uuid']) {
                self::$errores[] = 'El gasto elegido ya tiene otro UUID (' . $gasto['uuid_fiscal'] . '): no se sobrescribe.';
                return null;
            }
            // Solo se completan los datos de factura que el gasto no tenga; no se tocan importes ni fecha.
            $q = self::$db->prepare(
                'UPDATE gastos SET uuid_fiscal = COALESCE(uuid_fiscal, NULLIF(?, "")), folio_comercial = COALESCE(folio_comercial, NULLIF(?, "")),
                        rfc_emisor = COALESCE(rfc_emisor, NULLIF(?, "")), rfc_receptor = COALESCE(rfc_receptor, NULLIF(?, "")),
                        forma_pago_sat = COALESCE(forma_pago_sat, NULLIF(?, "")), metodo_pago_sat = COALESCE(metodo_pago_sat, NULLIF(?, "")),
                        comprobante_fiscal = 1, lectura_id = COALESCE(lectura_id, ?) WHERE id = ?'
            );
            $metodo = in_array($v['metodo_pago'], ['PUE', 'PPD'], true) ? $v['metodo_pago'] : '';
            $lid = (int) $lectura['id'];
            $q->bind_param('ssssssii', $v['uuid'], $v['folio_comercial'], $v['emisor_rfc'], $v['receptor_rfc'], $v['forma_pago'], $metodo, $lid, $objetivo);
            $q->execute();
            $q->close();
            if (Contabilidad::centavos($gasto['total']) !== Contabilidad::centavos($v['total'] ?: '0')) {
                $notas[] = 'El total del documento (' . $v['total'] . ') difiere del gasto (' . $gasto['total'] . '); no se modificó el gasto.';
            }
            $fallos = self::adjuntar($lectura, 'gasto', $objetivo, $usuarioId);
            Auditoria::registrar('gasto', $objetivo, 'asociar_documento', $usuarioId, null, ['lectura_id' => (int) $lectura['id']]);

            return ['gasto', $objetivo, array_merge($notas, $fallos)];
        }

        // Gasto nuevo: si hay un registro parecido, hay que decir expresamente que no es el mismo.
        if ($coincidencias && empty($p['acepta_nuevo'])) {
            self::$errores[] = 'Hay gastos que podrían ser este mismo documento: asócialo a uno de ellos o confirma que es un gasto distinto.';
            return null;
        }
        $tasa = 16;
        if (is_numeric($v['subtotal']) && (float) $v['subtotal'] > 0 && is_numeric($v['iva'])) {
            $calculada = round((float) $v['iva'] / (float) $v['subtotal'] * 100);
            $tasa = in_array((int) $calculada, [0, 8, 16], true) ? (int) $calculada : 16;
        } elseif ($v['iva'] === '0' || $v['iva'] === '0.00') {
            $tasa = 0;
        }

        $datos = [
            'fecha' => $v['fecha'], 'beneficiario' => trim((string) ($p['beneficiario'] ?? '')) ?: $v['emisor_nombre'],
            'concepto' => trim((string) ($p['concepto'] ?? '')), 'categoria_id' => (int) ($p['categoria_id'] ?? 0),
            'total' => $v['total'], 'iva_tasa' => (string) $tasa,
            'estado_pago' => ($p['estado_pago'] ?? '') === 'pagado' ? 'pagado' : 'pendiente', 'fecha_pago' => $v['fecha'],
            'comprobante_fiscal' => 1, 'uuid_fiscal' => $v['uuid'], 'folio_comercial' => $v['folio_comercial'], 'moneda' => $v['moneda'],
            'forma_pago_sat' => $v['forma_pago'], 'metodo_pago_sat' => $v['metodo_pago'], 'rfc_emisor' => $v['emisor_rfc'],
            'rfc_receptor' => $v['receptor_rfc'], 'lectura_id' => (int) $lectura['id'],
            'observaciones' => 'Capturado con lectura de documento #' . (int) $lectura['id'] . ' (' . (self::FUENTES[$lectura['fuente']] ?? $lectura['fuente']) . '), revisado por una persona.',
        ];
        if (!Gastos::crear($datos, $usuarioId)) {
            self::$errores = Gastos::getErrores();
            return null;
        }
        $nuevo = Gastos::$ultimoId;
        $fallos = self::adjuntar($lectura, 'gasto', $nuevo, $usuarioId);

        return ['gasto', $nuevo, $fallos];
    }

    /** @return array{0: string, 1: int, 2: string[]}|null */
    private static function confirmarVenta(array $lectura, array $v, array $p, int $ingresoId, int $usuarioId): ?array
    {
        $ingreso = $ingresoId ? Contabilidad::buscarIngreso($ingresoId) : null;
        if (!$ingreso) {
            self::$errores[] = 'Elige la venta a la que corresponde la factura (no se crean ventas desde un documento).';
            return null;
        }
        $notas = [];
        if (Contabilidad::centavos($ingreso['total']) !== Contabilidad::centavos($v['total'] ?: '0')) {
            $notas[] = 'El total del documento (' . $v['total'] . ') difiere del de la venta (' . $ingreso['total'] . ').';
            if (empty($p['acepta_discrepancias'])) {
                self::$errores[] = 'El total del documento no coincide con el de la venta ' . $ingreso['folio'] . ': corrígelo o acepta la discrepancia.';
                return null;
            }
        }
        $fallos = self::adjuntar($lectura, 'ingreso', $ingresoId, $usuarioId);
        $tieneXml = ($lectura['leidos']['familia'] ?? '') === 'xml';
        $ok = Contabilidad::actualizarFacturacion(
            $ingresoId, 'facturada', $v['folio_comercial'], $v['uuid'], $v['fecha'], $usuarioId,
            !$tieneXml && !empty($p['confirma_manual'])
        );
        if (!$ok) {
            self::$errores = Contabilidad::getErrores() ?: ['No fue posible marcar la venta como facturada.'];
            return null;
        }

        return ['ingreso', $ingresoId, array_merge($notas, $fallos)];
    }

    /**
     * Asocia al registro el original y los complementarios de la lectura.
     *
     * Se usa al confirmar una lectura y al guardar el gasto que se abrió con el
     * botón inteligente: así el XML y el PDF que se subieron juntos quedan los
     * dos adjuntos al registro.
     *
     * @return string[] avisos de lo que no se pudo adjuntar
     */
    public static function adjuntar(array $lectura, string $entidad, int $entidadId, int $usuarioId): array
    {
        $fallos = [];
        $lista = [['archivo' => $lectura['archivo'], 'nombre' => $lectura['nombre_original']]];
        foreach ($lectura['leidos']['extras'] ?? [] as $e) {
            $lista[] = ['archivo' => $e['archivo'], 'nombre' => $e['nombre']];
        }
        foreach ($lista as $item) {
            $ruta = Archivos::base() . '/' . $item['archivo'];
            if (!is_file($ruta)) {
                $fallos[] = $item['nombre'] . ': el original ya no está disponible.';
                continue;
            }
            $ok = Archivos::subir($entidad, $entidadId, [
                'name' => $item['nombre'], 'tmp_name' => $ruta, 'size' => filesize($ruta), 'error' => UPLOAD_ERR_OK,
            ], 'auto', $usuarioId, null, '', false);
            if (!$ok) {
                $fallos[] = $item['nombre'] . ': ' . (Archivos::getErrores()[0] ?? 'no se pudo adjuntar');
            }
        }

        return $fallos;
    }

    public static function descartar(int $id, int $usuarioId): bool
    {
        $q = self::$db->prepare("UPDATE lecturas_documentales SET estado = 'descartada', confirmado_por = ?, confirmado_en = NOW()
                WHERE id = ? AND estado = 'pendiente'");
        $q->bind_param('ii', $usuarioId, $id);
        $q->execute();
        $cambio = $q->affected_rows > 0;
        $q->close();
        if ($cambio) {
            Auditoria::registrar('lectura', $id, 'descartar', $usuarioId);
        }

        return $cambio;
    }

    /** @return array<int, array<string, mixed>> */
    public static function pendientes(int $limite = 10): array
    {
        $q = self::$db->prepare("SELECT id, destino, nombre_original, fuente, creado_en FROM lecturas_documentales WHERE estado = 'pendiente' ORDER BY id DESC LIMIT ?");
        $q->bind_param('i', $limite);
        $q->execute();
        $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        return $filas;
    }

    /* -------------------------------------------------------------------- *
     * ALTA RÁPIDA DE CLIENTE DESDE UNA FACTURA (venta inteligente)
     * ------------------------------------------------------------------ */

    /** Datos del CFDI archivado en una lectura, releídos del XML original.
     *  Devuelve el array `datos` de CfdiXml::leer() (con los campos fiscales del
     *  receptor que el alta rápida usa) o null si la lectura no existe o su
     *  archivo ya no se puede leer. */
    public static function cfdi(int $id): ?array
    {
        $lectura = self::buscarID($id);
        if (!$lectura) {
            return null;
        }
        $ruta = Archivos::base() . '/' . (string) $lectura['archivo'];
        if (!is_file($ruta)) {
            return null;
        }
        $cfdi = CfdiXml::leer((string) file_get_contents($ruta));
        return $cfdi['valido'] ? $cfdi['datos'] : null;
    }

    /**
     * Alta rápida de cliente con los datos del RECEPTOR de una factura cargada
     * con «Registro Venta Inteligente» (FASE 9). Nada se inventa: cada dato sale
     * del XML o lo captura la persona en el modal; el RFC se coteja contra el
     * documento para que no se pueda dar de alta a otra persona por error.
     *
     * @param array{nombre?:string, nombre_empresa?:string, rfc?:string, codigo_postal?:string,
     *               regimen_fiscal?:string, uso_cfdi?:string, telefono?:string, email?:string,
     *               ubicacion?:string} $datos
     * @return array{ok: bool, cliente_id?: int, ya_existia?: bool, errores: string[]}
     */
    public static function altaClienteDesdeLectura(int $lecturaId, array $datos, int $usuarioId): array
    {
        $devolver = static fn (array $extra = []): array => $extra + ['ok' => false, 'errores' => []];

        $cfdi = self::cfdi($lecturaId);
        if ($cfdi === null) {
            return $devolver(['errores' => ['La lectura ya no existe o su archivo XML no se pudo leer.']]);
        }
        $rfcDocumento = VentasFiscal::normalizarRfc((string) $cfdi['receptor_rfc']);
        if ($rfcDocumento === '') {
            return $devolver(['errores' => ['La factura no trae el RFC del receptor: no se puede dar de alta al cliente con ella.']]);
        }
        $rfc = VentasFiscal::normalizarRfc((string) ($datos['rfc'] ?? ''));
        if ($rfc !== $rfcDocumento) {
            return $devolver(['errores' => ['El RFC capturado no coincide con el receptor de la factura (' . $rfcDocumento . ').']]);
        }

        // El RFC ya registrado: NO se duplica la ficha, se reutiliza (la venta queda con ese cliente).
        $existente = VentasFiscal::buscarClientePorRfc($rfc);
        if ($existente !== null) {
            return ['ok' => true, 'cliente_id' => (int) $existente['id'], 'ya_existia' => true, 'errores' => []];
        }

        $cliente = new Clientes([
            // Si la persona no capturó nombre/razón social, se toman del RECEPTOR de la
            // factura (los datos vienen del documento, no se inventan).
            'nombre'         => trim((string) ($datos['nombre'] ?? '')) ?: trim((string) ($cfdi['receptor_nombre'] ?? '')),
            'nombre_empresa' => trim((string) ($datos['nombre_empresa'] ?? '')) ?: trim((string) ($cfdi['receptor_nombre'] ?? '')),
            'alias'          => trim((string) ($datos['alias'] ?? '')),
            'rfc'            => $rfc,
            'codigo_postal'  => trim((string) ($datos['codigo_postal'] ?? '')),
            'regimen_fiscal' => trim((string) ($datos['regimen_fiscal'] ?? '')),
            'uso_cfdi'       => trim((string) ($datos['uso_cfdi'] ?? '')),
            'telefono'       => trim((string) ($datos['telefono'] ?? '')),
            'email'          => trim((string) ($datos['email'] ?? '')),
            'ubicacion'      => trim((string) ($datos['ubicacion'] ?? '')),
            'notas'          => trim((string) ($datos['notas'] ?? '')) ?: null,
        ]);
        $errores = $cliente->validar();
        if ($errores !== []) {
            return $devolver(['errores' => $errores]);
        }
        if (!$cliente->crear()) {
            return $devolver(['errores' => Clientes::getErrores() ?: ['No fue posible guardar el cliente.']]);
        }

        Auditoria::registrar('cliente', (int) $cliente->id, 'crear', $usuarioId,
            'Alta rápida con los datos de la factura (venta inteligente)', ['rfc' => $rfc, 'lectura' => $lecturaId]);

        return ['ok' => true, 'cliente_id' => (int) $cliente->id, 'ya_existia' => false, 'errores' => []];
    }
}
