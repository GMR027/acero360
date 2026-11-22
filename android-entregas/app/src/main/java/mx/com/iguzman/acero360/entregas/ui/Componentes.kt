package mx.com.iguzman.acero360.entregas.ui

import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import coil.request.ImageRequest
import mx.com.iguzman.acero360.entregas.data.PhotoMeta
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

@Composable
fun AvisoBanner(aviso: Aviso, alCerrar: () -> Unit) {
    val fondo = if (aviso.error) MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.primaryContainer
    val texto = if (aviso.error) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onPrimaryContainer
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = fondo,
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 6.dp),
    ) {
        Row(
            modifier = Modifier.padding(start = 12.dp, end = 4.dp, top = 8.dp, bottom = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(
                imageVector = if (aviso.error) Icons.Default.Warning else Icons.Default.CheckCircle,
                contentDescription = null,
                tint = texto,
                modifier = Modifier.size(20.dp),
            )
            Spacer(Modifier.width(8.dp))
            Text(
                text = aviso.texto,
                color = texto,
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.weight(1f),
            )
            IconButton(onClick = alCerrar) {
                Icon(Icons.Default.Close, contentDescription = "Cerrar", tint = texto, modifier = Modifier.size(18.dp))
            }
        }
    }
}

@Composable
fun BannerOffline() {
    Surface(color = AceroAvisoSuave, contentColor = AceroAviso, modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Default.Warning, contentDescription = null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(
                "Sin conexión. Puedes seguir capturando; se enviará al recuperar internet.",
                style = MaterialTheme.typography.bodySmall,
            )
        }
    }
}

@Composable
fun BotonPrincipal(
    texto: String,
    onClick: () -> Unit,
    habilitado: Boolean = true,
    cargando: Boolean = false,
    modifier: Modifier = Modifier,
) {
    Button(
        onClick = onClick,
        enabled = habilitado && !cargando,
        shape = RoundedCornerShape(10.dp),
        modifier = modifier
            .fillMaxWidth()
            .height(54.dp),
    ) {
        if (cargando) {
            CircularProgressIndicator(
                modifier = Modifier.size(22.dp),
                strokeWidth = 2.dp,
                color = MaterialTheme.colorScheme.onPrimary,
            )
        } else {
            Text(texto, style = MaterialTheme.typography.labelLarge)
        }
    }
}

@Composable
fun BotonSecundario(
    texto: String,
    onClick: () -> Unit,
    habilitado: Boolean = true,
    modifier: Modifier = Modifier,
) {
    OutlinedButton(
        onClick = onClick,
        enabled = habilitado,
        shape = RoundedCornerShape(10.dp),
        modifier = modifier
            .fillMaxWidth()
            .height(50.dp),
    ) {
        Text(texto, style = MaterialTheme.typography.labelLarge)
    }
}

@Composable
fun EtiquetaEstado(
    texto: String,
    color: Color = AceroAzul,
    fondo: Color = MaterialTheme.colorScheme.primaryContainer,
) {
    Surface(color = fondo, contentColor = color, shape = RoundedCornerShape(999.dp)) {
        Text(
            text = texto,
            style = MaterialTheme.typography.labelSmall,
            modifier = Modifier.padding(horizontal = 9.dp, vertical = 3.dp),
            fontWeight = FontWeight.SemiBold,
        )
    }
}

