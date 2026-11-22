package mx.com.iguzman.acero360.entregas.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

/** Preferencias de apariencia que se guardan en el teléfono. */
object Temas {
    const val SISTEMA = "sistema"
    const val CLARO = "claro"
    const val OSCURO = "oscuro"
}

private val Azul = Color(0xFF4679A6)
private val AzulOscuro = Color(0xFF2F5F8C)
private val Fondo = Color(0xFFF4F7FA)
private val Superficie = Color(0xFFFFFFFF)
private val Texto = Color(0xFF33343A)
private val TextoSuave = Color(0xFF6B6B70)
private val Borde = Color(0xFFD9DDE3)

private val ExitoClaro = Color(0xFF1C6B3C)
private val ExitoSuaveClaro = Color(0xFFEAF7EF)
private val AlertaClaro = Color(0xFFA12A2A)
private val AlertaSuaveClaro = Color(0xFFFDF0F0)
private val AvisoClaro = Color(0xFF8A5A00)
private val AvisoSuaveClaro = Color(0xFFFFF7E2)

private val AzulOscuroTema = Color(0xFF8FB8DE)
private val ExitoOscuro = Color(0xFF7FD99C)
private val ExitoSuaveOscuro = Color(0xFF143A24)
private val AlertaOscuro = Color(0xFFFFB4AB)
private val AlertaSuaveOscuro = Color(0xFF4A1515)
private val AvisoOscuro = Color(0xFFFFD180)
private val AvisoSuaveOscuro = Color(0xFF3A2E12)

/**
 * Colores de estado que se adaptan al tema activo. En las pantallas se usan
 * como hasta ahora (`AceroAviso`, `AceroExito`…), pero cambian de tono cuando
 * el usuario elige el modo oscuro.
 */
val AceroAzul: Color
    @Composable get() = if (esTemaOscuro()) AzulOscuroTema else Azul

val AceroExito: Color
    @Composable get() = if (esTemaOscuro()) ExitoOscuro else ExitoClaro

val AceroExitoSuave: Color
    @Composable get() = if (esTemaOscuro()) ExitoSuaveOscuro else ExitoSuaveClaro

val AceroAlerta: Color
    @Composable get() = if (esTemaOscuro()) AlertaOscuro else AlertaClaro

val AceroAlertaSuave: Color
    @Composable get() = if (esTemaOscuro()) AlertaSuaveOscuro else AlertaSuaveClaro

val AceroAviso: Color
    @Composable get() = if (esTemaOscuro()) AvisoOscuro else AvisoClaro

val AceroAvisoSuave: Color
    @Composable get() = if (esTemaOscuro()) AvisoSuaveOscuro else AvisoSuaveClaro

@Composable
fun esTemaOscuro(): Boolean = MaterialTheme.colorScheme.background.luminance() < 0.5f

private val Claro = lightColorScheme(
    primary = Azul,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFEAF1F8),
    onPrimaryContainer = AzulOscuro,
    secondary = AzulOscuro,
    onSecondary = Color.White,
    background = Fondo,
    onBackground = Texto,
    surface = Superficie,
    onSurface = Texto,
    surfaceVariant = Color(0xFFEDF1F6),
    onSurfaceVariant = TextoSuave,
    outline = Borde,
    error = AlertaClaro,
    onError = Color.White,
    errorContainer = AlertaSuaveClaro,
    onErrorContainer = AlertaClaro,
)

private val Oscuro = darkColorScheme(
    primary = AzulOscuroTema,
    onPrimary = Color(0xFF10263B),
    primaryContainer = Color(0xFF1D3A55),
    onPrimaryContainer = Color(0xFFD7E8F8),
    secondary = Color(0xFF9CC3E6),
    onSecondary = Color(0xFF10263B),
    background = Color(0xFF12161C),
    onBackground = Color(0xFFE6E8EB),
    surface = Color(0xFF1B2027),
    onSurface = Color(0xFFE6E8EB),
    surfaceVariant = Color(0xFF262D36),
    onSurfaceVariant = Color(0xFFB6BDC7),
    outline = Color(0xFF3A434E),
    error = AlertaOscuro,
    onError = Color(0xFF690005),
    errorContainer = AlertaSuaveOscuro,
    onErrorContainer = Color(0xFFFFDAD6),
)

private val Tipografia = Typography(
    headlineSmall = TextStyle(fontSize = 24.sp, lineHeight = 30.sp, fontWeight = FontWeight.Bold),
    titleLarge = TextStyle(fontSize = 20.sp, lineHeight = 26.sp, fontWeight = FontWeight.Bold),
    titleMedium = TextStyle(fontSize = 17.sp, lineHeight = 22.sp, fontWeight = FontWeight.SemiBold),
    bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 23.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 20.sp),
    labelLarge = TextStyle(fontSize = 16.sp, lineHeight = 20.sp, fontWeight = FontWeight.SemiBold),
)

@Composable
fun AceroTheme(
    modo: String = Temas.SISTEMA,
    content: @Composable () -> Unit,
) {
    val oscuro = when (modo) {
        Temas.CLARO -> false
        Temas.OSCURO -> true
        else -> isSystemInDarkTheme()
    }
    MaterialTheme(
        colorScheme = if (oscuro) Oscuro else Claro,
        typography = Tipografia,
        content = content,
    )
}
