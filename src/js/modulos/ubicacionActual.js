/**
 * modulos/ubicacionActual.js
 * ---------------------------------------------------------------------------
 * Botones «Usar mi ubicación actual» de los formularios de entrega.
 *
 * Las fotos del acta no siempre traen GPS (cámaras sin geolocalización,
 * permisos negados o fotos reenviadas por mensajería). Para no depender sólo
 * del EXIF, cada formulario de ubicación ofrece capturar la posición del
 * dispositivo y escribirla como coordenadas en el campo de texto.
 *
 * Cómo se pide la posición (en celular, navegador de escritorio y la app):
 *  1. GPS de alta precisión, hasta 12 s.
 *  2. Si el GPS no responde (bajo techo, sin señal, escritorio sin GPS), se
 *     reintenta con la ubicación aproximada por red, aceptando una lectura
 *     reciente de hasta 10 minutos.
 *  3. Si el navegador nunca contesta (algunos WebView no avisan cuando se
 *     cierra el cuadro de permiso), a los 35 s se libera el botón con aviso.
 * Al obtenerla, el campo se rellena y se muestra un enlace «Ver en el mapa»
 * para confirmar visualmente que la ubicación es la correcta.
 *
 * Reglas del proyecto:
 *  - let/const, sin alert/confirm/prompt, sin innerHTML.
 *  - Nunca se envía el formulario automáticamente: sólo se rellena el campo.
 *  - Si el navegador no da la ubicación, se explica el motivo y se deja el
 *    campo como estaba para que el usuario pegue un enlace de Google Maps.
 */
