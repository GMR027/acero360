/**
 * tema.js — Apariencia: tema visual (claro, oscuro o automático, que sigue al sistema operativo) y
 * color de acento (azul, verde, violeta, teja, grafito o turquesa). Los dos se combinan.
 *
 * El servidor pinta <html data-tema="..." data-color="..."> con las preferencias guardadas (cookies
 * a360_tema y a360_color o las del usuario). Aquí se aplican al elegirlas en Ajustes → Apariencia, se
 * recuerdan en cookie + localStorage y se avisa a quien dibuja gráficas con el evento «tema:cambio»
 * para que adapten su paleta.
 */
const TEMAS_VALIDOS = ['claro', 'oscuro', 'auto'];
const COLORES_VALIDOS = [
  'azul', 'verde', 'violeta', 'teja', 'grafito', 'turquesa', 'indigo', 'cobalto',
  'cielo', 'menta', 'oliva', 'musgo', 'mostaza', 'ambar', 'coral', 'rosa',
  'ciruela', 'vino', 'cacao'
];
const CONSULTA_OSCURO = '(prefers-color-scheme: dark)';

/** Tema realmente visible ahora: «claro» u «oscuro» (resuelve «auto» con el sistema). */
function temaEfectivo() {
  const elegido = document.documentElement.getAttribute('data-tema');
  if (elegido === 'claro' || elegido === 'oscuro') {
    return elegido;
  }
  return window.matchMedia(CONSULTA_OSCURO).matches ? 'oscuro' : 'claro';
}

function avisarCambioDeTema() {
  document.dispatchEvent(new CustomEvent('tema:cambio', {
    detail: {
      tema: document.documentElement.getAttribute('data-tema'),
      color: document.documentElement.getAttribute('data-color'),
      efectivo: temaEfectivo()
    }
  }));
}

function elegido(nombre, validos, defecto) {
  const opcion = document.querySelector('input[name="' + nombre + '"]:checked');
  return opcion && validos.indexOf(opcion.value) >= 0 ? opcion.value : defecto;
}

/** Aplica tema y color a la vez; lo que no venga usa lo que ya está puesto. */
function aplicarApariencia(tema, color) {
  const raiz = document.documentElement;
  if (tema) {
    raiz.setAttribute('data-tema', TEMAS_VALIDOS.indexOf(tema) >= 0 ? tema : 'auto');
  }
  if (color) {
    raiz.setAttribute('data-color', COLORES_VALIDOS.indexOf(color) >= 0 ? color : 'azul');
  }
  try {
    document.cookie = 'a360_tema=' + raiz.getAttribute('data-tema') + ';path=/;max-age=31536000;SameSite=Lax';
    document.cookie = 'a360_color=' + raiz.getAttribute('data-color') + ';path=/;max-age=31536000;SameSite=Lax';
    window.localStorage.setItem('a360_tema', raiz.getAttribute('data-tema'));
    window.localStorage.setItem('a360_color', raiz.getAttribute('data-color'));
  } catch (error) {
    // Sin almacenamiento disponible: el cambio sigue valiendo en esta página.
  }
  avisarCambioDeTema();
}

function iniciarSelectorDeTema() {
  const selector = document.querySelector('[data-selector-tema]');
  const estado = document.querySelector('[data-estado-tema]');
  const token = selector ? selector.querySelector('input[name="token"]') : null;

  if (selector) {
    selector.querySelectorAll('input[name="tema"], input[name="color"]').forEach(function (opcion) {
      opcion.addEventListener('change', function () {
        const tema = elegido('tema', TEMAS_VALIDOS, 'auto');
        const color = elegido('color', COLORES_VALIDOS, 'azul');
        aplicarApariencia(tema, color);
        if (!token) {
          return;
        }
        const datos = new FormData();
        datos.append('tema', tema);
        datos.append('color', color);
        datos.append('token', token.value);
        const meta = document.querySelector('meta[name="csrf"]');
        fetch('/admin/ajustes/tema', { method: 'POST', body: datos, credentials: 'same-origin', headers: { 'X-CSRF-Token': meta ? meta.content : '' } })
          .then(function (respuesta) { return respuesta.json(); })
          .then(function (cuerpo) {
            token.value = cuerpo.token || token.value;
            if (estado) {
              estado.textContent = cuerpo.ok ? 'Tema y color guardados.' : (cuerpo.mensaje || 'No se pudo guardar.');
            }
          })
          .catch(function () {
            if (estado) {
              estado.textContent = 'Se aplicó en este navegador, pero no se pudo guardar en tu usuario.';
            }
          });
      });
    });
  }

  // En «auto», si el sistema cambia de claro a oscuro, la página y las gráficas lo siguen.
  const sistema = window.matchMedia(CONSULTA_OSCURO);
  const alCambiarSistema = function () {
    if (document.documentElement.getAttribute('data-tema') === 'auto') {
      avisarCambioDeTema();
    }
  };
  if (sistema.addEventListener) {
    sistema.addEventListener('change', alCambiarSistema);
  }
}

document.addEventListener('DOMContentLoaded', iniciarSelectorDeTema);
