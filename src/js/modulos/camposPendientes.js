/**
 * modulos/camposPendientes.js
 * ---------------------------------------------------------------------------
 * Marca desde que se abre el formulario los campos que todavía no tienen
 * información, para que se vea de un vistazo qué falta llenar.
 *
 *   • Obligatorio pendiente → borde rojo continuo (.campo--pendiente).
 *   • Recomendado pendiente → borde rojo punteado (.campo--sugerido).
 *
 * La marca desaparece en cuanto el campo se llena (input/change) y se vuelve
 * a calcular cuando otro campo cambia la condición de éste (p. ej. «Pagado a»).
 *
 * Reglas declarativas en el HTML (sin lógica por formulario):
 *   data-obligatorio-si="#beneficiario_tipo=proveedor"
 *   data-recomendado-si="#comprobante_fiscal=1"
 *   data-recomendado
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, ni alert, ni
 * confirm, ni prompt.
 */

/** ¿Se cumple la condición escrita en un atributo data-…-si? */
function cumpleReglaCondicional(control, atributo) {
  const regla = control.getAttribute(atributo);
  if (!regla) {
    return false;
  }

  const separador = regla.indexOf('=');
  const selector = (separador === -1 ? regla : regla.slice(0, separador)).trim();
  const disparador = selector !== '' ? document.querySelector(selector) : null;
  if (!disparador) {
    return false;
  }

  const valor = String(disparador.value || '').trim();
  if (separador === -1) {
    return valor !== '';
  }

  return regla
    .slice(separador + 1)
    .split('|')
    .map(function (esperado) { return esperado.trim(); })
    .indexOf(valor) !== -1;
}

/** ¿El control es obligatorio ahora (por required o por una regla condicional)? */
function campoObligatorioAhora(control) {
  return control.hasAttribute('required') || cumpleReglaCondicional(control, 'data-obligatorio-si');
}

/** ¿El control es recomendado ahora (aunque no sea obligatorio)? */
function campoRecomendadoAhora(control) {
  return control.hasAttribute('data-recomendado') || cumpleReglaCondicional(control, 'data-recomendado-si');
}

/** ¿El control sigue vacío? (checkbox, radio, archivo, select y texto). */
function campoEstaVacio(control) {
  if (control.type === 'checkbox' || control.type === 'radio') {
    return !control.checked;
  }
  if (control.type === 'file') {
    return !control.files || control.files.length === 0;
  }

  return String(control.value || '').trim() === '';
}

/** ¿El .campo se está mostrando? (los condicionales usan hidden o .campo--oculto). */
function campoVisible(campo) {
  return !campo.hidden
    && !campo.closest('[hidden]')
    && !campo.classList.contains('campo--oculto');
}

/**
 * Recalcula las marcas de un .campo concreto.
 *
 * @param {HTMLElement} campo
 */
function actualizarCampoPendiente(campo) {
  if (!campoVisible(campo)) {
    campo.classList.remove('campo--pendiente', 'campo--sugerido');
    return;
  }

  let pendiente = false;
  let sugerido = false;

  campo.querySelectorAll('input, select, textarea').forEach(function (control) {
    if (control.disabled || control.type === 'hidden') {
      return;
    }

    const obligatorio = campoObligatorioAhora(control);
    const recomendado = !obligatorio && campoRecomendadoAhora(control);
    if (!obligatorio && !recomendado) {
      return;
    }
    if (!campoEstaVacio(control)) {
      return;
    }

    if (obligatorio) {
      pendiente = true;
    } else {
      sugerido = true;
    }
  });

  campo.classList.toggle('campo--pendiente', pendiente);
  campo.classList.toggle('campo--sugerido', !pendiente && sugerido);

  // El color no basta: los lectores de pantalla también deben saber que falta.
  campo.querySelectorAll('.control').forEach(function (control) {
    if (pendiente) {
      control.setAttribute('aria-invalid', 'true');
    } else {
      control.removeAttribute('aria-invalid');
    }
  });
}

/** Recalcula todos los campos de un formulario (o de la página). */
function actualizarCamposPendientes(raiz) {
  const ambito = raiz && raiz.querySelectorAll ? raiz : document;
  ambito.querySelectorAll('.campo').forEach(actualizarCampoPendiente);
}

/**
 * Arranca el marcado en todos los formularios de la página.
 * Se llama después de preparar los selectores de búsqueda y los campos
 * condicionales, para que el DOM ya esté completo.
 */
function inicializarCamposPendientes() {
  // En las pantallas de acceso (login/registro) no se marca nada: los campos
  // vacíos son lo normal y el rojo parecería un error antes de escribir.
  if (document.querySelector('main.auth')) {
    return;
  }

  // La leyenda solo tiene sentido cuando el marcado funciona (sin JS no hay bordes).
  document.querySelectorAll('[data-leyenda-pendientes]').forEach(function (nodo) {
    nodo.hidden = false;
  });

  actualizarCamposPendientes(document);

  // Al escribir o elegir algo se recalcula el campo afectado (y sus condiciones).
  // En fase de burbuja: los módulos que muestran/ocultan campos condicionales
  // escuchan en el propio control y ya corrieron cuando llega este evento.
  ['input', 'change'].forEach(function (evento) {
    document.addEventListener(evento, function (e) {
      if (!(e.target instanceof Element)) {
        return;
      }

      const campo = e.target.closest('.campo');
      if (campo) {
        actualizarCampoPendiente(campo);
      }
      // Un cambio puede mostrar u ocultar otros campos: se refresca el formulario.
      const formulario = e.target.closest('form');
      if (formulario) {
        actualizarCamposPendientes(formulario);
      }
    });
  });
}
