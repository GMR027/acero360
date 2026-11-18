package mx.com.iguzman.acero360

import android.content.Context
import android.content.res.Configuration
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.SystemClock
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_WEAK
import androidx.biometric.BiometricManager.Authenticators.DEVICE_CREDENTIAL
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat

/**
 * Desbloqueo de Acero 360 con la huella digital (o el método biométrico del teléfono).
 *
 *  • Solo existe si el teléfono tiene lector biométrico y al menos una huella registrada; si no, la
 *    app se comporta como siempre y no se ofrece la opción.
 *  • Es opcional y por teléfono: la primera vez que hay huella disponible se pregunta una sola vez;
 *    después se activa o se quita desde Ajustes → Aplicación Android (puente `Acero360Android`).
 *  • Con el bloqueo activo, una pantalla opaca tapa la plataforma al abrir la app y al volver a ella
 *    tras [GRACIA_MS] en segundo plano (el margen evita pedir la huella después de tomar una foto o
 *    elegir un archivo). Si la huella falla, Android ofrece el PIN o patrón del teléfono.
 *  • Si después se borran todas las huellas del teléfono, la app ya no se bloquea: nadie queda fuera.
 *  • Inicio de sesión: la sesión del sitio se pierde cuando Android cierra la app. Con el bloqueo
 *    activo, el sitio entrega una llave para esta instalación ([guardarLlave]); al llegar a la
 *    pantalla de acceso, la app la envía tras confirmar la huella ([confirmar]). La llave solo se
 *    envía al mismo servidor que la emitió y se borra al quitar el bloqueo.
 */
class BloqueoHuella(private val actividad: AppCompatActivity, private val alCambiar: (Boolean, String) -> Unit) {

  companion object {
    private const val PREFERENCIAS = "acero360_bloqueo"
    private const val CLAVE_ACTIVA = "huella_activa"
    private const val CLAVE_PREGUNTADA = "huella_preguntada"
    private const val CLAVE_LLAVE = "huella_llave"
    private const val CLAVE_ORIGEN = "huella_origen"
    private const val RECIENTE_MS = 120_000L
    private const val GRACIA_MS = 60_000L
    private const val AUTENTICADORES = BIOMETRIC_WEAK or DEVICE_CREDENTIAL
  }

  private val preferencias = actividad.getSharedPreferences(PREFERENCIAS, Context.MODE_PRIVATE)
  private var pantalla: FrameLayout? = null
  private var mensaje: TextView? = null
  private var bloqueada = false
  private var autenticando = false
  private var salioEn = 0L
  private var desbloqueadaEn = 0L
  private val atrasBloqueado = object : OnBackPressedCallback(false) {
    override fun handleOnBackPressed() {
      actividad.moveTaskToBack(true)
    }
  }

  /** ¿El teléfono tiene lector biométrico con al menos una huella registrada? */
  fun disponible(): Boolean =
    BiometricManager.from(actividad).canAuthenticate(BIOMETRIC_WEAK) == BiometricManager.BIOMETRIC_SUCCESS

  fun activa(): Boolean = preferencias.getBoolean(CLAVE_ACTIVA, false)

  /** Llave de inicio de sesión guardada para ese servidor (solo con el bloqueo activo). */
  fun llave(origen: String): String? {
    if (!activa()) return null
    val guardada = preferencias.getString(CLAVE_LLAVE, null) ?: return null
    return if (preferencias.getString(CLAVE_ORIGEN, null) == origen) guardada else null
  }

  fun guardarLlave(llave: String, origen: String) {
    if (activa() && Regex("^[0-9a-f]{64}$").matches(llave)) {
      preferencias.edit().putString(CLAVE_LLAVE, llave).putString(CLAVE_ORIGEN, origen).apply()
    }
  }

  fun olvidarLlave() {
    preferencias.edit().remove(CLAVE_LLAVE).remove(CLAVE_ORIGEN).apply()
  }

  /** Confirma que es el dueño: si acaba de desbloquear la app no vuelve a pedir la huella. */
  fun confirmar(alExito: () -> Unit, alFallar: (String) -> Unit) {
    if (desbloqueadaEn != 0L && SystemClock.elapsedRealtime() - desbloqueadaEn < RECIENTE_MS) {
      alExito()
      return
    }
    autenticar("Entrar a Acero 360", "Usa tu huella digital", {
      desbloqueadaEn = SystemClock.elapsedRealtime()
      alExito()
    }, alFallar)
  }

