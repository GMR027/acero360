/**
 * modulos/inteligente.js
 * ---------------------------------------------------------------------------
 * «Registro Venta Inteligente» y «Registro compra inteligente».
 *
 * Suben una factura (XML, PDF o foto) al servidor, donde el motor documental
 * la lee y devuelve la ruta del formulario correspondiente con los datos ya
 * precargados. Aquí sólo se pide el archivo, se espera la lectura y se navega:
 * nada se guarda como venta ni como gasto hasta que la persona revisa y envía
 * ese formulario.
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, ni alert, ni
 * confirm, ni prompt.
 */

/** Textos de cada destino, para no repetirlos en la plantilla. */
let inteligenteTextos = {
  venta: {
    titulo: 'Registro Venta Inteligente',
    sobreTitulo: 'Factura que emití',
    ayuda: 'Sube el XML del CFDI (trae los datos exactos), el PDF o una foto de la factura. El formulario de venta se llena con el cliente, la fecha y los renglones que se reconozcan; lo demás lo completas tú.',
  },
  gasto: {
    titulo: 'Registro compra inteligente',
    sobreTitulo: 'Factura que me dieron',
    ayuda: 'Sube el XML del CFDI (trae los datos exactos), el PDF o una foto de la factura. El formulario de gasto se llena con el proveedor, el total, el folio fiscal y los renglones que se reconozcan; lo demás lo completas tú.',
  },
};

/** Crea un elemento con su clase y su texto. */
function nodoInteligente(etiqueta, clase, texto) {
  const elemento = document.createElement(etiqueta);
  if (clase) {
    elemento.className = clase;
  }
  if (texto !== undefined && texto !== null) {
    elemento.textContent = texto;
  }
  return elemento;
}

/**
 * Cuerpo del diálogo: el archivo a leer y cómo se va a usar.
 *
 * @param {string} destino  venta | gasto
 * @param {boolean} ocr     si el servidor puede leer fotos y PDFs escaneados
 * @param {string} aviso    error de la última tentativa, si la hubo
 * @returns {DocumentFragment}
 */
function cuerpoInteligente(destino, ocr, aviso) {
  const textos = inteligenteTextos[destino] || inteligenteTextos.gasto;
  const fragmento = document.createDocumentFragment();

  if (aviso) {
    fragmento.appendChild(nodoInteligente('p', 'nota nota--error mb-md', aviso));
  }

  const nota = nodoInteligente('p', 'campo__ayuda mb-md', textos.ayuda);
  fragmento.appendChild(nota);

  const campo = nodoInteligente('div', 'campo campo--completo');
  const etiqueta = document.createElement('label');
  etiqueta.className = 'campo__etiqueta campo__etiqueta--obligatorio';
  etiqueta.setAttribute('for', 'inteligente-archivo');
  etiqueta.textContent = 'Factura o comprobante';

  const archivo = document.createElement('input');
  archivo.className = 'control';
  archivo.type = 'file';
  archivo.id = 'inteligente-archivo';
  archivo.name = 'archivo[]';
  archivo.multiple = true;
  archivo.required = true;
  archivo.accept = '.xml,.pdf,.jpg,.jpeg,.png,.webp';

  campo.appendChild(etiqueta);
  campo.appendChild(archivo);
  campo.appendChild(
    nodoInteligente(
      'span',
      'campo__ayuda',
      ocr
        ? 'Puedes subir varios archivos a la vez (por ejemplo el XML y el PDF de la misma factura). Si solo tienes una foto, se lee con OCR y conviene revisar cada dato.'
        : 'Puedes subir varios archivos a la vez (por ejemplo el XML y el PDF de la misma factura). Este servidor no tiene OCR: una foto o un PDF escaneado no se pueden leer, se capturan a mano.'
    )
  );
  fragmento.appendChild(campo);

  return fragmento;
}

/**
 * Envía el archivo y devuelve la respuesta del servidor: la ruta del formulario
 * con lo leído precargado y, si la factura ya existe, las coincidencias.
 *
 * @param {string} destino  venta | gasto
 * @param {string} token    token de un solo uso del formulario
 * @returns {Promise<{ruta: string, duplicado?: boolean, coincidencias?: Array}>}
 */
