package mx.com.iguzman.acero360

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.TimeUnit
import org.json.JSONObject

/**
 * Notificaciones del Centro de seguimiento en el teléfono.
 *
 *  • Se activan desde Ajustes → Aplicación Android (puente `Acero360Android`). El sitio entrega una
 *    llave de SOLO LECTURA de avisos para esta instalación (POST /app/android/avisos/registrar).
 *  • [RevisarAvisos] corre cada ~15 min (lo mínimo que permite Android) con WorkManager, aunque la
 *    app esté cerrada, y sobrevive a reinicios del teléfono. Pide GET /app/android/avisos.json y
 *    muestra como notificación cada aviso que no se haya mostrado antes.
 *  • Suenan como cualquier notificación del teléfono: por omisión con el tono de notificación que
 *    el usuario tiene configurado en Android; desde Ajustes se puede elegir otro tono (el selector
 *    de Android incluye «Agregar tono» para usar un archivo propio). Android no deja cambiar el
 *    sonido de un canal ya creado, así que cada cambio de tono crea un canal nuevo (avisos_N).
 *  • Al tocar una notificación se abre la app en el registro del aviso ([guardarEnlace]).
 */
object AvisosAndroid {
  private const val PREFERENCIAS = "acero360_avisos"
  private const val CLAVE_QUIERE = "quiere"
  private const val CLAVE_LLAVE = "llave"
  private const val CLAVE_ORIGEN = "origen"
  private const val CLAVE_INSTALACION = "instalacion"
  private const val CLAVE_PRIMERA = "primera"
  private const val CLAVE_VISTOS = "vistos"
  private const val CLAVE_TONO = "tono"
  private const val CLAVE_CANAL = "canal"
  private const val CLAVE_ENLACE = "enlace"
  private const val TRABAJO = "acero360-avisos"
  private const val TRABAJO_YA = "acero360-avisos-ya"
  private const val GRUPO = "mx.com.iguzman.acero360.AVISOS"
  private const val ID_RESUMEN = 1
  private const val ID_PRUEBA = 2
  private const val MAXIMO_INDIVIDUALES = 5
  const val EXTRA_ENLACE = "acero360_enlace"

  private fun preferencias(ctx: Context) = ctx.getSharedPreferences(PREFERENCIAS, Context.MODE_PRIVATE)

  /** El usuario las quiere en este teléfono (aunque la llave falte y haya que pedir otra). */
  fun activas(ctx: Context) = preferencias(ctx).getBoolean(CLAVE_QUIERE, false)

  fun conectadas(ctx: Context) = preferencias(ctx).getString(CLAVE_LLAVE, null) != null

  fun permitidas(ctx: Context): Boolean {
    if (Build.VERSION.SDK_INT >= 33 &&
      ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
      return false
    }
    val gestor = NotificationManagerCompat.from(ctx)
    if (!gestor.areNotificationsEnabled()) return false
    if (Build.VERSION.SDK_INT >= 26) {
      val canal = gestor.getNotificationChannel(canal(ctx))
      if (canal != null && canal.importance == NotificationManager.IMPORTANCE_NONE) return false
    }
    return true
  }

  /** Guarda la llave que emitió el servidor y programa la revisión periódica. */
  fun activar(ctx: Context, llave: String, instalacion: String, origen: String): Boolean {
    if (!Regex("^[0-9a-f]{64}$").matches(llave) || !Regex("^[0-9a-f-]{36}$").matches(instalacion)) return false
    val p = preferencias(ctx)
    val primeraVez = !p.getBoolean(CLAVE_QUIERE, false) || p.getString(CLAVE_ORIGEN, null) != origen
    val cambios = p.edit()
      .putBoolean(CLAVE_QUIERE, true)
      .putString(CLAVE_LLAVE, llave)
      .putString(CLAVE_INSTALACION, instalacion)
      .putString(CLAVE_ORIGEN, origen)
    // Al encender: lo que ya estaba pendiente se resume en una sola notificación, no en decenas.
    if (primeraVez) cambios.putBoolean(CLAVE_PRIMERA, true).remove(CLAVE_VISTOS)
    cambios.apply()
    canal(ctx)
    programar(ctx)
    revisarAhora(ctx)
    return true
  }

  fun desactivar(ctx: Context) {
    preferencias(ctx).edit()
      .putBoolean(CLAVE_QUIERE, false)
      .remove(CLAVE_LLAVE).remove(CLAVE_VISTOS).remove(CLAVE_PRIMERA)
      .apply()
    WorkManager.getInstance(ctx).cancelUniqueWork(TRABAJO)
    NotificationManagerCompat.from(ctx).cancelAll()
  }

