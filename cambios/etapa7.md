# Etapa 7 — Estadísticas y exportaciones (sin migración)

`/admin/estadisticas` (solo lectura):
- Ventas por día, mes, trimestre, semestre y año; por negocio, producto y cliente; con IVA o sin IVA.
- Comparación de mes / trimestre / semestre / año contra el mismo periodo de 1 a 4 años anteriores: importe,
  operaciones, piezas, variación absoluta y porcentual (sin % si no hay base).
- **Ventas realizadas, facturadas y cobros son medidas distintas** y se muestran por separado.
- Un periodo incompleto o futuro se marca; además se compara contra el MISMO avance del año anterior.
- Productos más vendidos, clientes con mayor volumen, matrices por varios años y tendencia (criterio: pendiente ÷ promedio ±2 % por periodo).
- Gráficas SVG sin librerías (con tabla y `<title>` accesibles). Exporta PDF, Excel y CSV con los filtros aplicados.
- Fuente única: `ventas` registradas (una cotización vendida cuenta una vez), `ingresos` facturadas y `cobros` vigentes.
- Con filtro de producto se miden sus renglones sin IVA; facturadas y cobros no se reparten por producto.

Verificación: `DB_NAME=cotizaciones_test php pruebas/php/etapa7.php` y `node pruebas/probar_etapa7.mjs`.
