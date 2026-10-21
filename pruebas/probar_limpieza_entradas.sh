#!/usr/bin/env bash
# ===========================================================================
# probar_limpieza_entradas.sh — verifica scripts/limpiar_entradas_proveedor.php en una base TEMPORAL
# (copia de cotizaciones_test; nunca toca la real):
#   simular no borra · aplicar exige respaldo verificado · un respaldo corrupto se rechaza ·
#   una dependencia nueva detiene el borrado · al aplicar solo desaparecen las entradas y queda bitácora ·
#   ninguna otra tabla cambia · repetirlo es inofensivo.
#   DB_NAME=cotizaciones_test pruebas/probar_limpieza_entradas.sh
# ===========================================================================
set -uo pipefail
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"
ORIGEN="${DB_NAME:-cotizaciones_test}"
[[ "$ORIGEN" == "cotizaciones" ]] && { echo "Usa una base de prueba (DB_NAME=cotizaciones_test)." >&2; exit 2; }
TEMPORAL="cotizaciones_limpieza_$$"
CARPETA="$(mktemp -d)"
OK=0; MAL=0
verifica() { if [[ "$2" == "1" ]]; then OK=$((OK+1)); echo "  ✓ $1"; else MAL=$((MAL+1)); echo "  ✗ $1${3:+ → $3}"; fi; }
sql() { mysql -uroot -N -B "$TEMPORAL" -e "$1"; }
limpiar() { mysql -uroot -e "DROP DATABASE IF EXISTS $TEMPORAL" 2>/dev/null; rm -rf "$CARPETA"; }
trap limpiar EXIT

mysql -uroot -e "DROP DATABASE IF EXISTS $TEMPORAL; CREATE DATABASE $TEMPORAL CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysqldump -uroot --single-transaction "$ORIGEN" | mysql -uroot "$TEMPORAL"

# Asegura entradas de prueba (proveedor y usuario existentes) y un proveedor intacto.
PROV="$(sql 'SELECT MIN(id) FROM proveedores')"
USU="$(sql 'SELECT MIN(id) FROM usuarios')"
sql "INSERT INTO proveedor_entradas (proveedor_id, fecha, numero_pedido, monto, descripcion, usuario_id) VALUES ($PROV, CURDATE(), 'LIM-1', 10.50, 'x', $USU), ($PROV, CURDATE(), 'LIM-2', 20, 'y', $USU)"

foto() { sql "SELECT COUNT(*) FROM proveedor_entradas"; }
otras() {   # huella de las demás tablas de negocio
    sql "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM proveedores), (SELECT COUNT(*) FROM gastos), (SELECT COUNT(*) FROM productos),
         (SELECT COUNT(*) FROM movimientos_inventario), (SELECT COUNT(*) FROM ventas), (SELECT COUNT(*) FROM ingresos),
         (SELECT COUNT(*) FROM documentos), (SELECT COUNT(*) FROM ordenes_compra), (SELECT COUNT(*) FROM pagos_compra),
         (SELECT COUNT(*) FROM usuarios), (SELECT COUNT(*) FROM categorias_proveedor))"
}
correr() { DB_NAME="$TEMPORAL" php scripts/limpiar_entradas_proveedor.php "$@" 2>&1; }

ANTES="$(foto)"; OTRAS_ANTES="$(otras)"
[[ "$ANTES" -ge 2 ]] && verifica "hay entradas que limpiar ($ANTES)" 1 || verifica "hay entradas que limpiar" 0 "$ANTES"

echo "Simulación"
SALIDA="$(correr)"; CODIGO=$?
verifica "simular termina bien y lista lo que borraría" "$([[ $CODIGO -eq 0 && "$SALIDA" == *"Simulación: no se borró nada"* && "$SALIDA" == *"LIM-1"* ]] && echo 1 || echo 0)"
verifica "simular no borra nada" "$([[ "$(foto)" == "$ANTES" ]] && echo 1 || echo 0)"

echo "Seguridad"
correr --aplicar > /dev/null; CODIGO=$?
verifica "aplicar sin respaldo se rechaza" "$([[ $CODIGO -eq 2 && "$(foto)" == "$ANTES" ]] && echo 1 || echo 0)" "código $CODIGO"

mysqldump -uroot --single-transaction "$TEMPORAL" | gzip -9 > "$CARPETA/base.sql.gz"
( cd "$CARPETA" && sha256sum base.sql.gz > SHA256SUMS )
echo "corrupción" >> "$CARPETA/base.sql.gz"
correr --aplicar --respaldo="$CARPETA" > /dev/null; CODIGO=$?
verifica "un respaldo que no verifica se rechaza" "$([[ $CODIGO -eq 2 && "$(foto)" == "$ANTES" ]] && echo 1 || echo 0)" "código $CODIGO"
mysqldump -uroot --single-transaction "$TEMPORAL" | gzip -9 > "$CARPETA/base.sql.gz"
( cd "$CARPETA" && sha256sum base.sql.gz > SHA256SUMS )

sql "CREATE TABLE dependiente_prueba (entrada_id INT UNSIGNED NOT NULL, CONSTRAINT fk_dep_entrada FOREIGN KEY (entrada_id) REFERENCES proveedor_entradas (id))"
correr --aplicar --respaldo="$CARPETA" > /dev/null; CODIGO=$?
verifica "si otra tabla depende de las entradas, se detiene" "$([[ $CODIGO -eq 2 && "$(foto)" == "$ANTES" ]] && echo 1 || echo 0)" "código $CODIGO"
sql "DROP TABLE dependiente_prueba"

echo "Aplicar"
SALIDA="$(correr --aplicar --respaldo="$CARPETA")"; CODIGO=$?
verifica "aplicar con respaldo verificado termina bien" "$([[ $CODIGO -eq 0 && "$SALIDA" == *"Respaldo verificado"* ]] && echo 1 || echo 0)" "$CODIGO"
verifica "las entradas desaparecen" "$([[ "$(foto)" == "0" ]] && echo 1 || echo 0)"
verifica "ninguna otra tabla cambió (proveedores, gastos, productos, inventario, ventas, contabilidad, usuarios…)" "$([[ "$(otras)" == "$OTRAS_ANTES" ]] && echo 1 || echo 0)" "$OTRAS_ANTES vs $(otras)"
verifica "cada entrada borrada quedó en la bitácora" "$([[ "$(sql "SELECT COUNT(*) FROM auditoria WHERE entidad='proveedor_entrada' AND accion='eliminar'")" -ge "$ANTES" ]] && echo 1 || echo 0)"
SALIDA="$(correr --aplicar --respaldo="$CARPETA")"; CODIGO=$?
verifica "repetirlo es inofensivo" "$([[ $CODIGO -eq 0 && "$SALIDA" == *"No hay nada que borrar"* ]] && echo 1 || echo 0)"

echo
echo "Resultado: $OK correctas · $MAL fallos"
[[ $MAL -eq 0 ]]
