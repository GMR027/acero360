/**
 * mapaVentas.js — mapa histórico de ventas con Leaflet + OpenStreetMap.
 *
 * La figura de Estadísticas trae los puntos ya agrupados por el servidor en
 * `data-mapa-ventas` (JSON). Cada punto es un lugar y su número son las ventas
 * registradas a 50 m o menos. Leaflet sólo se carga en esta página.
 *
 * Reglas del proyecto: sin innerHTML para datos del servidor (popup y pin se
 * arman con nodos del DOM) y el mapa se crea cuando se acerca a la pantalla.
 */
const MAPA_VENTAS_TESELAS = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const MAPA_VENTAS_ATRIBUCION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">colaboradores de OpenStreetMap</a>';

let mapaVentasVivo = null;

/** Fecha Y-m-d del servidor → dd/mm/aaaa (sin desfase de zona horaria). */
function mapaVentasFecha(valor) {
  const partes = String(valor || '').split('-');
  return partes.length === 3 ? `${partes[2]}/${partes[1]}/${partes[0]}` : '';
}

/** Pin circular con el número de ventas del lugar. */
function mapaVentasIcono(punto) {
  const numero = document.createElement('span');
  numero.className = 'mapa-ventas__numero';
  numero.textContent = new Intl.NumberFormat('es-MX').format(punto.ventas);

  const circulo = document.createElement('span');
  circulo.className = 'mapa-ventas__pin';
  circulo.appendChild(numero);

  return L.divIcon({
    className: 'mapa-ventas__pin-capa',
    html: circulo,
    iconSize: [44, 44],
    iconAnchor: [22, 22],
    popupAnchor: [0, -24],
  });
}

/** Contenido del globo: ventas, clientes y fechas del lugar. */
function mapaVentasGlobo(punto) {
  const caja = document.createElement('div');
  caja.className = 'mapa-ventas__globo';

  const titulo = document.createElement('strong');
  titulo.textContent = new Intl.NumberFormat('es-MX').format(punto.ventas)
    + (punto.ventas === 1 ? ' venta en este lugar' : ' ventas en este lugar');
  caja.appendChild(titulo);

  const clientes = Array.isArray(punto.clientes) ? punto.clientes : [];
  if (clientes.length) {
    const visibles = clientes.slice(0, 3);
    const restantes = clientes.length - visibles.length;
    const linea = document.createElement('p');
    linea.textContent = 'Clientes: ' + visibles.join(', ') + (restantes > 0 ? ` y ${restantes} más` : '');
    caja.appendChild(linea);
  }

  const primera = mapaVentasFecha(punto.primera);
  const ultima = mapaVentasFecha(punto.ultima);
  if (primera || ultima) {
    const fechas = document.createElement('p');
    fechas.className = 'mapa-ventas__globo-fechas';
    fechas.textContent = primera === ultima || !ultima
      ? `Entrega: ${primera}`
      : `Entregas: ${primera} a ${ultima}`;
    caja.appendChild(fechas);
  }

  return caja;
}

/** Sustituye el mapa por un aviso legible si la biblioteca no cargó. */
function mapaVentasAviso(figura, texto) {
  const aviso = document.createElement('p');
  aviso.className = 'nota nota--advertencia';
  aviso.textContent = texto;
  figura.replaceChildren(aviso);
}

function dibujarMapaVentas(figura) {
  if (mapaVentasVivo) {
    return;
  }

  const contenedor = figura.querySelector('#mapa-ventas');
  if (!contenedor) {
    return;
  }

  if (typeof L === 'undefined') {
    mapaVentasAviso(figura, 'No se pudo cargar la biblioteca del mapa. Recarga la página para intentarlo de nuevo.');
    return;
  }

  let datos = null;
  try {
    datos = JSON.parse(figura.getAttribute('data-mapa-ventas') || '{}');
  } catch (e) {
    datos = null;
  }

  const puntos = datos && Array.isArray(datos.puntos) ? datos.puntos : [];
  if (!puntos.length) {
    mapaVentasAviso(figura, 'Todavía no hay ventas con GPS para mostrar en el mapa.');
    return;
  }

  mapaVentasVivo = L.map(contenedor, {
    scrollWheelZoom: false, // la rueda sigue desplazando la página
    zoomControl: true,
  });

  L.tileLayer(MAPA_VENTAS_TESELAS, {
    maxZoom: 19,
    attribution: MAPA_VENTAS_ATRIBUCION,
  }).addTo(mapaVentasVivo);

  const coordenadas = [];
  puntos.forEach(function (punto) {
    const lat = Number(punto.lat);
    const lon = Number(punto.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
      return;
    }

    coordenadas.push([lat, lon]);
    const marcador = L.marker([lat, lon], { icon: mapaVentasIcono(punto) });
    marcador.bindPopup(mapaVentasGlobo(punto), { maxWidth: 280 });
    marcador.addTo(mapaVentasVivo);
  });

  if (coordenadas.length === 1) {
    mapaVentasVivo.setView(coordenadas[0], 15);
  } else if (coordenadas.length > 1) {
    mapaVentasVivo.fitBounds(L.latLngBounds(coordenadas), { padding: [40, 40], maxZoom: 16 });
  }
}

document.addEventListener('DOMContentLoaded', function () {
  const figura = document.querySelector('[data-mapa-ventas]');
  if (!figura) {
    return;
  }

  if (!('IntersectionObserver' in window)) {
    dibujarMapaVentas(figura);
    return;
  }

  const observador = new IntersectionObserver(function (entradas) {
    entradas.forEach(function (entrada) {
      if (entrada.isIntersecting) {
        observador.unobserve(entrada.target);
        dibujarMapaVentas(entrada.target);
      }
    });
  }, { rootMargin: '250px' });

  observador.observe(figura);
});
