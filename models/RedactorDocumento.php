<?php

namespace Model;

use Classes\Imagenes;

/**
 * Documento del Redactor: texto con formato (HTML saneado) más nombre, fecha,
 * carpeta (departamento) y ajustes de página.
 *
 * En Acero 360 los documentos son COMPARTIDOS, como las Notas: cualquier
 * administrador ve y edita todo. `creado_por` sólo registra quién lo escribió.
 */
class RedactorDocumento extends ActiveRecord
{
    protected static $tabla = 'redactor_documentos';
    protected static $columnasDB = ['id', 'nombre', 'fecha', 'departamento', 'idioma', 'papel', 'orientacion', 'margen_sup', 'margen_inf', 'margen_izq', 'margen_der', 'numerar', 'num_posicion', 'encabezado', 'rastrear', 'marca_agua', 'encabezado_texto', 'pie_texto', 'membrete', 'imagen_encabezado', 'imagen_encabezado_opacidad', 'imagen_encabezado_x', 'imagen_encabezado_y', 'imagen_encabezado_ancho', 'imagen_pie', 'imagen_pie_opacidad', 'imagen_pie_x', 'imagen_pie_y', 'imagen_pie_ancho', 'contenido', 'comentarios', 'creado_por'];

    public $id, $nombre, $fecha, $departamento, $idioma, $papel, $orientacion;
    public $margen_sup, $margen_inf, $margen_izq, $margen_der, $numerar, $num_posicion, $encabezado, $rastrear, $marca_agua, $encabezado_texto, $pie_texto, $membrete;
    public $imagen_encabezado, $imagen_encabezado_opacidad, $imagen_encabezado_x, $imagen_encabezado_y, $imagen_encabezado_ancho;
    public $imagen_pie, $imagen_pie_opacidad, $imagen_pie_x, $imagen_pie_y, $imagen_pie_ancho;
    public $contenido, $comentarios, $creado_por;
    public $creado, $actualizado;   // los pone la BD

    /** Posiciones válidas para la numeración de páginas. */
    public const POSICIONES_NUM = [
        'pie_izq' => 'Pie · izquierda',
        'pie_centro' => 'Pie · centro',
        'pie_der' => 'Pie · derecha',
        'enc_izq' => 'Encabezado · izquierda',
        'enc_centro' => 'Encabezado · centro',
        'enc_der' => 'Encabezado · derecha',
    ];

    /**
     * Diseño del membrete por bloques. Cada zona (encabezado/pie) lleva su
     * logo, título y subencabezado; la marca de agua es independiente.
     * El color y los tamaños se guardan como datos, nunca como HTML.
     */
    public const MEMBRETE_DEFECTO = [
        'encabezado' => [
            'activo'           => 0,
            'disposicion'      => 'logo_izq',   // logo_izq | logo_arriba | logo_der | solo_texto
            'alineacion'       => 'izq',        // izq | centro | der
            'logo_imagen'      => '',
            'logo_opacidad'    => 100,
            'logo_ancho'       => 3.0,
            'titulo_texto'     => '',
            'titulo_tamano'    => 14.0,
            'titulo_color'     => '#1e293b',
            'titulo_negrita'   => 1,
            'titulo_cursiva'   => 0,
            'subtitulo_texto'  => '',
            'subtitulo_tamano' => 9.5,
            'subtitulo_color'  => '#64748b',
            'subtitulo_negrita'=> 0,
            'subtitulo_cursiva'=> 0,
        ],
        'pie' => [
            'activo'           => 0,
            'disposicion'      => 'logo_izq',
            'alineacion'       => 'izq',
            'logo_imagen'      => '',
            'logo_opacidad'    => 100,
            'logo_ancho'       => 3.0,
            'titulo_texto'     => '',
            'titulo_tamano'    => 9.5,
            'titulo_color'     => '#475569',
            'titulo_negrita'   => 0,
            'titulo_cursiva'   => 0,
            'subtitulo_texto'  => '',
            'subtitulo_tamano' => 8.0,
            'subtitulo_color'  => '#94a3b8',
            'subtitulo_negrita'=> 0,
            'subtitulo_cursiva'=> 0,
        ],
        'marca' => [
            'activo'   => 0,
            'texto'    => '',
            'color'    => '#cbd5e1',
            'opacidad' => 25,
            'tamano'   => 90.0,
            'angulo'   => -45,
        ],
    ];

