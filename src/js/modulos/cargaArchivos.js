/**
 * modulos/cargaArchivos.js
 * ---------------------------------------------------------------------------
 * Mejora la carga de archivos en todos los <input type="file"> visibles:
 *
 *  - Añade bajo el campo una pista («Arrastra aquí o haz clic…») que se
 *    sustituye por la lista de archivos elegidos, con su peso.
 *  - Permite arrastrar y soltar archivos sobre el propio campo.
 *  - Si el campo declara `accept`, descarta los archivos que no encajan y
 *    avisa con una alerta visual (nunca con alert()).
 *
 * Los campos de imagen con vista previa (data-preview) conservan su
 * previsualización: sólo se les añade el arrastre sobre la zona dashed.
 * Los campos ocultos y los del botón «Elegir archivos» de /admin/archivo
 * (que ya tienen su propio flujo) se dejan intactos.
 *
 * Reglas del proyecto: let/const, sin innerHTML, sin alert/confirm/prompt.
 */

/** Icono de la pista de carga. */
const ICONO_PISTA_ARCHIVO = 'upload_file';

/** Icono de cada archivo elegido. */
const ICONO_ARCHIVO = 'description';

/**
 * Convierte bytes a un texto legible (B, KB, MB, GB…).
 *
 * @param {number} bytes
 * @returns {string}
 */
function formatearPesoArchivo(bytes) {
  if (!Number.isFinite(bytes) || bytes < 0) {
    return '';
  }

  if (bytes < 1024) {
    return bytes + ' B';
  }

  const unidades = ['KB', 'MB', 'GB', 'TB'];
  let valor = bytes / 1024;
  let indice = 0;

  while (valor >= 1024 && indice < unidades.length - 1) {
    valor = valor / 1024;
    indice += 1;
  }

  const decimales = valor >= 10 ? 0 : 1;
  return valor.toFixed(decimales).replace('.', ',') + ' ' + unidades[indice];
}

/**
 * Crea un icono de Material Symbols.
 *
 * @param {string} nombre
 * @returns {HTMLSpanElement}
 */
function crearIconoArchivo(nombre) {
  const icono = document.createElement('span');
  icono.className = 'material-symbols-outlined campo-archivo__icono';
  icono.setAttribute('aria-hidden', 'true');
  icono.textContent = nombre;
  return icono;
}

/**
 * Texto de la pista según se admitan uno o varios archivos.
 *
 * @param {HTMLInputElement} input
 * @returns {string}
 */
function textoPistaArchivo(input) {
  return input.multiple
    ? 'Arrastra los archivos aquí o haz clic para elegirlos.'
    : 'Arrastra el archivo aquí o haz clic para elegirlo.';
}

/**
 * Comprueba si un archivo encaja con la lista `accept` del campo.
 *
 * @param {File}   archivo
 * @param {string} acepta  Valor del atributo accept.
 * @returns {boolean}
 */
function archivoCoincideConAccept(archivo, acepta) {
  const reglas = acepta
    .split(',')
    .map(function (regla) { return regla.trim().toLowerCase(); })
    .filter(function (regla) { return regla !== ''; });

  if (!reglas.length) {
    return true;
  }

  const nombre = archivo.name.toLowerCase();
  const tipo = (archivo.type || '').toLowerCase();

  return reglas.some(function (regla) {
    if (regla.charAt(0) === '.') {
      return nombre.slice(-regla.length) === regla;
    }

    if (regla.slice(-2) === '/*') {
      return tipo.indexOf(regla.slice(0, -1)) === 0;
    }

    return tipo === regla;
  });
}

/**
 * Separa los archivos soltados entre válidos y rechazados según `accept`.
 *
 * @param {HTMLInputElement} input
 * @param {FileList|File[]}  archivos
 * @returns {{ validos: File[], rechazados: File[] }}
 */
