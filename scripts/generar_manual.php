<?php
/** Regenera MANUAL_USUARIO.md a partir de includes/manual.php. Uso: php scripts/generar_manual.php */
$secciones = require __DIR__ . '/../includes/manual.php';
$md = "# Manual de usuario — Acero 360\n\n> También está dentro de la plataforma: menú **Ayuda**. Generado desde `includes/manual.php`.\n\n## Contenido\n\n";
foreach ($secciones as $i => $s) {
    $md .= ($i + 1) . '. ' . $s['titulo'] . "\n";
}
foreach ($secciones as $i => $s) {
    $md .= "\n---\n\n## " . ($i + 1) . '. ' . $s['titulo'] . "\n\n" . $s['intro'] . "\n";
    foreach ($s['pasos'] as [$titulo, $lista]) {
        $md .= "\n### $titulo\n\n";
        foreach ($lista as $n => $paso) {
            $md .= (count($lista) > 1 && !str_contains($titulo, 'Glosario') ? ($n + 1) . '. ' : '- ') . $paso . "\n";
        }
    }
    if ($s['consejos']) {
        $md .= "\n> **Consejos**\n";
        foreach ($s['consejos'] as $c) {
            $md .= "> - $c\n";
        }
    }
}
file_put_contents(__DIR__ . '/../MANUAL_USUARIO.md', $md . "\n");
echo "MANUAL_USUARIO.md generado\n";
