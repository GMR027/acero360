# Etapa 8 — Responsivo y accesibilidad (sin migración)

- `src/scss/layout/_movil.scss` (solo < 768 px): objetivos táctiles de 44 × 44 px, campos a 16 px (sin zoom al enfocar) y **tablas convertidas en tarjetas**
  (cada fila es una tarjeta; cada dato muestra su encabezado). El encabezado original sigue disponible para lectores de pantalla.
- `src/js/modulos/tablasResponsivas.js` etiqueta las celdas con el encabezado de su columna; `cotizador.js` hace lo mismo con los renglones
  que arma dinámicamente, así que el cotizador y las ventas directas también se usan sin desplazarse de lado.
- Ya existían: enlace «Saltar al contenido», foco visible, cajón lateral y modales que se cierran con Escape, `prefers-reduced-motion`,
  etiquetas en formularios, tablas con `<caption>` y `scope`.
- Verificación nueva: `DB_NAME=cotizaciones_test node pruebas/auditar_movil.mjs` (28 pantallas a 390 px; desbordes a 768 y 1024 px).
  Línea base antes de la etapa: 28 pantallas con objetivos pequeños, 24 tablas con scroll horizontal y 27 pantallas con campos de texto chico. Ahora: 0.
- Pendiente: probar en un teléfono físico y con un lector de pantalla real (aquí solo hay pruebas automáticas y revisión de capturas).
