package mx.com.iguzman.acero360.entregas.data

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.io.File
import java.util.concurrent.TimeUnit

/**
 * Sube en segundo plano las entregas/incidencias/visitas guardadas sin
 * internet. Cada operación lleva su token: si el servidor ya la recibió, no
 * se duplica.
 */
class SyncWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val session = SessionStore(applicationContext)
        if (session.token.isBlank()) return Result.success()

        val api = Api(session)
        val queue = QueueStore(session)
        val items = queue.listar().filter { it.error == null }
        var subioAlgo = false

        for (item in items) {
            try {
                api.subir(item)
                queue.quitar(item.token)
                item.fotos.forEach { foto -> runCatching { File(foto.ruta).delete() } }
                subioAlgo = true
            } catch (e: ApiException) {
                if (e.codigo in 400..499) {
                    // Error de datos: no se reintenta solo. La app lo muestra.
                    queue.marcarError(item.token, e.message ?: "No se pudo subir.")
                } else {
                    return Result.retry()
                }
            } catch (e: Exception) {
                return Result.retry()
            }
        }

        if (subioAlgo) {
            runCatching { api.pendientes() }
            session.lastSync = QueueStore.ahora()
        }
        return Result.success()
    }

    companion object {
        private const val UNICO = "acero_entregas_sync"
        private const val PERIODICO = "acero_entregas_sync_periodico"

        fun programarAhora(context: Context) {
            val request = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(UNICO, ExistingWorkPolicy.REPLACE, request)
        }

        fun programarPeriodico(context: Context) {
            val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                PERIODICO,
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
        }
    }
}
