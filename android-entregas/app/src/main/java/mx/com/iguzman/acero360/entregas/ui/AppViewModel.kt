package mx.com.iguzman.acero360.entregas.ui

import android.app.Application
import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.os.Build
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import mx.com.iguzman.acero360.entregas.data.Api
import mx.com.iguzman.acero360.entregas.data.ApiException
import mx.com.iguzman.acero360.entregas.data.ClienteDetalle
import mx.com.iguzman.acero360.entregas.data.ClientePendiente
import mx.com.iguzman.acero360.entregas.data.HistorialData
import mx.com.iguzman.acero360.entregas.data.LineaVenta
import mx.com.iguzman.acero360.entregas.data.PendientesData
import mx.com.iguzman.acero360.entregas.data.PhotoMeta
import mx.com.iguzman.acero360.entregas.data.ProductoPendiente
import mx.com.iguzman.acero360.entregas.data.QueueItem
import mx.com.iguzman.acero360.entregas.data.QueueStore
import mx.com.iguzman.acero360.entregas.data.ResumenVenta
import mx.com.iguzman.acero360.entregas.data.SessionStore
import mx.com.iguzman.acero360.entregas.data.SyncWorker
import mx.com.iguzman.acero360.entregas.data.Usuario
import mx.com.iguzman.acero360.entregas.data.VentaCabecera
import mx.com.iguzman.acero360.entregas.data.VentaDetalleData
import mx.com.iguzman.acero360.entregas.data.VentaResumen
import mx.com.iguzman.acero360.entregas.util.PdfHelper
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter

data class RenglonCaptura(
    val productoId: Int,
    val cantidad: Int,
    val cambio: Int = 0,
)

private enum class ResultadoEnvio { ENVIADO, ENCOLADO, ERROR }

class AppViewModel(app: Application) : AndroidViewModel(app) {

    private val contexto: Context = app.applicationContext
    private val session = SessionStore(contexto)
    private val api = Api(session)
    private val queue = QueueStore(session)
    private val mutexSync = Mutex()
    private var ultimoErrorOperacion: String? = null

    private val _estado = MutableStateFlow(
        UiState(
            servidor = session.serverUrl,
            cola = queue.contar(),
            colaItems = queue.listar(),
            ultimaSync = session.lastSync,
        )
    )
    val estado = _estado.asStateFlow()

