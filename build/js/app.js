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

/** Abre o cierra la hoja "Nueva venta / Nueva cotización" de la barra inferior. */
function alternarHojaNuevo(forzarAbierta) {
  const hoja = document.getElementById('hoja-nuevo');
  const fondo = document.getElementById('fondo-nuevo');
  const disparador = document.getElementById('boton-nuevo');

  if (!hoja) {
    return;
  }

  const abrir = typeof forzarAbierta === 'boolean' ? forzarAbierta : hoja.hidden;
  hoja.hidden = !abrir;

  if (fondo) {
    fondo.hidden = !abrir;
  }

  if (disparador) {
    disparador.setAttribute('aria-expanded', abrir ? 'true' : 'false');
  }
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
    return;
  }

  // Sin confirmación: se deja que el navegador envíe y, después, se bloquea el
  // botón (si se hiciera antes se perdería su name/value) para evitar el doble envío.
  if (formulario.method.toLowerCase() === 'post') {
    setTimeout(function () {
      if (!evento.defaultPrevented) {
        bloquearEnvio(formulario);
      }
    }, 0);
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

  const botonNuevo = document.getElementById('boton-nuevo');
  if (botonNuevo) {
    botonNuevo.addEventListener('click', function (evento) {
      evento.preventDefault();
      alternarHojaNuevo();
    });
  }

  const fondoNuevo = document.getElementById('fondo-nuevo');
  if (fondoNuevo) {
    fondoNuevo.addEventListener('click', function () {
      alternarHojaNuevo(false);
    });
  }

  document.addEventListener('keydown', function (evento) {
    if (evento.key === 'Escape') {
      alternarHojaNuevo(false);
    }
  });

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

  // Al volver con «Atrás» (caché del navegador) los botones no deben quedar bloqueados.
  window.addEventListener('pageshow', function (evento) {
    if (!evento.persisted) {
      return;
    }
    document.querySelectorAll('button[data-texto-original]').forEach(function (boton) {
      boton.textContent = boton.getAttribute('data-texto-original');
      boton.removeAttribute('data-texto-original');
      boton.disabled = false;
    });
  });

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

  // 8. Miniaturas de producto: alternativa si falla la carga y ampliación.
  inicializarMiniaturasProducto();

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
 * ayudas.js — Burbujas de ayuda: al dejar el cursor (o el foco) 200 ms sobre un elemento explican
 * qué hace. El texto sale de data-ayuda="..." o, para los enlaces y botones comunes, del
 * diccionario AYUDAS_POR_RUTA (clave: ruta del enlace sin parámetros). Un title="" existente se
 * convierte en burbuja para no mostrar dos globos a la vez.
 */

const RETARDO_AYUDA_MS = 200;
const RETARDO_CAMPO_MS = 400; // los campos de captura esperan más para no estorbar al escribir
const CAMPOS = 'input, select, textarea';

const AYUDAS_POR_RUTA = {
  '/admin': 'Inicio: accesos rápidos y las ganancias de cada negocio.',
  '/admin/cotizaciones': 'Todas las cotizaciones y pedidos: busca, filtra, convierte en venta o descarga el PDF.',
  '/admin/cotizaciones/crear': 'Crear una cotización nueva para un cliente.',
  '/admin/clientes': 'Tu directorio de clientes: datos de contacto y cotizaciones de cada uno.',
  '/admin/productos': 'Catálogo de productos: precios, fotos y existencias.',
  '/admin/notas': 'Tus notas personales y recordatorios.',
  '/admin/empresas': 'Tus negocios: nombre, RFC, logotipo y datos que salen en el PDF.',
  '/admin/escritorio': 'Descarga la aplicación para instalarla en tu computadora.',
  '/admin/usuarios': 'Tu perfil y tu contraseña.',
  '/admin/ventas': 'Ventas realizadas: las que vienen de una cotización y las directas.',
  '/admin/ventas/crear': 'Anotar una venta directa, sin hacer cotización.',
  '/admin/entregas': 'Pantalla para preparar y entregar lo vendido.',
  '/admin/contabilidad': 'Cuánto vendiste, cuánto gastaste y qué te falta hacer este mes.',
  '/admin/contabilidad/ingresos': 'Cada venta con su cobro y si se facturó. Ahí subes la factura.',
  '/admin/contabilidad/gastos': 'Todo lo que has gastado. Ahí subes tus facturas y tickets.',
  '/admin/contabilidad/gastos/crear': 'Anotar un gasto nuevo en 6 preguntas.',
  '/admin/contabilidad/sin-facturar': 'Ventas a las que todavía no les has dado factura.',
  '/admin/contabilidad/mas': 'Facturas del mes en ZIP, cierre de mes y meses cerrados.',
  '/admin/contabilidad/documentos': 'Todas las facturas, XML y tickets que subiste este mes.',
  '/admin/contabilidad/cierre': 'Revisa el mes y ciérralo para que nadie lo cambie sin querer.',
  '/admin/contabilidad/periodos': 'Meses ya cerrados y su resumen.',
  '/admin/fiscal': 'Cuánto separar para impuestos este mes (cálculo aproximado, informativo).',
  '/admin/fiscal/configuracion': 'Tus datos fiscales: tipo de persona y régimen.',
  '/admin/estadisticas': 'Gráficas de ventas, ganancias y productos más vendidos.',
  '/admin/inventario': 'Existencias de cada producto y sus movimientos.',
  '/admin/compras': 'Órdenes de compra a tus proveedores y recepción de mercancía.',
  '/admin/compras/crear': 'Crear una orden de compra a un proveedor.',
  '/admin/proveedores': 'Directorio de proveedores y lo que les has comprado.',
  '/admin/proveedores/crear': 'Dar de alta un proveedor nuevo.',
  '/admin/proveedores/entradas': 'Historial de entregas anteriores de proveedores (con fotos).',
  '/admin/ayuda': 'Manual de usuario: cómo hacer cada cosa paso a paso.',
  '/admin/salir': 'Cerrar tu sesión.',
};

const AYUDAS_POR_TEXTO = {
  'Guardar gasto': 'Guarda el gasto y sube los archivos que elegiste.',
  'Guardar': 'Guarda los cambios.',
  'Cancelar': 'Salir sin guardar nada.',
  'Anotar pago recibido': 'Suma este pago a lo que ya te pagó el cliente.',
  'Subir archivos': 'Sube los archivos elegidos. El tipo (factura, XML, foto) se detecta solo.',
  'Descargar': 'Descarga una copia del archivo.',
  'Guardar este cálculo del mes': 'Deja una copia del cálculo de impuestos de este mes para consultarla después.',
  'Revisar y cerrar el mes': 'Revisa que el mes esté completo y ciérralo.',
};

let temporizador = null;
let burbuja = null;
let origen = null;

function textoAyuda(elemento) {
  const explicita = elemento.getAttribute('data-ayuda');
  if (explicita) {
    return explicita;
  }
  // Un campo hereda la ayuda de su etiqueta (<label for="...">) o de la etiqueta que lo envuelve.
  if (elemento.matches(CAMPOS)) {
    const etiqueta = (elemento.id && document.querySelector(`label[for="${CSS.escape(elemento.id)}"]`)) || elemento.closest('label');
    return etiqueta ? etiqueta.getAttribute('data-ayuda') || '' : '';
  }
  if (elemento.tagName === 'A') {
    const ruta = (elemento.getAttribute('href') || '').split(/[?#]/)[0].replace(/\/$/, '') || '/admin';
    if (AYUDAS_POR_RUTA[ruta]) {
      return AYUDAS_POR_RUTA[ruta];
    }
  }
  if (elemento.tagName === 'A' || elemento.tagName === 'BUTTON') {
    const texto = (elemento.textContent || '').replace(/\s+/g, ' ').trim().replace(/^[a-z_]+(?=[A-ZÁÉÍÓÚÑ])/, '');
    for (const clave of Object.keys(AYUDAS_POR_TEXTO)) {
      if (texto === clave || texto.endsWith(clave)) {
        return AYUDAS_POR_TEXTO[clave];
      }
    }
  }
  return '';
}

function crearBurbuja() {
  const nodo = document.createElement('div');
  nodo.className = 'burbuja-ayuda';
  nodo.id = 'burbuja-ayuda';
  nodo.setAttribute('role', 'tooltip');
  nodo.hidden = true;
  document.body.appendChild(nodo);
  return nodo;
}

function ocultarAyuda() {
  clearTimeout(temporizador);
  temporizador = null;
  if (burbuja) {
    burbuja.hidden = true;
  }
  if (origen) {
    origen.removeAttribute('aria-describedby');
    origen = null;
  }
}

function mostrarAyuda(elemento, texto) {
  burbuja = burbuja || crearBurbuja();
  burbuja.textContent = texto;
  burbuja.hidden = false;
  burbuja.style.left = '0px';
  burbuja.style.top = '0px';

  const caja = elemento.getBoundingClientRect();
  const ancho = burbuja.offsetWidth;
  const alto = burbuja.offsetHeight;
  const margen = 8;

  let izquierda = caja.left + caja.width / 2 - ancho / 2;
  izquierda = Math.max(margen, Math.min(izquierda, window.innerWidth - ancho - margen));
  let arriba = caja.top - alto - margen;
  burbuja.classList.toggle('burbuja-ayuda--abajo', arriba < margen);
  if (arriba < margen) {
    arriba = caja.bottom + margen;
  }

  burbuja.style.left = `${izquierda + window.scrollX}px`;
  burbuja.style.top = `${arriba + window.scrollY}px`;
  burbuja.style.setProperty('--flecha-x', `${Math.max(12, Math.min(ancho - 12, caja.left + caja.width / 2 - izquierda))}px`);

  origen = elemento;
  elemento.setAttribute('aria-describedby', 'burbuja-ayuda');
}

function programarAyuda(evento) {
  const objetivo = evento.target instanceof Element ? evento.target.closest('a, button, [data-ayuda], label, summary, input, select, textarea') : null;
  if (!objetivo || objetivo === origen) {
    return;
  }

  // Un title="" nativo se pasa a data-ayuda para que solo salga nuestra burbuja.
  if (objetivo.hasAttribute('title') && !objetivo.hasAttribute('data-ayuda')) {
    objetivo.setAttribute('data-ayuda', objetivo.getAttribute('title'));
    objetivo.removeAttribute('title');
  }

  const texto = textoAyuda(objetivo);
  ocultarAyuda();
  if (!texto) {
    return;
  }
  temporizador = setTimeout(() => mostrarAyuda(objetivo, texto), objetivo.matches(CAMPOS) ? RETARDO_CAMPO_MS : RETARDO_AYUDA_MS);
}

function iniciarAyudas() {
  document.addEventListener('mouseover', programarAyuda);
  document.addEventListener('focusin', programarAyuda);
  document.addEventListener('mouseout', (evento) => {
    if (!(evento.relatedTarget instanceof Element) || !origen || !origen.contains(evento.relatedTarget)) {
      ocultarAyuda();
    }
  });
  document.addEventListener('focusout', ocultarAyuda);
  document.addEventListener('mousedown', ocultarAyuda);
  document.addEventListener('scroll', ocultarAyuda, true);
  document.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape') {
      ocultarAyuda();
    }
  });
}

