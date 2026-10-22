/* Redactor: editor de documentos (contenteditable) con corrector ortográfico local (typo.js + diccionarios Hunspell es/en) */
(function () {
    'use strict';
    var C = window.RD_CONFIG;
    if (!C) return;
    var $ = function (id) { return document.getElementById(id); };
    var hoja = $('rd-hoja'), pagina = $('rd-pagina'), guias = $('rd-guias'), fondo = $('rd-fondo');
    var CM = 37.7953;   // px por cm (96 dpi)
    var version = C.version, sucio = false, guardando = false, temporizador = null;

    // ---------- Utilidades ----------
    function estado(t, error) { var e = $('rd-estado'); e.textContent = t; e.style.color = error ? '#ef4444' : ''; }
    function marcarSucio() { sucio = true; estado('Cambios sin guardar'); planificarRevision(); planificarDiseno(); }
    function enHoja(n) { return n && hoja.contains(n.nodeType === 1 ? n : n.parentNode); }
    function seleccion() { var s = window.getSelection(); return s && s.rangeCount && enHoja(s.getRangeAt(0).commonAncestorContainer) ? s : null; }
    var rangoGuardado = null;
    document.addEventListener('selectionchange', function () {
        var s = seleccion();
        if (s) { rangoGuardado = s.getRangeAt(0).cloneRange(); actualizarEstadoBarra(); }
    });
    function restaurarSeleccion() {
        if (!rangoGuardado) { hoja.focus(); return; }
        hoja.focus();
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(rangoGuardado);
    }
    function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function exec(cmd, valor, css) {
        hoja.focus();
        document.execCommand('styleWithCSS', false, !!css);
        var ok = document.execCommand(cmd, false, valor === undefined ? null : valor);
        marcarSucio();
        return ok;
    }
    function insertarHtml(html) { restaurarSeleccion(); exec('insertHTML', html, false); }

    // ---------- Barra de herramientas ----------
    var CSS_CMD = { justifyLeft: 1, justifyCenter: 1, justifyRight: 1, justifyFull: 1 };
    document.querySelectorAll('[data-cmd]').forEach(function (b) {
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });   // no quitar el foco ni la selección del texto
        b.addEventListener('click', function () {
            var c = b.dataset.cmd;
            if (c === 'paste') return pegarDelPortapapeles(false);
            if (c === 'pastetext') return pegarDelPortapapeles(true);
            if (c === 'removeFormat') { exec('removeFormat'); exec('unlink'); return; }
            if (c === 'cut' || c === 'copy') { hoja.focus(); document.execCommand(c); if (c === 'cut') marcarSucio(); return; }
            exec(c, null, !!CSS_CMD[c]);
        });
    });
    var ESTADOS = ['bold', 'italic', 'underline', 'strikeThrough', 'subscript', 'superscript', 'insertUnorderedList', 'insertOrderedList', 'justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull'];
    function actualizarEstadoBarra() {
        ESTADOS.forEach(function (c) {
            var b = document.querySelector('[data-cmd="' + c + '"]');
            if (!b) return;
            var on = false; try { on = document.queryCommandState(c); } catch (e) { /* ignorar */ }
            b.classList.toggle('activo', on);
        });
        var celda = celdaActual();
        $('rd-tabla-op').hidden = !celda;
        if (celda && $('rd-x-punch-cfg')) $('rd-x-punch-cfg').hidden = !celda.closest('table').hasAttribute('data-punch');
        if ($('rd-x-sf-abrir')) {
            var tb = celda && celda.closest('table'), vinc = !!tb && tb.hasAttribute('data-sf'), propia = !!tb && !vinc && !tb.hasAttribute('data-punch');
            $('rd-x-sf-abrir').hidden = !vinc; $('rd-x-sf-conv').hidden = !propia;
        }
        if (celda) mostrarTamanoTabla(celda.closest('table'));
        var bloque = bloqueActual();
        if (bloque) { var t = bloque.tagName.toLowerCase(); $('rd-estilo').value = ['h1', 'h2', 'h3', 'h4', 'blockquote', 'pre'].indexOf(t) >= 0 ? t : 'p'; }
    }
    function bloqueActual() {
        var s = seleccion(); if (!s) return null;
        var n = s.anchorNode; n = n && n.nodeType === 3 ? n.parentNode : n;
        while (n && n !== hoja) { if (/^(P|H[1-6]|BLOCKQUOTE|PRE|LI|DIV|TD|TH)$/.test(n.tagName)) return n; n = n.parentNode; }
        return null;
    }
    function bloquesDeSeleccion() {
        var s = seleccion(); if (!s) return [];
        var r = s.getRangeAt(0), out = [];
        hoja.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li,blockquote,pre,td,th').forEach(function (b) {
            if (r.intersectsNode(b) && !(b.tagName === 'TD' || b.tagName === 'TH') ) out.push(b);
        });
        if (!out.length) { var b = bloqueActual(); if (b) out.push(b); }
        return out;
    }
    $('rd-estilo').addEventListener('change', function () { restaurarSeleccion(); exec('formatBlock', '<' + this.value + '>'); });
    $('rd-fuente').addEventListener('change', function () { if (!this.value) return; restaurarSeleccion(); exec('fontName', this.value, true); this.value = ''; });
    $('rd-tamano').addEventListener('change', function () {
        var pt = this.value; this.value = ''; if (!pt) return;
        restaurarSeleccion();
        hoja.focus(); document.execCommand('styleWithCSS', false, false);
        document.execCommand('fontSize', false, '7');
        hoja.querySelectorAll('font[size="7"]').forEach(function (f) {
            var sp = document.createElement('span'); sp.style.fontSize = pt + 'pt';
            while (f.firstChild) sp.appendChild(f.firstChild);
            f.parentNode.replaceChild(sp, f);
        });
        marcarSucio();
    });
    $('rd-interlineado').addEventListener('change', function () {
        var v = this.value; this.value = ''; if (!v) return;
        restaurarSeleccion();
        bloquesDeSeleccion().forEach(function (b) { b.style.lineHeight = v; });
        marcarSucio();
    });
    $('rd-color').addEventListener('input', function () { restaurarSeleccion(); exec('foreColor', this.value, true); });
    $('rd-resalte').addEventListener('input', function () { restaurarSeleccion(); exec('hiliteColor', this.value, true); });

    // ---------- Paneles ----------
    var PANELES = ['rd-p-tabla', 'rd-p-imagen', 'rd-p-figura', 'rd-p-buscar', 'rd-p-pagina', 'rd-p-firma', 'rd-p-plantilla', 'rd-p-stats'];
    function alternar(id) {
        PANELES.forEach(function (p) { var e = $(p); e.hidden = p === id ? !e.hidden : true; });
    }
    [['rd-i-tabla', 'rd-p-tabla'], ['rd-i-imagen', 'rd-p-imagen'], ['rd-i-figura', 'rd-p-figura'], ['rd-i-buscar', 'rd-p-buscar'], ['rd-i-pagina', 'rd-p-pagina']].forEach(function (par) {
        var b = $(par[0]);
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () { alternar(par[1]); });
    });

    // ---------- Tabla ----------
    function anchoTablaCss() {
        var v = parseFloat($('rd-t-ancho').value), u = $('rd-t-un').value;
        return v > 0 ? (u === '%' ? Math.min(100, v) + '%' : Math.round(v * (PX_UN[u] || 1)) + 'px') : '100%';
    }
    function mostrarTamanoTabla(t) {
        if (!t || document.activeElement === $('rd-ta-w') || document.activeElement === $('rd-ta-h')) return;
        var u = $('rd-ta-un').value, w = t.style.width || '100%';
        if (u === '%') $('rd-ta-w').value = /%$/.test(w) ? parseFloat(w) : Math.round(t.offsetWidth / anchoUtil() * 100);
        else $('rd-ta-w').value = +(t.offsetWidth / PX_UN[u]).toFixed(u === 'px' ? 0 : 2);
        var tr = t.rows[0], mh = tr ? parseFloat(tr.style.height) : 0;
        $('rd-ta-h').value = mh ? +(mh / (PX_UN[u] || 1)).toFixed(u === 'px' ? 0 : 2) : '';
    }
    function tablaActual() { var c = celdaActual(); return c ? c.closest('table') : null; }
    $('rd-ta-un').addEventListener('change', function () { mostrarTamanoTabla(tablaActual()); });
    $('rd-ta-w').addEventListener('change', function () {
        var t = tablaActual(), v = parseFloat(this.value); if (!t || !(v > 0)) return;
        var u = $('rd-ta-un').value;
        t.style.width = u === '%' ? Math.min(100, v) + '%' : Math.min(Math.round(anchoUtil()), Math.round(v * PX_UN[u])) + 'px';
        marcarSucio(); dibujarGuias(); mostrarTamanoTabla(t);
    });
    $('rd-ta-h').addEventListener('change', function () {
        var t = tablaActual(), v = parseFloat(this.value); if (!t) return;
        var u = $('rd-ta-un').value, px = v > 0 ? Math.round(v * (PX_UN[u] || 1)) : 0;
        Array.prototype.forEach.call(t.rows, function (r) { if (px) r.style.height = px + 'px'; else r.style.removeProperty('height'); });
        marcarSucio(); dibujarGuias(); mostrarTamanoTabla(t);
    });
    $('rd-t-ok').addEventListener('click', function () {
        var origen = $('rd-t-origen').value;
        if (origen) {
            if (!window.RD || !RD.tablaOrigen) { estado('La tabla del programa no está disponible', true); return; }
            RD.tablaOrigen(origen, anchoTablaCss(), $('rd-t-enc').checked, !!($('rd-t-sfcomo') && $('rd-t-sfcomo').checked)); $('rd-p-tabla').hidden = true; return;
        }
        var f = Math.max(1, Math.min(50, +$('rd-t-filas').value || 3)), c = Math.max(1, Math.min(12, +$('rd-t-cols').value || 3)), enc = $('rd-t-enc').checked;
        var celda = function (t) { return '<' + t + ' style="border:1px solid #888888"><br></' + t + '>'; };
        var h = '<table style="width:' + anchoTablaCss() + ';border-collapse:collapse"><tbody>';
        for (var i = 0; i < f; i++) { h += '<tr>'; for (var j = 0; j < c; j++) h += celda(i === 0 && enc ? 'th' : 'td'); h += '</tr>'; }
        insertarHtml(h + '</tbody></table><p><br></p>');
        $('rd-p-tabla').hidden = true;
    });
    function celdaActual() {
        var s = seleccion(); if (!s) return null;
        var n = s.anchorNode; n = n && n.nodeType === 3 ? n.parentNode : n;
        return n && n.closest ? n.closest('td,th') : null;
    }
    function nuevaCelda(modelo) {
        var c = document.createElement(modelo.tagName === 'TH' ? 'th' : 'td');
        c.setAttribute('style', 'border:1px solid #888888'); c.innerHTML = '<br>'; return c;
    }
    document.querySelectorAll('[data-tabla]').forEach(function (b) {
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () {
            var cel = celdaActual(); if (!cel) return;
            var fila = cel.parentNode, tabla = cel.closest('table'), idx = Array.prototype.indexOf.call(fila.children, cel), op = b.dataset.tabla;
            if (op === 'fila+') { var nf = document.createElement('tr'); Array.prototype.forEach.call(fila.children, function (c) { nf.appendChild(nuevaCelda(c.tagName === 'TH' ? { tagName: 'TD' } : c)); }); fila.parentNode.insertBefore(nf, fila.nextSibling); }
            else if (op === 'col+') { tabla.querySelectorAll('tr').forEach(function (r) { var ref = r.children[idx]; if (ref) r.insertBefore(nuevaCelda(ref), ref.nextSibling); }); }
            else if (op === 'fila-') { if (tabla.rows.length > 1) fila.remove(); else tabla.remove(); }
            else if (op === 'col-') { if (fila.children.length > 1) tabla.querySelectorAll('tr').forEach(function (r) { if (r.children[idx]) r.children[idx].remove(); }); else tabla.remove(); }
            else if (op === 'borrar') tabla.remove();
            marcarSucio(); actualizarEstadoBarra();
        });
    });
    $('rd-celda-color').addEventListener('input', function () { var c = celdaActual(); if (c) { c.style.backgroundColor = this.value; marcarSucio(); } });

    // ---------- Imágenes ----------
    function subirImagen(archivo, alTerminar) {
        var fd = new FormData(); fd.append('id', C.id); fd.append('imagen', archivo, archivo.name || 'imagen.png');
        estado('Subiendo imagen…');
        fetch(C.api.imagen, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) { estado(d.error || 'No se pudo subir la imagen', true); return; }
            estado(sucio ? 'Cambios sin guardar' : ''); alTerminar(d.src);
        }).catch(function () { estado('No se pudo subir la imagen', true); });
    }
    function ponerImagen(src, anchoMax) {
        var im = new Image();
        im.onload = function () {
            var util = anchoUtil(), w = Math.min(im.naturalWidth || 300, anchoMax || util, util);
            insertarHtml('<img src="' + esc(src) + '" alt="" style="width:' + Math.round(w) + 'px">');
        };
        im.onerror = function () { insertarHtml('<img src="' + esc(src) + '" alt="">'); };
        im.src = src;
    }
    function anchoUtil() { return Math.max(100, hoja.clientWidth - parseFloat(getComputedStyle(hoja).paddingLeft) - parseFloat(getComputedStyle(hoja).paddingRight)); }
    $('rd-img-subir').addEventListener('click', function () { $('rd-img-archivo').click(); });
    $('rd-img-archivo').addEventListener('change', function () {
        var f = this.files[0]; this.value = ''; if (!f) return;
        subirImagen(f, function (src) { ponerImagen(src); $('rd-p-imagen').hidden = true; });
    });
    // Símbolo de la biblioteca: se pasa a PNG (los SVG no se exportan bien) y se sube como imagen del documento
    var selSimbolo = $('rd-img-simbolo');
    if (selSimbolo) selSimbolo.addEventListener('change', function () {
        var s = this.value; this.value = ''; if (!s) return;
        var im = new Image();
        im.onload = function () {
            var k = 256 / Math.max(im.naturalWidth || 256, im.naturalHeight || 256), cv = document.createElement('canvas');
            cv.width = Math.round((im.naturalWidth || 256) * k); cv.height = Math.round((im.naturalHeight || 256) * k);
            cv.getContext('2d').drawImage(im, 0, 0, cv.width, cv.height);
            cv.toBlob(function (b) { if (b) subirImagen(b, function (src) { ponerImagen(src, 96); $('rd-p-imagen').hidden = true; }); else estado('No se pudo preparar el símbolo', true); }, 'image/png');
        };
        im.onerror = function () { estado('No se pudo cargar el símbolo', true); };
        im.src = s;
    });
    var selEditor = $('rd-img-editor');
    if (selEditor) selEditor.addEventListener('change', function () { var s = this.value; this.value = ''; if (s) { ponerImagen(s); $('rd-p-imagen').hidden = true; } });

    // Imagen seleccionada: tamaño, alineación y borrado
    var imgSel = null;
    hoja.addEventListener('click', function (e) {
        if (imgSel) imgSel.classList.remove('rd-sel');
        imgSel = e.target.tagName === 'IMG' ? e.target : null;
        $('rd-imagen-op').hidden = !imgSel;
        if (imgSel) { imgSel.classList.add('rd-sel'); sincronizarImagenOp(); }
        var a = e.target.closest && e.target.closest('a[href]');
        if (a && (e.ctrlKey || e.metaKey)) window.open(a.href, '_blank', 'noopener');
    });
    document.querySelectorAll('[data-img-ancho]').forEach(function (b) {
        b.addEventListener('click', function () { if (!imgSel) return; imgSel.style.width = Math.round(anchoUtil() * (+b.dataset.imgAncho) / 100) + 'px'; imgSel.style.height = ''; imgSel.removeAttribute('height'); marcarSucio(); mostrarTamanoImagen(); });
    });
    document.querySelectorAll('[data-img-alinear]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!imgSel) return;
            var p = imgSel.parentNode; if (p === hoja) { var np = document.createElement('p'); hoja.insertBefore(np, imgSel); np.appendChild(imgSel); p = np; }
            p.style.textAlign = b.dataset.imgAlinear; marcarSucio();
        });
    });
    document.querySelector('[data-img-borrar]').addEventListener('click', function () { if (imgSel) { imgSel.remove(); imgSel = null; $('rd-imagen-op').hidden = true; marcarSucio(); } });

    // ---------- Figuras (se dibujan como SVG, se pasan a PNG y se suben como imagen) ----------
    function svgFigura(tipo, w, h, relleno, borde, sin) {
        var f = sin ? 'none' : relleno, sw = 3, m = sw, o = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '"><g fill="' + f + '" stroke="' + borde + '" stroke-width="' + sw + '" stroke-linejoin="round" stroke-linecap="round">';
        if (tipo === 'rect') o += '<rect x="' + m + '" y="' + m + '" width="' + (w - 2 * m) + '" height="' + (h - 2 * m) + '"/>';
        else if (tipo === 'redondo') o += '<rect x="' + m + '" y="' + m + '" width="' + (w - 2 * m) + '" height="' + (h - 2 * m) + '" rx="' + Math.min(w, h) * 0.18 + '"/>';
        else if (tipo === 'circulo') o += '<ellipse cx="' + w / 2 + '" cy="' + h / 2 + '" rx="' + (w / 2 - m) + '" ry="' + (h / 2 - m) + '"/>';
        else if (tipo === 'triangulo') o += '<polygon points="' + w / 2 + ',' + m + ' ' + (w - m) + ',' + (h - m) + ' ' + m + ',' + (h - m) + '"/>';
        else if (tipo === 'rombo') o += '<polygon points="' + w / 2 + ',' + m + ' ' + (w - m) + ',' + h / 2 + ' ' + w / 2 + ',' + (h - m) + ' ' + m + ',' + h / 2 + '"/>';
        else if (tipo === 'estrella') {
            var p = [], cx = w / 2, cy = h / 2;
            for (var i = 0; i < 10; i++) { var a = -Math.PI / 2 + i * Math.PI / 5, k = i % 2 ? 0.42 : 1; p.push((cx + Math.cos(a) * (w / 2 - m) * k).toFixed(1) + ',' + (cy + Math.sin(a) * (h / 2 - m) * k).toFixed(1)); }
            o += '<polygon points="' + p.join(' ') + '"/>';
        } else if (tipo === 'flecha') {
            var cabeza = Math.min(w * 0.35, h), y1 = h * 0.3, y2 = h * 0.7;
            o += '<polygon points="' + m + ',' + y1 + ' ' + (w - cabeza) + ',' + y1 + ' ' + (w - cabeza) + ',' + m + ' ' + (w - m) + ',' + h / 2 + ' ' + (w - cabeza) + ',' + (h - m) + ' ' + (w - cabeza) + ',' + y2 + ' ' + m + ',' + y2 + '"/>';
        } else o += '<line x1="' + m + '" y1="' + h / 2 + '" x2="' + (w - m) + '" y2="' + h / 2 + '"/>';
        return o + '</g></svg>';
    }
    var FIGURAS = [['rect', 'Rectángulo'], ['redondo', 'Rectángulo redondeado'], ['circulo', 'Círculo / elipse'], ['triangulo', 'Triángulo'], ['rombo', 'Rombo'], ['estrella', 'Estrella'], ['flecha', 'Flecha'], ['linea', 'Línea']];
    function pintarGaleriaFiguras() {
        var g = $('rd-f-galeria'); if (!g) return;
        var rel = $('rd-f-relleno').value, bor = $('rd-f-borde').value, sin = $('rd-f-sinrelleno').checked, act = $('rd-f-tipo').value;
        g.innerHTML = FIGURAS.map(function (f) {
            return '<button type="button" class="rd-fig' + (f[0] === act ? ' activo' : '') + '" data-fig="' + f[0] + '" title="' + f[1] + '" aria-label="' + f[1] + '" aria-pressed="' + (f[0] === act) + '">' + svgFigura(f[0], 56, 40, rel, bor, sin) + '</button>';
        }).join('');
    }
    $('rd-f-galeria').addEventListener('click', function (e) {
        var b = e.target.closest('[data-fig]'); if (!b) return;
        $('rd-f-tipo').value = b.dataset.fig; pintarGaleriaFiguras();
    });
    ['rd-f-relleno', 'rd-f-borde', 'rd-f-sinrelleno'].forEach(function (id) { $(id).addEventListener('input', pintarGaleriaFiguras); });
    pintarGaleriaFiguras();
    (function () {   // al cambiar de unidad se convierten los valores escritos
        var ant = 'px';
        $('rd-f-un').addEventListener('change', function () {
            var nueva = this.value, d = nueva === 'px' ? 0 : 2;
            ['rd-f-w', 'rd-f-h'].forEach(function (id) { var e = $(id), px = (+e.value || 0) * PX_UN[ant]; e.value = +(px / PX_UN[nueva]).toFixed(d); });
            ant = nueva;
        });
    })();
    $('rd-f-ok').addEventListener('click', function () {
        var fu = PX_UN[$('rd-f-un').value] || 1;
        var w = Math.round(Math.max(20, Math.min(900, (+$('rd-f-w').value || 160) * fu))), h = Math.round(Math.max(10, Math.min(900, (+$('rd-f-h').value || 100) * fu)));
        var svg = svgFigura($('rd-f-tipo').value, w, h, $('rd-f-relleno').value, $('rd-f-borde').value, $('rd-f-sinrelleno').checked);
        var im = new Image(), esc4 = 3;
        im.onload = function () {
            var cv = document.createElement('canvas'); cv.width = w * esc4; cv.height = h * esc4;
            cv.getContext('2d').drawImage(im, 0, 0, cv.width, cv.height);
            cv.toBlob(function (blob) {
                if (!blob) { estado('No se pudo crear la figura', true); return; }
                subirImagen(blob, function (src) { ponerImagen(src, w); $('rd-p-figura').hidden = true; });
            }, 'image/png');
        };
        im.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    });

    // ---------- Hipervínculos ----------
    var dlg = $('rd-dlg-enlace');
    function tipoEnlace() { var t = $('rd-e-tipo').value; $('rd-e-ext').hidden = t === 'int'; $('rd-e-int').hidden = t !== 'int'; $('rd-e-url').placeholder = t === 'mail' ? 'correo@dominio.com' : 'https://...'; }
    $('rd-e-tipo').addEventListener('change', tipoEnlace);
    $('rd-i-enlace').addEventListener('mousedown', function (e) { e.preventDefault(); });
    $('rd-i-enlace').addEventListener('click', function () {
        var s = seleccion();
        $('rd-e-texto').value = s ? s.toString() : ''; $('rd-e-url').value = ''; $('rd-e-error').hidden = true;
        $('rd-e-tipo').value = 'ext'; tipoEnlace();
        dlg.showModal();
    });
    $('rd-e-cancelar').addEventListener('click', function () { dlg.close(); });
    $('rd-f-enlace').addEventListener('submit', function (e) {
        e.preventDefault();
        var t = $('rd-e-tipo').value, url = t === 'int' ? $('rd-e-dest').value : $('rd-e-url').value.trim(), er = $('rd-e-error');
        if (t === 'mail') url = 'mailto:' + url.replace(/^mailto:/i, '');
        else if (t === 'ext' && url && !/^[a-z][a-z0-9+.-]*:/i.test(url)) url = 'https://' + url;
        if (t === 'int' ? !/^\//.test(url) : !/^(https?:\/\/[^\s]+|mailto:[^\s@]+@[^\s@]+|tel:[\d+ ()-]+)$/i.test(url)) { er.textContent = 'La dirección no es válida'; er.hidden = false; return; }
        var texto = $('rd-e-texto').value.trim();
        dlg.close();
        restaurarSeleccion();
        var s = seleccion();
        if (s && !s.isCollapsed && !texto) exec('createLink', url);
        else insertarHtml('<a href="' + esc(url) + '">' + esc(texto || (t === 'int' ? $('rd-e-dest').selectedOptions[0].textContent : url)) + '</a>&nbsp;');
    });

    // ---------- Salto de página ----------
    $('rd-i-salto').addEventListener('mousedown', function (e) { e.preventDefault(); });
    $('rd-i-salto').addEventListener('click', function () { insertarEnRaiz('<div class="salto-pagina" style="page-break-after:always"></div><p><br></p>'); });
    /** Inserta un bloque como hijo directo de la hoja (después del bloque donde está el cursor): saltos de página e índice */
    function insertarEnRaiz(html) {
        restaurarSeleccion();
        var s = seleccion(), n = s ? s.anchorNode : null;
        while (n && n.parentNode !== hoja) n = n.parentNode;
        if (n && n.nodeType === 1) n.insertAdjacentHTML('afterend', html); else hoja.insertAdjacentHTML('beforeend', html);
        marcarSucio();
    }

    // ---------- Buscar y reemplazar ----------
    /** Búsqueda con expresión regular sobre el texto del documento */
    function buscarRegex(atras) {
        var re;
        try { re = new RegExp($('rd-b-buscar').value, $('rd-b-mayus').checked ? 'g' : 'gi'); } catch (e) { $('rd-b-info').textContent = 'Expresión no válida'; return false; }
        var nodos = [], it = document.createTreeWalker(hoja, NodeFilter.SHOW_TEXT), n, texto = '';
        while ((n = it.nextNode())) { nodos.push({ n: n, ini: texto.length }); texto += n.nodeValue; }
        var s = seleccion(), pos = 0;
        if (s) {
            var r = s.getRangeAt(0), previo = document.createRange();
            previo.setStart(hoja, 0); previo.setEnd(atras ? r.startContainer : r.endContainer, atras ? r.startOffset : r.endOffset);
            pos = previo.toString().length;
        }
        var m, hallados = [];
        while ((m = re.exec(texto))) { if (m[0] === '') { re.lastIndex++; continue; } hallados.push(m); }
        if (!hallados.length) { $('rd-b-info').textContent = 'No se encontró'; return false; }
        var el = atras ? hallados.filter(function (x) { return x.index + x[0].length <= pos; }).pop() || hallados[hallados.length - 1] : hallados.filter(function (x) { return x.index >= pos; })[0] || hallados[0];
        var donde = function (i, fin) {
            for (var k = nodos.length - 1; k >= 0; k--) if (nodos[k].ini < i || (nodos[k].ini === i && !fin) || k === 0) return [nodos[k].n, Math.max(0, Math.min(nodos[k].n.nodeValue.length, i - nodos[k].ini))];
        };
        var a = donde(el.index, false), b = donde(el.index + el[0].length, true), rg = document.createRange();
        rg.setStart(a[0], a[1]); rg.setEnd(b[0], b[1]);
        hoja.focus(); var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(rg);
        var pad = a[0].parentNode; if (pad && pad.scrollIntoView) pad.scrollIntoView({ block: 'center' });
        $('rd-b-info').textContent = ''; return true;
    }
    function buscar(atras) {
        var q = $('rd-b-buscar').value; if (!q) return false;
        if ($('rd-b-regex') && $('rd-b-regex').checked) return buscarRegex(atras);
        hoja.focus();
        var ok = window.find(q, $('rd-b-mayus').checked, !!atras, true, false, false, false);
        var s = seleccion();
        if (!ok || !s) { $('rd-b-info').textContent = 'No se encontró'; return false; }
        $('rd-b-info').textContent = ''; return true;
    }
    $('rd-b-sig').addEventListener('click', function () { buscar(false); });
    $('rd-b-buscar').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); buscar(e.shiftKey); } });
    function reemplazarSeleccion() {
        var s = seleccion(), q = $('rd-b-buscar').value;
        if (!s || !q) return false;
        if ($('rd-b-regex') && $('rd-b-regex').checked) {
            var fl = $('rd-b-mayus').checked ? '' : 'i', t = s.toString();
            try { if (!new RegExp('^(?:' + q + ')$', fl).test(t)) return false; exec('insertText', t.replace(new RegExp(q, fl), $('rd-b-reemp').value)); return true; } catch (e) { return false; }
        }
        var igual = $('rd-b-mayus').checked ? s.toString() === q : s.toString().toLowerCase() === q.toLowerCase();
        if (!igual) return false;
        exec('insertText', $('rd-b-reemp').value); return true;
    }
    $('rd-b-rep').addEventListener('click', function () { if (!reemplazarSeleccion()) { buscar(false); } else buscar(false); });
    $('rd-b-todo').addEventListener('click', function () {
        var n = 0, tope = 2000;
        var s = window.getSelection(); var r = document.createRange(); r.setStart(hoja, 0); r.collapse(true); s.removeAllRanges(); s.addRange(r);
        while (tope-- > 0 && buscar(false)) { if (reemplazarSeleccion()) n++; else break; var sel = seleccion(); if (!sel) break; }
        $('rd-b-info').textContent = n + ' reemplazo' + (n === 1 ? '' : 's');
    });

    // ---------- Portapapeles ----------
    var PERMITIDAS = /^(P|BR|DIV|SPAN|H[1-6]|STRONG|B|EM|I|U|S|STRIKE|SUB|SUP|UL|OL|LI|TABLE|THEAD|TBODY|TR|TD|TH|A|BLOCKQUOTE|HR|PRE|CODE)$/;
    var ESTILOS_OK = /^(text-align|color|background-color|font-size|font-family|font-weight|font-style|text-decoration|line-height|margin-left)$/;
    function limpiarPegado(html) {
        var d = new DOMParser().parseFromString(html, 'text/html');
        d.querySelectorAll('script,style,meta,link,title,head,xml,iframe,object,svg,img').forEach(function (n) { n.remove(); });
        (function recorre(n) {
            Array.prototype.slice.call(n.childNodes).forEach(function (h) {
                if (h.nodeType === 8) { h.remove(); return; }
                if (h.nodeType !== 1) return;
                recorre(h);
                if (!PERMITIDAS.test(h.tagName)) { while (h.firstChild) h.parentNode.insertBefore(h.firstChild, h); h.remove(); return; }
                var estilo = [];
                (h.getAttribute('style') || '').split(';').forEach(function (p) {
                    var kv = p.split(':'); if (kv.length < 2) return;
                    var k = kv[0].trim().toLowerCase(), v = kv.slice(1).join(':').trim();
                    if (ESTILOS_OK.test(k) && !/url\(|expression/i.test(v) && !(k === 'font-family' && /[^\w ,'"-]/.test(v))) estilo.push(k + ':' + v);
                });
                var href = h.tagName === 'A' ? h.getAttribute('href') : null, cs = h.getAttribute('colspan'), rs = h.getAttribute('rowspan');
                Array.prototype.slice.call(h.attributes).forEach(function (a) { h.removeAttribute(a.name); });
                if (estilo.length) h.setAttribute('style', estilo.join(';'));
                if (href && /^(https?:|mailto:|tel:|\/)/i.test(href)) h.setAttribute('href', href);
                if (cs) h.setAttribute('colspan', cs); if (rs) h.setAttribute('rowspan', rs);
                if (/^(TD|TH)$/.test(h.tagName) && !h.style.border) h.style.border = '1px solid #888888';
            });
        })(d.body);
        return d.body.innerHTML;
    }
    function insertarTextoPlano(t) { restaurarSeleccion(); exec('insertText', t); }
    hoja.addEventListener('paste', function (e) {
        var cd = e.clipboardData; if (!cd) return;
        var imagenes = Array.prototype.filter.call(cd.files || [], function (f) { return /^image\//.test(f.type); });
        if (imagenes.length && !cd.getData('text/html')) { e.preventDefault(); subirImagen(imagenes[0], function (src) { ponerImagen(src); }); return; }
        var html = cd.getData('text/html');
        e.preventDefault();
        if (rastrear) { insertarRastreado(cd.getData('text/plain')); return; }
        if (html) insertarHtml(limpiarPegado(html)); else insertarTextoPlano(cd.getData('text/plain'));
    });
    function pegarDelPortapapeles(plano) {
        restaurarSeleccion();
        if (!navigator.clipboard || !navigator.clipboard.read) { estado('Usa Ctrl+V para pegar en este navegador', true); return; }
        navigator.clipboard.read().then(function (items) {
            var it = items[0]; if (!it) return;
            var tipoHtml = !plano && it.types.indexOf('text/html') >= 0, tipo = tipoHtml ? 'text/html' : 'text/plain';
            if (it.types.indexOf(tipo) < 0) return;
            it.getType(tipo).then(function (b) { return b.text(); }).then(function (t) { tipoHtml ? insertarHtml(limpiarPegado(t)) : insertarTextoPlano(t); });
        }).catch(function () { estado('El navegador no permitió leer el portapapeles. Usa Ctrl+V.', true); });
    }

    // ---------- Teclado ----------
    hoja.addEventListener('keydown', function (e) {
        var ctrl = e.ctrlKey || e.metaKey;
        if (ctrl && e.key.toLowerCase() === 's') { e.preventDefault(); guardar(); return; }
        if (ctrl && e.key.toLowerCase() === 'f') { e.preventDefault(); $('rd-p-buscar').hidden = false; $('rd-b-buscar').focus(); return; }
        if (e.key === 'Tab') {
            var cel = celdaActual();
            if (cel) {
                e.preventDefault();
                var celdas = Array.prototype.slice.call(cel.closest('table').querySelectorAll('td,th')), i = celdas.indexOf(cel) + (e.shiftKey ? -1 : 1);
                if (i >= celdas.length) { document.querySelector('[data-tabla="fila+"]').click(); celdas = Array.prototype.slice.call(cel.closest('table').querySelectorAll('td,th')); }
                if (celdas[i]) { var r = document.createRange(); r.selectNodeContents(celdas[i]); r.collapse(true); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
            } else if (document.queryCommandState('insertUnorderedList') || document.queryCommandState('insertOrderedList')) {
                e.preventDefault(); exec(e.shiftKey ? 'outdent' : 'indent');
            } else { e.preventDefault(); exec('insertText', '    '); }
        }
    });
    // Texto suelto en la raíz (p. ej. tras borrar todo): se envuelve en un párrafo para que el formato funcione
    function asegurarParrafo() {
        var n = hoja.firstChild;
        if (!n) { hoja.innerHTML = '<p><br></p>'; return; }
        var s = seleccion();
        if (s && s.isCollapsed && hoja.childNodes.length && Array.prototype.some.call(hoja.childNodes, function (h) { return h.nodeType === 3 && h.nodeValue.trim() !== ''; })) {
            document.execCommand('formatBlock', false, 'p');
        }
    }
    hoja.addEventListener('input', function () { asegurarParrafo(); marcarSucio(); });
    // Que siempre haya un párrafo (y no texto suelto) para que el formato funcione
    document.execCommand('defaultParagraphSeparator', false, 'p');

    // ---------- Página, márgenes y numeración ----------
    function ajustes() {
        return {
            papel: $('rd-papel').value, orientacion: $('rd-orient').value,
            sup: num('rd-m-sup'), inf: num('rd-m-inf'), izq: num('rd-m-izq'), der: num('rd-m-der'),
            numerar: $('rd-numerar').checked, encabezado: $('rd-encabezado').checked, idioma: $('rd-idioma').value
        };
    }
    function num(id) { var v = parseFloat(String($(id).value).replace(',', '.')); return isFinite(v) ? Math.max(0, Math.min(8, v)) : 2.54; }
    var medidas = null;
    function aplicarPagina() {
        var a = ajustes(), p = C.papeles[a.papel] || C.papeles.carta, w = p[0], h = p[1];
        if (a.orientacion === 'horizontal') { var t = w; w = h; h = t; }
        medidas = { w: w * CM, h: h * CM, sup: a.sup * CM, inf: a.inf * CM, izq: a.izq * CM, der: a.der * CM };
        hoja.style.width = (w * CM) + 'px';
        hoja.style.padding = [a.sup, a.der, a.inf, a.izq].map(function (v) { return v * CM + 'px'; }).join(' ');
        hoja.style.minHeight = medidas.h + 'px';
        dibujarGuias();
    }
    /** En el editor, un salto de página deja en blanco el resto de la hoja (solo visual: no se guarda) */
    function ajustarSaltos() {
        var sl = hoja.querySelectorAll('.salto-pagina'), i;
        for (i = 0; i < sl.length; i++) sl[i].style.height = '';
        for (i = 0; i < sl.length; i++) {
            var top = sl[i].offsetTop, destino = (Math.floor(top / medidas.h) + 1) * medidas.h + (medidas.sup || 0);
            var libre = destino - top - 2 - parseFloat(getComputedStyle(sl[i]).marginBottom || 0);
            if (libre > 0) sl[i].style.height = libre + 'px';
        }
    }
    function dibujarGuias() {
        if (!medidas) return;
        hoja.style.minHeight = medidas.h + 'px';
        ajustarSaltos();
        var alto = hoja.scrollHeight, n = Math.max(1, Math.ceil((alto - 1) / medidas.h));
        hoja.style.minHeight = (n * medidas.h) + 'px';
        var a = ajustes(), h = '';
        for (var i = 1; i <= n; i++) {
            if (i < n) h += '<div class="rd-corte" style="top:' + (i * medidas.h - 8) + 'px"><span>Página ' + i + '</span></div>';
            if (a.numerar) h += '<div class="rd-num" style="top:' + (i * medidas.h - Math.max(14, medidas.inf / 2) - 8) + 'px">Página ' + i + ' de ' + n + '</div>';
        }
        var marca = $('rd-x-marca') ? $('rd-x-marca').value.trim() : '';
        if (marca) for (i = 0; i < n; i++) h += '<div class="rd-marca-txt" style="top:' + (i * medidas.h) + 'px;height:' + medidas.h + 'px"><span>' + esc(marca) + '</span></div>';
        guias.innerHTML = h;
        guias.style.height = (n * medidas.h) + 'px';
        $('rd-e-pagina').textContent = n + (n === 1 ? ' página' : ' páginas');
        $('rd-e-palabras').textContent = contarPalabras() + ' palabras';
        planificarMiniaturas();
    }
    function contarPalabras() { var t = hoja.innerText.trim(); return t ? t.split(/\s+/).length : 0; }
    var tDiseno = null;
    function planificarDiseno() { clearTimeout(tDiseno); tDiseno = setTimeout(dibujarGuias, 250); }
    ['rd-papel', 'rd-orient', 'rd-m-sup', 'rd-m-inf', 'rd-m-izq', 'rd-m-der', 'rd-numerar', 'rd-encabezado'].forEach(function (id) {
        $(id).addEventListener('input', function () { if (/^rd-m-/.test(id)) $('rd-margen-pre').value = ''; aplicarPagina(); marcarSucio(); });
    });
    $('rd-margen-pre').addEventListener('change', function () {
        if (!this.value) return;
        var v = this.value.split(','); ['sup', 'inf', 'izq', 'der'].forEach(function (k, i) { $('rd-m-' + k).value = v[i]; });
        aplicarPagina(); marcarSucio();
    });
    $('rd-zoom').addEventListener('change', function () { pagina.style.zoom = this.value; });
    window.addEventListener('resize', function () { /* la hoja tiene ancho fijo; el contenedor desplaza */ });

    // ---------- Guardado ----------
    var etiquetaVersion = '';
    function datosDocumento(forzar) {
        var a = ajustes(), fd = new FormData();
        fd.append('id', C.id); fd.append('version', version);
        fd.append('nombre', $('rd-nombre').value); fd.append('fecha', $('rd-fecha').value); fd.append('departamento', $('rd-depto').value);
        fd.append('comentarios', JSON.stringify(comentarios)); if (rastrear) fd.append('rastrear', '1');
        fd.append('idioma', a.idioma); fd.append('papel', a.papel); fd.append('orientacion', a.orientacion);
        fd.append('margen_sup', a.sup); fd.append('margen_inf', a.inf); fd.append('margen_izq', a.izq); fd.append('margen_der', a.der);
        if (a.numerar) fd.append('numerar', '1'); if (a.encabezado) fd.append('encabezado', '1');
        ['marca', 'enc', 'pie'].forEach(function (k) { var e = $('rd-x-' + k); if (e) fd.append({ marca: 'marca_agua', enc: 'encabezado_texto', pie: 'pie_texto' }[k], e.value); });
        if (etiquetaVersion) { fd.append('version_etiqueta', etiquetaVersion); etiquetaVersion = ''; }
        if (imgSel) imgSel.classList.remove('rd-sel');
        var copia = hoja.cloneNode(true);
        copia.querySelectorAll('.rd-sel').forEach(function (n) { n.classList.remove('rd-sel'); });
        copia.querySelectorAll('.salto-pagina').forEach(function (n) { n.style.removeProperty('height'); });
        fd.append('contenido', copia.innerHTML);
        if (imgSel) imgSel.classList.add('rd-sel');
        if (forzar) fd.append('forzar', '1');
        return fd;
    }
    function guardar(forzar, despues) {
        if (guardando) return;
        if (hoja.querySelector('.rd-indice')) actualizarIndice(true);
        guardando = true; estado('Guardando…');
        fetch(C.api.guardar, { method: 'POST', body: datosDocumento(forzar), credentials: 'same-origin' }).then(function (r) { return r.json().then(function (d) { return [r.status, d]; }); }).then(function (res) {
            guardando = false; var d = res[1];
            if (res[0] === 409 && d.conflicto) {
                window.confirmarModal({ titulo: 'Conflicto de versión', mensaje: d.error + ' ¿Guardar de todos modos y reemplazar esa versión?', aceptar: 'Reemplazar' }).then(function (ok) {
                    if (ok) guardar(true, despues); else estado('No guardado: hay una versión más reciente', true);
                });
                return;
            }
            if (!d.success) { estado(d.error || 'No se pudo guardar', true); return; }
            version = d.version; sucio = false; if (d.sellos) { sellos = d.sellos; pintarSellos(); } estado('Guardado ' + new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }));
            $('rd-titulo').textContent = $('rd-nombre').value;
            if (despues) despues();
        }).catch(function () { guardando = false; estado('Sin conexión: no se guardó', true); });
    }
    $('rd-guardar').addEventListener('click', function () { guardar(); });
    var autoGuardar = true;
    try { autoGuardar = localStorage.getItem('rd-auto') !== '0'; } catch (e) { /* sin almacenamiento */ }
    (function () {
        var c = $('rd-h-auto'); if (!c) return;
        c.checked = autoGuardar;
        c.addEventListener('change', function () {
            autoGuardar = c.checked;
            try { localStorage.setItem('rd-auto', autoGuardar ? '1' : '0'); } catch (e) { /* sin almacenamiento */ }
            estado(autoGuardar ? 'Autoguardado activado (cada 30 s)' : 'Autoguardado desactivado: guarda con el botón Guardar', !autoGuardar && sucio);
        });
    })();
    setInterval(function () { if (autoGuardar && sucio && !guardando) guardar(); }, 30000);   // autoguardado
    window.addEventListener('beforeunload', function (e) { if (sucio) { e.preventDefault(); e.returnValue = ''; } });
    ['rd-nombre', 'rd-fecha', 'rd-depto'].forEach(function (id) { $(id).addEventListener('input', marcarSucio); });
    function opcion(id) { var e = $(id); return !!(e && e.checked); }
    document.querySelectorAll('[data-exportar]').forEach(function (b) {
        b.addEventListener('click', function () {
            var f = b.dataset.exportar, ir = function () {
                var q = (opcion('rd-x-cambios') ? '&cambios=1' : '') + (opcion('rd-x-coment') ? '&comentarios=1' : '') + (opcion('rd-x-firmas') ? '' : '&firmas=0');
                if (f === 'imprimir') window.open(C.descargar + '&formato=pdf&inline=1' + q, '_blank', 'noopener');
                else window.location.href = C.descargar + '&formato=' + f + q;
            };
            if (sucio) guardar(false, ir); else ir();
        });
    });

    // ---------- Corrector ortográfico (typo.js + diccionarios locales) ----------
    var diccionarios = {}, propias = {}, ignoradas = {}, cache = {}, tRev = null;
    C.palabras.forEach(function (p) { propias[p.toLowerCase()] = true; });
    var soportaResaltado = typeof CSS !== 'undefined' && CSS.highlights && typeof Highlight !== 'undefined';
    function cargarDiccionario(idioma) {
        if (diccionarios[idioma]) return diccionarios[idioma];
        diccionarios[idioma] = Promise.all([fetch(C.dic + idioma + '.aff').then(function (r) { return r.text(); }), fetch(C.dic + idioma + '.dic').then(function (r) { return r.text(); })]).then(function (t) {
            return new Typo(idioma, t[0], t[1], { platform: 'any' });
        });
        return diccionarios[idioma];
    }
    var activos = [];   // diccionarios (Typo) en uso según el idioma del documento
    function prepararCorrector() {
        var idioma = $('rd-idioma').value, langs = idioma === 'en' ? ['en'] : idioma === 'es' ? ['es'] : ['es', 'en'];
        hoja.lang = idioma === 'en' ? 'en' : 'es';
        $('rd-e-corrector').textContent = 'Corrector: cargando…';
        cache = {};
        if (!soportaResaltado) { hoja.spellcheck = true; $('rd-e-corrector').textContent = 'Corrector: el del navegador'; return; }
        Promise.all(langs.map(cargarDiccionario)).then(function (d) {
            activos = d; $('rd-e-corrector').textContent = 'Corrector: ' + (idioma === 'en' ? 'English' : idioma === 'es' ? 'Español' : 'Español + English');
            revisar();
        }).catch(function () { hoja.spellcheck = true; $('rd-e-corrector').textContent = 'Corrector: el del navegador'; });
    }
    $('rd-idioma').addEventListener('change', function () { prepararCorrector(); marcarSucio(); });
    function palabraOk(w) {
        var k = w.toLowerCase();
        if (cache[k] !== undefined) return cache[k];
        var ok = propias[k] || ignoradas[k] || /\d/.test(w) || w.length < 2;
        if (!ok) { var n = w.replace(/[’]/g, "'"); ok = activos.some(function (t) { return t.check(n) || t.check(n.toLowerCase()); }); }
        if (!ok && w === w.toUpperCase() && w.length <= 6) ok = true;   // siglas
        return (cache[k] = !!ok);
    }
    var RE_PAL = /[\p{L}\p{M}][\p{L}\p{M}'’-]*/gu;
    function revisar() {
        if (!soportaResaltado || !activos.length) return;
        var rangos = [], it = document.createTreeWalker(hoja, NodeFilter.SHOW_TEXT, null), nodo;
        while ((nodo = it.nextNode())) {
            if (nodo.parentNode.closest && nodo.parentNode.closest('a,pre,code')) continue;
            var t = nodo.nodeValue, m;
            RE_PAL.lastIndex = 0;
            while ((m = RE_PAL.exec(t))) {
                var w = m[0].replace(/^[-'’]+|[-'’]+$/g, '');
                if (!w || palabraOk(w)) continue;
                var r = document.createRange(); r.setStart(nodo, m.index); r.setEnd(nodo, m.index + m[0].length); rangos.push(r);
            }
        }
        CSS.highlights.set('rd-error', new Highlight(...rangos));
    }
    function planificarRevision() { clearTimeout(tRev); tRev = setTimeout(revisar, 500); }

    // Menú contextual: sugerencias del corrector
    var menu = $('rd-menu-ort');
    function ocultarMenu() { menu.hidden = true; }
    document.addEventListener('click', ocultarMenu);
    hoja.addEventListener('scroll', ocultarMenu);
    function rangoPalabra(x, y) {
        var pos = document.caretPositionFromPoint ? document.caretPositionFromPoint(x, y) : null, nodo, off;
        if (pos) { nodo = pos.offsetNode; off = pos.offset; }
        else if (document.caretRangeFromPoint) { var r0 = document.caretRangeFromPoint(x, y); if (r0) { nodo = r0.startContainer; off = r0.startOffset; } }
        if (!nodo || nodo.nodeType !== 3 || !hoja.contains(nodo)) return null;
        var t = nodo.nodeValue, m;
        RE_PAL.lastIndex = 0;
        while ((m = RE_PAL.exec(t))) if (off >= m.index && off <= m.index + m[0].length) { var r = document.createRange(); r.setStart(nodo, m.index); r.setEnd(nodo, m.index + m[0].length); return r; }
        return null;
    }
    function quitarAcentos(t) { return t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    function distancia(a, b) {
        var m = a.length, n = b.length, d = [], i, j;
        for (i = 0; i <= m; i++) { d[i] = [i]; }
        for (j = 1; j <= n; j++) d[0][j] = j;
        for (i = 1; i <= m; i++) for (j = 1; j <= n; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        return d[m][n];
    }
    function puntuar(sug, base) { var x = quitarAcentos(sug.toLowerCase()); return distancia(x, base) * 2 + (x[0] === base[0] ? 0 : 1); }
    hoja.addEventListener('contextmenu', function (e) {
        if (!soportaResaltado || !activos.length) return;
        var r = rangoPalabra(e.clientX, e.clientY); if (!r) return;
        var w = r.toString().replace(/^[-'’]+|[-'’]+$/g, '');
        if (!w || palabraOk(w)) return;
        e.preventDefault();
        var sug = [];
        activos.forEach(function (t) { t.suggest(w.replace(/[’]/g, "'"), 8).forEach(function (s) { if (sug.indexOf(s) < 0) sug.push(s); }); });
        // typo.js no ordena por cercanía: se prefieren las de menor distancia de edición (sin acentos) y con la misma inicial
        var base = quitarAcentos(w.toLowerCase());
        sug.sort(function (x, y) { return puntuar(x, base) - puntuar(y, base); });
        sug = sug.slice(0, 6);
        menu.innerHTML = (sug.length ? sug.map(function (s, i) { return '<button type="button" data-sug="' + i + '"><strong>' + esc(s) + '</strong></button>'; }).join('') : '<span class="text-muted">Sin sugerencias</span>')
            + '<hr><button type="button" data-ort="ignorar">Ignorar</button><button type="button" data-ort="agregar">Agregar al diccionario</button>';
        menu.style.left = Math.min(e.clientX, window.innerWidth - 230) + 'px'; menu.style.top = Math.min(e.clientY, window.innerHeight - 220) + 'px';
        menu.hidden = false;
        menu.onclick = function (ev) {
            var b = ev.target.closest('button'); if (!b) return;
            ev.stopPropagation(); ocultarMenu();
            if (b.dataset.sug !== undefined) {
                var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); hoja.focus(); exec('insertText', sug[+b.dataset.sug]);
            } else if (b.dataset.ort === 'ignorar') { ignoradas[w.toLowerCase()] = true; cache = {}; revisar(); }
            else {
                propias[w.toLowerCase()] = true; cache = {}; revisar();
                var fd = new FormData(); fd.append('id', C.id); fd.append('palabra', w);
                fetch(C.api.palabra, { method: 'POST', body: fd, credentials: 'same-origin' });
            }
        };
    });


    // ---------- Índice automático ----------
    var estadoRevisionTimer = null;
    function textoSinBorrados(h) {
        var c = h.cloneNode(true);
        c.querySelectorAll('del').forEach(function (d) { d.remove(); });
        return c.textContent.replace(/\s+/g, ' ').trim();
    }
    function entradasIndice() {
        var html = '', n = 0;
        hoja.querySelectorAll('h1,h2,h3').forEach(function (h) {
            if (h.closest('.rd-indice')) return;
            var t = textoSinBorrados(h); if (!t) return;
            n++; h.id = 'rd-h' + n;
            html += '<p style="margin-left:' + (+h.tagName[1] - 1) * 18 + 'pt"><a href="#rd-h' + n + '">' + esc(t) + '</a></p>';
        });
        return html || '<p><em>Aún no hay títulos. Aplica «Título 1», «Título 2» o «Título 3» a tus encabezados y actualiza el índice.</em></p>';
    }
    function actualizarIndice(silencio) {
        var ind = hoja.querySelector('.rd-indice'); if (!ind) return;
        var titulo = ind.firstElementChild ? ind.firstElementChild.outerHTML : '<p><strong>Índice</strong></p>';
        ind.innerHTML = titulo + entradasIndice();
        ind.setAttribute('contenteditable', 'false');
        if (!silencio) marcarSucio();
    }
    $('rd-i-indice').addEventListener('mousedown', function (e) { e.preventDefault(); });
    $('rd-i-indice').addEventListener('click', function () {
        if (hoja.querySelector('.rd-indice')) { actualizarIndice(); estado('Índice actualizado'); return; }
        insertarEnRaiz('<div class="rd-indice"><p><strong>Índice</strong></p></div><p><br></p>');
        actualizarIndice();
    });
    hoja.querySelectorAll('.rd-indice').forEach(function (n) { n.setAttribute('contenteditable', 'false'); });
    if (hoja.querySelector('.rd-indice')) actualizarIndice(true);   // al abrir, el índice refleja los títulos actuales

    // ---------- Envolver texto (comentarios y cambios) ----------
    /** Parte los textos del rango en los bordes y los envuelve uno a uno con lo que devuelve crear(texto); crear puede devolver null para dejarlo igual */
    function envolverTextos(range, crear) {
        var raiz = range.commonAncestorContainer; if (raiz.nodeType === 3) raiz = raiz.parentNode;
        var it = document.createTreeWalker(raiz, NodeFilter.SHOW_TEXT), n, nodos = [], hechos = [];
        while ((n = it.nextNode())) if (range.intersectsNode(n) && n.nodeValue !== '') nodos.push(n);
        nodos.forEach(function (t) {
            var ini = t === range.startContainer ? range.startOffset : 0, fin = t === range.endContainer ? range.endOffset : t.nodeValue.length;
            if (fin <= ini) return;
            if (fin < t.nodeValue.length) t.splitText(fin);
            if (ini > 0) t = t.splitText(ini);
            var w = crear(t);
            if (w) { t.parentNode.insertBefore(w, t); w.appendChild(t); hechos.push(w); }
        });
        return hechos;
    }
    function desenvolver(el) { var p = el.parentNode; while (el.firstChild) p.insertBefore(el.firstChild, el); p.removeChild(el); p.normalize(); }
    function ahoraIso() { var d = new Date(), z = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + 'T' + z(d.getHours()) + ':' + z(d.getMinutes()) + ':' + z(d.getSeconds()); }
    function fechaCorta(iso) { var d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }); }

    // ---------- Panel lateral de revisión ----------
    var lateral = $('rd-lateral'), pestana = 'comentarios';
    function mostrarLateral(v, cual) {
        lateral.hidden = !v; if (cual) pestana = cual;
        document.querySelectorAll('[data-lat]').forEach(function (b) { b.classList.toggle('activo', b.dataset.lat === pestana); });
        $('rd-lat-comentarios').hidden = pestana !== 'comentarios'; $('rd-lat-cambios').hidden = pestana !== 'cambios';
        if (v) pintarRevision();
    }
    $('rd-i-revision').addEventListener('click', function () { mostrarLateral(lateral.hidden); });
    document.querySelectorAll('[data-lat]').forEach(function (b) { b.addEventListener('click', function () { mostrarLateral(true, b.dataset.lat); }); });
    function pintarRevision() {
        if (!lateral.hidden) { pintarComentarios(); pintarCambios(); }
        var n = hoja.querySelectorAll('ins,del').length ? cambiosAgrupados().length : 0, ab = comentarios.filter(function (c) { return !c.resuelto; }).length, tot = n + ab, b = $('rd-n-revision');
        b.hidden = !tot; b.textContent = tot;
    }
    function planificarRevisionPanel() { clearTimeout(estadoRevisionTimer); estadoRevisionTimer = setTimeout(pintarRevision, 400); }
    hoja.addEventListener('input', planificarRevisionPanel);

    // ---------- Comentarios ----------
    var comentarios = (C.comentarios || []).slice(), nuevoRango = null, nuevoElemento = null;
    function idComentario() { var m = 0; comentarios.forEach(function (c) { m = Math.max(m, +c.id.slice(1) || 0); }); hoja.querySelectorAll('[data-cid]').forEach(function (e) { m = Math.max(m, +e.dataset.cid.slice(1) || 0); }); return 'c' + (m + 1); }
    $('rd-i-comentar').addEventListener('mousedown', function (e) { e.preventDefault(); });
    $('rd-i-comentar').addEventListener('click', function () {
        var s = seleccion();
        if (!s || s.isCollapsed) { estado('Selecciona el texto que quieres comentar', true); return; }
        nuevoRango = s.getRangeAt(0).cloneRange(); nuevoElemento = null;
        mostrarLateral(true, 'comentarios');
        var ta = $('rd-com-nuevo-texto'); if (ta) ta.focus();
    });
    function textoObjetivo() {
        if (nuevoRango) return nuevoRango.toString();
        if (!nuevoElemento) return '';
        return nuevoElemento.tagName === 'IMG' ? '[Imagen]' : nuevoElemento.tagName === 'HR' ? '[Línea horizontal]' : nuevoElemento.textContent;
    }
    function quitarMarca(n) {
        if (n.tagName === 'SPAN' && n.classList.contains('rd-com')) { desenvolver(n); return; }
        n.removeAttribute('data-cid'); n.classList.remove('rd-com-el', 'rd-flash'); if (!n.getAttribute('class')) n.removeAttribute('class');
    }
    var filtroAutor = '';
    function pintarComentarios() {
        var cont = $('rd-lat-comentarios'), h = '';
        if (nuevoRango || nuevoElemento) h += '<div class="rd-com-form"><strong>Nuevo comentario</strong><blockquote>«' + esc(textoObjetivo().replace(/\s+/g, ' ').trim().slice(0, 120)) + '»</blockquote><textarea id="rd-com-nuevo-texto" rows="3" maxlength="2000" placeholder="Escribe tu comentario"></textarea><div><button type="button" class="btn btn-sm btn-primary" data-com="crear">Comentar</button> <button type="button" class="btn btn-sm btn-ghost" data-com="cancelar">Cancelar</button></div></div>';
        var autores = comentarios.map(function (c) { return c.autor; }).filter(function (a, i, v) { return v.indexOf(a) === i; });
        if (filtroAutor && autores.indexOf(filtroAutor) < 0) filtroAutor = '';
        if (autores.length > 1) h += '<label class="rd-com-filtro">Autor <select id="rd-com-autor"><option value="">Todos</option>' + autores.map(function (a) { return '<option value="' + esc(a) + '"' + (a === filtroAutor ? ' selected' : '') + '>' + esc(a) + '</option>'; }).join('') + '</select></label>';
        if (!comentarios.length && !nuevoRango && !nuevoElemento) h += '<p class="text-muted text-sm">Aún no hay comentarios. Selecciona texto y pulsa «Comentar selección», o activa «Comentar con clic» y haz clic en un párrafo, imagen o celda.</p>';
        comentarios.forEach(function (c) {
            if (filtroAutor && c.autor !== filtroAutor) return;
            var existe = !!hoja.querySelector('[data-cid="' + c.id + '"]');
            h += '<div class="rd-com-item' + (c.resuelto ? ' resuelto' : '') + '" data-id="' + c.id + '">'
                + (c.cita ? '<blockquote>«' + esc(c.cita) + '»' + (existe ? '' : ' <em>(texto eliminado)</em>') + '</blockquote>' : '')
                + '<p><strong>' + esc(c.autor) + '</strong> <small class="text-muted">' + esc(fechaCorta(c.fecha)) + '</small></p><p>' + esc(c.texto).replace(/\n/g, '<br>') + '</p>'
                + (c.respuestas || []).map(function (r) { return '<p class="rd-resp">↳ <strong>' + esc(r.autor) + '</strong>: ' + esc(r.texto) + '</p>'; }).join('')
                + '<div class="rd-com-acc"><input type="text" class="rd-resp-txt" maxlength="2000" placeholder="Responder…"><button type="button" class="btn btn-sm btn-ghost" data-com="responder">↳</button>'
                + '<button type="button" class="btn btn-sm btn-ghost" data-com="resolver">' + (c.resuelto ? 'Reabrir' : 'Resolver') + '</button><button type="button" class="btn btn-sm btn-ghost" data-com="borrar" title="Eliminar el comentario"><i class="fa-solid fa-trash-can"></i></button></div></div>';
        });
        cont.innerHTML = h;
    }
    $('rd-lat-comentarios').addEventListener('change', function (e) { if (e.target.id === 'rd-com-autor') { filtroAutor = e.target.value; pintarComentarios(); } });
    $('rd-lat-comentarios').addEventListener('click', function (e) {
        var b = e.target.closest('[data-com]'), card = e.target.closest('.rd-com-item');
        if (!b) { if (card) irAComentario(card.dataset.id); return; }
        var acc = b.dataset.com;
        if (acc === 'cancelar') { nuevoRango = null; nuevoElemento = null; pintarComentarios(); return; }
        if (acc === 'crear') {
            var txt = $('rd-com-nuevo-texto').value.trim(); if (!txt || (!nuevoRango && !nuevoElemento)) return;
            var id = idComentario(), cita = textoObjetivo().replace(/\s+/g, ' ').trim().slice(0, 200);
            if (nuevoRango) envolverTextos(nuevoRango, function () { var sp = document.createElement('span'); sp.className = 'rd-com'; sp.dataset.cid = id; return sp; });
            else { nuevoElemento.dataset.cid = id; nuevoElemento.classList.add('rd-com-el'); }
            comentarios.push({ id: id, autor: C.usuario, fecha: ahoraIso(), texto: txt, resuelto: false, cita: cita, respuestas: [] });
            nuevoRango = null; nuevoElemento = null; marcarSucio(); pintarRevision(); return;
        }
        var c = comentarios.filter(function (x) { return x.id === card.dataset.id; })[0]; if (!c) return;
        if (acc === 'responder') { var t = card.querySelector('.rd-resp-txt').value.trim(); if (!t) return; c.respuestas.push({ autor: C.usuario, fecha: ahoraIso(), texto: t }); }
        else if (acc === 'resolver') c.resuelto = !c.resuelto;
        else if (acc === 'borrar') { hoja.querySelectorAll('[data-cid="' + c.id + '"]').forEach(quitarMarca); comentarios = comentarios.filter(function (x) { return x !== c; }); }
        marcarSucio(); pintarRevision();
    });
    function irAComentario(id) {
        var el = hoja.querySelector('[data-cid="' + id + '"]'); if (!el) return;
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        hoja.querySelectorAll('[data-cid="' + id + '"]').forEach(function (n) { n.classList.add('rd-flash'); setTimeout(function () { n.classList.remove('rd-flash'); }, 1500); });
    }
    hoja.addEventListener('click', function (e) {
        var sp = e.target.closest && e.target.closest('[data-cid]');
        if (sp) { mostrarLateral(true, 'comentarios'); var card = lateral.querySelector('.rd-com-item[data-id="' + sp.dataset.cid + '"]'); if (card) { card.scrollIntoView({ block: 'nearest' }); card.classList.add('rd-flash'); setTimeout(function () { card.classList.remove('rd-flash'); }, 1500); } }
    });

    // Comentar con un clic en un párrafo, título, lista, imagen o celda
    var modoComentar = false, btnClic = $('rd-i-comentar-clic');
    function ponerModoComentar(v) {
        modoComentar = v; btnClic.classList.toggle('activo', v); hoja.classList.toggle('rd-modo-coment', v);
        if (v) estado('Haz clic en el párrafo, título, imagen o celda que quieras comentar (Esc para salir)');
        else estado(sucio ? 'Cambios sin guardar' : '');
    }
    btnClic.addEventListener('mousedown', function (e) { e.preventDefault(); });
    btnClic.addEventListener('click', function () { ponerModoComentar(!modoComentar); });
    hoja.addEventListener('click', function (e) {
        if (!modoComentar) return;
        var sel = window.getSelection(); if (sel && !sel.isCollapsed) return;
        var el = e.target.nodeType === 1 ? e.target : e.target.parentNode; if (!el || !el.closest) return;
        var t = el.tagName === 'IMG' || el.tagName === 'HR' ? el : el.closest('li,p,h1,h2,h3,h4,h5,h6,blockquote,pre,td,th');
        if (!t || !hoja.contains(t) || t.closest('.rd-indice')) return;
        if (t.dataset.cid) return;   // ya tiene comentario: el otro manejador abre el panel
        nuevoRango = null; nuevoElemento = t;
        mostrarLateral(true, 'comentarios');
        var ta = $('rd-com-nuevo-texto'); if (ta) ta.focus();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (modoComentar) ponerModoComentar(false);
        if (document.body.classList.contains('rd-enfoque')) ponerEnfoque(false);
    });

    // ---------- Control de cambios ----------
    var rastrear = !!C.rastrear;
    function pintarRastreo() { $('rd-i-rastrear').classList.toggle('activo', rastrear); hoja.classList.toggle('rd-rastreando', rastrear); }
    $('rd-i-rastrear').addEventListener('mousedown', function (e) { e.preventDefault(); });
    $('rd-i-rastrear').addEventListener('click', function () { rastrear = !rastrear; pintarRastreo(); marcarSucio(); estado(rastrear ? 'Control de cambios activado' : 'Control de cambios desactivado'); });
    function nuevoTid() { var m = 0; hoja.querySelectorAll('[data-tid]').forEach(function (e) { m = Math.max(m, +e.dataset.tid.slice(1) || 0); }); return 't' + (m + 1); }
    function marca(tipo, tid) { var e = document.createElement(tipo); e.className = tipo === 'ins' ? 'rd-ins' : 'rd-del'; e.dataset.tid = tid; e.dataset.autor = C.usuario; e.dataset.fecha = ahoraIso(); return e; }
    function propio(n, tipo) { var e = n.nodeType === 1 ? n : n.parentNode; e = e && e.closest ? e.closest(tipo) : null; return e && e.dataset.autor === C.usuario && hoja.contains(e) ? e : null; }
    function ponerCursor(nodo, pos) { var r = document.createRange(); r.setStart(nodo, pos); r.collapse(true); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    function ponerCursorDespues(el) { var r = document.createRange(); r.setStartAfter(el); r.collapse(true); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    function ponerCursorAntes(el) { var r = document.createRange(); r.setStartBefore(el); r.collapse(true); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    /** Marca como borrado lo que cubre el rango: lo escrito por uno mismo en un cambio se quita de verdad; lo ya borrado se respeta */
    function borrarRastreado(range) {
        var tid = nuevoTid();
        envolverTextos(range, function (t) {
            if (t.parentNode.closest && t.parentNode.closest('del')) return null;
            var mio = propio(t, 'ins');
            if (mio) { t.parentNode.removeChild(t); if (!mio.textContent) mio.remove(); return null; }
            return marca('del', tid);
        });
        var dels = hoja.querySelectorAll('del[data-tid="' + tid + '"]');
        return dels.length ? dels[dels.length - 1] : null;
    }
    function insertarRastreado(texto) {
        var s = seleccion(); if (!s) return;
        var r = s.getRangeAt(0);
        if (!r.collapsed) { var d = borrarRastreado(r); if (d) ponerCursorDespues(d); else if (rangoGuardado) { /* sin cambios */ } r = window.getSelection().getRangeAt(0); }
        var c = r.startContainer, off = r.startOffset, dentro = propio(c, 'ins');
        if (!dentro && c.nodeType === 1 && off > 0 && c.childNodes[off - 1] && c.childNodes[off - 1].nodeName === 'INS' && propio(c.childNodes[off - 1], 'ins')) { dentro = c.childNodes[off - 1]; ponerCursor(dentro, dentro.childNodes.length); r = window.getSelection().getRangeAt(0); }
        else if (!dentro && c.nodeType === 3 && off === 0 && c.previousSibling && c.previousSibling.nodeName === 'INS' && propio(c.previousSibling, 'ins')) { dentro = c.previousSibling; }
        var enDel = c.nodeType === 1 ? c.closest('del') : (c.parentNode && c.parentNode.closest ? c.parentNode.closest('del') : null);
        if (enDel) { ponerCursorDespues(enDel); r = window.getSelection().getRangeAt(0); dentro = null; }
        if (dentro) {
            var tn = document.createTextNode(texto);
            if (c.nodeType === 3 && dentro.contains(c)) { c.insertData(off, texto); ponerCursor(c, off + texto.length); }
            else { dentro.appendChild(tn); ponerCursor(tn, tn.length); }
        } else {
            var i = marca('ins', nuevoTid()), t = document.createTextNode(texto); i.appendChild(t); r.insertNode(i); ponerCursor(t, t.length);
        }
        marcarSucio();
    }
    hoja.addEventListener('beforeinput', function (e) {
        if (!rastrear) return;
        var t = e.inputType, s = seleccion(); if (!s) return;
        if (t === 'insertText' && e.data) { e.preventDefault(); insertarRastreado(e.data); return; }
        if (/^delete(Content|Word|Soft|Hard|ByCut)/.test(t) || t === 'deleteByCut') {
            var tr = e.getTargetRanges ? e.getTargetRanges() : [];
            var r = document.createRange();
            if (tr.length) { r.setStart(tr[0].startContainer, tr[0].startOffset); r.setEnd(tr[0].endContainer, tr[0].endOffset); }
            else { r = s.getRangeAt(0).cloneRange(); }
            if (r.collapsed || !hoja.contains(r.commonAncestorContainer)) return;   // unir párrafos: comportamiento normal
            e.preventDefault();
            var adelante = /Forward/.test(t), ant = r.startContainer, antOff = r.startOffset;
            var d = borrarRastreado(r);
            if (adelante && d) ponerCursorDespues(d); else if (d) { var dels = hoja.querySelectorAll('del[data-tid="' + d.dataset.tid + '"]'); ponerCursorAntes(dels[0]); }
            else if (hoja.contains(ant)) { try { ponerCursor(ant, Math.min(antOff, ant.nodeType === 3 ? ant.length : ant.childNodes.length)); } catch (x) { /* nodo ya movido */ } }
            marcarSucio();
        }
    });
    function nuevoTidActual() { var m = 0; hoja.querySelectorAll('[data-tid]').forEach(function (e) { m = Math.max(m, +e.dataset.tid.slice(1) || 0); }); return m; }
    function cambiosAgrupados() {
        var g = {}, orden = [];
        hoja.querySelectorAll('ins[data-tid],del[data-tid]').forEach(function (e) {
            var k = e.dataset.tid; if (!g[k]) { g[k] = { tid: k, tipo: e.tagName.toLowerCase(), autor: e.dataset.autor || '', fecha: e.dataset.fecha || '', texto: '', nodos: [] }; orden.push(k); }
            g[k].texto += e.textContent; g[k].nodos.push(e);
        });
        return orden.map(function (k) { return g[k]; });
    }
    function pintarCambios() {
        var lista = cambiosAgrupados(), h = '';
        if (!lista.length) h = '<p class="text-muted text-sm">No hay cambios pendientes. Activa «Cambios» para que lo que escribas o borres quede marcado.</p>';
        else h = '<div class="rd-com-acc"><button type="button" class="btn btn-sm btn-primary" data-cam="aceptar-todos">Aceptar todos</button><button type="button" class="btn btn-sm btn-ghost" data-cam="rechazar-todos">Rechazar todos</button></div>';
        lista.forEach(function (c) {
            h += '<div class="rd-cam-item" data-tid="' + c.tid + '"><p><span class="rd-cam-' + c.tipo + '">' + (c.tipo === 'ins' ? 'Inserción' : 'Eliminación') + '</span> <strong>' + esc(c.autor) + '</strong> <small class="text-muted">' + esc(fechaCorta(c.fecha)) + '</small></p><blockquote>' + esc(c.texto.slice(0, 160)) + '</blockquote>'
                + '<div class="rd-com-acc"><button type="button" class="btn btn-sm btn-ghost" data-cam="aceptar">✔ Aceptar</button><button type="button" class="btn btn-sm btn-ghost" data-cam="rechazar">✖ Rechazar</button></div></div>';
        });
        $('rd-lat-cambios').innerHTML = h;
    }
    function resolverCambio(tid, aceptar) {
        hoja.querySelectorAll('[data-tid="' + tid + '"]').forEach(function (n) {
            var esIns = n.tagName === 'INS';
            if (esIns === aceptar) { var p = n.parentNode; desenvolver(n); } else n.remove();
        });
    }
    $('rd-lat-cambios').addEventListener('click', function (e) {
        var b = e.target.closest('[data-cam]'), card = e.target.closest('.rd-cam-item');
        if (!b) { if (card) { var el = hoja.querySelector('[data-tid="' + card.dataset.tid + '"]'); if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } return; }
        var a = b.dataset.cam;
        if (a === 'aceptar-todos' || a === 'rechazar-todos') cambiosAgrupados().forEach(function (c) { resolverCambio(c.tid, a === 'aceptar-todos'); });
        else resolverCambio(card.dataset.tid, a === 'aceptar');
        marcarSucio(); pintarRevision();
    });
    hoja.addEventListener('click', function (e) {
        var c = e.target.closest && e.target.closest('ins[data-tid],del[data-tid]');
        if (c) { mostrarLateral(true, 'cambios'); }
    });

    // ---------- Ajuste de imágenes respecto al texto ----------
    var CL_IMG = ['rd-img-bloque', 'rd-img-izq', 'rd-img-der', 'rd-img-frente', 'rd-img-detras'];
    function zoomActual() { return +$('rd-zoom').value || 1; }
    function ajustarImagen(modo) {
        if (!imgSel) return;
        var img = imgSel, rI = img.getBoundingClientRect(), rH = hoja.getBoundingClientRect(), z = zoomActual();
        var x = Math.round((rI.left - rH.left) / z), y = Math.round((rI.top - rH.top) / z);
        CL_IMG.forEach(function (c) { img.classList.remove(c); });
        ['float', 'margin', 'position', 'left', 'top', 'z-index'].forEach(function (p) { img.style.removeProperty(p); });
        if (modo === 'bloque') img.classList.add('rd-img-bloque');
        else if (modo === 'izq') { img.classList.add('rd-img-izq'); img.style.cssText += ';float:left;margin:4px 12px 4px 0'; }
        else if (modo === 'der') { img.classList.add('rd-img-der'); img.style.cssText += ';float:right;margin:4px 0 4px 12px'; }
        else if (modo === 'frente' || modo === 'detras') {
            img.classList.add(modo === 'frente' ? 'rd-img-frente' : 'rd-img-detras');
            img.style.cssText += ';position:absolute;left:' + Math.max(0, x) + 'px;top:' + Math.max(0, y) + 'px;z-index:' + (modo === 'frente' ? 2 : -1);
        }
        marcarSucio(); sincronizarImagenOp();
    }
    document.querySelectorAll('[data-img-ajuste]').forEach(function (b) { b.addEventListener('click', function () { ajustarImagen(b.dataset.imgAjuste); }); });
    function esAbsoluta(img) { return img && (img.classList.contains('rd-img-frente') || img.classList.contains('rd-img-detras')); }
    function sincronizarImagenOp() {
        var abs = esAbsoluta(imgSel); $('rd-img-pos').hidden = !abs;
        if (imgSel) mostrarTamanoImagen();
        document.querySelectorAll('[data-img-ajuste]').forEach(function (b) {
            var m = b.dataset.imgAjuste, on = imgSel && (m === 'linea' ? !CL_IMG.some(function (c) { return imgSel.classList.contains(c); }) : imgSel.classList.contains('rd-img-' + m));
            b.classList.toggle('activo', !!on);
        });
        if (abs) { $('rd-img-x').value = parseInt(imgSel.style.left, 10) || 0; $('rd-img-y').value = parseInt(imgSel.style.top, 10) || 0; }
    }
    ['rd-img-x', 'rd-img-y'].forEach(function (id) {
        $(id).addEventListener('input', function () { if (!esAbsoluta(imgSel)) return; imgSel.style.left = (+$('rd-img-x').value || 0) + 'px'; imgSel.style.top = (+$('rd-img-y').value || 0) + 'px'; marcarSucio(); });
    });
    // Tamaño exacto: ancho y alto en px o cm, proporcional o libre, y porcentaje a elección
    var PX_UN = { px: 1, cm: CM, mm: CM / 10 };
    function aUnidad(px) { var u = $('rd-img-un').value; return u === 'px' ? Math.round(px) : Math.round(px / PX_UN[u] * 100) / 100; }
    function dePx(v) { return v * (PX_UN[$('rd-img-un').value] || 1); }
    function mostrarTamanoImagen() {
        if (!imgSel) return;
        var w = imgSel.offsetWidth, h = imgSel.offsetHeight;
        $('rd-img-w').value = aUnidad(w); $('rd-img-h').value = aUnidad(h);
        $('rd-img-pct').value = Math.round(w / anchoUtil() * 100);
    }
    function proporcion() { return imgSel && imgSel.naturalWidth ? imgSel.naturalHeight / imgSel.naturalWidth : (imgSel && imgSel.offsetWidth ? imgSel.offsetHeight / imgSel.offsetWidth : 1); }
    function ponerTamanoImagen(w, h) {
        var max = esAbsoluta(imgSel) ? 4000 : anchoUtil();
        w = Math.max(8, Math.min(max, w));
        imgSel.style.width = Math.round(w) + 'px';
        if (h && !$('rd-img-prop').checked) imgSel.style.height = Math.max(8, Math.min(4000, Math.round(h))) + 'px';
        else imgSel.style.height = '';
        imgSel.removeAttribute('height'); marcarSucio(); mostrarTamanoImagen();
    }
    $('rd-img-w').addEventListener('change', function () {
        if (!imgSel || !(+this.value > 0)) return;
        ponerTamanoImagen(dePx(+this.value), $('rd-img-prop').checked ? 0 : dePx(+$('rd-img-h').value));
    });
    $('rd-img-h').addEventListener('change', function () {
        if (!imgSel || !(+this.value > 0)) return;
        var h = dePx(+this.value);
        if ($('rd-img-prop').checked) ponerTamanoImagen(h / (proporcion() || 1), 0);
        else ponerTamanoImagen(dePx(+$('rd-img-w').value) || imgSel.offsetWidth, h);
    });
    $('rd-img-pct').addEventListener('change', function () {
        if (!imgSel || !(+this.value > 0)) return;
        imgSel.style.width = Math.round(anchoUtil() * Math.min(100, +this.value) / 100) + 'px'; imgSel.style.height = ''; imgSel.removeAttribute('height'); marcarSucio(); mostrarTamanoImagen();
    });
    $('rd-img-un').addEventListener('change', mostrarTamanoImagen);
    $('rd-img-orig').addEventListener('click', function () {
        if (!imgSel) return;
        ponerTamanoImagen(imgSel.naturalWidth || imgSel.offsetWidth, 0);
    });
    // Arrastrar una imagen delante del texto
    var arrastre = null;
    hoja.addEventListener('mousedown', function (e) {
        if (e.target.tagName !== 'IMG' || !esAbsoluta(e.target)) return;
        e.preventDefault(); imgSel = e.target;
        arrastre = { x: e.clientX, y: e.clientY, l: parseInt(imgSel.style.left, 10) || 0, t: parseInt(imgSel.style.top, 10) || 0 };
    });
    document.addEventListener('mousemove', function (e) {
        if (!arrastre) return;
        var z = zoomActual();
        imgSel.style.left = Math.max(0, Math.round(arrastre.l + (e.clientX - arrastre.x) / z)) + 'px';
        imgSel.style.top = Math.max(0, Math.round(arrastre.t + (e.clientY - arrastre.y) / z)) + 'px';
        sincronizarImagenOp();
    });
    document.addEventListener('mouseup', function () { if (arrastre) { arrastre = null; marcarSucio(); } });
    // Lista de imágenes (para elegir una que quedó detrás del texto)
    function llenarListaImagenes() {
        var sel = $('rd-img-lista'), h = '<option value="">— ninguna —</option>';
        hoja.querySelectorAll('img').forEach(function (im, i) { var m = CL_IMG.filter(function (c) { return im.classList.contains(c); })[0]; h += '<option value="' + i + '">Imagen ' + (i + 1) + (m ? ' · ' + ({ 'rd-img-bloque': 'arriba y abajo', 'rd-img-izq': 'izquierda', 'rd-img-der': 'derecha', 'rd-img-frente': 'delante', 'rd-img-detras': 'detrás' })[m] : '') + '</option>'; });
        sel.innerHTML = h;
    }
    $('rd-i-imagen').addEventListener('click', llenarListaImagenes);
    $('rd-img-lista').addEventListener('change', function () {
        var im = hoja.querySelectorAll('img')[+this.value]; if (!im || this.value === '') return;
        if (imgSel) imgSel.classList.remove('rd-sel');
        imgSel = im; im.classList.add('rd-sel'); $('rd-imagen-op').hidden = false; sincronizarImagenOp(); im.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });

    // ---------- Firmas ----------
    var sellos = (C.sellos || []).slice(), firmas = (C.firmas || []).slice();
    $('rd-i-firma').addEventListener('mousedown', function (e) { e.preventDefault(); });
    $('rd-i-firma').addEventListener('click', function () { alternar('rd-p-firma'); pintarFirmas(); pintarSellos(); });
    $('rd-firma-nombre').value = C.usuario || '';
    function pintarFirmas() {
        var l = $('rd-firmas-lista');
        l.innerHTML = firmas.length ? firmas.map(function (f) { return '<span class="rd-firma-item"><button type="button" data-firma="' + f.id + '" title="Insertar esta firma"><img src="' + esc(f.src) + '" alt="' + esc(f.nombre) + '"><small>' + esc(f.nombre) + '</small></button><button type="button" class="btn-icono" data-firma-borrar="' + f.id + '" title="Eliminar firma" aria-label="Eliminar firma"><i class="fa-solid fa-trash-can"></i></button></span>'; }).join('') : '<span class="text-sm text-muted">Aún no tienes firmas guardadas.</span>';
    }
    $('rd-firmas-lista').addEventListener('click', async function (e) {
        var b = e.target.closest('[data-firma]'), d = e.target.closest('[data-firma-borrar]');
        if (b) {
            var f = firmas.filter(function (x) { return String(x.id) === b.dataset.firma; })[0]; if (!f) return;
            var nom = $('rd-firma-nombre').value.trim(), cargo = $('rd-firma-cargo').value.trim(), hoy = new Date().toLocaleDateString('es-MX');
            insertarEnRaiz('<table style="width:45%;border-collapse:collapse"><tbody><tr><td style="border:0;text-align:center"><p style="text-align:center"><img src="' + esc(f.src) + '" alt="Firma" style="width:180px"></p><p style="text-align:center">______________________________</p><p style="text-align:center"><strong>' + esc(nom) + '</strong>' + (cargo ? '<br>' + esc(cargo) : '') + '<br>' + esc(hoy) + '</p></td></tr></tbody></table><p><br></p>');
        } else if (d) {
            if (!await window.confirmarModal({ titulo: '¿Eliminar la firma?', mensaje: 'Se borrará esta firma guardada.', aceptar: 'Eliminar' })) return;
            var fd = new FormData(); fd.append('id', C.id); fd.append('firma', d.dataset.firmaBorrar);
            fetch(C.api.firmaBorrar, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () { firmas = firmas.filter(function (x) { return String(x.id) !== d.dataset.firmaBorrar; }); pintarFirmas(); });
        }
    });
    $('rd-firma-bloque').addEventListener('click', function () {
        var n = +$('rd-firma-n').value || 2, h = '<table style="width:100%;border-collapse:collapse"><tbody><tr>';
        for (var i = 0; i < n; i++) h += '<td style="border:0;text-align:center"><p><br></p><p><br></p><p style="text-align:center">______________________________</p><p style="text-align:center"><strong>Nombre</strong><br>Cargo</p></td>';
        insertarEnRaiz(h + '</tr></tbody></table><p><br></p>');
    });
    // Dibujar o escribir una firma
    var dlgF = $('rd-dlg-firma'), cv = $('rd-firma-canvas'), cx = cv.getContext('2d'), dibujando = false, hayTrazo = false, modoF = 'dibujar';
    function limpiarLienzo() { cx.clearRect(0, 0, cv.width, cv.height); hayTrazo = false; var v = $('rd-firma-vista').getContext('2d'); v.clearRect(0, 0, 560, 200); }
    function posLienzo(e) { var r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; }
    cx.lineWidth = 3; cx.lineCap = 'round'; cx.lineJoin = 'round'; cx.strokeStyle = '#111827';
    cv.addEventListener('pointerdown', function (e) { dibujando = true; cv.setPointerCapture(e.pointerId); var p = posLienzo(e); cx.beginPath(); cx.moveTo(p[0], p[1]); cx.lineTo(p[0] + 0.1, p[1] + 0.1); cx.stroke(); hayTrazo = true; });
    cv.addEventListener('pointermove', function (e) { if (!dibujando) return; var p = posLienzo(e); cx.lineTo(p[0], p[1]); cx.stroke(); });
    ['pointerup', 'pointercancel'].forEach(function (ev) { cv.addEventListener(ev, function () { dibujando = false; }); });
    $('rd-firma-nueva').addEventListener('click', function () { limpiarLienzo(); $('rd-firma-error').hidden = true; dlgF.showModal(); });
    $('rd-firma-cancelar').addEventListener('click', function () { dlgF.close(); });
    $('rd-firma-limpiar').addEventListener('click', limpiarLienzo);
    document.querySelectorAll('[data-fp]').forEach(function (b) {
        b.addEventListener('click', function () { modoF = b.dataset.fp; document.querySelectorAll('[data-fp]').forEach(function (x) { x.classList.toggle('activo', x === b); }); $('rd-fp-dibujar').hidden = modoF !== 'dibujar'; $('rd-fp-escribir').hidden = modoF !== 'escribir'; });
    });
    $('rd-firma-texto').addEventListener('input', function () {
        var v = $('rd-firma-vista').getContext('2d'); v.clearRect(0, 0, 560, 200);
        v.fillStyle = '#111827'; v.font = 'italic 64px "Brush Script MT","Segoe Script","Lucida Handwriting",cursive'; v.textBaseline = 'middle'; v.fillText(this.value, 20, 100, 520);
    });
    $('rd-firma-guardar').addEventListener('click', function () {
        var er = $('rd-firma-error'), fuente = modoF === 'dibujar' ? cv : $('rd-firma-vista');
        if (modoF === 'dibujar' ? !hayTrazo : !$('rd-firma-texto').value.trim()) { er.textContent = 'Dibuja o escribe tu firma primero'; er.hidden = false; return; }
        var fd = new FormData(); fd.append('id', C.id); fd.append('nombre', $('rd-firma-etq').value); fd.append('png', recortar(fuente));
        fetch(C.api.firma, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) { er.textContent = d.error || 'No se pudo guardar'; er.hidden = false; return; }
            firmas.unshift(d.firma); pintarFirmas(); dlgF.close();
        }).catch(function () { er.textContent = 'Sin conexión'; er.hidden = false; });
    });
    /** PNG de la firma recortado a su contenido (fondo transparente) */
    function recortar(c) {
        var g = c.getContext('2d'), w = c.width, h = c.height, d = g.getImageData(0, 0, w, h).data, x0 = w, y0 = h, x1 = 0, y1 = 0;
        for (var y = 0; y < h; y++) for (var x = 0; x < w; x++) if (d[(y * w + x) * 4 + 3] > 8) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
        if (x1 <= x0) return c.toDataURL('image/png');
        var p = 6, o = document.createElement('canvas'); o.width = x1 - x0 + 2 * p; o.height = y1 - y0 + 2 * p;
        o.getContext('2d').drawImage(c, x0, y0, x1 - x0, y1 - y0, p, p, x1 - x0, y1 - y0);
        return o.toDataURL('image/png');
    }
    // Firma electrónica
    function pintarSellos() {
        $('rd-sellos').innerHTML = sellos.map(function (f) { return '<div class="rd-sello ' + (f.valido ? 'ok' : 'mal') + '"><strong>' + esc(f.nombre) + '</strong>' + (f.cargo ? ' · ' + esc(f.cargo) : '') + '<br><small>' + esc(fechaCorta(String(f.firmado).replace(' ', 'T'))) + ' · ' + (f.valido ? '✔ íntegro' : '⚠ modificado después de firmar') + '</small></div>'; }).join('');
    }
    $('rd-sello-ok').addEventListener('click', function () {
        var pass = $('rd-sello-pass').value; if (!pass) { estado('Escribe tu contraseña para firmar', true); return; }
        var enviar = function () {
            var fd = new FormData(); fd.append('id', C.id); fd.append('cargo', $('rd-sello-cargo').value); fd.append('password', pass);
            fetch(C.api.sellar, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d.success) { estado(d.error || 'No se pudo firmar', true); return; }
                sellos = d.sellos; pintarSellos(); $('rd-sello-pass').value = ''; estado('Documento firmado');
            }).catch(function () { estado('Sin conexión', true); });
        };
        if (sucio) guardar(false, enviar); else enviar();
    });
    // Guardar como plantilla
    $('rd-i-plantilla').addEventListener('mousedown', function (e) { e.preventDefault(); });
    $('rd-i-plantilla').addEventListener('click', function () { alternar('rd-p-plantilla'); $('rd-pl-nombre').focus(); });
    $('rd-pl-ok').addEventListener('click', function () {
        var nom = $('rd-pl-nombre').value.trim(); if (!nom) { estado('Escribe un nombre para la plantilla', true); return; }
        var enviar = function () {
            var fd = new FormData(); fd.append('id', C.id); fd.append('nombre', nom); fd.append('descripcion', $('rd-pl-desc').value);
            fetch(C.api.plantilla, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d.success) { estado(d.error || 'No se pudo guardar la plantilla', true); return; }
                $('rd-p-plantilla').hidden = true; $('rd-pl-nombre').value = ''; $('rd-pl-desc').value = ''; estado('Plantilla guardada: aparece al crear un documento');
            });
        };
        if (sucio) guardar(false, enviar); else enviar();
    });


    // ---------- Pestañas de la barra ----------
    function mostrarPestana(p) {
        PANELES.forEach(function (id) { var e = $(id); if (e) e.hidden = true; });
        var fl = $('rd-imagen-op'); if (fl) fl.hidden = true;
        document.querySelectorAll('#rd-pestanas [data-pest]').forEach(function (b) { b.classList.toggle('activo', b.dataset.pest === p); });
        document.querySelectorAll('#rd-barra > .rd-grupo-b[data-pest]').forEach(function (g) { g.classList.toggle('rd-oculto', g.dataset.pest !== p); });
        try { localStorage.setItem('rd-pestana', p); } catch (e) { /* sin almacenamiento */ }
    }
    document.querySelectorAll('#rd-pestanas [data-pest]').forEach(function (b) { b.addEventListener('click', function () { mostrarPestana(b.dataset.pest); }); });
    (function () { var p = 'inicio'; try { p = localStorage.getItem('rd-pestana') || 'inicio'; } catch (e) { /* sin almacenamiento */ } if (!document.querySelector('#rd-pestanas [data-pest="' + p + '"]')) p = 'inicio'; mostrarPestana(p); })();

    // ---------- Herramientas adicionales ----------
    function textoSeleccion() { var s = seleccion(); return s && !s.isCollapsed ? s.toString() : ''; }
    function mapearSeleccion(fn) {
        var s = seleccion();
        if (!s || s.isCollapsed) { estado('Selecciona el texto primero', true); return; }
        envolverTextos(s.getRangeAt(0).cloneRange(), function (t) { t.nodeValue = fn(t.nodeValue); return null; });
        marcarSucio();
    }
    function primerTexto(b) { var it = document.createTreeWalker(b, NodeFilter.SHOW_TEXT), n; while ((n = it.nextNode())) if (n.nodeValue !== '') return n; return null; }
    var NOTAS = {
        info: ['#0284c7', '#e0f2fe', 'Información'], aviso: ['#d97706', '#fef3c7', 'Atención'],
        peligro: ['#dc2626', '#fee2e2', 'Peligro'], ok: ['#16a34a', '#dcfce7', 'Correcto']
    };
    var ETIQUETAS = {
        aprobado: ['#dcfce7', '#166534', 'Aprobado'], pendiente: ['#fef3c7', '#92400e', 'Pendiente'],
        rechazado: ['#fee2e2', '#991b1b', 'Rechazado'], revision: ['#dbeafe', '#1e40af', 'En revisión']
    };
    var EJEMPLO = ['<p>El programa de obra describe la secuencia de actividades, sus duraciones y los recursos necesarios para terminar el proyecto dentro del plazo contractual. Cada partida agrupa actividades afines y se revisa semanalmente con el residente.</p>',
        '<p>Las restricciones que impidan iniciar una actividad deben registrarse y liberarse antes de la fecha comprometida; de lo contrario, la ruta crítica puede verse afectada y es necesario replanificar el trabajo de las siguientes semanas.</p>'];
    function aplicarBloques(fn) {
        restaurarSeleccion();
        var bl = bloquesDeSeleccion();
        if (!bl.length) { estado('Coloca el cursor en un párrafo', true); return; }
        bl.forEach(fn); marcarSucio();
    }
    var HERR = {
        mayus: function () { mapearSeleccion(function (t) { return t.toUpperCase(); }); },
        minus: function () { mapearSeleccion(function (t) { return t.toLowerCase(); }); },
        capit: function () { mapearSeleccion(function (t) { return t.toLowerCase().replace(/(^|[\s(\["“¿¡\/-])(\p{L})/gu, function (m, a, b) { return a + b.toUpperCase(); }); }); },
        codigo: function () {
            var t = textoSeleccion(); if (!t) { estado('Selecciona el texto primero', true); return; }
            insertarHtml('<code style="font-family:Courier New;background-color:#f1f5f9">' + esc(t) + '</code>');
        },
        fecha: function () { restaurarSeleccion(); exec('insertText', new Date().toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric' })); },
        hora: function () { restaurarSeleccion(); exec('insertText', new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })); },
        checklist: function () {
            aplicarBloques(function (b) {
                var t = primerTexto(b);
                if (t && /^[☐☑] /.test(t.nodeValue)) t.nodeValue = (t.nodeValue.charAt(0) === '☐' ? '☑' : '☐') + t.nodeValue.slice(1);
                else if (t) t.nodeValue = '☐ ' + t.nodeValue;
                else b.insertBefore(document.createTextNode('☐ '), b.firstChild);
            });
        },
        sangria: function () { aplicarBloques(function (b) { if (/^(P|BLOCKQUOTE|DIV)$/.test(b.tagName)) b.style.textIndent = b.style.textIndent ? '' : '1.27cm'; }); },
        ejemplo: function () { insertarHtml(EJEMPLO.join('')); },
        espacios: function () {
            var n = 0, fix = function (t) { return t.replace(/[  ]{2,}/g, function () { n++; return ' '; }); };
            if (textoSeleccion()) mapearSeleccion(fix);
            else {
                var it = document.createTreeWalker(hoja, NodeFilter.SHOW_TEXT), nodo, lista = [];
                while ((nodo = it.nextNode())) if (!(nodo.parentNode.closest && nodo.parentNode.closest('pre'))) lista.push(nodo);
                lista.forEach(function (x) { x.nodeValue = fix(x.nodeValue); });
                if (n) marcarSucio();
            }
            estado(n ? n + ' espacio' + (n === 1 ? '' : 's') + ' doble' + (n === 1 ? '' : 's') + ' corregido' + (n === 1 ? '' : 's') : 'No había espacios dobles');
        },
        ordenar: function () {
            restaurarSeleccion();
            var bl = bloquesDeSeleccion().filter(function (b) { return /^(P|LI|H[1-6])$/.test(b.tagName); });
            if (bl.length < 2) { estado('Selecciona al menos dos párrafos o elementos de lista', true); return; }
            var padre = bl[0].parentNode;
            if (bl.some(function (b) { return b.parentNode !== padre; })) { estado('Los elementos deben estar al mismo nivel', true); return; }
            var cmp = function (a, b) { return a.textContent.trim().localeCompare(b.textContent.trim(), 'es', { sensitivity: 'base' }); };
            var asc = bl.slice().sort(cmp), ya = asc.every(function (b, i) { return b === bl[i]; }), orden = ya ? asc.reverse() : asc;
            var ancla = bl[bl.length - 1].nextSibling;
            orden.forEach(function (b) { padre.insertBefore(b, ancla); });
            marcarSucio(); estado(ya ? 'Ordenado de Z a A' : 'Ordenado de A a Z');
        },
        estadisticas: function () {
            var p = $('rd-p-stats'), abrir = p.hidden;
            alternar('rd-p-stats'); if (!abrir) return;
            var t = hoja.innerText || '', pal = contarPalabras(), parr = 0;
            hoja.querySelectorAll('p,li,h1,h2,h3,h4,h5,h6,blockquote,pre').forEach(function (b) { if (b.textContent.trim()) parr++; });
            var datos = [[pal, 'palabras'], [t.replace(/\n/g, '').length, 'caracteres'], [t.replace(/\s/g, '').length, 'caracteres sin espacios'], [parr, 'párrafos'],
                [(t.match(/[^.!?\n]+[.!?]+/g) || []).length, 'oraciones'], [Math.max(1, Math.round(parseFloat(hoja.style.minHeight) / medidas.h)), 'páginas'],
                [hoja.querySelectorAll('img').length, 'imágenes'], [hoja.querySelectorAll('table').length, 'tablas'], [Math.max(1, Math.ceil(pal / 200)) + ' min', 'de lectura']];
            $('rd-stats').innerHTML = datos.map(function (d) { return '<span class="rd-stat"><b>' + d[0] + '</b> ' + d[1] + '</span>'; }).join('');
        }
    };
    document.querySelectorAll('[data-herr]').forEach(function (b) {
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () { if (HERR[b.dataset.herr]) HERR[b.dataset.herr](); });
    });
    $('rd-h-simbolo').addEventListener('change', function () { var v = this.value; this.value = ''; if (v) { restaurarSeleccion(); exec('insertText', v); } });
    $('rd-h-nota').addEventListener('change', function () {
        var n = NOTAS[this.value]; this.value = ''; if (!n) return;
        insertarEnRaiz('<table style="width:100%;border-collapse:collapse"><tbody><tr><td style="border:2px solid ' + n[0] + ';background-color:' + n[1] + ';padding:6px"><p><strong>' + n[2] + ':</strong> Escribe aquí el texto.</p></td></tr></tbody></table><p><br></p>');
    });
    $('rd-h-columnas').addEventListener('change', function () {
        var c = +this.value; this.value = ''; if (!c) return;
        var w = (100 / c).toFixed(1) + '%', h = '<table style="width:100%;border-collapse:collapse"><tbody><tr>';
        for (var i = 0; i < c; i++) h += '<td style="border:0;vertical-align:top;width:' + w + '"><p><br></p></td>';
        insertarEnRaiz(h + '</tr></tbody></table><p><br></p>');
    });
    $('rd-h-estado').addEventListener('change', function () {
        var e = ETIQUETAS[this.value]; this.value = ''; if (!e) return;
        insertarHtml('<span style="background-color:' + e[0] + ';color:' + e[1] + ';font-weight:bold">&nbsp;' + e[2] + '&nbsp;</span>&nbsp;');
    });
    $('rd-h-espacio').addEventListener('change', function () { var v = this.value; this.value = ''; if (v === '') return; aplicarBloques(function (b) { b.style.marginBottom = v + 'pt'; }); });
    $('rd-h-sombra').addEventListener('input', function () { var v = this.value; aplicarBloques(function (b) { b.style.backgroundColor = v.toLowerCase() === '#ffffff' ? '' : v; }); });
    function ponerEnfoque(v) { document.body.classList.toggle('rd-enfoque', v); $('rd-h-enfoque').classList.toggle('activo', v); }
    $('rd-h-enfoque').addEventListener('click', function () { ponerEnfoque(!document.body.classList.contains('rd-enfoque')); });
    $('rd-h-ancho').addEventListener('click', function () {
        if (!medidas) return;
        var z = Math.max(0.3, Math.min(2, (fondo.clientWidth - 40) / medidas.w)), sel = $('rd-zoom'), op = sel.querySelector('option[data-ajuste]');
        if (!op) { op = document.createElement('option'); op.dataset.ajuste = '1'; sel.appendChild(op); }
        op.value = z.toFixed(3); op.textContent = Math.round(z * 100) + '% (ajustado)'; sel.value = op.value; pagina.style.zoom = op.value;
    });

    // ---------- Navegador de páginas ----------
    var tMini = null;
    function planificarMiniaturas() { clearTimeout(tMini); tMini = setTimeout(pintarPaginas, 700); }
    /** Bloques raíz agrupados por la página donde empiezan */
    function gruposPaginas() {
        if (!medidas) return [];
        var n = Math.max(1, Math.round(parseFloat(hoja.style.minHeight) / medidas.h)), g = [], i;
        for (i = 0; i < n; i++) g.push([]);
        Array.prototype.forEach.call(hoja.children, function (el) { g[Math.min(n - 1, Math.max(0, Math.floor(el.offsetTop / medidas.h)))].push(el); });
        return g;
    }
    function pintarPaginas() {
        var cont = $('rd-pg-lista'); if (!medidas || !cont) return;
        var g = gruposPaginas(), esc1 = 118 / medidas.w, antes = $('rd-paginas').scrollTop;
        $('rd-pg-n').textContent = g.length;
        cont.innerHTML = '';
        g.forEach(function (els, i) {
            var caja = document.createElement('div'); caja.className = 'rd-pg'; caja.dataset.i = i;
            var vista = document.createElement('div'); vista.className = 'rd-pg-vista'; vista.style.width = Math.round(medidas.w * esc1) + 'px'; vista.style.height = Math.round(medidas.h * esc1) + 'px';
            var mini = document.createElement('div'); mini.className = 'rd-hoja rd-mini';
            mini.style.cssText = 'width:' + medidas.w + 'px;height:' + medidas.h + 'px;padding:' + hoja.style.padding + ';transform:scale(' + esc1 + ')';
            els.forEach(function (el) {
                var c = el.cloneNode(true); c.removeAttribute('id');
                c.querySelectorAll('[id]').forEach(function (x) { x.removeAttribute('id'); });
                c.querySelectorAll('.rd-sel').forEach(function (x) { x.classList.remove('rd-sel'); });
                mini.appendChild(c);
            });
            vista.appendChild(mini);
            var vacia = !els.length;
            caja.appendChild(vista);
            var barra = document.createElement('div'); barra.className = 'rd-pg-acc';
            barra.innerHTML = '<span>' + (i + 1) + '</span>'
                + '<button type="button" data-pg-acc="sube" title="Subir esta página"' + (i === 0 || vacia || !g[i - 1].length ? ' disabled' : '') + '><i class="fa-solid fa-arrow-up"></i></button>'
                + '<button type="button" data-pg-acc="baja" title="Bajar esta página"' + (i === g.length - 1 || vacia || !g[i + 1].length ? ' disabled' : '') + '><i class="fa-solid fa-arrow-down"></i></button>'
                + '<button type="button" data-pg-acc="borra" title="Eliminar esta página"' + (vacia ? ' disabled' : '') + '><i class="fa-solid fa-trash-can"></i></button>';
            caja.appendChild(barra); cont.appendChild(caja);
        });
        $('rd-paginas').scrollTop = antes;
    }
    $('rd-pg-nueva').addEventListener('click', function () {
        hoja.insertAdjacentHTML('beforeend', '<div class="salto-pagina" style="page-break-after:always"></div><p><br></p>');
        marcarSucio(); dibujarGuias();
        var ult = hoja.lastElementChild; hoja.focus();
        var r = document.createRange(); r.selectNodeContents(ult); r.collapse(true); var sel = seleccion(); if (sel) { sel.removeAllRanges(); sel.addRange(r); }
        ult.scrollIntoView({ block: 'center', behavior: 'smooth' });
        estado('Página agregada al final');
    });
    $('rd-pg-lista').addEventListener('click', async function (e) {
        var caja = e.target.closest('.rd-pg'); if (!caja) return;
        var i = +caja.dataset.i, g = gruposPaginas(), b = e.target.closest('[data-pg-acc]');
        if (!b) {
            // El editor tiene altura fija: solo se mueve su propio scroll hasta la página elegida
            var yHoja = g[i] && g[i].length ? g[i][0].getBoundingClientRect().top : hoja.getBoundingClientRect().top + i * medidas.h * zoomActual();
            fondo.scrollTo({ top: Math.max(0, fondo.scrollTop + yHoja - fondo.getBoundingClientRect().top - 20), behavior: 'smooth' });
            return;
        }
        if (b.disabled || !g[i]) return;
        var acc = b.dataset.pgAcc;
        if (acc === 'sube') { var ref = g[i - 1][0]; g[i].forEach(function (el) { hoja.insertBefore(el, ref); }); }
        else if (acc === 'baja') { var ref2 = g[i + 1][g[i + 1].length - 1].nextSibling; g[i].forEach(function (el) { hoja.insertBefore(el, ref2); }); }
        else if (acc === 'borra') {
            if (!await window.confirmarModal({ titulo: '¿Eliminar la página ' + (i + 1) + '?', mensaje: 'Se borrará la página con su contenido.', aceptar: 'Eliminar' })) return;
            g[i].forEach(function (el) { el.remove(); });
            if (!hoja.children.length) hoja.innerHTML = '<p><br></p>';
        }
        marcarSucio(); dibujarGuias();
        estado(acc === 'borra' ? 'Página eliminada' : 'Página movida: el contenido se vuelve a repartir entre las hojas');
    });

    // ---------- API para redactor-extras.js ----------
    window.RD = {
        C: C, $: $, hoja: hoja, pagina: pagina, fondo: fondo, esc: esc, estado: estado, marcarSucio: marcarSucio, seleccion: seleccion, restaurarSeleccion: restaurarSeleccion,
        exec: exec, insertarHtml: insertarHtml, insertarEnRaiz: insertarEnRaiz, alternar: alternar, paneles: PANELES, bloqueActual: bloqueActual, bloquesDeSeleccion: bloquesDeSeleccion,
        celdaActual: celdaActual, aplicarBloques: aplicarBloques, textoSeleccion: textoSeleccion, dibujarGuias: dibujarGuias, gruposPaginas: gruposPaginas,
        medidas: function () { return medidas; }, zoom: zoomActual, guardar: guardar, sucio: function () { return sucio; }, ponerEtiquetaVersion: function (t) { etiquetaVersion = t; },
        mostrarPestana: mostrarPestana, subirImagen: subirImagen, ponerImagen: ponerImagen, ahoraIso: ahoraIso
    };
    document.dispatchEvent(new Event('rd-listo'));

    // ---------- Inicio ----------
    aplicarPagina();
    pintarRastreo();
    mostrarLateral(true);
    prepararCorrector();
    actualizarEstadoBarra();
    setTimeout(dibujarGuias, 400);   // tras cargar imágenes
    hoja.querySelectorAll('img').forEach(function (i) { i.addEventListener('load', planificarDiseno); });
})();
