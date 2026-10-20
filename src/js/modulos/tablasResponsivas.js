/**
 * tablasResponsivas.js — etiqueta cada celda con el encabezado de su columna.
 *
 * En celular el CSS convierte cada fila en una tarjeta y muestra esa etiqueta
 * (data-label) delante de cada dato. En escritorio no tiene ningún efecto
 * visual. Se omite la tabla de renglones del cotizador (sus filas las arma
 * cotizador.js con su propio data-label) y las que declaran data-sin-tarjetas.
 */
function etiquetarTablas() {
  document.querySelectorAll('table.tabla:not(#tabla-renglones):not([data-sin-tarjetas])').forEach(function (tabla) {
    const encabezados = Array.from(tabla.querySelectorAll('thead tr:last-child th')).map(function (th) {
      const oculto = th.querySelector('.solo-lectores');
      return oculto && th.textContent.trim() === oculto.textContent.trim() ? '' : th.textContent.trim();
    });

    tabla.querySelectorAll('tbody tr, tfoot tr').forEach(function (fila) {
      let columna = 0;
      Array.from(fila.children).forEach(function (celda) {
        const abarca = Number.parseInt(celda.getAttribute('colspan') || '1', 10) || 1;
        if (celda.tagName === 'TD') {
          celda.setAttribute('data-label', abarca > 1 ? '' : (encabezados[columna] || ''));
        }
        columna += abarca;
      });
    });
  });
}

document.addEventListener('DOMContentLoaded', etiquetarTablas);