function filtrarArchivosSoltados(input, archivos) {
  const lista = Array.prototype.slice.call(archivos);
  const acepta = (input.getAttribute('accept') || '').trim();

  if (acepta === '') {
    return { validos: input.multiple ? lista : lista.slice(0, 1), rechazados: [] };
  }

  const validos = [];
  const rechazados = [];

  lista.forEach(function (archivo) {
    if (archivoCoincideConAccept(archivo, acepta)) {
      validos.push(archivo);
    } else {
      rechazados.push(archivo);
    }
  });

  return {
    validos: input.multiple ? validos : validos.slice(0, 1),
    rechazados: rechazados,
  };
}

/**
 * Coloca los archivos en el campo y avisa a los demás módulos (vista previa,
 * resúmenes del modal…) mediante un evento change real.
 *
 * @param {HTMLInputElement} input
 * @param {File[]}           archivos
 * @returns {boolean} true si se pudieron asignar.
 */
function asignarArchivosAlCampo(input, archivos) {
  if (!archivos.length) {
    return false;
  }

  try {
    const lote = new DataTransfer();
    archivos.forEach(function (archivo) {
      lote.items.add(archivo);
    });
    input.files = lote.files;
  } catch (error) {
    return false;
  }

  input.dispatchEvent(new Event('change', { bubbles: true }));
  return true;
}

/**
 * Dibuja la pista o la lista de archivos dentro del detalle del campo.
 *
 * @param {HTMLInputElement} input
 * @param {HTMLElement}      detalle
 */
function pintarDetalleArchivos(input, detalle) {
  while (detalle.firstChild) {
    detalle.removeChild(detalle.firstChild);
  }

  const archivos = input.files ? Array.prototype.slice.call(input.files) : [];

  if (!archivos.length) {
    detalle.classList.add('campo-archivo__detalle--vacio');

    const pista = document.createElement('span');
    pista.className = 'campo-archivo__pista';
    pista.appendChild(crearIconoArchivo(ICONO_PISTA_ARCHIVO));
    pista.appendChild(document.createTextNode(textoPistaArchivo(input)));
    detalle.appendChild(pista);
    return;
  }

  detalle.classList.remove('campo-archivo__detalle--vacio');

  const lista = document.createElement('ul');
  lista.className = 'campo-archivo__lista';

  archivos.forEach(function (archivo) {
    const elemento = document.createElement('li');
    elemento.className = 'campo-archivo__archivo';

    const nombre = document.createElement('span');
    nombre.className = 'campo-archivo__nombre';
    nombre.textContent = archivo.name;
    nombre.title = archivo.name;

    const peso = document.createElement('span');
    peso.className = 'campo-archivo__peso';
    peso.textContent = formatearPesoArchivo(archivo.size);

    elemento.appendChild(crearIconoArchivo(ICONO_ARCHIVO));
    elemento.appendChild(nombre);
    elemento.appendChild(peso);
    lista.appendChild(elemento);
  });

  detalle.appendChild(lista);
}

/**
 * Activa el arrastrar y soltar sobre una zona.
 *
 * @param {HTMLInputElement} input      Campo de archivo.
 * @param {HTMLElement}      zona       Superficie que recibe el arrastre.
 * @param {string}           claseActiva Clase visual mientras se arrastra.
 * @param {HTMLElement|null} detalle    Zona donde pintar la lista (opcional).
 */
