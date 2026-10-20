# Etapa 10 — Regresión, documentación y preparación del despliegue

- **Hueco cerrado:** exportación del inventario a PDF/Excel/CSV con filtros, historial de movimientos (filtros + exportación) y fotografías mensuales
  del inventario con comparación contra hoy (migración 009; `scripts/foto_inventario.php` para automatizarla). Ningún reporte modifica el inventario.
- **Respaldos:** `scripts/respaldar.sh` / `scripts/restaurar.sh` (huellas SHA-256, retención, no pisa datos) y `pruebas/probar_respaldo.sh`.
- **Despliegue:** `initContainer migrar-base` en el Deployment (migraciones automáticas), `.dockerignore` ajustado para incluir `scripts/migrar.php`,
  plantillas de CronJob desactivadas (respaldo e inventario) y `helm lint` correcto. **Nada se desplegó.**
- **Regresión:** `pruebas/ejecutar_todo.sh` (28 suites) y `probar_permisos.mjs` (149 rutas). Matriz en `matriz-pruebas.md`.
- **Documentación:** `GUIA.md` (instalación, configuración, operación, respaldos, restauración, actualización, despliegue, seguridad y limitaciones).
- Corregido: el servidor de desarrollo de PHP servía `composer.json` (ahora bloquea las mismas rutas que el `.htaccess`).
