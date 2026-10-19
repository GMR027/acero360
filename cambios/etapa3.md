# Etapa 3 — Ventas unificadas e inventario único

## Migración
`php scripts/migrar.php` aplica `mysql/migraciones/003_ventas_unificadas.sql`
(reversión: `003_reversion.sql` o restaurar `cotizaciones-respaldos/db-pre-etapa3-*.sql`).
Respalda los datos: cada cotización ya vendida recibe su venta (`VEN-AAAA-NNNN`), y sus renglones y
movimientos de inventario quedan ligados a esa venta.

## Modelo
- `ventas` es la **única fuente de verdad** de lo vendido. `ventas.cotizacion_id` (UNIQUE) la liga con la
  cotización de origen; NULL = venta directa. Máximo 1 venta por cotización.
- Marcar una cotización «vendida» crea (o reactiva) su venta y descuenta el stock **una sola vez**
  (idempotente, con la cotización bloqueada `FOR UPDATE`). Editar una cotización vendida sincroniza la venta
  y el stock; quitarla de «vendido» o eliminarla **cancela** la venta (no se borra) y regresa las piezas.
- Ganancias, historial de ventas y (próximas etapas) contabilidad, entregas y estadísticas leen `ventas`.
- Una venta nacida de cotización no se edita ni elimina desde Ventas: se hace desde la cotización.

## Inventario
- Todo movimiento lleva `motivo`: `venta`, `recepcion`, `ajuste`, `devolucion`, `correccion`, `cancelacion`.
- **Devolución** (`/admin/ventas/devolucion?id=`): regresa piezas de una venta registrada; nunca más de lo
  vendido menos devoluciones previas; exige motivo. No cambia el importe de la venta (el ajuste económico
  corresponde al módulo de contabilidad).
- **Corrección** (`Inventario::corregir`): fija existencias exigiendo motivo, con bitácora de auditoría.
  Por ahora solo existe en backend; la pantalla de Inventario sigue usando el ajuste manual (`motivo=ajuste`).

## Verificación
- `DB_NAME=cotizaciones_test php pruebas/php/etapa3.php` (incluye 4 peticiones simultáneas sobre la misma cotización).
- `DB_NAME=cotizaciones_test node pruebas/probar_etapa3.mjs` (con el servidor de PHP apuntando a esa BD).
- Manual: Cotización → Estado «Vendido» → «Venta VEN-…» → Devolución; Ventas lista la operación una sola vez.
