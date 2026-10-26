/**
 * modulos/operacionComercial.js
 * ---------------------------------------------------------------------------
 * Ventanas modales del hub «Operación Comercial» (seguimiento de UNA venta).
 *
 * Cada enlace [data-modal-form] abre en el modal de la aplicación el
 * <template id="…"> que guarda el formulario REAL (mismo action, campos y
 * token que la página suelta) y lo envía desde el pie del diálogo. Sin JavaScript
 * el enlace sigue siendo un enlace: navega a la página suelta, que existe como
 * respaldo y para accesibilidad.
 *
 * También liga el comportamiento de la incidencia de entrega (marcar «requiere
 * cambio» al elegir cambio/sustitución y forzar la corrección de factura en
 * faltante/incompleto), tanto en la página suelta como en el clon del modal.
 *
 * Reglas del proyecto: createElement/nodos del DOM, sin innerHTML, ni alert,
 * ni confirm, ni prompt.
 */

/**
 * Abre el modal con el formulario del <template> indicado y, al confirmar,
 * envía el formulario real que quedó dentro del diálogo.
 *
 * @param {HTMLElement} enlace <a data-modal-form="id-del-template">
 */
function abrirModalFormulario(enlace) {
  if (typeof abrirModal !== 'function') {
    return; // sin modal disponible: se navega a la página suelta (href normal)
  }

  const plantilla = document.getElementById(enlace.getAttribute('data-modal-form'));
  if (!plantilla || !plantilla.content) {
    return;
  }

  abrirModal({
    sobreTitulo: enlace.getAttribute('data-modal-sobre-titulo') || '',
    titulo: enlace.getAttribute('data-modal-titulo') || 'Registrar',
    cuerpo: plantilla.content.cloneNode(true),
    textoConfirmar: enlace.getAttribute('data-modal-confirmar') || 'Guardar',
    alConfirmar: function () {
      const formulario = document.querySelector('#modal-cuerpo form');
      if (formulario) {
        formulario.submit(); // envío nativo: el servidor vuelve a validar todo
      }
    },
  });

  // El clon ya vive en el modal: se le liga su comportamiento (incidencias).
  const formulario = document.querySelector('#modal-cuerpo form[data-incidencia]');
  if (formulario) {
    ligarIncidencia(formulario);
  }
}

/**
 * Liga el motivo de una incidencia a un formulario concreto (página suelta o
 * formulario clonado dentro del modal):
 *   · «Cambio o sustitución» marca como «requiere cambio» los productos con piezas;
 *   · «Producto faltante» / «Producto incompleto» fuerzan la casilla de corregir
 *     factura (cuando la venta está facturada el servidor también lo fuerza);
 *   · al salir de esos motivos, la casilla automática se desmarca (una marcada
 *     a mano se conserva).
 *
 * @param {HTMLFormElement} formulario
 */
function ligarIncidencia(formulario) {
  const motivo = formulario.querySelector('select[name="motivo"]');
  if (!motivo || motivo.getAttribute('data-ligado') === '1') {
    return;
  }
  motivo.setAttribute('data-ligado', '1');

  const correccion = (formulario.getAttribute('data-forzar-factura') || '')
    .split(',')
    .filter(Boolean);
  const factura = formulario.querySelector('input[name="factura_requerida"]');

  motivo.addEventListener('change', function () {
    // Marca/desmarca «requiere cambio» según el motivo (comportamiento histórico).
    const esCambio = motivo.value === 'cambio_sustitucion';
    formulario.querySelectorAll('input[type="checkbox"][name^="cambio["]').forEach(function (casilla) {
      const fila = casilla.closest('tr');
      const cantidad = fila ? fila.querySelector('input[type="number"]') : null;
      if (esCambio && cantidad && Number(cantidad.value) > 0) {
        casilla.checked = true;
      } else if (!esCambio) {
        casilla.checked = false;
      }
    });

    // Corrección de factura: obligatoria en faltante/incompleto, opcional en el resto.
    if (!factura) {
      return;
    }
    if (correccion.indexOf(motivo.value) !== -1) {
      factura.checked = true;
      factura.setAttribute('data-automatico', '1');
    } else if (factura.getAttribute('data-automatico') === '1') {
      factura.checked = false;
      factura.removeAttribute('data-automatico');
    }
  });
}

/** Prepara todos los botones/modal de la página (se llama desde inicializarAplicacion). */
function prepararOperacionComercial() {
  document.querySelectorAll('a[data-modal-form]').forEach(function (enlace) {
    enlace.addEventListener('click', function (evento) {
      const plantilla = document.getElementById(enlace.getAttribute('data-modal-form'));
      // Con modal disponible se abre el diálogo; sin él se navega como siempre.
      if (plantilla && typeof abrirModal === 'function') {
        evento.preventDefault();
        abrirModalFormulario(enlace);
      }
    });
  });

  // La página suelta de incidencias (sin modal) comparte el mismo comportamiento.
  document.querySelectorAll('form[data-incidencia]').forEach(ligarIncidencia);
}
