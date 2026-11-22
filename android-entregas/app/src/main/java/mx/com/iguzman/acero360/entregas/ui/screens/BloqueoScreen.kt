package mx.com.iguzman.acero360.entregas.ui.screens

import androidx.activity.compose.LocalActivity
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import kotlinx.coroutines.delay
import mx.com.iguzman.acero360.entregas.R
import mx.com.iguzman.acero360.entregas.ui.AceroAlerta
import mx.com.iguzman.acero360.entregas.ui.BotonPrincipal
import mx.com.iguzman.acero360.entregas.ui.UiState
import mx.com.iguzman.acero360.entregas.util.Biometria

/**
 * Pantalla que tapa la aplicación cuando el acceso con huella está activo.
 * Se muestra al abrir la app y al volver a ella después de un minuto en
 * segundo plano. Si la huella falla o el teléfono deja de tenerla, siempre
 * queda el camino de entrar con contraseña.
 */
@Composable
fun BloqueoScreen(
    estado: UiState,
    alDesbloquear: () -> Unit,
    alUsarContrasena: () -> Unit,
) {
    val contexto = androidx.compose.ui.platform.LocalContext.current
    val actividad = LocalActivity.current as? FragmentActivity
    var error by rememberSaveable { mutableStateOf("") }
    var pidiendo by remember { mutableStateOf(false) }
    var intentoAutomatico by rememberSaveable { mutableStateOf(false) }

    fun pedirHuella() {
        if (pidiendo) return
        if (actividad == null) {
            error = "No se pudo abrir el lector de huellas."
            return
        }
        if (!estado.biometriaDisponible) {
            error = Biometria.motivoNoDisponible(contexto)
            return
        }
        error = ""
        pidiendo = true
        Biometria.autenticar(
            actividad = actividad,
            titulo = "Desbloquear Acero Entregas",
            subtitulo = "Usa tu huella digital para entrar",
            alExito = {
                pidiendo = false
                alDesbloquear()
            },
            alError = {
                pidiendo = false
                error = it
            },
        )
    }

    LaunchedEffect(Unit) {
        if (!intentoAutomatico && estado.biometriaDisponible) {
            intentoAutomatico = true
            delay(250)
            pedirHuella()
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .safeDrawingPadding()
            .padding(horizontal = 28.dp, vertical = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Image(
            painter = painterResource(R.drawable.logo),
            contentDescription = "Acero Entregas",
            modifier = Modifier.size(112.dp),
        )
        Spacer(Modifier.height(18.dp))
        Text(
            "Acero Entregas",
            style = MaterialTheme.typography.headlineSmall,
            color = MaterialTheme.colorScheme.primary,
        )
        Text(
            "La aplicación está bloqueada",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(28.dp))
        Icon(
            Icons.Default.Lock,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.primary,
            modifier = Modifier.size(40.dp),
        )
        Spacer(Modifier.height(10.dp))
        Text(
            "Confirma tu huella digital para entrar.",
            style = MaterialTheme.typography.bodyLarge,
            textAlign = TextAlign.Center,
        )

        if (!estado.biometriaDisponible) {
            Spacer(Modifier.height(10.dp))
            Text(
                Biometria.motivoNoDisponible(contexto),
                style = MaterialTheme.typography.bodySmall,
                color = AceroAlerta,
                textAlign = TextAlign.Center,
            )
        }

        if (error.isNotBlank()) {
            Spacer(Modifier.height(10.dp))
            Text(
                error,
                style = MaterialTheme.typography.bodySmall,
                color = AceroAlerta,
                textAlign = TextAlign.Center,
            )
        }

        Spacer(Modifier.height(26.dp))
        BotonPrincipal(
            texto = "Desbloquear con huella",
            onClick = ::pedirHuella,
            habilitado = estado.biometriaDisponible,
            cargando = pidiendo,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(10.dp))
        OutlinedButton(
            onClick = alUsarContrasena,
            shape = RoundedCornerShape(10.dp),
            modifier = Modifier
                .fillMaxWidth()
                .height(50.dp),
        ) {
            Text("Entrar con contraseña", fontWeight = FontWeight.SemiBold)
        }
    }
}
