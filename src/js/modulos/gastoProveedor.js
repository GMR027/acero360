/**
 * modulos/gastoProveedor.js
 * ---------------------------------------------------------------------------
 * «Pagado a → Proveedor» del formulario de gasto
 * (/admin/contabilidad/gastos/crear).
 *
 * Tres responsabilidades:
 *
 *  1. Muestra debajo del selector el RFC del proveedor elegido.
 *  2. Ofrece el botón «¿No existe…? Regístralo» cuando lo escrito no coincide
 *     con ningún proveedor del directorio —o cuando todavía no hay ninguno—.
 *  3. Guarda lo capturado en el gasto (sessionStorage), abre
 *     /admin/proveedores/crear y, al volver, restaura el formulario para que el
 *     registro del gasto continúe donde quedó, con el proveedor nuevo ya
 *     elegido por el servidor.
 *
 * El borrador vive sólo en esta pestaña, caduca a los 30 minutos y se borra
 * apenas se restaura o se envía el gasto.
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, ni alert, ni
 * confirm, ni prompt.
 */

/** Llave del borrador de gasto en sessionStorage. */
const LLAVE_BORRADOR_GASTO = 'acero360.gasto.borrador';

/** Vigencia del borrador: nadie vuelve a un formulario olvidado horas después. */
const BORRADOR_GASTO_VIGENCIA = 30 * 60 * 1000;

/**
 * URL de esta página sin los parámetros que agrega el regreso del alta de
 * proveedor: así el borrador se compara contra la misma dirección de origen.
 */
function urlBaseGasto() {
  const parametros = new URLSearchParams(window.location.search);
  parametros.delete('proveedor');
  parametros.delete('proveedor_nuevo');
  const consulta = parametros.toString();
  return window.location.pathname + (consulta ? '?' + consulta : '');
}

/** Lee el borrador si sigue vigente y corresponde a esta misma página. */
function leerBorradorGasto() {
  let borrador = null;

  try {
    const crudo = window.sessionStorage.getItem(LLAVE_BORRADOR_GASTO);
    borrador = crudo ? JSON.parse(crudo) : null;
  } catch (error) {
    borrador = null;
  }

  if (!borrador || typeof borrador !== 'object') {
    return null;
  }

  const caducado = !borrador.ts || Date.now() - borrador.ts > BORRADOR_GASTO_VIGENCIA;
  if (caducado || borrador.url !== urlBaseGasto()) {
    window.sessionStorage.removeItem(LLAVE_BORRADOR_GASTO);
    return null;
  }

  return borrador;
}

/** Descarta el borrador guardado. */
function borrarBorradorGasto() {
  try {
    window.sessionStorage.removeItem(LLAVE_BORRADOR_GASTO);
  } catch (error) {
    /* Sin sessionStorage no hay borrador que borrar. */
  }
}

/**
 * Guarda lo capturado en el formulario del gasto antes de salir a registrar el
 * proveedor. Los archivos no se guardan (el navegador no lo permite): se avisa
 * al volver.
 *
 * @param {HTMLFormElement} formulario
 * @param {string}          texto  Lo escrito en el buscador de proveedores.
 * @returns {boolean} false si sessionStorage no está disponible.
 */
function guardarBorradorGasto(formulario, texto) {
  const campos = {};
  let conArchivo = false;

  Array.prototype.forEach.call(formulario.elements, function (campo) {
    if (!campo.name || campo.disabled) {
      return;
    }

    const tipo = (campo.type || '').toLowerCase();

    if (tipo === 'file') {
      conArchivo = conArchivo || campo.files.length > 0;
      return;
    }
    if (tipo === 'submit' || tipo === 'button' || tipo === 'reset') {
      return;
    }
    // La llave anti-falsificación es de un solo uso: la pinta el servidor al volver.
    if (campo.name === 'token') {
      return;
    }
    if (tipo === 'checkbox' || tipo === 'radio') {
      if (campo.checked) {
        campos[campo.name] = campo.value;
      }
      return;
    }

    campos[campo.name] = campo.value;
  });

  try {
    window.sessionStorage.setItem(LLAVE_BORRADOR_GASTO, JSON.stringify({
      url: urlBaseGasto(),
      campos: campos,
      texto: texto,
      conArchivo: conArchivo,
      ts: Date.now(),
    }));
    return true;
  } catch (error) {
    return false;
  }
}

