#!/usr/bin/env bash
# ===========================================================================
# restaurar.sh — restaura una copia hecha con respaldar.sh.
#
# Uso:   scripts/restaurar.sh <carpeta_del_respaldo> <base_destino> [carpeta_para_archivos]
#   • Verifica las huellas SHA-256 ANTES de tocar nada.
#   • Restaura la base en <base_destino>. Se NEGARÁ si esa base ya tiene tablas (no pisa datos);
#     para sustituir una base existente, vacíala a propósito antes (o restaura primero en una base nueva y compara).
#   • Los archivos se extraen en <carpeta_para_archivos> (por defecto ./restauracion-AAAAMMDD-HHMMSS),
#     nunca encima de los archivos vivos: tú decides qué copiar y cuándo.
# Variables: DB_HOST DB_USER DB_PASSWORD (o las de .env).
# Prueba de restauración automatizada: pruebas/probar_respaldo.sh
# ===========================================================================
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# El entorno (Docker, cron, pruebas) manda sobre .env: sólo se completa lo que no venga definido.
PREVIAS="$(env | grep -E '^(DB_HOST|DB_USER|DB_PASSWORD)=' || true)"
[[ -f "$RAIZ/.env" ]] && set -a && source <(grep -E '^(DB_HOST|DB_USER|DB_PASSWORD)=' "$RAIZ/.env") && set +a
while IFS= read -r linea; do [[ -n "$linea" ]] && export "$linea"; done <<< "$PREVIAS"

[[ $# -ge 2 ]] || { sed -n '2,13p' "${BASH_SOURCE[0]}" >&2; exit 2; }
RESPALDO="$1"; BASE="$2"
ARCHIVOS="${3:-$PWD/restauracion-$(date +%Y%m%d-%H%M%S)}"
DB_HOST="${DB_HOST:-localhost}"; DB_USER="${DB_USER:-root}"
export MYSQL_PWD="${DB_PASSWORD:-}"

[[ -f "$RESPALDO/SHA256SUMS" ]] || { echo "No hay SHA256SUMS en $RESPALDO: no es un respaldo válido." >&2; exit 1; }
echo "==> Verificando integridad del respaldo"
( cd "$RESPALDO" && sha256sum -c SHA256SUMS ) || { echo "La copia está dañada o fue alterada. No se restauró nada." >&2; exit 1; }

EXISTENTES="$(mysql -h "$DB_HOST" -u "$DB_USER" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$BASE'")"
if [[ "$EXISTENTES" != "0" ]]; then
    echo "La base '$BASE' ya tiene $EXISTENTES tablas: no se pisa. Usa una base nueva o vacíala a propósito." >&2
    exit 1
fi

echo "==> Restaurando la base en '$BASE'"
mysql -h "$DB_HOST" -u "$DB_USER" -e "CREATE DATABASE IF NOT EXISTS \`$BASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c "$RESPALDO/base.sql.gz" | mysql -h "$DB_HOST" -u "$DB_USER" "$BASE"

echo "==> Extrayendo archivos en $ARCHIVOS"
mkdir -p "$ARCHIVOS"
tar -C "$ARCHIVOS" -xzf "$RESPALDO/archivos.tar.gz"

TABLAS="$(mysql -h "$DB_HOST" -u "$DB_USER" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$BASE'")"
echo "==> Listo: $TABLAS tablas restauradas en '$BASE'; archivos en $ARCHIVOS"
echo "    Revisa los datos y, si todo es correcto, apunta la aplicación a esa base y copia los archivos a su lugar."
