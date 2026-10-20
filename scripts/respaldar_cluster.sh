#!/usr/bin/env bash
# ===========================================================================
# respaldar_cluster.sh — respaldo de la PRODUCCIÓN (clúster) desde un equipo con acceso a kubectl.
#
# Uso:   scripts/respaldar_cluster.sh [carpeta_destino]       (por defecto ~/Documentos/respaldos-cotizaciones)
# Variables: NAMESPACE (cotizaciones) · RETENER (copias a conservar, 14)
#
# Genera <destino>/AAAAMMDD-HHMMSS/{base.sql.gz, archivos.tar.gz, SHA256SUMS, MANIFIESTO.txt} con el MISMO formato que
# scripts/respaldar.sh, así que scripts/restaurar.sh lo restaura igual. Incluye la base, las fotos/logotipos y los
# DOCUMENTOS FISCALES (storage/documentos). Solo lee: no modifica nada en el clúster.
# Cron sugerido (diario 02:30):  30 2 * * *  /ruta/scripts/respaldar_cluster.sh >> ~/Documentos/respaldos-cotizaciones/respaldo.log 2>&1
# ===========================================================================
set -euo pipefail
export PATH="/snap/bin:/usr/local/bin:/usr/bin:/bin:$PATH"

NAMESPACE="${NAMESPACE:-cotizaciones}"
DESTINO="${1:-$HOME/Documentos/respaldos-cotizaciones}"
RETENER="${RETENER:-14}"
SELLO="$(date +%Y%m%d-%H%M%S)"
CARPETA="$DESTINO/$SELLO"
mkdir -p "$CARPETA"; chmod 700 "$DESTINO" "$CARPETA"

echo "[$(date -Is)] Respaldando producción ($NAMESPACE) en $CARPETA"
kubectl exec -n "$NAMESPACE" deploy/mysql -- sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --default-character-set=utf8mb4 --no-tablespaces cotizaciones 2>/dev/null' | gzip -9 > "$CARPETA/base.sql.gz"
gzip -t "$CARPETA/base.sql.gz"
[[ "$(zcat "$CARPETA/base.sql.gz" | grep -c 'CREATE TABLE')" -ge 20 ]] || { echo "El volcado parece incompleto" >&2; exit 1; }

# Los paquetes .deb (storage/documentos/escritorio) se excluyen: se regeneran y su SHA-256 queda en la base.
# La transmisión se verifica y se reintenta (kubectl exec puede cortar flujos largos).
for intento in 1 2 3; do
    kubectl exec -n "$NAMESPACE" deploy/cotizaciones -c cotizaciones -- sh -c 'cd /var/www/html && tar czf - --exclude=storage/documentos/escritorio public/image storage/documentos 2>/dev/null' > "$CARPETA/archivos.tar.gz" || true
    gzip -t "$CARPETA/archivos.tar.gz" 2>/dev/null && break
    echo "Archivos: transmisión incompleta (intento $intento/3)" >&2
    [[ $intento -lt 3 ]] || { echo "No se pudo respaldar archivos" >&2; exit 1; }
done

( cd "$CARPETA" && sha256sum base.sql.gz archivos.tar.gz > SHA256SUMS )
{
    echo "Respaldo de PRODUCCIÓN de Cotizaciones"
    echo "Fecha:       $(date -Is)"
    echo "Clúster:     $(kubectl config current-context) · namespace $NAMESPACE"
    echo "Migraciones: $(kubectl exec -n "$NAMESPACE" deploy/mysql -- sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B cotizaciones -e "SELECT COUNT(*) FROM schema_migraciones"' 2>/dev/null)"
    echo "Tablas:      $(zcat "$CARPETA/base.sql.gz" | grep -c 'CREATE TABLE')"
} > "$CARPETA/MANIFIESTO.txt"
chmod 600 "$CARPETA"/*

cd "$DESTINO"
ls -1d 20??????-?????? 2>/dev/null | sort | head -n -"$RETENER" | while read -r vieja; do echo "Retención: borrando $vieja"; rm -rf -- "$vieja"; done
echo "[$(date -Is)] Listo: $CARPETA ($(du -sh "$CARPETA" | cut -f1))"
