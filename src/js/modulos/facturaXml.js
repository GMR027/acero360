/**
 * modulos/facturaXml.js
 * ---------------------------------------------------------------------------
 * Al elegir el XML de una factura en «Facturación y cobro» de una venta, lee
 * sus datos en el navegador y llena los campos que estén VACÍOS:
 *
 *   folio, UUID, fecha, método y forma de pago, uso y tipo de comprobante,
 *   RFC, código postal y régimen fiscal del receptor.
 *
 * Nunca sobrescribe un dato que la persona ya haya escrito: si el XML dice algo
 * distinto, lo avisa para que lo revise. El servidor vuelve a leer el archivo y
 * aplica la misma regla antes de guardar.
 *
 * También atiende el formulario «Completar con el XML» de una venta que ya
 * tiene CFDI: ahí sólo muestra el resumen de lo que se completará, porque el
 * UUID y el documento actual no se cambian.
 *
 * Reglas del proyecto: createElement (nunca innerHTML), sin alert/confirm.
 */

/** Atributo de un nodo, ya recortado. */
function atributoFactura(nodo, nombre) {
  return nodo ? (nodo.getAttribute(nombre) || '').trim() : '';
}

/** Primer nodo por nombre local (sin depender del namespace del CFDI). */
function nodoFactura(documento, etiqueta) {
  return documento.getElementsByTagNameNS('*', etiqueta)[0] || null;
}

/** Forma de pago 28 → 05, igual que VentasFiscal::normalizarFormaPago. */
function normalizarFormaFactura(clave) {
  return clave === '28' ? '05' : clave;
}

/** ¿El valor ya está en las opciones del select? */
function opcionExiste(select, valor) {
  return Array.prototype.some.call(select.options, function (opcion) {
    return opcion.value === valor;
  });
}

/** Escribe el valor sólo si el campo está vacío. Devuelve true si lo llenó. */
function llenarSiVacio(campo, valor) {
  if (!campo || valor === undefined || valor === null || String(valor).trim() === '') {
    return false;
  }
  if (campo.value !== undefined && String(campo.value).trim() !== '') {
    return false;
  }
  if (campo.tagName === 'SELECT') {
    if (!opcionExiste(campo, String(valor))) {
      return false;
    }
    campo.value = String(valor);
    return true;
  }
  campo.value = String(valor);
  return true;
}

/** Muestra el resumen dentro del contenedor de avisos. */
function pintarResumenXml(contenedor, titulo, lineas, diferencias, error) {
  if (!contenedor) {
    return;
  }
  contenedor.textContent = '';
  contenedor.hidden = false;
  contenedor.classList.toggle('nota--advertencia', Boolean(error));
  contenedor.classList.toggle('nota--info', !error);

  const encabezado = document.createElement('strong');
  encabezado.textContent = titulo;
  contenedor.appendChild(encabezado);

  if (error) {
    const parrafo = document.createElement('p');
    parrafo.className = 'texto-sm';
    parrafo.textContent = error;
    contenedor.appendChild(parrafo);
    return;
  }

  if (lineas.length) {
    const lista = document.createElement('ul');
    lista.className = 'lista-simple texto-sm';
    lineas.forEach(function (linea) {
      const item = document.createElement('li');
      item.textContent = linea;
      lista.appendChild(item);
    });
    contenedor.appendChild(lista);
  }

  if (diferencias.length) {
    const lista = document.createElement('ul');
    lista.className = 'lista-simple texto-sm';
    diferencias.forEach(function (diferencia) {
      const item = document.createElement('li');
      item.className = 'texto-error';
      item.textContent = diferencia;
      lista.appendChild(item);
    });
    contenedor.appendChild(lista);
  }
}

/** Lee un archivo como texto. */
async function leerArchivoTexto(archivo) {
  if (typeof archivo.text === 'function') {
    return archivo.text();
  }
  return new Promise(function (resolver, rechazar) {
    const lector = new FileReader();
    lector.onload = function () { resolver(String(lector.result || '')); };
    lector.onerror = function () { rechazar(new Error('No se pudo leer el XML.')); };
    lector.readAsText(archivo, 'UTF-8');
  });
}

