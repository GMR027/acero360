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