/**
 * Devuelve al formulario lo capturado antes de salir. Los campos vacíos se
 * omiten (no pisan lo que el servidor ya precargó) y `proveedor_id` lo pone el
 * servidor con el proveedor recién creado.
 *
 * @param {HTMLFormElement} formulario
 * @param {object}          borrador
 */
function restaurarBorradorGasto(formulario, borrador) {
  const campos = borrador.campos || {};
  const sinRestaurar = ['token', 'archivo', 'proveedor_id'];

  Object.keys(campos).forEach(function (nombre) {
    if (sinRestaurar.indexOf(nombre) !== -1) {
      return;
    }

    const campo = formulario.elements[nombre];
    const valor = campos[nombre];

    if (!campo || valor === '' || valor === null || valor === undefined) {
      return;
    }

    const tipo = (campo.type || '').toLowerCase();

    if (tipo === 'checkbox' || tipo === 'radio') {
      const marcado = campo.value === String(valor);
      if (campo.checked !== marcado) {
        campo.checked = marcado;
        campo.dispatchEvent(new Event('change', { bubbles: true }));
      }
      return;
    }

    if (campo.value === String(valor)) {
      return;
    }

    campo.value = valor;
    // Los dos eventos: hay módulos que reaccionan al cambio y otros a la escritura.
    campo.dispatchEvent(new Event('input', { bubbles: true }));
    campo.dispatchEvent(new Event('change', { bubbles: true }));
  });
}

/** Aviso de que los archivos elegidos no se pudieron conservar. */
function avisarArchivosNoRestaurados(formulario) {
  const campoArchivo = formulario.querySelector('input[type="file"]');
  const contenedor = campoArchivo ? campoArchivo.closest('.campo') : null;

  if (!contenedor || contenedor.querySelector('[data-archivo-restaurado]')) {
    return;
  }

  const aviso = document.createElement('p');
  aviso.className = 'campo__ayuda';
  aviso.setAttribute('data-archivo-restaurado', '');
  aviso.textContent = 'Vuelve a adjuntar los archivos del gasto: el navegador no conserva los archivos elegidos.';
  contenedor.appendChild(aviso);
}

/**
 * Prepara el campo «Proveedor» del formulario de gasto.
 * Se llama desde inicializarAplicacion() (src/js/app.js), DESPUÉS de
 * prepararSelectoresBusqueda(), porque aquí se apoya en el combobox ya construido.
 */