/**
 * Procesa los archivos elegidos: toma el primer XML, lo lee y llena el
 * formulario. Devuelve una promesa para que la prueba o el llamador puedan
 * esperar a que termine.
 */
async function aplicarXmlFactura(entrada, opciones) {
  const opcionesEfectivas = opciones || {};
  const contenedor = opcionesEfectivas.contenedor || null;
  const archivos = Array.prototype.slice.call(entrada.files || []);
  const xml = archivos.find(function (archivo) {
    return /\.xml$/i.test(archivo.name) || archivo.type === 'text/xml' || archivo.type === 'application/xml';
  });

  if (!xml) {
    if (contenedor) {
      contenedor.hidden = true;
      contenedor.textContent = '';
    }
    return;
  }

  let texto;
  try {
    texto = await leerArchivoTexto(xml);
  } catch (error) {
    pintarResumenXml(contenedor, 'No se pudo leer el XML', [], [], error.message);
    return;
  }

  const documento = new DOMParser().parseFromString(texto, 'application/xml');
  if (documento.getElementsByTagName('parsererror').length) {
    pintarResumenXml(contenedor, 'El XML no es válido', [], [], 'Revisa que el archivo sea el XML original del CFDI.');
    return;
  }

  const comprobante = nodoFactura(documento, 'Comprobante');
  if (!comprobante) {
    pintarResumenXml(contenedor, 'El archivo no es un CFDI', [], [], 'No se encontró el nodo Comprobante.');
    return;
  }

  const tipo = atributoFactura(comprobante, 'TipoDeComprobante').toUpperCase();
  if (tipo === 'P') {
    pintarResumenXml(contenedor, 'Es un complemento de pago', [], [],
      'Este XML es un CFDI tipo P (complemento de pago), no una factura. Cárgalo en Ventas → Complementos de pago.');
    return;
  }

  const emisor = nodoFactura(documento, 'Emisor');
  const receptor = nodoFactura(documento, 'Receptor');
  const timbre = nodoFactura(documento, 'TimbreFiscalDigital');
  const fecha = atributoFactura(comprobante, 'Fecha').slice(0, 10);
  const folio = atributoFactura(comprobante, 'Serie') + atributoFactura(comprobante, 'Folio');
  const uuid = atributoFactura(timbre, 'UUID').toUpperCase();
  const metodo = atributoFactura(comprobante, 'MetodoPago').toUpperCase();
  const forma = normalizarFormaFactura(atributoFactura(comprobante, 'FormaPago'));
  const uso = atributoFactura(receptor, 'UsoCFDI').toUpperCase();
  const rfc = atributoFactura(receptor, 'Rfc').toUpperCase();
  const cp = atributoFactura(receptor, 'DomicilioFiscalReceptor') || atributoFactura(receptor, 'DomicilioFiscal');
  const regimen = atributoFactura(receptor, 'RegimenFiscalReceptor');
  const total = atributoFactura(comprobante, 'Total');

  const llenados = [];
  const diferencias = [];

  const registrar = function (etiqueta, valor) {
    if (valor) {
      llenados.push(etiqueta + ': ' + valor);
    }
  };

  if (opcionesEfectivas.soloResumen) {
    /* Venta ya facturada: no hay campos que llenar en pantalla; el servidor
     * completa los huecos y conserva UUID y documento. */
    registrar('Folio', folio);
    registrar('UUID', uuid);
    registrar('Fecha', fecha);
    registrar('Método de pago', metodo);
    registrar('Forma de pago', forma);
    registrar('Total del XML', total);
  } else {
    const campos = opcionesEfectivas.campos || {};
    const totalVenta = Number.parseFloat(opcionesEfectivas.totalVenta || '');

    if (campos.rfc && campos.rfc.tagName === 'SELECT') {
      const opcion = Array.prototype.find.call(campos.rfc.options, function (o) {
        return o.value.toUpperCase() === rfc;
      });
      if (opcion && campos.rfc.value.toUpperCase() !== rfc) {
        campos.rfc.value = opcion.value;
        campos.rfc.dispatchEvent(new Event('change', { bubbles: true }));
        registrar('RFC del receptor', rfc);
      } else if (!opcion && rfc) {
        diferencias.push('El XML es del RFC ' + rfc + ', que no está entre los RFC del cliente: no se cambió.');
      }
    } else if (llenarSiVacio(campos.rfc, rfc)) {
      registrar('RFC del receptor', rfc);
    }

    if (llenarSiVacio(campos.folio, folio)) { registrar('Folio de la factura', folio); }
    if (llenarSiVacio(campos.uuid, uuid)) { registrar('Folio fiscal UUID', uuid); }
    if (llenarSiVacio(campos.fecha, fecha)) { registrar('Fecha de la factura', fecha); }
    if (llenarSiVacio(campos.metodo, metodo)) { registrar('Método de pago', metodo); }
    if (llenarSiVacio(campos.forma, forma)) { registrar('Forma de pago', forma); }
    if (llenarSiVacio(campos.uso, uso)) { registrar('Uso del CFDI', uso); }
    if (llenarSiVacio(campos.tipo, tipo)) { registrar('Tipo de comprobante', tipo); }
    if (llenarSiVacio(campos.cp, cp)) { registrar('Código postal fiscal', cp); }
    if (llenarSiVacio(campos.regimen, regimen)) { registrar('Régimen fiscal', regimen); }

    /* Avisos de diferencia: no se sobrescribe lo que ya estaba. */
    const comparar = function (etiqueta, campo, valor) {
      if (!campo || !valor || campo.value === undefined) {
        return;
      }
      if (String(campo.value).trim() !== '' && String(campo.value).trim().toUpperCase() !== String(valor).toUpperCase()) {
        diferencias.push('El XML trae ' + etiqueta + ' «' + valor + '» y el formulario tiene «' + campo.value + '»: se conserva el del formulario.');
      }
    };
    comparar('RFC', campos.rfc, rfc);
    comparar('código postal', campos.cp, cp);
    comparar('régimen fiscal', campos.regimen, regimen);
    comparar('método de pago', campos.metodo, metodo);
    comparar('forma de pago', campos.forma, forma);
    comparar('uso del CFDI', campos.uso, uso);

    if (Number.isFinite(totalVenta) && total !== '' && Math.abs(Number.parseFloat(total) - totalVenta) > 0.009) {
      diferencias.push('El total del XML (' + total + ') no coincide con el de la venta. Si es correcto, marca la casilla de discrepancia.');
    }
  }

  const titulo = llenados.length
    ? 'Datos leídos del XML (' + llenados.length + ')'
    : 'XML leído, pero no había campos vacíos que llenar';
  pintarResumenXml(contenedor, titulo, llenados, diferencias);
}

/** Conecta las dos formas de la pantalla de facturación. */
function inicializarFacturaXml() {
  const principal = document.getElementById('doc-archivo');
  if (principal) {
    const form = principal.closest('form');
    principal.addEventListener('change', function () {
      aplicarXmlFactura(principal, {
        contenedor: document.getElementById('xml-leido'),
        totalVenta: form ? form.getAttribute('data-total-venta') : '',
        campos: {
          rfc: document.getElementById('f-rfc'),
          cp: document.getElementById('f-cp'),
          regimen: document.getElementById('f-regimen'),
          folio: document.getElementById('factura_folio'),
          uuid: document.getElementById('uuid_fiscal'),
          fecha: document.getElementById('fecha_facturacion'),
          metodo: document.getElementById('f-metodo'),
          forma: document.getElementById('f-forma'),
          uso: document.getElementById('f-uso'),
          tipo: document.getElementById('f-tipo'),
        },
      });
    });
  }

  const completar = document.getElementById('doc-completar');
  if (completar) {
    completar.addEventListener('change', function () {
      aplicarXmlFactura(completar, {
        contenedor: document.getElementById('xml-leido-completar'),
        soloResumen: true,
      });
    });
  }
}
