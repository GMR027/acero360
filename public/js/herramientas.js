/* ==========================================================================
   herramientas.js — Puente entre las herramientas portadas y Acero 360.

   · Añade el token CSRF a los fetch POST (la aplicación lo exige).
   · Traduce los iconos de Font Awesome que usan las vistas portadas a
     Material Symbols, para no cargar dos familias de iconos.
   · Implementa confirmarModal() con el modal estilizado de la aplicación
     (nunca con window.confirm) y ofrece pedirTexto() en lugar de prompt().
   · Maneja los formularios con data-confirm y el cambio de carpeta.
   ========================================================================== */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf"]');
    var token = meta ? meta.content : '';

    // ---------- fetch con CSRF ----------
    if (window.fetch && token) {
        var fetchOriginal = window.fetch.bind(window);
        window.fetch = function (entrada, opciones) {
            opciones = opciones || {};
            var metodo = String(opciones.method || 'GET').toUpperCase();
            if (metodo === 'POST') {
                var cabeceras = new Headers(opciones.headers || {});
                if (!cabeceras.has('X-CSRF-Token')) cabeceras.set('X-CSRF-Token', token);
                opciones.headers = cabeceras;
                if (!opciones.credentials) opciones.credentials = 'same-origin';
            }
            return fetchOriginal(entrada, opciones);
        };
    }

    // ---------- confirmación y peticiones de texto (modal estilizado) ----------
    // confirmarModal() y pedirTexto() viven en modulos/modal.js (app.js), que se
    // carga después que este archivo: sólo se usan dentro de manejadores de
    // eventos, cuando app.js ya está ejecutado.

    window.confirmarModal = function (opciones) {
        var o = opciones || {};
        return new Promise(function (resolver) {
            confirmarAccion({
                sobreTitulo: o.sobreTitulo || 'Confirmación',
                titulo: o.titulo || '¿Continuar?',
                mensaje: o.mensaje || '',
                textoConfirmar: o.aceptar || o.textoConfirmar || 'Sí, continuar',
                alConfirmar: function () { resolver(true); },
                alCerrar: function () { resolver(false); }
            });
        });
    };

    /* Se registra al terminar la carga (y no al parsear): así el manejador de
       validación de app.js ya está colocado y, si rechaza el envío, este
       escuchador lo ve con e.defaultPrevented y no llega a preguntar. */
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (form.dataset.confirmado) return;
                // Si app.js ya rechazó el envío (campos sin validar), no se pregunta.
                if (e.defaultPrevented) return;
                e.preventDefault();
                var partes = String(form.dataset.confirm || '').split('|');
                confirmarAccion({
                    sobreTitulo: 'Confirmación',
                    titulo: partes[0] || '¿Continuar?',
                    mensaje: partes[1] || 'Esta acción no se puede deshacer.',
                    textoConfirmar: partes[2] || 'Sí, continuar',
                    alConfirmar: function () {
                        form.dataset.confirmado = '1';
                        form.submit();   // submit() nativo: no vuelve a disparar el evento
                    }
                });
            });
        });
    });

    // ---------- carpetas ----------
    document.querySelectorAll('.carpeta-mover').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (!sel.value) return;
            var carpeta = sel.value;
            if (carpeta === '__nueva') {
                sel.value = '';
                pedirTexto({
                    titulo: 'Carpeta nueva',
                    mensaje: 'Se creará al mover el elemento dentro de ella.',
                    etiqueta: 'Nombre de la carpeta',
                    textoConfirmar: 'Crear y mover'
                }).then(function (nombre) {
                    nombre = (nombre || '').trim();
                    if (!nombre) return;
                    enviar('/admin/' + sel.dataset.herramienta + '/mover', { id: sel.dataset.id, carpeta: nombre });
                });
                return;
            }
            enviar('/admin/' + sel.dataset.herramienta + '/mover', { id: sel.dataset.id, carpeta: carpeta });
        });
    });

    document.querySelectorAll('[data-carpeta-renombrar]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            var anterior = boton.dataset.carpetaRenombrar || '';
            pedirTexto({
                titulo: 'Renombrar carpeta',
                mensaje: 'Si otra carpeta usa el mismo nombre, se unirán en una sola.',
                etiqueta: 'Nombre de la carpeta',
                predeterminado: anterior,
                textoConfirmar: 'Guardar'
            }).then(function (nueva) {
                nueva = (nueva || '').trim();
                if (!nueva || nueva === anterior) return;
                enviar('/admin/' + boton.dataset.herramienta + '/renombrar', { de: anterior, a: nueva });
            });
        });
    });

    function enviar(url, datos) {
        var cuerpo = new URLSearchParams(datos);
        window.fetch(url, {
            method: 'POST',
            body: cuerpo,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        }).then(function (r) { return r.json().catch(function () { return { success: r.ok }; }); })
          .then(function (d) {
              if (d && d.success === false) {
                  crearAlerta('error', d.error || 'No se pudo completar la acción', 'No se pudo completar');
                  return;
              }
              window.location.reload();
          })
          .catch(function () { crearAlerta('error', 'No se pudo completar la acción', 'Sin respuesta'); });
    }

    // ---------- iconos Font Awesome → Material Symbols ----------
    var ICONOS = {
        'a': 'format_color_text',
        'align-center': 'format_align_center',
        'align-left': 'format_align_left',
        'align-right': 'format_align_right',
        'arrow-down': 'arrow_downward',
        'arrow-down-1-9': 'sort',
        'arrow-down-a-z': 'sort_by_alpha',
        'arrow-down-z-a': 'sort_by_alpha',
        'arrow-left': 'arrow_back',
        'arrow-right': 'arrow_forward',
        'arrow-right-to-bracket': 'login',
        'arrow-up': 'arrow_upward',
        'arrow-up-right-from-square': 'open_in_new',
        'arrows-left-right': 'width',
        'arrows-up-down': 'height',
        'asterisk': 'asterisk',
        'ban': 'block',
        'bars-progress': 'trending_up',
        'bars-staggered': 'reorder',
        'bold': 'format_bold',
        'book-open': 'menu_book',
        'border-all': 'border_all',
        'broom': 'cleaning_services',
        'calendar': 'calendar_month',
        'calendar-day': 'event',
        'chart-simple': 'bar_chart',
        'check': 'check',
        'check-double': 'done_all',
        'chevron-left': 'chevron_left',
        'chevron-right': 'chevron_right',
        'circle': 'circle',
        'clock': 'schedule',
        'clock-rotate-left': 'history',
        'clone': 'content_copy',
        'code': 'code',
        'code-compare': 'difference',
        'comment-dots': 'comment',
        'comment-medical': 'add_comment',
        'comments': 'forum',
        'copy': 'content_copy',
        'diagram-project': 'account_tree',
        'dollar-sign': 'attach_money',
        'download': 'download',
        'eraser': 'cleaning_services',
        'expand': 'open_in_full',
        'eye': 'visibility',
        'file': 'description',
        'file-circle-plus': 'note_add',
        'file-csv': 'description',
        'file-excel': 'table_chart',
        'file-export': 'ios_share',
        'file-image': 'image',
        'file-lines': 'article',
        'file-pdf': 'picture_as_pdf',
        'file-pen': 'edit_document',
        'file-word': 'description',
        'fill': 'format_color_fill',
        'fill-drip': 'format_color_fill',
        'floppy-disk': 'save',
        'folder-open': 'folder_open',
        'font': 'text_fields',
        'gear': 'settings',
        'grip-lines': 'drag_handle',
        'hashtag': 'tag',
        'highlighter': 'border_color',
        'house': 'home',
        'image': 'image',
        'italic': 'format_italic',
        'layer-group': 'layers',
        'left-right': 'swap_horiz',
        'link': 'link',
        'list': 'list',
        'list-check': 'checklist',
        'list-ol': 'format_list_numbered',
        'location-crosshairs': 'my_location',
        'magnifying-glass': 'search',
        'microphone': 'mic',
        'minus': 'remove',
        'note-sticky': 'sticky_note_2',
        'object-group': 'select_all',
        'object-ungroup': 'deselect',
        'paintbrush': 'brush',
        'paragraph': 'notes',
        'pen': 'edit',
        'pen-nib': 'ink_pen',
        'pen-ruler': 'design_services',
        'pencil': 'edit',
        'percent': 'percent',
        'play': 'play_arrow',
        'plus': 'add',
        'print': 'print',
        'right-to-bracket': 'logout',
        'rotate': 'rotate_right',
        'rotate-left': 'undo',
        'rotate-right': 'redo',
        'ruler-horizontal': 'straighten',
        'screwdriver-wrench': 'build',
        'shapes': 'category',
        'share-nodes': 'share',
        'signature': 'draw',
        'slash': 'horizontal_rule',
        'square': 'square',
        'square-check': 'check_box',
        'square-root-variable': 'functions',
        'stamp': 'approve',
        'star': 'star',
        'swatchbook': 'palette',
        'table': 'table',
        'table-cells': 'grid_on',
        'table-columns': 'view_column',
        'table-list': 'table_rows',
        'table-rows': 'table_rows',
        'text-width': 'format_size',
        'trash-can': 'delete',
        'traffic-light': 'traffic',
        'underline': 'format_underlined',
        'upload': 'upload',
        'user-pen': 'manage_accounts',
        'wand-magic-sparkles': 'auto_fix_high',
        'window-maximize': 'fullscreen',
        'xmark': 'close'
    };

    function reemplazar(raiz) {
        var iconos = raiz.querySelectorAll ? raiz.querySelectorAll('i.fa-solid, i.fa-regular, i.fa-brands') : [];
        iconos.forEach(function (i) {
            var clases = String(i.className || '').split(/\s+/);
            var nombre = null;
            clases.forEach(function (c) {
                if (c.indexOf('fa-') === 0 && c !== 'fa-solid' && c !== 'fa-regular' && c !== 'fa-brands' && !nombre) {
                    nombre = c.slice(3);
                }
            });
            var material = ICONOS[nombre] || 'widgets';
            var span = document.createElement('span');
            span.className = 'material-symbols-outlined ico-herr';
            span.setAttribute('aria-hidden', 'true');
            span.textContent = material;
            if (i.getAttribute('style')) span.setAttribute('style', i.getAttribute('style'));
            i.replaceWith(span);
        });
    }

    reemplazar(document.body);
    if (window.MutationObserver) {
        new MutationObserver(function (mutaciones) {
            mutaciones.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType === 1) {
                        if (n.matches && n.matches('i.fa-solid, i.fa-regular, i.fa-brands')) reemplazar(n.parentNode || document.body);
                        else reemplazar(n);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
