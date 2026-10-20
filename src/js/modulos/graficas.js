/**
 * graficas.js — gráficas SVG sin librerías ni innerHTML.
 *
 * Cada <figure data-grafica> trae sus datos en JSON:
 *   { "tipo": "barras" | "linea" | "barras-h",
 *     "etiquetas": [...], "series": [{ "nombre": "...", "valores": [...] }],
 *     "formato": "moneda" | "entero", "destacar": "ultimo" }
 *   - barras:   columnas con el valor encima; con "destacar":"ultimo" el último periodo resalta y los demás se atenúan.
 *   - linea:    línea con área suave; los puntos muestran su valor al pasar el cursor.
 *   - barras-h: barras horizontales para rankings (productos, clientes).
 * La misma información está SIEMPRE en una tabla en la página (accesibilidad) y cada marca trae <title>.
 */
const PALETA_GRAFICAS = ['#2f6f9f', '#e08a2e', '#4f9a5b', '#8a6bbd', '#9aa5ad'];
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

function recortar(texto, maximo) {
  return texto.length > maximo ? texto.slice(0, maximo - 1) + '…' : texto;
}

/** Globo con el valor al pasar el cursor o enfocar una marca. */
function globoDeFigura(figura) {
  let globo = figura.querySelector('.grafica__globo');
  if (!globo) {
    globo = document.createElement('div');
    globo.className = 'grafica__globo';
    globo.hidden = true;
    figura.appendChild(globo);
  }
  return globo;
}

function conGlobo(figura, marca, texto) {
  const globo = globoDeFigura(figura);
  const mostrar = function () {
    globo.textContent = texto;
    globo.hidden = false;
    const f = figura.getBoundingClientRect();
    const m = marca.getBoundingClientRect();
    const izquierda = Math.max(4, Math.min(m.left - f.left + m.width / 2 - globo.offsetWidth / 2, f.width - globo.offsetWidth - 4));
    globo.style.left = izquierda + 'px';
    globo.style.top = Math.max(0, m.top - f.top - globo.offsetHeight - 8) + 'px';
    marca.classList.add('grafica__marca--activa');
  };
  const ocultar = function () {
    globo.hidden = true;
    marca.classList.remove('grafica__marca--activa');
  };
  marca.addEventListener('mouseenter', mostrar);
  marca.addEventListener('mouseleave', ocultar);
  marca.addEventListener('focus', mostrar);
  marca.addEventListener('blur', ocultar);
  marca.setAttribute('tabindex', '0');
}

