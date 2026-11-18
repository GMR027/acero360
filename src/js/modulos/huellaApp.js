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
 *
 * Inicio de sesión con huella (la sesión se pierde cuando Android cierra la app):
 *  - Con sesión y huella activa, si la app no tiene llave se pide una al
 *    servidor (/app/android/huella/registrar) y se le entrega a la app.
 *  - En /login, con llave guardada, se muestra «Entrar con mi huella» y se
 *    intenta una vez sola, salvo justo después de «Cerrar sesión» (?salio=1)
 *    o si el servidor rechazó la llave (?huella=invalida: se borra).
 *  - Al quitar la huella se revoca la llave en el servidor.
 */
(function () {
  'use strict';

  function puente() {
    const p = window.Acero360Android;
    return p && typeof p.huellaDisponible === 'function' && typeof p.cambiarHuella === 'function' ? p : null;
  }

  function puenteEntrada() {
    const p = puente();
    return p && typeof p.entrarConHuella === 'function' && typeof p.huellaTieneLlave === 'function' ? p : null;
  }

  function csrf() {
    const meta = document.querySelector('meta[name="csrf"]');
    return meta ? meta.content : '';
  }

  function enviar(ruta) {
    return fetch(ruta, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf(), 'X-Requested-With': 'acero360-app' }
    });
  }

  /** Con sesión abierta: si la huella está activa y la app no tiene llave, se le da una. */
  function asegurarLlave(app) {
    if (!app || document.querySelector('[data-entrar-huella]') || location.pathname === '/login') {
      return;
    }
    try {
      if (app.huellaActiva() !== true || app.huellaTieneLlave() === true) {
        return;
      }
    } catch (e) {
      return;
    }
    enviar('/app/android/huella/registrar')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (datos) {
        if (datos && datos.ok && datos.llave) {
          app.guardarLlaveHuella(datos.llave);
        }
      })
      .catch(function () { /* sin red: se reintenta en la siguiente página */ });
  }

  /** Pantalla de acceso: botón «Entrar con mi huella» e intento automático. */
  function prepararEntrada(app) {
    const caja = document.querySelector('[data-entrar-huella]');
    if (!caja || !app) {
      return;
    }
    const parametros = new URLSearchParams(location.search);
    if (parametros.get('huella') === 'invalida') {
      app.olvidarLlaveHuella();
      return;
    }
    let lista = false;
    try {
      lista = app.huellaActiva() === true && app.huellaTieneLlave() === true;
    } catch (e) {
      lista = false;
    }
    if (!lista) {
      return;
    }

    const boton = caja.querySelector('button');
    const estado = caja.querySelector('[data-entrar-huella-estado]');
    const campoCsrf = document.querySelector('form[action="/login"] input[name="_csrf"]');
    const llaveCsrf = campoCsrf ? campoCsrf.value : csrf();
    caja.hidden = false;

    const entrar = function () {
      boton.disabled = true;
      estado.textContent = 'Confirma con tu huella…';
      app.entrarConHuella(llaveCsrf);
    };
    boton.addEventListener('click', entrar);
    window.addEventListener('acero360:entrada', function (evento) {
      const detalle = evento.detail || {};
      boton.disabled = detalle.entrando === true;
      estado.textContent = detalle.mensaje || '';
    });

    // Un solo intento automático por cada 30 s (evita ciclos si algo falla).
    let ultimo = 0;
    try { ultimo = Number(sessionStorage.getItem('acero360-huella-auto') || 0); } catch (e) { ultimo = 0; }
    if (parametros.get('salio') !== '1' && Date.now() - ultimo > 30000) {
      try { sessionStorage.setItem('acero360-huella-auto', String(Date.now())); } catch (e) { /* sin almacenamiento */ }
      entrar();
    }
  }

  function prepararHuellaApp() {
    const entrada = puenteEntrada();
    prepararEntrada(entrada);
    asegurarLlave(entrada);

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
      if (detalle.activa === true) {
        asegurarLlave(puenteEntrada());
      } else if (detalle.mensaje) {
        enviar('/app/android/huella/revocar').catch(function () {});
      }
    });
  }

  window.prepararHuellaApp = prepararHuellaApp;
})();
