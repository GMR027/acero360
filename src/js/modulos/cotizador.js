/**
 * modulos/cotizador.js
 * ---------------------------------------------------------------------------
 * Motor de la cotización: catálogo visual, renglones y totales en vivo.
 *
 * El navegador sólo hace una VISTA PREVIA: el servidor vuelve a recalcular
 * todos los importes con Cotizaciones::calcular() al guardar.
 *
 * Reglas del proyecto aplicadas en este archivo:
 *  - sólo let y const (nunca var)
 *  - sin alert(), confirm() ni prompt(): el feedback usa crearAlerta()
 *  - sin innerHTML: todo se construye con document.createElement + appendChild
 *  - preventDefault() en todo clic o envío que se intercepta
 */

/* --------------------------------------------------------------------------
   FORMATO
   -------------------------------------------------------------------------- */

/** Formatea un importe como moneda mexicana: $1,234.50 */
function monedaCotizador(monto) {
  const valor = Number.isFinite(monto) ? monto : 0;
  return (
    '$' +
    valor.toLocaleString('es-MX', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

/** Ruta de la miniatura 300×300 de una imagen de producto. */
function miniaturaCotizador(imagen) {
  if (!imagen) {
    return '';
  }
  return '/public/image/' + imagen.replace(/\.([a-z0-9]+)$/i, '_mini.$1');
}

/**
 * Importe con letra (vista previa; el PDF usa la versión del servidor).
 *
 * @param {number} monto
 * @returns {string}
 */
function letraCotizador(monto) {
  const unidades = [
    '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho',
    'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince',
    'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
    'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco',
    'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
  ];
  const decenas = [
    '', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta',
    'setenta', 'ochenta', 'noventa',
  ];
  const centenas = [
    '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
    'seiscientos', 'setecientos', 'ochocientos', 'novecientos',
  ];

  /** Escrito de un número de 1 a 999. */
  const grupo = function (n) {
    if (n === 0) {
      return '';
    }
    if (n === 100) {
      return 'cien';
    }

    const partes = [];
    const c = Math.floor(n / 100);
    const r = n % 100;

    if (c > 0) {
      partes.push(centenas[c]);
    }

    if (r > 0) {
      if (r < 30) {
        partes.push(unidades[r]);
      } else {
        const d = Math.floor(r / 10);
        const u = r % 10;
        partes.push(u > 0 ? decenas[d] + ' y ' + unidades[u] : decenas[d]);
      }
    }

    return partes.join(' ');
  };

  const redondeado = Math.round((Number.isFinite(monto) ? monto : 0) * 100) / 100;
  const entero = Math.floor(redondeado);
  const centavos = Math.round((redondeado - entero) * 100);
  const formatoCentavos = String(centavos).padStart(2, '0') + '/100 M.N.';

  if (entero === 0) {
    return 'Cero pesos ' + formatoCentavos;
  }

  const partes = [];
  const millones = Math.floor(entero / 1000000);
  const resto = entero % 1000000;
  const miles = Math.floor(resto / 1000);
  const unidadesResto = resto % 1000;

  if (millones > 0) {
    partes.push(millones === 1 ? 'un millón' : grupo(millones) + ' millones');
  }
  if (miles > 0) {
    partes.push(miles === 1 ? 'mil' : grupo(miles) + ' mil');
  }
  if (unidadesResto > 0) {
    partes.push(grupo(unidadesResto));
  }

  let texto = partes.join(' ');
  texto = texto.replace(/\bveintiuno\b/g, 'veintiún').replace(/\buno$/g, 'un');

  const inicial = texto.charAt(0).toUpperCase() + texto.slice(1);
  return inicial + ' pesos ' + formatoCentavos;
}

/* --------------------------------------------------------------------------
   ESTADO DEL COTIZADOR
   -------------------------------------------------------------------------- */

/** Nodos del formulario cacheados al iniciar (null hasta inicializar). */
let cotizadorNodos = null;

/** Renglones cargados: [{id, nombre, sku, unidad, imagen, precio_lista, ...}] */
let cotizadorLineas = [];

/** Descuento global (%) e indicador de IVA. */
let cotizadorDescuento = 0;
let cotizadorIva = true;

/** Mapa producto_id → datos del catálogo (precio de lista, imagen, etc.). */
let cotizadorCatalogo = new Map();

/** Mensajes que el servidor dejó en #cotizador-estado. */
let cotizadorMensajes = {};

/** Lee un mensaje configurado en el servidor. */
function msgCotizador(clave, porDefecto) {
  return cotizadorMensajes[clave] || porDefecto;
}

/* --------------------------------------------------------------------------
   CONSTRUCCIÓN DE PIEZAS (siempre createElement, nunca innerHTML)
   -------------------------------------------------------------------------- */

/**
 * Crea un elemento simple.
 *
 * @param {string} etiqueta  Tag a crear.
 * @param {string} clase     Clase CSS (opcional).
 * @param {string} texto     Contenido de texto (opcional).
 */
function nodoCotizador(etiqueta, clase, texto) {
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
 * Crea un campo de formulario (input) para un renglón.
 *
 * @param {object} opciones {tipo, nombre, valor, min, max, paso, titulo, clase}
 */
function campoCotizador(opciones) {
  const input = document.createElement('input');
  input.type = opciones.tipo || 'number';
  input.className = opciones.clase || 'control control--moneda';
  input.name = opciones.nombre;
  input.value = opciones.valor;

  if (opciones.min !== undefined) {
    input.min = String(opciones.min);
  }
  if (opciones.max !== undefined) {
    input.max = String(opciones.max);
  }
  if (opciones.paso !== undefined) {
    input.step = String(opciones.paso);
  }
  if (opciones.titulo) {
    input.title = opciones.titulo;
    input.setAttribute('aria-label', opciones.titulo);
  }

  return input;
}

/* --------------------------------------------------------------------------
   CATÁLOGO
   -------------------------------------------------------------------------- */

/** Recorre las tarjetas del catálogo y arma el mapa de productos. */
function catalogoCotizador() {
  cotizadorCatalogo = new Map();

  document.querySelectorAll('[data-cotizador-producto]').forEach(function (tarjeta) {
    const id = Number.parseInt(tarjeta.getAttribute('data-id'), 10);
    if (!Number.isFinite(id)) {
      return;
    }

    cotizadorCatalogo.set(id, {
      id: id,
      empresa: Number.parseInt(tarjeta.getAttribute('data-empresa'), 10) || 0,
      empresas: (tarjeta.getAttribute('data-empresas') || '').split(',').map(Number).filter(Boolean),
      nombre: tarjeta.getAttribute('data-nombre') || '',
      sku: tarjeta.getAttribute('data-sku') || '',
      categoria: tarjeta.getAttribute('data-categoria') || '',
      unidad: tarjeta.getAttribute('data-unidad') || 'pza',
      precio: Number.parseFloat(tarjeta.getAttribute('data-precio')) || 0,
      imagen: tarjeta.getAttribute('data-imagen') || '',
      buscar: tarjeta.getAttribute('data-buscar') || '',
      tarjeta: tarjeta,
    });
  });
}

/** Filtra las tarjetas según el texto y el negocio elegidos. */
function filtrarCatalogo() {
  if (!cotizadorNodos) {
    return;
  }

  const texto = (cotizadorNodos.busqueda.value || '').trim().toLowerCase();
  const negocio = Number.parseInt(cotizadorNodos.catalogoEmpresa.value, 10) || 0;
  let visibles = 0;

  cotizadorCatalogo.forEach(function (producto) {
    const coincide = (texto === '' || producto.buscar.indexOf(texto) !== -1)
      && (negocio === 0 || producto.empresas.includes(negocio) || producto.empresa === negocio);

    producto.tarjeta.hidden = !coincide;
    if (coincide) {
      visibles++;
    }
  });

  cotizadorNodos.visibles.textContent = String(visibles);
  cotizadorNodos.sinResultados.hidden = visibles > 0;
  cotizadorNodos.sinResultados.textContent = msgCotizador(
    'sin-resultados',
    'Ningún producto coincide con la búsqueda.'
  );
}

/* --------------------------------------------------------------------------
   RENGLONES
   -------------------------------------------------------------------------- */

/** Devuelve el renglón de un producto o undefined si no está cargado. */
function lineaCotizador(productoId) {
  return cotizadorLineas.find(function (linea) {
    return linea.id === productoId;
  });
}

/** Agrega un producto del catálogo como renglón de la cotización. */
function agregarLineaCotizador(producto) {
  if (!producto) {
    return false;
  }

  if (lineaCotizador(producto.id)) {
    crearAlerta('advertencia', msgCotizador('duplicado', 'Ese producto ya está en la cotización'));
    return false;
  }

  cotizadorLineas.push({
    id: producto.id,
    nombre: producto.nombre,
    sku: producto.sku,
    categoria: producto.categoria,
    unidad: producto.unidad,
    imagen: producto.imagen,
    precio_lista: producto.precio,
    cantidad: 1,
    precio: producto.precio,
    descuento_pct: 0,
    no_recordar: false,
  });

  crearAlerta('exito', msgCotizador('agregado', 'Producto agregado'));
  return true;
}

/** Quita un renglón por su identificador de producto. */
function quitarLineaCotizador(productoId) {
  cotizadorLineas = cotizadorLineas.filter(function (linea) {
    return linea.id !== productoId;
  });
}

/**
 * Construye una fila completa de la tabla de renglones.
 *
 * @param {object} linea
 * @param {number} indice
 * @returns {HTMLTableRowElement}
 */
function filaCotizador(linea, indice) {
  const fila = document.createElement('tr');
  fila.setAttribute('data-fila', String(linea.id));

  // 1. Número de renglón.
  const celdaIndice = document.createElement('td');
  celdaIndice.className = 'tabla--numerica cifra';
  celdaIndice.textContent = String(indice + 1);
  fila.appendChild(celdaIndice);

  // 2. Producto con su imagen.
  const celdaProducto = document.createElement('td');
  const envoltorio = nodoCotizador('div', 'tabla__producto');

  const rutaMiniatura = miniaturaCotizador(linea.imagen);
  if (rutaMiniatura !== '') {
    const imagen = document.createElement('img');
    imagen.className = 'tabla__miniatura';
    imagen.src = rutaMiniatura;
    imagen.alt = '';
    imagen.width = 300;
    imagen.height = 300;
    imagen.loading = 'lazy';
    envoltorio.appendChild(imagen);
  } else {
    const marca = nodoCotizador('span', 'tabla__producto-img tabla__producto-img--vacia');
    marca.setAttribute('aria-hidden', 'true');
    marca.textContent = 'inventory_2';
    marca.classList.add('material-symbols-outlined');
    envoltorio.appendChild(marca);
  }

  const textos = nodoCotizador('div', 'tabla__producto-textos');
  textos.appendChild(nodoCotizador('span', '', linea.nombre));

  const detalle = nodoCotizador('span', 'texto-secundario');
  const partes = [];
  if (linea.sku) {
    partes.push(linea.sku);
  }
  if (linea.categoria) {
    partes.push(linea.categoria);
  }
  if (partes.length > 0) {
    detalle.textContent = partes.join(' · ');
    textos.appendChild(detalle);
  }

  const esEspecial = Math.abs(linea.precio - linea.precio_lista) > 0.009;
  if (esEspecial) {
    const sello = nodoCotizador('span', 'badge badge--vip', 'Precio especial');
    textos.appendChild(sello);
  }

  envoltorio.appendChild(textos);
  celdaProducto.appendChild(envoltorio);
  fila.appendChild(celdaProducto);

  // 3. Precio de lista (sólo lectura: es el precio fijo del catálogo).
  const celdaLista = document.createElement('td');
  celdaLista.className = 'tabla--numerica cifra';
  celdaLista.textContent = monedaCotizador(linea.precio_lista);
  fila.appendChild(celdaLista);

  // 4. Precio cliente + casilla "No recordar este precio".
  const celdaPrecio = document.createElement('td');
  celdaPrecio.className = 'cotizador-celda-precio';

  celdaPrecio.appendChild(
    campoCotizador({
      tipo: 'number',
      nombre: 'lineas[' + linea.id + '][precio]',
      valor: linea.precio.toFixed(2),
      min: 0,
      paso: '0.01',
      titulo: 'Precio negociado con el cliente',
      clase: 'control control--moneda',
    })
  );

  const etiquetaRecordar = document.createElement('label');
  etiquetaRecordar.className = 'comprobacion cotizador-recordar';
  etiquetaRecordar.title = 'Si lo marcas, el precio no se guarda como acuerdo con el cliente';

  const casillaRecordar = document.createElement('input');
  casillaRecordar.type = 'checkbox';
  casillaRecordar.name = 'lineas[' + linea.id + '][no_recordar]';
  casillaRecordar.value = '1';
  casillaRecordar.checked = linea.no_recordar;

  const textoRecordar = nodoCotizador('span', '', 'No recordar este precio');
  etiquetaRecordar.appendChild(casillaRecordar);
  etiquetaRecordar.appendChild(textoRecordar);
  celdaPrecio.appendChild(etiquetaRecordar);

  fila.appendChild(celdaPrecio);

  // 5. Cantidad.
  const celdaCantidad = document.createElement('td');
  celdaCantidad.className = 'cotizador-celda-cantidad';
  celdaCantidad.appendChild(
    campoCotizador({
      tipo: 'number',
      nombre: 'lineas[' + linea.id + '][cantidad]',
      valor: String(linea.cantidad),
      min: 1,
      paso: '1',
      titulo: 'Cantidad',
      clase: 'control control--cantidad',
    })
  );
  fila.appendChild(celdaCantidad);

  // 6. Descuento del renglón.
  const celdaDescuento = document.createElement('td');
  celdaDescuento.className = 'cotizador-celda-descuento';
  celdaDescuento.appendChild(
    campoCotizador({
      tipo: 'number',
      nombre: 'lineas[' + linea.id + '][descuento_pct]',
      valor: String(linea.descuento_pct),
      min: 0,
      max: 100,
      paso: '0.5',
      titulo: 'Descuento del renglón (%)',
      clase: 'control control--moneda',
    })
  );
  fila.appendChild(celdaDescuento);

  // 7. Importe del renglón.
  const celdaTotal = document.createElement('td');
  celdaTotal.className = 'tabla--numerica cifra';
  celdaTotal.setAttribute('data-fila-total', String(linea.id));
  celdaTotal.textContent = monedaCotizador(totalLineaCotizador(linea));
  fila.appendChild(celdaTotal);

  // 8. Eliminar renglón.
  const celdaAccion = document.createElement('td');
  celdaAccion.className = 'tabla--centro';

  const botonQuitar = document.createElement('button');
  botonQuitar.type = 'button';
  botonQuitar.className = 'btn btn--peligro btn--sm btn--icono';
  botonQuitar.title = 'Eliminar renglón';
  botonQuitar.setAttribute('aria-label', 'Eliminar ' + linea.nombre);
  botonQuitar.setAttribute('data-quitar-fila', String(linea.id));

  const iconoQuitar = nodoCotizador('span', 'material-symbols-outlined', 'delete');
  iconoQuitar.setAttribute('aria-hidden', 'true');
  iconoQuitar.style.fontSize = '1.125rem';
  botonQuitar.appendChild(iconoQuitar);

  celdaAccion.appendChild(botonQuitar);
  fila.appendChild(celdaAccion);

  // Etiquetas para la vista de tarjetas en celular (el CSS las muestra delante de cada dato).
  const etiquetasColumnas = ['#', 'Producto', 'Precio lista', 'Precio cliente', 'Cantidad', 'Descuento (%)', 'Total', ''];
  Array.from(fila.children).forEach(function (celda, posicion) {
    celda.setAttribute('data-label', etiquetasColumnas[posicion] || '');
  });

  return fila;
}

/** Importe neto de un renglón (cantidad × precio con su descuento). */
function totalLineaCotizador(linea) {
  const cantidad = Math.max(1, Math.round(Number(linea.cantidad) || 1));
  const precio = Math.max(0, Number(linea.precio) || 0);
  const pct = Math.min(100, Math.max(0, Number(linea.descuento_pct) || 0));
  const bruto = cantidad * precio;
  return Math.round((bruto - bruto * (pct / 100)) * 100) / 100;
}

/* --------------------------------------------------------------------------
   TOTALES
   -------------------------------------------------------------------------- */

/** Recalcula el resumen y lo pinta en el DOM. */
function totalesCotizador() {
  if (!cotizadorNodos) {
    return;
  }

  let subtotal = 0;

  cotizadorLineas.forEach(function (linea) {
    const cantidad = Math.max(1, Math.round(Number(linea.cantidad) || 1));
    const precio = Math.max(0, Number(linea.precio) || 0);
    subtotal += cantidad * precio;

    const celda = cotizadorNodos.cuerpo.querySelector('[data-fila-total="' + linea.id + '"]');
    if (celda) {
      celda.textContent = monedaCotizador(totalLineaCotizador(linea));
    }
  });

  subtotal = Math.round(subtotal * 100) / 100;

  // Descuento por renglón.
  let descuentoRenglones = 0;
  cotizadorLineas.forEach(function (linea) {
    const cantidad = Math.max(1, Math.round(Number(linea.cantidad) || 1));
    const precio = Math.max(0, Number(linea.precio) || 0);
    const pct = Math.min(100, Math.max(0, Number(linea.descuento_pct) || 0));
    const bruto = cantidad * precio;
    descuentoRenglones += bruto - bruto * (1 - pct / 100);
  });
  descuentoRenglones = Math.round(descuentoRenglones * 100) / 100;

  const pctGlobal = Math.min(100, Math.max(0, Number(cotizadorDescuento) || 0));
  const baseTrasRenglones = Math.round((subtotal - descuentoRenglones) * 100) / 100;
  const descuentoGlobal = Math.round(baseTrasRenglones * (pctGlobal / 100) * 100) / 100;
  const descuento = Math.round((descuentoRenglones + descuentoGlobal) * 100) / 100;
  const base = Math.round((subtotal - descuento) * 100) / 100;
  const iva = cotizadorIva ? Math.round(base * 0.16 * 100) / 100 : 0;
  const total = Math.round((base + iva) * 100) / 100;

  cotizadorNodos.resumen.subtotal.textContent = monedaCotizador(subtotal);
  cotizadorNodos.resumen.descuento.textContent = '-' + monedaCotizador(descuento);
  cotizadorNodos.resumen.base.textContent = monedaCotizador(base);
  cotizadorNodos.resumen.iva.textContent = monedaCotizador(iva);
  cotizadorNodos.resumen.total.textContent = monedaCotizador(total);
  cotizadorNodos.resumen.letra.textContent = letraCotizador(total);
  cotizadorNodos.pieSubtotal.textContent = monedaCotizador(subtotal);

  const vacio = cotizadorLineas.length === 0;
  cotizadorNodos.pieVacio.hidden = !vacio;
  cotizadorNodos.pieVacio.textContent = msgCotizador('vacio', 'Sin renglones cargados');
}

/* --------------------------------------------------------------------------
   PINTADO
   -------------------------------------------------------------------------- */

/** Reconstruye la tabla de renglones completa y recalcula. */
function pintarCotizador() {
  if (!cotizadorNodos) {
    return;
  }

  const cuerpo = cotizadorNodos.cuerpo;
  while (cuerpo.firstChild) {
    cuerpo.removeChild(cuerpo.firstChild);
  }

  cotizadorLineas.forEach(function (linea, indice) {
    cuerpo.appendChild(filaCotizador(linea, indice));
  });

  totalesCotizador();
}

/* --------------------------------------------------------------------------
   INTERACCIÓN
   -------------------------------------------------------------------------- */

/**
 * Lee el valor de un campo de un renglón y lo guarda en el estado.
 *
 * @param {HTMLInputElement} campo
 * @param {string}           atributo
 */
function leerCampoLinea(campo, atributo) {
  const fila = campo.closest('tr');
  if (!fila) {
    return;
  }

  const productoId = Number.parseInt(fila.getAttribute('data-fila'), 10);
  const linea = lineaCotizador(productoId);
  if (!linea) {
    return;
  }

  if (atributo === 'no_recordar') {
    linea.no_recordar = campo.checked;
    return;
  }

  const valor = Number.parseFloat(campo.value);
  linea[atributo] = Number.isFinite(valor) ? valor : 0;
}

/**
 * Cuando cambia el cliente se piden sus precios acordados al servidor.
 *
 * Se usa fetch + JSON: la respuesta no contiene HTML, sólo datos, de modo
 * que las filas se reconstruyen con createElement().
 */
function actualizarClienteCotizador() {
  if (!cotizadorNodos) {
    return;
  }

  const selector = cotizadorNodos.cliente;
  const clienteId = Number.parseInt(selector.value, 10) || 0;
  const opcion = selector.options[selector.selectedIndex];

  // Descuento por defecto tomado de la ficha del cliente.
  const descuentoFicha = opcion ? Number.parseFloat(opcion.getAttribute('data-descuento')) : NaN;
  cotizadorDescuento = Number.isFinite(descuentoFicha) ? descuentoFicha : 0;
  cotizadorNodos.descuento.value = String(cotizadorDescuento);

  if (clienteId === 0) {
    return;
  }

  const ruta = cotizadorNodos.estado.getAttribute('data-ruta-precios');

  fetch(ruta + '?cliente=' + clienteId, { credentials: 'same-origin' })
    .then(function (respuesta) {
      if (!respuesta.ok) {
        throw new Error('HTTP ' + respuesta.status);
      }
      return respuesta.json();
    })
    .then(function (datos) {
      const precios = datos && datos.precios ? datos.precios : {};
      let actualizados = 0;

      cotizadorLineas.forEach(function (linea) {
        const especial = precios[String(linea.id)];
        const nuevo = especial !== undefined ? Number(especial) : linea.precio_lista;

        if (Math.abs(nuevo - linea.precio) > 0.009) {
          actualizados++;
        }
        linea.precio = nuevo;
      });

      pintarCotizador();

      if (actualizados > 0) {
        crearAlerta('info', msgCotizador('precios', 'Precios actualizados para el nuevo cliente'));
      }
    })
    .catch(function () {
      crearAlerta('advertencia', 'No se pudieron consultar los precios del cliente.');
    });
}

/**
 * Copia las condiciones del negocio elegido en los textareas.
 *
 * Sólo se sustituye el texto cuando el campo está vacío o cuando todavía
 * contiene las condiciones del negocio anterior (así no se pisan las notas
 * que el usuario haya redactado a mano).
 */
let cotizadorCondiciones = { envio: '', compra: '' };

function registrarCondicionesIniciales() {
  if (!cotizadorNodos || !cotizadorNodos.empresa) {
    return;
  }

  const opcion = cotizadorNodos.empresa.options[cotizadorNodos.empresa.selectedIndex];
  if (!opcion) {
    return;
  }

  cotizadorCondiciones = {
    envio: (opcion.getAttribute('data-envio') || '').replace(/\n/g, ' '),
    compra: (opcion.getAttribute('data-compra') || '').replace(/\n/g, ' '),
  };
}

function actualizarCondicionesCotizador() {
  if (!cotizadorNodos || !cotizadorNodos.empresa) {
    return;
  }

  const opcion = cotizadorNodos.empresa.options[cotizadorNodos.empresa.selectedIndex];
  if (!opcion) {
    return;
  }

  const envio = (opcion.getAttribute('data-envio') || '').replace(/\n/g, ' ');
  const compra = (opcion.getAttribute('data-compra') || '').replace(/\n/g, ' ');

  const aplicar = function (elemento, valor, previo) {
    if (!elemento) {
      return;
    }
    const actual = elemento.value.trim();
    if (actual === '' || actual === previo) {
      elemento.value = valor;
    }
  };

  aplicar(cotizadorNodos.condicionesEnvio, envio, cotizadorCondiciones.envio);
  aplicar(cotizadorNodos.condicionesCompra, compra, cotizadorCondiciones.compra);

  cotizadorCondiciones = { envio: envio, compra: compra };
}

/* --------------------------------------------------------------------------
   INICIALIZACIÓN
   -------------------------------------------------------------------------- */

/** Cachea los nodos necesarios del formulario del cotizador. */
function referenciasCotizador(formulario) {
  const estado = document.getElementById('cotizador-estado');
  if (!estado) {
    return null;
  }

  const resumen = {};
  document.querySelectorAll('[data-resumen]').forEach(function (elemento) {
    resumen[elemento.getAttribute('data-resumen')] = elemento;
  });

  return {
    formulario: formulario,
    estado: estado,
    cuerpo: document.getElementById('cuerpo-renglones'),
    cliente: formulario.querySelector('#cliente_id'),
    empresa: formulario.querySelector('#empresa_id'),
    descuento: formulario.querySelector('#descuento_pct'),
    checkIva: formulario.querySelector('#aplica_iva'),
    condicionesEnvio: formulario.querySelector('#condiciones_envio'),
    condicionesCompra: formulario.querySelector('#condiciones_compra'),
    busqueda: document.getElementById('buscar-producto'),
    catalogoEmpresa: document.getElementById('catalogo-empresa'),
    visibles: document.querySelector('[data-cotizador-visibles]'),
    sinResultados: document.querySelector('[data-cotizador-sin-resultados]'),
    pieSubtotal: document.querySelector('[data-cotizador-subtotal]'),
    pieVacio: document.querySelector('[data-cotizador-vacio]'),
    resumen: resumen,
  };
}

/** Carga el estado inicial (renglones ya guardados y descuento). */
function estadoInicialCotizador() {
  let datos = { lineas: [], descuento: 0, iva: true };

  try {
    datos = JSON.parse(cotizadorNodos.estado.getAttribute('data-estado') || '{}');
  } catch (error) {
    datos = { lineas: [], descuento: 0, iva: true };
  }

  cotizadorLineas = Array.isArray(datos.lineas) ? datos.lineas : [];
  cotizadorDescuento = Number(datos.descuento) || 0;
  cotizadorIva = datos.iva !== false;

  // Los mensajes de aviso viven en el mismo nodo.
  cotizadorMensajes = {};
  [
    'agregado', 'duplicado', 'sin-resultados', 'precios',
    'vacio', 'precio', 'cantidad',
  ].forEach(function (clave) {
    cotizadorMensajes[clave] = cotizadorNodos.estado.getAttribute('data-msg-' + clave) || '';
  });
}

/** Arranca el cotizador si la página tiene el formulario. */
function inicializarCotizador() {
  const formulario = document.querySelector('form[data-cotizador]');

  if (!formulario) {
    return;
  }

  cotizadorNodos = referenciasCotizador(formulario);

  if (!cotizadorNodos || !cotizadorNodos.cuerpo) {
    return;
  }

  catalogoCotizador();
  estadoInicialCotizador();

  if (cotizadorNodos.descuento) {
    cotizadorNodos.descuento.value = String(cotizadorDescuento);
  }
  if (cotizadorNodos.checkIva) {
    cotizadorNodos.checkIva.checked = cotizadorIva;
  }

  pintarCotizador();
  filtrarCatalogo();
  registrarCondicionesIniciales();

  /* --- Catálogo: agregar producto ------------------------------------- */
  const agregar = function (tarjeta) {
    if (!tarjeta) {
      return;
    }
    const id = Number.parseInt(tarjeta.getAttribute('data-id'), 10);
    if (agregarLineaCotizador(cotizadorCatalogo.get(id))) {
      pintarCotizador();
    }
  };

  document.querySelectorAll('[data-cotizador-producto]').forEach(function (tarjeta) {
    tarjeta.addEventListener('click', function (evento) {
      evento.preventDefault();
      agregar(tarjeta);
    });
  });

  const botonAgregar = document.querySelector('[data-cotizador-agregar]');
  if (botonAgregar) {
    botonAgregar.addEventListener('click', function (evento) {
      evento.preventDefault();
      const visible = Array.from(cotizadorCatalogo.values()).find(function (producto) {
        return !producto.tarjeta.hidden;
      });

      if (!visible) {
        crearAlerta('advertencia', msgCotizador('sin-resultados', 'No hay productos para agregar.'));
        return;
      }
      agregar(visible.tarjeta);
    });
  }

  /* --- Catálogo: filtros ------------------------------------------------ */
  if (cotizadorNodos.busqueda) {
    cotizadorNodos.busqueda.addEventListener('input', filtrarCatalogo);
  }
  if (cotizadorNodos.catalogoEmpresa) {
    cotizadorNodos.catalogoEmpresa.addEventListener('change', filtrarCatalogo);
  }

  /* --- Renglones: entrada de datos (delegación en la tabla) ------------- */
  cotizadorNodos.cuerpo.addEventListener('input', function (evento) {
    const campo = evento.target;
    if (!(campo instanceof HTMLInputElement)) {
      return;
    }

    if (campo.name.indexOf('[precio]') !== -1) {
      leerCampoLinea(campo, 'precio');
    } else if (campo.name.indexOf('[cantidad]') !== -1) {
      leerCampoLinea(campo, 'cantidad');
    } else if (campo.name.indexOf('[descuento_pct]') !== -1) {
      leerCampoLinea(campo, 'descuento_pct');
    } else {
      return;
    }

    totalesCotizador();
  });

  cotizadorNodos.cuerpo.addEventListener('change', function (evento) {
    const campo = evento.target;
    if (!(campo instanceof HTMLInputElement) || campo.type !== 'checkbox') {
      return;
    }
    if (campo.name.indexOf('[no_recordar]') !== -1) {
      leerCampoLinea(campo, 'no_recordar');
    }
  });

  cotizadorNodos.cuerpo.addEventListener('click', function (evento) {
    const boton = evento.target instanceof Element
      ? evento.target.closest('[data-quitar-fila]')
      : null;

    if (!boton) {
      return;
    }

    evento.preventDefault();
    const productoId = Number.parseInt(boton.getAttribute('data-quitar-fila'), 10);
    quitarLineaCotizador(productoId);
    pintarCotizador();
  });

  /* --- Resumen: descuento e IVA ---------------------------------------- */
  if (cotizadorNodos.descuento) {
    cotizadorNodos.descuento.addEventListener('input', function () {
      const valor = Number.parseFloat(cotizadorNodos.descuento.value);
      cotizadorDescuento = Number.isFinite(valor) ? valor : 0;
      totalesCotizador();
    });
  }

  if (cotizadorNodos.checkIva) {
    cotizadorNodos.checkIva.addEventListener('change', function () {
      cotizadorIva = cotizadorNodos.checkIva.checked;
      totalesCotizador();
    });
  }

  /* --- Encabezado: cliente y negocio ------------------------------------ */
  if (cotizadorNodos.cliente) {
    cotizadorNodos.cliente.addEventListener('change', actualizarClienteCotizador);
  }

  if (cotizadorNodos.empresa) {
    cotizadorNodos.empresa.addEventListener('change', actualizarCondicionesCotizador);
  }

  /* --- Envío: exige al menos un renglón --------------------------------- */
  cotizadorNodos.formulario.addEventListener('submit', function (evento) {
    if (cotizadorLineas.length > 0) {
      return;
    }

    evento.preventDefault();
    crearAlerta(
      'error',
      msgCotizador('vacio', 'La cotización necesita al menos un renglón.'),
      'Faltan productos'
    );
  });
}

document.addEventListener('DOMContentLoaded', inicializarCotizador);
