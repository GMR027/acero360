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
    S.refs = S.refs && !Array.isArray(S.refs) ? S.refs : {};
    S.reglas = Array.isArray(S.reglas) ? S.reglas : [];
    S.graficas = Array.isArray(S.graficas) ? S.graficas : [];
    S.merges = Array.isArray(S.merges) ? S.merges : [];
    var grid = $('sf-grid'), area = $('sf-area'), fx = $('sf-fx');
    var version = C.version, sucio = false, guardando = false, temporizador = null;
    var sel = { r0: 0, c0: 0, r1: 0, c1: 0 };   // rango elegido; (r0,c0) es el ancla. r = -1 es el encabezado
    var editor = null;                           // {r,c,input,previo} mientras se edita una celda
    var pickFormula = null;                      // selección de celdas mientras se escribe una fórmula
    var pasado = [], futuro = [];
    var MAX_C = C.max.cols, MAX_F = C.max.filas;
    var zoom = 100;
    var filtro = { texto: '', columna: false };
    var temporizadorFiltro = null;

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

    /** Celda vinculada a un dato externo (clave) o null */
    function refDe(r, c) { return r >= 0 ? (S.refs[r + ',' + c] || null) : null; }
    function quitarRef(r, c) { if (r >= 0) delete S.refs[r + ',' + c]; }

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
                if (!filaVisible(r)) return;
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
    /** ¿El renglón pasa el filtro activo? (r = índice 0 de datos) */
    function filaVisible(r) {
        if (!filtro.texto) return true;
        var t = filtro.texto.toLowerCase();
        var c0 = filtro.columna ? Math.max(0, sel.c0) : 0;
        var c1 = filtro.columna ? Math.max(0, sel.c1) : S.cols.length - 1;
        for (var c = c0; c <= c1; c++) {
            if (String(mostrado(r, c)).toLowerCase().indexOf(t) >= 0) return true;
        }
        return false;
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
        if (f.dbar) {
            var dc = f.dbarColor || '#93c5fd';
            var dp = Math.max(0, Math.min(100, +f.dbar || 0));
            c += 'background-image:linear-gradient(to right,' + dc + ' 0%,' + dc + ' ' + dp + '%,transparent ' + dp + '%);';
        }
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
    function urlSegura(u) {
        var t = String(u || '').trim();
        return /^(https?:\/\/|mailto:|tel:|\/)/i.test(t) ? t : '';
    }
    function dataURLtoBlob(dataUrl) {
        var partes = String(dataUrl).split(',');
        var mime = /:(.*?);/.exec(partes[0]);
        var bin = atob(partes[1] || '');
        var arr = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        return new Blob([arr], { type: mime ? mime[1] : 'image/png' });
    }
    function enlaceHtml(f, v) {
        var u = urlSegura(f && f.lk);
        if (!u) return esc(v);
        return '<a class="sf-enlace" href="' + esc(u) + '" target="_blank" rel="noopener" title="' + esc(u) + ' · Ctrl+clic para abrir">' + esc(v) + '</a>';
    }
    function barraHtml(f, v) {
        var n = numero(v);
        var pct = n === null ? 0 : Math.max(0, Math.min(100, n));
        var color = (f && f.barColor) || '';
        return '<div class="sf-barra-celda"><div class="sf-barra-pista"><span style="width:' + pct + '%' + (color ? ';background:' + esc(color) : '') + '"></span></div><span class="sf-barra-val">' + esc(v) + '</span></div>';
    }
    function indiceCol(letras) {
        var n = 0;
        letras = String(letras).toUpperCase();
        for (var i = 0; i < letras.length; i++) n = n * 26 + letras.charCodeAt(i) - 64;
        return Math.max(0, n - 1);
    }
    function valoresSpark(expr) {
        var vals = [], m;
        expr = String(expr || '').trim();
        if ((m = /^([A-Za-z]{1,2})(\d+)(?::([A-Za-z]{1,2})(\d+))?$/.exec(expr))) {
            var c0 = indiceCol(m[1]), r0 = Math.max(0, parseInt(m[2], 10) - 2);
            var c1 = m[3] ? indiceCol(m[3]) : c0, r1 = m[4] ? Math.max(0, parseInt(m[4], 10) - 2) : r0;
            for (var r = Math.min(r0, r1); r <= Math.max(r0, r1); r++) {
                for (var c = Math.min(c0, c1); c <= Math.max(c0, c1); c++) {
                    var v = S.rows[r] ? S.rows[r][c] : '';
                    var n = numero(esFormula(v) ? (S.calc[r + ',' + c] || '') : v);
                    if (n !== null) vals.push(n);
                }
            }
        } else {
            expr.split(/[\s,;]+/).forEach(function (x) { var n = numero(x); if (n !== null) vals.push(n); });
        }
        return vals;
    }
    function sparkHtml(f) {
        var vals = valoresSpark(f.spark);
        if (!vals.length) return '';
        var w = 90, h = 28, pad = 2, min = Math.min.apply(null, vals), max = Math.max.apply(null, vals);
        var rango = max - min || 1, ancho = w - pad * 2, alto = h - pad * 2;
        var color = /^#[0-9a-f]{6}$/i.test(f.sparkColor || '') ? f.sparkColor : '#2563eb';
        var s = '<svg class="sf-spark" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" aria-hidden="true">';
        s += '<line x1="' + pad + '" y1="' + (h - pad) + '" x2="' + (w - pad) + '" y2="' + (h - pad) + '" stroke="#e2e8f0" stroke-width="1"/>';
        if (f.sparkTipo === 'barras') {
            var bw = Math.max(1, ancho / vals.length * 0.8);
            vals.forEach(function (v, i) {
                var bh = (v - min) / rango * alto;
                var x = pad + ancho / vals.length * i + (ancho / vals.length - bw) / 2;
                s += '<rect x="' + x.toFixed(2) + '" y="' + (h - pad - bh).toFixed(2) + '" width="' + bw.toFixed(2) + '" height="' + Math.max(1, bh).toFixed(2) + '" fill="' + color + '"/>';
            });
        } else {
            var pts = vals.map(function (v, i) {
                var x = pad + (vals.length === 1 ? ancho / 2 : ancho * i / (vals.length - 1));
                var y = h - pad - (v - min) / rango * alto;
                return x.toFixed(2) + ',' + y.toFixed(2);
            });
            s += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
        }
        return s + '</svg>';
    }
    function contenidoHtml(f, v) {
        if (f && f.spark) return sparkHtml(f);
        if (f && f.bar) return barraHtml(f, v);
        if (f && f.lk) return enlaceHtml(f, v);
        return esc(v);
    }
    function mezclaColor(a, b, p) {
        p = Math.max(0, Math.min(1, p));
        var ca = (a || '#fee2e2').replace('#', ''), cb = (b || '#bbf7d0').replace('#', '');
        var out = '#';
        for (var i = 0; i < 3; i++) {
            var x = parseInt(ca.substr(i * 2, 2), 16), y = parseInt(cb.substr(i * 2, 2), 16);
            out += ('0' + Math.round(x + (y - x) * p).toString(16)).slice(-2);
        }
        return out;
    }
    function prepararReglas() {
        var mm = {};
        S.reglas.forEach(function (r, i) {
            if (r.tipo !== 'escala' && r.tipo !== 'barras') return;
            var min = null, max = null;
            for (var rr = r.r0; rr <= r.r1; rr++) for (var cc = r.c0; cc <= r.c1; cc++) {
                var n = numero(mostrado(rr, cc));
                if (n === null) continue;
                if (min === null || n < min) min = n;
                if (max === null || n > max) max = n;
            }
            mm[i] = [min == null ? 0 : min, max == null ? 0 : max];
        });
        return mm;
    }
    function estiloRegla(r, c, v, mm) {
        var out = {};
        S.reglas.forEach(function (reg, i) {
            if (r < reg.r0 || r > reg.r1 || c < reg.c0 || c > reg.c1) return;
            var n = numero(v), n1 = numero(reg.v1), n2 = numero(reg.v2), ok = false;
            if (reg.tipo === 'mayor') ok = n !== null && n1 !== null && n > n1;
            else if (reg.tipo === 'menor') ok = n !== null && n1 !== null && n < n1;
            else if (reg.tipo === 'entre') ok = n !== null && n1 !== null && n2 !== null && n >= Math.min(n1, n2) && n <= Math.max(n1, n2);
            else if (reg.tipo === 'contiene') ok = !!reg.v1 && String(v).toLowerCase().indexOf(String(reg.v1).toLowerCase()) >= 0;
            else if (reg.tipo === 'igual') ok = n !== null && n1 !== null ? n == n1 : String(v).trim() === String(reg.v1 || '').trim();
            else if (reg.tipo === 'vacio') ok = String(v).trim() === '';
            else if (reg.tipo === 'no_vacio') ok = String(v).trim() !== '';
            else if (reg.tipo === 'escala' || reg.tipo === 'barras') ok = n !== null;
            if (!ok) return;
            var f = {};
            if (reg.tipo === 'escala') {
                var m = mm[i] || [0, 0], p = m[1] > m[0] && n !== null ? (n - m[0]) / (m[1] - m[0]) : 0;
                f.bg = mezclaColor(reg.color || '#fee2e2', reg.color2 || '#bbf7d0', p);
                f.tc = contraste(f.bg);
            } else if (reg.tipo === 'barras') {
                var m2 = mm[i] || [0, 0], p2 = m2[1] > m2[0] && n !== null ? (n - m2[0]) / (m2[1] - m2[0]) * 100 : 0;
                f.dbar = Math.round(p2 * 10) / 10;
                f.dbarColor = reg.color || '#93c5fd';
            }
            Object.keys(reg.fmt || {}).forEach(function (k) { f[k] = reg.fmt[k]; });
            Object.keys(f).forEach(function (k) { out[k] = f[k]; });
        });
        return out;
    }
    function svgGrafica(g, vals) {
        var w = 640, h = 240, L = 48, R = 20, T = 44, B = 30, ancho = w - L - R, alto = h - T - B;
        var titulo = esc(g.titulo || 'Gráfica');
        var s = '<svg class="sf-grafica-svg" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="' + titulo + '">';
        s += '<text x="16" y="26" font-family="Arial" font-size="16" font-weight="bold" fill="#0f172a">' + titulo + '</text>';
        if (!vals.length) return s + '<text x="' + (w / 2) + '" y="' + (h / 2) + '" text-anchor="middle" font-family="Arial" font-size="13" fill="#64748b">Sin datos numéricos en ' + esc(g.rango) + '</text></svg>';
        if (g.tipo === 'pastel') {
            var total = vals.reduce(function (a, b) { return a + Math.max(0, b); }, 0) || 1;
            var cx = w / 2, cy = T + alto / 2, rad = Math.min(ancho, alto) / 2 - 6;
            var paleta = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#64748b'];
            var ang = -Math.PI / 2;
            vals.forEach(function (v, i) {
                var a = Math.max(0, v) / total * Math.PI * 2;
                if (a <= 0) return;
                var x1 = cx + Math.cos(ang) * rad, y1 = cy + Math.sin(ang) * rad;
                var x2 = cx + Math.cos(ang + a) * rad, y2 = cy + Math.sin(ang + a) * rad;
                var larga = a > Math.PI ? 1 : 0;
                s += '<path d="M' + cx + ',' + cy + ' L' + x1.toFixed(2) + ',' + y1.toFixed(2) + ' A' + rad + ',' + rad + ' 0 ' + larga + ' 1 ' + x2.toFixed(2) + ',' + y2.toFixed(2) + ' Z" fill="' + paleta[i % paleta.length] + '" stroke="#fff" stroke-width="1"/>';
                ang += a;
            });
            s += '<text x="' + cx + '" y="' + (cy + 4) + '" text-anchor="middle" font-family="Arial" font-size="12" fill="#334155">' + vals.length + ' valores</text>';
            return s + '</svg>';
        }
        var min = Math.min.apply(null, vals.concat([0])), max = Math.max.apply(null, vals);
        if (max === min) max = min + 1;
        var rango = max - min;
        var y = function (v) { return T + alto - (v - min) / rango * alto; };
        for (var k = 0; k <= 4; k++) {
            var val = min + rango * k / 4, yy = y(val);
            s += '<line x1="' + L + '" y1="' + yy.toFixed(2) + '" x2="' + (w - R) + '" y2="' + yy.toFixed(2) + '" stroke="#e2e8f0" stroke-width="1"/>';
            s += '<text x="' + (L - 6) + '" y="' + (yy + 3).toFixed(2) + '" text-anchor="end" font-family="Arial" font-size="9" fill="#64748b">' + (Math.round(val * 100) / 100) + '</text>';
        }
        s += '<line x1="' + L + '" y1="' + (T + alto) + '" x2="' + (w - R) + '" y2="' + (T + alto) + '" stroke="#94a3b8" stroke-width="1"/>';
        if (g.tipo === 'linea') {
            var pts = vals.map(function (v, i) {
                var x = L + (vals.length === 1 ? ancho / 2 : ancho * i / (vals.length - 1));
                return x.toFixed(2) + ',' + y(v).toFixed(2);
            });
            s += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
            vals.forEach(function (v, i) {
                var x = L + (vals.length === 1 ? ancho / 2 : ancho * i / (vals.length - 1));
                s += '<circle cx="' + x.toFixed(2) + '" cy="' + y(v).toFixed(2) + '" r="3" fill="#2563eb"/>';
            });
        } else {
            var bw = Math.max(2, ancho / vals.length * 0.65);
            vals.forEach(function (v, i) {
                var x = L + ancho / vals.length * i + (ancho / vals.length - bw) / 2;
                var top = y(Math.max(0, v)), base = y(0);
                s += '<rect x="' + x.toFixed(2) + '" y="' + Math.min(top, base).toFixed(2) + '" width="' + bw.toFixed(2) + '" height="' + Math.abs(base - top).toFixed(2) + '" fill="#2563eb" rx="2"/>';
            });
        }
        return s + '</svg>';
    }
    function dibujarGraficas() {
        var cont = $('sf-graficas');
        if (!cont) return;
        if (!S.graficas.length) { cont.innerHTML = ''; cont.hidden = true; return; }
        cont.hidden = false;
        cont.innerHTML = S.graficas.map(function (g, i) {
            return '<figure class="sf-grafica" data-g="' + i + '"><figcaption><strong>' + esc(g.titulo || 'Gráfica') + '</strong> · ' + esc(g.rango) +
                ' <button type="button" class="sf-b" data-quitar-g="' + i + '" title="Quitar esta gráfica"><i class="fa-solid fa-xmark"></i></button></figcaption>' +
                svgGrafica(g, valoresSpark(g.rango)) + '</figure>';
        }).join('');
    }
    $('sf-graficas').addEventListener('click', function (e) {
        var b = e.target.closest('[data-quitar-g]');
        if (!b) return;
        antes();
        S.graficas.splice(parseInt(b.dataset.quitarG, 10), 1);
        dibujar(); cambio();
    });
    function mergeDe(r, c) {
        for (var i = 0; i < S.merges.length; i++) {
            var m = S.merges[i];
            if (r >= m.r0 && r <= m.r1 && c >= m.c0 && c <= m.c1) return m;
        }
        return null;
    }
    function dibujar() {
        recalcular();
        var minmaxReglas = prepararReglas();
        var e = S.estilo, h = '<colgroup><col class="sf-col-n">';
        S.cols.forEach(function (col) { h += '<col' + (col.w ? ' style="width:' + col.w + 'px;min-width:' + col.w + 'px"' : '') + '>'; });
        h += '</colgroup><thead><tr><th class="sf-esq"></th>';
        S.cols.forEach(function (col, c) { h += '<th class="sf-letra" data-letra="' + c + '">' + letra(c) + '<i class="sf-tirador" data-tir="' + c + '" title="Arrastra para cambiar el ancho · doble clic: automático"></i></th>'; });
        h += '</tr><tr><th class="sf-num" data-fila="-1">1</th>';
        S.cols.forEach(function (col, c) { h += '<th class="sf-enc" data-r="-1" data-c="' + c + '" style="background:' + e.enc + ';color:' + e.encTxt + ';' + cssFmt(hsty[c]) + '">' + imgHtml(hsty[c]) + esc(col.n) + '</th>'; });
        h += '</tr></thead><tbody>';
        S.rows.forEach(function (fila, r) {
            var clasesFila = [];
            if (e.zebra && r % 2) clasesFila.push('sf-alt');
            if (!filaVisible(r)) clasesFila.push('sf-oculta');
            h += '<tr' + (clasesFila.length ? ' class="' + clasesFila.join(' ') + '"' : '') + '><th class="sf-num" data-fila="' + r + '">' + (r + 2) + '</th>';
            S.cols.forEach(function (col, c) {
                var mg = mergeDe(r, c);
                if (mg && (mg.r0 !== r || mg.c0 !== c)) return;
                var v = mostrado(r, c), err = /^#[A-Z/0?!]+$/.test(v), ref = refDe(r, c), clases = [];
                var f = Object.assign({}, estiloRegla(r, c, v, minmaxReglas), sty[r][c] || {});
                if (esFormula(fila[c])) clases.push('sf-f' + (err ? ' sf-err' : ''));
                if (ref) clases.push('sf-ext');
                if (f && f.nota) clases.push('sf-con-nota');
                var titulo = ref ? 'Dato externo vinculado. Se actualiza con «Actualizar datos».' : '';
                if (f && f.nota) titulo = (titulo ? titulo + ' · ' : '') + 'Nota: ' + f.nota;
                var span = mg ? ' colspan="' + (mg.c1 - mg.c0 + 1) + '" rowspan="' + (mg.r1 - mg.r0 + 1) + '"' : '';
                h += '<td data-r="' + r + '" data-c="' + c + '"' + span + ' style="text-align:' + ALIN[col.a] + ';' + cssFmt(f) + '"'
                    + (clases.length ? ' class="' + clases.join(' ') + '"' : '')
                    + (titulo ? ' title="' + esc(titulo) + '"' : '')
                    + '>' + imgHtml(f) + contenidoHtml(f, v) + '</td>';
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
        dibujarGraficas();
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
        if (!pickFormula) {
            fx.value = sel.r0 < 0 ? S.cols[sel.c0].n : S.rows[sel.r0][sel.c0];
            var vinculada = sel.r0 >= 0 && !!refDe(sel.r0, sel.c0);
            fx.disabled = vinculada;
            fx.title = vinculada ? 'Celda vinculada a un dato externo. Usa «Quitar vínculo» para escribir un valor o fórmula propia.' : '';
            fx.placeholder = vinculada
                ? 'Celda vinculada: usa «Quitar vínculo» para escribir aquí'
                : 'Contenido; = para fórmula. La fila 1 es el encabezado: los datos empiezan en la 2. Ej.: =A2+B2';
        }
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
    function copia() { return JSON.stringify({ cols: S.cols, rows: S.rows, refs: S.refs, estilo: S.estilo, sty: sty, hsty: hsty, reglas: S.reglas, graficas: S.graficas, merges: S.merges }); }
    function antes() { pasado.push(copia()); if (pasado.length > 100) pasado.shift(); futuro = []; }
    function cambio() { sucio = true; estado('Cambios sin guardar…'); clearTimeout(temporizador); temporizador = setTimeout(function () { guardar(false); }, 1800); }
    function restaurar(json) {
        var d = JSON.parse(json); S.cols = d.cols; S.rows = d.rows; S.refs = d.refs || {}; S.estilo = d.estilo; sty = d.sty; hsty = d.hsty; S.reglas = d.reglas || []; S.graficas = d.graficas || []; S.merges = d.merges || [];
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
        fd.append('datos', JSON.stringify({ cols: S.cols, rows: S.rows, refs: S.refs, calc: S.calc, tot: S.tot, estilo: S.estilo, fmt: fmtMapa(), reglas: S.reglas, graficas: S.graficas, merges: S.merges }));
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
    function irAPestana(p) {
        document.querySelectorAll('#sf-pestanas [data-pest]').forEach(function (b) { b.classList.toggle('activo', b.dataset.pest === p); });
        document.querySelectorAll('.sf-barra [data-panel]').forEach(function (x) { x.hidden = x.dataset.panel !== p; });
        try { localStorage.setItem('sf-pestana', p); } catch (e) { /* sin almacenamiento */ }
    }
    (function () {
        document.querySelectorAll('#sf-pestanas [data-pest]').forEach(function (b) { b.addEventListener('click', function () { irAPestana(b.dataset.pest); }); });
        var g = 'inicio'; try { g = localStorage.getItem('sf-pestana') || 'inicio'; } catch (e) { /* idem */ }
        if (!document.querySelector('.sf-barra [data-panel="' + g + '"]')) g = 'inicio';
        irAPestana(g);
    })();

    // ================= Edición de celdas =================
    function fijar(r, c, v) {
        if (r < 0) { S.cols[c].n = String(v).replace(/[\r\n]+/g, ' ').slice(0, 60); return; }
        quitarRef(r, c);
        S.rows[r][c] = normalizar(v, S.cols[c].t).slice(0, 500);
    }
    /** Si hay una fórmula en edición (en la celda o en la barra fx), devuelve su input. */
    function formulaEditando() {
        if (editor && editor.input && document.activeElement === editor.input && /^\s*=/.test(editor.input.value)) {
            return { input: editor.input, tipo: 'celda', r: editor.r, c: editor.c };
        }
        if (document.activeElement === fx && /^\s*=/.test(fx.value)) {
            return { input: fx, tipo: 'fx', r: sel.r0, c: sel.c0 };
        }
        return null;
    }

    /** Inserta la referencia elegida (A2 o A2:B5) en la fórmula y mantiene la edición. */
    function finalizarPickFormula() {
        var p = pickFormula;
        if (!p) return;
        pickFormula = null;

        var ref = nombreCelda(p.r0, p.c0) + ((p.r1 !== p.r0 || p.c1 !== p.c0) ? ':' + nombreCelda(p.r1, p.c1) : '');
        var inp = p.input;
        var ini = p.pos != null ? p.pos : inp.selectionStart;
        var fin = inp.selectionEnd;
        // Como en Excel: si justo antes del cursor hay una referencia, se reemplaza.
        var previa = /(\$?[A-Za-z]{1,2}\$?\d+(?::\$?[A-Za-z]{1,2}\$?\d+)?)$/.exec(inp.value.slice(0, ini));
        if (previa) ini -= previa[0].length;
        var texto = inp.value.slice(0, ini) + ref + inp.value.slice(fin);
        var pos = ini + ref.length;

        // Vuelve a la celda que se estaba editando y conserva el texto de la fórmula.
        sel.r0 = sel.r1 = p.editR;
        sel.c0 = sel.c1 = p.editC;
        inp.value = texto;
        pintarSeleccion();
        // pintarSeleccion() reescribe fx con el valor de la celda; se restaura la fórmula.
        if (p.tipo === 'fx') { inp.value = texto; fx.value = texto; }
        else if (editor && editor.input === inp) fx.value = texto;
        try { inp.focus(); inp.setSelectionRange(pos, pos); } catch (e) { /* sin selección */ }
    }

    function empezarEdicion(r, c, inicial) {
        terminarEdicion(true);
        if (r >= 0 && refDe(r, c)) { estado('Esta celda es un dato externo. Usa «Quitar vínculo» si quieres escribir un valor propio.', true); return; }
        // Casilla y semáforo se cambian con doble clic / Enter, sin teclado.
        if (r >= 0 && S.cols[c].t === 'casilla' && inicial == null) {
            antes();
            fijar(r, c, S.rows[r][c] === '1' ? '0' : '1');
            dibujar(); cambio();
            return;
        }
        if (r >= 0 && S.cols[c].t === 'semaforo' && inicial == null) {
            var ciclo = ['verde', 'amarillo', 'rojo', ''];
            var actual = normalizar(S.rows[r][c], 'semaforo');
            antes();
            fijar(r, c, ciclo[(ciclo.indexOf(actual) + 1) % ciclo.length]);
            dibujar(); cambio();
            return;
        }
        var el = celdaEl(r, c); if (!el) return;
        var inp = document.createElement('input');
        inp.className = 'sf-input'; inp.type = 'text'; inp.spellcheck = false; inp.autocomplete = 'off';
        var crudo = r < 0 ? S.cols[c].n : S.rows[r][c];
        inp.value = inicial != null ? inicial : (r >= 0 && S.cols[c].t === 'semaforo' && SEMAFORO[crudo] ? SEMAFORO[crudo] : (r >= 0 && S.cols[c].t === 'casilla' && crudo === '1' ? 'si' : crudo));
        var tc = r >= 0 ? S.cols[c].t : '';
        if (tc === 'fecha') {   // selector de calendario nativo
            inp.type = 'date';
            if (!/^\d{4}-\d{2}-\d{2}$/.test(inp.value)) inp.value = '';
        }
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
        if (e.key === 'Enter') {
            e.preventDefault();
            if (sel.r0 >= 0 && refDe(sel.r0, sel.c0)) { estado('Esta celda es un dato externo. Usa «Quitar vínculo» si quieres escribir un valor propio.', true); return; }
            antes(); fijar(sel.r0, sel.c0, fx.value); dibujar(); cambio(); area.focus();
        } else if (e.key === 'Escape') { pintarSeleccion(); area.focus(); }
    });
    var FUNCIONES_RANGO = ['SUMA', 'SUM', 'PROMEDIO', 'AVERAGE', 'PROM', 'MIN', 'MAX', 'CONTAR', 'COUNT'];

    /** Bloque de celdas numéricas (o fórmulas) contiguo hacia arriba o a la izquierda de (r,c). */
    function bloqueAutosuma(r, c, eje) {
        var dr = eje === 'r' ? -1 : 0, dc = eje === 'c' ? -1 : 0;
        var rr = r + dr, cc = c + dc, celdas = [];
        while (rr >= 0 && cc >= 0 && rr < S.rows.length && cc < S.cols.length) {
            var t = S.rows[rr][cc];
            if (t === '') break;
            if (!esFormula(t) && numero(t) === null) break;
            celdas.push({ r: rr, c: cc });
            rr += dr; cc += dc;
        }
        if (!celdas.length) return null;
        var a = celdas[celdas.length - 1], b = celdas[0];
        return { r0: Math.min(a.r, b.r), r1: Math.max(a.r, b.r), c0: Math.min(a.c, b.c), c1: Math.max(a.c, b.c) };
    }

    /** Si el destino cae dentro de un rango de la fórmula, lo mueve debajo del rango (nunca #CICLO! al insertar). */
    function evitarCiclo(f, r, c) {
        var re = /\$?([A-Za-z]{1,2})\$?(\d+)(?::\$?([A-Za-z]{1,2})\$?(\d+))?/g, m, maxR = -1, dentro = false;
        while ((m = re.exec(f))) {
            var c0 = indiceLetra(m[1].toUpperCase()), r0 = parseInt(m[2], 10) - 2;
            var c1 = m[3] ? indiceLetra(m[3].toUpperCase()) : c0, r1 = m[4] ? parseInt(m[4], 10) - 2 : r0;
            if (r >= Math.min(r0, r1) && r <= Math.max(r0, r1) && c >= Math.min(c0, c1) && c <= Math.max(c0, c1)) dentro = true;
            maxR = Math.max(maxR, Math.max(r0, r1));
        }
        return dentro ? Math.max(r, maxR + 1) : r;
    }

    /** Deja el cursor al final del primer argumento: al hacer clic en celdas se reemplaza ese rango. */
    function enfocarParteFormula(inp, texto) {
        inp.focus();
        var ini = texto.indexOf('('), pos = texto.length;
        if (ini >= 0) {
            var prof = 0;
            for (var i = ini + 1; i < texto.length; i++) {
                var ch = texto[i];
                if (ch === '(') prof++;
                else if (ch === ')') { if (prof === 0) { pos = i; break; } prof--; }
                else if ((ch === ';' || ch === ',') && prof === 0) { pos = i; break; }
            }
        }
        try { inp.setSelectionRange(pos, pos); } catch (e) { /* sin selección */ }
    }

    /** Inserta la función elegida sumando la selección (o el bloque contiguo), como el autosuma de LibreOffice. */
    function insertarFuncion(f) {
        if (!f || sel.r0 < 0) return;
        var n = rangoNorm();
        var m = /^=\s*([A-Za-zÁÉÍÓÚ.]+)\s*\(/.exec(f);
        var fn = m ? m[1].toUpperCase() : '';
        var multi = n.r0 !== n.r1 || n.c0 !== n.c1;
        var destinoR = sel.r0, destinoC = sel.c0;
        var rango = null;

        if (FUNCIONES_RANGO.indexOf(fn) >= 0) {
            if (multi) {
                rango = n;
                // Coloca el resultado fuera de la selección: debajo si es vertical, a la derecha si es horizontal.
                if (n.r0 === n.r1) { destinoR = n.r0; destinoC = n.c1 + 1; }
                else { destinoR = n.r1 + 1; destinoC = n.c0; }
            } else {
                rango = bloqueAutosuma(destinoR, destinoC, 'r') || bloqueAutosuma(destinoR, destinoC, 'c');
                // Sin números alrededor: deja la función vacía para que no se apunte a sí misma.
                if (!rango) f = '=' + fn + '()';
            }
            if (rango) f = '=' + fn + '(' + nombreCelda(rango.r0, rango.c0) + ':' + nombreCelda(rango.r1, rango.c1) + ')';
        } else {
            // Plantillas con ejemplo (SUMAR.SI, SI…): si el destino está dentro, bajarlo fuera del rango.
            destinoR = evitarCiclo(f, destinoR, destinoC);
        }

        var filasNuevas = Math.max(0, destinoR - S.rows.length + 1);
        var colsNuevas = Math.max(0, destinoC - S.cols.length + 1);
        if (S.rows.length + filasNuevas > MAX_F) { estado('Máximo ' + MAX_F + ' renglones', true); return; }
        if (S.cols.length + colsNuevas > MAX_C) { estado('Máximo ' + MAX_C + ' columnas', true); return; }

        antes();
        if (filasNuevas) insertar('r', S.rows.length, filasNuevas);
        if (colsNuevas) insertar('c', S.cols.length, colsNuevas);
        quitarRef(destinoR, destinoC);
        S.rows[destinoR][destinoC] = f;
        sel.r0 = sel.r1 = destinoR; sel.c0 = sel.c1 = destinoC;
        dibujar(); visible(destinoR, destinoC); cambio(); enfocarParteFormula(fx, f);
        estado('Fórmula insertada en ' + nombreCelda(destinoR, destinoC) + '. Haz clic en celdas para ajustar el rango.');
    }

    // Insertar una función de la lista en la celda elegida (queda en la barra de fórmulas para ajustar los rangos)
    $('sf-fn').addEventListener('change', function () {
        var f = this.value; this.value = '';
        insertarFuncion(f);
    });

    // ================= Datos externos =================
    var CATALOGO = null;
    function cargarCatalogo() {
        if (CATALOGO) return Promise.resolve(CATALOGO);
        var fd = new FormData();
        fd.append('accion', 'catalogo');
        return fetch(C.api.externo, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) throw new Error(d.error || 'No se pudo leer el catálogo de datos');
                CATALOGO = d;
                return d;
            });
    }

    function opcion(valor, texto) { var o = document.createElement('option'); o.value = valor; o.textContent = texto; return o; }

    function abrirDatosExternos() {
        var n = rangoNorm();
        if (sel.r0 < 0 || n.r0 !== n.r1 || n.c0 !== n.c1) {
            estado('Elige una sola celda para insertar el dato externo', true);
            return;
        }
        if (typeof abrirModal !== 'function') {
            estado('La ventana de datos aún no está disponible; recarga la página', true);
            return;
        }
        estado('Cargando datos…');
        cargarCatalogo().then(function (cat) {
            estado('');

            var FUENTES = [
                ['inventario_empresa', 'Inventario · totales del negocio'],
                ['inventario_producto', 'Inventario · dato de un producto'],
                ['cliente', 'Cliente · datos y ventas'],
                ['ventas', 'Ventas · periodo'],
                ['gastos', 'Gastos · periodo'],
                ['compras', 'Compras · periodo'],
                ['cotizaciones', 'Cotizaciones · periodo'],
                ['entregas', 'Entregas'],
                ['fiscal', 'Fiscal · periodo'],
            ];

            var cuerpo = document.createElement('div');
            cuerpo.className = 'columna';

            function campo(texto) {
                var l = document.createElement('label'); l.className = 'campo'; l.textContent = texto;
                cuerpo.appendChild(l); return l;
            }

            var lFuente = campo('¿Qué dato quieres traer?');
            var selFuente = document.createElement('select'); selFuente.className = 'control'; selFuente.id = 'sf-ext-fuente';
            FUENTES.forEach(function (f) { selFuente.appendChild(opcion(f[0], f[1])); });
            lFuente.appendChild(selFuente);

            var lEmp = campo('Negocio');
            var selEmp = document.createElement('select'); selEmp.className = 'control'; selEmp.id = 'sf-ext-emp';
            lEmp.appendChild(selEmp);

            var lCliente = campo('Cliente');
            var selCliente = document.createElement('select'); selCliente.className = 'control'; selCliente.id = 'sf-ext-cliente';
            (cat.clientes || []).forEach(function (c) {
                selCliente.appendChild(opcion(String(c.id), (c.rfc ? c.rfc + ' · ' : '') + c.nombre));
            });
            lCliente.appendChild(selCliente);

            var lContrib = campo('Contribuyente');
            var selContrib = document.createElement('select'); selContrib.className = 'control'; selContrib.id = 'sf-ext-contrib';
            (cat.contribuyentes || []).forEach(function (c) {
                selContrib.appendChild(opcion(String(c.id), (c.rfc ? c.rfc + ' · ' : '') + c.nombre));
            });
            lContrib.appendChild(selContrib);

            var lPeriodo = document.createElement('div'); lPeriodo.className = 'ed-fila';
            cuerpo.appendChild(lPeriodo);
            var lDesde = document.createElement('label'); lDesde.className = 'campo'; lDesde.textContent = 'Desde';
            var desde = document.createElement('input'); desde.type = 'date'; desde.className = 'control'; desde.id = 'sf-ext-desde';
            desde.value = cat.hoy.slice(0, 7) + '-01';
            lDesde.appendChild(desde); lPeriodo.appendChild(lDesde);
            var lHasta = document.createElement('label'); lHasta.className = 'campo'; lHasta.textContent = 'Hasta';
            var hasta = document.createElement('input'); hasta.type = 'date'; hasta.className = 'control'; hasta.id = 'sf-ext-hasta'; hasta.value = cat.hoy;
            lHasta.appendChild(hasta); lPeriodo.appendChild(lHasta);

            var lFiscal = document.createElement('div'); lFiscal.className = 'ed-fila';
            cuerpo.appendChild(lFiscal);
            var lAnio = document.createElement('label'); lAnio.className = 'campo'; lAnio.textContent = 'Año';
            var anio = document.createElement('input'); anio.type = 'number'; anio.min = '2000'; anio.max = '2100'; anio.value = cat.hoy.slice(0, 4); anio.className = 'control'; anio.id = 'sf-ext-anio';
            lAnio.appendChild(anio); lFiscal.appendChild(lAnio);
            var lMes = document.createElement('label'); lMes.className = 'campo'; lMes.textContent = 'Mes (0 = todo el año)';
            var mes = document.createElement('input'); mes.type = 'number'; mes.min = '0'; mes.max = '12'; mes.value = String(parseInt(cat.hoy.slice(5, 7), 10)); mes.className = 'control'; mes.id = 'sf-ext-mes';
            lMes.appendChild(mes); lFiscal.appendChild(lMes);

            var lBuscar = campo('Buscar producto');
            var buscar = document.createElement('input'); buscar.type = 'search'; buscar.className = 'control'; buscar.id = 'sf-ext-buscar'; buscar.placeholder = 'Código o nombre…'; buscar.autocomplete = 'off';
            lBuscar.appendChild(buscar);

            var lProd = campo('Producto');
            var selProd = document.createElement('select'); selProd.className = 'control'; selProd.id = 'sf-ext-prod';
            lProd.appendChild(selProd);

            var lCampo = campo('Dato');
            var selCampo = document.createElement('select'); selCampo.className = 'control'; selCampo.id = 'sf-ext-campo';
            lCampo.appendChild(selCampo);

            var vista = document.createElement('p'); vista.className = 'text-sm text-muted'; cuerpo.appendChild(vista);
            var nota = document.createElement('p'); nota.className = 'text-sm text-muted';
            nota.textContent = 'La celda quedará vinculada y no se podrá escribir a mano. Para un valor propio, usa «Quitar vínculo».';
            cuerpo.appendChild(nota);

            function grupoCampos() {
                var f = selFuente.value;
                if (f === 'inventario_empresa') return cat.campos.empresa;
                if (f === 'inventario_producto') return cat.campos.producto;
                if (f === 'cliente') return cat.campos.cliente;
                if (f === 'ventas') return cat.campos.ventas;
                if (f === 'gastos') return cat.campos.gastos;
                if (f === 'compras') return cat.campos.compras;
                if (f === 'cotizaciones') return cat.campos.cotizaciones;
                if (f === 'entregas') return cat.campos.entregas;
                return cat.campos.fiscal;
            }
            function empresa() {
                var lista = selFuente.value === 'inventario_empresa' || selFuente.value === 'inventario_producto' ? cat.empresas : (cat.negocios || []);
                return lista.filter(function (e) { return String(e.id) === selEmp.value; })[0] || lista[0] || null;
            }
            function producto() {
                var e = empresa();
                return ((e && e.productos) || []).filter(function (p) { return String(p.id) === selProd.value; })[0] || null;
            }
            function poblarEmpresas() {
                var esInv = selFuente.value === 'inventario_empresa' || selFuente.value === 'inventario_producto';
                var lista = esInv ? cat.empresas : (cat.negocios || []);
                var actual = selEmp.value;
                selEmp.innerHTML = '';
                if (!esInv) selEmp.appendChild(opcion('0', 'Todas las empresas'));
                lista.forEach(function (e) { selEmp.appendChild(opcion(String(e.id), e.nombre)); });
                if (actual && selEmp.querySelector('option[value="' + actual + '"]')) selEmp.value = actual;
                if (!selEmp.options.length) selEmp.appendChild(opcion('', 'Sin negocios'));
            }
            function poblarProductos() {
                var e = empresa();
                var filtro = buscar.value.trim().toLowerCase();
                var actualP = selProd.value;
                selProd.innerHTML = '';
                ((e && e.productos) || []).forEach(function (p) {
                    var texto = (p.sku ? p.sku + ' · ' : '') + p.nombre;
                    if (filtro && texto.toLowerCase().indexOf(filtro) < 0) return;
                    selProd.appendChild(opcion(String(p.id), texto));
                });
                if (actualP && selProd.querySelector('option[value="' + actualP.replace(/"/g, '\\"') + '"]')) selProd.value = actualP;
                if (!selProd.options.length) selProd.appendChild(opcion('', 'Sin productos que coincidan'));
            }
            function poblarCampos() {
                var defs = grupoCampos(), actual = selCampo.value;
                selCampo.innerHTML = '';
                Object.keys(defs).forEach(function (k) {
                    if (selFuente.value === 'entregas' && k !== 'pendientes' && k !== 'entregas' && k !== 'piezas') return;
                    selCampo.appendChild(opcion(k, defs[k].etiqueta));
                });
                if (actual && defs[actual]) selCampo.value = actual;
            }
            function claveActual() {
                var f = selFuente.value, campo = selCampo.value;
                if (!campo) return '';
                if (f === 'inventario_empresa') {
                    var e = empresa(); return e ? 'inventario:empresa:' + e.id + ':' + campo : '';
                }
                if (f === 'inventario_producto') {
                    var e2 = empresa(), p = producto();
                    return e2 && p ? 'inventario:producto:' + e2.id + ':' + p.id + ':' + campo : '';
                }
                if (f === 'cliente') return 'clientes:cliente:' + selCliente.value + ':' + campo;
                if (f === 'ventas' || f === 'gastos') {
                    var emp = parseInt(selEmp.value, 10) || 0;
                    var base = f + ':' + (emp > 0 ? 'empresa:' + emp + ':' : 'periodo:');
                    return base + desde.value + ':' + hasta.value + ':' + campo;
                }
                if (f === 'compras') return 'compras:periodo:' + desde.value + ':' + hasta.value + ':' + campo;
                if (f === 'cotizaciones') return 'cotizaciones:periodo:' + desde.value + ':' + hasta.value + ':' + campo;
                if (f === 'entregas') {
                    if (campo === 'pendientes') {
                        var empE = parseInt(selEmp.value, 10) || 0;
                        return empE > 0 ? 'entregas:pendientes:empresa:' + empE : 'entregas:pendientes';
                    }
                    return 'entregas:periodo:' + desde.value + ':' + hasta.value + ':' + campo;
                }
                if (f === 'fiscal') {
                    return 'fiscal:' + selContrib.value + ':' + anio.value + ':' + (parseInt(mes.value, 10) || 0) + ':' + campo;
                }
                return '';
            }
            var tokenVista = 0;
            function actualizarVista() {
                var clave = claveActual();
                var def = grupoCampos()[selCampo.value] || {};
                if (!clave || !/^\d{4}-\d{2}-\d{2}$/.test(desde.value) || !/^\d{4}-\d{2}-\d{2}$/.test(hasta.value)) {
                    vista.textContent = 'Completa los datos para ver el valor actual.';
                    return;
                }
                var mio = ++tokenVista;
                vista.textContent = 'Consultando…';
                resolverClave(clave).then(function (v) {
                    if (mio !== tokenVista) return;
                    if (v === '') { vista.textContent = 'El dato está vacío actualmente.'; return; }
                    var tipo = def.tipo === 'moneda' ? 'moneda' : def.tipo === 'texto' ? 'texto' : def.tipo === 'numero' ? 'numero' : 'numero';
                    vista.textContent = 'Valor actual: ' + (tipo === 'texto' ? v : formatear(v, tipo));
                }).catch(function () {
                    if (mio === tokenVista) vista.textContent = 'No se pudo consultar el valor actual.';
                });
            }
            function resolverClave(clave) {
                var fd = new FormData();
                fd.append('accion', 'valores');
                fd.append('refs', JSON.stringify({ '0,0': clave }));
                return fetch(C.api.externo, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.success) throw new Error(d.error || 'No se pudo leer el dato');
                        return d.valores && d.valores[clave] != null ? String(d.valores[clave]) : '';
                    });
            }
            function modo() {
                var f = selFuente.value;
                var esInv = f === 'inventario_empresa' || f === 'inventario_producto';
                var esProducto = f === 'inventario_producto';
                lEmp.hidden = !(esInv || f === 'ventas' || f === 'gastos' || f === 'entregas');
                lCliente.hidden = f !== 'cliente';
                lContrib.hidden = f !== 'fiscal';
                lFiscal.hidden = f !== 'fiscal';
                lBuscar.hidden = !esProducto;
                lProd.hidden = !esProducto;
                lPeriodo.hidden = !(f === 'ventas' || f === 'gastos' || f === 'compras' || f === 'cotizaciones' || ((f === 'entregas') && selCampo.value !== 'pendientes'));
                poblarEmpresas();
                if (esProducto) poblarProductos();
                poblarCampos();
                actualizarVista();
            }

            selFuente.addEventListener('change', modo);
            selEmp.addEventListener('change', function () { poblarProductos(); actualizarVista(); });
            selCliente.addEventListener('change', actualizarVista);
            selContrib.addEventListener('change', actualizarVista);
            desde.addEventListener('change', actualizarVista);
            hasta.addEventListener('change', actualizarVista);
            anio.addEventListener('change', actualizarVista);
            mes.addEventListener('change', actualizarVista);
            buscar.addEventListener('input', function () { poblarProductos(); actualizarVista(); });
            selProd.addEventListener('change', actualizarVista);
            selCampo.addEventListener('change', function () { lPeriodo.hidden = !(selFuente.value === 'ventas' || selFuente.value === 'gastos' || selFuente.value === 'compras' || selFuente.value === 'cotizaciones' || (selFuente.value === 'entregas' && selCampo.value !== 'pendientes')); actualizarVista(); });

            modo();

            abrirModal({
                sobreTitulo: 'SheetFlow',
                titulo: 'Insertar dato externo',
                cuerpo: cuerpo,
                textoConfirmar: 'Insertar',
                alConfirmar: function () {
                    if (selFuente.value === 'inventario_producto' && !producto()) { estado('Elige un producto', true); return; }
                    if (selFuente.value === 'cliente' && !selCliente.value) { estado('No hay clientes para elegir', true); return; }
                    if (selFuente.value === 'fiscal' && !selContrib.value) { estado('No hay contribuyentes configurados', true); return; }
                    if (desde.value && hasta.value && desde.value > hasta.value) { estado('La fecha «desde» no puede ser mayor que «hasta»', true); return; }
                    var clave = claveActual();
                    if (!clave) { estado('Completa los datos del origen', true); return; }
                    var def = grupoCampos()[selCampo.value] || {};
                    var r = sel.r0, c = sel.c0, col = S.cols[c];
                    var colVacia = S.rows.every(function (fila, i) { return i === r || fila[c] === ''; });
                    estado('Cargando ' + (def.etiqueta || 'dato') + '…');
                    resolverClave(clave).then(function (valor) {
                        antes();
                        S.rows[r][c] = valor;
                        S.refs[r + ',' + c] = clave;
                        if (colVacia && col.t === 'texto') {
                            col.t = def.tipo === 'moneda' ? 'moneda' : def.tipo === 'texto' ? 'texto' : 'numero';
                        }
                        dibujar(); cambio();
                        estado('Dato externo insertado en ' + nombreCelda(r, c));
                        marcarFrescura();
                    }).catch(function (e) {
                        estado(e.message || 'No se pudo insertar el dato', true);
                    });
                }
            });
        }).catch(function (e) { estado(e.message || 'No se pudieron cargar los datos externos', true); });
    }

    function actualizarExternos() {
        var claves = Object.keys(S.refs);
        if (!claves.length) { estado('Esta tabla no tiene datos externos vinculados', true); return; }
        estado('Actualizando datos externos…');
        var fd = new FormData();
        fd.append('accion', 'valores');
        fd.append('refs', JSON.stringify(S.refs));
        fetch(C.api.externo, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) throw new Error(d.error || 'No se pudieron actualizar los datos');
                var cambios = [], faltan = 0;
                claves.forEach(function (k) {
                    var clave = S.refs[k];
                    if (!(clave in d.valores)) { faltan++; return; }
                    var p = k.split(','), r = +p[0], c = +p[1];
                    var nuevo = String(d.valores[clave]);
                    if (S.rows[r] && S.rows[r][c] !== nuevo) cambios.push([r, c, nuevo]);
                });
                if (cambios.length) {
                    antes();
                    cambios.forEach(function (x) { S.rows[x[0]][x[1]] = x[2]; });
                    dibujar(); cambio();
                    estado('Datos externos actualizados (' + cambios.length + ')');
                } else {
                    estado(faltan ? 'Sin cambios; algunos datos ya no están disponibles' : 'Los datos externos no han cambiado');
                }
                marcarFrescura();
                if (faltan) setTimeout(function () { estado(faltan + ' dato(s) externo(s) ya no están disponibles; se conserva el último valor', true); }, 2200);
            })
            .catch(function (e) { estado(e.message || 'No se pudieron actualizar los datos externos', true); });
    }

    function quitarVinculo() {
        var n = rangoNorm();
        if (n.r0 < 0) return;
        var habia = false;
        for (var r = n.r0; r <= n.r1; r++) for (var c = n.c0; c <= n.c1; c++) if (refDe(r, c)) habia = true;
        if (!habia) { estado('Las celdas elegidas no tienen vínculo externo', true); return; }
        antes();
        for (var rr = n.r0; rr <= n.r1; rr++) for (var cc = n.c0; cc <= n.c1; cc++) quitarRef(rr, cc);
        dibujar(); cambio();
        estado('Vínculo quitado; el valor actual queda como número normal');
    }

    $('sf-ext-insertar').addEventListener('click', abrirDatosExternos);
    $('sf-ext-actualizar').addEventListener('click', actualizarExternos);
    $('sf-ext-quitar').addEventListener('click', quitarVinculo);

    // ================= Insertar una lista completa =================
    function escribirLista(filas, tipos, alinear) {
        if (!filas || !filas.length) { estado('La lista está vacía', true); return; }
        var r0 = Math.max(0, sel.r0), c0 = Math.max(0, sel.c0);
        var nc = Math.max.apply(null, filas.map(function (f) { return f.length; }));
        if (r0 + filas.length > MAX_F || c0 + nc > MAX_C) {
            estado('La lista no cabe desde esa celda (máx. ' + MAX_F + ' renglones y ' + MAX_C + ' columnas)', true);
            return;
        }
        antes();
        while (S.cols.length < c0 + nc) {
            S.cols.push({ n: 'Columna ' + (S.cols.length + 1), t: 'texto', a: 'izquierda', tot: '', w: 0 });
            hsty.push(null);
            S.rows.forEach(function (fila, r) { fila.push(''); sty[r].push(null); });
        }
        while (S.rows.length < r0 + filas.length) {
            S.rows.push(S.cols.map(function () { return ''; }));
            sty.push(S.cols.map(function () { return null; }));
        }
        for (var c = 0; c < nc; c++) {
            var col = S.cols[c0 + c];
            var vacia = S.rows.every(function (fila) { return fila[c0 + c] === ''; });
            if (vacia && tipos && tipos[c] && tipos[c] !== 'texto') {
                col.t = tipos[c];
                col.a = (alinear && alinear[c]) || col.a;
            }
        }
        for (var r = 0; r < filas.length; r++) {
            for (var cc = 0; cc < nc; cc++) fijar(r0 + r, c0 + cc, filas[r][cc] == null ? '' : filas[r][cc]);
        }
        for (var ch = 0; ch < nc; ch++) {
            var f = Object.assign({}, getSt(r0, c0 + ch) || {});
            f.b = 1;
            setSt(r0, c0 + ch, f);
        }
        sel.r0 = r0; sel.c0 = c0; sel.r1 = r0 + filas.length - 1; sel.c1 = c0 + nc - 1;
        dibujar(); cambio();
        estado('Lista insertada: ' + filas.length + ' renglones');
    }

    function abrirListaExterna() {
        if (typeof abrirModal !== 'function') { estado('La ventana de datos aún no está disponible; recarga la página', true); return; }
        estado('Cargando datos…');
        cargarCatalogo().then(function (cat) {
            estado('');
            var cuerpo = document.createElement('div');
            cuerpo.className = 'columna';

            function campo(texto) {
                var l = document.createElement('label'); l.className = 'campo'; l.textContent = texto;
                cuerpo.appendChild(l); return l;
            }

            var lFuente = campo('Lista');
            var selFuente = document.createElement('select'); selFuente.className = 'control'; selFuente.id = 'sf-lista-fuente';
            [['inventario', 'Inventario de un negocio'], ['clientes', 'Clientes'], ['ventas', 'Ventas del periodo'],
                ['cotizaciones', 'Cotizaciones del periodo'], ['gastos', 'Gastos del periodo'], ['compras', 'Compras del periodo']]
                .forEach(function (f) { selFuente.appendChild(opcion(f[0], f[1])); });
            lFuente.appendChild(selFuente);

            var lEmp = campo('Negocio');
            var selEmp = document.createElement('select'); selEmp.className = 'control'; selEmp.id = 'sf-lista-emp';
            lEmp.appendChild(selEmp);

            var lPeriodo = document.createElement('div'); lPeriodo.className = 'ed-fila';
            cuerpo.appendChild(lPeriodo);
            var lDesde = document.createElement('label'); lDesde.className = 'campo'; lDesde.textContent = 'Desde';
            var desde = document.createElement('input'); desde.type = 'date'; desde.className = 'control'; desde.id = 'sf-lista-desde'; desde.value = cat.hoy.slice(0, 7) + '-01';
            lDesde.appendChild(desde); lPeriodo.appendChild(lDesde);
            var lHasta = document.createElement('label'); lHasta.className = 'campo'; lHasta.textContent = 'Hasta';
            var hasta = document.createElement('input'); hasta.type = 'date'; hasta.className = 'control'; hasta.id = 'sf-lista-hasta'; hasta.value = cat.hoy;
            lHasta.appendChild(hasta); lPeriodo.appendChild(lHasta);

            var nota = document.createElement('p'); nota.className = 'text-sm text-muted';
            nota.textContent = 'La lista se escribe desde la celda elegida y puede reemplazar lo que ya esté ahí. Puedes deshacer con Ctrl+Z.';
            cuerpo.appendChild(nota);

            function poblarEmpresas() {
                var inv = selFuente.value === 'inventario';
                var lista = inv ? cat.empresas : (cat.negocios || []);
                var actual = selEmp.value;
                selEmp.innerHTML = '';
                if (!inv) selEmp.appendChild(opcion('0', 'Todas las empresas'));
                lista.forEach(function (e) { selEmp.appendChild(opcion(String(e.id), e.nombre)); });
                if (actual && selEmp.querySelector('option[value="' + actual + '"]')) selEmp.value = actual;
            }
            function modo() {
                var f = selFuente.value;
                lEmp.hidden = !(f === 'inventario' || f === 'ventas' || f === 'gastos');
                lPeriodo.hidden = !(f === 'ventas' || f === 'cotizaciones' || f === 'gastos' || f === 'compras');
                poblarEmpresas();
            }
            selFuente.addEventListener('change', modo);
            modo();

            abrirModal({
                sobreTitulo: 'SheetFlow',
                titulo: 'Insertar lista',
                cuerpo: cuerpo,
                textoConfirmar: 'Insertar',
                alConfirmar: function () {
                    var f = selFuente.value;
                    var params = { empresa: parseInt(selEmp.value, 10) || 0, desde: desde.value, hasta: hasta.value };
                    if (f === 'inventario' && !params.empresa) { estado('Elige un negocio con inventario', true); return; }
                    var fd = new FormData();
                    fd.append('accion', 'lista');
                    fd.append('tipo', f);
                    fd.append('params', JSON.stringify(params));
                    estado('Cargando la lista…');
                    fetch(C.api.externo, { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (!d.success) throw new Error(d.error || 'No se pudo leer la lista');
                            escribirLista(d.filas, d.tipos, d.alinear);
                        })
                        .catch(function (e) { estado(e.message || 'No se pudo insertar la lista', true); });
                }
            });
        }).catch(function (e) { estado(e.message || 'No se pudieron cargar los datos', true); });
    }
    $('sf-lista-insertar').addEventListener('click', abrirListaExterna);

    // ================= Combinar celdas =================
    $('sf-combinar').addEventListener('click', function () {
        var n = rangoNorm(); n.r0 = Math.max(0, n.r0);
        if (n.r1 <= n.r0 && n.c1 <= n.c0) { estado('Elige al menos dos celdas para combinar', true); return; }
        antes();
        S.merges = S.merges.filter(function (m) { return m.r1 < n.r0 || m.r0 > n.r1 || m.c1 < n.c0 || m.c0 > n.c1; });
        S.merges.push({ r0: n.r0, c0: n.c0, r1: n.r1, c1: n.c1 });
        dibujar(); cambio(); estado('Celdas combinadas');
    });
    $('sf-separar').addEventListener('click', function () {
        var n = rangoNorm(); n.r0 = Math.max(0, n.r0);
        var antesN = S.merges.length;
        antes();
        S.merges = S.merges.filter(function (m) { return m.r1 < n.r0 || m.r0 > n.r1 || m.c1 < n.c0 || m.c0 > n.c1; });
        dibujar(); cambio();
        estado(antesN === S.merges.length ? 'No hay celdas combinadas en ese rango' : 'Celdas separadas');
    });

    // ================= Tabla dinámica =================
    $('sf-pivote').addEventListener('click', function () {
        if (typeof abrirModal !== 'function') { estado('La ventana aún no está disponible', true); return; }
        var n = rangoNorm(); n.r0 = Math.max(0, n.r0); if (n.r1 < n.r0) n.r1 = n.r0;
        var cuerpo = document.createElement('div'); cuerpo.className = 'columna';
        function campo(texto) { var l = document.createElement('label'); l.className = 'campo'; l.textContent = texto; cuerpo.appendChild(l); return l; }
        var lNom = campo('Nombre del resumen'); var nombre = document.createElement('input'); nombre.type = 'text'; nombre.className = 'control'; nombre.value = 'Resumen ' + new Date().toLocaleDateString('es-MX'); lNom.appendChild(nombre);
        var lF = campo('Filas (agrupar por)'); var selF = document.createElement('select'); selF.className = 'control'; selF.id = 'sf-piv-f';
        for (var c = n.c0; c <= n.c1; c++) selF.appendChild(opcion(String(c), letra(c) + ' · ' + (S.cols[c].n || 'Columna ' + (c + 1))));
        lF.appendChild(selF);
        var lC = campo('Columnas (opcional)'); var selC = document.createElement('select'); selC.className = 'control'; selC.id = 'sf-piv-c';
        selC.appendChild(opcion('-1', 'Sin columnas'));
        for (var c2 = n.c0; c2 <= n.c1; c2++) if (c2 !== parseInt(selF.value, 10)) selC.appendChild(opcion(String(c2), letra(c2) + ' · ' + (S.cols[c2].n || 'Columna ' + (c2 + 1))));
        lC.appendChild(selC);
        var lV = campo('Valores'); var selV = document.createElement('select'); selV.className = 'control'; selV.id = 'sf-piv-v';
        for (var c3 = n.c0; c3 <= n.c1; c3++) if (NUM.indexOf(S.cols[c3].t) >= 0) selV.appendChild(opcion(String(c3), letra(c3) + ' · ' + (S.cols[c3].n || 'Columna ' + (c3 + 1))));
        selV.appendChild(opcion('-1', 'Contar renglones'));
        lV.appendChild(selV);
        var lOp = campo('Operación'); var op = document.createElement('select'); op.className = 'control'; op.id = 'sf-piv-op';
        op.appendChild(opcion('suma', 'Suma')); op.appendChild(opcion('cuenta', 'Cuenta')); op.appendChild(opcion('promedio', 'Promedio')); lOp.appendChild(op);
        var nota = document.createElement('p'); nota.className = 'text-sm text-muted';
        nota.textContent = 'Se crea una hoja nueva con el resumen; la tabla original no cambia.';
        cuerpo.appendChild(nota);

        function recalcularColumnas() {
            var actual = selC.value;
            selC.innerHTML = '';
            selC.appendChild(opcion('-1', 'Sin columnas'));
            for (var c4 = n.c0; c4 <= n.c1; c4++) if (c4 !== parseInt(selF.value, 10)) selC.appendChild(opcion(String(c4), letra(c4) + ' · ' + (S.cols[c4].n || 'Columna ' + (c4 + 1))));
            if (actual && selC.querySelector('option[value="' + actual + '"]')) selC.value = actual;
        }
        selF.addEventListener('change', recalcularColumnas);

        abrirModal({
            sobreTitulo: 'SheetFlow', titulo: 'Tabla dinámica', cuerpo: cuerpo, textoConfirmar: 'Crear resumen',
            alConfirmar: function () {
                var cf = parseInt(selF.value, 10), cc = parseInt(selC.value, 10), cv = parseInt(selV.value, 10), operacion = op.value;
                var grupos = {}, fs = [], cs = [];
                for (var r = n.r0; r <= n.r1; r++) {
                    var f = mostrado(r, cf) || '(vacío)';
                    var col = cc >= 0 ? (mostrado(r, cc) || '(vacío)') : '';
                    if (fs.indexOf(f) < 0) fs.push(f);
                    if (cc >= 0 && cs.indexOf(col) < 0) cs.push(col);
                    var k = f + '\u0000' + col;
                    if (!grupos[k]) grupos[k] = { suma: 0, n: 0 };
                    if (cv >= 0) { var v = numero(mostrado(r, cv)); if (v !== null) grupos[k].suma += v; }
                    grupos[k].n++;
                }
                fs.sort(function (a, b) { return String(a).localeCompare(String(b), 'es', { numeric: true }); });
                cs.sort(function (a, b) { return String(a).localeCompare(String(b), 'es', { numeric: true }); });
                function val(d) { return operacion === 'cuenta' || cv < 0 ? d.n : operacion === 'promedio' ? (d.n ? d.suma / d.n : 0) : d.suma; }
                function num(x) { return String(Math.round(x * 100) / 100); }
                var out = [], enc = [S.cols[cf].n || 'Grupo'];
                if (cc >= 0) cs.forEach(function (x) { enc.push(x); }); else enc.push(cv >= 0 ? (S.cols[cv].n || 'Valor') : 'Cuenta');
                if (cc >= 0) enc.push('Total');
                out.push(enc);
                var totales = new Array(Math.max(1, cc >= 0 ? cs.length : 1)).fill(0);
                fs.forEach(function (f) {
                    var fila = [f];
                    if (cc >= 0) {
                        var tot = 0;
                        cs.forEach(function (x, i) { var v = val(grupos[f + '\u0000' + x] || { suma: 0, n: 0 }); fila.push(num(v)); tot += v; totales[i] += v; });
                        fila.push(num(tot));
                    } else {
                        var v = val(grupos[f + '\u0000'] || { suma: 0, n: 0 }); fila.push(num(v)); totales[0] += v;
                    }
                    out.push(fila);
                });
                var tf = ['Total'];
                if (cc >= 0) { totales.forEach(function (v) { tf.push(num(v)); }); tf.push(num(totales.reduce(function (a, b) { return a + b; }, 0))); }
                else tf.push(num(totales[0]));
                out.push(tf);
                var fd = new FormData();
                fd.append('nombre', nombre.value || 'Resumen');
                fd.append('filas', JSON.stringify(out));
                estado('Creando el resumen…');
                fetch('/admin/sheetflow/api/pivote', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.success) throw new Error(d.error || 'No se pudo crear el resumen');
                        estado('Resumen creado');
                        location.href = '/admin/sheetflow/abrir?id=' + d.id;
                    })
                    .catch(function (e) { estado(e.message || 'No se pudo crear el resumen', true); });
            }
        });
    });

    $('sf-subtotales').addEventListener('click', function () {
        if (typeof abrirModal !== 'function') { estado('La ventana aún no está disponible', true); return; }
        var n = rangoNorm();
        n.r0 = Math.max(0, n.r0); if (n.r1 < n.r0) n.r1 = n.r0;
        var cuerpo = document.createElement('div'); cuerpo.className = 'columna';
        function campo(texto) { var l = document.createElement('label'); l.className = 'campo'; l.textContent = texto; cuerpo.appendChild(l); return l; }
        var lG = campo('Agrupar por'); var selG = document.createElement('select'); selG.className = 'control'; selG.id = 'sf-sub-g';
        for (var c = n.c0; c <= n.c1; c++) selG.appendChild(opcion(String(c), letra(c) + ' · ' + (S.cols[c].n || 'Columna ' + (c + 1))));
        lG.appendChild(selG);
        var lS = campo('Sumar'); var selS = document.createElement('select'); selS.className = 'control'; selS.id = 'sf-sub-s';
        for (var c2 = n.c0; c2 <= n.c1; c2++) {
            if (NUM.indexOf(S.cols[c2].t) >= 0) selS.appendChild(opcion(String(c2), letra(c2) + ' · ' + (S.cols[c2].n || 'Columna ' + (c2 + 1))));
        }
        selS.appendChild(opcion('-1', 'Solo contar renglones'));
        lS.appendChild(selS);
        var lVis = document.createElement('label'); lVis.className = 'sf-chk';
        lVis.innerHTML = '<input type="checkbox" id="sf-sub-vis"> Solo los renglones visibles (con filtro)';
        cuerpo.appendChild(lVis);
        var nota = document.createElement('p'); nota.className = 'text-sm text-muted';
        nota.textContent = 'El resumen se agrega al final de la tabla; puedes deshacerlo con Ctrl+Z.';
        cuerpo.appendChild(nota);
        abrirModal({
            sobreTitulo: 'SheetFlow', titulo: 'Subtotales por grupo', cuerpo: cuerpo, textoConfirmar: 'Agregar',
            alConfirmar: function () {
                var cg = parseInt(selG.value, 10), cs = parseInt(selS.value, 10);
                var soloVis = document.getElementById('sf-sub-vis').checked;
                var grupos = {}, orden = [];
                for (var r = n.r0; r <= n.r1; r++) {
                    if (soloVis && filtro.texto && !filaVisible(r)) continue;
                    var clave = mostrado(r, cg) || '(vacío)';
                    if (!(clave in grupos)) { grupos[clave] = { suma: 0, n: 0 }; orden.push(clave); }
                    grupos[clave].n++;
                    if (cs >= 0) { var v = numero(mostrado(r, cs)); if (v !== null) grupos[clave].suma += v; }
                }
                if (!orden.length) { estado('No hay datos que agrupar', true); return; }
                var fin = S.rows.length;
                while (fin > 0 && S.rows[fin - 1].every(function (x) { return x === ''; })) fin--;
                var inicio = fin + 1;
                antes();
                while (S.rows.length < inicio + orden.length + 3) {
                    S.rows.push(S.cols.map(function () { return ''; }));
                    sty.push(S.cols.map(function () { return null; }));
                }
                function poner(r, c, v) { fijar(r, c, v); }
                poner(inicio, cg, cg === cs ? 'Grupo' : (S.cols[cg].n || 'Grupo'));
                if (cs >= 0) poner(inicio, cs, S.cols[cs].n || 'Suma');
                if (cs < 0) poner(inicio, cg + 1 <= S.cols.length - 1 ? cg + 1 : cg, 'Renglones');
                for (var i = 0; i < orden.length; i++) {
                    poner(inicio + 1 + i, cg, orden[i]);
                    var val = cs >= 0 ? grupos[orden[i]].suma : grupos[orden[i]].n;
                    poner(inicio + 1 + i, cs >= 0 ? cs : (cg + 1 <= S.cols.length - 1 ? cg + 1 : cg), String(Math.round(val * 100) / 100));
                }
                var tf = inicio + orden.length + 1;
                poner(tf, cg, 'Total');
                var total = 0;
                Object.keys(grupos).forEach(function (k) { total += cs >= 0 ? grupos[k].suma : grupos[k].n; });
                poner(tf, cs >= 0 ? cs : (cg + 1 <= S.cols.length - 1 ? cg + 1 : cg), String(Math.round(total * 100) / 100));
                [inicio, tf].forEach(function (rr) { for (var c3 = n.c0; c3 <= n.c1; c3++) { var f = Object.assign({}, getSt(rr, c3) || {}); f.b = 1; setSt(rr, c3, f); } });
                sel.r0 = inicio; sel.c0 = Math.min(cg, S.cols.length - 1); sel.r1 = tf; sel.c1 = sel.c0;
                dibujar(); cambio(); area.focus();
                estado('Subtotales agregados: ' + orden.length + ' grupos');
            }
        });
    });

    // ================= Selección con el ratón =================
    var arrastrando = false, tirando = null;
    grid.addEventListener('mousedown', function (e) {
        var tir = e.target.closest('.sf-tirador');
        if (tir) { var c0 = +tir.dataset.tir, th = tir.parentNode; tirando = { c: c0, x: e.clientX, w: th.getBoundingClientRect().width / (zoom / 100), antes: copia() }; e.preventDefault(); return; }
        var t = e.target.closest('td,th'); if (!t || e.target.classList.contains('sf-input')) return;

        // Mientras se escribe una fórmula: el clic elige celdas para insertar su referencia.
        var enFormula = formulaEditando();
        if (enFormula) {
            var pf = null, pc = null, hf = null, hc = null;
            if (t.dataset.r != null) {
                pf = +t.dataset.r; pc = +t.dataset.c;
            } else if (t.classList.contains('sf-letra')) {
                pf = 0; pc = +t.dataset.letra;
                hf = Math.max(0, S.rows.length - 1); hc = pc;
            } else if (t.classList.contains('sf-num') && t.dataset.fila != null && +t.dataset.fila >= 0) {
                pf = +t.dataset.fila; pc = 0;
                hf = pf; hc = S.cols.length - 1;
            }
            if (pf !== null) {
                e.preventDefault();
                e.stopPropagation();
                pickFormula = {
                    input: enFormula.input, tipo: enFormula.tipo,
                    r0: pf, c0: pc, r1: hf !== null ? hf : pf, c1: hc !== null ? hc : pc,
                    editR: enFormula.r, editC: enFormula.c,
                    pos: enFormula.input.selectionStart
                };
                sel.r0 = pickFormula.r0; sel.c0 = pickFormula.c0;
                sel.r1 = pickFormula.r1; sel.c1 = pickFormula.c1;
                pintarSeleccion();
                return;
            }
        }

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
        var t = e.target.closest('td,th.sf-enc');
        if (pickFormula) {
            if (!t || t.dataset.r == null) return;
            pickFormula.r1 = +t.dataset.r;
            pickFormula.c1 = +t.dataset.c;
            sel.r0 = pickFormula.r0; sel.c0 = pickFormula.c0;
            sel.r1 = pickFormula.r1; sel.c1 = pickFormula.c1;
            pintarSeleccion();
            return;
        }
        if (!arrastrando) return;
        if (!t || t.dataset.r == null) return;
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
        if (pickFormula) { finalizarPickFormula(); return; }
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
        for (var r = n.r0; r <= n.r1; r++) for (var c = n.c0; c <= n.c1; c++) { if (r >= 0) { S.rows[r][c] = ''; quitarRef(r, c); } }
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
    /** Mueve o elimina las claves de `refs` cuando cambia la estructura. */
    function remapearRefs(eje, mapa) {
        var out = {};
        Object.keys(S.refs).forEach(function (k) {
            var p = k.split(','), r = +p[0], c = +p[1];
            var nv = mapa(eje === 'r' ? r : c);
            if (nv < 0) return;
            if (eje === 'r') r = nv; else c = nv;
            out[r + ',' + c] = S.refs[k];
        });
        S.refs = out;
    }
    function insertar(eje, en, k) {
        reescribir(eje, function (v) { return v >= en ? v + k : v; });
        remapearRefs(eje, function (v) { return v >= en ? v + k : v; });
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
        remapearRefs(eje, function (v) {
            if (v < desde) return v;
            if (v > hasta) return v - k;
            return -1;
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
                if (Object.keys(S.refs).length) { estado('No se puede ordenar una tabla con datos externos (los vínculos cambiarían)', true); return; }
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
        irAPestana('modificar');
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
        irAPestana('modificar');
        var v = this.value;
        antes();
        columnasElegidas().forEach(function (c) { var col = S.cols[c]; col.tot = v === '' || v === 'cuenta' || NUM.indexOf(col.t) >= 0 ? v : ''; });
        if (v && v !== 'cuenta' && columnasElegidas().some(function (c) { return NUM.indexOf(S.cols[c].t) < 0; })) estado('El total solo se aplica a columnas de tipo número, moneda o porcentaje (o «Cuenta»)', true);
        dibujar(); cambio();
    });
    $('sf-ancho-auto').addEventListener('click', function () { irAPestana('modificar'); antes(); columnasElegidas().forEach(function (c) { S.cols[c].w = 0; }); dibujar(); cambio(); });
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
        irAPestana('modificar');
        var ancla = getSt(sel.r0, sel.c0) || {}, on = !ancla[k];
        aplicar(function (f) { if (on) f[k] = 1; else delete f[k]; });
    }
    document.querySelectorAll('[data-fmt]').forEach(function (b) { b.addEventListener('click', function () { alternar(b.dataset.fmt); }); });
    document.querySelectorAll('[data-alin]').forEach(function (b) {
        b.addEventListener('click', function () {
            irAPestana('modificar');
            var v = b.dataset.alin;
            antes();
            columnasElegidas().forEach(function (c) { S.cols[c].a = v; });   // la alineación de la columna
            aplicar(function (f) { delete f.al; }, true);                    // y se quita la particular de las celdas para que mande la columna
        });
    });
    function cambiarTamano(delta, valor) {
        irAPestana('modificar');
        aplicar(function (f) { f.fs = valor != null ? valor : Math.max(6, Math.min(72, (f.fs || 13) + delta)); });
    }
    $('sf-fs-menos').addEventListener('click', function () { cambiarTamano(-1); });
    $('sf-fs-mas').addEventListener('click', function () { cambiarTamano(1); });
    $('sf-fs').addEventListener('change', function () { var v = parseInt(this.value, 10); if (v >= 6 && v <= 72) cambiarTamano(0, v); });
    $('sf-fs').addEventListener('keydown', function (e) { e.stopPropagation(); if (e.key === 'Enter') { this.dispatchEvent(new Event('change')); } });
    $('sf-c-letra').addEventListener('input', function () { irAPestana('modificar'); var v = this.value; aplicar(function (f) { f.tc = v; }); });
    $('sf-c-fondo').addEventListener('input', function () { irAPestana('modificar'); var v = this.value; aplicar(function (f) { f.bg = v; }); });
    $('sf-sin-relleno').addEventListener('click', function () { irAPestana('modificar'); aplicar(function (f) { delete f.bg; }); });
    $('sf-limpiar-fmt').addEventListener('click', function () { irAPestana('modificar'); aplicar(function (f) { Object.keys(f).forEach(function (k) { if (k !== 'im') delete f[k]; }); }); });

    /** Borde de un lado de la celda (r,c); también el lado contiguo de la vecina para que no choquen al fundirse las líneas */
    function ponerBorde(r, c, lado, valor) {
        var op = { bt: 'bb', bb: 'bt', bl: 'br', br: 'bl' }[lado];
        var dr = lado === 'bt' ? -1 : lado === 'bb' ? 1 : 0, dc = lado === 'bl' ? -1 : lado === 'br' ? 1 : 0;
        var f = Object.assign({}, getSt(r, c) || {}); f[lado] = valor; setSt(r, c, f);
        var rr = r + dr, cc = c + dc;
        if (rr >= -1 && rr < S.rows.length && cc >= 0 && cc < S.cols.length) { var g = Object.assign({}, getSt(rr, cc) || {}); g[op] = valor; setSt(rr, cc, g); }
    }
    $('sf-b-aplicar').addEventListener('click', function () {
        irAPestana('modificar');
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

    // ================= Formato condicional =================
    function reglaToca(r, n) {
        return !(r.r1 < n.r0 || r.r0 > n.r1 || r.c1 < n.c0 || r.c0 > n.c1);
    }
    function abrirReglaCondicional() {
        var n = rangoNorm();
        n.r0 = Math.max(0, n.r0);
        if (n.r1 < n.r0) n.r1 = n.r0;
        if (typeof abrirModal !== 'function') { estado('La ventana de reglas aún no está disponible', true); return; }

        var cuerpo = document.createElement('div'); cuerpo.className = 'columna';
        function campo(texto) {
            var l = document.createElement('label'); l.className = 'campo'; l.textContent = texto;
            cuerpo.appendChild(l); return l;
        }
        var rango = document.createElement('p'); rango.className = 'text-sm text-muted';
        rango.textContent = 'Rango: ' + letra(n.c0) + (n.r0 + 2) + ':' + letra(n.c1) + (n.r1 + 2) + ' · las reglas se recalculan al cambiar los valores.';
        cuerpo.appendChild(rango);

        var lTipo = campo('Condición');
        var selTipo = document.createElement('select'); selTipo.className = 'control'; selTipo.id = 'sf-regla-tipo';
        [['mayor', 'Mayor que'], ['menor', 'Menor que'], ['entre', 'Entre dos valores'], ['contiene', 'Contiene el texto'],
            ['igual', 'Igual a'], ['vacio', 'Está vacía'], ['no_vacio', 'No está vacía'],
            ['escala', 'Escala de color (mín. a máx.)'], ['barras', 'Barras de datos']]
            .forEach(function (x) { selTipo.appendChild(opcion(x[0], x[1])); });
        lTipo.appendChild(selTipo);

        var lV1 = campo('Valor'); var v1 = document.createElement('input'); v1.type = 'text'; v1.className = 'control'; v1.id = 'sf-regla-v1'; lV1.appendChild(v1);
        var lV2 = campo('Segundo valor'); var v2 = document.createElement('input'); v2.type = 'text'; v2.className = 'control'; v2.id = 'sf-regla-v2'; lV2.appendChild(v2);

        var lColor = campo('Color'); var color = document.createElement('input'); color.type = 'color'; color.value = '#fee2e2'; color.className = 'control'; color.id = 'sf-regla-color'; lColor.appendChild(color);
        var lColor2 = campo('Color del máximo'); var color2 = document.createElement('input'); color2.type = 'color'; color2.value = '#bbf7d0'; color2.className = 'control'; color2.id = 'sf-regla-color2'; lColor2.appendChild(color2);
        var lTexto = campo('Color del texto'); var tc = document.createElement('input'); tc.type = 'color'; tc.value = '#111827'; tc.className = 'control'; tc.id = 'sf-regla-texto'; lTexto.appendChild(tc);
        var lBold = document.createElement('label'); lBold.className = 'sf-chk'; lBold.innerHTML = '<input type="checkbox" id="sf-regla-bold"> Negrita';
        cuerpo.appendChild(lBold);
        var chkBold = lBold.querySelector('input');

        // Reglas existentes
        var lista = document.createElement('div'); lista.className = 'columna';
        var titulo = document.createElement('p'); titulo.className = 'text-sm'; titulo.textContent = 'Reglas de esta tabla';
        lista.appendChild(titulo);
        if (!S.reglas.length) {
            var vacio = document.createElement('p'); vacio.className = 'text-sm text-muted'; vacio.textContent = 'Todavía no hay reglas.'; lista.appendChild(vacio);
        }
        S.reglas.forEach(function (r, i) {
            var fila = document.createElement('div'); fila.className = 'ed-fila';
            var txt = document.createElement('span'); txt.className = 'text-sm';
            txt.textContent = letra(r.c0) + (r.r0 + 2) + ':' + letra(r.c1) + (r.r1 + 2) + ' · ' + r.tipo + (r.v1 != null && r.v1 !== '' ? ' ' + r.v1 : '') + (r.v2 != null && r.v2 !== '' ? ' / ' + r.v2 : '');
            var quitar = document.createElement('button'); quitar.type = 'button'; quitar.className = 'btn btn--texto btn--sm'; quitar.textContent = 'Quitar';
            quitar.addEventListener('click', function () { antes(); S.reglas.splice(i, 1); dibujar(); cambio(); fila.remove(); });
            fila.appendChild(txt); fila.appendChild(quitar); lista.appendChild(fila);
        });
        cuerpo.appendChild(lista);

        function modo() {
            var t = selTipo.value;
            var pide = t === 'mayor' || t === 'menor' || t === 'entre' || t === 'contiene' || t === 'igual';
            lV1.hidden = !pide;
            lV2.hidden = !(t === 'entre');
            var escala = t === 'escala';
            lColor.hidden = t === 'vacio' || t === 'no_vacio';
            lColor2.hidden = !escala;
            lTexto.hidden = escala || t === 'barras' || t === 'vacio' || t === 'no_vacio';
            lBold.hidden = escala || t === 'barras' || t === 'vacio' || t === 'no_vacio';
            if (escala) color.value = '#fee2e2';
        }
        selTipo.addEventListener('change', modo);
        modo();

        abrirModal({
            sobreTitulo: 'SheetFlow',
            titulo: 'Formato condicional',
            cuerpo: cuerpo,
            textoConfirmar: 'Aplicar',
            alConfirmar: function () {
                var t = selTipo.value;
                var reg = { tipo: t, r0: n.r0, r1: n.r1, c0: n.c0, c1: n.c1, v1: v1.value, v2: v2.value, color: color.value };
                if (t === 'escala') { reg.color = color.value; reg.color2 = color2.value; }
                else if (t === 'barras') { reg.color = color.value; }
                else {
                    reg.fmt = { bg: color.value };
                    if (!lTexto.hidden) reg.fmt.tc = tc.value;
                    if (!lBold.hidden && chkBold.checked) reg.fmt.b = 1;
                }
                antes();
                S.reglas.push(reg);
                dibujar(); cambio();
                estado('Regla aplicada al rango ' + letra(n.c0) + (n.r0 + 2) + ':' + letra(n.c1) + (n.r1 + 2));
            }
        });
    }
    $('sf-regla').addEventListener('click', abrirReglaCondicional);
    $('sf-regla-quitar').addEventListener('click', function () {
        var n = rangoNorm(); n.r0 = Math.max(0, n.r0);
        if (n.r1 < n.r0) n.r1 = n.r0;
        var antesN = S.reglas.length;
        antes();
        S.reglas = S.reglas.filter(function (r) { return !reglaToca(r, n); });
        dibujar(); cambio();
        estado(antesN === S.reglas.length ? 'No había reglas en ese rango' : 'Reglas quitadas (' + (antesN - S.reglas.length) + ')');
    });

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
    function subirImagenCelda(f, extra, etiqueta) {
        var fd = new FormData(); fd.append('id', C.id); fd.append('proyecto', C.proyecto); fd.append('imagen', f, f.name || ((etiqueta || 'imagen') + '.png'));
        estado('Subiendo imagen…');
        fetch('/admin/sheetflow/api/imagen', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) { estado(d.error || 'No se pudo subir la imagen', true); return; }
            var ancla = { r: sel.r0, c: sel.c0 };
            antes();
            var g = Object.assign({}, getSt(ancla.r, ancla.c) || {});
            g.im = d.src;
            if (extra) Object.keys(extra).forEach(function (k) { g[k] = extra[k]; });
            setSt(ancla.r, ancla.c, g);
            dibujar(); cambio(); estado((etiqueta || 'Imagen') + ' puesta en ' + nombreCelda(ancla.r, ancla.c));
        }).catch(function () { estado('Sin conexión: no se pudo subir la imagen', true); });
    }

    $('sf-qr-poner').addEventListener('click', function () {
        var n = rangoNorm();
        if (n.r0 < 0) { estado('Elige una celda de datos (no el encabezado)', true); return; }
        if (typeof qrcode !== 'function') { estado('El generador de QR no está disponible; recarga la página', true); return; }
        var actual = S.rows[n.r0][n.c0] || '';
        if (typeof pedirTexto !== 'function') { estado('La ventana de texto aún no está disponible; recarga la página', true); return; }
        pedirTexto({ titulo: 'Código QR', etiqueta: 'Texto o dirección', mensaje: 'El QR se guarda como imagen de la celda; si cambias el texto, vuelve a generarlo.', predeterminado: actual, placeholder: 'https://… o ACERO-123', maximo: 300, textoConfirmar: 'Generar' })
            .then(function (texto) {
                if (!texto) return;
                try {
                    var qr = qrcode(0, 'M');
                    qr.addData(texto);
                    qr.make();
                    subirImagenCelda(dataURLtoBlob(qr.createDataURL(4, 8)), { qr: texto }, 'QR');
                } catch (e) {
                    estado('No se pudo generar el QR: ' + (e.message || 'texto no válido'), true);
                }
            });
    });

    $('sf-barcode-poner').addEventListener('click', function () {
        var n = rangoNorm();
        if (n.r0 < 0) { estado('Elige una celda de datos (no el encabezado)', true); return; }
        if (typeof JsBarcode !== 'function') { estado('El generador de códigos de barras no está disponible; recarga la página', true); return; }
        var actual = S.rows[n.r0][n.c0] || '';
        if (typeof pedirTexto !== 'function') { estado('La ventana de texto aún no está disponible; recarga la página', true); return; }
        pedirTexto({ titulo: 'Código de barras', etiqueta: 'Texto (CODE128)', predeterminado: actual, placeholder: 'ACERO-123', maximo: 300, textoConfirmar: 'Generar' })
            .then(function (texto) {
                if (!texto) return;
                var cv = document.createElement('canvas');
                try { JsBarcode(cv, texto, { format: 'CODE128', width: 2, height: 70, margin: 8, displayValue: false }); }
                catch (e) { estado('No se pudo generar el código de barras: ' + (e.message || 'texto no válido'), true); return; }
                cv.toBlob(function (b) {
                    if (b) subirImagenCelda(b, { barcode: texto }, 'Código de barras');
                    else estado('No se pudo preparar el código de barras', true);
                }, 'image/png');
            });
    });
    $('sf-img-quitar').addEventListener('click', function () { aplicar(function (f) { delete f.im; }); });

    // ================= Enlaces, barras y notas en la celda =================
    $('sf-lk-poner').addEventListener('click', function () {
        var n = rangoNorm();
        if (n.r0 < 0) { estado('Elige una celda de datos (no el encabezado)', true); return; }
        if (typeof pedirTexto !== 'function') { estado('La ventana de texto aún no está disponible; recarga la página', true); return; }
        var actual = (getSt(n.r0, n.c0) || {}).lk || '';
        pedirTexto({ titulo: 'Enlace de la celda', etiqueta: 'Dirección', mensaje: 'Puede ser https://…, mailto:, tel: o una ruta interna que empiece con /.', predeterminado: actual, placeholder: 'https://…', maximo: 500, textoConfirmar: 'Poner enlace' })
            .then(function (url) {
                if (!url) return;
                var u = urlSegura(url);
                if (!u) { estado('La dirección no es válida', true); return; }
                aplicar(function (f) { f.lk = u; });
                estado('Enlace puesto en ' + nombreCelda(n.r0, n.c0) + '; Ctrl+clic para abrirlo');
            });
    });
    $('sf-lk-quitar').addEventListener('click', function () { aplicar(function (f) { delete f.lk; }); });
    $('sf-bar-poner').addEventListener('click', function () {
        aplicar(function (f) { f.bar = 1; });
        estado('Barra de progreso: usa un valor de 0 a 100 en la celda');
    });
    $('sf-bar-quitar').addEventListener('click', function () { aplicar(function (f) { delete f.bar; }); });
    $('sf-spark-poner').addEventListener('click', function () {
        var n = rangoNorm();
        if (n.r0 < 0) { estado('Elige una celda de datos (no el encabezado)', true); return; }
        if (typeof abrirModal !== 'function') { estado('La ventana aún no está disponible', true); return; }
        var cuerpo = document.createElement('div'); cuerpo.className = 'columna';
        function campo(texto) { var l = document.createElement('label'); l.className = 'campo'; l.textContent = texto; cuerpo.appendChild(l); return l; }
        var lRango = campo('Rango o valores'); var rango = document.createElement('input'); rango.type = 'text'; rango.className = 'control'; rango.id = 'sf-spark-rango';
        rango.placeholder = 'B2:B10 o 3,5,2,8'; rango.value = letra(n.c0) + '2:' + letra(n.c0) + (S.rows.length + 1); lRango.appendChild(rango);
        var lTipo = campo('Tipo'); var tipo = document.createElement('select'); tipo.className = 'control'; tipo.id = 'sf-spark-tipo';
        tipo.appendChild(opcion('linea', 'Línea')); tipo.appendChild(opcion('barras', 'Barras')); lTipo.appendChild(tipo);
        var lColor = campo('Color'); var color = document.createElement('input'); color.type = 'color'; color.value = '#2563eb'; color.className = 'control'; color.id = 'sf-spark-color'; lColor.appendChild(color);
        var nota = document.createElement('p'); nota.className = 'text-sm text-muted';
        nota.textContent = 'Escribe un rango A1 (B2:B10), una lista separada por comas o deja el rango de esta columna.';
        cuerpo.appendChild(nota);
        abrirModal({
            sobreTitulo: 'SheetFlow', titulo: 'Minigráfica en la celda ' + nombreCelda(n.r0, n.c0), cuerpo: cuerpo, textoConfirmar: 'Poner',
            alConfirmar: function () {
                var expr = rango.value.trim();
                if (!expr) { estado('Escribe un rango o valores', true); return; }
                antes();
                var g = Object.assign({}, getSt(n.r0, n.c0) || {});
                g.spark = expr; g.sparkTipo = tipo.value; g.sparkColor = color.value;
                setSt(n.r0, n.c0, g);
                dibujar(); cambio();
                estado('Minigráfica puesta en ' + nombreCelda(n.r0, n.c0));
            }
        });
    });
    $('sf-spark-quitar').addEventListener('click', function () { aplicar(function (f) { delete f.spark; delete f.sparkTipo; delete f.sparkColor; }); });
    $('sf-grafica-poner').addEventListener('click', function () {
        if (typeof abrirModal !== 'function') { estado('La ventana aún no está disponible', true); return; }
        var n = rangoNorm();
        var cuerpo = document.createElement('div'); cuerpo.className = 'columna';
        function campo(texto) { var l = document.createElement('label'); l.className = 'campo'; l.textContent = texto; cuerpo.appendChild(l); return l; }
        var lTitulo = campo('Título'); var titulo = document.createElement('input'); titulo.type = 'text'; titulo.className = 'control'; titulo.id = 'sf-grafica-titulo'; titulo.value = 'Gráfica'; titulo.maxLength = 80; lTitulo.appendChild(titulo);
        var lRango = campo('Rango de datos'); var rango = document.createElement('input'); rango.type = 'text'; rango.className = 'control'; rango.id = 'sf-grafica-rango';
        rango.placeholder = 'B2:B10'; rango.value = n.r0 >= 0 ? letra(n.c0) + (n.r0 + 2) + ':' + letra(n.c1) + (n.r1 + 2) : 'B2:B10'; lRango.appendChild(rango);
        var lTipo = campo('Tipo'); var tipo = document.createElement('select'); tipo.className = 'control'; tipo.id = 'sf-grafica-tipo';
        tipo.appendChild(opcion('barras', 'Barras')); tipo.appendChild(opcion('linea', 'Líneas')); tipo.appendChild(opcion('pastel', 'Pastel')); lTipo.appendChild(tipo);
        var nota = document.createElement('p'); nota.className = 'text-sm text-muted';
        nota.textContent = 'La gráfica se guarda con la tabla y se actualiza al cambiar los valores del rango.';
        cuerpo.appendChild(nota);
        abrirModal({
            sobreTitulo: 'SheetFlow', titulo: 'Nueva gráfica', cuerpo: cuerpo, textoConfirmar: 'Crear',
            alConfirmar: function () {
                var expr = rango.value.trim();
                if (!/^[A-Za-z]{1,2}\d+(?::[A-Za-z]{1,2}\d+)?$/.test(expr)) { estado('Escribe un rango como B2:B10', true); return; }
                antes();
                S.graficas.push({ titulo: (titulo.value || 'Gráfica').slice(0, 80), rango: expr, tipo: tipo.value });
                dibujar(); cambio();
                estado('Gráfica creada');
                var cont = $('sf-graficas'); if (cont) cont.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    });
    $('sf-nota-poner').addEventListener('click', function () {
        var n = rangoNorm();
        if (n.r0 < 0) { estado('Elige una celda de datos (no el encabezado)', true); return; }
        if (typeof pedirTexto !== 'function') { estado('La ventana de texto aún no está disponible; recarga la página', true); return; }
        var actual = (getSt(n.r0, n.c0) || {}).nota || '';
        pedirTexto({ titulo: 'Nota de la celda ' + nombreCelda(n.r0, n.c0), etiqueta: 'Nota', mensaje: 'Déjala vacía para quitar la nota.', predeterminado: actual, maximo: 300, textoConfirmar: 'Guardar nota' })
            .then(function (nota) {
                nota = (nota || '').trim().slice(0, 300);
                aplicar(function (f) { if (nota) f.nota = nota; else delete f.nota; });
                estado(nota ? 'Nota guardada en ' + nombreCelda(n.r0, n.c0) : 'Nota quitada');
            });
    });

    // Ctrl+clic abre los enlaces de las celdas (un clic normal solo selecciona).
    grid.addEventListener('click', function (e) {
        var a = e.target.closest('.sf-enlace');
        if (!a) return;
        if (e.ctrlKey || e.metaKey) { e.preventDefault(); window.open(a.getAttribute('href'), '_blank', 'noopener'); }
        else e.preventDefault();
    });

    // ================= Filtro de renglones =================
    $('sf-filtro').addEventListener('input', function () {
        filtro.texto = this.value.trim();
        clearTimeout(temporizadorFiltro);
        temporizadorFiltro = setTimeout(dibujar, 120);
    });
    $('sf-filtro-col').addEventListener('change', function () { filtro.columna = this.checked; dibujar(); });
    $('sf-filtro-limpiar').addEventListener('click', function () {
        filtro.texto = ''; filtro.columna = false;
        $('sf-filtro').value = ''; $('sf-filtro-col').checked = false;
        dibujar();
    });

    // ================= Frescura de los datos externos =================
    var LS_EXT = 'sf-ext-' + C.id;
    function pintarFrescura() {
        var el = $('sf-ext-estado');
        if (!el) return;
        var t = 0;
        try { t = parseInt(localStorage.getItem(LS_EXT) || '0', 10) || 0; } catch (e) { t = 0; }
        if (!t) { el.textContent = Object.keys(S.refs).length ? 'vinculados' : ''; return; }
        var min = Math.max(0, Math.floor((Date.now() - t) / 60000));
        el.textContent = min < 1 ? 'actualizado ahora' : min < 60 ? 'hace ' + min + ' min' : 'hace ' + Math.floor(min / 60) + ' h';
    }
    function marcarFrescura() {
        try { localStorage.setItem(LS_EXT, String(Date.now())); } catch (e) { /* sin almacenamiento */ }
        pintarFrescura();
    }
    pintarFrescura();

    // ================= Enlace público =================
    function modalCompartir(token, url) {
        var cuerpo = document.createElement('div'); cuerpo.className = 'columna';
        var nota = document.createElement('p'); nota.className = 'text-sm text-muted';
        nota.textContent = token
            ? 'Cualquiera con este enlace puede ver la tabla, pero no editarla. Si la desactivas, el enlace deja de funcionar.'
            : 'Crea un enlace para compartir una copia de solo lectura de esta tabla.';
        cuerpo.appendChild(nota);
        if (token) {
            var campo = document.createElement('label'); campo.className = 'campo'; campo.textContent = 'Enlace';
            var inp = document.createElement('input'); inp.type = 'text'; inp.className = 'control'; inp.readOnly = true; inp.value = url; inp.id = 'sf-enlace-publico';
            campo.appendChild(inp); cuerpo.appendChild(campo);
            var acciones = document.createElement('div'); acciones.className = 'ed-fila';
            var copiar = document.createElement('button'); copiar.type = 'button'; copiar.className = 'btn btn--secundario btn--sm'; copiar.textContent = 'Copiar enlace';
            copiar.addEventListener('click', function () {
                inp.select();
                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url);
                    else document.execCommand('copy');
                    estado('Enlace copiado');
                } catch (e) { estado('Selecciona y copia el enlace', true); }
            });
            var quitar = document.createElement('button'); quitar.type = 'button'; quitar.className = 'btn btn--texto btn--sm'; quitar.textContent = 'Desactivar enlace';
            quitar.addEventListener('click', function () {
                confirmarModal({ titulo: 'Desactivar enlace', mensaje: 'El enlace público dejará de mostrar la tabla.', aceptar: 'Desactivar' }).then(function (ok) {
                    if (!ok) return;
                    var fd = new FormData(); fd.append('id', C.id); fd.append('accion', 'quitar');
                    fetch(C.api.compartir, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
                        if (!d.success) { estado(d.error || 'No se pudo desactivar', true); return; }
                        modalCompartir('', ''); estado('Enlace desactivado');
                    });
                });
            });
            acciones.appendChild(copiar); acciones.appendChild(quitar); cuerpo.appendChild(acciones);
        } else {
            var crear = document.createElement('button'); crear.type = 'button'; crear.className = 'btn btn--primario';
            crear.textContent = 'Crear enlace público';
            crear.addEventListener('click', function () {
                var fd = new FormData(); fd.append('id', C.id); fd.append('accion', 'crear');
                fetch(C.api.compartir, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
                    if (!d.success) { estado(d.error || 'No se pudo crear el enlace', true); return; }
                    modalCompartir(d.token, d.url); estado('Enlace creado');
                });
            });
            cuerpo.appendChild(crear);
        }
        abrirModal({ sobreTitulo: 'SheetFlow', titulo: 'Compartir tabla', cuerpo: cuerpo });
    }
    $('sf-compartir').addEventListener('click', function () {
        if (typeof abrirModal !== 'function') { estado('La ventana aún no está disponible', true); return; }
        var fd = new FormData(); fd.append('id', C.id); fd.append('accion', 'estado');
        estado('Consultando el enlace…');
        fetch(C.api.compartir, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) throw new Error(d.error || 'No se pudo consultar el enlace');
            estado('');
            modalCompartir(d.token || '', d.url || '');
        }).catch(function (e) { estado(e.message || 'No se pudo consultar el enlace', true); });
    });

    // ================= Historial de versiones =================
    function fechaHora(s) {
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? String(s) : d.toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
    }
    $('sf-versiones').addEventListener('click', function () {
        if (typeof abrirModal !== 'function') { estado('La ventana aún no está disponible', true); return; }
        var fd = new FormData(); fd.append('id', C.id); fd.append('accion', 'listar');
        estado('Cargando versiones…');
        fetch(C.api.versiones, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) throw new Error(d.error || 'No se pudieron leer las versiones');
                estado('');
                var cuerpo = document.createElement('div'); cuerpo.className = 'columna';
                var guardar = document.createElement('button');
                guardar.type = 'button'; guardar.className = 'btn btn--texto btn--sm';
                guardar.innerHTML = '<span class="material-symbols-outlined">add</span> Guardar una versión ahora';
                guardar.addEventListener('click', function () {
                    var f2 = new FormData(); f2.append('id', C.id); f2.append('accion', 'crear');
                    fetch(C.api.versiones, { method: 'POST', body: f2 }).then(function (r) { return r.json(); }).then(function (x) {
                        estado(x.success ? 'Versión guardada' : (x.error || 'No se pudo guardar'), !x.success);
                    });
                });
                cuerpo.appendChild(guardar);
                if (!d.versiones.length) {
                    var vacio = document.createElement('p'); vacio.className = 'text-sm text-muted';
                    vacio.textContent = 'Todavía no hay versiones. Se guardan automáticamente al guardar cambios.';
                    cuerpo.appendChild(vacio);
                }
                d.versiones.forEach(function (v) {
                    var fila = document.createElement('div'); fila.className = 'sf-version';
                    var txt = document.createElement('span');
                    txt.innerHTML = '<strong>' + esc(v.nombre || 'Sin nombre') + '</strong><br><span class="text-sm text-muted">' + esc(fechaHora(v.fecha)) + ' · ' + Math.round(v.bytes / 1024) + ' KB</span>';
                    var b = document.createElement('button');
                    b.type = 'button'; b.className = 'btn btn--texto btn--sm'; b.textContent = 'Restaurar';
                    b.addEventListener('click', function () {
                        confirmarModal({
                            titulo: 'Restaurar versión',
                            mensaje: 'La tabla volverá al estado del ' + fechaHora(v.fecha) + '. La versión actual se guarda antes de reemplazarla.',
                            aceptar: 'Restaurar'
                        }).then(function (ok) {
                            if (!ok) return;
                            var f3 = new FormData(); f3.append('id', C.id); f3.append('accion', 'restaurar'); f3.append('version', String(v.id));
                            fetch(C.api.versiones, { method: 'POST', body: f3 }).then(function (r) { return r.json(); }).then(function (x) {
                                if (!x.success) { estado(x.error || 'No se pudo restaurar', true); return; }
                                location.reload();
                            }).catch(function () { estado('Sin conexión: no se pudo restaurar', true); });
                        });
                    });
                    fila.appendChild(txt); fila.appendChild(b);
                    cuerpo.appendChild(fila);
                });
                abrirModal({ sobreTitulo: 'SheetFlow', titulo: 'Versiones de la tabla', cuerpo: cuerpo });
            })
            .catch(function (e) { estado(e.message || 'No se pudieron leer las versiones', true); });
    });


    dibujar();
    area.focus();
})();
