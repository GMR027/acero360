/**
 * app.js — Punto de entrada del JavaScript de la aplicación.
 *
 * Reglas del proyecto aplicadas aquí:
 *  - sólo let y const (nunca var)
 *  - sin alert(), confirm() ni prompt()
 *  - sin innerHTML: se usa document.createElement + appendChild
 *  - se previene el comportamiento por defecto en todo submit
 *
 * El archivo se concatena con los módulos de src/js/modulos/, así que
 * toda la inicialización se difiere a DOMContentLoaded para que el orden
 * de concatenación no influya.
 */

/**
 * Alterna la navegación lateral (cajón) en pantallas pequeñas.
 * Mantiene sincronizado el atributo aria-expanded del botón que lo abre.
 */
function alternarSidebar() {
  const sidebar = document.getElementById('sidebar');
  const fondo = document.getElementById('fondo-sidebar');
  const disparador = document.getElementById('boton-menu');

  if (!sidebar) {
    return;
  }

  const abierto = sidebar.classList.toggle('sidebar--abierto');

  if (fondo) {
    fondo.hidden = !abierto;
  }

  if (disparador) {
    disparador.setAttribute('aria-expanded', abierto ? 'true' : 'false');
  }

  document.body.style.overflow = abierto ? 'hidden' : '';
}

/** Cierra la navegación lateral si está desplegada. */
function cerrarSidebar() {
  const sidebar = document.getElementById('sidebar');
  const fondo = document.getElementById('fondo-sidebar');
  const disparador = document.getElementById('boton-menu');

  if (!sidebar) {
    return;
  }

  sidebar.classList.remove('sidebar--abierto');

  if (fondo) {
    fondo.hidden = true;
  }

  if (disparador) {
    disparador.setAttribute('aria-expanded', 'false');
  }

  document.body.style.overflow = '';
}

/**
 * Intercepta el envío de los formularios marcados con data-validar:
 * valida en cliente y, sólo si todo está correcto, permite el envío.
 *
 * @param {SubmitEvent} evento
 */
function manejarEnvioFormulario(evento) {
  const formulario = evento.target;

  if (!(formulario instanceof HTMLFormElement)) {
    return;
  }

  if (!validarFormulario(formulario)) {
    evento.preventDefault();
    return;
  }

  // Formularios con confirmación previa (borrar, cancelar, etc.).
  if (formulario.hasAttribute('data-confirmacion')) {
    evento.preventDefault();

    confirmarAccion({
      sobreTitulo: formulario.getAttribute('data-confirmacion-sobre') || 'Confirmación',
      titulo: formulario.getAttribute('data-confirmacion') || '¿Deseas continuar?',
      mensaje: formulario.getAttribute('data-confirmacion-mensaje') || 'Se aplicarán los cambios indicados.',
      textoConfirmar: formulario.getAttribute('data-confirmacion-boton') || 'Confirmar',
      alConfirmar: function () {
        bloquearEnvio(formulario);
        formulario.submit(); // submit() nativo: no vuelve a disparar el evento.
      },
    });
  }
}

/**
 * Inicializa todos los comportamientos de la página una vez cargado el DOM.
 */
