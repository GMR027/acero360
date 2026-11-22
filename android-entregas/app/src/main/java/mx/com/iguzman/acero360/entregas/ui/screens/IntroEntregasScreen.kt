package mx.com.iguzman.acero360.entregas.ui.screens

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import mx.com.iguzman.acero360.entregas.R
import kotlin.math.PI
import kotlin.math.min
import kotlin.math.sin
import kotlin.random.Random

private val FondoArriba = Color(0xFF07120E)
private val FondoMedio = Color(0xFF0C2118)
private val FondoAbajo = Color(0xFF050A08)
private val VerdeBrillo = Color(0xFF5ED39B)
private val VerdeSuave = Color(0xFF9CE7C3)
private val Dorado = Color(0xFFF3B477)
private val Bronce = Color(0xFFC69A5E)

/**
 * Animación de bienvenida de Acero Entregas.
 *
 * La app se abre como una bóveda: dos paneles metálicos se separan, aparece
 * el logotipo, la ilustración de entregas —recoloreada a la paleta de la
 * app— y una barra de avance mientras se prepara la ruta. Tocar la pantalla
 * salta la animación.
 */
@Composable
fun IntroEntregasScreen(alTerminar: () -> Unit) {
    val apertura = remember { Animatable(0f) }
    val contenido = remember { Animatable(0f) }
    val marca = remember { Animatable(0f) }
    val progreso = remember { Animatable(0f) }
    var salida by remember { mutableStateOf(false) }
    var terminado by remember { mutableStateOf(false) }

    fun terminar() {
        if (terminado) return
        terminado = true
        alTerminar()
    }

    fun solicitarSalida() {
        if (!salida) salida = true
    }

    val alphaSalida by animateFloatAsState(
        targetValue = if (salida) 0f else 1f,
        animationSpec = tween(durationMillis = 420, easing = FastOutSlowInEasing),
        label = "salidaIntro",
    )

    LaunchedEffect(salida) {
        if (salida) {
            delay(420)
            terminar()
        }
    }

    LaunchedEffect(Unit) {
        coroutineScope {
            launch {
                delay(140)
                apertura.animateTo(1f, tween(durationMillis = 820, easing = FastOutSlowInEasing))
            }
            launch {
                delay(500)
                contenido.animateTo(1f, tween(durationMillis = 950, easing = FastOutSlowInEasing))
            }
            launch {
                delay(760)
                marca.animateTo(
                    targetValue = 1f,
                    animationSpec = spring(
                        dampingRatio = Spring.DampingRatioMediumBouncy,
                        stiffness = Spring.StiffnessMediumLow,
                    ),
                )
            }
            launch {
                delay(980)
                progreso.animateTo(1f, tween(durationMillis = 1450, easing = LinearEasing))
            }
        }
        delay(220)
        solicitarSalida()
    }

    val movimiento = rememberInfiniteTransition(label = "introEntregas")
    val giro by movimiento.animateFloat(
        initialValue = 0f,
        targetValue = 360f,
        animationSpec = infiniteRepeatable(tween(26000, easing = LinearEasing)),
        label = "giroBoveda",
    )
    val giroInverso by movimiento.animateFloat(
        initialValue = 360f,
        targetValue = 0f,
        animationSpec = infiniteRepeatable(tween(38000, easing = LinearEasing)),
        label = "giroInverso",
    )
    val pulso by movimiento.animateFloat(
        initialValue = 0.82f,
        targetValue = 1.08f,
        animationSpec = infiniteRepeatable(
            tween(2200, easing = FastOutSlowInEasing),
            RepeatMode.Reverse,
        ),
        label = "pulsoLogo",
    )
    val flotar by movimiento.animateFloat(
        initialValue = -5f,
        targetValue = 5f,
        animationSpec = infiniteRepeatable(
            tween(3200, easing = FastOutSlowInEasing),
            RepeatMode.Reverse,
        ),
        label = "flotarIlustracion",
    )
    val brillo by movimiento.animateFloat(
        initialValue = -0.35f,
        targetValue = 1.35f,
        animationSpec = infiniteRepeatable(
            tween(3200, delayMillis = 900, easing = LinearEasing),
        ),
        label = "brilloPanel",
    )
    val tiempo by movimiento.animateFloat(
        initialValue = 0f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(tween(9000, easing = LinearEasing)),
        label = "particulas",
    )

    val particulas = remember {
        val azar = Random(12)
        List(20) {
            Particula(
                x = azar.nextFloat(),
                y = azar.nextFloat(),
                radio = 1.1f + azar.nextFloat() * 2.2f,
                velocidad = 0.12f + azar.nextFloat() * 0.25f,
                fase = azar.nextFloat(),
                color = if (azar.nextBoolean()) VerdeBrillo else Dorado,
            )
        }
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .graphicsLayer { alpha = alphaSalida }
            .background(
                Brush.verticalGradient(
                    listOf(FondoArriba, FondoMedio, FondoAbajo),
                ),
            )
            .clickable(
                interactionSource = remember { MutableInteractionSource() },
                indication = null,
            ) { solicitarSalida() },
    ) {
        FondoBoveda(
            giro = giro,
            giroInverso = giroInverso,
            tiempo = tiempo,
            particulas = particulas,
            pulso = pulso,
        )

        BoxWithConstraints(
            modifier = Modifier
                .fillMaxSize()
                .safeDrawingPadding(),
        ) {
            Column(
                modifier = Modifier
                    .align(Alignment.Center)
                    .fillMaxWidth()
                    .padding(horizontal = 24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                MarcaConAnillo(
                    progreso = marca.value,
                    giro = giro,
                    pulso = pulso,
                )
                Spacer(Modifier.height(14.dp))
                Text(
                    text = "ACERO ENTREGAS",
                    style = MaterialTheme.typography.headlineSmall,
                    color = Color.White,
                    fontWeight = FontWeight.Black,
                    letterSpacing = (0.8f + 2.4f * contenido.value).sp,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.graphicsLayer { alpha = contenido.value },
                )
                Text(
                    text = "Tu pedido, en camino",
                    color = VerdeSuave.copy(alpha = 0.72f),
                    fontSize = 13.sp,
                    letterSpacing = 2.2.sp,
                    textAlign = TextAlign.Center,
                    modifier = Modifier
                        .padding(top = 3.dp)
                        .graphicsLayer { alpha = contenido.value },
                )
                Spacer(Modifier.height(24.dp))
                PanelIlustracion(
                    progreso = contenido.value,
                    flotar = flotar,
                    brillo = brillo,
                )
            }

            Column(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .padding(bottom = 48.dp)
                    .graphicsLayer { alpha = contenido.value },
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(
                    text = "PREPARANDO TU RUTA",
                    color = Color.White.copy(alpha = 0.56f),
                    fontSize = 10.sp,
                    letterSpacing = 3.2.sp,
                    fontWeight = FontWeight.Medium,
                )
                Spacer(Modifier.height(11.dp))
                Box(
                    modifier = Modifier
                        .width(188.dp)
                        .height(3.dp)
                        .clip(CircleShape)
                        .background(Color.White.copy(alpha = 0.13f)),
                ) {
                    Box(
                        modifier = Modifier
                            .fillMaxHeight()
                            .fillMaxWidth(progreso.value.coerceIn(0f, 1f))
                            .background(
                                Brush.horizontalGradient(
                                    listOf(VerdeBrillo, VerdeSuave, Dorado),
                                ),
                            ),
                    )
                }
            }
        }

        // La bóveda se abre: dos paneles metálicos se deslizan a los lados.
        RowPaneles(apertura = apertura.value)

        Box(
            modifier = Modifier
                .align(Alignment.Center)
                .width(3.dp)
                .fillMaxHeight()
                .graphicsLayer {
                    alpha = (1f - apertura.value * 1.7f).coerceIn(0f, 1f)
                }
                .background(
                    Brush.verticalGradient(
                        listOf(
                            Color.Transparent,
                            Dorado.copy(alpha = 0.9f),
                            Color.White.copy(alpha = 0.95f),
                            Dorado.copy(alpha = 0.9f),
                            Color.Transparent,
                        ),
                    ),
                ),
        )
    }
}

private data class Particula(
    val x: Float,
    val y: Float,
    val radio: Float,
    val velocidad: Float,
    val fase: Float,
    val color: Color,
)

@Composable
private fun FondoBoveda(
    giro: Float,
    giroInverso: Float,
    tiempo: Float,
    particulas: List<Particula>,
    pulso: Float,
) {
    Canvas(Modifier.fillMaxSize()) {
        val centro = androidx.compose.ui.geometry.Offset(size.width / 2f, size.height * 0.46f)
        val radio = size.minDimension * 0.58f

        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(
                    VerdeBrillo.copy(alpha = 0.20f * pulso),
                    VerdeBrillo.copy(alpha = 0.05f),
                    Color.Transparent,
                ),
                center = centro,
                radius = radio,
            ),
            radius = radio,
            center = centro,
        )

        rotate(giro, pivot = centro) {
            drawCircle(
                color = Color.White.copy(alpha = 0.045f),
                radius = radio * 0.86f,
                center = centro,
                style = Stroke(width = 1.dp.toPx()),
            )
            drawArc(
                color = VerdeBrillo.copy(alpha = 0.20f),
                startAngle = 18f,
                sweepAngle = 106f,
                useCenter = false,
                topLeft = androidx.compose.ui.geometry.Offset(
                    centro.x - radio * 0.86f,
                    centro.y - radio * 0.86f,
                ),
                size = androidx.compose.ui.geometry.Size(radio * 1.72f, radio * 1.72f),
                style = Stroke(
                    width = 2.dp.toPx(),
                    cap = StrokeCap.Round,
                    pathEffect = PathEffect.dashPathEffect(floatArrayOf(17f, 15f)),
                ),
            )
            drawArc(
                color = Dorado.copy(alpha = 0.16f),
                startAngle = 196f,
                sweepAngle = 58f,
                useCenter = false,
                topLeft = androidx.compose.ui.geometry.Offset(
                    centro.x - radio * 0.86f,
                    centro.y - radio * 0.86f,
                ),
                size = androidx.compose.ui.geometry.Size(radio * 1.72f, radio * 1.72f),
                style = Stroke(
                    width = 1.5.dp.toPx(),
                    cap = StrokeCap.Round,
                    pathEffect = PathEffect.dashPathEffect(floatArrayOf(4f, 12f)),
                ),
            )
        }

        rotate(giroInverso, pivot = centro) {
            drawCircle(
                color = Color.White.copy(alpha = 0.03f),
                radius = radio * 0.66f,
                center = centro,
                style = Stroke(width = 1.dp.toPx()),
            )
            drawArc(
                color = Dorado.copy(alpha = 0.18f),
                startAngle = 300f,
                sweepAngle = 82f,
                useCenter = false,
                topLeft = androidx.compose.ui.geometry.Offset(
                    centro.x - radio * 0.66f,
                    centro.y - radio * 0.66f,
                ),
                size = androidx.compose.ui.geometry.Size(radio * 1.32f, radio * 1.32f),
                style = Stroke(
                    width = 2.dp.toPx(),
                    cap = StrokeCap.Round,
                ),
            )
        }

        particulas.forEach { particula ->
            val ciclo = (tiempo * (0.65f + particula.velocidad) + particula.fase) % 1f
            val y = size.height * (1.04f - ciclo)
            val x = size.width * particula.x +
                sin((ciclo * 2f * PI + particula.fase * 2f * PI).toFloat()) * 13.dp.toPx()
            val alfa = (0.10f + 0.34f * (1f - ciclo)) * (0.6f + 0.4f * pulso)
            drawCircle(
                color = particula.color.copy(alpha = alfa.coerceIn(0f, 0.45f)),
                radius = particula.radio.dp.toPx(),
                center = androidx.compose.ui.geometry.Offset(x, y),
            )
        }
    }
}

