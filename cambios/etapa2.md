# Etapa 2 — Negocios/catálogo, inventario y compras

## Migración
`php scripts/migrar.php` aplica `mysql/migraciones/002_negocios_catalogo_compras.sql` una sola vez
(controlado por la tabla `schema_migraciones`). Reversión: `mysql/migraciones/002_reversion.sql`
o restaurar el respaldo `cotizaciones-respaldos/db-pre-etapa2-*.sql`.

## Qué cambió
- **Contribuyente** (`contribuyentes`) separado de los negocios; ambos negocios apuntan al mismo RFC.
- **Productos ↔ negocios N:M** (`producto_empresa`). La existencia sigue en `productos.stock` (una sola).
  `productos.empresa_id` se conserva como negocio principal. Nuevos: `costo_actual`, `stock_minimo`, SKU único.
- **Stock negativo**: permitido por defecto (`configuracion.permitir_stock_negativo = 1`); con `0` las ventas
  sin existencias se rechazan. `Inventario::mover` ahora bloquea la fila (`FOR UPDATE`).
- **Auditoría** (`auditoria`) y **configuración** (`configuracion`).
- **Proveedores**: activo/desactivar, contacto, correo, observaciones.
- **Órdenes de compra** (`/admin/compras`): folio `OC-AAAA-NNNN`, partidas con costo esperado, recepciones
  parciales con historial, token de un solo uso (sin duplicar entradas), cierre con faltantes, reapertura con
  motivo auditado, PDF de la orden y reporte solicitado-vs-recibido.
- Cada recepción suma al **producto comercial** elegido en la partida (p. ej. «Registro número 3»).

## Verificación
- Automática: `php scripts/migrar.php cotizaciones_test && DB_NAME=cotizaciones_test php pruebas/php/etapa2.php`
- Manual: Compras → Nueva orden → Detalle → Registrar recepción (parcial) → Cerrar con faltantes → PDF de cierre.
  Inventario muestra el stock sumado solo una vez aunque se reenvíe el formulario.

## Pendiente (siguientes etapas)
Exportar inventario a Excel/CSV, foto mensual del inventario y alertas visibles de `stock_minimo` (Etapa 7);
devoluciones/correcciones como tipos de movimiento propios (Etapa 3).
