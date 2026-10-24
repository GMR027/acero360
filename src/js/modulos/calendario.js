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
 * Cuerpo del modal «Ver»: los marcadores del negocio de ese día con sus
 * cifras, más el aviso de los recordatorios. Se construye con createElement,
 * nunca con innerHTML.
 */
function calendarioCuerpoDetalle(datos) {
  const envoltura = document.createElement('div');
  envoltura.className = 'agenda-detalle';

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

  const pie = document.createElement('p');
  pie.className = 'texto-secundario agenda-detalle__pie';
  pie.textContent = (datos.eventos || 0) === 0
    ? 'No hay recordatorios ni eventos capturados ese día.'
    : (datos.eventos === 1 ? '1 recordatorio o evento capturado' : datos.eventos + ' recordatorios o eventos capturados') + ' ese día.';
  envoltura.appendChild(pie);

  return envoltura;
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
