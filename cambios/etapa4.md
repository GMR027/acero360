# Etapa 4 — Pantalla de entrega

## Migración
`php scripts/migrar.php` aplica `mysql/migraciones/004_entregas.sql` (reversión: `004_reversion.sql`).
Tablas: `preparaciones`, `preparacion_ventas`, `entregas`, `entrega_renglones`.

## Qué hace
- **Preparación de pedidos** (`/admin/entregas`): lista las ventas confirmadas con piezas por entregar
  (directas y nacidas de cotización vendida; **nunca** cotizaciones sin vender). Al marcar casillas,
  el resumen agrupa los productos iguales y suma cantidades con foto, código y total.
- **Preparaciones** (`PREP-AAAA-NNNN`): estados pendiente → en preparación → preparado → en reparto →
  entregado (o cancelada). Se puede cambiar libremente entre los intermedios; «Entregado» solo si no queda
  nada pendiente (también se alcanza solo al completar las entregas); «Cancelada» solo sin entregas.
- **Venta repetida**: una venta ya incluida en otra preparación activa no se agrega en silencio; se muestra
  una advertencia con las preparaciones y hay que confirmar «Agregar de todos modos».
- **Entregas por cliente/destino** (`/admin/entregas/clientes`): lo pendiente agrupado por cliente, con
  ubicación y productos sumados; desde ahí o desde la preparación se registra la entrega.
- **Entrega total o parcial** (`/admin/entregas/entregar`): se capturan las piezas realmente entregadas
  (nunca más que lo pendiente). Token de un solo uso: reenviar el formulario no duplica.
- **PDF**: lista de preparación (productos agrupados con foto, casillas y ventas de origen) y PDF de
  entrega por cliente con línea de firma. Generarlos no modifica nada.
- **Inventario**: la pantalla de entrega nunca lo toca; la salida ocurrió al registrar la venta.

## Verificación
- `DB_NAME=cotizaciones_test php pruebas/php/etapa4.php` (incluye 4 entregas simultáneas de la misma pieza).
- `DB_NAME=cotizaciones_test node pruebas/probar_etapa4.mjs` (servidor PHP apuntando a esa BD).
- Manual: Ventas → Pantalla de entrega → marcar 2 ventas con el mismo producto → ver la suma → Crear
  preparación → cambiar estados → Registrar entrega parcial → PDF.

## Devolución = cierre de la entrega (migración 005)
Una devolución de cliente (Etapa 3) **cierra la entrega** de la venta (`ventas.entrega_cerrada_en`): lo que
faltaba por entregar deja de estar pendiente, la venta sale de «por entregar», no se puede preparar ni
entregar de nuevo y sus preparaciones activas se recalculan. La venta sigue registrada (es ingreso).

## Decisiones
- No habrá rutas de reparto ni asignación de repartidor (decisión del usuario).