    public const DISPOSICIONES_MEMBRETE = [
        'logo_izq'    => 'Logo a la izquierda',
        'logo_arriba' => 'Logo arriba',
        'logo_der'    => 'Logo a la derecha',
        'solo_texto'  => 'Solo texto',
    ];

    public const ALINEACIONES_MEMBRETE = [
        'izq'    => 'Izquierda',
        'centro' => 'Centrada',
        'der'    => 'Derecha',
    ];

    /** Papeles [clave => [etiqueta, ancho cm, alto cm]] (vertical) */
    public const PAPELES = [
        'carta' => ['Carta 21.6 × 27.9 cm', 21.59, 27.94],
        'a4' => ['A4 21 × 29.7 cm', 21.0, 29.7],
        'oficio' => ['Oficio 21.6 × 33 cm', 21.59, 33.02],
        'tabloide' => ['Tabloide 27.9 × 43.2 cm', 27.94, 43.18],
    ];
    public const IDIOMAS = ['es' => 'Español', 'en' => 'English', 'es+en' => 'Español + English'];
    public const MARGEN_MIN = 0.0;
    public const MARGEN_MAX = 8.0;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = trim((string)($args['nombre'] ?? ''));
        $this->fecha = (string)($args['fecha'] ?? date('Y-m-d'));
        $this->departamento = trim((string)($args['departamento'] ?? ''));
        $this->idioma = isset(self::IDIOMAS[$args['idioma'] ?? '']) ? $args['idioma'] : 'es';
        $this->papel = isset(self::PAPELES[$args['papel'] ?? '']) ? $args['papel'] : 'carta';
        $this->orientacion = ($args['orientacion'] ?? '') === 'horizontal' ? 'horizontal' : 'vertical';
        foreach (['sup', 'inf', 'izq', 'der'] as $l) $this->{'margen_' . $l} = self::margen($args['margen_' . $l] ?? 2.54);
        $this->numerar = !empty($args['numerar']) || !array_key_exists('numerar', $args) ? 1 : 0;
        $this->num_posicion = isset(self::POSICIONES_NUM[$args['num_posicion'] ?? '']) ? $args['num_posicion'] : 'pie_centro';
        $this->encabezado = !empty($args['encabezado']) ? 1 : 0;
        $this->rastrear = !empty($args['rastrear']) ? 1 : 0;
        $this->marca_agua = mb_substr(trim(preg_replace('/[<>"\x00-\x1f]/u', '', (string)($args['marca_agua'] ?? ''))), 0, 40);
        $this->encabezado_texto = mb_substr(trim(preg_replace('/[<>"\x00-\x1f]/u', '', (string)($args['encabezado_texto'] ?? ''))), 0, 200);
        $this->pie_texto = mb_substr(trim(preg_replace('/[<>"\x00-\x1f]/u', '', (string)($args['pie_texto'] ?? ''))), 0, 200);
        $membrete = $args['membrete'] ?? '';
        if (is_array($membrete)) {
            $this->membrete = json_encode(self::normalizarMembrete($membrete), JSON_UNESCAPED_UNICODE);
        } elseif (trim((string) $membrete) !== '') {
            $datos = json_decode((string) $membrete, true);
            $this->membrete = is_array($datos)
                ? json_encode(self::normalizarMembrete($datos), JSON_UNESCAPED_UNICODE)
                : '';
        } else {
            $this->membrete = '';
        }
        foreach (['encabezado', 'pie'] as $slot) {
            $base = 'imagen_' . $slot;
            $this->{$base} = self::nombreImagen($args[$base] ?? '');
            $this->{$base . '_opacidad'} = (int) max(0, min(100, (int) ($args[$base . '_opacidad'] ?? 100)));
            $this->{$base . '_x'} = self::decimal($args[$base . '_x'] ?? 0, 0, 0, 100);
            $this->{$base . '_y'} = self::decimal($args[$base . '_y'] ?? 0, 0, 0, 100);
            $this->{$base . '_ancho'} = self::decimal($args[$base . '_ancho'] ?? 5, 5, 0.5, 100);
        }
        $this->contenido = $args['contenido'] ?? '';
        $this->comentarios = $args['comentarios'] ?? null;
        $this->creado_por = $args['creado_por'] ?? null;
    }

    /** Margen en cm siempre dentro de rango (acepta coma decimal) */
    public static function margen($v): float
    {
        $n = is_numeric(str_replace(',', '.', (string)$v)) ? (float)str_replace(',', '.', (string)$v) : 2.54;
        return round(max(self::MARGEN_MIN, min(self::MARGEN_MAX, $n)), 2);
    }

    /** Número decimal acotado (acepta coma decimal). */
    private static function decimal($v, float $defecto, float $min, float $max): float
    {
        $n = is_numeric(str_replace(',', '.', (string) $v)) ? (float) str_replace(',', '.', (string) $v) : $defecto;
        return round(max($min, min($max, $n)), 2);
    }

    /** Nombre de una imagen del Redactor (debe ser un archivo generado en vplan). */
    public static function nombreImagen($v): string
    {
        $n = basename(trim((string) $v));
        return preg_match('/^[a-f0-9]{32}\.(?:png|jpg)$/', $n) ? $n : '';
    }

    /** Texto de una línea del membrete, sin etiquetas ni controles. */
    private static function textoMembrete($v, int $max): string
    {
        $t = preg_replace('/[<>"\x00-\x1f]/u', '', strip_tags((string) $v));
        return mb_substr(trim((string) $t), 0, $max);
    }

    /** Color #rrggbb del membrete (si no es válido, el color de respaldo). */
    private static function colorMembrete($v, string $defecto): string
    {
        $c = strtolower(trim((string) $v));
        return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : $defecto;
    }

    /**
     * Convierte cualquier entrada (JSON, array o texto vacío) en el diseño de
     * membrete saneado. Sirve tanto al guardar como al leer de la base.
     */
    public static function normalizarMembrete($valor): array
    {
        $datos = is_array($valor) ? $valor : json_decode((string) $valor, true);
        if (!is_array($datos)) $datos = [];

        $out = self::MEMBRETE_DEFECTO;
        foreach (['encabezado', 'pie'] as $slot) {
            $b = is_array($datos[$slot] ?? null) ? $datos[$slot] : [];
            $def = self::MEMBRETE_DEFECTO[$slot];
            $out[$slot]['activo'] = !empty($b['activo']) ? 1 : 0;
            $out[$slot]['disposicion'] = isset(self::DISPOSICIONES_MEMBRETE[$b['disposicion'] ?? ''])
                ? $b['disposicion'] : $def['disposicion'];
            $out[$slot]['alineacion'] = isset(self::ALINEACIONES_MEMBRETE[$b['alineacion'] ?? ''])
                ? $b['alineacion'] : $def['alineacion'];
            $out[$slot]['logo_imagen'] = self::nombreImagen($b['logo_imagen'] ?? '');
            $out[$slot]['logo_opacidad'] = (int) max(0, min(100, (int) ($b['logo_opacidad'] ?? $def['logo_opacidad'])));
            $out[$slot]['logo_ancho'] = self::decimal($b['logo_ancho'] ?? $def['logo_ancho'], $def['logo_ancho'], 0.5, 24);
            foreach (['titulo', 'subtitulo'] as $linea) {
                $out[$slot][$linea . '_texto'] = self::textoMembrete($b[$linea . '_texto'] ?? '', 200);
                $out[$slot][$linea . '_tamano'] = self::decimal(
                    $b[$linea . '_tamano'] ?? $def[$linea . '_tamano'],
                    $def[$linea . '_tamano'], 5, 72
                );
                $out[$slot][$linea . '_color'] = self::colorMembrete(
                    $b[$linea . '_color'] ?? $def[$linea . '_color'],
                    $def[$linea . '_color']
                );
                $out[$slot][$linea . '_negrita'] = !empty($b[$linea . '_negrita']) ? 1 : 0;
                $out[$slot][$linea . '_cursiva'] = !empty($b[$linea . '_cursiva']) ? 1 : 0;
            }
        }

        $m = is_array($datos['marca'] ?? null) ? $datos['marca'] : [];
        $def = self::MEMBRETE_DEFECTO['marca'];
        $out['marca']['activo'] = !empty($m['activo']) ? 1 : 0;
        $out['marca']['texto'] = self::textoMembrete($m['texto'] ?? '', 40);
        $out['marca']['color'] = self::colorMembrete($m['color'] ?? $def['color'], $def['color']);
        $out['marca']['opacidad'] = (int) max(0, min(100, (int) ($m['opacidad'] ?? $def['opacidad'])));
        $out['marca']['tamano'] = self::decimal($m['tamano'] ?? $def['tamano'], $def['tamano'], 24, 220);
        $out['marca']['angulo'] = (int) max(-90, min(90, (int) ($m['angulo'] ?? $def['angulo'])));

        return $out;
    }

    /**
     * Diseño del membrete listo para pintar. Si el documento es anterior a
     * los bloques, se construye a partir de los campos antiguos para que al
     * abrirlo el usuario vea su encabezado y su pie ya colocados.
     */
    public function membreteDiseno(): array
    {
        $raw = trim((string) ($this->membrete ?? ''));
        if ($raw !== '') {
            $datos = json_decode($raw, true);
            if (is_array($datos)) return self::normalizarMembrete($datos);
        }

        $out = self::MEMBRETE_DEFECTO;
        $fecha = date('d/m/Y', strtotime((string) $this->fecha));
        $automatico = trim($this->nombre . ($this->departamento !== '' ? ' · ' . $this->departamento : '') . ' · ' . $fecha);

        $encTitulo = trim((string) $this->encabezado_texto);
        if ($encTitulo === '' && !empty($this->encabezado)) $encTitulo = $automatico;
        $logoEnc = self::nombreImagen($this->imagen_encabezado);
        $out['encabezado'] = array_merge($out['encabezado'], [
            'activo'        => ($encTitulo !== '' || $logoEnc !== '') ? 1 : 0,
            'logo_imagen'   => $logoEnc,
            'logo_opacidad' => (int) $this->imagen_encabezado_opacidad,
            'logo_ancho'    => (float) $this->imagen_encabezado_ancho,
            'titulo_texto'  => mb_substr($encTitulo, 0, 200),
        ]);

        $logoPie = self::nombreImagen($this->imagen_pie);
        $out['pie'] = array_merge($out['pie'], [
            'activo'        => (trim((string) $this->pie_texto) !== '' || $logoPie !== '') ? 1 : 0,
            'logo_imagen'   => $logoPie,
            'logo_opacidad' => (int) $this->imagen_pie_opacidad,
            'logo_ancho'    => (float) $this->imagen_pie_ancho,
            'titulo_texto'  => mb_substr(trim((string) $this->pie_texto), 0, 200),
        ]);

        if (trim((string) $this->marca_agua) !== '') {
            $out['marca'] = array_merge($out['marca'], [
                'activo' => 1,
                'texto'  => mb_substr(trim((string) $this->marca_agua), 0, 40),
            ]);
        }

        return self::normalizarMembrete($out);
    }

    /** Sustituye {nombre}, {departamento} y {fecha} en un texto del membrete. */
    public function textoMembreteResuelto(string $texto): string
    {
        return strtr($texto, [
            '{nombre}'       => (string) $this->nombre,
            '{departamento}' => (string) $this->departamento,
            '{fecha}'        => date('d/m/Y', strtotime((string) $this->fecha)),
        ]);
    }

    /** Texto que va en la numeración de páginas. */
    public function textoNumeracion(): string
    {
        return 'Página {PAGE} de {NUMPAGES}';
    }

    public function validar(): array
    {
        self::$alertas = [];
        if ($this->nombre === '') self::$alertas['error'][] = 'El nombre es obligatorio';
        if (mb_strlen($this->nombre) > 150) self::$alertas['error'][] = 'El nombre es demasiado largo (máx. 150)';
        if (mb_strlen($this->departamento) > 100) self::$alertas['error'][] = 'La carpeta es demasiado larga (máx. 100)';
        $f = \DateTime::createFromFormat('Y-m-d', $this->fecha);
        if (!$f || $f->format('Y-m-d') !== $this->fecha) self::$alertas['error'][] = 'La fecha no es válida';
        return self::$alertas;
    }

    /** Medidas útiles de la hoja en cm: [ancho, alto] según papel y orientación */
    public function medidas(): array
    {
        [, $w, $h] = self::PAPELES[$this->papel];
        return $this->orientacion === 'horizontal' ? [$h, $w] : [$w, $h];
    }

    /** Todos los documentos (sección compartida) */
    public static function todos(): array
    {
        return self::consultarSQL("SELECT * FROM redactor_documentos ORDER BY departamento ASC, fecha DESC, id DESC");
    }

    /** Carpetas ya usadas por los documentos */
    public static function departamentos(): array
    {
        $out = [];
        try {
            $r = self::$db->query("SELECT DISTINCT departamento FROM redactor_documentos WHERE departamento <> '' ORDER BY departamento");
            while ($r && ($f = $r->fetch_assoc())) $out[] = $f['departamento'];
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** Palabras agregadas al diccionario del corrector */
    public static function palabras(): array
    {
        $out = [];
        try {
            $r = self::$db->query("SELECT palabra FROM redactor_palabras ORDER BY palabra");
            while ($r && ($f = $r->fetch_assoc())) $out[] = $f['palabra'];
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public static function agregarPalabra(string $palabra): bool
    {
        $palabra = trim($palabra);
        if ($palabra === '' || mb_strlen($palabra) > 60 || !preg_match('/^[\p{L}\p{M}][\p{L}\p{M}\'’-]*$/u', $palabra)) return false;
        return self::ejecutar("INSERT IGNORE INTO redactor_palabras (palabra) VALUES ('" . self::escapar($palabra) . "')");
    }

    /** Imágenes subidas que el contenido usa (nombres de archivo en public/uploads/vplan) */
    public static function imagenesDe(?string $html): array
    {
        preg_match_all('#/public/uploads/vplan/([a-f0-9]{32}\.(?:png|jpg))#', (string)$html, $m);
        return array_values(array_unique($m[1]));
    }

    /** Imágenes de encabezado/pie que usa el documento (nombres en vplan). */
    public function imagenesMembrete(): array
    {
        $diseno = $this->membreteDiseno();
        return array_values(array_unique(array_filter([
            self::nombreImagen($this->imagen_encabezado),
            self::nombreImagen($this->imagen_pie),
            $diseno['encabezado']['logo_imagen'] ?? '',
            $diseno['pie']['logo_imagen'] ?? '',
        ])));
    }

    /** Todas las imágenes propias del documento: contenido y membrete. */
    public function imagenesTodas(): array
    {
        return array_values(array_unique(array_merge(self::imagenesDe($this->contenido), $this->imagenesMembrete())));
    }

    /** Borra del disco las imágenes indicadas (o todas las del documento) que ningún otro documento conserva */
    public function eliminarArchivos(?array $imagenes = null): void
    {
        $otros = self::imagenesDeOtros((int)$this->id);
        foreach (array_diff($imagenes ?? $this->imagenesTodas(), $otros) as $n) Imagenes::eliminarVplan($n);
    }

    private static function imagenesDeOtros(int $excluir): array
    {
        $out = [];
        $r = self::$db->query("SELECT contenido, imagen_encabezado, imagen_pie, membrete FROM redactor_documentos WHERE id <> $excluir");
        while ($r && ($f = $r->fetch_assoc())) {
            $out = array_merge($out, self::imagenesDe($f['contenido']));
            foreach (['imagen_encabezado', 'imagen_pie'] as $campo) {
                $n = self::nombreImagen($f[$campo] ?? '');
                if ($n !== '') $out[] = $n;
            }
            $diseno = json_decode((string) ($f['membrete'] ?? ''), true);
            if (is_array($diseno)) {
                foreach (['encabezado', 'pie'] as $slot) {
                    $n = self::nombreImagen($diseno[$slot]['logo_imagen'] ?? '');
                    if ($n !== '') $out[] = $n;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Peso aproximado: el texto más las imágenes que usa */
    public function bytes(): int
    {
        $t = strlen((string)$this->contenido) + strlen((string)$this->comentarios);
        foreach ($this->imagenesTodas() as $n) {
            $ruta = Imagenes::CARPETA_VPLAN . '/' . $n;
            if (is_file($ruta)) $t += (int)@filesize($ruta);
        }
        return $t;
    }

    // ---------- Historial de versiones ----------
    public const MAX_VERSIONES = 40;

    /** Guarda una copia del contenido; conserva solo las últimas MAX_VERSIONES del documento */
    public static function guardarVersion(int $documentoId, string $contenido, string $usuario, string $etiqueta = ''): void
    {
        try {
            self::ejecutar("INSERT INTO redactor_versiones (documento_id, etiqueta, usuario, contenido) VALUES ($documentoId, '" . self::escapar(mb_substr($etiqueta, 0, 80)) . "', '" . self::escapar(mb_substr($usuario, 0, 100)) . "', '" . self::escapar($contenido) . "')");
            self::ejecutar("DELETE FROM redactor_versiones WHERE documento_id = $documentoId AND id NOT IN (SELECT id FROM (SELECT id FROM redactor_versiones WHERE documento_id = $documentoId ORDER BY id DESC LIMIT " . self::MAX_VERSIONES . ") t)");
        } catch (\Throwable $e) {
            error_log('Redactor versiones: ' . $e->getMessage());
        }
    }

    /** Fecha (Y-m-d H:i:s) de la última versión guardada, o null */
    public static function ultimaVersion(int $documentoId): ?string
    {
        try {
            $r = self::$db->query("SELECT creado FROM redactor_versiones WHERE documento_id = $documentoId ORDER BY id DESC LIMIT 1");
            $f = $r ? $r->fetch_assoc() : null;
            return $f['creado'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function versiones(int $documentoId): array
    {
        $out = [];
        try {
            $r = self::$db->query("SELECT id, etiqueta, usuario, creado, CHAR_LENGTH(contenido) AS tam FROM redactor_versiones WHERE documento_id = $documentoId ORDER BY id DESC");
            while ($r && ($f = $r->fetch_assoc())) $out[] = $f;
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public static function version(int $documentoId, int $versionId): ?array
    {
        try {
            $r = self::$db->query("SELECT id, etiqueta, usuario, creado, contenido FROM redactor_versiones WHERE documento_id = $documentoId AND id = $versionId");
            return $r ? ($r->fetch_assoc() ?: null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Tabla de SheetFlow lista para insertar en el documento: columnas, filas y HTML vinculado */
    public static function tablaExtra(string $tipo): ?array
    {
        if (preg_match('/^sf:(\d+)$/', $tipo, $m)) {
            $h = SheetflowHoja::find((int)$m[1]);
            if (!$h) return null;
            $t = \Classes\Sheetflow::matriz($h);
            return [
                'columnas' => $t['columnas'],
                'filas' => $t['totales'] ? array_merge($t['filas'], [$t['totales']]) : $t['filas'],
                'html' => \Classes\Sheetflow::htmlRedactor($h),
            ];
        }
        return null;
    }
}