@Composable
private fun MarcaConAnillo(progreso: Float, giro: Float, pulso: Float) {
    Box(
        modifier = Modifier
            .size(126.dp)
            .graphicsLayer {
                alpha = progreso
                scaleX = 0.72f + 0.28f * progreso
                scaleY = 0.72f + 0.28f * progreso
                translationY = (1f - progreso) * 22.dp.toPx()
            },
        contentAlignment = Alignment.Center,
    ) {
        Canvas(Modifier.fillMaxSize()) {
            val centro = androidx.compose.ui.geometry.Offset(size.width / 2f, size.height / 2f)
            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(
                        VerdeBrillo.copy(alpha = 0.38f * pulso),
                        Color.Transparent,
                    ),
                    center = centro,
                    radius = size.minDimension * 0.55f,
                ),
                radius = size.minDimension * 0.55f,
                center = centro,
            )
            rotate(giro, pivot = centro) {
                drawArc(
                    color = VerdeSuave.copy(alpha = 0.80f),
                    startAngle = 0f,
                    sweepAngle = 82f,
                    useCenter = false,
                    topLeft = androidx.compose.ui.geometry.Offset(
                        centro.x - size.minDimension * 0.46f,
                        centro.y - size.minDimension * 0.46f,
                    ),
                    size = androidx.compose.ui.geometry.Size(
                        size.minDimension * 0.92f,
                        size.minDimension * 0.92f,
                    ),
                    style = Stroke(width = 2.dp.toPx(), cap = StrokeCap.Round),
                )
                drawArc(
                    color = Dorado.copy(alpha = 0.80f),
                    startAngle = 188f,
                    sweepAngle = 38f,
                    useCenter = false,
                    topLeft = androidx.compose.ui.geometry.Offset(
                        centro.x - size.minDimension * 0.46f,
                        centro.y - size.minDimension * 0.46f,
                    ),
                    size = androidx.compose.ui.geometry.Size(
                        size.minDimension * 0.92f,
                        size.minDimension * 0.92f,
                    ),
                    style = Stroke(width = 1.5.dp.toPx(), cap = StrokeCap.Round),
                )
            }
            drawCircle(
                color = Color.White.copy(alpha = 0.12f),
                radius = size.minDimension * 0.38f,
                center = centro,
                style = Stroke(width = 1.dp.toPx()),
            )
        }
        Image(
            painter = painterResource(R.drawable.logo),
            contentDescription = "Acero Entregas",
            modifier = Modifier
                .size(78.dp)
                .clip(RoundedCornerShape(21.dp)),
        )
    }
}

