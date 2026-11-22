package mx.com.iguzman.acero360.entregas.util

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.location.Location
import android.net.Uri
import androidx.core.content.FileProvider
import androidx.exifinterface.media.ExifInterface
import mx.com.iguzman.acero360.entregas.data.PhotoMeta
import java.io.File
import java.io.FileOutputStream
import java.text.SimpleDateFormat
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.Date
import java.util.Locale
import java.util.UUID

/**
 * Cámara, galería y compresión de fotos.
 *
 * La fecha/hora y el GPS se leen ANTES de comprimir y se devuelven en
 * PhotoMeta; la imagen comprimida ya no conserva EXIF. La app manda esos
 * metadatos aparte al servidor, exactamente como la web.
 */
object PhotoHelper {

    const val MAX_FOTOS = 8
    private const val LADO_MAX = 1600
    private const val CALIDAD = 85

    fun crearArchivoCamara(context: Context): File {
        val carpeta = File(context.cacheDir, "camara").apply { mkdirs() }
        return File(carpeta, "cam_${UUID.randomUUID()}.jpg")
    }

    fun uriParaArchivo(context: Context, archivo: File): Uri =
        FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", archivo)

    fun hayCamara(context: Context): Boolean =
        android.content.Intent(android.provider.MediaStore.ACTION_IMAGE_CAPTURE)
            .resolveActivity(context.packageManager) != null

    fun procesarArchivo(context: Context, origen: File, ubicacion: Location?): PhotoMeta? = runCatching {
        if (!origen.isFile) return@runCatching null
        val exif = runCatching { ExifInterface(origen.absolutePath) }.getOrNull()
        val meta = leerMetadatos(exif, ubicacion)
        val destino = comprimir(context, origen, exif) ?: return@runCatching null
        runCatching { origen.delete() }
        PhotoMeta(
            ruta = destino.absolutePath,
            tomadaEn = meta.first,
            latitud = meta.second?.first,
            longitud = meta.second?.second,
        )
    }.getOrNull()

    fun procesarUri(context: Context, uri: Uri, ubicacion: Location?): PhotoMeta? = runCatching {
        val exif = runCatching {
            context.contentResolver.openInputStream(uri)?.use { ExifInterface(it) }
        }.getOrNull()
        val meta = leerMetadatos(exif, ubicacion)

        val temporal = File(context.cacheDir, "galeria_${UUID.randomUUID()}.jpg")
        context.contentResolver.openInputStream(uri)?.use { entrada ->
            FileOutputStream(temporal).use { salida -> entrada.copyTo(salida) }
        } ?: return@runCatching null

        val destino = comprimir(context, temporal, exif) ?: return@runCatching null
        runCatching { temporal.delete() }
        PhotoMeta(
            ruta = destino.absolutePath,
            tomadaEn = meta.first,
            latitud = meta.second?.first,
            longitud = meta.second?.second,
        )
    }.getOrNull()

    private fun leerMetadatos(exif: ExifInterface?, ubicacion: Location?): Pair<String, Pair<Double, Double>?> {
        var fecha = LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
        val crudo = exif?.getAttribute(ExifInterface.TAG_DATETIME_ORIGINAL)
            ?: exif?.getAttribute(ExifInterface.TAG_DATETIME)
        if (!crudo.isNullOrBlank()) {
            val formatos = listOf("yyyy:MM:dd HH:mm:ss", "yyyy-MM-dd HH:mm:ss")
            for (formato in formatos) {
                val parseada = runCatching { SimpleDateFormat(formato, Locale.US).parse(crudo) }.getOrNull()
                if (parseada != null) {
                    fecha = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(parseada)
                    break
                }
            }
        }

        val coordenadas = FloatArray(2)
        if (exif?.getLatLong(coordenadas) == true) {
            return fecha to (coordenadas[0].toDouble() to coordenadas[1].toDouble())
        }
        if (ubicacion != null) {
            return fecha to (ubicacion.latitude to ubicacion.longitude)
        }
        return fecha to null
    }

    private fun comprimir(context: Context, origen: File, exif: ExifInterface?): File? {
        val opciones = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(origen.absolutePath, opciones)
        if (opciones.outWidth <= 0 || opciones.outHeight <= 0) return null

        var muestra = 1
        while (opciones.outWidth / muestra > LADO_MAX * 2 || opciones.outHeight / muestra > LADO_MAX * 2) {
            muestra *= 2
        }
        val bitmapOriginal = BitmapFactory.decodeFile(
            origen.absolutePath,
            BitmapFactory.Options().apply { inSampleSize = muestra }
        ) ?: return null

        val escala = minOf(
            1f,
            LADO_MAX.toFloat() / bitmapOriginal.width.coerceAtLeast(1),
            LADO_MAX.toFloat() / bitmapOriginal.height.coerceAtLeast(1)
        )
        val escalado = if (escala < 1f) {
            Bitmap.createScaledBitmap(
                bitmapOriginal,
                (bitmapOriginal.width * escala).toInt().coerceAtLeast(1),
                (bitmapOriginal.height * escala).toInt().coerceAtLeast(1),
                true
            )
        } else {
            bitmapOriginal
        }

        val orientacion = exif?.getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)
            ?: ExifInterface.ORIENTATION_NORMAL
        val grados = when (orientacion) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90f
            ExifInterface.ORIENTATION_ROTATE_180 -> 180f
            ExifInterface.ORIENTATION_ROTATE_270 -> 270f
            else -> 0f
        }
        val finalBitmap = if (grados != 0f) {
            Bitmap.createBitmap(escalado, 0, 0, escalado.width, escalado.height, Matrix().apply { postRotate(grados) }, true)
        } else {
            escalado
        }

        val carpeta = File(context.filesDir, "fotos").apply { mkdirs() }
        val destino = File(carpeta, "entrega_${UUID.randomUUID()}.jpg")
        FileOutputStream(destino).use { salida ->
            finalBitmap.compress(Bitmap.CompressFormat.JPEG, CALIDAD, salida)
        }
        if (finalBitmap !== bitmapOriginal) finalBitmap.recycle()
        bitmapOriginal.recycle()
        return destino
    }
}
