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

  if (nodoPie) {
    vaciarNodo(nodoPie);

    const botonCancelar = document.createElement('button');
    botonCancelar.type = 'button';
    botonCancelar.className = 'btn btn--secundario';
    botonCancelar.textContent = 'Cancelar';
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
  modal.hidden = false;
  document.body.style.overflow = 'hidden';

  // Coloca el foco en el primer control interactivo del diálogo.
  const primerControl = modal.querySelector('button, [href], input, select, textarea');
  if (primerControl) {
    primerControl.focus();
  }
}

/** Cierra el modal y devuelve el foco al elemento que lo abrió. */
function cerrarModal() {
  const modal = obtenerNodoModal();
  if (!modal || modal.hidden) {
    return;
  }

  modal.hidden = true;
  document.body.style.overflow = '';
  accionConfirmada = null;

  if (elementoFocoAnterior && typeof elementoFocoAnterior.focus === 'function') {
    elementoFocoAnterior.focus();
  }
  elementoFocoAnterior = null;
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
  cuerpo.appendChild(nota);

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
    const accion = accionConfirmada;
    cerrarModal();
    if (typeof accion === 'function') {
      accion();
    }
  }
}
