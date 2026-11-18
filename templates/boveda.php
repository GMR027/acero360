<?php
/**
 * templates/boveda.php — Animación de bienvenida: la bóveda se abre al iniciar sesión.
 *
 * La pinta header.php UNA sola vez, en la primera página tras abrirSesionUsuario()
 * (contraseña o huella, web o app Android). Todo es SVG + CSS (_boveda.scss):
 *   0.0–0.3 s  aparece la bóveda cerrada
 *   0.4–1.5 s  gira el volante
 *   1.5–1.8 s  se retraen los pestillos
 *   1.8–3.0 s  la puerta gira sobre sus bisagras y deja ver el logo
 *   3.0–3.6 s  acercamiento y se desvanece (≥ 3 s en total)
 * Sin JS también desaparece (la animación termina en visibility:hidden); boveda.js
 * sólo retira el nodo al acabar.
 *
 * Variables: $saludo — nombre de pila para el saludo (opcional).
 */

$saludo = trim((string) ($saludo ?? ''));

/** Remaches repartidos en un anillo (cx, cy) de radio $r. */
$remaches = static function (float $r, int $cuantos, float $tam, string $clase): string {
    $salida = '';
    for ($i = 0; $i < $cuantos; $i++) {
        $angulo = 2 * M_PI * $i / $cuantos;
        $salida .= sprintf('<circle class="%s" cx="%.1f" cy="%.1f" r="%.1f"/>', $clase, 200 + $r * cos($angulo), 200 + $r * sin($angulo), $tam);
    }
    return $salida;
};
?>
<div class="boveda" data-boveda role="status" aria-live="polite">
    <span class="solo-lectores">Acceso concedido<?= $saludo !== '' ? ', bienvenido ' . escaparValores($saludo) : '' ?>.</span>
    <div class="boveda__escena" aria-hidden="true">
        <svg class="boveda__marco" viewBox="0 0 400 400" focusable="false">
            <defs>
                <linearGradient id="bv-acero" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#f5f7f9"/>
                    <stop offset=".32" stop-color="#aeb6bf"/>
                    <stop offset=".5" stop-color="#eef1f4"/>
                    <stop offset=".78" stop-color="#7f8892"/>
                    <stop offset="1" stop-color="#d3d8de"/>
                </linearGradient>
                <linearGradient id="bv-acero-inv" x1="1" y1="1" x2="0" y2="0">
                    <stop offset="0" stop-color="#f2f4f6"/>
                    <stop offset=".45" stop-color="#9aa2ab"/>
                    <stop offset="1" stop-color="#5f6872"/>
                </linearGradient>
                <radialGradient id="bv-hueco" cx=".5" cy=".5" r=".5">
                    <stop offset="0" stop-color="#2f4b66"/>
                    <stop offset=".7" stop-color="#14202c"/>
                    <stop offset="1" stop-color="#05080b"/>
                </radialGradient>
            </defs>

            <!-- Placas de anclaje: arriba/abajo y en diagonal. -->
            <?php foreach ([0, 45, 135, 180, 225, 315] as $giro) : $grande = $giro % 180 === 0; ?>
                <g transform="rotate(<?= $giro ?> 200 200)">
                    <rect x="<?= $grande ? 180 : 188 ?>" y="<?= $grande ? 10 : 22 ?>" width="<?= $grande ? 40 : 24 ?>" height="<?= $grande ? 34 : 26 ?>" rx="3" fill="url(#bv-acero)" stroke="#5b636c" stroke-width="1.5"/>
                    <circle class="bv-tornillo" cx="<?= $grande ? 188 : 194 ?>" cy="<?= $grande ? 18 : 29 ?>" r="2.4"/>
                    <circle class="bv-tornillo" cx="<?= $grande ? 212 : 206 ?>" cy="<?= $grande ? 18 : 29 ?>" r="2.4"/>
                </g>
            <?php endforeach; ?>

            <!-- Aro exterior, bisel y hueco de la bóveda. -->
            <circle cx="200" cy="200" r="170" fill="url(#bv-acero)" stroke="#59616a" stroke-width="2"/>
            <circle cx="200" cy="200" r="152" fill="url(#bv-acero-inv)"/>
            <circle cx="200" cy="200" r="141" fill="url(#bv-acero)" stroke="#6c747d" stroke-width="1"/>
            <?= $remaches(161, 28, 2.6, 'bv-tornillo') ?>
            <circle cx="200" cy="200" r="130" fill="url(#bv-hueco)" stroke="#3b434c" stroke-width="3"/>

            <!-- Bisagras del lado izquierdo. -->
            <?php foreach ([138, 238] as $y) : ?>
                <rect x="14" y="<?= $y ?>" width="74" height="24" rx="4" fill="url(#bv-acero)" stroke="#5b636c" stroke-width="1.5"/>
                <rect x="58" y="<?= $y - 8 ?>" width="16" height="40" rx="6" fill="url(#bv-acero-inv)" stroke="#5b636c" stroke-width="1.5"/>
                <circle class="bv-tornillo" cx="26" cy="<?= $y + 12 ?>" r="2.6"/>
                <circle class="bv-tornillo" cx="42" cy="<?= $y + 12 ?>" r="2.6"/>
            <?php endforeach; ?>
        </svg>

        <div class="boveda__luz">
            <img src="/public/brand/acero360-marca.svg" alt="" width="64" height="64">
        </div>

        <!-- Puerta: div 3D (gira sobre la bisagra izquierda) con capas para darle grosor. -->
        <div class="boveda__puerta">
            <span class="boveda__canto boveda__canto--1"></span>
            <span class="boveda__canto boveda__canto--2"></span>
            <span class="boveda__canto boveda__canto--3"></span>
            <svg class="boveda__cara" viewBox="0 0 400 400" focusable="false">
                <defs>
                    <linearGradient id="bv-puerta" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#eef1f4"/>
                        <stop offset=".4" stop-color="#b3bbc4"/>
                        <stop offset=".55" stop-color="#e6eaee"/>
                        <stop offset="1" stop-color="#7b848e"/>
                    </linearGradient>
                    <radialGradient id="bv-centro" cx=".42" cy=".38" r=".7">
                        <stop offset="0" stop-color="#f7f9fa"/>
                        <stop offset=".6" stop-color="#bcc3cb"/>
                        <stop offset="1" stop-color="#8a939d"/>
                    </radialGradient>
                </defs>

                <!-- Pestillos (salen del canto derecho hacia el marco). -->
                <g class="boveda__pestillos">
                    <rect x="300" y="164" width="62" height="14" rx="3" fill="url(#bv-acero-inv)" stroke="#59616a"/>
                    <rect x="300" y="222" width="62" height="14" rx="3" fill="url(#bv-acero-inv)" stroke="#59616a"/>
                </g>

                <circle cx="200" cy="200" r="132" fill="url(#bv-puerta)" stroke="#56606a" stroke-width="2.5"/>
                <circle cx="200" cy="200" r="116" fill="none" stroke="#f4f6f8" stroke-opacity=".7" stroke-width="2"/>
                <circle cx="200" cy="200" r="112" fill="url(#bv-centro)" stroke="#79828c" stroke-width="1.5"/>
                <?= $remaches(124, 24, 2.2, 'bv-tornillo') ?>

                <!-- Junta vertical y manija curva, como la referencia. -->
                <rect x="196" y="90" width="8" height="220" rx="3" fill="#8f98a2" stroke="#68717b" stroke-width="1"/>
                <path d="M226 112 A92 92 0 0 1 290 176" fill="none" stroke="#5f6872" stroke-width="12" stroke-linecap="round"/>
                <path d="M226 112 A92 92 0 0 1 290 176" fill="none" stroke="#e9edf0" stroke-width="5" stroke-linecap="round"/>

                <!-- Dial pequeño. -->
                <g transform="translate(270 262)">
                    <circle r="17" fill="url(#bv-acero)" stroke="#59616a" stroke-width="1.5"/>
                    <circle r="11" fill="none" stroke="#59616a" stroke-width="1.5"/>
                    <path d="M0-11V11M-11 0H11M-7.8-7.8 7.8 7.8M-7.8 7.8 7.8-7.8" stroke="#59616a" stroke-width="1.5"/>
                    <circle r="3" fill="#59616a"/>
                </g>

                <!-- Volante central: gira antes de abrir. -->
                <g class="boveda__volante">
                    <circle cx="200" cy="200" r="44" fill="none" stroke="#5f6872" stroke-width="7"/>
                    <circle cx="200" cy="200" r="44" fill="none" stroke="#eef1f4" stroke-width="2.5"/>
                    <?php for ($i = 0; $i < 8; $i++) :
                        $a = M_PI * $i / 4;
                        $x2 = 200 + 58 * cos($a);
                        $y2 = 200 + 58 * sin($a); ?>
                        <line x1="200" y1="200" x2="<?= sprintf('%.1f', $x2) ?>" y2="<?= sprintf('%.1f', $y2) ?>" stroke="#5f6872" stroke-width="5" stroke-linecap="round"/>
                        <circle cx="<?= sprintf('%.1f', $x2) ?>" cy="<?= sprintf('%.1f', $y2) ?>" r="5.5" fill="url(#bv-acero)" stroke="#5f6872" stroke-width="1.5"/>
                    <?php endfor; ?>
                    <circle cx="200" cy="200" r="14" fill="url(#bv-acero)" stroke="#5f6872" stroke-width="2"/>
                    <circle cx="200" cy="200" r="5" fill="#5f6872"/>
                </g>
            </svg>
        </div>
    </div>
    <p class="boveda__texto" aria-hidden="true"><?= $saludo !== '' ? 'Bienvenido, ' . escaparValores($saludo) : 'Bienvenido' ?></p>
</div>