document.addEventListener('DOMContentLoaded', iniciarAyudas);

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
  celdaIndice.className = 'tabla--numerica cifra cotizador-celda-indice';
  celdaIndice.textContent = String(indice + 1);
  fila.appendChild(celdaIndice);

  // 2. Producto con su imagen.
  const celdaProducto = document.createElement('td');
  celdaProducto.className = 'cotizador-celda-producto';
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
  if (linea.sku) {
    detalle.appendChild(nodoCotizador('span', 'tabla__codigo', linea.sku));
  }
  if (linea.categoria) {
    detalle.appendChild(document.createTextNode((linea.sku ? ' · ' : '') + linea.categoria));
  }
  if (linea.sku || linea.categoria) {
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
  celdaLista.className = 'tabla--numerica cifra cotizador-celda-lista';
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
  celdaTotal.className = 'tabla--numerica cifra cotizador-celda-total';
  celdaTotal.setAttribute('data-fila-total', String(linea.id));
  celdaTotal.textContent = monedaCotizador(totalLineaCotizador(linea));
  fila.appendChild(celdaTotal);

  // 8. Eliminar renglón.
  const celdaAccion = document.createElement('td');
  celdaAccion.className = 'tabla--centro cotizador-celda-accion';

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
  const lista = resumen.querySelector('[data-resumen-lista]'); // opcional: ya no se muestra el resumen en línea
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

    if (lista) {
      lista.replaceChildren();
    }
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
      if (lista) {
        lista.appendChild(tarjeta);
      }
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
 * graficas.js — gráficas de Estadísticas con Apache ECharts.
 *
 * Cada <figure data-grafica> trae su especificación en JSON (la misma que usan el PDF y el Excel):
 *   { "tipo": "barras" | "apilado" | "linea" | "barras-h", "formato": "moneda" | "entero",
 *     "categorias": [...], "series": [{ "nombre", "datos": [...], "color" }],
 *     "resaltar": [mesInicio, mesFin], "comparaSeries": true, "acumulado": true }
 *   - comparaSeries: las series son periodos consecutivos (años); el globo muestra la variación
 *     porcentual de cada serie contra la anterior.
 *   - resaltar: franja con el periodo elegido en los filtros.
 * La biblioteca (build/js/echarts.min.js) solo se carga en la página de estadísticas; si no está,
 * las tablas de la página traen los mismos datos.
 * Los colores de texto y rejilla salen de las variables CSS del tema y se repintan con «tema:cambio».
 * El globo se arma con nodos del DOM (nunca con texto del servidor como HTML).
 */
const GRAFICAS_FUENTE = 'Inter, "Segoe UI", Roboto, Arial, sans-serif';
const graficasVivas = [];

function graficasColorCss(nombre, respaldo) {
  const valor = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
  return valor || respaldo;
}

function graficasTema() {
  return {
    texto: graficasColorCss('--color-texto', '#4d4c53'),
    suave: graficasColorCss('--color-texto-secundario', '#64646a'),
    borde: graficasColorCss('--color-borde', '#e4e6ea'),
    bordeFuerte: graficasColorCss('--color-borde-fuerte', '#c9cdd4'),
    superficie: graficasColorCss('--color-superficie', '#ffffff'),
    oscuro: typeof temaEfectivo === 'function' && temaEfectivo() === 'oscuro',
  };
}

/** En tema oscuro aclara un color #rrggbb para que no se pierda sobre el fondo. */
function graficasColor(color, tema) {
  if (!tema.oscuro || !/^#[0-9a-f]{6}$/i.test(color)) {
    return color;
  }
  const mezcla = function (i) {
    const canal = parseInt(color.slice(i, i + 2), 16);
    return Math.round(canal + (255 - canal) * 0.28).toString(16).padStart(2, '0');
  };
  return '#' + mezcla(1) + mezcla(3) + mezcla(5);
}

function graficasMoneda(valor, decimales) {
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: decimales, maximumFractionDigits: decimales }).format(valor);
}

