/**
 * graficas.js — gráficas SVG sin librerías ni innerHTML.
 *
 * Cada <figure data-grafica> trae sus datos en JSON:
 *   { "tipo": "barras" | "linea", "etiquetas": [...], "series": [{ "nombre": "...", "valores": [...] }], "formato": "moneda" | "entero" }
 * La misma información está SIEMPRE en una tabla junto a la gráfica (accesibilidad), y cada punto
 * tiene un <title> con su valor para lectores de pantalla y al pasar el cursor.
 */
const PALETA_GRAFICAS = ['#4679a6', '#d9822b', '#5b8c5a', '#8a6bbd', '#a0a0a0'];
const SVG_NS = 'http://www.w3.org/2000/svg';

function nodoSvg(nombre, atributos, texto) {
  const nodo = document.createElementNS(SVG_NS, nombre);
  Object.keys(atributos || {}).forEach(function (clave) {
    nodo.setAttribute(clave, String(atributos[clave]));
  });
  if (texto !== undefined) {
    nodo.textContent = texto;
  }
  return nodo;
}

function formatoValor(valor, formato) {
  if (formato === 'entero') {
    return new Intl.NumberFormat('es-MX').format(valor);
  }
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 }).format(valor);
}

function formatoCorto(valor, formato) {
  const absoluto = Math.abs(valor);
  const prefijo = formato === 'moneda' ? '$' : '';
  if (absoluto >= 1000000) {
    return prefijo + (valor / 1000000).toFixed(1).replace(/\.0$/, '') + ' M';
  }
  if (absoluto >= 1000) {
    return prefijo + (valor / 1000).toFixed(1).replace(/\.0$/, '') + ' mil';
  }
  return prefijo + String(Math.round(valor));
}

/** Máximo "redondo" para el eje (1, 2, 5 × 10^n). */
function maximoEje(valor) {
  if (valor <= 0) {
    return 1;
  }
  const exponente = Math.pow(10, Math.floor(Math.log10(valor)));
  const fraccion = valor / exponente;
  const paso = fraccion <= 1 ? 1 : fraccion <= 2 ? 2 : fraccion <= 5 ? 5 : 10;
  return paso * exponente;
}

function dibujarGrafica(figura) {
  let datos;
  try {
    datos = JSON.parse(figura.getAttribute('data-grafica'));
  } catch (error) {
    return;
  }

  const etiquetas = datos.etiquetas || [];
  const series = datos.series || [];
  const formato = datos.formato || 'moneda';
  if (!etiquetas.length || !series.length) {
    return;
  }

  const ancho = 720;
  const alto = 320;
  const margen = { arriba: 16, derecha: 16, abajo: 44, izquierda: 64 };
  const areaAncho = ancho - margen.izquierda - margen.derecha;
  const areaAlto = alto - margen.arriba - margen.abajo;
  const maximo = maximoEje(Math.max.apply(null, series.map(function (s) { return Math.max.apply(null, s.valores); }).concat([0])));

  const svg = nodoSvg('svg', { viewBox: '0 0 ' + ancho + ' ' + alto, role: 'img', 'aria-label': figura.getAttribute('aria-label') || 'Gráfica', class: 'grafica__svg' });

  // Rejilla y eje Y
  for (let i = 0; i <= 4; i += 1) {
    const valor = (maximo / 4) * i;
    const y = margen.arriba + areaAlto - (valor / maximo) * areaAlto;
    svg.appendChild(nodoSvg('line', { x1: margen.izquierda, x2: ancho - margen.derecha, y1: y, y2: y, class: 'grafica__rejilla' }));
    svg.appendChild(nodoSvg('text', { x: margen.izquierda - 8, y: y + 4, 'text-anchor': 'end', class: 'grafica__texto' }, formatoCorto(valor, formato)));
  }

  const cada = Math.max(1, Math.ceil(etiquetas.length / 12));
  const paso = areaAncho / etiquetas.length;

  etiquetas.forEach(function (etiqueta, i) {
    if (i % cada === 0) {
      svg.appendChild(nodoSvg('text', { x: margen.izquierda + paso * i + paso / 2, y: alto - margen.abajo + 18, 'text-anchor': 'middle', class: 'grafica__texto' }, etiqueta));
    }
  });

  if (datos.tipo === 'linea') {
    series.forEach(function (serie, s) {
      const color = PALETA_GRAFICAS[s % PALETA_GRAFICAS.length];
      const puntos = serie.valores.map(function (valor, i) {
        return [margen.izquierda + paso * i + paso / 2, margen.arriba + areaAlto - (valor / maximo) * areaAlto];
      });
      svg.appendChild(nodoSvg('polyline', { points: puntos.map(function (p) { return p.join(','); }).join(' '), fill: 'none', stroke: color, 'stroke-width': 2.5 }));
      puntos.forEach(function (p, i) {
        const circulo = nodoSvg('circle', { cx: p[0], cy: p[1], r: 3.5, fill: color });
        circulo.appendChild(nodoSvg('title', {}, serie.nombre + ' · ' + etiquetas[i] + ': ' + formatoValor(serie.valores[i], formato)));
        svg.appendChild(circulo);
      });
    });
  } else {
    const anchoGrupo = paso * 0.78;
    const anchoBarra = anchoGrupo / series.length;
    series.forEach(function (serie, s) {
      const color = PALETA_GRAFICAS[s % PALETA_GRAFICAS.length];
      serie.valores.forEach(function (valor, i) {
        const altoBarra = (valor / maximo) * areaAlto;
        const x = margen.izquierda + paso * i + (paso - anchoGrupo) / 2 + anchoBarra * s;
        const barra = nodoSvg('rect', { x: x, y: margen.arriba + areaAlto - altoBarra, width: Math.max(1, anchoBarra - 2), height: altoBarra, fill: color, rx: 2 });
        barra.appendChild(nodoSvg('title', {}, serie.nombre + ' · ' + etiquetas[i] + ': ' + formatoValor(valor, formato)));
        svg.appendChild(barra);
      });
    });
  }

  figura.replaceChildren(svg);

  // Leyenda (solo si hay más de una serie)
  if (series.length > 1) {
    const leyenda = document.createElement('ul');
    leyenda.className = 'grafica__leyenda';
    series.forEach(function (serie, s) {
      const item = document.createElement('li');
      const muestra = document.createElement('span');
      muestra.className = 'grafica__muestra';
      muestra.style.backgroundColor = PALETA_GRAFICAS[s % PALETA_GRAFICAS.length];
      item.appendChild(muestra);
      item.appendChild(document.createTextNode(serie.nombre));
      leyenda.appendChild(item);
    });
    figura.appendChild(leyenda);
  }
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-grafica]').forEach(dibujarGrafica);
});
