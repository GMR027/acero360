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
 *   · VER: la Agenda es una tabla con una fila por marcador del negocio o
 *     recordatorio. El botón «Ver» de un recordatorio abre su ficha completa;
 *     el de un marcador del negocio abre el detalle del día. Los datos de cada
 *     día viajan UNA vez en los nodos [data-agenda-datos] y cada fila los
 *     referencia con data-agenda-ref.
 *
 * La Agenda y el calendario se sincronizan solos: al cargar con un día
 * elegido, la página baja hasta sus filas de la Agenda.
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
  const datos = calendarioDatosDia(tarjeta);
  if (!datos) {
    return;
  }

  calendarioVerDiaDatos(datos);
}

/**
 * Datos del día al que pertenece una fila de la Agenda. Se guardan una sola
 * vez en [data-agenda-datos] y aquí se resuelven por la fecha de la fila.
 */
function calendarioDatosDia(nodo) {
  const fila = nodo instanceof Element ? nodo.closest('[data-agenda-ref]') : null;
  if (!fila) {
    return null;
  }

  const fecha = fila.getAttribute('data-agenda-ref');
  const fuente = document.getElementById('agenda-datos-' + fecha);
  if (!fecha || !fuente) {
    return null;
  }

  try {
    return JSON.parse(fuente.getAttribute('data-agenda-dia'));
  } catch (error) {
    return null;
  }
}

/** Baja hasta las filas de la Agenda del día elegido y las resalta. */
function calendarioIrAlDia() {
  const casilla = document.querySelector('a.calendario__dia[aria-current="date"][data-fecha]');
  if (!casilla) {
    return;
  }

  const fecha = casilla.getAttribute('data-fecha');
  const filas = document.querySelectorAll('[data-agenda-ref="' + fecha + '"]');
  if (!filas.length) {
    return;
  }

  filas.forEach(function (fila) {
    fila.classList.add('agenda-fila--rastreado');
  });

  const primera = filas[0];
  const caja = primera.getBoundingClientRect();
  const esVisible = caja.top >= 0 && caja.bottom <= window.innerHeight;

  // Sólo se baja si la fila no se ve completa: así un clic no mueve la
  // pantalla debajo de los pies del usuario.
  if (!esVisible) {
    primera.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function iniciarCalendario() {
  document.addEventListener('click', function (evento) {
    const objetivo = evento.target;
    if (!(objetivo instanceof Element)) {
      return;
    }

    // «Ver» de un recordatorio: abre la ficha completa del evento.
    const botonEvento = objetivo.closest('[data-ver-evento]');
    if (botonEvento) {
      const datos = calendarioDatosDia(botonEvento);
      const id = botonEvento.getAttribute('data-ver-evento');
      const evento = datos && Array.isArray(datos.eventos)
        ? datos.eventos.find(function (item) { return String(item.id) === String(id); })
        : null;
      if (datos && evento) {
        calendarioVerEvento(datos, evento);
      }
      return;
    }

    // «Ver» de un marcador del negocio: abre el detalle del día.
    const botonVer = objetivo.closest('[data-ver-dia]');
    if (botonVer) {
      calendarioVerDia(botonVer);
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
