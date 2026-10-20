# Cotizaciones — guía de instalación, operación y despliegue

Plataforma única para **cotizaciones, ventas, inventario, compras, entregas, contabilidad, archivo fiscal, estimación de impuestos y
estadísticas** de dos negocios que comparten un solo contribuyente. Funciona en el navegador (también en celular) y como aplicación de
escritorio Linux (`.deb`). Una sola base de datos: web y escritorio ven siempre los mismos datos.

> Documentos relacionados: [`DESPLIEGUE.md`](DESPLIEGUE.md) (runbook del clúster), [`desktop/README.md`](desktop/README.md)
> (aplicación de escritorio), [`cambios/`](cambios/) (una nota por etapa) y [`cambios/matriz-pruebas.md`](cambios/matriz-pruebas.md).

## 1. Módulos

| Módulo | Ruta | Qué hace |
|---|---|---|
| Cotizaciones | `/admin/cotizaciones` | Folio, precio especial por cliente (no altera el catálogo), descuentos por renglón y global, IVA, PDF y correo |
| Ventas | `/admin/ventas` | Una sola tabla de ventas: directas y cotizaciones vendidas (una cotización = una venta). Devoluciones |
| Inventario | `/admin/inventario` | Existencias únicas compartidas, alertas de mínimo, historial de movimientos, fotografías mensuales, exportación PDF/Excel/CSV |
| Órdenes de compra | `/admin/compras` | Orden → recepciones parciales (suman solo lo recibido) → cierre con faltantes → reporte antes del pago |
| Pantalla de entrega | `/admin/entregas` | Preparación de pedidos (productos agrupados) y entregas por cliente; no toca el inventario |
| Contabilidad | `/admin/contabilidad` | Ingresos automáticos, cobros, gastos (compartidos una sola vez), documentos PDF/imagen/XML, ZIP mensual, ventas sin facturar, cierre mensual |
| Fiscal | `/admin/fiscal` | Catálogo de reglas versionado (ISR art. 106, IVA), motor por flujo de efectivo, retenciones, ajustes, pagos reales |
| Estadísticas | `/admin/estadisticas` | Comparaciones entre años, tendencias, rankings, gráficas, exportación PDF/Excel/CSV |
| App de escritorio | `/admin/escritorio` | Descarga controlada del paquete `.deb` |

## 2. Instalación local (desarrollo o servidor propio)

Requisitos: PHP ≥ 8.3 (extensiones mysqli, gd, zip, mbstring, fileinfo, dom), MySQL 8, Composer, Node ≥ 20 (solo para compilar assets y pruebas).

```bash
composer install
npm install && npm run build                  # compila SASS y JS a build/
mysql -uroot -e "CREATE DATABASE cotizaciones CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -uroot cotizaciones < mysql/dump-inicial.sql      # esquema inicial (solo instalación nueva)
php scripts/migrar.php                         # aplica TODAS las migraciones pendientes (002 … 009)
cp .env.example .env                           # y ajusta las variables de la sección 3
php -S 127.0.0.1:8017 index.php                # servidor de desarrollo
```
Las migraciones se registran en `schema_migraciones` y cada una corre **una sola vez**. Una base vacía no se migra (el script se niega).

## 3. Configuración

