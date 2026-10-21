/**
 * ordenCompra.js — partidas dinámicas del formulario de compra.
 *
 * Hay un bloque de partidas por tipo de compra (semiterminado o insumo); solo el del tipo elegido se envía
 * (el otro queda oculto y con sus campos deshabilitados). Agrega y quita renglones (clonando el primero, sin
 * innerHTML) y recalcula el importe de cada uno y el subtotal. El servidor vuelve a calcular todo: esto es
 * solo una ayuda visual.
 */
function iniciarOrdenCompra() {
  const bloques = document.querySelectorAll('[data-bloque-tipo]');
  if (!bloques.length) {
    return;
  }

  const formatoMoneda = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

  /** Muestra el bloque del tipo elegido y deshabilita los campos del otro. */
  const aplicarTipo = function () {
    const elegido = document.querySelector('[data-tipo-compra]:checked');
    const tipo = elegido ? elegido.value : 'semiterminado';
    bloques.forEach(function (bloque) {
      const activo = bloque.getAttribute('data-bloque-tipo') === tipo;
      bloque.hidden = !activo;
      bloque.querySelectorAll('input, select, textarea, button').forEach(function (campo) {
        campo.disabled = !activo;
      });
    });
  };

  const iniciarBloque = function (bloque) {
    const cuerpo = bloque.querySelector('[data-partidas-cuerpo]');
    const total = bloque.querySelector('[data-total]');

    const recalcular = function () {
      let centavos = 0;
      cuerpo.querySelectorAll('[data-partida]').forEach(function (fila) {
        const milesimas = Math.round((Number.parseFloat(fila.querySelector('[data-cantidad]').value) || 0) * 1000);
        const costo = Math.round((Number.parseFloat(fila.querySelector('[data-costo]').value) || 0) * 100);
        const importe = Math.round((milesimas * costo) / 1000);
        centavos += importe;
        fila.querySelector('[data-importe]').textContent = formatoMoneda.format(importe / 100);
      });
      total.textContent = formatoMoneda.format(centavos / 100);
    };

    /** El nombre del material solo se pide cuando la partida se controla en inventario. */
    const mostrarMaterial = function (fila) {
      const control = fila.querySelector('[data-controla]');
      const material = fila.querySelector('[data-material]');
      if (control && material) {
        material.hidden = control.value !== '1';
      }
    };

    bloque.querySelector('[data-agregar-partida]').addEventListener('click', function (evento) {
      evento.preventDefault();
      const nueva = cuerpo.querySelector('[data-partida]').cloneNode(true);
      nueva.querySelectorAll('input').forEach(function (campo) {
        campo.value = campo.hasAttribute('data-cantidad') ? '1' : (campo.hasAttribute('data-costo') ? '0.00' : '');
      });
      nueva.querySelectorAll('select').forEach(function (campo) {
        campo.selectedIndex = 0;
      });
      cuerpo.appendChild(nueva);
      mostrarMaterial(nueva);
      recalcular();
    });

    cuerpo.addEventListener('click', function (evento) {
      const boton = evento.target.closest('[data-quitar-partida]');
      if (!boton) {
        return;
      }
      evento.preventDefault();
      if (cuerpo.querySelectorAll('[data-partida]').length > 1) {
        boton.closest('[data-partida]').remove();
        recalcular();
      }
    });

    cuerpo.addEventListener('input', recalcular);

    cuerpo.addEventListener('change', function (evento) {
      const fila = evento.target.closest('[data-partida]');
      if (!fila) {
        return;
      }
      if (evento.target.hasAttribute('data-controla')) {
        mostrarMaterial(fila);
        return;
      }
      // Al elegir un producto con costo actual, lo propone si el costo está en cero.
      if (evento.target.tagName === 'SELECT' && evento.target.name === 'producto_id[]') {
        const costo = evento.target.selectedOptions[0].getAttribute('data-costo-catalogo');
        const campo = fila.querySelector('[data-costo]');
        if (costo && Number.parseFloat(campo.value) === 0) {
          campo.value = Number.parseFloat(costo).toFixed(2);
          recalcular();
        }
      }
    });

    cuerpo.querySelectorAll('[data-partida]').forEach(mostrarMaterial);
    recalcular();
  };

  bloques.forEach(iniciarBloque);
  document.querySelectorAll('[data-tipo-compra]').forEach(function (radio) {
    radio.addEventListener('change', aplicarTipo);
  });
  aplicarTipo();
}

document.addEventListener('DOMContentLoaded', iniciarOrdenCompra);