function prepararGastoProveedor() {
  const tipoBen = document.querySelector('[data-beneficiario-tipo]');
  const bloque = document.querySelector('[data-ben-proveedor]');
  const select = document.getElementById('proveedor_id');

  // Otros formularios comparten el id `proveedor_id`: aquí sólo interesa el gasto.
  if (!tipoBen || !bloque || !select) {
    return;
  }

  const formulario = select.closest('form');
  if (!formulario) {
    return;
  }

  const boton = document.querySelector('[data-registrar-proveedor]');
  const rfcCaja = document.querySelector('[data-proveedor-rfc]');
  const entrada = document.getElementById(select.id + '-busqueda');
  /* No basta con `data-buscador-listo`: selectorBusqueda.js lo marca antes de
   * rendirse, así que con el directorio vacío no se construye el combobox y el
   * indicador sigue en «1». La señal fiable es que el campo exista. */
  const hayCombobox = entrada !== null;

  /** Último texto escrito y número de coincidencias (lo dicta selectorBusqueda.js). */
  const estado = { texto: '', total: null };

  const pintarRfc = function () {
    if (!rfcCaja) {
      return;
    }
    const opcion = select.options[select.selectedIndex];
    const rfc = opcion ? (opcion.getAttribute('data-rfc') || '').trim() : '';
    rfcCaja.textContent = rfc !== '' ? 'RFC del proveedor: ' + rfc : '';
    rfcCaja.hidden = rfc === '';
  };

  const textoBoton = function () {
    const texto = (estado.texto || '').trim();
    return texto !== ''
      ? '¿No existe «' + texto + '»? Regístralo'
      : 'Este proveedor no está en tu directorio: regístralo';
  };

  const sincronizarBoton = function () {
    if (!boton) {
      return;
    }

    let sinCoincidencias;
    if (hayCombobox) {
      // Con buscador sólo se ofrece la alta cuando lo escrito no casa con nada
      // y, por eso, todavía no hay ningún proveedor elegido.
      sinCoincidencias = estado.total === 0
        && (estado.texto || '').trim() !== ''
        && select.value === '';
    } else {
      // Sin buscador (directorio vacío): se ofrece la alta mientras no haya nada elegido.
      sinCoincidencias = select.value === '';
    }

    const mostrar = !bloque.hidden && sinCoincidencias;
    if (mostrar) {
      boton.textContent = textoBoton();
    }
    boton.hidden = !mostrar;
  };

  document.addEventListener('buscador:resultado', function (evento) {
    if (evento.target !== entrada && evento.target !== select) {
      return;
    }
    estado.texto = (evento.detail && evento.detail.texto) || '';
    estado.total = evento.detail ? evento.detail.total : null;
    sincronizarBoton();
  });

  select.addEventListener('change', function () {
    // Al elegir una ficha ya no hay nada que registrar.
    if (select.value !== '') {
      estado.texto = '';
      estado.total = null;
    }
    pintarRfc();
    sincronizarBoton();
  });

  tipoBen.addEventListener('change', function () {
    /* Al salir de «Proveedor» el texto escrito deja de significar nada: se
     * borra (el buscador impediría el envío si se quedara con algo sin elegir)
     * y se olvida la última búsqueda. */
    if (tipoBen.value !== 'proveedor') {
      if (entrada) {
        entrada.value = '';
      }
      estado.texto = '';
      estado.total = null;
    }
    sincronizarBoton();
  });

  if (boton) {
    boton.addEventListener('click', function () {
      // estado.texto sobrevive al desenfoque del campo: es lo que se escribió.
      const texto = (estado.texto || (entrada ? entrada.value : '')).trim();

      guardarBorradorGasto(formulario, texto);

      const destino = new URL('/admin/proveedores/crear', window.location.origin);
      destino.searchParams.set('regresar', urlBaseGasto());
      if (texto !== '') {
        destino.searchParams.set('nombre', texto);
      }

      // El RFC emisor del CFDI (si el gasto lo trae) es el RFC fiscal del proveedor.
      const rfcEmisor = formulario.elements['rfc_emisor'];
      const rfc = rfcEmisor ? String(rfcEmisor.value || '').trim() : '';
      if (rfc !== '') {
        destino.searchParams.set('rfc', rfc);
      }

      window.location.assign(destino.pathname + destino.search);
    });
  }

  // Regreso del alta: se repone lo que se venía capturando.
  const borrador = leerBorradorGasto();
  if (borrador) {
    restaurarBorradorGasto(formulario, borrador);
    if (borrador.conArchivo) {
      avisarArchivosNoRestaurados(formulario);
    }
    borrarBorradorGasto();
  }

  pintarRfc();
  sincronizarBoton();

  /* Al enviar el gasto ya no hace falta el borrador. Va en `document` y después
   * del manejador de app.js, que es el que decide si el envío se llega a hacer. */
  document.addEventListener('submit', function (evento) {
    if (evento.target !== formulario || evento.defaultPrevented) {
      return;
    }
    borrarBorradorGasto();
  });
}
