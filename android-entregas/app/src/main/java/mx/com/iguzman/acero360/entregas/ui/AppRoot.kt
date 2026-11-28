package mx.com.iguzman.acero360.entregas.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import mx.com.iguzman.acero360.entregas.ui.screens.BloqueoScreen
import mx.com.iguzman.acero360.entregas.ui.screens.DetalleScreen
import mx.com.iguzman.acero360.entregas.ui.screens.EntregaScreen
import mx.com.iguzman.acero360.entregas.ui.screens.HistorialScreen
import mx.com.iguzman.acero360.entregas.ui.screens.IncidenciaScreen
import mx.com.iguzman.acero360.entregas.ui.screens.IntentoScreen
import mx.com.iguzman.acero360.entregas.ui.screens.IntroEntregasScreen
import mx.com.iguzman.acero360.entregas.ui.screens.LoginScreen
import mx.com.iguzman.acero360.entregas.ui.screens.PendientesScreen
import mx.com.iguzman.acero360.entregas.ui.screens.PerfilScreen

@Composable
fun AppRoot(
    modelo: AppViewModel,
    introCompletada: Boolean,
    alCompletarIntro: () -> Unit,
) {
    val estado by modelo.estado.collectAsStateWithLifecycle()

    // El contenido real se compone debajo de la bienvenida para que la salida
    // de la bóveda sea un fundido hacia la app, no un corte.
    Box(Modifier.fillMaxSize()) {
        ContenidoApp(modelo = modelo, estado = estado)
        if (!introCompletada && !estado.bloqueado) {
            IntroEntregasScreen(alTerminar = alCompletarIntro)
        }
        if (estado.generandoPdf) {
            DialogoGenerandoPdf(alCancelar = modelo::cancelarPdf)
        }
    }

    BackHandler(enabled = !introCompletada && !estado.bloqueado) {
        alCompletarIntro()
    }
}