  /** Al crear la actividad: tapa la plataforma antes de que se vea y, si toca, ofrece activarlo. */
  fun alCrear() {
    actividad.onBackPressedDispatcher.addCallback(actividad, atrasBloqueado)
    ocultarEnRecientes(activa())
    if (activa() && disponible()) {
      bloquear()
    } else if (!preferencias.getBoolean(CLAVE_PREGUNTADA, false) && disponible()) {
      actividad.window.decorView.postDelayed({ ofrecer() }, 2500)
    }
  }

  /** El WebView registra su propio «atrás»; el del bloqueo debe quedar encima. */
  fun reforzarAtras() {
    atrasBloqueado.remove()
    actividad.onBackPressedDispatcher.addCallback(actividad, atrasBloqueado)
    atrasBloqueado.isEnabled = bloqueada
  }

  fun alDetener() {
    if (!autenticando) {
      salioEn = SystemClock.elapsedRealtime()
    }
  }

  fun alIniciar() {
    if (autenticando || bloqueada || salioEn == 0L) {
      return
    }
    if (activa() && disponible() && SystemClock.elapsedRealtime() - salioEn >= GRACIA_MS) {
      bloquear()
    }
    salioEn = 0L
  }

  /** Activa o quita el bloqueo; en ambos casos se confirma con la huella del dueño del teléfono. */
  fun cambiar(activar: Boolean) {
    if (activar && !disponible()) {
      alCambiar(false, "Este teléfono no tiene huella registrada. Regístrala en Ajustes de Android → Seguridad.")
      return
    }
    if (activar == activa()) {
      alCambiar(activa(), "")
      return
    }
    autenticar(
      if (activar) "Activar desbloqueo con huella" else "Quitar desbloqueo con huella",
      "Confirma que eres tú",
      {
        guardar(activar)
        alCambiar(activar, if (activar) "Listo: Acero 360 pedirá tu huella al abrirse." else "Se quitó el desbloqueo con huella.")
      },
      { error -> alCambiar(activa(), error) }
    )
  }

  private fun guardar(activar: Boolean) {
    preferencias.edit().putBoolean(CLAVE_ACTIVA, activar).putBoolean(CLAVE_PREGUNTADA, true).apply()
    if (!activar) olvidarLlave()
    desbloqueadaEn = SystemClock.elapsedRealtime()
    ocultarEnRecientes(activar)
  }

  /** Pregunta única la primera vez que hay huella disponible. */
  private fun ofrecer() {
    if (actividad.isFinishing || preferencias.getBoolean(CLAVE_PREGUNTADA, false) || activa()) {
      return
    }
    preferencias.edit().putBoolean(CLAVE_PREGUNTADA, true).apply()
    AlertDialog.Builder(actividad)
      .setTitle("¿Proteger Acero 360 con tu huella?")
      .setMessage("Al abrir la app se pedirá tu huella digital para ver la información. Puedes cambiarlo después en Ajustes → Aplicación Android.")
      .setPositiveButton("Activar") { _, _ -> cambiar(true) }
      .setNegativeButton("Ahora no", null)
      .show()
  }