function inicializarAplicacion() {
  // 1. Alertas que envió el servidor (redirecciones con ?st=... o flash).
  prepararAlertasDelServidor();

  // 2. Navegación lateral (móvil / tablet).
  const botonMenu = document.getElementById('boton-menu');
  if (botonMenu) {
    botonMenu.addEventListener('click', function (evento) {
      evento.preventDefault();
      alternarSidebar();
    });
  }

  const fondoSidebar = document.getElementById('fondo-sidebar');
  if (fondoSidebar) {
    fondoSidebar.addEventListener('click', function () {
      cerrarSidebar();
    });
  }

  // Los enlaces del menú cierran el panel en pantallas pequeñas.
  const enlacesSidebar = document.querySelectorAll('.sidebar__enlace');
  enlacesSidebar.forEach(function (enlace) {
    enlace.addEventListener('click', function () {
      if (window.matchMedia('(max-width: 767px)').matches) {
        cerrarSidebar();
      }
    });
  });

  // 3. Modal: clics y tecla Escape.
  document.addEventListener('click', manejarInteraccionModal);
  document.addEventListener('keydown', function (evento) {
    if (evento.key === 'Escape') {
      cerrarModal();
      cerrarSidebar();
    }
  });

  // 4. Envío de formularios: validación y prevención del comportamiento por defecto.
  document.addEventListener('submit', manejarEnvioFormulario);

  // 5. Carga de imágenes con vista previa.
  const camposImagen = document.querySelectorAll('input[type="file"][data-preview]');
  camposImagen.forEach(function (input) {
    const contenedor = input.closest('.carga-imagen');
    if (contenedor) {
      prepararCargaImagen(input, contenedor);
    }
  });

  // 6. Panel de ganancias: mes y semestre sólo aplican con un año concreto.
  const filtroAnio = document.getElementById('filtro-anio');
  if (filtroAnio) {
    const sincronizarPeriodo = function () {
      const hayAnio = filtroAnio.value !== '';

      ['filtro-semestre', 'filtro-mes'].forEach(function (identificador) {
        const campo = document.getElementById(identificador);
        if (!campo) {
          return;
        }

        campo.disabled = !hayAnio;
        if (!hayAnio) {
          campo.selectedIndex = 0;
        }
      });
    };

    filtroAnio.addEventListener('change', sincronizarPeriodo);
    sincronizarPeriodo();
  }

  // 7. Botones de borrado que usan confirmación sin <form> (delegación).
  document.addEventListener('click', function (evento) {
    const boton = evento.target instanceof Element
      ? evento.target.closest('[data-confirmar-accion]')
      : null;

    if (!boton) {
      return;
    }

    evento.preventDefault();

    confirmarAccion({
      sobreTitulo: boton.getAttribute('data-confirmar-sobre') || 'Confirmación',
      titulo: boton.getAttribute('data-confirmar-accion'),
      mensaje: boton.getAttribute('data-confirmar-mensaje') || 'Esta acción no se puede deshacer.',
      textoConfirmar: boton.getAttribute('data-confirmar-boton') || 'Eliminar',
      alConfirmar: function () {
        const destino = boton.getAttribute('data-url');
        if (destino) {
          window.location.href = destino;
        }
      },
    });
  });
}

// El arranque se difiere para que todos los módulos ya estén declarados.
document.addEventListener('DOMContentLoaded', inicializarAplicacion);

/**
 * modulos/alertas.js
 * ---------------------------------------------------------------------------
 * Sistema de alertas visuales (toasts) de la aplicación.
 *
 * Sustituye por completo a alert(), confirm() y prompt(): todo el feedback
 * del usuario se muestra en el DOM, dentro del contenedor .alertas, con el
 * mismo estilo en todas las pantallas.
 *
 * Todas las alertas se construyen con document.createElement + appendChild.
 * NUNCA se usa innerHTML.
 */

/** Iconos (caracteres Unicode) asociados a cada tipo de alerta. */
const ICONOS_ALERTA = {
  exito: '✓',
  error: '✕',
  advertencia: '!',
  info: 'i',
};

/** Duración en milisegundos antes de cerrarse automáticamente. */
const DURACION_ALERTA = 6000;

/**
 * Crea y muestra una alerta en pantalla.
 *
 * @param {'exito'|'error'|'advertencia'|'info'} tipo   Variante visual.
 * @param {string}                               texto  Mensaje para el usuario.
 * @param {string}                               titulo Título opcional.
 * @param {boolean}                              persistente  Si es true no se cierra sola.
 * @returns {HTMLElement} Nodo de la alerta creada.
 */
function crearAlerta(tipo, texto, titulo = '', persistente = false) {
  const contenedor = document.getElementById('alertas-contenedor');
  if (!contenedor) {
    return null;
  }

  const tipoValido = Object.prototype.hasOwnProperty.call(ICONOS_ALERTA, tipo) ? tipo : 'info';

  // Estructura: alerta > [icono, contenido(título+texto), botón cerrar]
  const alerta = document.createElement('div');
  alerta.className = 'alerta alerta--' + tipoValido;
  alerta.setAttribute('role', 'alert');

  const icono = document.createElement('span');
  icono.className = 'alerta__icono';
  icono.setAttribute('aria-hidden', 'true');
  icono.textContent = ICONOS_ALERTA[tipoValido];

  const contenido = document.createElement('div');
  contenido.className = 'alerta__contenido';

  if (titulo !== '') {
    const nodoTitulo = document.createElement('strong');
    nodoTitulo.className = 'alerta__titulo';
    nodoTitulo.textContent = titulo;
    contenido.appendChild(nodoTitulo);
  }

  const nodoTexto = document.createElement('p');
  nodoTexto.className = 'alerta__texto';
  nodoTexto.textContent = texto;
  contenido.appendChild(nodoTexto);

  const botonCerrar = document.createElement('button');
  botonCerrar.type = 'button';
  botonCerrar.className = 'alerta__cerrar';
  botonCerrar.setAttribute('aria-label', 'Cerrar alerta');
  botonCerrar.textContent = '✕';
  botonCerrar.addEventListener('click', function () {
    retirarAlerta(alerta);
  });

  alerta.appendChild(icono);
  alerta.appendChild(contenido);
  alerta.appendChild(botonCerrar);
  contenedor.appendChild(alerta);

  // Las alertas informativas y de éxito se cierran solas.
  if (!persistente) {
    window.setTimeout(function () {
      retirarAlerta(alerta);
    }, DURACION_ALERTA);
  }

  return alerta;
}

