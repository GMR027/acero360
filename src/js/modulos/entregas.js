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
  const lista = resumen.querySelector('[data-resumen-lista]');
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

    lista.replaceChildren();
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
      lista.appendChild(tarjeta);
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
