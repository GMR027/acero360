# Acero 360 para Android (Rust + Tauri 2)

Ventana segura hacia la plataforma (igual que la app de escritorio): una pantalla local
(`ui/index.html`) revisa `GET /app/android/version.json`, ofrece actualizar si hay una versión
mayor y luego abre el sitio en el WebView pasando por `/app/android/entrar?v=…&id=…` (cookie con
la versión instalada → tabla `dispositivos_android`, visible en Ajustes → Aplicación Android).

## Requisitos (ya instalados en este equipo)
Rust + `rustup target add aarch64-linux-android`, `cargo install tauri-cli --version "^2"`,
Android SDK (`~/Android/Sdk`), NDK 27, JDK 17.

## Compilar y publicar
1. Sube `"version"` en `src-tauri/tauri.conf.json` (Android exige un número mayor para actualizar).
2. `./compilar.sh` → `dist/acero360-android_X.Y.Z.apk` firmado (≈6 MB, arm64). `--instalar` lo instala por ADB.
3. En el servidor: `php scripts/publicar_android.php android/dist/acero360-android_X.Y.Z.apk X.Y.Z "notas"`.

**Llave de firma:** `~/.config/acero360-android/` (fuera del repo). Respáldala: sin ella no se pueden
publicar actualizaciones que se instalen sobre la app existente.

## Desarrollo en el teléfono
`adb connect IP:PUERTO`, `./compilar.sh --instalar`. Para probar contra un servidor local:
`adb reverse tcp:8090 tcp:8090` y en la app «Cambiar servidor» → `http://localhost:8090`
(HTTP plano solo se permite hacia localhost; todo lo demás exige HTTPS).

## Qué hace el código nativo
- `src-tauri/src/lib.rs`: política de navegación (solo el servidor elegido dentro de la app; mapas,
  WhatsApp, tel: y sitios externos se abren con la app del sistema) y comandos `fijar_servidor` y
  `abrir_actualizacion`. Las páginas del servidor no tienen acceso a Rust.
- `gen/android/.../MainActivity.kt`: márgenes de borde a borde (Android 15+) y descargas
  (PDF/Excel/CSV) al gestor de descargas con la cookie de sesión.
- `gen/android/.../AvisosAndroid.kt`: avisos del Centro de seguimiento como notificaciones del
  teléfono. WorkManager consulta `GET /app/android/avisos.json` cada ~15 min con una llave de solo
  lectura (`llaves_avisos_android`); suenan con el tono de notificación del teléfono o con uno
  elegido en Ajustes → Aplicación Android (cada cambio de tono crea un canal nuevo `avisos_N`).
- `gen/android/app/build.gradle.kts`: firma de publicación; `res/xml/network_security_config.xml`.
