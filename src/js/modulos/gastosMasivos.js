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
