/* Redactor: herramientas adicionales (notas al pie, leyendas, ecuaciones, portada, bloques de obra, tablas, versiones, regla…). Usa la API window.RD de redactor.js */
(function () {
    'use strict';
    var R = window.RD;
    if (!R) return;
    var $ = R.$, hoja = R.hoja, esc = R.esc, estado = R.estado, C = R.C;
    ['rd-p-refcruz', 'rd-p-ecuacion', 'rd-p-caja', 'rd-p-portada', 'rd-p-programa', 'rd-p-punch', 'rd-p-estilos', 'rd-p-encpie', 'rd-p-ira', 'rd-p-versiones'].forEach(function (id) { R.paneles.push(id); });

    function boton(id, fn) { var b = $(id); if (!b) return; b.addEventListener('mousedown', function (e) { e.preventDefault(); }); b.addEventListener('click', fn); }
    function panel(btn, id, alAbrir) { boton(btn, function () { R.alternar(id); if (!$(id).hidden && alAbrir) alAbrir(); }); }
    function seleccionar(sel, fn) { var e = $(sel); if (e) e.addEventListener('change', function () { var v = this.value; this.value = ''; if (v) fn(v); }); }
    function post(url, datos) {
        var fd = new FormData(); fd.append('id', C.id);
        Object.keys(datos || {}).forEach(function (k) { fd.append(k, datos[k]); });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function fechaLarga(iso) { var d = iso ? new Date(iso + 'T12:00:00') : new Date(); return isNaN(d) ? '' : d.toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric' }); }
    function fechaCorta(iso) { var d = new Date(String(iso).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }); }
    /** Inserta un bloque en la raíz y devuelve el elemento nuevo (el que lleva data-rd-nuevo) */
    function insertarRaiz(html) {
        R.insertarEnRaiz(html);
        var n = hoja.querySelector('[data-rd-nuevo]');
        if (n) n.removeAttribute('data-rd-nuevo');
        return n;
    }
    function ponerCursor(nodo, desde, hasta) {
        var r = document.createRange();
        if (nodo.nodeType === 3) { r.setStart(nodo, desde || 0); r.setEnd(nodo, hasta === undefined ? nodo.nodeValue.length : hasta); } else r.selectNodeContents(nodo);
        hoja.focus(); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
    }

    // ================= Notas al pie, leyendas y referencias cruzadas =================
    function contenedorNotas(crear) {
        var c = hoja.querySelector('.rd-notas');
        if (!c && crear) { hoja.insertAdjacentHTML('beforeend', '<div class="rd-notas"><hr><p><strong>Notas</strong></p></div>'); c = hoja.querySelector('.rd-notas'); }
        return c;
    }
    function listaNotas() {
        var c = contenedorNotas(false); if (!c) return [];
        return Array.prototype.filter.call(c.children, function (e) { return e.tagName === 'P' && e.firstElementChild && /^\d+\.$/.test(e.firstElementChild.textContent.trim()); });
    }
    function renumerarNotas() {
        var refs = hoja.querySelectorAll('sup.rd-nota-ref'), notas = listaNotas();
        refs.forEach(function (r, i) { r.textContent = i + 1; });
        notas.forEach(function (p, i) { p.firstElementChild.textContent = (i + 1) + '.'; });
        return refs.length === notas.length;
    }
    function renumerarLeyendas() {
        var cuenta = {};
        hoja.querySelectorAll('p.rd-leyenda').forEach(function (p) {
            var st = p.querySelector('strong'); if (!st) return;
            var m = /^(Tabla|Figura)/.exec(st.textContent.trim()); if (!m) return;
            cuenta[m[1]] = (cuenta[m[1]] || 0) + 1;
            st.textContent = m[1] + ' ' + cuenta[m[1]] + '.';
        });
    }
    boton('rd-x-nota', function () {
        R.restaurarSeleccion();
        var sel = R.seleccion();
        if (!sel) { estado('Coloca el cursor en el texto donde va la nota', true); return; }
        // execCommand('insertHTML') convertiría <sup> en un span sin clase: se inserta el nodo directamente
        var ref = document.createElement('sup'); ref.className = 'rd-nota-ref'; ref.textContent = '0';
        var rg = sel.getRangeAt(0); rg.collapse(false); rg.insertNode(ref);
        rg.setStartAfter(ref); rg.collapse(true); sel.removeAllRanges(); sel.addRange(rg);
        var idx = Array.prototype.indexOf.call(hoja.querySelectorAll('sup.rd-nota-ref'), ref), cont = contenedorNotas(true), notas = listaNotas();
        var p = document.createElement('p'); p.innerHTML = '<strong>0.</strong> Escribe la nota';
        if (idx < notas.length) cont.insertBefore(p, notas[idx]); else cont.appendChild(p);
        renumerarNotas(); R.marcarSucio();
        ponerCursor(p.lastChild, 1);
        p.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });
    seleccionar('rd-x-leyenda', function (tipo) {
        R.restaurarSeleccion();
        var p = insertarRaiz('<p class="rd-leyenda" data-rd-nuevo="1" style="text-align:center"><strong>' + tipo + ' 0.</strong> Descripción</p>');
        if (!p) return;
        renumerarLeyendas(); R.marcarSucio();
        ponerCursor(p.lastChild, 1);
    });
    boton('rd-x-leyendas-act', function () {
        renumerarLeyendas();
        var ok = renumerarNotas(); R.marcarSucio();
        estado(ok ? 'Leyendas y notas renumeradas' : 'Leyendas renumeradas; hay notas sin llamada (o llamadas sin nota): revísalas', !ok);
    });
    var destinosRef = [];
    panel('rd-x-refcruz', 'rd-p-refcruz', function () {
        destinosRef = [];
        hoja.querySelectorAll('h1,h2,h3,p.rd-leyenda').forEach(function (e) {
            var t = e.textContent.replace(/\s+/g, ' ').trim(); if (!t) return;
            if (e.tagName === 'P') { var st = e.querySelector('strong'); if (st) destinosRef.push({ etq: t.slice(0, 70), texto: st.textContent.replace(/\.$/, '') }); }
            else destinosRef.push({ etq: (e.tagName === 'H1' ? '' : e.tagName === 'H2' ? '   ' : '      ') + t.slice(0, 70), texto: '«' + t.slice(0, 120) + '»' });
        });
        $('rd-x-ref-dest').innerHTML = destinosRef.length ? destinosRef.map(function (d, i) { return '<option value="' + i + '">' + esc(d.etq) + '</option>'; }).join('') : '<option value="">(no hay títulos ni leyendas)</option>';
    });
    boton('rd-x-ref-ok', function () {
        var d = destinosRef[+$('rd-x-ref-dest').value]; if (!d) { estado('No hay destino para la referencia', true); return; }
        R.restaurarSeleccion();
        R.exec('insertText', ($('rd-x-ref-como').value === 'ver' ? 'véase ' : '') + d.texto);
        $('rd-p-refcruz').hidden = true;
    });

    // ================= Ecuación =================
    var GRIEGAS = { alpha: 'α', beta: 'β', gamma: 'γ', delta: 'δ', Delta: 'Δ', theta: 'θ', lambda: 'λ', mu: 'μ', sigma: 'σ', Sigma: 'Σ', omega: 'ω', Omega: 'Ω', phi: 'φ', rho: 'ρ', epsilon: 'ε', pi: 'π' };
    function ecuacionHtml(t) {
        var h = esc(t);
        h = h.replace(/\^\{([^}]*)\}/g, '<sup>$1</sup>').replace(/\^(-?[\w.]+)/g, '<sup>$1</sup>')
            .replace(/_\{([^}]*)\}/g, '<sub>$1</sub>').replace(/_(-?[\w.]+)/g, '<sub>$1</sub>')
            .replace(/sqrt\(/g, '√(').replace(/&lt;=/g, '≤').replace(/&gt;=/g, '≥').replace(/!=/g, '≠').replace(/\+-/g, '±').replace(/-&gt;/g, '→')
            .replace(/\bsum\b/g, 'Σ').replace(/\bint\b/g, '∫').replace(/\binf\b/g, '∞').replace(/\*/g, '·');
        Object.keys(GRIEGAS).forEach(function (k) { h = h.replace(new RegExp('\\b' + k + '\\b', 'g'), GRIEGAS[k]); });
        return h;
    }
    panel('rd-x-ecuacion', 'rd-p-ecuacion', function () { $('rd-x-ec-txt').focus(); });
    $('rd-x-ec-txt').addEventListener('input', function () { $('rd-x-ec-vista').innerHTML = ecuacionHtml(this.value); });
    boton('rd-x-ec-ok', function () {
        var t = $('rd-x-ec-txt').value.trim(); if (!t) return;
        R.restaurarSeleccion();
        insertarRaiz('<p class="rd-ecuacion" data-rd-nuevo="1" style="text-align:center;font-family:Cambria;font-style:italic;font-size:13pt">' + ecuacionHtml(t) + '</p><p><br></p>');
        $('rd-x-ec-txt').value = ''; $('rd-x-ec-vista').innerHTML = ''; $('rd-p-ecuacion').hidden = true;
    });

    // ================= Cuadro de texto =================
    panel('rd-x-caja', 'rd-p-caja');
    boton('rd-x-caja-ok', function () {
        var lado = $('rd-x-caja-lado').value, w = Math.max(15, Math.min(100, +$('rd-x-caja-w').value || 40));
        var pos = lado === 'der' ? 'float:right;margin:4px 0px 4px 12px;' : lado === 'izq' ? 'float:left;margin:4px 12px 4px 0px;' : 'margin:6px 0px;';
        R.restaurarSeleccion();
        var d = insertarRaiz('<div class="rd-caja" data-rd-nuevo="1" style="' + pos + 'width:' + w + '%;border:1px solid ' + $('rd-x-caja-borde').value + ';background-color:' + $('rd-x-caja-fondo').value + ';padding:8px"><p>Escribe aquí el texto del cuadro.</p></div><p><br></p>');
        $('rd-p-caja').hidden = true;
        if (d && d.firstChild && d.firstChild.firstChild) ponerCursor(d.firstChild.firstChild);
    });

    // ================= Portada =================
    panel('rd-x-portada', 'rd-p-portada', function () {
        if (!$('rd-x-por-titulo').value) $('rd-x-por-titulo').value = $('rd-nombre').value;
        if (!$('rd-x-por-autor').value) $('rd-x-por-autor').value = C.usuario || '';
    });
    boton('rd-x-por-ok', function () {
        var t = esc($('rd-x-por-titulo').value.trim() || $('rd-nombre').value), sub = esc($('rd-x-por-sub').value.trim()), autor = esc($('rd-x-por-autor').value.trim()), col = $('rd-x-por-color').value;
        var depto = esc($('rd-depto').value.trim()), fecha = esc(fechaLarga($('rd-fecha').value)), tipo = $('rd-x-por-tipo').value, h = '';
        var pie = (autor ? '<p style="text-align:center;font-size:12pt">' + autor + '</p>' : '') + (depto ? '<p style="text-align:center;font-size:12pt;color:#475569">' + depto + '</p>' : '') + '<p style="text-align:center;font-size:12pt;color:#475569">' + fecha + '</p>';
        if (tipo === 'banda') {
            h = '<p style="margin-top:60px"><br></p><table style="width:100%;border-collapse:collapse"><tbody><tr><td style="border:0;background-color:' + col + ';padding:40px"><p style="font-size:30pt;font-weight:bold;color:#ffffff">' + t + '</p>' + (sub ? '<p style="font-size:14pt;color:#ffffff">' + sub + '</p>' : '') + '</td></tr></tbody></table><p style="margin-top:220px"><br></p>' + pie.replace(/text-align:center;/g, 'text-align:right;');
        } else if (tipo === 'minimal') {
            h = '<p style="margin-top:200px;font-size:34pt;font-weight:bold;color:#0f172a">' + t + '</p><p style="border:3px solid ' + col + ';width:25%;height:0px"><br></p>' + (sub ? '<p style="font-size:15pt;color:#475569">' + sub + '</p>' : '') + '<p style="margin-top:220px"><br></p>' + pie.replace(/text-align:center;/g, '');
        } else {
            h = '<p style="margin-top:120px;text-align:center;font-size:32pt;font-weight:bold;color:' + col + '">' + t + '</p>' + (sub ? '<p style="text-align:center;font-size:16pt;color:#475569">' + sub + '</p>' : '') + '<hr><p style="margin-top:200px"><br></p>' + pie;
        }
        hoja.insertAdjacentHTML('afterbegin', h + '<div class="salto-pagina" style="page-break-after:always"></div>');
        R.marcarSucio(); R.dibujarGuias(); $('rd-p-portada').hidden = true;
        window.scrollTo({ top: 0, behavior: 'smooth' }); estado('Portada insertada al inicio');
    });

    // ================= Bloques de obra =================
    var B = 'border:1px solid #888888;padding:4px';
    function td(t, ex) { return '<td style="' + B + (ex || '') + '">' + (t || '<br>') + '</td>'; }
    function th(t, w) { return '<th style="' + B + ';background-color:#e2e8f0' + (w ? ';width:' + w : '') + '">' + t + '</th>'; }
    function tabla(cabeza, filas, anchos) {
        var h = '<table style="width:100%;border-collapse:collapse"><tbody>';
        if (cabeza) h += '<tr>' + cabeza.map(function (c, i) { return th(c, anchos && anchos[i]); }).join('') + '</tr>';
        return h + filas.map(function (f) { return '<tr>' + f.map(function (c) { return td(c); }).join('') + '</tr>'; }).join('') + '</tbody></table>';
    }
    function vacias(n, cols) { var o = []; for (var i = 0; i < n; i++) { var f = []; for (var j = 0; j < cols; j++) f.push(j === 0 && cols > 2 ? String(i + 1) : ''); o.push(f); } return o; }
    function datos(pares) { return '<table style="width:100%;border-collapse:collapse"><tbody>' + pares.map(function (p) { return '<tr>' + th(p[0], '28%') + td(p[1]) + '</tr>'; }).join('') + '</tbody></table>'; }
    var hoy = function () { return esc(fechaLarga($('rd-fecha').value)); };
    var BLOQUES = {
        acta: function () {
            return '<h2>Acta de reunión</h2>' + datos([['Proyecto', ''], ['Fecha', hoy()], ['Lugar', ''], ['Hora de inicio / fin', ''], ['Convoca', '']])
                + '<h3>Asistentes</h3>' + tabla(['Nombre', 'Cargo / empresa', 'Firma'], vacias(6, 3).map(function (f) { return ['', '', '']; }), ['35%', '35%', '30%'])
                + '<h3>Orden del día</h3><ol><li>Revisión de acuerdos anteriores</li><li>Avance del programa de obra</li><li>Asuntos generales</li></ol>'
                + '<h3>Acuerdos</h3>' + tabla(['No.', 'Acuerdo', 'Responsable', 'Fecha compromiso'], vacias(5, 4), ['8%', '50%', '22%', '20%']) + '<p><br></p>';
        },
        minuta: function () {
            return '<h2>Minuta</h2>' + datos([['Asunto', ''], ['Fecha', hoy()], ['Participantes', '']])
                + '<h3>Puntos tratados</h3><ul><li><br></li></ul><h3>Compromisos</h3>' + tabla(['No.', 'Compromiso', 'Responsable', 'Fecha'], vacias(4, 4), ['8%', '52%', '22%', '18%']) + '<p><br></p>';
        },
        bitacora: function () {
            return '<h2>Bitácora diaria de obra</h2>' + datos([['Fecha', hoy()], ['Clima', ''], ['Personal en obra', ''], ['Equipo y maquinaria', '']])
                + '<h3>Actividades realizadas</h3><ul><li><br></li></ul><h3>Incidencias y restricciones</h3><ul><li><br></li></ul><h3>Observaciones</h3><p><br></p>'
                + tabla(['Elaboró', 'Revisó'], [['<br><br>', '<br><br>']]) + '<p><br></p>';
        },
        cambio: function () {
            return '<h2>Orden de cambio</h2>' + datos([['Número', ''], ['Fecha', hoy()], ['Proyecto', ''], ['Solicita', '']])
                + '<h3>Descripción del cambio</h3><p><br></p><h3>Justificación</h3><p><br></p><h3>Impacto</h3>' + datos([['En costo', ''], ['En plazo (días)', ''], ['En actividades de la ruta crítica', '']])
                + '<h3>Aprobaciones</h3>' + tabla(['Residente', 'Supervisión', 'Cliente'], [['<br><br><br>', '<br><br><br>', '<br><br><br>']]) + '<p><br></p>';
        },
        asistencia: function () {
            return '<h2>Lista de asistencia</h2>' + datos([['Reunión / evento', ''], ['Fecha', hoy()]]) + '<p><br></p>' + tabla(['No.', 'Nombre', 'Empresa / cargo', 'Firma'], vacias(12, 4), ['8%', '34%', '30%', '28%']) + '<p><br></p>';
        }
    };
    seleccionar('rd-x-bloque', function (k) {
        if (!BLOQUES[k]) return;
        R.restaurarSeleccion(); R.insertarEnRaiz(BLOQUES[k]()); estado('Bloque insertado: rellena los campos');
    });

    // ================= Tabla del programa =================
    function fechaDmy(f) { var m = /^(\d{4})-(\d\d)-(\d\d)/.exec(f || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }
    panel('rd-x-programa', 'rd-p-programa');
    var TABLA_ANCHO = 'width:100%';
    function insertarTabla(cabeza, filas) {
        R.restaurarSeleccion();
        R.insertarEnRaiz('<table style="' + TABLA_ANCHO + ';border-collapse:collapse"><tbody><tr>' + cabeza.map(function (c) { return th(esc(c)); }).join('') + '</tr>' + filas + '</tbody></table><p><br></p>');
    }
    /** Programa: todas las partidas, o solo la de id `pid`; `soloPartidas` omite las actividades */
    function tablaPrograma(pid, soloPartidas, comoHoja) {
        estado('Cargando el programa…');
        return post(C.api.programa).then(function (d) {
            var datos = d.success ? d.filas.filter(function (f) { return !pid || String(f.pid) === String(pid); }) : [];
            if (!d.success || !datos.length) { estado(d.success ? 'No hay partidas que insertar' : (d.error || 'No se pudo leer el programa'), true); return false; }
            var vistas = {}, filas = '', mat = [['Código', 'Nombre', 'Inicio', 'Fin', 'Días', 'Avance']];
            datos.forEach(function (f) {
                if (!vistas[f.pid]) {
                    vistas[f.pid] = true;
                    var ps = datos.filter(function (x) { return x.pid === f.pid && x.nombre; });
                    var ini = ps.length ? ps.map(function (x) { return x.fecha_inicio; }).sort()[0] : '', fin = ps.length ? ps.map(function (x) { return x.fecha_fin; }).sort().pop() : '';
                    var prom = ps.length ? Math.round(ps.reduce(function (a, x) { return a + (+x.progreso || 0); }, 0) / ps.length) + '%' : '';
                    mat.push([f.pcodigo, f.pnombre, fechaDmy(ini), fechaDmy(fin), '', prom]);
                    filas += '<tr>' + ['<strong>' + esc(f.pcodigo) + '</strong>', '<strong>' + esc(f.pnombre) + '</strong>', fechaDmy(ini), fechaDmy(fin), '', prom].map(function (c) { return td(c, ';background-color:#e2e8f0'); }).join('') + '</tr>';
                }
                if (!soloPartidas && f.nombre) mat.push([f.codigo, f.nombre, fechaDmy(f.fecha_inicio), fechaDmy(f.fecha_fin), String(f.duracion_dias == null ? '' : f.duracion_dias), (+f.progreso || 0) + '%']);
                if (!soloPartidas && f.nombre) filas += '<tr>' + [esc(f.codigo), esc(f.nombre), fechaDmy(f.fecha_inicio), fechaDmy(f.fecha_fin), esc(f.duracion_dias), (+f.progreso || 0) + '%'].map(function (c) { return td(c); }).join('') + '</tr>';
            });
            if (comoHoja) return hojaDeMatriz(mat, soloPartidas ? 'Partidas' : 'Programa');
            insertarTabla(mat[0], filas);
            return true;
        });
    }
    /** Crea una hoja de SheetFlow con la matriz (encabezado primero) e inserta su tabla vinculada */
    function hojaDeMatriz(mat, nombre) {
        estado('Creando la tabla en SheetFlow…');
        return post(C.api.hoja, { accion: 'convertir', filas: JSON.stringify(mat), nombre: nombre }).then(function (d) {
            if (!d.success) { estado(d.error || 'No se pudo crear la tabla en SheetFlow', true); return false; }
            R.restaurarSeleccion(); R.insertarEnRaiz(d.html + '<p><br></p>');
            return true;
        });
    }
    function tablaAdicional(tipo, comoHoja) {
        estado('Cargando la tabla…');
        return post(C.api.programa, { tabla: tipo }).then(function (d) {
            if (!d.success || !d.filas.length) { estado(d.success ? 'No hay datos para esa tabla' : (d.error || 'No se pudo leer la tabla'), true); return false; }
            if (d.html) { R.restaurarSeleccion(); R.insertarEnRaiz(d.html + '<p><br></p>'); return true; }
            if (comoHoja) return hojaDeMatriz([d.columnas].concat(d.filas), tipo.charAt(0).toUpperCase() + tipo.slice(1));
            insertarTabla(d.columnas, d.filas.map(function (f) { return '<tr>' + f.map(function (c) { return td(esc(c)); }).join('') + '</tr>'; }).join(''));
            return true;
        });
    }
    /** Desde «Insertar tabla»: origen = id de partida, @programa, @partidas, @materiales, @restricciones o @festivos */
    R.tablaOrigen = function (origen, ancho, enc, comoHoja) {
        if (origen === '@punchvista') { abrirPunch(null); return; }
        TABLA_ANCHO = 'width:' + ancho;
        var ok;
        if (origen === '@sfnueva') {
            estado('Creando la tabla en SheetFlow…');
            ok = post(C.api.hoja, { accion: 'nueva' }).then(function (d) {
                if (!d.success) { estado(d.error || 'No se pudo crear la tabla', true); return false; }
                R.restaurarSeleccion(); R.insertarEnRaiz(d.html + '<p><br></p>');
                window.open(d.url, '_blank', 'noopener');
                return true;
            });
        } else {
            ok = origen === '@programa' ? tablaPrograma(0, false, comoHoja) : origen === '@partidas' ? tablaPrograma(0, true, comoHoja)
                : origen.charAt(0) === '@' ? tablaAdicional(origen.slice(1), comoHoja) : tablaPrograma(origen, false, comoHoja);
        }
        ok.then(function (hecho) { if (hecho) estado('Tabla insertada'); }).catch(function () { estado('Sin conexión: no se pudo leer la tabla', true); });
    };
    // ---- Tablas de SheetFlow dentro del documento ----
    boton('rd-x-sf-abrir', function () {
        R.restaurarSeleccion();
        var c = R.celdaActual(), t = c && c.closest('table');
        if (!t || !t.hasAttribute('data-sf')) { estado('Coloca el cursor en una tabla de SheetFlow', true); return; }
        window.open('/sheetflow/abrir?proyecto=' + C.proyecto + '&id=' + encodeURIComponent(t.getAttribute('data-sf')), '_blank', 'noopener');
        estado('Se abrió en otra pestaña; al terminar, vuelve a abrir o exporta el documento para ver los cambios');
    });
    boton('rd-x-sf-conv', function () {
        R.restaurarSeleccion();
        var c = R.celdaActual(), t = c && c.closest('table');
        if (!t || t.hasAttribute('data-sf') || t.hasAttribute('data-punch')) { estado('Coloca el cursor en una tabla propia del documento', true); return; }
        var mat = Array.prototype.map.call(t.rows, function (f) { return Array.prototype.map.call(f.cells, function (x) { return x.textContent.replace(/\s+/g, ' ').trim(); }); });
        estado('Creando la tabla en SheetFlow…');
        post(C.api.hoja, { accion: 'convertir', filas: JSON.stringify(mat), nombre: 'Tabla del documento' }).then(function (d) {
            if (!d.success) { estado(d.error || 'No se pudo pasar a SheetFlow', true); return; }
            t.outerHTML = d.html; R.marcarSucio(); estado('Tabla pasada a SheetFlow: ahora se edita allá');
        }).catch(function () { estado('Sin conexión: no se pudo crear la tabla', true); });
    });
    // ---- Punch list con elección de columnas (tabla data-punch: se refresca sola) ----
    var punchTabla = null;
    function cfgPunchDeAtributo(v) { try { return JSON.parse(decodeURIComponent(escape(atob(v.replace(/-/g, '+').replace(/_/g, '/'))))); } catch (e) { return null; } }
    function abrirPunch(tabla) {
        punchTabla = tabla;
        var cfg = tabla ? cfgPunchDeAtributo(tabla.getAttribute('data-punch')) : null;
        var marcadas = cfg ? cfg.columnas : null;
        Array.prototype.forEach.call($('rd-pu-cols').querySelectorAll('input'), function (i) { if (marcadas) i.checked = marcadas.indexOf(i.value) >= 0; });
        $('rd-pu-estado').value = cfg ? cfg.estado : 'pendientes'; $('rd-pu-sev').value = cfg ? cfg.severidad : '';
        $('rd-pu-con').value = cfg ? cfg.contratista : ''; $('rd-pu-lim').value = cfg ? cfg.limite : 50;
        $('rd-pu-ok').textContent = tabla ? 'Aplicar' : 'Insertar';
        R.paneles.forEach(function (id) { if ($(id)) $(id).hidden = id !== 'rd-p-punch'; });
    }
    boton('rd-x-punch-cfg', function () {
        R.restaurarSeleccion();
        var c = R.celdaActual(), t = c && c.closest('table');
        if (!t || !t.hasAttribute('data-punch')) { estado('Coloca el cursor en una tabla del Punch list', true); return; }
        abrirPunch(t);
    });
    boton('rd-pu-ok', function () {
        var cols = Array.prototype.filter.call($('rd-pu-cols').querySelectorAll('input'), function (i) { return i.checked; }).map(function (i) { return i.value; });
        if (!cols.length) { estado('Marca al menos una columna', true); return; }
        estado('Cargando el Punch list…');
        post(C.api.programa, { tabla: 'punchvista', cfg: JSON.stringify({ columnas: cols, estado: $('rd-pu-estado').value, severidad: $('rd-pu-sev').value, contratista: $('rd-pu-con').value, limite: +$('rd-pu-lim').value || 50 }) }).then(function (d) {
            if (!d.success) { estado(d.error || 'No se pudo leer el Punch list', true); return; }
            if (punchTabla && punchTabla.parentNode) { punchTabla.outerHTML = d.html; R.marcarSucio(); }
            else { R.restaurarSeleccion(); R.insertarEnRaiz(d.html + '<p><br></p>'); }
            $('rd-p-punch').hidden = true; punchTabla = null;
            estado(d.total ? 'Tabla del Punch list lista (' + d.total + ' pendientes)' : 'No hay pendientes con ese filtro');
        }).catch(function () { estado('Sin conexión: no se pudo leer el Punch list', true); });
    });
    // Al abrir «Insertar tabla» o «Tabla del programa» se llenan los grupos de partidas
    var partidasCargadas = false;
    function cargarPartidas() {
        if (partidasCargadas) return; partidasCargadas = true;
        post(C.api.programa).then(function (d) {
            if (!d.success) { partidasCargadas = false; return; }
            var vistas = {}, grupos = [$('rd-t-partidas'), $('rd-x-prog-partidas')];
            d.filas.forEach(function (f) {
                if (vistas[f.pid]) return; vistas[f.pid] = true;
                grupos.forEach(function (g) {
                    if (!g) return;
                    var o = document.createElement('option'); o.value = f.pid; o.textContent = (f.pcodigo ? f.pcodigo + ' · ' : '') + f.pnombre; g.appendChild(o);
                });
            });
        }).catch(function () { partidasCargadas = false; });
    }
    $('rd-i-tabla').addEventListener('click', function () { if (!$('rd-p-tabla').hidden) cargarPartidas(); });
    $('rd-x-programa').addEventListener('click', function () { if (!$('rd-p-programa').hidden) cargarPartidas(); });
    $('rd-t-origen').addEventListener('change', function () { $('rd-t-medidas').hidden = !!this.value; });
    boton('rd-x-prog-ok', function () {
        R.tablaOrigen($('rd-x-prog-origen').value, '100%', true, !!$('rd-x-prog-sf').checked);
        $('rd-p-programa').hidden = true;
    });
    TABLA_ANCHO = 'width:100%';

    // ================= Formato: copiar formato, bordes, capital, estilos propios =================
    var formatoCopiado = null;
    function hexDe(rgb) {
        var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/.exec(rgb); if (!m || (m[4] !== undefined && +m[4] === 0)) return '';
        return '#' + [m[1], m[2], m[3]].map(function (n) { return ('0' + (+n).toString(16)).slice(-2); }).join('');
    }
    /** Valor de una propiedad puesto por el usuario (estilo en línea) en el nodo o sus ancestros dentro de la hoja; '' si no hay.
     *  Evita copiar los colores y la fuente que el tema de la página da a los títulos */
    function explicito(n, prop) {
        for (; n && n !== hoja && n.nodeType === 1; n = n.parentNode) if (n.style && n.style[prop]) return n.style[prop];
        return '';
    }
    function nodoActual() { var s = R.seleccion(); if (!s) return null; var n = s.anchorNode; return n && n.nodeType === 3 ? n.parentNode : n; }
    function capturarFormato() {
        var n = nodoActual(); if (!n) return null;
        var cs = getComputedStyle(n);
        return { negrita: (parseInt(cs.fontWeight, 10) || 400) >= 600, cursiva: cs.fontStyle === 'italic', subrayado: /underline/.test(cs.textDecorationLine || cs.textDecoration), tachado: /line-through/.test(cs.textDecorationLine || cs.textDecoration),
            color: hexDe(explicito(n, 'color')) || '#000000', fondo: hexDe(explicito(n, 'backgroundColor')), fuente: (explicito(n, 'fontFamily') || 'Calibri').split(',')[0].replace(/["']/g, '').trim(), pt: Math.round(parseFloat(cs.fontSize) * 0.75 * 2) / 2 };
    }
    function aplicarFormato(f) {
        var estadoDe = function (c) { try { return document.queryCommandState(c); } catch (e) { return false; } };
        [['bold', f.negrita], ['italic', f.cursiva], ['underline', f.subrayado], ['strikeThrough', f.tachado]].forEach(function (p) { if (estadoDe(p[0]) !== p[1]) R.exec(p[0]); });
        R.exec('foreColor', f.color, true);
        if (f.fondo) R.exec('hiliteColor', f.fondo, true); else R.exec('hiliteColor', 'transparent', true);
        if (/^[\p{L}0-9 ,'"-]{1,80}$/u.test(f.fuente)) R.exec('fontName', f.fuente, true);
        document.execCommand('styleWithCSS', false, false);
        document.execCommand('fontSize', false, '7');
        hoja.querySelectorAll('font[size="7"]').forEach(function (x) {
            var sp = document.createElement('span'); sp.style.fontSize = f.pt + 'pt';
            while (x.firstChild) sp.appendChild(x.firstChild);
            x.parentNode.replaceChild(sp, x);
        });
        R.marcarSucio();
    }
    boton('rd-x-brocha', function () {
        var b = $('rd-x-brocha');
        if (formatoCopiado) { formatoCopiado = null; b.classList.remove('activo'); document.body.classList.remove('rd-brocha'); estado('Copiar formato cancelado'); return; }
        R.restaurarSeleccion();
        formatoCopiado = capturarFormato();
        if (!formatoCopiado) { estado('Coloca el cursor en el texto cuyo formato quieres copiar', true); return; }
        b.classList.add('activo'); document.body.classList.add('rd-brocha'); estado('Formato copiado: selecciona el texto al que lo quieres aplicar');
    });
    hoja.addEventListener('mouseup', function () {
        if (!formatoCopiado) return;
        setTimeout(function () {
            var s = R.seleccion(); if (!s || s.isCollapsed) return;
            aplicarFormato(formatoCopiado);
            formatoCopiado = null; $('rd-x-brocha').classList.remove('activo'); document.body.classList.remove('rd-brocha'); estado('Formato aplicado');
        }, 0);
    });

    var BORDES = { fino: '1px solid #334155', grueso: '3px solid #334155', discontinuo: '1px dashed #334155', doble: '3px double #334155' };
    seleccionar('rd-x-borde', function (v) {
        R.restaurarSeleccion();
        R.aplicarBloques(function (b) {
            if (v === 'ninguno') { b.style.border = ''; b.style.padding = ''; } else { b.style.border = BORDES[v]; b.style.padding = '4px'; }
        });
    });
    boton('rd-x-capital', function () {
        R.restaurarSeleccion();
        var b = R.bloqueActual(); if (!b || !/^(P|BLOCKQUOTE|DIV)$/.test(b.tagName)) { estado('Coloca el cursor en un párrafo de texto', true); return; }
        var cap = b.querySelector('.rd-capital');
        if (cap) { cap.replaceWith(document.createTextNode(cap.textContent)); b.normalize(); R.marcarSucio(); return; }
        var it = document.createTreeWalker(b, NodeFilter.SHOW_TEXT), n;
        while ((n = it.nextNode())) if (/\S/.test(n.nodeValue)) break;
        if (!n) return;
        var i = n.nodeValue.search(/\S/), sp = document.createElement('span');
        sp.className = 'rd-capital'; sp.style.cssText = 'float:left;font-size:44pt;line-height:1;font-weight:bold;margin:0px 6px 0px 0px';
        sp.textContent = n.nodeValue.charAt(i);
        var resto = n.splitText(i + 1); n.nodeValue = n.nodeValue.slice(0, i);
        resto.parentNode.insertBefore(sp, resto);
        R.marcarSucio();
    });

    function claveEstilos() { return 'rd-estilos-' + C.proyecto; }
    function leerEstilos() { try { return JSON.parse(localStorage.getItem(claveEstilos()) || '[]') || []; } catch (e) { return []; } }
    function escribirEstilos(l) { try { localStorage.setItem(claveEstilos(), JSON.stringify(l)); } catch (e) { estado('No se pudo guardar el estilo en este navegador', true); } }
    function pintarEstilos() {
        var l = leerEstilos();
        $('rd-x-est-lista').innerHTML = l.length ? l.map(function (e, i) {
            return '<span class="rd-est"><button type="button" class="btn btn-sm btn-ghost" data-est="aplicar" data-i="' + i + '" title="Aplicar a los párrafos seleccionados">' + esc(e.nombre) + '</button><button type="button" class="btn btn-sm btn-ghost" data-est="borrar" data-i="' + i + '" title="Eliminar el estilo" aria-label="Eliminar"><i class="fa-solid fa-xmark"></i></button></span>';
        }).join('') : '<span class="text-sm text-muted">Aún no hay estilos guardados.</span>';
    }
    panel('rd-x-estilos', 'rd-p-estilos', pintarEstilos);
    boton('rd-x-est-guardar', function () {
        var nombre = $('rd-x-est-nombre').value.trim(); if (!nombre) { estado('Escribe un nombre para el estilo', true); return; }
        R.restaurarSeleccion();
        var b = R.bloqueActual(); if (!b) { estado('Coloca el cursor en el párrafo cuyo formato quieres guardar', true); return; }
        var cs = getComputedStyle(b), px = parseFloat(cs.fontSize), lh = parseFloat(cs.lineHeight), e = { nombre: nombre.slice(0, 40), p: {} };
        e.p['font-family'] = (explicito(b, 'fontFamily') || 'Calibri').split(',')[0].replace(/["']/g, '').trim();
        e.p['font-size'] = Math.round(px * 0.75 * 2) / 2 + 'pt';
        e.p['color'] = hexDe(explicito(b, 'color')) || '#000000';
        var fondo = hexDe(explicito(b, 'backgroundColor')); if (fondo) e.p['background-color'] = fondo;
        e.p['font-weight'] = (parseInt(cs.fontWeight, 10) || 400) >= 600 ? 'bold' : 'normal';
        e.p['font-style'] = cs.fontStyle === 'italic' ? 'italic' : 'normal';
        e.p['text-align'] = /^(left|right|center|justify)$/.test(cs.textAlign) ? cs.textAlign : 'left';
        if (lh > 0 && px > 0) e.p['line-height'] = String(Math.round(lh / px * 100) / 100);
        e.p['margin-bottom'] = Math.round(parseFloat(cs.marginBottom) || 0) + 'px';
        e.p['text-indent'] = Math.round(parseFloat(cs.textIndent) || 0) + 'px';
        var l = leerEstilos().filter(function (x) { return x.nombre !== e.nombre; }); l.push(e); escribirEstilos(l);
        $('rd-x-est-nombre').value = ''; pintarEstilos(); estado('Estilo «' + e.nombre + '» guardado');
    });
    $('rd-x-est-lista').addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-est]'); if (!b) return;
        var l = leerEstilos(), e = l[+b.dataset.i]; if (!e) return;
        if (b.dataset.est === 'borrar') { l.splice(+b.dataset.i, 1); escribirEstilos(l); pintarEstilos(); return; }
        R.restaurarSeleccion();
        R.aplicarBloques(function (bl) { Object.keys(e.p).forEach(function (k) { bl.style.setProperty(k, e.p[k]); }); });
    });

    // ================= Numeración de títulos y listas multinivel =================
    seleccionar('rd-x-tnum', function (v) {
        hoja.querySelectorAll('h1 .rd-tnum,h2 .rd-tnum,h3 .rd-tnum').forEach(function (n) { n.remove(); });
        if (v === 'aplicar') {
            var c = [0, 0, 0];
            hoja.querySelectorAll('h1,h2,h3').forEach(function (h) {
                if (!h.textContent.trim()) return;
                var l = +h.tagName.charAt(1) - 1; c[l]++; for (var k = l + 1; k < 3; k++) c[k] = 0;
                var sp = document.createElement('span'); sp.className = 'rd-tnum'; sp.textContent = c.slice(0, l + 1).join('.') + (l === 0 ? '.' : '') + ' ';
                h.insertBefore(sp, h.firstChild);
            });
        }
        R.marcarSucio(); estado(v === 'aplicar' ? 'Títulos numerados (vuelve a aplicar si agregas o mueves títulos)' : 'Numeración de títulos quitada');
    });
    function aplanar(lista, prefijo, nivel, salida) {
        var n = 0;
        Array.prototype.forEach.call(lista.children, function (li) {
            if (li.tagName !== 'LI') return;
            n++;
            var num = prefijo ? prefijo + '.' + n : String(n), p = document.createElement('p');
            p.style.marginLeft = (nivel * 18) + 'pt';
            p.innerHTML = '<span class="rd-tnum">' + num + (nivel === 0 ? '.' : '') + ' </span>';
            Array.prototype.forEach.call(li.childNodes, function (ch) { if (!(ch.nodeType === 1 && /^(UL|OL)$/.test(ch.tagName))) p.appendChild(ch.cloneNode(true)); });
            salida.push(p);
            Array.prototype.forEach.call(li.children, function (ch) { if (/^(UL|OL)$/.test(ch.tagName)) aplanar(ch, num, nivel + 1, salida); });
        });
    }
    boton('rd-x-multinivel', function () {
        R.restaurarSeleccion();
        var n = nodoActual(), lista = n && n.closest ? n.closest('ul,ol') : null;
        if (!lista || !hoja.contains(lista)) { estado('Coloca el cursor dentro de una lista (usa «Aumentar sangría» para crear subniveles)', true); return; }
        while (lista.parentNode && lista.parentNode.closest && lista.parentNode.closest('ul,ol') && hoja.contains(lista.parentNode.closest('ul,ol'))) lista = lista.parentNode.closest('ul,ol');
        var ps = []; aplanar(lista, '', 0, ps);
        ps.forEach(function (p) { lista.parentNode.insertBefore(p, lista); });
        lista.remove(); R.marcarSucio();
        estado('Lista convertida a numeración multinivel (ya es texto: no se renumera sola)');
    });

    // ================= Cambios: aceptar / rechazar todos =================
    document.querySelectorAll('[data-x-cambios]').forEach(function (b) {
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () {
            var aceptar = b.dataset.xCambios === 'aceptar', acc = $('rd-lat-cambios').querySelector('[data-cam="' + (aceptar ? 'aceptar-todos' : 'rechazar-todos') + '"]');
            if (!acc) { estado('No hay cambios pendientes'); return; }
            acc.click(); estado(aceptar ? 'Todos los cambios aceptados' : 'Todos los cambios rechazados');
        });
    });

    // ================= Encabezado, pie y marca de agua =================
    panel('rd-x-encpie', 'rd-p-encpie');
    ['rd-x-enc', 'rd-x-pie'].forEach(function (id) { $(id).addEventListener('input', R.marcarSucio); });
    $('rd-x-marca').addEventListener('input', function () { R.marcarSucio(); R.dibujarGuias(); });
    document.querySelectorAll('[data-x-marca]').forEach(function (b) {
        b.addEventListener('click', function () { $('rd-x-marca').value = b.dataset.xMarca; R.marcarSucio(); R.dibujarGuias(); });
    });

    // ================= Ir a… =================
    panel('rd-x-ira', 'rd-p-ira', function () {
        var hs = hoja.querySelectorAll('h1,h2,h3,h4');
        $('rd-x-ira-titulo').innerHTML = hs.length ? Array.prototype.map.call(hs, function (h, i) {
            return '<option value="' + i + '">' + esc(new Array(+h.tagName.charAt(1)).join('  ') + h.textContent.replace(/\s+/g, ' ').trim().slice(0, 60)) + '</option>';
        }).join('') : '<option value="">(el documento no tiene títulos)</option>';
        var m = /(\d+)/.exec($('rd-e-pagina').textContent); $('rd-x-ira-pag').max = m ? m[1] : 1;
    });
    boton('rd-x-ira-ir', function () {
        var h = hoja.querySelectorAll('h1,h2,h3,h4')[+$('rd-x-ira-titulo').value]; if (!h) return;
        h.scrollIntoView({ block: 'center', behavior: 'smooth' }); h.classList.add('rd-flash'); setTimeout(function () { h.classList.remove('rd-flash'); }, 1500);
    });
    boton('rd-x-ira-pir', function () {
        var m = R.medidas(), n = Math.max(1, +$('rd-x-ira-pag').value || 1); if (!m) return;
        window.scrollTo({ top: hoja.getBoundingClientRect().top + window.scrollY + (n - 1) * m.h * R.zoom() - 140, behavior: 'smooth' });
    });

    // ================= Tablas: combinar, dividir, ordenar, filas alternas, fórmulas =================
    function tablaActual() { var c = R.celdaActual(); return c ? c.closest('table') : null; }
    function mapa(t) {
        var g = [];
        Array.prototype.forEach.call(t.rows, function (tr, r) {
            g[r] = g[r] || []; var c = 0;
            Array.prototype.forEach.call(tr.cells, function (celda) {
                while (g[r][c]) c++;
                for (var i = 0; i < celda.rowSpan; i++) for (var j = 0; j < celda.colSpan; j++) (g[r + i] = g[r + i] || [])[c + j] = celda;
                c += celda.colSpan;
            });
        });
        return g;
    }
    function origen(g, celda) { for (var r = 0; r < g.length; r++) for (var c = 0; c < (g[r] || []).length; c++) if (g[r][c] === celda) return [r, c]; return null; }
    function combinar() {
        var s = R.seleccion(), t = tablaActual(); if (!s || !t) return;
        var rg = s.getRangeAt(0), g = mapa(t), celdas = Array.prototype.filter.call(t.querySelectorAll('td,th'), function (c) { return rg.intersectsNode(c) && c.closest('table') === t; });
        if (celdas.length < 2) { estado('Selecciona con el mouse dos o más celdas para combinarlas', true); return; }
        var r1 = 1e9, c1 = 1e9, r2 = -1, c2 = -1;
        celdas.forEach(function (c) { var o = origen(g, c); if (!o) return; r1 = Math.min(r1, o[0]); c1 = Math.min(c1, o[1]); r2 = Math.max(r2, o[0] + c.rowSpan - 1); c2 = Math.max(c2, o[1] + c.colSpan - 1); });
        var dentro = [];
        for (var r = r1; r <= r2; r++) for (var c = c1; c <= c2; c++) { var x = g[r] && g[r][c]; if (!x) { estado('La selección no es rectangular', true); return; } if (dentro.indexOf(x) < 0) dentro.push(x); }
        for (var k = 0; k < dentro.length; k++) { var o2 = origen(g, dentro[k]); if (o2[0] < r1 || o2[1] < c1 || o2[0] + dentro[k].rowSpan - 1 > r2 || o2[1] + dentro[k].colSpan - 1 > c2) { estado('Hay celdas combinadas que sobresalen de la selección', true); return; } }
        var primera = g[r1][c1];
        dentro.forEach(function (x) {
            if (x === primera) return;
            if (x.textContent.trim()) { while (x.firstChild) primera.appendChild(x.firstChild); }
            x.remove();
        });
        primera.colSpan = c2 - c1 + 1; primera.rowSpan = r2 - r1 + 1;
        if (primera.colSpan === 1) primera.removeAttribute('colspan'); if (primera.rowSpan === 1) primera.removeAttribute('rowspan');
        R.marcarSucio(); estado('Celdas combinadas');
    }
    function dividir() {
        var c = R.celdaActual(), t = c && c.closest('table'); if (!c) return;
        if (c.colSpan === 1 && c.rowSpan === 1) { estado('La celda no está combinada', true); return; }
        var g = mapa(t), o = origen(g, c), cs = c.colSpan, rs = c.rowSpan, colOrigen = new Map();
        Array.prototype.forEach.call(t.querySelectorAll('td,th'), function (x) { var p = origen(g, x); if (p) colOrigen.set(x, p[1]); });
        var estilo = c.getAttribute('style') || '', tag = c.tagName.toLowerCase();
        for (var i = 0; i < rs; i++) {
            var tr = t.rows[o[0] + i], existentes = Array.prototype.slice.call(tr.cells);
            for (var j = (i === 0 ? 1 : 0); j < cs; j++) {
                var nueva = document.createElement(tag); nueva.setAttribute('style', estilo.replace(/background-color:[^;]*;?/, '')); nueva.innerHTML = '<br>';
                var ref = existentes.filter(function (x) { return colOrigen.get(x) > o[1] + j; })[0] || null;
                tr.insertBefore(nueva, ref);
            }
        }
        c.removeAttribute('colspan'); c.removeAttribute('rowspan');
        R.marcarSucio(); estado('Celda dividida');
    }
    var ultimoOrden = { tabla: null, col: -1, asc: false };
    function numeroDe(t) { var n = parseFloat(String(t).replace(/[$,%\s]/g, '').replace(/,/g, '')); return isFinite(n) ? n : null; }
    function ordenarPorColumna() {
        var c = R.celdaActual(), t = c && c.closest('table'); if (!c) return;
        if (t.querySelector('[rowspan]')) { estado('No se puede ordenar una tabla con filas combinadas', true); return; }
        var col = c.cellIndex, filas = Array.prototype.slice.call(t.rows), cab = filas.length && filas[0].querySelector('th') ? filas.shift() : null;
        if (filas.length < 2) { estado('La tabla necesita al menos dos filas de datos', true); return; }
        var asc = !(ultimoOrden.tabla === t && ultimoOrden.col === col && ultimoOrden.asc);
        var v = function (f) { var x = f.cells[col]; return x ? x.textContent.trim() : ''; };
        var todasNum = filas.every(function (f) { return v(f) === '' || numeroDe(v(f)) !== null; });
        filas.sort(function (a, b) {
            var r = todasNum ? (numeroDe(v(a)) || 0) - (numeroDe(v(b)) || 0) : v(a).localeCompare(v(b), 'es', { sensitivity: 'base', numeric: true });
            return asc ? r : -r;
        });
        filas.forEach(function (f) { f.parentNode.appendChild(f); });
        ponerCursor(c);   // al mover las filas se pierde el cursor: así se puede ordenar otra vez (descendente)
        ultimoOrden = { tabla: t, col: col, asc: asc };
        R.marcarSucio(); estado('Ordenado por la columna ' + (col + 1) + (asc ? ' (ascendente)' : ' (descendente)'));
    }
    function filasAlternas() {
        var t = tablaActual(); if (!t) return;
        var filas = Array.prototype.slice.call(t.rows).filter(function (f) { return !f.querySelector('th'); });
        var activo = filas.length > 1 && filas[1].cells[0] && filas[1].cells[0].style.backgroundColor === 'rgb(241, 245, 249)';
        filas.forEach(function (f, i) { Array.prototype.forEach.call(f.cells, function (x) { if (activo) x.style.backgroundColor = ''; else if (i % 2) x.style.backgroundColor = '#f1f5f9'; }); });
        R.marcarSucio(); estado(activo ? 'Filas alternas quitadas' : 'Filas alternas aplicadas');
    }
    document.querySelectorAll('[data-x-tabla]').forEach(function (b) {
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        if (b.dataset.xTabla === 'punch') return;
        b.addEventListener('click', function () { ({ combinar: combinar, dividir: dividir, ordenar: ordenarPorColumna, zebra: filasAlternas })[b.dataset.xTabla](); });
    });
    seleccionar('rd-x-formula', function (v) {
        R.restaurarSeleccion();
        var c = R.celdaActual(), t = c && c.closest('table'); if (!c) { estado('Coloca el cursor en la celda del resultado', true); return; }
        var f = v.split(':'), g = mapa(t), o = origen(g, c), nums = [], r = o[0], k = o[1];
        for (;;) {
            if (f[1] === 'arriba') r--; else k--;
            var x = g[r] && g[r][k]; if (!x || r < 0 || k < 0) break;
            var n = numeroDe(x.textContent); if (n === null || x.textContent.trim() === '') break;
            nums.push(n);
        }
        if (!nums.length) { estado('No hay números contiguos ' + (f[1] === 'arriba' ? 'arriba' : 'a la izquierda') + ' de la celda', true); return; }
        var suma = nums.reduce(function (a, n) { return a + n; }, 0);
        var res = { suma: suma, prom: suma / nums.length, max: Math.max.apply(null, nums), min: Math.min.apply(null, nums), cuenta: nums.length }[f[0]];
        c.innerHTML = '<strong>' + (Math.round(res * 100) / 100).toLocaleString('es-MX', { maximumFractionDigits: 2 }) + '</strong>';
        R.marcarSucio(); estado('Resultado escrito (es texto: no se recalcula solo)');
    });

    // ================= Historial de versiones =================
    function textoBloques(html) {
        var d = document.createElement('div'); d.innerHTML = html;
        d.querySelectorAll('del').forEach(function (x) { x.remove(); });
        var out = [];
        d.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li,blockquote,pre,td,th').forEach(function (b) {
            if (b.querySelector('p,li,td,th,h1,h2,h3,h4,blockquote,pre')) return;
            out.push(b.textContent.replace(/\s+/g, ' ').trim());
        });
        return out;
    }
    function diff(a, b) {
        var ini = 0, fin = 0;
        while (ini < a.length && ini < b.length && a[ini] === b[ini]) ini++;
        while (fin < a.length - ini && fin < b.length - ini && a[a.length - 1 - fin] === b[b.length - 1 - fin]) fin++;
        var A = a.slice(ini, a.length - fin), B2 = b.slice(ini, b.length - fin), n = A.length, m = B2.length, ops = [], i;
        for (i = 0; i < ini; i++) ops.push(['=', a[i]]);
        if (n * m > 6000000) { A.forEach(function (x) { ops.push(['-', x]); }); B2.forEach(function (x) { ops.push(['+', x]); }); }
        else {
            var w = m + 1, dp = new Int32Array((n + 1) * w), j;
            for (i = n - 1; i >= 0; i--) for (j = m - 1; j >= 0; j--) dp[i * w + j] = A[i] === B2[j] ? dp[(i + 1) * w + j + 1] + 1 : Math.max(dp[(i + 1) * w + j], dp[i * w + j + 1]);
            i = 0; j = 0;
            while (i < n && j < m) {
                if (A[i] === B2[j]) { ops.push(['=', A[i]]); i++; j++; }
                else if (dp[(i + 1) * w + j] >= dp[i * w + j + 1]) { ops.push(['-', A[i]]); i++; }
                else { ops.push(['+', B2[j]]); j++; }
            }
            while (i < n) ops.push(['-', A[i++]]);
            while (j < m) ops.push(['+', B2[j++]]);
        }
        for (i = a.length - fin; i < a.length; i++) ops.push(['=', a[i]]);
        return ops;
    }
    function compararHtml(htmlViejo, htmlNuevo) {
        var vb = textoBloques(htmlViejo), nb = textoBloques(htmlNuevo), tokens = function (bl) { var o = []; bl.forEach(function (p, i) { if (i) o.push('\n'); (p.match(/\S+/g) || []).forEach(function (w) { o.push(w); }); }); return o; };
        var ops = diff(tokens(vb), tokens(nb)), h = '', tipo = '=', buf = [];
        var vaciar = function () {
            if (!buf.length) return;
            var t = esc(buf.join(' ')).replace(/ ?\n ?/g, '<br>');
            h += tipo === '=' ? t + ' ' : '<span class="rd-dif-' + (tipo === '+' ? 'ins' : 'del') + '">' + t + '</span> ';
            buf = [];
        };
        ops.forEach(function (o) { if (o[0] !== tipo) { vaciar(); tipo = o[0]; } buf.push(o[1]); });
        vaciar();
        var cambios = ops.filter(function (o) { return o[0] !== '='; }).length;
        return { html: h, cambios: cambios };
    }
    function pintarVersiones() {
        var lista = $('rd-x-ver-lista'); lista.innerHTML = '<span class="text-sm text-muted">Cargando…</span>';
        post(C.api.versiones).then(function (d) {
            if (!d.success) { lista.textContent = d.error || 'No se pudo leer el historial'; return; }
            lista.innerHTML = d.versiones.length ? d.versiones.map(function (v) {
                return '<div class="rd-ver-item"><span><strong>' + esc(v.etiqueta || 'Versión') + '</strong> <small class="text-muted">' + esc(fechaCorta(v.creado)) + ' · ' + esc(v.usuario) + ' · ' + Math.round(v.tam / 1024) + ' KB</small></span>'
                    + '<button type="button" class="btn btn-sm btn-ghost" data-ver="comparar" data-id="' + v.id + '" title="Comparar con el documento actual"><i class="fa-solid fa-code-compare"></i> Comparar</button>'
                    + '<button type="button" class="btn btn-sm btn-ghost" data-ver="restaurar" data-id="' + v.id + '" title="Volver a esta versión"><i class="fa-solid fa-clock-rotate-left"></i> Restaurar</button></div>';
            }).join('') : '<span class="text-sm text-muted">Todavía no hay versiones. Guarda una o espera al próximo guardado.</span>';
        }).catch(function () { lista.textContent = 'Sin conexión'; });
    }
    panel('rd-x-versiones', 'rd-p-versiones', pintarVersiones);
    boton('rd-x-ver-guardar', function () {
        R.ponerEtiquetaVersion($('rd-x-ver-etq').value.trim() || 'Versión manual');
        R.guardar(false, function () { $('rd-x-ver-etq').value = ''; pintarVersiones(); estado('Versión guardada'); });
    });
    $('rd-x-ver-lista').addEventListener('click', function (e) {
        var b = e.target.closest('[data-ver]'); if (!b) return;
        post(C.api.version, { version_id: b.dataset.id }).then(async function (d) {
            if (!d.success) { estado(d.error || 'No se pudo leer la versión', true); return; }
            if (b.dataset.ver === 'comparar') {
                var r = compararHtml(d.version.contenido, hoja.innerHTML);
                $('rd-x-cmp-tit').textContent = 'Versión «' + (d.version.etiqueta || 'sin nombre') + '» (' + fechaCorta(d.version.creado) + ') frente al documento actual';
                $('rd-x-cmp-cuerpo').innerHTML = r.cambios ? r.html : '<p class="text-muted">No hay diferencias de texto.</p>';
                $('rd-dlg-comparar').showModal();
                return;
            }
            if (!await window.confirmarModal({ titulo: '¿Volver a esta versión?', mensaje: '«' + (d.version.etiqueta || 'sin nombre') + '»: antes se guarda una copia del texto actual para no perderlo.', aceptar: 'Restaurar', tono: 'info', icono: 'fa-clock-rotate-left' })) return;
            R.ponerEtiquetaVersion('Antes de restaurar');
            R.guardar(false, function () {
                hoja.innerHTML = d.version.contenido || '<p><br></p>';
                R.marcarSucio(); R.dibujarGuias(); pintarVersiones(); estado('Versión restaurada; se guardará con el autoguardado o con Guardar');
            });
        }).catch(function () { estado('Sin conexión', true); });
    });
    $('rd-x-cmp-cerrar').addEventListener('click', function () { $('rd-dlg-comparar').close(); });

    // ================= Regla con sangrías =================
    var regla = null, mIzq = null, mPrim = null;
    function crearRegla() {
        regla = document.createElement('div'); regla.className = 'rd-regla'; regla.id = 'rd-regla'; regla.hidden = true;
        regla.innerHTML = '<div class="rd-regla-pista" id="rd-regla-pista"></div>';
        R.fondo.insertBefore(regla, R.pagina);
        var pista = regla.firstChild;
        mPrim = document.createElement('span'); mPrim.className = 'rd-reg-m rd-reg-prim'; mPrim.title = 'Sangría de primera línea'; mPrim.textContent = '▼';
        mIzq = document.createElement('span'); mIzq.className = 'rd-reg-m rd-reg-izq'; mIzq.title = 'Sangría izquierda'; mIzq.textContent = '▲';
        pista.appendChild(mPrim); pista.appendChild(mIzq);
        [mPrim, mIzq].forEach(function (m) {
            m.addEventListener('pointerdown', function (e) {
                e.preventDefault(); m.setPointerCapture(e.pointerId);
                var mover = function (ev) {
                    var med = R.medidas(), r = pista.getBoundingClientRect(), z = R.zoom();
                    var x = Math.max(0, Math.min(med.w, (ev.clientX - r.left) / z)), cm = Math.max(0, Math.round((x - med.izq) / 37.7953 * 4) / 4);
                    var bl = R.bloquesDeSeleccion(); if (!bl.length) return;
                    bl.forEach(function (b) {
                        var izq = parseFloat(b.style.marginLeft) || 0, ind = parseFloat(b.style.textIndent) || 0;
                        if (m === mIzq) { b.style.marginLeft = cm + 'cm'; } else { b.style.textIndent = (Math.round((cm - izq) * 4) / 4) + 'cm'; }
                    });
                    posicionarMarcas();
                };
                var fin = function () { m.removeEventListener('pointermove', mover); m.removeEventListener('pointerup', fin); R.marcarSucio(); };
                m.addEventListener('pointermove', mover); m.addEventListener('pointerup', fin);
            });
        });
        dibujarRegla();
    }
    function dibujarRegla() {
        var med = R.medidas(); if (!regla || !med) return;
        var CM = 37.7953, pista = regla.firstChild, h = '';
        regla.style.width = med.w + 'px'; regla.style.zoom = R.pagina.style.zoom || 1;
        h += '<span class="rd-reg-margen" style="left:0;width:' + med.izq + 'px"></span><span class="rd-reg-margen" style="right:0;width:' + med.der + 'px"></span>';
        for (var i = 0; i * CM <= med.w; i++) h += '<span class="rd-reg-num" style="left:' + (i * CM + 2) + 'px">' + i + '</span>';
        Array.prototype.slice.call(pista.querySelectorAll('.rd-reg-margen,.rd-reg-num')).forEach(function (n) { n.remove(); });
        pista.insertAdjacentHTML('afterbegin', h);
        posicionarMarcas();
    }
    function posicionarMarcas() {
        var med = R.medidas(); if (!regla || !med) return;
        var b = R.bloqueActual(), izq = 0, ind = 0;
        if (b) { var cs = getComputedStyle(b); izq = parseFloat(cs.marginLeft) || 0; ind = parseFloat(cs.textIndent) || 0; }
        mIzq.style.left = (med.izq + izq - 5) + 'px'; mPrim.style.left = (med.izq + izq + ind - 5) + 'px';
    }
    boton('rd-x-regla', function () {
        if (!regla) crearRegla();
        regla.hidden = !regla.hidden; $('rd-x-regla').classList.toggle('activo', !regla.hidden);
        if (!regla.hidden) dibujarRegla();
    });
    document.addEventListener('selectionchange', function () { if (regla && !regla.hidden) posicionarMarcas(); });
    ['rd-papel', 'rd-orient', 'rd-m-sup', 'rd-m-inf', 'rd-m-izq', 'rd-m-der'].forEach(function (id) { $(id).addEventListener('input', function () { setTimeout(function () { if (regla && !regla.hidden) dibujarRegla(); }, 0); }); });
    $('rd-zoom').addEventListener('change', function () { if (regla && !regla.hidden) dibujarRegla(); });

    // ================= Vista de dos páginas =================
    var parDos = 0;
    function pintarDos() {
        var med = R.medidas(), cuerpo = $('rd-x-dos-cuerpo'), n = Math.max(1, Math.round(parseFloat(hoja.style.minHeight) / med.h));
        parDos = Math.max(0, Math.min(parDos, n - 1)); parDos -= parDos % 2;
        var ancho = Math.min(window.innerWidth * 0.94, 1100), esc1 = (ancho / 2 - 24) / med.w;
        cuerpo.innerHTML = '';
        [parDos, parDos + 1].forEach(function (pg) {
            var caja = document.createElement('div'); caja.className = 'rd-dos-pagina';
            caja.style.width = Math.round(med.w * esc1) + 'px'; caja.style.height = Math.round(med.h * esc1) + 'px';
            if (pg < n) {
                var copia = document.createElement('div'); copia.className = 'rd-hoja rd-mini';
                copia.style.cssText = 'width:' + med.w + 'px;padding:' + hoja.style.padding + ';transform-origin:0 0;transform:scale(' + esc1 + ') translateY(' + (-pg * med.h) + 'px);position:absolute;left:0;top:0';
                copia.innerHTML = hoja.innerHTML;
                copia.querySelectorAll('[id]').forEach(function (x) { x.removeAttribute('id'); });
                caja.appendChild(copia);
            } else caja.className += ' vacia';
            cuerpo.appendChild(caja);
        });
        $('rd-x-dos-tit').textContent = 'Páginas ' + (parDos + 1) + (parDos + 1 < n ? ' y ' + (parDos + 2) : '') + ' de ' + n;
        $('rd-x-dos-ant').disabled = parDos === 0; $('rd-x-dos-sig').disabled = parDos + 2 >= n;
    }
    boton('rd-x-dos', function () { parDos = 0; pintarDos(); $('rd-dlg-dos').showModal(); });
    $('rd-x-dos-ant').addEventListener('click', function () { parDos -= 2; pintarDos(); });
    $('rd-x-dos-sig').addEventListener('click', function () { parDos += 2; pintarDos(); });
    $('rd-x-dos-cerrar').addEventListener('click', function () { $('rd-dlg-dos').close(); });

    // ================= Dictado por voz =================
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition, rec = null, dictando = false;
    boton('rd-x-dictado', function () {
        var b = $('rd-x-dictado');
        if (!SR) { estado('Este navegador no incluye dictado por voz (prueba en Chrome o Edge)', true); return; }
        if (dictando) { dictando = false; if (rec) rec.stop(); b.classList.remove('activo'); estado('Dictado detenido'); return; }
        rec = new SR(); rec.lang = $('rd-idioma').value === 'en' ? 'en-US' : 'es-MX'; rec.continuous = true; rec.interimResults = false;
        rec.onresult = function (e) {
            for (var i = e.resultIndex; i < e.results.length; i++) if (e.results[i].isFinal) { R.restaurarSeleccion(); R.exec('insertText', e.results[i][0].transcript.trim() + ' '); }
        };
        rec.onerror = function (e) { dictando = false; b.classList.remove('activo'); estado('Dictado: ' + (e.error === 'not-allowed' ? 'permiso de micrófono denegado' : e.error === 'network' ? 'este navegador no pudo conectar con el servicio de voz' : e.error), true); };
        rec.onend = function () { if (dictando) { try { rec.start(); } catch (e) { /* ya iniciado */ } } else b.classList.remove('activo'); };
        try { rec.start(); dictando = true; b.classList.add('activo'); estado('Dictando… habla y el texto aparece donde está el cursor'); } catch (e) { estado('No se pudo iniciar el dictado', true); }
    });

    // ================= Exportar solo la selección =================
    seleccionar('rd-x-expsel', function (formato) {
        R.restaurarSeleccion();
        var s = R.seleccion(); if (!s || s.isCollapsed) { estado('Selecciona primero el texto que quieres exportar', true); return; }
        var cont = document.createElement('div'); cont.appendChild(s.getRangeAt(0).cloneContents());
        cont.querySelectorAll('.rd-sel').forEach(function (n) { n.classList.remove('rd-sel'); });
        estado('Generando el archivo…');
        var fd = new FormData(); fd.append('id', C.id); fd.append('formato', formato); fd.append('html', cont.innerHTML);
        fetch(C.api.exportarSeleccion, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'No se pudo exportar'); });
            var nombre = r.headers.get('X-Nombre-Archivo') || ('seleccion.' + formato);
            return r.blob().then(function (blob) {
                var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = nombre; document.body.appendChild(a); a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
                estado('Selección exportada: ' + nombre);
            });
        }).catch(function (e) { estado(e.message || 'No se pudo exportar', true); });
    });

    // ================= Dibujo (herramientas de lienzo) =================
    (function () {
        var dlg = $('rd-dlg-dibujo'), cv = $('rd-dib-canvas'); if (!dlg || !cv) return;
        var cx = cv.getContext('2d'), formas = [], herr = 'lapiz', actual = null;
        function opciones() { return { color: $('rd-dib-color').value, relleno: $('rd-dib-conrelleno').checked ? $('rd-dib-relleno').value : '', grosor: +$('rd-dib-grosor').value || 3 }; }
        function flecha(a, b, g) {
            var ang = Math.atan2(b[1] - a[1], b[0] - a[0]), l = 8 + g * 2;
            cx.beginPath(); cx.moveTo(a[0], a[1]); cx.lineTo(b[0], b[1]); cx.stroke();
            cx.beginPath(); cx.moveTo(b[0], b[1]); cx.lineTo(b[0] - l * Math.cos(ang - 0.45), b[1] - l * Math.sin(ang - 0.45)); cx.moveTo(b[0], b[1]); cx.lineTo(b[0] - l * Math.cos(ang + 0.45), b[1] - l * Math.sin(ang + 0.45)); cx.stroke();
        }
        function pintarForma(f) {
            cx.strokeStyle = f.color; cx.fillStyle = f.relleno || 'transparent'; cx.lineWidth = f.grosor; cx.lineCap = 'round'; cx.lineJoin = 'round';
            var a = f.a, b = f.b;
            if (f.t === 'lapiz') { cx.beginPath(); f.pts.forEach(function (p, i) { if (i) cx.lineTo(p[0], p[1]); else cx.moveTo(p[0], p[1]); }); if (f.pts.length === 1) cx.lineTo(f.pts[0][0] + 0.1, f.pts[0][1] + 0.1); cx.stroke(); }
            else if (f.t === 'linea') { cx.beginPath(); cx.moveTo(a[0], a[1]); cx.lineTo(b[0], b[1]); cx.stroke(); }
            else if (f.t === 'flecha') flecha(a, b, f.grosor);
            else if (f.t === 'rect') { if (f.relleno) cx.fillRect(a[0], a[1], b[0] - a[0], b[1] - a[1]); cx.strokeRect(a[0], a[1], b[0] - a[0], b[1] - a[1]); }
            else if (f.t === 'elipse') { cx.beginPath(); cx.ellipse((a[0] + b[0]) / 2, (a[1] + b[1]) / 2, Math.abs(b[0] - a[0]) / 2, Math.abs(b[1] - a[1]) / 2, 0, 0, 7); if (f.relleno) cx.fill(); cx.stroke(); }
            else if (f.t === 'cota') {
                var ang = Math.atan2(b[1] - a[1], b[0] - a[0]), n = 8 + f.grosor;
                cx.beginPath(); cx.moveTo(a[0], a[1]); cx.lineTo(b[0], b[1]);
                [a, b].forEach(function (p) { cx.moveTo(p[0] - n * Math.sin(ang), p[1] + n * Math.cos(ang)); cx.lineTo(p[0] + n * Math.sin(ang), p[1] - n * Math.cos(ang)); }); cx.stroke();
            }
            else if (f.t === 'texto') { cx.fillStyle = f.color; cx.font = (10 + f.grosor * 3) + 'px Calibri, Arial, sans-serif'; cx.textBaseline = 'top'; cx.fillText(f.txt, a[0], a[1]); }
        }
        function fondoLienzo(transp) {
            var modo = $('rd-dib-fondo').value;
            if (modo === 'transparente' && transp) { cx.clearRect(0, 0, cv.width, cv.height); return; }
            cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height);
            if (modo === 'cuadricula') {
                cx.strokeStyle = '#cbd5e1'; cx.lineWidth = 1; cx.beginPath();
                for (var x = 0; x <= cv.width; x += 25) { cx.moveTo(x + .5, 0); cx.lineTo(x + .5, cv.height); }
                for (var y = 0; y <= cv.height; y += 25) { cx.moveTo(0, y + .5); cx.lineTo(cv.width, y + .5); }
                cx.stroke();
            }
        }
        function redibujar(transp) { fondoLienzo(transp); formas.forEach(pintarForma); if (actual) pintarForma(actual); }
        function pos(e) { var r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; }
        function cerca(f, p) {
            var t = 8 + f.grosor;
            if (f.t === 'lapiz') return f.pts.some(function (q) { return Math.hypot(q[0] - p[0], q[1] - p[1]) < t; });
            if (f.t === 'texto') return p[0] >= f.a[0] - t && p[0] <= f.a[0] + f.txt.length * (10 + f.grosor * 3) * .6 + t && p[1] >= f.a[1] - t && p[1] <= f.a[1] + (10 + f.grosor * 3) + t;
            var x0 = Math.min(f.a[0], f.b[0]) - t, x1 = Math.max(f.a[0], f.b[0]) + t, y0 = Math.min(f.a[1], f.b[1]) - t, y1 = Math.max(f.a[1], f.b[1]) + t;
            return p[0] >= x0 && p[0] <= x1 && p[1] >= y0 && p[1] <= y1;
        }
        cv.addEventListener('pointerdown', function (e) {
            var p = pos(e), o = opciones();
            if (herr === 'borrar') { for (var i = formas.length - 1; i >= 0; i--) if (cerca(formas[i], p)) { formas.splice(i, 1); break; } redibujar(); return; }
            if (herr === 'texto') {
                // Diálogo con campo (modal estilizado; nunca prompt()).
                pedirTexto({
                    sobreTitulo: 'Dibujo',
                    titulo: 'Texto sobre el dibujo',
                    etiqueta: 'Texto',
                    placeholder: 'Escribe aquí…',
                    maximo: 120,
                    textoConfirmar: 'Insertar'
                }).then(function (t) {
                    if (t && t.trim()) { o.t = 'texto'; o.a = p; o.txt = t.trim(); formas.push(o); redibujar(); }
                });
                return;
            }
            cv.setPointerCapture(e.pointerId); o.t = herr; o.a = p; o.b = p; if (herr === 'lapiz') o.pts = [p]; actual = o;
        });
        cv.addEventListener('pointermove', function (e) {
            if (!actual) return; var p = pos(e);
            if (actual.t === 'lapiz') actual.pts.push(p); else actual.b = e.shiftKey && (actual.t === 'rect' || actual.t === 'elipse') ? [p[0], actual.a[1] + (p[0] - actual.a[0])] : p;
            redibujar();
        });
        ['pointerup', 'pointercancel'].forEach(function (ev) { cv.addEventListener(ev, function () { if (actual) { formas.push(actual); actual = null; redibujar(); } }); });
        $('rd-dib-herr').addEventListener('click', function (e) {
            var b = e.target.closest('[data-dib]'); if (!b) return; herr = b.dataset.dib;
            this.querySelectorAll('[data-dib]').forEach(function (x) { x.classList.toggle('activo', x === b); });
            cv.style.cursor = herr === 'borrar' ? 'not-allowed' : herr === 'texto' ? 'text' : 'crosshair';
        });
        $('rd-dib-fondo').addEventListener('change', function () { redibujar(); });
        $('rd-dib-tam').addEventListener('change', function () { var m = this.value.split('x'); cv.width = +m[0]; cv.height = +m[1]; redibujar(); });
        $('rd-dib-deshacer').addEventListener('click', function () { formas.pop(); redibujar(); });
        $('rd-dib-limpiar').addEventListener('click', async function () { if (!formas.length || await window.confirmarModal({ titulo: '¿Borrar todo el dibujo?', mensaje: 'Se quitarán todos los trazos.', aceptar: 'Borrar' })) { formas = []; redibujar(); } });
        $('rd-dib-cancelar').addEventListener('click', function () { dlg.close(); });
        $('rd-dib-insertar').addEventListener('click', function () {
            if (!formas.length) { estado('Dibuja algo primero', true); return; }
            redibujar($('rd-dib-fondo').value === 'transparente');
            cv.toBlob(function (blob) {
                redibujar();
                if (!blob) { estado('No se pudo crear la imagen', true); return; }
                R.subirImagen(new File([blob], 'dibujo.png', { type: 'image/png' }), function (src) { dlg.close(); hoja.focus(); R.restaurarSeleccion(); R.ponerImagen(src, 600); formas = []; redibujar(); });
            }, 'image/png');
        });
        boton('rd-x-dibujo', function () { redibujar(); dlg.showModal(); });
    })();
})();
