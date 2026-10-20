/**
 * graficas.js — gráficas de Estadísticas con Apache ECharts.
 *
 * Cada <figure data-grafica> trae su especificación en JSON (la misma que usan el PDF y el Excel):
 *   { "tipo": "barras" | "apilado" | "linea" | "barras-h", "formato": "moneda" | "entero",
 *     "categorias": [...], "series": [{ "nombre", "datos": [...], "color" }],
 *     "resaltar": [mesInicio, mesFin], "comparaSeries": true, "acumulado": true }
 *   - comparaSeries: las series son periodos consecutivos (años); el globo muestra la variación
 *     porcentual de cada serie contra la anterior.
 *   - resaltar: franja con el periodo elegido en los filtros.
 * La biblioteca (build/js/echarts.min.js) solo se carga en la página de estadísticas; si no está,
 * las tablas de la página traen los mismos datos.
 * Los colores de texto y rejilla salen de las variables CSS del tema y se repintan con «tema:cambio».
 * El globo se arma con nodos del DOM (nunca con texto del servidor como HTML).
 */
const GRAFICAS_FUENTE = 'Inter, "Segoe UI", Roboto, Arial, sans-serif';
const graficasVivas = [];

function graficasColorCss(nombre, respaldo) {
  const valor = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
  return valor || respaldo;
}

function graficasTema() {
  return {
    texto: graficasColorCss('--color-texto', '#4d4c53'),
    suave: graficasColorCss('--color-texto-secundario', '#64646a'),
    borde: graficasColorCss('--color-borde', '#e4e6ea'),
    bordeFuerte: graficasColorCss('--color-borde-fuerte', '#c9cdd4'),
    superficie: graficasColorCss('--color-superficie', '#ffffff'),
    oscuro: typeof temaEfectivo === 'function' && temaEfectivo() === 'oscuro',
  };
}

/** En tema oscuro aclara un color #rrggbb para que no se pierda sobre el fondo. */
function graficasColor(color, tema) {
  if (!tema.oscuro || !/^#[0-9a-f]{6}$/i.test(color)) {
    return color;
  }
  const mezcla = function (i) {
    const canal = parseInt(color.slice(i, i + 2), 16);
    return Math.round(canal + (255 - canal) * 0.28).toString(16).padStart(2, '0');
  };
  return '#' + mezcla(1) + mezcla(3) + mezcla(5);
}

function graficasMoneda(valor, decimales) {
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: decimales, maximumFractionDigits: decimales }).format(valor);
}

function graficasValor(valor, formato) {
  if (valor === null || valor === undefined || Number.isNaN(Number(valor))) {
    return '—';
  }
  return formato === 'entero' ? new Intl.NumberFormat('es-MX').format(valor) : graficasMoneda(valor, 2);
}

function graficasCorto(valor, formato) {
  const absoluto = Math.abs(valor);
  const prefijo = formato === 'moneda' ? '$' : '';
  const compacto = function (n, sufijo) {
    return prefijo + n.toFixed(1).replace(/\.0$/, '') + sufijo;
  };
  if (absoluto >= 1000000) {
    return compacto(valor / 1000000, ' M');
  }
  if (absoluto >= 1000) {
    return compacto(valor / 1000, ' mil');
  }
  return prefijo + String(Math.round(valor));
}

function graficasVariacion(actual, previo) {
  if (actual === null || actual === undefined || !previo || previo <= 0) {
    return null;
  }
  return ((actual - previo) * 100) / previo;
}

/** Globo con jerarquía: periodo, una fila por serie (con variación) y total si es apilado. */
function graficasGlobo(espec) {
  const formato = espec.formato || 'moneda';
  return function (parametros) {
    const lista = Array.isArray(parametros) ? parametros : [parametros];
    if (!lista.length) {
      return '';
    }
    const caja = document.createElement('div');
    caja.className = 'globo';
    const titulo = document.createElement('div');
    titulo.className = 'globo__titulo';
    titulo.textContent = String(lista[0].name !== undefined && lista[0].name !== '' ? lista[0].name : lista[0].axisValueLabel);
    caja.appendChild(titulo);

    let total = 0;
    lista.forEach(function (item) {
      const valor = Array.isArray(item.value) ? item.value[1] : item.value;
      if (valor !== null && valor !== undefined) {
        total += Number(valor);
      }
      const fila = document.createElement('div');
      fila.className = 'globo__fila';
      const marca = document.createElement('span');
      marca.className = 'globo__marca';
      marca.style.backgroundColor = typeof item.color === 'string' ? item.color : espec.series[item.seriesIndex].color;
      const nombre = document.createElement('span');
      nombre.className = 'globo__nombre';
      nombre.textContent = item.seriesName;
      const cifra = document.createElement('strong');
      cifra.className = 'globo__cifra';
      cifra.textContent = graficasValor(valor, formato);
      fila.append(marca, nombre, cifra);
      caja.appendChild(fila);

      if (espec.comparaSeries && item.seriesIndex > 0) {
        const previa = espec.series[item.seriesIndex - 1];
        const variacion = graficasVariacion(valor, previa ? previa.datos[item.dataIndex] : null);
        const nota = document.createElement('div');
        nota.className = 'globo__variacion';
        if (variacion === null) {
          nota.textContent = 'vs ' + previa.nombre + ': sin base de comparación';
        } else {
          const sube = variacion >= 0;
          nota.classList.add(sube ? 'globo__variacion--sube' : 'globo__variacion--baja');
          nota.textContent = (sube ? '▲ +' : '▼ ') + variacion.toFixed(1) + ' % vs ' + previa.nombre;
        }
        caja.appendChild(nota);
      }
    });

    if (espec.tipo === 'apilado' && lista.length > 1) {
      const fila = document.createElement('div');
      fila.className = 'globo__fila globo__fila--total';
      const nombre = document.createElement('span');
      nombre.className = 'globo__nombre';
      nombre.textContent = 'Total';
      const cifra = document.createElement('strong');
      cifra.className = 'globo__cifra';
      cifra.textContent = graficasValor(total, formato);
      fila.append(nombre, cifra);
      caja.appendChild(fila);
      lista.forEach(function (item) {
        const valor = Array.isArray(item.value) ? item.value[1] : item.value;
        if (total > 0 && valor > 0) {
          const parte = document.createElement('div');
          parte.className = 'globo__variacion';
          parte.textContent = item.seriesName + ': ' + ((valor * 100) / total).toFixed(1) + ' % del total';
          caja.appendChild(parte);
        }
      });
    }

    return caja;
  };
}