function graficasValor(valor, formato) {
  if (valor === null || valor === undefined || Number.isNaN(Number(valor))) {
    return '—';
  }
  return formato === 'entero' ? new Intl.NumberFormat('es-MX').format(valor) : graficasMoneda(valor, 2);
}

function graficasCorto(valor, formato) {
  const absoluto = Math.abs(valor);
  const prefijo = formato === 'moneda' ? '$' : '';
  const compacto = function (n, sufijo) {
    return prefijo + n.toFixed(1).replace(/\.0$/, '') + sufijo;
  };
  if (absoluto >= 1000000) {
    return compacto(valor / 1000000, ' M');
  }
  if (absoluto >= 1000) {
    return compacto(valor / 1000, ' mil');
  }
  return prefijo + String(Math.round(valor));
}

function graficasVariacion(actual, previo) {
  if (actual === null || actual === undefined || !previo || previo <= 0) {
    return null;
  }
  return ((actual - previo) * 100) / previo;
}

/** Globo con jerarquía: periodo, una fila por serie (con variación) y total si es apilado. */
function graficasGlobo(espec) {
  const formato = espec.formato || 'moneda';
  return function (parametros) {
    const lista = Array.isArray(parametros) ? parametros : [parametros];
    if (!lista.length) {
      return '';
    }
    const caja = document.createElement('div');
    caja.className = 'globo';
    const titulo = document.createElement('div');
    titulo.className = 'globo__titulo';
    titulo.textContent = String(lista[0].name !== undefined && lista[0].name !== '' ? lista[0].name : lista[0].axisValueLabel);
    caja.appendChild(titulo);

    let total = 0;
    lista.forEach(function (item) {
      const valor = Array.isArray(item.value) ? item.value[1] : item.value;
      if (valor !== null && valor !== undefined) {
        total += Number(valor);
      }
      const fila = document.createElement('div');
      fila.className = 'globo__fila';
      const marca = document.createElement('span');
      marca.className = 'globo__marca';
      marca.style.backgroundColor = typeof item.color === 'string' ? item.color : espec.series[item.seriesIndex].color;
      const nombre = document.createElement('span');
      nombre.className = 'globo__nombre';
      nombre.textContent = item.seriesName;
      const cifra = document.createElement('strong');
      cifra.className = 'globo__cifra';
      cifra.textContent = graficasValor(valor, formato);
      fila.append(marca, nombre, cifra);
      caja.appendChild(fila);

      if (espec.comparaSeries && item.seriesIndex > 0) {
        const previa = espec.series[item.seriesIndex - 1];
        const variacion = graficasVariacion(valor, previa ? previa.datos[item.dataIndex] : null);
        const nota = document.createElement('div');
        nota.className = 'globo__variacion';
        if (variacion === null) {
          nota.textContent = 'vs ' + previa.nombre + ': sin base de comparación';
        } else {
          const sube = variacion >= 0;
          nota.classList.add(sube ? 'globo__variacion--sube' : 'globo__variacion--baja');
          nota.textContent = (sube ? '▲ +' : '▼ ') + variacion.toFixed(1) + ' % vs ' + previa.nombre;
        }
        caja.appendChild(nota);
      }
    });

    if (espec.tipo === 'apilado' && lista.length > 1) {
      const fila = document.createElement('div');
      fila.className = 'globo__fila globo__fila--total';
      const nombre = document.createElement('span');
      nombre.className = 'globo__nombre';
      nombre.textContent = 'Total';
      const cifra = document.createElement('strong');
      cifra.className = 'globo__cifra';
      cifra.textContent = graficasValor(total, formato);
      fila.append(nombre, cifra);
      caja.appendChild(fila);
      lista.forEach(function (item) {
        const valor = Array.isArray(item.value) ? item.value[1] : item.value;
        if (total > 0 && valor > 0) {
          const parte = document.createElement('div');
          parte.className = 'globo__variacion';
          parte.textContent = item.seriesName + ': ' + ((valor * 100) / total).toFixed(1) + ' % del total';
          caja.appendChild(parte);
        }
      });
    }

    return caja;
  };
}

