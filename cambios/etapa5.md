# Etapa 5 — Contabilidad, documentos y archivo mensual

## Migración
`php scripts/migrar.php` aplica `mysql/migraciones/006_contabilidad.sql` (reversión: `006_reversion.sql`).
Tablas: `periodos_contables`, `ingresos`, `cobros`, `categorias_gasto`, `gastos`, `gasto_reparto`, `documentos`.
Respalda los datos: cada venta ya registrada recibe su ingreso.

## Qué hace (`/admin/contabilidad`)
- **Resumen mensual consolidado** de ambos negocios (un solo contribuyente) con selector de año, mes y negocio:
  ventas (subtotal, IVA registrado), facturadas / no facturadas / sin clasificar, cobrado y por cobrar,
  gastos (subtotal, IVA, pagado/pendiente) y detalle por negocio. Son importes **registrados**, no estimaciones fiscales.
- **Ingresos**: se generan y actualizan solos con cada venta (directa o de cotización) dentro de su misma
  transacción. Una venta = un ingreso. Cancelar la venta cancela el ingreso (no se borra). Cada cambio queda en auditoría.
- **Facturación explícita**: `sin_clasificar` (por defecto), `facturada` (folio, UUID, fecha) o `no_facturada`.
  Adjuntar o no un PDF **no** cambia el estado.
- **Cobros** parciales o totales (nunca más que el saldo), con cancelación motivada; estados sin cobrar / parcial / cobrada.
- **Egresos**: CRUD con IVA calculado en centavos, categoría, naturaleza, proveedor, estado de pago, indicador de
  comprobante y **revisión fiscal «por validar»** (un adjunto no vuelve deducible un gasto). Un gasto compartido se
  registra **una sola vez**; el reparto (debe sumar 100 %) solo alimenta el detalle por negocio. No se borran: se cancelan con motivo.
- **Documentos** (PDF, JPG, PNG, WebP, XML) ligados a ingresos y gastos: lista, subir, sustituir (el anterior se
  conserva), dar de baja con motivo, descargar. Un XML se lee como CFDI (UUID, RFC, total) y se compara con el
  contribuyente y la operación; **no** es validación ante el SAT.
- **Archivo mensual**: dos ZIP independientes (ventas / gastos) con carpetas `Ventas facturadas`,
  `Ventas no facturadas`, `Gastos y compras`, `XML` y `Pendientes de clasificacion`, nombres únicos e `indice.csv`.
- **Ventas sin facturar** por periodo y negocio, con subtotal, IVA registrado y total; exporta **PDF, Excel y CSV**.
- **Periodos cerrados**: historial. El candado ya funciona (ver abajo); el cierre y la reapertura controlada
  se construyen en la Etapa 6.

## Periodo cerrado
Si el mes de una operación está cerrado, **se rechazan** (con mensaje) los cambios en la venta, su ingreso, la
facturación, los cobros, los gastos y los documentos. No hay modificaciones silenciosas.

## Seguridad de archivos
- Almacén fuera de `public/`: `storage/documentos/<contribuyente>/<año>/<mes>/<ventas|gastos>/<nombre aleatorio>`
  (variable `DOCUMENTOS_PATH` para cambiarlo; `DOCUMENTOS_MAX_MB`, 10 por defecto).
- Validación del contenido real (no solo extensión): PDF sin JavaScript ni archivos incrustados; imágenes reales;
  XML sin DOCTYPE ni entidades (anti-XXE). Sin duplicados exactos (SHA-256) ni el mismo UUID en dos operaciones.
- Descarga solo para administradores autenticados, siempre como adjunto, con `nosniff` y CSP restrictiva;
  la ruta se resuelve con `realpath` dentro del almacén.
- `.htaccess` y `storage/.htaccess` bloquean el acceso web directo a `storage/`, código, configuración, migraciones,
  `.env`, `*.md`, `*.sql`, `composer.*`, `Dockerfile`, etc. (verificado con Apache real: 403; `public/` y `build/` siguen públicos).
- Helm: nuevo `pvc-documentos.yaml` (5 Gi) montado en `storage/documentos`. **No se ha desplegado**; ese volumen
  debe entrar en los respaldos junto con la base de datos.

## Verificación
- `DB_NAME=cotizaciones_test php pruebas/php/etapa5.php` (ingresos, periodos cerrados, facturación, cobros,
  gastos, validación y ataques de archivos, CFDI, ZIP, CSV/XLSX).
- `DB_NAME=cotizaciones_test node pruebas/probar_etapa5.mjs` (con `php -S` apuntando a esa BD).
- Manual: Contabilidad → Ingresos → abrir uno → marcar facturada, registrar cobro, adjuntar PDF y XML →
  Comprobantes y documentos → descargar ZIP; Egresos → Nuevo gasto compartido 60/40.

## Pendiente (siguientes etapas)
- Motor fiscal (IVA a cargo, ISR provisional), retenciones, cierre y reapertura de periodos, conciliación.
- Nota de crédito / ajuste económico de una devolución.
- No se verifica el timbre contra el SAT (solo lectura documental del XML).