  /** En la vista de apps recientes no se muestra la plataforma mientras el bloqueo está activo. */
  private fun ocultarEnRecientes(ocultar: Boolean) {
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
      actividad.setRecentsScreenshotEnabled(!ocultar)
    }
  }

  private fun bloquear() {
    bloqueada = true
    atrasBloqueado.isEnabled = true
    val vista = pantalla ?: crearPantalla().also { pantalla = it }
    if (vista.parent == null) {
      (actividad.window.decorView as ViewGroup).addView(vista, ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
    }
    vista.visibility = View.VISIBLE
    vista.bringToFront()
    contenido()?.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO_HIDE_DESCENDANTS
    mensaje?.text = "Usa tu huella para entrar."
    vista.post { pedirDesbloqueo() }
  }

  private fun desbloquear() {
    bloqueada = false
    desbloqueadaEn = SystemClock.elapsedRealtime()
    atrasBloqueado.isEnabled = false
    pantalla?.visibility = View.GONE
    contenido()?.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_AUTO
  }

  private fun contenido(): View? = actividad.findViewById(android.R.id.content)

  private fun pedirDesbloqueo() {
    if (!bloqueada || autenticando) {
      return
    }
    if (!disponible()) {
      // Se borraron las huellas del teléfono: no se deja a nadie fuera de su propia app.
      desbloquear()
      return
    }
    autenticar("Desbloquear Acero 360", "Usa tu huella digital", { desbloquear() }, { error -> mensaje?.text = error })
  }

  private fun autenticar(titulo: String, subtitulo: String, alExito: () -> Unit, alFallar: (String) -> Unit) {
    if (autenticando) {
      return
    }
    autenticando = true
    val solicitud = BiometricPrompt.PromptInfo.Builder()
      .setTitle(titulo)
      .setSubtitle(subtitulo)
      .setAllowedAuthenticators(AUTENTICADORES)
      .build()
    val dialogo = BiometricPrompt(actividad, ContextCompat.getMainExecutor(actividad), object : BiometricPrompt.AuthenticationCallback() {
      override fun onAuthenticationSucceeded(resultado: BiometricPrompt.AuthenticationResult) {
        autenticando = false
        alExito()
      }

      override fun onAuthenticationError(codigo: Int, texto: CharSequence) {
        autenticando = false
        val cancelado = codigo == BiometricPrompt.ERROR_USER_CANCELED || codigo == BiometricPrompt.ERROR_NEGATIVE_BUTTON || codigo == BiometricPrompt.ERROR_CANCELED
        alFallar(if (cancelado) "No se confirmó la huella." else texto.toString())
      }
      // onAuthenticationFailed (huella no reconocida) no se maneja: el cuadro sigue abierto para otro intento.
    })
    try {
      dialogo.authenticate(solicitud)
    } catch (e: Exception) {
      autenticando = false
      alFallar("No se pudo pedir la huella: ${e.message}")
    }
  }

  /** Pantalla opaca con el logotipo y el botón «Desbloquear». */
  private fun crearPantalla(): FrameLayout {
    val oscuro = (actividad.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
    val fondo = Color.parseColor(if (oscuro) "#15171b" else "#ededed")
    val texto = Color.parseColor(if (oscuro) "#e3e5e8" else "#4d4c53")
    val primario = Color.parseColor(if (oscuro) "#5b8fbf" else "#4679a6")
    val dp = { valor: Float -> TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, valor, actividad.resources.displayMetrics).toInt() }

    val columna = LinearLayout(actividad).apply {
      orientation = LinearLayout.VERTICAL
      gravity = Gravity.CENTER_HORIZONTAL
      setPadding(dp(32f), dp(32f), dp(32f), dp(32f))
    }
    columna.addView(ImageView(actividad).apply {
      setImageResource(R.mipmap.ic_launcher)
      contentDescription = null
    }, LinearLayout.LayoutParams(dp(88f), dp(88f)))
    columna.addView(TextView(actividad).apply {
      this.text = "Acero 360 está bloqueada"
      setTextColor(texto)
      setTextSize(TypedValue.COMPLEX_UNIT_SP, 20f)
      typeface = Typeface.DEFAULT_BOLD
      gravity = Gravity.CENTER
      setPadding(0, dp(20f), 0, dp(6f))
    })
    mensaje = TextView(actividad).apply {
      setTextColor(texto)
      setTextSize(TypedValue.COMPLEX_UNIT_SP, 15f)
      gravity = Gravity.CENTER
      setPadding(0, 0, 0, dp(24f))
    }
    columna.addView(mensaje)
    columna.addView(Button(actividad).apply {
      this.text = "Desbloquear"
      isAllCaps = false
      setTextColor(Color.WHITE)
      setTextSize(TypedValue.COMPLEX_UNIT_SP, 16f)
      background = GradientDrawable().apply {
        setColor(primario)
        cornerRadius = dp(10f).toFloat()
      }
      setPadding(dp(28f), dp(12f), dp(28f), dp(12f))
      setOnClickListener { pedirDesbloqueo() }
    }, LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT))

    return FrameLayout(actividad).apply {
      setBackgroundColor(fondo)
      isClickable = true
      isFocusable = true
      elevation = dp(100f).toFloat()
      addView(columna, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER))
    }
  }
}
