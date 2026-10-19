/**
 * modulos/alertas.js
 * ---------------------------------------------------------------------------
 * Sistema de alertas visuales (toasts) de la aplicación.
 *
 * Sustituye por completo a alert(), confirm() y prompt(): todo el feedback
 * del usuario se muestra en el DOM, dentro del contenedor .alertas, con el
 * mismo estilo en todas las pantallas.
 *
 * Todas las alertas se construyen con document.createElement + appendChild.
 * NUNCA se usa innerHTML.
 */

/** Iconos (caracteres Unicode) asociados a cada tipo de alerta. */
const ICONOS_ALERTA = {
  exito: '✓',
  error: '✕',
  advertencia: '!',
  info: 'i',
};

/** Duración en milisegundos antes de cerrarse automáticamente. */
const DURACION_ALERTA = 6000;

/**
 * Crea y muestra una alerta en pantalla.
 *
 * @param {'exito'|'error'|'advertencia'|'info'} tipo   Variante visual.
 * @param {string}                               texto  Mensaje para el usuario.
 * @param {string}                               titulo Título opcional.
 * @param {boolean}                              persistente  Si es true no se cierra sola.
 * @returns {HTMLElement} Nodo de la alerta creada.
 */
function crearAlerta(tipo, texto, titulo = '', persistente = false) {
  const contenedor = document.getElementById('alertas-contenedor');
  if (!contenedor) {
    return null;
  }

  const tipoValido = Object.prototype.hasOwnProperty.call(ICONOS_ALERTA, tipo) ? tipo : 'info';

  // Estructura: alerta > [icono, contenido(título+texto), botón cerrar]
  const alerta = document.createElement('div');
  alerta.className = 'alerta alerta--' + tipoValido;
  alerta.setAttribute('role', 'alert');

  const icono = document.createElement('span');
  icono.className = 'alerta__icono';
  icono.setAttribute('aria-hidden', 'true');
  icono.textContent = ICONOS_ALERTA[tipoValido];

  const contenido = document.createElement('div');
  contenido.className = 'alerta__contenido';

  if (titulo !== '') {
    const nodoTitulo = document.createElement('strong');
    nodoTitulo.className = 'alerta__titulo';
    nodoTitulo.textContent = titulo;
    contenido.appendChild(nodoTitulo);
  }

  const nodoTexto = document.createElement('p');
  nodoTexto.className = 'alerta__texto';
  nodoTexto.textContent = texto;
  contenido.appendChild(nodoTexto);

  const botonCerrar = document.createElement('button');
  botonCerrar.type = 'button';
  botonCerrar.className = 'alerta__cerrar';
  botonCerrar.setAttribute('aria-label', 'Cerrar alerta');
  botonCerrar.textContent = '✕';
  botonCerrar.addEventListener('click', function () {
    retirarAlerta(alerta);
  });

  alerta.appendChild(icono);
  alerta.appendChild(contenido);
  alerta.appendChild(botonCerrar);
  contenedor.appendChild(alerta);

  // Las alertas informativas y de éxito se cierran solas.
  if (!persistente) {
    window.setTimeout(function () {
      retirarAlerta(alerta);
    }, DURACION_ALERTA);
  }

  return alerta;
}

/**
 * Anima la salida de la alerta y la retira del DOM.
 *
 * @param {HTMLElement} alerta Nodo a eliminar.
 */
function retirarAlerta(alerta) {
  if (!alerta || !alerta.parentNode) {
    return;
  }

  alerta.classList.add('alerta--saliendo');

  window.setTimeout(function () {
    if (alerta.parentNode) {
      alerta.parentNode.removeChild(alerta);
    }
  }, 200);
}

/**
 * Prepara las alertas que el servidor ya renderizó (plantilla alertas.php):
 * les añade el botón de cierre y el temporizador de auto-cierre.
 */
function prepararAlertasDelServidor() {
  const contenedor = document.getElementById('alertas-contenedor');
  if (!contenedor) {
    return;
  }

  const alertas = contenedor.querySelectorAll('.alerta');
  alertas.forEach(function (alerta) {
    if (alerta.querySelector('.alerta__cerrar')) {
      return; // Ya preparada.
    }

    const botonCerrar = document.createElement('button');
    botonCerrar.type = 'button';
    botonCerrar.className = 'alerta__cerrar';
    botonCerrar.setAttribute('aria-label', 'Cerrar alerta');
    botonCerrar.textContent = '✕';
    botonCerrar.addEventListener('click', function () {
      retirarAlerta(alerta);
    });
    alerta.appendChild(botonCerrar);

    window.setTimeout(function () {
      retirarAlerta(alerta);
    }, DURACION_ALERTA);
  });
}
