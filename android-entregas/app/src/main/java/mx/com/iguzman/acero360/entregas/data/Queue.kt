package mx.com.iguzman.acero360.entregas.data

import org.json.JSONArray
import org.json.JSONObject
import java.security.SecureRandom
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter

/** Operación pendiente de subir (entrega, incidencia o visita fallida). */
data class QueueItem(
    val token: String,
    val tipo: String,
    val ventaId: Int,
    val payload: JSONObject,
    val fotos: List<PhotoMeta>,
    val creadoEn: String,
    val error: String? = null,
) {
    fun aJson(): JSONObject = JSONObject().apply {
        put("token", token)
        put("tipo", tipo)
        put("venta_id", ventaId)
        put("payload", payload)
        val fotosJson = JSONArray()
        fotos.forEach { foto ->
            fotosJson.put(JSONObject().apply {
                put("ruta", foto.ruta)
                put("tomada_en", foto.tomadaEn)
                if (foto.latitud != null) put("latitud", foto.latitud) else put("latitud", JSONObject.NULL)
                if (foto.longitud != null) put("longitud", foto.longitud) else put("longitud", JSONObject.NULL)
            })
        }
        put("fotos", fotosJson)
        put("creado_en", creadoEn)
        put("error", error ?: JSONObject.NULL)
    }

    companion object {
        fun deJson(json: JSONObject): QueueItem? {
            val token = json.text("token")
            val tipo = json.text("tipo")
            if (token.isBlank() || tipo.isBlank()) return null
            val fotos = mutableListOf<PhotoMeta>()
            json.optJSONArray("fotos")?.let { arr ->
                for (i in 0 until arr.length()) {
                    val f = arr.optJSONObject(i) ?: continue
                    fotos.add(PhotoMeta(
                        ruta = f.text("ruta"),
                        tomadaEn = f.text("tomada_en"),
                        latitud = if (f.isNull("latitud")) null else f.optDouble("latitud"),
                        longitud = if (f.isNull("longitud")) null else f.optDouble("longitud"),
                    ))
                }
            }
            return QueueItem(
                token = token,
                tipo = tipo,
                ventaId = json.entero("venta_id"),
                payload = json.optJSONObject("payload") ?: JSONObject(),
                fotos = fotos,
                creadoEn = json.text("creado_en"),
                error = if (json.isNull("error")) null else json.text("error").ifBlank { null },
            )
        }
    }
}

class QueueStore(private val session: SessionStore) {

    fun listar(): MutableList<QueueItem> {
        val raw = session.queueJson
        val lista = mutableListOf<QueueItem>()
        runCatching {
            val arr = JSONArray(raw)
            for (i in 0 until arr.length()) {
                val item = arr.optJSONObject(i) ?: continue
                QueueItem.deJson(item)?.let { lista.add(it) }
            }
        }
        return lista
    }

    fun guardar(lista: List<QueueItem>) {
        val arr = JSONArray()
        lista.forEach { arr.put(it.aJson()) }
        session.queueJson = arr.toString()
    }

    fun agregar(item: QueueItem) {
        val lista = listar()
        if (lista.none { it.token == item.token }) {
            lista.add(item)
            guardar(lista)
        }
    }

    fun quitar(token: String) {
        guardar(listar().filterNot { it.token == token })
    }

    fun marcarError(token: String, error: String) {
        guardar(listar().map { if (it.token == token) it.copy(error = error) else it })
    }

    fun limpiarError(token: String) {
        guardar(listar().map { if (it.token == token) it.copy(error = null) else it })
    }

    fun contar(): Int = listar().size

    fun pendientesDeVenta(ventaId: Int): List<QueueItem> =
        listar().filter { it.ventaId == ventaId && it.error == null }

    companion object {
        fun nuevoToken(): String {
            val bytes = ByteArray(16)
            SecureRandom().nextBytes(bytes)
            return bytes.joinToString("") { "%02x".format(it.toInt() and 0xFF) }
        }

        fun ahora(): String =
            LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
    }
}