  /** El servidor rechazó la llave (contraseña cambiada, caducada): el sitio pedirá otra al entrar. */
  private fun olvidarLlave(ctx: Context) {
    preferencias(ctx).edit().remove(CLAVE_LLAVE).apply()
  }

  /** Revisión periódica (se conserva la ya programada; al abrir la app solo se asegura que exista). */
  fun programar(ctx: Context) {
    if (!activas(ctx)) return
    val trabajo = PeriodicWorkRequestBuilder<RevisarAvisos>(15, TimeUnit.MINUTES)
      .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
      .build()
    WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(TRABAJO, ExistingPeriodicWorkPolicy.KEEP, trabajo)
  }

  private fun revisarAhora(ctx: Context) {
    val trabajo = OneTimeWorkRequestBuilder<RevisarAvisos>()
      .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
      .build()
    WorkManager.getInstance(ctx).enqueueUniqueWork(TRABAJO_YA, ExistingWorkPolicy.REPLACE, trabajo)
  }

  /* ----------------------------------------------------------------- Tono y canal */

  /** Tono elegido en la app; null = el tono de notificación configurado en el teléfono. */
  private fun tonoElegido(ctx: Context): Uri? =
    preferencias(ctx).getString(CLAVE_TONO, null)?.takeIf { it.isNotBlank() }?.let { Uri.parse(it) }

  /** Sonido real: el del canal (el usuario pudo cambiarlo en los ajustes de Android) o el elegido. */
  fun tonoActual(ctx: Context): Uri? {
    if (Build.VERSION.SDK_INT >= 26) {
      val gestor = ctx.getSystemService(NotificationManager::class.java)
      gestor.getNotificationChannel(canal(ctx))?.let { return it.sound }
    }
    return tonoElegido(ctx) ?: Settings.System.DEFAULT_NOTIFICATION_URI
  }

  fun tonoPropio(ctx: Context): Boolean {
    val tono = tonoActual(ctx) ?: return true // silencio elegido en Android
    return tono != Settings.System.DEFAULT_NOTIFICATION_URI
  }

  fun nombreTono(ctx: Context): String {
    val tono = tonoActual(ctx) ?: return "Sin sonido"
    if (tono == Settings.System.DEFAULT_NOTIFICATION_URI) {
      val real = RingtoneManager.getActualDefaultRingtoneUri(ctx, RingtoneManager.TYPE_NOTIFICATION)
      val nombre = real?.let { titulo(ctx, it) }
      return if (nombre != null) "El del teléfono ($nombre)" else "El del teléfono"
    }
    return titulo(ctx, tono) ?: "Tono personalizado"
  }

  private fun titulo(ctx: Context, uri: Uri): String? = try {
    RingtoneManager.getRingtone(ctx, uri)?.getTitle(ctx)?.takeIf { it.isNotBlank() }
  } catch (_: Exception) {
    null
  }

  /** Cambia el tono (null = el del teléfono). Android fija el sonido al crear el canal: se crea otro. */
  fun cambiarTono(ctx: Context, tono: Uri?) {
    val valor = if (tono == null || tono == Settings.System.DEFAULT_NOTIFICATION_URI) "" else tono.toString()
    val p = preferencias(ctx)
    if (Build.VERSION.SDK_INT >= 26) {
      val gestor = ctx.getSystemService(NotificationManager::class.java)
      gestor.deleteNotificationChannel(idCanal(p.getInt(CLAVE_CANAL, 1)))
    }
    p.edit().putString(CLAVE_TONO, valor).putInt(CLAVE_CANAL, p.getInt(CLAVE_CANAL, 1) + 1).commit()
    canal(ctx)
  }

  private fun idCanal(n: Int) = "avisos_$n"

  /** Crea (si falta) el canal «Avisos de seguimiento» con el tono vigente y devuelve su id. */
  fun canal(ctx: Context): String {
    val id = idCanal(preferencias(ctx).getInt(CLAVE_CANAL, 1))
    if (Build.VERSION.SDK_INT >= 26) {
      val gestor = ctx.getSystemService(NotificationManager::class.java)
      if (gestor.getNotificationChannel(id) == null) {
        val canal = NotificationChannel(id, "Avisos de seguimiento", NotificationManager.IMPORTANCE_HIGH).apply {
          description = "Pedidos, cobros, entregas, inventario y demás avisos del Centro de seguimiento"
          enableVibration(true)
          enableLights(true)
          setSound(
            tonoElegido(ctx) ?: Settings.System.DEFAULT_NOTIFICATION_URI,
            AudioAttributes.Builder()
              .setUsage(AudioAttributes.USAGE_NOTIFICATION)
              .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
              .build()
          )
        }
        gestor.createNotificationChannel(canal)
      }
    }
    return id
  }