function enviarInteligente(destino, token) {
  const campo = document.getElementById('inteligente-archivo');
  const meta = document.querySelector('meta[name="csrf"]');

  const datos = new FormData();
  datos.append('destino', destino);
  datos.append('token', token);

  const archivos = campo && campo.files ? campo.files : [];
  for (let i = 0; i < archivos.length; i += 1) {
    datos.append('archivo[]', archivos[i]);
  }

  return fetch('/admin/contabilidad/lectura/inteligente', {
    method: 'POST',
    body: datos,
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': meta ? meta.content : '' },
  }).then(function (respuesta) {
    return respuesta.json().catch(function () {
      return { ok: false, error: 'El servidor no respondió como se esperaba (' + respuesta.status + ').' };
    }).then(function (cuerpo) {
      if (!respuesta.ok || !cuerpo.ok) {
        throw new Error(cuerpo.error || 'No fue posible leer el documento.');
      }
      return cuerpo;
    });
  });
}

/**
 * Aviso de que esta factura ya tiene un registro: diálogo con cada coincidencia
 * (folio, fecha, importe y por qué se considera duplicado) y un botón para
 * abrirla desde ahí, además de la salida para registrar de todos modos.
 *
 * @param {Array}  coincidencias [{tipo, id, ruta, etiqueta, folio, fecha, total, detalle, criterio}]
 * @param {string} ruta          formulario al que se iría si la persona continúa
 * @param {string} origen        contexto del diálogo, p. ej. «Registro compra inteligente»
 */
function avisarDuplicado(coincidencias, ruta, origen) {
  const cuerpo = document.createDocumentFragment();

  cuerpo.appendChild(nodoInteligente(
    'p',
    'nota nota--advertencia mb-md',
    'Este documento ya tiene un registro con la misma información. Mira el que existe antes de guardar otro: si es el mismo, ábrelo y no lo dupliques.'
  ));

  const lista = document.createElement('ul');
  lista.className = 'lista-simple';
  coincidencias.forEach(function (coincidencia) {
    const item = document.createElement('li');
    const titulo = coincidencia.etiqueta + (coincidencia.folio ? ' · ' + coincidencia.folio : '');
    item.appendChild(nodoInteligente('strong', '', titulo));

    const detalle = [
      coincidencia.fecha || '',
      monedaCotizador(coincidencia.total || 0),
      coincidencia.detalle || '',
    ].filter(Boolean).join(' · ');
    if (detalle) {
      item.appendChild(document.createTextNode(' — ' + detalle));
    }
    if (coincidencia.criterio) {
      // Sin paréntesis exteriores: el criterio puede traer los suyos («folio fiscal (UUID)»).
      item.appendChild(document.createTextNode(' · ' + coincidencia.criterio));
    }
    lista.appendChild(item);
  });
  cuerpo.appendChild(lista);

  // Un botón por tipo de registro encontrado: el pedido es poder abrir la venta
  // directamente desde este aviso (o el gasto, si el duplicado es de gasto).
  const acciones = [];
  ['venta', 'gasto'].forEach(function (tipo) {
    const coincidencia = coincidencias.find(function (elemento) { return elemento.tipo === tipo; });
    if (!coincidencia) {
      return;
    }
    const boton = document.createElement('button');
    boton.type = 'button';
    boton.className = 'btn btn--secundario';
    boton.textContent = (tipo === 'venta' ? 'Ver la venta' : 'Ver el gasto') + ' ' + coincidencia.id;
    boton.setAttribute('data-ver-registro', coincidencia.ruta);
    boton.addEventListener('click', function () {
      window.location.assign(coincidencia.ruta);
    });
    acciones.push(boton);
  });

  abrirModal({
    sobreTitulo: origen,
    titulo: 'Ya hay un registro con esta factura',
    cuerpo: cuerpo,
    acciones: acciones,
    textoConfirmar: 'Registrar de todos modos',
    alConfirmar: function () {
      /* «De todos modos» NO registra nada aquí: abre el formulario sin el aviso
       * (continuar=1). El servidor vuelve a comprobar el duplicado al guardar y,
       * si el folio fiscal (UUID) coincide, lo rechaza con un mensaje claro.
       * Antes sólo recargaba la misma ruta y el aviso reaparecía: un bucle. */
      const separador = ruta.indexOf('?') === -1 ? '?' : '&';
      window.location.assign(ruta + separador + 'continuar=1');
    },
  });
}

