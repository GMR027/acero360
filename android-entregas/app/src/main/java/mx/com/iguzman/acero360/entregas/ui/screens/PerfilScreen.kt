package mx.com.iguzman.acero360.entregas.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import mx.com.iguzman.acero360.entregas.BuildConfig
import mx.com.iguzman.acero360.entregas.data.QueueItem
import mx.com.iguzman.acero360.entregas.ui.AceroAlerta
import mx.com.iguzman.acero360.entregas.ui.AceroAlertaSuave
import mx.com.iguzman.acero360.entregas.ui.AceroAviso
import mx.com.iguzman.acero360.entregas.ui.AceroAvisoSuave
import mx.com.iguzman.acero360.entregas.ui.AceroAzul
import mx.com.iguzman.acero360.entregas.ui.BotonPrincipal
import mx.com.iguzman.acero360.entregas.ui.DatoLinea
import mx.com.iguzman.acero360.entregas.ui.EtiquetaEstado
import mx.com.iguzman.acero360.entregas.ui.TituloSeccion
import mx.com.iguzman.acero360.entregas.ui.UiState
import mx.com.iguzman.acero360.entregas.ui.fechaHora

@Composable
fun PerfilScreen(
    estado: UiState,
    alSincronizar: () -> Unit,
    alCerrarSesion: () -> Unit,
    alReintentarCola: () -> Unit,
    alDescartarCola: (String) -> Unit,
) {
    var confirmarSalida by remember { mutableStateOf(false) }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Card(
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(Modifier.padding(16.dp)) {
                    Text(
                        estado.usuario?.nombre ?: "Usuario",
                        style = MaterialTheme.typography.titleLarge,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        estado.usuario?.email ?: "",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.height(10.dp))
                    DatoLinea("Servidor", estado.servidor)
                    DatoLinea("Última sync", if (estado.ultimaSync.isBlank()) "—" else fechaHora(estado.ultimaSync))
                    DatoLinea("Sin subir", estado.cola.toString())
                }
            }
        }

        item {
            Card(
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(Modifier.padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            "Sincronización",
                            style = MaterialTheme.typography.titleMedium,
                            modifier = Modifier.weight(1f),
                        )
                        EtiquetaEstado(
                            if (estado.offline) "Sin conexión" else "En línea",
                            if (estado.offline) AceroAviso else AceroAzul,
                            if (estado.offline) AceroAvisoSuave else MaterialTheme.colorScheme.primaryContainer,
                        )
                    }
                    Spacer(Modifier.height(8.dp))
                    if (estado.cola == 0) {
                        Text(
                            "No hay operaciones pendientes de subir.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    } else {
                        Text(
                            "${estado.cola} operación(es) esperando subir.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = AceroAviso,
                        )
                    }
                    Spacer(Modifier.height(10.dp))
                    BotonPrincipal(
                        texto = if (estado.sincronizando) "Sincronizando…" else "Sincronizar ahora",
                        onClick = alSincronizar,
                        cargando = estado.sincronizando,
                    )
                }
            }
        }

        if (estado.colaItems.isNotEmpty()) {
            item { TituloSeccion("Operaciones en cola") }
            items(estado.colaItems, key = { it.token }) { item ->
                ColaCard(
                    item = item,
                    alReintentar = alReintentarCola,
                    alDescartar = { alDescartarCola(item.token) },
                )
            }
        }

        item {
            TituloSeccion("Sesión")
            OutlinedButton(
                onClick = { confirmarSalida = true },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Cerrar sesión", color = AceroAlerta)
            }
            Text(
                "Si hay operaciones sin sincronizar, sincronízalas antes de salir.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(top = 4.dp),
            )
        }

        item {
            Text(
                "Acero Entregas ${BuildConfig.VERSION_NAME} · Acero 360",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(vertical = 16.dp),
            )
        }
    }

    if (confirmarSalida) {
        AlertDialog(
            onDismissRequest = { confirmarSalida = false },
            title = { Text("Cerrar sesión") },
            text = { Text("¿Quieres salir de la app?") },
            confirmButton = {
                TextButton(onClick = {
                    confirmarSalida = false
                    alCerrarSesion()
                }) { Text("Cerrar sesión", color = AceroAlerta) }
            },
            dismissButton = {
                TextButton(onClick = { confirmarSalida = false }) { Text("Cancelar") }
            },
        )
    }
}

@Composable
private fun ColaCard(
    item: QueueItem,
    alReintentar: () -> Unit,
    alDescartar: () -> Unit,
) {
    val error = item.error
    Card(
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (error == null) MaterialTheme.colorScheme.surface else AceroAlertaSuave,
        ),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    when (item.tipo) {
                        "entrega" -> "Entrega"
                        "incidencia" -> "Incidencia"
                        "intento" -> "Visita sin entrega"
                        else -> item.tipo
                    },
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f),
                )
                EtiquetaEstado(
                    if (error == null) "Pendiente" else "Con error",
                    if (error == null) AceroAviso else AceroAlerta,
                    if (error == null) AceroAvisoSuave else AceroAlertaSuave,
                )
            }
            Text(
                "Venta #${item.ventaId} · ${fechaHora(item.creadoEn)}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (!error.isNullOrBlank()) {
                Text(
                    error,
                    style = MaterialTheme.typography.bodySmall,
                    color = AceroAlerta,
                    modifier = Modifier.padding(top = 4.dp),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    TextButton(onClick = alReintentar) { Text("Reintentar todo") }
                    TextButton(onClick = alDescartar) { Text("Descartar", color = AceroAlerta) }
                }
            }
        }
    }
}
