/**
 * modulos/modal.js
 * ---------------------------------------------------------------------------
 * Control de la ventana modal única de la aplicación.
 *
 * Todos los diálogos (confirmaciones, vistas rápidas) comparten el mismo
 * nodo del DOM: templates/modal.php. Sólo cambian el sobre-título, el
 * título, el cuerpo y las acciones, que se construyen con createElement.
 *
 * No se usa innerHTML ni window.confirm en ninguna parte.
 */

/** Referencia al nodo del modal (se busca al abrir para tolerar re-render). */
let nodoModal = null;

/** Último elemento con el foco antes de abrir, para devolverlo al cerrar. */
let elementoFocoAnterior = null;

/** Función pendiente de ejecutar cuando el usuario confirma. */
let accionConfirmada = null;

/** La trampa de foco se instala una sola vez sobre el modal compartido. */
let trampaFocoInstalada = false;

/** Selector de controles que pueden recibir foco dentro del diálogo. */
const SELECTOR_ENFOCABLE =
  'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), ' +
  'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Controles visibles del modal, en orden de tabulación. */
function controlesEnfocables(modal) {
  return Array.from(modal.querySelectorAll(SELECTOR_ENFOCABLE)).filter(function (el) {
    return el.offsetParent !== null || el === document.activeElement;
  });
}

/** Mantiene Tab y Shift+Tab dentro del diálogo abierto. */
function instalarTrampaFoco(modal) {
  if (trampaFocoInstalada) {
    return;
  }
  trampaFocoInstalada = true;
  modal.addEventListener('keydown', function (evento) {
    if (evento.key !== 'Tab') {
      return;
    }
    const controles = controlesEnfocables(modal);
    if (!controles.length) {
      evento.preventDefault();
      return;
    }
    const primero = controles[0];
    const ultimo = controles[controles.length - 1];
    const dentro = modal.contains(document.activeElement);
    if (evento.shiftKey && (!dentro || document.activeElement === primero)) {
      evento.preventDefault();
      ultimo.focus();
    } else if (!evento.shiftKey && (!dentro || document.activeElement === ultimo)) {
      evento.preventDefault();
      primero.focus();
    }
  });
}

/**
 * Acción pendiente al cerrar el modal (diálogos con valor: pedirTexto).
 * Se invoca SIEMPRE que el modal se cierra, de la forma que sea, y sólo una
 * vez, para resolver la promesa pendiente aunque el usuario pulse Escape.
 */
let accionAlCerrar = null;

/**
 * Devuelve el nodo raíz del modal o null si la plantilla no está en la página.
 *
 * @returns {HTMLElement|null}
 */
function obtenerNodoModal() {
  if (!nodoModal) {
    nodoModal = document.getElementById('modal-principal');
  }
  return nodoModal;
}

/**
 * Abre el modal con el contenido recibido.
 *
 * @param {Object}  opciones
 * @param {string}  opciones.sobreTitulo  Contexto pequeño sobre el título.
 * @param {string}  opciones.titulo       Título de la acción.
 * @param {Node|Node[]} [opciones.cuerpo] Contenido del cuerpo.
 * @param {Node|Node[]} [opciones.acciones] Botones del pie (además de Cerrar).
 * @param {Function} [opciones.alConfirmar] Se invoca al pulsar la acción principal.
 * @param {string}   [opciones.textoConfirmar] Texto del botón principal.
 * @param {boolean}  [opciones.confirmacion] Estilo de diálogo de confirmación.
 * @param {boolean}  [opciones.amplio]        Panel más ancho (vistas previas).
 * @param {Function} [opciones.alCerrar] Se invoca UNA vez al cerrar, sea como sea
 *                                       (confirmar, cancelar, Escape o clic fuera).
 */
