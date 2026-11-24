/**
 * expediente.js — Pestañas del expediente de una venta.
 *
 * Convierte los enlaces #resumen, #entrega, #incidencias, #facturacion y
 * #historial en pestañas reales: sólo se muestra el panel activo y los enlaces
 * internos (incluidos los que llegan desde un POST con ancla) abren la pestaña
 * correcta antes de desplazarse.
 */

/** Anclas antiguas que pertenecen a una pestaña concreta. */
const EXPEDIENTE_ANCLAS = {
  resumen: 'resumen',
  entrega: 'entrega',
  'fotos-entrega': 'entrega',
  'intentos-entrega': 'entrega',
  incidencias: 'incidencias',
  cambios: 'incidencias',
  facturacion: 'facturacion',
  cobros: 'facturacion',
  documentos: 'facturacion',
  historial: 'historial',
  'eliminar-venta': 'resumen',
};

function prepararPestanasExpedienteVenta() {
  const raiz = document.querySelector('[data-expediente]');
  if (!raiz) {
    return;
  }

  const nav = raiz.querySelector('[data-expediente-nav]');
  const tabs = Array.from(raiz.querySelectorAll('[data-expediente-tab]'));
  const paneles = Array.from(raiz.querySelectorAll('[data-expediente-panel]'));
  if (!tabs.length || !paneles.length) {
    return;
  }

  /** Devuelve la pestaña a la que pertenece un ancla. */
  const tabDeAncla = function (ancla) {
    if (EXPEDIENTE_ANCLAS[ancla]) {
      return EXPEDIENTE_ANCLAS[ancla];
    }
    const objetivo = document.getElementById(ancla);
    const panel = objetivo ? objetivo.closest('[data-expediente-panel]') : null;
    return panel ? panel.getAttribute('data-expediente-panel') : '';
  };

  /** Abre los <details> que contienen el objetivo (p. ej. «Resolver incidencia»). */
  const abrirDetalles = function (objetivo) {
    if (!objetivo) {
      return;
    }
    let detalles = objetivo.tagName === 'DETAILS' ? objetivo : objetivo.closest('details');
    while (detalles) {
      detalles.open = true;
      detalles = detalles.parentElement ? detalles.parentElement.closest('details') : null;
    }
  };

  const cerrarMenus = function () {
    raiz.querySelectorAll('.expediente-menu[open]').forEach(function (menu) {
      menu.removeAttribute('open');
    });
  };

  const activar = function (clave, desplazar) {
    const valida = tabs.some(function (tab) {
      return tab.getAttribute('data-expediente-tab') === clave;
    }) ? clave : 'resumen';

    tabs.forEach(function (tab) {
      const activo = tab.getAttribute('data-expediente-tab') === valida;
      tab.classList.toggle('is-activo', activo);
      tab.setAttribute('aria-selected', activo ? 'true' : 'false');
      tab.setAttribute('tabindex', activo ? '0' : '-1');
    });

    paneles.forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-expediente-panel') !== valida;
    });

    // Si la persona ya venía desplazada, al cambiar de pestaña se coloca el
    // inicio del panel bajo la barra fija (sin saltar si está arriba del todo).
    if (desplazar && window.scrollY > 0) {
      const panelActivo = paneles.find(function (panel) {
        return panel.getAttribute('data-expediente-panel') === valida;
      });
      if (panelActivo) {
        const destino = panelActivo.getBoundingClientRect().top + window.scrollY - 120;
        window.scrollTo({ top: Math.max(0, destino), behavior: 'smooth' });
      }
    }

    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', '#' + valida);
    }
  };

  // Abre la pestaña que corresponda al ancla de la URL (también tras un POST).
  const hash = window.location.hash.replace('#', '');
  const inicial = hash ? (tabDeAncla(hash) || 'resumen') : 'resumen';
  activar(inicial, false);

  if (hash) {
    const objetivo = document.getElementById(hash);
    if (objetivo) {
      abrirDetalles(objetivo);
      window.setTimeout(function () {
        objetivo.scrollIntoView({ behavior: 'auto', block: 'start' });
      }, 80);
    }
  }

  // Cambios de hash sin recargar (atrás/adelante o enlaces internos).
  window.addEventListener('hashchange', function () {
    const ancla = window.location.hash.replace('#', '');
    const panel = ancla ? tabDeAncla(ancla) : 'resumen';
    if (!panel) {
      return;
    }
    activar(panel, false);
    const objetivo = ancla ? document.getElementById(ancla) : null;
    if (objetivo) {
      abrirDetalles(objetivo);
      window.setTimeout(function () {
        objetivo.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 60);
    }
  });

  tabs.forEach(function (tab, indice) {
    tab.addEventListener('click', function (evento) {
      evento.preventDefault();
      activar(tab.getAttribute('data-expediente-tab'), true);
      cerrarMenus();
    });

    tab.addEventListener('keydown', function (evento) {
      if (evento.key !== 'ArrowRight' && evento.key !== 'ArrowLeft') {
        return;
      }
      evento.preventDefault();
      const siguiente = evento.key === 'ArrowRight'
        ? (indice + 1) % tabs.length
        : (indice - 1 + tabs.length) % tabs.length;
      tabs[siguiente].focus();
      activar(tabs[siguiente].getAttribute('data-expediente-tab'), true);
    });
  });

  raiz.addEventListener('click', function (evento) {
    const enlace = evento.target instanceof Element
      ? evento.target.closest('a[href^="#"]')
      : null;

    // Las pestañas y los enlaces que abren un modal tienen su propio manejador.
    if (!enlace) {
      return;
    }
    if (enlace.hasAttribute('data-expediente-tab')) {
      return;
    }
    if (enlace.hasAttribute('data-modal-form')) {
      cerrarMenus();
      return;
    }

    const ancla = enlace.getAttribute('href').slice(1);
    const panel = ancla ? tabDeAncla(ancla) : '';
    if (!panel) {
      return;
    }

    evento.preventDefault();
    activar(panel, true);

    const objetivo = document.getElementById(ancla);
    const contenedor = objetivo ? objetivo.closest('[data-expediente-panel]') : null;
    if (objetivo) {
      abrirDetalles(objetivo);
    }
    if (objetivo && contenedor && !contenedor.hidden) {
      window.setTimeout(function () {
        objetivo.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 60);
    }
  });

  document.addEventListener('click', function (evento) {
    if (evento.target instanceof Element && !evento.target.closest('.expediente-menu')) {
      cerrarMenus();
    }
  });

  document.addEventListener('keydown', function (evento) {
    if (evento.key === 'Escape') {
      cerrarMenus();
    }
  });
}
