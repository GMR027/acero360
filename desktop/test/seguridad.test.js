const test = require('node:test');
const assert = require('node:assert/strict');
const { validarUrlBase, mismoOrigen, esExternoSeguro, PREFERENCIAS_SEGURAS, URL_PRODUCCION } = require('../src/seguridad');

test('acepta HTTPS y normaliza al origen', () => {
  const r = validarUrlBase('https://cotizaciones.iguzman.com.mx/admin?x=1');
  assert.equal(r.ok, true);
  assert.equal(r.url, 'https://cotizaciones.iguzman.com.mx/');
  assert.equal(r.origen, 'https://cotizaciones.iguzman.com.mx');
});

test('rechaza HTTP hacia servidores remotos', () => {
  assert.equal(validarUrlBase('http://cotizaciones.iguzman.com.mx').ok, false);
  assert.equal(validarUrlBase('http://192.168.1.10').ok, false);
});

test('permite HTTP solo hacia localhost (desarrollo y pruebas)', () => {
  assert.equal(validarUrlBase('http://127.0.0.1:8017').ok, true);
  assert.equal(validarUrlBase('http://localhost:8000').ok, true);
});

test('rechaza esquemas peligrosos, URLs inválidas y credenciales embebidas', () => {
  for (const mala of ['file:///etc/passwd', 'javascript:alert(1)', 'data:text/html,hola', 'ftp://x.com', 'no es url', '', 'https://usuario:clave@sitio.com']) {
    assert.equal(validarUrlBase(mala).ok, false, mala);
  }
});

test('mismoOrigen distingue subdominios, puertos y esquemas', () => {
  const origen = 'https://cotizaciones.iguzman.com.mx';
  assert.equal(mismoOrigen('https://cotizaciones.iguzman.com.mx/admin', origen), true);
  assert.equal(mismoOrigen('https://iguzman.com.mx/', origen), false);
  assert.equal(mismoOrigen('https://cotizaciones.iguzman.com.mx.evil.com/', origen), false);
  assert.equal(mismoOrigen('http://cotizaciones.iguzman.com.mx/', origen), false);
  assert.equal(mismoOrigen('https://cotizaciones.iguzman.com.mx:8443/', origen), false);
  assert.equal(mismoOrigen('no es url', origen), false);
});

test('solo http y https se abren fuera de la aplicación', () => {
  assert.equal(esExternoSeguro('https://www.sat.gob.mx'), true);
  assert.equal(esExternoSeguro('http://ejemplo.com'), true);
  for (const peligrosa of ['file:///etc/passwd', 'javascript:alert(1)', 'smb://servidor/recurso', 'data:text/html,x']) {
    assert.equal(esExternoSeguro(peligrosa), false, peligrosa);
  }
});

test('las preferencias seguras de la ventana no se relajan', () => {
  assert.equal(PREFERENCIAS_SEGURAS.contextIsolation, true);
  assert.equal(PREFERENCIAS_SEGURAS.nodeIntegration, false);
  assert.equal(PREFERENCIAS_SEGURAS.nodeIntegrationInWorker, false);
  assert.equal(PREFERENCIAS_SEGURAS.nodeIntegrationInSubFrames, false);
  assert.equal(PREFERENCIAS_SEGURAS.sandbox, true);
  assert.equal(PREFERENCIAS_SEGURAS.webSecurity, true);
  assert.equal(PREFERENCIAS_SEGURAS.allowRunningInsecureContent, false);
  assert.throws(() => { 'use strict'; PREFERENCIAS_SEGURAS.nodeIntegration = true; }, TypeError);
});

test('la dirección de producción es HTTPS', () => {
  assert.equal(validarUrlBase(URL_PRODUCCION).ok, true);
  assert.ok(URL_PRODUCCION.startsWith('https://'));
});