function graficasDegradado(color, arriba, abajo) {
  return {
    type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
    colorStops: [{ offset: 0, color: color + arriba }, { offset: 1, color: color + abajo }],
  };
}

function graficasOpciones(espec, compacto, sinAnimacion) {
  const tema = graficasTema();
  const formato = espec.formato || 'moneda';
  const categorias = espec.categorias;
  const series = espec.series;
  const horizontal = espec.tipo === 'barras-h';
  const linea = espec.tipo === 'linea';
  const apilado = espec.tipo === 'apilado';
  const muchos = categorias.length > 60;
  const color = function (serie) { return graficasColor(serie.color, tema); };

  const ejeValores = {
    type: 'value',
    axisLabel: { color: tema.suave, fontFamily: GRAFICAS_FUENTE, fontSize: 11, formatter: function (v) { return graficasCorto(v, formato); } },
    splitLine: { lineStyle: { color: tema.borde, type: [4, 4] } },
    axisLine: { show: false },
    axisTick: { show: false },
  };
  const ejeCategorias = {
    type: 'category',
    data: categorias,
    inverse: horizontal,
    axisLabel: {
      color: horizontal ? tema.texto : tema.suave, fontFamily: GRAFICAS_FUENTE, fontSize: 11, hideOverlap: true,
      width: horizontal ? (compacto ? 110 : 190) : undefined, overflow: 'truncate',
    },
    axisLine: { lineStyle: { color: tema.bordeFuerte } },
    axisTick: { show: false },
    boundaryGap: !linea,
  };

  const marcaPeriodo = !horizontal && espec.resaltar && espec.resaltar.length === 2 && !(espec.resaltar[0] === 1 && espec.resaltar[1] === 12)
    ? { silent: true, itemStyle: { color: tema.oscuro ? 'rgba(120,170,220,0.12)' : 'rgba(70,121,166,0.08)' }, data: [[{ xAxis: espec.resaltar[0] - 1 }, { xAxis: espec.resaltar[1] - 1 }]] }
    : null;

  const grupos = series.map(function (serie, i) {
    const tono = color(serie);
    const base = { name: serie.nombre, data: serie.datos, emphasis: { focus: 'series' } };
    if (linea) {
      return Object.assign(base, {
        type: 'line',
        smooth: 0.2,
        showSymbol: categorias.length <= 24,
        symbolSize: 7,
        sampling: categorias.length > 300 ? 'lttb' : undefined,
        itemStyle: { color: tono },
        lineStyle: { width: 3, color: tono },
        areaStyle: { color: graficasDegradado(tono, i === series.length - 1 ? '40' : '14', '03') },
        connectNulls: false,
        markArea: i === 0 && marcaPeriodo ? marcaPeriodo : undefined,
      });
    }
    return Object.assign(base, {
      type: 'bar',
      stack: apilado ? 'total' : undefined,
      barMaxWidth: horizontal ? 22 : 38,
      large: muchos,
      largeThreshold: 600,
      itemStyle: {
        color: horizontal && series.length === 1
          ? function (p) { return tono + (p.dataIndex < 3 ? '' : 'cc'); }
          : tono,
        borderRadius: horizontal ? [0, 4, 4, 0] : (apilado ? 0 : [4, 4, 0, 0]),
      },
      label: horizontal ? { show: true, position: 'right', color: tema.texto, fontFamily: GRAFICAS_FUENTE, fontSize: 11, fontWeight: 600, formatter: function (p) { return graficasCorto(p.value, formato); } } : undefined,
      markArea: i === 0 && marcaPeriodo ? marcaPeriodo : undefined,
    });
  });

  return {
    aria: { enabled: true },
    color: series.map(color),
    backgroundColor: 'transparent',
    textStyle: { fontFamily: GRAFICAS_FUENTE },
    animation: !sinAnimacion,
    animationDuration: 750,
    animationEasing: 'cubicOut',
    animationDelay: function (indice) { return Math.min(indice * 25, 400); },
    animationDurationUpdate: 300,
    grid: { left: compacto ? 8 : 12, right: horizontal ? 56 : (compacto ? 10 : 24), top: 28, bottom: (series.length > 1 ? 44 : 12) + (muchos ? 36 : 0), containLabel: true },
    legend: series.length > 1
      ? { type: 'scroll', bottom: muchos ? 34 : 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8, textStyle: { color: tema.texto, fontFamily: GRAFICAS_FUENTE, fontSize: 12 }, pageIconSize: 10, pageTextStyle: { color: tema.suave } }
      : { show: false },
    tooltip: {
      trigger: horizontal ? 'item' : 'axis',
      axisPointer: { type: horizontal ? 'none' : 'shadow', shadowStyle: { color: tema.oscuro ? 'rgba(120,170,220,0.10)' : 'rgba(70,121,166,0.07)' }, lineStyle: { color: tema.bordeFuerte } },
      backgroundColor: tema.superficie,
      borderColor: tema.borde,
      borderWidth: 1,
      padding: 0,
      confine: true,
      extraCssText: 'border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.18);',
      formatter: graficasGlobo(espec),
    },
    toolbox: compacto ? { show: false } : {
      right: 4, top: 0, itemSize: 14,
      feature: { saveAsImage: { title: 'Guardar imagen', name: espec.titulo, pixelRatio: 2, backgroundColor: tema.superficie } },
      iconStyle: { borderColor: tema.suave },
    },
    dataZoom: muchos ? [{ type: 'inside' }, { type: 'slider', height: 18, bottom: 6, borderColor: tema.borde, textStyle: { color: tema.suave }, brushSelect: false }] : undefined,
    xAxis: horizontal ? ejeValores : ejeCategorias,
    yAxis: horizontal ? ejeCategorias : ejeValores,
    series: grupos,
  };
}