/** Capa visible mientras el servidor compone el PDF y el teléfono lo recibe. */
@Composable
private fun DialogoGenerandoPdf(alCancelar: () -> Unit) {
    Dialog(onDismissRequest = { /* Se cierra al terminar o con Cancelar. */ }) {
        Surface(
            shape = RoundedCornerShape(16.dp),
            color = MaterialTheme.colorScheme.surface,
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                CircularProgressIndicator()
                Spacer(Modifier.height(16.dp))
                Text(
                    "Generando documento…",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    textAlign = TextAlign.Center,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    "Esto puede tardar unos segundos. El PDF se abrirá al terminar.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                )
                Spacer(Modifier.height(16.dp))
                TextButton(onClick = alCancelar) {
                    Text("Cancelar")
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ContenidoApp(modelo: AppViewModel, estado: UiState) {
    if (estado.bloqueado) {
        BloqueoScreen(
            estado = estado,
            alDesbloquear = modelo::desbloquear,
            alUsarContrasena = modelo::entrarConContrasena,
        )
        return
    }

    if (estado.iniciando) {
        Box(Modifier.fillMaxSize()) {
            PantallaCargando()
        }
        return
    }

    if (estado.pantalla is Pantalla.Login) {
        LoginScreen(
            estado = estado,
            alEntrar = modelo::iniciarSesion,
            alCerrarAviso = modelo::limpiarAviso,
        )
        return
    }

    BackHandler(enabled = estado.pantalla !is Pantalla.Pendientes) {
        modelo.volver()
    }

    val enDetalle = estado.pantalla is Pantalla.Detalle ||
        estado.pantalla is Pantalla.Entrega ||
        estado.pantalla is Pantalla.Incidencia ||
        estado.pantalla is Pantalla.Intento
    val enFormulario = estado.pantalla is Pantalla.Entrega ||
        estado.pantalla is Pantalla.Incidencia ||
        estado.pantalla is Pantalla.Intento

    val titulo = when (val p = estado.pantalla) {
        Pantalla.Pendientes -> "Pedidos pendientes"
        is Pantalla.Detalle -> estado.detalle?.venta?.folio ?: "Pedido"
        is Pantalla.Entrega -> "Registrar entrega"
        is Pantalla.Incidencia -> "Incidencia"
        is Pantalla.Intento -> "No se pudo entregar"
        Pantalla.Historial -> "Historial"
        Pantalla.Perfil -> "Perfil"
        Pantalla.Login -> ""
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(titulo, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        if (estado.cola > 0 && estado.pantalla is Pantalla.Pendientes) {
                            Text(
                                "${estado.cola} por sincronizar",
                                style = MaterialTheme.typography.labelSmall,
                                color = AceroAviso,
                            )
                        }
                    }
                },
                navigationIcon = {
                    if (enDetalle || enFormulario) {
                        IconButton(onClick = modelo::volver) {
                            Icon(Icons.Default.ArrowBack, contentDescription = "Volver")
                        }
                    }
                },
                actions = {
                    when (estado.pantalla) {
                        is Pantalla.Pendientes -> IconButton(onClick = { modelo.sincronizarAhora() }) {
                            Icon(Icons.Default.Refresh, contentDescription = "Sincronizar")
                        }
                        is Pantalla.Historial -> IconButton(onClick = { modelo.irHistorial() }) {
                            Icon(Icons.Default.Refresh, contentDescription = "Actualizar historial")
                        }
                        else -> Unit
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.surface,
                ),
            )
        },
        bottomBar = {
            val mostrar = estado.pantalla is Pantalla.Pendientes ||
                estado.pantalla is Pantalla.Historial ||
                estado.pantalla is Pantalla.Perfil
            if (mostrar) {
                NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
                    NavigationBarItem(
                        selected = estado.pantalla is Pantalla.Pendientes,
                        onClick = modelo::irPendientes,
                        icon = { Icon(Icons.Default.Home, contentDescription = null) },
                        label = { Text("Pendientes") },
                    )
                    NavigationBarItem(
                        selected = estado.pantalla is Pantalla.Historial,
                        onClick = modelo::irHistorial,
                        icon = { Icon(Icons.Default.List, contentDescription = null) },
                        label = { Text("Historial") },
                    )
                    NavigationBarItem(
                        selected = estado.pantalla is Pantalla.Perfil,
                        onClick = modelo::irPerfil,
                        icon = { Icon(Icons.Default.Person, contentDescription = null) },
                        label = { Text("Perfil") },
                    )
                }
            }
        },
    ) { relleno ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(relleno),
        ) {
            estado.aviso?.let { aviso ->
                AvisoBanner(aviso = aviso, alCerrar = modelo::limpiarAviso)
            }
            if (estado.offline) {
                BannerOffline()
            }
            Box(Modifier.fillMaxSize()) {
                when (val pantalla = estado.pantalla) {
                    Pantalla.Pendientes -> PendientesScreen(
                        estado = estado,
                        alAbrirVenta = modelo::abrirDetalle,
                        alActivarSeleccion = modelo::activarSeleccion,
                        alCancelarSeleccion = modelo::cancelarSeleccion,
                        alAlternarSeleccion = modelo::alternarSeleccion,
                        alBuscar = modelo::actualizarBusqueda,
                        alFiltrarEmpresa = modelo::actualizarFiltroEmpresa,
                        alGenerarPreparacion = { modelo.generarPdf("preparacion", ventaIds = estado.seleccion.toList()) },
                        alRefrescar = { modelo.cargarPendientes() },
                    )

                    is Pantalla.Detalle -> DetalleScreen(
                        estado = estado,
                        ventaId = pantalla.ventaId,
                        alRegistrarEntrega = { modelo.abrirEntrega(pantalla.ventaId) },
                        alRegistrarIncidencia = { modelo.abrirIncidencia(pantalla.ventaId) },
                        alRegistrarIntento = { modelo.abrirIntento(pantalla.ventaId) },
                        alPdfEntrega = { modelo.generarPdf("entrega", ventaId = pantalla.ventaId) },
                        alPdfActa = { modelo.generarPdf("acta", ventaId = pantalla.ventaId) },
                    )

                    is Pantalla.Entrega -> EntregaScreen(
                        estado = estado,
                        ventaId = pantalla.ventaId,
                        alEnviar = modelo::enviarEntrega,
                        alVolver = modelo::volver,
                    )

                    is Pantalla.Incidencia -> IncidenciaScreen(
                        estado = estado,
                        ventaId = pantalla.ventaId,
                        alEnviar = modelo::enviarIncidencia,
                    )

                    is Pantalla.Intento -> IntentoScreen(
                        estado = estado,
                        ventaId = pantalla.ventaId,
                        alEnviar = modelo::enviarIntento,
                    )

                    Pantalla.Historial -> HistorialScreen(
                        estado = estado,
                        alRefrescar = modelo::irHistorial,
                        alPdfActa = { modelo.generarPdf("acta", ventaId = it) },
                    )

                    Pantalla.Perfil -> PerfilScreen(
                        estado = estado,
                        alSincronizar = modelo::sincronizarAhora,
                        alCerrarSesion = modelo::cerrarSesion,
                        alReintentarCola = modelo::reintentarCola,
                        alDescartarCola = modelo::descartarCola,
                        alCambiarTema = modelo::cambiarTema,
                        alCambiarBiometria = modelo::cambiarBiometria,
                    )

                    Pantalla.Login -> Unit
                }

                if (estado.cargando) {
                    PantallaCargando()
                }
            }
        }
    }
}

@Composable
fun PantallaCargando() {
    Box(Modifier.fillMaxSize()) {
        androidx.compose.material3.CircularProgressIndicator(
            modifier = Modifier
                .align(androidx.compose.ui.Alignment.Center)
                .padding(24.dp),
        )
    }
}
