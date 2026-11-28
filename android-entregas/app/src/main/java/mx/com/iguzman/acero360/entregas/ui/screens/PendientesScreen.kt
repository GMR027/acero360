package mx.com.iguzman.acero360.entregas.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
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
import androidx.compose.material.icons.automirrored.filled.ArrowForward
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import mx.com.iguzman.acero360.entregas.data.ClientePendiente
import mx.com.iguzman.acero360.entregas.data.PendientesData
import mx.com.iguzman.acero360.entregas.data.ProductoPendiente
import mx.com.iguzman.acero360.entregas.data.VentaResumen
import mx.com.iguzman.acero360.entregas.ui.AceroAviso
import mx.com.iguzman.acero360.entregas.ui.AceroAvisoSuave
import mx.com.iguzman.acero360.entregas.ui.AceroAzul
import mx.com.iguzman.acero360.entregas.ui.AceroExito
import mx.com.iguzman.acero360.entregas.ui.AceroExitoSuave
import mx.com.iguzman.acero360.entregas.ui.BotonPrincipal
import mx.com.iguzman.acero360.entregas.ui.EtiquetaEstado
import mx.com.iguzman.acero360.entregas.ui.ImagenProducto
import mx.com.iguzman.acero360.entregas.ui.MensajeVacio
import mx.com.iguzman.acero360.entregas.ui.UiState
import mx.com.iguzman.acero360.entregas.ui.fechaCorta

@Composable
fun PendientesScreen(
    estado: UiState,
    alAbrirVenta: (Int) -> Unit,
    alActivarSeleccion: () -> Unit,
    alCancelarSeleccion: () -> Unit,
    alAlternarSeleccion: (Int) -> Unit,
    alBuscar: (String) -> Unit,
    alFiltrarEmpresa: (String) -> Unit,
    alGenerarPreparacion: () -> Unit,
    alRefrescar: () -> Unit,
) {
    val datos = estado.pendientes
    val empresas = datos?.clientes
        ?.flatMap { it.ventas }
        ?.map { it.empresaNombre }
        ?.filter { it.isNotBlank() }
        ?.distinct()
        ?.sorted()
        .orEmpty()

    val filtrados = datos?.let { filtrar(it, estado.busqueda, estado.filtroEmpresa) }.orEmpty()

    Box(Modifier.fillMaxSize()) {
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(
                start = 16.dp,
                end = 16.dp,
                top = 8.dp,
                bottom = if (estado.modoSeleccion) 104.dp else 16.dp,
            ),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            item {
                ResumenPendientes(datos)
            }

            item {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    OutlinedTextField(
                        value = estado.busqueda,
                        onValueChange = alBuscar,
                        placeholder = { Text("Buscar cliente, folio o producto") },
                        leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                        singleLine = true,
                        shape = RoundedCornerShape(12.dp),
                        modifier = Modifier.weight(1f),
                    )
                    if (estado.busqueda.isNotBlank()) {
                        IconButton(onClick = { alBuscar("") }) {
                            Text("×", style = MaterialTheme.typography.titleLarge)
                        }
                    }
                }
            }

            if (empresas.size > 1) {
                item {
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        item {
                            FilterChip(
                                selected = estado.filtroEmpresa == "Todos",
                                onClick = { alFiltrarEmpresa("Todos") },
                                label = { Text("Todos") },
                            )
                        }
                        items(empresas) { empresa ->
                            FilterChip(
                                selected = estado.filtroEmpresa == empresa,
                                onClick = { alFiltrarEmpresa(empresa) },
                                label = { Text(empresa, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                            )
                        }
                    }
                }
            }

            item {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    if (estado.modoSeleccion) {
                        TextButton(onClick = alCancelarSeleccion) { Text("Cancelar") }
                    } else {
                        TextButton(onClick = alActivarSeleccion) { Text("Seleccionar pedidos") }
                    }
                    TextButton(onClick = alRefrescar) { Text("Actualizar") }
                }
            }

            if (filtrados.isEmpty()) {
                item {
                    MensajeVacio(
                        titulo = when {
                            datos != null -> "No hay pedidos pendientes"
                            estado.cargando -> "Cargando pedidos…"
                            else -> "No se pudieron cargar los pedidos"
                        },
                        detalle = if (datos != null && (estado.busqueda.isNotBlank() || estado.filtroEmpresa != "Todos")) {
                            "Prueba con otra búsqueda o cambia el negocio."
                        } else if (datos != null) {
                            "Las ventas confirmadas aparecerán aquí hasta que se entreguen."
                        } else {
                            "Revisa tu conexión y toca Actualizar."
                        },
                    )
                }
            }

            items(filtrados, key = { it.id }) { cliente ->
                ClienteCard(
                    cliente = cliente,
                    servidor = estado.servidor,
                    modoSeleccion = estado.modoSeleccion,
                    seleccion = estado.seleccion,
                    alAbrirVenta = alAbrirVenta,
                    alAlternarSeleccion = alAlternarSeleccion,
                )
            }
        }

        if (estado.modoSeleccion) {
            Surface(
                color = MaterialTheme.colorScheme.surface,
                shadowElevation = 10.dp,
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth(),
            ) {
                Column(Modifier.padding(16.dp)) {
                    Text(
                        if (estado.seleccion.isEmpty()) {
                            "Elige uno o varios pedidos"
                        } else {
                            "${estado.seleccion.size} pedido(s) seleccionado(s)"
                        },
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.height(8.dp))
                    BotonPrincipal(
                        texto = "Generar lista de preparación",
                        onClick = alGenerarPreparacion,
                        habilitado = estado.seleccion.isNotEmpty(),
                        cargando = estado.generandoPdf,
                    )
                }
            }
        }
    }
}