function dibujarHorizontal(figura, datos) {
  const etiquetas = datos.etiquetas;
  const valores = datos.series[0].valores;
  const formato = datos.formato || 'moneda';
  const filaAlto = 34;
  const ancho = 560;
  const margenIzq = 150;
  const margenDer = 70;
  const alto = etiquetas.length * filaAlto + 12;
  const maximo = Math.max.apply(null, valores.concat([1]));
  const svg = nodoSvg('svg', { viewBox: '0 0 ' + ancho + ' ' + alto, role: 'img', 'aria-label': figura.getAttribute('aria-label') || 'Ranking', class: 'grafica__svg grafica__svg--h' });

  etiquetas.forEach(function (etiqueta, i) {
    const y = 6 + i * filaAlto;
    const largo = Math.max(3, (valores[i] / maximo) * (ancho - margenIzq - margenDer));
    svg.appendChild(nodoSvg('text', { x: margenIzq - 10, y: y + filaAlto / 2 + 4, 'text-anchor': 'end', class: 'grafica__texto grafica__texto--etiqueta' }, recortar(etiqueta, 21)));
    const barra = nodoSvg('rect', { x: margenIzq, y: y + 5, width: largo, height: filaAlto - 12, rx: 5, fill: PALETA_GRAFICAS[0], class: 'grafica__barra', style: 'opacity:' + (1 - i * 0.06) });
    barra.appendChild(nodoSvg('title', {}, etiqueta + ': ' + formatoValor(valores[i], formato)));
    svg.appendChild(barra);
    svg.appendChild(nodoSvg('text', { x: margenIzq + largo + 8, y: y + filaAlto / 2 + 4, class: 'grafica__texto grafica__texto--valor' }, formatoCorto(valores[i], formato)));
    conGlobo(figura, barra, etiqueta + ': ' + formatoValor(valores[i], formato));
  });
  figura.replaceChildren(svg);
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
  if (datos.tipo === 'barras-h') {
    dibujarHorizontal(figura, datos);
    return;
  }

  const ancho = 560;
  const alto = 300;
  const margen = { arriba: 28, derecha: 16, abajo: 40, izquierda: 56 };
  const areaAncho = ancho - margen.izquierda - margen.derecha;
  const areaAlto = alto - margen.arriba - margen.abajo;
  const maximo = maximoEje(Math.max.apply(null, series.map(function (s) { return Math.max.apply(null, s.valores); }).concat([0])));
  const yDe = function (valor) { return margen.arriba + areaAlto - (valor / maximo) * areaAlto; };

  const svg = nodoSvg('svg', { viewBox: '0 0 ' + ancho + ' ' + alto, role: 'img', 'aria-label': figura.getAttribute('aria-label') || 'Gráfica', class: 'grafica__svg' });

  // Rejilla horizontal y eje Y
  for (let i = 0; i <= 4; i += 1) {
    const valor = (maximo / 4) * i;
    const y = yDe(valor);
    svg.appendChild(nodoSvg('line', { x1: margen.izquierda, x2: ancho - margen.derecha, y1: y, y2: y, class: i === 0 ? 'grafica__base' : 'grafica__rejilla' }));
    svg.appendChild(nodoSvg('text', { x: margen.izquierda - 8, y: y + 4, 'text-anchor': 'end', class: 'grafica__texto' }, formatoCorto(valor, formato)));
  }

  const cada = Math.max(1, Math.ceil(etiquetas.length / 12));
  const paso = areaAncho / etiquetas.length;
  etiquetas.forEach(function (etiqueta, i) {
    if (i % cada === 0) {
      svg.appendChild(nodoSvg('text', { x: margen.izquierda + paso * i + paso / 2, y: alto - margen.abajo + 20, 'text-anchor': 'middle', class: 'grafica__texto' }, etiqueta));
    }
  });

  if (datos.tipo === 'linea') {
    const defs = nodoSvg('defs');
    const degradado = nodoSvg('linearGradient', { id: 'area-' + Math.random().toString(36).slice(2, 8), x1: 0, y1: 0, x2: 0, y2: 1 });
    degradado.appendChild(nodoSvg('stop', { offset: '0%', 'stop-color': PALETA_GRAFICAS[0], 'stop-opacity': 0.28 }));
    degradado.appendChild(nodoSvg('stop', { offset: '100%', 'stop-color': PALETA_GRAFICAS[0], 'stop-opacity': 0.02 }));
    defs.appendChild(degradado);
    svg.appendChild(defs);

    series.forEach(function (serie, s) {
      const color = PALETA_GRAFICAS[s % PALETA_GRAFICAS.length];
      const puntos = serie.valores.map(function (valor, i) {
        return [margen.izquierda + paso * i + paso / 2, yDe(valor)];
      });
      if (s === 0 && puntos.length > 1) {
        const base = yDe(0);
        const camino = 'M' + puntos[0][0] + ',' + base + ' L' + puntos.map(function (p) { return p.join(','); }).join(' L') + ' L' + puntos[puntos.length - 1][0] + ',' + base + ' Z';
        svg.appendChild(nodoSvg('path', { d: camino, fill: 'url(#' + degradado.getAttribute('id') + ')' }));
      }
      svg.appendChild(nodoSvg('polyline', { points: puntos.map(function (p) { return p.join(','); }).join(' '), fill: 'none', stroke: color, 'stroke-width': 2.75, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
      puntos.forEach(function (p, i) {
        const texto = serie.nombre + ' · ' + etiquetas[i] + ': ' + formatoValor(serie.valores[i], formato);
        const circulo = nodoSvg('circle', { cx: p[0], cy: p[1], r: puntos.length > 40 ? 2.5 : 4, fill: '#fff', stroke: color, 'stroke-width': 2.25, class: 'grafica__marca' });
        circulo.appendChild(nodoSvg('title', {}, texto));
        conGlobo(figura, circulo, texto);
        svg.appendChild(circulo);
      });
    });
  } else {
    const anchoGrupo = Math.min(paso * 0.72, 64 * series.length);
    const anchoBarra = anchoGrupo / series.length;
    const resaltarUltimo = datos.destacar === 'ultimo';
    series.forEach(function (serie, s) {
      const color = PALETA_GRAFICAS[s % PALETA_GRAFICAS.length];
      serie.valores.forEach(function (valor, i) {
        const altoBarra = Math.max(valor > 0 ? 2 : 0, (valor / maximo) * areaAlto);
        const x = margen.izquierda + paso * i + (paso - anchoGrupo) / 2 + anchoBarra * s;
        const esUltimo = i === etiquetas.length - 1;
        const texto = serie.nombre + ' · ' + etiquetas[i] + ': ' + formatoValor(valor, formato);
        const barra = nodoSvg('rect', {
          x: x, y: margen.arriba + areaAlto - altoBarra, width: Math.max(2, anchoBarra - 3), height: altoBarra, fill: color, rx: 4,
          class: 'grafica__barra', style: 'opacity:' + (resaltarUltimo && !esUltimo ? 0.45 : 1) + ';transform-origin:' + (x + anchoBarra / 2) + 'px ' + (margen.arriba + areaAlto) + 'px',
        });
        barra.appendChild(nodoSvg('title', {}, texto));
        conGlobo(figura, barra, texto);
        svg.appendChild(barra);
        if (valor > 0 && etiquetas.length * series.length <= 14) {
          svg.appendChild(nodoSvg('text', { x: x + (anchoBarra - 3) / 2, y: margen.arriba + areaAlto - altoBarra - 6, 'text-anchor': 'middle', class: 'grafica__texto grafica__texto--valor' }, formatoCorto(valor, formato)));
        }
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
