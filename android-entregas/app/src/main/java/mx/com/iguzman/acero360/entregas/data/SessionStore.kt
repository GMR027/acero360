package mx.com.iguzman.acero360.entregas.data

import android.content.Context
import org.json.JSONObject
import java.util.UUID

/**
 * Preferencias de la app: servidor, token, usuario, caché de pendientes y cola
 * de operaciones sin sincronizar.
 */
class SessionStore(context: Context) {
    private val prefs = context.getSharedPreferences("acero_entregas", Context.MODE_PRIVATE)

    var serverUrl: String
        get() = prefs.getString("server", DEFAULT_SERVER) ?: DEFAULT_SERVER
        set(value) = prefs.edit().putString("server", value.trim().trimEnd('/')).apply()

    var token: String
        get() = prefs.getString("token", "") ?: ""
        set(value) = prefs.edit().putString("token", value).apply()

    var usuario: Usuario?
        get() {
            val raw = prefs.getString("usuario", "") ?: ""
            if (raw.isBlank()) return null
            return runCatching { usuarioDeJson(JSONObject(raw)) }.getOrNull()
        }
        set(value) {
            if (value == null) {
                prefs.edit().remove("usuario").apply()
            } else {
                prefs.edit().putString("usuario", JSONObject().apply {
                    put("id", value.id)
                    put("nombre", value.nombre)
                    put("email", value.email)
                    put("telefono", value.telefono)
                }.toString()).apply()
            }
        }

    val deviceId: String
        get() {
            var id = prefs.getString("device_id", "") ?: ""
            if (id.isBlank()) {
                id = UUID.randomUUID().toString()
                prefs.edit().putString("device_id", id).apply()
            }
            return id
        }

    fun guardarLogin(token: String, usuario: Usuario) {
        prefs.edit()
            .putString("token", token)
            .putString("usuario", JSONObject().apply {
                put("id", usuario.id)
                put("nombre", usuario.nombre)
                put("email", usuario.email)
                put("telefono", usuario.telefono)
            }.toString())
            .apply()
    }

    fun cerrarSesion() {
        prefs.edit()
            .remove("token")
            .remove("usuario")
            .remove("pendientes_cache")
            .remove("last_sync")
            .apply()
    }

    var pendientesCache: String
        get() = prefs.getString("pendientes_cache", "") ?: ""
        set(value) = prefs.edit().putString("pendientes_cache", value).apply()

    var queueJson: String
        get() = prefs.getString("queue", "[]") ?: "[]"
        set(value) = prefs.edit().putString("queue", value).apply()

    var lastSync: String
        get() = prefs.getString("last_sync", "") ?: ""
        set(value) = prefs.edit().putString("last_sync", value).apply()

    companion object {
        const val DEFAULT_SERVER = "https://cotizaciones.iguzman.com.mx"
    }
}