/**
 * Anima la salida de la alerta y la retira del DOM.
 *
 * @param {HTMLElement} alerta Nodo a eliminar.
 */
function retirarAlerta(alerta) {
  if (!alerta || !alerta.parentNode) {
    return;
  }

  alerta.classList.add('alerta--saliendo');

  window.setTimeout(function () {
    if (alerta.parentNode) {
      alerta.parentNode.removeChild(alerta);
    }
  }, 200);
}

/**
 * Prepara las alertas que el servidor ya renderizó (plantilla alertas.php):
 * les añade el botón de cierre y el temporizador de auto-cierre.
 */
function prepararAlertasDelServidor() {
  const contenedor = document.getElementById('alertas-contenedor');
  if (!contenedor) {
    return;
  }

  const alertas = contenedor.querySelectorAll('.alerta');
  alertas.forEach(function (alerta) {
    if (alerta.querySelector('.alerta__cerrar')) {
      return; // Ya preparada.
    }

    const botonCerrar = document.createElement('button');
    botonCerrar.type = 'button';
    botonCerrar.className = 'alerta__cerrar';
    botonCerrar.setAttribute('aria-label', 'Cerrar alerta');
    botonCerrar.textContent = '✕';
    botonCerrar.addEventListener('click', function () {
      retirarAlerta(alerta);
    });
    alerta.appendChild(botonCerrar);

    window.setTimeout(function () {
      retirarAlerta(alerta);
    }, DURACION_ALERTA);
  });
}

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

/**
 * entregas.js — resumen en vivo de la pantalla de preparación.
 *
 * Al marcar ventas con las casillas, agrupa los productos iguales y suma sus
 * cantidades (lee data-lineas de cada fila, sin consultar al servidor). Todo
 * el DOM se arma con createElement: nada de innerHTML.
 */
function iniciarEntregas() {
  const formulario = document.querySelector('[data-seleccion-ventas]');
  if (!formulario) {
    return;
  }

  const casillas = formulario.querySelectorAll('input[name="ventas[]"]');
  const resumen = document.querySelector('[data-resumen-agrupado]');
  const lista = resumen.querySelector('[data-resumen-lista]');
  const contador = resumen.querySelector('[data-resumen-contador]');
  const boton = formulario.querySelector('[data-crear-preparacion]');
  const todas = formulario.querySelector('[data-marcar-todas]');

  const texto = function (etiqueta, clase, contenido) {
    const nodo = document.createElement(etiqueta);
    if (clase) {
      nodo.className = clase;
    }
    nodo.textContent = contenido;
    return nodo;
  };

  const actualizar = function () {
    const grupos = new Map();
    let marcadas = 0;

    casillas.forEach(function (casilla) {
      if (!casilla.checked) {
        return;
      }
      marcadas += 1;
      const lineas = JSON.parse(casilla.getAttribute('data-lineas') || '[]');
      lineas.forEach(function (linea) {
        const grupo = grupos.get(linea.id) || { sku: linea.sku, nombre: linea.nombre, imagen: linea.imagen, total: 0 };
        grupo.total += linea.pendiente;
        grupos.set(linea.id, grupo);
      });
    });

    lista.replaceChildren();
    grupos.forEach(function (grupo) {
      const tarjeta = document.createElement('article');
      tarjeta.className = 'producto-grupo';
      if (grupo.imagen) {
        const imagen = document.createElement('img');
        imagen.className = 'producto-grupo__imagen';
        imagen.src = grupo.imagen;
        imagen.alt = grupo.nombre;
        imagen.loading = 'lazy';
        tarjeta.appendChild(imagen);
      }
      tarjeta.appendChild(texto('span', 'producto-grupo__sku', grupo.sku || 'Sin código'));
      tarjeta.appendChild(texto('strong', '', grupo.nombre));
      tarjeta.appendChild(texto('span', 'producto-grupo__cantidad', String(grupo.total)));
      lista.appendChild(tarjeta);
    });

    resumen.hidden = marcadas === 0;
    contador.textContent = marcadas + (marcadas === 1 ? ' venta seleccionada' : ' ventas seleccionadas');
    boton.disabled = marcadas === 0;
  };

  formulario.addEventListener('change', function (evento) {
    if (evento.target === todas) {
      casillas.forEach(function (casilla) {
        casilla.checked = todas.checked;
      });
    }
    actualizar();
  });

  actualizar();
}