/**
 * Abre el diálogo de carga de un botón.
 *
 * @param {HTMLElement} boton
 * @param {string} aviso  mensaje de error a mostrar (opcional)
 */
function abrirInteligente(boton, aviso) {
  const destino = boton.getAttribute('data-inteligente') || 'gasto';
  const textos = inteligenteTextos[destino] || inteligenteTextos.gasto;
  const nodoOcr = document.querySelector('[data-ocr]');
  const ocr = !nodoOcr || nodoOcr.getAttribute('data-ocr') === '1';
  const tokenNodo = document.querySelector('[data-inteligente-token]');
  const token = tokenNodo ? tokenNodo.value : '';

  abrirModal({
    sobreTitulo: textos.sobreTitulo,
    titulo: textos.titulo,
    cuerpo: cuerpoInteligente(destino, ocr, aviso || ''),
    textoConfirmar: 'Leer documento',
    alConfirmar: function () {
      const campo = document.getElementById('inteligente-archivo');
      if (!campo || !campo.files || campo.files.length === 0) {
        abrirInteligente(boton, 'Elige al menos un archivo (XML, PDF o foto de la factura).');
        return;
      }

      crearAlerta('info', 'Leyendo el documento… se abre el formulario en cuanto esté listo.', 'Registro inteligente');

      enviarInteligente(destino, token)
        .then(function (cuerpo) {
          const hayDuplicados = cuerpo.duplicado && cuerpo.coincidencias && cuerpo.coincidencias.length > 0;
          if (hayDuplicados) {
            // El diálogo de carga ya se cerró: se abre el de duplicados con las
            // coincidencias y sus enlaces, y la persona decide si sigue.
            avisarDuplicado(cuerpo.coincidencias, cuerpo.ruta, textos.titulo);
            return;
          }
          crearAlerta('exito', 'Documento leído. Revisa lo que quedó cargado antes de guardar.', 'Registro inteligente');
          window.location.assign(cuerpo.ruta);
        })
        .catch(function (error) {
          crearAlerta('error', error.message || 'No fue posible leer el documento.', 'Registro inteligente');
        });
    },
  });
}

/** Llena el buscador del catálogo con la descripción de un concepto sin equivalente. */
function buscarEnCatalogo(descripcion) {
  const campo = document.getElementById('buscar-producto');
  if (!campo) {
    crearAlerta('advertencia', 'Esta pantalla no tiene buscador de productos.');
    return;
  }

  campo.value = descripcion;
  campo.dispatchEvent(new Event('input', { bubbles: true }));
  campo.scrollIntoView({ block: 'center' });
  campo.focus();
  crearAlerta('info', 'Buscador lleno con la descripción del documento: elige el producto que corresponda.');
}

/**
 * Da de alta un concepto sin equivalente en el catálogo del proveedor emisor.
 *
 * NO recarga la pantalla: aquí se está capturando un gasto y un refresco
 * perdería todo lo que ya se escribió. El servidor contesta JSON y, en la misma
 * celda, el botón se sustituye por el resultado.
 *
 * @param {HTMLElement} boton [data-agregar-proveedor] de la fila
 */
function agregarAlProveedor(boton) {
  const meta = document.querySelector('meta[name="csrf"]');
  const datos = new FormData();
  datos.append('proveedor_id', boton.getAttribute('data-proveedor-id') || '');
  datos.append('descripcion', boton.getAttribute('data-descripcion') || '');
  datos.append('sku', boton.getAttribute('data-sku') || '');
  datos.append('nota', boton.getAttribute('data-nota') || '');
  datos.append('costo', boton.getAttribute('data-costo') || '0');

  boton.disabled = true;
  quitarErrorConcepto(boton);

  fetch('/admin/compras/catalogo/agregar', {
    method: 'POST',
    body: datos,
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': meta ? meta.content : '' },
  })
    .then(function (respuesta) {
      return respuesta
        .json()
        .catch(function () {
          return { ok: false, error: 'El servidor no respondió como se esperaba (' + respuesta.status + ').' };
        })
        .then(function (cuerpo) {
          if (!respuesta.ok || !cuerpo.ok) {
            throw new Error(cuerpo.error || 'No fue posible agregar el concepto.');
          }
          return cuerpo;
        });
    })
    .then(function (cuerpo) {
      marcarAgregado(boton, cuerpo);
    })
    .catch(function (error) {
      boton.disabled = false;
      avisarErrorConcepto(boton, error.message || 'No fue posible agregar el concepto.');
    });
}