@Composable
private fun ResumenPendientes(datos: PendientesData?) {
    Card(
        shape = RoundedCornerShape(14.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(14.dp)) {
            Text(
                "Por entregar",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onPrimaryContainer,
            )
            Text(
                "${datos?.totalPiezas ?: 0} piezas",
                style = MaterialTheme.typography.headlineSmall,
                color = MaterialTheme.colorScheme.onPrimaryContainer,
            )
            Text(
                "${datos?.totalVentas ?: 0} pedido(s) · ${datos?.totalClientes ?: 0} cliente(s)",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onPrimaryContainer,
            )
        }
    }
}

@Composable
private fun ClienteCard(
    cliente: ClientePendiente,
    servidor: String,
    modoSeleccion: Boolean,
    seleccion: Set<Int>,
    alAbrirVenta: (Int) -> Unit,
    alAlternarSeleccion: (Int) -> Unit,
) {
    Card(
        shape = RoundedCornerShape(14.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f)) {
                    Text(
                        cliente.nombre,
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    if (cliente.contacto.isNotBlank() && cliente.contacto != cliente.nombre) {
                        Text(
                            cliente.contacto,
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
                EtiquetaEstado("${cliente.piezas} pza", AceroAviso, AceroAvisoSuave)
            }

            if (cliente.direccion.isNotBlank()) {
                Row(
                    modifier = Modifier.padding(top = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        Icons.Default.LocationOn,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(15.dp),
                    )
                    Spacer(Modifier.width(4.dp))
                    Text(
                        cliente.direccion,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }

            Spacer(Modifier.height(8.dp))
            cliente.ventas.forEach { venta ->
                val seleccionada = seleccion.contains(venta.id)
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(
                            if (seleccionada) MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.6f)
                            else MaterialTheme.colorScheme.surface,
                            RoundedCornerShape(8.dp),
                        )
                        .clickable {
                            if (modoSeleccion) alAlternarSeleccion(venta.id) else alAbrirVenta(venta.id)
                        }
                        .padding(vertical = 8.dp, horizontal = 6.dp),
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        if (modoSeleccion) {
                            Checkbox(
                                checked = seleccionada,
                                onCheckedChange = { alAlternarSeleccion(venta.id) },
                            )
                        } else {
                            Box(
                                modifier = Modifier
                                    .size(10.dp)
                                    .background(
                                        if (venta.incidenciasAbiertas > 0) AceroAviso else AceroExito,
                                        RoundedCornerShape(50),
                                    ),
                            )
                            Spacer(Modifier.width(8.dp))
                        }
                        Column(Modifier.weight(1f)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(
                                    venta.folio,
                                    style = MaterialTheme.typography.bodyMedium,
                                    fontWeight = FontWeight.SemiBold,
                                )
                                Spacer(Modifier.width(8.dp))
                                Text(
                                    venta.empresaNombre,
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis,
                                )
                            }
                            if (venta.proyectoFolio.isNotBlank() || venta.proyectoNombre.isNotBlank()) {
                                Text(
                                    listOf(venta.proyectoFolio, venta.proyectoNombre)
                                        .filter { it.isNotBlank() }
                                        .joinToString(" · "),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = AceroAzul,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis,
                                )
                            }
                            Text(
                                "${venta.pendiente} pieza(s) pendiente(s) · ${fechaCorta(venta.fecha)}",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Column(horizontalAlignment = Alignment.End) {
                            Text(
                                "${venta.pendiente}",
                                style = MaterialTheme.typography.titleMedium,
                                color = AceroAviso,
                                fontWeight = FontWeight.Bold,
                            )
                            if (venta.incidenciasAbiertas > 0) {
                                EtiquetaEstado(
                                    "${venta.incidenciasAbiertas} incidencia(s)",
                                    AceroAviso,
                                    AceroAvisoSuave,
                                )
                            }
                        }
                    }
                    if (!modoSeleccion) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.End,
                        ) {
                            OutlinedButton(
                                onClick = { alAbrirVenta(venta.id) },
                                contentPadding = PaddingValues(horizontal = 12.dp, vertical = 0.dp),
                                modifier = Modifier.height(34.dp),
                            ) {
                                Icon(
                                    Icons.AutoMirrored.Filled.ArrowForward,
                                    contentDescription = null,
                                    modifier = Modifier.size(16.dp),
                                )
                                Spacer(Modifier.width(6.dp))
                                Text("Ver pedido", style = MaterialTheme.typography.labelMedium)
                            }
                        }
                    }
                }
            }

            val productos = productosDeVentas(cliente.ventas)
            if (productos.isNotEmpty()) {
                Spacer(Modifier.height(8.dp))
                Surface(
                    color = MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.5f),
                    shape = RoundedCornerShape(10.dp),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Column(
                        modifier = Modifier.padding(8.dp),
                        verticalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        productos.forEach { producto ->
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                ImagenProducto(
                                    servidor = servidor,
                                    archivo = producto.imagen,
                                    descripcion = producto.nombre,
                                    tamano = 40.dp,
                                )
                                Spacer(Modifier.width(8.dp))
                                Text(
                                    "${producto.total} × ${producto.nombre}",
                                    style = MaterialTheme.typography.bodySmall,
                                    maxLines = 2,
                                    overflow = TextOverflow.Ellipsis,
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}

private fun filtrar(datos: PendientesData, busqueda: String, empresa: String): List<ClientePendiente> {
    val termino = busqueda.trim().lowercase()
    return datos.clientes.mapNotNull { cliente ->
        val ventas = cliente.ventas.filter { venta ->
            val coincideEmpresa = empresa == "Todos" || venta.empresaNombre == empresa
            val coincideTexto = termino.isBlank() ||
                cliente.nombre.lowercase().contains(termino) ||
                cliente.contacto.lowercase().contains(termino) ||
                venta.folio.lowercase().contains(termino) ||
                venta.lineas.any { it.nombre.lowercase().contains(termino) || it.sku.lowercase().contains(termino) }
            coincideEmpresa && coincideTexto
        }
        if (ventas.isEmpty()) {
            null
        } else {
            cliente.copy(
                ventas = ventas,
                piezas = ventas.sumOf { it.pendiente },
            )
        }
    }
}

private fun productosDeVentas(ventas: List<VentaResumen>): List<ProductoPendiente> {
    val mapa = linkedMapOf<Int, ProductoPendiente>()
    ventas.flatMap { it.lineas }
        .filter { it.pendiente > 0 }
        .forEach { linea ->
            val actual = mapa[linea.productoId]
            mapa[linea.productoId] = if (actual == null) {
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
    return mapa.values.toList()
}
