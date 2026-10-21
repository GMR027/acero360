#!/usr/bin/env bash
# ===========================================================================
# ejecutar_todo.sh — regresión completa. NUNCA toca la base real.
#
#   pruebas/ejecutar_todo.sh [--rapido]
#
# 1. Crea la base de PRUEBA cotizaciones_test desde un volcado de la base actual y aplica las migraciones.
# 2. Levanta el servidor de PHP apuntando a esa base.
# 3. Ejecuta las pruebas de PHP, las de navegador (Playwright), las auditorías, la de permisos, la de
#    restauración de respaldos y las de la aplicación de escritorio.
# Con --rapido se omiten las auditorías de pantalla y la aplicación de escritorio (abre ventanas reales).
# Requiere: mysql local (root), php, node, pdftotext. Ver cambios/matriz-pruebas.md.
# ===========================================================================
set -uo pipefail
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"
RAPIDO=0; [[ "${1:-}" == "--rapido" ]] && RAPIDO=1
export DB_NAME=cotizaciones_test
PUERTO=8017
OK=0; MAL=0; FALLAS=()

ejecutar() {   # ejecutar "nombre" comando...
    local nombre="$1"; shift
    local salida; salida="$(mktemp)"
    if "$@" > "$salida" 2>&1; then OK=$((OK+1)); printf "  ✓ %-34s %s\n" "$nombre" "$(grep -E 'Resultado|Total:|TODO OK|Resumen|todas las pruebas|Pruebas:|pass ' "$salida" | tail -1 | cut -c1-90)"
    else MAL=$((MAL+1)); FALLAS+=("$nombre"); printf "  ✗ %-34s (ver %s)\n" "$nombre" "$salida"; fi
}

echo "==> Preparando la base de prueba ($DB_NAME)"
mysql -uroot -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysqldump -uroot --single-transaction cotizaciones | mysql -uroot "$DB_NAME"
php scripts/migrar.php "$DB_NAME" > /dev/null

echo "==> Servidor de pruebas en :$PUERTO"
php -S 127.0.0.1:$PUERTO index.php > /tmp/ejecutar_todo_servidor.log 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null; wait $SERVIDOR 2>/dev/null' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:$PUERTO/login && break; sleep 0.5; done

echo "==> PHP (lógica de negocio)"
for n in 2 3 4 5 6 7 9 10 11 12 14 15 16 17 18; do ejecutar "php etapa$n" php pruebas/php/etapa$n.php; done
ejecutar "php descuentos en PDF" php pruebas/probar_descuento_pdf.php

echo "==> Navegador (flujos completos)"
for t in probar_etapa2 probar_etapa3 probar_etapa4 probar_etapa5 probar_etapa7 probar_etapa9 probar_etapa10 probar_etapa12 probar_campana probar_devoluciones probar_etapa13 probar_compras probar_reportes probar_ronda probar_ronda2 probar_categorias probar_permisos; do
    ejecutar "$t" node pruebas/$t.mjs
done

if [[ $RAPIDO -eq 0 ]]; then
    echo "==> Auditorías de pantalla (escritorio y celular)"
    for t in auditar_ui auditar_profundo auditar_consola auditar_movil; do ejecutar "$t" node pruebas/$t.mjs; done
fi

echo "==> Respaldos y restauración"
ejecutar "respaldo y restauración" pruebas/probar_respaldo.sh "$DB_NAME"
ejecutar "limpieza de entradas" pruebas/probar_limpieza_entradas.sh

if [[ $RAPIDO -eq 0 ]]; then
    echo "==> Aplicación de escritorio (abre ventanas reales unos segundos)"
    ejecutar "escritorio · unitarias" bash -c 'cd desktop && npm test'
    ejecutar "escritorio · integración" bash -c 'cd desktop && node test/integracion.mjs'
fi

echo
echo "Resultado global: $OK correctas · $MAL con fallos"
((MAL == 0)) || { printf 'Con fallos: %s\n' "${FALLAS[*]}"; exit 1; }
