#!/usr/bin/env bash
# ===========================================================================
# deploy.sh — construye, publica y despliega una actualización del proyecto.
#
# Uso:
#   ./deploy.sh            # tag auto-incremental (último + 1)
#   ./deploy.sh 7          # usar un tag concreto
#
# Flujo:
#   1. Verifica sesión de Docker Hub
#   2. Sincroniza el dump SQL dentro del chart (si existe mysql/dump-inicial.sql)
#   3. docker build  → gmr27/cotizaciones:<tag> (+ :latest)
#   4. docker push
#   5. helm upgrade --install (namespace cotizaciones)
#   6. kubectl rollout status + resumen final
#
# La base de datos y las imágenes subidas NO se tocan en los upgrades:
# viven en PVCs y el Job de importación sólo corre en el primer install.
# ===========================================================================
set -euo pipefail

# --- Configuración ---------------------------------------------------------
REPO="gmr27/cotizaciones"
NAMESPACE="cotizaciones"
RELEASE="cotizaciones"
CHART_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/helm/cotizaciones"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TAG_FILE="$ROOT_DIR/.deploy-tag"

# --- Colores ---------------------------------------------------------------
verde()  { printf '\033[32m%s\033[0m\n' "$*"; }
amarillo(){ printf '\033[33m%s\033[0m\n' "$*"; }
rojo()   { printf '\033[31m%s\033[0m\n' "$*" >&2; }

# --- Tag -------------------------------------------------------------------
if [[ $# -ge 1 ]]; then
    TAG="$1"
else
    ULTIMO=0
    [[ -f "$TAG_FILE" ]] && ULTIMO="$(cat "$TAG_FILE")"
    [[ "$ULTIMO" =~ ^[0-9]+$ ]] || ULTIMO=0
    TAG=$((ULTIMO + 1))
fi
verde "==> Tag de esta versión: $TAG"

# --- Requisitos -------------------------------------------------------------
for cmd in docker helm kubectl; do
    command -v "$cmd" >/dev/null 2>&1 || { rojo "Falta '$cmd' en el PATH."; exit 1; }
done

# Sesión de Docker Hub: basta con que exista credencial configurada;
# si el token expiró, docker push lo renovará o fallará con error claro.
if ! grep -q '"auths"' ~/.docker/config.json 2>/dev/null \
   || grep -q '"auths"[[:space:]]*:[[:space:]]*{}' ~/.docker/config.json 2>/dev/null; then
    rojo "No hay credenciales de Docker Hub. Ejecuta primero:  docker login"
    exit 1
fi

# --- Sincronizar dump SQL con el chart --------------------------------------
# El ConfigMap embebe files/dump-inicial.sql; si hay un dump nuevo en mysql/,
# lo copiamos para que un PRIMER install futuro use los datos más recientes.
if [[ -f "$ROOT_DIR/mysql/dump-inicial.sql" ]]; then
    cp "$ROOT_DIR/mysql/dump-inicial.sql" "$CHART_DIR/files/dump-inicial.sql"
fi

# values.local.yaml es obligatorio (contiene los secretos reales)
if [[ ! -f "$CHART_DIR/values.local.yaml" ]]; then
    rojo "Falta $CHART_DIR/values.local.yaml (contraseñas DB y SMTP)."
    exit 1
fi

# --- Assets compilados ------------------------------------------------------
# El Dockerfile sólo COPIA build/ ya hecho (la imagen no lleva Node), así que
# sin este paso un .scss o .js recién editado viajaría con la versión vieja:
# un fallo silencioso, porque la app arranca perfecta con el CSS anterior.
verde "==> Compilando estilos y JavaScript..."
if [[ -d "$ROOT_DIR/node_modules" ]]; then
    (cd "$ROOT_DIR" && npm run build)
else
    amarillo "Sin node_modules: se usa build/ ya compilado (ejecuta 'npm install' si tocaste src/)."
fi

# --- Build ------------------------------------------------------------------
verde "==> Construyendo imagen..."
docker build -t "$REPO:$TAG" -t "$REPO:latest" "$ROOT_DIR"

# --- Push -------------------------------------------------------------------
verde "==> Subiendo a Docker Hub..."
docker push "$REPO:$TAG"
docker push "$REPO:latest"

# --- Deploy -----------------------------------------------------------------
verde "==> Desplegando con Helm en el namespace '$NAMESPACE'..."
helm upgrade --install "$RELEASE" "$CHART_DIR" \
    --namespace "$NAMESPACE" \
    --create-namespace \
    -f "$CHART_DIR/values.yaml" \
    -f "$CHART_DIR/values.local.yaml" \
    --set image.tag="$TAG"

echo "$TAG" > "$TAG_FILE"

# --- Verificación -------------------------------------------------------------
verde "==> Esperando a que el rollout termine..."
kubectl rollout status deployment/cotizaciones -n "$NAMESPACE" --timeout=180s
kubectl rollout status deployment/mysql        -n "$NAMESPACE" --timeout=180s

echo
verde "==> Despliegue completado. Estado actual:"
kubectl get pods,svc,ingress -n "$NAMESPACE"
echo
verde "URL: https://cotizaciones.iguzman.com.mx"
