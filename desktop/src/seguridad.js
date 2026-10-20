/**
 * seguridad.js — reglas de navegación y configuración, sin dependencias de Electron
 * (para poder probarlas con `node --test`).
 *
 * Principios:
 *   • Solo se carga la plataforma por HTTPS. HTTP únicamente hacia localhost (desarrollo y pruebas).
 *   • La ventana nunca navega a otro origen: los enlaces externos se abren en el navegador del sistema (solo http/https).
 *   • No hay credenciales, secretos ni claves en esta aplicación: el usuario inicia sesión en la web como siempre.
 */

const URL_PRODUCCION = 'https://cotizaciones.iguzman.com.mx';

/** ¿Es un host local? (permite HTTP solo para desarrollo). */
function esLocal(host) {
  return host === 'localhost' || host === '127.0.0.1' || host === '[::1]';
}

/**
 * Valida y normaliza la URL de la plataforma.
 * @returns {{ok: boolean, url?: string, origen?: string, error?: string}}
 */
function validarUrlBase(texto) {
  let url;
  try {
    url = new URL(String(texto).trim());
  } catch (error) {
    return { ok: false, error: 'La dirección de la plataforma no es una URL válida.' };
  }

  if (url.username || url.password) {
    return { ok: false, error: 'La dirección no debe incluir usuario ni contraseña.' };
  }
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && esLocal(url.hostname))) {
    return { ok: false, error: 'La plataforma debe usar HTTPS (HTTP solo se permite hacia localhost).' };
  }

  return { ok: true, url: url.origin + '/', origen: url.origin };
}

/** ¿La URL pertenece al origen de la plataforma? */
function mismoOrigen(destino, origenBase) {
  try {
    return new URL(destino).origin === origenBase;
  } catch (error) {
    return false;
  }
}

/** ¿Se puede abrir en el navegador del sistema? (nunca file:, javascript:, data:, etc.). */
function esExternoSeguro(destino) {
  try {
    const protocolo = new URL(destino).protocol;
    return protocolo === 'https:' || protocolo === 'http:';
  } catch (error) {
    return false;
  }
}

/** Opciones de webPreferences que NO deben cambiar (las verifica una prueba). */
const PREFERENCIAS_SEGURAS = Object.freeze({
  contextIsolation: true,
  nodeIntegration: false,
  nodeIntegrationInWorker: false,
  nodeIntegrationInSubFrames: false,
  sandbox: true,
  webSecurity: true,
  allowRunningInsecureContent: false,
  experimentalFeatures: false,
  enableBlinkFeatures: '',
  spellcheck: true,
});

module.exports = { URL_PRODUCCION, esLocal, validarUrlBase, mismoOrigen, esExternoSeguro, PREFERENCIAS_SEGURAS };