function abrirModal(opciones) {
  const modal = obtenerNodoModal();
  if (!modal) {
    return;
  }

  const sobreTitulo = opciones.sobreTitulo || '';
  const titulo = opciones.titulo || '';
  const cuerpo = opciones.cuerpo || null;
  const acciones = opciones.acciones || null;
  const confirmacion = opciones.confirmacion === true;
  const amplio = opciones.amplio === true;

  // --- Cabecera -----------------------------------------------------------
  const nodoSobreTitulo = modal.querySelector('#modal-sobre-titulo');
  const nodoTitulo = modal.querySelector('#modal-titulo');

  if (nodoSobreTitulo) {
    nodoSobreTitulo.textContent = sobreTitulo;
    nodoSobreTitulo.hidden = sobreTitulo === '';
  }
  if (nodoTitulo) {
    nodoTitulo.textContent = titulo;
  }

  // --- Cuerpo -------------------------------------------------------------
  const nodoCuerpo = modal.querySelector('#modal-cuerpo');
  if (nodoCuerpo) {
    vaciarNodo(nodoCuerpo);
    agregarContenido(nodoCuerpo, cuerpo);
  }

  // --- Pie de acciones ----------------------------------------------------
  const nodoPie = modal.querySelector('#modal-pie');
  accionConfirmada = typeof opciones.alConfirmar === 'function' ? opciones.alConfirmar : null;
  accionAlCerrar = typeof opciones.alCerrar === 'function' ? opciones.alCerrar : null;

  if (nodoPie) {
    vaciarNodo(nodoPie);

    const botonCancelar = document.createElement('button');
    botonCancelar.type = 'button';
    botonCancelar.className = 'btn btn--secundario';
    botonCancelar.textContent = accionConfirmada ? 'Cancelar' : 'Cerrar';
    botonCancelar.setAttribute('data-cerrar-modal', '');
    nodoPie.appendChild(botonCancelar);

    if (accionConfirmada) {
      const botonConfirmar = document.createElement('button');
      botonConfirmar.type = 'button';
      botonConfirmar.className = confirmacion
        ? 'btn btn--peligro'
        : 'btn btn--primario';
      botonConfirmar.textContent = opciones.textoConfirmar || 'Confirmar';
      botonConfirmar.setAttribute('data-confirmar-modal', '');
      nodoPie.appendChild(botonConfirmar);
    }

    agregarContenido(nodoPie, acciones);
  }

  // --- Mostrar ------------------------------------------------------------
  elementoFocoAnterior = document.activeElement;
  modal.classList.toggle('modal--confirmacion', confirmacion);
  modal.classList.toggle('modal--amplio', amplio);
  modal.hidden = false;
  document.body.style.overflow = 'hidden';
  instalarTrampaFoco(modal);

  // Mientras el diálogo está abierto, el resto de la aplicación no debe
  // recibir foco ni ser leído por tecnologías de asistencia.
  const app = document.querySelector('.app');
  if (app) {
    app.setAttribute('inert', '');
  }

  // Foco inicial útil: el primer campo del formulario; en confirmaciones,
  // «Cancelar» para no confirmar por accidente con Enter.
  let foco = null;
  if (confirmacion) {
    // Cancelar del pie, no la «X» de la cabecera.
    foco = modal.querySelector('#modal-pie [data-cerrar-modal]') || modal.querySelector('[data-cerrar-modal]');
  } else {
    foco = modal.querySelector(
      '#modal-cuerpo input:not([type="hidden"]), #modal-cuerpo select, #modal-cuerpo textarea'
    ) || modal.querySelector('[data-confirmar-modal]');
  }
  if (!foco) {
    foco = modal.querySelector('button, [href], input:not([type="hidden"]), select, textarea');
  }
  if (foco) {
    foco.focus();
  }
}

/** Cierra el modal, devuelve el foco al elemento que lo abrió y avisa a alCerrar. */
function cerrarModal() {
  const modal = obtenerNodoModal();
  if (!modal || modal.hidden) {
    return;
  }

  modal.hidden = true;
  document.body.style.overflow = '';
  accionConfirmada = null;

  const app = document.querySelector('.app');
  if (app) {
    app.removeAttribute('inert');
  }

  if (elementoFocoAnterior && typeof elementoFocoAnterior.focus === 'function') {
    elementoFocoAnterior.focus();
  }
  elementoFocoAnterior = null;

  // Se toma antes de limpiar: la acción puede abrir otro diálogo.
  const alCerrar = accionAlCerrar;
  accionAlCerrar = null;
  if (typeof alCerrar === 'function') {
    alCerrar();
  }
}

/**
 * Diálogo de confirmación reutilizable (equivale a window.confirm, pero
 * con el mismo diseño visual que el resto de la aplicación).
 *
 * @param {Object}   opciones
 * @param {string}   opciones.titulo
 * @param {string}   [opciones.sobreTitulo]
 * @param {string}   opciones.mensaje
 * @param {string}   [opciones.textoConfirmar]
 * @param {Function} opciones.alConfirmar
 */
function confirmarAccion(opciones) {
  const parrafo = document.createElement('p');
  parrafo.className = 'texto-base';
  parrafo.textContent = opciones.mensaje || '';

  const nota = document.createElement('div');
  nota.className = 'nota nota--advertencia';
  const iconoNota = document.createElement('span');
  iconoNota.className = 'nota__icono';
  iconoNota.setAttribute('aria-hidden', 'true');
  iconoNota.textContent = '!';
  const textoNota = document.createElement('span');
  textoNota.textContent = 'Esta acción no se puede deshacer.';
  nota.appendChild(iconoNota);
  nota.appendChild(textoNota);

  const cuerpo = document.createElement('div');
  cuerpo.className = 'columna';
  cuerpo.appendChild(parrafo);
  // Si el mensaje ya avisa de ello (formularios con data-confirm), no se repite.
  if (!/no se puede deshacer/i.test(opciones.mensaje || '')) {
    cuerpo.appendChild(nota);
  }

  abrirModal({
    sobreTitulo: opciones.sobreTitulo || 'Confirmación',
    titulo: opciones.titulo || '¿Continuar?',
    cuerpo: cuerpo,
    confirmacion: true,
    textoConfirmar: opciones.textoConfirmar || 'Sí, continuar',
    alConfirmar: opciones.alConfirmar,
  });
}

