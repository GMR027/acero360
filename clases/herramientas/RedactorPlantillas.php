<?php

namespace Classes;

use Model\RedactorDocumento;

/** Plantillas del Redactor: las incluidas con la aplicación y las que se guardan en la aplicación. Marcas: {{proyecto}} {{nombre}} {{fecha}} {{departamento}} {{usuario}} */
class RedactorPlantillas
{
    private const B = 'border:1px solid #888888';

    private static function tabla(array $filas, array $encabezado = [], string $ancho = '100%'): string
    {
        $h = '<table style="width:' . $ancho . ';border-collapse:collapse"><tbody>';
        if ($encabezado) {
            $h .= '<tr>' . implode('', array_map(fn($t) => '<th style="' . self::B . '">' . $t . '</th>', $encabezado)) . '</tr>';
        }
        foreach ($filas as $f) $h .= '<tr>' . implode('', array_map(fn($t) => '<td style="' . self::B . '">' . ($t === '' ? '<br>' : $t) . '</td>', $f)) . '</tr>';
        return $h . '</tbody></table>';
    }

    /** Fila de bloques de firma a mano: una columna por persona, sin bordes */
    private static function firmas(array $personas): string
    {
        $h = '<table style="width:100%;border-collapse:collapse"><tbody><tr>';
        foreach ($personas as [$nombre, $cargo]) {
            $h .= '<td style="border:0;text-align:center"><p><br></p><p><br></p><p style="text-align:center">______________________________</p><p style="text-align:center"><strong>' . $nombre . '</strong><br>' . $cargo . '</p></td>';
        }
        return $h . '</tr></tbody></table>';
    }

    private static function encabezado(string $tipo): string
    {
        return '<h1 style="text-align:center">' . $tipo . '</h1><p style="text-align:center"><strong>{{proyecto}}</strong><br>{{departamento}} · {{fecha}}</p>';
    }

    /** Plantillas incluidas: clave => [nombre, descripción, icono, html] */
    public static function incluidas(): array
    {
        return [
            'oficio' => ['Oficio / carta', 'Carta formal con destinatario, asunto y firma', 'fa-envelope-open-text',
                '<p style="text-align:right">{{fecha}}</p><p><strong>Asunto:</strong> </p><p><br></p><p><strong>Nombre del destinatario</strong><br>Cargo<br>Empresa</p><p><br></p><p>Por medio de la presente me dirijo a usted para </p><p style="text-align:justify">Sin otro particular por el momento, quedo a sus órdenes para cualquier aclaración.</p><p><br></p><p>Atentamente</p>' . self::firmas([['{{usuario}}', '{{departamento}}']])],
            'minuta' => ['Minuta de reunión', 'Asistentes, orden del día, acuerdos y responsables', 'fa-handshake',
                self::encabezado('Minuta de reunión') . '<h2>Datos de la reunión</h2>' . self::tabla([['<strong>Fecha</strong>', '{{fecha}}', '<strong>Hora</strong>', ''], ['<strong>Lugar</strong>', '', '<strong>Convoca</strong>', '{{usuario}}']])
                . '<h2>Asistentes</h2>' . self::tabla([['', '', ''], ['', '', ''], ['', '', '']], ['Nombre', 'Empresa / área', 'Cargo'])
                . '<h2>Orden del día</h2><ol><li></li><li></li></ol><h2>Acuerdos</h2>' . self::tabla([['1', '', '', ''], ['2', '', '', '']], ['#', 'Acuerdo', 'Responsable', 'Fecha compromiso'])
                . '<h2>Próxima reunión</h2><p></p>' . self::firmas([['Elaboró', '{{usuario}}'], ['Revisó', '']])],
            'acta' => ['Acta de entrega-recepción', 'Entrega de obra o equipo con observaciones y firmas', 'fa-clipboard-check',
                self::encabezado('Acta de entrega-recepción') . '<p>En la fecha {{fecha}}, se hace entrega y recepción de los trabajos correspondientes a <strong>{{proyecto}}</strong>, conforme a lo contratado.</p><h2>Alcance entregado</h2><ul><li></li><li></li></ul><h2>Observaciones</h2>'
                . self::tabla([['', '', '']], ['Observación', 'Responsable', 'Fecha de atención']) . '<h2>Documentación entregada</h2><ul><li>Planos as-built</li><li>Manuales y garantías</li></ul>' . self::firmas([['Entrega', ''], ['Recibe', ''], ['Testigo', '']])],
            'checklist' => ['Lista de verificación', 'Puntos a revisar con casillas de cumplimiento', 'fa-list-check',
                self::encabezado('Lista de verificación') . self::tabla([['', '☐ Sí  ☐ No  ☐ N/A', ''], ['', '☐ Sí  ☐ No  ☐ N/A', ''], ['', '☐ Sí  ☐ No  ☐ N/A', ''], ['', '☐ Sí  ☐ No  ☐ N/A', '']], ['Punto a verificar', 'Cumple', 'Observaciones']) . self::firmas([['Revisó', '{{usuario}}']])],
        ];
    }

