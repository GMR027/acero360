#!/usr/bin/env bash
# ===========================================================================
# respaldar.sh — copia de seguridad de la base de datos y de los archivos del sistema.
#
# Uso:   scripts/respaldar.sh [carpeta_destino]        (por defecto ./respaldos)
# Variables (o las de .env): DB_HOST DB_USER DB_PASSWORD DB_NAME  ·  RETENER (copias a conservar, 14)
#                            ARCHIVOS_DIRS (carpetas a respaldar; por defecto public/image y storage/documentos)
#
# Genera  <destino>/AAAAMMDD-HHMMSS/{base.sql.gz, archivos.tar.gz, SHA256SUMS, MANIFIESTO.txt}
#   • base.sql.gz      volcado consistente (--single-transaction) con rutinas y disparadores
#   • archivos.tar.gz  fotos, logotipos y DOCUMENTOS FISCALES (PDF, imágenes y XML)
#   • SHA256SUMS       huellas para verificar la copia antes de restaurar
# No modifica la base ni los archivos. La contraseña viaja por MYSQL_PWD (no aparece en la lista de procesos).
# Programación sugerida (cron diario 02:30):  30 2 * * *  /ruta/scripts/respaldar.sh /ruta/respaldos
# ===========================================================================
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# El entorno (Docker, cron, pruebas) manda sobre .env: sólo se completa lo que no venga definido.
PREVIAS="$(env | grep -E '^(DB_HOST|DB_USER|DB_PASSWORD|DB_NAME)=' || true)"
[[ -f "$RAIZ/.env" ]] && set -a && source <(grep -E '^(DB_HOST|DB_USER|DB_PASSWORD|DB_NAME)=' "$RAIZ/.env") && set +a
while IFS= read -r linea; do [[ -n "$linea" ]] && export "$linea"; done <<< "$PREVIAS"

DB_HOST="${DB_HOST:-localhost}"; DB_USER="${DB_USER:-root}"; DB_NAME="${DB_NAME:-cotizaciones}"
export MYSQL_PWD="${DB_PASSWORD:-}"
DESTINO="${1:-$RAIZ/respaldos}"
RETENER="${RETENER:-14}"
ARCHIVOS_DIRS="${ARCHIVOS_DIRS:-public/image storage/documentos}"

SELLO="$(date +%Y%m%d-%H%M%S)"
CARPETA="$DESTINO/$SELLO"
mkdir -p "$CARPETA"
chmod 700 "$DESTINO" "$CARPETA"

echo "==> Respaldando la base '$DB_NAME' en $CARPETA"
mysqldump -h "$DB_HOST" -u "$DB_USER" --single-transaction --routines --triggers --default-character-set=utf8mb4 "$DB_NAME" | gzip -9 > "$CARPETA/base.sql.gz"
[[ "$(gzip -t "$CARPETA/base.sql.gz" && echo ok)" == ok ]] || { echo "El volcado quedó dañado" >&2; exit 1; }

echo "==> Respaldando archivos: $ARCHIVOS_DIRS"
EXISTENTES=()
for d in $ARCHIVOS_DIRS; do [[ -e "$RAIZ/$d" ]] && EXISTENTES+=("$d"); done
if ((${#EXISTENTES[@]})); then
    tar -C "$RAIZ" -czf "$CARPETA/archivos.tar.gz" "${EXISTENTES[@]}"
else
    tar -czf "$CARPETA/archivos.tar.gz" -T /dev/null
fi

( cd "$CARPETA" && sha256sum base.sql.gz archivos.tar.gz > SHA256SUMS )
{
    echo "Respaldo de Cotizaciones"
    echo "Fecha:        $(date -Is)"
    echo "Base:         $DB_NAME en $DB_HOST"
    echo "Archivos:     ${EXISTENTES[*]:-(ninguno)}"
    echo "Migraciones:  $(mysql -h "$DB_HOST" -u "$DB_USER" -N -B "$DB_NAME" -e 'SELECT GROUP_CONCAT(nombre ORDER BY nombre) FROM schema_migraciones' 2>/dev/null || echo 'sin control de migraciones')"
    echo "Tablas:       $(mysql -h "$DB_HOST" -u "$DB_USER" -N -B "$DB_NAME" -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')"
} > "$CARPETA/MANIFIESTO.txt"
chmod 600 "$CARPETA"/*

# Retención: se conservan las últimas $RETENER copias.
cd "$DESTINO"
ls -1d 20??????-?????? 2>/dev/null | sort | head -n -"$RETENER" | while read -r vieja; do echo "==> Retención: borrando $vieja"; rm -rf -- "$vieja"; done

echo "==> Listo: $CARPETA ($(du -sh "$CARPETA" | cut -f1))"
echo "$CARPETA"
