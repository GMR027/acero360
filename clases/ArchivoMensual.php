<?php

namespace App;

/**
 * ArchivoMensual — ZIP con los documentos de un mes (ventas o gastos).
 *
 * Carpetas: «Ventas facturadas», «Ventas no facturadas», «Gastos y compras»,
 * «XML» y «Pendientes de clasificacion» (documentos tipo «Otro» o de ventas
 * aún sin clasificar). Cada archivo lleva fecha, folio/proveedor y el id del
 * documento, así que dos archivos distintos nunca se sobrescriben. Incluye
 * indice.csv con folio, cliente o proveedor y tipo de documento.
 *
 * Solo lee: descargar el archivo mensual no modifica nada.
 */
class ArchivoMensual
{
    /** @var string[] */
    protected static $errores = [];

    public static function getErrores(): array
    {
        $e = self::$errores;
        self::$errores = [];
        return $e;
    }

    /** @var \mysqli */
    protected static $db;

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    private static function slug(string $texto): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
        $t = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $t), '-');

        return mb_substr($t !== '' ? $t : 'sin-nombre', 0, 40);
    }

    /**
     * Documentos activos del periodo con los datos de su operación.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function documentos(string $tipo, int $anio, int $mes): array
    {
        if ($tipo === 'ventas') {
            $sql = "SELECT d.*, i.fecha, i.folio AS referencia, i.estado_facturacion,
                           COALESCE(NULLIF(cl.nombre_empresa, ''), cl.nombre) AS contraparte
                      FROM documentos d
                      JOIN ingresos i ON i.id = d.entidad_id AND d.entidad = 'ingreso'
                      JOIN clientes cl ON cl.id = i.cliente_id
                     WHERE d.activo = 1 AND i.estado = 'vigente' AND YEAR(i.fecha) = ? AND MONTH(i.fecha) = ?
                     ORDER BY i.fecha, d.id";
        } else {
            $sql = "SELECT d.*, g.fecha, g.concepto AS referencia, 'n/a' AS estado_facturacion,
                           g.beneficiario AS contraparte
                      FROM documentos d
                      JOIN gastos g ON g.id = d.entidad_id AND d.entidad = 'gasto'
                     WHERE d.activo = 1 AND g.estado = 'vigente' AND YEAR(g.fecha) = ? AND MONTH(g.fecha) = ?
                     ORDER BY g.fecha, d.id";
        }

        $consulta = self::$db->prepare($sql);
        $consulta->bind_param('ii', $anio, $mes);
        $consulta->execute();
        $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        $consulta->close();

        return $filas;
    }

    /** Carpeta del ZIP a la que va un documento. */
    public static function carpeta(string $tipo, array $doc): string
    {
        if ($doc['tipo'] === 'xml') {
            return 'XML';
        }
        if ($doc['tipo'] === 'otro') {
            return 'Pendientes de clasificacion';
        }
        if ($tipo === 'gastos') {
            return 'Gastos y compras';
        }

        return match ($doc['estado_facturacion']) {
            'facturada'    => 'Ventas facturadas',
            'no_facturada' => 'Ventas no facturadas',
            default        => 'Pendientes de clasificacion',
        };
    }

    /**
     * Arma el ZIP en un archivo temporal.
     *
     * @return array{ruta: string, archivos: int, nombre: string}|null
     */
    public static function construir(string $tipo, int $anio, int $mes): ?array
    {
        $tipo = $tipo === 'gastos' ? 'gastos' : 'ventas';
        $docs = self::documentos($tipo, $anio, $mes);

        $temporal = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        if ($zip->open($temporal, \ZipArchive::OVERWRITE) !== true) {
            self::$errores[] = 'No fue posible crear el archivo ZIP.';
            return null;
        }

        $indice = [];
        $agregados = 0;
        foreach ($docs as $doc) {
            $ruta = Archivos::rutaAbsoluta($doc);
            if ($ruta === null) {
                $indice[] = ['(archivo no encontrado en el almacén)', '', $doc['fecha'], $doc['referencia'], $doc['contraparte'],
                    Archivos::TIPOS[$doc['tipo']] ?? $doc['tipo'], $doc['uuid_fiscal'], $doc['estado_validacion'], $doc['sha256'], $doc['tamano'], $doc['nombre_original']];
                continue;
            }

            $carpeta = self::carpeta($tipo, $doc);
            $ext = strtolower(pathinfo((string) $doc['archivo'], PATHINFO_EXTENSION));
            $nombre = $doc['fecha'] . '_' . self::slug((string) ($tipo === 'ventas' ? $doc['referencia'] : $doc['contraparte']))
                . '_doc' . $doc['id'] . '.' . $ext;
            $zip->addFile($ruta, $carpeta . '/' . $nombre);
            $agregados++;

            $indice[] = [$carpeta, $nombre, $doc['fecha'], $doc['referencia'], $doc['contraparte'],
                Archivos::TIPOS[$doc['tipo']] ?? $doc['tipo'], $doc['uuid_fiscal'], $doc['estado_validacion'],
                $doc['sha256'], $doc['tamano'], $doc['nombre_original']];
        }

        $zip->addFromString('indice.csv', Exportador::csv(
            ['Carpeta', 'Archivo', 'Fecha de operacion', $tipo === 'ventas' ? 'Folio de venta' : 'Concepto',
                $tipo === 'ventas' ? 'Cliente' : 'Proveedor o beneficiario', 'Tipo de documento', 'UUID',
                'Validacion documental', 'SHA-256', 'Tamano (bytes)', 'Nombre original'],
            $indice
        ));

        if (!$zip->close()) {
            @unlink($temporal);
            self::$errores[] = 'No fue posible cerrar el archivo ZIP.';
            return null;
        }

        return [
            'ruta' => $temporal, 'archivos' => $agregados,
            'nombre' => sprintf('%s-%04d-%02d.zip', $tipo === 'ventas' ? 'ventas-documentos' : 'gastos-documentos', $anio, $mes),
        ];
    }
}