(function () {
  'use strict';

  const ESPERA_MAXIMA = 35000;

  /** Lat/lon de la posición o null si no son números válidos. */
  function coordenadasDe(posicion) {
    const lat = Number(posicion && posicion.coords ? posicion.coords.latitude : NaN);
    const lon = Number(posicion && posicion.coords ? posicion.coords.longitude : NaN);
    if (!isFinite(lat) || !isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
      return null;
    }
    return { lat: lat, lon: lon };
  }

  /** ¿Corre dentro de la app Android (WebView de Acero 360)? */
  function enAppAndroid() {
    return /Android/i.test(navigator.userAgent) && /; wv\)/.test(navigator.userAgent);
  }

  /** Texto de error legible a partir del código de geolocalización. */
  function mensajeDeError(error) {
    const codigo = error && typeof error.code === 'number' ? error.code : 0;
    if (codigo === 1) {
      return enAppAndroid()
        ? 'La app no tiene permiso de ubicación. Actívalo en Ajustes del teléfono → Aplicaciones → Acero 360 → Permisos → Ubicación, o pega el enlace de Google Maps a mano.'
        : 'No diste permiso de ubicación. Actívalo en el candado junto a la dirección del sitio, o pega el enlace de Google Maps a mano.';
    }
    if (codigo === 2) {
      return 'El dispositivo no pudo obtener la ubicación. Revisa que la ubicación (GPS) esté encendida, o pega el enlace a mano.';
    }
    if (codigo === 3) {
      return 'La ubicación tardó demasiado. Sal a un lugar con mejor señal e inténtalo otra vez, o pega el enlace a mano.';
    }
    return 'No se pudo obtener la ubicación. Pega el enlace de Google Maps a mano.';
  }

  /** Contenedor de mensajes bajo el campo (se crea si la vista no lo trae). */
  function contenedorEstado(campo) {
    const contenedor = campo.closest('.campo');
    if (!contenedor) {
      return null;
    }
    let estado = contenedor.querySelector('[data-estado-ubicacion]');
    if (!estado) {
      estado = document.createElement('span');
      estado.className = 'campo__ayuda';
      estado.setAttribute('data-estado-ubicacion', '');
      contenedor.appendChild(estado);
    }
    estado.setAttribute('role', 'status');
    estado.setAttribute('aria-live', 'polite');
    return estado;
  }

  /** Muestra un mensaje (y opcionalmente un enlace al mapa) bajo el campo. */
  function mostrarEstado(campo, texto, error, enlaceMapa) {
    const estado = contenedorEstado(campo);
    if (!estado) {
      return;
    }

    estado.replaceChildren();
    estado.hidden = texto === '';
    estado.style.color = error ? 'var(--color-error, #b3261e)' : '';
    estado.style.fontWeight = error ? '600' : '';
    estado.append(document.createTextNode(texto));

    if (enlaceMapa) {
      estado.append(document.createTextNode(' '));
      const enlace = document.createElement('a');
      enlace.href = enlaceMapa;
      enlace.target = '_blank';
      enlace.rel = 'noopener';
      enlace.textContent = 'Ver en el mapa';
      estado.append(enlace);
    }
  }

  /** Pone el botón en espera (o lo restaura) sin perder su contenido. */
  function enEspera(boton, esperando) {
    const texto = boton.querySelector('span:not(.material-symbols-outlined)');
    if (esperando) {
      boton.disabled = true;
      boton.setAttribute('aria-busy', 'true');
      if (texto) {
        boton.dataset.textoOriginal = texto.textContent;
        texto.textContent = 'Obteniendo ubicación…';
      }
      return;
    }
    boton.disabled = false;
    boton.removeAttribute('aria-busy');
    if (texto && boton.dataset.textoOriginal) {
      texto.textContent = boton.dataset.textoOriginal;
    }
  }

  /** Pide la posición: GPS primero y, si falla por señal o tiempo, ubicación aproximada. */
  function pedirPosicion(alObtener, alFallar) {
    navigator.geolocation.getCurrentPosition(
      alObtener,
      function (error) {
        if (error && error.code === 1) {
          alFallar(error);
          return;
        }
        navigator.geolocation.getCurrentPosition(
          alObtener,
          alFallar,
          { enableHighAccuracy: false, timeout: 20000, maximumAge: 600000 }
        );
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
  }

  function capturar(boton, campo) {
    if (!window.isSecureContext) {
      mostrarEstado(campo, 'La ubicación solo funciona con conexión segura (https). Pega el enlace de Google Maps a mano.', true);
      return;
    }
    if (!navigator.geolocation) {
      mostrarEstado(campo, 'Este navegador no permite obtener la ubicación. Pega el enlace de Google Maps a mano.', true);
      return;
    }

    let terminado = false;
    const terminar = function () {
      if (terminado) {
        return false;
      }
      terminado = true;
      window.clearTimeout(vigilante);
      enEspera(boton, false);
      return true;
    };
    const vigilante = window.setTimeout(function () {
      if (terminar()) {
        mostrarEstado(campo, 'No hubo respuesta del dispositivo. Revisa el permiso de ubicación y que el GPS esté encendido, o pega el enlace a mano.', true);
      }
    }, ESPERA_MAXIMA);

    enEspera(boton, true);
    mostrarEstado(campo, 'Obteniendo tu ubicación… si aparece un aviso de permiso, elige «Permitir».', false);

    pedirPosicion(
      function (posicion) {
        if (!terminar()) {
          return;
        }
        const punto = coordenadasDe(posicion);
        if (!punto) {
          mostrarEstado(campo, 'El dispositivo devolvió una ubicación no válida. Inténtalo otra vez.', true);
          return;
        }

        const texto = punto.lat.toFixed(7) + ', ' + punto.lon.toFixed(7);
        campo.value = texto;
        campo.dispatchEvent(new Event('input', { bubbles: true }));
        campo.dispatchEvent(new Event('change', { bubbles: true }));

        const precision = Number(posicion.coords.accuracy);
        const detalle = isFinite(precision) && precision > 0
          ? ' (precisión aproximada de ' + Math.round(precision) + ' m)'
          : '';
        mostrarEstado(
          campo,
          'Ubicación capturada: ' + texto + detalle + '. Guarda para usarla en el mapa.',
          false,
          'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(punto.lat.toFixed(7) + ',' + punto.lon.toFixed(7))
        );
      },
      function (error) {
        if (terminar()) {
          mostrarEstado(campo, mensajeDeError(error), true);
        }
      }
    );
  }

  /** Liga los botones que ya están en la página. */
  function prepararUbicacionActual() {
    document.querySelectorAll('[data-usar-ubicacion]').forEach(function (boton) {
      if (boton.dataset.ubicacionLigada === '1') {
        return;
      }
      boton.dataset.ubicacionLigada = '1';

      boton.addEventListener('click', function (evento) {
        evento.preventDefault();

        const selector = boton.getAttribute('data-usar-ubicacion');
        const campo = selector ? document.querySelector(selector) : null;
        if (!campo || boton.disabled) {
          return;
        }

        capturar(boton, campo);
      });
    });
  }

  window.prepararUbicacionActual = prepararUbicacionActual;
})();