/**
 * Diálogo para pedir un texto al usuario (sustituye a window.prompt).
 *
 * Devuelve una promesa con el texto escrito o null si cancela (botón, clic
 * fuera o tecla Escape). El campo se autocompleta con $predeterminado.
 *
 * @param {Object}  opciones
 * @param {string}  opciones.titulo
 * @param {string}  [opciones.mensaje]    Explicación breve sobre el campo.
 * @param {string}  [opciones.etiqueta]   Rótulo del campo.
 * @param {string}  [opciones.predeterminado] Valor inicial del campo.
 * @param {string}  [opciones.placeholder] Ejemplo mostrado dentro del campo.
 * @param {number}  [opciones.maximo]     Longitud máxima (carácteres).
 * @param {string}  [opciones.textoConfirmar] Texto del botón principal.
 * @returns {Promise<string|null>}
 */
function pedirTexto(opciones) {
  const opcionesTexto = opciones || {};

  return new Promise(function (resolver) {
    let resuelta = false;
    const responder = function (valor) {
      if (resuelta) {
        return;
      }
      resuelta = true;
      resolver(valor);
    };

    const campo = document.createElement('input');
    campo.type = 'text';
    campo.className = 'control';
    campo.value = opcionesTexto.predeterminado || '';
    if (opcionesTexto.placeholder) {
      campo.placeholder = opcionesTexto.placeholder;
    }
    if (opcionesTexto.maximo) {
      campo.maxLength = opcionesTexto.maximo;
    }

    const etiqueta = document.createElement('label');
    etiqueta.className = 'campo';
    const rotulo = document.createElement('span');
    rotulo.className = 'campo__etiqueta';
    rotulo.textContent = opcionesTexto.etiqueta || 'Nombre';
    etiqueta.appendChild(rotulo);
    etiqueta.appendChild(campo);

    const cuerpo = document.createElement('div');
    cuerpo.className = 'columna';
    if (opcionesTexto.mensaje) {
      const parrafo = document.createElement('p');
      parrafo.className = 'texto-base texto-secundario';
      parrafo.textContent = opcionesTexto.mensaje;
      cuerpo.appendChild(parrafo);
    }
    cuerpo.appendChild(etiqueta);

    // Intro en el campo = aceptar (el campo no está dentro de un <form>).
    campo.addEventListener('keydown', function (evento) {
      if (evento.key !== 'Enter') {
        return;
      }
      evento.preventDefault();
      responder(campo.value);   // antes de cerrar: si no, alCerrar resolvería null
      cerrarModal();
    });

    abrirModal({
      sobreTitulo: opcionesTexto.sobreTitulo || '',
      titulo: opcionesTexto.titulo || 'Escribe un valor',
      cuerpo: cuerpo,
      textoConfirmar: opcionesTexto.textoConfirmar || 'Aceptar',
      alConfirmar: function () {
        const valor = campo.value;
        // cerrarModal() dispara alCerrar, que responde(null); al llegar aquí
        // la promesa ya está resuelta y este valor no la pisa (resuelta=true).
        responder(valor);
      },
      alCerrar: function () {
        responder(null);
      },
    });

    campo.focus();
    campo.select();
  });
}

/**
 * Vacía un nodo quitando todos sus hijos de forma segura.
 *
 * @param {HTMLElement} nodo
 */
function vaciarNodo(nodo) {
  while (nodo.firstChild) {
    nodo.removeChild(nodo.firstChild);
  }
}

/**
 * Agrega uno o varios nodos a un contenedor.
 *
 * @param {HTMLElement}        contenedor
 * @param {Node|Node[]|null}   contenido
 */
function agregarContenido(contenedor, contenido) {
  if (!contenido) {
    return;
  }

  if (Array.isArray(contenido)) {
    contenido.forEach(function (nodo) {
      if (nodo) {
        contenedor.appendChild(nodo);
      }
    });
    return;
  }

  contenedor.appendChild(contenido);
}

/**
 * Cierra el modal al pulsar fuera de él o con la tecla Escape,
 * y procesa los atributos data-cerrar-modal / data-confirmar-modal.
 *
 * @param {Event} evento
 */
function manejarInteraccionModal(evento) {
  const destino = evento.target;

  if (!(destino instanceof Element)) {
    return;
  }

  // Clic directo en el fondo del modal.
  if (destino.id === 'modal-principal') {
    cerrarModal();
    return;
  }

  if (destino.closest('[data-cerrar-modal]')) {
    evento.preventDefault();
    cerrarModal();
    return;
  }

  if (destino.closest('[data-confirmar-modal]')) {
    evento.preventDefault();
    // La acción ANTES de cerrar: así los diálogos con valor (pedirTexto)
    // resuelven con lo escrito y no con el null del cierre.
    const accion = accionConfirmada;
    accionConfirmada = null;
    if (typeof accion === 'function') {
      accion();
    }
    cerrarModal();
  }
}
