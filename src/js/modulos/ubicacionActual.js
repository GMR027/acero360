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
 * Reglas del proyecto:
 *  - let/const, sin alert/confirm/prompt, sin innerHTML.
 *  - Nunca se envía el formulario automáticamente: sólo se rellena el campo.
 *  - Si el navegador no da la ubicación, se explica el motivo y se deja el
 *    campo como estaba para que el usuario pegue un enlace de Google Maps.
 */
(function () {
  'use strict';

  /** Formatea la posición como «lat, lon» con siete decimales. */
  function coordenadasDe(posicion) {
    const lat = Number(posicion.coords.latitude);
    const lon = Number(posicion.coords.longitude);
    if (!isFinite(lat) || !isFinite(lon)) {
      return '';
    }
    return lat.toFixed(7) + ', ' + lon.toFixed(7);
  }

  /** Texto de error legible a partir del código de geolocalización. */
  function mensajeDeError(error) {
    const codigo = error && typeof error.code === 'number' ? error.code : 0;
    if (codigo === 1) {
      return 'No diste permiso de ubicación. Puedes pegar el enlace de Google Maps a mano.';
    }
    if (codigo === 2) {
      return 'El dispositivo no pudo obtener la ubicación. Revisa el GPS o pega el enlace a mano.';
    }
    if (codigo === 3) {
      return 'La ubicación tardó demasiado. Inténtalo otra vez o pega el enlace a mano.';
    }
    return 'No se pudo obtener la ubicación. Pega el enlace de Google Maps a mano.';
  }

  /** Muestra un mensaje bajo el campo (o lo oculta con texto vacío). */
  function mostrarEstado(campo, texto, error) {
    if (!campo) {
      return;
    }
    const contenedor = campo.closest('.campo');
    let estado = contenedor ? contenedor.querySelector('[data-estado-ubicacion]') : null;

    if (!estado && contenedor) {
      estado = document.createElement('span');
      estado.className = 'campo__ayuda';
      estado.setAttribute('data-estado-ubicacion', '');
      contenedor.appendChild(estado);
    }
    if (!estado) {
      return;
    }

    estado.hidden = texto === '';
    estado.textContent = texto;
    estado.style.color = error ? 'var(--color-error, #b3261e)' : '';
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
        if (!campo) {
          return;
        }

        if (!navigator.geolocation) {
          mostrarEstado(campo, 'Este navegador no permite obtener la ubicación.', true);
          return;
        }

        boton.disabled = true;
        mostrarEstado(campo, 'Obteniendo tu ubicación…', false);

        navigator.geolocation.getCurrentPosition(
          function (posicion) {
            const coordenadas = coordenadasDe(posicion);
            if (coordenadas === '') {
              mostrarEstado(campo, 'El dispositivo devolvió una ubicación no válida.', true);
              boton.disabled = false;
              return;
            }

            campo.value = coordenadas;
            campo.dispatchEvent(new Event('input', { bubbles: true }));
            campo.dispatchEvent(new Event('change', { bubbles: true }));

            const precision = Number(posicion.coords.accuracy);
            const detalle = isFinite(precision) && precision > 0
              ? ' (precisión aproximada de ' + Math.round(precision) + ' m)'
              : '';
            mostrarEstado(campo, 'Ubicación capturada' + detalle + '. Guarda para usarla en el mapa.', false);
            boton.disabled = false;
          },
          function (error) {
            mostrarEstado(campo, mensajeDeError(error), true);
            boton.disabled = false;
          },
          { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
      });
    });
  }

  window.prepararUbicacionActual = prepararUbicacionActual;
})();
