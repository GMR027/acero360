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
    protected static $columnasDB = ['id', 'nombre', 'fecha', 'departamento', 'idioma', 'papel', 'orientacion', 'margen_sup', 'margen_inf', 'margen_izq', 'margen_der', 'numerar', 'encabezado', 'rastrear', 'marca_agua', 'encabezado_texto', 'pie_texto', 'contenido', 'comentarios', 'creado_por'];

    public $id, $nombre, $fecha, $departamento, $idioma, $papel, $orientacion;
    public $margen_sup, $margen_inf, $margen_izq, $margen_der, $numerar, $encabezado, $rastrear, $marca_agua, $encabezado_texto, $pie_texto, $contenido, $comentarios, $creado_por;
    public $creado, $actualizado;   // los pone la BD

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
        $this->encabezado = !empty($args['encabezado']) ? 1 : 0;
        $this->rastrear = !empty($args['rastrear']) ? 1 : 0;
        $this->marca_agua = mb_substr(trim(preg_replace('/[<>"\x00-\x1f]/u', '', (string)($args['marca_agua'] ?? ''))), 0, 40);
        $this->encabezado_texto = mb_substr(trim(preg_replace('/[<>"\x00-\x1f]/u', '', (string)($args['encabezado_texto'] ?? ''))), 0, 200);
        $this->pie_texto = mb_substr(trim(preg_replace('/[<>"\x00-\x1f]/u', '', (string)($args['pie_texto'] ?? ''))), 0, 200);
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

    /** Borra del disco las imágenes que el documento usa y ningún otro documento conserva */
    public function eliminarArchivos(): void
    {
        $otros = self::imagenesDeOtros((int)$this->id);
        foreach (array_diff(self::imagenesDe($this->contenido), $otros) as $n) Imagenes::eliminarVplan($n);
    }

    private static function imagenesDeOtros(int $excluir): array
    {
        $out = [];
        $r = self::$db->query("SELECT contenido FROM redactor_documentos WHERE id <> $excluir");
        while ($r && ($f = $r->fetch_assoc())) $out = array_merge($out, self::imagenesDe($f['contenido']));
        return $out;
    }

    /** Peso aproximado: el texto más las imágenes que usa */
    public function bytes(): int
    {
        $t = strlen((string)$this->contenido) + strlen((string)$this->comentarios);
        foreach (self::imagenesDe($this->contenido) as $n) {
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
