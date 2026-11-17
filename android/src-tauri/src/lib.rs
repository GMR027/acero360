//! Acero 360 para Android (Tauri 2).
//!
//! La app es una ventana hacia la plataforma web: la pantalla local `ui/index.html` revisa la
//! versión publicada en el servidor y después abre el sitio dentro del WebView. Este módulo:
//!   • crea la ventana principal con una política de navegación: solo el servidor elegido y la
//!     pantalla local se abren dentro de la app; cualquier otro enlace (mapas, WhatsApp, tel:,
//!     correo, sitios externos) se abre con la aplicación del sistema;
//!   • expone `fijar_servidor`, que la pantalla local llama antes de navegar, para que la política
//!     sepa cuál es el servidor de confianza.
//! Las páginas del servidor NO tienen acceso a los comandos de Rust (la capacidad es solo local).

use std::sync::Mutex;

use tauri::{Manager, WebviewUrl, WebviewWindowBuilder};
use tauri_plugin_opener::OpenerExt;
use url::Url;

/// Origen (esquema://host:puerto) del servidor de confianza.
#[derive(Default)]
struct Servidor(Mutex<Option<String>>);

/// Un servidor válido: HTTPS siempre; HTTP solo hacia localhost (pruebas con `adb reverse`;
/// la configuración de red de Android bloquea cualquier otro HTTP plano).
fn origen_valido(texto: &str) -> Result<String, String> {
    let url = Url::parse(texto.trim()).map_err(|_| "La dirección no es válida.".to_string())?;
    let host = url.host_str().ok_or("La dirección no tiene servidor.")?.to_string();
    match url.scheme() {
        "https" => {}
        "http" if es_local(&host) => {}
        _ => return Err("Usa una dirección https:// (http solo se permite para localhost).".into()),
    }
    Ok(url.origin().ascii_serialization())
}

fn es_local(host: &str) -> bool {
    host == "localhost" || host == "127.0.0.1"
}

/// La pantalla local avisa a qué servidor va a entrar (se valida aquí, no en JavaScript).
#[tauri::command]
fn fijar_servidor(url: String, estado: tauri::State<'_, Servidor>) -> Result<String, String> {
    let origen = origen_valido(&url)?;
    *estado.0.lock().unwrap() = Some(origen.clone());
    Ok(origen)
}

/// Abre la descarga del APK nuevo con el navegador del sistema (Android pide confirmar la
/// instalación). Solo acepta la ruta de descarga del servidor de confianza.
#[tauri::command]
fn abrir_actualizacion(url: String, app: tauri::AppHandle, estado: tauri::State<'_, Servidor>) -> Result<(), String> {
    let destino = Url::parse(&url).map_err(|_| "Dirección de descarga inválida.".to_string())?;
    let servidor = estado.0.lock().unwrap().clone();
    if servidor.as_deref() != Some(destino.origin().ascii_serialization().as_str()) || destino.path() != "/app/android/descargar" {
        return Err("La descarga no viene del servidor de confianza.".into());
    }
    app.opener().open_url(destino.as_str(), None::<&str>).map_err(|e| e.to_string())
}

/// ¿Se abre dentro de la app? La pantalla local, el servidor de confianza y recursos internos.
fn navegacion_interna(url: &Url, servidor: Option<&str>) -> bool {
    match url.scheme() {
        "about" | "data" | "blob" | "tauri" | "asset" => return true,
        "http" | "https" => {}
        _ => return false,
    }
    if url.host_str() == Some("tauri.localhost") {
        return true;
    }
    servidor.is_some_and(|s| url.origin().ascii_serialization() == s)
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .manage(Servidor::default())
        .invoke_handler(tauri::generate_handler![fijar_servidor, abrir_actualizacion])
        .setup(|app| {
            let handle = app.handle().clone();
            let builder = WebviewWindowBuilder::new(app, "main", WebviewUrl::App("index.html".into()))
                .on_navigation(move |url| {
                    let servidor = handle.state::<Servidor>().0.lock().unwrap().clone();
                    if navegacion_interna(url, servidor.as_deref()) {
                        return true;
                    }
                    // Enlace externo: lo abre la aplicación del sistema (Chrome, Maps, WhatsApp…).
                    // Fuera del hilo de la interfaz: el plugin de Android no responde si se llama desde aquí.
                    let (h, destino) = (handle.clone(), url.to_string());
                    std::thread::spawn(move || {
                        if let Err(e) = h.opener().open_url(destino, None::<&str>) {
                            eprintln!("no se pudo abrir el enlace externo: {e}");
                        }
                    });
                    false
                });
            #[cfg(desktop)]
            let builder = builder.title("Acero 360").inner_size(420.0, 860.0);
            builder.build()?;
            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("no fue posible iniciar Acero 360");
}

#[cfg(test)]
mod pruebas {
    use super::*;

    #[test]
    fn servidores_permitidos() {
        assert_eq!(origen_valido("https://cotizaciones.iguzman.com.mx/").unwrap(), "https://cotizaciones.iguzman.com.mx");
        assert_eq!(origen_valido("http://localhost:8090").unwrap(), "http://localhost:8090");
        assert!(origen_valido("http://192.168.1.10:8017").is_err());
        assert!(origen_valido("http://ejemplo.com").is_err());
        assert!(origen_valido("ftp://192.168.1.10").is_err());
        assert!(origen_valido("no es url").is_err());
    }

    #[test]
    fn politica_de_navegacion() {
        let s = Some("https://cotizaciones.iguzman.com.mx");
        let u = |t: &str| Url::parse(t).unwrap();
        assert!(navegacion_interna(&u("https://cotizaciones.iguzman.com.mx/admin"), s));
        assert!(navegacion_interna(&u("http://tauri.localhost/index.html"), s));
        assert!(!navegacion_interna(&u("https://www.google.com/maps?q=1,2"), s));
        assert!(!navegacion_interna(&u("https://cotizaciones.iguzman.com.mx.evil.com/"), s));
        assert!(!navegacion_interna(&u("tel:5551234567"), s));
        assert!(!navegacion_interna(&u("https://cotizaciones.iguzman.com.mx/"), None));
    }
}