function graficasDegradado(color, arriba, abajo) {
  return {
    type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
    colorStops: [{ offset: 0, color: color + arriba }, { offset: 1, color: color + abajo }],
  };
}

function graficasOpciones(espec, compacto, sinAnimacion) {
  const tema = graficasTema();
  const formato = espec.formato || 'moneda';
  const categorias = espec.categorias;
  const series = espec.series;
  const horizontal = espec.tipo === 'barras-h';
  const linea = espec.tipo === 'linea';
  const apilado = espec.tipo === 'apilado';
  const muchos = categorias.length > 60;
  const color = function (serie) { return graficasColor(serie.color, tema); };

  const ejeValores = {
    type: 'value',
    axisLabel: { color: tema.suave, fontFamily: GRAFICAS_FUENTE, fontSize: 11, formatter: function (v) { return graficasCorto(v, formato); } },
    splitLine: { lineStyle: { color: tema.borde, type: [4, 4] } },
    axisLine: { show: false },
    axisTick: { show: false },
  };
  const ejeCategorias = {
    type: 'category',
    data: categorias,
    inverse: horizontal,
    axisLabel: {
      color: horizontal ? tema.texto : tema.suave, fontFamily: GRAFICAS_FUENTE, fontSize: 11, hideOverlap: true,
      width: horizontal ? (compacto ? 110 : 190) : undefined, overflow: 'truncate',
    },
    axisLine: { lineStyle: { color: tema.bordeFuerte } },
    axisTick: { show: false },
    boundaryGap: !linea,
  };

  const marcaPeriodo = !horizontal && espec.resaltar && espec.resaltar.length === 2 && !(espec.resaltar[0] === 1 && espec.resaltar[1] === 12)
    ? { silent: true, itemStyle: { color: tema.oscuro ? 'rgba(120,170,220,0.12)' : 'rgba(70,121,166,0.08)' }, data: [[{ xAxis: espec.resaltar[0] - 1 }, { xAxis: espec.resaltar[1] - 1 }]] }
    : null;

  const grupos = series.map(function (serie, i) {
    const tono = color(serie);
    const base = { name: serie.nombre, data: serie.datos, emphasis: { focus: 'series' } };
    if (linea) {
      return Object.assign(base, {
        type: 'line',
        smooth: 0.2,
        showSymbol: categorias.length <= 24,
        symbolSize: 7,
        sampling: categorias.length > 300 ? 'lttb' : undefined,
        itemStyle: { color: tono },
        lineStyle: { width: 3, color: tono },
        areaStyle: { color: graficasDegradado(tono, i === series.length - 1 ? '40' : '14', '03') },
        connectNulls: false,
        markArea: i === 0 && marcaPeriodo ? marcaPeriodo : undefined,
      });
    }
    return Object.assign(base, {
      type: 'bar',
      stack: apilado ? 'total' : undefined,
      barMaxWidth: horizontal ? 22 : 38,
      large: muchos,
      largeThreshold: 600,
      itemStyle: {
        color: horizontal && series.length === 1
          ? function (p) { return tono + (p.dataIndex < 3 ? '' : 'cc'); }
          : tono,
        borderRadius: horizontal ? [0, 4, 4, 0] : (apilado ? 0 : [4, 4, 0, 0]),
      },
      label: horizontal ? { show: true, position: 'right', color: tema.texto, fontFamily: GRAFICAS_FUENTE, fontSize: 11, fontWeight: 600, formatter: function (p) { return graficasCorto(p.value, formato); } } : undefined,
      markArea: i === 0 && marcaPeriodo ? marcaPeriodo : undefined,
    });
  });

  return {
    aria: { enabled: true },
    color: series.map(color),
    backgroundColor: 'transparent',
    textStyle: { fontFamily: GRAFICAS_FUENTE },
    animation: !sinAnimacion,
    animationDuration: 750,
    animationEasing: 'cubicOut',
    animationDelay: function (indice) { return Math.min(indice * 25, 400); },
    animationDurationUpdate: 300,
    grid: { left: compacto ? 8 : 12, right: horizontal ? 56 : (compacto ? 10 : 24), top: 28, bottom: (series.length > 1 ? 44 : 12) + (muchos ? 36 : 0), containLabel: true },
    legend: series.length > 1
      ? { type: 'scroll', bottom: muchos ? 34 : 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8, textStyle: { color: tema.texto, fontFamily: GRAFICAS_FUENTE, fontSize: 12 }, pageIconSize: 10, pageTextStyle: { color: tema.suave } }
      : { show: false },
    tooltip: {
      trigger: horizontal ? 'item' : 'axis',
      axisPointer: { type: horizontal ? 'none' : 'shadow', shadowStyle: { color: tema.oscuro ? 'rgba(120,170,220,0.10)' : 'rgba(70,121,166,0.07)' }, lineStyle: { color: tema.bordeFuerte } },
      backgroundColor: tema.superficie,
      borderColor: tema.borde,
      borderWidth: 1,
      padding: 0,
      confine: true,
      extraCssText: 'border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.18);',
      formatter: graficasGlobo(espec),
    },
    toolbox: compacto ? { show: false } : {
      right: 4, top: 0, itemSize: 14,
      feature: { saveAsImage: { title: 'Guardar imagen', name: espec.titulo, pixelRatio: 2, backgroundColor: tema.superficie } },
      iconStyle: { borderColor: tema.suave },
    },
    dataZoom: muchos ? [{ type: 'inside' }, { type: 'slider', height: 18, bottom: 6, borderColor: tema.borde, textStyle: { color: tema.suave }, brushSelect: false }] : undefined,
    xAxis: horizontal ? ejeValores : ejeCategorias,
    yAxis: horizontal ? ejeCategorias : ejeValores,
    series: grupos,
  };
}

