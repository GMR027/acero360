package mx.com.iguzman.acero360.entregas.data

import org.json.JSONArray
import org.json.JSONObject

data class Usuario(
    val id: Int,
    val nombre: String,
    val email: String,
    val telefono: String = "",
)

data class ProductoPendiente(
    val id: Int,
    val sku: String,
    val nombre: String,
    val unidad: String,
    val imagen: String,
    val total: Int,
)

data class VentaResumen(
    val id: Int,
    val folio: String,
    val fecha: String,
    val entregaFecha: String,
    val entregaEstado: String,
    val empresaId: Int,
    val empresaNombre: String,
    val piezas: Int,
    val entregadas: Int,
    val pendiente: Int,
    val incidenciasAbiertas: Int,
    val lineas: List<LineaVenta> = emptyList(),
)

data class ClientePendiente(
    val id: Int,
    val nombre: String,
    val contacto: String,
    val telefono: String,
    val direccion: String,
    val mapa: String,
    val piezas: Int,
    val ventas: List<VentaResumen>,
    val productos: List<ProductoPendiente>,
)

data class PendientesData(
    val clientes: List<ClientePendiente>,
    val totalClientes: Int,
    val totalVentas: Int,
    val totalPiezas: Int,
)

data class LineaVenta(
    val productoId: Int,
    val sku: String,
    val nombre: String,
    val unidad: String,
    val imagen: String,
    val vendido: Int,
    val entregado: Int,
    val pendiente: Int,
)

data class ClienteDetalle(
    val id: Int,
    val nombre: String,
    val empresa: String,
    val telefono: String,
    val email: String,
    val direccion: String,
    val mapa: String,
)

data class VentaCabecera(
    val id: Int,
    val folio: String,
    val fecha: String,
    val entregaFecha: String,
    val entregaObservaciones: String,
    val entregaEstado: String,
    val condicionesEnvio: String,
    val comentarios: String,
    val empresaId: Int,
    val empresaNombre: String,
    val empresaLogo: String,
)

data class HistorialRenglon(val nombre: String, val cantidad: Int)

data class HistorialEntrega(
    val id: Int,
    val fecha: String,
    val creadoEn: String,
    val notas: String,
    val recibioNombre: String,
    val origen: String,
    val usuarioNombre: String,
    val ubicacion: String,
    val renglones: List<HistorialRenglon>,
    val fotos: Int,
    val ventaId: Int = 0,
    val folio: String = "",
    val cliente: String = "",
    val empresa: String = "",
)

data class IncidenciaRenglon(
    val productoNombre: String,
    val cantidad: Int,
    val requiereCambio: Boolean,
)

data class IncidenciaEntrega(
    val id: Int,
    val motivo: String,
    val motivoEtiqueta: String,
    val estado: String,
    val notas: String,
    val creadoEn: String,
    val renglones: List<IncidenciaRenglon>,
)

data class IntentoEntrega(
    val id: Int,
    val fecha: String,
    val motivo: String,
    val motivoEtiqueta: String,
    val notas: String,
    val usuarioNombre: String,
    val fotos: Int,
    val ventaId: Int = 0,
    val folio: String = "",
    val cliente: String = "",
)

data class ResumenVenta(
    val vendido: Int,
    val entregado: Int,
    val pendiente: Int,
    val incidenciasAbiertas: Int,
)

data class VentaDetalleData(
    val venta: VentaCabecera,
    val cliente: ClienteDetalle,
    val lineas: List<LineaVenta>,
    val historial: List<HistorialEntrega>,
    val incidencias: List<IncidenciaEntrega>,
    val intentos: List<IntentoEntrega>,
    val resumen: ResumenVenta,
    val puedeEntregar: Boolean,
)

data class HistorialData(
    val entregas: List<HistorialEntrega>,
    val intentos: List<IntentoEntrega>,
)

data class PhotoMeta(
    val ruta: String,
    val tomadaEn: String,
    val latitud: Double?,
    val longitud: Double?,
)

data class MotivoOpcion(val clave: String, val etiqueta: String)

fun JSONObject.text(clave: String): String =
    if (isNull(clave)) "" else optString(clave, "")

fun JSONObject.entero(clave: String): Int = optInt(clave, 0)

fun JSONObject.booleano(clave: String): Boolean = optBoolean(clave, false)

