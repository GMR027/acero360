#!/usr/bin/env bash
# ===========================================================================
# scripts/crear_usuario_portal.sh — Usuario MySQL del portal de clientes.
#
# El portal (sitio de Soluciones en Acero) comparte esta base. Este usuario
# sólo ve lo que el portal necesita: nada de costos, stock, usuarios, datos
# fiscales ni contabilidad. Es idempotente: se puede volver a ejecutar para
# rotar la contraseña o reaplicar permisos.
#
# Requiere las migraciones 052, 053 y 056 aplicadas (los permisos son por columna).
#
# Uso local:
#   PORTAL_DB_PASSWORD='...' scripts/crear_usuario_portal.sh [base]
#
# Uso en el clúster (la contraseña de root viene del Secret del pod):
#   PORTAL_DB_PASSWORD='...' \
#   MYSQL_CMD='kubectl -n cotizaciones exec -i deploy/mysql -- sh -c "exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\""' \
#   scripts/crear_usuario_portal.sh cotizaciones
#
# Variables:
#   PORTAL_DB_PASSWORD  contraseña del usuario (obligatoria, mínimo 16 caracteres)
#   PORTAL_DB_USER      nombre del usuario (por omisión portal_soluciones)
#   MYSQL_CMD           cliente mysql con privilegios de administrador (por omisión: mysql -uroot)
# ===========================================================================
set -euo pipefail

BASE="${1:-cotizaciones}"
USUARIO="${PORTAL_DB_USER:-portal_soluciones}"
MYSQL_CMD="${MYSQL_CMD:-mysql -uroot}"

if [[ -z "${PORTAL_DB_PASSWORD:-}" || ${#PORTAL_DB_PASSWORD} -lt 16 ]]; then
    echo "ERROR: define PORTAL_DB_PASSWORD (mínimo 16 caracteres)." >&2
    exit 1
fi
if [[ ! "$BASE" =~ ^[A-Za-z0-9_]+$ || ! "$USUARIO" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "ERROR: nombre de base o usuario inválido." >&2
    exit 1
fi

# Escapa comillas simples para el literal SQL de la contraseña.
CONTRASENA="${PORTAL_DB_PASSWORD//\\/\\\\}"
CONTRASENA="${CONTRASENA//\'/\\\'}"

bash -c "$MYSQL_CMD" <<SQL
CREATE USER IF NOT EXISTS '$USUARIO'@'%' IDENTIFIED BY '$CONTRASENA';
ALTER USER '$USUARIO'@'%' IDENTIFIED BY '$CONTRASENA';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$USUARIO'@'%';

-- Inicio de sesión y cambio de contraseña del cliente
GRANT SELECT (id, nombre, nombre_empresa, email, telefono, portal_contrasena, portal_actualizada_en),
      UPDATE (portal_contrasena, portal_actualizada_en)
   ON \`$BASE\`.clientes TO '$USUARIO'@'%';

-- Catálogo con precio de lista (sin costo ni existencias) y catálogo público (053)
GRANT SELECT (id, empresa_id, nombre, sku, categoria, categoria_id, unidad, descripcion, precio,
              imagen, imagen_uso, activo, publicado_web)
   ON \`$BASE\`.productos TO '$USUARIO'@'%';
GRANT SELECT (id, nombre, slug, descripcion, imagen, orden) ON \`$BASE\`.categorias_producto TO '$USUARIO'@'%';
GRANT SELECT ON \`$BASE\`.producto_empresa TO '$USUARIO'@'%';
GRANT SELECT (id, nombre, rfc, direccion, ciudad, estado, cp, telefono, email, logo, condiciones_envio, condiciones_compra, activa)
   ON \`$BASE\`.empresas TO '$USUARIO'@'%';

-- Distribuidores del sitio público (056)
GRANT SELECT (id, empresa_id, nombre, sucursal, municipio, direccion, telefono, maps, imagen, publicado, orden)
   ON \`$BASE\`.distribuidores TO '$USUARIO'@'%';

-- Pedidos del portal (alta, consulta y cancelación mientras están pendientes)
GRANT SELECT, INSERT, UPDATE (estado, cancelado_en) ON \`$BASE\`.pedidos TO '$USUARIO'@'%';
GRANT SELECT, INSERT ON \`$BASE\`.pedido_detalles TO '$USUARIO'@'%';

-- Seguimiento de la venta y fotos de entrega (los archivos se piden firmados a /portal/entregas/foto).
-- empresa_id: la galería pública del sitio muestra sólo las entregas de su negocio.
GRANT SELECT (id, folio, estado, empresa_id, cliente_id, entrega_estado, entrega_fecha) ON \`$BASE\`.ventas TO '$USUARIO'@'%';
GRANT SELECT (id, venta_id, fecha) ON \`$BASE\`.entregas TO '$USUARIO'@'%';
GRANT SELECT (id, entrega_id, tomada_en) ON \`$BASE\`.entrega_fotos TO '$USUARIO'@'%';

FLUSH PRIVILEGES;
SQL

echo "Usuario '$USUARIO' listo en la base '$BASE'."