    private val manejadorRed = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            _estado.update { it.copy(offline = false) }
            if (session.token.isNotBlank()) {
                viewModelScope.launch {
                    if (queue.contar() > 0) sincronizarAhora() else cargarPendientes(true)
                }
            }
        }

        override fun onLost(network: Network) {
            _estado.update { it.copy(offline = true) }
        }
    }

    init {
        runCatching {
            val cm = contexto.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            cm.registerDefaultNetworkCallback(manejadorRed)
        }
        _estado.update { it.copy(offline = !hayRed()) }
        SyncWorker.programarPeriodico(contexto)
        autoEntrar()
    }

    override fun onCleared() {
        runCatching {
            val cm = contexto.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            cm.unregisterNetworkCallback(manejadorRed)
        }
        super.onCleared()
    }

    /* ------------------------------------------------------------------
     * Sesión
     * ---------------------------------------------------------------- */

    private fun autoEntrar() {
        if (session.token.isBlank()) {
            _estado.update {
                it.copy(iniciando = false, pantalla = Pantalla.Login, servidor = session.serverUrl)
            }
            return
        }

        val cache = api.pendientesDesdeCache()?.let { filtrarCola(it) }
        val usuarioCache = session.usuario
        if (usuarioCache != null) {
            _estado.update {
                it.copy(
                    iniciando = false,
                    usuario = usuarioCache,
                    pendientes = cache,
                    pantalla = Pantalla.Pendientes,
                    servidor = session.serverUrl,
                )
            }
        }

        viewModelScope.launch {
            try {
                val usuario = api.yo()
                session.usuario = usuario
                _estado.update {
                    it.copy(
                        iniciando = false,
                        usuario = usuario,
                        pantalla = if (it.usuario == null) Pantalla.Pendientes else it.pantalla,
                        servidor = session.serverUrl,
                    )
                }
                SyncWorker.programarPeriodico(contexto)
                cargarPendientes(true)
                if (queue.contar() > 0 && hayRed()) sincronizarAhora()
            } catch (e: ApiException) {
                if (e.codigo == 401) {
                    sesionExpirada("Tu sesión caducó. Inicia sesión de nuevo.")
                } else if (usuarioCache == null) {
                    _estado.update {
                        it.copy(iniciando = false, pantalla = Pantalla.Login, aviso = Aviso(e.message ?: "No se pudo conectar.", true))
                    }
                } else {
                    _estado.update { it.copy(iniciando = false, offline = true) }
                }
            } catch (e: Exception) {
                if (usuarioCache == null) {
                    _estado.update {
                        it.copy(iniciando = false, pantalla = Pantalla.Login, aviso = Aviso("No se pudo conectar con el servidor.", true))
                    }
                } else {
                    _estado.update { it.copy(iniciando = false, offline = true) }
                }
            }
        }
    }

    fun iniciarSesion(servidor: String, email: String, password: String) {
        if (email.isBlank() || password.isBlank()) {
            avisar("Escribe tu correo y contraseña.", true)
            return
        }
        _estado.update { it.copy(cargando = true, aviso = null, servidor = servidor) }
        viewModelScope.launch {
            try {
                val datos = api.login(
                    servidor = servidor,
                    email = email.trim(),
                    password = password,
                    dispositivo = "${Build.MANUFACTURER} ${Build.MODEL}".trim(),
                )
                session.guardarLogin(datos.token, datos.usuario)
                _estado.update {
                    it.copy(
                        cargando = false,
                        iniciando = false,
                        usuario = datos.usuario,
                        pantalla = Pantalla.Pendientes,
                        servidor = session.serverUrl,
                        cola = queue.contar(),
                        colaItems = queue.listar(),
                        aviso = null,
                    )
                }
                SyncWorker.programarPeriodico(contexto)
                cargarPendientes(true)
                if (queue.contar() > 0) sincronizarAhora()
            } catch (e: ApiException) {
                _estado.update { it.copy(cargando = false, aviso = Aviso(e.message ?: "No se pudo iniciar sesión.", true)) }
            } catch (e: Exception) {
                _estado.update { it.copy(cargando = false, aviso = Aviso("No se pudo conectar con el servidor.", true)) }
            }
        }
    }

    fun cerrarSesion() {
        if (queue.contar() > 0) {
            avisar("Tienes ${queue.contar()} operación(es) sin sincronizar. Sincroniza antes de cerrar sesión.", true)
            return
        }
        viewModelScope.launch {
            api.logout()
            session.cerrarSesion()
            _estado.value = UiState(
                iniciando = false,
                servidor = session.serverUrl,
                pantalla = Pantalla.Login,
            )
        }
    }

    private fun sesionExpirada(mensaje: String) {
        session.cerrarSesion()
        _estado.value = UiState(
            iniciando = false,
            servidor = session.serverUrl,
            pantalla = Pantalla.Login,
            aviso = Aviso(mensaje, true),
        )
    }

    /* ------------------------------------------------------------------
     * Pendientes y detalle
     * ---------------------------------------------------------------- */

    fun cargarPendientes(silencioso: Boolean = false) {
        viewModelScope.launch { cargarPendientesSuspend(silencioso) }
    }

    private suspend fun cargarPendientesSuspend(silencioso: Boolean) {
        if (!silencioso) _estado.update { it.copy(cargando = true) }
        if (!hayRed()) {
            val cache = api.pendientesDesdeCache()?.let { filtrarCola(it) }
            _estado.update {
                it.copy(
                    cargando = false,
                    offline = true,
                    pendientes = cache ?: it.pendientes,
                    aviso = if (cache == null && it.pendientes == null) {
                        Aviso("Sin conexión y sin datos guardados. Conéctate para cargar los pendientes.", true)
                    } else it.aviso,
                )
            }
            return
        }

        try {
            val datos = api.pendientes()
            session.lastSync = QueueStore.ahora()
            _estado.update {
                it.copy(
                    cargando = false,
                    offline = false,
                    pendientes = filtrarCola(datos),
                    ultimaSync = session.lastSync,
                )
            }
        } catch (e: ApiException) {
            if (e.codigo == 401) {
                sesionExpirada("Tu sesión caducó. Inicia sesión de nuevo.")
                return
            }
            val cache = api.pendientesDesdeCache()?.let { filtrarCola(it) }
            _estado.update {
                it.copy(
                    cargando = false,
                    offline = true,
                    pendientes = cache ?: it.pendientes,
                    aviso = if (silencioso) it.aviso else Aviso(e.message ?: "No se pudieron actualizar los pendientes.", true),
                )
            }
        } catch (e: Exception) {
            val cache = api.pendientesDesdeCache()?.let { filtrarCola(it) }
            _estado.update {
                it.copy(
                    cargando = false,
                    offline = true,
                    pendientes = cache ?: it.pendientes,
                    aviso = if (silencioso) it.aviso else Aviso("Sin conexión. Se muestran los últimos datos guardados.", false),
                )
            }
        }
    }

    fun abrirDetalle(ventaId: Int) {
        _estado.update {
            it.copy(
                pantalla = Pantalla.Detalle(ventaId),
                detalle = null,
                cargando = true,
                aviso = null,
            )
        }
        if (!hayRed()) {
            val offline = detalleOffline(ventaId)
            _estado.update {
                if (offline == null) {
                    it.copy(
                        cargando = false,
                        pantalla = Pantalla.Pendientes,
                        aviso = Aviso("Este pedido no está guardado para trabajar sin conexión.", true),
                    )
                } else {
                    it.copy(cargando = false, offline = true, detalle = offline)
                }
            }
            return
        }

        viewModelScope.launch {
            try {
                val datos = api.venta(ventaId)
                _estado.update { it.copy(cargando = false, offline = false, detalle = datos) }
            } catch (e: ApiException) {
                if (e.codigo == 401) {
                    sesionExpirada("Tu sesión caducó. Inicia sesión de nuevo.")
                    return@launch
                }
                val offline = detalleOffline(ventaId)
                _estado.update {
                    if (offline != null) {
                        it.copy(cargando = false, offline = true, detalle = offline)
                    } else {
                        it.copy(
                            cargando = false,
                            pantalla = Pantalla.Pendientes,
                            aviso = Aviso(e.message ?: "No se pudo abrir el pedido.", true),
                        )
                    }
                }
                if (e.codigo in 400..499) cargarPendientesSuspend(true)
            } catch (e: Exception) {
                val offline = detalleOffline(ventaId)
                _estado.update {
                    if (offline != null) {
                        it.copy(cargando = false, offline = true, detalle = offline)
                    } else {
                        it.copy(
                            cargando = false,
                            pantalla = Pantalla.Pendientes,
                            aviso = Aviso("Sin conexión. No hay datos guardados de este pedido.", true),
                        )
                    }
                }
            }
        }
    }

    private fun detalleOffline(ventaId: Int): VentaDetalleData? {
        val pendientes = _estado.value.pendientes ?: return null
        val cliente = pendientes.clientes.firstOrNull { c -> c.ventas.any { it.id == ventaId } } ?: return null
        val venta = cliente.ventas.firstOrNull { it.id == ventaId } ?: return null
        if (venta.lineas.isEmpty()) return null

        return VentaDetalleData(
            venta = VentaCabecera(
                id = venta.id,
                folio = venta.folio,
                fecha = venta.fecha,
                entregaFecha = venta.entregaFecha,
                entregaObservaciones = "",
                entregaEstado = venta.entregaEstado,
                condicionesEnvio = "",
                comentarios = "",
                empresaId = venta.empresaId,
                empresaNombre = venta.empresaNombre,
                empresaLogo = "",
            ),
            cliente = ClienteDetalle(
                id = cliente.id,
                nombre = cliente.contacto.ifBlank { cliente.nombre },
                empresa = cliente.nombre,
                telefono = cliente.telefono,
                email = "",
                direccion = cliente.direccion,
                mapa = cliente.mapa,
            ),
            lineas = venta.lineas,
            historial = emptyList(),
            incidencias = emptyList(),
            intentos = emptyList(),
            resumen = ResumenVenta(
                vendido = venta.piezas,
                entregado = venta.entregadas,
                pendiente = venta.pendiente,
                incidenciasAbiertas = venta.incidenciasAbiertas,
            ),
            puedeEntregar = venta.pendiente > 0,
        )
    }

    fun abrirEntrega(ventaId: Int) {
        _estado.update { it.copy(pantalla = Pantalla.Entrega(ventaId), aviso = null) }
    }

    fun abrirIncidencia(ventaId: Int) {
        _estado.update { it.copy(pantalla = Pantalla.Incidencia(ventaId), aviso = null) }
    }

    fun abrirIntento(ventaId: Int) {
        _estado.update { it.copy(pantalla = Pantalla.Intento(ventaId), aviso = null) }
    }

    fun irPendientes() {
        _estado.update { it.copy(pantalla = Pantalla.Pendientes, detalle = null, aviso = null) }
    }

    fun irHistorial() {
        _estado.update { it.copy(pantalla = Pantalla.Historial, cargando = true, aviso = null) }
        viewModelScope.launch {
            if (!hayRed()) {
                _estado.update {
                    it.copy(
                        cargando = false,
                        offline = true,
                        aviso = Aviso("Sin conexión. El historial se consulta cuando vuelvas a conectarte.", true),
                    )
                }
                return@launch
            }
            try {
                val datos = api.historial()
                _estado.update { it.copy(cargando = false, historial = datos, offline = false) }
            } catch (e: ApiException) {
                if (e.codigo == 401) sesionExpirada("Tu sesión caducó. Inicia sesión de nuevo.")
                else _estado.update { it.copy(cargando = false, aviso = Aviso(e.message ?: "No se pudo cargar el historial.", true)) }
            } catch (e: Exception) {
                _estado.update { it.copy(cargando = false, aviso = Aviso("Sin conexión. No se pudo cargar el historial.", true)) }
            }
        }
    }

    fun irPerfil() {
        _estado.update { it.copy(pantalla = Pantalla.Perfil, aviso = null) }
    }

    fun volver() {
        val actual = _estado.value.pantalla
        when (actual) {
            is Pantalla.Detalle -> irPendientes()
            is Pantalla.Entrega -> _estado.update { it.copy(pantalla = Pantalla.Detalle(actual.ventaId), aviso = null) }
            is Pantalla.Incidencia -> _estado.update { it.copy(pantalla = Pantalla.Detalle(actual.ventaId), aviso = null) }
            is Pantalla.Intento -> _estado.update { it.copy(pantalla = Pantalla.Detalle(actual.ventaId), aviso = null) }
            Pantalla.Historial, Pantalla.Perfil -> irPendientes()
            else -> Unit
        }
    }

    fun actualizarBusqueda(texto: String) {
        _estado.update { it.copy(busqueda = texto) }
    }

    fun actualizarFiltroEmpresa(empresa: String) {
        _estado.update { it.copy(filtroEmpresa = empresa) }
    }

    /* ------------------------------------------------------------------
     * Selección para la lista de preparación
     * ---------------------------------------------------------------- */

    fun activarSeleccion() {
        _estado.update { it.copy(modoSeleccion = true, seleccion = emptySet()) }
    }

    fun cancelarSeleccion() {
        _estado.update { it.copy(modoSeleccion = false, seleccion = emptySet()) }
    }

    fun alternarSeleccion(ventaId: Int) {
        _estado.update {
            val nueva = if (it.seleccion.contains(ventaId)) it.seleccion - ventaId else it.seleccion + ventaId
            it.copy(seleccion = nueva)
        }
    }

    /* ------------------------------------------------------------------
     * Registro de entrega / incidencia / visita
     * ---------------------------------------------------------------- */

    fun enviarEntrega(
        ventaId: Int,
        cantidades: Map<Int, Int>,
        recibio: String,
        notas: String,
        ubicacion: String,
        fotos: List<PhotoMeta>,
    ) {
        if (_estado.value.guardando) return
        val validas = cantidades.filterValues { it > 0 }
        if (validas.isEmpty()) {
            avisar("Captura al menos una pieza entregada.", true)
            return
        }
        if (fotos.size > 8) {
            avisar("Se admiten como máximo 8 fotos por entrega.", true)
            return
        }

        val cantidadesJson = JSONObject()
        validas.forEach { (productoId, cantidad) -> cantidadesJson.put(productoId.toString(), cantidad) }
        val payload = JSONObject().apply {
            put("fecha", LocalDate.now().toString())
            put("notas", notas.trim())
            put("recibio_nombre", recibio.trim())
            put("ubicacion", ubicacion.trim())
            put("cantidades", cantidadesJson)
        }
        val item = QueueItem(
            token = QueueStore.nuevoToken(),
            tipo = "entrega",
            ventaId = ventaId,
            payload = payload,
            fotos = fotos,
            creadoEn = QueueStore.ahora(),
        )

        _estado.update { it.copy(guardando = true, aviso = null) }
        viewModelScope.launch {
            when (subirOEncolar(item)) {
                ResultadoEnvio.ENVIADO -> {
                    borrarFotos(item)
                    filtrarVentaLocal(ventaId)
                    _estado.update { it.copy(guardando = false, pantalla = Pantalla.Pendientes, aviso = Aviso("Entrega registrada. El acta está disponible en Historial.")) }
                    cargarPendientesSuspend(true)
                }
                ResultadoEnvio.ENCOLADO -> {
                    filtrarVentaLocal(ventaId)
                    _estado.update {
                        it.copy(
                            guardando = false,
                            pantalla = Pantalla.Pendientes,
                            aviso = Aviso("Entrega guardada. Se enviará automáticamente al recuperar internet."),
                        )
                    }
                    SyncWorker.programarAhora(contexto)
                }
                ResultadoEnvio.ERROR -> {
                    _estado.update { it.copy(guardando = false) }
                    if (session.token.isNotBlank()) cargarPendientes(true)
                }
            }
        }
    }

    fun enviarIncidencia(
        ventaId: Int,
        motivo: String,
        renglones: List<RenglonCaptura>,
        notas: String,
    ) {
        if (_estado.value.guardando) return
        if (motivo.isBlank()) {
            avisar("Elige el motivo de la incidencia.", true)
            return
        }
        val validos = renglones.filter { it.cantidad > 0 }
        if (validos.isEmpty()) {
            avisar("Indica al menos un producto afectado.", true)
            return
        }
        val arr = JSONArray()
        validos.forEach {
            arr.put(JSONObject().apply {
                put("producto_id", it.productoId)
                put("cantidad", it.cantidad)
                put("cambio", it.cambio)
            })
        }
        val payload = JSONObject().apply {
            put("motivo", motivo)
            put("notas", notas.trim())
            put("renglones", arr)
        }
        val item = QueueItem(
            token = QueueStore.nuevoToken(),
            tipo = "incidencia",
            ventaId = ventaId,
            payload = payload,
            fotos = emptyList(),
            creadoEn = QueueStore.ahora(),
        )

        _estado.update { it.copy(guardando = true, aviso = null) }
        viewModelScope.launch {
            when (subirOEncolar(item)) {
                ResultadoEnvio.ENVIADO -> {
                    _estado.update { it.copy(guardando = false, pantalla = Pantalla.Detalle(ventaId)) }
                    abrirDetalle(ventaId)
                    _estado.update { it.copy(aviso = Aviso("Incidencia registrada. La oficina revisará el inventario y la factura si aplica.")) }
                }
                ResultadoEnvio.ENCOLADO -> {
                    _estado.update {
                        it.copy(
                            guardando = false,
                            pantalla = Pantalla.Detalle(ventaId),
                            aviso = Aviso("Incidencia guardada. Se enviará automáticamente al recuperar internet."),
                        )
                    }
                    SyncWorker.programarAhora(contexto)
                }
                ResultadoEnvio.ERROR -> {
                    _estado.update { it.copy(guardando = false) }
                    if (session.token.isNotBlank()) {
                        val mensaje = ultimoErrorOperacion ?: "No se pudo registrar la incidencia."
                        abrirDetalle(ventaId)
                        _estado.update { it.copy(aviso = Aviso(mensaje, true)) }
                    }
                }
            }
        }
    }

    fun enviarIntento(
        ventaId: Int,
        motivo: String,
        notas: String,
        ubicacion: String,
        fotos: List<PhotoMeta>,
    ) {
        if (_estado.value.guardando) return
        if (motivo.isBlank()) {
            avisar("Elige el motivo por el que no se pudo entregar.", true)
            return
        }
        val payload = JSONObject().apply {
            put("motivo", motivo)
            put("notas", notas.trim())
            put("ubicacion", ubicacion.trim())
        }
        val item = QueueItem(
            token = QueueStore.nuevoToken(),
            tipo = "intento",
            ventaId = ventaId,
            payload = payload,
            fotos = fotos,
            creadoEn = QueueStore.ahora(),
        )

        _estado.update { it.copy(guardando = true, aviso = null) }
        viewModelScope.launch {
            when (subirOEncolar(item)) {
                ResultadoEnvio.ENVIADO -> {
                    borrarFotos(item)
                    _estado.update { it.copy(guardando = false, pantalla = Pantalla.Detalle(ventaId)) }
                    abrirDetalle(ventaId)
                    _estado.update { it.copy(aviso = Aviso("Visita registrada. El pedido sigue pendiente de entrega.")) }
                }
                ResultadoEnvio.ENCOLADO -> {
                    _estado.update {
                        it.copy(
                            guardando = false,
                            pantalla = Pantalla.Detalle(ventaId),
                            aviso = Aviso("Visita guardada. Se enviará automáticamente al recuperar internet."),
                        )
                    }
                    SyncWorker.programarAhora(contexto)
                }
                ResultadoEnvio.ERROR -> {
                    _estado.update { it.copy(guardando = false) }
                    if (session.token.isNotBlank()) {
                        val mensaje = ultimoErrorOperacion ?: "No se pudo registrar la visita."
                        abrirDetalle(ventaId)
                        _estado.update { it.copy(aviso = Aviso(mensaje, true)) }
                    }
                }
            }
        }
    }

    private suspend fun subirOEncolar(item: QueueItem): ResultadoEnvio {
        ultimoErrorOperacion = null
        if (!hayRed()) {
            queue.agregar(item)
            actualizarCola()
            return ResultadoEnvio.ENCOLADO
        }
        return try {
            api.subir(item)
            ResultadoEnvio.ENVIADO
        } catch (e: ApiException) {
            if (e.codigo == 401) {
                sesionExpirada("Tu sesión caducó. Inicia sesión de nuevo.")
                ResultadoEnvio.ERROR
            } else if (e.codigo in 400..499) {
                ultimoErrorOperacion = e.message ?: "No se pudo registrar la operación."
                avisar(ultimoErrorOperacion!!, true)
                ResultadoEnvio.ERROR
            } else {
                queue.agregar(item)
                actualizarCola()
                ResultadoEnvio.ENCOLADO
            }
        } catch (e: Exception) {
            queue.agregar(item)
            actualizarCola()
            ResultadoEnvio.ENCOLADO
        }
    }

    private fun borrarFotos(item: QueueItem) {
        item.fotos.forEach { foto -> runCatching { File(foto.ruta).delete() } }
    }

    /* ------------------------------------------------------------------
     * Cola y sincronización
     * ---------------------------------------------------------------- */

    fun sincronizarAhora() {
        if (!hayRed()) {
            avisar("Sin conexión. Las operaciones se enviarán cuando vuelvas a conectarte.", true)
            return
        }
        if (_estado.value.sincronizando) return
        viewModelScope.launch {
            mutexSync.withLock {
                _estado.update { it.copy(sincronizando = true, aviso = null) }
                var enviadas = 0
                var errores = 0
                var interrumpido = false
                val enCola = queue.listar()
                val conError = enCola.count { it.error != null }
                val items = enCola.filter { it.error == null }
                for (item in items) {
                    try {
                        api.subir(item)
                        queue.quitar(item.token)
                        borrarFotos(item)
                        enviadas++
                    } catch (e: ApiException) {
                        if (e.codigo == 401) {
                            sesionExpirada("Tu sesión caducó. Inicia sesión de nuevo.")
                            _estado.update { it.copy(sincronizando = false) }
                            return@withLock
                        }
                        if (e.codigo in 400..499) {
                            queue.marcarError(item.token, e.message ?: "Error de datos.")
                            errores++
                        } else {
                            interrumpido = true
                            break
                        }
                    } catch (e: Exception) {
                        interrumpido = true
                        break
                    }
                }
                actualizarCola()
                _estado.update {
                    it.copy(
                        sincronizando = false,
                        ultimaSync = session.lastSync,
                        aviso = when {
                            interrumpido -> Aviso("No se pudo completar la sincronización. Se reintentará al recuperar la conexión.", true)
                            enviadas > 0 && errores > 0 -> Aviso("Se enviaron $enviadas operación(es); $errores quedaron con error. Revisa el perfil.")
                            enviadas > 0 -> Aviso("Se sincronizaron $enviadas operación(es).")
                            errores > 0 || conError > 0 -> Aviso("Hay ${maxOf(errores, conError)} operación(es) con error. Revisa el perfil.", true)
                            else -> Aviso("No había operaciones pendientes.")
                        },
                    )
                }
                cargarPendientesSuspend(true)
            }
        }
    }

    fun reintentarCola() {
        queue.listar().filter { it.error != null }.forEach { queue.limpiarError(it.token) }
        actualizarCola()
        sincronizarAhora()
    }

    fun descartarCola(token: String) {
        val item = queue.listar().firstOrNull { it.token == token }
        queue.quitar(token)
        item?.let { borrarFotos(it) }
        actualizarCola()
        avisar("Operación descartada.")
        cargarPendientes(true)
    }

    private fun actualizarCola() {
        val items = queue.listar()
        _estado.update { it.copy(cola = items.size, colaItems = items) }
    }

    /* ------------------------------------------------------------------
     * PDF
     * ---------------------------------------------------------------- */

    fun generarPdf(tipo: String, ventaId: Int? = null, ventaIds: List<Int> = emptyList()) {
        if (!hayRed()) {
            avisar("Conéctate para generar el documento.", true)
            return
        }
        if (tipo == "preparacion" && ventaIds.isEmpty()) {
            avisar("Selecciona al menos un pedido para la lista de preparación.", true)
            return
        }
        _estado.update { it.copy(generandoPdf = true, aviso = null) }
        viewModelScope.launch {
            try {
                val (path, params, nombre) = when (tipo) {
                    "preparacion" -> Triple(
                        "/api/entregas/v1/pdf/preparacion",
                        mapOf("ventas" to ventaIds.joinToString(",")),
                        "preparacion-${LocalDate.now()}.pdf",
                    )
                    "acta" -> Triple(
                        "/api/entregas/v1/pdf/acta",
                        mapOf("venta" to (ventaId ?: 0).toString()),
                        "acta-entrega-${folio(ventaId)}.pdf",
                    )
                    else -> Triple(
                        "/api/entregas/v1/pdf/entrega",
                        mapOf("venta" to (ventaId ?: 0).toString()),
                        "hoja-entrega-${folio(ventaId)}.pdf",
                    )
                }
                val bytes = api.descargarPdf(path, params)
                val uri = withContext(Dispatchers.IO) { PdfHelper.guardar(contexto, bytes, nombre) }
                PdfHelper.abrir(contexto, uri)
                _estado.update { it.copy(generandoPdf = false, aviso = Aviso("Documento listo.")) }
            } catch (e: ApiException) {
                if (e.codigo == 401) sesionExpirada("Tu sesión caducó. Inicia sesión de nuevo.")
                else _estado.update { it.copy(generandoPdf = false, aviso = Aviso(e.message ?: "No se pudo generar el documento.", true)) }
            } catch (e: Exception) {
                _estado.update { it.copy(generandoPdf = false, aviso = Aviso("No se pudo generar el documento.", true)) }
            }
        }
    }

    private fun folio(ventaId: Int?): String {
        if (ventaId == null) return "documento"
        val directo = _estado.value.detalle?.venta?.folio
        if (directo != null && _estado.value.detalle?.venta?.id == ventaId) return directo
        val pendientes = _estado.value.pendientes ?: return "venta-$ventaId"
        pendientes.clientes.forEach { cliente ->
            cliente.ventas.firstOrNull { it.id == ventaId }?.let { return it.folio }
        }
        return "venta-$ventaId"
    }

    /* ------------------------------------------------------------------
     * Utilidades
     * ---------------------------------------------------------------- */

    fun limpiarAviso() {
        _estado.update { it.copy(aviso = null) }
    }

    fun onResume() {
        if (session.token.isBlank()) return
        _estado.update { it.copy(offline = !hayRed()) }
        if (queue.contar() > 0 && hayRed()) {
            sincronizarAhora()
        } else {
            cargarPendientes(true)
        }
    }

    private fun avisar(texto: String, error: Boolean = false) {
        _estado.update { it.copy(aviso = Aviso(texto, error)) }
    }

    private fun hayRed(): Boolean {
        val cm = contexto.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return false
        val red = cm.activeNetwork ?: return false
        val capacidades = cm.getNetworkCapabilities(red) ?: return false
        return capacidades.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    private fun filtrarVentaLocal(ventaId: Int) {
        _estado.update {
            it.copy(pendientes = it.pendientes?.let { datos -> filtrarVentas(datos, setOf(ventaId)) })
        }
    }

    private fun filtrarCola(datos: PendientesData): PendientesData {
        val ids = queue.listar()
            .filter { it.tipo == "entrega" && it.error == null }
            .map { it.ventaId }
            .toSet()
        return if (ids.isEmpty()) datos else filtrarVentas(datos, ids)
    }

    private fun filtrarVentas(datos: PendientesData, ids: Set<Int>): PendientesData {
        if (ids.isEmpty()) return datos
        val clientes = datos.clientes.mapNotNull { cliente ->
            val ventas = cliente.ventas.filterNot { ids.contains(it.id) }
            if (ventas.isEmpty()) {
                null
            } else {
                val productos = mutableMapOf<Int, ProductoPendiente>()
                ventas.flatMap { it.lineas }
                    .filter { it.pendiente > 0 }
                    .forEach { linea ->
                        val actual = productos[linea.productoId]
                        productos[linea.productoId] = if (actual == null) {
                            ProductoPendiente(
                                id = linea.productoId,
                                sku = linea.sku,
                                nombre = linea.nombre,
                                unidad = linea.unidad,
                                imagen = linea.imagen,
                                total = linea.pendiente,
                            )
                        } else {
                            actual.copy(total = actual.total + linea.pendiente)
                        }
                    }
                cliente.copy(
                    ventas = ventas,
                    piezas = ventas.sumOf { it.pendiente },
                    productos = productos.values.toList(),
                )
            }
        }
        return datos.copy(
            clientes = clientes,
            totalClientes = clientes.size,
            totalVentas = clientes.sumOf { it.ventas.size },
            totalPiezas = clientes.sumOf { it.piezas },
        )
    }
}
