<?php

namespace Classes;

use Model\RedactorDocumento;

/** Redactor: saneado del HTML del documento y exportación a DOCX, ODT y PDF */
class Redactor
{
    private const ETIQUETAS = ['p', 'br', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'td', 'th', 'colgroup', 'col', 'a', 'img', 'blockquote', 'hr', 'pre', 'code', 'ins', 'del'];
    private const ATRIBUTOS = ['colspan', 'rowspan', 'width', 'height', 'alt', 'class', 'start'];
    /** Propiedades CSS permitidas y la forma que debe tener su valor */
    private const ESTILOS = [
        'text-align' => '/^(left|right|center|justify)$/',
        'color' => '/^(#[0-9a-f]{3,6}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)|[a-z]{3,20})$/i',
        'background-color' => '/^(#[0-9a-f]{3,6}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)|[a-z]{3,20}|transparent)$/i',
        'font-size' => '/^\d{1,3}(\.\d+)?(pt|px)$/',
        'font-family' => '/^[\p{L}0-9 ,\'"-]{1,80}$/u',
        'font-weight' => '/^(normal|bold|[1-9]00)$/',
        'font-style' => '/^(normal|italic)$/',
        'text-decoration' => '/^(none|underline|line-through|underline line-through|line-through underline)$/',
        'line-height' => '/^\d(\.\d+)?$/',
        'margin-left' => '/^\d{1,3}(\.\d+)?(pt|px|cm)$/',
        'margin-top' => '/^\d{1,3}(\.\d+)?(pt|px)$/',
        'margin-bottom' => '/^\d{1,3}(\.\d+)?(pt|px)$/',
        'text-indent' => '/^-?\d{1,3}(\.\d+)?(pt|px|cm)$/',
        'width' => '/^\d{1,4}(\.\d+)?(px|%|pt|cm)$/',
        'height' => '/^\d{1,4}(\.\d+)?(px|%|pt|cm)$/',
        'border' => '/^(0|none|\d(\.\d+)?(px|pt) (solid|dashed|dotted|double) (#[0-9a-f]{3,6}|[a-z]{3,20}))$/i',
        'vertical-align' => '/^(top|middle|bottom|baseline|sub|super)$/',
        'page-break-after' => '/^always$/',
        'border-collapse' => '/^(collapse|separate)$/',
        'float' => '/^(left|right|none)$/',
        'margin' => '/^(-?\d{1,3}(\.\d+)?(px|pt) ?){1,4}$/',
        'padding' => '/^(\d{1,3}(\.\d+)?(px|pt) ?){1,4}$/',
    ];
    /** Solo para imágenes colocadas delante o detrás del texto */
    private const ESTILOS_IMG = [
        'position' => '/^absolute$/',
        'left' => '/^-?\d{1,5}(\.\d+)?px$/',
        'top' => '/^-?\d{1,6}(\.\d+)?px$/',
        'z-index' => '/^(-1|2)$/',
    ];
    public const FUENTES = ['Arial', 'Calibri', 'Cambria', 'Courier New', 'Georgia', 'Tahoma', 'Times New Roman', 'Verdana'];
    public const CLASES = ['salto-pagina', 'rd-indice', 'rd-com', 'rd-com-el', 'rd-ins', 'rd-del', 'rd-img-bloque', 'rd-img-izq', 'rd-img-der', 'rd-img-frente', 'rd-img-detras',
        'rd-nota-ref', 'rd-notas', 'rd-leyenda', 'rd-tnum', 'rd-multi', 'rd-ecuacion', 'rd-portada', 'rd-caja', 'rd-capital'];