function graficasMensajeSinBiblioteca(figura) {
  const nota = document.createElement('p');
  nota.className = 'grafica__aviso';
  nota.textContent = 'No se pudo cargar la biblioteca de gráficas. Los mismos datos están en las tablas de esta página.';
  figura.replaceChildren(nota);
}

function dibujarGrafica(figura) {
  let espec;
  try {
    espec = JSON.parse(figura.getAttribute('data-grafica'));
  } catch (error) {
    return;
  }
  if (!espec || !espec.categorias || !espec.categorias.length || !espec.series || !espec.series.length) {
    return;
  }
  if (typeof echarts === 'undefined') {
    graficasMensajeSinBiblioteca(figura);
    return;
  }

  const sinAnimacion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const lienzo = document.createElement('div');
  lienzo.className = 'grafica__lienzo' + (espec.tipo === 'barras-h' ? ' grafica__lienzo--h' : '');
  if (espec.tipo === 'barras-h') {
    lienzo.style.height = Math.max(220, espec.categorias.length * 34 + 40) + 'px';
  }
  figura.replaceChildren(lienzo);

  const grafica = echarts.init(lienzo, null, { renderer: 'canvas' });
  const estado = { compacto: lienzo.clientWidth < 520 };
  grafica.setOption(graficasOpciones(espec, estado.compacto, sinAnimacion));
  graficasVivas.push({ grafica: grafica, espec: espec, estado: estado });

  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(function () {
      const ahoraCompacto = lienzo.clientWidth < 520;
      if (ahoraCompacto !== estado.compacto) {
        estado.compacto = ahoraCompacto;
        grafica.setOption(graficasOpciones(espec, ahoraCompacto, true), true);
      }
      grafica.resize();
    }).observe(lienzo);
  } else {
    window.addEventListener('resize', function () { grafica.resize(); });
  }
}

document.addEventListener('tema:cambio', function () {
  graficasVivas.forEach(function (viva) {
    viva.grafica.setOption(graficasOpciones(viva.espec, viva.estado.compacto, true), true);
  });
});

document.addEventListener('DOMContentLoaded', function () {
  const figuras = document.querySelectorAll('[data-grafica]');
  if (!('IntersectionObserver' in window)) {
    figuras.forEach(dibujarGrafica);
    return;
  }
  // Cada gráfica se crea al acercarse a la pantalla: la página abre rápido aunque tenga muchas.
  const observador = new IntersectionObserver(function (entradas) {
    entradas.forEach(function (entrada) {
      if (entrada.isIntersecting) {
        observador.unobserve(entrada.target);
        dibujarGrafica(entrada.target);
      }
    });
  }, { rootMargin: '200px' });
  figuras.forEach(function (figura) { observador.observe(figura); });
});