function graficasMensajeSinBiblioteca(figura) {
  const nota = document.createElement('p');
  nota.className = 'grafica__aviso';
  nota.textContent = 'No se pudo cargar la biblioteca de gráficas. Los mismos datos están en las tablas de esta página.';
  figura.replaceChildren(nota);
}

function dibujarGrafica(figura) {
  let espec;
  try {
    espec = JSON.parse(figura.getAttribute('data-grafica'));
  } catch (error) {
    return;
  }
  if (!espec || !espec.categorias || !espec.categorias.length || !espec.series || !espec.series.length) {
    return;
  }
  if (typeof echarts === 'undefined') {
    graficasMensajeSinBiblioteca(figura);
    return;
  }

  const sinAnimacion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const lienzo = document.createElement('div');
  lienzo.className = 'grafica__lienzo' + (espec.tipo === 'barras-h' ? ' grafica__lienzo--h' : '');
  if (espec.tipo === 'barras-h') {
    lienzo.style.height = Math.max(220, espec.categorias.length * 34 + 40) + 'px';
  }
  figura.replaceChildren(lienzo);

  const grafica = echarts.init(lienzo, null, { renderer: 'canvas' });
  const estado = { compacto: lienzo.clientWidth < 520 };
  grafica.setOption(graficasOpciones(espec, estado.compacto, sinAnimacion));
  graficasVivas.push({ grafica: grafica, espec: espec, estado: estado });

  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(function () {
      const ahoraCompacto = lienzo.clientWidth < 520;
      if (ahoraCompacto !== estado.compacto) {
        estado.compacto = ahoraCompacto;
        grafica.setOption(graficasOpciones(espec, ahoraCompacto, true), true);
      }
      grafica.resize();
    }).observe(lienzo);
  } else {
    window.addEventListener('resize', function () { grafica.resize(); });
  }
}

