#!/usr/bin/env bash
# android/compilar.sh — compila el APK firmado de Acero 360 para Android (arm64).
#
#   ./compilar.sh            → APK release firmado en android/dist/acero360-android_<versión>.apk
#   ./compilar.sh --instalar → además lo instala en el teléfono conectado por ADB
#
# La versión sale de src-tauri/tauri.conf.json ("version"); súbela antes de cada publicación.
# Publicar en el servidor: php scripts/publicar_android.php android/dist/acero360-android_X.Y.Z.apk X.Y.Z "notas"
set -euo pipefail
cd "$(dirname "$0")"
export ANDROID_HOME="${ANDROID_HOME:-$HOME/Android/Sdk}"
export NDK_HOME="${NDK_HOME:-$(ls -d "$ANDROID_HOME"/ndk/* | sort -V | tail -1)}"
FIRMA="${ACERO360_FIRMA:-$HOME/.config/acero360-android/keystore.properties}"
[[ -f "$FIRMA" ]] || { echo "Falta la llave de firma ($FIRMA). Sin ella Android no aceptará el APK como actualización." >&2; exit 1; }

VERSION=$(grep -m1 '"version"' src-tauri/tauri.conf.json | sed -E 's/.*"([0-9]+\.[0-9]+\.[0-9]+)".*/\1/')
(cd src-tauri && cargo tauri android build --apk --target aarch64)

mkdir -p dist
SALIDA="dist/acero360-android_${VERSION}.apk"
cp src-tauri/gen/android/app/build/outputs/apk/universal/release/app-universal-release.apk "$SALIDA"
"$ANDROID_HOME"/build-tools/$(ls "$ANDROID_HOME"/build-tools | sort -V | tail -1)/apksigner verify "$SALIDA"
echo "Listo: $SALIDA ($(du -h "$SALIDA" | cut -f1)) · SHA-256 $(sha256sum "$SALIDA" | cut -d' ' -f1)"

if [[ "${1:-}" == "--instalar" ]]; then
  adb install -r "$SALIDA"
fi
