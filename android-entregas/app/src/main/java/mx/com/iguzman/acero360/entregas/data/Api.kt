package mx.com.iguzman.acero360.entregas.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.util.concurrent.TimeUnit

class ApiException(mensaje: String, val codigo: Int = 0) : Exception(mensaje)

data class LoginData(val token: String, val usuario: Usuario)

/**
 * Cliente HTTP de la API /api/entregas/v1. Toda la app pasa por aquí: no hay
 * conexión directa a MySQL y el token viaja en Authorization: Bearer.
 */
class Api(private val session: SessionStore) {

    private val cliente = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(120, TimeUnit.SECONDS)
        .writeTimeout(120, TimeUnit.SECONDS)
        .build()

    private val jsonMedia = "application/json; charset=utf-8".toMediaType()

    private fun url(path: String, params: Map<String, String> = emptyMap()): HttpUrl {
        val base = session.serverUrl.trim().trimEnd('/')
        val completa = if (path.startsWith("/")) "$base$path" else "$base/$path"
        val builder = (completa.toHttpUrlOrNull()
            ?: throw ApiException("La dirección del servidor no es válida.")).newBuilder()
        params.forEach { (clave, valor) -> builder.addQueryParameter(clave, valor) }
        return builder.build()
    }

    private fun request(path: String, params: Map<String, String> = emptyMap()): Request.Builder =
        Request.Builder()
            .url(url(path, params))
            .header("Accept", "application/json")
            .header("Authorization", "Bearer ${session.token}")

    private fun errorDe(response: okhttp3.Response): ApiException {
        val cuerpo = response.body?.string().orEmpty()
        val mensaje = runCatching { JSONObject(cuerpo).text("error") }.getOrNull()
        return ApiException(mensaje?.ifBlank { null } ?: "Error del servidor (${response.code}).", response.code)
    }

