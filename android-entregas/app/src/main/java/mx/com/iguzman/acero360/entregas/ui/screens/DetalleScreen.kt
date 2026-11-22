package mx.com.iguzman.acero360.entregas.ui.screens

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Share
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import mx.com.iguzman.acero360.entregas.data.HistorialEntrega
import mx.com.iguzman.acero360.entregas.data.IncidenciaEntrega
import mx.com.iguzman.acero360.entregas.data.IntentoEntrega
import mx.com.iguzman.acero360.entregas.data.LineaVenta
import mx.com.iguzman.acero360.entregas.ui.AceroAviso
import mx.com.iguzman.acero360.entregas.ui.AceroAvisoSuave
import mx.com.iguzman.acero360.entregas.ui.AceroExito
import mx.com.iguzman.acero360.entregas.ui.AceroExitoSuave
import mx.com.iguzman.acero360.entregas.ui.AceroAzul
import mx.com.iguzman.acero360.entregas.ui.BotonPrincipal
import mx.com.iguzman.acero360.entregas.ui.BotonSecundario
import mx.com.iguzman.acero360.entregas.ui.CajaInfo
import mx.com.iguzman.acero360.entregas.ui.DatoLinea
import mx.com.iguzman.acero360.entregas.ui.DivisorSuave
import mx.com.iguzman.acero360.entregas.ui.EtiquetaEstado
import mx.com.iguzman.acero360.entregas.ui.ImagenProducto
import mx.com.iguzman.acero360.entregas.ui.MensajeVacio
import mx.com.iguzman.acero360.entregas.ui.TituloSeccion
import mx.com.iguzman.acero360.entregas.ui.UiState
import mx.com.iguzman.acero360.entregas.ui.fechaCorta
import mx.com.iguzman.acero360.entregas.ui.fechaHora

