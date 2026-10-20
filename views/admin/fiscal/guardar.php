<?php
/**
 * views/admin/fiscal/guardar.php — guarda una fotografía del cálculo (solo POST). No cambia ninguna cifra.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$anio = (int) ($_POST['anio'] ?? 0);
$mes  = (int) ($_POST['mes'] ?? 0);
$destino = "/admin/fiscal/detalle?anio=$anio&mes=$mes";

if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($destino, 'error', 'Este formulario ya se envió; no se guardó el cálculo dos veces.');
}

$calculo = \App\MotorFiscal::calcular(\App\Contabilidad::contribuyentePrincipal(), $anio, $mes);
if (!$calculo['habilitado']) {
    redirigir($destino, 'error', 'El cálculo no está disponible para guardar.');
}

$id = \App\MotorFiscal::guardarCalculo($calculo, 'guardado', (int) ($_SESSION['id'] ?? 0));
redirigir($destino, 'exito', "Cálculo guardado (#$id) con las versiones de reglas que se usaron.");
