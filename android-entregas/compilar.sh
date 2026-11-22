#!/usr/bin/env bash
# android-entregas/compilar.sh — compila el APK firmado de Acero Entregas.
#
#   ./compilar.sh            → dist/acero360-entregas_1.0.0.apk
#   ./compilar.sh --instalar → además lo instala por ADB
#
# La firma vive en ~/.config/acero360-entregas (fuera del repositorio).
# Respáldala: sin ella no se pueden publicar actualizaciones sobre la app.
set -euo pipefail

cd "$(dirname "$0")"
export ANDROID_HOME="${ANDROID_HOME:-$HOME/Android/Sdk}"

./gradlew assembleRelease
mkdir -p dist

VERSION=$(grep -m1 'versionName' app/build.gradle.kts | sed -E 's/.*"([0-9]+\.[0-9]+\.[0-9]+)".*/\1/')
SALIDA="dist/acero360-entregas_${VERSION}.apk"
cp app/build/outputs/apk/release/app-release.apk "$SALIDA"

"$ANDROID_HOME"/build-tools/$(ls "$ANDROID_HOME"/build-tools | sort -V | tail -1)/apksigner verify "$SALIDA"
echo "Listo: $SALIDA ($(du -h "$SALIDA" | cut -f1))"
echo "SHA-256: $(sha256sum "$SALIDA" | cut -d' ' -f1)"

if [[ "${1:-}" == "--instalar" ]]; then
    adb install -r "$SALIDA"
fi
