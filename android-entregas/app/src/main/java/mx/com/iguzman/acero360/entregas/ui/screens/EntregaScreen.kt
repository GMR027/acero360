package mx.com.iguzman.acero360.entregas.ui.screens

import android.Manifest
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import mx.com.iguzman.acero360.entregas.data.LineaVenta
import mx.com.iguzman.acero360.entregas.data.PhotoMeta
import mx.com.iguzman.acero360.entregas.ui.AceroAviso
import mx.com.iguzman.acero360.entregas.ui.AceroAvisoSuave
import mx.com.iguzman.acero360.entregas.ui.AceroAzul
import mx.com.iguzman.acero360.entregas.ui.Aviso
import mx.com.iguzman.acero360.entregas.ui.BotonPrincipal
import mx.com.iguzman.acero360.entregas.ui.CajaInfo
import mx.com.iguzman.acero360.entregas.ui.EtiquetaEstado
import mx.com.iguzman.acero360.entregas.ui.FotoMiniatura
import mx.com.iguzman.acero360.entregas.ui.ImagenProducto
import mx.com.iguzman.acero360.entregas.ui.MensajeVacio
import mx.com.iguzman.acero360.entregas.ui.SelectorCantidad
import mx.com.iguzman.acero360.entregas.ui.UiState
import mx.com.iguzman.acero360.entregas.util.LocationHelper
import mx.com.iguzman.acero360.entregas.util.PhotoHelper
import mx.com.iguzman.acero360.entregas.util.TomarFoto
import java.io.File

