/**
 * modulos/descargasApp.js
 * ---------------------------------------------------------------------------
 * Descargas dentro de la app Android.
 *
 * El WebView de Android no implementa ventanas nuevas: un enlace con
 * target="_blank" se ignora (no navega, no descarga). Todas las exportaciones
 * que abrían el PDF en otra pestaña quedaban muertas en la app.
 *
 * Este módulo sólo actúa cuando existe el puente `Acero360Android`:
 *  - Los enlaces con target="_blank" se abren en la misma ventana. Si el
 *    servidor responde con Content-Disposition: attachment, el WebView llama
 *    al DownloadListener de la app y guarda el archivo en Descargas.
 *  - Los enlaces «Imprimir» que piden un PDF inline=1 se convierten en
 *    descarga: el WebView no tiene visor de PDF.
 *  - Los enlaces externos con target="_blank" se navegan igualmente para que
 *    la política de la app los entregue a Chrome, Maps, WhatsApp, etc.
 */
(function () {
  'use strict';

  /** ¿Estamos dentro de la app Android? (el puente lo inyecta MainActivity). */
  function enApp() {
    return !!(window.Acero360Android && typeof window.Acero360Android === 'object');
  }

  /** Convierte una URL interna en URL de descarga (sin inline=1). Null si es externa. */
  function urlDescarga(destino) {
    let url;
    try {
      url = new URL(destino, window.location.href);
    } catch (error) {
      return null;
    }
    if (url.origin !== window.location.origin) {
      return null;
    }
    url.searchParams.delete('inline');
    return url.href;
  }

  function interceptar(evento) {
    if (!enApp() || evento.defaultPrevented || evento.button !== 0
      || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
      return;
    }

    const enlace = evento.target instanceof Element ? evento.target.closest('a[href]') : null;
    if (!enlace || enlace.hasAttribute('download')) {
      return;
    }

    const interno = urlDescarga(enlace.getAttribute('href'));
    if (!interno) {
      // Enlace externo: en la app lo abre el sistema (la política de navegación
      // de Tauri intercepta la navegación). Sin esto, target="_blank" no hace nada.
      if (enlace.target === '_blank' && /^https?:/i.test(enlace.href)) {
        evento.preventDefault();
        window.location.href = enlace.href;
      }
      return;
    }

    let pideInline = false;
    try {
      pideInline = new URL(enlace.href).searchParams.get('inline') === '1';
    } catch (error) {
      pideInline = false;
    }

    // Sólo se tocan los enlaces que en un navegador abrirían otra pestaña o
    // que piden el PDF inline. Un enlace interno normal sigue igual.
    if (enlace.target !== '_blank' && !pideInline) {
      return;
    }

    evento.preventDefault();
    window.location.href = interno;
  }

  document.addEventListener('click', interceptar, true);
})();
