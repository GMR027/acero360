# DESPLIEGUE — manual de despliegue

Runbook operativo: cómo publicar cambios del proyecto en el clúster, cómo
verificarlos, cómo volver atrás y cómo subir el límite de subida de archivos.

> La referencia conceptual (qué es cada pieza, arquitectura, límites) está en
> [`cambios/documentacion.md` §16](cambios/documentacion.md#16-despliegue).
> Este fichero es sólo el **procedimiento**.

---

## 1. En una línea

```bash
cd ~/Documentos/programacion/cotizaciones
./deploy.sh
```

Eso es todo. Compila, construye, sube la imagen y actualiza el despliegue.
Con tag concreto:

```bash
./deploy.sh 7
```

---

## 2. Requisitos (comprobar una vez)

```bash
docker version --format '{{.Client.Version}}'   # 29.8.2
helm version --short                            # v3.20.1
kubectl config current-context                  # microk8s
kubectl get ns cotizaciones                     # Active
ls helm/cotizaciones/values.local.yaml          # debe existir
grep -c auths ~/.docker/config.json             # > 0  (si no: docker login)
```

Si falta alguno, `deploy.sh` lo detecta y se detiene antes de romper nada.

---

## 3. Qué hace `deploy.sh`, en orden

| # | Paso | Comando |
|---|---|---|
| 1 | Tag auto-incremental (`.deploy-tag` + 1) | — |
| 2 | Comprueba `docker`, `helm`, `kubectl` y sesión de Docker Hub | — |
| 3 | Copia `mysql/dump-inicial.sql` al chart *(sólo para un futuro primer install; **no** toca la BD actual)* | `cp` |
| 4 | **Compila `build/`** (la imagen no lleva Node, sólo copia lo compilado) | `npm run build` |
| 5 | Construye la imagen | `docker build -t gmr27/cotizaciones:<tag> -t :latest .` |
| 6 | Sube a Docker Hub | `docker push` ×2 |
| 7 | Actualiza el release | `helm upgrade --install … --set image.tag=<tag>` |
| 8 | Guarda el tag | `echo <tag> > .deploy-tag` |
| 9 | Espera al rollout y muestra el estado | `kubectl rollout status` ×2 |

---

## 4. Equivalente manual (por si no usas el script)

Copiar y pegar tal cual:

```bash
cd ~/Documentos/programacion/cotizaciones

# --- 4.1 tag ---------------------------------------------------------------
TAG=$(( $(cat .deploy-tag 2>/dev/null || echo 0) + 1 )); echo "$TAG"

# --- 4.2 assets compilados (obligatorio si tocaste src/) -------------------
npm run build

# --- 4.3 imagen ------------------------------------------------------------
docker build -t "gmr27/cotizaciones:$TAG" -t "gmr27/cotizaciones:latest" .

# --- 4.4 publicar ----------------------------------------------------------
docker push "gmr27/cotizaciones:$TAG"
docker push "gmr27/cotizaciones:latest"

# --- 4.5 desplegar ---------------------------------------------------------
helm upgrade --install cotizaciones ./helm/cotizaciones \
  --namespace cotizaciones \
  --create-namespace \
  -f helm/cotizaciones/values.yaml \
  -f helm/cotizaciones/values.local.yaml \
  --set image.tag="$TAG"

# --- 4.6 guardar el tag y esperar -----------------------------------------
echo "$TAG" > .deploy-tag
kubectl rollout status deployment/cotizaciones -n cotizaciones --timeout=180s
kubectl rollout status deployment/mysql        -n cotizaciones --timeout=180s

# --- 4.7 estado ------------------------------------------------------------
kubectl get pods,svc,ingress -n cotizaciones
```

---

## 5. Regla: la base de datos NO se modifica

Los despliegues **nunca** tocan los datos ya escritos:

- La base vive en el PVC **`mysql-data` (2Gi)** y las fotos subidas en
  **`cotizaciones-images` (1Gi)**. Un `helm upgrade` no los recicla ni los
  restaura.
- El Job `cotizaciones-db-init` sólo importa el dump si la tabla `usuarios`
  **no existe**. Con datos presentes termina en `Completed` sin hacer nada.
- El pod de MySQL no se reinicia (el `rollout status` sólo espera).

**Por eso: no ejecutar `mysql/migracion_*.sql`, ni `mysql/dump-inicial.sql`,
ni `scripts/preparar_*.php` contra el clúster sin un permiso explícito.**

Si algún día un cambio de código exige una columna nueva, se hace **una sola
vez y a mano**, y nunca dentro de `deploy.sh`:

```bash
# ⚠️ Sólo con permiso. Ver antes qué hace el fichero.
PW=$(kubectl exec deploy/mysql -n cotizaciones -- printenv MYSQL_ROOT_PASSWORD)
kubectl exec -i deploy/mysql -n cotizaciones -- \
  sh -c "MYSQL_PWD='$PW' mysql -uroot cotizaciones" < mysql/migracion_xxx.sql
```

---

## 6. Verificar que salió bien

```bash
# tag publicado y tag en ejecución
cat .deploy-tag
kubectl get deploy cotizaciones -n cotizaciones \
  -o jsonpath='{.spec.template.spec.containers[0].image}{"\n"}'

# pods y sitio
kubectl get pods -n cotizaciones
curl -s -o /dev/null -w '%{http_code}\n' https://cotizaciones.iguzman.com.mx/login   # 200

# ¿el cambio nuevo está dentro del contenedor?
kubectl exec deploy/cotizaciones -n cotizaciones -- \
  sh -c "grep -n 'TAMANO_MAXIMO = ' /var/www/html/clases/Imagenes.php"

# errores de la app
kubectl logs deploy/cotizaciones -n cotizaciones --tail=50

# revisión de Helm
helm history cotizaciones -n cotizaciones
```

---

## 7. Volver atrás (rollback)

```bash
# a la imagen anterior (rápido, sin reconstruir)
kubectl rollout undo deployment/cotizaciones -n cotizaciones

# o a un tag concreto ya publicado
helm upgrade cotizaciones ./helm/cotizaciones \
  --namespace cotizaciones \
  -f helm/cotizaciones/values.yaml \
  -f helm/cotizaciones/values.local.yaml \
  --set image.tag=2
```

Tampoco el rollback toca la base de datos.

---

## 8. Cambiar el límite de subida de archivos

El que manda es el de la aplicación. Hay que mover **tres** sitios que tienen
que quedar iguales:

| # | Fichero | Qué cambiar |
|---|---|---|
| 1 | `clases/Imagenes.php` | `public const TAMANO_MAXIMO = 6291456;` (6 MB) |
| 2 | `src/js/modulos/validacion.js` | `const TAMANO_MAXIMO_IMAGEN = 6 * 1024 * 1024;` |
| 3 | 6 plantillas (`campoFoto`, `campoRetrato`, `formularioProductos`, `formularioEmpresas`, `formularioVentas`, `formularioProveedorEntradas`) | texto «hasta 6 MB» |

```bash
# ejemplo: pasar de 6 MB a 10 MB
perl -pi -e 's/TAMANO_MAXIMO = \d+/TAMANO_MAXIMO = 10485760/' clases/Imagenes.php
perl -pi -e 's/TAMANO_MAXIMO_IMAGEN = \d+ \* 1024 \* 1024/TAMANO_MAXIMO_IMAGEN = 10 * 1024 * 1024/' src/js/modulos/validacion.js
grep -rl 'hasta 6 MB' templates/ | xargs perl -pi -e 's/hasta 6 MB/hasta 10 MB/g'

npm run build && ./deploy.sh
```

Los mensajes de error se derivan de esas constantes, no hay que tocarlos.
Si se supera el tope de la app pero no el de PHP/ingress aparece un **413**:
entonces hay que subir `upload_max_filesize`/`post_max_size` en el `Dockerfile`
y `ingress.proxyBodySize` en `helm/cotizaciones/values.yaml` (hoy 512M / 512M / 512m;
el valor alto es para poder subir un respaldo externo completo desde Ajustes → Almacenamiento).

---

## 8.1 Respaldos: se hacen y se restauran en el clúster

La app corre con el cliente de MySQL (`default-mysql-client`) y los scripts
`scripts/respaldar.sh` / `scripts/restaurar.sh` dentro de la imagen. El respaldo
**no depende de un equipo con `kubectl`**.

| Pieza | Dónde |
|---|---|
| Copias en el clúster | PVC `cotizaciones-respaldos`, montado en `/var/www/html/respaldos` |
| Respaldo diario | CronJob `cotizaciones-respaldo` (02:30; base + todas las carpetas persistentes) |
| Manual | Ajustes → Almacenamiento → «Hacer un respaldo ahora» |
| Descargar una copia | Botón «Descargar» → un único `.tar.gz` (base + archivos + huellas) |
| Restaurar una copia del clúster | Botón «Restaurar en base nueva» |
| Restaurar una copia externa | «Subir copia externa» y, después, «Restaurar en base nueva» |

La copia incluye `public/image`, `public/uploads`, `storage/documentos` y
`storage/archivo`. Este último ya tiene su propio PVC (`cotizaciones-archivo`):
antes vivía sólo en la capa del contenedor y se perdía al reiniciar.

> **Antes del primer despliegue con esta versión:** si el pod actual tiene
> archivos en `/var/www/html/storage/archivo`, cópialos fuera (por ejemplo con
> `kubectl cp`) antes de actualizar. Al montar el PVC nuevo, esa carpeta empieza
> vacía y los archivos que sólo estaban en el contenedor no se migran solos.

Restaurar **nunca** pisa la base viva: crea `cotizaciones_restaurada_AAAAMMDD`,
extrae los archivos en `respaldos/restauraciones/…` y verifica las huellas
SHA-256 antes de importar. La copia descargada se puede guardar en un disco
externo o en la nube y volver a subir a esta o a otra instalación.

Para crear esa base nueva, el pod de la app recibe `MYSQL_ROOT_PASSWORD` del
Secret `cotizaciones`; **sólo `scripts/restaurar.sh` lo usa**. El respaldo y la
consulta normal siguen con el usuario de la app. La conexión al MySQL interno
desactiva TLS porque el servidor usa un certificado autofirmado.

```bash
# Ver el estado del respaldo diario y las copias en el volumen
kubectl get cronjob,job -n cotizaciones | grep respaldo
kubectl exec deploy/cotizaciones -n cotizaciones -- ls -lh /var/www/html/respaldos
```

> El PVC es `ReadWriteOnce`: el CronJob y la app deben correr en el mismo nodo
> (MicroK8s de un nodo). No escales la app a varios nodos con este esquema.

---

## 9. Problemas frecuentes

| Síntoma | Causa | Solución |
|---|---|---|
| `deploy.sh` dice `Falta 'helm' en el PATH` | Herramienta no instalada | `sudo snap install helm --classic` |
| `No hay credencial de Docker Hub` | Sesión caducada | `docker login` |
| Desplegué pero no se ve el cambio de CSS/JS | La imagen **copia** `build/`; no se compiló | Ya lo hace el script; a mano: `npm run build` |
| Error **413** al subir un archivo | El ingress deja pasar menos que la app | `values.yaml → ingress.proxyBodySize` (512m) |
| «La imagen no puede pesar más de N MB» con PHP en 20M | El tope lo pone la aplicación | Sección 8 |
| La app arranca pero dice que falta una columna | Hubo un cambio de esquema sin migrar | Sección 5 (a mano, con permiso) |
| `helm upgrade` falla por valores | Falta `values.local.yaml` | Crearlo con las contraseñas reales |
| 500 en todo el sitio | Revisar secretos y `.env` del release | `kubectl logs deploy/cotizaciones -n cotizaciones` |

---

## 10. Mapa de ficheros

```
Dockerfile                  imagen PHP 8.3 + Apache (límites PHP en el .ini)
.dockerignore               qué NO viaja a la imagen
deploy.sh                   el proceso entero en un comando
.deploy-tag                 último tag publicado (auto-incremental)
helm/cotizaciones/
  Chart.yaml                chart
  values.yaml               valores por defecto (host, límites, imágenes)
  values.local.yaml         secretos reales — NO subir a git
  files/dump-inicial.sql    dump para el primer install
  templates/                Deployment, MySQL, Service, Ingress, PVC, Job
DESPLIEGUE.md               este fichero
```

---

## 11. Último despliegue registrado

| | |
|---|---|
| Fecha | 23 de octubre de 2026 |
| Tag | **20** (`gmr27/cotizaciones:20`) |
| Revisión de Helm | 22 |
| Pod de la app | `cotizaciones-78d84b9bc4-whn6m` |
| MySQL | **no reiniciado** (mismo pod, 17 días) |
| Migraciones | «Migraciones al día» (28 aplicadas: hasta `028_calendario.sql`) |
| App de escritorio | **1.1.0** publicada (`cotizaciones-escritorio_1.1.0_amd64.deb`, 94.9 MB, SHA-256 `60afbd84…`) |
| URL | https://cotizaciones.iguzman.com.mx |

Comprobar el estado real en cualquier momento con los comandos de la
**sección 6**.
