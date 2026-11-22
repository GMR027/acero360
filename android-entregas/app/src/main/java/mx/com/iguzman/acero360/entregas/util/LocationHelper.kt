package mx.com.iguzman.acero360.entregas.util

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import androidx.core.content.ContextCompat
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/** Ubicación puntual para las fotos y la entrega. No hace seguimiento. */
object LocationHelper {

    fun tienePermiso(context: Context): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

    @Suppress("MissingPermission", "DEPRECATION")
    suspend fun actual(context: Context, esperaMs: Long = 3500): Location? {
        if (!tienePermiso(context)) return null
        val manager = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return null

        val ultima = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
            .mapNotNull { proveedor ->
                runCatching { manager.getLastKnownLocation(proveedor) }.getOrNull()
            }
            .maxByOrNull { it.time }

        if (ultima != null && System.currentTimeMillis() - ultima.time < 5 * 60_000L) {
            return ultima
        }

        return suspendCancellableCoroutine { continuacion ->
            var terminado = false
            val listener = object : LocationListener {
                override fun onLocationChanged(location: Location) {
                    if (terminado) return
                    terminado = true
                    manager.removeUpdates(this)
                    if (continuacion.isActive) continuacion.resume(location)
                }

                override fun onProviderEnabled(provider: String) {}
                override fun onProviderDisabled(provider: String) {}
                override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
            }

            val handler = Handler(Looper.getMainLooper())
            val timeout = Runnable {
                if (!terminado) {
                    terminado = true
                    runCatching { manager.removeUpdates(listener) }
                    if (continuacion.isActive) continuacion.resume(ultima)
                }
            }

            runCatching {
                val proveedor = when {
                    manager.isProviderEnabled(LocationManager.GPS_PROVIDER) -> LocationManager.GPS_PROVIDER
                    manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER) -> LocationManager.NETWORK_PROVIDER
                    else -> null
                }
                if (proveedor == null) {
                    terminado = true
                    if (continuacion.isActive) continuacion.resume(ultima)
                } else {
                    manager.requestSingleUpdate(proveedor, listener, Looper.getMainLooper())
                    handler.postDelayed(timeout, esperaMs)
                }
            }.onFailure {
                terminado = true
                if (continuacion.isActive) continuacion.resume(ultima)
            }

            continuacion.invokeOnCancellation {
                terminado = true
                runCatching { manager.removeUpdates(listener) }
                handler.removeCallbacks(timeout)
            }
        }
    }

    fun comoTexto(location: Location?): String =
        if (location == null) "" else String.format(
            java.util.Locale.US,
            "%.7f,%.7f",
            location.latitude,
            location.longitude
        )
}