/**
 * Sustituye el botón por el resultado: alta hecha, o aviso de que ya existía.
 *
 * @param {HTMLElement} boton
 * @param {object}      cuerpo {estado, nombre, proveedor, ruta_listado, activo}
 */
function marcarAgregado(boton, cuerpo) {
  const celda = boton.closest('[data-acciones-concepto]');
  if (!celda) {
    return;
  }

  const inexistente = cuerpo.estado === 'existente' && Number(cuerpo.activo) !== 1;
  const etiqueta = cuerpo.estado === 'creado'
    ? 'Agregado al catálogo de ' + cuerpo.proveedor
    : (inexistente
      ? 'Ya estaba en el catálogo de ' + cuerpo.proveedor + ' (inactivo)'
      : 'Ya estaba en el catálogo de ' + cuerpo.proveedor);

  const insignia = document.createElement('span');
  insignia.className = 'badge ' + (cuerpo.estado === 'creado' ? 'badge--aprobado' : 'badge--neutro');
  insignia.textContent = etiqueta;

  const enlace = document.createElement('a');
  enlace.className = 'texto-xs';
  enlace.href = cuerpo.ruta_listado;
  enlace.textContent = 'Ver catálogo';

  boton.remove();
  celda.appendChild(insignia);
  celda.appendChild(enlace);
  crearAlerta(
    cuerpo.estado === 'creado' ? 'exito' : 'info',
    cuerpo.estado === 'creado'
      ? '«' + cuerpo.nombre + '» quedó en el catálogo de ' + cuerpo.proveedor + ': la próxima factura suya ya lo reconoce.'
      : '«' + cuerpo.nombre + '» ya estaba en el catálogo de ' + cuerpo.proveedor + '.',
    'Catálogo del proveedor'
  );
}

/** Muestra el error del servidor bajo las acciones de la fila (una sola vez). */
function avisarErrorConcepto(boton, mensaje) {
  const celda = boton.closest('[data-acciones-concepto]');
  if (!celda) {
    return;
  }
  quitarErrorConcepto(boton);

  const nodo = document.createElement('span');
  nodo.className = 'campo__error';
  nodo.setAttribute('data-error-concepto', '');
  nodo.textContent = mensaje;
  celda.appendChild(nodo);
}

/** Quita el mensaje de error previo de esa misma fila. */
function quitarErrorConcepto(boton) {
  const celda = boton.closest('[data-acciones-concepto]');
  if (!celda) {
    return;
  }
  celda.querySelectorAll('[data-error-concepto]').forEach(function (nodo) {
    nodo.remove();
  });
}

/** Abre el diálogo de carga desde cualquiera de los botones [data-inteligente]. */
document.addEventListener('DOMContentLoaded', function () {
  document.addEventListener('click', function (evento) {
    if (!(evento.target instanceof Element)) {
      return;
    }

    const boton = evento.target.closest('[data-inteligente]');
    if (boton) {
      evento.preventDefault();
      abrirInteligente(boton);
      return;
    }

    const buscador = evento.target.closest('[data-cargar-busqueda]');
    if (buscador) {
      evento.preventDefault();
      buscarEnCatalogo(buscador.getAttribute('data-cargar-busqueda') || '');
      return;
    }

    const proveedor = evento.target.closest('[data-agregar-proveedor]');
    if (proveedor) {
      evento.preventDefault();
      agregarAlProveedor(proveedor);
    }
  });

  /* Llegada directa al formulario con ?lectura=… : si esa factura ya existe, se
   * muestra el mismo aviso que al subirla desde Contabilidad (lo pone la vista). */
  const coincidenciasNodo = document.querySelector('[data-coincidencias]');
  if (coincidenciasNodo) {
    try {
      const coincidencias = JSON.parse(coincidenciasNodo.getAttribute('data-coincidencias') || '[]');
      if (Array.isArray(coincidencias) && coincidencias.length) {
        avisarDuplicado(
          coincidencias,
          coincidenciasNodo.getAttribute('data-ruta') || '',
          coincidenciasNodo.getAttribute('data-origen') || 'Registro inteligente'
        );
      }
    } catch (error) {
      // Con el JSON ilegible no se abre nada: el formulario sigue siendo usable.
    }
  }
});