    /** Plantillas guardadas por el proyecto: [['id', 'nombre', 'descripcion']] */
    public static function todas(): array
    {
        $out = [];
        try {
            $r = \Model\ActiveRecord::conexion()->query("SELECT id, nombre, descripcion FROM redactor_plantillas ORDER BY nombre");
            while ($r && ($f = $r->fetch_assoc())) $out[] = ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'descripcion' => $f['descripcion']];
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** Datos de una plantilla ('inc:clave' o 'pro:id') con las marcas ya sustituidas, o null */
    public static function aplicar(string $clave, string $nombreProyecto, RedactorDocumento $d, string $usuario): ?array
    {
        $html = null;
        $ajustes = [];
        if (str_starts_with($clave, 'inc:')) {
            $p = self::incluidas()[substr($clave, 4)] ?? null;
            if ($p) $html = $p[3];
        } elseif (str_starts_with($clave, 'pro:')) {
            $id = (int)substr($clave, 4);
            $r = \Model\ActiveRecord::conexion()->query("SELECT * FROM redactor_plantillas WHERE id = $id");
            if ($r && ($f = $r->fetch_assoc())) {
                $html = (string)$f['contenido'];
                $ajustes = ['papel' => $f['papel'], 'orientacion' => $f['orientacion'], 'margen_sup' => $f['margen_sup'], 'margen_inf' => $f['margen_inf'], 'margen_izq' => $f['margen_izq'], 'margen_der' => $f['margen_der']];
            }
        }
        if ($html === null) return null;
        $e = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $html = strtr($html, ['{{proyecto}}' => $e($nombreProyecto), '{{nombre}}' => $e($d->nombre), '{{fecha}}' => date('d/m/Y', strtotime($d->fecha)), '{{departamento}}' => $e($d->departamento !== '' ? $d->departamento : 'Departamento'), '{{usuario}}' => $e($usuario)]);
        return ['contenido' => Redactor::limpiar($html)] + $ajustes;
    }

    public static function guardar(string $nombre, string $descripcion, RedactorDocumento $d): bool
    {
        $db = \Model\ActiveRecord::conexion();
        $html = Redactor::limpiar(Redactor::resolverCambios((string)$d->contenido, true));
        $q = fn($t) => $db->real_escape_string($t);
        return (bool)$db->query('INSERT INTO redactor_plantillas (nombre, descripcion, papel, orientacion, margen_sup, margen_inf, margen_izq, margen_der, contenido) VALUES ("' . $q(mb_substr($nombre, 0, 100)) . '", "' . $q(mb_substr($descripcion, 0, 200)) . '", "' . $q($d->papel) . '", "' . $q($d->orientacion) . '", ' . (float)$d->margen_sup . ', ' . (float)$d->margen_inf . ', ' . (float)$d->margen_izq . ', ' . (float)$d->margen_der . ', "' . $q($html) . '")');
    }

    public static function eliminar(int $id): void
    {
        \Model\ActiveRecord::conexion()->query("DELETE FROM redactor_plantillas WHERE id = " . (int)$id);
    }
}
