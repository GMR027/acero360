package mx.com.iguzman.acero360.entregas

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import mx.com.iguzman.acero360.entregas.ui.AceroTheme
import mx.com.iguzman.acero360.entregas.ui.AppRoot
import mx.com.iguzman.acero360.entregas.ui.AppViewModel

class MainActivity : ComponentActivity() {

    private val modelo: AppViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            AceroTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    AppRoot(modelo)
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        modelo.onResume()
    }
}
