package mx.com.iguzman.acero360.entregas.ui

import mx.com.iguzman.acero360.entregas.data.HistorialData
import mx.com.iguzman.acero360.entregas.data.PendientesData
import mx.com.iguzman.acero360.entregas.data.QueueItem
import mx.com.iguzman.acero360.entregas.data.Usuario
import mx.com.iguzman.acero360.entregas.data.VentaDetalleData

sealed interface Pantalla {
    data object Login : Pantalla
    data object Pendientes : Pantalla
    data class Detalle(val ventaId: Int) : Pantalla
    data class Entrega(val ventaId: Int) : Pantalla
    data class Incidencia(val ventaId: Int) : Pantalla
    data class Intento(val ventaId: Int) : Pantalla
    data object Historial : Pantalla
    data object Perfil : Pantalla
}

data class Aviso(val texto: String, val error: Boolean = false)

data class UiState(
    val iniciando: Boolean = true,
    val cargando: Boolean = false,
    val guardando: Boolean = false,
    val sincronizando: Boolean = false,
    val generandoPdf: Boolean = false,
    val servidor: String = "https://cotizaciones.iguzman.com.mx",
    val usuario: Usuario? = null,
    val pendientes: PendientesData? = null,
    val detalle: VentaDetalleData? = null,
    val historial: HistorialData? = null,
    val pantalla: Pantalla = Pantalla.Login,
    val aviso: Aviso? = null,
    val offline: Boolean = false,
    val cola: Int = 0,
    val colaItems: List<QueueItem> = emptyList(),
    val ultimaSync: String = "",
    val busqueda: String = "",
    val filtroEmpresa: String = "Todos",
    val modoSeleccion: Boolean = false,
    val seleccion: Set<Int> = emptySet(),
)