@Composable
private fun PanelIlustracion(progreso: Float, flotar: Float, brillo: Float) {
    val anchoMaximo = LocalConfiguration.current.screenWidthDp.dp * 0.86f
    val forma = RoundedCornerShape(30.dp)
    Box(
        modifier = Modifier
            .width(min(anchoMaximo.value, 386f).dp)
            .aspectRatio(1.26f)
            .graphicsLayer {
                alpha = progreso
                scaleX = 0.90f + 0.10f * progreso
                scaleY = 0.90f + 0.10f * progreso
                translationY = (1f - progreso) * 46.dp.toPx() + flotar.dp.toPx()
            }
            .clip(forma)
            .background(
                Brush.linearGradient(
                    listOf(
                        Color.White.copy(alpha = 0.105f),
                        Color.White.copy(alpha = 0.035f),
                        Color(0xFF2E7D5C).copy(alpha = 0.10f),
                    ),
                ),
            )
            .border(1.dp, Color.White.copy(alpha = 0.16f), forma),
        contentAlignment = Alignment.Center,
    ) {
        Canvas(Modifier.matchParentSize()) {
            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(
                        VerdeBrillo.copy(alpha = 0.18f),
                        Color.Transparent,
                    ),
                    center = androidx.compose.ui.geometry.Offset(size.width * 0.5f, size.height * 0.52f),
                    radius = size.width * 0.55f,
                ),
                radius = size.width * 0.55f,
                center = androidx.compose.ui.geometry.Offset(size.width * 0.5f, size.height * 0.52f),
            )
        }
        Image(
            painter = painterResource(R.drawable.entrega_intro),
            contentDescription = "Ilustración de entregas",
            contentScale = ContentScale.Fit,
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 12.dp, vertical = 8.dp),
        )
        Canvas(Modifier.matchParentSize()) {
            val x = size.width * brillo
            drawRect(
                brush = Brush.linearGradient(
                    colors = listOf(
                        Color.Transparent,
                        Color.White.copy(alpha = 0.13f),
                        Color.Transparent,
                    ),
                    start = androidx.compose.ui.geometry.Offset(x - size.width * 0.22f, 0f),
                    end = androidx.compose.ui.geometry.Offset(x + size.width * 0.22f, size.height),
                ),
            )
        }
    }
}

