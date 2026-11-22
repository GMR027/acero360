package mx.com.iguzman.acero360.entregas.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

val AceroAzul = Color(0xFF4679A6)
val AceroAzulOscuro = Color(0xFF2F5F8C)
val AceroFondo = Color(0xFFF4F7FA)
val AceroSuperficie = Color(0xFFFFFFFF)
val AceroTexto = Color(0xFF33343A)
val AceroTextoSuave = Color(0xFF6B6B70)
val AceroBorde = Color(0xFFD9DDE3)
val AceroExito = Color(0xFF1C6B3C)
val AceroExitoSuave = Color(0xFFEAF7EF)
val AceroAlerta = Color(0xFFA12A2A)
val AceroAlertaSuave = Color(0xFFFDF0F0)
val AceroAviso = Color(0xFF8A5A00)
val AceroAvisoSuave = Color(0xFFFFF7E2)

private val Claro = lightColorScheme(
    primary = AceroAzul,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFEAF1F8),
    onPrimaryContainer = AceroAzulOscuro,
    secondary = AceroAzulOscuro,
    onSecondary = Color.White,
    background = AceroFondo,
    onBackground = AceroTexto,
    surface = AceroSuperficie,
    onSurface = AceroTexto,
    surfaceVariant = Color(0xFFEDF1F6),
    onSurfaceVariant = AceroTextoSuave,
    outline = AceroBorde,
    error = AceroAlerta,
    onError = Color.White,
    errorContainer = AceroAlertaSuave,
    onErrorContainer = AceroAlerta,
)

private val Oscuro = darkColorScheme(
    primary = Color(0xFF8FB8DE),
    onPrimary = Color(0xFF10263B),
    primaryContainer = Color(0xFF1D3A55),
    onPrimaryContainer = Color(0xFFD7E8F8),
    background = Color(0xFF12161C),
    onBackground = Color(0xFFE6E8EB),
    surface = Color(0xFF1B2027),
    onSurface = Color(0xFFE6E8EB),
    surfaceVariant = Color(0xFF262D36),
    onSurfaceVariant = Color(0xFFB6BDC7),
    outline = Color(0xFF3A434E),
    error = Color(0xFFFFB4AB),
    onError = Color(0xFF690005),
    errorContainer = Color(0xFF4A1515),
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
    oscuro: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = if (oscuro) Oscuro else Claro,
        typography = Tipografia,
        content = content,
    )
}
