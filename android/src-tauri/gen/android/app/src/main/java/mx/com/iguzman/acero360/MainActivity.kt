package mx.com.iguzman.acero360

import android.app.DownloadManager
import android.content.res.Configuration
import android.graphics.Color
import android.net.Uri
import android.os.Bundle
import android.os.Environment
import android.view.View
import android.webkit.CookieManager
import android.webkit.URLUtil
import android.webkit.WebView
import android.widget.Toast
import androidx.activity.enableEdgeToEdge
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat

/**
 * Actividad principal de Acero 360.
 *  • Android 15+ dibuja de borde a borde: la plataforma web no reserva espacio para la barra de
 *    estado ni la de navegación, así que el contenedor del WebView recibe esos márgenes.
 *  • Las descargas (PDF, Excel, CSV, ZIP) del sitio pasan al gestor de descargas de Android con
 *    la cookie de sesión, porque el WebView por sí solo las ignora.
 */
class MainActivity : TauriActivity() {
  override fun onCreate(savedInstanceState: Bundle?) {
    enableEdgeToEdge()
    super.onCreate(savedInstanceState)

    // El contenedor raíz (android.R.id.content) existe siempre: ahí se reservan las barras del sistema
    // y el teclado, para que el WebView nunca quede debajo de ellas.
    val oscuro = (resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
    val raiz = findViewById<View>(android.R.id.content)
    raiz.setBackgroundColor(if (oscuro) Color.parseColor("#15171b") else Color.parseColor("#ededed"))
    WindowCompat.getInsetsController(window, window.decorView).apply {
      isAppearanceLightStatusBars = !oscuro
      isAppearanceLightNavigationBars = !oscuro
    }
    ViewCompat.setOnApplyWindowInsetsListener(raiz) { v, insets ->
      val b = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.ime() or WindowInsetsCompat.Type.displayCutout())
      v.setPadding(b.left, b.top, b.right, b.bottom)
      WindowInsetsCompat.CONSUMED
    }
    ViewCompat.requestApplyInsets(raiz)
  }

  override fun onWebViewCreate(webView: WebView) {

    webView.setDownloadListener { url, agente, disposicion, tipo, _ ->
      try {
        if (!url.startsWith("http")) {
          Toast.makeText(this, "Esta descarga no es compatible con la app; ábrela desde el navegador.", Toast.LENGTH_LONG).show()
          return@setDownloadListener
        }
        val nombre = URLUtil.guessFileName(url, disposicion, tipo)
        val solicitud = DownloadManager.Request(Uri.parse(url)).apply {
          setMimeType(tipo)
          CookieManager.getInstance().getCookie(url)?.let { addRequestHeader("Cookie", it) }
          addRequestHeader("User-Agent", agente)
          setTitle(nombre)
          setDescription("Acero 360")
          setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
          setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, nombre)
        }
        (getSystemService(DOWNLOAD_SERVICE) as DownloadManager).enqueue(solicitud)
        Toast.makeText(this, "Descargando $nombre…", Toast.LENGTH_SHORT).show()
      } catch (e: Exception) {
        Toast.makeText(this, "No se pudo descargar: ${e.message}", Toast.LENGTH_LONG).show()
      }
    }
  }
}
