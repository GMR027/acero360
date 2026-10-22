<?php

namespace Classes;

/** Hipervínculos de las láminas (VPlan y Gráficos): validación, destinos internos del proyecto y extracción desde marcas_json */
class EnlacesLamina
{
    private const GLIFOS = ['link' => '🔗', 'globe' => '🌐', 'map' => '📍', 'doc' => '📄', 'photo' => '📷', 'info' => 'i', 'star' => '★', 'flag' => '🚩', 'video' => '▶'];
    /** Módulos del sistema a los que puede apuntar un enlace interno (el resto de rutas se rechaza) */
    private const PREFIJOS = ['/admin', '/admin/redactor', '/admin/sheetflow', '/admin/editor', '/admin/notas', '/admin/clientes', '/admin/cotizaciones', '/admin/productos', '/admin/estadisticas'];

    /** Devuelve la URL ya saneada o null si no es válida. $tipo: 'ext' (enlace externo) o 'int' (ruta del sistema) */
    public static function limpiar(string $tipo, string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $url)) return null;
        if ($tipo === 'int') {
            if ($url[0] !== '/' || str_starts_with($url, '//')) return null;
            $ruta = parse_url($url, PHP_URL_PATH) ?: '';
            foreach (self::PREFIJOS as $p) if ($ruta === $p || str_starts_with($ruta, $p . '/')) return $url;
            return null;
        }
        if ($tipo === 'v360') return preg_match('#^/public/public/uploads/vplan/[a-f0-9]{32}\.(png|jpg)$#', $url) ? $url : null;   // foto 360 subida por el editor
        $esq = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (in_array($esq, ['http', 'https'], true)) return parse_url($url, PHP_URL_HOST) ? $url : null;
        return in_array($esq, ['mailto', 'tel'], true) && strlen($url) > strlen($esq) + 1 ? $url : null;
    }

    /** Enlaces de un marcas_json: [{x,y,w,h,tipo,url,icono,color,n,isize,etiqueta}] en píxeles del lienzo, solo los válidos. $w/$h devuelven el tamaño del lienzo */
    public static function de(?string $json, ?float &$w = null, ?float &$h = null): array
    {
        $d = $json ? json_decode($json, true) : null;
        if (!is_array($d)) return [];
        $w = (float)($d['w'] ?? 0);
        $h = (float)($d['h'] ?? 0);
        $out = [];
        $ocultas = array_column(array_filter($d['layers'] ?? [], fn($l) => ($l['visible'] ?? true) === false), 'id');
        foreach ($d['shapes'] ?? [] as $s) {
            if (($s['type'] ?? '') !== 'link' || in_array($s['layer'] ?? null, $ocultas, true)) continue;
            $k = $s['kind'] ?? 'ext';
            if ($k === '360' && !str_starts_with((string)($s['url'] ?? ''), '/uploads/')) $k = 'ext';   // versión anterior: URL de un visor externo
            $tipo = match ($k) { 'int' => 'int', '360' => 'v360', default => 'ext' };
            $url = self::limpiar($tipo, (string)($s['url'] ?? ''));
            if ($url === null || ($s['w'] ?? 0) <= 0 || ($s['h'] ?? 0) <= 0) continue;
            $out[] = [
                'x' => (float)$s['x'], 'y' => (float)$s['y'], 'w' => (float)$s['w'], 'h' => (float)$s['h'], 'tipo' => $tipo, 'url' => $url,
                'icono' => (string)($s['icon'] ?? 'link'), 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($s['iconColor'] ?? '')) ? $s['iconColor'] : '#0070c0',
                'n' => (int)($s['n'] ?? 1), 'isize' => (float)($s['isize'] ?? 0), 'etiqueta' => mb_substr((string)($s['label'] ?? ''), 0, 120),
            ];
        }
        return $out;
    }

    /** Solo los enlaces externos, los únicos que se incluyen en un PDF */
    public static function externos(array $enlaces): array
    {
        return array_values(array_filter($enlaces, fn($e) => $e['tipo'] === 'ext'));
    }

    /** Destinos internos que ofrece el editor: [{grupo, etiqueta, url}] */
    public static function destinos(int $proyectoId): array
    {
        $q = '?proyecto=' . $proyectoId;
        $d = [];
        foreach ([['Programa', '/programa'], ['Dependencias de obra', '/dependencias'], ['Lookahead', '/lookahead'], ['Calendario', '/calendario'], ['Curvas', '/curvas'], ['Curva S', '/curvas/s'], ['Materiales', '/materiales'],
            ['Submittals', '/submittals'], ['MSR', '/msr'], ['Láminas VPlan', '/vplan'], ['Gráficos', '/graficos'], ['Redactor', '/redactor'], ['SheetFlow', '/sheetflow'], ['View360', '/view360'], ['Reportes', '/reportes'], ['Bitácora', '/bitacora'], ['Minutas', '/minutas'], ['Archivos', '/archivos'], ['Evidencias', '/evidencias']] as [$n, $r]) {
            $d[] = ['grupo' => 'Secciones', 'etiqueta' => $n, 'url' => $r . $q];
        }
        $db = $GLOBALS['db'];
        try {
            $r = $db->query("SELECT id, titulo FROM vplan_laminas WHERE proyecto_id = $proyectoId ORDER BY orden, id LIMIT 200");
            while ($f = $r->fetch_assoc()) $d[] = ['grupo' => 'Láminas VPlan', 'etiqueta' => $f['titulo'], 'url' => '/vplan/ver' . $q . '&id=' . (int)$f['id']];
            $r = $db->query("SELECT id, nombre FROM editor_graficos WHERE proyecto_id = $proyectoId ORDER BY id DESC LIMIT 200");
            while ($f = $r->fetch_assoc()) $d[] = ['grupo' => 'Gráficos', 'etiqueta' => $f['nombre'], 'url' => '/graficos/ver' . $q . '&id=' . (int)$f['id']];
            $r = $db->query("SELECT id, numero_submittal FROM submittals WHERE proyecto_id = $proyectoId ORDER BY id DESC LIMIT 200");
            while ($f = $r->fetch_assoc()) $d[] = ['grupo' => 'Submittals', 'etiqueta' => $f['numero_submittal'], 'url' => '/submittals/ver' . $q . '&id=' . (int)$f['id']];
            $r = $db->query("SELECT id, nombre FROM redactor_documentos WHERE proyecto_id = $proyectoId ORDER BY id DESC LIMIT 200");
            while ($f = $r->fetch_assoc()) $d[] = ['grupo' => 'Documentos del Redactor', 'etiqueta' => $f['nombre'], 'url' => '/redactor/ver' . $q . '&id=' . (int)$f['id']];
            $r = $db->query("SELECT id, nombre FROM sheetflow_hojas WHERE proyecto_id = $proyectoId ORDER BY id DESC LIMIT 200");
            while ($f = $r->fetch_assoc()) $d[] = ['grupo' => 'Tablas de SheetFlow', 'etiqueta' => $f['nombre'], 'url' => '/sheetflow/ver' . $q . '&id=' . (int)$f['id']];
        } catch (\Throwable $e) {   // tablas aún sin migrar
        }
        return $d;
    }

    /** Áreas clicables (en %) para superponer a la imagen de la lámina; el icono se dibuja encima. Los enlaces internos llevan la clase vp-lk-int (no salen al imprimir) */
    public static function html(array $enlaces, float $w, float $h): string
    {
        if ($w <= 0 || $h <= 0) return '';
        $o = '';
        foreach ($enlaces as $e) {
            $ext = $e['tipo'] === 'ext';
            $v = $e['tipo'] === 'v360';
            $et = htmlspecialchars($e['etiqueta'] !== '' ? $e['etiqueta'] : ($v ? 'Vista 360' : ($ext ? $e['url'] : 'Abrir en el proyecto')), ENT_QUOTES);
            $aw = max(1.0, $e['w']);
            $ah = max(1.0, $e['h']);
            $r = $e['isize'] > 0 ? $e['isize'] : max(10, min(22, min($aw, $ah) * 0.35));
            $glifo = $e['icono'] === 'num' ? (string)$e['n'] : (self::GLIFOS[$e['icono']] ?? '');
            $ico = $e['icono'] === 'none' || $glifo === '' ? '' : '<span class="vp-lk-ico" style="left:' . round(3 / $aw * 100, 3) . '%;top:' . round(3 / $ah * 100, 3) . '%;width:' . round(2 * $r / $aw * 100, 3) . '%;background:' . $e['color'] . ';--k:' . round(($e['icono'] === 'num' ? 1.1 : 1.15) * $r / $aw * 100, 3) . '">' . htmlspecialchars($glifo) . '</span>';
            $attr = $v ? ' data-v360="' . htmlspecialchars($e['url'], ENT_QUOTES) . '" data-nombre="' . htmlspecialchars($e['etiqueta'], ENT_QUOTES) . '"' : '';
            $o .= '<a class="vp-lk ' . ($v ? 'vp-lk-360' : ($ext ? 'vp-lk-ext' : 'vp-lk-int')) . '"' . $attr . ' href="' . htmlspecialchars($e['url'], ENT_QUOTES) . '"' . ($ext || $v ? ' target="_blank" rel="noopener noreferrer"' : '') . ' title="' . $et . '" aria-label="' . $et . '" style="left:' . round($e['x'] / $w * 100, 3) . '%;top:' . round($e['y'] / $h * 100, 3) . '%;width:' . round($e['w'] / $w * 100, 3) . '%;height:' . round($e['h'] / $h * 100, 3) . '%">' . $ico . ($v && $e['etiqueta'] !== '' ? '<span class="vp-lk-et">' . htmlspecialchars($e['etiqueta']) . '</span>' : '') . '</a>';
        }
        return $o;
    }
}