| Variable | Para qué | Por defecto |
|---|---|---|
| `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | Conexión a MySQL | localhost / root / — / cotizaciones |
| `REGISTRO_ABIERTO=1` | Permite registrar administradores nuevos (cerrado por defecto) | cerrado |
| `DOCUMENTOS_PATH` | Dónde se guardan los documentos fiscales (**fuera de `public/`**) | `storage/documentos` |
| `DOCUMENTOS_MAX_MB` | Tamaño máximo por documento | 10 |
| `CORREO_*` | Envío de cotizaciones por correo (ver `.env.example`; `CORREO_MODO=log` no envía) | log |
| `MIGRAR_REINTENTOS` | Intentos (cada 5 s) de `migrar.php` esperando a la base | 1 |

**Primer arranque fiscal (una vez):** en *Fiscal → Catálogo de reglas* importa los tres archivos de `mysql/catalogo_fiscal/`
(tarifa ISR 2026 del Anexo 8 RMF, tasa de IVA y criterios), **valida** cada uno y **actívalo** con tu contraseña. Después confirma
régimen y flujo de efectivo con tu contador en *Fiscal → Configuración fiscal*. Sin reglas activas el motor no calcula (no inventa).

## 4. Operación diaria

1. **Vender:** cotización → *Vendido* (o venta directa). El inventario baja una vez y se crea el ingreso contable solo.
2. **Cobrar:** *Contabilidad → Ingresos → abrir* → registra cada cobro; marca la **facturación** (facturada / no facturada) — es un dato explícito.
3. **Comprar:** *Órdenes de compra* → registrar recepción (parcial o total) → cerrar → PDF «solicitado vs recibido».
4. **Entregar:** *Pantalla de entrega* → marcar ventas → crear preparación → registrar entregas (parciales permitidas).
5. **Gastos:** *Contabilidad → Egresos*; adjunta CFDI (XML) y comprobantes; valida cada gasto para que cuente en la estimación fiscal.
6. **Cada mes:** *Cierre del mes* (verificaciones, reconocer avisos, cerrar). Un mes cerrado bloquea cambios; se reabre solo con contraseña y motivo.
7. **Fotografía del inventario:** *Inventario → Fotografías mensuales* (o automática con `scripts/foto_inventario.php`).

## 5. Respaldos y restauración

```bash
scripts/respaldar.sh /ruta/respaldos           # base + fotos + documentos fiscales + SHA256SUMS + manifiesto (retiene 14)
scripts/restaurar.sh /ruta/respaldos/AAAAMMDD-HHMMSS cotizaciones_restaurada   # verifica huellas; NO pisa una base con datos
pruebas/probar_respaldo.sh                     # ensayo completo respaldo → restauración → comparación
```
- Programa el respaldo con cron (diario 02:30) y **guarda una copia fuera del equipo**. El respaldo incluye `storage/documentos` (información fiscal).
- La restauración deja los archivos en una carpeta aparte: tú decides qué copiar y cuándo. Probada: mismas tablas, mismos registros y archivos idénticos.
- En el clúster: `helm` trae plantillas **desactivadas** (`respaldo.enabled`, `fotoInventario.enabled`). El respaldo de archivos se hace con `respaldar.sh` desde un equipo con acceso.

## 6. Actualización y despliegue

`./deploy.sh` construye la imagen, la sube y hace `helm upgrade` (ver `DESPLIEGUE.md`). **Desde la Etapa 10 cada despliegue aplica las
migraciones solo**: el Deployment tiene un `initContainer` (`migrar-base`) que ejecuta `php scripts/migrar.php`, espera a MySQL y no toca una base vacía.

Antes de desplegar: `pruebas/ejecutar_todo.sh` (regresión completa) y un respaldo (`scripts/respaldar.sh`).
**Marcha atrás:** restaurar el respaldo en una base nueva y apuntar la aplicación a ella; cada migración trae su `NNN_reversion.sql` (úsala solo si sabes que no hay datos nuevos que perder).

Ensayo realizado: una base con el esquema original (16 productos) migró a la versión actual con los 8 scripts en orden, conservando sus datos; la segunda ejecución no cambió nada.
**Nada de esto se ha desplegado en el clúster:** requiere tu orden explícita. Pendiente antes de producción: volumen persistente para `storage/documentos` (ya está en el chart: `pvc-documentos.yaml`) y subir `proxyBodySize` solo si quisieras subir paquetes `.deb` por web (hoy se publican por script).

## 7. Seguridad (resumen)

- Solo rol administrador; **todas** las rutas se protegen en el backend (prueba automática de 149 rutas, con y sin sesión, y con un usuario sin permisos).
- Sentencias preparadas; contraseñas con bcrypt; formularios críticos con token de un solo uso (no se duplican cobros, gastos, entregas ni recepciones).
- Documentos fuera de `public/`, nombre aleatorio, validación de contenido (PDF/imagen/XML sin XXE), descarga solo autenticada y siempre como adjunto.
- `.htaccess` bloquea `storage/`, código, `.env`, `*.sql`, `*.md`, `composer.*`… (verificado con Apache real y con la imagen Docker).
- Operaciones sensibles (activar reglas fiscales, reabrir un mes, cambiar la configuración fiscal) exigen **contraseña y motivo** y quedan en `auditoria`.
- La aplicación de escritorio: contextIsolation, sandbox, sin Node en la página, solo HTTPS, permisos del navegador denegados.

## 8. Pruebas

`pruebas/ejecutar_todo.sh` ejecuta 28 suites (lógica PHP, flujos en navegador, auditorías de escritorio y celular, permisos, restauración de respaldos y
aplicación de escritorio) contra una base de **prueba**, nunca la real. Detalle por escenario en [`cambios/matriz-pruebas.md`](cambios/matriz-pruebas.md).

## 9. Limitaciones conocidas (honestas)

- **Los cálculos fiscales son una estimación administrativa** y deben validarse con el contador; régimen y flujo de efectivo están **por confirmar** (aviso visible).
  Los criterios de deducción/acreditamiento de gastos (pagado + validado + con comprobante; activos fijos fuera) son de la plataforma, no texto de ley.
- El XML se lee documentalmente (UUID, RFC, total); **no se verifica el timbre ante el SAT**.
- No hay nota de crédito para el ajuste económico de una devolución; no hay depreciación de activos; no se calculan retenciones que tú hagas a terceros.
- Un solo rol (administrador); no hay roles granulares (decisión del usuario).
- Electron: solo Linux amd64, paquete sin firmar y sin actualización automática. No se probó en un teléfono físico ni con lector de pantalla real.
- Los respaldos de producción no están programados todavía (hay scripts y plantillas, sin activar).
