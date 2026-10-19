/**
 * ordenCompra.js — partidas dinámicas del formulario de orden de compra.
 *
 * Agrega y quita renglones (clonando el primero, sin innerHTML) y recalcula
 * el importe de cada uno y el total. El servidor vuelve a calcular todo:
 * esto es solo una ayuda visual.
 */
function iniciarOrdenCompra() {
  const tabla = document.querySelector('[data-partidas]');
  if (!tabla) {
    return;
  }

  const cuerpo = tabla.querySelector('[data-partidas-cuerpo]');
  const total = tabla.querySelector('[data-total]');
  const formatoMoneda = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

  const recalcular = function () {
    let centavos = 0;
    cuerpo.querySelectorAll('[data-partida]').forEach(function (fila) {
      const cantidad = Number.parseInt(fila.querySelector('[data-cantidad]').value, 10) || 0;
      const costo = Math.round((Number.parseFloat(fila.querySelector('[data-costo]').value) || 0) * 100);
      centavos += cantidad * costo;
      fila.querySelector('[data-importe]').textContent = formatoMoneda.format((cantidad * costo) / 100);
    });
    total.textContent = formatoMoneda.format(centavos / 100);
  };

  document.querySelector('[data-agregar-partida]').addEventListener('click', function (evento) {
    evento.preventDefault();
    const nueva = cuerpo.querySelector('[data-partida]').cloneNode(true);
    nueva.querySelectorAll('input').forEach(function (campo) {
      campo.value = campo.hasAttribute('data-cantidad') ? '1' : (campo.hasAttribute('data-costo') ? '0.00' : '');
    });
    nueva.querySelector('select').value = '';
    cuerpo.appendChild(nueva);
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

  // Al elegir un producto con costo actual, lo propone si el costo está en cero.
  cuerpo.addEventListener('change', function (evento) {
    if (evento.target.tagName !== 'SELECT') {
      return;
    }
    const costo = evento.target.selectedOptions[0].getAttribute('data-costo-catalogo');
    const campo = evento.target.closest('[data-partida]').querySelector('[data-costo]');
    if (costo && Number.parseFloat(campo.value) === 0) {
      campo.value = Number.parseFloat(costo).toFixed(2);
      recalcular();
    }
  });

  recalcular();
}

document.addEventListener('DOMContentLoaded', iniciarOrdenCompra);