    suspend fun login(
        servidor: String,
        email: String,
        password: String,
        dispositivo: String,
    ): LoginData = withContext(Dispatchers.IO) {
        session.serverUrl = servidor
        val cuerpo = JSONObject().apply {
            put("email", email)
            put("password", password)
            put("dispositivo", dispositivo)
            put("device_id", session.deviceId)
        }
        val request = Request.Builder()
            .url(url("/api/entregas/v1/login"))
            .post(cuerpo.toString().toRequestBody(jsonMedia))
            .header("Accept", "application/json")
            .build()
        cliente.newCall(request).execute().use { response ->
            val texto = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                val mensaje = runCatching { JSONObject(texto).text("error") }.getOrNull()
                throw ApiException(mensaje?.ifBlank { null } ?: "No se pudo iniciar sesión.", response.code)
            }
            val json = JSONObject(texto)
            val token = json.text("token")
            val usuario = json.objeto("usuario")?.let { usuarioDeJson(it) }
                ?: throw ApiException("El servidor no devolvió el usuario.")
            if (token.isBlank()) throw ApiException("El servidor no devolvió el token.")
            LoginData(token, usuario)
        }
    }

    suspend fun yo(): Usuario = withContext(Dispatchers.IO) {
        cliente.newCall(request("/api/entregas/v1/yo").get().build()).execute().use { response ->
            val texto = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw errorDe(response)
            val json = JSONObject(texto)
            json.objeto("usuario")?.let { usuarioDeJson(it) }
                ?: throw ApiException("La sesión no es válida.")
        }
    }

    suspend fun pendientes(): PendientesData = withContext(Dispatchers.IO) {
        cliente.newCall(request("/api/entregas/v1/pendientes").get().build()).execute().use { response ->
            val texto = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw errorDe(response)
            val json = JSONObject(texto)
            if (!json.booleano("ok")) throw ApiException(json.text("error").ifBlank { "No se pudieron cargar los pendientes." })
            session.pendientesCache = texto
            pendientesDeJson(json)
        }
    }

    fun pendientesDesdeCache(): PendientesData? {
        val raw = session.pendientesCache
        if (raw.isBlank()) return null
        return runCatching { pendientesDeJson(JSONObject(raw)) }.getOrNull()
    }

    suspend fun venta(id: Int): VentaDetalleData = withContext(Dispatchers.IO) {
        cliente.newCall(request("/api/entregas/v1/venta", mapOf("id" to id.toString())).get().build()).execute().use { response ->
            val texto = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw errorDe(response)
            val json = JSONObject(texto)
            if (!json.booleano("ok")) throw ApiException(json.text("error").ifBlank { "No se pudo cargar la venta." })
            ventaDetalleDeJson(json)
        }
    }

    suspend fun historial(): HistorialData = withContext(Dispatchers.IO) {
        cliente.newCall(request("/api/entregas/v1/historial").get().build()).execute().use { response ->
            val texto = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw errorDe(response)
            val json = JSONObject(texto)
            if (!json.booleano("ok")) throw ApiException(json.text("error").ifBlank { "No se pudo cargar el historial." })
            historialDeJson(json)
        }
    }

    /** Sube una operación de la cola o de captura directa. */
    suspend fun subir(item: QueueItem): JSONObject = withContext(Dispatchers.IO) {
        when (item.tipo) {
            "incidencia" -> postJson("/api/entregas/v1/incidencia", item)
            "entrega" -> postMultipart(item, "entrega")
            "intento" -> postMultipart(item, "intento")
            else -> throw ApiException("Tipo de operación desconocido.")
        }
    }

    private fun postJson(path: String, item: QueueItem): JSONObject {
        val cuerpo = JSONObject(item.payload.toString()).apply {
            put("venta_id", item.ventaId)
            put("token", item.token)
        }
        val request = request(path)
            .post(cuerpo.toString().toRequestBody(jsonMedia))
            .build()
        cliente.newCall(request).execute().use { response ->
            val texto = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw errorDe(response)
            return JSONObject(texto)
        }
    }

    private fun postMultipart(item: QueueItem, accion: String): JSONObject {
        val builder = MultipartBody.Builder().setType(MultipartBody.FORM)
        builder.addFormDataPart("venta_id", item.ventaId.toString())
        builder.addFormDataPart("token", item.token)
        item.payload.optString("fecha").takeIf { it.isNotBlank() }?.let { builder.addFormDataPart("fecha", it) }
        item.payload.optString("notas").takeIf { it.isNotBlank() }?.let { builder.addFormDataPart("notas", it) }
        item.payload.optString("recibio_nombre").takeIf { it.isNotBlank() }?.let { builder.addFormDataPart("recibio_nombre", it) }
        item.payload.optString("ubicacion").takeIf { it.isNotBlank() }?.let { builder.addFormDataPart("ubicacion", it) }
        item.payload.optString("motivo").takeIf { it.isNotBlank() }?.let { builder.addFormDataPart("motivo", it) }
        item.payload.optJSONObject("cantidades")?.let { builder.addFormDataPart("cantidades", it.toString()) }
        item.payload.optJSONArray("renglones")?.let { builder.addFormDataPart("renglones", it.toString()) }

        val meta = JSONArray()
        item.fotos.forEach { foto ->
            meta.put(JSONObject().apply {
                put("tomada_en", foto.tomadaEn)
                if (foto.latitud != null) put("latitud", foto.latitud)
                if (foto.longitud != null) put("longitud", foto.longitud)
            })
            val archivo = File(foto.ruta)
            if (archivo.isFile) {
                builder.addFormDataPart(
                    "fotos[]",
                    archivo.name,
                    archivo.asRequestBody("image/jpeg".toMediaTypeOrNull())
                )
            }
        }
        if (meta.length() > 0) builder.addFormDataPart("fotos_meta", meta.toString())

        val request = request("/api/entregas/v1/$accion")
            .post(builder.build())
            .build()
        cliente.newCall(request).execute().use { response ->
            val texto = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw errorDe(response)
            return JSONObject(texto)
        }
    }

    suspend fun descargarPdf(
        path: String,
        params: Map<String, String>,
    ): ByteArray = withContext(Dispatchers.IO) {
        cliente.newCall(
            request(path, params).get().build()
        ).execute().use { response ->
            if (!response.isSuccessful) throw errorDe(response)
            response.body?.bytes() ?: throw ApiException("El documento vino vacío.")
        }
    }

    suspend fun logout(): Unit = withContext(Dispatchers.IO) {
        runCatching {
            cliente.newCall(
                request("/api/entregas/v1/logout").post(ByteArray(0).toRequestBody(null)).build()
            ).execute().use { response ->
                if (!response.isSuccessful) throw errorDe(response)
            }
        }
    }
}
