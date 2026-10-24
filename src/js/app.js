/**
 * app.js — Punto de entrada del JavaScript de la aplicación.
 *
 * Reglas del proyecto aplicadas aquí:
 *  - sólo let y const (nunca var)
 *  - sin alert(), confirm() ni prompt()
 *  - sin innerHTML: se usa document.createElement + appendChild
 *  - se previene el comportamiento por defecto en todo submit
 *
 * El archivo se concatena con los módulos de src/js/modulos/, así que
 * toda la inicialización se difiere a DOMContentLoaded para que el orden
 * de concatenación no influya.
 */

/**
 * Alterna la navegación lateral (cajón) en pantallas pequeñas.
 * Mantiene sincronizado el atributo aria-expanded del botón que lo abre.
 */
function alternarSidebar() {
  const sidebar = document.getElementById('sidebar');
  const fondo = document.getElementById('fondo-sidebar');
  const disparador = document.getElementById('boton-menu');

  if (!sidebar) {
    return;
  }

  const abierto = sidebar.classList.toggle('sidebar--abierto');

  if (fondo) {
    fondo.hidden = !abierto;
  }

  if (disparador) {
    disparador.setAttribute('aria-expanded', abierto ? 'true' : 'false');
  }

  document.body.style.overflow = abierto ? 'hidden' : '';
}

/** Cierra la navegación lateral si está desplegada. */
function cerrarSidebar() {
  const sidebar = document.getElementById('sidebar');
  const fondo = document.getElementById('fondo-sidebar');
  const disparador = document.getElementById('boton-menu');

  if (!sidebar) {
    return;
  }

  sidebar.classList.remove('sidebar--abierto');

  if (fondo) {
    fondo.hidden = true;
  }

  if (disparador) {
    disparador.setAttribute('aria-expanded', 'false');
  }

  document.body.style.overflow = '';
}

/**
 * Pliega o despliega el menú lateral en ESCRITORIO (botón flotante).
 * No toca el cajón de móvil: ése lo gobierna alternarSidebar().
 * La preferencia se guarda en localStorage y la aplica el guion inline de
 * templates/sidebar.php, para que no haya parpadeo al recargar.
 */
function alternarMenuEscritorio() {
  const sidebar = document.getElementById('sidebar');
  const boton = document.getElementById('sidebar-toggle');

  if (!sidebar || !boton) {
    return;
  }

  const plegado = sidebar.classList.toggle('sidebar--plegado');
  boton.classList.toggle('sidebar__toggle--plegado', plegado);
  boton.setAttribute('aria-expanded', plegado ? 'false' : 'true');

  const etiqueta = plegado ? 'Mostrar el menú lateral' : 'Ocultar el menú lateral';
  boton.setAttribute('aria-label', etiqueta);
  boton.setAttribute('title', etiqueta);

  try {
    window.localStorage.setItem('a360_menu_plegado', plegado ? '1' : '0');
  } catch (e) {
    /* Almacenamiento no disponible: el estado vale sólo para esta sesión. */
  }
}

/** Abre o cierra la hoja "Nueva venta / Nueva cotización" de la barra inferior. */
function alternarHojaNuevo(forzarAbierta) {
  const hoja = document.getElementById('hoja-nuevo');
  const fondo = document.getElementById('fondo-nuevo');
  const disparador = document.getElementById('boton-nuevo');

  if (!hoja) {
    return;
  }

  const abrir = typeof forzarAbierta === 'boolean' ? forzarAbierta : hoja.hidden;
  hoja.hidden = !abrir;

  if (fondo) {
    fondo.hidden = !abrir;
  }

  if (disparador) {
    disparador.setAttribute('aria-expanded', abrir ? 'true' : 'false');
  }
}

/**
 * Intercepta el envío de los formularios marcados con data-validar:
 * valida en cliente y, sólo si todo está correcto, permite el envío.
 *
 * @param {SubmitEvent} evento
 */
function manejarEnvioFormulario(evento) {
  const formulario = evento.target;

  if (!(formulario instanceof HTMLFormElement)) {
    return;
  }

  if (!validarFormulario(formulario)) {
    evento.preventDefault();
    return;
  }

  // Formularios con confirmación previa (borrar, cancelar, etc.).
  if (formulario.hasAttribute('data-confirmacion')) {
    evento.preventDefault();

    confirmarAccion({
      sobreTitulo: formulario.getAttribute('data-confirmacion-sobre') || 'Confirmación',
      titulo: formulario.getAttribute('data-confirmacion') || '¿Deseas continuar?',
      mensaje: formulario.getAttribute('data-confirmacion-mensaje') || 'Se aplicarán los cambios indicados.',
      textoConfirmar: formulario.getAttribute('data-confirmacion-boton') || 'Confirmar',
      alConfirmar: function () {
        bloquearEnvio(formulario);
        formulario.submit(); // submit() nativo: no vuelve a disparar el evento.
      },
    });
    return;
  }

  // Sin confirmación: se deja que el navegador envíe y, después, se bloquea el
  // botón (si se hiciera antes se perdería su name/value) para evitar el doble envío.
  if (formulario.method.toLowerCase() === 'post') {
    setTimeout(function () {
      if (!evento.defaultPrevented) {
        bloquearEnvio(formulario);
      }
    }, 0);
  }
}