@Composable
fun SelectorCantidad(
    valor: Int,
    maximo: Int,
    alCambiar: (Int) -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier,
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center,
    ) {
        FilledTonalIconButton(
            onClick = { if (valor > 0) alCambiar((valor - 1).coerceAtLeast(0)) },
            enabled = valor > 0,
            modifier = Modifier.size(42.dp),
        ) {
            Text("−", fontSize = 22.sp, fontWeight = FontWeight.Bold)
        }
        Text(
            text = valor.toString(),
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier
                .width(44.dp)
                .padding(horizontal = 4.dp),
            textAlign = TextAlign.Center,
        )
        FilledTonalIconButton(
            onClick = { if (valor < maximo) alCambiar((valor + 1).coerceAtMost(maximo)) },
            enabled = valor < maximo,
            modifier = Modifier.size(42.dp),
        ) {
            Text("+", fontSize = 20.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
fun DatoLinea(etiqueta: String, valor: String) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(
            text = etiqueta,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.width(88.dp),
        )
        Text(
            text = valor.ifBlank { "—" },
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
fun TituloSeccion(texto: String, modifier: Modifier = Modifier) {
    Text(
        text = texto.uppercase(Locale.getDefault()),
        style = MaterialTheme.typography.labelSmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        fontWeight = FontWeight.Bold,
        letterSpacing = 0.7.sp,
        modifier = modifier.padding(top = 16.dp, bottom = 6.dp),
    )
}

@Composable
fun DivisorSuave() {
    HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.5f))
}

/**
 * Dirección pública de la foto de un producto. La API entrega sólo el nombre
 * del archivo guardado en `public/image`; aquí se completa con el servidor y se
 * prefiere la miniatura de 300×300 que genera Imagenes::subir().
 */
fun urlImagenProducto(servidor: String, archivo: String, miniatura: Boolean): String {
    val limpio = archivo.trim()
    if (limpio.isBlank()) return ""
    if (limpio.startsWith("http://", ignoreCase = true) ||
        limpio.startsWith("https://", ignoreCase = true)
    ) {
        return limpio
    }
    val nombre = if (miniatura) {
        limpio.replace(Regex("(\\.[A-Za-z0-9]+)$"), "_mini$1")
    } else {
        limpio
    }
    return "${servidor.trim().trimEnd('/')}/public/image/${nombre.trimStart('/')}"
}

/**
 * Miniatura cuadrada de un producto con respaldo: si no existe la versión
 * `_mini`, intenta la imagen original; si tampoco hay, muestra el icono de
 * producto para que la fila nunca quede rota.
 */
@Composable
fun ImagenProducto(
    servidor: String,
    archivo: String,
    descripcion: String = "Producto",
    tamano: Dp = 48.dp,
    modifier: Modifier = Modifier,
) {
    var usarMiniatura by remember(archivo) { mutableStateOf(true) }
    val url = remember(servidor, archivo, usarMiniatura) {
        urlImagenProducto(servidor, archivo, usarMiniatura)
    }
    val forma = RoundedCornerShape(9.dp)

    Box(
        modifier = modifier
            .size(tamano)
            .clip(forma)
            .background(MaterialTheme.colorScheme.surfaceVariant),
        contentAlignment = Alignment.Center,
    ) {
        if (url.isBlank()) {
            Icon(
                Icons.Default.ShoppingCart,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(tamano * 0.45f),
            )
        } else {
            AsyncImage(
                model = ImageRequest.Builder(LocalContext.current)
                    .data(url)
                    .crossfade(true)
                    .build(),
                contentDescription = descripcion,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
                onError = {
                    if (usarMiniatura) usarMiniatura = false
                },
            )
        }
    }
}

@Composable
fun FotoMiniatura(foto: PhotoMeta, alQuitar: () -> Unit) {
    val bitmap = remember(foto.ruta) {
        runCatching { BitmapFactory.decodeFile(foto.ruta)?.asImageBitmap() }.getOrNull()
    }
    Box(modifier = Modifier.size(86.dp)) {
        if (bitmap != null) {
            Image(
                bitmap = bitmap,
                contentDescription = "Foto de la entrega",
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .fillMaxSize()
                    .clip(RoundedCornerShape(10.dp)),
            )
        } else {
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .clip(RoundedCornerShape(10.dp))
                    .background(MaterialTheme.colorScheme.surfaceVariant),
                contentAlignment = Alignment.Center,
            ) {
                Text("Foto", style = MaterialTheme.typography.labelSmall)
            }
        }
        Surface(
            color = Color.Black.copy(alpha = 0.65f),
            shape = CircleShape,
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(3.dp)
                .size(24.dp),
        ) {
            IconButton(onClick = alQuitar, modifier = Modifier.size(24.dp)) {
                Icon(Icons.Default.Close, contentDescription = "Quitar foto", tint = Color.White, modifier = Modifier.size(15.dp))
            }
        }
    }
}

@Composable
fun MensajeVacio(titulo: String, detalle: String = "") {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(titulo, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        if (detalle.isNotBlank()) {
            Spacer(Modifier.height(6.dp))
            Text(
                detalle,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
fun ChipMotivo(texto: String, seleccionado: Boolean, onClick: () -> Unit) {
    FilterChip(
        selected = seleccionado,
        onClick = onClick,
        label = { Text(texto) },
        shape = RoundedCornerShape(999.dp),
    )
}

@Composable
fun CajaInfo(texto: String, modifier: Modifier = Modifier) {
    Surface(
        color = MaterialTheme.colorScheme.primaryContainer,
        contentColor = MaterialTheme.colorScheme.onPrimaryContainer,
        shape = RoundedCornerShape(10.dp),
        modifier = modifier.fillMaxWidth(),
    ) {
        Text(
            texto,
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier.padding(12.dp),
        )
    }
}

fun fechaCorta(valor: String): String {
    if (valor.isBlank()) return "—"
    return runCatching {
        LocalDate.parse(valor.take(10), DateTimeFormatter.ISO_LOCAL_DATE)
            .format(DateTimeFormatter.ofPattern("dd/MM/yyyy"))
    }.getOrDefault(valor)
}

fun fechaHora(valor: String): String {
    if (valor.isBlank()) return "—"
    return runCatching {
        LocalDateTime.parse(valor.replace(" ", "T").take(19))
            .format(DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm"))
    }.getOrDefault(valor)
}