document.addEventListener('tema:cambio', function () {
  graficasVivas.forEach(function (viva) {
    viva.grafica.setOption(graficasOpciones(viva.espec, viva.estado.compacto, true), true);
  });
});

document.addEventListener('DOMContentLoaded', function () {
  const figuras = document.querySelectorAll('[data-grafica]');
  if (!('IntersectionObserver' in window)) {
    figuras.forEach(dibujarGrafica);
    return;
  }
  // Cada gráfica se crea al acercarse a la pantalla: la página abre rápido aunque tenga muchas.
  const observador = new IntersectionObserver(function (entradas) {
    entradas.forEach(function (entrada) {
      if (entrada.isIntersecting) {
        observador.unobserve(entrada.target);
        dibujarGrafica(entrada.target);
      }
    });
  }, { rootMargin: '200px' });
  figuras.forEach(function (figura) { observador.observe(figura); });
});

/**
 * modulos/miniaturas.js
 * ---------------------------------------------------------------------------
 * Miniaturas de producto en tablas (Inventario):
 *   - si la imagen no carga, se sustituye por un icono (sin innerHTML);
 *   - al pulsarla se muestra la imagen completa en el modal único.
 */

/** Cambia la miniatura rota por el marcador de "sin imagen". */
function marcarMiniaturaRota(imagen) {
  const contenedor = imagen.closest('.miniatura-producto');
  if (!contenedor || contenedor.classList.contains('miniatura-producto--vacia')) {
    return;
  }

  const icono = document.createElement('span');
  icono.className = 'material-symbols-outlined';
  icono.setAttribute('aria-hidden', 'true');
  icono.textContent = 'broken_image';

  contenedor.classList.add('miniatura-producto--vacia');
  contenedor.removeAttribute('data-ampliar-imagen');
  contenedor.disabled = true;
  imagen.replaceWith(icono);
}

