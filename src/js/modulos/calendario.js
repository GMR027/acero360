/**
 * modulos/calendario.js
 * ---------------------------------------------------------------------------
 * «Nuevo recordatorio o evento» del Calendario en ventana modal.
 *
 * El formulario vive OCULTO en la página (contenedor [data-alta-calendario-
 * contenedor]) y aquí se MUEVE al modal, nunca se copia: así los id de los
 * campos (fecha, nombre, hora…) siguen siendo únicos y la validación en
 * cliente, que trabaja por id, no cambia. Al cerrar el modal el formulario
 * regresa a su contenedor para no perderlo.
 *
 * Se abre de dos formas:
 *   · haciendo clic una SEGUNDA vez sobre la casilla ya seleccionada (la
 *     primera la selecciona y recarga su panel, como siempre);
 *   · con el botón «Nuevo recordatorio o evento» del panel del día.
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

function iniciarCalendario() {
  document.addEventListener('click', function (evento) {
    const objetivo = evento.target;
    if (!(objetivo instanceof Element)) {
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
}

document.addEventListener('DOMContentLoaded', iniciarCalendario);