function activarArrastreArchivos(input, zona, claseActiva, detalle) {
  let profundidad = 0;

  const hayArchivos = function (evento) {
    const tipos = evento.dataTransfer && evento.dataTransfer.types;
    return !!tipos && Array.prototype.indexOf.call(tipos, 'Files') !== -1;
  };

  const apagar = function () {
    profundidad = 0;
    zona.classList.remove(claseActiva);
  };

  zona.addEventListener('dragenter', function (evento) {
    if (!hayArchivos(evento)) {
      return;
    }
    evento.preventDefault();
    profundidad += 1;
    zona.classList.add(claseActiva);
  });

  zona.addEventListener('dragover', function (evento) {
    if (!hayArchivos(evento)) {
      return;
    }
    evento.preventDefault();
    evento.dataTransfer.dropEffect = 'copy';
    zona.classList.add(claseActiva);
  });

  zona.addEventListener('dragleave', function (evento) {
    if (!hayArchivos(evento)) {
      return;
    }
    profundidad = Math.max(0, profundidad - 1);
    if (profundidad === 0) {
      zona.classList.remove(claseActiva);
    }
  });

  zona.addEventListener('drop', function (evento) {
    apagar();

    if (!evento.dataTransfer || !evento.dataTransfer.files || !evento.dataTransfer.files.length) {
      return;
    }

    evento.preventDefault();
    evento.stopPropagation();

    const resultado = filtrarArchivosSoltados(input, evento.dataTransfer.files);

    if (!resultado.validos.length) {
      if (typeof crearAlerta === 'function') {
        crearAlerta('error', 'Ningún archivo coincide con los formatos permitidos por este campo.', 'Archivo no válido');
      }
      return;
    }

    if (resultado.rechazados.length && typeof crearAlerta === 'function') {
      const nombres = resultado.rechazados.map(function (archivo) { return archivo.name; });
      crearAlerta(
        'advertencia',
        'Se descartó ' + (nombres.length === 1 ? 'este archivo: ' : 'estos archivos: ') + nombres.join(', ') + '.',
        'Formato no permitido'
      );
    }

    if (!asignarArchivosAlCampo(input, resultado.validos)) {
      if (typeof crearAlerta === 'function') {
        crearAlerta('error', 'El navegador no permitió tomar los archivos soltados. Usa el botón del campo.', 'No se pudo cargar');
      }
      return;
    }

    if (detalle) {
      pintarDetalleArchivos(input, detalle);
    }
  });
}

/**
 * Decide si un campo de archivo debe recibir la mejora.
 *
 * @param {HTMLInputElement} input
 * @returns {boolean}
 */
function debePrepararCampoArchivo(input) {
  if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
    return false;
  }

  if (input.hidden || input.disabled || input.hasAttribute('data-carga-preparada')) {
    return false;
  }

  // El botón «Elegir archivos» de /admin/archivo ya tiene su propio flujo
  // (input invisible dentro de una etiqueta); no se toca para no duplicarlo.
  if (input.closest('.ar-subir')) {
    return false;
  }

  // Inputs invisibles usados por editores (opacidad 0): no son una zona real.
  const estilo = window.getComputedStyle(input);
  if (estilo.opacity === '0' || estilo.display === 'none') {
    return false;
  }

  return true;
}

/**
 * Prepara todos los campos de archivo de la página.
 */
function prepararCargaArchivos() {
  document.querySelectorAll('input[type="file"]').forEach(function (input) {
    if (!debePrepararCampoArchivo(input)) {
      return;
    }

    input.setAttribute('data-carga-preparada', '1');

    // Campos de imagen: el arrastre se activa sobre la zona de previsualización.
    if (input.hasAttribute('data-preview')) {
      const carga = input.closest('.carga-imagen');
      const zona = carga ? carga.querySelector('.carga-imagen__zona') : null;

      if (zona) {
        activarArrastreArchivos(input, zona, 'carga-imagen__zona--arrastrando', null);
      }
      return;
    }

    input.classList.add('campo-archivo__control');

    const campo = input.closest('.campo');
    const zona = campo || input;
    zona.classList.add('campo-archivo');

    const detalle = document.createElement('div');
    detalle.className = 'campo-archivo__detalle';
    detalle.setAttribute('aria-live', 'polite');
    input.insertAdjacentElement('afterend', detalle);

    pintarDetalleArchivos(input, detalle);

    input.addEventListener('change', function () {
      pintarDetalleArchivos(input, detalle);
    });

    activarArrastreArchivos(input, zona, 'campo-archivo--activo', detalle);
  });
}