    /** HTML permitido. Todo lo demás (scripts, eventos, estilos raros, imágenes ajenas) se elimina */
    public static function limpiar(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) return '';
        self::limpiarNodo($body);
        $o = '';
        foreach ($body->childNodes as $h) $o .= $dom->saveHTML($h);
        return $o;
    }

    private static function limpiarNodo(\DOMNode $nodo): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $h) {
            if ($h instanceof \DOMText) continue;
            if (!$h instanceof \DOMElement) { $nodo->removeChild($h); continue; }
            $t = strtolower($h->tagName);
            if (!in_array($t, self::ETIQUETAS, true)) {
                if (in_array($t, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'form', 'textarea', 'select', 'button', 'head', 'title', 'meta', 'link', 'base', 'noscript'], true)) { $nodo->removeChild($h); continue; }
                // Etiqueta desconocida pero inocua (font, section...): se conserva su contenido
                self::limpiarNodo($h);
                while ($h->firstChild) $nodo->insertBefore($h->firstChild, $h);
                $nodo->removeChild($h);
                continue;
            }
            self::limpiarAtributos($h, $t);
            if ($t === 'img' && !$h->hasAttribute('src')) { $nodo->removeChild($h); continue; }
            if ($t === 'a' && !$h->hasAttribute('href')) {   // enlace sin destino válido: solo el texto
                self::limpiarNodo($h);
                while ($h->firstChild) $nodo->insertBefore($h->firstChild, $h);
                $nodo->removeChild($h);
                continue;
            }
            self::limpiarNodo($h);
        }
    }

    private static function limpiarAtributos(\DOMElement $e, string $t): void
    {
        foreach (iterator_to_array($e->attributes) as $a) {
            $n = strtolower($a->name);
            $v = $a->value;
            $e->removeAttribute($a->name);
            if ($n === 'style') {
                $s = self::limpiarEstilo($v, $t === 'img');
                if ($s !== '') $e->setAttribute('style', $s);
            } elseif ($n === 'href' && $t === 'a') {
                $url = self::urlEnlace($v);
                if ($url !== null) {
                    $e->setAttribute('href', $url);
                    if (!str_starts_with($url, '/')) { $e->setAttribute('target', '_blank'); $e->setAttribute('rel', 'noopener noreferrer'); }
                }
            } elseif ($n === 'src' && $t === 'img') {
                if (preg_match('#^/public/uploads/vplan/[a-f0-9]{32}\.(png|jpg)$#', $v) || preg_match(RedactorFirmas::SRC, $v)) $e->setAttribute('src', $v);
            } elseif ($n === 'id' && preg_match('/^h[1-6]$/', $t)) {
                if (preg_match('/^rd-h\d{1,4}$/', $v)) $e->setAttribute('id', $v);
            } elseif ($n === 'data-sf' && $t === 'table') {
                if (preg_match('/^\d{1,9}$/', $v)) $e->setAttribute($n, $v);
            } elseif ($n === 'data-punch' && $t === 'table') {
                if (preg_match('/^[A-Za-z0-9_-]{1,6000}$/', $v)) $e->setAttribute($n, $v);
            } elseif ($n === 'data-cid') {
                if (preg_match('/^c\d{1,6}$/', $v)) $e->setAttribute($n, $v);
            } elseif (in_array($n, ['data-tid', 'data-autor', 'data-fecha'], true) && ($t === 'ins' || $t === 'del')) {
                if ($n === 'data-tid' && preg_match('/^t\d{1,6}$/', $v)) $e->setAttribute($n, $v);
                elseif ($n === 'data-autor') $e->setAttribute($n, mb_substr(preg_replace('/[<>"\x00-\x1f]/u', '', $v), 0, 60));
                elseif ($n === 'data-fecha' && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d/', $v)) $e->setAttribute($n, mb_substr($v, 0, 25));
            } elseif ($n === 'class') {
                $c = array_values(array_intersect(preg_split('/\s+/', trim($v)), self::CLASES));
                if ($c) $e->setAttribute('class', implode(' ', $c));
            } elseif (in_array($n, self::ATRIBUTOS, true)) {
                if (in_array($n, ['colspan', 'rowspan', 'width', 'height', 'start'], true)) {
                    if (preg_match('/^\d{1,4}$/', $v)) $e->setAttribute($n, $v);
                } elseif ($n === 'alt') {
                    $e->setAttribute('alt', mb_substr($v, 0, 200));
                }
            }
        }
    }

    private static function limpiarEstilo(string $css, bool $img = false): string
    {
        $o = [];
        foreach (explode(';', $css) as $d) {
            if (!str_contains($d, ':')) continue;
            [$p, $v] = array_map('trim', explode(':', $d, 2));
            $p = strtolower($p);
            $regla = self::ESTILOS[$p] ?? ($img ? (self::ESTILOS_IMG[$p] ?? null) : null);
            if ($regla && preg_match($regla, $v) && !preg_match('/url\(|expression|javascript/i', $v)) $o[$p] = $v;
        }
        return implode(';', array_map(fn($k, $v) => "$k:$v", array_keys($o), $o));
    }

    /** Enlace permitido: externo (http/https/mailto/tel), ruta del sistema o ancla; null si no es válido */
    public static function urlEnlace(string $url): ?string
    {
        $url = trim(html_entity_decode($url));
        if ($url === '') return null;
        if (preg_match('/^#[A-Za-z0-9_-]{1,60}$/', $url)) return $url;
        return EnlacesLamina::limpiar($url[0] === '/' ? 'int' : 'ext', $url);
    }

    public static function textoPlano(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '</div>', '</li>', '<br>', '<br/>', '</tr>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    // ---------- Comentarios y control de cambios ----------

    /** Comentarios normalizados (JSON): [{id, autor, fecha, texto, resuelto, respuestas:[{autor, fecha, texto}]}] */
    public static function limpiarComentarios(string $json): string
    {
        $d = json_decode($json, true);
        $out = [];
        $cadena = fn($v, $n) => mb_substr(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', '', trim((string)$v)), 0, $n);
        foreach (is_array($d) ? array_slice($d, 0, 300) : [] as $c) {
            if (!is_array($c) || !preg_match('/^c\d{1,6}$/', (string)($c['id'] ?? ''))) continue;
            $resp = [];
            foreach (array_slice(is_array($c['respuestas'] ?? null) ? $c['respuestas'] : [], 0, 50) as $r) {
                if (is_array($r) && trim((string)($r['texto'] ?? '')) !== '') $resp[] = ['autor' => $cadena($r['autor'] ?? '', 60), 'fecha' => $cadena($r['fecha'] ?? '', 25), 'texto' => $cadena($r['texto'], 2000)];
            }
            $out[] = ['id' => $c['id'], 'autor' => $cadena($c['autor'] ?? '', 60), 'fecha' => $cadena($c['fecha'] ?? '', 25), 'texto' => $cadena($c['texto'] ?? '', 2000), 'resuelto' => !empty($c['resuelto']), 'cita' => $cadena($c['cita'] ?? '', 200), 'respuestas' => $resp];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    private static function cargarDom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $dom;
    }

    private static function cuerpoHtml(\DOMDocument $dom): string
    {
        $o = '';
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $h) $o .= $dom->saveHTML($h);
        return $o;
    }

    private static function desenvolver(\DOMNode $n): void
    {
        while ($n->firstChild) $n->parentNode->insertBefore($n->firstChild, $n);
        $n->parentNode->removeChild($n);
    }

    /** Quita las marcas de comentario: los textos comentados se desenvuelven y los elementos comentados pierden la marca */
    private static function quitarComentarios(\DOMDocument $dom): void
    {
        foreach (iterator_to_array($dom->getElementsByTagName('span')) as $n) if (str_contains($n->getAttribute('class'), 'rd-com')) self::desenvolver($n);
        foreach ((new \DOMXPath($dom))->query('//*[@data-cid]') as $n) {
            $n->removeAttribute('data-cid');
            $c = trim(preg_replace('/\brd-com-el\b/', '', $n->getAttribute('class')));
            $c === '' ? $n->removeAttribute('class') : $n->setAttribute('class', $c);
        }
    }

    /** Resuelve los cambios marcados: aceptar = se queda lo insertado y se quita lo borrado; rechazar = al revés. Quita además los comentarios */
    public static function resolverCambios(string $html, bool $aceptar): string
    {
        if (!preg_match('/<(ins|del)\b|rd-com/i', $html)) return $html;
        $dom = self::cargarDom($html);
        foreach (iterator_to_array($dom->getElementsByTagName('ins')) as $n) $aceptar ? self::desenvolver($n) : $n->parentNode->removeChild($n);
        foreach (iterator_to_array($dom->getElementsByTagName('del')) as $n) $aceptar ? $n->parentNode->removeChild($n) : self::desenvolver($n);
        self::quitarComentarios($dom);
        return self::cuerpoHtml($dom);
    }

    /** Cambios marcados como texto de colores (insertado subrayado, borrado tachado) para exportar mostrando la revisión */
    private static function marcarCambios(string $html): string
    {
        if (!preg_match('/<(ins|del)\b/i', $html)) return $html;
        $dom = self::cargarDom($html);
        foreach (['ins' => 'color:#1d4ed8;text-decoration:underline', 'del' => 'color:#dc2626;text-decoration:line-through'] as $t => $estilo) {
            foreach (iterator_to_array($dom->getElementsByTagName($t)) as $n) {
                $sp = $dom->createElement('span');
                $sp->setAttribute('style', $estilo);
                while ($n->firstChild) $sp->appendChild($n->firstChild);
                $n->parentNode->replaceChild($sp, $n);
            }
        }
        self::quitarComentarios($dom);
        return self::cuerpoHtml($dom);
    }

    /** Rehace las entradas del índice con los títulos 1 a 3 actuales (el índice guardado puede estar desactualizado) */
    private static function regenerarIndice(string $html): string
    {
        if (!str_contains($html, 'rd-indice')) return $html;
        $dom = self::cargarDom($html);
        $xp = new \DOMXPath($dom);
        $bloques = $xp->query('//div[contains(@class, "rd-indice")]');
        if (!$bloques->length) return $html;
        $entradas = [];
        foreach ($xp->query('//h1|//h2|//h3') as $h) {
            if ($h->parentNode && $xp->query('ancestor::div[contains(@class, "rd-indice")]', $h)->length) continue;
            $t = trim(preg_replace('/\s+/u', ' ', $h->textContent));
            if ($t !== '') $entradas[] = [(int)$h->tagName[1], $t];
        }
        foreach ($bloques as $b) {
            $titulo = null;
            foreach ($b->childNodes as $h) if ($h instanceof \DOMElement) { $titulo = $h; break; }
            while ($b->lastChild && $b->lastChild !== $titulo) $b->removeChild($b->lastChild);
            foreach ($entradas as [$nivel, $t]) {
                $p = $dom->createElement('p');
                $p->setAttribute('style', 'margin-left:' . (($nivel - 1) * 18) . 'pt');
                $p->appendChild($dom->createTextNode($t));
                $b->appendChild($p);
            }
            if (!$entradas) { $p = $dom->createElement('p'); $p->appendChild($dom->createTextNode('(Sin títulos)')); $b->appendChild($p); }
        }
        return self::cuerpoHtml($dom);
    }

    /** Cambios pendientes del contenido: [['tid', 'tipo', 'autor', 'fecha', 'texto'], ...] */
    public static function cambiosPendientes(string $html): int
    {
        return preg_match_all('/<(ins|del)\b/i', $html);
    }

    // ---------- Exportación ----------

    private static function cmATwips(float $cm): int
    {
        return (int)round($cm * 566.929);
    }

    /** Contenido listo para exportar: cambios resueltos o marcados y, si se piden, comentarios y firmas al final */
    private static function contenidoExportable(RedactorDocumento $d, array $opc): string
    {
        $html = Sheetflow::refrescarHtml((string)$d->contenido);
        $html = !empty($opc['cambios']) ? self::marcarCambios($html) : self::resolverCambios($html, true);
        $html = self::regenerarIndice($html);
        if (!empty($opc['comentarios'])) {
            $lista = json_decode((string)$d->comentarios, true) ?: [];
            if ($lista) {
                $html .= '<h2>Comentarios</h2><table style="width:100%"><tbody><tr><th style="border:1px solid #888888">#</th><th style="border:1px solid #888888">Texto comentado</th><th style="border:1px solid #888888">Comentario</th><th style="border:1px solid #888888">Estado</th></tr>';
                foreach ($lista as $i => $c) {
                    $txt = htmlspecialchars((string)$c['texto']) . ' <em>(' . htmlspecialchars((string)$c['autor']) . ')</em>';
                    foreach ($c['respuestas'] ?? [] as $r) $txt .= '<br>↳ ' . htmlspecialchars((string)$r['texto']) . ' <em>(' . htmlspecialchars((string)$r['autor']) . ')</em>';
                    $html .= '<tr><td style="border:1px solid #888888">' . ($i + 1) . '</td><td style="border:1px solid #888888">' . htmlspecialchars((string)($c['cita'] ?? '')) . '</td><td style="border:1px solid #888888">' . $txt . '</td><td style="border:1px solid #888888">' . (!empty($c['resuelto']) ? 'Resuelto' : 'Abierto') . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        }
        if (!empty($opc['firmas'])) {
            $sellos = RedactorFirmas::sellos($d);
            if ($sellos) {
                $html .= '<h2>Firmas electrónicas</h2><table style="width:100%"><tbody><tr><th style="border:1px solid #888888">Firmante</th><th style="border:1px solid #888888">Cargo</th><th style="border:1px solid #888888">Fecha</th><th style="border:1px solid #888888">Huella</th><th style="border:1px solid #888888">Estado</th></tr>';
                foreach ($sellos as $f) {
                    $html .= '<tr><td style="border:1px solid #888888">' . htmlspecialchars($f['nombre']) . '</td><td style="border:1px solid #888888">' . htmlspecialchars($f['cargo']) . '</td><td style="border:1px solid #888888">' . date('d/m/Y H:i', strtotime($f['firmado'])) . '</td><td style="border:1px solid #888888">' . substr($f['huella'], 0, 16) . '…</td><td style="border:1px solid #888888">' . ($f['valido'] ? 'Íntegro' : 'Modificado después de firmar') . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        }
        return $html;
    }

    /** Piezas del documento en orden: html, salto de página o índice (solo los bloques de primer nivel) */
    private static function piezas(string $html): array
    {
        if (trim($html) === '') return [['tipo' => 'html', 'html' => '']];
        $dom = self::cargarDom($html);
        $piezas = [];
        $acum = '';
        $vaciar = function () use (&$piezas, &$acum) { if (trim($acum) !== '') $piezas[] = ['tipo' => 'html', 'html' => $acum]; $acum = ''; };
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $n) {
            $cl = $n instanceof \DOMElement ? $n->getAttribute('class') : '';
            if (str_contains($cl, 'salto-pagina')) { $vaciar(); $piezas[] = ['tipo' => 'salto']; }
            elseif (str_contains($cl, 'rd-indice')) {
                $vaciar();
                $titulo = '';
                foreach ($n->childNodes as $h) if ($h instanceof \DOMElement) { $titulo = trim($h->textContent); break; }
                $piezas[] = ['tipo' => 'indice', 'titulo' => $titulo !== '' ? $titulo : 'Índice', 'html' => $dom->saveHTML($n)];
            } else $acum .= $dom->saveHTML($n);
        }
        $vaciar();
        return $piezas ?: [['tipo' => 'html', 'html' => '']];
    }

    /** XHTML bien formado (PhpWord lo exige) con las imágenes como rutas locales. Devuelve [xhtml, imágenes con posición especial] */
    /** Imágenes del documento en orden de aparición: [modo, x, y, ancho px, alto px] (para ajustar el ODT, que PhpWord escribe siempre en línea) */
    private static array $colaOdt = [];
    /** Por cada tabla del documento, en orden: si lleva bordes (el escritor ODT de PhpWord no los escribe) */
    private static array $tablasOdt = [];

    private static function xhtml(string $html, array &$especiales): string
    {
        if (trim($html) === '') return '<p></p>';
        $dom = self::cargarDom($html);
        foreach ($dom->getElementsByTagName('table') as $t) {
            $celda = $t->getElementsByTagName('td')->item(0) ?? $t->getElementsByTagName('th')->item(0);
            self::$tablasOdt[] = $celda && preg_match('/border(?:-\w+)?\s*:\s*[1-9]/', $celda->getAttribute('style'));
        }
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            $ruta = self::rutaImagen($img->getAttribute('src'));
            if (!$ruta) { $img->parentNode->removeChild($img); continue; }
            $estilo = $img->getAttribute('style');
            $cl = $img->getAttribute('class');
            // Tamaño explícito (ancho y alto) para que ningún formato deforme la imagen
            [$nw, $nh] = @getimagesize($ruta) ?: [300, 150];
            $w = (float)$img->getAttribute('width') ?: (preg_match('/(?:^|;)\s*width\s*:\s*(\d+(?:\.\d+)?)px/', $estilo, $mw) ? (float)$mw[1] : min($nw, 600));
            $w = max(8.0, min($w, 1400.0));
            $h = round($w * $nh / max(1, $nw));
            $img->setAttribute('width', (string)round($w));
            $img->setAttribute('height', (string)$h);
            $estilo = trim(preg_replace('/(^|;)\s*(width|height)\s*:[^;]*/', '$1', $estilo), '; ');
            $img->setAttribute('style', $estilo);
            $modo = str_contains($cl, 'rd-img-frente') ? 'frente' : (str_contains($cl, 'rd-img-detras') ? 'detras' : (str_contains($cl, 'rd-img-bloque') ? 'bloque' : (str_contains($cl, 'rd-img-izq') ? 'izq' : (str_contains($cl, 'rd-img-der') ? 'der' : 'linea'))));
            self::$colaOdt[] = [$modo, (int)preg_replace('/.*left:\s*(-?\d+).*/s', '$1', $estilo), (int)preg_replace('/.*top:\s*(-?\d+).*/s', '$1', $estilo), $w, $h];
            if (in_array($modo, ['frente', 'detras', 'izq', 'der'], true)) {
                // PhpWord no sabe de «delante/detrás» ni de «a un lado»: se copia con un nombre marcado y se ajusta después de armar el documento
                $marca = sys_get_temp_dir() . '/rdw_' . ['frente' => 'f', 'detras' => 'd', 'izq' => 'i', 'der' => 'r'][$modo] . '_' . (int)preg_replace('/.*left:\s*(-?\d+).*/s', '$1', $estilo) . '_' . (int)preg_replace('/.*top:\s*(-?\d+).*/s', '$1', $estilo) . '_' . uniqid() . '.' . pathinfo($ruta, PATHINFO_EXTENSION);
                if (@copy($ruta, $marca)) { $especiales[] = $marca; $ruta = $marca; }
            } elseif (str_contains($cl, 'rd-img-bloque')) {
                $p = $dom->createElement('p');
                $p->setAttribute('style', 'text-align:center');
                $img->parentNode->insertBefore($p, $img);
                $p->appendChild($img);
            }
            $img->setAttribute('src', $ruta);
        }
        $o = '';
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $h) $o .= $dom->saveXML($h);
        // PhpWord solo lee los bordes en el orden «ancho color estilo»
        return preg_replace('/(border(?:-(?:top|bottom|left|right))?\s*:\s*)(\d+(?:\.\d+)?(?:px|pt)?)\s+(solid|dashed|dotted)\s+(#[0-9a-fA-F]{6})/', '$1$2 $4 $3', $o);
    }

    /** Ruta en disco de una imagen del documento (vplan o firmas) o null */
    private static function rutaImagen(string $src): ?string
    {
        $n = basename($src);
        foreach ([[Imagenes::CARPETA_VPLAN, '#^/public/uploads/vplan/#'], [RedactorFirmas::CARPETA, '#^/public/uploads/firmas/#']] as [$dir, $re]) {
            if (preg_match($re, $src) && is_file($dir . '/' . $n)) return $dir . '/' . $n;
        }
        return null;
    }

    /** Datos de la imagen de membrete de un documento, o null si no existe. */
    private static function membreteConfig(RedactorDocumento $d, string $slot): ?array
    {
        $nombre = $slot === 'enc' ? (string) $d->imagen_encabezado : (string) $d->imagen_pie;
        if ($nombre === '' || !preg_match('/^[a-f0-9]{32}\.(?:png|jpg)$/', $nombre)) return null;
        $ruta = Imagenes::CARPETA_VPLAN . '/' . $nombre;
        $info = @getimagesize($ruta);
        if (!is_file($ruta) || !$info) return null;
        $ancho = (float) ($slot === 'enc' ? $d->imagen_encabezado_ancho : $d->imagen_pie_ancho);
        return [
            'ruta' => $ruta,
            'opacidad' => (int) ($slot === 'enc' ? $d->imagen_encabezado_opacidad : $d->imagen_pie_opacidad),
            'x' => (float) ($slot === 'enc' ? $d->imagen_encabezado_x : $d->imagen_pie_x),
            'y' => (float) ($slot === 'enc' ? $d->imagen_encabezado_y : $d->imagen_pie_y),
            'ancho' => $ancho > 0 ? $ancho : 5,
            'alto' => $ancho > 0 ? $ancho * $info[1] / max(1, $info[0]) : 2.5,
        ];
    }

    /** PNG temporal con la opacidad incorporada (Word/ODT no aplican opacidad a las imágenes). */
    private static function imagenConOpacidad(string $ruta, int $opacidad): ?string
    {
        $opacidad = max(0, min(100, $opacidad));
        if ($opacidad >= 100) return $ruta;
        if (!function_exists('imagecreatefromstring')) return null;
        $datos = @file_get_contents($ruta);
        $origen = $datos !== false ? @imagecreatefromstring($datos) : null;
        if (!$origen) return null;
        if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($origen);
        $w = imagesx($origen);
        $h = imagesy($origen);
        $destino = imagecreatetruecolor($w, $h);
        if (!$destino) return null;
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        $op = $opacidad / 100;
        $alfaBase = 127 * (1 - $op);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($origen, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                $na = (int) round($alfaBase + $a * $op);
                imagesetpixel($destino, $x, $y, imagecolorallocatealpha($destino, $r, $g, $b, $na));
            }
        }
        $base = tempnam(sys_get_temp_dir(), 'rdm');
        if ($base === false) return null;
        @unlink($base);
        $salida = $base . '.png';
        $ok = imagepng($destino, $salida);
        imagedestroy($origen);
        imagedestroy($destino);
        return $ok ? $salida : null;
    }

    /** Alineación de la numeración: left, center o right. */
    private static function alineacionNumero(string $pos): string
    {
        return str_ends_with($pos, '_izq') ? 'left' : (str_ends_with($pos, '_der') ? 'right' : 'center');
    }

    /** Imagen de membrete en el encabezado/pie del DOCX, anclada a la página y detrás del texto. */
    private static function agregarImagenMembreteDocx($contenedor, array $info, array &$temporales): void
    {
        $ruta = self::imagenConOpacidad($info['ruta'], $info['opacidad']);
        if (!$ruta) return;
        if ($ruta !== $info['ruta']) $temporales[] = $ruta;
        $pt = 28.3464567;   // 1 cm en puntos (unidad de los marcos de PhpWord)
        try {
            $contenedor->addImage($ruta, [
                'width' => $info['ancho'] * $pt,
                'height' => $info['alto'] * $pt,
                'positioning' => 'absolute',
                'posHorizontal' => 'absolute',
                'posVertical' => 'absolute',
                'posHorizontalRel' => 'page',
                'posVerticalRel' => 'page',
                'marginLeft' => $info['x'] * $pt,
                'marginTop' => $info['y'] * $pt,
                'wrappingStyle' => 'behind',
            ]);
        } catch (\Throwable $e) {
            error_log('Redactor membrete: ' . $e->getMessage());
        }
    }

    private static function phpWord(RedactorDocumento $d, string $formato, array $opc, array &$temporales): \PhpOffice\PhpWord\PhpWord
    {
        $pw = new \PhpOffice\PhpWord\PhpWord();
        $pw->setDefaultFontName('Calibri');
        $pw->setDefaultFontSize(11);
        $pw->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language($d->idioma === 'en' ? 'en-US' : 'es-MX'));
        $pw->getDocInfo()->setTitle($d->nombre)->setCompany($d->departamento)->setCreator((string)($_SESSION['nombre'] ?? ''));
        foreach ([1 => 20, 2 => 16, 3 => 13, 4 => 12] as $n => $tam) {
            $pw->addTitleStyle($n, ['bold' => true, 'size' => $tam], ['spaceBefore' => 240, 'spaceAfter' => 80]);
        }
        [$w, $h] = $d->medidas();
        $sec = $pw->addSection([
            'pageSizeW' => self::cmATwips($w), 'pageSizeH' => self::cmATwips($h), 'orientation' => 'portrait',
            'marginTop' => self::cmATwips((float)$d->margen_sup), 'marginBottom' => self::cmATwips((float)$d->margen_inf),
            'marginLeft' => self::cmATwips((float)$d->margen_izq), 'marginRight' => self::cmATwips((float)$d->margen_der),
            'headerHeight' => 400, 'footerHeight' => 400,
        ]);
        $esOdt = $formato === 'odt';
        $numEnEnc = $d->numerar && str_starts_with((string) $d->num_posicion, 'enc_');
        $imagenEnc = $esOdt ? null : self::membreteConfig($d, 'enc');
        $imagenPie = $esOdt ? null : self::membreteConfig($d, 'pie');
        $cabecera = (!$esOdt && ($d->encabezado || $d->encabezado_texto !== '' || $imagenEnc || $numEnEnc)) ? $sec->addHeader() : null;
        if ($cabecera) {
            if ($d->encabezado || $d->encabezado_texto !== '') $cabecera->addText(self::linea($d), ['size' => 9, 'color' => '666666']);
            if ($d->numerar && $numEnEnc) $cabecera->addPreserveText($d->textoNumeracion(), ['size' => 9, 'color' => '666666'], ['alignment' => self::alineacionNumero($d->num_posicion)]);
        }
        if (!$esOdt && $d->marca_agua !== '' && $formato === 'docx' && ($png = self::pngMarcaAgua($d->marca_agua))) {
            $temporales[] = $png;
            try {
                ($cabecera ?? $sec->addHeader())->addWatermark($png, ['width' => 420, 'height' => 420, 'positioning' => 'absolute', 'posHorizontalRel' => 'margin', 'posVerticalRel' => 'margin', 'posHorizontal' => 'center', 'posVertical' => 'center', 'wrappingStyle' => 'behind']);
            } catch (\Throwable $e) {
                error_log('Redactor marca de agua: ' . $e->getMessage());
            }
        }
        $pie = (!$esOdt && ($d->pie_texto !== '' || $imagenPie || ($d->numerar && !$numEnEnc))) ? $sec->addFooter() : null;
        if ($pie) {
            if ($d->pie_texto !== '') $pie->addText($d->pie_texto, ['size' => 9, 'color' => '666666'], ['alignment' => 'center']);
            if ($d->numerar && !$numEnEnc) $pie->addPreserveText($d->textoNumeracion(), ['size' => 9, 'color' => '666666'], ['alignment' => self::alineacionNumero($d->num_posicion)]);
        }
        if (!$esOdt) {
            if ($imagenEnc) self::agregarImagenMembreteDocx($cabecera ?? $sec->addHeader(), $imagenEnc, $temporales);
            if ($imagenPie) self::agregarImagenMembreteDocx($pie ?? $sec->addFooter(), $imagenPie, $temporales);
        }
        $especiales = [];
        foreach (self::piezas(self::contenidoExportable($d, $opc)) as $i => $pz) {
            if ($pz['tipo'] === 'salto') { $sec->addPageBreak(); continue; }
            if ($pz['tipo'] === 'indice' && $formato === 'docx') {
                // Índice real de Word (con números de página); Word lo actualiza al abrir
                $sec->addText($pz['titulo'], ['bold' => true, 'size' => 16], ['spaceAfter' => 120]);
                $sec->addTOC(['size' => 11], ['tabLeader' => \PhpOffice\PhpWord\Style\TOC::TAB_LEADER_DOT], 1, 3);
                $pw->getSettings()->setUpdateFields(true);
                continue;
            }
            \PhpOffice\PhpWord\Shared\Html::addHtml($sec, self::xhtml($pz['html'], $especiales), false, false);
        }
        foreach ($especiales as $f) $temporales[] = $f;
        if ($especiales) self::ajustarImagenes($sec, $d, $formato);
        return $pw;
    }

    /** Imágenes delante o detrás del texto (marcadas por su nombre temporal): se les pone el ajuste y la posición de la página */
    private static function ajustarImagenes($contenedor, RedactorDocumento $d, string $formato): void
    {
        foreach ($contenedor->getElements() as $el) {
            if ($el instanceof \PhpOffice\PhpWord\Element\Image) {
                if (!preg_match('/rdw_([fdir])_(-?\d+)_(-?\d+)_/', basename((string)$el->getSource()), $m)) continue;
                $st = $el->getStyle();
                if ($m[1] === 'i' || $m[1] === 'r') {
                    if ($formato !== 'docx') continue;   // en ODT los marcos se reescriben en marcosOdt()
                    // A un lado del texto: ajuste cuadrado pegado al margen izquierdo o derecho
                    $st->setWrap(\PhpOffice\PhpWord\Style\Image::WRAP_SQUARE);
                    $st->setPositioning(\PhpOffice\PhpWord\Style\Image::POS_RELATIVE);
                    $st->setPosHorizontal($m[1] === 'i' ? \PhpOffice\PhpWord\Style\Image::POS_LEFT : \PhpOffice\PhpWord\Style\Image::POS_RIGHT);
                    $st->setPosHorizontalRel(\PhpOffice\PhpWord\Style\Image::POS_RELTO_MARGIN);
                    $st->setPosVerticalRel(\PhpOffice\PhpWord\Style\Image::POS_RELTO_LINE);
                    $st->setPosVertical(\PhpOffice\PhpWord\Style\Image::POS_TOP);
                    $st->setWrapDistanceLeft(8);
                    $st->setWrapDistanceRight(8);
                    continue;
                }
                $st->setWrap($m[1] === 'f' ? \PhpOffice\PhpWord\Style\Image::WRAP_INFRONT : \PhpOffice\PhpWord\Style\Image::WRAP_BEHIND);
                $st->setPositioning(\PhpOffice\PhpWord\Style\Image::POS_ABSOLUTE);
                $st->setPosHorizontal(\PhpOffice\PhpWord\Style\Image::POS_ABSOLUTE);
                $st->setPosHorizontalRel(\PhpOffice\PhpWord\Style\Image::POS_RELTO_PAGE);
                $st->setPosVertical(\PhpOffice\PhpWord\Style\Image::POS_ABSOLUTE);
                $st->setPosVerticalRel(\PhpOffice\PhpWord\Style\Image::POS_RELTO_PAGE);
                $st->setMarginLeft((float)$m[2] * 0.75 + (float)$d->margen_izq * 28.35);
                $st->setMarginTop((float)$m[3] * 0.75);
            } elseif (method_exists($el, 'getElements')) {
                self::ajustarImagenes($el, $d, $formato);
            }
        }
    }

    /** PNG transparente con el texto en diagonal y gris claro (PhpWord solo admite marcas de agua de imagen); devuelve su ruta temporal */
    private static function pngMarcaAgua(string $texto): ?string
    {
        $ttf = __DIR__ . '/../../vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
        if (!function_exists('imagettftext') || !is_file($ttf)) return null;
        $tam = 900;
        $im = imagecreatetruecolor($tam, $tam);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagealphablending($im, true);
        $color = imagecolorallocatealpha($im, 150, 150, 150, 90);
        $pt = 110;
        do {
            $caja = imagettfbbox($pt, 0, $ttf, $texto);
            $ancho = $caja[2] - $caja[0];
            $pt -= 6;
        } while ($ancho > $tam * 0.92 && $pt > 20);
        $alto = $caja[1] - $caja[7];
        $lienzo = imagecreatetruecolor($tam, $tam);
        imagesavealpha($lienzo, true);
        imagealphablending($lienzo, false);
        imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
        imagealphablending($lienzo, true);
        imagettftext($lienzo, $pt + 6, 0, (int)(($tam - $ancho) / 2), (int)(($tam + $alto) / 2), $color, $ttf, $texto);
        $rot = imagerotate($lienzo, 45, imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
        if (!$rot) return null;
        imagesavealpha($rot, true);
        $ruta = tempnam(sys_get_temp_dir(), 'rdw') . '.png';
        imagepng($rot, $ruta);
        return is_file($ruta) ? $ruta : null;
    }

    private static function linea(RedactorDocumento $d): string
    {
        if ($d->encabezado_texto !== '') {
            return strtr($d->encabezado_texto, ['{nombre}' => $d->nombre, '{departamento}' => $d->departamento, '{fecha}' => date('d/m/Y', strtotime($d->fecha))]);
        }
        return $d->nombre . ($d->departamento !== '' ? ' · ' . $d->departamento : '') . ' · ' . date('d/m/Y', strtotime($d->fecha));
    }

    /** Binario del documento en 'docx' o 'odt'. $opc: cambios (mostrar revisión), comentarios y firmas (anexar al final) */
    public static function exportar(RedactorDocumento $d, string $formato, array $opc = []): string
    {
        @ini_set('memory_limit', '512M');
        $temporales = [];
        self::$colaOdt = [];
        self::$tablasOdt = [];
        $pw = self::phpWord($d, $formato, $opc, $temporales);
        $escritor = \PhpOffice\PhpWord\IOFactory::createWriter($pw, $formato === 'odt' ? 'ODText' : 'Word2007');
        $tmp = tempnam(sys_get_temp_dir(), 'red');
        try {
            $escritor->save($tmp);
            if ($formato === 'odt') self::ajustarOdt($tmp, $d);
            return (string)file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            foreach ($temporales as $t) @unlink($t);
        }
    }

    /** Ajuste de las imágenes en el ODT (a un lado, arriba y abajo, delante o detrás): se reescriben los marcos en el orden en que salieron */
    private static function marcosOdt(string $xml, RedactorDocumento $d): string
    {
        $cola = self::$colaOdt;
        if (!$cola || !preg_match_all('#<draw:frame draw:style-name="([^"]+)"[^>]*?text:anchor-type="as-char"#', $xml, $m)) return $xml;
        $estilos = [];
        $i = 0;
        $xml = preg_replace_callback('#<draw:frame draw:style-name="([^"]+)"([^>]*?)text:anchor-type="as-char"([^>]*?)>#', function ($f) use (&$i, $cola, $d, &$estilos) {
            [$modo, $x, $y, $w, $h] = $cola[$i++] ?? ['linea', 0, 0, 0, 0];
            $cm = fn($px) => round($px * 0.026458, 3) . 'cm';
            $dim = $w > 0 ? ' svg:width="' . $cm($w) . '" svg:height="' . $cm($h) . '"' : '';
            $sin = fn($t) => preg_replace('#\s(svg:(?:width|height)|svg:[xy])="[^"]*"#', '', $t);
            $nombre = $f[1];
            if ($modo === 'izq' || $modo === 'der') {
                $estilos[$nombre] = '<style:graphic-properties style:wrap="parallel" style:number-wrapped-paragraphs="no-limit" style:wrap-contour="false" style:run-through="foreground" style:horizontal-pos="' . ($modo === 'izq' ? 'left' : 'right') . '" style:horizontal-rel="paragraph" style:vertical-pos="top" style:vertical-rel="paragraph" fo:margin-top="0.1cm" fo:margin-bottom="0.1cm" fo:margin-left="' . ($modo === 'izq' ? '0cm' : '0.3cm') . '" fo:margin-right="' . ($modo === 'izq' ? '0.3cm' : '0cm') . '"/>';
                return '<draw:frame draw:style-name="' . $nombre . '"' . $sin($f[2]) . 'text:anchor-type="paragraph"' . $sin($f[3]) . $dim . '>';
            }
            if ($modo === 'bloque') {
                $estilos[$nombre] = '<style:graphic-properties style:wrap="none" style:horizontal-pos="center" style:horizontal-rel="paragraph" style:vertical-pos="top" style:vertical-rel="paragraph"/>';
                return '<draw:frame draw:style-name="' . $nombre . '"' . $sin($f[2]) . 'text:anchor-type="paragraph"' . $sin($f[3]) . $dim . '>';
            }
            if ($modo === 'frente' || $modo === 'detras') {
                $estilos[$nombre] = '<style:graphic-properties style:wrap="run-through" style:run-through="' . ($modo === 'frente' ? 'foreground' : 'background') . '" style:horizontal-pos="from-left" style:horizontal-rel="page" style:vertical-pos="from-top" style:vertical-rel="page"/>';
                return '<draw:frame draw:style-name="' . $nombre . '"' . $sin($f[2]) . 'text:anchor-type="page"' . $sin($f[3]) . $dim . ' svg:x="' . round((float)$d->margen_izq + $x * 0.026458, 3) . 'cm" svg:y="' . $cm($y) . '">';
            }
            return '<draw:frame draw:style-name="' . $nombre . '"' . $sin($f[2]) . 'text:anchor-type="as-char"' . $sin($f[3]) . $dim . '>';
        }, $xml);
        foreach ($estilos as $nombre => $props) {
            $xml = preg_replace('#(<style:style style:name="' . preg_quote($nombre, '#') . '" style:family="graphic"[^>]*>)<style:graphic-properties[^>]*/>#', '$1' . $props, $xml, 1);
        }
        return $xml;
    }

    /** El escritor ODT de PhpWord usa estilos «HeadingN» que no define (los títulos salen como texto normal) y no escribe bordes de tabla: se agregan */
    private static function estilosOdt(string $xml): string
    {
        $estilos = '<style:style style:name="RdCelda" style:family="table-cell"><style:table-cell-properties fo:padding="0.08cm" fo:border="0.5pt solid #888888"/></style:style>';
        foreach ([1 => 20, 2 => 16, 3 => 13, 4 => 12] as $n => $tam) {
            if (str_contains($xml, 'text:style-name="Heading' . $n . '"')) {
                $estilos .= '<style:style style:name="Heading' . $n . '" style:family="paragraph" style:parent-style-name="Normal"><style:paragraph-properties fo:margin-top="0.42cm" fo:margin-bottom="0.14cm" fo:keep-with-next="always"/><style:text-properties fo:font-size="' . $tam . 'pt" fo:font-weight="bold" style:font-size-asian="' . $tam . 'pt" style:font-size-complex="' . $tam . 'pt" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>';
            }
        }
        $xml = str_contains($xml, '<office:automatic-styles/>')
            ? str_replace('<office:automatic-styles/>', '<office:automatic-styles>' . $estilos . '</office:automatic-styles>', $xml)
            : preg_replace('#</office:automatic-styles>#', $estilos . '</office:automatic-styles>', $xml, 1);
        $i = 0;
        return preg_replace_callback('#<table:table [^>]*>.*?</table:table>#s', function ($m) use (&$i) {
            $con = self::$tablasOdt[$i++] ?? false;
            return $con ? str_replace('<table:table-cell office:value-type', '<table:table-cell table:style-name="RdCelda" office:value-type', $m[0]) : $m[0];
        }, $xml) ?? $xml;
    }

    /** El escritor ODT de PhpWord cruza los márgenes izquierdo/derecho, reduce los de arriba/abajo y deja el pie vacío: se corrigen y se pone «Página N de M» con los campos nativos de LibreOffice */
    private static function ajustarOdt(string $ruta, RedactorDocumento $d): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) return;
        // Los <br> salen como párrafos vacíos dentro de otro párrafo (inválido): se vuelven saltos de línea
        $cont = (string)$zip->getFromName('content.xml');
        if ($cont !== '') {
            $nuevo = preg_replace('#(</text:span>)<text:p text:style-name="[^"]*"/>(?=<text:span)#', '$1<text:line-break/>', $cont);
            $nuevo = self::marcosOdt($nuevo ?? $cont, $d);
            $nuevo = self::estilosOdt($nuevo);
            if ($nuevo !== $cont) $zip->addFromString('content.xml', $nuevo);
        }
        $xml = (string)$zip->getFromName('styles.xml');
        if ($xml !== '' && str_contains($xml, 'xmlns:fo=')) {
            foreach (['top' => 'sup', 'bottom' => 'inf', 'left' => 'izq', 'right' => 'der'] as $lado => $campo) {
                $xml = preg_replace('#(<style:page-layout-properties[^>]*?fo:margin-' . $lado . '=")[^"]*"#', '${1}' . (float)$d->{'margen_' . $campo} . 'cm"', $xml, 1);
            }
            $align = self::alineacionNumero((string) $d->num_posicion);
            $numEnEnc = $d->numerar && str_starts_with((string) $d->num_posicion, 'enc_');
            $encPartes = $piePartes = [];
            if ($d->encabezado || $d->encabezado_texto !== '') {
                $encPartes[] = '<text:p text:style-name="RdEncPie">' . htmlspecialchars(self::linea($d), ENT_XML1) . '</text:p>';
            }
            if ($d->numerar && $numEnEnc) {
                $encPartes[] = '<text:p text:style-name="RdNumPagina">Página <text:page-number text:select-page="current">1</text:page-number> de <text:page-count>1</text:page-count></text:p>';
            }
            if ($d->pie_texto !== '') {
                $piePartes[] = '<text:p text:style-name="RdEncPie">' . htmlspecialchars($d->pie_texto, ENT_XML1) . '</text:p>';
            }
            if ($d->numerar && !$numEnEnc) {
                $piePartes[] = '<text:p text:style-name="RdNumPagina">Página <text:page-number text:select-page="current">1</text:page-number> de <text:page-count>1</text:page-count></text:p>';
            }
            $manifest = (string) $zip->getFromName('META-INF/manifest.xml');
            foreach (['enc' => 'header', 'pie' => 'footer'] as $slot => $zona) {
                $info = self::membreteConfig($d, $slot);
                if (!$info) continue;
                $ruta = self::imagenConOpacidad($info['ruta'], (int) $info['opacidad']);
                if (!$ruta) continue;
                $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) ?: 'png';
                if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) $ext = 'png';
                $destino = 'Pictures/rdmembrete_' . $slot . '.' . $ext;
                if ($ruta === $info['ruta']) {
                    $zip->addFile($ruta, $destino);
                } else {
                    $zip->addFromString($destino, (string) file_get_contents($ruta));
                    @unlink($ruta);
                }
                $mime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
                if ($manifest !== '' && !str_contains($manifest, 'manifest:full-path="' . $destino . '"')) {
                    $manifest = str_replace('</manifest:manifest>', '<manifest:file-entry manifest:full-path="' . $destino . '" manifest:media-type="' . $mime . '"/></manifest:manifest>', $manifest);
                }
                $frame = '<text:p><draw:frame text:anchor-type="page" svg:x="' . $info['x'] . 'cm" svg:y="' . $info['y'] . 'cm" svg:width="' . $info['ancho'] . 'cm" svg:height="' . $info['alto'] . 'cm" draw:z-index="-1" style:wrap="run-through" style:run-through="background">'
                    . '<draw:image xlink:href="' . $destino . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame></text:p>';
                if ($zona === 'header') $encPartes[] = $frame; else $piePartes[] = $frame;
            }
            if ($manifest !== '' && str_contains($manifest, 'rdmembrete_')) $zip->addFromString('META-INF/manifest.xml', $manifest);
            $cabecera = $encPartes ? '<style:header>' . implode('', $encPartes) . '</style:header>' : '';
            $pie = $piePartes ? '<style:footer>' . implode('', $piePartes) . '</style:footer>' : '';
            $xml = preg_replace('#<style:header\b[^>]*/>#', '', $xml);
            $xml = preg_replace('#<style:header\b[^>]*>.*?</style:header>#s', '', $xml);
            $xml = preg_replace('#<style:footer\b[^>]*/>#', '', $xml);
            $xml = preg_replace('#<style:footer\b[^>]*>.*?</style:footer>#s', '', $xml);
            if ($cabecera !== '' || $pie !== '') {
                $hecho = false;
                $xml = preg_replace_callback('#<style:master-page\b([^>]*)/>#', function ($m) use ($cabecera, $pie, &$hecho) {
                    $hecho = true;
                    return '<style:master-page' . $m[1] . '>' . $cabecera . $pie . '</style:master-page>';
                }, $xml, 1);
                if (!$hecho) $xml = preg_replace('#</style:master-page>#', $cabecera . $pie . '</style:master-page>', $xml, 1);
            }
            $estilo = '<style:style style:name="RdEncPie" style:family="paragraph"><style:paragraph-properties fo:text-align="left"/><style:text-properties fo:font-size="9pt" fo:color="#666666"/></style:style>'
                . '<style:style style:name="RdNumPagina" style:family="paragraph"><style:paragraph-properties fo:text-align="' . $align . '"/><style:text-properties fo:font-size="9pt" fo:color="#666666"/></style:style>';
            $xml = str_contains($xml, '<office:automatic-styles/>')
                ? str_replace('<office:automatic-styles/>', '<office:automatic-styles>' . $estilo . '</office:automatic-styles>', $xml)
                : preg_replace('#</office:automatic-styles>#', $estilo . '</office:automatic-styles>', $xml, 1);
            $zip->addFromString('styles.xml', $xml);
        }
        $zip->close();
    }

    public static function pdf(RedactorDocumento $d, array $opc = []): string
    {
        @ini_set('memory_limit', '512M');
        [$w, $h] = $d->medidas();
        $html = self::contenidoExportable($d, $opc);
        $html = preg_replace_callback('#src="(/public/uploads/(?:vplan|firmas)/[a-f0-9]{32}\.(?:png|jpg))"#', fn($m) => 'src="' . (self::rutaImagen($m[1]) ?? '') . '"', $html);
        // Índice: sin números de página (Dompdf no los conoce); las entradas son enlaces internos
        $html = preg_replace('#<div[^>]*class="[^"]*salto-pagina[^"]*"[^>]*>(\s*</div>)?#i', '<div style="page-break-after:always"></div>', $html);
        $m = ['sup' => (float)$d->margen_sup, 'inf' => (float)$d->margen_inf, 'izq' => (float)$d->margen_izq, 'der' => (float)$d->margen_der];
        $css = '@page{margin:' . $m['sup'] . 'cm ' . $m['der'] . 'cm ' . $m['inf'] . 'cm ' . $m['izq'] . 'cm}'
            . 'body{font-family:DejaVu Sans,Helvetica,sans-serif;font-size:11pt;line-height:1.35;color:#000;margin:0}'
            . 'h1{font-size:20pt}h2{font-size:16pt}h3{font-size:13pt}table{border-collapse:collapse}td,th{border:1px solid #888;padding:3pt 5pt;vertical-align:top}'
            . 'blockquote{margin:6pt 0 6pt 18pt;padding-left:8pt;border-left:3pt solid #bbb;color:#444}pre{font-family:DejaVu Sans Mono,monospace;font-size:10pt}img{max-width:100%}img.rd-img-izq{float:left;margin:0 8pt 4pt 0}img.rd-img-der{float:right;margin:0 0 4pt 8pt}img.rd-img-bloque{display:block;margin:6pt auto}a{color:#0563c1}.rd-indice p{margin:2pt 0}';
        $marca = $d->marca_agua !== '' ? '<div style="position:fixed;left:0;right:0;top:40%;text-align:center;font-size:96pt;font-weight:bold;color:#cccccc;opacity:0.35;transform:rotate(-45deg);z-index:-1">' . htmlspecialchars($d->marca_agua) . '</div>' : '';
        $membrete = '';
        foreach (['enc', 'pie'] as $slot) {
            $info = self::membreteConfig($d, $slot);
            if (!$info) continue;
            $membrete .= '<div style="position:fixed;left:' . $info['x'] . 'cm;top:' . $info['y'] . 'cm;width:' . $info['ancho'] . 'cm;opacity:' . ($info['opacidad'] / 100) . ';z-index:-1">'
                . '<img src="' . htmlspecialchars($info['ruta']) . '" style="display:block;width:100%;height:auto">'
                . '</div>';
        }
        $dompdf = Pdf::preparar('<html><head><meta charset="utf-8"><title>' . htmlspecialchars($d->nombre) . '</title><style>' . $css . '</style></head><body>' . $membrete . $marca . $html . '</body></html>',
            [0, 0, $w / 2.54 * 72, $h / 2.54 * 72], 'portrait',
            ['isRemoteEnabled' => false, 'chroot' => realpath(__DIR__ . '/../../public/uploads') ?: Imagenes::CARPETA_VPLAN, 'defaultFont' => 'serif']);
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $fuente = $dompdf->getFontMetrics()->getFont('helvetica');
        $pw = $canvas->get_width();
        $ph = $canvas->get_height();
        if ($d->numerar) {
            $textoNum = 'Página {PAGE_NUM} de {PAGE_COUNT}';
            $anchoNum = $dompdf->getFontMetrics()->getTextWidth('Página 88 de 88', $fuente, 9);
            $pos = (string) $d->num_posicion;
            $xNum = str_ends_with($pos, '_izq')
                ? $m['izq'] * 28.35
                : (str_ends_with($pos, '_der') ? $pw - $m['der'] * 28.35 - $anchoNum : $pw / 2 - $anchoNum / 2);
            $yNum = str_starts_with($pos, 'enc_')
                ? max(10, $m['sup'] * 28.35 / 2) - 4
                : $ph - max(14, $m['inf'] * 28.35 / 2) - 4;
            $canvas->page_text($xNum, $yNum, $textoNum, $fuente, 9, [0.4, 0.4, 0.4]);
        }
        if ($d->encabezado || $d->encabezado_texto !== '') {
            $canvas->page_text($m['izq'] * 28.35, max(10, $m['sup'] * 28.35 / 2) - 4, self::linea($d), $fuente, 9, [0.4, 0.4, 0.4]);
        }
        if ($d->pie_texto !== '') {
            $ancho = $dompdf->getFontMetrics()->getTextWidth($d->pie_texto, $fuente, 9);
            $canvas->page_text($pw / 2 - $ancho / 2, $ph - max(14, $m['inf'] * 28.35 / 2) - ($d->numerar && !str_starts_with((string) $d->num_posicion, 'enc_') ? 14 : 4), $d->pie_texto, $fuente, 9, [0.4, 0.4, 0.4]);
        }
        return $dompdf->output();
    }
}
