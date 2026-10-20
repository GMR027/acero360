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
        $titulos = array_map(static fn ($t) => ['v' => $t, 'f' => 'neg'], $encabezados);

        return self::libro([['nombre' => $hoja, 'filas' => array_merge([$titulos], $filas)]]);
    }

    /** Estilos de celda (índice en cellXfs de styles.xml). */
    private const ESTILOS = ['neg' => 1, 'moneda' => 2, 'entero' => 3, 'pct' => 4, 'titulo' => 5, 'nota' => 6, 'monedaNeg' => 7];

    /** Colores sin «#» para DrawingML. */
    private static function rgb(string $c): string
    {
        return strtoupper(ltrim($c, '#'));
    }

    /**
     * Libro con varias hojas. Una celda es un escalar o ['v' => valor, 'f' => formato].
     * Formatos: neg, moneda, entero, pct, titulo, nota. Los textos NUNCA son fórmulas.
     *
     * Cada hoja: ['nombre', 'filas', 'anchos' => [col => ancho], 'grafica' => [
     *   'tipo' => barras|apilado|linea|barras-h, 'titulo', 'formato', 'fila_enc' (1-based), 'fila_ini', 'fila_fin',
     *   'series' => [['col' => 1, 'color' => '#2f6f9f'], …]]]
     * La gráfica es nativa de Excel y apunta a las celdas de la propia hoja.
     *
     * @param array<int, array<string, mixed>> $hojas
     */
    public static function libro(array $hojas): ?string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($zip->open($temporal, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $esc = static fn ($t) => htmlspecialchars((string) $t, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $limpio = static fn ($t) => preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $t);
        $cabecera = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

        $tipos = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $hojasXml = '';
        $relLibro = '';
        $usados = [];
        $nGraficas = 0;

        foreach (array_values($hojas) as $h => $hoja) {
            $n = $h + 1;
            $nombre = mb_substr(trim((string) preg_replace('/[\\\\\/?*\[\]:]/u', ' ', (string) $hoja['nombre'])), 0, 31) ?: 'Hoja' . $n;
            $base = $nombre;
            for ($k = 2; in_array(mb_strtolower($nombre), $usados, true); $k++) {
                $nombre = mb_substr($base, 0, 28) . ' ' . $k;
            }
            $usados[] = mb_strtolower($nombre);

            $datos = '';
            foreach (array_values($hoja['filas']) as $i => $fila) {
                $datos .= '<row r="' . ($i + 1) . '">';
                foreach (array_values($fila) as $c => $valor) {
                    $formato = null;
                    if (is_array($valor)) {
                        $formato = $valor['f'] ?? null;
                        $valor = $valor['v'] ?? null;
                    }
                    $ref = self::columna($c) . ($i + 1);
                    $estilo = isset(self::ESTILOS[$formato]) ? ' s="' . self::ESTILOS[$formato] . '"' : '';
                    if ($valor === null || $valor === '') {
                        if ($estilo !== '') { $datos .= '<c r="' . $ref . '"' . $estilo . '/>'; }
                    } elseif (is_int($valor) || is_float($valor)) {
                        $datos .= '<c r="' . $ref . '"' . $estilo . '><v>' . $valor . '</v></c>';
                    } else {
                        $datos .= '<c r="' . $ref . '" t="inlineStr"' . $estilo . '><is><t xml:space="preserve">' . $esc($limpio($valor)) . '</t></is></c>';
                    }
                }
                $datos .= '</row>';
            }

            $cols = '';
            foreach (($hoja['anchos'] ?? []) as $c => $ancho) {
                $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float) $ancho . '" customWidth="1"/>';
            }
            $cols = $cols !== '' ? '<cols>' . $cols . '</cols>' : '';

            $dibujo = '';
            if (!empty($hoja['grafica'])) {
                $nGraficas++;
                $g = $hoja['grafica'];
                $zip->addFromString("xl/charts/chart$nGraficas.xml", self::graficaXml($g, $nombre, $esc, $limpio));
                $col0 = (int) ($g['col_ancla'] ?? 0);
                $fila0 = (int) ($g['fila_ancla'] ?? 0);
                $zip->addFromString("xl/drawings/drawing$nGraficas.xml", $cabecera
                    . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                    . '<xdr:twoCellAnchor><xdr:from><xdr:col>' . $col0 . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $fila0 . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                    . '<xdr:to><xdr:col>' . ($col0 + 9) . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . ($fila0 + 20) . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
                    . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="2" name="Gráfica ' . $nGraficas . '"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
                    . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart r:id="rId1"/></a:graphicData></a:graphic></xdr:graphicFrame>'
                    . '<xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>');
                $zip->addFromString("xl/drawings/_rels/drawing$nGraficas.xml.rels", $cabecera . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart' . $nGraficas . '.xml"/></Relationships>');
                $zip->addFromString("xl/worksheets/_rels/sheet$n.xml.rels", $cabecera . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $nGraficas . '.xml"/></Relationships>');
                $tipos .= '<Override PartName="/xl/charts/chart' . $nGraficas . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>'
                    . '<Override PartName="/xl/drawings/drawing' . $nGraficas . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
                $dibujo = '<drawing r:id="rId1"/>';
            }

            $zip->addFromString("xl/worksheets/sheet$n.xml", $cabecera
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . $cols . '<sheetData>' . $datos . '</sheetData>' . $dibujo . '</worksheet>');
            $tipos .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $hojasXml .= '<sheet name="' . $esc($nombre) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $relLibro .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }

        $total = count($hojas);
        $zip->addFromString('[Content_Types].xml', $cabecera . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . $tipos . '</Types>');
        $zip->addFromString('_rels/.rels', $cabecera . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $cabecera . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $hojasXml . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $cabecera . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relLibro
            . '<Relationship Id="rId' . ($total + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $cabecera . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="0.00&quot; %&quot;"/><numFmt numFmtId="166" formatCode="&quot;$&quot;#,##0.00;[Red]\-&quot;$&quot;#,##0.00"/></numFmts>'
            . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF38648C"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF64646A"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->close();

        $bytes = file_get_contents($temporal);
        @unlink($temporal);

        return $bytes === false ? null : $bytes;
    }

    /** XML de una gráfica nativa de Excel que apunta a las celdas de su hoja. */
    private static function graficaXml(array $g, string $hoja, callable $esc, callable $limpio): string
    {
        $ref = static fn (int $col, int $f1, int $f2): string => "'" . str_replace("'", "''", $hoja) . "'!$" . self::columna($col) . '$' . $f1 . ($f2 !== $f1 ? ':$' . self::columna($col) . '$' . $f2 : '');
        $horizontal = $g['tipo'] === 'barras-h';
        $linea = $g['tipo'] === 'linea';
        $apilado = $g['tipo'] === 'apilado';
        $codigo = ($g['formato'] ?? 'moneda') === 'moneda' ? '&quot;$&quot;#,##0' : '#,##0';

        $series = '';
        foreach ($g['series'] as $i => $s) {
            $color = self::rgb($s['color']);
            $forma = $linea
                ? '<c:spPr><a:ln w="28575" cap="rnd"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:round/></a:ln></c:spPr><c:marker><c:symbol val="circle"/><c:size val="5"/><c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:ln w="19050"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:ln></c:spPr></c:marker>'
                : '<c:spPr><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></c:spPr><c:invertIfNegative val="0"/>';
            $series .= '<c:ser><c:idx val="' . $i . '"/><c:order val="' . $i . '"/><c:tx><c:strRef><c:f>' . $esc($ref($s['col'], $g['fila_enc'], $g['fila_enc'])) . '</c:f></c:strRef></c:tx>' . $forma
                . '<c:cat><c:strRef><c:f>' . $esc($ref(0, $g['fila_ini'], $g['fila_fin'])) . '</c:f></c:strRef></c:cat>'
                . '<c:val><c:numRef><c:f>' . $esc($ref($s['col'], $g['fila_ini'], $g['fila_fin'])) . '</c:f></c:numRef></c:val>' . ($linea ? '<c:smooth val="0"/>' : '') . '</c:ser>';
        }

        if ($linea) {
            $grafica = '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>' . $series . '<c:marker val="1"/><c:axId val="111"/><c:axId val="222"/></c:lineChart>';
        } else {
            $grafica = '<c:barChart><c:barDir val="' . ($horizontal ? 'bar' : 'col') . '"/><c:grouping val="' . ($apilado ? 'stacked' : 'clustered') . '"/><c:varyColors val="0"/>' . $series
                . '<c:gapWidth val="70"/>' . ($apilado ? '<c:overlap val="100"/>' : '') . '<c:axId val="111"/><c:axId val="222"/></c:barChart>';
        }
        $texto = '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="900"><a:solidFill><a:srgbClr val="64646A"/></a:solidFill></a:defRPr></a:pPr><a:endParaRPr lang="es-MX"/></a:p></c:txPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<c:roundedCorners val="0"/><c:chart><c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1200" b="1"/></a:pPr><a:r><a:rPr lang="es-MX" sz="1200" b="1"/><a:t>' . $esc($limpio($g['titulo'])) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>'
            . '<c:autoTitleDeleted val="0"/><c:plotArea><c:layout/>' . $grafica
            . '<c:catAx><c:axId val="111"/><c:scaling><c:orientation val="' . ($horizontal ? 'maxMin' : 'minMax') . '"/></c:scaling><c:delete val="0"/><c:axPos val="' . ($horizontal ? 'l' : 'b') . '"/><c:numFmt formatCode="General" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>' . $texto . '<c:crossAx val="222"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
            . '<c:valAx><c:axId val="222"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="' . ($horizontal ? 'b' : 'l') . '"/><c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="E4E6EA"/></a:solidFill></a:ln></c:spPr></c:majorGridlines><c:numFmt formatCode="' . $codigo . '" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>' . $texto . '<c:crossAx val="111"/><c:crosses val="' . ($horizontal ? 'max' : 'autoZero') . '"/><c:crossBetween val="between"/></c:valAx>'
            . '</c:plotArea>' . (count($g['series']) > 1 ? '<c:legend><c:legendPos val="b"/><c:overlay val="0"/>' . $texto . '</c:legend>' : '') . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart></c:chartSpace>';
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
