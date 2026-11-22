package mx.com.iguzman.acero360.entregas

import android.app.Activity
import android.content.res.Configuration
import android.os.Bundle
import android.os.SystemClock
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import mx.com.iguzman.acero360.entregas.ui.AceroTheme
import mx.com.iguzman.acero360.entregas.ui.AppRoot
import mx.com.iguzman.acero360.entregas.ui.AppViewModel
import mx.com.iguzman.acero360.entregas.ui.esTemaOscuro

class MainActivity : FragmentActivity() {

    private val modelo: AppViewModel by viewModels()

    /** Cuándo salió la app a segundo plano; sirve para volver a pedir la huella. */
    private var salioEn = 0L

    override fun onCreate(savedInstanceState: Bundle?) {
        aplicarTemaDeArranque()
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            val estado by modelo.estado.collectAsStateWithLifecycle()
            var introCompletada by rememberSaveable { mutableStateOf(false) }
            AceroTheme(modo = estado.tema) {
                val oscuro = esTemaOscuro()
                val vista = LocalView.current
                val fondoOscuro = estado.bloqueado || !introCompletada
                SideEffect {
                    val ventana = (vista.context as? Activity)?.window ?: return@SideEffect
                    WindowCompat.getInsetsController(ventana, vista).apply {
                        isAppearanceLightStatusBars = !oscuro && !fondoOscuro
                        isAppearanceLightNavigationBars = !oscuro && !fondoOscuro
                    }
                }
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    AppRoot(
                        modelo = modelo,
                        introCompletada = introCompletada,
                        alCompletarIntro = { introCompletada = true },
                    )
                }
            }
        }
    }

    override fun onStart() {
        super.onStart()
        if (salioEn > 0 && SystemClock.elapsedRealtime() - salioEn >= 60_000L) {
            modelo.bloquear()
        }
        salioEn = 0
    }

    override fun onStop() {
        salioEn = SystemClock.elapsedRealtime()
        super.onStop()
    }

    override fun onResume() {
        super.onResume()
        modelo.onResume()
    }

    /**
     * El modo oscuro elegido en Perfil también se aplica a la ventana nativa
     * (fondo, barra de estado y barra de navegación) antes de dibujar Compose.
     */
    private fun aplicarTemaDeArranque() {
        val prefs = getSharedPreferences("acero_entregas", MODE_PRIVATE)
        val tema = prefs.getString("tema", "sistema")
        val oscuro = when (tema) {
            "claro" -> false
            "oscuro" -> true
            else -> (resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) ==
                Configuration.UI_MODE_NIGHT_YES
        }
        if (oscuro) setTheme(R.style.Theme_AceroEntregas_Oscuro) else setTheme(R.style.Theme_AceroEntregas)
    }
}
