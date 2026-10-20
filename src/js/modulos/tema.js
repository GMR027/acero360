/**
 * tema.js — Tema visual: claro, oscuro o automático (sigue al sistema operativo).
 *
 * El servidor pinta <html data-tema="..."> con la preferencia guardada (cookie a360_tema o la del usuario).
 * Aquí se aplica al elegirla en Ajustes → Apariencia, se recuerda en cookie + localStorage y se avisa a
 * quien dibuja gráficas con el evento «tema:cambio» para que adapten su paleta.
 */
const TEMAS_VALIDOS = ['claro', 'oscuro', 'auto'];
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
  document.dispatchEvent(new CustomEvent('tema:cambio', { detail: { tema: document.documentElement.getAttribute('data-tema'), efectivo: temaEfectivo() } }));
}

function aplicarTema(valor) {
  const tema = TEMAS_VALIDOS.indexOf(valor) >= 0 ? valor : 'auto';
  document.documentElement.setAttribute('data-tema', tema);
  try {
    document.cookie = 'a360_tema=' + tema + ';path=/;max-age=31536000;SameSite=Lax';
    window.localStorage.setItem('a360_tema', tema);
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
    selector.querySelectorAll('input[name="tema"]').forEach(function (opcion) {
      opcion.addEventListener('change', function () {
        aplicarTema(opcion.value);
        if (!token) {
          return;
        }
        const datos = new FormData();
        datos.append('tema', opcion.value);
        datos.append('token', token.value);
        const meta = document.querySelector('meta[name="csrf"]');
        fetch('/admin/ajustes/tema', { method: 'POST', body: datos, credentials: 'same-origin', headers: { 'X-CSRF-Token': meta ? meta.content : '' } })
          .then(function (respuesta) { return respuesta.json(); })
          .then(function (cuerpo) {
            token.value = cuerpo.token || token.value;
            if (estado) {
              estado.textContent = cuerpo.ok ? 'Tema guardado.' : (cuerpo.mensaje || 'No se pudo guardar.');
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
