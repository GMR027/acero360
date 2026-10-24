/* ==========================================================================
   editor.js — Editor de documentos con Konva (Acero 360).

   · Cada lámina es un documento Konva; su JSON es la fuente editable.
   · Las capas son grupos lógicos (Konva.Group) dentro de la capa de contenido.
   · Las guías se guardan como atributo del documento y no se exportan.
   · El PDF y el PNG se generan desde el JSON (nunca al revés).
   ========================================================================== */
(function () {
    'use strict';

    var C = window.ED_CONFIG;
    if (!C || typeof Konva === 'undefined') return;

    var $ = function (id) { return document.getElementById(id); };
    var nombre = $('ed-nombre');
    var estadoTxt = $('ed-estado');
    var pagina = $('editor');
    var lienzo = $('ed-lienzo');
    var scroll = $('ed-scroll');
    var marco = $('ed-marco');
    var cont = $('ed-stage');
    var archivoImg = $('ed-archivo-img');

    var paginas = (C.laminas || []).map(function (l) {
        return {
            id: l.id || 0,
            nombre: l.nombre || '',
            json: l.json || '',
            thumb: l.png ? '/public/uploads/vplan/' + l.png : null
        };
    });
    if (!paginas.length) paginas.push({ id: 0, nombre: 'Lámina 1', json: '', thumb: null });

    var actual = 0;
    var zoom = 1;
    var ajusteAuto = true;
    var herramienta = 'select';
    var selNodos = [];
    var capaActivaId = null;
    var guias = [];
    var version = C.version;
    var sucio = false;
    var guardando = false;
    var historial = [];
    var futuro = [];
    var temporizador = null;
    var editando = null;
    var posNuevaImagen = null;
    var accionEnCurso = false;
    var moverInicio = null;
    var portapapeles = [];
    var contadorGrupo = 0;
    var contadorGuia = 0;
    var paneo = null;
    var ctxBloqueado = false, ctxTimer = null;

    var GRID_PASO = 20;
    var CUADRICULA = true;
    var SNAP = true;

    var FONDO = {
        color: C.fondo_color || '#ffffff',
        tipo: C.fondo || 'liso',
        paso: (C.fondo_config && C.fondo_config.paso) || 40,
        linea: (C.fondo_config && C.fondo_config.color) || '#e2e8f0',
        imagen: C.fondo_imagen || null
    };

    var UNIDADES = { px: 1, mm: 25.4 / 96, cm: 2.54 / 96, m: 0.0254 / 96, in: 1 / 96 };
    var NOMBRE_UNIDAD = { px: 'px', mm: 'mm', cm: 'cm', m: 'm', in: 'in' };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(v, def, min, max) {
        var n = parseFloat(v);
        if (!isFinite(n)) n = def;
        if (min !== undefined) n = Math.max(min, n);
        if (max !== undefined) n = Math.min(max, n);
        return n;
    }
    function suma(a) { return a.reduce(function (s, v) { return s + v; }, 0); }
    function colorHex(c) {
        if (!c || typeof c !== 'string') return '#000000';
        if (/^#[0-9a-f]{6}$/i.test(c)) return c;
        if (/^#[0-9a-f]{3}$/i.test(c)) return '#' + c[1] + c[1] + c[2] + c[2] + c[3] + c[3];
        return '#000000';
    }
    function visible(el, v) { if (el) el.hidden = !v; }

    // ------------------------------------------------------------------
    // Escenario
    // ------------------------------------------------------------------
    var stage = new Konva.Stage({ container: 'ed-stage', width: C.ancho, height: C.alto });
    var capaFondo = new Konva.Layer({ listening: false });
    var capaContenido = new Konva.Layer();
    var capaUI = new Konva.Layer();
    stage.add(capaFondo, capaContenido, capaUI);

    var tr = new Konva.Transformer({
        rotateEnabled: true,
        borderStroke: '#4679a6',
        borderDash: [5, 3],
        anchorStroke: '#4679a6',
        anchorFill: '#ffffff',
        anchorSize: 9,
        anchorCornerRadius: 2,
        padding: 3,
        ignoreStroke: false,
        shouldOverdrawWholeArea: false
    });
    capaUI.add(tr);

    var marquesaNodo = new Konva.Rect({
        fill: 'rgba(70, 121, 166, .12)',
        stroke: '#4679a6',
        strokeWidth: 1,
        dash: [5, 3],
        visible: false,
        listening: false
    });
    capaUI.add(marquesaNodo);

    // ------------------------------------------------------------------
    // Imágenes y fondo
    // ------------------------------------------------------------------
    var cacheImg = {};
    function cargarImagen(src) {
        return new Promise(function (res) {
            if (!src) return res(null);
            if (cacheImg[src]) return res(cacheImg[src]);
            var im = new Image();
            im.onload = function () { cacheImg[src] = im; res(im); };
            im.onerror = function () { res(null); };
            im.src = src;
        });
    }

    function crearFondo(layer, conCuadricula) {
        layer.destroyChildren();
        layer.add(new Konva.Rect({ x: 0, y: 0, width: C.ancho, height: C.alto, fill: FONDO.color, listening: false }));

        if (FONDO.tipo === 'cuadricula') {
            for (var x = FONDO.paso; x < C.ancho; x += FONDO.paso) {
                layer.add(new Konva.Line({ points: [x, 0, x, C.alto], stroke: FONDO.linea, strokeWidth: 1, listening: false }));
            }
            for (var y = FONDO.paso; y < C.alto; y += FONDO.paso) {
                layer.add(new Konva.Line({ points: [0, y, C.ancho, y], stroke: FONDO.linea, strokeWidth: 1, listening: false }));
            }
        } else if (FONDO.tipo === 'puntos') {
            for (var px = FONDO.paso; px < C.ancho; px += FONDO.paso) {
                for (var py = FONDO.paso; py < C.alto; py += FONDO.paso) {
                    layer.add(new Konva.Circle({ x: px, y: py, radius: 1.2, fill: FONDO.linea, listening: false }));
                }
            }
        }

        if (conCuadricula !== false && CUADRICULA) {
            for (var gx = GRID_PASO; gx < C.ancho; gx += GRID_PASO) {
                layer.add(new Konva.Line({ points: [gx, 0, gx, C.alto], stroke: 'rgba(70, 121, 166, .10)', strokeWidth: 1, listening: false }));
            }
            for (var gy = GRID_PASO; gy < C.alto; gy += GRID_PASO) {
                layer.add(new Konva.Line({ points: [0, gy, C.ancho, gy], stroke: 'rgba(70, 121, 166, .10)', strokeWidth: 1, listening: false }));
            }
        }

        if (FONDO.tipo === 'imagen' && FONDO.imagen) {
            return cargarImagen(FONDO.imagen).then(function (im) {
                if (!im) return;
                var esc2 = Math.max(C.ancho / im.width, C.alto / im.height);
                var w = im.width * esc2, h = im.height * esc2;
                layer.add(new Konva.Image({
                    image: im, x: (C.ancho - w) / 2, y: (C.alto - h) / 2,
                    width: w, height: h, listening: false, opacity: 0.9
                }));
            });
        }
        return Promise.resolve();
    }

    function redibujarFondo() {
        return crearFondo(capaFondo, true).then(function () { capaFondo.batchDraw(); });
    }

    // ------------------------------------------------------------------
    // Capas lógicas
    // ------------------------------------------------------------------
    function capas() {
        return capaContenido.getChildren().filter(function (n) { return n.getAttr('esCapa'); });
    }

    function nuevaCapa(nombreCapa, id) {
        var capa = new Konva.Group({
            x: 0,
            y: 0,
            esCapa: true,
            capaId: id || ('capa-' + Date.now() + '-' + Math.round(Math.random() * 9999)),
            nombre: nombreCapa || ('Capa ' + (capas().length + 1)),
            locked: false
        });
        capaContenido.add(capa);
        return capa;
    }

    function capaActivaGrupo() {
        var cs = capas();
        if (!cs.length) return null;
        for (var i = 0; i < cs.length; i++) {
            if (cs[i].getAttr('capaId') === capaActivaId) return cs[i];
        }
        capaActivaId = cs[cs.length - 1].getAttr('capaId');
        return cs[cs.length - 1];
    }

    function capaDe(n) {
        var p = n ? n.getParent() : null;
        while (p && !p.getAttr('esCapa')) p = p.getParent();
        return p && p.getAttr('esCapa') ? p : null;
    }

    function capaPorId(id) {
        var cs = capas();
        for (var i = 0; i < cs.length; i++) if (cs[i].getAttr('capaId') === id) return cs[i];
        return null;
    }

    function objetos(incluirBloqueadas) {
        var out = [];
        capas().forEach(function (c) {
            if (!c.visible()) return;
            if (!incluirBloqueadas && c.getAttr('locked')) return;
            out = out.concat(c.getChildren());
        });
        return out;
    }

    function capaPermitida() {
        var c = capaActivaGrupo();
        if (!c) { estado('No hay capas'); return null; }
        if (c.getAttr('locked')) { estado('La capa activa está bloqueada', true); return null; }
        if (!c.visible()) { estado('La capa activa está oculta', true); return null; }
        return c;
    }

    function agregarObjeto(n) {
        var c = capaPermitida();
        if (!c) return false;
        c.add(n);
        conectar(n);
        return true;
    }

    function pintarCapas() {
        var lista = $('ed-capas-lista');
        if (!lista) return;
        lista.innerHTML = '';
        capas().slice().reverse().forEach(function (c) {
            var id = c.getAttr('capaId');
            var li = document.createElement('li');
            li.className = 'ed-capa-item' + (id === capaActivaId ? ' activa' : '') +
                (c.visible() ? '' : ' oculta') + (c.getAttr('locked') ? ' bloqueada' : '');
            li.dataset.id = id;
            li.innerHTML =
                '<button type="button" class="ed-capa-nombre" data-op="activar" title="Hacer capa activa. Doble clic para renombrar">' +
                '<span class="material-symbols-outlined">' + (c.visible() ? 'layers' : 'visibility_off') + '</span>' +
                '<span class="ed-capa-nombre__txt">' + esc(c.getAttr('nombre')) + '</span></button>' +
                '<span class="ed-capa-n">' + c.getChildren().length + '</span>' +
                '<span class="ed-capa-ops">' +
                '<button type="button" data-op="ver" title="' + (c.visible() ? 'Ocultar capa' : 'Mostrar capa') + '"><span class="material-symbols-outlined">' + (c.visible() ? 'visibility' : 'visibility_off') + '</span></button>' +
                '<button type="button" data-op="bloquear" title="' + (c.getAttr('locked') ? 'Desbloquear capa' : 'Bloquear capa') + '"><span class="material-symbols-outlined">' + (c.getAttr('locked') ? 'lock' : 'lock_open') + '</span></button>' +
                '<button type="button" data-op="subir" title="Subir capa"><span class="material-symbols-outlined">arrow_upward</span></button>' +
                '<button type="button" data-op="bajar" title="Bajar capa"><span class="material-symbols-outlined">arrow_downward</span></button>' +
                '<button type="button" data-op="borrar" title="Eliminar capa"><span class="material-symbols-outlined">delete</span></button>' +
                '</span>';
            lista.appendChild(li);
        });
    }

    function fijarCapaActiva(id) {
        var c = capaPorId(id);
        if (!c) return;
        capaActivaId = id;
        // No reconstruimos la lista: así el doble clic para renombrar no se
        // pierde porque el botón desaparece entre los dos clics.
        document.querySelectorAll('#ed-capas-lista .ed-capa-item').forEach(function (li) {
            li.classList.toggle('activa', li.dataset.id === id);
        });
        actualizarProps();
    }

    function accionCapa(id, op) {
        var c = capaPorId(id);
        if (!c) return;
        if (op === 'activar') {
            fijarCapaActiva(id);
            return;
        }
        if (op === 'ver') {
            antes();
            c.visible(!c.visible());
            c.listening(c.visible() && !c.getAttr('locked'));
            if (!c.visible()) limpiarSeleccionDe(c);
            pintarCapas();
            capaContenido.batchDraw();
            marcar();
            return;
        }
        if (op === 'bloquear') {
            antes();
            c.setAttr('locked', !c.getAttr('locked'));
            c.listening(c.visible() && !c.getAttr('locked'));
            if (c.getAttr('locked')) limpiarSeleccionDe(c);
            pintarCapas();
            marcar();
            return;
        }
        if (op === 'subir') {
            antes();
            c.moveUp();
            pintarCapas();
            marcar();
            return;
        }
        if (op === 'bajar') {
            antes();
            c.moveDown();
            pintarCapas();
            marcar();
            return;
        }
        if (op === 'borrar') {
            if (capas().length <= 1) { estado('Debe quedar al menos una capa', true); return; }
            confirmarAccion({
                sobreTitulo: 'Capas',
                titulo: 'Eliminar la capa «' + c.getAttr('nombre') + '»',
                mensaje: 'Se borrará la capa y todo su contenido.',
                textoConfirmar: 'Eliminar capa',
                alConfirmar: function () {
                    antes();
                    var eraActiva = c.getAttr('capaId') === capaActivaId;
                    c.destroy();
                    if (eraActiva) {
                        var cs = capas();
                        capaActivaId = cs[cs.length - 1].getAttr('capaId');
                    }
                    tr.nodes([]);
                    selNodos = [];
                    pintarCapas();
                    capaContenido.batchDraw();
                    marcar();
                }
            });
            return;
        }
    }

    function limpiarSeleccionDe(capa) {
        selNodos = selNodos.filter(function (n) { return capaDe(n) !== capa; });
        tr.nodes(selNodos);
        capaUI.batchDraw();
        actualizarProps();
    }

    // ------------------------------------------------------------------
    // Nodos y serialización
    // ------------------------------------------------------------------
    function hidratar(raiz) {
        var imgs = raiz.find('Image');
        return Promise.all(imgs.map(function (n) {
            return cargarImagen(n.getAttr('src')).then(function (im) { if (im) n.image(im); });
        }));
    }

    function caja(n) {
        return n.getClientRect({ relativeTo: capaContenido });
    }

    function grupoDe(n) {
        var g = n.getAttr('grupo');
        if (!g) return [n];
        return todosObjetos().filter(function (o) { return o.getAttr('grupo') === g; });
    }

    function todosObjetos() {
        var out = [];
        capas().forEach(function (c) { out = out.concat(c.getChildren()); });
        return out;
    }

    function conectar(n) {
        n.draggable(herramienta === 'select' || herramienta === 'multi');

        n.on('mousedown touchstart', function (e) {
            if (herramienta !== 'select' && herramienta !== 'multi') return;
            if (!capaDe(n) || capaDe(n).getAttr('locked')) return;
            e.cancelBubble = true;
            var sumaSel = e.evt && (e.evt.shiftKey || e.evt.ctrlKey || e.evt.metaKey);
            if (sumaSel) {
                var grupo = grupoDe(n);
                var set = selNodos.slice();
                var todos = grupo.every(function (x) { return set.indexOf(x) >= 0; });
                grupo.forEach(function (x) {
                    var i = set.indexOf(x);
                    if (todos && i >= 0) set.splice(i, 1);
                    if (!todos && i < 0) set.push(x);
                });
                seleccionar(set, false);
            } else if (selNodos.indexOf(n) < 0) {
                seleccionar([n], true);
            }
        });

        n.on('dragstart', function () {
            antesAccion();
            moverInicio = selNodos.map(function (x) { return { n: x, x: x.x(), y: x.y() }; });
        });
        n.on('dragmove', function () {
            if (!moverInicio || selNodos.length < 2 || selNodos.indexOf(n) < 0) return;
            var base = null;
            moverInicio.forEach(function (p) { if (p.n === n) base = p; });
            if (!base) return;
            var dx = n.x() - base.x, dy = n.y() - base.y;
            moverInicio.forEach(function (p) {
                if (p.n !== n) { p.n.x(p.x + dx); p.n.y(p.y + dy); }
            });
            actualizarProps();
        });
        n.on('dragend', function () {
            moverInicio = null;
            capaContenido.batchDraw();
            actualizarProps();
            finAccion();
            marcar();
        });

        n.on('transformstart', function () { antesAccion(); });
        n.on('transformend', function () {
            normalizarEscala(n);
            capaContenido.batchDraw();
            actualizarProps();
            finAccion();
            marcar();
        });

        n.dragBoundFunc(function (pos) {
            return ajustarPosNodo(n, pos);
        });

        if (n.getClassName() === 'Text') {
            n.on('dblclick dbltap', function (e) {
                e.cancelBubble = true;
                editarTexto(n);
            });
        }
        if (n.getAttr('esTabla')) {
            n.on('dblclick dbltap', function (e) {
                e.cancelBubble = true;
                editarCeldaDesdeEvento(n, e);
            });
        }
        if (n.getAttr('esPin')) {
            n.on('dblclick dbltap', function (e) {
                e.cancelBubble = true;
                editarNumero(n);
            });
        }
    }

    function ajustarPosNodo(n, pos) {
        var k = stage.scaleX() || 1;
        var mundo = { x: pos.x / k, y: pos.y / k };
        if (SNAP) {
            mundo.x = Math.round(mundo.x / GRID_PASO) * GRID_PASO;
            mundo.y = Math.round(mundo.y / GRID_PASO) * GRID_PASO;
        }
        if (SNAP && guiasOn() && guias.length) {
            var b = caja(n);
            var tol = 7 / k;
            var mejorX = null, distX = tol, mejorY = null, distY = tol;
            guias.forEach(function (g) {
                if (g.eje === 'v') {
                    [mundo.x, mundo.x + b.width / 2, mundo.x + b.width].forEach(function (cand, i) {
                        var d = Math.abs(cand - g.pos);
                        if (d < distX) { distX = d; mejorX = g.pos - (i === 1 ? b.width / 2 : i === 2 ? b.width : 0); }
                    });
                } else {
                    [mundo.y, mundo.y + b.height / 2, mundo.y + b.height].forEach(function (cand, i) {
                        var d = Math.abs(cand - g.pos);
                        if (d < distY) { distY = d; mejorY = g.pos - (i === 1 ? b.height / 2 : i === 2 ? b.height : 0); }
                    });
                }
            });
            if (mejorX !== null) mundo.x = mejorX;
            if (mejorY !== null) mundo.y = mejorY;
        }
        return { x: mundo.x * k, y: mundo.y * k };
    }

    function normalizarEscala(n) {
        var sx = n.scaleX(), sy = n.scaleY();
        if (sx === 1 && sy === 1) return;
        var clase = n.getClassName();

        if (n.getAttr('esTabla')) {
            var cw = (n.getAttr('colW') || []).map(function (v) { return Math.max(12, v * sx); });
            var rh = (n.getAttr('rowH') || []).map(function (v) { return Math.max(10, v * sy); });
            n.setAttr('colW', cw);
            n.setAttr('rowH', rh);
            n.setAttr('fontSize', Math.max(7, (n.getAttr('fontSize') || 14) * Math.sqrt(Math.max(0.05, sx * sy))));
            n.scale({ x: 1, y: 1 });
            dibujarTabla(n);
        } else if (n.getAttr('esPin')) {
            n.setAttr('radio', Math.max(8, (n.getAttr('radio') || 18) * Math.max(sx, sy)));
            n.scale({ x: 1, y: 1 });
            dibujarPin(n);
        } else if (n.getAttr('esMedida')) {
            n.setAttr('puntos', (n.getAttr('puntos') || [0, 0, 0, 0]).map(function (v, i) { return v * (i % 2 === 0 ? sx : sy); }));
            n.scale({ x: 1, y: 1 });
            dibujarMedida(n);
        } else if (n.getAttr('esForma')) {
            n.points(n.points().map(function (v, i) { return v * (i % 2 === 0 ? sx : sy); }));
            n.scale({ x: 1, y: 1 });
        } else if (clase === 'Text') {
            n.width(Math.max(20, n.width() * sx));
            if (sy !== 1) n.fontSize(Math.max(6, n.fontSize() * sy));
            n.scale({ x: 1, y: 1 });
        } else if (clase === 'Ellipse') {
            n.radiusX(Math.max(1, n.radiusX() * sx));
            n.radiusY(Math.max(1, n.radiusY() * sy));
            n.scale({ x: 1, y: 1 });
        } else if (clase === 'Line' || clase === 'Arrow') {
            n.points(n.points().map(function (v, i) { return v * (i % 2 === 0 ? sx : sy); }));
            n.scale({ x: 1, y: 1 });
        } else {
            n.width(Math.max(1, n.width() * sx));
            n.height(Math.max(1, n.height() * sy));
            n.scale({ x: 1, y: 1 });
        }
        capaContenido.batchDraw();
    }

    function capaJSON() {
        capaContenido.setAttr('guias', guias);
        return capaContenido.toJSON();
    }

    function cargarCapa(json) {
        capaContenido.destroyChildren();
        tr.nodes([]);
        selNodos = [];
        guias = [];

        if (json) {
            var root = null;
            try { root = Konva.Node.create(json); } catch (e) { root = null; }
            if (root) {
                guias = root.getAttr('guias') || [];
                var hijos = root.getChildren().slice();
                var capasJSON = hijos.filter(function (h) { return h.getAttr('esCapa'); });
                if (capasJSON.length) {
                    capasJSON.forEach(function (g) {
                        g.remove();
                        g.x(0); g.y(0);
                        capaContenido.add(g);
                    });
                    hijos.forEach(function (h) {
                        if (capasJSON.indexOf(h) >= 0) return;
                        h.remove();
                        capaContenido.add(h);
                    });
                } else {
                    var contenedor = new Konva.Group({
                        x: 0, y: 0, esCapa: true, capaId: 'capa-1', nombre: 'Capa 1', locked: false
                    });
                    hijos.forEach(function (h) { h.remove(); contenedor.add(h); });
                    capaContenido.add(contenedor);
                }
                root.destroy();
            }
        }

        // Objetos sueltos (JSON antiguo o mixto) se guardan en la primera capa.
        var sueltos = capaContenido.getChildren().filter(function (n) { return !n.getAttr('esCapa'); });
        if (sueltos.length) {
            var destino = capas()[0] || nuevaCapa('Capa 1');
            sueltos.forEach(function (n) { n.remove(); destino.add(n); });
        }
        if (!capas().length) nuevaCapa('Capa 1');

        capaContenido.setAttr('guias', guias);

        if (!capaPorId(capaActivaId)) capaActivaId = capas()[capas().length - 1].getAttr('capaId');

        capas().forEach(function (c) {
            c.listening(c.visible() && !c.getAttr('locked'));
            c.getChildren().forEach(conectar);
        });

        return hidratar(capaContenido).then(function () {
            capaContenido.draw();
            capaUI.draw();
            pintarCapas();
            pintarGuias();
            pintarListaGuias();
            actualizarProps();
        });
    }

    // ------------------------------------------------------------------
    // Historial
    // ------------------------------------------------------------------
    function antes() {
        historial.push({ pag: actual, json: capaJSON() });
        if (historial.length > 60) historial.shift();
        futuro = [];
    }
    function antesAccion() {
        if (accionEnCurso) return;
        accionEnCurso = true;
        antes();
    }
    function finAccion() {
        setTimeout(function () { accionEnCurso = false; }, 0);
    }

    function restaurar(entrada) {
        if (!entrada) return;
        var cambiar = entrada.pag !== actual;
        if (cambiar) {
            guardarPaginaActual();
            actual = entrada.pag;
            pintarPaginas();
        }
        cargarCapa(entrada.json).then(function () {
            marcar();
            if (cambiar) pintarPaginas();
        });
    }

    function deshacer() {
        if (!historial.length) return;
        futuro.push({ pag: actual, json: capaJSON() });
        restaurar(historial.pop());
    }
    function rehacer() {
        if (!futuro.length) return;
        historial.push({ pag: actual, json: capaJSON() });
        restaurar(futuro.pop());
    }

    // ------------------------------------------------------------------
    // Estado y guardado
    // ------------------------------------------------------------------
    function estado(txt, error) {
        if (!estadoTxt) return;
        estadoTxt.textContent = txt || '';
        estadoTxt.classList.toggle('ed-estado--error', !!error);
    }

    function marcar() {
        if (capaActivaGrupo()) capaActivaId = capaActivaGrupo().getAttr('capaId');
        sucio = true;
        estado('Cambios sin guardar');
        clearTimeout(temporizador);
        temporizador = setTimeout(function () { if (!editando) guardar(false); }, 3000);
    }

    function guardarPaginaActual() {
        if (paginas[actual]) paginas[actual].json = capaJSON();
    }

    function payload() {
        guardarPaginaActual();
        return {
            id: C.id,
            version: version,
            nombre: nombre.value.trim() || C.nombre,
            fondo: FONDO.tipo,
            fondo_color: FONDO.color,
            fondo_config: { paso: FONDO.paso, color: FONDO.linea },
            laminas: paginas.map(function (p, i) {
                return {
                    id: p.id || 0,
                    orden: i + 1,
                    nombre: p.nombre || ('Lámina ' + (i + 1)),
                    json: p.json || '',
                    png: p.nuevoPng || null
                };
            })
        };
    }

    function guardar(manual) {
        if (guardando) return Promise.resolve(false);
        guardarPaginaActual();
        guardando = true;
        estado('Guardando…');

        var preparar = Promise.resolve();
        if (manual) {
            preparar = Promise.all(paginas.map(function (p) {
                return renderUrl(p.json, 0.3).then(function (url) { p.nuevoPng = url; });
            }));
        }

        return preparar.then(function () {
            var fd = new FormData();
            fd.append('datos', JSON.stringify(payload()));
            return fetch(C.api.guardar, { method: 'POST', body: fd })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (x) {
                    if (x.ok && x.j.success) {
                        version = x.j.version;
                        aplicarIds(x.j.laminas || []);
                        sucio = false;
                        estado('Guardado ' + new Date().toLocaleTimeString());
                        pintarPaginas();
                        return true;
                    }
                    if (x.j.conflicto && manual) {
                        // Confirmación en el modal estilizado (nunca window.confirm):
                        // la promesa se resuelve con true si el usuario fuerza el guardado.
                        return new Promise(function (confirmar) {
                            confirmarAccion({
                                sobreTitulo: 'Guardar',
                                titulo: 'Hay otra versión más reciente',
                                mensaje: (x.j.error || 'El documento cambió desde otra pestaña.')
                                    + '\n\n¿Guardar de todos modos y reemplazar esa versión?',
                                textoConfirmar: 'Reemplazar la versión',
                                alConfirmar: function () { confirmar(true); },
                                alCerrar: function () { confirmar(false); }
                            });
                        }).then(function (forzar) {
                            if (!forzar) return false;
                            var fd2 = new FormData();
                            fd2.append('datos', JSON.stringify(payload()));
                            fd2.append('forzar', '1');
                            return fetch(C.api.guardar, { method: 'POST', body: fd2 })
                                .then(function (r) { return r.json(); })
                                .then(function (j2) {
                                    if (j2.success) {
                                        version = j2.version;
                                        aplicarIds(j2.laminas || []);
                                        sucio = false;
                                        estado('Guardado ' + new Date().toLocaleTimeString());
                                        pintarPaginas();
                                        return true;
                                    }
                                    estado(j2.error || 'No se pudo guardar', true);
                                    return false;
                                });
                        });
                    }
                    estado(x.j.error || 'No se pudo guardar', true);
                    return false;
                });
        }).catch(function () {
            estado('No se pudo guardar', true);
            return false;
        }).then(function (ok) {
            guardando = false;
            paginas.forEach(function (p) { p.nuevoPng = null; });
            return ok;
        });
    }

    function aplicarIds(laminas) {
        laminas.forEach(function (l, i) {
            if (!paginas[i]) return;
            paginas[i].id = l.id;
            if (l.png) paginas[i].thumb = '/public/uploads/vplan/' + l.png;
        });
    }

    // ------------------------------------------------------------------
    // Render fuera de pantalla (miniaturas y exportación)
    // ------------------------------------------------------------------
    function renderUrl(json, ratio) {
        return new Promise(function (resolve) {
            var div = document.createElement('div');
            div.style.cssText = 'position:fixed;left:-100000px;top:0;width:' + C.ancho + 'px;height:' + C.alto + 'px;overflow:hidden';
            document.body.appendChild(div);
            var st = new Konva.Stage({ container: div, width: C.ancho, height: C.alto });
            var f = new Konva.Layer({ listening: false });
            var c = new Konva.Layer({ listening: false });
            st.add(f, c);

            crearFondo(f, false).then(function () {
                if (!json) return;
                var nodo = null;
                try { nodo = Konva.Node.create(json); } catch (e) { nodo = null; }
                if (nodo) {
                    nodo.getChildren().slice().forEach(function (n) {
                        n.remove();
                        n.listening(false);
                        c.add(n);
                    });
                    nodo.destroy();
                }
                return hidratar(c);
            }).then(function () {
                st.draw();
                var url = '';
                try { url = st.toDataURL({ pixelRatio: ratio, mimeType: 'image/png' }); } catch (e) { url = ''; }
                st.destroy();
                div.remove();
                resolve(url);
            });
        });
    }

    // ------------------------------------------------------------------
    // Selección
    // ------------------------------------------------------------------
    function seleccionar(lista, expandir) {
        var final = [];
        (lista || []).forEach(function (n) {
            if (!n || !n.getStage()) return;
            var capa = capaDe(n);
            if (!capa || !capa.visible()) return;
            var grupo = expandir === false ? [n] : grupoDe(n);
            grupo.forEach(function (x) {
                if (x.getStage() && final.indexOf(x) < 0 && capaDe(x) && capaDe(x).visible()) final.push(x);
            });
        });
        selNodos = final;
        tr.nodes(final);
        if (final.length === 1) {
            var c = capaDe(final[0]);
            if (c && c.getAttr('capaId') !== capaActivaId) {
                capaActivaId = c.getAttr('capaId');
                pintarCapas();
            }
        }
        capaUI.batchDraw();
        actualizarProps();
        if (final.length) irATab('props');
    }

    function deseleccionar() { seleccionar([], false); }

    // ------------------------------------------------------------------
    // Formas predefinidas
    // ------------------------------------------------------------------
    function puntosRegulares(lados, offset) {
        var pts = [];
        for (var i = 0; i < lados; i++) {
            var a = (Math.PI * 2 * i / lados) - Math.PI / 2 + (offset || 0) * Math.PI / 180;
            pts.push(50 + Math.cos(a) * 50, 50 + Math.sin(a) * 50);
        }
        return pts;
    }
    function puntosEstrella(puntas, interno) {
        var pts = [];
        for (var i = 0; i < puntas * 2; i++) {
            var r = i % 2 === 0 ? 50 : interno;
            var a = (Math.PI * i / puntas) - Math.PI / 2;
            pts.push(50 + Math.cos(a) * r, 50 + Math.sin(a) * r);
        }
        return pts;
    }
    function puntosCorazon() {
        var pts = [];
        for (var i = 0; i < 48; i++) {
            var t = Math.PI * 2 * i / 48;
            var x = 16 * Math.pow(Math.sin(t), 3);
            var y = 13 * Math.cos(t) - 5 * Math.cos(2 * t) - 2 * Math.cos(3 * t) - Math.cos(4 * t);
            pts.push(50 + x * 2.75, 48 - y * 2.75);
        }
        return pts;
    }

    var FORMAS = {
        triangulo: [50, 0, 100, 100, 0, 100],
        rombo: [50, 0, 100, 50, 50, 100, 0, 50],
        pentagono: puntosRegulares(5),
        hexagono: puntosRegulares(6, 30),
        octagono: puntosRegulares(8, 22.5),
        estrella: puntosEstrella(5, 20),
        estrella4: puntosEstrella(4, 22),
        estrella6: puntosEstrella(6, 28),
        estrella8: puntosEstrella(8, 32),
        cruz: [35, 0, 65, 0, 65, 35, 100, 35, 100, 65, 65, 65, 65, 100, 35, 100, 35, 65, 0, 65, 0, 35, 35, 35],
        equis: [0, 12, 12, 0, 50, 38, 88, 0, 100, 12, 62, 50, 100, 88, 88, 100, 50, 62, 12, 100, 0, 88, 38, 50],
        check: [0, 52, 14, 38, 38, 62, 86, 10, 100, 24, 38, 90],
        'flecha-bloque': [0, 30, 55, 30, 55, 0, 100, 50, 55, 100, 55, 70, 0, 70],
        chevron: [0, 0, 45, 0, 100, 50, 45, 100, 0, 100, 55, 50],
        trapecio: [20, 0, 80, 0, 100, 100, 0, 100],
        paralelogramo: [25, 0, 100, 0, 75, 100, 0, 100],
        'triangulo-rect': [0, 0, 0, 100, 100, 100],
        casa: [50, 0, 100, 35, 100, 100, 0, 100, 0, 35],
        corazon: puntosCorazon(),
        luna: [50, 0, 72, 6, 88, 22, 94, 42, 92, 62, 82, 82, 64, 96, 46, 100, 60, 84, 68, 64, 68, 42, 60, 20],
        rayo: [55, 0, 15, 55, 45, 55, 30, 100, 90, 35, 55, 35],
        gota: [50, 0, 70, 30, 90, 60, 85, 80, 70, 95, 50, 100, 30, 95, 15, 80, 10, 60, 30, 30],
        'forma-l': [0, 0, 40, 0, 40, 60, 100, 60, 100, 100, 0, 100],
        'forma-t': [0, 0, 100, 0, 100, 40, 70, 40, 70, 100, 30, 100, 30, 40, 0, 40],
        'forma-u': [0, 0, 35, 0, 35, 60, 65, 60, 65, 0, 100, 0, 100, 100, 0, 100],
        globo: [0, 0, 100, 0, 100, 70, 45, 70, 30, 100, 30, 70, 0, 70],
        bandera: [0, 0, 100, 0, 80, 25, 100, 50, 10, 50, 10, 100, 0, 100]
    };

    function aplicarDash(n, estilo, l, g) {
        var sw = Math.max(1, n.strokeWidth() || 1);
        var largo = l || 4, hueco = g || 3;
        n.setAttr('dashEstilo', estilo);
        n.setAttr('dashL', largo);
        n.setAttr('dashG', hueco);
        var arr = [];
        if (estilo === 'dash') arr = [sw * largo, sw * hueco];
        else if (estilo === 'dashdot') arr = [sw * largo, sw * hueco, sw * 0.9, sw * hueco];
        else if (estilo === 'dot') arr = [Math.max(0.5, sw * 0.45), sw * hueco];
        n.setAttr('dash', arr);
        n.setAttr('lineCap', estilo === 'dot' ? 'round' : 'butt');
    }

    function crearForma(tipo, p) {
        var comunes = { x: p.x, y: p.y, draggable: true };
        if (tipo === 'rect' || tipo === 'rrect' || tipo === 'zona') {
            return new Konva.Rect(Object.assign({}, comunes, {
                width: 0, height: 0,
                fill: tipo === 'zona' ? 'rgba(70,121,166,.16)' : '#dbeafe',
                stroke: '#4679a6', strokeWidth: 2,
                cornerRadius: tipo === 'rrect' ? 12 : 0,
                dashEstilo: tipo === 'zona' ? 'dash' : 'solid'
            }));
        }
        if (tipo === 'elipse') {
            return new Konva.Ellipse(Object.assign({}, comunes, {
                radiusX: 0, radiusY: 0, fill: '#dbeafe', stroke: '#4679a6', strokeWidth: 2,
                dashEstilo: 'solid'
            }));
        }
        if (tipo === 'flecha' || tipo === 'flecha-doble') {
            return new Konva.Arrow(Object.assign({}, comunes, {
                points: [0, 0, 0, 0], stroke: '#1f2937', fill: '#1f2937',
                strokeWidth: 2, pointerLength: 13, pointerWidth: 13,
                pointerAtBeginning: tipo === 'flecha-doble',
                lineCap: 'round', dashEstilo: 'solid'
            }));
        }
        if (tipo === 'linea' || tipo === 'linea-dash' || tipo === 'linea-dashdot' || tipo === 'linea-dot') {
            var estilo = tipo === 'linea' ? 'solid' : tipo.replace('linea-', '');
            return new Konva.Line(Object.assign({}, comunes, {
                points: [0, 0, 0, 0], stroke: '#1f2937', strokeWidth: 2,
                lineCap: 'round', dashEstilo: estilo
            }));
        }
        if (tipo === 'lapiz') {
            return new Konva.Line(Object.assign({}, comunes, {
                points: [p.x, p.y], stroke: '#1f2937', strokeWidth: 2,
                lineCap: 'round', lineJoin: 'round', tension: 0.35, dashEstilo: 'solid'
            }));
        }
        if (tipo === 'medir') {
            return crearMedida(p);
        }
        // Galería de formas
        return new Konva.Line(Object.assign({}, comunes, {
            points: (FORMAS[tipo] || FORMAS.rect).slice(),
            closed: true,
            fill: '#dbeafe',
            stroke: '#4679a6',
            strokeWidth: 2,
            esForma: true,
            forma: tipo,
            dashEstilo: 'solid'
        }));
    }

    function redimensionarForma(n, o, p, ortogonal) {
        var tipo = n.getAttr('forma') || (n.getClassName() === 'Rect' ? (n.cornerRadius() ? 'rrect' : 'rect') : n.getClassName());
        var w = p.x - o.x, h = p.y - o.y;
        if (ortogonal && (n.getClassName() === 'Line' || n.getClassName() === 'Arrow')) {
            if (Math.abs(w) > Math.abs(h)) h = 0; else w = 0;
        }
        if (n.getClassName() === 'Rect') {
            n.x(Math.min(o.x, o.x + w));
            n.y(Math.min(o.y, o.y + h));
            n.width(Math.abs(w));
            n.height(Math.abs(h));
        } else if (n.getClassName() === 'Ellipse') {
            n.x(o.x + w / 2);
            n.y(o.y + h / 2);
            n.radiusX(Math.abs(w) / 2);
            n.radiusY(Math.abs(h) / 2);
        } else if (n.getAttr('esForma')) {
            n.x(Math.min(o.x, o.x + w));
            n.y(Math.min(o.y, o.y + h));
            n.scaleX(Math.max(2, Math.abs(w)) / 100);
            n.scaleY(Math.max(2, Math.abs(h)) / 100);
        } else {
            n.x(o.x);
            n.y(o.y);
            n.points([0, 0, w, h]);
        }
    }

    function completarForma(n, tipo) {
        var clase = n.getClassName();
        if (clase === 'Rect' && (n.width() < 4 || n.height() < 4)) {
            n.width(180); n.height(110);
        } else if (clase === 'Ellipse' && (n.radiusX() < 3 || n.radiusY() < 3)) {
            n.radiusX(90); n.radiusY(60);
        } else if ((clase === 'Line' || clase === 'Arrow') && !n.getAttr('esForma')) {
            var pts = n.points();
            if (Math.abs(pts[2] - pts[0]) < 3 && Math.abs(pts[3] - pts[1]) < 3) {
                n.points([0, 0, 140, 0]);
            }
        } else if (n.getAttr('esForma') && (Math.abs(n.scaleX() * 100) < 4 || Math.abs(n.scaleY() * 100) < 4)) {
            n.scaleX(1.6); n.scaleY(1.2);
        }
        var estilo = n.getAttr('dashEstilo');
        if (!estilo && (clase === 'Line' || clase === 'Arrow')) estilo = 'solid';
        if (estilo) aplicarDash(n, estilo, n.getAttr('dashL') || 4, n.getAttr('dashG') || 3);
    }

    // ------------------------------------------------------------------
    // Tablas
    // ------------------------------------------------------------------
    function geometriaTabla(t) {
        var cw = (t.getAttr('colW') || []).slice();
        var rh = (t.getAttr('rowH') || []).slice();
        return { cw: cw, rh: rh, W: suma(cw), H: suma(rh) };
    }

    function dibujarTabla(t) {
        var filas = t.getAttr('filas') || 3;
        var cols = t.getAttr('columnas') || 3;
        var cw = (t.getAttr('colW') || []).slice();
        var rh = (t.getAttr('rowH') || []).slice();
        while (cw.length < cols) cw.push(cw[cw.length - 1] || 110);
        while (rh.length < filas) rh.push(rh[rh.length - 1] || 32);
        cw = cw.slice(0, cols); rh = rh.slice(0, filas);
        t.setAttr('colW', cw); t.setAttr('rowH', rh);
        var W = suma(cw), H = suma(rh);
        t.width(W); t.height(H);
        t.destroyChildren();

        t.add(new Konva.Rect({ x: 0, y: 0, width: W, height: H, fill: t.getAttr('fondo') || '#ffffff', listening: true }));
        if (t.getAttr('encabezado')) {
            t.add(new Konva.Rect({ x: 0, y: 0, width: W, height: rh[0], fill: t.getAttr('hfill') || '#dbeafe', listening: false }));
        }
        var x = 0, y = 0, i, j;
        for (i = 0; i <= cols; i++) {
            var linea = new Konva.Line({ points: [x, 0, x, H], stroke: t.getAttr('borde') || '#94a3b8', strokeWidth: 1, listening: false });
            t.add(linea);
            x += cw[i] || 0;
        }
        for (j = 0; j <= filas; j++) {
            t.add(new Konva.Line({ points: [0, y, W, y], stroke: t.getAttr('borde') || '#94a3b8', strokeWidth: 1, listening: false }));
            y += rh[j] || 0;
        }
        var celdas = t.getAttr('celdas') || [];
        var fs = t.getAttr('fontSize') || 14;
        var tc = t.getAttr('textoColor') || '#111827';
        var top = 0;
        for (j = 0; j < filas; j++) {
            var left = 0;
            for (i = 0; i < cols; i++) {
                var valor = (celdas[j] && celdas[j][i] != null) ? celdas[j][i] : '';
                t.add(new Konva.Text({
                    x: left + 6, y: top + (rh[j] - fs * 1.2) / 2,
                    width: Math.max(4, cw[i] - 12), height: fs * 1.4,
                    text: valor, fontSize: fs, fontFamily: 'Arial',
                    fill: tc, align: 'left', verticalAlign: 'middle',
                    wrap: 'none', ellipsis: true, listening: false
                }));
                left += cw[i];
            }
            top += rh[j];
        }
    }

    function crearTabla(p) {
        var celdas = [];
        var filas = 3, cols = 3;
        for (var j = 0; j < filas; j++) {
            var fila = [];
            for (var i = 0; i < cols; i++) fila.push(j === 0 ? 'Columna ' + (i + 1) : '');
            celdas.push(fila);
        }
        var t = new Konva.Group({
            x: p.x, y: p.y, draggable: true, esTabla: true,
            filas: filas, columnas: cols,
            colW: [110, 110, 110], rowH: [34, 34, 34],
            celdas: celdas, encabezado: true,
            hfill: '#dbeafe', textoColor: '#111827', fondo: '#ffffff',
            borde: '#94a3b8', fontSize: 14
        });
        dibujarTabla(t);
        return t;
    }

    function ajustarTabla(t) {
        var filas = Math.max(1, Math.round(t.getAttr('filas') || 1));
        var cols = Math.max(1, Math.round(t.getAttr('columnas') || 1));
        var cw = (t.getAttr('colW') || []).slice();
        var rh = (t.getAttr('rowH') || []).slice();
        while (cw.length < cols) cw.push(110);
        while (rh.length < filas) rh.push(34);
        cw = cw.slice(0, cols); rh = rh.slice(0, filas);
        var celdas = [];
        for (var j = 0; j < filas; j++) {
            var fila = [];
            for (var i = 0; i < cols; i++) fila.push((t.getAttr('celdas')[j] && t.getAttr('celdas')[j][i]) || '');
            celdas.push(fila);
        }
        t.setAttr('filas', filas);
        t.setAttr('columnas', cols);
        t.setAttr('colW', cw);
        t.setAttr('rowH', rh);
        t.setAttr('celdas', celdas);
        dibujarTabla(t);
    }

    function editarCeldaDesdeEvento(t, e) {
        var pos = stage.getPointerPosition();
        if (!pos) return;
        var rel = t.getAbsoluteTransform().copy().invert().point(pos);
        var g = geometriaTabla(t);
        var c = 0, r = 0, acc = 0, i;
        for (i = 0; i < g.cw.length; i++) { acc += g.cw[i]; if (rel.x <= acc) { c = i; break; } c = i; }
        acc = 0;
        for (i = 0; i < g.rh.length; i++) { acc += g.rh[i]; if (rel.y <= acc) { r = i; break; } r = i; }
        editarCelda(t, r, c);
    }

    function editarCelda(t, r, c) {
        if (editando) return;
        r = Math.max(0, Math.min(t.getAttr('filas') - 1, r));
        c = Math.max(0, Math.min(t.getAttr('columnas') - 1, c));
        var g = geometriaTabla(t);
        var x = suma(g.cw.slice(0, c)), y = suma(g.rh.slice(0, r));
        var abs = t.getAbsoluteTransform().point({ x: x, y: y });
        var cr = cont.getBoundingClientRect();
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'ed-textarea ed-textarea--celda';
        inp.value = (t.getAttr('celdas')[r] && t.getAttr('celdas')[r][c]) || '';
        inp.style.left = (cr.left + abs.x + 2) + 'px';
        inp.style.top = (cr.top + abs.y + 2) + 'px';
        inp.style.width = Math.max(40, g.cw[c] * zoom - 4) + 'px';
        inp.style.height = Math.max(22, g.rh[r] * zoom - 4) + 'px';
        inp.style.fontSize = Math.max(9, (t.getAttr('fontSize') || 14) * zoom) + 'px';
        document.body.appendChild(inp);
        antes();
        editando = { tipo: 'celda', nodo: t, r: r, c: c };
        inp.focus();
        inp.select();

        var cerrado = false;
        function cerrar(guardar, mover) {
            if (cerrado) return;
            cerrado = true;
            editando = null;
            var valor = inp.value;
            inp.remove();
            if (guardar && valor !== ((t.getAttr('celdas')[r] || [])[c] || '')) {
                var celdas = (t.getAttr('celdas') || []).map(function (f) { return f.slice(); });
                celdas[r][c] = valor;
                t.setAttr('celdas', celdas);
                dibujarTabla(t);
                capaContenido.batchDraw();
                marcar();
            }
            if (mover) {
                var nc = c + 1, nr = r;
                if (nc >= t.getAttr('columnas')) { nc = 0; nr++; }
                if (nr < t.getAttr('filas')) setTimeout(function () { editarCelda(t, nr, nc); }, 20);
            }
            actualizarProps();
        }
        inp.addEventListener('blur', function () { cerrar(true, false); });
        inp.addEventListener('keydown', function (ev) {
            ev.stopPropagation();
            if (ev.key === 'Escape') { ev.preventDefault(); cerrar(false, false); }
            if (ev.key === 'Enter') { ev.preventDefault(); cerrar(true, false); }
            if (ev.key === 'Tab') { ev.preventDefault(); cerrar(true, true); }
        });
    }

    // ------------------------------------------------------------------
    // Pines y medidas
    // ------------------------------------------------------------------
    function siguienteNumero(tipo) {
        var max = 0;
        todosObjetos().forEach(function (n) {
            if (n.getAttr('esPin') && (n.getAttr('forma') || 'circulo') === tipo) {
                max = Math.max(max, parseInt(n.getAttr('numero'), 10) || 0);
            }
        });
        return max + 1;
    }

    function dibujarPin(g) {
        g.destroyChildren();
        var r = g.getAttr('radio') || 18;
        var forma = g.getAttr('forma') || 'circulo';
        var fill = g.getAttr('relleno') || (forma === 'rombo' ? '#dc2626' : '#0070c0');
        var stroke = g.getAttr('borde') || '#ffffff';
        var numero = g.getAttr('numero') || 1;
        g.width(r * 2); g.height(r * 2);
        if (forma === 'rombo') {
            g.add(new Konva.Line({
                points: [r, 0, r * 2, r, r, r * 2, 0, r],
                closed: true, fill: fill, stroke: stroke, strokeWidth: 2,
                listening: true
            }));
        } else {
            g.add(new Konva.Circle({ x: r, y: r, radius: r, fill: fill, stroke: stroke, strokeWidth: 2, listening: true }));
        }
        g.add(new Konva.Text({
            x: 0, y: r - (r * 0.72), width: r * 2, height: r * 1.44,
            text: String(numero), fontSize: Math.max(10, r * 1.05),
            fontFamily: 'Arial', fontStyle: 'bold', fill: '#ffffff',
            align: 'center', verticalAlign: 'middle', listening: false
        }));
    }

    function crearPin(p, tipo) {
        var g = new Konva.Group({
            x: p.x - 18, y: p.y - 18, draggable: true, esPin: true,
            forma: tipo === 'restr' ? 'rombo' : 'circulo',
            radio: tipo === 'restr' ? 19 : 18,
            numero: siguienteNumero(tipo === 'restr' ? 'rombo' : 'circulo'),
            relleno: tipo === 'restr' ? '#dc2626' : '#0070c0',
            borde: '#ffffff'
        });
        dibujarPin(g);
        return g;
    }

    function editarNumero(n) {
        pedirTexto({
            sobreTitulo: 'Marcador',
            titulo: 'Número del marcador',
            etiqueta: 'Número',
            predeterminado: String(n.getAttr('numero') || 1),
            placeholder: '1',
            textoConfirmar: 'Aceptar'
        }).then(function (v) {
            if (v === null) return;
            var numero = parseInt(v, 10);
            if (!isFinite(numero) || numero < 1) {
                if (String(v).trim() !== '') { estado('El número del marcador no es válido', true); }
                return;
            }
            antes();
            n.setAttr('numero', numero);
            dibujarPin(n);
            capaContenido.batchDraw();
            marcar();
            actualizarProps();
        });
    }

    function medidaTexto(px, unidad) {
        var valor = px * (UNIDADES[unidad] || 1);
        var decimales = unidad === 'px' ? 0 : (unidad === 'm' ? 3 : 2);
        return valor.toFixed(decimales) + ' ' + (NOMBRE_UNIDAD[unidad] || unidad);
    }

    function dibujarMedida(g) {
        g.destroyChildren();
        var pts = g.getAttr('puntos') || [0, 0, 0, 0];
        var unidad = g.getAttr('unidad') || 'cm';
        var largo = Math.hypot(pts[2] - pts[0], pts[3] - pts[1]);
        g.add(new Konva.Arrow({
            points: pts, stroke: g.getAttr('trazo') || '#dc2626',
            fill: g.getAttr('trazo') || '#dc2626',
            strokeWidth: g.getAttr('grosor') || 2, pointerLength: 10, pointerWidth: 9,
            listening: true
        }));
        var txt = medidaTexto(largo, unidad);
        var etiqueta = new Konva.Label({ x: pts[2] / 2, y: pts[3] / 2 - 16, listening: false });
        etiqueta.add(new Konva.Tag({ fill: 'rgba(255,255,255,.92)', stroke: g.getAttr('trazo') || '#dc2626', cornerRadius: 3 }));
        etiqueta.add(new Konva.Text({ text: txt, fontSize: 13, padding: 4, fontFamily: 'Arial', fill: '#991b1b' }));
        g.add(etiqueta);
    }

    function crearMedida(p) {
        var g = new Konva.Group({ x: p.x, y: p.y, draggable: true, esMedida: true, puntos: [0, 0, 0, 0], unidad: 'cm', trazo: '#dc2626', grosor: 2 });
        dibujarMedida(g);
        return g;
    }

    // ------------------------------------------------------------------
    // Texto en línea
    // ------------------------------------------------------------------
    function editarTexto(n) {
        if (editando) return;
        var b = caja(n);
        var cr = cont.getBoundingClientRect();
        var ta = document.createElement('textarea');
        ta.className = 'ed-textarea';
        ta.value = n.text();
        ta.style.left = (cr.left + b.x * zoom) + 'px';
        ta.style.top = (cr.top + b.y * zoom) + 'px';
        ta.style.width = Math.max(70, b.width * zoom) + 'px';
        ta.style.height = Math.max(34, b.height * zoom + 10) + 'px';
        ta.style.fontSize = (n.fontSize() * zoom) + 'px';
        ta.style.fontFamily = n.fontFamily();
        ta.style.lineHeight = n.lineHeight() || 1.2;
        ta.style.color = n.fill();
        document.body.appendChild(ta);
        n.visible(false);
        capaContenido.batchDraw();
        antes();
        editando = { tipo: 'texto', nodo: n };
        ta.focus();
        ta.select();

        var cerrado = false;
        function cerrar(guardar) {
            if (cerrado) return;
            cerrado = true;
            var valor = ta.value;
            editando = null;
            ta.remove();
            n.visible(true);
            if (guardar) {
                n.text(valor === '' ? ' ' : valor);
                n.width(Math.max(20, n.width()));
                marcar();
                actualizarProps();
            }
            capaContenido.batchDraw();
        }
        ta.addEventListener('blur', function () { cerrar(true); });
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); cerrar(false); }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); cerrar(true); }
            e.stopPropagation();
        });
    }

    // ------------------------------------------------------------------
    // Herramientas
    // ------------------------------------------------------------------
    var DIBUJO = {
        linea: 1, 'linea-dash': 1, 'linea-dashdot': 1, 'linea-dot': 1,
        lapiz: 1, flecha: 1, 'flecha-doble': 1, rect: 1, rrect: 1,
        elipse: 1, zona: 1
    };
    Object.keys(FORMAS).forEach(function (k) { DIBUJO[k] = 1; });

    function restaurarCursor() {
        var esPaneo = herramienta === 'paneo';
        var dibujando = !esPaneo && herramienta !== 'select' && herramienta !== 'multi';
        stage.container().style.cursor = esPaneo ? 'grab' : dibujando ? 'crosshair' : 'default';
    }

    function activar(nombreHerramienta) {
        herramienta = nombreHerramienta;
        var esPaneo = herramienta === 'paneo';
        document.querySelectorAll('[data-herr]').forEach(function (b) {
            b.classList.toggle('activa', b.dataset.herr === nombreHerramienta);
        });
        pagina.classList.toggle('ed-paneo', esPaneo);
        if (!esPaneo) {
            paneo = null;
            scroll.classList.remove('paneando');
        }
        // En modo mano el lienzo no debe seleccionar ni mover objetos.
        capaContenido.listening(!esPaneo);
        capaUI.listening(!esPaneo);
        restaurarCursor();
        todosObjetos().forEach(function (n) {
            var c = capaDe(n);
            n.draggable((herramienta === 'select' || herramienta === 'multi') && c && !c.getAttr('locked'));
        });
        if (nombreHerramienta === 'imagen') {
            posNuevaImagen = { x: C.ancho / 2, y: C.alto / 2 };
            archivoImg.click();
        }
        if (herramienta !== 'select' && herramienta !== 'multi' && !esPaneo) deseleccionar();
    }

    document.querySelectorAll('[data-herr]').forEach(function (b) {
        b.addEventListener('click', function () {
            activar(b.dataset.herr);
            var mas = $('ed-mas');
            if (mas) mas.open = false;
        });
    });

    var temp = null, origen = null, dibujandoAhora = false, poli = null, marquesa = null;

    function crearTexto(p) {
        antes();
        var t = new Konva.Text({
            x: p.x, y: p.y, text: 'Texto', fontSize: 28, fontFamily: 'Arial',
            fill: '#111827', width: 320, lineHeight: 1.2, draggable: true
        });
        if (!agregarObjeto(t)) { t.destroy(); return; }
        capaContenido.draw();
        seleccionar([t]);
        setTimeout(function () { editarTexto(t); }, 40);
        marcar();
    }

    function puntoOrtogonal(prev, p, shift) {
        if (!shift && !$('ed-orto').checked) return p;
        var dx = p.x - prev.x, dy = p.y - prev.y;
        if (Math.abs(dx) > Math.abs(dy)) return { x: p.x, y: prev.y };
        return { x: prev.x, y: p.y };
    }

    function terminarPoli() {
        if (!poli) return;
        var n = poli.n;
        if (poli.puntos.length < 6) { n.destroy(); }
        else {
            n.points(poli.puntos);
            completarForma(n, 'polilinea');
            conectar(n);
            seleccionar([n]);
            marcar();
        }
        poli = null;
        capaContenido.batchDraw();
        activar('select');
    }

    stage.on('mousedown touchstart', function (e) {
        if (e.evt && e.evt.button !== undefined && e.evt.button !== 0) return;
        if (herramienta === 'paneo') return;

        if (herramienta === 'select' || herramienta === 'multi') {
            if (e.target !== stage) return;
            marquesa = {
                x: stage.getRelativePointerPosition().x,
                y: stage.getRelativePointerPosition().y,
                sumar: !!(e.evt && (e.evt.shiftKey || e.evt.ctrlKey || e.evt.metaKey))
            };
            return;
        }

        var p = stage.getRelativePointerPosition();
        if (!p) return;

        if (herramienta === 'imagen') {
            posNuevaImagen = p;
            archivoImg.click();
            return;
        }

        if (herramienta === 'texto') { crearTexto(p); activar('select'); return; }
        if (herramienta === 'tabla') {
            antes();
            var t = crearTabla(p);
            if (agregarObjeto(t)) { seleccionar([t]); marcar(); }
            activar('select');
            return;
        }
        if (herramienta === 'pin' || herramienta === 'restr') {
            antes();
            var pin = crearPin(p, herramienta);
            if (agregarObjeto(pin)) { seleccionar([pin]); marcar(); }
            activar('select');
            return;
        }

        if (herramienta === 'polilinea') {
            if (!poli) {
                antes();
                var linea = new Konva.Line({
                    points: [p.x, p.y, p.x, p.y], stroke: '#1f2937', strokeWidth: 2,
                    lineCap: 'round', lineJoin: 'round', dashEstilo: 'solid'
                });
                if (!agregarObjeto(linea)) { linea.destroy(); return; }
                poli = { n: linea, puntos: [p.x, p.y, p.x, p.y] };
            } else {
                poli.puntos.push(p.x, p.y);
            }
            e.cancelBubble = true;
            return;
        }

        if (herramienta === 'medir') {
            antes();
            var m = crearMedida(p);
            if (!agregarObjeto(m)) { m.destroy(); return; }
            origen = p;
            temp = m;
            dibujandoAhora = true;
            marcar();
            return;
        }

        if (DIBUJO[herramienta]) {
            antes();
            origen = p;
            temp = crearForma(herramienta, p);
            if (!agregarObjeto(temp)) { temp.destroy(); temp = null; return; }
            dibujandoAhora = true;
            e.cancelBubble = true;
        }
    });

    stage.on('mousemove touchmove', function () {
        var p = stage.getRelativePointerPosition();
        if (!p) return;

        if (marquesa && !dibujandoAhora) {
            var x = Math.min(marquesa.x, p.x), y = Math.min(marquesa.y, p.y);
            var w = Math.abs(p.x - marquesa.x), h = Math.abs(p.y - marquesa.y);
            marquesaNodo.setAttrs({ x: x, y: y, width: w, height: h, visible: w > 3 || h > 3 });
            capaUI.batchDraw();
            return;
        }
        if (poli) {
            var pts = poli.puntos.slice();
            var ultimo = { x: pts[pts.length - 2], y: pts[pts.length - 1] };
            var q = puntoOrtogonal(ultimo, p, false);
            pts[pts.length - 2] = q.x;
            pts[pts.length - 1] = q.y;
            poli.n.points(pts);
            capaContenido.batchDraw();
            return;
        }
        if (!dibujandoAhora || !temp) return;

        if (herramienta === 'lapiz') {
            var p2 = p;
            var lp = temp.points();
            var lx = lp[lp.length - 2], ly = lp[lp.length - 1];
            if (Math.abs(p2.x - lx) + Math.abs(p2.y - ly) > 1.5) {
                temp.points(lp.concat([p2.x, p2.y]));
            }
        } else if (herramienta === 'medir') {
            var mp = puntoOrtogonal(origen, p, false);
            temp.setAttr('puntos', [0, 0, mp.x - origen.x, mp.y - origen.y]);
            dibujarMedida(temp);
        } else {
            var np = puntoOrtogonal(origen, p, false);
            redimensionarForma(temp, origen, np, false);
        }
        capaContenido.batchDraw();
    });

    function terminarDibujo() {
        if (marquesa) {
            var m = marquesa;
            marquesa = null;
            var rect = { x: marquesaNodo.x(), y: marquesaNodo.y(), width: marquesaNodo.width(), height: marquesaNodo.height() };
            marquesaNodo.visible(false);
            capaUI.batchDraw();
            if (rect.width < 4 && rect.height < 4) {
                if (!m.sumar) deseleccionar();
                return;
            }
            var elegidos = objetos().filter(function (n) {
                var b = caja(n);
                return !(b.x > rect.x + rect.width || b.x + b.width < rect.x ||
                    b.y > rect.y + rect.height || b.y + b.height < rect.y);
            });
            if (m.sumar) elegidos = selNodos.concat(elegidos);
            seleccionar(elegidos, false);
            return;
        }
        if (!dibujandoAhora || !temp) return;
        dibujandoAhora = false;
        if (herramienta === 'lapiz') {
            if (temp.points().length < 4) { temp.destroy(); temp = null; capaContenido.batchDraw(); return; }
        } else if (herramienta === 'medir') {
            var pts = temp.getAttr('puntos');
            if (Math.hypot(pts[2], pts[3]) < 5) {
                temp.setAttr('puntos', [0, 0, 160, 0]);
                dibujarMedida(temp);
            }
        } else {
            completarForma(temp, herramienta);
        }
        conectar(temp);
        capaContenido.draw();
        seleccionar([temp]);
        temp = null;
        origen = null;
        activar('select');
        marcar();
    }

    window.addEventListener('mouseup', terminarDibujo);
    window.addEventListener('touchend', terminarDibujo);

    stage.on('dblclick dbltap', function () {
        if (poli) terminarPoli();
    });

    // ------------------------------------------------------------------
    // Propiedades
    // ------------------------------------------------------------------
    var P = {
        vacio: $('ed-sin-seleccion'), con: $('ed-con-seleccion'),
        titulo: $('ed-sel-titulo'), info: $('ed-sel-info'),
        geo: $('ed-grupo-geometria'), apariencia: $('ed-grupo-apariencia'),
        x: $('ed-p-x'), y: $('ed-p-y'), w: $('ed-p-w'), h: $('ed-p-h'),
        rot: $('ed-p-rot'), op: $('ed-p-op'),
        filaTamano: $('ed-fila-tamano'),
        texto: $('ed-grupo-texto'), contenido: $('ed-p-texto'),
        fuente: $('ed-p-fuente'), fuenteTam: $('ed-p-fuente-tam'),
        interlineado: $('ed-p-interlineado'), espaciado: $('ed-p-espaciado'),
        filaRelleno: $('ed-fila-relleno'), relleno: $('ed-p-fill'), borde: $('ed-p-stroke'),
        sinRelleno: $('ed-fila-sin-relleno'), chkSinRelleno: $('ed-p-sin-relleno'),
        filaGrosor: $('ed-fila-grosor'), grosor: $('ed-p-grosor'),
        filaEstilo: $('ed-fila-estilo'), dash: $('ed-p-dash'),
        filaDash: $('ed-fila-dash'), dashL: $('ed-p-dash-l'), dashG: $('ed-p-dash-g'),
        tabla: $('ed-grupo-tabla'), filas: $('ed-p-filas'), columnas: $('ed-p-columnas'),
        colW: $('ed-p-col-w'), filaH: $('ed-p-fila-h'), encabezado: $('ed-p-encabezado'),
        hfill: $('ed-p-hfill'), textoColor: $('ed-p-texto-color'),
        pin: $('ed-grupo-pin'), numero: $('ed-p-numero'),
        medida: $('ed-grupo-medida'), unidad: $('ed-p-unidad'), longitud: $('ed-p-longitud'),
        filaCapa: $('ed-fila-capa'), capa: $('ed-p-capa'),
        alinear: $('ed-panel-alinear'), selN: $('ed-sel-n'), alRef: $('ed-al-ref')
    };

    var NOMBRES_TIPO = {
        Rect: 'Rectángulo', Ellipse: 'Elipse', Text: 'Texto', Line: 'Línea',
        Arrow: 'Flecha', Image: 'Imagen', Group: 'Grupo'
    };
    var NOMBRES_FORMA = {
        triangulo: 'Triángulo', rombo: 'Rombo', pentagono: 'Pentágono', hexagono: 'Hexágono',
        octagono: 'Octágono', estrella: 'Estrella', estrella4: 'Estrella de 4 puntas',
        estrella6: 'Estrella de 6 puntas', estrella8: 'Estrella de 8 puntas', cruz: 'Cruz',
        equis: 'Equis', check: 'Palomita', 'flecha-bloque': 'Flecha de bloque', chevron: 'Chevron',
        trapecio: 'Trapecio', paralelogramo: 'Paralelogramo', 'triangulo-rect': 'Triángulo rectángulo',
        casa: 'Casa', corazon: 'Corazón', luna: 'Luna', rayo: 'Rayo', gota: 'Gota',
        'forma-l': 'Forma en L', 'forma-t': 'Forma en T', 'forma-u': 'Forma en U',
        globo: 'Globo de texto', bandera: 'Bandera', zona: 'Zona'
    };

    function tipoDe(n) {
        if (n.getAttr('esTabla')) return 'Tabla';
        if (n.getAttr('esPin')) return n.getAttr('forma') === 'rombo' ? 'Restricción' : 'Círculo numerado';
        if (n.getAttr('esMedida')) return 'Medida';
        if (n.getAttr('esForma')) return NOMBRES_FORMA[n.getAttr('forma')] || 'Forma';
        if (n.getClassName() === 'Rect' && n.cornerRadius()) return 'Rectángulo redondeado';
        if (n.getClassName() === 'Line' && n.getAttr('dashEstilo') === 'dash' && n.closed()) return 'Forma';
        return NOMBRES_TIPO[n.getClassName()] || n.getClassName();
    }

    function mostrar(el, v) { if (el) el.hidden = !v; }

    function actualizarProps() {
        selNodos = selNodos.filter(function (n) { return n.getStage(); });
        if (!selNodos.length) {
            P.vacio.hidden = false;
            P.con.hidden = true;
            tr.nodes([]);
            return;
        }
        P.vacio.hidden = true;
        P.con.hidden = false;

        var n = selNodos.length === 1 ? selNodos[0] : null;
        var multi = selNodos.length > 1;
        P.titulo.textContent = multi ? selNodos.length + ' objetos' : tipoDe(n);
        var capa = n ? capaDe(n) : null;
        P.info.textContent = capa ? capa.getAttr('nombre') : '';

        mostrar(P.geo, !multi);
        mostrar(P.apariencia, !multi);
        mostrar(P.texto, !!n && n.getClassName() === 'Text');
        mostrar(P.tabla, !!n && !!n.getAttr('esTabla'));
        mostrar(P.pin, !!n && !!n.getAttr('esPin'));
        mostrar(P.medida, !!n && !!n.getAttr('esMedida'));
        mostrar(P.alinear, multi);
        mostrar(P.filaCapa, !multi);
        P.selN.textContent = multi ? '(' + selNodos.length + ')' : '';

        if (multi) {
            P.titulo.textContent = selNodos.length + ' objetos';
            poblarCapasSelect(n || selNodos[0]);
            return;
        }

        var clase = n.getClassName();
        var esTexto = clase === 'Text';
        var esImagen = clase === 'Image';
        var esLinea = clase === 'Line' || clase === 'Arrow';
        var esTabla = !!n.getAttr('esTabla');
        var esPin = !!n.getAttr('esPin');
        var esMedida = !!n.getAttr('esMedida');
        var esForma = !!n.getAttr('esForma');
        var esRect = clase === 'Rect';
        var esElipse = clase === 'Ellipse';

        P.x.value = Math.round(n.x());
        P.y.value = Math.round(n.y());
        P.rot.value = Math.round(n.rotation());
        P.op.value = Math.round(n.opacity() * 100);

        var b = caja(n);
        P.w.value = Math.round(b.width);
        P.h.value = Math.round(b.height);
        mostrar(P.filaTamano, !esLinea && !esPin && !esTabla && !esMedida);

        var conRelleno = esRect || esElipse || esForma || esTexto;
        var conTrazo = !esTexto && !esImagen && !esTabla && !esPin && !esMedida;
        mostrar(P.filaRelleno, conRelleno);
        mostrar(P.sinRelleno, conRelleno && !esTexto);
        mostrar(P.filaGrosor, conTrazo);
        mostrar(P.filaEstilo, (esLinea || conTrazo) && !esImagen);
        P.grosor.value = (typeof n.strokeWidth === 'function' ? n.strokeWidth() : 0) || 0;
        P.borde.value = colorHex((typeof n.stroke === 'function' ? n.stroke() : null) || '#4679a6');

        var relleno = typeof n.fill === 'function' ? n.fill() : null;
        P.relleno.value = colorHex(relleno || (esTexto ? '#111827' : '#dbeafe'));
        P.chkSinRelleno.checked = !relleno || relleno === 'transparent';
        P.dash.value = n.getAttr('dashEstilo') || 'solid';
        P.dashL.value = n.getAttr('dashL') || 4;
        P.dashG.value = n.getAttr('dashG') || 3;
        mostrar(P.filaDash, P.dash.value !== 'solid' && !esImagen);

        if (esTexto) {
            P.contenido.value = n.text();
            P.fuente.value = n.fontFamily();
            P.fuenteTam.value = n.fontSize();
            P.interlineado.value = n.lineHeight() || 1.2;
            P.espaciado.value = n.letterSpacing() || 0;
        }

        if (esTabla) {
            P.filas.value = n.getAttr('filas');
            P.columnas.value = n.getAttr('columnas');
            P.colW.value = Math.round((n.getAttr('colW') || [110])[0]);
            P.filaH.value = Math.round((n.getAttr('rowH') || [34])[0]);
            P.encabezado.checked = !!n.getAttr('encabezado');
            P.hfill.value = colorHex(n.getAttr('hfill') || '#dbeafe');
            P.textoColor.value = colorHex(n.getAttr('textoColor') || '#111827');
        }
        if (esPin) P.numero.value = n.getAttr('numero') || 1;
        if (esMedida) {
            P.unidad.value = n.getAttr('unidad') || 'cm';
            var pts = n.getAttr('puntos') || [0, 0, 0, 0];
            P.longitud.textContent = medidaTexto(Math.hypot(pts[2], pts[3]), P.unidad.value);
        }
        poblarCapasSelect(n);
    }

    function poblarCapasSelect(n) {
        if (!P.capa) return;
        var capaSel = n ? capaDe(n) : null;
        var actualId = capaSel ? capaSel.getAttr('capaId') : null;
        P.capa.innerHTML = '';
        capas().slice().reverse().forEach(function (c) {
            var o = document.createElement('option');
            o.value = c.getAttr('capaId');
            o.textContent = c.getAttr('nombre') + (c.getAttr('locked') ? ' 🔒' : '') + (c.visible() ? '' : ' (oculta)');
            if (c.getAttr('capaId') === actualId) o.selected = true;
            P.capa.appendChild(o);
        });
    }

    function modificar(fn) {
        if (!selNodos.length) return;
        antes();
        selNodos.forEach(fn);
        capaContenido.batchDraw();
        marcar();
        actualizarProps();
    }

    [['x', 'x'], ['y', 'y'], ['rot', 'rotation']].forEach(function (par) {
        P[par[0]].addEventListener('change', function () {
            var v = parseFloat(P[par[0]].value) || 0;
            modificar(function (n) { n[par[1]](v); });
        });
    });

    P.op.addEventListener('change', function () {
        var v = Math.max(0, Math.min(100, parseFloat(P.op.value) || 0)) / 100;
        modificar(function (n) { n.opacity(v); });
    });

    P.w.addEventListener('change', function () {
        var v = Math.max(1, parseFloat(P.w.value) || 1);
        modificar(function (n) {
            if (n.getAttr('esForma')) n.scaleX(v / 100);
            else if (n.getClassName() === 'Ellipse') n.radiusX(v / 2);
            else n.width(v);
        });
    });
    P.h.addEventListener('change', function () {
        var v = Math.max(1, parseFloat(P.h.value) || 1);
        modificar(function (n) {
            if (n.getAttr('esForma')) n.scaleY(v / 100);
            else if (n.getClassName() === 'Ellipse') n.radiusY(v / 2);
            else n.height(v);
        });
    });

    P.contenido.addEventListener('change', function () {
        var v = P.contenido.value;
        modificar(function (n) { n.text(v === '' ? ' ' : v); });
    });
    P.fuente.addEventListener('change', function () {
        var v = P.fuente.value;
        modificar(function (n) { n.fontFamily(v); });
    });
    P.fuenteTam.addEventListener('change', function () {
        var v = Math.max(6, parseFloat(P.fuenteTam.value) || 12);
        modificar(function (n) { n.fontSize(v); });
    });
    P.interlineado.addEventListener('change', function () {
        var v = Math.max(0.6, parseFloat(P.interlineado.value) || 1.2);
        modificar(function (n) { n.lineHeight(v); });
    });
    P.espaciado.addEventListener('change', function () {
        var v = parseFloat(P.espaciado.value) || 0;
        modificar(function (n) { n.letterSpacing(v); });
    });

    P.relleno.addEventListener('change', function () {
        var v = P.relleno.value;
        modificar(function (n) { n.fill(v); });
    });
    P.borde.addEventListener('change', function () {
        var v = P.borde.value;
        modificar(function (n) {
            n.stroke(v);
            if (n.getClassName() === 'Arrow') n.fill(v);
        });
    });
    P.chkSinRelleno.addEventListener('change', function () {
        var v = this.checked ? '' : P.relleno.value;
        modificar(function (n) { n.fill(v); });
    });
    P.grosor.addEventListener('change', function () {
        var v = Math.max(0, parseFloat(P.grosor.value) || 0);
        modificar(function (n) {
            n.strokeWidth(v);
            if (n.getClassName() === 'Arrow') n.fill(n.stroke() || '#1f2937');
        });
    });
    P.dash.addEventListener('change', function () {
        var estilo = P.dash.value;
        modificar(function (n) { aplicarDash(n, estilo, parseFloat(P.dashL.value) || 4, parseFloat(P.dashG.value) || 3); });
        mostrar(P.filaDash, estilo !== 'solid');
    });
    [P.dashL, P.dashG].forEach(function (inp) {
        inp.addEventListener('change', function () {
            var estilo = P.dash.value || 'solid';
            modificar(function (n) { aplicarDash(n, estilo, parseFloat(P.dashL.value) || 4, parseFloat(P.dashG.value) || 3); });
        });
    });

    document.querySelectorAll('[data-estilo]').forEach(function (b) {
        b.addEventListener('click', function () {
            var tipo = b.dataset.estilo;
            modificar(function (n) {
                if (tipo === 'underline' || tipo === 'line-through') {
                    var act = (n.textDecoration() || '').split(/\s+/).filter(Boolean);
                    var i = act.indexOf(tipo);
                    if (i >= 0) act.splice(i, 1); else act.push(tipo);
                    n.textDecoration(act.join(' '));
                } else {
                    var estilo = (n.fontStyle() || 'normal').split(/\s+/).filter(function (p) { return p && p !== 'normal'; });
                    var j = estilo.indexOf(tipo);
                    if (j >= 0) estilo.splice(j, 1); else estilo.push(tipo);
                    n.fontStyle(estilo.join(' ') || 'normal');
                }
            });
        });
    });

    document.querySelectorAll('[data-alinear]').forEach(function (b) {
        b.addEventListener('click', function () {
            modificar(function (n) { n.align(b.dataset.alinear); });
        });
    });

    function conTabla(fn) {
        var t = selNodos[0];
        if (!t || !t.getAttr('esTabla')) return;
        antes();
        fn(t);
        ajustarTabla(t);
        marcar();
        actualizarProps();
    }

    P.filas.addEventListener('change', function () {
        conTabla(function (t) { t.setAttr('filas', Math.max(1, Math.min(40, parseInt(P.filas.value, 10) || 1))); });
    });
    P.columnas.addEventListener('change', function () {
        conTabla(function (t) { t.setAttr('columnas', Math.max(1, Math.min(16, parseInt(P.columnas.value, 10) || 1))); });
    });
    P.colW.addEventListener('change', function () {
        conTabla(function (t) {
            var v = Math.max(10, parseFloat(P.colW.value) || 110);
            t.setAttr('colW', (t.getAttr('colW') || []).map(function () { return v; }));
        });
    });
    P.filaH.addEventListener('change', function () {
        conTabla(function (t) {
            var v = Math.max(8, parseFloat(P.filaH.value) || 34);
            t.setAttr('rowH', (t.getAttr('rowH') || []).map(function () { return v; }));
        });
    });
    P.encabezado.addEventListener('change', function () {
        conTabla(function (t) { t.setAttr('encabezado', P.encabezado.checked); });
    });
    P.hfill.addEventListener('change', function () {
        conTabla(function (t) { t.setAttr('hfill', P.hfill.value); });
    });
    P.textoColor.addEventListener('change', function () {
        conTabla(function (t) { t.setAttr('textoColor', P.textoColor.value); });
    });

    P.numero.addEventListener('change', function () {
        var v = Math.max(1, parseInt(P.numero.value, 10) || 1);
        modificar(function (n) { n.setAttr('numero', v); dibujarPin(n); });
    });
    P.unidad.addEventListener('change', function () {
        var u = P.unidad.value;
        modificar(function (n) { n.setAttr('unidad', u); dibujarMedida(n); });
        var pts = selNodos[0].getAttr('puntos') || [0, 0, 0, 0];
        P.longitud.textContent = medidaTexto(Math.hypot(pts[2], pts[3]), u);
    });

    P.capa.addEventListener('change', function () {
        var destino = capaPorId(P.capa.value);
        if (!destino || !selNodos.length) return;
        antes();
        selNodos.forEach(function (n) {
            var pos = n.absolutePosition();
            n.moveTo(destino);
            n.absolutePosition(pos);
        });
        capaActivaId = destino.getAttr('capaId');
        capaContenido.batchDraw();
        pintarCapas();
        marcar();
        actualizarProps();
    });

    $('ed-mover-capa').addEventListener('click', function () {
        var destino = capaActivaGrupo();
        if (!destino || !selNodos.length) return;
        if (destino.getAttr('locked') || !destino.visible()) { estado('La capa activa no admite cambios', true); return; }
        antes();
        selNodos.forEach(function (n) {
            var pos = n.absolutePosition();
            n.moveTo(destino);
            n.absolutePosition(pos);
        });
        capaContenido.batchDraw();
        pintarCapas();
        marcar();
        actualizarProps();
    });

    $('ed-al-frente').addEventListener('click', function () { modificar(function (n) { n.moveToTop(); }); pintarCapas(); });
    $('ed-al-fondo').addEventListener('click', function () { modificar(function (n) { n.moveToBottom(); }); pintarCapas(); });
    $('ed-eliminar-sel').addEventListener('click', borrarSeleccion);
    var btnBorrarHerr = $('ed-borrar');
    if (btnBorrarHerr) btnBorrarHerr.addEventListener('click', borrarSeleccion);
    $('ed-deshacer').addEventListener('click', deshacer);
    $('ed-rehacer').addEventListener('click', rehacer);

    function borrarSeleccion() {
        if (!selNodos.length) return;
        antes();
        selNodos.forEach(function (n) { n.destroy(); });
        selNodos = [];
        tr.nodes([]);
        capaContenido.batchDraw();
        capaUI.batchDraw();
        pintarCapas();
        actualizarProps();
        marcar();
    }

    function duplicarSeleccion() {
        if (!selNodos.length) return;
        antes();
        var nuevos = [];
        var mapaGrupos = {};
        selNodos.forEach(function (n) {
            var copia = n.clone({ x: n.x() + 24, y: n.y() + 24 });
            var g = n.getAttr('grupo');
            if (g) {
                if (!mapaGrupos[g]) mapaGrupos[g] = 'g' + (++contadorGrupo) + '-' + Date.now();
                copia.setAttr('grupo', mapaGrupos[g]);
            }
            var capa = capaPermitida() || capaDe(n);
            if (!capa) return;
            capa.add(copia);
            conectar(copia);
            nuevos.push(copia);
        });
        if (nuevos.length) seleccionar(nuevos, false);
        capaContenido.draw();
        marcar();
    }

    // ------------------------------------------------------------------
    // Alinear, distribuir y agrupar
    // ------------------------------------------------------------------
    function referencia(modo) {
        if (modo === 'pag') return { x: 0, y: 0, width: C.ancho, height: C.alto };
        if (modo === 'prim' && selNodos.length) return caja(selNodos[0]);
        if (modo === 'ult' && selNodos.length) return caja(selNodos[selNodos.length - 1]);
        var x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
        selNodos.forEach(function (n) {
            var b = caja(n);
            x1 = Math.min(x1, b.x); y1 = Math.min(y1, b.y);
            x2 = Math.max(x2, b.x + b.width); y2 = Math.max(y2, b.y + b.height);
        });
        return { x: x1, y: y1, width: x2 - x1, height: y2 - y1 };
    }

    function alinear(modo) {
        if (selNodos.length < 2) return;
        antes();
        var ref = referencia(P.alRef.value);
        selNodos.forEach(function (n) {
            var b = caja(n);
            var dx = 0, dy = 0;
            if (modo === 'izq') dx = ref.x - b.x;
            if (modo === 'cx') dx = (ref.x + ref.width / 2) - (b.x + b.width / 2);
            if (modo === 'der') dx = (ref.x + ref.width) - (b.x + b.width);
            if (modo === 'sup') dy = ref.y - b.y;
            if (modo === 'cy') dy = (ref.y + ref.height / 2) - (b.y + b.height / 2);
            if (modo === 'inf') dy = (ref.y + ref.height) - (b.y + b.height);
            n.x(n.x() + dx);
            n.y(n.y() + dy);
        });
        capaContenido.batchDraw();
        marcar();
        actualizarProps();
    }

    function distribuir(modo) {
        if (selNodos.length < 3) { estado('Elige al menos 3 objetos para distribuir', true); return; }
        antes();
        var cajas = selNodos.map(function (n) { return { n: n, b: caja(n) }; });
        var horiz = modo.charAt(0) === 'h';
        cajas.sort(function (a, b) { return horiz ? a.b.x - b.b.x : a.b.y - b.b.y; });
        var ref = referencia('sel');

        if (modo === 'h-ini' || modo === 'h-cen' || modo === 'h-fin') {
            var ini = cajas[0].b.x, fin = cajas[cajas.length - 1].b.x + cajas[cajas.length - 1].b.width;
            cajas.forEach(function (item, i) {
                var t = i / (cajas.length - 1);
                if (modo === 'h-ini') item.dest = ini + t * (fin - ini - item.b.width) - item.b.x;
                if (modo === 'h-cen') item.dest = (ini + fin) / 2 + (t - 0.5) * (fin - ini) - (item.b.width / 2) - item.b.x;
                if (modo === 'h-fin') item.dest = ini + t * (fin - ini - item.b.width) + item.b.width - (item.b.x + item.b.width);
                item.n.x(item.n.x() + item.dest);
            });
        } else if (modo === 'v-ini' || modo === 'v-cen' || modo === 'v-fin') {
            var iniY = cajas[0].b.y, finY = cajas[cajas.length - 1].b.y + cajas[cajas.length - 1].b.height;
            cajas.forEach(function (item, i) {
                var t = i / (cajas.length - 1);
                if (modo === 'v-ini') item.dest = iniY + t * (finY - iniY - item.b.height) - item.b.y;
                if (modo === 'v-cen') item.dest = (iniY + finY) / 2 + (t - 0.5) * (finY - iniY) - (item.b.height / 2) - item.b.y;
                if (modo === 'v-fin') item.dest = iniY + t * (finY - iniY - item.b.height) + item.b.height - (item.b.y + item.b.height);
                item.n.y(item.n.y() + item.dest);
            });
        } else if (modo === 'h-gap' || modo === 'v-gap') {
            var tam = cajas.reduce(function (s, item) { return s + (horiz ? item.b.width : item.b.height); }, 0);
            var espacio = ((horiz ? ref.width : ref.height) - tam) / (cajas.length - 1);
            var cursor = horiz ? ref.x : ref.y;
            cajas.forEach(function (item) {
                if (horiz) { item.n.x(item.n.x() + cursor - item.b.x); cursor += item.b.width + espacio; }
                else { item.n.y(item.n.y() + cursor - item.b.y); cursor += item.b.height + espacio; }
            });
        }
        capaContenido.batchDraw();
        marcar();
        actualizarProps();
    }

    function agrupar() {
        if (selNodos.length < 2) return;
        antes();
        var id = 'g' + (++contadorGrupo) + '-' + Date.now();
        selNodos.forEach(function (n) { n.setAttr('grupo', id); });
        marcar();
        actualizarProps();
    }
    function desagrupar() {
        if (!selNodos.length) return;
        antes();
        selNodos.forEach(function (n) { n.setAttr('grupo', undefined); });
        marcar();
        actualizarProps();
    }

    document.querySelectorAll('[data-alinear-sel]').forEach(function (b) {
        b.addEventListener('click', function () { alinear(b.dataset.alinearSel); });
    });
    document.querySelectorAll('[data-distribuir]').forEach(function (b) {
        b.addEventListener('click', function () { distribuir(b.dataset.distribuir); });
    });
    $('ed-agrupar').addEventListener('click', agrupar);
    $('ed-desagrupar').addEventListener('click', desagrupar);

    // ------------------------------------------------------------------
    // Guías y reglas
    // ------------------------------------------------------------------
    function guiasOn() { var el = $('ed-guias-on'); return !el || el.checked; }
    function reglasOn() { var el = $('ed-reglas-on'); return !el || el.checked; }

    function guardarGuias() {
        capaContenido.setAttr('guias', guias);
    }

    function pintarGuias() {
        var ov = $('ed-guias');
        if (!ov) return;
        ov.innerHTML = '';
        if (!guiasOn()) { pintarListaGuias(); return; }
        var lr = lienzo.getBoundingClientRect();
        var sr = cont.getBoundingClientRect();
        var x0 = sr.left - lr.left, y0 = sr.top - lr.top;
        guias.forEach(function (g) {
            var el = document.createElement('div');
            el.className = 'ed-guia ed-guia--' + g.eje;
            el.dataset.id = g.id;
            el.title = (g.eje === 'v' ? 'Guía vertical' : 'Guía horizontal') + ' · ' + Math.round(g.pos) + ' px';
            if (g.eje === 'v') {
                el.style.left = (x0 + g.pos * zoom) + 'px';
                el.style.top = y0 + 'px';
                el.style.height = (C.alto * zoom) + 'px';
            } else {
                el.style.top = (y0 + g.pos * zoom) + 'px';
                el.style.left = x0 + 'px';
                el.style.width = (C.ancho * zoom) + 'px';
            }
            ov.appendChild(el);
        });
        pintarListaGuias();
    }

    function pintarListaGuias() {
        var lista = $('ed-guias-lista');
        if (!lista) return;
        lista.innerHTML = '';
        if (!guias.length) {
            lista.innerHTML = '<li class="text-sm text-muted">Sin guías. Arrástralas desde las reglas.</li>';
            return;
        }
        guias.forEach(function (g) {
            var li = document.createElement('li');
            li.className = 'ed-guia-item';
            li.dataset.id = g.id;
            li.innerHTML = '<span class="material-symbols-outlined">' + (g.eje === 'v' ? 'vertical_align_center' : 'horizontal_align_center') + '</span>' +
                '<span class="ed-guia-item__eje">' + (g.eje === 'v' ? 'Vertical' : 'Horizontal') + '</span>' +
                '<input type="number" step="1" value="' + Math.round(g.pos) + '" aria-label="Posición de la guía">' +
                '<span class="text-sm text-muted">px</span>' +
                '<button type="button" data-op="borrar" title="Quitar guía"><span class="material-symbols-outlined">close</span></button>';
            lista.appendChild(li);
        });
    }

    function posMundo(ev) {
        var sr = cont.getBoundingClientRect();
        return {
            x: Math.max(0, Math.min(C.ancho, (ev.clientX - sr.left) / zoom)),
            y: Math.max(0, Math.min(C.alto, (ev.clientY - sr.top) / zoom))
        };
    }

    function arrastrarGuia(ev, eje, id) {
        ev.preventDefault();
        ev.stopPropagation();
        var existente = null;
        if (id) existente = guias.filter(function (g) { return g.id === id; })[0];
        antes();
        if (!existente) {
            existente = { id: 'g' + (++contadorGuia), eje: eje, pos: eje === 'v' ? posMundo(ev).x : posMundo(ev).y };
            guias.push(existente);
        }
        var sr = cont.getBoundingClientRect();
        function mover(e2) {
            var p = posMundo(e2);
            existente.pos = eje === 'v' ? p.x : p.y;
            pintarGuias();
        }
        function soltar(e2) {
            document.removeEventListener('pointermove', mover);
            document.removeEventListener('pointerup', soltar);
            var p = posMundo(e2);
            var fuera = e2.clientX < sr.left - 25 || e2.clientX > sr.right + 25 || e2.clientY < sr.top - 25 || e2.clientY > sr.bottom + 25;
            if (fuera) {
                guias = guias.filter(function (g) { return g !== existente; });
            }
            guardarGuias();
            pintarGuias();
            marcar();
        }
        document.addEventListener('pointermove', mover);
        document.addEventListener('pointerup', soltar, { once: true });
        pintarGuias();
    }

    $('ed-regla-h').addEventListener('pointerdown', function (e) {
        if (e.button === 0) arrastrarGuia(e, 'h', null);
    });
    $('ed-regla-v').addEventListener('pointerdown', function (e) {
        if (e.button === 0) arrastrarGuia(e, 'v', null);
    });

    $('ed-guias').addEventListener('pointerdown', function (e) {
        var el = e.target.closest('.ed-guia');
        if (!el || e.button !== 0) return;
        arrastrarGuia(e, el.classList.contains('ed-guia--v') ? 'v' : 'h', el.dataset.id);
    });

    $('ed-guias-lista').addEventListener('change', function (e) {
        var inp = e.target.closest('input');
        if (!inp) return;
        var li = inp.closest('.ed-guia-item');
        var g = guias.filter(function (x) { return x.id === li.dataset.id; })[0];
        if (!g) return;
        antes();
        g.pos = Math.max(0, Math.min(g.eje === 'v' ? C.ancho : C.alto, parseFloat(inp.value) || 0));
        guardarGuias();
        pintarGuias();
        marcar();
    });
    $('ed-guias-lista').addEventListener('click', function (e) {
        var b = e.target.closest('[data-op="borrar"]');
        if (!b) return;
        var li = b.closest('.ed-guia-item');
        antes();
        guias = guias.filter(function (g) { return g.id !== li.dataset.id; });
        guardarGuias();
        pintarGuias();
        marcar();
    });

    $('ed-guia-v').addEventListener('click', function () {
        antes();
        guias.push({ id: 'g' + (++contadorGuia), eje: 'v', pos: C.ancho / 2 });
        guardarGuias(); pintarGuias(); marcar();
    });
    $('ed-guia-h').addEventListener('click', function () {
        antes();
        guias.push({ id: 'g' + (++contadorGuia), eje: 'h', pos: C.alto / 2 });
        guardarGuias(); pintarGuias(); marcar();
    });
    $('ed-guias-x').addEventListener('click', function () {
        if (!guias.length) return;
        antes();
        guias = [];
        guardarGuias(); pintarGuias(); marcar();
    });

    function pasoRegla() {
        var objetivo = 68 / zoom;
        var pot = Math.pow(10, Math.floor(Math.log(objetivo) / Math.LN10));
        var mult = objetivo / pot;
        var paso = (mult <= 1 ? 1 : mult <= 2 ? 2 : mult <= 5 ? 5 : 10) * pot;
        return Math.max(1, paso);
    }

    function dibujarReglas() {
        var ch = $('ed-regla-h'), cv = $('ed-regla-v'), esq = $('ed-regla-esq');
        if (!ch || !cv) return;
        var on = reglasOn();
        ch.hidden = cv.hidden = esq.hidden = !on;
        if (!on) return;
        var sr = cont.getBoundingClientRect();
        var lr = lienzo.getBoundingClientRect();
        var x0 = sr.left - lr.left, y0 = sr.top - lr.top;
        var W = lienzo.clientWidth, H = lienzo.clientHeight;
        var dpr = window.devicePixelRatio || 1;

        [ch, cv].forEach(function (c) {
            var w = c === ch ? W : 24, h = c === ch ? 24 : H;
            c.width = Math.round(w * dpr); c.height = Math.round(h * dpr);
            c.style.width = w + 'px'; c.style.height = h + 'px';
            var ctx = c.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.clearRect(0, 0, w, h);
            ctx.fillStyle = '#f1f5f9';
            ctx.fillRect(0, 0, w, h);
            ctx.strokeStyle = '#94a3b8';
            ctx.fillStyle = '#475569';
            ctx.font = '10px Inter, Arial, sans-serif';
            ctx.lineWidth = 1;
            var paso = pasoRegla();
            var inicio = Math.floor(0 / paso) * paso;
            var fin = (c === ch ? C.ancho : C.alto) + paso;
            for (var v = inicio; v <= fin; v += paso) {
                var pos = (c === ch ? x0 : y0) + v * zoom;
                if (pos < 0 || pos > (c === ch ? W : H)) continue;
                ctx.beginPath();
                if (c === ch) { ctx.moveTo(pos, 14); ctx.lineTo(pos, 24); }
                else { ctx.moveTo(14, pos); ctx.lineTo(24, pos); }
                ctx.stroke();
                if (c === ch) ctx.fillText(String(Math.round(v)), pos + 2, 11);
                else {
                    ctx.save();
                    ctx.translate(11, pos + 2);
                    ctx.rotate(-Math.PI / 2);
                    ctx.fillText(String(Math.round(v)), 0, 0);
                    ctx.restore();
                }
            }
            ctx.strokeStyle = '#cbd5e1';
            if (c === ch) { ctx.beginPath(); ctx.moveTo(0, 23.5); ctx.lineTo(W, 23.5); ctx.stroke(); }
            else { ctx.beginPath(); ctx.moveTo(23.5, 0); ctx.lineTo(23.5, H); ctx.stroke(); }
        });
        esq.textContent = 'px';
    }

    // ------------------------------------------------------------------
    // Zoom y vista
    // ------------------------------------------------------------------
    function aplicarZoom() {
        stage.width(Math.round(C.ancho * zoom));
        stage.height(Math.round(C.alto * zoom));
        stage.scale({ x: zoom, y: zoom });
        marco.style.width = Math.round(C.ancho * zoom) + 'px';
        marco.style.height = Math.round(C.alto * zoom) + 'px';
        stage.batchDraw();
        $('ed-zoom-valor').textContent = Math.round(zoom * 100) + '%';
        pintarGuias();
        dibujarReglas();
    }

    function ajustar() {
        var w = lienzo.clientWidth - 90;
        var h = lienzo.clientHeight - 90;
        zoom = Math.max(0.08, Math.min(1.6, Math.min(w / C.ancho, h / C.alto)));
        aplicarZoom();
    }

    document.querySelectorAll('[data-zoom]').forEach(function (b) {
        b.addEventListener('click', function () {
            ajusteAuto = false;
            zoom = Math.max(0.08, Math.min(4, zoom + (parseInt(b.dataset.zoom, 10) > 0 ? 0.15 : -0.15)));
            aplicarZoom();
        });
    });
    $('ed-zoom-valor').addEventListener('click', function () { ajusteAuto = true; ajustar(); });

    function limitarZoom(z) {
        return Math.max(0.08, Math.min(4, z));
    }

    /* Acerca o aleja manteniendo bajo el cursor el mismo punto de la hoja. */
    function zoomEnPunto(clientX, clientY, factor) {
        var nuevo = limitarZoom(zoom * factor);
        if (Math.abs(nuevo - zoom) < 0.0001) return;
        var sr = cont.getBoundingClientRect();
        var wx = (clientX - sr.left) / zoom;
        var wy = (clientY - sr.top) / zoom;
        ajusteAuto = false;
        zoom = nuevo;
        aplicarZoom();
        var sr2 = cont.getBoundingClientRect();
        scroll.scrollLeft += (sr2.left + wx * zoom) - clientX;
        scroll.scrollTop += (sr2.top + wy * zoom) - clientY;
    }

    function deltaRueda(e) {
        var m = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? scroll.clientHeight : 1;
        return { x: e.deltaX * m, y: e.deltaY * m };
    }

    lienzo.addEventListener('wheel', function (e) {
        if (e.shiftKey) {
            // Mayús + rueda: desplazamiento horizontal rápido.
            e.preventDefault();
            var d = deltaRueda(e);
            scroll.scrollLeft += Math.abs(d.y) > Math.abs(d.x) ? d.y : d.x;
            return;
        }
        // La rueda siempre acerca/aleja en el cursor, con cualquier herramienta.
        e.preventDefault();
        zoomEnPunto(e.clientX, e.clientY, Math.exp(-e.deltaY * 0.0015));
    }, { passive: false });

    /* Arrastrar con el botón central o el derecho desplaza el lienzo, con
       cualquier herramienta activa. También con el izquierdo en modo mano. */
    scroll.addEventListener('pointerdown', function (e) {
        var izqPaneo = herramienta === 'paneo' && e.button === 0;
        var centralDerecho = e.button === 1 || e.button === 2;
        if (!izqPaneo && !centralDerecho) return;
        // OJO: e.buttons NO coloca el botón en su propio bit (izq=bit0/1,
        // der=bit1/2, central=bit2/4). Con «1 << e.button» el central y el
        // derecho no cuadraban y el arrastre se abortaba sin empezar.
        var mascaraBoton = e.button === 0 ? 1 : (e.button === 1 ? 4 : 2);
        if (e.pointerType === 'mouse' && !(e.buttons & mascaraBoton)) return;
        e.preventDefault();
        e.stopPropagation();
        paneo = { x: e.clientX, y: e.clientY, left: scroll.scrollLeft, top: scroll.scrollTop, movido: false, btn: e.button };
        scroll.classList.add('paneando');
        stage.container().style.cursor = 'grabbing';
        // Botón derecho sobre el lienzo: el navegador emite 'contextmenu' casi
        // al pulsar (1 ms después, ANTES de cualquier movimiento), así que no
        // da tiempo a saber si habrá arrastre. Se cancela aquí mismo y el
        // derecho queda reservado para el paneo; fuera del lienzo el menú
        // contextual del navegador sigue funcionando con normalidad.
        if (e.button === 2) {
            ctxBloqueado = true;
            clearTimeout(ctxTimer);
        }
        try { scroll.setPointerCapture(e.pointerId); } catch (err) { /* sin captura */ }
    }, true);
    // El navegador entra en «paneo automático» al pulsar la rueda (mousedown del
    // botón central): se cancela para que mande el arrastre del editor.
    scroll.addEventListener('mousedown', function (e) {
        if (e.button === 1 || e.button === 2) e.preventDefault();
    }, true);
    window.addEventListener('pointermove', function (e) {
        if (!paneo) return;
        e.preventDefault();
        if (!paneo.movido && Math.abs(e.clientX - paneo.x) + Math.abs(e.clientY - paneo.y) > 2) {
            paneo.movido = true;
            // Arrastre real con el derecho → no debe abrirse el menú contextual.
            if (paneo.btn === 2) ctxBloqueado = true;
        }
        scroll.scrollLeft = paneo.left - (e.clientX - paneo.x);
        scroll.scrollTop = paneo.top - (e.clientY - paneo.y);
    }, { passive: false });
    /* Al soltar (o si el navegador cancela el puntero) se termina el paneo.
       OJO: addEventListener NO admite varios tipos separados por espacios en
       este navegador — «'pointerup pointercancel'» no se registraba para nada
       y el estado (clase «paneando» y paneo) se quedaba colgado tras soltar. */
    function soltarPaneo() {
        if (!paneo) return;
        // El 'contextmenu' del botón derecho llega enseguida: se mantiene la
        // marca unos ms para poder cancelarlo (si no hubo arrastre, no se puso).
        if (ctxBloqueado) {
            clearTimeout(ctxTimer);
            ctxTimer = setTimeout(function () { ctxBloqueado = false; }, 400);
        }
        paneo = null;
        scroll.classList.remove('paneando');
        restaurarCursor();
    }
    window.addEventListener('pointerup', soltarPaneo);
    window.addEventListener('pointercancel', soltarPaneo);
    // El menú contextual se suprime sólo cuando el derecho se pulsó sobre el
    // lienzo (flag del pointerdown); en el resto de la página no se toca.
    window.addEventListener('contextmenu', function (e) {
        if (!ctxBloqueado) return;
        e.preventDefault();
        ctxBloqueado = false;
    });

    scroll.addEventListener('scroll', function () { pintarGuias(); dibujarReglas(); });

    var redim = null;
    window.addEventListener('resize', function () {
        clearTimeout(redim);
        redim = setTimeout(function () {
            if (ajusteAuto) ajustar(); else { pintarGuias(); dibujarReglas(); }
        }, 120);
    });

    $('ed-panel-toggle').addEventListener('click', function () {
        pagina.classList.toggle('ed-pagina--sin-panel');
        var abierto = !pagina.classList.contains('ed-pagina--sin-panel');
        this.setAttribute('aria-pressed', String(abierto));
        setTimeout(function () { if (ajusteAuto) ajustar(); else { pintarGuias(); dibujarReglas(); } }, 80);
    });

    $('ed-maximizar').addEventListener('click', function () {
        pagina.classList.toggle('ed-pagina--amplio');
        var amplio = pagina.classList.contains('ed-pagina--amplio');
        this.setAttribute('aria-pressed', String(amplio));
        this.querySelector('.material-symbols-outlined').textContent = amplio ? 'fullscreen_exit' : 'fullscreen';
        setTimeout(function () { if (ajusteAuto) ajustar(); else { pintarGuias(); dibujarReglas(); } }, 80);
    });

    // ------------------------------------------------------------------
    // Láminas
    // ------------------------------------------------------------------
    function pintarPaginas() {
        var lista = $('ed-paginas');
        lista.innerHTML = '';
        paginas.forEach(function (p, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ed-pagina-item' + (i === actual ? ' activa' : '');
            b.dataset.i = i;
            b.title = p.nombre || ('Lámina ' + (i + 1));
            b.innerHTML = '<span class="ed-pagina-num">' + (i + 1) + '</span>'
                + (p.thumb ? '<img src="' + p.thumb + '" alt="">' : '<span class="material-symbols-outlined">description</span>')
                + '<span class="ed-pagina-nom">' + esc(p.nombre || ('Lámina ' + (i + 1))) + '</span>';
            b.addEventListener('click', function () { irPagina(i); });
            b.addEventListener('dblclick', function () {
                pedirTexto({
                    sobreTitulo: 'Lámina',
                    titulo: 'Nombre de la lámina',
                    etiqueta: 'Nombre',
                    predeterminado: p.nombre || ('Lámina ' + (i + 1)),
                    maximo: 60,
                    textoConfirmar: 'Guardar'
                }).then(function (n) {
                    if (n && n.trim()) { p.nombre = n.trim().slice(0, 60); pintarPaginas(); marcar(); }
                });
            });
            lista.appendChild(b);
        });
    }

    function irPagina(i) {
        if (i === actual || i < 0 || i >= paginas.length) return;
        guardarPaginaActual();
        actual = i;
        cargarCapa(paginas[i].json).then(function () {
            tr.nodes([]);
            selNodos = [];
            pintarPaginas();
            if (ajusteAuto) ajustar();
        });
    }

    $('ed-pagina-nueva').addEventListener('click', function () {
        guardarPaginaActual();
        paginas.push({ id: 0, nombre: 'Lámina ' + (paginas.length + 1), json: '', thumb: null });
        actual = paginas.length - 1;
        capaActivaId = null;
        cargarCapa('').then(function () { pintarPaginas(); marcar(); });
    });

    $('ed-pagina-copiar').addEventListener('click', function () {
        guardarPaginaActual();
        var copia = { id: 0, nombre: (paginas[actual].nombre || 'Lámina') + ' (copia)', json: paginas[actual].json, thumb: paginas[actual].thumb };
        paginas.splice(actual + 1, 0, copia);
        actual++;
        cargarCapa(copia.json).then(function () { pintarPaginas(); marcar(); });
    });

    $('ed-pagina-izq').addEventListener('click', function () { moverPagina(-1); });
    $('ed-pagina-der').addEventListener('click', function () { moverPagina(1); });

    function moverPagina(dir) {
        var j = actual + dir;
        if (j < 0 || j >= paginas.length) return;
        guardarPaginaActual();
        var tmp = paginas[actual];
        paginas[actual] = paginas[j];
        paginas[j] = tmp;
        actual = j;
        pintarPaginas();
        marcar();
    }

    $('ed-pagina-borrar').addEventListener('click', function () {
        if (paginas.length <= 1) {
            // Aviso con toast (nunca window.alert).
            crearAlerta('advertencia', 'El documento debe tener al menos una lámina.', 'Lámina obligatoria');
            estado('El documento debe tener al menos una lámina', true);
            return;
        }
        confirmarAccion({
            sobreTitulo: 'Láminas',
            titulo: '¿Eliminar la lámina ' + (actual + 1) + '?',
            mensaje: 'Se quitará del documento y no se puede deshacer.',
            textoConfirmar: 'Eliminar lámina',
            alConfirmar: function () {
                paginas.splice(actual, 1);
                if (actual >= paginas.length) actual = paginas.length - 1;
                capaActivaId = null;
                cargarCapa(paginas[actual].json).then(function () { pintarPaginas(); marcar(); });
            }
        });
    });

    // ------------------------------------------------------------------
    // Imágenes
    // ------------------------------------------------------------------
    function subirImagen(archivo, alTerminar) {
        var fd = new FormData();
        fd.append('id', C.id);
        fd.append('imagen', archivo, archivo.name || 'imagen.png');
        estado('Subiendo imagen…');
        fetch(C.api.imagen, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { estado(d.error || 'No se pudo subir la imagen', true); return; }
                estado('');
                alTerminar(d.src);
            })
            .catch(function () { estado('No se pudo subir la imagen', true); });
    }

    function insertarImagen(src, p) {
        cargarImagen(src).then(function (im) {
            var maxW = C.ancho * 0.5;
            var w = im ? Math.min(im.width, maxW) : 360;
            var h = im ? w * im.height / im.width : 240;
            antes();
            var nodo = new Konva.Image({
                x: (p ? p.x : C.ancho / 2) - w / 2,
                y: (p ? p.y : C.alto / 2) - h / 2,
                width: w, height: h, image: im || undefined, draggable: true, src: src
            });
            if (agregarObjeto(nodo)) { seleccionar([nodo]); marcar(); }
            capaContenido.draw();
        });
    }

    archivoImg.addEventListener('change', function () {
        var f = this.files[0];
        this.value = '';
        if (!f) return;
        var p = posNuevaImagen;
        posNuevaImagen = null;
        subirImagen(f, function (src) { insertarImagen(src, p); });
        activar('select');
    });

    document.addEventListener('paste', function (e) {
        var items = (e.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image/') === 0) {
                var f = items[i].getAsFile();
                if (f) {
                    e.preventDefault();
                    subirImagen(f, function (src) { insertarImagen(src, null); });
                }
                break;
            }
        }
    });

    // ------------------------------------------------------------------
    // Exportar
    // ------------------------------------------------------------------
    function descargarBlob(blob, archivo) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = archivo;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }

    function nombreArchivo(ext) {
        return (nombre.value.trim() || C.nombre || 'documento').replace(/[\\/:*?"<>|]+/g, '_') + '.' + ext;
    }

    function exportarPdf(imprimir) {
        estado('Generando PDF…');
        var ventana = imprimir ? window.open('', '_blank') : null;
        guardar(false).then(function () {
            return Promise.all(paginas.map(function (p) { return renderUrl(p.json, 2); })).then(function (urls) {
                var fd = new FormData();
                fd.append('id', C.id);
                fd.append('version', version);
                fd.append('nombre', nombre.value.trim() || C.nombre);
                fd.append('paginas', JSON.stringify(urls));
                return fetch(C.api.pdf, { method: 'POST', body: fd }).then(function (r) {
                    if (!r.ok) throw new Error('pdf');
                    return r.blob();
                });
            });
        }).then(function (blob) {
            if (!blob || !blob.size) throw new Error('pdf');
            var url = URL.createObjectURL(blob);
            if (ventana) ventana.location.href = url;
            else descargarBlob(blob, nombreArchivo('pdf'));
            estado('PDF generado');
            setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
        }).catch(function () {
            if (ventana) ventana.close();
            estado('No se pudo generar el PDF', true);
        });
    }

    document.querySelectorAll('[data-exportar]').forEach(function (b) {
        b.addEventListener('click', function () {
            var tipo = b.dataset.exportar;
            var menu = b.closest('details');
            if (menu) menu.open = false;
            if (tipo === 'pdf') exportarPdf(false);
            else if (tipo === 'imprimir') exportarPdf(true);
            else if (tipo === 'png') {
                guardarPaginaActual();
                estado('Generando PNG…');
                renderUrl(paginas[actual].json, 2).then(function (url) {
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = nombreArchivo('png');
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    estado('PNG generado');
                });
            }
        });
    });

    // ------------------------------------------------------------------
    // Fondo
    // ------------------------------------------------------------------
    $('ed-fondo-color').addEventListener('input', function () {
        FONDO.color = this.value;
        redibujarFondo(); marcar();
    });
    $('ed-fondo').addEventListener('change', function () {
        FONDO.tipo = this.value;
        redibujarFondo(); marcar();
    });
    $('ed-fondo-paso').addEventListener('change', function () {
        FONDO.paso = Math.max(8, Math.min(120, parseInt(this.value, 10) || 40));
        redibujarFondo(); marcar();
    });
    $('ed-fondo-linea').addEventListener('input', function () {
        FONDO.linea = this.value;
        redibujarFondo(); marcar();
    });

    ['ed-cuadricula', 'ed-snap', 'ed-reglas-on', 'ed-guias-on'].forEach(function (id) {
        var el = $(id);
        if (!el) return;
        el.addEventListener('change', function () {
            if (id === 'ed-cuadricula') { CUADRICULA = el.checked; redibujarFondo(); }
            if (id === 'ed-snap') SNAP = el.checked;
            if (id === 'ed-reglas-on') dibujarReglas();
            if (id === 'ed-guias-on') pintarGuias();
        });
    });

    // Pestañas del panel lateral
    function irATab(nombreTab) {
        document.querySelectorAll('[data-tab]').forEach(function (x) {
            x.classList.toggle('activa', x.dataset.tab === nombreTab);
            x.setAttribute('aria-selected', String(x.dataset.tab === nombreTab));
        });
        document.querySelectorAll('.ed-panel[data-panel]').forEach(function (p) {
            p.hidden = p.dataset.panel !== nombreTab;
        });
    }
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.addEventListener('click', function () { irATab(b.dataset.tab); });
    });

    // Cerrar los menús flotantes al hacer clic fuera de ellos.
    document.addEventListener('pointerdown', function (e) {
        ['ed-mas', 'gr-descargar'].forEach(function (id) {
            var det = id === 'ed-mas' ? $('ed-mas') : document.querySelector('.gr-descargar');
            if (det && det.open && !det.contains(e.target)) det.open = false;
        });
    }, true);

    // Lista de capas
    $('ed-capas-lista').addEventListener('click', function (e) {
        var b = e.target.closest('[data-op]');
        if (!b) return;
        var li = b.closest('.ed-capa-item');
        if (li) accionCapa(li.dataset.id, b.dataset.op);
    });
    $('ed-capas-lista').addEventListener('dblclick', function (e) {
        var n = e.target.closest('.ed-capa-nombre');
        if (!n) return;
        var li = n.closest('.ed-capa-item');
        var c = capaPorId(li.dataset.id);
        if (!c) return;
        pedirTexto({
            sobreTitulo: 'Capas',
            titulo: 'Nombre de la capa',
            etiqueta: 'Nombre',
            predeterminado: c.getAttr('nombre'),
            maximo: 60,
            textoConfirmar: 'Guardar'
        }).then(function (v) {
            if (v && v.trim()) {
                antes();
                c.setAttr('nombre', v.trim().slice(0, 60));
                pintarCapas(); marcar(); actualizarProps();
            }
        });
    });
    $('ed-capa-nueva').addEventListener('click', function () {
        antes();
        var c = nuevaCapa();
        capaActivaId = c.getAttr('capaId');
        pintarCapas();
        actualizarProps();
        marcar();
    });

    // ------------------------------------------------------------------
    // Teclado y portapapeles
    // ------------------------------------------------------------------
    function copiar() {
        portapapeles = selNodos.map(function (n) {
            return { json: n.toJSON(), capa: capaDe(n).getAttr('capaId'), grupo: n.getAttr('grupo') };
        });
    }
    function pegar() {
        if (!portapapeles.length) return;
        antes();
        var nuevos = [];
        var mapaGrupos = {};
        portapapeles.forEach(function (item) {
            var n = Konva.Node.create(item.json);
            n.x(n.x() + 30); n.y(n.y() + 30);
            var g = item.grupo;
            if (g) {
                if (!mapaGrupos[g]) mapaGrupos[g] = 'g' + (++contadorGrupo) + '-' + Date.now();
                n.setAttr('grupo', mapaGrupos[g]);
            }
            n.draggable(true);
            var destino = capaPorId(item.capa) || capaActivaGrupo();
            destino.add(n);
            conectar(n);
            nuevos.push(n);
        });
        hidratar(capaContenido).then(function () { capaContenido.draw(); });
        seleccionar(nuevos, false);
        pintarCapas();
        marcar();
    }

    document.addEventListener('keydown', function (e) {
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) return;
        var ctrl = e.ctrlKey || e.metaKey;
        var k = (e.key || '').toLowerCase();

        if (ctrl && k === 's') { e.preventDefault(); guardar(true); return; }
        if (ctrl && k === 'z') { e.preventDefault(); if (e.shiftKey) rehacer(); else deshacer(); return; }
        if (ctrl && k === 'y') { e.preventDefault(); rehacer(); return; }
        if (ctrl && k === 'd') { e.preventDefault(); duplicarSeleccion(); return; }
        if (ctrl && k === 'c') { e.preventDefault(); copiar(); return; }
        if (ctrl && k === 'v') { e.preventDefault(); pegar(); return; }
        if (ctrl && k === 'g') { e.preventDefault(); if (e.shiftKey) desagrupar(); else agrupar(); return; }
        if (ctrl && (k === 'b' || k === 'i' || k === 'u')) {
            if (selNodos.length && selNodos[0].getClassName() === 'Text') {
                e.preventDefault();
                var tipo = { b: 'bold', i: 'italic', u: 'underline' }[k];
                document.querySelector('[data-estilo="' + tipo + '"]').click();
            }
            return;
        }
        if (e.key === 'F8') { e.preventDefault(); var o = $('ed-orto'); o.checked = !o.checked; return; }
        if (e.key === 'Enter' && poli) { e.preventDefault(); terminarPoli(); return; }
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (selNodos.length) { e.preventDefault(); borrarSeleccion(); }
            return;
        }
        if (e.key === 'Escape') {
            if (poli) { poli.n.destroy(); poli = null; capaContenido.batchDraw(); }
            deseleccionar(); activar('select'); return;
        }
        if (e.key === 'PageUp') { e.preventDefault(); irPagina(actual - 1); return; }
        if (e.key === 'PageDown') { e.preventDefault(); irPagina(actual + 1); return; }

        var mapa = { v: 'select', h: 'paneo', t: 'texto', r: 'rect', o: 'elipse', l: 'linea', f: 'flecha', p: 'lapiz', i: 'imagen', m: 'medir' };
        if (!ctrl && !e.altKey && mapa[k]) { e.preventDefault(); activar(mapa[k]); return; }

        if (selNodos.length && e.key.indexOf('Arrow') === 0) {
            e.preventDefault();
            var d = e.shiftKey ? 10 : 1;
            antes();
            selNodos.forEach(function (n) {
                if (e.key === 'ArrowLeft') n.x(n.x() - d);
                if (e.key === 'ArrowRight') n.x(n.x() + d);
                if (e.key === 'ArrowUp') n.y(n.y() - d);
                if (e.key === 'ArrowDown') n.y(n.y() + d);
            });
            capaContenido.batchDraw();
            marcar();
            actualizarProps();
        }
    });

    $('ed-guardar').addEventListener('click', function () { guardar(true); });
    nombre.addEventListener('input', function () { marcar(); });

    window.addEventListener('beforeunload', function (e) {
        if (!sucio) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ------------------------------------------------------------------
    // Arranque
    // ------------------------------------------------------------------
    redibujarFondo().then(function () {
        return cargarCapa(paginas[actual].json);
    }).then(function () {
        pintarPaginas();
        pintarCapas();
        pintarGuias();
        ajustar();
        estado('Listo');
    });
})();
