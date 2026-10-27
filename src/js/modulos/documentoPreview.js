/**
 * modulos/documentoPreview.js
 * ---------------------------------------------------------------------------
 * Vista previa de facturas y comprobantes sin salir de la página: usa la
 * ventana modal única de la aplicación.
 *
 *   • PDF      → iframe con el visor del navegador (no se descarga).
 *   • XML      → se lee como texto y se muestra el resumen del CFDI y, si se
 *                despliega, el XML completo. Nunca se interpreta como HTML.
 *   • Imágenes → <img> ampliada.
 *
 * Cualquier documento de la tabla lleva el botón [data-ver-documento] con su
 * id, el tipo de vista y el nombre. El pie del modal ofrece la descarga, que
 * sigue siendo la única vía para guardar el archivo.
 */

/** Endpoint que sirve el documento inline (solo administradores). */
const DOCUMENTO_VISTA_URL = '/admin/contabilidad/documentos/ver?id=';

/** Crea un nodo con clase y texto opcionales (nunca innerHTML). */
function crearNodoDocumento(etiqueta, clase, texto) {
  const nodo = document.createElement(etiqueta);
  if (clase) {
    nodo.className = clase;
  }
  if (texto !== undefined && texto !== null) {
    nodo.textContent = String(texto);
  }
  return nodo;
}

/** Importe con el formato de la moneda del CFDI (MXN si no viene). */
function formatearImporteDocumento(valor, moneda) {
  const numero = Number.parseFloat(String(valor).replace(/,/g, ''));
  if (!Number.isFinite(numero)) {
    return String(valor || '');
  }
  const codigo = /^[A-Z]{3}$/.test(String(moneda || '')) ? String(moneda) : 'MXN';
  try {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: codigo }).format(numero);
  } catch (error) {
    return numero.toFixed(2);
  }
}

/** Botón/enlace de descarga que se coloca en el pie del modal. */
function crearDescargaDocumento(id) {
  const enlace = crearNodoDocumento('a', 'btn btn--primario', 'Descargar');
  enlace.href = '/admin/contabilidad/documentos/descargar?id=' + encodeURIComponent(id);
  return enlace;
}

/** Añade una pareja etiqueta/valor al resumen, omitiendo los vacíos. */
function agregarDatoDocumento(lista, etiqueta, valor) {
  if (valor === undefined || valor === null || String(valor).trim() === '') {
    return;
  }
  const termino = crearNodoDocumento('dt', null, etiqueta);
  const definicion = crearNodoDocumento('dd', null, valor);
  lista.appendChild(termino);
  lista.appendChild(definicion);
}

