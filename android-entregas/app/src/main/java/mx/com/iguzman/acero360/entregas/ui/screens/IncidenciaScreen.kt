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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import mx.com.iguzman.acero360.entregas.ui.AceroAviso
import mx.com.iguzman.acero360.entregas.ui.AceroAvisoSuave
import mx.com.iguzman.acero360.entregas.ui.BotonPrincipal
import mx.com.iguzman.acero360.entregas.ui.CajaInfo
import mx.com.iguzman.acero360.entregas.ui.ChipMotivo
import mx.com.iguzman.acero360.entregas.ui.MensajeVacio
import mx.com.iguzman.acero360.entregas.ui.RenglonCaptura
import mx.com.iguzman.acero360.entregas.ui.SelectorCantidad
import mx.com.iguzman.acero360.entregas.ui.UiState

private val MOTIVOS = listOf(
    "producto_faltante" to "Producto faltante",
    "producto_danado" to "Producto dañado",
    "producto_incompleto" to "Producto incompleto",
    "cambio_sustitucion" to "Cambio o sustitución",
    "devolucion" to "Devolución",
)

@Composable
fun IncidenciaScreen(
    estado: UiState,
    ventaId: Int,
    alEnviar: (Int, String, List<RenglonCaptura>, String) -> Unit,
) {
    val detalle = estado.detalle
    if (detalle == null || detalle.venta.id != ventaId) {
        MensajeVacio("Cargando pedido…")
        return
    }

    var motivo by remember { mutableStateOf("producto_danado") }
    var notas by remember { mutableStateOf("") }
    val cantidades = remember { mutableStateMapOf<Int, Int>() }
    val cambios = remember { mutableStateMapOf<Int, Boolean>() }
    var errorLocal by remember { mutableStateOf<String?>(null) }

    val lineas = remember(detalle.venta.id, detalle.lineas) {
        detalle.lineas.filter { it.vendido > 0 }
    }

    fun enviar() {
        val renglones = lineas.mapNotNull { linea ->
            val cantidad = cantidades[linea.productoId] ?: 0
            if (cantidad <= 0) null else RenglonCaptura(
                productoId = linea.productoId,
                cantidad = cantidad,
                cambio = if (cambios[linea.productoId] == true) cantidad else 0,
            )
        }
        if (renglones.isEmpty()) {
            errorLocal = "Indica al menos un producto afectado."
            return
        }
        errorLocal = null
        alEnviar(ventaId, motivo, renglones, notas)
    }

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
                        detalle.cliente.empresa.ifBlank { detalle.cliente.nombre },
                        style = MaterialTheme.typography.titleLarge,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        "Pedido ${detalle.venta.folio} · ${detalle.venta.empresaNombre}",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }

        item {
            Text("Motivo", style = MaterialTheme.typography.titleMedium)
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                items(MOTIVOS) { (clave, etiqueta) ->
                    ChipMotivo(etiqueta, motivo == clave) { motivo = clave }
                }
            }
        }

        item {
            CajaInfo(
                when (motivo) {
                    "producto_faltante", "producto_incompleto" ->
                        "Faltante o incompleto: las piezas regresan al inventario y la oficina corregirá la factura si la venta ya está facturada."
                    "cambio_sustitucion" ->
                        "Cambio o sustitución: el producto se marca como notificado y queda pendiente el reemplazo."
                    "devolucion" ->
                        "Devolución: el producto se marca como devuelto; no se mueve inventario desde la app."
                    else ->
                        "Producto dañado: se notifica para que la oficina dé seguimiento. No se mueve inventario desde la app."
                }
            )
        }

        item {
            Text("Productos afectados", style = MaterialTheme.typography.titleMedium)
            Text(
                "Ajusta la cantidad afectada de cada producto.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        items(lineas, key = { it.productoId }) { linea ->
            val cantidad = cantidades[linea.productoId] ?: 0
            Card(
                shape = RoundedCornerShape(12.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(Modifier.padding(12.dp)) {
                    Text(
                        linea.nombre,
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = FontWeight.SemiBold,
                    )
                    Text(
                        "${linea.sku.ifBlank { "Sin código" }} · vendido: ${linea.vendido} ${linea.unidad}",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.weight(1f),
                        ) {
                            Checkbox(
                                checked = cambios[linea.productoId] == true,
                                onCheckedChange = { cambios[linea.productoId] = it },
                            )
                            Text("Requiere cambio", style = MaterialTheme.typography.bodySmall)
                        }
                        SelectorCantidad(
                            valor = cantidad,
                            maximo = linea.vendido,
                            alCambiar = { cantidades[linea.productoId] = it },
                        )
                    }
                }
            }
        }

        item {
            OutlinedTextField(
                value = notas,
                onValueChange = { notas = it },
                label = { Text("Notas de la incidencia (opcional)") },
                minLines = 2,
                maxLines = 4,
                modifier = Modifier.fillMaxWidth(),
            )
        }

        errorLocal?.let { item { Text(it, color = AceroAviso, style = MaterialTheme.typography.bodyMedium) } }

        item {
            BotonPrincipal(
                texto = "Registrar incidencia",
                onClick = ::enviar,
                habilitado = cantidades.values.any { it > 0 },
                cargando = estado.guardando,
            )
        }
    }
}
