package mx.com.iguzman.acero360.entregas.util

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.core.content.FileProvider
import java.io.File

/** Guarda, abre y comparte los PDF que genera el servidor. */
object PdfHelper {

    fun guardar(context: Context, bytes: ByteArray, nombre: String): Uri {
        val limpio = nombre.replace(Regex("[^A-Za-z0-9._-]"), "_").ifBlank { "documento.pdf" }
        val carpeta = File(context.cacheDir, "pdf").apply { mkdirs() }
        val archivo = File(carpeta, limpio)
        archivo.writeBytes(bytes)
        return FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", archivo)
    }

    fun abrir(context: Context, uri: Uri) {
        val ver = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/pdf")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        if (ver.resolveActivity(context.packageManager) != null) {
            context.startActivity(ver)
        } else {
            compartir(context, uri)
        }
    }

    fun compartir(context: Context, uri: Uri) {
        val enviar = Intent(Intent.ACTION_SEND).apply {
            type = "application/pdf"
            putExtra(Intent.EXTRA_STREAM, uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        context.startActivity(
            Intent.createChooser(enviar, "Compartir documento")
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        )
    }
}