/** Extrae el resumen legible de un XML de CFDI. Devuelve null si no lo es. */
function resumirCfdiDocumento(texto) {
  const documento = new DOMParser().parseFromString(texto, 'application/xml');
  if (documento.getElementsByTagName('parsererror').length) {
    return null;
  }

  const nodo = function (etiqueta) {
    return documento.getElementsByTagNameNS('*', etiqueta)[0] || null;
  };
  const atributo = function (elemento, nombre) {
    return elemento ? (elemento.getAttribute(nombre) || '').trim() : '';
  };

  const comprobante = nodo('Comprobante');
  if (!comprobante) {
    return null;
  }

  const emisor = nodo('Emisor');
  const receptor = nodo('Receptor');
  const timbre = nodo('TimbreFiscalDigital');
  const moneda = atributo(comprobante, 'Moneda') || 'MXN';

  const contenedor = crearNodoDocumento('div', 'documento-preview__resumen');

  const lista = crearNodoDocumento('dl', 'documento-preview__datos');
  agregarDatoDocumento(lista, 'Folio', atributo(comprobante, 'Serie') + atributo(comprobante, 'Folio'));
  agregarDatoDocumento(lista, 'UUID', atributo(timbre, 'UUID'));
  agregarDatoDocumento(lista, 'Fecha', atributo(comprobante, 'Fecha').replace('T', ' '));
  agregarDatoDocumento(lista, 'Emisor', [atributo(emisor, 'Nombre'), atributo(emisor, 'Rfc')].filter(Boolean).join(' · '));
  agregarDatoDocumento(lista, 'Receptor', [atributo(receptor, 'Nombre'), atributo(receptor, 'Rfc')].filter(Boolean).join(' · '));
  agregarDatoDocumento(lista, 'Subtotal', formatearImporteDocumento(atributo(comprobante, 'SubTotal'), moneda));
  agregarDatoDocumento(lista, 'Total', formatearImporteDocumento(atributo(comprobante, 'Total'), moneda));
  agregarDatoDocumento(lista, 'Moneda', moneda);
  agregarDatoDocumento(lista, 'Forma de pago', atributo(comprobante, 'FormaPago'));
  agregarDatoDocumento(lista, 'Método de pago', atributo(comprobante, 'MetodoPago'));
  agregarDatoDocumento(lista, 'Uso del CFDI', atributo(receptor, 'UsoCFDI'));

  if (lista.children.length) {
    contenedor.appendChild(crearNodoDocumento('h3', 'documento-preview__subtitulo', 'Datos del CFDI'));
    contenedor.appendChild(lista);
  }

  const conceptos = Array.prototype.slice.call(documento.getElementsByTagNameNS('*', 'Concepto'));
  if (conceptos.length) {
    const tabla = crearNodoDocumento('table', 'tabla documento-preview__conceptos');
    const leyenda = crearNodoDocumento('caption', 'solo-lectores', 'Conceptos del CFDI');
    tabla.appendChild(leyenda);

    const cabecera = document.createElement('thead');
    const filaCabecera = document.createElement('tr');
    ['Descripción', 'Cantidad', 'Valor unitario', 'Importe'].forEach(function (titulo, indice) {
      const celda = crearNodoDocumento('th', indice === 0 ? null : 'tabla--numerica', titulo);
      celda.scope = 'col';
      filaCabecera.appendChild(celda);
    });
    cabecera.appendChild(filaCabecera);
    tabla.appendChild(cabecera);

    const cuerpo = document.createElement('tbody');
    conceptos.forEach(function (concepto) {
      const fila = document.createElement('tr');
      fila.appendChild(crearNodoDocumento('td', null, atributo(concepto, 'Descripcion')));
      fila.appendChild(crearNodoDocumento('td', 'tabla--numerica cifra', atributo(concepto, 'Cantidad')));
      fila.appendChild(crearNodoDocumento('td', 'tabla--numerica cifra', formatearImporteDocumento(atributo(concepto, 'ValorUnitario'), moneda)));
      fila.appendChild(crearNodoDocumento('td', 'tabla--numerica cifra', formatearImporteDocumento(atributo(concepto, 'Importe'), moneda)));
      cuerpo.appendChild(fila);
    });
    tabla.appendChild(cuerpo);

    contenedor.appendChild(crearNodoDocumento('h3', 'documento-preview__subtitulo', 'Conceptos'));
    contenedor.appendChild(tabla);
  }

  // El XML completo queda plegado para quien necesite revisarlo a detalle.
  const detalles = crearNodoDocumento('details', 'documento-preview__crudo');
  detalles.appendChild(crearNodoDocumento('summary', 'btn btn--texto btn--sm', 'Ver XML completo'));
  detalles.appendChild(crearNodoDocumento('pre', 'documento-preview__xml', texto));
  contenedor.appendChild(detalles);

  return contenedor;
}