@Composable
fun EntregaScreen(
    estado: UiState,
    ventaId: Int,
    alEnviar: (Int, Map<Int, Int>, String, String, String, List<PhotoMeta>) -> Unit,
    alVolver: () -> Unit,
) {
    val detalle = estado.detalle
    if (detalle == null || detalle.venta.id != ventaId) {
        MensajeVacio("Cargando pedido…")
        return
    }

    val contexto = LocalContext.current
    val alcance = rememberCoroutineScope()
    val cantidades = remember { mutableStateMapOf<Int, Int>() }
    val fotos = remember { mutableStateListOf<PhotoMeta>() }
    var recibio by remember { mutableStateOf("") }
    var notas by remember { mutableStateOf("") }
    var ubicacion by remember { mutableStateOf("") }
    var errorLocal by remember { mutableStateOf<String?>(null) }
    var archivoCamara by remember { mutableStateOf<File?>(null) }
    var procesandoFoto by remember { mutableStateOf(false) }
    var permisoUbicacion by remember { mutableStateOf(LocationHelper.tienePermiso(contexto)) }
    var esperandoPermisoParaUbicacion by remember { mutableStateOf(false) }
    var camaraPendientePermiso by remember { mutableStateOf(false) }

    val lineas = remember(detalle.venta.id, detalle.lineas) {
        detalle.lineas.filter { it.pendiente > 0 }
    }

    LaunchedEffect(detalle.venta.id, lineas) {
        cantidades.clear()
        lineas.forEach { cantidades[it.productoId] = it.pendiente }
    }

    fun sumarCantidades(): Int = cantidades.values.sum()

    fun capturarUbicacion() {
        alcance.launch {
            val loc = LocationHelper.actual(contexto)
            if (loc != null) {
                ubicacion = LocationHelper.comoTexto(loc)
                errorLocal = null
            } else {
                errorLocal = "No se pudo obtener la ubicación. Puedes continuar sin ella."
            }
        }
    }

    val camaraLauncher = rememberLauncherForActivityResult(TomarFoto()) { ok ->
        val archivo = archivoCamara
        archivoCamara = null
        if (!ok || archivo == null) {
            archivo?.delete()
            return@rememberLauncherForActivityResult
        }
        alcance.launch {
            procesandoFoto = true
            val loc = LocationHelper.actual(contexto)
            val meta = withContext(Dispatchers.IO) { PhotoHelper.procesarArchivo(contexto, archivo, loc) }
            if (meta != null) {
                fotos.add(meta)
                if (ubicacion.isBlank() && meta.latitud != null && meta.longitud != null) {
                    ubicacion = String.format(java.util.Locale.US, "%.7f,%.7f", meta.latitud, meta.longitud)
                }
                errorLocal = null
            } else {
                errorLocal = "No se pudo procesar la foto."
            }
            procesandoFoto = false
        }
    }

    val permisoLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permisos ->
        permisoUbicacion = permisos.values.any { it }
        if (camaraPendientePermiso) {
            camaraPendientePermiso = false
            val archivo = PhotoHelper.crearArchivoCamara(contexto)
            archivoCamara = archivo
            camaraLauncher.launch(PhotoHelper.uriParaArchivo(contexto, archivo))
        } else if (permisoUbicacion) {
            capturarUbicacion()
        } else if (esperandoPermisoParaUbicacion) {
            errorLocal = "Sin permiso de ubicación. Puedes continuar sin GPS."
        }
        esperandoPermisoParaUbicacion = false
    }

    val galeriaLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.PickVisualMedia()
    ) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        alcance.launch {
            procesandoFoto = true
            val loc = LocationHelper.actual(contexto)
            val meta = withContext(Dispatchers.IO) { PhotoHelper.procesarUri(contexto, uri, loc) }
            if (meta != null) {
                fotos.add(meta)
                if (ubicacion.isBlank() && meta.latitud != null && meta.longitud != null) {
                    ubicacion = String.format(java.util.Locale.US, "%.7f,%.7f", meta.latitud, meta.longitud)
                }
                errorLocal = null
            } else {
                errorLocal = "No se pudo procesar la foto."
            }
            procesandoFoto = false
        }
    }

    fun abrirCamara() {
        if (fotos.size >= PhotoHelper.MAX_FOTOS) {
            errorLocal = "Máximo ${PhotoHelper.MAX_FOTOS} fotos por entrega."
            return
        }
        if (!PhotoHelper.hayCamara(contexto)) {
            errorLocal = "Este dispositivo no tiene una app de cámara disponible."
            return
        }
        if (!permisoUbicacion) {
            camaraPendientePermiso = true
            permisoLauncher.launch(
                arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)
            )
            return
        }
        val archivo = PhotoHelper.crearArchivoCamara(contexto)
        archivoCamara = archivo
        camaraLauncher.launch(PhotoHelper.uriParaArchivo(contexto, archivo))
    }

    fun confirmar() {
        if (sumarCantidades() <= 0) {
            errorLocal = "Captura al menos una pieza entregada."
            return
        }
        alcance.launch {
            var ubi = ubicacion
            if (ubi.isBlank()) {
                val loc = LocationHelper.actual(contexto)
                if (loc != null) ubi = LocationHelper.comoTexto(loc)
            }
            alEnviar(ventaId, cantidades.toMap(), recibio, notas, ubi, fotos.toList())
        }
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
                    Spacer(Modifier.height(8.dp))
                    EtiquetaEstado(
                        "${detalle.resumen.pendiente} pieza(s) pendiente(s)",
                        AceroAviso,
                        AceroAvisoSuave,
                    )
                }
            }
        }

        item {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    "¿Qué se entrega?",
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.weight(1f),
                )
                TextButton(
                    onClick = {
                        cantidades.clear()
                        lineas.forEach { cantidades[it.productoId] = it.pendiente }
                    }
                ) { Text("Entregar todo") }
            }
        }

        items(lineas, key = { it.productoId }) { linea ->
            ProductoEntrega(
                linea = linea,
                servidor = estado.servidor,
                cantidad = cantidades[linea.productoId] ?: 0,
                alCambiar = { cantidades[linea.productoId] = it },
            )
        }

        item {
            Card(
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Datos opcionales", style = MaterialTheme.typography.titleMedium)
                    OutlinedTextField(
                        value = recibio,
                        onValueChange = { recibio = it },
                        label = { Text("Nombre de quien recibe (opcional)") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(
                        value = notas,
                        onValueChange = { notas = it },
                        label = { Text("Notas (opcional)") },
                        minLines = 2,
                        maxLines = 4,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Text("Fotos (opcional)", style = MaterialTheme.typography.titleSmall)
                    Text(
                        "Se guardan la fecha/hora y el GPS de la toma. Puedes continuar sin fotos.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedButton(
                            onClick = ::abrirCamara,
                            enabled = !procesandoFoto && fotos.size < PhotoHelper.MAX_FOTOS,
                        ) {
                            Icon(Icons.Default.Add, contentDescription = null, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(4.dp))
                            Text("Tomar foto")
                        }
                        OutlinedButton(
                            onClick = {
                                if (fotos.size < PhotoHelper.MAX_FOTOS) {
                                    galeriaLauncher.launch(
                                        PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)
                                    )
                                }
                            },
                            enabled = !procesandoFoto && fotos.size < PhotoHelper.MAX_FOTOS,
                        ) {
                            Text("Galería")
                        }
                    }
                    if (procesandoFoto) {
                        Text("Procesando foto…", style = MaterialTheme.typography.bodySmall)
                    }
                    if (fotos.isNotEmpty()) {
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(fotos.toList(), key = { it.ruta }) { foto ->
                                FotoMiniatura(foto) {
                                    fotos.remove(foto)
                                    runCatching { File(foto.ruta).delete() }
                                }
                            }
                        }
                    }

                    Text("Ubicación (opcional)", style = MaterialTheme.typography.titleSmall)
                    OutlinedTextField(
                        value = ubicacion,
                        onValueChange = { ubicacion = it },
                        label = { Text("Coordenadas o dirección") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedButton(
                        onClick = {
                            if (permisoUbicacion) {
                                capturarUbicacion()
                            } else {
                                esperandoPermisoParaUbicacion = true
                                permisoLauncher.launch(
                                    arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)
                                )
                            }
                        },
                    ) {
                        Icon(Icons.Default.LocationOn, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(4.dp))
                        Text("Usar mi ubicación")
                    }
                }
            }
        }

        errorLocal?.let { mensaje ->
            item {
                CajaInfo(mensaje)
            }
        }

        item {
            BotonPrincipal(
                texto = if (sumarCantidades() > 0) {
                    "Confirmar entrega (${sumarCantidades()} pza)"
                } else {
                    "Captura al menos una pieza"
                },
                onClick = ::confirmar,
                habilitado = sumarCantidades() > 0,
                cargando = estado.guardando,
            )
        }
        item {
            TextButton(onClick = alVolver, modifier = Modifier.fillMaxWidth()) {
                Text("Cancelar")
            }
        }
    }
}

@Composable
private fun ProductoEntrega(
    linea: LineaVenta,
    servidor: String,
    cantidad: Int,
    alCambiar: (Int) -> Unit,
) {
    Card(
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier.padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
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
                )
                Text(
                    "${linea.sku.ifBlank { "Sin código" }} · ${linea.pendiente} ${linea.unidad} pendiente(s)",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            SelectorCantidad(
                valor = cantidad,
                maximo = linea.pendiente,
                alCambiar = alCambiar,
            )
        }
    }
}
