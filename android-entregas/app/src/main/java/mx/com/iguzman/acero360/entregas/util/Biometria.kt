package mx.com.iguzman.acero360.entregas.util

import android.content.Context
import android.os.Build
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity

/**
 * Acceso con huella digital.
 *
 * Se usa para dos cosas:
 *  - confirmar que el dueño del teléfono activa o desactiva el bloqueo;
 *  - desbloquear la app al abrirla o al volver después de un rato en segundo plano.
 *
 * No guarda contraseñas: el bloqueo protege la sesión que ya está iniciada.
 * En Android 10 o superior se permite también el PIN/patrón del teléfono como
 * respaldo (así nadie queda fuera si la huella falla); en versiones anteriores
 * se usa el lector biométrico disponible.
 */
object Biometria {

    private const val TITULO = "Acceso con huella digital"
    private const val SUBTITULO = "Confirma que eres tú"

    private fun autenticadores(): Int =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            BiometricManager.Authenticators.BIOMETRIC_WEAK or
                BiometricManager.Authenticators.DEVICE_CREDENTIAL
        } else {
            BiometricManager.Authenticators.BIOMETRIC_STRONG
        }

    fun disponible(context: Context): Boolean = estado(context) == BiometricManager.BIOMETRIC_SUCCESS

    /** Motivo legible cuando el teléfono no puede pedir la huella. */
    fun motivoNoDisponible(context: Context): String = when (estado(context)) {
        BiometricManager.BIOMETRIC_ERROR_NO_HARDWARE ->
            "Este teléfono no tiene lector de huellas."
        BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED ->
            "No hay una huella registrada. Agrégala en Ajustes de Android → Seguridad."
        BiometricManager.BIOMETRIC_ERROR_HW_UNAVAILABLE ->
            "El lector de huellas no está disponible en este momento. Inténtalo más tarde."
        else ->
            "El acceso con huella no está disponible en este teléfono."
    }

    private fun estado(context: Context): Int = runCatching {
        BiometricManager.from(context).canAuthenticate(autenticadores())
    }.getOrDefault(BiometricManager.BIOMETRIC_ERROR_NO_HARDWARE)

    /**
     * Muestra el cuadro del sistema y avisa si el dueño confirmó su identidad.
     * Los errores de cancelación llegan como un mensaje breve, no como fallo grave.
     */
    fun autenticar(
        actividad: FragmentActivity,
        titulo: String = TITULO,
        subtitulo: String = SUBTITULO,
        alExito: () -> Unit,
        alError: (String) -> Unit,
    ) {
        val solicitud = BiometricPrompt.PromptInfo.Builder()
            .setTitle(titulo)
            .setSubtitle(subtitulo)
            .setAllowedAuthenticators(autenticadores())
            .build()

        val dialogo = BiometricPrompt(
            actividad,
            ContextCompat.getMainExecutor(actividad),
            object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(resultado: BiometricPrompt.AuthenticationResult) {
                    alExito()
                }

                override fun onAuthenticationError(codigo: Int, texto: CharSequence) {
                    alError(mensajeError(codigo, texto))
                }
            },
        )

        runCatching { dialogo.authenticate(solicitud) }
            .onFailure { alError("No se pudo abrir el lector de huellas: ${it.message ?: "error desconocido"}.") }
    }

    private fun mensajeError(codigo: Int, texto: CharSequence): String = when (codigo) {
        BiometricPrompt.ERROR_USER_CANCELED,
        BiometricPrompt.ERROR_NEGATIVE_BUTTON,
        BiometricPrompt.ERROR_CANCELED,
        -> "No se confirmó la huella."

        BiometricPrompt.ERROR_LOCKOUT ->
            "Demasiados intentos. Espera un momento o usa tu contraseña."

        BiometricPrompt.ERROR_LOCKOUT_PERMANENT ->
            "El lector está bloqueado. Usa tu contraseña para entrar."

        BiometricPrompt.ERROR_NO_BIOMETRICS ->
            "Este teléfono no tiene una huella registrada."

        else -> texto.toString().ifBlank { "No se pudo verificar la huella." }
    }
}