/** Abre el modal con la imagen completa. */
function ampliarImagenProducto(boton) {
  const imagen = document.createElement('img');
  imagen.className = 'imagen-ampliada';
  imagen.src = boton.getAttribute('data-ampliar-imagen');
  imagen.alt = boton.getAttribute('data-ampliar-titulo') || '';
  imagen.addEventListener('error', function () {
    const aviso = document.createElement('p');
    aviso.className = 'texto-secundario';
    aviso.textContent = 'No se pudo cargar la imagen.';
    imagen.replaceWith(aviso);
  });

  abrirModal({
    sobreTitulo: 'Imagen del producto',
    titulo: boton.getAttribute('data-ampliar-titulo') || 'Producto',
    cuerpo: imagen,
  });
}

function inicializarMiniaturasProducto() {
  document.querySelectorAll('.miniatura-producto img').forEach(function (imagen) {
    // El error pudo ocurrir antes de registrar el listener.
    if (imagen.complete && imagen.naturalWidth === 0) {
      marcarMiniaturaRota(imagen);
      return;
    }
    imagen.addEventListener('error', function () {
      marcarMiniaturaRota(imagen);
    });
  });

  document.addEventListener('click', function (evento) {
    const boton = evento.target instanceof Element
      ? evento.target.closest('[data-ampliar-imagen]')
      : null;

    if (boton) {
      evento.preventDefault();
      ampliarImagenProducto(boton);
    }
  });
}

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
 * ordenCompra.js — partidas dinámicas del formulario de compra.
 *
 * Hay un bloque de partidas por tipo de compra (semiterminado o insumo); solo el del tipo elegido se envía
 * (el otro queda oculto y con sus campos deshabilitados). Agrega y quita renglones (clonando el primero, sin
 * innerHTML) y recalcula el importe de cada uno y el subtotal. El servidor vuelve a calcular todo: esto es
 * solo una ayuda visual.
 */
