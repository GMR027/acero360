<?php

namespace App;

/**
 * Exportador — CSV y Excel (.xlsx) sin dependencias externas.
 *
 * CSV: UTF-8 con BOM (Excel lo abre bien) y protección contra inyección de
 * fórmulas (celdas que empiezan con = + - @ se prefijan con un apóstrofo).
 * XLSX: libro de una hoja armado con ZipArchive; los textos van como cadenas
 * en línea, nunca como fórmulas.
 *
 * Exportar nunca modifica datos.
 */
class Exportador
{
    /** Neutraliza celdas que Excel interpretaría como fórmula. */
    private static function seguro($valor)
    {
        if (is_string($valor) && $valor !== '' && strpbrk($valor[0], "=+-@\t\r") !== false && !is_numeric($valor)) {
            return "'" . $valor;
        }

        return $valor;
    }

    /**
     * @param string[] $encabezados
     * @param array<int, array<int, scalar|null>> $filas
     */
    public static function csv(array $encabezados, array $filas): string
    {
        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, "\xEF\xBB\xBF");
        fputcsv($flujo, array_map([self::class, 'seguro'], $encabezados), ',', '"', '\\');
        foreach ($filas as $fila) {
            fputcsv($flujo, array_map(static fn ($v) => self::seguro($v ?? ''), $fila), ',', '"', '\\');
        }
        rewind($flujo);
        $contenido = (string) stream_get_contents($flujo);
        fclose($flujo);

        return $contenido;
    }

    private static function columna(int $indice): string
    {
        $letras = '';
        for ($i = $indice + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $letras = chr(65 + (($i - 1) % 26)) . $letras;
        }

        return $letras;
    }

    /**
     * @param string[] $encabezados
     * @param array<int, array<int, scalar|null>> $filas ints/floats se guardan como número
     * @return string|null bytes del .xlsx
     */
    public static function xlsx(string $hoja, array $encabezados, array $filas): ?string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($zip->open($temporal, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $esc = static fn ($t) => htmlspecialchars((string) $t, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Quita caracteres de control no válidos en XML 1.0.
        $limpio = static fn ($t) => preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $t);

        $filaXml = static function (int $n, array $celdas, bool $encabezado) use ($esc, $limpio): string {
            $xml = '<row r="' . $n . '">';
            foreach (array_values($celdas) as $i => $valor) {
                $ref = self::columna($i) . $n;
                if (!$encabezado && (is_int($valor) || is_float($valor))) {
                    $xml .= '<c r="' . $ref . '"><v>' . $valor . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . ($encabezado ? ' s="1"' : '') . '><is><t xml:space="preserve">'
                        . $esc($limpio($valor ?? '')) . '</t></is></c>';
                }
            }

            return $xml . '</row>';
        };

        $datos = $filaXml(1, $encabezados, true);
        foreach (array_values($filas) as $i => $fila) {
            $datos .= $filaXml($i + 2, $fila, false);
        }

        $nombreHoja = $esc(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $hoja), 0, 31) ?: 'Hoja1');

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $nombreHoja . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $datos . '</sheetData></worksheet>');
        $zip->close();

        $bytes = file_get_contents($temporal);
        @unlink($temporal);

        return $bytes === false ? null : $bytes;
    }

    /** Envía bytes como descarga y termina la petición. */
    public static function descargar(string $bytes, string $nombre, string $mime): void
    {
        $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre) ?: 'exportacion';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    }
}
