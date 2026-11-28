/**
 * modulos/pdfExport.js
 * ---------------------------------------------------------------------------
 * Indicador visible y apertura automática de las exportaciones PDF.
 *
 * Antes cada botón PDF disparaba una descarga silenciosa: si dompdf tardaba,
 * el usuario no sabía si el clic había funcionado y, al terminar, el archivo
 * se quedaba en Descargas. Este módulo:
 *
 *  - intercepta los enlaces PDF de toda la aplicación (y las exportaciones
 *    del Editor/Redactor que lo llaman a propósito);
 *  - muestra una capa bloqueante con el tiempo transcurrido y el avance de
 *    la descarga;
 *  - al terminar abre el PDF: pestaña nueva en el navegador, visor del
 *    sistema en escritorio y puente de Android cuando está disponible.
 *
 * La capa vive en templates/pdfOverlay.php. El HTML se construye/consulta
 * con document.getElementById; aquí nunca se usa innerHTML.
 */
(function () {
  'use strict';

  const RUTAS_PDF = [
    /\/pdf\/?$/i,
    /\/pdf\//i,
  ];

  // Rutas que devuelven un PDF pero no llevan "pdf" en la URL.
  const RUTAS_ESPECIALES = [
    /^\/admin\/entregas-seguimiento\/acta\/?$/i,
  ];

  let capa = null;
  let titulo = null;
  let detalle = null;
  let progreso = null;
  let barra = null;
  let tiempo = null;
  let botonCancelar = null;

  let ocupado = false;
  let controlador = null;
  let temporizador = null;
  let inicio = 0;
  let focoAnterior = null;
  let ventanaPdf = null;
  let abrirAlTerminar = true;

  /** ¿La página corre dentro de la app Android Acero 360? */
  function esAndroid() {
    return !!(window.Acero360Android
      && typeof window.Acero360Android === 'object'
      && typeof window.Acero360Android.guardar === 'function');
  }

  /** ¿La página corre dentro de la app de escritorio? */
  function esEscritorio() {
    return navigator.userAgent.indexOf('Acero360Escritorio/') !== -1;
  }

  /** Guarda las referencias de la capa una sola vez. */
  function preparar() {
    if (capa) {
      return;
    }
    capa = document.getElementById('pdf-capa');
    if (!capa) {
      return;
    }

    titulo = document.getElementById('pdf-capa-titulo');
    detalle = document.getElementById('pdf-capa-detalle');
    progreso = document.getElementById('pdf-capa-progreso');
    barra = document.getElementById('pdf-capa-barra');
    tiempo = document.getElementById('pdf-capa-tiempo');
    botonCancelar = document.getElementById('pdf-capa-cancelar');

    document.addEventListener('click', manejarClic);
    document.addEventListener('keydown', function (evento) {
      if (evento.key === 'Escape' && ocupado) {
        evento.preventDefault();
        cancelar();
      }
    });

    if (botonCancelar) {
      botonCancelar.addEventListener('click', cancelar);
    }

    capa.addEventListener('click', function (evento) {
      if (evento.target === capa) {
        cancelar();
      }
    });
  }

  /**
   * ¿La URL es una exportación PDF de esta aplicación? Se evitan las
   * previsualizaciones de archivos ya guardados (Archivo, comprobantes…).
   */
  function esPdf(destino) {
    let url;
    try {
      url = new URL(destino, window.location.href);
    } catch (error) {
      return false;
    }

    if (url.origin !== window.location.origin) {
      return false;
    }
    if (url.pathname.indexOf('/admin/archivo/') === 0) {
      return false;
    }

    const formato = (url.searchParams.get('formato') || '').toLowerCase();
    if (formato === 'pdf') {
      return true;
    }

    if (url.searchParams.get('inline') === '1' && /\/descargar\/?$/i.test(url.pathname)) {
      return true;
    }

    for (let i = 0; i < RUTAS_PDF.length; i += 1) {
      if (RUTAS_PDF[i].test(url.pathname)) {
        return true;
      }
    }
    for (let i = 0; i < RUTAS_ESPECIALES.length; i += 1) {
      if (RUTAS_ESPECIALES[i].test(url.pathname)) {
        return true;
      }
    }

    return false;
  }

  /** Nombre de archivo a partir de la URL cuando el servidor no manda uno. */
  function nombreDeUrl(destino) {
    let nombre = 'documento.pdf';
    try {
      const url = new URL(destino, window.location.href);
      const partes = url.pathname.split('/').filter(Boolean);
      if (partes.length) {
        nombre = partes[partes.length - 1];
      }
      if (!/\.pdf$/i.test(nombre)) {
        nombre = 'documento.pdf';
      }
    } catch (error) {
      nombre = 'documento.pdf';
    }
    return nombre;
  }

  /** Lee el nombre que envía el servidor en Content-Disposition. */
  function nombreDeCabecera(cabecera, destino) {
    if (!cabecera) {
      return nombreDeUrl(destino);
    }

    const extendido = /filename\*=UTF-8''([^;]+)/i.exec(cabecera);
    if (extendido) {
      try {
        return decodeURIComponent(extendido[1].trim().replace(/^"|"$/g, ''));
      } catch (error) {
        /* Se intenta con el formato normal. */
      }
    }

    const normal = /filename="?([^";]+)"?/i.exec(cabecera);
    if (normal) {
      return normal[1].trim();
    }

    return nombreDeUrl(destino);
  }

  /** Asegura que el nombre termine en .pdf. */
  function nombrePdf(nombre) {
    const limpio = String(nombre || 'documento.pdf').trim() || 'documento.pdf';
    return /\.pdf$/i.test(limpio) ? limpio : limpio + '.pdf';
  }

  /** Abre una pestaña en blanco antes de empezar para que el navegador no la bloquee. */
  function abrirVentana() {
    if (esAndroid() || esEscritorio()) {
      return null;
    }

    let ventana = null;
    try {
      ventana = window.open('', '_blank');
    } catch (error) {
      return null;
    }
    if (!ventana || ventana.closed) {
      return null;
    }

    try {
      ventana.document.title = 'Generando PDF…';
      ventana.document.body.textContent = '';
      const aviso = ventana.document.createElement('p');
      aviso.textContent = 'Generando el PDF… esta pestaña se llenará cuando esté listo.';
      aviso.style.cssText = 'font-family: system-ui, sans-serif; padding: 2rem; color: #475569;';
      ventana.document.body.appendChild(aviso);
    } catch (error) {
      /* Si no se puede escribir, se deja la pestaña en blanco. */
    }

    return ventana;
  }

  /** Muestra la capa y arranca el contador de tiempo. */
  function mostrarCapa(textoTitulo, textoDetalle) {
    if (!capa) {
      preparar();
    }
    if (!capa) {
      return;
    }

    if (titulo) {
      titulo.textContent = textoTitulo || 'Generando PDF…';
    }
    if (detalle) {
      detalle.textContent = textoDetalle || 'El servidor está preparando el documento.';
    }
    if (capa) {
      capa.classList.remove('pdf-capa--indeterminada');
    }
    if (progreso) {
      progreso.removeAttribute('aria-valuenow');
    }
    if (barra) {
      barra.style.width = '0%';
    }
    if (tiempo) {
      tiempo.textContent = '0 s';
    }

    focoAnterior = document.activeElement;
    capa.hidden = false;
    document.body.classList.add('pdf-capa-abierta');

    if (botonCancelar) {
      botonCancelar.focus();
    }

    inicio = Date.now();
    if (temporizador) {
      window.clearInterval(temporizador);
    }
    temporizador = window.setInterval(function () {
      if (!tiempo) {
        return;
      }
      const segundos = Math.floor((Date.now() - inicio) / 1000);
      tiempo.textContent = segundos + ' s';
    }, 500);
  }

  /** Fija el avance. pct = null deja la barra en movimiento continuo. */
  function fijarProgreso(pct, texto) {
    if (texto && detalle) {
      detalle.textContent = texto;
    }
    if (!progreso || !barra) {
      return;
    }

    if (pct === null || typeof pct === 'undefined' || isNaN(pct)) {
      if (capa) {
        capa.classList.add('pdf-capa--indeterminada');
      }
      progreso.removeAttribute('aria-valuenow');
      barra.style.width = '';
      return;
    }

    const valor = Math.max(0, Math.min(100, Math.round(pct)));
    if (capa) {
      capa.classList.remove('pdf-capa--indeterminada');
    }
    progreso.setAttribute('aria-valuenow', String(valor));
    barra.style.width = valor + '%';
  }

  /** Oculta la capa y restaura el foco. */
  function ocultarCapa() {
    if (temporizador) {
      window.clearInterval(temporizador);
      temporizador = null;
    }
    if (capa) {
      capa.hidden = true;
    }
    document.body.classList.remove('pdf-capa-abierta');

    if (focoAnterior && typeof focoAnterior.focus === 'function' && document.contains(focoAnterior)) {
      try {
        focoAnterior.focus();
      } catch (error) {
        /* Sin foco disponible. */
      }
    }
    focoAnterior = null;
  }

  /** Termina el estado actual. */
  function terminarEstado() {
    ocupado = false;
    controlador = null;
    ventanaPdf = null;
    abrirAlTerminar = true;
    ocultarCapa();
  }

  /** Descarga el Blob con el nombre indicado. */
  function descargar(blob, nombre) {
    const url = URL.createObjectURL(blob);
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.download = nombre;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    window.setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 60000);
  }

  /** Entrega el PDF al puente de Android, que lo guarda y lo abre. */
  function enviarAndroid(blob, nombre) {
    return new Promise(function (resolver, rechazar) {
      const lector = new FileReader();
      lector.onload = function () {
        const datos = String(lector.result || '');
        const coma = datos.indexOf(',');
        if (coma < 0) {
          rechazar(new Error('No se pudo preparar el archivo.'));
          return;
        }
        try {
          window.Acero360Android.guardar(datos.substring(coma + 1), nombre, 'application/pdf');
          resolver();
        } catch (error) {
          rechazar(error);
        }
      };
      lector.onerror = function () {
        rechazar(new Error('No se pudo leer el archivo.'));
      };
      lector.readAsDataURL(blob);
    });
  }

  /** Abre el PDF terminado según la plataforma. */
  function abrirResultado(blob, nombre) {
    const archivo = nombrePdf(nombre);

    if (esAndroid()) {
      return enviarAndroid(blob, archivo).then(function () {
        crearAlerta('exito', 'El PDF se guardó en Descargas y se abrirá con la app disponible.', 'PDF listo');
      }).catch(function () {
        // Si el puente falla, al menos se descarga con el navegador/WebView.
        descargar(blob, archivo);
        crearAlerta('exito', 'El PDF se descargó.', 'PDF listo');
      });
    }

    if (esEscritorio()) {
      descargar(blob, archivo);
      crearAlerta('exito', 'El PDF se abrirá con el visor del sistema.', 'PDF listo');
      return Promise.resolve();
    }

    if (!abrirAlTerminar) {
      descargar(blob, archivo);
      crearAlerta('exito', 'PDF descargado.', 'PDF listo');
      return Promise.resolve();
    }

    const url = URL.createObjectURL(blob);
    let visor = null;

    if (ventanaPdf && !ventanaPdf.closed) {
      try {
        ventanaPdf.location.replace(url);
        visor = ventanaPdf;
      } catch (error) {
        visor = null;
      }
    }

    if (!visor) {
      try {
        visor = window.open(url, '_blank');
      } catch (error) {
        visor = null;
      }
    }

    if (!visor) {
      descargar(blob, archivo);
      crearAlerta('exito', 'El navegador bloqueó la pestaña; el PDF se descargó.', 'PDF listo');
      window.setTimeout(function () {
        URL.revokeObjectURL(url);
      }, 60000);
      return Promise.resolve();
    }

    // Algunos navegadores no tienen visor de PDF y dejan la pestaña en blanco.
    // Se comprueba a los dos segundos: si sigue en about:blank, se descarga.
    window.setTimeout(function () {
      let enBlanco = false;
      try {
        enBlanco = !visor.closed && visor.location.href === 'about:blank';
      } catch (error) {
        enBlanco = false;
      }

      if (!enBlanco) {
        crearAlerta('exito', 'El PDF se abrió en una pestaña nueva.', 'PDF listo');
        return;
      }

      try {
        visor.close();
      } catch (error) {
        /* Sin permiso para cerrar. */
      }
      descargar(blob, archivo);
      crearAlerta('exito', 'El visor no se abrió; el PDF se descargó.', 'PDF listo');
    }, 2000);

    window.setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 60000);

    return Promise.resolve();
  }

  /** Lee la respuesta como Blob y actualiza el avance. */
  function leerRespuesta(respuesta, destino) {
    const tipo = respuesta.headers.get('Content-Type') || '';
    if (tipo && tipo.indexOf('application/pdf') === -1 && tipo.indexOf('application/octet-stream') === -1) {
      return respuesta.text().then(function () {
        throw new Error('El servidor no devolvió un PDF. Puede que tu sesión haya caducado.');
      });
    }

    const total = Number(respuesta.headers.get('Content-Length') || 0);

    if (respuesta.body && typeof respuesta.body.getReader === 'function') {
      const lector = respuesta.body.getReader();
      const trozos = [];
      let recibido = 0;

      return new Promise(function (resolver, rechazar) {
        function siguiente() {
          lector.read().then(function (parte) {
            if (parte.done) {
              const blob = new Blob(trozos, { type: 'application/pdf' });
              if (!blob.size) {
                rechazar(new Error('El PDF vino vacío.'));
                return;
              }
              resolver(blob);
              return;
            }

            trozos.push(parte.value);
            recibido += parte.value.length;

            if (total > 0) {
              const pct = recibido / total * 100;
              fijarProgreso(pct, 'Descargando… ' + Math.round(pct) + ' %');
            } else {
              fijarProgreso(null, 'Descargando el documento…');
            }
            siguiente();
          }).catch(rechazar);
        }
        siguiente();
      });
    }

    return respuesta.blob().then(function (blob) {
      if (!blob || !blob.size) {
        throw new Error('El PDF vino vacío.');
      }
      return blob;
    });
  }

  /**
   * Exporta una URL y abre el resultado al terminar.
   *
   * @param {string} destino URL del PDF.
   * @param {{nombre?: string, titulo?: string, detalle?: string, method?: string,
   *          body?: BodyInit, headers?: Object, csrf?: boolean, abrir?: boolean}} opciones
   * @returns {Promise<boolean>}
   */
  function exportar(destino, opciones) {
    opciones = opciones || {};

    if (ocupado) {
      crearAlerta('info', 'Ya se está generando un PDF. Espera a que termine.', 'Exportación en curso');
      return Promise.resolve(false);
    }

    if (!iniciarExterno({
      nombre: opciones.nombre || '',
      titulo: opciones.titulo || 'Generando PDF…',
      detalle: opciones.detalle || 'El servidor está preparando el documento.',
      abrir: opciones.abrir !== false
    })) {
      return Promise.resolve(false);
    }

    controlador = new AbortController();
    const cabeceras = Object.assign({ Accept: 'application/pdf' }, opciones.headers || {});
    if (opciones.csrf) {
      const meta = document.querySelector('meta[name="csrf"]');
      cabeceras['X-CSRF-Token'] = meta ? meta.getAttribute('content') : '';
    }
    let nombreServidor = '';

    return fetch(destino, {
      method: opciones.method || 'GET',
      body: opciones.body,
      headers: cabeceras,
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controlador.signal
    }).then(function (respuesta) {
      if (!respuesta.ok) {
        throw new Error('No se pudo generar el PDF (error ' + respuesta.status + ').');
      }
      nombreServidor = nombreDeCabecera(respuesta.headers.get('Content-Disposition'), destino);
      return leerRespuesta(respuesta, destino);
    }).then(function (blob) {
      const nombre = nombrePdf(opciones.nombre || nombreServidor);
      return abrirResultado(blob, nombre);
    }).then(function () {
      terminarEstado();
      return true;
    }).catch(function (error) {
      if (error && error.name === 'AbortError') {
        return false;
      }
      if (ventanaPdf && !ventanaPdf.closed) {
        try {
          ventanaPdf.close();
        } catch (e) {
          /* Sin permiso para cerrar. */
        }
      }
      crearAlerta(
        'error',
        (error && error.message) ? error.message : 'No se pudo generar el PDF. Inténtalo de nuevo.',
        'No se pudo exportar',
        true
      );
      terminarEstado();
      return false;
    });
  }

  /** Inicia la capa para que el Editor/Redactor la use con su propio fetch. */
  function iniciarExterno(opciones) {
    opciones = opciones || {};

    if (ocupado) {
      crearAlerta('info', 'Ya se está generando un PDF. Espera a que termine.', 'Exportación en curso');
      return false;
    }

    ocupado = true;
    abrirAlTerminar = opciones.abrir !== false;
    ventanaPdf = abrirAlTerminar ? abrirVentana() : null;
    mostrarCapa(opciones.titulo || 'Generando PDF…', opciones.detalle || 'El servidor está preparando el documento.');
    return true;
  }

  /** Finaliza una exportación que el Editor/Redactor generó por su cuenta. */
  function terminarExterno(blob, nombre) {
    if (!ocupado) {
      return Promise.resolve(false);
    }
    if (!blob || !blob.size) {
      errorExterno('El PDF vino vacío.');
      return Promise.resolve(false);
    }
    return abrirResultado(blob, nombre).then(function () {
      terminarEstado();
      return true;
    }).catch(function () {
      errorExterno('No se pudo abrir el PDF.');
      return false;
    });
  }

  /** Muestra un error y cierra la capa. */
  function errorExterno(mensaje) {
    if (ventanaPdf && !ventanaPdf.closed) {
      try {
        ventanaPdf.close();
      } catch (error) {
        /* Sin permiso para cerrar. */
      }
    }
    crearAlerta('error', mensaje || 'No se pudo generar el PDF.', 'No se pudo exportar', true);
    terminarEstado();
  }

  /** Cancela la exportación en curso. */
  function cancelar() {
    if (!ocupado) {
      return;
    }

    if (controlador) {
      controlador.abort();
    }
    if (ventanaPdf && !ventanaPdf.closed) {
      try {
        ventanaPdf.close();
      } catch (error) {
        /* Sin permiso para cerrar. */
      }
    }

    terminarEstado();
    crearAlerta('info', 'Exportación cancelada.', 'PDF');
  }

  /** Delegación de clics: cualquier enlace PDF pasa por el mismo flujo. */
  function manejarClic(evento) {
    if (evento.defaultPrevented || evento.button !== 0
      || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
      return;
    }

    const enlace = evento.target instanceof Element ? evento.target.closest('a[href]') : null;
    if (!enlace || !esPdf(enlace.href)) {
      return;
    }

    evento.preventDefault();
    evento.stopPropagation();

    exportar(enlace.href, {
      nombre: enlace.getAttribute('data-pdf-nombre') || '',
      titulo: 'Generando PDF…',
      detalle: 'El servidor está preparando el documento.'
    });
  }

  window.PdfUI = {
    exportar: exportar,
    descargarUrl: exportar,
    iniciar: iniciarExterno,
    progreso: function (texto, pct) {
      if (ocupado) {
        fijarProgreso(typeof pct === 'number' ? pct : null, texto || '');
      }
    },
    terminar: terminarExterno,
    error: errorExterno,
    cancelar: cancelar,
    activo: function () {
      return ocupado;
    }
  };

  window.prepararExportacionPdf = preparar;
})();
