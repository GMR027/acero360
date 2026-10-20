#!/usr/bin/env bash
# probar_respaldo.sh — PRUEBA de restauración: respalda una base, la restaura en otra nueva y compara.
#   pruebas/probar_respaldo.sh [base_origen]     (por defecto cotizaciones_test; no modifica la base de origen)
set -euo pipefail
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ORIGEN="${1:-cotizaciones_test}"
RESTAURADA="${ORIGEN}_restaurada"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"; mysql -uroot -e "DROP DATABASE IF EXISTS \`$RESTAURADA\`" 2>/dev/null || true' EXIT
ok=0; mal=0
verifica() { if [[ "$2" == "1" ]]; then ok=$((ok+1)); echo "  ✓ $1"; else mal=$((mal+1)); echo "  ✗ $1 ${3:-}"; fi; }

# Archivos de ejemplo que deben viajar en el respaldo (documentos fiscales e imágenes).
mkdir -p "$TMP/app/public/image" "$TMP/app/storage/documentos/1/2026/01/ventas"
echo "factura de prueba" > "$TMP/app/storage/documentos/1/2026/01/ventas/abc123.pdf"
echo "xml de prueba" > "$TMP/app/storage/documentos/1/2026/01/ventas/abc123.xml"
echo "logo" > "$TMP/app/public/image/logo-prueba.png"
mkdir -p "$TMP/app/scripts" && cp "$RAIZ/scripts/respaldar.sh" "$RAIZ/scripts/restaurar.sh" "$TMP/app/scripts/"

echo "Respaldo"
SALIDA="$(DB_NAME="$ORIGEN" DB_USER=root RETENER=3 "$TMP/app/scripts/respaldar.sh" "$TMP/respaldos" | tail -1)"
verifica "genera la carpeta del respaldo" "$([[ -d "$SALIDA" ]] && echo 1 || echo 0)"
for f in base.sql.gz archivos.tar.gz SHA256SUMS MANIFIESTO.txt; do verifica "incluye $f" "$([[ -s "$SALIDA/$f" ]] && echo 1 || echo 0)"; done
verifica "los archivos incluyen documentos fiscales e imágenes" "$(tar -tzf "$SALIDA/archivos.tar.gz" | grep -c -E 'abc123.xml|logo-prueba.png' | grep -q 2 && echo 1 || echo 0)"
verifica "el respaldo no es legible por otros usuarios" "$([[ "$(stat -c %a "$SALIDA/base.sql.gz")" == 600 ]] && echo 1 || echo 0)"
verifica "el manifiesto lista las migraciones" "$(grep -q 'Migraciones:' "$SALIDA/MANIFIESTO.txt" && echo 1 || echo 0)"

echo "Restauración en una base nueva"
mysql -uroot -e "DROP DATABASE IF EXISTS \`$RESTAURADA\`"
DB_USER=root "$TMP/app/scripts/restaurar.sh" "$SALIDA" "$RESTAURADA" "$TMP/restaurados" > "$TMP/restaurar.log" 2>&1 && verifica "restaura sin errores" 1 || { verifica "restaura sin errores" 0 "$(tail -3 "$TMP/restaurar.log")"; }

cuenta() { mysql -uroot -N -B "$1" -e "SELECT CONCAT(table_name, ':', table_rows) FROM information_schema.tables WHERE table_schema = '$1' ORDER BY table_name" | md5sum | cut -d' ' -f1; }
tablas() { mysql -uroot -N -B -e "SELECT GROUP_CONCAT(table_name ORDER BY table_name) FROM information_schema.tables WHERE table_schema = '$1'"; }
verifica "las mismas tablas que el origen" "$([[ "$(tablas "$ORIGEN")" == "$(tablas "$RESTAURADA")" ]] && echo 1 || echo 0)"
DIFERENCIAS=0
for t in $(mysql -uroot -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$ORIGEN' AND table_type='BASE TABLE'"); do
    a="$(mysql -uroot -N -B "$ORIGEN" -e "SELECT COUNT(*) FROM \`$t\`")"; b="$(mysql -uroot -N -B "$RESTAURADA" -e "SELECT COUNT(*) FROM \`$t\`")"
    [[ "$a" == "$b" ]] || { DIFERENCIAS=$((DIFERENCIAS+1)); echo "    diferencia en $t: $a vs $b"; }
done
verifica "el número de registros coincide en TODAS las tablas" "$([[ $DIFERENCIAS -eq 0 ]] && echo 1 || echo 0)"
verifica "los datos coinciden (suma de verificación de productos y precios)" "$([[ "$(mysql -uroot -N -B "$ORIGEN" -e 'CHECKSUM TABLE productos' | cut -f2)" == "$(mysql -uroot -N -B "$RESTAURADA" -e 'CHECKSUM TABLE productos' | cut -f2)" ]] && echo 1 || echo 0)"
verifica "los archivos se restauran idénticos" "$([[ "$(cat "$TMP/restaurados/storage/documentos/1/2026/01/ventas/abc123.xml")" == "xml de prueba" ]] && echo 1 || echo 0)"

echo "Seguridad de la restauración"
DB_USER=root "$TMP/app/scripts/restaurar.sh" "$SALIDA" "$RESTAURADA" "$TMP/otra" > "$TMP/pisar.log" 2>&1 && verifica "se NIEGA a pisar una base con datos" 0 || verifica "se NIEGA a pisar una base con datos" "$(grep -q 'no se pisa' "$TMP/pisar.log" && echo 1 || echo 0)"
cp -r "$SALIDA" "$TMP/dañado"; echo "x" >> "$TMP/dañado/base.sql.gz"
mysql -uroot -e "DROP DATABASE IF EXISTS \`${RESTAURADA}_2\`"
DB_USER=root "$TMP/app/scripts/restaurar.sh" "$TMP/dañado" "${RESTAURADA}_2" "$TMP/x" > "$TMP/dan.log" 2>&1 && verifica "detecta un respaldo alterado y no restaura" 0 || verifica "detecta un respaldo alterado y no restaura" "$(grep -qE 'dañada|alterada' "$TMP/dan.log" && [[ "$(mysql -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='${RESTAURADA}_2'")" == 0 ]] && echo 1 || echo 0)"

echo "Retención"
for i in 1 2 3 4; do sleep 1; DB_NAME="$ORIGEN" DB_USER=root RETENER=3 "$TMP/app/scripts/respaldar.sh" "$TMP/respaldos" > /dev/null; done
verifica "conserva solo las últimas 3 copias" "$([[ "$(ls -1d "$TMP"/respaldos/20??????-?????? | wc -l)" == 3 ]] && echo 1 || echo 0)"

echo; echo "Resultado: $ok correctas · $mal fallos"
[[ $mal -eq 0 ]]
