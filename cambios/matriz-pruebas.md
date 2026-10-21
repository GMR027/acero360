# Matriz de pruebas — los 22 escenarios obligatorios

Ejecución completa: `pruebas/ejecutar_todo.sh` (28 suites, base de prueba, nunca la real). Última corrida: **28 correctas · 0 fallos**.

| # | Escenario | Dónde se prueba | Estado |
|---|---|---|---|
| 1 | Crear una cotización y exportarla a PDF | `php/etapa10.php` (cálculo, alta, PDF `%PDF-`), `probar_descuento_pdf.php`, `probar_ronda*.mjs` | ✔ automática |
| 2 | Modificar un precio especial sin alterar el precio base | `php/etapa10.php` (el base no cambia; el especial se recuerda por cliente; «no recordar» no) | ✔ automática |
| 3 | Descuentos e IVA correctos | `php/etapa10.php` (460.00 → 467.25 con IVA; 402.80 sin IVA), `probar_descuento_pdf.php` | ✔ automática |
| 4 | Cotización → venta sin duplicar | `php/etapa3.php` (marcar 2 veces, 4 peticiones simultáneas → 1 venta), `probar_etapa3.mjs` | ✔ automática |
| 5 | Venta directa | `php/etapa3.php`, `probar_etapa3.mjs` | ✔ automática |
| 6 | Inventario se descuenta una sola vez | `php/etapa3.php`, `php/etapa4.php` (entregar no descuenta), `php/etapa10.php` | ✔ automática |
| 7 | Recibir mercancía de un proveedor (el semiterminado queda «por terminar», no vendible) | `php/etapa2.php`, `php/etapa18.php`, `probar_etapa2.mjs`, `probar_compras.mjs` | ✔ automática |
| 8 | Recepción parcial y cierre con faltantes | `php/etapa2.php`, `php/etapa18.php`, `probar_etapa2.mjs` | ✔ automática |
| 9 | Actualizar costos sin modificar el historial | `php/etapa2.php`, `php/etapa18.php` | ✔ automática |
| 10 | Varias ventas agrupadas en la pantalla de entrega (5+2=7) | `php/etapa4.php`, `probar_etapa4.mjs` | ✔ automática |
| 11 | Entregar no vuelve a descontar inventario | `php/etapa4.php`, `probar_etapa4.mjs` | ✔ automática |
| 12 | Gasto compartido sin duplicarlo | `php/etapa5.php` (60/40, total contado una vez), `probar_etapa5.mjs` | ✔ automática |
| 13 | Adjuntar y recuperar PDF, imágenes y XML | `php/etapa5.php` (incluye 11 ataques), `probar_etapa5.mjs` | ✔ automática |
| 14 | Consultar la contabilidad de meses anteriores | `probar_etapa5.mjs` (selector año/mes), `php/etapa6.php` (enero y febrero), `probar_etapa7.mjs` | ✔ automática |
| 15 | Reporte fiscal consolidado | `php/etapa6.php` (montos calculados a mano) | ✔ automática |
| 16 | Comparar ventas entre años | `php/etapa7.php`, `probar_etapa7.mjs` | ✔ automática |
| 17 | Exportar inventario, estadísticas y ventas sin facturar (PDF/Excel/CSV) | `probar_etapa10.mjs`, `probar_etapa7.mjs`, `probar_etapa5.mjs` | ✔ automática |
| 18 | Permisos y operaciones administrativas | `probar_permisos.mjs` (149 rutas × sin sesión / rol 2); `php/etapa6.php` (contraseña y motivo en activación, reapertura, configuración fiscal) | ✔ automática |
| 19 | Interfaz utilizable en celular | `auditar_movil.mjs` (28 pantallas a 390 px; 768 y 1024), `probar_etapa*.mjs` (sin desborde); revisión de capturas | ✔ automática + revisión visual · ✘ no probado en teléfono físico |
| 20 | Instalar y ejecutar el paquete Debian | `desktop/test/probar-deb.sh` en contenedores **Debian 12** y **Ubuntu 24.04** limpios (instala, arranca, desinstala); `desktop/test/integracion.mjs` (Electron real) | ✔ (con Xvfb y `--no-sandbox` en el contenedor) |
| 21 | Escritorio y web consultan los mismos datos | `desktop/test/integracion.mjs` (mismas filas y un cambio en el servidor se ve al instante) | ✔ automática |
| 22 | Restaurar una copia de seguridad de prueba | `pruebas/probar_respaldo.sh` (tablas, registros y archivos idénticos; se niega a pisar datos; detecta copias alteradas) | ✔ automática |

## Compras y proveedores unificados (reestructuración, 2026-10-20)