  /** Ajustes de Android para este canal (sonido, vibración, pantalla bloqueada, «No molestar»). */
  fun intentAjustes(ctx: Context): Intent =
    if (Build.VERSION.SDK_INT >= 26) {
      Intent(Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS)
        .putExtra(Settings.EXTRA_APP_PACKAGE, ctx.packageName)
        .putExtra(Settings.EXTRA_CHANNEL_ID, canal(ctx))
    } else {
      Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + ctx.packageName))
    }

  /** Selector de tonos de Android (incluye «Agregar tono» en la mayoría de los teléfonos). */
  fun intentElegirTono(ctx: Context): Intent =
    Intent(RingtoneManager.ACTION_RINGTONE_PICKER)
      .putExtra(RingtoneManager.EXTRA_RINGTONE_TYPE, RingtoneManager.TYPE_NOTIFICATION)
      .putExtra(RingtoneManager.EXTRA_RINGTONE_TITLE, "Tono de los avisos")
      .putExtra(RingtoneManager.EXTRA_RINGTONE_SHOW_DEFAULT, true)
      .putExtra(RingtoneManager.EXTRA_RINGTONE_DEFAULT_URI, Settings.System.DEFAULT_NOTIFICATION_URI)
      .putExtra(RingtoneManager.EXTRA_RINGTONE_SHOW_SILENT, false)
      .putExtra(RingtoneManager.EXTRA_RINGTONE_EXISTING_URI, tonoActual(ctx))

  /* ----------------------------------------------------------------- Notificaciones */

  fun probar(ctx: Context) {
    publicar(ctx, ID_PRUEBA, "Acero 360", "Así sonarán los avisos del Centro de seguimiento.", "/admin/seguimiento", resumen = false, agrupar = false)
  }

  /**
   * Los avisos de una misma revisión van agrupados y solo suena el resumen: un sonido por tanda,
   * no uno por aviso.
   */
  fun publicar(ctx: Context, id: Int, titulo: String, texto: String, enlace: String, resumen: Boolean, agrupar: Boolean = true) {
    if (!permitidas(ctx)) return
    val abrir = Intent(ctx, MainActivity::class.java)
      .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
      .putExtra(EXTRA_ENLACE, enlace)
    val alTocar = PendingIntent.getActivity(ctx, id, abrir, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    val aviso = NotificationCompat.Builder(ctx, canal(ctx))
      .setSmallIcon(R.drawable.ic_notificacion)
      .setContentTitle(titulo)
      .setContentText(texto)
      .setStyle(NotificationCompat.BigTextStyle().bigText(texto))
      .setPriority(NotificationCompat.PRIORITY_HIGH)
      .setCategory(NotificationCompat.CATEGORY_REMINDER)
      .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
      .setAutoCancel(true)
      .setContentIntent(alTocar)
    if (agrupar) {
      aviso.setGroup(GRUPO).setGroupSummary(resumen).setGroupAlertBehavior(NotificationCompat.GROUP_ALERT_SUMMARY)
    }
    if (Build.VERSION.SDK_INT < 26) {
      // Antes de Android 8 no hay canales: el sonido va en cada notificación.
      aviso.setSound(tonoElegido(ctx) ?: Settings.System.DEFAULT_NOTIFICATION_URI)
        .setDefaults(NotificationCompat.DEFAULT_VIBRATE or NotificationCompat.DEFAULT_LIGHTS)
    }
    try {
      NotificationManagerCompat.from(ctx).notify(id, aviso.build())
    } catch (_: SecurityException) {
      // Permiso retirado justo ahora: se intentará en la siguiente revisión.
    }
  }

  /** Revisa el servidor y publica lo nuevo. Corre en el hilo de WorkManager. */
  internal fun revisar(ctx: Context) {
    val p = preferencias(ctx)
    if (!p.getBoolean(CLAVE_QUIERE, false)) return
    val llave = p.getString(CLAVE_LLAVE, null) ?: return
    val origen = p.getString(CLAVE_ORIGEN, null) ?: return
    val instalacion = p.getString(CLAVE_INSTALACION, null) ?: return

    val conexion = URL("$origen/app/android/avisos.json").openConnection() as HttpURLConnection
    val datos = try {
      conexion.connectTimeout = 15_000
      conexion.readTimeout = 20_000
      conexion.instanceFollowRedirects = false
      conexion.setRequestProperty("Accept", "application/json")
      conexion.setRequestProperty("X-Acero360-Llave", llave)
      conexion.setRequestProperty("X-Acero360-Instalacion", instalacion)
      when (conexion.responseCode) {
        200 -> JSONObject(conexion.inputStream.bufferedReader().use { it.readText() })
        401 -> { olvidarLlave(ctx); return }
        else -> return
      }
    } finally {
      conexion.disconnect()
    }

    val lista = datos.optJSONArray("avisos") ?: return
    val total = datos.optInt("total", lista.length())
    val vistos = p.getStringSet(CLAVE_VISTOS, emptySet())!!.toSet()
    val actuales = LinkedHashMap<String, JSONObject>()
    for (i in 0 until lista.length()) {
      val a = lista.getJSONObject(i)
      actuales[a.getString("id")] = a
    }
    val gestor = NotificationManagerCompat.from(ctx)

    // Lo que ya se atendió (leído en la plataforma o resuelto) se quita de la bandeja.
    (vistos - actuales.keys).forEach { gestor.cancel(numero(it)) }

    if (p.getBoolean(CLAVE_PRIMERA, false)) {
      if (total > 0) {
        publicar(ctx, ID_RESUMEN, "Acero 360",
          if (total == 1) "Tienes 1 aviso sin leer en el Centro de seguimiento."
          else "Tienes $total avisos sin leer en el Centro de seguimiento.",
          "/admin/seguimiento?ver=nuevos", resumen = true)
      }
    } else {
      val nuevos = actuales.filterKeys { it !in vistos }.values.toList()
      nuevos.take(MAXIMO_INDIVIDUALES).forEach { a ->
        val titulo = a.optString("titulo").ifBlank { "Aviso nuevo" }
        val categoria = a.optString("categoria")
        val urgente = a.optString("prioridad") == "urgente"
        publicar(ctx, numero(a.getString("id")),
          (if (urgente) "Urgente · " else "") + titulo,
          listOf(categoria, a.optString("detalle")).filter { it.isNotBlank() }.joinToString(" · "),
          a.optString("enlace", "/admin/seguimiento"), resumen = false)
      }
      if (nuevos.isNotEmpty()) {
        val extra = nuevos.size - MAXIMO_INDIVIDUALES
        publicar(ctx, ID_RESUMEN, "Acero 360",
          if (extra > 0) "${nuevos.size} avisos nuevos (y $extra más en el Centro de seguimiento)."
          else if (nuevos.size == 1) "1 aviso nuevo." else "${nuevos.size} avisos nuevos.",
          "/admin/seguimiento?ver=nuevos", resumen = true)
      }
    }
    p.edit().putStringSet(CLAVE_VISTOS, actuales.keys).putBoolean(CLAVE_PRIMERA, false).apply()
  }

  /** Id de notificación estable por aviso (los ids 1 y 2 quedan para el resumen y la prueba). */
  private fun numero(id: String) = (id.hashCode() and 0x7fffffff) or 0x100

  /* ----------------------------------------------------------------- Abrir el aviso tocado */

  /** Guarda la ruta del aviso tocado; la página la pide con `avisoPendiente()` al cargar. */
  fun guardarEnlace(ctx: Context, intent: Intent?): Boolean {
    val enlace = intent?.getStringExtra(EXTRA_ENLACE) ?: return false
    intent.removeExtra(EXTRA_ENLACE)
    if (!Regex("^/admin(/|\\?|$)").containsMatchIn(enlace) || enlace.contains("//")) return false
    preferencias(ctx).edit().putString(CLAVE_ENLACE, enlace).apply()
    return true
  }

  fun tomarEnlace(ctx: Context): String {
    val p = preferencias(ctx)
    val enlace = p.getString(CLAVE_ENLACE, null) ?: return ""
    p.edit().remove(CLAVE_ENLACE).apply()
    return enlace
  }

  fun estado(ctx: Context): JSONObject = JSONObject()
    .put("activas", activas(ctx))
    .put("conectadas", conectadas(ctx))
    .put("permitidas", permitidas(ctx))
    .put("tono", nombreTono(ctx))
    .put("tonoPropio", tonoPropio(ctx))
}

/** Revisión en segundo plano (WorkManager): sin red o con error, espera a la siguiente vuelta. */
class RevisarAvisos(ctx: Context, parametros: WorkerParameters) : Worker(ctx, parametros) {
  override fun doWork(): Result {
    try {
      AvisosAndroid.revisar(applicationContext)
    } catch (_: Exception) {
      // Servidor caído o respuesta inesperada: se reintenta en la siguiente revisión periódica.
    }
    return Result.success()
  }
}
