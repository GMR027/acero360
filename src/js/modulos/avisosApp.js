/**
 * modulos/avisosApp.js
 * ---------------------------------------------------------------------------
 * Notificaciones del Centro de seguimiento en la app Android (AvisosAndroid.kt).
 *
 * La tarjeta «Notificaciones en este teléfono» de Ajustes → Aplicación Android
 * solo aparece dentro de la app con el puente `Acero360Android` (1.0.6+).
 *  - Activar: el servidor emite una llave de SOLO LECTURA de avisos para esta
 *    instalación (/app/android/avisos/registrar) y se le entrega a la app, que
 *    revisa cada ~15 min en segundo plano y notifica lo nuevo con sonido.
 *  - Tono: el del teléfono (predeterminado) o uno elegido con el selector de
 *    Android; «Más ajustes» abre los ajustes del canal en Android.
 *  - Desactivar: la app borra la llave y se revoca en el servidor.
 *  - Si la app perdió la llave (contraseña cambiada, caducada) y el usuario
 *    las quiere, se pide otra en la siguiente página con sesión.
 *  - Al tocar una notificación, la app guarda la ruta del aviso y aquí se abre
 *    (al cargar la página o con el evento `acero360:abrir-aviso`).
 */
(function () {
  'use strict';

  function puente() {
    const p = window.Acero360Android;
    return p && typeof p.avisosEstado === 'function' && typeof p.activarAvisos === 'function' ? p : null;
  }

  function estado(app) {
    try {
      return JSON.parse(app.avisosEstado()) || {};
    } catch (e) {
      return {};
    }
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

  function enSesion() {
    return location.pathname.indexOf('/admin') === 0;
  }

  /** Pide la llave al servidor y se la entrega a la app. */
  function registrar(app) {
    return enviar('/app/android/avisos/registrar')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (datos) {
        if (datos && datos.ok && datos.llave && datos.instalacion) {
          app.activarAvisos(datos.llave, datos.instalacion);
          return true;
        }
        return false;
      })
      .catch(function () { return false; });
  }

  /** Abre el aviso tocado en la bandeja (solo con sesión: tras el inicio de sesión se abrirá). */
  function abrirPendiente(app) {
    if (!enSesion()) {
      return;
    }
    let enlace = '';
    try { enlace = String(app.avisoPendiente() || ''); } catch (e) { enlace = ''; }
    if (/^\/admin(\/|\?|$)/.test(enlace) && enlace.indexOf('//') === -1 && enlace !== location.pathname + location.search) {
      location.assign(enlace);
    }
  }

  function prepararTarjeta(app) {
    const tarjeta = document.querySelector('[data-avisos-app]');
    if (!tarjeta) {
      return;
    }
    const casilla = tarjeta.querySelector('#avisos-activos');
    const opciones = tarjeta.querySelector('[data-avisos-opciones]');
    const tono = tarjeta.querySelector('[data-avisos-tono]');
    const usarTelefono = tarjeta.querySelector('[data-avisos-tono-telefono]');
    const permiso = tarjeta.querySelector('[data-avisos-permiso]');
    const mensaje = tarjeta.querySelector('[data-avisos-estado]');

    function pintar(e) {
      casilla.checked = e.activas === true;
      casilla.disabled = false;
      opciones.hidden = e.activas !== true;
      tono.textContent = e.tono || 'El del teléfono';
      usarTelefono.hidden = e.tonoPropio !== true;
      permiso.hidden = !(e.activas === true && e.permitidas === false);
    }

    pintar(estado(app));
    tarjeta.hidden = false;

    casilla.addEventListener('change', function () {
      casilla.disabled = true;
      if (casilla.checked) {
        mensaje.textContent = 'Activando…';
        registrar(app).then(function (ok) {
          mensaje.textContent = ok ? 'Listo: recibirás los avisos nuevos en este teléfono.' : 'No se pudo activar. Revisa tu conexión e inténtalo de nuevo.';
          if (!ok) { pintar(estado(app)); }
        });
      } else {
        app.desactivarAvisos();
        enviar('/app/android/avisos/revocar').catch(function () {});
        mensaje.textContent = 'Ya no recibirás avisos en este teléfono.';
      }
    });

    tarjeta.querySelector('[data-avisos-elegir]').addEventListener('click', function () { app.elegirTonoAvisos(); });
    usarTelefono.addEventListener('click', function () { app.tonoDelTelefonoAvisos(); });
    tarjeta.querySelector('[data-avisos-probar]').addEventListener('click', function () { app.probarAviso(); });
    tarjeta.querySelectorAll('[data-avisos-ajustes]').forEach(function (b) {
      b.addEventListener('click', function () { app.ajustesAvisos(); });
    });

    window.addEventListener('acero360:avisos', function (evento) { pintar(evento.detail || {}); });
  }

  function prepararAvisosApp() {
    const app = puente();
    if (!app) {
      return;
    }
    abrirPendiente(app);
    window.addEventListener('acero360:abrir-aviso', function () { abrirPendiente(app); });

    // Las quiere pero la app perdió la llave: se pide otra (una vez por carga de página).
    const e = estado(app);
    if (enSesion() && e.activas === true && e.conectadas !== true) {
      registrar(app);
    }

    prepararTarjeta(app);
  }

  window.prepararAvisosApp = prepararAvisosApp;
})();
