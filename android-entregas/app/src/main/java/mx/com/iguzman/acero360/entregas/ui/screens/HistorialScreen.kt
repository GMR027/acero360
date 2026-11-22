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
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import mx.com.iguzman.acero360.entregas.data.HistorialEntrega
import mx.com.iguzman.acero360.entregas.data.IntentoEntrega
import mx.com.iguzman.acero360.entregas.ui.AceroAviso
import mx.com.iguzman.acero360.entregas.ui.AceroAvisoSuave
import mx.com.iguzman.acero360.entregas.ui.AceroAzul
import mx.com.iguzman.acero360.entregas.ui.AceroExito
import mx.com.iguzman.acero360.entregas.ui.AceroExitoSuave
import mx.com.iguzman.acero360.entregas.ui.EtiquetaEstado
import mx.com.iguzman.acero360.entregas.ui.MensajeVacio
import mx.com.iguzman.acero360.entregas.ui.TituloSeccion
import mx.com.iguzman.acero360.entregas.ui.UiState
import mx.com.iguzman.acero360.entregas.ui.fechaCorta
import mx.com.iguzman.acero360.entregas.ui.fechaHora

@Composable
fun HistorialScreen(
    estado: UiState,
    alRefrescar: () -> Unit,
    alPdfActa: (Int) -> Unit,
) {
    val datos = estado.historial
    if (datos == null || (datos.entregas.isEmpty() && datos.intentos.isEmpty())) {
        Column(Modifier.fillMaxSize()) {
            MensajeVacio(
                titulo = if (estado.cargando) "Cargando historial…" else "Sin movimientos",
                detalle = if (estado.cargando) "" else "Aquí aparecerán las entregas y visitas de todo el equipo.",
            )
            OutlinedButton(onClick = alRefrescar, modifier = Modifier.padding(16.dp)) {
                Text("Actualizar")
            }
        }
        return
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                OutlinedButton(onClick = alRefrescar) { Text("Actualizar") }
            }
        }

        if (datos.entregas.isNotEmpty()) {
            item { TituloSeccion("Entregas (${datos.entregas.size})") }
            items(datos.entregas, key = { "e-${it.id}" }) { entrega ->
                EntregaHistorialCard(entrega, alPdfActa)
            }
        }

        if (datos.intentos.isNotEmpty()) {
            item { TituloSeccion("Visitas sin entrega (${datos.intentos.size})") }
            items(datos.intentos, key = { "i-${it.id}" }) { intento ->
                IntentoHistorialCard(intento)
            }
        }
    }
}

@Composable
private fun EntregaHistorialCard(entrega: HistorialEntrega, alPdfActa: (Int) -> Unit) {
    Card(
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(12.dp)) {
            Row {
                Column(Modifier.weight(1f)) {
                    Text(
                        "${entrega.folio.ifBlank { "Venta" }} · ${entrega.cliente.ifBlank { "Cliente" }}",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        "${fechaCorta(entrega.fecha)} · ${entrega.usuarioNombre.ifBlank { "—" }}",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                EtiquetaEstado(if (entrega.origen == "app") "App" else "Web", AceroAzul)
            }
            if (entrega.recibioNombre.isNotBlank()) {
                Text(
                    "Recibió: ${entrega.recibioNombre}",
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 3.dp),
                )
            }
            if (entrega.renglones.isNotEmpty()) {
                Text(
                    entrega.renglones.take(3).joinToString(" · ") { "${it.cantidad} × ${it.nombre}" } +
                        if (entrega.renglones.size > 3) " …" else "",
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 3.dp),
                )
            }
            if (entrega.notas.isNotBlank()) {
                Text(
                    entrega.notas,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(top = 2.dp),
                )
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    if (entrega.fotos > 0) "${entrega.fotos} foto(s)" else "Sin fotos",
                    style = MaterialTheme.typography.labelSmall,
                    color = AceroAzul,
                    modifier = Modifier.padding(top = 4.dp),
                )
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        fechaHora(entrega.creadoEn),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(top = 4.dp),
                    )
                    if (entrega.ventaId > 0) {
                        OutlinedButton(
                            onClick = { alPdfActa(entrega.ventaId) },
                            modifier = Modifier.padding(start = 8.dp),
                        ) { Text("Acta", style = MaterialTheme.typography.labelSmall) }
                    }
                }
            }
        }
    }
}

@Composable
private fun IntentoHistorialCard(intento: IntentoEntrega) {
    Card(
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(12.dp)) {
            Row {
                Column(Modifier.weight(1f)) {
                    Text(
                        "${intento.folio.ifBlank { "Venta" }} · ${intento.cliente.ifBlank { "Cliente" }}",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        fechaHora(intento.fecha),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                EtiquetaEstado(intento.motivoEtiqueta, AceroAviso, AceroAvisoSuave)
            }
            if (intento.notas.isNotBlank()) {
                Text(
                    intento.notas,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 3.dp),
                )
            }
            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(
                    "Registró ${intento.usuarioNombre.ifBlank { "—" }}",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(top = 4.dp),
                )
                if (intento.fotos > 0) {
                    Text(
                        "${intento.fotos} foto(s)",
                        style = MaterialTheme.typography.labelSmall,
                        color = AceroAzul,
                        modifier = Modifier.padding(top = 4.dp),
                    )
                }
            }
        }
    }
}
