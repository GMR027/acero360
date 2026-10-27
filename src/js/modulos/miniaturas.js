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
