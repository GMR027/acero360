/* SheetFlow: editor de tablas (cuadrícula con selección, fórmulas, formato por celda, imágenes, pegado desde Excel, deshacer y autoguardado).
 * Datos (ver Classes\Sheetflow): { cols:[{n,t,a,tot,w}], rows:[[texto]], calc, tot, estilo:{enc,encTxt,zebra,bordes}, fmt:{"r,c":{b,i,u,fs,tc,bg,al,bt,br,bb,bl,im}} }
 * Una celda que empieza con «=» es una fórmula. El servidor vuelve a calcular todo al guardar (Classes\SheetflowFormulas: mismo lenguaje que aquí).
 * El encabezado es la fila 1 (como en Excel): su formato vive en la fila -1 de fmt. */
(function () {
    'use strict';
    var C = window.SF_CONFIG;
    if (!C) return;
    var $ = function (id) { return document.getElementById(id); };
    var S = C.datos;
    S.calc = S.calc && !Array.isArray(S.calc) ? S.calc : {};
    S.tot = S.tot && !Array.isArray(S.tot) ? S.tot : {};
    var grid = $('sf-grid'), area = $('sf-area'), fx = $('sf-fx');
    var version = C.version, sucio = false, guardando = false, temporizador = null;
    var sel = { r0: 0, c0: 0, r1: 0, c1: 0 };   // rango elegido; (r0,c0) es el ancla. r = -1 es el encabezado
    var editor = null;                           // {r,c,input,previo} mientras se edita una celda
    var pasado = [], futuro = [];
    var MAX_C = C.max.cols, MAX_F = C.max.filas;
    var zoom = 100;

    // Formatos por celda en matriz paralela a rows (sty[r][c]) y al encabezado (hsty[c]); en el JSON viajan como mapa disperso fmt
    var sty = [], hsty = [];
    (function () {
        var f = S.fmt && !Array.isArray(S.fmt) ? S.fmt : {};
        sty = S.rows.map(function (fila) { return fila.map(function () { return null; }); });
        hsty = S.cols.map(function () { return null; });
        Object.keys(f).forEach(function (k) {
            var p = k.split(','), r = +p[0], c = +p[1];
            if (r < 0) hsty[c] = f[k]; else if (sty[r]) sty[r][c] = f[k];
        });
    })();
    function fmtMapa() {
        var o = {};
        hsty.forEach(function (f, c) { if (f && Object.keys(f).length) o['-1,' + c] = f; });
        sty.forEach(function (fila, r) { fila.forEach(function (f, c) { if (f && Object.keys(f).length) o[r + ',' + c] = f; }); });
        return o;
    }
    function getSt(r, c) { return r < 0 ? hsty[c] : (sty[r] ? sty[r][c] : null); }
    function setSt(r, c, f) { var vacio = !f || !Object.keys(f).length; if (r < 0) hsty[c] = vacio ? null : f; else sty[r][c] = vacio ? null : f; }

    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function estado(t, mal) { var e = $('sf-estado'); e.textContent = t || ''; e.style.color = mal ? 'var(--danger, #dc2626)' : ''; }
    function letra(i) { var s = ''; for (i++; i > 0; i = Math.floor((i - 1) / 26)) s = String.fromCharCode(65 + (i - 1) % 26) + s; return s; }
    function indiceLetra(l) { var n = 0; for (var i = 0; i < l.length; i++) n = n * 26 + l.charCodeAt(i) - 64; return n - 1; }
    function nombreCelda(r, c) { return letra(c) + (r + 2); }
    var NUM = ['numero', 'moneda', 'porcentaje'];

    // ================= Valores y formato (igual que Classes\Sheetflow::formatear) =================
    function numero(v) {
        var t = String(v == null ? '' : v).replace(/[,$%\s]/g, '');
        return t !== '' && isFinite(t) ? parseFloat(t) : null;
    }
    function miles(n, dec) {
        var s = Math.abs(n).toFixed(dec);
        var p = s.split('.'), e = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        if (p[1]) { p[1] = p[1].replace(/0+$/, ''); }
        return (n < 0 && parseFloat(s) !== 0 ? '-' : '') + e + (p[1] ? '.' + p[1] : '');
    }
    function formatear(v, tipo) {
        v = String(v == null ? '' : v).trim();
        if (v === '') return '';
        var n = numero(v);
        if (tipo === 'numero') return n === null ? v : miles(n, 4);
        if (tipo === 'moneda') return n === null ? v : (n < 0 ? '-' : '') + '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (tipo === 'porcentaje') return n === null ? v : miles(n, 2) + '%';
        if (tipo === 'fecha') { var m = /^(\d{4})-(\d\d)-(\d\d)$/.exec(v); return m ? m[3] + '/' + m[2] + '/' + m[1] : v; }
        if (tipo === 'casilla') return valorEspecial(v, tipo) === '1' ? '\u2714' : '';
        if (tipo === 'semaforo') return SEMAFORO[valorEspecial(v, tipo)] || v;
        return v;
    }
    var SEMAFORO = { verde: 'Verde', amarillo: 'Amarillo', rojo: 'Rojo' };
    /** Valor guardado de una casilla ('1' o '') o un semáforo (verde|amarillo|rojo o ''); igual que Sheetflow::normalizarValor */
    function valorEspecial(v, tipo) {
        v = String(v == null ? '' : v).trim().toLowerCase();
        if (tipo === 'casilla') return ['1', 'si', 's\u00ed', 'x', 'true', 'yes', '\u2714', '\u2713'].indexOf(v) >= 0 ? '1' : '';
        v = { green: 'verde', yellow: 'amarillo', red: 'rojo' }[v] || v;
        return SEMAFORO[v] ? v : '';
    }
    function esFormula(t) { return /^\s*=/.test(t || ''); }
    /** Texto que se guarda al terminar de editar: fechas d/m/a → ISO y números sin formato */
    function normalizar(txt, tipo) {
        txt = String(txt).replace(/\r/g, '');
        if (esFormula(txt) || txt.trim() === '') return esFormula(txt) ? txt.trim() : '';
        if (tipo === 'fecha') {
            var m = /^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})$/.exec(txt.trim());
            if (m) { var a = m[3].length === 2 ? '20' + m[3] : m[3]; return a + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2); }
            return txt.trim();
        }
        if (NUM.indexOf(tipo) >= 0) { var n = numero(txt); return n === null ? txt.trim() : String(n); }
        if (tipo === 'casilla' || tipo === 'semaforo') return valorEspecial(txt, tipo);
        return txt;
    }

    // ================= Fórmulas =================
    // Errores de valor (#DIV/0!, #VALOR!…) viajan como valor ({e}); los de sintaxis cortan el análisis. Debe dar lo mismo que Classes\SheetflowFormulas.
    var errores = { ref: '#REF!', div: '#DIV/0!', val: '#VALOR!', ciclo: '#CICLO!', nom: '#NOMBRE?' };
    function valorCelda(r, c, visitando) {
        if (r < -1 || c < 0) return { e: errores.ref };
        if (r === -1) return c < S.cols.length ? { s: S.cols[c].n } : { v: 0, vacio: true };   // A1 = encabezado
        if (r >= S.rows.length || c >= S.cols.length) return { v: 0, vacio: true };           // fuera de la tabla = vacía, como en Excel
        var t = S.rows[r][c];
        if (!esFormula(t)) { var n = numero(t); return t === '' ? { v: 0, vacio: true } : n === null ? { s: t } : { v: n }; }
        var k = r + ',' + c;
        if (visitando[k]) return { e: errores.ciclo };
        visitando[k] = true;
        var res = evaluar(t.replace(/^\s*=/, ''), visitando);
        delete visitando[k];
        return res;
    }
    function evaluar(src, visitando) {
        var i = 0;
        function esp() { while (i < src.length && /\s/.test(src[i])) i++; }
        function sintaxis(e) { var x = new Error(e); x.sf = e; throw x; }
        function fallo(e) { var x = new Error(e); x.sf = e; x.valor = true; throw x; }
        function seguro(f) { try { return f(); } catch (e) { if (e.valor) return { e: e.sf }; throw e; } }
        function num1(x) {
            if (x.e) fallo(x.e);
            if (x.rango) fallo(errores.val);
            if (x.s !== undefined) { var n = numero(x.s); if (n === null) fallo(errores.val); return n; }
            return x.v;
        }
        function esTexto(x) { return x.s !== undefined && numero(x.s) === null; }
        function txt(x) {
            if (x.e) fallo(x.e);
            if (x.rango) fallo(errores.val);
            return x.s !== undefined ? x.s : (x.vacio ? '' : String(+x.v.toFixed(10)));
        }
        function comparacion() {
            var a = concat(); esp();
            var m = /^(<=|>=|<>|<|>|=)/.exec(src.slice(i));
            if (!m) return a;
            i += m[1].length;
            var b = concat();
            return seguro(function () {
                var x, y;
                if (esTexto(a) || esTexto(b)) { var s1 = txt(a).toLowerCase(), s2 = txt(b).toLowerCase(); x = s1 < s2 ? -1 : s1 > s2 ? 1 : 0; y = 0; }
                else { x = num1(a); y = num1(b); }
                return { v: ({ '=': x === y, '<>': x !== y, '<': x < y, '>': x > y, '<=': x <= y, '>=': x >= y })[m[1]] ? 1 : 0 };
            });
        }
        function concat() {
            var a = suma(); esp();
            while (src[i] === '&') { i++; var b = suma(); var aa = a; a = seguro(function () { return { s: txt(aa) + txt(b) }; }); esp(); }
            return a;
        }
        function suma() {
            var a = prod(); esp();
            while (i < src.length && (src[i] === '+' || src[i] === '-')) { var op = src[i++], b = prod(), aa = a; a = seguro(function () { return { v: op === '+' ? num1(aa) + num1(b) : num1(aa) - num1(b) }; }); esp(); }
            return a;
        }
        function prod() {
            var a = pot(); esp();
            while (i < src.length && (src[i] === '*' || src[i] === '/')) {
                var op = src[i++], b = pot(), aa = a;
                a = seguro(function () {
                    if (op === '/') { var d = num1(b); if (d === 0) fallo(errores.div); return { v: num1(aa) / d }; }
                    return { v: num1(aa) * num1(b) };
                });
                esp();
            }
            return a;
        }
        function pot() {
            var a = unario(); esp();
            if (src[i] === '^') { i++; var b = pot(), aa = a; a = seguro(function () { return { v: Math.pow(num1(aa), num1(b)) }; }); }
            return a;
        }
        function unario() {
            esp();
            if (src[i] === '-') { i++; var x = unario(); return seguro(function () { return { v: -num1(x) }; }); }
            if (src[i] === '+') { i++; return unario(); }
            return primario();
        }
        function args() {
            var out = []; esp();
            if (src[i] === ')') { i++; return out; }
            for (;;) {
                out.push(comparacion()); esp();
                if (src[i] === ',' || src[i] === ';') { i++; continue; }
                if (src[i] === ')') { i++; return out; }
                sintaxis(errores.val);
            }
        }
        function lista(a) {   // aplana rangos: números sueltos y rangos de celdas
            var nums = [];
            a.forEach(function (x) {
                if (x.rango) x.rango.forEach(function (c) { if (c.e) fallo(c.e); if (c.v !== undefined && !c.vacio) nums.push(c.v); });
                else if (x.e) fallo(x.e);
                else if (x.v !== undefined) nums.push(x.v);
                else if (x.s !== undefined) { var n = numero(x.s); if (n !== null) nums.push(n); }
            });
            return nums;
        }
        function cumple(c, crit) {
            if (c.e) return false;
            var t = crit.s !== undefined ? crit.s : (crit.v !== undefined ? String(+crit.v.toFixed(10)) : ''), op = '=', m = /^(<=|>=|<>|<|>|=)([\s\S]*)$/.exec(t);
            if (m) { op = m[1]; t = m[2]; }
            var valor = c.s !== undefined ? c.s : (c.vacio ? '' : String(+c.v.toFixed(10))), nc = numero(valor), nt = numero(t);
            if (nt !== null && nc !== null && valor !== '') return ({ '=': nc === nt, '<>': nc !== nt, '<': nc < nt, '>': nc > nt, '<=': nc <= nt, '>=': nc >= nt })[op];
            if (op === '=' || op === '<>') {
                var re = new RegExp('^' + t.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$', 'i');
                return re.test(valor) === (op === '=');
            }
            if (nt !== null || valor === '') return false;
            var k = valor.toLowerCase() < t.toLowerCase() ? -1 : valor.toLowerCase() > t.toLowerCase() ? 1 : 0;
            return ({ '<': k < 0, '>': k > 0, '<=': k <= 0, '>=': k >= 0 })[op];
        }
        function funcion(f, a) {
            var n;
            switch (f) {
                case 'SUMA': case 'SUM': return { v: lista(a).reduce(function (p, q) { return p + q; }, 0) };
                case 'PROMEDIO': case 'AVERAGE': case 'PROM': n = lista(a); if (!n.length) fallo(errores.div); return { v: n.reduce(function (p, q) { return p + q; }, 0) / n.length };
                case 'MIN': n = lista(a); return { v: n.length ? Math.min.apply(null, n) : 0 };
                case 'MAX': n = lista(a); return { v: n.length ? Math.max.apply(null, n) : 0 };
                case 'CONTAR': case 'COUNT': return { v: lista(a).length };
                case 'REDONDEAR': case 'ROUND': { var d = a[1] ? num1(a[1]) : 0, k = Math.pow(10, d); return { v: Math.round(num1(a[0] || { v: 0 }) * k) / k }; }
                case 'SI': case 'IF': return num1(a[0] || { v: 0 }) ? (a[1] || { v: 0 }) : (a[2] || { v: 0 });
                case 'Y': case 'AND': case 'O': case 'OR': {
                    if (!a.length) fallo(errores.val);
                    var y = f === 'Y' || f === 'AND';
                    for (var q = 0; q < a.length; q++) {
                        var items = a[q].rango || [a[q]];
                        for (var z = 0; z < items.length; z++) {
                            var v = items[z];
                            if (v.e) fallo(v.e);
                            if (v.vacio) continue;
                            var b = num1(v) !== 0;
                            if (y && !b) return { v: 0 };
                            if (!y && b) return { v: 1 };
                        }
                    }
                    return { v: y ? 1 : 0 };
                }
                case 'NO': case 'NOT': return { v: num1(a[0] || { v: 0 }) === 0 ? 1 : 0 };
                case 'SI.ERROR': case 'IFERROR': return (a[0] || {}).e ? (a[1] || { s: '' }) : (a[0] || { v: 0 });
                case 'SUMAR.SI': case 'SUMIF': case 'CONTAR.SI': case 'COUNTIF': case 'PROMEDIO.SI': case 'AVERAGEIF': {
                    var rango = a[0] && (a[0].rango || [a[0]]);
                    if (!rango || !a[1]) fallo(errores.val);
                    var cuenta = f === 'CONTAR.SI' || f === 'COUNTIF', prom = f === 'PROMEDIO.SI' || f === 'AVERAGEIF';
                    var sumar = cuenta ? null : ((a[2] && a[2].rango) || rango), cnt = 0, tot = 0;
                    rango.forEach(function (c, idx) {
                        if (!cumple(c, a[1])) return;
                        cnt++;
                        if (sumar) { var vv = sumar[idx] || { v: 0, vacio: true }; if (vv.e) fallo(vv.e); var x = vv.s !== undefined ? numero(vv.s) : vv.v; tot += x === null ? 0 : x; }
                    });
                    if (cuenta) return { v: cnt };
                    if (prom) { if (!cnt) fallo(errores.div); return { v: tot / cnt }; }
                    return { v: tot };
                }
                default: fallo(errores.nom);
            }
        }
        function primario() {
            esp();
            var rest = src.slice(i), m;
            if ((m = /^(\d+([.,]\d+)?|[.]\d+)/.exec(rest))) { i += m[0].length; var v = parseFloat(m[0].replace(',', '.')); esp(); if (src[i] === '%') { i++; v /= 100; } return { v: v }; }
            if ((m = /^(VERDADERO|FALSO|TRUE|FALSE)\b/i.exec(rest))) { i += m[0].length; return { v: /^(V|T)/i.test(m[1]) ? 1 : 0 }; }
            if (rest.indexOf('#REF!') === 0) { i += 5; return { e: errores.ref }; }
            if (src[i] === '(') { i++; var x = comparacion(); esp(); if (src[i] !== ')') sintaxis(errores.val); i++; return x; }
            if (src[i] === '"') { var j = src.indexOf('"', i + 1); if (j < 0) sintaxis(errores.val); var s = src.slice(i + 1, j); i = j + 1; return { s: s }; }
            if ((m = /^\$?([A-Za-z]{1,2})\$?(\d+)(?::\$?([A-Za-z]{1,2})\$?(\d+))?(?![A-Za-z(])/.exec(rest))) {
                i += m[0].length;
                var c0 = indiceLetra(m[1].toUpperCase()), r0 = parseInt(m[2], 10) - 2;
                if (m[3]) {
                    var c1 = indiceLetra(m[3].toUpperCase()), r1 = parseInt(m[4], 10) - 2, out = [];
                    for (var r = Math.min(r0, r1); r <= Math.max(r0, r1); r++) for (var c = Math.min(c0, c1); c <= Math.max(c0, c1); c++) out.push(valorCelda(r, c, visitando));
                    return { rango: out };
                }
                return valorCelda(r0, c0, visitando);
            }
            if ((m = /^([A-Za-zÁÉÍÓÚáéíóú.]+)\s*\(/.exec(rest))) {
                i += m[0].length;
                var f = m[1].toUpperCase().replace(/Á/g, 'A').replace(/É/g, 'E').replace(/Í/g, 'I').replace(/Ó/g, 'O').replace(/Ú/g, 'U'), a = args();
                return seguro(function () { return funcion(f, a); });
            }
            sintaxis(errores.val);
        }
        try {
            var r = comparacion(); esp();
            if (i < src.length || r.rango) sintaxis(errores.val);
            return r;
        } catch (e) { return { e: e.sf || errores.val }; }
    }
    function texto(res) {
        if (res.e) return res.e;
        if (res.s !== undefined) return res.s;
        return String(+res.v.toFixed(10));
    }
    function recalcular() {
        var calc = {};
        S.rows.forEach(function (fila, r) {
            fila.forEach(function (t, c) { if (esFormula(t)) calc[r + ',' + c] = texto(valorCelda(r, c, {})); });
        });
        S.calc = calc;
        var tot = {};
        S.cols.forEach(function (col, c) {
            if (!col.tot) return;
            var vals = [], llenas = 0;
            S.rows.forEach(function (fila, r) {
                var v = esFormula(fila[c]) ? calc[r + ',' + c] : fila[c];
                if (v !== '' && v != null) llenas++;
                var n = numero(v); if (n !== null) vals.push(n);
            });
            var res = col.tot === 'cuenta' ? llenas : !vals.length ? 0 : col.tot === 'suma' ? vals.reduce(function (p, q) { return p + q; }, 0)
                : col.tot === 'promedio' ? vals.reduce(function (p, q) { return p + q; }, 0) / vals.length : col.tot === 'min' ? Math.min.apply(null, vals) : Math.max.apply(null, vals);
            tot[c] = String(+res.toFixed(10));
        });
        S.tot = tot;
    }
    function mostrado(r, c) {
        var t = S.rows[r][c];
        return esFormula(t) ? formatear(S.calc[r + ',' + c] || '', S.cols[c].t) : formatear(t, S.cols[c].t);
    }

    // ================= Dibujo =================
    function rangoNorm() { return { r0: Math.min(sel.r0, sel.r1), r1: Math.max(sel.r0, sel.r1), c0: Math.min(sel.c0, sel.c1), c1: Math.max(sel.c0, sel.c1) }; }
    var ALIN = { izquierda: 'left', centro: 'center', derecha: 'right' };
    var LADOS = { bt: 'top', br: 'right', bb: 'bottom', bl: 'left' };
    /** Letra negra o blanca según la luminosidad del relleno (igual que Classes\Sheetflow::contraste) */
    function contraste(hex) {
        var r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) > 140 ? '#111111' : '#ffffff';
    }
    /** CSS de un formato de celda (mismo criterio que Classes\Sheetflow::cssFmt, pero en px) */
    function cssFmt(f) {
        if (!f) return '';
        var c = '';
        if (f.b) c += 'font-weight:bold;';
        if (f.i) c += 'font-style:italic;';
        if (f.u) c += 'text-decoration:underline;';
        if (f.fs) c += 'font-size:' + f.fs + 'px;';
        if (f.tc) c += 'color:' + f.tc + ';';
        else if (f.bg) c += 'color:' + contraste(f.bg) + ';';   // con relleno y sin color de letra, la letra se ajusta para que se lea
        if (f.bg) c += 'background-color:' + f.bg + ';';
        if (f.al) c += 'text-align:' + ALIN[f.al] + ';';
        Object.keys(LADOS).forEach(function (k) {
            if (!f[k]) return;
            if (f[k] === 'n') { c += 'border-' + LADOS[k] + ':none;'; return; }
            var p = f[k].split('|');
            c += 'border-' + LADOS[k] + ':' + p[0] + 'px ' + p[1] + ' ' + p[2] + ';';
        });
        return c;
    }
    function imgHtml(f) { return f && f.im ? '<img class="sf-img" src="' + esc(f.im) + '" alt="">' : ''; }
    function dibujar() {
        recalcular();
        var e = S.estilo, h = '<colgroup><col class="sf-col-n">';
        S.cols.forEach(function (col) { h += '<col' + (col.w ? ' style="width:' + col.w + 'px;min-width:' + col.w + 'px"' : '') + '>'; });
        h += '</colgroup><thead><tr><th class="sf-esq"></th>';
        S.cols.forEach(function (col, c) { h += '<th class="sf-letra" data-letra="' + c + '">' + letra(c) + '<i class="sf-tirador" data-tir="' + c + '" title="Arrastra para cambiar el ancho · doble clic: automático"></i></th>'; });
        h += '</tr><tr><th class="sf-num" data-fila="-1">1</th>';
        S.cols.forEach(function (col, c) { h += '<th class="sf-enc" data-r="-1" data-c="' + c + '" style="background:' + e.enc + ';color:' + e.encTxt + ';' + cssFmt(hsty[c]) + '">' + imgHtml(hsty[c]) + esc(col.n) + '</th>'; });
        h += '</tr></thead><tbody>';
        S.rows.forEach(function (fila, r) {
            h += '<tr' + (e.zebra && r % 2 ? ' class="sf-alt"' : '') + '><th class="sf-num" data-fila="' + r + '">' + (r + 2) + '</th>';
            S.cols.forEach(function (col, c) {
                var v = mostrado(r, c), err = /^#[A-Z/0?!]+$/.test(v), f = sty[r][c];
                h += '<td data-r="' + r + '" data-c="' + c + '" style="text-align:' + ALIN[col.a] + ';' + cssFmt(f) + '"' + (esFormula(fila[c]) ? ' class="sf-f' + (err ? ' sf-err' : '') + '"' : '') + '>' + imgHtml(f) + esc(v) + '</td>';
            });
            h += '</tr>';
        });
        if (S.cols.some(function (col) { return col.tot; })) {
            h += '<tr class="sf-totales"><th class="sf-num">Σ</th>';
            S.cols.forEach(function (col, c) {
                var t = col.tot && S.tot[c] != null ? (col.tot === 'cuenta' ? S.tot[c] : formatear(S.tot[c], col.t)) : '';
                h += '<td style="text-align:' + ALIN[col.a] + '">' + (c === 0 && !t ? 'Total' : esc(t)) + '</td>';
            });
            h += '</tr>';
        }
        grid.className = 'sf-grid' + (e.bordes ? '' : ' sf-sin-bordes');
        grid.style.zoom = zoom / 100;
        grid.innerHTML = h + '</tbody>';
        pintarSeleccion();
        sincronizarBarra();
    }
    function celdaEl(r, c) { return grid.querySelector(r < 0 ? 'th.sf-enc[data-c="' + c + '"]' : 'td[data-r="' + r + '"][data-c="' + c + '"]'); }
    function pintarSeleccion() {
        grid.querySelectorAll('.sf-sel,.sf-ancla').forEach(function (x) { x.classList.remove('sf-sel', 'sf-ancla'); });
        grid.querySelectorAll('.sf-letra-sel,.sf-num-sel').forEach(function (x) { x.classList.remove('sf-letra-sel', 'sf-num-sel'); });
        var n = rangoNorm();
        for (var r = n.r0; r <= n.r1; r++) for (var c = n.c0; c <= n.c1; c++) { var el = celdaEl(r, c); if (el) el.classList.add('sf-sel'); }
        var an = celdaEl(sel.r0, sel.c0); if (an) an.classList.add('sf-ancla');
        for (var cc = n.c0; cc <= n.c1; cc++) { var l = grid.querySelector('th.sf-letra[data-letra="' + cc + '"]'); if (l) l.classList.add('sf-letra-sel'); }
        for (var rr = n.r0; rr <= n.r1; rr++) { var f = grid.querySelector('th.sf-num[data-fila="' + rr + '"]'); if (f) f.classList.add('sf-num-sel'); }
        $('sf-ref').textContent = nombreCelda(sel.r0, sel.c0) + (n.r0 !== n.r1 || n.c0 !== n.c1 ? ':' + nombreCelda(n.r1, n.c1) : '');
        fx.value = sel.r0 < 0 ? S.cols[sel.c0].n : S.rows[sel.r0][sel.c0];
        sincronizarBarra();
        estadistica();
    }
    /** Resumen de las celdas elegidas (como la barra de estado de Excel) */
    function estadistica() {
        var n = rangoNorm(), vals = [], llenas = 0;
        if (n.r0 === n.r1 && n.c0 === n.c1) { $('sf-estadistica').textContent = ''; return; }
        for (var r = Math.max(0, n.r0); r <= n.r1; r++) for (var c = n.c0; c <= n.c1; c++) {
            var t = S.rows[r][c], v = esFormula(t) ? S.calc[r + ',' + c] : t;
            if (v !== '' && v != null) llenas++;
            var x = numero(v); if (x !== null) vals.push(x);
        }
        var s = 'Cuenta: ' + llenas;
        if (vals.length) {
            var suma = vals.reduce(function (p, q) { return p + q; }, 0);
            s += ' · Suma: ' + miles(suma, 4) + ' · Promedio: ' + miles(suma / vals.length, 4) + ' · Mín: ' + miles(Math.min.apply(null, vals), 4) + ' · Máx: ' + miles(Math.max.apply(null, vals), 4);
        }
        $('sf-estadistica').textContent = s;
    }
    function sincronizarBarra() {
        var cc = Math.min(sel.c0, S.cols.length - 1), col = S.cols[cc] || S.cols[0], f = getSt(sel.r0, cc) || {};
        $('sf-tipo').value = col.t;
        $('sf-opciones').hidden = col.t !== 'lista'; $('sf-opciones').value = (col.op || []).join(', ');
        $('sf-total').value = col.tot || '';
        $('sf-c-enc').value = S.estilo.enc; $('sf-c-txt').value = S.estilo.encTxt;
        $('sf-zebra').checked = !!S.estilo.zebra; $('sf-bordes').checked = !!S.estilo.bordes;
        document.querySelectorAll('[data-alin]').forEach(function (b) { b.classList.toggle('activo', b.dataset.alin === (f.al || col.a)); });
        document.querySelectorAll('[data-fmt]').forEach(function (b) { b.classList.toggle('activo', !!f[b.dataset.fmt]); });
        if (document.activeElement !== $('sf-fs')) $('sf-fs').value = f.fs || 13;
        if (f.tc) $('sf-c-letra').value = f.tc;
        $('sf-deshacer').disabled = !pasado.length; $('sf-rehacer').disabled = !futuro.length;
    }
    function visible(r, c) {
        var el = celdaEl(r, c); if (!el) return;
        var cont = area.querySelector('.sf-scroll'), a = el.getBoundingClientRect(), b = cont.getBoundingClientRect();
        if (a.bottom > b.bottom - 4) cont.scrollTop += a.bottom - b.bottom + 40;
        else if (a.top < b.top + 60) cont.scrollTop -= b.top + 60 - a.top;
        if (a.right > b.right - 4) cont.scrollLeft += a.right - b.right + 40;
        else if (a.left < b.left + 50) cont.scrollLeft -= b.left + 50 - a.left;
    }

    // ================= Cambios, deshacer y guardado =================
    function copia() { return JSON.stringify({ cols: S.cols, rows: S.rows, estilo: S.estilo, sty: sty, hsty: hsty }); }
    function antes() { pasado.push(copia()); if (pasado.length > 100) pasado.shift(); futuro = []; }
    function cambio() { sucio = true; estado('Cambios sin guardar…'); clearTimeout(temporizador); temporizador = setTimeout(function () { guardar(false); }, 1800); }
    function restaurar(json) {
        var d = JSON.parse(json); S.cols = d.cols; S.rows = d.rows; S.estilo = d.estilo; sty = d.sty; hsty = d.hsty;
        sel.r0 = Math.min(sel.r0, S.rows.length - 1); sel.r1 = Math.min(sel.r1, S.rows.length - 1); sel.c0 = Math.min(sel.c0, S.cols.length - 1); sel.c1 = Math.min(sel.c1, S.cols.length - 1);
        dibujar(); cambio();
    }
    $('sf-deshacer').addEventListener('click', deshacer); $('sf-rehacer').addEventListener('click', rehacer);
    function deshacer() { if (!pasado.length) return; futuro.push(copia()); restaurar(pasado.pop()); }
    function rehacer() { if (!futuro.length) return; pasado.push(copia()); restaurar(futuro.pop()); }

    function datosEnvio(forzar) {
        var fd = new FormData();
        fd.append('id', C.id); fd.append('proyecto', C.proyecto); fd.append('version', version);
        fd.append('nombre', $('sf-nombre').value); fd.append('fecha', $('sf-fecha').value); fd.append('departamento', $('sf-depto').value); fd.append('descripcion', $('sf-desc').value);
        fd.append('datos', JSON.stringify({ cols: S.cols, rows: S.rows, calc: S.calc, tot: S.tot, estilo: S.estilo, fmt: fmtMapa() }));
        if (forzar) fd.append('forzar', '1');
        return fd;
    }
    /** Guarda; devuelve una promesa que se resuelve true si quedó guardado */
    function guardar(manual, forzar) {
        clearTimeout(temporizador);
        if (guardando) { temporizador = setTimeout(function () { guardar(manual, forzar); }, 600); return Promise.resolve(false); }
        if (!sucio && !manual) return Promise.resolve(true);
        guardando = true; estado('Guardando…');
        return fetch(C.api.guardar, { method: 'POST', body: datosEnvio(forzar) }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); }).then(function (x) {
            guardando = false;
            if (x.ok && x.d.success) { version = x.d.version; sucio = false; estado('Guardado ' + new Date().toLocaleTimeString()); return true; }
            if (x.d.conflicto) {
                estado('Conflicto de versión', true);
                return window.confirmarModal({ titulo: 'Conflicto de versión', mensaje: x.d.error + ' ¿Guardar de todos modos y reemplazar esa versión?', aceptar: 'Reemplazar' }).then(function (ok) { return ok ? guardar(true, true) : false; });
            }
            estado(x.d.error || 'No se pudo guardar', true); return false;
        }).catch(function () { guardando = false; estado('Sin conexión: no se pudo guardar', true); return false; });
    }
    $('sf-guardar').addEventListener('click', function () { guardar(true); });
    ['sf-nombre', 'sf-fecha', 'sf-depto', 'sf-desc'].forEach(function (id) { $(id).addEventListener('input', function () { if (id === 'sf-nombre') $('sf-titulo').textContent = this.value; cambio(); }); });
    window.addEventListener('beforeunload', function (e) { if (sucio) { e.preventDefault(); e.returnValue = ''; } });
    // Exportar / usar en vPlan: se guarda primero para que el archivo y las copias lleven lo último
    document.querySelectorAll('[data-guardar-antes]').forEach(function (a) {
        a.addEventListener('click', function (e) { if (!sucio) return; e.preventDefault(); var url = a.href, nueva = a.target === '_blank'; guardar(true).then(function (ok) { if (ok) { if (nueva) window.open(url, '_blank'); else location.href = url; } }); });
    });
    document.querySelectorAll('form[data-sf-guardar]').forEach(function (f) {
        f.addEventListener('submit', function (e) { if (!sucio) return; e.preventDefault(); guardar(true).then(function (ok) { if (ok) f.submit(); }); });
    });

    // ================= Pestañas de la barra =================
    (function () {
        var ls = 'sf-pestana';
        function mostrar(p) {
            document.querySelectorAll('#sf-pestanas [data-pest]').forEach(function (b) { b.classList.toggle('activo', b.dataset.pest === p); });
            document.querySelectorAll('.sf-barra [data-panel]').forEach(function (x) { x.hidden = x.dataset.panel !== p; });
            try { localStorage.setItem(ls, p); } catch (e) { /* sin almacenamiento */ }
        }
        document.querySelectorAll('#sf-pestanas [data-pest]').forEach(function (b) { b.addEventListener('click', function () { mostrar(b.dataset.pest); }); });
        var g = 'inicio'; try { g = localStorage.getItem(ls) || 'inicio'; } catch (e) { /* idem */ }
        if (!document.querySelector('.sf-barra [data-panel="' + g + '"]')) g = 'inicio';
        mostrar(g);
    })();

    // ================= Edición de celdas =================
    function fijar(r, c, v) {
        if (r < 0) { S.cols[c].n = String(v).replace(/[\r\n]+/g, ' ').slice(0, 60); return; }
        S.rows[r][c] = normalizar(v, S.cols[c].t).slice(0, 500);
    }
    function empezarEdicion(r, c, inicial) {
        terminarEdicion(true);
        var el = celdaEl(r, c); if (!el) return;
        var inp = document.createElement('input');
        inp.className = 'sf-input'; inp.type = 'text'; inp.spellcheck = false; inp.autocomplete = 'off';
        var crudo = r < 0 ? S.cols[c].n : S.rows[r][c];
        inp.value = inicial != null ? inicial : (r >= 0 && S.cols[c].t === 'semaforo' && SEMAFORO[crudo] ? SEMAFORO[crudo] : (r >= 0 && S.cols[c].t === 'casilla' && crudo === '1' ? 'si' : crudo));
        var tc = r >= 0 ? S.cols[c].t : '';
        if (tc === 'lista' || tc === 'semaforo' || tc === 'casilla') {   // sugerencias de la columna
            var dl = $('sf-dl') || document.body.appendChild(Object.assign(document.createElement('datalist'), { id: 'sf-dl' }));
            dl.innerHTML = (tc === 'lista' ? (S.cols[c].op || []) : tc === 'casilla' ? ['si', 'no'] : ['Verde', 'Amarillo', 'Rojo']).map(function (o) { return '<option value="' + String(o).replace(/"/g, '&quot;') + '">'; }).join('');
            inp.setAttribute('list', 'sf-dl');
        }
        var im = el.querySelector('.sf-img');
        el.textContent = ''; if (im) el.appendChild(im); el.appendChild(inp);
        editor = { r: r, c: c, input: inp, previo: crudo };
        inp.focus();
        if (inicial == null) inp.select(); else inp.setSelectionRange(inp.value.length, inp.value.length);
        inp.addEventListener('input', function () { fx.value = inp.value; });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); terminarEdicion(true); mover(e.shiftKey ? -1 : 1, 0, false); area.focus(); }
            else if (e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); terminarEdicion(true); mover(0, e.shiftKey ? -1 : 1, false); area.focus(); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); terminarEdicion(false); area.focus(); }
            else e.stopPropagation();
        });
        inp.addEventListener('blur', function () { if (editor && editor.input === inp) terminarEdicion(true); });
    }
    function terminarEdicion(aplicar) {
        if (!editor) return;
        var e = editor; editor = null;
        var nuevo = e.input.value;
        if (aplicar && nuevo !== e.previo) { antes(); fijar(e.r, e.c, nuevo); dibujar(); cambio(); }
        else dibujar();
    }
    function mover(dr, dc, extender) {
        var r = (extender ? sel.r1 : sel.r0) + dr, c = (extender ? sel.c1 : sel.c0) + dc;
        r = Math.max(-1, Math.min(S.rows.length - 1, r)); c = Math.max(0, Math.min(S.cols.length - 1, c));
        if (extender) { sel.r1 = r; sel.c1 = c; } else { sel.r0 = sel.r1 = r; sel.c0 = sel.c1 = c; }
        pintarSeleccion(); visible(r, c);
    }
    fx.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); antes(); fijar(sel.r0, sel.c0, fx.value); dibujar(); cambio(); area.focus(); }
        else if (e.key === 'Escape') { pintarSeleccion(); area.focus(); }
    });
    // Insertar una función de la lista en la celda elegida (queda en la barra de fórmulas para ajustar los rangos)
    $('sf-fn').addEventListener('change', function () {
        var f = this.value; this.value = ''; if (!f || sel.r0 < 0) return;
        antes(); S.rows[sel.r0][sel.c0] = f; dibujar(); cambio(); fx.focus(); fx.select();
    });

    // ================= Selección con el ratón =================
    var arrastrando = false, tirando = null;
    grid.addEventListener('mousedown', function (e) {
        var tir = e.target.closest('.sf-tirador');
        if (tir) { var c0 = +tir.dataset.tir, th = tir.parentNode; tirando = { c: c0, x: e.clientX, w: th.getBoundingClientRect().width / (zoom / 100), antes: copia() }; e.preventDefault(); return; }
        var t = e.target.closest('td,th'); if (!t || e.target.classList.contains('sf-input')) return;
        if (editor) terminarEdicion(true);
        if (t.classList.contains('sf-letra')) {
            var c = +t.dataset.letra;
            if (e.shiftKey) sel.c1 = c; else { sel.c0 = sel.c1 = c; sel.r0 = -1; }
            sel.r1 = S.rows.length - 1; pintarSeleccion(); e.preventDefault(); area.focus(); return;
        }
        if (t.classList.contains('sf-num')) { if (t.dataset.fila == null) return; var r = +t.dataset.fila; if (e.shiftKey) sel.r1 = r; else { sel.r0 = sel.r1 = r; } sel.c0 = 0; sel.c1 = S.cols.length - 1; pintarSeleccion(); e.preventDefault(); area.focus(); return; }
        if (t.dataset.r == null) return;
        var rr = +t.dataset.r, cc = +t.dataset.c;
        if (e.shiftKey) { sel.r1 = rr; sel.c1 = cc; } else { sel.r0 = sel.r1 = rr; sel.c0 = sel.c1 = cc; }
        arrastrando = true; pintarSeleccion(); e.preventDefault(); area.focus();
    });
    grid.addEventListener('mouseover', function (e) {
        if (!arrastrando) return;
        var t = e.target.closest('td,th.sf-enc'); if (!t || t.dataset.r == null) return;
        sel.r1 = +t.dataset.r; sel.c1 = +t.dataset.c; pintarSeleccion();
    });
    document.addEventListener('mousemove', function (e) {
        if (!tirando) return;
        var w = Math.max(40, Math.min(800, Math.round(tirando.w + (e.clientX - tirando.x) / (zoom / 100))));
        var col = grid.querySelectorAll('colgroup col')[tirando.c + 1];
        if (col) { col.style.width = w + 'px'; col.style.minWidth = w + 'px'; }
        tirando.nuevo = w;
    });
    document.addEventListener('mouseup', function () {
        arrastrando = false;
        if (tirando && tirando.nuevo) { pasado.push(tirando.antes); futuro = []; S.cols[tirando.c].w = tirando.nuevo; cambio(); dibujar(); }
        tirando = null;
    });
    grid.addEventListener('dblclick', function (e) {
        var tir = e.target.closest('.sf-tirador');
        if (tir) { antes(); S.cols[+tir.dataset.tir].w = 0; dibujar(); cambio(); return; }
        var t = e.target.closest('td,th.sf-enc'); if (!t || t.dataset.r == null) return;
        empezarEdicion(+t.dataset.r, +t.dataset.c, null);
    });

    // ================= Teclado, copiar y pegar =================
    area.addEventListener('keydown', function (e) {
        if (editor) return;
        var k = e.key, ctrl = e.ctrlKey || e.metaKey;
        if (ctrl && (k === 'z' || k === 'Z')) { e.preventDefault(); e.shiftKey ? rehacer() : deshacer(); return; }
        if (ctrl && (k === 'y' || k === 'Y')) { e.preventDefault(); rehacer(); return; }
        if (ctrl && (k === 's' || k === 'S')) { e.preventDefault(); guardar(true); return; }
        if (ctrl && (k === 'b' || k === 'B')) { e.preventDefault(); alternar('b'); return; }
        if (ctrl && (k === 'i' || k === 'I')) { e.preventDefault(); alternar('i'); return; }
        if (ctrl && (k === 'u' || k === 'U')) { e.preventDefault(); alternar('u'); return; }
        if (ctrl && (k === 'a' || k === 'A')) { e.preventDefault(); sel.r0 = -1; sel.c0 = 0; sel.r1 = S.rows.length - 1; sel.c1 = S.cols.length - 1; pintarSeleccion(); return; }
        if (ctrl) return;   // copiar/cortar/pegar van por sus eventos
        if (k === 'ArrowDown') { e.preventDefault(); mover(1, 0, e.shiftKey); }
        else if (k === 'ArrowUp') { e.preventDefault(); mover(-1, 0, e.shiftKey); }
        else if (k === 'ArrowLeft') { e.preventDefault(); mover(0, -1, e.shiftKey); }
        else if (k === 'ArrowRight') { e.preventDefault(); mover(0, 1, e.shiftKey); }
        else if (k === 'Tab') { e.preventDefault(); mover(0, e.shiftKey ? -1 : 1, false); }
        else if (k === 'Enter' || k === 'F2') { e.preventDefault(); empezarEdicion(sel.r0, sel.c0, null); }
        else if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); vaciar(); }
        else if (k.length === 1 && !e.altKey) { e.preventDefault(); empezarEdicion(sel.r0, sel.c0, k); }
    });
    function vaciar() {
        var n = rangoNorm(); antes();
        for (var r = n.r0; r <= n.r1; r++) for (var c = n.c0; c <= n.c1; c++) { if (r >= 0) S.rows[r][c] = ''; }
        dibujar(); cambio();
    }
    function textoRango() {
        var n = rangoNorm(), out = [];
        for (var r = n.r0; r <= n.r1; r++) { var f = []; for (var c = n.c0; c <= n.c1; c++) f.push(r < 0 ? S.cols[c].n : S.rows[r][c]); out.push(f.join('\t')); }
        return out.join('\n');
    }
    area.addEventListener('copy', function (e) { if (editor) return; e.clipboardData.setData('text/plain', textoRango()); e.preventDefault(); });
    area.addEventListener('cut', function (e) { if (editor) return; e.clipboardData.setData('text/plain', textoRango()); e.preventDefault(); vaciar(); });
    area.addEventListener('paste', function (e) {
        if (editor) return;
        var t = e.clipboardData.getData('text/plain'); if (t === '') return;
        e.preventDefault();
        var filas = t.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n').map(function (l) { return l.split('\t'); });
        var n = rangoNorm(), r0 = Math.max(0, n.r0), c0 = n.c0;
        // una sola celda copiada pegada sobre un rango: se repite en todo el rango
        var uno = filas.length === 1 && filas[0].length === 1 && (n.r1 > n.r0 || n.c1 > n.c0);
        antes();
        var nf = uno ? n.r1 - r0 + 1 : filas.length, nc = uno ? n.c1 - c0 + 1 : Math.max.apply(null, filas.map(function (f) { return f.length; }));
        while (S.cols.length < Math.min(MAX_C, c0 + nc)) { S.cols.push({ n: 'Columna ' + (S.cols.length + 1), t: 'texto', a: 'izquierda', tot: '', w: 0 }); hsty.push(null); S.rows.forEach(function (f, r) { f.push(''); sty[r].push(null); }); }
        while (S.rows.length < Math.min(MAX_F, r0 + nf)) { S.rows.push(S.cols.map(function () { return ''; })); sty.push(S.cols.map(function () { return null; })); }
        var cortado = false;
        for (var i = 0; i < nf; i++) for (var j = 0; j < nc; j++) {
            var r = r0 + i, c = c0 + j;
            if (r >= MAX_F || c >= MAX_C) { cortado = true; continue; }
            fijar(r, c, uno ? filas[0][0] : (filas[i][j] || ''));
        }
        sel.r0 = r0; sel.c0 = c0; sel.r1 = Math.min(S.rows.length - 1, r0 + nf - 1); sel.c1 = Math.min(S.cols.length - 1, c0 + nc - 1);
        dibujar(); cambio();
        if (cortado) estado('Se pegó solo lo que cabe (máx. ' + MAX_C + ' columnas y ' + MAX_F + ' renglones)', true);
    });

    // ================= Estructura: insertar, eliminar, ordenar =================
    /** Reescribe las referencias A1 de las fórmulas cuando cambia la estructura. `mapa(n)` devuelve el nuevo índice (0 = base) o -1 si se borró */
    function reescribir(eje, mapa) {
        var re = /(\$?)([A-Za-z]{1,2})(\$?)(\d+)(?::(\$?)([A-Za-z]{1,2})(\$?)(\d+))?(?![A-Za-z(])/g;
        S.rows.forEach(function (fila) {
            fila.forEach(function (t, c) {
                if (!esFormula(t)) return;
                fila[c] = t.replace(re, function (m, d1, l1, d2, n1, d3, l2, d4, n2) {
                    var a = { c: indiceLetra(l1.toUpperCase()), r: parseInt(n1, 10) - 2 };
                    var b = l2 ? { c: indiceLetra(l2.toUpperCase()), r: parseInt(n2, 10) - 2 } : null;
                    function nuevo(p, modo) { var v = p[eje]; return v < 0 ? v : mapa(v, modo); }
                    var na = nuevo(a, b ? 'ini' : 'solo'), nb = b ? nuevo(b, 'fin') : na;
                    if (na === -1 || nb === -1 || (b && na > nb)) return errores.ref;
                    var ra = eje === 'r' ? na : a.r, ca = eje === 'c' ? na : a.c;
                    var s = d1 + letra(ca) + d2 + (ra + 2);
                    if (b) { var rb = eje === 'r' ? nb : b.r, cb = eje === 'c' ? nb : b.c; s += ':' + d3 + letra(cb) + d4 + (rb + 2); }
                    return s;
                });
            });
        });
    }
    function insertar(eje, en, k) {
        reescribir(eje, function (v) { return v >= en ? v + k : v; });
        if (eje === 'r') for (var i = 0; i < k; i++) { S.rows.splice(en, 0, S.cols.map(function () { return ''; })); sty.splice(en, 0, S.cols.map(function () { return null; })); }
        else for (var j = 0; j < k; j++) { S.cols.splice(en, 0, { n: 'Columna ' + (S.cols.length + 1), t: 'texto', a: 'izquierda', tot: '', w: 0 }); hsty.splice(en, 0, null); S.rows.forEach(function (f, r) { f.splice(en, 0, ''); sty[r].splice(en, 0, null); }); }
    }
    function eliminar(eje, desde, hasta) {
        var k = hasta - desde + 1;
        reescribir(eje, function (v, modo) {
            if (v < desde) return v;
            if (v > hasta) return v - k;
            // dentro de lo borrado: un rango se acorta, una celda suelta se pierde
            return modo === 'solo' ? -1 : modo === 'fin' ? (desde - 1 >= 0 ? desde - 1 : -1) : desde;
        });
        if (eje === 'r') { S.rows.splice(desde, k); sty.splice(desde, k); }
        else { S.cols.splice(desde, k); hsty.splice(desde, k); S.rows.forEach(function (f, r) { f.splice(desde, k); sty[r].splice(desde, k); }); }
    }
    document.querySelectorAll('[data-acc]').forEach(function (b) {
        b.addEventListener('click', function () {
            var a = b.dataset.acc, n = rangoNorm(), k;
            if (a === 'fila-arriba' || a === 'fila-abajo') {
                k = Math.max(1, n.r1 - Math.max(0, n.r0) + 1);
                if (S.rows.length + k > MAX_F) { estado('Máximo ' + MAX_F + ' renglones', true); return; }
                antes(); var en = a === 'fila-arriba' ? Math.max(0, n.r0) : n.r1 + 1; insertar('r', en, k);
                sel.r0 = sel.r1 = en; sel.c1 = sel.c0;
            } else if (a === 'fila-borrar') {
                var d = Math.max(0, n.r0); if (n.r1 < 0) return;
                antes(); eliminar('r', d, n.r1);
                if (!S.rows.length) { S.rows.push(S.cols.map(function () { return ''; })); sty.push(S.cols.map(function () { return null; })); }
                sel.r0 = sel.r1 = Math.min(d, S.rows.length - 1);
            } else if (a === 'col-izq' || a === 'col-der') {
                k = n.c1 - n.c0 + 1;
                if (S.cols.length + k > MAX_C) { estado('Máximo ' + MAX_C + ' columnas', true); return; }
                antes(); var ec = a === 'col-izq' ? n.c0 : n.c1 + 1; insertar('c', ec, k);
                sel.c0 = sel.c1 = ec;
            } else if (a === 'col-borrar') {
                if (n.c1 - n.c0 + 1 >= S.cols.length) { estado('La tabla necesita al menos una columna', true); return; }
                antes(); eliminar('c', n.c0, n.c1); sel.c0 = sel.c1 = Math.min(n.c0, S.cols.length - 1);
            } else if (a === 'orden-asc' || a === 'orden-desc') {
                if (S.rows.some(function (f) { return f.some(esFormula); })) { estado('No se puede ordenar una tabla con fórmulas (las referencias cambiarían)', true); return; }
                var col = sel.c0, tipo = S.cols[col].t, dir = a === 'orden-asc' ? 1 : -1;
                antes();
                var pares = S.rows.map(function (f, r) { return { f: f, s: sty[r] }; });
                var vacio = function (p) { return !p.f.some(function (x) { return x !== ''; }); };
                var llenas = pares.filter(function (p) { return !vacio(p); }), vacias = pares.filter(vacio);
                llenas.sort(function (x, y) {
                    var p = x.f[col], q = y.f[col];
                    if (p === '' && q !== '') return 1; if (q === '' && p !== '') return -1;
                    var np = numero(p), nq = numero(q);
                    if (NUM.indexOf(tipo) >= 0 && np !== null && nq !== null) return (np - nq) * dir;
                    return String(p).localeCompare(String(q), 'es', { numeric: true, sensitivity: 'base' }) * dir;
                });
                var todas = llenas.concat(vacias);
                S.rows = todas.map(function (p) { return p.f; }); sty = todas.map(function (p) { return p.s; });
            }
            dibujar(); cambio(); area.focus();
        });
    });

    // ================= Propiedades de columna y estilo de la tabla =================
    function columnasElegidas() { var n = rangoNorm(), o = []; for (var c = n.c0; c <= n.c1; c++) o.push(c); return o; }
    $('sf-tipo').addEventListener('change', function () {
        antes(); var t = this.value;
        columnasElegidas().forEach(function (c) {
            var col = S.cols[c]; col.t = t; col.a = NUM.indexOf(t) >= 0 ? 'derecha' : (t === 'fecha' || t === 'casilla' || t === 'semaforo' ? 'centro' : 'izquierda');
            if (t === 'lista') col.op = col.op || []; else delete col.op;
            if (col.tot && col.tot !== 'cuenta' && NUM.indexOf(t) < 0) col.tot = '';
            S.rows.forEach(function (f, r) { if (!esFormula(f[c])) f[c] = normalizar(f[c], t); });
        });
        sincronizarBarra(); dibujar(); cambio();
    });
    $('sf-opciones').addEventListener('change', function () {
        antes();
        var op = this.value.split(',').map(function (x) { return x.trim().slice(0, 40); }).filter(function (x, i, a) { return x && a.indexOf(x) === i; }).slice(0, 20);
        columnasElegidas().forEach(function (c) { if (S.cols[c].t === 'lista') S.cols[c].op = op; });
        cambio();
    });
    $('sf-total').addEventListener('change', function () {
        var v = this.value;
        antes();
        columnasElegidas().forEach(function (c) { var col = S.cols[c]; col.tot = v === '' || v === 'cuenta' || NUM.indexOf(col.t) >= 0 ? v : ''; });
        if (v && v !== 'cuenta' && columnasElegidas().some(function (c) { return NUM.indexOf(S.cols[c].t) < 0; })) estado('El total solo se aplica a columnas de tipo número, moneda o porcentaje (o «Cuenta»)', true);
        dibujar(); cambio();
    });
    $('sf-ancho-auto').addEventListener('click', function () { antes(); columnasElegidas().forEach(function (c) { S.cols[c].w = 0; }); dibujar(); cambio(); });
    $('sf-c-enc').addEventListener('input', function () { S.estilo.enc = this.value; dibujar(); cambio(); });
    $('sf-c-txt').addEventListener('input', function () { S.estilo.encTxt = this.value; dibujar(); cambio(); });
    $('sf-zebra').addEventListener('change', function () { S.estilo.zebra = this.checked; dibujar(); cambio(); });
    $('sf-bordes').addEventListener('change', function () { S.estilo.bordes = this.checked; dibujar(); cambio(); });
    $('sf-zoom').addEventListener('input', function () { zoom = +this.value; $('sf-zoom-v').textContent = zoom + '%'; grid.style.zoom = zoom / 100; });

    // ================= Formato de celdas: letra, colores, alineación, bordes, imagen =================
    /** Aplica fn(formato) a cada celda elegida (incluye el encabezado si está en la selección) */
    function aplicar(fn, sinAntes) {
        var n = rangoNorm();
        if (!sinAntes) antes();
        for (var r = n.r0; r <= n.r1; r++) for (var c = n.c0; c <= n.c1; c++) {
            var f = Object.assign({}, getSt(r, c) || {});
            fn(f, r, c);
            setSt(r, c, f);
        }
        dibujar(); cambio(); area.focus();
    }
    function alternar(k) {
        var ancla = getSt(sel.r0, sel.c0) || {}, on = !ancla[k];
        aplicar(function (f) { if (on) f[k] = 1; else delete f[k]; });
    }
    document.querySelectorAll('[data-fmt]').forEach(function (b) { b.addEventListener('click', function () { alternar(b.dataset.fmt); }); });
    document.querySelectorAll('[data-alin]').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = b.dataset.alin;
            antes();
            columnasElegidas().forEach(function (c) { S.cols[c].a = v; });   // la alineación de la columna
            aplicar(function (f) { delete f.al; }, true);                    // y se quita la particular de las celdas para que mande la columna
        });
    });
    function cambiarTamano(delta, valor) {
        aplicar(function (f) { f.fs = valor != null ? valor : Math.max(6, Math.min(72, (f.fs || 13) + delta)); });
    }
    $('sf-fs-menos').addEventListener('click', function () { cambiarTamano(-1); });
    $('sf-fs-mas').addEventListener('click', function () { cambiarTamano(1); });
    $('sf-fs').addEventListener('change', function () { var v = parseInt(this.value, 10); if (v >= 6 && v <= 72) cambiarTamano(0, v); });
    $('sf-fs').addEventListener('keydown', function (e) { e.stopPropagation(); if (e.key === 'Enter') { this.dispatchEvent(new Event('change')); } });
    $('sf-c-letra').addEventListener('input', function () { var v = this.value; aplicar(function (f) { f.tc = v; }); });
    $('sf-c-fondo').addEventListener('input', function () { var v = this.value; aplicar(function (f) { f.bg = v; }); });
    $('sf-sin-relleno').addEventListener('click', function () { aplicar(function (f) { delete f.bg; }); });
    $('sf-limpiar-fmt').addEventListener('click', function () { aplicar(function (f) { Object.keys(f).forEach(function (k) { if (k !== 'im') delete f[k]; }); }); });

    /** Borde de un lado de la celda (r,c); también el lado contiguo de la vecina para que no choquen al fundirse las líneas */
    function ponerBorde(r, c, lado, valor) {
        var op = { bt: 'bb', bb: 'bt', bl: 'br', br: 'bl' }[lado];
        var dr = lado === 'bt' ? -1 : lado === 'bb' ? 1 : 0, dc = lado === 'bl' ? -1 : lado === 'br' ? 1 : 0;
        var f = Object.assign({}, getSt(r, c) || {}); f[lado] = valor; setSt(r, c, f);
        var rr = r + dr, cc = c + dc;
        if (rr >= -1 && rr < S.rows.length && cc >= 0 && cc < S.cols.length) { var g = Object.assign({}, getSt(rr, cc) || {}); g[op] = valor; setSt(rr, cc, g); }
    }
    $('sf-b-aplicar').addEventListener('click', function () {
        var lado = $('sf-b-lado').value, n = rangoNorm();
        var val = lado === 'ninguno' ? 'n' : $('sf-b-grosor').value + '|' + $('sf-b-estilo').value + '|' + $('sf-b-color').value;
        antes();
        for (var r = n.r0; r <= n.r1; r++) for (var c = n.c0; c <= n.c1; c++) {
            var arriba = r === n.r0, abajo = r === n.r1, izq = c === n.c0, der = c === n.c1;
            if (lado === 'todos' || lado === 'ninguno') { ['bt', 'br', 'bb', 'bl'].forEach(function (l) { ponerBorde(r, c, l, val); }); continue; }
            if ((lado === 'exterior' || lado === 'arriba') && arriba) ponerBorde(r, c, 'bt', val);
            if ((lado === 'exterior' || lado === 'abajo') && abajo) ponerBorde(r, c, 'bb', val);
            if ((lado === 'exterior' || lado === 'izquierda') && izq) ponerBorde(r, c, 'bl', val);
            if ((lado === 'exterior' || lado === 'derecha') && der) ponerBorde(r, c, 'br', val);
        }
        dibujar(); cambio(); area.focus();
    });

    // Imagen en la celda: se sube y su ruta queda en el formato de la celda
    $('sf-img-poner').addEventListener('click', function () { $('sf-img-archivo').click(); });
    $('sf-img-archivo').addEventListener('change', function () {
        var f = this.files[0]; this.value = ''; if (!f) return;
        subirImagenCelda(f);
    });
    // Símbolo de la biblioteca: se pasa a PNG y se sube igual que una imagen propia
    var selSimbolo = $('sf-img-simbolo');
    if (selSimbolo) selSimbolo.addEventListener('change', function () {
        var s = this.value; this.value = ''; if (!s) return;
        var im = new Image();
        im.onload = function () {
            var k = 256 / Math.max(im.naturalWidth || 256, im.naturalHeight || 256), cv = document.createElement('canvas');
            cv.width = Math.round((im.naturalWidth || 256) * k); cv.height = Math.round((im.naturalHeight || 256) * k);
            cv.getContext('2d').drawImage(im, 0, 0, cv.width, cv.height);
            cv.toBlob(function (b) { if (b) subirImagenCelda(b); else estado('No se pudo preparar el símbolo', true); }, 'image/png');
        };
        im.onerror = function () { estado('No se pudo cargar el símbolo', true); };
        im.src = s;
    });
    function subirImagenCelda(f) {
        var fd = new FormData(); fd.append('id', C.id); fd.append('proyecto', C.proyecto); fd.append('imagen', f, f.name || 'simbolo.png');
        estado('Subiendo imagen…');
        fetch('/admin/sheetflow/api/imagen', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) { estado(d.error || 'No se pudo subir la imagen', true); return; }
            var ancla = { r: sel.r0, c: sel.c0 };
            antes();
            var g = Object.assign({}, getSt(ancla.r, ancla.c) || {}); g.im = d.src; setSt(ancla.r, ancla.c, g);
            dibujar(); cambio(); estado('Imagen puesta en ' + nombreCelda(ancla.r, ancla.c));
        }).catch(function () { estado('Sin conexión: no se pudo subir la imagen', true); });
    }
    $('sf-img-quitar').addEventListener('click', function () { aplicar(function (f) { delete f.im; }); });

    dibujar();
    area.focus();
})();