document.addEventListener('DOMContentLoaded', iniciarEntregas);

/**
 * modulos/filePreview.js
 * ---------------------------------------------------------------------------
 * Vista previa y validación del campo de carga de imágenes de producto
 * (<input type="file">).
 *
 * La imagen se previsualiza en el DOM con FileReader + createElement.
 * La copia cuadrada definitiva la hace el servidor con Intervention Image.
 */

/**
 * Valida el archivo seleccionado antes de mostrarlo.
 *
 * @param {File} archivo
 * @returns {string} Mensaje de error o cadena vacía si es válido.
 */
function validarImagen(archivo) {
  if (!archivo) {
    return 'No se ha seleccionado ningún archivo.';
  }

  if (TIPOS_IMAGEN_PERMITIDOS.indexOf(archivo.type) === -1) {
    return 'Formato no permitido. Usa JPG, PNG o WebP.';
  }

  if (archivo.size > TAMANO_MAXIMO_IMAGEN) {
    const megas = (archivo.size / (1024 * 1024)).toFixed(1);
    const maximo = Math.round(TAMANO_MAXIMO_IMAGEN / (1024 * 1024));
    return 'La imagen pesa ' + megas + ' MB. El máximo permitido es ' + maximo + ' MB.';
  }

  return '';
}

/**
 * Dibuja la miniatura de la imagen en la zona de previsualización.
 *
 * @param {string}       url      Resultado de FileReader.readAsDataURL.
 * @param {HTMLElement}  contenedor Nodo que contiene la previsualización.
 */
function pintarPreview(url, contenedor) {
  const zona = contenedor.querySelector('.carga-imagen__zona');
  if (!zona) {
    return;
  }

  // Se retira el marcador de posición si existe.
  const anterior = zona.querySelector('.carga-imagen__preview, .carga-imagen__placeholder');
  if (anterior && anterior.parentNode) {
    anterior.parentNode.removeChild(anterior);
  }

  const imagen = document.createElement('img');
  imagen.className = 'carga-imagen__preview';
  imagen.src = url;
  imagen.alt = 'Vista previa de la imagen seleccionada';
  zona.insertBefore(imagen, zona.firstChild);
}

/**
 * Prepara un campo de tipo file: valida, previsualiza y reporta errores
 * mediante alertas visuales (nunca con alert()).
 *
 * @param {HTMLInputElement} input   Campo <input type="file">.
 * @param {HTMLElement}      contenedor  Zona .carga-imagen que lo contiene.
 */
function prepararCargaImagen(input, contenedor) {
  input.addEventListener('change', function () {
    const archivo = input.files && input.files[0] ? input.files[0] : null;
    const error = validarImagen(archivo);

    if (error !== '') {
      input.value = ''; // Se limpia el campo para no enviar un archivo inválido.
      crearAlerta('error', error, 'Imagen no válida');
      return;
    }

    const lector = new FileReader();
    lector.addEventListener('load', function () {
      if (typeof lector.result === 'string') {
        pintarPreview(lector.result, contenedor);
      }
    });
    lector.addEventListener('error', function () {
      crearAlerta('error', 'No fue posible leer el archivo seleccionado.', 'Error de lectura');
    });
    lector.readAsDataURL(archivo);
  });
}

/**
 * graficas.js — gráficas SVG sin librerías ni innerHTML.
 *
 * Cada <figure data-grafica> trae sus datos en JSON:
 *   { "tipo": "barras" | "linea", "etiquetas": [...], "series": [{ "nombre": "...", "valores": [...] }], "formato": "moneda" | "entero" }
 * La misma información está SIEMPRE en una tabla junto a la gráfica (accesibilidad), y cada punto
 * tiene un <title> con su valor para lectores de pantalla y al pasar el cursor.
 */
const PALETA_GRAFICAS = ['#4679a6', '#d9822b', '#5b8c5a', '#8a6bbd', '#a0a0a0'];
const SVG_NS = 'http://www.w3.org/2000/svg';

function nodoSvg(nombre, atributos, texto) {
  const nodo = document.createElementNS(SVG_NS, nombre);
  Object.keys(atributos || {}).forEach(function (clave) {
    nodo.setAttribute(clave, String(atributos[clave]));
  });
  if (texto !== undefined) {
    nodo.textContent = texto;
  }
  return nodo;
}

