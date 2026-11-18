/**
 * modulos/huellaApp.js
 * ---------------------------------------------------------------------------
 * Interruptor «Desbloqueo con huella» de Ajustes → Aplicación Android.
 *
 * El bloqueo vive en la app Android (BloqueoHuella.kt); aquí solo se consulta
 * y se cambia por el puente `Acero360Android`. La tarjeta queda oculta en el
 * navegador, en versiones de la app sin el puente y en teléfonos sin huella.
 * La app confirma cada cambio con la huella y responde con el evento
 * `acero360:huella` ({ activa, mensaje }).
 */
(function () {
  'use strict';

  function puente() {
    const p = window.Acero360Android;
    return p && typeof p.huellaDisponible === 'function' && typeof p.cambiarHuella === 'function' ? p : null;
  }

  function prepararHuellaApp() {
    const tarjeta = document.querySelector('[data-huella-app]');
    const app = puente();
    if (!tarjeta || !app) {
      return;
    }
    let disponible = false;
    try {
      disponible = app.huellaDisponible() === true;
    } catch (e) {
      disponible = false;
    }
    if (!disponible) {
      return;
    }

    const casilla = tarjeta.querySelector('#huella-activa');
    const estado = tarjeta.querySelector('[data-huella-estado]');
    casilla.checked = app.huellaActiva() === true;
    tarjeta.hidden = false;

    casilla.addEventListener('change', function () {
      const pedido = casilla.checked;
      casilla.checked = !pedido;   // se confirma hasta que la app responda
      casilla.disabled = true;
      estado.textContent = 'Confirma con tu huella…';
      app.cambiarHuella(pedido);
    });

    window.addEventListener('acero360:huella', function (evento) {
      const detalle = evento.detail || {};
      casilla.checked = detalle.activa === true;
      casilla.disabled = false;
      estado.textContent = detalle.mensaje || '';
    });
  }

  window.prepararHuellaApp = prepararHuellaApp;
})();