fun JSONObject.objeto(clave: String): JSONObject? =
    if (has(clave) && !isNull(clave)) optJSONObject(clave) else null

fun JSONObject.lista(clave: String): JSONArray =
    if (has(clave) && !isNull(clave)) optJSONArray(clave) ?: JSONArray() else JSONArray()

fun JSONArray.cada(accion: (JSONObject) -> Unit) {
    for (i in 0 until length()) {
        val item = optJSONObject(i) ?: continue
        accion(item)
    }
}

fun usuarioDeJson(json: JSONObject): Usuario = Usuario(
    id = json.entero("id"),
    nombre = json.text("nombre"),
    email = json.text("email"),
    telefono = json.text("telefono"),
)

fun ventaResumenDeJson(json: JSONObject): VentaResumen {
    val lineas = mutableListOf<LineaVenta>()
    json.lista("lineas").cada { l ->
        lineas.add(LineaVenta(
            productoId = l.entero("producto_id"),
            sku = l.text("sku"),
            nombre = l.text("nombre"),
            unidad = l.text("unidad").ifBlank { "pza" },
            imagen = l.text("imagen"),
            vendido = l.entero("vendido"),
            entregado = l.entero("entregado"),
            pendiente = l.entero("pendiente"),
        ))
    }
    return VentaResumen(
        id = json.entero("id"),
        folio = json.text("folio"),
        fecha = json.text("fecha"),
        entregaFecha = json.text("entrega_fecha"),
        entregaEstado = json.text("entrega_estado"),
        empresaId = json.entero("empresa_id"),
        empresaNombre = json.text("empresa_nombre"),
        piezas = json.entero("piezas"),
        entregadas = json.entero("entregadas"),
        pendiente = json.entero("pendiente"),
        incidenciasAbiertas = json.entero("incidencias_abiertas"),
        lineas = lineas,
    )
}

fun productoPendienteDeJson(json: JSONObject): ProductoPendiente = ProductoPendiente(
    id = json.entero("producto_id"),
    sku = json.text("sku"),
    nombre = json.text("nombre"),
    unidad = json.text("unidad").ifBlank { "pza" },
    imagen = json.text("imagen"),
    total = json.entero("total"),
)

fun clientePendienteDeJson(json: JSONObject): ClientePendiente {
    val ventas = mutableListOf<VentaResumen>()
    json.lista("ventas").cada { ventas.add(ventaResumenDeJson(it)) }
    val productos = mutableListOf<ProductoPendiente>()
    json.lista("productos").cada { productos.add(productoPendienteDeJson(it)) }
    return ClientePendiente(
        id = json.entero("cliente_id"),
        nombre = json.text("nombre"),
        contacto = json.text("contacto"),
        telefono = json.text("telefono"),
        direccion = json.text("direccion"),
        mapa = json.text("mapa"),
        piezas = json.entero("piezas"),
        ventas = ventas,
        productos = productos,
    )
}

fun pendientesDeJson(json: JSONObject): PendientesData {
    val clientes = mutableListOf<ClientePendiente>()
    json.lista("clientes").cada { clientes.add(clientePendienteDeJson(it)) }
    val totales = json.objeto("totales")
    return PendientesData(
        clientes = clientes,
        totalClientes = totales?.entero("clientes") ?: clientes.size,
        totalVentas = totales?.entero("ventas") ?: clientes.sumOf { it.ventas.size },
        totalPiezas = totales?.entero("piezas") ?: clientes.sumOf { it.piezas },
    )
}

fun historialEntregaDeJson(json: JSONObject): HistorialEntrega {
    val renglones = mutableListOf<HistorialRenglon>()
    json.lista("renglones").cada {
        renglones.add(HistorialRenglon(it.text("producto_nombre").ifBlank { it.text("nombre") }, it.entero("cantidad")))
    }
    return HistorialEntrega(
        id = json.entero("id"),
        fecha = json.text("fecha"),
        creadoEn = json.text("creado_en"),
        notas = json.text("notas"),
        recibioNombre = json.text("recibio_nombre"),
        origen = json.text("origen"),
        usuarioNombre = json.text("usuario_nombre"),
        ubicacion = json.text("ubicacion"),
        renglones = renglones,
        fotos = json.entero("fotos"),
        ventaId = json.entero("venta_id"),
        folio = json.text("folio"),
        cliente = json.text("cliente_empresa").ifBlank { json.text("cliente_nombre") },
        empresa = json.text("cliente_empresa"),
    )
}

