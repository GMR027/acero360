/**
 * modulos/archivoPreview.js
 * ---------------------------------------------------------------------------
 * Vista previa de los archivos de la herramienta «Archivo» sin salir de la
 * página: usa la ventana modal única de la aplicación.
 *
 *   • PDF      → iframe con el visor del navegador (no se descarga).
 *   • Imágenes → <img> ampliada.
 *   • Otros    → aviso de que el navegador no puede mostrarlos, con la descarga.
 *
 * Tanto la miniatura del nombre como el botón «Ver» de la tabla llevan el
 * atributo [data-ver-archivo]. Sin JavaScript el enlace abre el archivo en
 * una pestaña nueva (o lo descarga si no hay vista previa).
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, sin alert.
 */

/** Endpoint que sirve el archivo inline (solo administradores). */
const ARCHIVO_VISTA_URL = '/admin/archivo/ver?id=';
const ARCHIVO_DESCARGA_URL = '/admin/archivo/descargar?id=';

/** Crea un nodo con clase y texto opcionales (nunca innerHTML). */
function crearNodoArchivo(etiqueta, clase, texto) {
  const nodo = document.createElement(etiqueta);
  if (clase) {
    nodo.className = clase;
  }
  if (texto !== undefined && texto !== null) {
    nodo.textContent = String(texto);
  }
  return nodo;
}

/** Enlace de descarga que se coloca en el pie del modal. */
function crearDescargaArchivo(id) {
  const enlace = crearNodoArchivo('a', 'btn btn--primario', 'Descargar');
  enlace.href = ARCHIVO_DESCARGA_URL + encodeURIComponent(id);
  return enlace;
}

/** Cuerpo del modal para un PDF: visor del navegador incrustado. */
function cuerpoPdfArchivo(url, nombre) {
  const contenedor = crearNodoArchivo('div', 'documento-preview documento-preview--pdf');
  const marco = document.createElement('iframe');
  marco.className = 'documento-preview__marco';
  marco.title = nombre;
  marco.src = url + '#view=FitH';
  contenedor.appendChild(marco);

  // Los navegadores móviles no siempre incrustan PDF: queda la vía directa.
  const nota = crearNodoArchivo('p', 'texto-xs texto-secundario', '¿No se ve el documento? ');
  const enlace = crearNodoArchivo('a', null, 'Ábrelo en una pestaña nueva');
  enlace.href = url;
  enlace.target = '_blank';
  enlace.rel = 'noopener';
  nota.appendChild(enlace);
  contenedor.appendChild(nota);

  return contenedor;
}

/** Cuerpo del modal para una imagen. */
function cuerpoImagenArchivo(url, nombre) {
  const contenedor = crearNodoArchivo('div', 'documento-preview documento-preview--imagen');
  const imagen = document.createElement('img');
  imagen.className = 'documento-preview__imagen';
  imagen.src = url;
  imagen.alt = nombre;
  imagen.addEventListener('error', function () {
    imagen.replaceWith(crearNodoArchivo('p', 'texto-secundario', 'No fue posible mostrar la imagen.'));
  });
  contenedor.appendChild(imagen);
  return contenedor;
}

/** Cuerpo del modal para formatos que el navegador no puede previsualizar. */
function cuerpoSinVistaArchivo(nombre) {
  const contenedor = crearNodoArchivo('div', 'documento-preview');
  contenedor.appendChild(crearNodoArchivo(
    'p',
    'texto-secundario',
    '«' + nombre + '» está guardado, pero el navegador no puede mostrarlo aquí. '
      + 'Descárgalo para abrirlo con su programa.'
  ));
  return contenedor;
}

/** Abre el archivo pulsado en la ventana modal única. */
function abrirVistaArchivo(boton) {
  if (typeof abrirModal !== 'function') {
    return; // Sin modal se conserva la navegación del enlace.
  }

  const id = boton.getAttribute('data-ver-archivo');
  if (!id) {
    return;
  }

  const nombre = boton.getAttribute('data-archivo-nombre') || 'Archivo';
  const tipo = boton.getAttribute('data-archivo-tipo') || 'otro';
  const url = ARCHIVO_VISTA_URL + encodeURIComponent(id);

  let cuerpo;
  if (tipo === 'pdf') {
    cuerpo = cuerpoPdfArchivo(url, nombre);
  } else if (tipo === 'imagen') {
    cuerpo = cuerpoImagenArchivo(url, nombre);
  } else {
    cuerpo = cuerpoSinVistaArchivo(nombre);
  }

  abrirModal({
    sobreTitulo: 'Vista previa',
    titulo: nombre,
    cuerpo: cuerpo,
    amplio: true,
    acciones: [crearDescargaArchivo(id)],
  });
}

/** Sustituye una miniatura que no carga por el icono del tipo de archivo. */
function marcarMiniaturaArchivoRota(imagen) {
  const enlace = imagen.closest('.ar-miniatura');
  if (!enlace) {
    return;
  }

  const icono = crearNodoArchivo('span', 'material-symbols-outlined ar-ico-tipo');
  icono.setAttribute('aria-hidden', 'true');
  icono.textContent = 'draft';

  enlace.classList.add('ar-miniatura--icono');
  imagen.replaceWith(icono);
}

/** Delegación de clic para todos los [data-ver-archivo] de la página. */
function inicializarArchivoPreview() {
  document.addEventListener('click', function (evento) {
    const boton = evento.target instanceof Element
      ? evento.target.closest('[data-ver-archivo]')
      : null;

    if (!boton) {
      return;
    }

    evento.preventDefault();
    abrirVistaArchivo(boton);
  });

  // El evento «error» no burbujea: se escucha en captura.
  document.addEventListener('error', function (evento) {
    if (evento.target instanceof HTMLImageElement
      && evento.target.closest('.ar-miniatura')) {
      marcarMiniaturaArchivoRota(evento.target);
    }
  }, true);
}
