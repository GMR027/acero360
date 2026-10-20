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
  '/admin/fiscal/registros': 'Anota lo que ya pagaste al SAT y retenciones para que el cálculo sea más cercano.',
  '/admin/fiscal/catalogo': 'Las tarifas y reglas del SAT que usa el cálculo.',
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
