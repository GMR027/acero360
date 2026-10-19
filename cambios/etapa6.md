# Etapa 6 — Fiscal y cierre mensual (ESTADO: código y pruebas listos; falta documentación final y regresión completa en navegador)

Migración: `php scripts/migrar.php` aplica `007_fiscal.sql` (reversión `007_reversion.sql`).

- Catálogo fiscal versionado (`/admin/fiscal/catalogo`): importar (borrador) → validar (estructura + 40 casos independientes + comparación con la versión activa) → activar con contraseña y motivo. La versión anterior queda «reemplazada» intacta; los cálculos guardados conservan su versión.
- Tarifas ISR art. 106 LISR 2026 (12 meses) extraídas del Anexo 8 RMF 2026 (DOF 28-dic-2025) con `scripts/extraer_anexo8.py`; archivos en `mysql/catalogo_fiscal/`. Tasa IVA 16 % y criterios de la plataforma (no son texto de ley: revisar con el contador).
- Motor determinista por flujo de efectivo (IVA e ISR por separado): `MotorFiscal`. Sin regla activa no calcula ni inventa valores.
- Configuración fiscal con historial (`/admin/fiscal/configuracion`), retenciones, ajustes y declaraciones reales (`/admin/fiscal/registros`).
- Cierre mensual con verificaciones, reconocimiento de avisos, fotografía, exportación PDF/CSV y reapertura controlada (`/admin/contabilidad/cierre`).

Verificación: `DB_NAME=cotizaciones_test php pruebas/php/etapa6.php` y `node pruebas/probar_etapa6.mjs`.
Pendiente de confirmar con el contador: régimen y base de flujo de efectivo (aviso visible hasta confirmarlo).