@Composable
fun DetalleScreen(
    estado: UiState,
    ventaId: Int,
    alRegistrarEntrega: () -> Unit,
    alRegistrarIncidencia: () -> Unit,
    alRegistrarIntento: () -> Unit,
    alPdfEntrega: () -> Unit,
    alPdfActa: () -> Unit,
) {
    val detalle = estado.detalle
    if (detalle == null || detalle.venta.id != ventaId) {
        MensajeVacio(
            titulo = if (estado.cargando) "Cargando pedido…" else "No se pudo cargar el pedido",
            detalle = if (estado.cargando) "" else "Vuelve a intentarlo desde la lista de pendientes.",
        )
        return
    }

    val contexto = LocalContext.current
    val venta = detalle.venta
    val cliente = detalle.cliente
    val telefono = cliente.telefono.filter { it.isDigit() || it == '+' }

    fun abrir(intent: Intent) = runCatching { contexto.startActivity(intent) }

    fun llamar() {
        if (telefono.isNotBlank()) abrir(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$telefono")))
    }

    fun whatsapp() {
        if (telefono.isNotBlank()) {
            val numero = telefono.filter { it.isDigit() }
            val texto = Uri.encode("Hola, soy de ${venta.empresaNombre}. Voy a entregar el pedido ${venta.folio}.")
            abrir(Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/$numero?text=$texto")))
        }
    }

    fun mapa() {
        val consulta = when {
            cliente.mapa.startsWith("http", ignoreCase = true) -> cliente.mapa
            cliente.direccion.isNotBlank() -> "https://www.google.com/maps/search/?api=1&query=${Uri.encode(cliente.direccion)}"
            else -> ""
        }
        if (consulta.isNotBlank()) abrir(Intent(Intent.ACTION_VIEW, Uri.parse(consulta)))
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
                    Row(verticalAlignment = Alignment.Top) {
                        Column(Modifier.weight(1f)) {
                            Text(
                                cliente.empresa.ifBlank { cliente.nombre },
                                style = MaterialTheme.typography.titleLarge,
                                fontWeight = FontWeight.Bold,
                            )
                            if (cliente.nombre.isNotBlank() && cliente.nombre != cliente.empresa) {
                                Text(
                                    "Contacto: ${cliente.nombre}",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }
                        EtiquetaEstado(venta.entregaEstado.replace("_", " "), AceroAzul)
                    }
                    Spacer(Modifier.height(8.dp))
                    DatoLinea("Folio", venta.folio)
                    DatoLinea("Negocio", venta.empresaNombre)
                    DatoLinea("Fecha", fechaCorta(venta.fecha))
                    if (venta.entregaFecha.isNotBlank()) DatoLinea("Entrega", fechaCorta(venta.entregaFecha))
                    DatoLinea("Dirección", cliente.direccion)
                    if (cliente.telefono.isNotBlank()) DatoLinea("Teléfono", cliente.telefono)
                    if (venta.entregaObservaciones.isNotBlank()) {
                        DatoLinea("Observaciones", venta.entregaObservaciones)
                    }
                    if (venta.condicionesEnvio.isNotBlank()) {
                        Spacer(Modifier.height(6.dp))
                        CajaInfo("Condiciones de envío: ${venta.condicionesEnvio}")
                    }
                    if (venta.comentarios.isNotBlank()) {
                        Spacer(Modifier.height(6.dp))
                        Text(
                            "Comentarios: ${venta.comentarios}",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (telefono.isNotBlank()) {
                            OutlinedButton(onClick = ::llamar) {
                                Icon(Icons.Default.Call, contentDescription = null, modifier = Modifier.size(18.dp))
                                Spacer(Modifier.width(6.dp))
                                Text("Llamar")
                            }
                            OutlinedButton(onClick = ::whatsapp) {
                                Icon(Icons.Default.Share, contentDescription = null, modifier = Modifier.size(18.dp))
                                Spacer(Modifier.width(6.dp))
                                Text("WhatsApp")
                            }
                        }
                        if (cliente.mapa.isNotBlank() || cliente.direccion.isNotBlank()) {
                            OutlinedButton(onClick = ::mapa) {
                                Icon(Icons.Default.LocationOn, contentDescription = null, modifier = Modifier.size(18.dp))
                                Spacer(Modifier.width(6.dp))
                                Text("Mapa")
                            }
                        }
                    }
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
                    Text("Resumen", style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                        ResumenDato("Pedido", detalle.resumen.vendido)
                        ResumenDato("Entregado", detalle.resumen.entregado)
                        ResumenDato("Pendiente", detalle.resumen.pendiente, AceroAviso)
                    }
                    if (detalle.resumen.incidenciasAbiertas > 0) {
                        Spacer(Modifier.height(8.dp))
                        EtiquetaEstado(
                            "${detalle.resumen.incidenciasAbiertas} incidencia(s) abierta(s)",
                            AceroAviso,
                            AceroAvisoSuave,
                        )
                    }
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
                    Text("Productos", style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(6.dp))
                    detalle.lineas.forEach { linea -> LineaProducto(linea, estado.servidor) }
                }
            }
        }

        item {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (detalle.puedeEntregar) {
                    BotonPrincipal(
                        texto = "Registrar entrega",
                        onClick = alRegistrarEntrega,
                        cargando = estado.guardando,
                    )
                } else {
                    EtiquetaEstado("Entrega completa", AceroExito, AceroExitoSuave)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    BotonSecundario(
                        texto = "No se pudo entregar",
                        onClick = alRegistrarIntento,
                        modifier = Modifier.weight(1f),
                    )
                    BotonSecundario(
                        texto = "Incidencia",
                        onClick = alRegistrarIncidencia,
                        modifier = Modifier.weight(1f),
                    )
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
                    Text("Documentos", style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedButton(
                            onClick = alPdfEntrega,
                            enabled = !estado.generandoPdf && detalle.puedeEntregar,
                            modifier = Modifier.weight(1f),
                        ) {
                            Text("Hoja de entrega\n(2 copias)", style = MaterialTheme.typography.bodySmall)
                        }
                        OutlinedButton(
                            onClick = alPdfActa,
                            enabled = !estado.generandoPdf && detalle.historial.isNotEmpty(),
                            modifier = Modifier.weight(1f),
                        ) {
                            Text("Acta de entrega", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                    if (estado.generandoPdf) {
                        Spacer(Modifier.height(8.dp))
                        Text("Generando documento…", style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }

        if (detalle.historial.isNotEmpty()) {
            item {
                TituloSeccion("Entregas registradas")
            }
            items(detalle.historial.size) { indice ->
                HistorialEntregaCard(detalle.historial[indice])
            }
        }

        if (detalle.incidencias.isNotEmpty()) {
            item { TituloSeccion("Incidencias") }
            items(detalle.incidencias.size) { indice ->
                IncidenciaCard(detalle.incidencias[indice])
            }
        }

        if (detalle.intentos.isNotEmpty()) {
            item { TituloSeccion("Visitas sin entrega") }
            items(detalle.intentos.size) { indice ->
                IntentoCard(detalle.intentos[indice])
            }
        }
    }
}

@Composable
private fun ResumenDato(etiqueta: String, valor: Int, color: androidx.compose.ui.graphics.Color = MaterialTheme.colorScheme.onSurface) {
    Column {
        Text(etiqueta, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(valor.toString(), style = MaterialTheme.typography.headlineSmall, color = color)
    }
}

@Composable
private fun LineaProducto(linea: LineaVenta, servidor: String) {
    Column(Modifier.padding(vertical = 6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            ImagenProducto(
                servidor = servidor,
                archivo = linea.imagen,
                descripcion = linea.nombre,
                tamano = 48.dp,
            )
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    linea.nombre,
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    "Código ${linea.sku.ifBlank { "—" }} · ${linea.unidad}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(
                    "${linea.pendiente}",
                    style = MaterialTheme.typography.titleMedium,
                    color = if (linea.pendiente > 0) AceroAviso else AceroExito,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    "de ${linea.vendido}",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        DivisorSuave()
    }
}

@Composable
private fun HistorialEntregaCard(entrega: HistorialEntrega) {
    Card(
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(12.dp)) {
            Row {
                Column(Modifier.weight(1f)) {
                    Text(fechaCorta(entrega.fecha), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    Text(
                        "Registró ${entrega.usuarioNombre.ifBlank { "—" }}" +
                            if (entrega.recibioNombre.isNotBlank()) " · Recibió ${entrega.recibioNombre}" else "",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                EtiquetaEstado(if (entrega.origen == "app") "App" else "Web", AceroAzul)
            }
            if (entrega.renglones.isNotEmpty()) {
                Text(
                    entrega.renglones.joinToString(" · ") { "${it.cantidad} × ${it.nombre}" },
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 4.dp),
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
            if (entrega.fotos > 0) {
                Text("${entrega.fotos} foto(s)", style = MaterialTheme.typography.labelSmall, color = AceroAzul)
            }
        }
    }
}

@Composable
private fun IncidenciaCard(incidencia: IncidenciaEntrega) {
    Card(
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(12.dp)) {
            Row {
                Text(
                    incidencia.motivoEtiqueta,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f),
                )
                EtiquetaEstado(
                    incidencia.estado,
                    if (incidencia.estado == "abierta") AceroAviso else AceroExito,
                    if (incidencia.estado == "abierta") AceroAvisoSuave else AceroExitoSuave,
                )
            }
            if (incidencia.renglones.isNotEmpty()) {
                Text(
                    incidencia.renglones.joinToString(" · ") { "${it.cantidad} × ${it.productoNombre}" },
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 4.dp),
                )
            }
            if (incidencia.notas.isNotBlank()) {
                Text(
                    incidencia.notas,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(top = 2.dp),
                )
            }
            Text(
                fechaHora(incidencia.creadoEn),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(top = 4.dp),
            )
        }
    }
}

@Composable
private fun IntentoCard(intento: IntentoEntrega) {
    Card(
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(12.dp)) {
            Text(intento.motivoEtiqueta, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            Text(
                "${fechaHora(intento.fecha)} · ${intento.usuarioNombre.ifBlank { "—" }}",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (intento.notas.isNotBlank()) {
                Text(intento.notas, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 2.dp))
            }
            if (intento.fotos > 0) {
                Text("${intento.fotos} foto(s)", style = MaterialTheme.typography.labelSmall, color = AceroAzul)
            }
        }
    }
}
