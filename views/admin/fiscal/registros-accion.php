<?php
/**
 * views/admin/fiscal/registros-accion.php — retenciones, ajustes y declaraciones (solo POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$anio    = (int) ($_POST['anio'] ?? date('Y'));
$destino = '/admin/fiscal/registros?anio=' . $anio;
$contrib = \App\Contabilidad::contribuyentePrincipal();
$usuario = (int) ($_SESSION['id'] ?? 0);
$n = static fn (string $campo): string => str_replace(',', '.', trim((string) ($_POST[$campo] ?? '0')));

switch ((string) ($_POST['accion'] ?? '')) {
    case 'retencion':
        $ok = \App\RegistrosFiscales::registrarRetencion($contrib, (string) ($_POST['fecha'] ?? ''), (string) ($_POST['impuesto'] ?? ''), $n('importe'),
            (string) ($_POST['retenedor'] ?? ''), null, (string) ($_POST['nota'] ?? ''), $usuario);
        $mensaje = 'Retención registrada.';
        break;
    case 'retencion_cancelar':
        $ok = \App\RegistrosFiscales::cancelarRetencion((int) ($_POST['id'] ?? 0), (string) ($_POST['motivo'] ?? ''), $usuario);
        $mensaje = 'Retención cancelada.';
        break;
    case 'ajuste':
        $ok = \App\RegistrosFiscales::registrarAjuste($contrib, $anio, (int) ($_POST['mes'] ?? 0), (string) ($_POST['concepto'] ?? ''), $n('importe'), (string) ($_POST['nota'] ?? ''), $usuario);
        $mensaje = 'Ajuste registrado.';
        break;
    case 'ajuste_cancelar':
        $ok = \App\RegistrosFiscales::cancelarAjuste((int) ($_POST['id'] ?? 0), (string) ($_POST['motivo'] ?? ''), $usuario);
        $mensaje = 'Ajuste cancelado.';
        break;
    case 'declaracion':
        $ok = \App\RegistrosFiscales::guardarDeclaracion($contrib, $anio, (int) ($_POST['mes'] ?? 0), (string) ($_POST['impuesto'] ?? ''),
            $n('declarado'), $n('saldo_favor'), $n('pagado'), (string) ($_POST['fecha_presentacion'] ?? ''), (string) ($_POST['fecha_pago'] ?? ''),
            (string) ($_POST['referencia'] ?? ''), '', $usuario);
        $mensaje = 'Importes reales guardados.';
        break;
    default:
        redirigir($destino, 'error', 'Acción no reconocida.');
}

if ($ok) {
    redirigir($destino, 'exito', $mensaje);
}

redirigir($destino, 'error', \App\RegistrosFiscales::getErrores()[0] ?? 'No fue posible completar la operación.');