function formatoValor(valor, formato) {
  if (formato === 'entero') {
    return new Intl.NumberFormat('es-MX').format(valor);
  }
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 }).format(valor);
}

function formatoCorto(valor, formato) {
  const absoluto = Math.abs(valor);
  const prefijo = formato === 'moneda' ? '$' : '';
  if (absoluto >= 1000000) {
    return prefijo + (valor / 1000000).toFixed(1).replace(/\.0$/, '') + ' M';
  }
  if (absoluto >= 1000) {
    return prefijo + (valor / 1000).toFixed(1).replace(/\.0$/, '') + ' mil';
  }
  return prefijo + String(Math.round(valor));
}

/** Máximo "redondo" para el eje (1, 2, 5 × 10^n). */
function maximoEje(valor) {
  if (valor <= 0) {
    return 1;
  }
  const exponente = Math.pow(10, Math.floor(Math.log10(valor)));
  const fraccion = valor / exponente;
  const paso = fraccion <= 1 ? 1 : fraccion <= 2 ? 2 : fraccion <= 5 ? 5 : 10;
  return paso * exponente;
}

function dibujarGrafica(figura) {
  let datos;
  try {
    datos = JSON.parse(figura.getAttribute('data-grafica'));
  } catch (error) {
    return;
  }

  const etiquetas = datos.etiquetas || [];
  const series = datos.series || [];
  const formato = datos.formato || 'moneda';
  if (!etiquetas.length || !series.length) {
    return;
  }

  const ancho = 720;
  const alto = 320;
  const margen = { arriba: 16, derecha: 16, abajo: 44, izquierda: 64 };
  const areaAncho = ancho - margen.izquierda - margen.derecha;
  const areaAlto = alto - margen.arriba - margen.abajo;
  const maximo = maximoEje(Math.max.apply(null, series.map(function (s) { return Math.max.apply(null, s.valores); }).concat([0])));

  const svg = nodoSvg('svg', { viewBox: '0 0 ' + ancho + ' ' + alto, role: 'img', 'aria-label': figura.getAttribute('aria-label') || 'Gráfica', class: 'grafica__svg' });

  // Rejilla y eje Y
  for (let i = 0; i <= 4; i += 1) {
    const valor = (maximo / 4) * i;
    const y = margen.arriba + areaAlto - (valor / maximo) * areaAlto;
    svg.appendChild(nodoSvg('line', { x1: margen.izquierda, x2: ancho - margen.derecha, y1: y, y2: y, class: 'grafica__rejilla' }));
    svg.appendChild(nodoSvg('text', { x: margen.izquierda - 8, y: y + 4, 'text-anchor': 'end', class: 'grafica__texto' }, formatoCorto(valor, formato)));
  }

  const cada = Math.max(1, Math.ceil(etiquetas.length / 12));
  const paso = areaAncho / etiquetas.length;

  etiquetas.forEach(function (etiqueta, i) {
    if (i % cada === 0) {
      svg.appendChild(nodoSvg('text', { x: margen.izquierda + paso * i + paso / 2, y: alto - margen.abajo + 18, 'text-anchor': 'middle', class: 'grafica__texto' }, etiqueta));
    }
  });

  if (datos.tipo === 'linea') {
    series.forEach(function (serie, s) {
      const color = PALETA_GRAFICAS[s % PALETA_GRAFICAS.length];
      const puntos = serie.valores.map(function (valor, i) {
        return [margen.izquierda + paso * i + paso / 2, margen.arriba + areaAlto - (valor / maximo) * areaAlto];
      });
      svg.appendChild(nodoSvg('polyline', { points: puntos.map(function (p) { return p.join(','); }).join(' '), fill: 'none', stroke: color, 'stroke-width': 2.5 }));
      puntos.forEach(function (p, i) {
        const circulo = nodoSvg('circle', { cx: p[0], cy: p[1], r: 3.5, fill: color });
        circulo.appendChild(nodoSvg('title', {}, serie.nombre + ' · ' + etiquetas[i] + ': ' + formatoValor(serie.valores[i], formato)));
        svg.appendChild(circulo);
      });
    });
  } else {
    const anchoGrupo = paso * 0.78;
    const anchoBarra = anchoGrupo / series.length;
    series.forEach(function (serie, s) {
      const color = PALETA_GRAFICAS[s % PALETA_GRAFICAS.length];
      serie.valores.forEach(function (valor, i) {
        const altoBarra = (valor / maximo) * areaAlto;
        const x = margen.izquierda + paso * i + (paso - anchoGrupo) / 2 + anchoBarra * s;
        const barra = nodoSvg('rect', { x: x, y: margen.arriba + areaAlto - altoBarra, width: Math.max(1, anchoBarra - 2), height: altoBarra, fill: color, rx: 2 });
        barra.appendChild(nodoSvg('title', {}, serie.nombre + ' · ' + etiquetas[i] + ': ' + formatoValor(valor, formato)));
        svg.appendChild(barra);
      });
    });
  }

  figura.replaceChildren(svg);

  // Leyenda (solo si hay más de una serie)
  if (series.length > 1) {
    const leyenda = document.createElement('ul');
    leyenda.className = 'grafica__leyenda';
    series.forEach(function (serie, s) {
      const item = document.createElement('li');
      const muestra = document.createElement('span');
      muestra.className = 'grafica__muestra';
      muestra.style.backgroundColor = PALETA_GRAFICAS[s % PALETA_GRAFICAS.length];
      item.appendChild(muestra);
      item.appendChild(document.createTextNode(serie.nombre));
      leyenda.appendChild(item);
    });
    figura.appendChild(leyenda);
  }
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-grafica]').forEach(dibujarGrafica);
});

