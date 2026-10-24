# Acero 360 · aplicación de escritorio (Linux)

> Los identificadores técnicos (paquete `cotizaciones-escritorio`, carpeta `~/.config/Cotizaciones`, `COTIZACIONES_URL`) se conservan para que las instalaciones existentes se actualicen sin perder sesión.

Ventana de escritorio segura hacia la plataforma web. **No tiene base de datos ni servicios propios**: usa los
mismos datos y la misma sesión que la web, así que web y escritorio nunca divergen.

## Qué debe seguir disponible en el servidor
Aplicación PHP + MySQL, almacén de documentos fiscales (`storage/documentos`) y generación de PDF/Excel/ZIP.
Electron no convierte el backend en una aplicación local: sin servidor no hay datos.

## Seguridad
- `contextIsolation` activo, `nodeIntegration` desactivada, `sandbox` activo, `webSecurity` activa, DevTools desactivadas.
- Puente (`preload.js`) con solo 3 funciones: `reintentar`, `informacion`, `abrirAyuda`. La página no tiene `require` ni `process`.
- Solo HTTPS (HTTP únicamente hacia localhost). La ventana nunca sale del origen de la plataforma; los enlaces externos
  (solo http/https) se abren en el navegador del sistema; `file:`, `javascript:` y similares se ignoran.
- Todos los permisos del navegador (cámara, micrófono, ubicación, notificaciones) están denegados; los certificados inválidos se rechazan.
- Sin credenciales ni secretos en el paquete. El usuario inicia sesión en la web; las cookies viven en la partición persistente
  de Chromium (`~/.config/Cotizaciones`).
- Si no hay conexión se muestra una pantalla clara con botón «Reintentar» (nunca una ventana en blanco).

## Configuración (producción independiente de desarrollo)
Dirección de la plataforma, en este orden: variable `COTIZACIONES_URL` → `~/.config/Cotizaciones/config.json` (`{"url":"https://…"}`) →
producción `https://cotizaciones.iguzman.com.mx`. Una dirección inválida o sin HTTPS se rechaza y se usa producción.
Desarrollo: `COTIZACIONES_URL=http://127.0.0.1:8017 COTIZACIONES_DEV=1 npm start` (DEV habilita las DevTools).

## Generar el paquete
```bash
cd desktop
npm install
node node_modules/electron/install.js      # descarga el binario de Electron (npm no ejecuta el script solo)
npm test                                   # pruebas unitarias de las reglas de seguridad
npm run empaquetar                         # → dist/cotizaciones-escritorio_<versión>_amd64.deb
```
La versión sale de `desktop/package.json`. Súbela antes de generar un paquete nuevo.

## Instalar, actualizar y desinstalar
```bash
sudo apt install ./cotizaciones-escritorio_1.0.0_amd64.deb     # instalar (apt resuelve dependencias)
sudo apt install ./cotizaciones-escritorio_1.0.1_amd64.deb     # actualizar: reemplaza la versión anterior
sudo apt remove cotizaciones-escritorio                         # desinstalar
rm -r ~/.config/Cotizaciones                                    # opcional: borra cookies y datos locales
```
Dependencias declaradas (con alternativas `t64` de Ubuntu 24.04): libgtk-3, libnotify4, libnss3, libxss1, libxtst6, xdg-utils, libatspi2.0,
libuuid1, libsecret-1, libasound2, libgbm1, libdrm2, libxkbcommon0, libcups2.

## Publicar en la plataforma (el administrador lo descarga en «App de escritorio»)
En el servidor: `php scripts/publicar_escritorio.php dist/cotizaciones-escritorio_1.0.0_amd64.deb 1.0.0 amd64 "notas"`.
Registra versión, arquitectura, tamaño y SHA-256; una versión publicada no se sobrescribe. La descarga es solo para administradores
autenticados y verifica el SHA-256 antes de entregar. Una versión defectuosa se **retira** con motivo (se conserva en el registro).
**Esto no despliega nada en el clúster**: el paquete (~100 MB) se copia al almacén de documentos del servidor.

## Verificación hecha (paquete 1.0.0, SHA-256 `6d885ddce8a3d4c2fa309eaa2658adfd359fb408bfbfd678076d8ab6ef66ce7c`)
- 8 pruebas unitarias (`npm test`) y 28 pruebas de integración con Electron real (`node test/integracion.mjs`, con el servidor de pruebas).
- Instalación limpia, arranque y desinstalación en contenedores **Debian 12** y **Ubuntu 24.04** (con Xvfb y `--no-sandbox`, por correr como root).
- Los contenedores destaparon dos errores reales de dependencias (`libasound2`), ya corregidos.

## Limitaciones conocidas
- El paquete no está firmado ni hay repositorio apt: no se actualiza solo; se instala la versión nueva a mano.
- Solo Linux amd64. No se probó en pantallas HiDPI ni con lectores de pantalla.
- La prueba de arranque en contenedor usa `--no-sandbox` (root); en un escritorio normal el sandbox de Chromium sí está activo (verificado en la prueba de integración).
