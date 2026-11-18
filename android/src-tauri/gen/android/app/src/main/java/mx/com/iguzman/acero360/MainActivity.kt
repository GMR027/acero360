package mx.com.iguzman.acero360

import android.app.DownloadManager
import android.content.ContentValues
import android.content.Intent
import android.content.res.Configuration
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.print.PrintManager
import android.provider.MediaStore
import android.util.Base64
import android.view.View
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.MimeTypeMap
import android.webkit.URLUtil
import android.webkit.WebView
import android.widget.Toast
import androidx.core.content.FileProvider
import java.io.File
import org.json.JSONObject
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
 *  • Los archivos que la página arma en el navegador (blob: y data:, p. ej. el PDF y el PNG del
 *    Editor) no se pueden volver a pedir al servidor: la página los entrega por el puente
 *    `Acero360Android` y se guardan en Descargas.
 *  • `Acero360Android.imprimir()` abre el cuadro de impresión de Android (el WebView ignora
 *    window.print()).
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

  private var webViewPrincipal: WebView? = null

  /** Puente que ve la página: solo guarda archivos en Descargas e imprime la vista actual. */
  inner class Puente {
    @JavascriptInterface
    fun guardar(base64: String, nombre: String, tipo: String) {
      runOnUiThread { guardarEnDescargas(base64, nombre, tipo) }
    }

    @JavascriptInterface
    fun fallo(mensaje: String) {
      runOnUiThread { Toast.makeText(this@MainActivity, "No se pudo descargar: $mensaje", Toast.LENGTH_LONG).show() }
    }

    @JavascriptInterface
    fun imprimir(titulo: String) {
      runOnUiThread {
        val vista = webViewPrincipal ?: return@runOnUiThread
        val nombre = titulo.ifBlank { "Acero 360" }
        val impresion = getSystemService(PRINT_SERVICE) as PrintManager
        impresion.print(nombre, vista.createPrintDocumentAdapter(nombre), null)
      }
    }
  }

  /** Nombre seguro con la extensión que corresponde al tipo. */
  private fun nombreSeguro(nombre: String, tipo: String): String {
    var limpio = nombre.replace(Regex("[\\\\/:*?\"<>|]+"), "_").trim().ifBlank { "descarga" }
    val extension = MimeTypeMap.getSingleton().getExtensionFromMimeType(tipo)
    if (extension != null && !limpio.lowercase().endsWith(".$extension")) {
      limpio = "$limpio.$extension"
    }
    return limpio.take(120)
  }

  private fun guardarEnDescargas(base64: String, nombre: String, tipo: String) {
    try {
      val bytes = Base64.decode(base64, Base64.DEFAULT)
      val mime = tipo.ifBlank { "application/octet-stream" }
      val archivo = nombreSeguro(nombre, mime)
      val uri: Uri
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
        val valores = ContentValues().apply {
          put(MediaStore.Downloads.DISPLAY_NAME, archivo)
          put(MediaStore.Downloads.MIME_TYPE, mime)
          put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS)
        }
        uri = contentResolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, valores)
          ?: throw IllegalStateException("Android no dio espacio en Descargas")
        contentResolver.openOutputStream(uri)?.use { it.write(bytes) }
          ?: throw IllegalStateException("no se pudo escribir el archivo")
      } else {
        val carpeta = getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS) ?: filesDir
        val destino = File(carpeta, archivo)
        destino.writeBytes(bytes)
        uri = FileProvider.getUriForFile(this, "$packageName.fileprovider", destino)
      }
      Toast.makeText(this, "Guardado en Descargas: $archivo", Toast.LENGTH_SHORT).show()
      abrirArchivo(uri, mime)
    } catch (e: Exception) {
      Toast.makeText(this, "No se pudo guardar: ${e.message}", Toast.LENGTH_LONG).show()
    }
  }

  /** Abre el archivo recién guardado con la app que lo sepa mostrar (si hay alguna). */
  private fun abrirArchivo(uri: Uri, mime: String) {
    try {
      val ver = Intent(Intent.ACTION_VIEW).apply {
        setDataAndType(uri, mime)
        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
      }
      startActivity(Intent.createChooser(ver, "Abrir con").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (_: Exception) {
      // Sin visor instalado: el archivo ya quedó en Descargas.
    }
  }

  /** blob: → la página lee el blob y lo devuelve en base64 por el puente. */
  private fun descargarBlob(webView: WebView, url: String, nombre: String, tipo: String) {
    val js = "(function(){var u=" + JSONObject.quote(url) + ",n=" + JSONObject.quote(nombre) + ",t=" + JSONObject.quote(tipo) + ";" +
      "fetch(u).then(function(r){return r.blob();}).then(function(b){return new Promise(function(ok,ko){" +
      "var f=new FileReader();f.onload=function(){ok([f.result,b.type]);};f.onerror=ko;f.readAsDataURL(b);});})" +
      ".then(function(x){var d=String(x[0]);Acero360Android.guardar(d.substring(d.indexOf(',')+1),n,x[1]||t);})" +
      ".catch(function(e){Acero360Android.fallo(String(e&&e.message||e));});})();"
    webView.evaluateJavascript(js, null)
  }

  override fun onWebViewCreate(webView: WebView) {
    webViewPrincipal = webView
    webView.addJavascriptInterface(Puente(), "Acero360Android")

    webView.setDownloadListener { url, agente, disposicion, tipo, _ ->
      try {
        if (url.startsWith("blob:")) {
          descargarBlob(webView, url, URLUtil.guessFileName(url, disposicion, tipo), tipo ?: "")
          return@setDownloadListener
        }
        if (url.startsWith("data:")) {
          val coma = url.indexOf(',')
          val cabecera = url.substring(5, maxOf(5, coma))
          val mime = cabecera.substringBefore(';').ifBlank { "application/octet-stream" }
          val datos = url.substring(coma + 1)
          val base64 = if (cabecera.contains(";base64")) datos
            else Base64.encodeToString(Uri.decode(datos).toByteArray(), Base64.NO_WRAP)
          guardarEnDescargas(base64, "acero360-" + System.currentTimeMillis(), mime)
          return@setDownloadListener
        }
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