/**
 * modulos/modal.js
 * ---------------------------------------------------------------------------
 * Control de la ventana modal única de la aplicación.
 *
 * Todos los diálogos (confirmaciones, vistas rápidas) comparten el mismo
 * nodo del DOM: templates/modal.php. Sólo cambian el sobre-título, el
 * título, el cuerpo y las acciones, que se construyen con createElement.
 *
 * No se usa innerHTML ni window.confirm en ninguna parte.
 */

/** Referencia al nodo del modal (se busca al abrir para tolerar re-render). */
let nodoModal = null;

/** Último elemento con el foco antes de abrir, para devolverlo al cerrar. */
let elementoFocoAnterior = null;

/** Función pendiente de ejecutar cuando el usuario confirma. */
let accionConfirmada = null;

/**
 * Devuelve el nodo raíz del modal o null si la plantilla no está en la página.
 *
 * @returns {HTMLElement|null}
 */
function obtenerNodoModal() {
  if (!nodoModal) {
    nodoModal = document.getElementById('modal-principal');
  }
  return nodoModal;
}

/**
 * Abre el modal con el contenido recibido.
 *
 * @param {Object}  opciones
 * @param {string}  opciones.sobreTitulo  Contexto pequeño sobre el título.
 * @param {string}  opciones.titulo       Título de la acción.
 * @param {Node|Node[]} [opciones.cuerpo] Contenido del cuerpo.
 * @param {Node|Node[]} [opciones.acciones] Botones del pie (además de Cerrar).
 * @param {Function} [opciones.alConfirmar] Se invoca al pulsar la acción principal.
 * @param {string}   [opciones.textoConfirmar] Texto del botón principal.
 * @param {boolean}  [opciones.confirmacion] Estilo de diálogo de confirmación.
 */
function abrirModal(opciones) {
  const modal = obtenerNodoModal();
  if (!modal) {
    return;
  }

  const sobreTitulo = opciones.sobreTitulo || '';
  const titulo = opciones.titulo || '';
  const cuerpo = opciones.cuerpo || null;
  const acciones = opciones.acciones || null;
  const confirmacion = opciones.confirmacion === true;

  // --- Cabecera -----------------------------------------------------------
  const nodoSobreTitulo = modal.querySelector('#modal-sobre-titulo');
  const nodoTitulo = modal.querySelector('#modal-titulo');

  if (nodoSobreTitulo) {
    nodoSobreTitulo.textContent = sobreTitulo;
    nodoSobreTitulo.hidden = sobreTitulo === '';
  }
  if (nodoTitulo) {
    nodoTitulo.textContent = titulo;
  }

  // --- Cuerpo -------------------------------------------------------------
  const nodoCuerpo = modal.querySelector('#modal-cuerpo');
  if (nodoCuerpo) {
    vaciarNodo(nodoCuerpo);
    agregarContenido(nodoCuerpo, cuerpo);
  }

  // --- Pie de acciones ----------------------------------------------------
  const nodoPie = modal.querySelector('#modal-pie');
  accionConfirmada = typeof opciones.alConfirmar === 'function' ? opciones.alConfirmar : null;

  if (nodoPie) {
    vaciarNodo(nodoPie);

    const botonCancelar = document.createElement('button');
    botonCancelar.type = 'button';
    botonCancelar.className = 'btn btn--secundario';
    botonCancelar.textContent = 'Cancelar';
    botonCancelar.setAttribute('data-cerrar-modal', '');
    nodoPie.appendChild(botonCancelar);

    if (accionConfirmada) {
      const botonConfirmar = document.createElement('button');
      botonConfirmar.type = 'button';
      botonConfirmar.className = confirmacion
        ? 'btn btn--peligro'
        : 'btn btn--primario';
      botonConfirmar.textContent = opciones.textoConfirmar || 'Confirmar';
      botonConfirmar.setAttribute('data-confirmar-modal', '');
      nodoPie.appendChild(botonConfirmar);
    }

    agregarContenido(nodoPie, acciones);
  }

  // --- Mostrar ------------------------------------------------------------
  elementoFocoAnterior = document.activeElement;
  modal.classList.toggle('modal--confirmacion', confirmacion);
  modal.hidden = false;
  document.body.style.overflow = 'hidden';

  // Coloca el foco en el primer control interactivo del diálogo.
  const primerControl = modal.querySelector('button, [href], input, select, textarea');
  if (primerControl) {
    primerControl.focus();
  }
}

