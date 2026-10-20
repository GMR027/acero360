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
| 7 | Recibir mercancía de un proveedor | `php/etapa2.php`, `probar_etapa2.mjs` | ✔ automática |
| 8 | Recepción parcial y cierre con faltantes | `php/etapa2.php`, `probar_etapa2.mjs` | ✔ automática |
| 9 | Actualizar costos sin modificar el historial | `php/etapa2.php` | ✔ automática |
| 10 | Varias ventas agrupadas en la pantalla de entrega (5+2=7) | `php/etapa4.php`, `probar_etapa4.mjs` | ✔ automática |
| 11 | Entregar no vuelve a descontar inventario | `php/etapa4.php`, `probar_etapa4.mjs` | ✔ automática |
| 12 | Gasto compartido sin duplicarlo | `php/etapa5.php` (60/40, total contado una vez), `probar_etapa5.mjs` | ✔ automática |
| 13 | Adjuntar y recuperar PDF, imágenes y XML | `php/etapa5.php` (incluye 11 ataques), `probar_etapa5.mjs` | ✔ automática |
| 14 | Consultar la contabilidad de meses anteriores | `probar_etapa5.mjs` (selector año/mes), `php/etapa6.php` (enero y febrero), `probar_etapa7.mjs` | ✔ automática |
| 15 | Reporte fiscal consolidado | `php/etapa6.php` (montos calculados a mano), `probar_etapa6.mjs` | ✔ automática |
| 16 | Comparar ventas entre años | `php/etapa7.php`, `probar_etapa7.mjs` | ✔ automática |
| 17 | Exportar inventario, estadísticas y ventas sin facturar (PDF/Excel/CSV) | `probar_etapa10.mjs`, `probar_etapa7.mjs`, `probar_etapa5.mjs` | ✔ automática |
| 18 | Permisos y operaciones administrativas | `probar_permisos.mjs` (149 rutas × sin sesión / rol 2); `php/etapa6.php` (contraseña y motivo en activación, reapertura, configuración fiscal) | ✔ automática |
| 19 | Interfaz utilizable en celular | `auditar_movil.mjs` (28 pantallas a 390 px; 768 y 1024), `probar_etapa*.mjs` (sin desborde); revisión de capturas | ✔ automática + revisión visual · ✘ no probado en teléfono físico |
| 20 | Instalar y ejecutar el paquete Debian | `desktop/test/probar-deb.sh` en contenedores **Debian 12** y **Ubuntu 24.04** limpios (instala, arranca, desinstala); `desktop/test/integracion.mjs` (Electron real) | ✔ (con Xvfb y `--no-sandbox` en el contenedor) |
| 21 | Escritorio y web consultan los mismos datos | `desktop/test/integracion.mjs` (mismas filas y un cambio en el servidor se ve al instante) | ✔ automática |
| 22 | Restaurar una copia de seguridad de prueba | `pruebas/probar_respaldo.sh` (tablas, registros y archivos idénticos; se niega a pisar datos; detecta copias alteradas) | ✔ automática |

## Ensayos adicionales
- **Actualización de producción:** base del esquema original (pre-Etapa 2) → 8 migraciones con la imagen Docker del proyecto → datos intactos, segunda corrida idempotente, aplicación sirviendo con el acceso a archivos internos bloqueado (403).
- **Apache real:** `.htaccess` verificado (403 en `storage/`, código, `.env`, `*.sql`, `composer.*`; 200 en `build/` y `public/`).
- **Concurrencia:** 4 procesos simultáneos sobre la misma cotización (1 venta, 1 descuento) y sobre la misma entrega (se entrega una vez).

## Lo que NO está probado
Teléfono físico, lector de pantalla real, HiDPI, instalación del `.deb` en un escritorio real fuera de contenedores, el clúster de producción
(nada se desplegó), el envío real de correo (modo `log`) y la verificación de timbres ante el SAT.
