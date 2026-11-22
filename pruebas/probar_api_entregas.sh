#!/usr/bin/env bash
# Prueba de humo de la API de Acero Entregas.
#
# Uso:
#   APP_URL=http://127.0.0.1:8090 \
#   APP_EMAIL=usuario@ejemplo.com \
#   APP_PASSWORD='TuContraseña' \
#   ./pruebas/probar_api_entregas.sh
#
# No registra entregas: solo inicia sesión, lee pendientes, abre el primer
# pedido y descarga los PDFs. Sirve para comprobar que la API y las plantillas
# responden antes de instalar el APK.
set -euo pipefail

APP_URL="${APP_URL:-http://127.0.0.1:8090}"
APP_EMAIL="${APP_EMAIL:?Define APP_EMAIL}"
APP_PASSWORD="${APP_PASSWORD:?Define APP_PASSWORD}"
BASE="$APP_URL/api/entregas/v1"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "1. Login..."
LOGIN=$(curl -fsS -X POST "$BASE/login" \
    -H 'Content-Type: application/json' \
    -d "{\"email\":\"$APP_EMAIL\",\"password\":\"$APP_PASSWORD\",\"dispositivo\":\"prueba-smoke\"}")
TOKEN=$(printf '%s' "$LOGIN" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["token"]??"";')
[[ ${#TOKEN} -eq 64 ]] || { echo "No se obtuvo token"; exit 1; }
echo "   token OK"

echo "2. Pendientes..."
PENDIENTES=$(curl -fsS -H "Authorization: Bearer $TOKEN" "$BASE/pendientes")
php -r '$j=json_decode(stream_get_contents(STDIN),true); if(empty($j["ok"])) exit(1); echo "   ".($j["totales"]["ventas"]??0)." pedido(s), ".($j["totales"]["piezas"]??0)." pieza(s)\n";' <<<"$PENDIENTES"

echo "3. Detalle del primer pedido..."
VENTA=$(printf '%s' "$PENDIENTES" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["clientes"][0]["ventas"][0]["id"]??"";')
[[ -n "$VENTA" ]] || { echo "No hay pedidos pendientes para probar"; exit 0; }
curl -fsS -H "Authorization: Bearer $TOKEN" "$BASE/venta?id=$VENTA" > "$TMP/venta.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); echo "   ".$j["venta"]["folio"]." · ".count($j["lineas"])." línea(s)\n";' "$TMP/venta.json"

echo "4. Hoja de entrega (2 copias, carta horizontal)..."
curl -fsS -H "Authorization: Bearer $TOKEN" "$BASE/pdf/entrega?venta=$VENTA" -o "$TMP/hoja.pdf"
pdfinfo "$TMP/hoja.pdf" | grep -q 'Page size:.*792 x 612' || { echo "La hoja no salió horizontal"; exit 1; }
echo "   PDF OK: $(pdfinfo "$TMP/hoja.pdf" | awk '/Pages/ {print $2}') página(s)"

echo "5. Lista de preparación..."
curl -fsS -H "Authorization: Bearer $TOKEN" "$BASE/pdf/preparacion?ventas=$VENTA" -o "$TMP/prep.pdf"
echo "   PDF OK: $(stat -c%s "$TMP/prep.pdf") bytes"

echo "6. Logout..."
curl -fsS -X POST -H "Authorization: Bearer $TOKEN" "$BASE/logout" >/dev/null
echo "Todo correcto."