Los 15 escenarios obligatorios del prompt de reestructuración. `php/etapa18.php` prueba la lógica (y se verificó con mutaciones: al romper a propósito el tope de pago, el enlace único con el gasto, el destino «por terminar» o la entidad fiscal, las pruebas fallan); `probar_compras.mjs` recorre la interfaz real.

| # | Escenario | Dónde se prueba |
|---|---|---|
| 1 | Compra a Agustín asociada a un producto del catálogo (el nombre del proveedor y el del catálogo no se mezclan) | `etapa18` · `probar_compras` |
| 2 | Recepción parcial: solo se cuenta lo recibido; no sube lo vendible | `etapa18` · `probar_etapa2` · `probar_compras` |
| 3 | Pintura/discos sin crear producto comercial ni movimientos | `etapa18` · `probar_compras` |
| 4 | Materia prima controlada en inventario (material aparte, nunca producto de venta) | `etapa18` · `probar_compras` |
| 5 | Compra con factura (XML con timbre) enlazada a contabilidad | `etapa18` · `probar_compras` |
| 6 | Compra sin factura y CFDI después, sin duplicar el gasto; baja del XML la devuelve a «pendiente» | `etapa18` · `probar_compras` |
| 7 | Pagos parciales y saldo correcto; no se paga más que el saldo; cancelar un pago reabre el saldo | `etapa18` · `probar_compras` |
| 8 | Un pago no genera gasto duplicado | `etapa18` · `probar_compras` |
| 9 | La misma factura (UUID) no se incorpora dos veces | `etapa18` · `probar_compras` |
| 10 | Reenviar una recepción o un pago (mismo token) no duplica | `etapa18` |
| 11 | Costos históricos no cambian al actualizar el costo vigente | `etapa18` |
| 12 | Historial de compras por proveedor, filtros y totales | `etapa18` · `probar_compras` |
| 13 | La limpieza de entradas de ejemplo no toca otros módulos (simula, exige respaldo verificado, se detiene ante dependencias) | `probar_limpieza_entradas.sh` |
| 14 | Ventas, cotizaciones, inventario, gastos, contabilidad e impuestos siguen funcionando | regresión completa (`ejecutar_todo.sh`) |
| 15 | Permisos y separación por entidad fiscal / periodos cerrados independientes | `probar_permisos.mjs` (recorre todas las rutas) · `etapa18` |

## Catálogo de semiterminados por proveedor (fases 2 y 3 del segundo prompt)

`php/etapa19.php` (lógica: alta, duplicados por proveedor, mismo artículo con otro costo en otro proveedor, desactivar sin borrar, auditoría de costos, relación N:M con productos propios, compras históricas intactas) y `probar_catalogo.mjs` (interfaz de punta a punta, confirmaciones, filtros, consulta desde el producto propio, móvil). Migración 020 ensayada sobre un respaldo real (aplicar → revertir → aplicar).

## Solicitud de compra desde el catálogo (sección 4 del segundo prompt)

`php/etapa20.php` (lógica: solo el catálogo activo del proveedor; nombre, código y costo recuperados; importe y total; IVA; copia histórica del costo al cambiar el catálogo; costo manual respetado y auditado; producto de otro proveedor o inactivo rechazado; recepción con el costo histórico; verificado con mutaciones) y `probar_compra_catalogo.mjs` (interfaz: selector por proveedor, rellenado y bloqueo, importes y total en vivo, «Cambiar costo», historial intacto, producto desactivado, cambio de proveedor, móvil). Migración 021 ensayada sobre un respaldo real (aplicar → revertir → aplicar).

## Ensayos adicionales
- **Migración 019 sobre un respaldo REAL de producción** (BD en la migración 10 → 011…019): aplica limpia, los datos quedan intactos y la reversión funciona (aplicar → revertir → aplicar).
- **Actualización de producción:** base del esquema original (pre-Etapa 2) → 8 migraciones con la imagen Docker del proyecto → datos intactos, segunda corrida idempotente, aplicación sirviendo con el acceso a archivos internos bloqueado (403).
- **Apache real:** `.htaccess` verificado (403 en `storage/`, código, `.env`, `*.sql`, `composer.*`; 200 en `build/` y `public/`).
- **Concurrencia:** 4 procesos simultáneos sobre la misma cotización (1 venta, 1 descuento) y sobre la misma entrega (se entrega una vez).

## Lo que NO está probado
Teléfono físico, lector de pantalla real, HiDPI, instalación del `.deb` en un escritorio real fuera de contenedores, el clúster de producción
(nada se desplegó), el envío real de correo (modo `log`) y la verificación de timbres ante el SAT.
