#!/usr/bin/env bash
# ===========================================================================
# respaldar.sh — copia de seguridad de la base de datos y de los archivos del sistema.
#
# Uso:   scripts/respaldar.sh [carpeta_destino]        (por defecto ./respaldos)
# Variables (o las de .env): DB_HOST DB_USER DB_PASSWORD DB_NAME  ·  RETENER (copias a conservar, 14)
#                            ARCHIVOS_DIRS (carpetas a respaldar; por defecto todas las persistentes)
#
# Genera  <destino>/AAAAMMDD-HHMMSS/{base.sql.gz, archivos.tar.gz, SHA256SUMS, MANIFIESTO.txt}
#   • base.sql.gz      volcado consistente (--single-transaction) con rutinas y disparadores
#   • archivos.tar.gz  fotos, logotipos, documentos fiscales, Archivo compartido y subidas
#   • SHA256SUMS       huellas para verificar la copia antes de restaurar
# No modifica la base ni los archivos. La contraseña viaja por MYSQL_PWD (no aparece en la lista de procesos).
# Programación sugerida (cron diario 02:30):  30 2 * * *  /ruta/scripts/respaldar.sh /ruta/respaldos
# En el clúster lo ejecuta el CronJob cotizaciones-respaldo (ver helm/cotizaciones/templates/app/cronjobs.yaml).
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
ARCHIVOS_DIRS="${ARCHIVOS_DIRS:-public/image public/uploads storage/documentos storage/archivo}"

# El cliente MariaDB del contenedor y el de MySQL 8 no comparten la opción de TLS.
# La conexión va por la red interna del clúster; se desactiva TLS para no fallar
# con el certificado autofirmado del servidor MySQL.
SSL=()
if mysqldump --help 2>/dev/null | grep -q -- '--ssl-mode'; then
    SSL=(--ssl-mode=DISABLED)
else
    SSL=(--ssl=0)
fi

# Si no lo impone quien llama (la app pasa RETENER), se respeta lo elegido en
# Ajustes → Almacenamiento. El CronJob del clúster también lee esta configuración.
if [[ -z "${RETENER:-}" ]]; then
    RETENER="$(mysql -h "$DB_HOST" -u "$DB_USER" "${SSL[@]}" -N -B "$DB_NAME" \
        -e "SELECT valor FROM configuracion WHERE clave = 'respaldo_retener'" 2>/dev/null || true)"
fi
RETENER="${RETENER:-14}"
[[ "$RETENER" =~ ^[0-9]+$ ]] || RETENER=14
if (( RETENER < 1 )); then RETENER=1; fi
if (( RETENER > 365 )); then RETENER=365; fi

SELLO="$(date +%Y%m%d-%H%M%S)"
CARPETA="$DESTINO/$SELLO"
mkdir -p "$CARPETA"
chmod 700 "$DESTINO" "$CARPETA"

echo "==> Respaldando la base '$DB_NAME' en $CARPETA"
mysqldump -h "$DB_HOST" -u "$DB_USER" "${SSL[@]}" --single-transaction --routines --triggers --no-tablespaces --default-character-set=utf8mb4 "$DB_NAME" | gzip -9 > "$CARPETA/base.sql.gz"
[[ "$(gzip -t "$CARPETA/base.sql.gz" && echo ok)" == ok ]] || { echo "El volcado quedó dañado" >&2; exit 1; }

echo "==> Respaldando archivos: $ARCHIVOS_DIRS"
EXISTENTES=()
for d in $ARCHIVOS_DIRS; do [[ -e "$RAIZ/$d" ]] && EXISTENTES+=("$d"); done
if ((${#EXISTENTES[@]})); then
    # Los paquetes .deb de la app de escritorio (storage/documentos/escritorio) se excluyen:
    # se regeneran y su SHA-256 ya queda en la base.
    tar -C "$RAIZ" --exclude='storage/documentos/escritorio' -czf "$CARPETA/archivos.tar.gz" "${EXISTENTES[@]}"
else
    tar -czf "$CARPETA/archivos.tar.gz" -T /dev/null
fi

( cd "$CARPETA" && sha256sum base.sql.gz archivos.tar.gz > SHA256SUMS )
{
    echo "Respaldo de Cotizaciones"
    echo "Fecha:        $(date -Is)"
    echo "Origen:       ${RESPALDO_ORIGEN:-manual}"
    echo "Base:         $DB_NAME en $DB_HOST"
    echo "Archivos:     ${EXISTENTES[*]:-(ninguno)}"
    echo "Migraciones:  $(mysql -h "$DB_HOST" -u "$DB_USER" "${SSL[@]}" -N -B "$DB_NAME" -e 'SELECT GROUP_CONCAT(nombre ORDER BY nombre) FROM schema_migraciones' 2>/dev/null || echo 'sin control de migraciones')"
    echo "Tablas:       $(mysql -h "$DB_HOST" -u "$DB_USER" "${SSL[@]}" -N -B "$DB_NAME" -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')"
} > "$CARPETA/MANIFIESTO.txt"
chmod 600 "$CARPETA"/*

# Retención: se conservan las últimas $RETENER copias.
cd "$DESTINO"
ls -1d 20??????-?????? 2>/dev/null | sort | head -n -"$RETENER" | while read -r vieja; do echo "==> Retención: borrando $vieja"; rm -rf -- "$vieja"; done

echo "==> Listo: $CARPETA ($(du -sh "$CARPETA" | cut -f1))"
echo "$CARPETA"