fun incidenciaDeJson(json: JSONObject): IncidenciaEntrega {
    val renglones = mutableListOf<IncidenciaRenglon>()
    json.lista("renglones").cada {
        renglones.add(IncidenciaRenglon(
            productoNombre = it.text("producto_nombre"),
            cantidad = it.entero("cantidad"),
            requiereCambio = it.booleano("requiere_cambio"),
        ))
    }
    return IncidenciaEntrega(
        id = json.entero("id"),
        motivo = json.text("motivo"),
        motivoEtiqueta = json.text("motivo_etiqueta"),
        estado = json.text("estado"),
        notas = json.text("notas"),
        creadoEn = json.text("creado_en"),
        renglones = renglones,
    )
}

fun intentoDeJson(json: JSONObject): IntentoEntrega = IntentoEntrega(
    id = json.entero("id"),
    fecha = json.text("fecha"),
    motivo = json.text("motivo"),
    motivoEtiqueta = json.text("motivo_etiqueta"),
    notas = json.text("notas"),
    usuarioNombre = json.text("usuario_nombre"),
    fotos = json.entero("fotos"),
    ventaId = json.entero("venta_id"),
    folio = json.text("folio"),
    cliente = json.text("cliente_empresa").ifBlank { json.text("cliente_nombre") },
)

fun ventaDetalleDeJson(json: JSONObject): VentaDetalleData {
    val v = json.objeto("venta") ?: JSONObject()
    val c = json.objeto("cliente") ?: JSONObject()
    val lineas = mutableListOf<LineaVenta>()
    json.lista("lineas").cada { l ->
        lineas.add(LineaVenta(
            productoId = l.entero("producto_id"),
            sku = l.text("sku"),
            nombre = l.text("nombre"),
            unidad = l.text("unidad").ifBlank { "pza" },
            imagen = l.text("imagen"),
            vendido = l.entero("vendido"),
            entregado = l.entero("entregado"),
            pendiente = l.entero("pendiente"),
        ))
    }
    val historial = mutableListOf<HistorialEntrega>()
    json.lista("historial").cada { historial.add(historialEntregaDeJson(it)) }
    val incidencias = mutableListOf<IncidenciaEntrega>()
    json.lista("incidencias").cada { incidencias.add(incidenciaDeJson(it)) }
    val intentos = mutableListOf<IntentoEntrega>()
    json.lista("intentos").cada { intentos.add(intentoDeJson(it)) }
    val r = json.objeto("resumen") ?: JSONObject()
    return VentaDetalleData(
        venta = VentaCabecera(
            id = v.entero("id"),
            folio = v.text("folio"),
            fecha = v.text("fecha"),
            entregaFecha = v.text("entrega_fecha"),
            entregaObservaciones = v.text("entrega_observaciones"),
            entregaEstado = v.text("entrega_estado"),
            condicionesEnvio = v.text("condiciones_envio"),
            comentarios = v.text("comentarios"),
            empresaId = v.entero("empresa_id"),
            empresaNombre = v.text("empresa_nombre"),
            empresaLogo = v.text("empresa_logo"),
        ),
        cliente = ClienteDetalle(
            id = c.entero("id"),
            nombre = c.text("nombre"),
            empresa = c.text("empresa"),
            telefono = c.text("telefono"),
            email = c.text("email"),
            direccion = c.text("direccion"),
            mapa = c.text("mapa"),
        ),
        lineas = lineas,
        historial = historial,
        incidencias = incidencias,
        intentos = intentos,
        resumen = ResumenVenta(
            vendido = r.entero("vendido"),
            entregado = r.entero("entregado"),
            pendiente = r.entero("pendiente"),
            incidenciasAbiertas = r.entero("incidencias_abiertas"),
        ),
        puedeEntregar = json.booleano("puede_entregar"),
    )
}

fun historialDeJson(json: JSONObject): HistorialData {
    val entregas = mutableListOf<HistorialEntrega>()
    json.lista("entregas").cada { entregas.add(historialEntregaDeJson(it)) }
    val intentos = mutableListOf<IntentoEntrega>()
    json.lista("intentos").cada { intentos.add(intentoDeJson(it)) }
    return HistorialData(entregas, intentos)
}