/** Cuerpo del modal para un XML: primero carga, luego resumen o aviso. */
function cuerpoXmlDocumento(url) {
  const contenedor = crearNodoDocumento('div', 'documento-preview documento-preview--xml');
  const aviso = crearNodoDocumento('p', 'texto-secundario', 'Cargando el XML…');
  contenedor.appendChild(aviso);

  fetch(url, { credentials: 'same-origin' })
    .then(function (respuesta) {
      if (!respuesta.ok || respuesta.redirected) {
        throw new Error('No fue posible abrir el XML. Vuelve a iniciar sesión e inténtalo de nuevo.');
      }
      return respuesta.text();
    })
    .then(function (texto) {
      aviso.remove();
      const resumen = resumirCfdiDocumento(texto);
      contenedor.appendChild(resumen || crearNodoDocumento('p', 'texto-secundario', 'El archivo no tiene la estructura de un CFDI. Se muestra el contenido tal cual.'));
      if (!resumen) {
        contenedor.appendChild(crearNodoDocumento('pre', 'documento-preview__xml', texto));
      }
    })
    .catch(function (error) {
      aviso.className = 'nota nota--advertencia';
      aviso.textContent = error && error.message
        ? error.message
        : 'No fue posible abrir el XML.';
    });

  return contenedor;
}

/** Cuerpo del modal para un PDF. */
function cuerpoPdfDocumento(url, nombre) {
  const contenedor = crearNodoDocumento('div', 'documento-preview documento-preview--pdf');
  const marco = document.createElement('iframe');
  marco.className = 'documento-preview__marco';
  marco.title = nombre;
  marco.src = url + '#view=FitH';
  contenedor.appendChild(marco);

  // Los navegadores móviles no siempre incrustan PDF: queda la vía directa.
  const nota = crearNodoDocumento('p', 'texto-xs texto-secundario', '¿No se ve el documento? ');
  const enlace = crearNodoDocumento('a', null, 'Ábrelo en una pestaña nueva');
  enlace.href = url;
  enlace.target = '_blank';
  enlace.rel = 'noopener';
  nota.appendChild(enlace);
  contenedor.appendChild(nota);

  return contenedor;
}

/** Cuerpo del modal para una imagen. */
function cuerpoImagenDocumento(url, nombre) {
  const contenedor = crearNodoDocumento('div', 'documento-preview documento-preview--imagen');
  const imagen = document.createElement('img');
  imagen.className = 'documento-preview__imagen';
  imagen.src = url;
  imagen.alt = nombre;
  imagen.addEventListener('error', function () {
    imagen.replaceWith(crearNodoDocumento('p', 'texto-secundario', 'No fue posible mostrar la imagen.'));
  });
  contenedor.appendChild(imagen);
  return contenedor;
}

/** Abre el documento pulsado en la ventana modal única. */
function abrirVistaDocumento(boton) {
  if (typeof abrirModal !== 'function') {
    return; // Sin modal disponible se conserva la descarga de siempre.
  }

  const id = boton.getAttribute('data-ver-documento');
  if (!id) {
    return;
  }

  const nombre = boton.getAttribute('data-documento-nombre') || 'Documento';
  const tipo = boton.getAttribute('data-documento-tipo') || 'pdf';
  const url = DOCUMENTO_VISTA_URL + encodeURIComponent(id);

  let cuerpo;
  if (tipo === 'xml') {
    cuerpo = cuerpoXmlDocumento(url);
  } else if (tipo === 'imagen') {
    cuerpo = cuerpoImagenDocumento(url, nombre);
  } else {
    cuerpo = cuerpoPdfDocumento(url, nombre);
  }

  abrirModal({
    sobreTitulo: 'Vista previa',
    titulo: nombre,
    cuerpo: cuerpo,
    amplio: true,
    acciones: [crearDescargaDocumento(id)],
  });
}

/** Delegación de clic para todos los botones [data-ver-documento]. */
function inicializarDocumentoPreview() {
  document.addEventListener('click', function (evento) {
    const boton = evento.target instanceof Element
      ? evento.target.closest('[data-ver-documento]')
      : null;

    if (!boton) {
      return;
    }

    evento.preventDefault();
    abrirVistaDocumento(boton);
  });
}