function iniciarOrdenCompra() {
  const bloques = document.querySelectorAll('[data-bloque-tipo]');
  if (!bloques.length) {
    return;
  }

  const formatoMoneda = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

  /** Muestra el bloque del tipo elegido y deshabilita los campos del otro. */
  const aplicarTipo = function () {
    const elegido = document.querySelector('[data-tipo-compra]:checked');
    const tipo = elegido ? elegido.value : 'semiterminado';
    bloques.forEach(function (bloque) {
      const activo = bloque.getAttribute('data-bloque-tipo') === tipo;
      bloque.hidden = !activo;
      bloque.querySelectorAll('input, select, textarea, button').forEach(function (campo) {
        campo.disabled = !activo;
      });
    });
  };

  const iniciarBloque = function (bloque) {
    const cuerpo = bloque.querySelector('[data-partidas-cuerpo]');
    const total = bloque.querySelector('[data-total]');

    const recalcular = function () {
      let centavos = 0;
      cuerpo.querySelectorAll('[data-partida]').forEach(function (fila) {
        const milesimas = Math.round((Number.parseFloat(fila.querySelector('[data-cantidad]').value) || 0) * 1000);
        const costo = Math.round((Number.parseFloat(fila.querySelector('[data-costo]').value) || 0) * 100);
        const importe = Math.round((milesimas * costo) / 1000);
        centavos += importe;
        fila.querySelector('[data-importe]').textContent = formatoMoneda.format(importe / 100);
      });
      total.textContent = formatoMoneda.format(centavos / 100);
    };

    /** El nombre del material solo se pide cuando la partida se controla en inventario. */
    const mostrarMaterial = function (fila) {
      const control = fila.querySelector('[data-controla]');
      const material = fila.querySelector('[data-material]');
      if (control && material) {
        material.hidden = control.value !== '1';
      }
    };

    bloque.querySelector('[data-agregar-partida]').addEventListener('click', function (evento) {
      evento.preventDefault();
      const nueva = cuerpo.querySelector('[data-partida]').cloneNode(true);
      nueva.querySelectorAll('input').forEach(function (campo) {
        campo.value = campo.hasAttribute('data-cantidad') ? '1' : (campo.hasAttribute('data-costo') ? '0.00' : '');
      });
      nueva.querySelectorAll('select').forEach(function (campo) {
        campo.selectedIndex = 0;
      });
      cuerpo.appendChild(nueva);
      mostrarMaterial(nueva);
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

    cuerpo.addEventListener('change', function (evento) {
      const fila = evento.target.closest('[data-partida]');
      if (!fila) {
        return;
      }
      if (evento.target.hasAttribute('data-controla')) {
        mostrarMaterial(fila);
        return;
      }
      // Al elegir un producto con costo actual, lo propone si el costo está en cero.
      if (evento.target.tagName === 'SELECT' && evento.target.name === 'producto_id[]') {
        const costo = evento.target.selectedOptions[0].getAttribute('data-costo-catalogo');
        const campo = fila.querySelector('[data-costo]');
        if (costo && Number.parseFloat(campo.value) === 0) {
          campo.value = Number.parseFloat(costo).toFixed(2);
          recalcular();
        }
      }
    });

    cuerpo.querySelectorAll('[data-partida]').forEach(mostrarMaterial);
    recalcular();
  };

  bloques.forEach(iniciarBloque);
  document.querySelectorAll('[data-tipo-compra]').forEach(function (radio) {
    radio.addEventListener('change', aplicarTipo);
  });
  aplicarTipo();
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
 * tema.js — Tema visual: claro, oscuro o automático (sigue al sistema operativo).
 *
 * El servidor pinta <html data-tema="..."> con la preferencia guardada (cookie a360_tema o la del usuario).
 * Aquí se aplica al elegirla en Ajustes → Apariencia, se recuerda en cookie + localStorage y se avisa a
 * quien dibuja gráficas con el evento «tema:cambio» para que adapten su paleta.
 */
const TEMAS_VALIDOS = ['claro', 'oscuro', 'auto'];
const CONSULTA_OSCURO = '(prefers-color-scheme: dark)';

/** Tema realmente visible ahora: «claro» u «oscuro» (resuelve «auto» con el sistema). */
function temaEfectivo() {
  const elegido = document.documentElement.getAttribute('data-tema');
  if (elegido === 'claro' || elegido === 'oscuro') {
    return elegido;
  }
  return window.matchMedia(CONSULTA_OSCURO).matches ? 'oscuro' : 'claro';
}

function avisarCambioDeTema() {
  document.dispatchEvent(new CustomEvent('tema:cambio', { detail: { tema: document.documentElement.getAttribute('data-tema'), efectivo: temaEfectivo() } }));
}

function aplicarTema(valor) {
  const tema = TEMAS_VALIDOS.indexOf(valor) >= 0 ? valor : 'auto';
  document.documentElement.setAttribute('data-tema', tema);
  try {
    document.cookie = 'a360_tema=' + tema + ';path=/;max-age=31536000;SameSite=Lax';
    window.localStorage.setItem('a360_tema', tema);
  } catch (error) {
    // Sin almacenamiento disponible: el cambio sigue valiendo en esta página.
  }
  avisarCambioDeTema();
}

function iniciarSelectorDeTema() {
  const selector = document.querySelector('[data-selector-tema]');
  const estado = document.querySelector('[data-estado-tema]');
  const token = selector ? selector.querySelector('input[name="token"]') : null;

  if (selector) {
    selector.querySelectorAll('input[name="tema"]').forEach(function (opcion) {
      opcion.addEventListener('change', function () {
        aplicarTema(opcion.value);
        if (!token) {
          return;
        }
        const datos = new FormData();
        datos.append('tema', opcion.value);
        datos.append('token', token.value);
        const meta = document.querySelector('meta[name="csrf"]');
        fetch('/admin/ajustes/tema', { method: 'POST', body: datos, credentials: 'same-origin', headers: { 'X-CSRF-Token': meta ? meta.content : '' } })
          .then(function (respuesta) { return respuesta.json(); })
          .then(function (cuerpo) {
            token.value = cuerpo.token || token.value;
            if (estado) {
              estado.textContent = cuerpo.ok ? 'Tema guardado.' : (cuerpo.mensaje || 'No se pudo guardar.');
            }
          })
          .catch(function () {
            if (estado) {
              estado.textContent = 'Se aplicó en este navegador, pero no se pudo guardar en tu usuario.';
            }
          });
      });
    });
  }

  // En «auto», si el sistema cambia de claro a oscuro, la página y las gráficas lo siguen.
  const sistema = window.matchMedia(CONSULTA_OSCURO);
  const alCambiarSistema = function () {
    if (document.documentElement.getAttribute('data-tema') === 'auto') {
      avisarCambioDeTema();
    }
  };
  if (sistema.addEventListener) {
    sistema.addEventListener('change', alCambiarSistema);
  }
}

document.addEventListener('DOMContentLoaded', iniciarSelectorDeTema);

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
