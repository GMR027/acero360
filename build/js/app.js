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
 * Pliega o despliega el menú lateral en ESCRITORIO (botón flotante).
 * No toca el cajón de móvil: ése lo gobierna alternarSidebar().
 * La preferencia se guarda en localStorage y la aplica el guion inline de
 * templates/sidebar.php, para que no haya parpadeo al recargar.
 */
function alternarMenuEscritorio() {
  const sidebar = document.getElementById('sidebar');
  const boton = document.getElementById('sidebar-toggle');

  if (!sidebar || !boton) {
    return;
  }

  const plegado = sidebar.classList.toggle('sidebar--plegado');
  boton.classList.toggle('sidebar__toggle--plegado', plegado);
  boton.setAttribute('aria-expanded', plegado ? 'false' : 'true');

  const etiqueta = plegado ? 'Mostrar el menú lateral' : 'Ocultar el menú lateral';
  boton.setAttribute('aria-label', etiqueta);
  boton.setAttribute('title', etiqueta);

  try {
    window.localStorage.setItem('a360_menu_plegado', plegado ? '1' : '0');
  } catch (e) {
    /* Almacenamiento no disponible: el estado vale sólo para esta sesión. */
  }
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

  // Botón flotante de escritorio: pliega/despliega el menú para ganar espacio.
  const botonToggleMenu = document.getElementById('sidebar-toggle');
  if (botonToggleMenu) {
    botonToggleMenu.addEventListener('click', function () {
      alternarMenuEscritorio();
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

  // 5b. Campos de archivo: aspecto de zona de carga, lista de elegidos y
  //     arrastrar y soltar (va antes de mover campos en modales).
  prepararCargaArchivos();

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

  // 9. Campos de contraseña con botón para mostrar lo que se escribió.
  prepararBotonesVerContrasena();

  // 10. Selectores largos (p. ej. el cliente del cotizador) como búsqueda por texto.
  prepararSelectoresBusqueda();

  // 11. Modales del hub de Operación Comercial (entrega, incidencia, devolución).
  prepararOperacionComercial();

  // 12. «Pagado a → Proveedor» del gasto: RFC del elegido, alta rápida que
  //     vuelve con el formulario precargado (va después del buscador de arriba).
  prepararGastoProveedor();

  // 13. Subida de facturas y comprobantes desde la ventana modal.
  prepararArchivosModal();

  // 14. Campos que todavía no tienen información: borde rojo desde que se abre
  //     el formulario (obligatorios) o rojo punteado (recomendados).
  inicializarCamposPendientes();

  // 15. Vista previa de facturas y comprobantes (PDF, XML e imágenes) en el modal.
  inicializarDocumentoPreview();

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
 * modulos/archivosModal.js
 * ---------------------------------------------------------------------------
 * Subida de archivos desde la ventana modal (el mismo `modal__panel` que abre
 * «Registro Venta Inteligente»).
 *
 * Cada botón [data-archivo-modal="id-del-input"] abre el diálogo con el bloque
 * <div class="campo"> REAL: se mueve al modal y, al cerrar, vuelve a su hueco,
 * de modo que los archivos elegidos siguen viajando con el formulario. Para eso
 * el <input type="file"> trae el atributo form="…", indispensable porque el
 * diálogo vive fuera del <form>.
 *
 * Sin JavaScript el botón permanece oculto y el campo queda en línea, como
 * siempre: el módulo sólo lo sustituye cuando hay modal disponible.
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, sin alert, ni
 * confirm, ni prompt.
 */

/**
 * Resume junto al botón los archivos elegidos («2 archivos elegidos: …»).
 *
 * @param {HTMLInputElement} campo
 * @param {HTMLElement|null} resumen  Nodo [data-archivo-resumen] del botón.
 */
function resumirArchivos(campo, resumen) {
  if (!resumen) {
    return;
  }

  const nombres = campo.files
    ? Array.prototype.map.call(campo.files, function (archivo) { return archivo.name; })
    : [];

  if (!nombres.length) {
    resumen.textContent = '';
    resumen.hidden = true;
    return;
  }

  resumen.textContent = nombres.length === 1
    ? '1 archivo elegido: ' + nombres[0]
    : nombres.length + ' archivos elegidos: ' + nombres.join(' · ');
  resumen.hidden = false;
}

/** Prepara todos los botones [data-archivo-modal] de la página. */
function prepararArchivosModal() {
  document.querySelectorAll('[data-archivo-modal]').forEach(function (boton) {
    const campo = document.getElementById(boton.getAttribute('data-archivo-modal'));
    if (!campo) {
      return;
    }

    const bloque = campo.closest('.campo') || campo.parentNode;
    const hueco = { padre: bloque.parentNode, siguiente: bloque.nextSibling };
    const contenedor = boton.parentNode;
    const resumen = contenedor ? contenedor.querySelector('[data-archivo-resumen]') : null;
    const etiqueta = bloque.querySelector('.campo__etiqueta');

    campo.addEventListener('change', function () {
      resumirArchivos(campo, resumen);
    });

    // Con JavaScript el botón sustituye al campo en línea (sin él no se llega aquí).
    bloque.hidden = true;
    boton.hidden = false;

    boton.addEventListener('click', function (evento) {
      evento.preventDefault();

      if (typeof abrirModal !== 'function') {
        // Sin ventana modal disponible se vuelve al campo de la página.
        bloque.hidden = false;
        boton.hidden = true;
        return;
      }

      bloque.hidden = false;
      abrirModal({
        sobreTitulo: boton.getAttribute('data-archivo-sobre') || '',
        titulo: boton.getAttribute('data-archivo-titulo')
          || (etiqueta ? etiqueta.textContent.trim() : 'Subir archivos'),
        cuerpo: bloque,
        textoConfirmar: 'Listo',
        alConfirmar: function () {
          // Los archivos ya quedaron en el campo: el diálogo sólo se cierra.
        },
        alCerrar: function () {
          // El bloque vuelve a su hueco ANTES de que el modal vacíe su cuerpo.
          hueco.padre.insertBefore(bloque, hueco.siguiente);
          bloque.hidden = true;
          boton.hidden = false;
          resumirArchivos(campo, resumen);
        },
      });
    });
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
  '/admin/calendario': 'Calendario del mes: elige un día para ver sus eventos o crear un recordatorio.',
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
  '/admin/compras': 'Resumen de compras: lo que falta recibir, facturar y pagar.',
  '/admin/compras/pedidos': 'Todas tus compras a proveedores, con filtros por proveedor, fecha, categoría, factura y pago.',
  '/admin/compras/crear': 'Registrar una compra: semiterminados para vender o materiales e insumos.',
  '/admin/compras/recepciones': 'Lo pendiente de recibir, los semiterminados por terminar y los materiales en inventario.',
  '/admin/compras/catalogo': 'Los productos semiterminados que te vende cada proveedor, con su código y costo.',
  '/admin/compras/catalogo/crear': 'Dar de alta un producto semiterminado de un proveedor.',
  '/admin/compras/facturas': 'Qué compras están facturadas, pendientes de factura o sin factura.',
  '/admin/compras/pagos': 'Saldos pendientes con proveedores y pagos realizados.',
  '/admin/proveedores': 'Directorio de proveedores y su expediente: compras, facturas y pagos.',
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
 * modulos/calendario.js
 * ---------------------------------------------------------------------------
 * Ventanas modales del Calendario.
 *
 * Dos usos distintos sobre el mismo modal de la aplicación:
 *
 *   · ALTA: «Nuevo recordatorio o evento». El formulario vive OCULTO en la
 *     página (contenedor [data-alta-calendario-contenedor]) y aquí se MUEVE al
 *     modal, nunca se copia: así los id de los campos (fecha, nombre, hora…)
 *     siguen siendo únicos y la validación en cliente, que trabaja por id, no
 *     cambia. Al cerrar el modal el formulario regresa a su contenedor.
 *     Se abre con el botón de la cabecera o haciendo clic una SEGUNDA vez
 *     sobre la casilla ya seleccionada.
 *
 *   · VER: el botón con icono de cada día de la Agenda abre el detalle de ese
 *     día (los marcadores del negocio con sus cifras y los recordatorios). Los
 *     datos viajan en el atributo data-agenda-dia de la propia tarjeta.
 *
 * La Agenda y el calendario se sincronizan solos: al cargar con un día
 * elegido, la página baja hasta su tarjeta de la Agenda.
 */

/** Formato largo de una fecha ISO: «viernes 23 de octubre de 2026». */
function calendarioFechaLarga(iso) {
  const partes = String(iso).split('-');
  if (partes.length !== 3) {
    return String(iso);
  }

  const fecha = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
  if (Number.isNaN(fecha.getTime())) {
    return String(iso);
  }

  return new Intl.DateTimeFormat('es-MX', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(fecha);
}

/** Contenedor que guarda el formulario de alta mientras no se usa. */
function calendarioContenedorAlta() {
  return document.querySelector('[data-alta-calendario-contenedor]');
}

/**
 * Carga la fecha elegida en el formulario: el campo de fecha y los tres
 * ocultos (anio, mes, dia) que usan las páginas de regreso si hay un error.
 */
function calendarioCargarFecha(formulario, iso) {
  const partes = String(iso).split('-');
  if (partes.length !== 3) {
    return false;
  }

  const fecha = formulario.querySelector('[name="fecha"]');
  const anio  = formulario.querySelector('[name="anio"]');
  const mes   = formulario.querySelector('[name="mes"]');
  const dia   = formulario.querySelector('[name="dia"]');

  if (fecha) {
    fecha.value = iso;
  }
  if (anio) {
    anio.value = partes[0];
  }
  if (mes) {
    mes.value = String(Number(partes[1]));
  }
  if (dia) {
    dia.value = String(Number(partes[2]));
  }

  return true;
}

/** Abre el alta de un evento en ventana modal, cargando la fecha indicada. */
function calendarioAbrirAlta(iso) {
  const contenedor = calendarioContenedorAlta();
  if (!contenedor || !iso) {
    return;
  }

  const formulario = contenedor.querySelector('[data-formulario-alta]');
  if (!formulario || !calendarioCargarFecha(formulario, iso)) {
    return;
  }

  const botonGuardar = document.createElement('button');
  botonGuardar.type = 'button';
  botonGuardar.className = 'btn btn--primario';
  botonGuardar.textContent = 'Guardar';
  botonGuardar.addEventListener('click', function () {
    // Dispara el envío normal del formulario (con su token y su validación en
    // cliente). No se usa alConfirmar del modal porque éste cierra SIEMPRE,
    // y si el formulario tiene un error tendría que seguir abierto para
    // corregirlo.
    formulario.requestSubmit();
  });

  // El modal vacía su cuerpo cada vez que se abre: el formulario se devuelve a
  // su contenedor al cerrar, sea como sea (Guardar, Cancelar o Escape).
  abrirModal({
    sobreTitulo: 'Calendario',
    titulo: 'Nuevo recordatorio o evento · ' + calendarioFechaLarga(iso),
    cuerpo: formulario,
    acciones: [botonGuardar],
    alCerrar: function () {
      contenedor.appendChild(formulario);
    },
  });

  const campoNombre = formulario.querySelector('[name="nombre"]');
  if (campoNombre) {
    campoNombre.focus();
  }
}

/**
 * Cuerpo del modal «Ver»: el resumen del negocio de ese día y, debajo, el
 * listado de recordatorios y eventos capturados. Cada evento trae su icono,
 * su nombre y un botón para abrir la ficha completa. Se construye con
 * createElement, nunca con innerHTML.
 */
function calendarioCuerpoDetalle(datos) {
  const envoltura = document.createElement('div');
  envoltura.className = 'agenda-detalle';

  // --- Resumen de lo que pasó en los negocios ---
  if (!datos.negocio || datos.negocio.length === 0) {
    const vacio = document.createElement('p');
    vacio.className = 'texto-secundario';
    vacio.textContent = 'Ese día no hubo ventas, gastos ni recepciones.';
    envoltura.appendChild(vacio);
  } else {
    datos.negocio.forEach(function (fila) {
      const filaNodo = document.createElement('div');
      filaNodo.className = 'agenda-detalle__fila';

      const concepto = document.createElement('span');
      concepto.className = 'agenda-detalle__concepto';

      const icono = document.createElement('span');
      icono.className = 'material-icons agenda-dia__icono agenda-dia__icono--' + fila.color;
      icono.setAttribute('aria-hidden', 'true');
      icono.textContent = fila.icono;
      concepto.appendChild(icono);

      const texto = document.createElement('span');
      texto.textContent = fila.n + ' ' + (fila.n === 1 ? fila.etiqueta : fila.plural);
      concepto.appendChild(texto);
      filaNodo.appendChild(concepto);

      if (fila.importe !== null && fila.importe !== undefined) {
        const cifra = document.createElement('span');
        cifra.className = 'agenda-detalle__cifra';
        cifra.textContent = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(fila.importe);
        filaNodo.appendChild(cifra);
      }

      envoltura.appendChild(filaNodo);
    });
  }

  // --- Listado de recordatorios y eventos del día ---
  const eventos = Array.isArray(datos.eventos) ? datos.eventos : [];

  const titulo = document.createElement('h3');
  titulo.className = 'agenda-detalle__titulo';
  titulo.textContent = eventos.length === 1 ? 'Evento del día' : 'Eventos del día';
  envoltura.appendChild(titulo);

  if (eventos.length === 0) {
    const sinEventos = document.createElement('p');
    sinEventos.className = 'texto-secundario agenda-detalle__pie';
    sinEventos.textContent = 'No hay recordatorios ni eventos capturados ese día.';
    envoltura.appendChild(sinEventos);
    return envoltura;
  }

  const listado = document.createElement('div');
  listado.className = 'agenda-detalle__eventos';
  eventos.forEach(function (evento) {
    listado.appendChild(calendarioFilaEvento(datos, evento));
  });
  envoltura.appendChild(listado);

  return envoltura;
}

/**
 * Una fila del listado de eventos: icono de importancia, nombre, hora y
 * negocio, y el botón «Ver» (icono de ojo) que abre la ficha del evento.
 */
function calendarioFilaEvento(datos, evento) {
  const fila = document.createElement('div');
  fila.className = 'agenda-detalle__evento';

  const icono = document.createElement('span');
  icono.className = 'agenda-detalle__evento-icono agenda-detalle__evento-icono--' + (evento.importancia || 'medio');
  icono.setAttribute('aria-hidden', 'true');
  const iconoTexto = document.createElement('span');
  iconoTexto.className = 'material-symbols-outlined';
  iconoTexto.textContent = evento.importanciaIcono || 'event';
  icono.appendChild(iconoTexto);
  fila.appendChild(icono);

  const cuerpo = document.createElement('div');
  cuerpo.className = 'agenda-detalle__evento-cuerpo';

  const nombre = document.createElement('strong');
  nombre.className = 'agenda-detalle__evento-nombre';
  nombre.textContent = evento.nombre || 'Evento sin nombre';
  cuerpo.appendChild(nombre);

  const meta = [];
  if (evento.hora) {
    meta.push(evento.hora);
  }
  if (evento.negocio) {
    meta.push(evento.negocio);
  }
  if (meta.length > 0) {
    const detalle = document.createElement('span');
    detalle.className = 'agenda-detalle__evento-meta';
    detalle.textContent = meta.join(' · ');
    cuerpo.appendChild(detalle);
  }
  fila.appendChild(cuerpo);

  const ver = document.createElement('button');
  ver.type = 'button';
  ver.className = 'btn btn--secundario btn--sm btn--icono agenda-detalle__evento-ver';
  ver.title = 'Ver el detalle del evento';
  ver.setAttribute('aria-label', 'Ver el detalle de ' + (evento.nombre || 'el evento'));
  const verIcono = document.createElement('span');
  verIcono.className = 'material-symbols-outlined';
  verIcono.setAttribute('aria-hidden', 'true');
  verIcono.textContent = 'visibility';
  ver.appendChild(verIcono);
  ver.addEventListener('click', function () {
    calendarioVerEvento(datos, evento);
  });
  fila.appendChild(ver);

  return fila;
}

/** Ficha completa de un evento, dentro del mismo modal. */
function calendarioVerEvento(dia, evento) {
  const cuerpo = document.createElement('div');
  cuerpo.className = 'agenda-evento-detalle';

  const cabecera = document.createElement('div');
  cabecera.className = 'agenda-evento-detalle__cabecera';

  const insignia = document.createElement('span');
  insignia.className = 'badge ' + (evento.importanciaClase || 'badge--neutro');
  insignia.textContent = evento.importanciaEtiqueta || 'Evento';
  cabecera.appendChild(insignia);

  if (evento.hora) {
    const hora = document.createElement('span');
    hora.className = 'agenda-evento-detalle__hora';
    hora.textContent = evento.hora;
    cabecera.appendChild(hora);
  }
  cuerpo.appendChild(cabecera);

  const nombre = document.createElement('p');
  nombre.className = 'agenda-evento-detalle__nombre';
  nombre.textContent = evento.nombre || 'Evento sin nombre';
  cuerpo.appendChild(nombre);

  const lista = document.createElement('dl');
  lista.className = 'agenda-evento-detalle__lista';

  const agregar = function (termino, valor) {
    if (!valor) {
      return;
    }
    const dt = document.createElement('dt');
    dt.textContent = termino;
    const dd = document.createElement('dd');
    dd.textContent = valor;
    lista.appendChild(dt);
    lista.appendChild(dd);
  };

  agregar('Fecha', dia.titulo);
  agregar('Negocio', evento.negocio);
  agregar('Autor', evento.autor);
  cuerpo.appendChild(lista);

  const descripcion = document.createElement('p');
  descripcion.className = 'agenda-evento-detalle__descripcion';
  descripcion.textContent = evento.descripcion || 'Sin descripción.';
  cuerpo.appendChild(descripcion);

  const volver = document.createElement('button');
  volver.type = 'button';
  volver.className = 'btn btn--secundario';
  volver.textContent = 'Volver al día';
  volver.addEventListener('click', function () {
    calendarioVerDiaDatos(dia);
  });

  const editar = document.createElement('a');
  editar.className = 'btn btn--primario';
  editar.href = evento.rutaEditar || '#';
  editar.textContent = 'Editar evento';

  abrirModal({
    sobreTitulo: 'Evento del calendario',
    titulo: 'Detalle del evento',
    cuerpo: cuerpo,
    acciones: [volver, editar],
  });
}

/** Abre el detalle de un día de la Agenda en ventana modal. */
function calendarioVerDiaDatos(datos) {
  const enlaceDia = document.createElement('a');
  enlaceDia.className = 'btn btn--secundario';
  enlaceDia.href = datos.rutaDia;
  enlaceDia.textContent = 'Ver en el calendario';

  abrirModal({
    sobreTitulo: datos.hoy ? 'Hoy' : 'Agenda del mes',
    titulo: datos.titulo,
    cuerpo: calendarioCuerpoDetalle(datos),
    acciones: [enlaceDia],
  });
}

/** Abre el detalle de un día de la Agenda en ventana modal. */
function calendarioVerDia(tarjeta) {
  let datos = null;
  try {
    datos = JSON.parse(tarjeta.getAttribute('data-agenda-dia'));
  } catch (error) {
    datos = null;
  }
  if (!datos) {
    return;
  }

  calendarioVerDiaDatos(datos);
}

/** Baja hasta la tarjeta de la Agenda del día elegido y la resalta. */
function calendarioIrAlDia() {
  const casilla = document.querySelector('a.calendario__dia[aria-current="date"][data-fecha]');
  if (!casilla) {
    return;
  }

  const tarjeta = document.getElementById('agenda-' + casilla.getAttribute('data-fecha'));
  if (!tarjeta) {
    return;
  }

  tarjeta.classList.add('agenda-dia--rastreado');
  const esVisible = tarjeta.getBoundingClientRect().top >= 0
    && tarjeta.getBoundingClientRect().bottom <= window.innerHeight;

  // Sólo se baja si la tarjeta no se ve completa: así un clic no mueve la
  // pantalla debajo de los pies del usuario.
  if (!esVisible) {
    tarjeta.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function iniciarCalendario() {
  document.addEventListener('click', function (evento) {
    const objetivo = evento.target;
    if (!(objetivo instanceof Element)) {
      return;
    }

    // Botón «Ver» de una tarjeta de la Agenda.
    const botonVer = objetivo.closest('[data-ver-dia]');
    if (botonVer) {
      const tarjeta = botonVer.closest('[data-agenda-dia]');
      if (tarjeta) {
        calendarioVerDia(tarjeta);
      }
      return;
    }

    // Segunda vez sobre la casilla ya seleccionada: se abre el alta. La
    // primera vez el clic navega y selecciona el día, como siempre.
    const casilla = objetivo.closest('a.calendario__dia[data-fecha]');
    if (casilla && casilla.getAttribute('aria-current') === 'date') {
      evento.preventDefault();
      calendarioAbrirAlta(casilla.getAttribute('data-fecha'));
      return;
    }

    const boton = objetivo.closest('[data-alta-calendario]');
    if (boton) {
      calendarioAbrirAlta(boton.getAttribute('data-fecha'));
    }
  });

  calendarioIrAlDia();
}

document.addEventListener('DOMContentLoaded', iniciarCalendario);

/**
 * modulos/camposPendientes.js
 * ---------------------------------------------------------------------------
 * Marca desde que se abre el formulario los campos que todavía no tienen
 * información, para que se vea de un vistazo qué falta llenar.
 *
 *   • Obligatorio pendiente → borde rojo continuo (.campo--pendiente).
 *   • Recomendado pendiente → borde rojo punteado (.campo--sugerido).
 *
 * La marca desaparece en cuanto el campo se llena (input/change) y se vuelve
 * a calcular cuando otro campo cambia la condición de éste (p. ej. «Pagado a»).
 *
 * Reglas declarativas en el HTML (sin lógica por formulario):
 *   data-obligatorio-si="#beneficiario_tipo=proveedor"
 *   data-recomendado-si="#comprobante_fiscal=1"
 *   data-recomendado
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, ni alert, ni
 * confirm, ni prompt.
 */

/** ¿Se cumple la condición escrita en un atributo data-…-si? */
function cumpleReglaCondicional(control, atributo) {
  const regla = control.getAttribute(atributo);
  if (!regla) {
    return false;
  }

  const separador = regla.indexOf('=');
  const selector = (separador === -1 ? regla : regla.slice(0, separador)).trim();
  const disparador = selector !== '' ? document.querySelector(selector) : null;
  if (!disparador) {
    return false;
  }

  const valor = String(disparador.value || '').trim();
  if (separador === -1) {
    return valor !== '';
  }

  return regla
    .slice(separador + 1)
    .split('|')
    .map(function (esperado) { return esperado.trim(); })
    .indexOf(valor) !== -1;
}

/** ¿El control es obligatorio ahora (por required o por una regla condicional)? */
function campoObligatorioAhora(control) {
  return control.hasAttribute('required') || cumpleReglaCondicional(control, 'data-obligatorio-si');
}

/** ¿El control es recomendado ahora (aunque no sea obligatorio)? */
function campoRecomendadoAhora(control) {
  return control.hasAttribute('data-recomendado') || cumpleReglaCondicional(control, 'data-recomendado-si');
}

/** ¿El control sigue vacío? (checkbox, radio, archivo, select y texto). */
function campoEstaVacio(control) {
  if (control.type === 'checkbox' || control.type === 'radio') {
    return !control.checked;
  }
  if (control.type === 'file') {
    return !control.files || control.files.length === 0;
  }

  return String(control.value || '').trim() === '';
}

/** ¿El .campo se está mostrando? (los condicionales usan hidden o .campo--oculto). */
function campoVisible(campo) {
  return !campo.hidden
    && !campo.closest('[hidden]')
    && !campo.classList.contains('campo--oculto');
}

/**
 * Recalcula las marcas de un .campo concreto.
 *
 * @param {HTMLElement} campo
 */
function actualizarCampoPendiente(campo) {
  if (!campoVisible(campo)) {
    campo.classList.remove('campo--pendiente', 'campo--sugerido');
    return;
  }

  let pendiente = false;
  let sugerido = false;

  campo.querySelectorAll('input, select, textarea').forEach(function (control) {
    if (control.disabled || control.type === 'hidden') {
      return;
    }

    const obligatorio = campoObligatorioAhora(control);
    const recomendado = !obligatorio && campoRecomendadoAhora(control);
    if (!obligatorio && !recomendado) {
      return;
    }
    if (!campoEstaVacio(control)) {
      return;
    }

    if (obligatorio) {
      pendiente = true;
    } else {
      sugerido = true;
    }
  });

  campo.classList.toggle('campo--pendiente', pendiente);
  campo.classList.toggle('campo--sugerido', !pendiente && sugerido);

  // El color no basta: los lectores de pantalla también deben saber que falta.
  campo.querySelectorAll('.control').forEach(function (control) {
    if (pendiente) {
      control.setAttribute('aria-invalid', 'true');
    } else {
      control.removeAttribute('aria-invalid');
    }
  });
}

/** Recalcula todos los campos de un formulario (o de la página). */
function actualizarCamposPendientes(raiz) {
  const ambito = raiz && raiz.querySelectorAll ? raiz : document;
  ambito.querySelectorAll('.campo').forEach(actualizarCampoPendiente);
}

/**
 * Arranca el marcado en todos los formularios de la página.
 * Se llama después de preparar los selectores de búsqueda y los campos
 * condicionales, para que el DOM ya esté completo.
 */
function inicializarCamposPendientes() {
  // En las pantallas de acceso (login/registro) no se marca nada: los campos
  // vacíos son lo normal y el rojo parecería un error antes de escribir.
  if (document.querySelector('main.auth')) {
    return;
  }

  // La leyenda solo tiene sentido cuando el marcado funciona (sin JS no hay bordes).
  document.querySelectorAll('[data-leyenda-pendientes]').forEach(function (nodo) {
    nodo.hidden = false;
  });

  actualizarCamposPendientes(document);

  // Al escribir o elegir algo se recalcula el campo afectado (y sus condiciones).
  // En fase de burbuja: los módulos que muestran/ocultan campos condicionales
  // escuchan en el propio control y ya corrieron cuando llega este evento.
  ['input', 'change'].forEach(function (evento) {
    document.addEventListener(evento, function (e) {
      if (!(e.target instanceof Element)) {
        return;
      }

      const campo = e.target.closest('.campo');
      if (campo) {
        actualizarCampoPendiente(campo);
      }
      // Un cambio puede mostrar u ocultar otros campos: se refresca el formulario.
      const formulario = e.target.closest('form');
      if (formulario) {
        actualizarCamposPendientes(formulario);
      }
    });
  });
}

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

/**
 * Precios acordados con el cliente elegido, indexados por producto_id.
 *
 * El servidor los devuelve al cambiar el cliente. Se guardan aparte de los
 * renglones porque un producto puede agregarse DESPUÉS de elegir al cliente y
 * también tiene que nacer con el precio acordado (antes nacía con el de lista).
 */
let cotizadorPreciosCliente = {};

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

/**
 * Precio con el que nace un renglón: el acordado con el cliente si ya se
 * consultó, o el de lista cuando no hay acuerdo.
 */
function precioCotizadorCliente(productoId, precioLista) {
  const especial = cotizadorPreciosCliente[String(productoId)];
  const numero = Number(especial);
  return especial !== undefined && Number.isFinite(numero) ? numero : precioLista;
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
    precio: precioCotizadorCliente(producto.id, producto.precio),
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
 * Pide al servidor los precios acordados del cliente y los deja en caché.
 *
 * Se usa fetch + JSON: la respuesta no contiene HTML, sólo datos, de modo
 * que las filas se reconstruyen con createElement(). La caché sirve para que
 * los productos que se agreguen DESPUÉS también nazcan con el precio acordado;
 * `aplicar` decide si además se actualizan los renglones ya cargados (al cambiar
 * de cliente sí; al abrir una cotización guardada no, porque sus precios manda).
 *
 * @param {number}  clienteId
 * @param {boolean} aplicar
 */
function cargarPreciosCliente(clienteId, aplicar) {
  if (!cotizadorNodos || clienteId <= 0) {
    return;
  }

  const ruta = cotizadorNodos.estado ? cotizadorNodos.estado.getAttribute('data-ruta-precios') : '';
  if (!ruta) {
    return;
  }

  fetch(ruta + '?cliente=' + clienteId, { credentials: 'same-origin' })
    .then(function (respuesta) {
      if (!respuesta.ok) {
        throw new Error('HTTP ' + respuesta.status);
      }
      return respuesta.json();
    })
    .then(function (datos) {
      const precios = datos && datos.precios ? datos.precios : {};
      cotizadorPreciosCliente = precios;

      if (!aplicar) {
        return;
      }

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
      // Sin datos no se inventa nada: los productos nuevos usan el precio de lista.
      cotizadorPreciosCliente = {};
      if (aplicar) {
        crearAlerta('advertencia', 'No se pudieron consultar los precios del cliente.');
      }
    });
}

/**
 * Cuando cambia el cliente se piden sus precios acordados al servidor.
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

  // Los acuerdos del cliente anterior dejan de valer en cuanto cambia la ficha.
  cotizadorPreciosCliente = {};

  if (clienteId === 0) {
    return;
  }

  cargarPreciosCliente(clienteId, true);
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

  /* Cliente precargado (al editar una cotización o al abrir el formulario con
   * ?cliente=): se traen sus precios acordados para que los productos que se
   * agreguen después también nazcan con ese precio. NO se aplican a los
   * renglones ya guardados: conservan lo que se cotizó en su momento. */
  const clienteInicial = cotizadorNodos.cliente ? Number.parseInt(cotizadorNodos.cliente.value, 10) || 0 : 0;
  if (clienteInicial > 0) {
    cargarPreciosCliente(clienteInicial, false);
  }

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

/**
 * modulos/gastosMasivos.js — selección múltiple de gastos para eliminarlos.
 *
 * En la lista de gastos, las casillas de la primera columna marcan los
 * registros que se mandan a POST /admin/contabilidad/gastos/masivo. Este
 * módulo:
 *
 *   · muestra la barra del pie sólo con algo marcado y cuenta gastos y
 *     documentos adjuntos (lee data-documentos de cada fila, sin consultar);
 *   · exige el motivo antes de continuar (el servidor lo vuelve a exigir);
 *   · arma el mensaje de la confirmación con esos números y con el motivo.
 *
 * El envío lo termina app.js (data-confirmacion): aquí sólo se decide si se
 * puede enviar y con qué mensaje.
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, ni alert, ni
 * confirm, ni prompt.
 */

/** «1 gasto marcado» / «3 gastos marcados». */
function pluralGastosMasivos(n, uno, varios) {
  return n === 1 ? uno : varios;
}

function iniciarGastosMasivos() {
  const formulario = document.querySelector('[data-gastos-masivos]');
  if (!formulario) {
    return;
  }

  const casillas = formulario.querySelectorAll('input[name="gastos[]"]');
  const todas = formulario.querySelector('[data-marcar-todas]');
  const barra = document.querySelector('[data-resumen-seleccion]');
  const contador = barra ? barra.querySelector('[data-seleccion-contador]') : null;
  const adjuntos = barra ? barra.querySelector('[data-seleccion-documentos]') : null;
  const boton = formulario.querySelector('[data-eliminar-gastos]');
  const motivo = formulario.querySelector('[name="motivo"]');

  if (!barra || !contador || !adjuntos || !boton || !motivo || casillas.length === 0) {
    return;
  }

  let marcados = 0;
  let documentos = 0;

  const actualizar = function () {
    marcados = 0;
    documentos = 0;

    casillas.forEach(function (casilla) {
      if (!casilla.checked) {
        return;
      }
      marcados += 1;
      documentos += Number(casilla.getAttribute('data-documentos') || 0);
    });

    if (todas) {
      todas.checked = marcados === casillas.length;
      todas.indeterminate = marcados > 0 && marcados < casillas.length;
    }

    barra.hidden = marcados === 0;
    boton.disabled = marcados === 0;
    contador.textContent = marcados === 0
      ? 'Ningún gasto marcado'
      : marcados + ' ' + pluralGastosMasivos(marcados, 'gasto marcado', 'gastos marcados');
    adjuntos.textContent = documentos === 0
      ? 'Sin documentos adjuntos que borrar'
      : documentos === 1
        ? 'Se borrará 1 documento adjunto con él'
        : 'Se borrarán ' + documentos + ' documentos adjuntos con ellos';
  };

  formulario.addEventListener('change', function (evento) {
    if (evento.target === todas) {
      casillas.forEach(function (casilla) {
        casilla.checked = todas.checked;
      });
    }
    actualizar();
  });

  motivo.addEventListener('input', function () {
    if (motivo.value.trim().length >= 5) {
      limpiarErrores(formulario);
    }
  });

  // Se ejecuta antes que el manejador de app.js (los submit suben del campo
  // al documento), así que el mensaje que se escriba aquí es el que se lee.
  formulario.addEventListener('submit', function (evento) {
    if (marcados === 0) {
      evento.preventDefault();
      evento.stopPropagation();
      crearAlerta('advertencia', 'Marca al menos un gasto de la lista para eliminarlo.', 'Selección vacía');
      return;
    }

    const texto = (motivo.value || '').trim();
    if (texto.length < 5) {
      evento.preventDefault();
      evento.stopPropagation();
      limpiarErrores(formulario);
      mostrarErrorCampo(motivo, 'Escribe el motivo de la eliminación: mínimo 5 caracteres.');
      motivo.focus();
      return;
    }

    formulario.setAttribute(
      'data-confirmacion-mensaje',
      'Se eliminarán ' + marcados + ' ' + pluralGastosMasivos(marcados, 'gasto', 'gastos')
        + ' y ' + documentos + ' ' + pluralGastosMasivos(documentos, 'documento adjunto', 'documentos adjuntos')
        + ' (filas y archivos), con el motivo «' + texto + '».'
    );
  });

  actualizar();
}

document.addEventListener('DOMContentLoaded', iniciarGastosMasivos);

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
/**
 * Avisa de un documento que ya tiene registro.
 *
 * @param {Array}   coincidencias
 * @param {string}  ruta    formulario al que se vuelve con «Registrar de todos modos»
 * @param {string}  origen
 * @param {boolean} fuerte  true = mismo folio fiscal: no se ofrece «registrar de todos modos»
 */
function avisarDuplicado(coincidencias, ruta, origen, fuerte) {
  // Mismo folio fiscal (o folio de factura) = el mismo documento: no hay forma
  // legítima de «registrarlo de todos modos». El aviso sólo ofrece abrirlo.
  const porUuid = Array.isArray(coincidencias) && coincidencias.some(function (c) {
    return /\(UUID\)/i.test(String(c.criterio || ''));
  });
  fuerte = fuerte || porUuid;

  const cuerpo = document.createDocumentFragment();

  cuerpo.appendChild(nodoInteligente(
    'p',
    'nota nota--advertencia mb-md',
    fuerte
      ? 'Este pago ya está registrado con el mismo folio fiscal (UUID). Un mismo CFDI no se contabiliza dos veces. Si es otra compra del mismo proveedor o del mismo servicio, el folio fiscal es distinto y no aparecerá este aviso.'
      : 'Este documento ya tiene un registro con la misma información. Mira el que existe antes de guardar otro: si es el mismo, ábrelo y no lo dupliques.'
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

  if (fuerte) {
    abrirModal({
      sobreTitulo: origen,
      titulo: 'Ese pago ya está registrado',
      cuerpo: cuerpo,
      acciones: acciones,
      textoConfirmar: 'Entendido',
      alConfirmar: function () { /* sólo cierra: aquí no se registra nada */ },
    });
    return;
  }

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
          coincidenciasNodo.getAttribute('data-origen') || 'Registro inteligente',
          coincidenciasNodo.getAttribute('data-duplicado-fuerte') === '1'
        );
      }
    } catch (error) {
      // Con el JSON ilegible no se abre nada: el formulario sigue siendo usable.
    }
  }
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
    sobreTitulo: boton.getAttribute('data-ampliar-sobre') || 'Imagen del producto',
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
 * Acción pendiente al cerrar el modal (diálogos con valor: pedirTexto).
 * Se invoca SIEMPRE que el modal se cierra, de la forma que sea, y sólo una
 * vez, para resolver la promesa pendiente aunque el usuario pulse Escape.
 */
let accionAlCerrar = null;

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
 * @param {boolean}  [opciones.amplio]        Panel más ancho (vistas previas).
 * @param {Function} [opciones.alCerrar] Se invoca UNA vez al cerrar, sea como sea
 *                                       (confirmar, cancelar, Escape o clic fuera).
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
  const amplio = opciones.amplio === true;

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
  accionAlCerrar = typeof opciones.alCerrar === 'function' ? opciones.alCerrar : null;

  if (nodoPie) {
    vaciarNodo(nodoPie);

    const botonCancelar = document.createElement('button');
    botonCancelar.type = 'button';
    botonCancelar.className = 'btn btn--secundario';
    botonCancelar.textContent = accionConfirmada ? 'Cancelar' : 'Cerrar';
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
  modal.classList.toggle('modal--amplio', amplio);
  modal.hidden = false;
  document.body.style.overflow = 'hidden';

  // Coloca el foco en el primer control interactivo del diálogo (los inputs
  // ocultos no reciben foco: se ignoran para que el foco no quede perdido).
  const primerControl = modal.querySelector('button, [href], input:not([type="hidden"]), select, textarea');
  if (primerControl) {
    primerControl.focus();
  }
}

/** Cierra el modal, devuelve el foco al elemento que lo abrió y avisa a alCerrar. */
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

  // Se toma antes de limpiar: la acción puede abrir otro diálogo.
  const alCerrar = accionAlCerrar;
  accionAlCerrar = null;
  if (typeof alCerrar === 'function') {
    alCerrar();
  }
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
  // Si el mensaje ya avisa de ello (formularios con data-confirm), no se repite.
  if (!/no se puede deshacer/i.test(opciones.mensaje || '')) {
    cuerpo.appendChild(nota);
  }

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
 * Diálogo para pedir un texto al usuario (sustituye a window.prompt).
 *
 * Devuelve una promesa con el texto escrito o null si cancela (botón, clic
 * fuera o tecla Escape). El campo se autocompleta con $predeterminado.
 *
 * @param {Object}  opciones
 * @param {string}  opciones.titulo
 * @param {string}  [opciones.mensaje]    Explicación breve sobre el campo.
 * @param {string}  [opciones.etiqueta]   Rótulo del campo.
 * @param {string}  [opciones.predeterminado] Valor inicial del campo.
 * @param {string}  [opciones.placeholder] Ejemplo mostrado dentro del campo.
 * @param {number}  [opciones.maximo]     Longitud máxima (carácteres).
 * @param {string}  [opciones.textoConfirmar] Texto del botón principal.
 * @returns {Promise<string|null>}
 */
function pedirTexto(opciones) {
  const opcionesTexto = opciones || {};

  return new Promise(function (resolver) {
    let resuelta = false;
    const responder = function (valor) {
      if (resuelta) {
        return;
      }
      resuelta = true;
      resolver(valor);
    };

    const campo = document.createElement('input');
    campo.type = 'text';
    campo.className = 'control';
    campo.value = opcionesTexto.predeterminado || '';
    if (opcionesTexto.placeholder) {
      campo.placeholder = opcionesTexto.placeholder;
    }
    if (opcionesTexto.maximo) {
      campo.maxLength = opcionesTexto.maximo;
    }

    const etiqueta = document.createElement('label');
    etiqueta.className = 'campo';
    const rotulo = document.createElement('span');
    rotulo.className = 'campo__etiqueta';
    rotulo.textContent = opcionesTexto.etiqueta || 'Nombre';
    etiqueta.appendChild(rotulo);
    etiqueta.appendChild(campo);

    const cuerpo = document.createElement('div');
    cuerpo.className = 'columna';
    if (opcionesTexto.mensaje) {
      const parrafo = document.createElement('p');
      parrafo.className = 'texto-base texto-secundario';
      parrafo.textContent = opcionesTexto.mensaje;
      cuerpo.appendChild(parrafo);
    }
    cuerpo.appendChild(etiqueta);

    // Intro en el campo = aceptar (el campo no está dentro de un <form>).
    campo.addEventListener('keydown', function (evento) {
      if (evento.key !== 'Enter') {
        return;
      }
      evento.preventDefault();
      responder(campo.value);   // antes de cerrar: si no, alCerrar resolvería null
      cerrarModal();
    });

    abrirModal({
      sobreTitulo: opcionesTexto.sobreTitulo || '',
      titulo: opcionesTexto.titulo || 'Escribe un valor',
      cuerpo: cuerpo,
      textoConfirmar: opcionesTexto.textoConfirmar || 'Aceptar',
      alConfirmar: function () {
        const valor = campo.value;
        // cerrarModal() dispara alCerrar, que responde(null); al llegar aquí
        // la promesa ya está resuelta y este valor no la pisa (resuelta=true).
        responder(valor);
      },
      alCerrar: function () {
        responder(null);
      },
    });

    campo.focus();
    campo.select();
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
    // La acción ANTES de cerrar: así los diálogos con valor (pedirTexto)
    // resuelven con lo escrito y no con el null del cierre.
    const accion = accionConfirmada;
    accionConfirmada = null;
    if (typeof accion === 'function') {
      accion();
    }
    cerrarModal();
  }
}

/**
 * modulos/operacionComercial.js
 * ---------------------------------------------------------------------------
 * Ventanas modales del hub «Operación Comercial» (seguimiento de UNA venta).
 *
 * Cada enlace [data-modal-form] abre en el modal de la aplicación el
 * <template id="…"> que guarda el formulario REAL (mismo action, campos y
 * token que la página suelta) y lo envía desde el pie del diálogo. Sin JavaScript
 * el enlace sigue siendo un enlace: navega a la página suelta, que existe como
 * respaldo y para accesibilidad.
 *
 * También liga el comportamiento de la incidencia de entrega (marcar «requiere
 * cambio» al elegir cambio/sustitución y forzar la corrección de factura en
 * faltante/incompleto), tanto en la página suelta como en el clon del modal.
 *
 * Reglas del proyecto: createElement/nodos del DOM, sin innerHTML, ni alert,
 * ni confirm, ni prompt.
 */

/**
 * Abre el modal con el formulario del <template> indicado y, al confirmar,
 * envía el formulario real que quedó dentro del diálogo.
 *
 * @param {HTMLElement} enlace <a data-modal-form="id-del-template">
 */
function abrirModalFormulario(enlace) {
  if (typeof abrirModal !== 'function') {
    return; // sin modal disponible: se navega a la página suelta (href normal)
  }

  const plantilla = document.getElementById(enlace.getAttribute('data-modal-form'));
  if (!plantilla || !plantilla.content) {
    return;
  }

  abrirModal({
    sobreTitulo: enlace.getAttribute('data-modal-sobre-titulo') || '',
    titulo: enlace.getAttribute('data-modal-titulo') || 'Registrar',
    cuerpo: plantilla.content.cloneNode(true),
    textoConfirmar: enlace.getAttribute('data-modal-confirmar') || 'Guardar',
    alConfirmar: function () {
      const formulario = document.querySelector('#modal-cuerpo form');
      if (formulario) {
        formulario.submit(); // envío nativo: el servidor vuelve a validar todo
      }
    },
  });

  // El clon ya vive en el modal: se le liga su comportamiento (incidencias).
  const formulario = document.querySelector('#modal-cuerpo form[data-incidencia]');
  if (formulario) {
    ligarIncidencia(formulario);
  }
}

/**
 * Liga el motivo de una incidencia a un formulario concreto (página suelta o
 * formulario clonado dentro del modal):
 *   · «Cambio o sustitución» marca como «requiere cambio» los productos con piezas;
 *   · «Producto faltante» / «Producto incompleto» fuerzan la casilla de corregir
 *     factura (cuando la venta está facturada el servidor también lo fuerza);
 *   · al salir de esos motivos, la casilla automática se desmarca (una marcada
 *     a mano se conserva).
 *
 * @param {HTMLFormElement} formulario
 */
function ligarIncidencia(formulario) {
  const motivo = formulario.querySelector('select[name="motivo"]');
  if (!motivo || motivo.getAttribute('data-ligado') === '1') {
    return;
  }
  motivo.setAttribute('data-ligado', '1');

  const correccion = (formulario.getAttribute('data-forzar-factura') || '')
    .split(',')
    .filter(Boolean);
  const factura = formulario.querySelector('input[name="factura_requerida"]');

  motivo.addEventListener('change', function () {
    // Marca/desmarca «requiere cambio» según el motivo (comportamiento histórico).
    const esCambio = motivo.value === 'cambio_sustitucion';
    formulario.querySelectorAll('input[type="checkbox"][name^="cambio["]').forEach(function (casilla) {
      const fila = casilla.closest('tr');
      const cantidad = fila ? fila.querySelector('input[type="number"]') : null;
      if (esCambio && cantidad && Number(cantidad.value) > 0) {
        casilla.checked = true;
      } else if (!esCambio) {
        casilla.checked = false;
      }
    });

    // Corrección de factura: obligatoria en faltante/incompleto, opcional en el resto.
    if (!factura) {
      return;
    }
    if (correccion.indexOf(motivo.value) !== -1) {
      factura.checked = true;
      factura.setAttribute('data-automatico', '1');
    } else if (factura.getAttribute('data-automatico') === '1') {
      factura.checked = false;
      factura.removeAttribute('data-automatico');
    }
  });
}

/** Prepara todos los botones/modal de la página (se llama desde inicializarAplicacion). */
function prepararOperacionComercial() {
  document.querySelectorAll('a[data-modal-form]').forEach(function (enlace) {
    enlace.addEventListener('click', function (evento) {
      const plantilla = document.getElementById(enlace.getAttribute('data-modal-form'));
      // Con modal disponible se abre el diálogo; sin él se navega como siempre.
      if (plantilla && typeof abrirModal === 'function') {
        evento.preventDefault();
        abrirModalFormulario(enlace);
      }
    });
  });

  // La página suelta de incidencias (sin modal) comparte el mismo comportamiento.
  document.querySelectorAll('form[data-incidencia]').forEach(ligarIncidencia);
}

/**
 * ordenCompra.js — formulario de compra.
 *
 * Hay un bloque de partidas por tipo de compra (semiterminado o insumo); solo el del tipo elegido se envía
 * (el otro queda oculto y con sus campos deshabilitados). Agrega y quita renglones (clonando el primero, sin
 * innerHTML) y recalcula el importe de cada uno, el subtotal y el total con el IVA capturado. El servidor vuelve a
 * calcular todo: esto es solo una ayuda visual.
 *
 * Semiterminados: al elegir al proveedor cada renglón ofrece SU catálogo activo (JSON embebido en la página); al elegir
 * un producto se rellenan nombre, código y costo (el costo queda bloqueado hasta «Cambiar costo») y, si el producto del
 * proveedor está relacionado con un único producto propio, se propone ese producto propio.
 */
function iniciarOrdenCompra() {
  const bloques = document.querySelectorAll('[data-bloque-tipo]');
  if (!bloques.length) {
    return;
  }

  const formatoMoneda = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
  const totalCompra = document.querySelector('[data-total-compra]');
  const campoIva = document.getElementById('iva');
  const selectorProveedor = document.getElementById('proveedor_id');

  let catalogos = {};
  const datos = document.getElementById('catalogo-proveedores');
  if (datos) {
    try {
      catalogos = JSON.parse(datos.textContent) || {};
    } catch (error) {
      catalogos = {};
    }
  }
  const itemsDe = function (proveedorId) {
    return Array.isArray(catalogos[proveedorId]) ? catalogos[proveedorId] : [];
  };
  const buscarItem = function (proveedorId, itemId) {
    return itemsDe(proveedorId).find(function (item) {
      return String(item.id) === String(itemId);
    });
  };

  const campoTasa = document.getElementById('iva_tasa');
  const baseGravable = document.querySelector('[data-base-gravable]');
  // El IVA es automático mientras esté vacío o no se haya escrito a mano; «Recalcular IVA» vuelve al cálculo.
  let ivaManual = campoIva ? campoIva.value !== '' : false;

  /** Base gravable (centavos) de las partidas «Con IVA» del bloque visible. */
  const gravableActual = function () {
    let base = 0;
    bloques.forEach(function (bloque) {
      if (!bloque.hidden) {
        base += Number(bloque.dataset.gravable || 0);
      }
    });
    return base;
  };

  /** IVA = base gravable × tasa, redondeado al centavo (mitad hacia arriba), como en el servidor. */
  const calcularIvaCentavos = function () {
    const tasa = Number.parseFloat(campoTasa ? campoTasa.value : '');
    return Number.isFinite(tasa) ? Math.round(gravableActual() * tasa / 100) : null;
  };

  const actualizarIvaAutomatico = function () {
    if (baseGravable) {
      baseGravable.textContent = formatoMoneda.format(gravableActual() / 100);
    }
    if (campoIva && !ivaManual) {
      const iva = calcularIvaCentavos();
      campoIva.value = iva === null ? '' : (iva / 100).toFixed(2);
    }
  };

  /** Subtotal (centavos) del bloque visible + IVA capturado = total de la compra. */
  const actualizarTotalCompra = function () {
    if (!totalCompra) {
      return;
    }
    let subtotal = 0;
    bloques.forEach(function (bloque) {
      if (!bloque.hidden) {
        subtotal += Number(bloque.dataset.subtotal || 0);
      }
    });
    const iva = Math.round((Number.parseFloat(campoIva ? campoIva.value : '') || 0) * 100);
    totalCompra.textContent = formatoMoneda.format((subtotal + iva) / 100);
  };

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
    actualizarTotalCompra();
  };

  /** Rellena (o libera) los campos de un renglón según el producto del catálogo elegido. */
  const aplicarItem = function (fila, reiniciar) {
    const selector = fila.querySelector('[data-catalogo]');
    if (!selector) {
      return;
    }
    const descripcion = fila.querySelector('[data-descripcion]');
    const costo = fila.querySelector('[data-costo]');
    const codigo = fila.querySelector('[data-codigo]');
    const boton = fila.querySelector('[data-cambiar-costo]');
    const aviso = fila.querySelector('[data-aviso-costo]');
    const propio = fila.querySelector('select[name="producto_id[]"]');
    const item = selector.value !== '0' && selector.value !== ''
      ? buscarItem(selectorProveedor ? selectorProveedor.value : '', selector.value) : null;

    if (item) {
      descripcion.value = item.nombre;
      descripcion.readOnly = true;
      costo.value = item.costo;
      costo.readOnly = true;
      codigo.textContent = item.codigo ? 'Código ' + item.codigo : '';
      aviso.textContent = '';
      boton.hidden = false;
      if (propio && Array.isArray(item.propios) && item.propios.length === 1) {
        propio.value = String(item.propios[0]);
      }
    } else {
      descripcion.readOnly = false;
      costo.readOnly = false;
      codigo.textContent = '';
      aviso.textContent = '';
      boton.hidden = true;
      if (reiniciar) {
        descripcion.value = '';
        costo.value = '0.00';
      }
    }
  };

  /** Rearma las opciones del catálogo de un renglón con las del proveedor elegido. */
  const normalizar = function (texto) {
    return String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  };

  const llenarCatalogo = function (fila, proveedorId) {
    const selector = fila.querySelector('[data-catalogo]');
    if (!selector) {
      return;
    }
    const previo = selector.value;
    const buscar = fila.querySelector('[data-buscar-catalogo]');
    const texto = normalizar(buscar ? buscar.value : '');
    while (selector.options.length > 1) {
      selector.remove(1);
    }
    itemsDe(proveedorId).forEach(function (item) {
      if (!item.activo && String(item.id) !== previo) {
        return;
      }
      // La búsqueda filtra por nombre o código; lo ya elegido siempre se conserva.
      if (texto !== '' && String(item.id) !== previo && normalizar(item.codigo + ' ' + item.nombre).indexOf(texto) === -1) {
        return;
      }
      const opcion = document.createElement('option');
      opcion.value = String(item.id);
      opcion.textContent = (item.codigo ? item.codigo + ' · ' : '') + item.nombre + (item.activo ? '' : ' (inactivo)');
      selector.appendChild(opcion);
    });
    selector.value = Array.from(selector.options).some(function (o) { return o.value === previo; }) ? previo : '0';
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
      bloque.dataset.subtotal = String(centavos);
      let gravable = 0;
      cuerpo.querySelectorAll('[data-partida]').forEach(function (fila) {
        const aplica = fila.querySelector('[data-aplica-iva]');
        if (aplica && aplica.value === '1') {
          const milesimas = Math.round((Number.parseFloat(fila.querySelector('[data-cantidad]').value) || 0) * 1000);
          const costo = Math.round((Number.parseFloat(fila.querySelector('[data-costo]').value) || 0) * 100);
          gravable += Math.round((milesimas * costo) / 1000);
        }
      });
      bloque.dataset.gravable = String(gravable);
      actualizarIvaAutomatico();
      actualizarTotalCompra();
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
        campo.readOnly = false;
        campo.value = campo.hasAttribute('data-cantidad') ? '1' : (campo.hasAttribute('data-costo') ? '0.00' : '');
      });
      nueva.querySelectorAll('select').forEach(function (campo) {
        campo.value = campo.options.length ? campo.options[0].value : '';
      });
      nueva.querySelectorAll('[data-codigo], [data-aviso-costo]').forEach(function (nodo) {
        nodo.textContent = '';
      });
      cuerpo.appendChild(nueva);
      if (selectorProveedor) {
        llenarCatalogo(nueva, selectorProveedor.value);
      }
      aplicarItem(nueva, false);
      mostrarMaterial(nueva);
      recalcular();
    });

    cuerpo.addEventListener('click', function (evento) {
      const cambiar = evento.target.closest('[data-cambiar-costo]');
      if (cambiar) {
        evento.preventDefault();
        const fila = cambiar.closest('[data-partida]');
        const costo = fila.querySelector('[data-costo]');
        costo.readOnly = false;
        costo.focus();
        return;
      }
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

    cuerpo.addEventListener('input', function (evento) {
      if (evento.target.hasAttribute('data-buscar-catalogo')) {
        llenarCatalogo(evento.target.closest('[data-partida]'), selectorProveedor ? selectorProveedor.value : '');
        return;
      }
      // Si el costo se edita y ya no coincide con el del catálogo, se avisa cuál era.
      if (evento.target.hasAttribute('data-costo')) {
        const fila = evento.target.closest('[data-partida]');
        const selector = fila.querySelector('[data-catalogo]');
        const aviso = fila.querySelector('[data-aviso-costo]');
        const item = selector && selector.value !== '0' ? buscarItem(selectorProveedor ? selectorProveedor.value : '', selector.value) : null;
        if (item && aviso) {
          aviso.textContent = Number.parseFloat(evento.target.value) !== Number.parseFloat(item.costo) ? 'Catálogo: ' + Number.parseFloat(item.costo).toFixed(2) : '';
        }
      }
      recalcular();
    });

    cuerpo.addEventListener('change', function (evento) {
      const fila = evento.target.closest('[data-partida]');
      if (!fila) {
        return;
      }
      if (evento.target.hasAttribute('data-controla')) {
        mostrarMaterial(fila);
        return;
      }
      if (evento.target.hasAttribute('data-catalogo')) {
        aplicarItem(fila, true);
        recalcular();
      }
    });

    cuerpo.querySelectorAll('[data-partida]').forEach(mostrarMaterial);
    recalcular();
  };

  bloques.forEach(iniciarBloque);
  document.querySelectorAll('[data-tipo-compra]').forEach(function (radio) {
    radio.addEventListener('change', aplicarTipo);
  });

  // Al cambiar de proveedor, cada renglón ofrece el catálogo del nuevo; lo ya elegido que no sea suyo se libera.
  if (selectorProveedor) {
    selectorProveedor.addEventListener('change', function () {
      document.querySelectorAll('[data-bloque-tipo="semiterminado"] [data-partida]').forEach(function (fila) {
        const eraDelCatalogo = (fila.querySelector('[data-catalogo]') || {}).value !== '0';
        llenarCatalogo(fila, selectorProveedor.value);
        const sigue = (fila.querySelector('[data-catalogo]') || {}).value !== '0';
        aplicarItem(fila, eraDelCatalogo && !sigue);
      });
      bloques.forEach(function (bloque) {
        const cuerpo = bloque.querySelector('[data-partidas-cuerpo]');
        if (cuerpo) {
          cuerpo.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
    });
  }

  const calcularIva = document.querySelector('[data-calcular-iva]');
  if (calcularIva && campoIva) {
    calcularIva.addEventListener('click', function (evento) {
      evento.preventDefault();
      ivaManual = false;
      actualizarIvaAutomatico();
      actualizarTotalCompra();
    });
  }
  if (campoIva) {
    campoIva.addEventListener('input', function () {
      ivaManual = campoIva.value !== '';
      actualizarTotalCompra();
    });
  }
  if (campoTasa) {
    campoTasa.addEventListener('input', function () {
      actualizarIvaAutomatico();
      actualizarTotalCompra();
    });
  }
  document.querySelectorAll('[data-partidas-cuerpo]').forEach(function (cuerpo) {
    cuerpo.addEventListener('change', function (evento) {
      if (evento.target.hasAttribute('data-aplica-iva')) {
        cuerpo.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  });

  aplicarTipo();
}

document.addEventListener('DOMContentLoaded', iniciarOrdenCompra);

/**
 * modulos/selectorBusqueda.js
 * ---------------------------------------------------------------------------
 * Convierte un <select data-buscador> en un campo de búsqueda por texto con
 * lista desplegable filtrada (combobox), pensado para listas largas como la
 * de clientes del cotizador.
 *
 * Cómo funciona:
 *  - El <select> permanece en el DOM (oculto) como fuente de la verdad: el
 *    formulario sigue enviando su name, el servidor valida igual y los demás
 *    módulos no cambian (cotizador.js escucha el 'change' del cliente).
 *  - Al elegir una opción se escribe su valor en el <select> y se dispara
 *    'change', de modo que el descuento y los precios del cliente se
 *    actualizan como siempre.
 *  - Sin JavaScript el <select> se muestra y funciona como siempre: la mejora
 *    sólo se construye cuando hay JS.
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, ni alert, ni
 * confirm, ni prompt.
 */

/** Máximo de opciones pintadas en la lista desplegable (afina con el teclado). */
const BUSCADOR_LIMITE_OPCIONES = 60;

/** Pinta en el input el texto de la opción elegida (o nada si no hay). */
function sincronizarEntradaBusqueda(select, entrada) {
  const opcion = select.options[select.selectedIndex];
  const texto = opcion && opcion.value !== '' ? opcion.text : '';
  entrada.value = texto;
}

/** Quita acentos y minúsculas para que «Jose» encuentre a «José». */
function normalizarBusqueda(texto) {
  return (texto || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Lee las opciones reales del <select> junto a su texto de búsqueda. */
function leerOpcionesBusqueda(select) {
  const opciones = [];

  Array.prototype.forEach.call(select.options, function (opcion) {
    if (opcion.value === '') {
      return; // el marcador «Selecciona…» no se busca
    }

    const rfc = opcion.getAttribute('data-rfc') || '';
    const alias = opcion.getAttribute('data-alias') || '';

    opciones.push({
      valor: opcion.value,
      texto: opcion.text,
      rfc: rfc,
      buscar: normalizarBusqueda(opcion.text + ' ' + alias + ' ' + rfc),
    });
  });

  return opciones;
}

/** Muestra el error de «escribió pero no eligió» bajo el campo, con el foco. */
function marcarErrorBusqueda(entrada, mensaje) {
  const campo = entrada.closest('.campo');
  if (!campo) {
    return;
  }

  campo.classList.add('campo--con-error');

  const previo = campo.querySelector('.campo__error');
  if (previo && previo.parentNode) {
    previo.parentNode.removeChild(previo);
  }

  const error = document.createElement('p');
  error.className = 'campo__error';
  error.textContent = mensaje;
  campo.appendChild(error);

  entrada.focus();
}

/**
 * Construye el buscador de un <select> concreto.
 *
 * @param {HTMLSelectElement} select
 */
function crearSelectorBusqueda(select) {
  if (select.getAttribute('data-buscador-listo') === '1') {
    return; // idempotente: no se construye dos veces el mismo combobox
  }
  select.setAttribute('data-buscador-listo', '1');

  const opciones = leerOpcionesBusqueda(select);
  if (opciones.length === 0) {
    return; // sin opciones no hay nada que buscar
  }

  /* De qué se busca («cliente», «proveedor»…): lo dicen los avisos, para que
   * el mensaje no le hable a un cotizador cuando se busca un proveedor. */
  const entidad = select.getAttribute('data-buscador-entidad') || 'cliente';

  const base = select.id || 'selector';
  const idEntrada = base + '-busqueda';
  const idLista = base + '-lista';

  /* Envoltorio que da posición a la lista desplegable. */
  const envoltorio = document.createElement('div');
  envoltorio.className = 'selector-busqueda';

  const entrada = document.createElement('input');
  entrada.type = 'search';
  entrada.className = 'control';
  entrada.id = idEntrada;
  entrada.autocomplete = 'off';
  entrada.spellcheck = false;
  entrada.placeholder =
    select.getAttribute('data-buscador-placeholder') || 'Escribe para buscar…';
  entrada.setAttribute('role', 'combobox');
  entrada.setAttribute('aria-autocomplete', 'list');
  entrada.setAttribute('aria-expanded', 'false');
  entrada.setAttribute('aria-controls', idLista);
  if (select.required) {
    entrada.required = true;
  }

  const lista = document.createElement('ul');
  lista.className = 'selector-busqueda__lista';
  lista.id = idLista;
  lista.setAttribute('role', 'listbox');
  lista.setAttribute('aria-label', 'Opciones');
  lista.hidden = true;

  envoltorio.appendChild(entrada);
  envoltorio.appendChild(lista);

  /* El buscador va ANTES del <select> (que se oculta pero se queda): el orden
   * del DOM importa para la validación, que así señala primero el campo que la
   * persona ve y puede corregir. */
  select.parentNode.insertBefore(envoltorio, select);
  select.hidden = true;

  /* La etiqueta apunta al control visible (sin JS vuelve a apuntar al select,
   * que es el que se ve en ese caso). */
  const etiqueta = document.querySelector('label[for="' + select.id + '"]');
  if (etiqueta) {
    etiqueta.setAttribute('for', idEntrada);
  }

  sincronizarEntradaBusqueda(select, entrada);

  /** Índice de la opción resaltada con el teclado (-1 = ninguna). */
  let activa = -1;

  /** Opciones que se pintaron en la lista en la última filtración. */
  let visibles = [];

  /** Elige una opción: escribe el <select> y avisa del cambio. */
  const elegir = function (opcion) {
    select.value = opcion.valor;
    sincronizarEntradaBusqueda(select, entrada);
    cerrarLista();
    // Se retira el aviso de «texto sin coincidencia» si lo había: ya hay ficha.
    const campo = entrada.closest('.campo');
    if (campo) {
      campo.classList.remove('campo--con-error');
      const error = campo.querySelector('.campo__error');
      if (error && error.parentNode) {
        error.parentNode.removeChild(error);
      }
    }
    // El 'change' es el que ya escuchan los demás módulos (cotizador: descuento
    // y precios del cliente).
    select.dispatchEvent(new Event('change', { bubbles: true }));
  };

  const cerrarLista = function () {
    lista.hidden = true;
    entrada.setAttribute('aria-expanded', 'false');
    entrada.removeAttribute('aria-activedescendant');
    activa = -1;
  };

  /** Pinta la lista con las opciones que coinciden con el texto del input. */
  const pintarLista = function () {
    const termino = normalizarBusqueda(entrada.value);

    while (lista.firstChild) {
      lista.removeChild(lista.firstChild);
    }

    visibles = opciones.filter(function (opcion) {
      return termino === '' || opcion.buscar.indexOf(termino) !== -1;
    });

    const pintadas = Math.min(visibles.length, BUSCADOR_LIMITE_OPCIONES);

    for (let i = 0; i < pintadas; i += 1) {
      const opcion = visibles[i];
      const elegida = select.value === opcion.valor;

      const item = document.createElement('li');
      item.className = 'selector-busqueda__opcion' + (elegida ? ' selector-busqueda__opcion--elegida' : '');
      item.id = idLista + '-opcion-' + i;
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', elegida ? 'true' : 'false');

      const principal = document.createElement('span');
      principal.className = 'selector-busqueda__nombre';
      principal.textContent = opcion.texto;
      item.appendChild(principal);

      if (opcion.rfc !== '') {
        const rfc = document.createElement('span');
        rfc.className = 'selector-busqueda__rfc';
        rfc.textContent = opcion.rfc;
        item.appendChild(rfc);
      }

      item.addEventListener('pointerdown', function (evento) {
        // Sin esto el foco salta del input a la lista y se cerraría antes de
        // poder elegir: el clic se resuelve aquí, con el foco en su sitio.
        evento.preventDefault();
        elegir(opcion);
      });

      lista.appendChild(item);
    }

    if (pintadas === 0) {
      const vacia = document.createElement('li');
      vacia.className = 'selector-busqueda__opcion selector-busqueda__opcion--vacia';
      vacia.setAttribute('role', 'option');
      vacia.setAttribute('aria-disabled', 'true');
      vacia.textContent = 'Sin coincidencias' + (entrada.value.trim() !== '' ? ' para «' + entrada.value.trim() + '»' : '');
      lista.appendChild(vacia);
    } else if (visibles.length > pintadas) {
      const resto = document.createElement('li');
      resto.className = 'selector-busqueda__pie';
      resto.textContent = (visibles.length - pintadas) + ' más: sigue escribiendo para afinar';
      lista.appendChild(resto);
    }

    const conteo = document.createElement('li');
    conteo.className = 'selector-busqueda__pie';
    conteo.textContent = pintadas + ' de ' + opciones.length + ' opciones';
    lista.appendChild(conteo);

    activa = -1;

    /* Aviso para otros módulos (gastoProveedor.js): cuántas opciones coinciden
     * con lo escrito y qué se escribió, para que puedan ofrecer el alta de una
     * ficha que todavía no existe en el directorio. */
    entrada.dispatchEvent(new CustomEvent('buscador:resultado', {
      bubbles: true,
      detail: { total: visibles.length, texto: entrada.value },
    }));
  };

  const abrirLista = function () {
    pintarLista();
    lista.hidden = false;
    entrada.setAttribute('aria-expanded', 'true');
  };

  /** Marca la opción activa del teclado (y la deja a la vista). */
  const resaltar = function (indice) {
    const items = lista.querySelectorAll('.selector-busqueda__opcion:not(.selector-busqueda__opcion--vacia)');
    if (items.length === 0) {
      return;
    }

    // La resaltada es sólo visual: lo elegido lo marca aria-selected y lo
    // activo por teclado, aria-activedescendant.
    items.forEach(function (item) {
      item.classList.remove('selector-busqueda__opcion--activa');
    });

    activa = (indice + items.length) % items.length;
    const item = items[activa];
    if (item) {
      item.classList.add('selector-busqueda__opcion--activa');
      entrada.setAttribute('aria-activedescendant', item.id);
      item.scrollIntoView({ block: 'nearest' });
    }
  };

  entrada.addEventListener('focus', abrirLista);
  entrada.addEventListener('click', abrirLista);

  entrada.addEventListener('input', function () {
    // Si lo escrito deja de ser la etiqueta de la ficha elegida, la elección
    // queda en suspenso: el <select> vuelve a vacío (y se avisa con 'change')
    // hasta que se elige otra opción de la lista.
    if (select.value !== '') {
      const opcion = select.options[select.selectedIndex];
      if (normalizarBusqueda(entrada.value) !== normalizarBusqueda(opcion ? opcion.text : '')) {
        select.value = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    abrirLista();
  });

  entrada.addEventListener('keydown', function (evento) {
    if (evento.key === 'ArrowDown') {
      evento.preventDefault();
      if (lista.hidden) {
        abrirLista();
      }
      resaltar(activa + 1);
      return;
    }

    if (evento.key === 'ArrowUp') {
      evento.preventDefault();
      if (lista.hidden) {
        abrirLista();
      }
      resaltar(activa - 1);
      return;
    }

    if (evento.key === 'Enter') {
      // Con la lista abierta, Intro elige la opción resaltada (o la primera
      // coincidencia si no se movió con el teclado); sin coincidencias, avisa
      // en el momento que ese texto no corresponde a ninguna ficha; cerrada,
      // Intro hace lo de siempre (enviar el formulario).
      if (!lista.hidden) {
        evento.preventDefault();
        const items = lista.querySelectorAll('.selector-busqueda__opcion:not(.selector-busqueda__opcion--vacia)');
        if (items.length > 0) {
          const indice = activa >= 0 && activa < items.length ? activa : 0;
          elegir(visibles[indice]);
        } else {
          marcarErrorBusqueda(
            entrada,
            'Ninguna ficha coincide con «' + entrada.value.trim() + '»: corrige el texto o elige un ' + entidad + ' de la lista.'
          );
        }
      }
      return;
    }

    if (evento.key === 'Escape' && !lista.hidden) {
      evento.preventDefault();
      cerrarLista();
    }
  });

  entrada.addEventListener('blur', function () {
    // Al salir del campo se cierra la lista y el texto vuelve a reflejar lo
    // elegido de verdad en el <select> (nada si no hay elección).
    cerrarLista();
    sincronizarEntradaBusqueda(select, entrada);
  });

  /* Envío con texto suelto: si la persona escribió algo que no corresponde a
   * una ficha elegida, se le avisa aquí (con foco incluido) y no se envía.
   * Corre ANTES de la validación general de app.js y por eso detiene también
   * su paso; si el input está vacío, esa validación señala el campo igual. */
  const formulario = select.closest('form');
  if (formulario) {
    formulario.addEventListener('submit', function (evento) {
      if (evento.defaultPrevented) {
        return;
      }
      if (select.value !== '' || entrada.value.trim() === '') {
        return;
      }

      evento.preventDefault();
      evento.stopPropagation();
      marcarErrorBusqueda(
        entrada,
        'Elige un ' + entidad + ' de la lista: lo que escribiste no coincide con ninguna ficha.'
      );
    });
  }
}

/**
 * Prepara todos los <select data-buscador> de la página.
 * Se llama desde inicializarAplicacion() (src/js/app.js).
 */
function prepararSelectoresBusqueda() {
  document.querySelectorAll('select[data-buscador]').forEach(crearSelectorBusqueda);
}

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
  const obligatorio = typeof campoObligatorioAhora === 'function'
    ? campoObligatorioAhora(control)
    : control.hasAttribute('required');

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
