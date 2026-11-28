/**
 * modulos/proyectos.js
 * ---------------------------------------------------------------------------
 * Formulario de proyectos de entrega:
 *  - agrega y quita partidas (productos + cantidades);
 *  - filtra los productos por el negocio elegido;
 *  - no envía filas vacías.
 *
 * No usa innerHTML: la fila nueva se clona desde un <template>.
 */
(function () {
  'use strict';

  function preparar() {
    const formulario = document.getElementById('form-proyecto');
    if (!formulario) {
      return;
    }

    const contenedor = formulario.querySelector('[data-partidas]');
    const plantilla = document.getElementById('proyecto-partida-plantilla');
    const botonAgregar = formulario.querySelector('[data-agregar-partida]');
    const empresa = document.getElementById('empresa_id');

    if (!contenedor || !plantilla || !botonAgregar) {
      return;
    }

    /** Muestra sólo los productos que se venden en el negocio elegido. */
    function filtrarProductos() {
      const empresaId = empresa ? String(empresa.value || '') : '';
      contenedor.querySelectorAll('[data-partida-producto]').forEach(function (select) {
        let seleccionInvalida = false;

        Array.prototype.forEach.call(select.options, function (opcion) {
          if (!opcion.value) {
            return;
          }
          const empresas = String(opcion.getAttribute('data-empresas') || '').split(',');
          const visible = empresaId === '' || empresas.indexOf(empresaId) !== -1;
          opcion.hidden = !visible;
          opcion.disabled = !visible;
          if (!visible && opcion.selected) {
            seleccionInvalida = true;
          }
        });

        if (seleccionInvalida) {
          select.value = '';
        }
      });
    }

    /** Agrega una partida nueva al final. */
    function agregarPartida() {
      const copia = plantilla.content.cloneNode(true);
      const fila = copia.querySelector('[data-partida]');
      contenedor.appendChild(copia);
      filtrarProductos();

      const select = fila ? fila.querySelector('[data-partida-producto]') : null;
      if (select) {
        select.focus();
      }
    }

    botonAgregar.addEventListener('click', agregarPartida);

    contenedor.addEventListener('click', function (evento) {
      const boton = evento.target instanceof Element ? evento.target.closest('[data-quitar-partida]') : null;
      if (!boton) {
        return;
      }
      const fila = boton.closest('[data-partida]');
      const filas = contenedor.querySelectorAll('[data-partida]');
      if (fila && filas.length > 1) {
        fila.remove();
      } else {
        crearAlerta('info', 'El proyecto necesita al menos una partida.', 'Partidas');
      }
    });

    if (empresa) {
      empresa.addEventListener('change', filtrarProductos);
    }

    formulario.addEventListener('submit', function (evento) {
      const filas = Array.prototype.slice.call(contenedor.querySelectorAll('[data-partida]'));
      let validas = 0;

      filas.forEach(function (fila) {
        const select = fila.querySelector('[data-partida-producto]');
        if (select && !select.value) {
          fila.remove();
          return;
        }
        validas += 1;
      });

      if (validas === 0) {
        evento.preventDefault();
        evento.stopPropagation();
        crearAlerta('error', 'Agrega al menos un producto con su cantidad comprometida.', 'Proyecto incompleto');
      }
    });

    filtrarProductos();
  }

  /** «Seleccionar todas» de la pantalla de vinculación de ventas. */
  function prepararSeleccionVentas() {
    document.querySelectorAll('[data-marcar-todas]').forEach(function (todas) {
      const formulario = todas.closest('form');
      if (!formulario) {
        return;
      }
      const casillas = formulario.querySelectorAll('input[name="venta_ids[]"]');
      todas.addEventListener('change', function () {
        casillas.forEach(function (casilla) {
          casilla.checked = todas.checked;
        });
      });
    });
  }

  function iniciar() {
    preparar();
    prepararSeleccionVentas();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
})();
