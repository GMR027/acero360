/**
 * modulos/archivosModal.js
 * ---------------------------------------------------------------------------
 * Subida de archivos desde la ventana modal (el mismo `modal__panel` que abre
 * «Registro Venta Inteligente»).
 *
 * Cada botón [data-archivo-modal="id-del-input"] abre el diálogo con el bloque
 * <div class="campo"> REAL: se mueve al modal y, al cerrar, vuelve a su hueco,
 * de modo que los archivos elegidos siguen viajando con el formulario. Para eso
 * el <input type="file"> trae el atributo form="…", indispensable porque el
 * diálogo vive fuera del <form>.
 *
 * Sin JavaScript el botón permanece oculto y el campo queda en línea, como
 * siempre: el módulo sólo lo sustituye cuando hay modal disponible.
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, sin alert, ni
 * confirm, ni prompt.
 */

/**
 * Resume junto al botón los archivos elegidos («2 archivos elegidos: …»).
 *
 * @param {HTMLInputElement} campo
 * @param {HTMLElement|null} resumen  Nodo [data-archivo-resumen] del botón.
 */
function resumirArchivos(campo, resumen) {
  if (!resumen) {
    return;
  }

  const nombres = campo.files
    ? Array.prototype.map.call(campo.files, function (archivo) { return archivo.name; })
    : [];

  if (!nombres.length) {
    resumen.textContent = '';
    resumen.hidden = true;
    return;
  }

  resumen.textContent = nombres.length === 1
    ? '1 archivo elegido: ' + nombres[0]
    : nombres.length + ' archivos elegidos: ' + nombres.join(' · ');
  resumen.hidden = false;
}

/** Prepara todos los botones [data-archivo-modal] de la página. */
function prepararArchivosModal() {
  document.querySelectorAll('[data-archivo-modal]').forEach(function (boton) {
    const campo = document.getElementById(boton.getAttribute('data-archivo-modal'));
    if (!campo) {
      return;
    }

    const bloque = campo.closest('.campo') || campo.parentNode;
    const hueco = { padre: bloque.parentNode, siguiente: bloque.nextSibling };
    const contenedor = boton.parentNode;
    const resumen = contenedor ? contenedor.querySelector('[data-archivo-resumen]') : null;
    const etiqueta = bloque.querySelector('.campo__etiqueta');

    campo.addEventListener('change', function () {
      resumirArchivos(campo, resumen);
    });

    // Con JavaScript el botón sustituye al campo en línea (sin él no se llega aquí).
    bloque.hidden = true;
    boton.hidden = false;

    boton.addEventListener('click', function (evento) {
      evento.preventDefault();

      if (typeof abrirModal !== 'function') {
        // Sin ventana modal disponible se vuelve al campo de la página.
        bloque.hidden = false;
        boton.hidden = true;
        return;
      }

      bloque.hidden = false;
      abrirModal({
        sobreTitulo: boton.getAttribute('data-archivo-sobre') || '',
        titulo: boton.getAttribute('data-archivo-titulo')
          || (etiqueta ? etiqueta.textContent.trim() : 'Subir archivos'),
        cuerpo: bloque,
        textoConfirmar: 'Listo',
        alConfirmar: function () {
          // Los archivos ya quedaron en el campo: el diálogo sólo se cierra.
        },
        alCerrar: function () {
          // El bloque vuelve a su hueco ANTES de que el modal vacíe su cuerpo.
          hueco.padre.insertBefore(bloque, hueco.siguiente);
          bloque.hidden = true;
          boton.hidden = false;
          resumirArchivos(campo, resumen);
        },
      });
    });
  });
}
