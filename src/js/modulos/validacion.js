/**
 * modulos/validacion.js
 * ---------------------------------------------------------------------------
 * Validación de formularios con retroalimentación visual en el DOM.
 *
 * Reglas del proyecto:
 *  - nunca usar alert() / confirm() / prompt()
 *  - siempre prevenir el comportamiento por defecto del evento submit
 *  - los errores se muestran debajo de cada campo (.campo__error)
 */

/**
 * Tamaño máximo permitido para una imagen (6 MB).
 *
 * Debe coincidir con `Imagenes::TAMANO_MAXIMO` (PHP): el navegador avisa
 * antes de subir y el servidor vuelve a comprobarlo.
 */
const TAMANO_MAXIMO_IMAGEN = 6 * 1024 * 1024;

/** Tipos de archivo admitidos para imágenes de producto. */
const TIPOS_IMAGEN_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Elimina cualquier mensaje de error previo del formulario.
 *
 * @param {HTMLFormElement} formulario
 */
function limpiarErrores(formulario) {
  const mensajes = formulario.querySelectorAll('.campo__error');
  mensajes.forEach(function (nodo) {
    if (nodo.parentNode) {
      nodo.parentNode.removeChild(nodo);
    }
  });

  const camposConError = formulario.querySelectorAll('.campo--con-error');
  camposConError.forEach(function (campo) {
    campo.classList.remove('campo--con-error');
  });
}

/**
 * Muestra un mensaje de error debajo de un campo concreto.
 *
 * @param {HTMLElement} control  Input, select o textarea.
 * @param {string}      mensaje
 */
function mostrarErrorCampo(control, mensaje) {
  const campo = control.closest('.campo');
  if (!campo || campo.querySelector('.campo__error')) {
    return;
  }

  campo.classList.add('campo--con-error');

  const nodoError = document.createElement('p');
  nodoError.className = 'campo__error';
  nodoError.textContent = mensaje;
  campo.appendChild(nodoError);
}

/**
 * Comprueba el valor de un campo según su tipo nativo y sus atributos.
 *
 * @param {HTMLElement} control
 * @returns {string} Mensaje de error o cadena vacía si es válido.
 */
function mensajeDeError(control) {
  const valor = (control.value || '').trim();
  const obligatorio = typeof campoObligatorioAhora === 'function'
    ? campoObligatorioAhora(control)
    : control.hasAttribute('required');

  if (obligatorio && valor === '') {
    const etiqueta = control.closest('.campo')
      ? control.closest('.campo').querySelector('.campo__etiqueta')
      : null;
    const nombre = etiqueta ? etiqueta.textContent.replace('*', '').trim() : 'Este campo';
    return nombre + ' es obligatorio.';
  }

  if (valor === '') {
    return '';
  }

  // Correo electrónico.
  if (control.type === 'email') {
    const patron = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    if (!patron.test(valor)) {
      return 'Introduce un correo electrónico válido.';
    }
  }

  // Número entero positivo.
  if (control.type === 'number') {
    const numero = Number(valor);
    if (Number.isNaN(numero)) {
      return 'Introduce un número válido.';
    }
    if (control.hasAttribute('min') && numero < Number(control.getAttribute('min'))) {
      return 'El valor mínimo permitido es ' + control.getAttribute('min') + '.';
    }
  }

  // Longitud mínima (contraseñas).
  if (control.type === 'password' && control.hasAttribute('minlength')) {
    const minimo = Number(control.getAttribute('minlength'));
    if (valor.length < minimo) {
      return 'Debe tener al menos ' + minimo + ' caracteres.';
    }
  }

  // Confirmación de contraseña: se compara con el campo indicado en data-comparar.
  if (control.hasAttribute('data-comparar')) {
    const otro = document.getElementById(control.getAttribute('data-comparar'));
    if (otro && valor !== otro.value) {
      return 'Las contraseñas no coinciden.';
    }
  }

  return '';
}

/**
 * Valida todos los campos de un formulario.
 * Si hay errores, los pinta bajo cada campo, marca el primero y devuelve false.
 *
 * @param {HTMLFormElement} formulario
 * @returns {boolean} true si el formulario es válido.
 */
function validarFormulario(formulario) {
  limpiarErrores(formulario);

  const controles = formulario.querySelectorAll('input, select, textarea');
  let primerInvalido = null;

  controles.forEach(function (control) {
    // Un campo deshabilitado no se envía, así que tampoco se valida (p. ej. el bloque de partidas del otro tipo de compra).
    if (control.type === 'hidden' || control.type === 'submit' || control.type === 'button' || control.disabled) {
      return;
    }

    const mensaje = mensajeDeError(control);
    if (mensaje !== '') {
      mostrarErrorCampo(control, mensaje);
      if (!primerInvalido) {
        primerInvalido = control;
      }
    }
  });

  if (primerInvalido) {
    primerInvalido.focus();
    crearAlerta('error', 'Revisa los campos marcados antes de continuar.', 'Formulario incompleto');
    return false;
  }

  return true;
}

/**
 * Evita el envío doble de un formulario: bloquea el botón y muestra un
 * indicador de carga mientras la petición se procesa.
 *
 * @param {HTMLFormElement} formulario
 */
function bloquearEnvio(formulario) {
  const boton = formulario.querySelector('[type="submit"]');
  if (!boton) {
    return;
  }

  boton.disabled = true;
  boton.setAttribute('data-texto-original', boton.textContent);

  const indicador = document.createElement('span');
  indicador.className = 'cargando';
  indicador.setAttribute('aria-hidden', 'true');

  const texto = document.createElement('span');
  texto.textContent = ' Guardando…';

  boton.textContent = '';
  boton.appendChild(indicador);
  boton.appendChild(texto);
}
