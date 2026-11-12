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
 *   · VER: la Agenda es una tabla con una fila por operación del negocio o
 *     recordatorio. El botón «Ver» de una operación abre el detalle de su
 *     módulo; el de un recordatorio abre su ficha completa. Los datos del
 *     recordatorio viajan en el atributo data-evento del propio botón.
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

/** Ficha completa de un recordatorio, dentro del modal de la aplicación. */
function calendarioVerEvento(evento) {
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

  agregar('Fecha', evento.fecha);
  agregar('Negocio', evento.negocio);
  agregar('Autor', evento.autor);
  cuerpo.appendChild(lista);

  const descripcion = document.createElement('p');
  descripcion.className = 'agenda-evento-detalle__descripcion';
  descripcion.textContent = evento.descripcion || 'Sin descripción.';
  cuerpo.appendChild(descripcion);

  const editar = document.createElement('a');
  editar.className = 'btn btn--primario';
  editar.href = evento.rutaEditar || '#';
  editar.textContent = 'Editar evento';

  // El pie ya trae «Cerrar» (lo agrega abrirModal cuando no hay confirmación).
  abrirModal({
    sobreTitulo: 'Recordatorio',
    titulo: 'Detalle del evento',
    cuerpo: cuerpo,
    acciones: [editar],
  });
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
      let evento = null;
      try {
        evento = JSON.parse(botonEvento.getAttribute('data-evento') || 'null');
      } catch (error) {
        evento = null;
      }
      if (evento) {
        calendarioVerEvento(evento);
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