@Composable
private fun RowPaneles(apertura: Float) {
    Box(Modifier.fillMaxSize()) {
        PanelBoveda(izquierda = true, apertura = apertura)
        PanelBoveda(izquierda = false, apertura = apertura)
    }
}

@Composable
private fun BoxScope.PanelBoveda(
    izquierda: Boolean,
    apertura: Float,
) {
    Box(
        modifier = Modifier
            .fillMaxHeight()
            .fillMaxWidth(0.5f)
            .align(if (izquierda) Alignment.CenterStart else Alignment.CenterEnd)
            .graphicsLayer {
                translationX = if (izquierda) {
                    -(size.width + 6.dp.toPx()) * apertura
                } else {
                    (size.width + 6.dp.toPx()) * apertura
                }
                alpha = if (apertura < 0.72f) {
                    1f
                } else {
                    ((1f - apertura) / 0.28f).coerceIn(0f, 1f)
                }
            }
            .background(
                Brush.linearGradient(
                    colors = if (izquierda) {
                        listOf(Color(0xFF07120E), Color(0xFF153327), Color(0xFF0A1A13))
                    } else {
                        listOf(Color(0xFF0A1A13), Color(0xFF153327), Color(0xFF07120E))
                    },
                ),
            ),
    ) {
        Canvas(Modifier.fillMaxSize()) {
            val centro = androidx.compose.ui.geometry.Offset(
                if (izquierda) size.width else 0f,
                size.height * 0.43f,
            )
            val radio = size.width * 0.70f

            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(
                        VerdeBrillo.copy(alpha = 0.055f),
                        Color.Transparent,
                    ),
                    center = centro,
                    radius = radio * 1.4f,
                ),
                radius = radio * 1.4f,
                center = centro,
            )

            // La mitad del mecanismo de la bóveda: al cerrarse, los dos
            // paneles forman un solo círculo metálico en el centro.
            drawCircle(
                color = Bronce.copy(alpha = 0.22f),
                radius = radio * 0.58f,
                center = centro,
                style = Stroke(width = 1.4.dp.toPx()),
            )
            drawCircle(
                color = Color.White.copy(alpha = 0.055f),
                radius = radio * 0.43f,
                center = centro,
                style = Stroke(width = 1.dp.toPx()),
            )
            drawCircle(
                color = Bronce.copy(alpha = 0.14f),
                radius = radio * 0.28f,
                center = centro,
                style = Stroke(width = 1.dp.toPx()),
            )

            // Remaches del borde interior.
            val xBorde = if (izquierda) size.width - 14.dp.toPx() else 14.dp.toPx()
            var y = 46.dp.toPx()
            while (y < size.height - 30.dp.toPx()) {
                drawCircle(
                    color = Bronce.copy(alpha = 0.35f),
                    radius = 1.7.dp.toPx(),
                    center = androidx.compose.ui.geometry.Offset(xBorde, y),
                )
                y += 46.dp.toPx()
            }

            // Filo luminoso que queda junto a la costura central.
            val xFilo = if (izquierda) size.width - 1.dp.toPx() else 0f
            drawLine(
                color = Bronce.copy(alpha = 0.65f),
                start = androidx.compose.ui.geometry.Offset(xFilo, 0f),
                end = androidx.compose.ui.geometry.Offset(xFilo, size.height),
                strokeWidth = 2.dp.toPx(),
            )
        }
    }
}
