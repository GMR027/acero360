/**
 * modulos/verContrasena.js
 * ---------------------------------------------------------------------------
 * Botón «mostrar contraseña» (ojo) para los campos <input type="password">
 * marcados con el atributo data-ver-contrasena.
 *
 * El botón se construye con document.createElement (nunca innerHTML) y se
 * coloca dentro de la caja .campo__caja que envuelve al control. Sólo
 * alterna el tipo del input entre "password" y "text": el texto que ya
 * había escrito el usuario se conserva y no se vuelve a tocar el value.
 */

/** Texto del icono del ojo según el estado del campo. */
const ICONO_OJO_ABIERTO = 'visibility';
const ICONO_OJO_CERRADO = 'visibility_off';

/**
 * Refresca el aspecto del botón: icono, etiqueta accesible y aria-pressed.
 *
 * @param {HTMLButtonElement} boton
 * @param {boolean}           visible  true si la contraseña se está mostrando.
 */
function pintarBotonVerContrasena(boton, visible) {
  const icono = boton.querySelector('.material-symbols-outlined');

  if (icono) {
    icono.textContent = visible ? ICONO_OJO_CERRADO : ICONO_OJO_ABIERTO;
  }

  const etiqueta = visible ? 'Ocultar la contraseña' : 'Mostrar la contraseña';
  boton.setAttribute('aria-label', etiqueta);
  boton.setAttribute('title', etiqueta);
  boton.setAttribute('aria-pressed', visible ? 'true' : 'false');
}

/**
 * Agrega el botón del ojo a un campo de contraseña y le asigna su comportamiento.
 *
 * @param {HTMLInputElement} campo  <input type="password"> dentro de .campo__caja.
 */
function prepararCampoVerContrasena(campo) {
  const caja = campo.parentNode;

  // El botón sólo tiene sentido dentro de la caja que lo posiciona y reserva
  // el espacio a la derecha del control.
  if (!(caja instanceof HTMLElement) || !caja.classList.contains('campo__caja')) {
    return;
  }

  // Si por alguna razón ya existe, no se duplica.
  if (caja.querySelector('.campo__ojo')) {
    return;
  }

  const boton = document.createElement('button');
  boton.type = 'button';
  boton.className = 'campo__ojo';

  const icono = document.createElement('span');
  icono.className = 'material-symbols-outlined campo__ojo-icono';
  icono.setAttribute('aria-hidden', 'true');
  icono.textContent = ICONO_OJO_ABIERTO;
  boton.appendChild(icono);

  boton.addEventListener('click', function () {
    // Cambiar el tipo del input reinicia la selección: se guarda la posición
    // del cursor para devolverla justo donde estaba el usuario.
    const inicio = campo.selectionStart;
    const fin = campo.selectionEnd;
    const visible = campo.type === 'text';

    campo.type = visible ? 'password' : 'text';
    pintarBotonVerContrasena(boton, !visible);

    campo.focus();

    if (inicio !== null && fin !== null) {
      campo.setSelectionRange(inicio, fin);
    }
  });

  pintarBotonVerContrasena(boton, false);
  caja.appendChild(boton);
}

/** Recorre la página y prepara todos los campos que pidieron el botón. */
function prepararBotonesVerContrasena() {
  document.querySelectorAll('input[type="password"][data-ver-contrasena]')
    .forEach(prepararCampoVerContrasena);
}