/** Cierra el modal y devuelve el foco al elemento que lo abrió. */
function cerrarModal() {
  const modal = obtenerNodoModal();
  if (!modal || modal.hidden) {
    return;
  }

  modal.hidden = true;
  document.body.style.overflow = '';
  accionConfirmada = null;

  if (elementoFocoAnterior && typeof elementoFocoAnterior.focus === 'function') {
    elementoFocoAnterior.focus();
  }
  elementoFocoAnterior = null;
}

/**
 * Diálogo de confirmación reutilizable (equivale a window.confirm, pero
 * con el mismo diseño visual que el resto de la aplicación).
 *
 * @param {Object}   opciones
 * @param {string}   opciones.titulo
 * @param {string}   [opciones.sobreTitulo]
 * @param {string}   opciones.mensaje
 * @param {string}   [opciones.textoConfirmar]
 * @param {Function} opciones.alConfirmar
 */
function confirmarAccion(opciones) {
  const parrafo = document.createElement('p');
  parrafo.className = 'texto-base';
  parrafo.textContent = opciones.mensaje || '';

  const nota = document.createElement('div');
  nota.className = 'nota nota--advertencia';
  const iconoNota = document.createElement('span');
  iconoNota.className = 'nota__icono';
  iconoNota.setAttribute('aria-hidden', 'true');
  iconoNota.textContent = '!';
  const textoNota = document.createElement('span');
  textoNota.textContent = 'Esta acción no se puede deshacer.';
  nota.appendChild(iconoNota);
  nota.appendChild(textoNota);

  const cuerpo = document.createElement('div');
  cuerpo.className = 'columna';
  cuerpo.appendChild(parrafo);
  cuerpo.appendChild(nota);

  abrirModal({
    sobreTitulo: opciones.sobreTitulo || 'Confirmación',
    titulo: opciones.titulo || '¿Continuar?',
    cuerpo: cuerpo,
    confirmacion: true,
    textoConfirmar: opciones.textoConfirmar || 'Sí, continuar',
    alConfirmar: opciones.alConfirmar,
  });
}

/**
 * Vacía un nodo quitando todos sus hijos de forma segura.
 *
 * @param {HTMLElement} nodo
 */
function vaciarNodo(nodo) {
  while (nodo.firstChild) {
    nodo.removeChild(nodo.firstChild);
  }
}

/**
 * Agrega uno o varios nodos a un contenedor.
 *
 * @param {HTMLElement}        contenedor
 * @param {Node|Node[]|null}   contenido
 */
function agregarContenido(contenedor, contenido) {
  if (!contenido) {
    return;
  }

  if (Array.isArray(contenido)) {
    contenido.forEach(function (nodo) {
      if (nodo) {
        contenedor.appendChild(nodo);
      }
    });
    return;
  }

  contenedor.appendChild(contenido);
}

/**
 * Cierra el modal al pulsar fuera de él o con la tecla Escape,
 * y procesa los atributos data-cerrar-modal / data-confirmar-modal.
 *
 * @param {Event} evento
 */
function manejarInteraccionModal(evento) {
  const destino = evento.target;

  if (!(destino instanceof Element)) {
    return;
  }

  // Clic directo en el fondo del modal.
  if (destino.id === 'modal-principal') {
    cerrarModal();
    return;
  }

  if (destino.closest('[data-cerrar-modal]')) {
    evento.preventDefault();
    cerrarModal();
    return;
  }

  if (destino.closest('[data-confirmar-modal]')) {
    evento.preventDefault();
    const accion = accionConfirmada;
    cerrarModal();
    if (typeof accion === 'function') {
      accion();
    }
  }
}