/**
 * Inicializa todos los comportamientos de la página una vez cargado el DOM.
 */
function inicializarAplicacion() {
  // 1. Alertas que envió el servidor (redirecciones con ?st=... o flash).
  prepararAlertasDelServidor();

  // 2. Navegación lateral (móvil / tablet).
  const botonMenu = document.getElementById('boton-menu');
  if (botonMenu) {
    botonMenu.addEventListener('click', function (evento) {
      evento.preventDefault();
      alternarSidebar();
    });
  }

  const botonNuevo = document.getElementById('boton-nuevo');
  if (botonNuevo) {
    botonNuevo.addEventListener('click', function (evento) {
      evento.preventDefault();
      alternarHojaNuevo();
    });
  }

  const fondoNuevo = document.getElementById('fondo-nuevo');
  if (fondoNuevo) {
    fondoNuevo.addEventListener('click', function () {
      alternarHojaNuevo(false);
    });
  }

  document.addEventListener('keydown', function (evento) {
    if (evento.key === 'Escape') {
      alternarHojaNuevo(false);
    }
  });

  const fondoSidebar = document.getElementById('fondo-sidebar');
  if (fondoSidebar) {
    fondoSidebar.addEventListener('click', function () {
      cerrarSidebar();
    });
  }

  // Botón flotante de escritorio: pliega/despliega el menú para ganar espacio.
  const botonToggleMenu = document.getElementById('sidebar-toggle');
  if (botonToggleMenu) {
    botonToggleMenu.addEventListener('click', function () {
      alternarMenuEscritorio();
    });
  }

  // Los enlaces del menú cierran el panel en pantallas pequeñas.
  const enlacesSidebar = document.querySelectorAll('.sidebar__enlace');
  enlacesSidebar.forEach(function (enlace) {
    enlace.addEventListener('click', function () {
      if (window.matchMedia('(max-width: 767px)').matches) {
        cerrarSidebar();
      }
    });
  });

  // 3. Modal: clics y tecla Escape.
  document.addEventListener('click', manejarInteraccionModal);
  document.addEventListener('keydown', function (evento) {
    if (evento.key === 'Escape') {
      cerrarModal();
      cerrarSidebar();
    }
  });

  // 4. Envío de formularios: validación y prevención del comportamiento por defecto.
  document.addEventListener('submit', manejarEnvioFormulario);

  // Al volver con «Atrás» (caché del navegador) los botones no deben quedar bloqueados.
  window.addEventListener('pageshow', function (evento) {
    if (!evento.persisted) {
      return;
    }
    document.querySelectorAll('button[data-texto-original]').forEach(function (boton) {
      boton.textContent = boton.getAttribute('data-texto-original');
      boton.removeAttribute('data-texto-original');
      boton.disabled = false;
    });
  });

  // 5. Carga de imágenes con vista previa.
  const camposImagen = document.querySelectorAll('input[type="file"][data-preview]');
  camposImagen.forEach(function (input) {
    const contenedor = input.closest('.carga-imagen');
    if (contenedor) {
      prepararCargaImagen(input, contenedor);
    }
  });

  // 6. Panel de ganancias: mes y semestre sólo aplican con un año concreto.
  const filtroAnio = document.getElementById('filtro-anio');
  if (filtroAnio) {
    const sincronizarPeriodo = function () {
      const hayAnio = filtroAnio.value !== '';

      ['filtro-semestre', 'filtro-mes'].forEach(function (identificador) {
        const campo = document.getElementById(identificador);
        if (!campo) {
          return;
        }

        campo.disabled = !hayAnio;
        if (!hayAnio) {
          campo.selectedIndex = 0;
        }
      });
    };

    filtroAnio.addEventListener('change', sincronizarPeriodo);
    sincronizarPeriodo();
  }

  // 8. Miniaturas de producto: alternativa si falla la carga y ampliación.
  inicializarMiniaturasProducto();

  // 7. Botones de borrado que usan confirmación sin <form> (delegación).
  document.addEventListener('click', function (evento) {
    const boton = evento.target instanceof Element
      ? evento.target.closest('[data-confirmar-accion]')
      : null;

    if (!boton) {
      return;
    }

    evento.preventDefault();

    confirmarAccion({
      sobreTitulo: boton.getAttribute('data-confirmar-sobre') || 'Confirmación',
      titulo: boton.getAttribute('data-confirmar-accion'),
      mensaje: boton.getAttribute('data-confirmar-mensaje') || 'Esta acción no se puede deshacer.',
      textoConfirmar: boton.getAttribute('data-confirmar-boton') || 'Eliminar',
      alConfirmar: function () {
        const destino = boton.getAttribute('data-url');
        if (destino) {
          window.location.href = destino;
        }
      },
    });
  });
}

// El arranque se difiere para que todos los módulos ya estén declarados.
document.addEventListener('DOMContentLoaded', inicializarAplicacion);