/**
 * ordenCompra.js — partidas dinámicas del formulario de orden de compra.
 *
 * Agrega y quita renglones (clonando el primero, sin innerHTML) y recalcula
 * el importe de cada uno y el total. El servidor vuelve a calcular todo:
 * esto es solo una ayuda visual.
 */
function iniciarOrdenCompra() {
  const tabla = document.querySelector('[data-partidas]');
  if (!tabla) {
    return;
  }

  const cuerpo = tabla.querySelector('[data-partidas-cuerpo]');
  const total = tabla.querySelector('[data-total]');
  const formatoMoneda = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

  const recalcular = function () {
    let centavos = 0;
    cuerpo.querySelectorAll('[data-partida]').forEach(function (fila) {
      const cantidad = Number.parseInt(fila.querySelector('[data-cantidad]').value, 10) || 0;
      const costo = Math.round((Number.parseFloat(fila.querySelector('[data-costo]').value) || 0) * 100);
      centavos += cantidad * costo;
      fila.querySelector('[data-importe]').textContent = formatoMoneda.format((cantidad * costo) / 100);
    });
    total.textContent = formatoMoneda.format(centavos / 100);
  };

  document.querySelector('[data-agregar-partida]').addEventListener('click', function (evento) {
    evento.preventDefault();
    const nueva = cuerpo.querySelector('[data-partida]').cloneNode(true);
    nueva.querySelectorAll('input').forEach(function (campo) {
      campo.value = campo.hasAttribute('data-cantidad') ? '1' : (campo.hasAttribute('data-costo') ? '0.00' : '');
    });
    nueva.querySelector('select').value = '';
    cuerpo.appendChild(nueva);
    recalcular();
  });

  cuerpo.addEventListener('click', function (evento) {
    const boton = evento.target.closest('[data-quitar-partida]');
    if (!boton) {
      return;
    }
    evento.preventDefault();
    if (cuerpo.querySelectorAll('[data-partida]').length > 1) {
      boton.closest('[data-partida]').remove();
      recalcular();
    }
  });

  cuerpo.addEventListener('input', recalcular);

  // Al elegir un producto con costo actual, lo propone si el costo está en cero.
  cuerpo.addEventListener('change', function (evento) {
    if (evento.target.tagName !== 'SELECT') {
      return;
    }
    const costo = evento.target.selectedOptions[0].getAttribute('data-costo-catalogo');
    const campo = evento.target.closest('[data-partida]').querySelector('[data-costo]');
    if (costo && Number.parseFloat(campo.value) === 0) {
      campo.value = Number.parseFloat(costo).toFixed(2);
      recalcular();
    }
  });

  recalcular();
}

document.addEventListener('DOMContentLoaded', iniciarOrdenCompra);

/**
 * tablasResponsivas.js — etiqueta cada celda con el encabezado de su columna.
 *
 * En celular el CSS convierte cada fila en una tarjeta y muestra esa etiqueta
 * (data-label) delante de cada dato. En escritorio no tiene ningún efecto
 * visual. Se omite la tabla de renglones del cotizador (sus filas las arma
 * cotizador.js con su propio data-label) y las que declaran data-sin-tarjetas.
 */
function etiquetarTablas() {
  document.querySelectorAll('table.tabla:not(#tabla-renglones):not([data-sin-tarjetas])').forEach(function (tabla) {
    const encabezados = Array.from(tabla.querySelectorAll('thead tr:last-child th')).map(function (th) {
      const oculto = th.querySelector('.solo-lectores');
      return oculto && th.textContent.trim() === oculto.textContent.trim() ? '' : th.textContent.trim();
    });

    tabla.querySelectorAll('tbody tr, tfoot tr').forEach(function (fila) {
      let columna = 0;
      Array.from(fila.children).forEach(function (celda) {
        const abarca = Number.parseInt(celda.getAttribute('colspan') || '1', 10) || 1;
        if (celda.tagName === 'TD') {
          celda.setAttribute('data-label', abarca > 1 ? '' : (encabezados[columna] || ''));
        }
        columna += abarca;
      });
    });
  });
}

document.addEventListener('DOMContentLoaded', etiquetarTablas);

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
  const obligatorio = control.hasAttribute('required');

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
    if (control.type === 'hidden' || control.type === 'submit' || control.type === 'button') {
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
