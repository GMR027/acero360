# cambios.md — Diferencias con el proyecto base (`canastillasWep`)

> Este documento registra **qué cambió** respecto al proyecto del que parte la
> arquitectura, `~/Documentos/programacion/canastillasWep`.
>
> - **Proyecto base:** canastillasWep (CRUD de canastillas: usuarios, productos, pedidos, reportes).
> - **Proyecto nuevo:** cotizaciones (app de cotización de productos para dos negocios, con correo y PDF).
> - **Regla:** canastillasWep **no se modifica**; se usa sólo como referencia.

---

## 1. Qué se conserva tal cual (la base reutilizada)

| Pieza | Detalle |
|---|---|
| Front controller + router | `index.php` que enruta todo y `Router.php` con comprobación de método y middlewares |
| Capa de acceso a datos | Una clase por entidad en `/clases` con `setDB()`, `atributos()`, `sanitizarAtributos()`, `crear()`, `actualizar()`, `eliminar()`, `validar()`, `getErrores()` |
| Bootstrap | `includes/app.php` carga funciones → conexión → autoload de Composer → inyecta la BD en cada clase |
| Helpers | `template()`, `escaparValores()`, `validarID()`, `paginacion()`, middlewares `iniciarSesion()` / `soloAdmin()` |
| Conexión | `config/database.php` con variables de entorno y `utf8mb4` |
| Build | `gulpfile.js` con las tareas `css` y `js`, salida a `build/css` y `build/js` |
| Estilo | SCSS en `src/scss` (carpetas `base/` y `layout/`), medidas en `rem`, flexbox/grid |

---

## 2. Cambios de estructura de archivos

| canastillasWep | cotizaciones | Motivo |
|---|---|---|
| `views/` | `views/` | Se conserva |
| `templates/` (10 archivos) | `templates/` (10 archivos, otros nuevos) | Ver §5 |
| — | `mysql/cotizaciones.sql` | El proyecto base sólo tiene `canastillas.mwb` (archivo de Workbench, **sin SQL exportado**). Aquí se entrega el SQL reproducible con `DROP/CREATE DATABASE` |
| — | `OPENCODE.md`, `plan.md`, `cambios.md` | Especificación, plan de fases y este documento |
| — | `design/` | 13 maquetas HTML + PNG que definen la interfaz |
| — | `.htaccess` | Rewriting hacia el front controller (faltaba en el proyecto base) |
| `README.md`, `filtros.md`, `paginacion.md`, `pedidos-campos.md` | `plan.md` (reemplaza los documentos sueltos) | Un único plan de trabajo por fases |
| `Dockerfile`, `deployment/`, `netlify.toml`, `deploy.sh`, `setup-dev-env.sh` | — | *No se copian:* no se pidió despliegue |
| `backup-images/`, `backup-images.sh`, `restore-images.sh` | — | *No se copian:* canastillas tenía imágenes externas; aquí las imágenes subidas viven en `public/image/` |
| `imagenes/` | `public/image/` | Carpeta pública única para subidas (Intervention Image) |

---

## 3. Base de datos

| Aspecto | canastillasWep | cotizaciones |
|---|---|---|
| Nombre | `canastillas` | **`cotizaciones`** (independiente) |
| Entregable | Sólo `mysql/canastillas.mwb` | **`mysql/cotizaciones.sql`** con 8 tablas, claves foráneas, índices y datos semilla |
| Tablas | usuarios, productos, pedidos, reportes… (según el .mwb) | `usuarios`, `empresas`, `clientes`, `productos`, `precios_cliente`, `cotizaciones`, `cotizacion_detalles`, `notas` |
| Múltiples negocios | No | **Sí:** `empresas` con los dos negocios y `productos.empresa_id` / `cotizaciones.empresa_id` |
| Clientes | No existían como ficha | **Nueva tabla `clientes`** con nombre, empresa, alias, RFC, teléfono, email, ubicación, **VIP** y descuento global. *No inician sesión.* |
| Motor de precios | Precio único en producto | **`productos.precio` = precio de lista (fijo)** + **`precios_cliente`** con el precio acordado por cliente y producto |
| Cotización normalizada | Pedido en una tabla | Cabecera (`cotizaciones`) + partidas (`cotizacion_detalles`) con snapshot de precio |
| Bitácora personal | — | **Nueva tabla `notas`** (sección "Notas Personales" del diseño) |
| Sesión de usuarios | `rol` con admin/cliente/proveedor | Sólo `rol = 1` (admin) inicia sesión; "cliente" es una ficha, no un login |

---

## 4. Backend, rutas y seguridad

| Aspecto | canastillasWep | cotizaciones |
|---|---|---|
| Rutas declaradas | 36 | **56**: los 4 CRUD + Notas + Cotizaciones (alta, edición, detalle, estado, eliminar, PDF, envío y precios del cliente) |
| Protección | `soloAdmin`, `soloCliente`, `soloProveedor` | **`soloAdmin` en TODA ruta bajo `/admin`**, comprobado además **dentro de `Router.php`** (un 404 bajo `/admin` también exige sesión) |
| Registro | — | **`soloRegistroAbierto`**: registro **cerrado por defecto**; se habilita con `REGISTRO_ABIERTO=1`. Motivo: en OPENCODE "los usuarios que se registren serán admin" |
| Consultas SQL | Concatenación + `real_escape_string` | **Sentencias preparadas** (`prepare` / `bind_param`) en los CRUD nuevos |
| Errores mysqli | Se propagan (PHP 8.1 lanza excepción) | `try/catch` sobre `mysqli_sql_exception` + `error_log`, con mensaje amable al usuario |
| Registro inexistente | — | `paginaNoEncontrada()`: cuando la ruta existe pero el id no, muestra la vista 404 (**idéntico para todos los módulos, incluidos detalle/editar/PDF de cotizaciones**) |
| Alertas flash | — | `agregarAlerta()` / `obtenerAlertas()` + `redirigir($ruta, $tipo, $texto)` para confirmar acciones tras un POST |
| Variables de entorno | variables del servidor | **`config/entorno.php`** carga un `.env` (idempotente y **sin pisar** el entorno real) y alimenta `getenv()` en BD y correo |

---

## 5. Plantillas (`templates/`)

| canastillasWep | cotizaciones | Motivo |
|---|---|---|
| `headerHTML.php` | **`header.php`** | Abre documento, sidebar y barra superior; recibe `titulo`, `subtitulo`, `acciones`, `mostrarSidebar` |
| `footer.php` | **`footer.php`** | Cierra el panel e incluye el modal y la barra inferior |
| `paginacion.php` | **`paginacion.php`** | Ahora respeta los parámetros de búsqueda al paginar (`?q=…&pagina=2`) |
| `formularioUsuarios.php` | **`formularioUsuarios.php`** | Contraseña opcional en edición + rol informativo |
| `formularioCrearProductos.php` | **`formularioProductos.php`** | + `input type="file"` con vista previa y `enctype="multipart/form-data"` |
| `formularioPedidos.php` | **`formularioCotizaciones.php`** | Encabezado, catálogo visual, renglones, términos y resumen; **una sola plantilla para alta y edición** |
| — | **`pdfCotizaciones.php`** | Documento HTML que alimenta el PDF: tablas e imágenes en base64 (dompdf no necesita red ni disco) |
| `formularioReportes.php` | — | *No aplica:* no hay módulo de reportes |
| `infoPedidos.php`, `entregas.php` | — | *No aplica:* eran vistas de la app anterior |
| — | **`sidebar.php`** | Navegación lateral; **el estado activo se calcula desde la URL**, no desde cada vista |
| — | **`bottomnav.php`** | Barra inferior de móvil (sólo iconos, estilo Facebook) con 6 destinos |
| — | **`alertas.php`** | Toasts visuales que sustituyen a `alert()` |
| — | **`modal.php`** | **Modal único** de la app: todos los diálogos usan este nodo para tener el mismo diseño |
| — | **`formularioClientes.php`** | Alta y edición de clientes |
| — | **`formularioEmpresas.php`** | Datos fiscales (RFC, régimen, domicilio, CP) + logo + condiciones |
| — | **`formularioNotas.php`** | Alta y edición de notas personales |

---

## 6. JavaScript

| canastillasWep | cotizaciones |
|---|---|
| Un solo archivo `src/js/app.js` | `src/js/app.js` (arranque) + **`src/js/modulos/`**: `alertas.js`, `modal.js`, `validacion.js`, `filePreview.js`, **`cotizador.js`** |
| Sin módulos de confirmación | **`abrirModal()` / `confirmarAccion()`**: reemplazan a `window.confirm()` con el mismo diseño que el resto de ventanas |
| — | Validación visual bajo cada campo (`.campo__error`) con `preventDefault()` en todo `submit` |
| — | Vista previa de imagen con `FileReader` + validación de tipo y tamaño (6 MB, el mismo tope que `Imagenes::TAMANO_MAXIMO`) |
| — | **`cotizador.js`**: filtra el catálogo, arma los renglones con `createElement`, recalcula el resumen en vivo y trae los precios del cliente con `fetch` (**JSON puro, nunca HTML**) |
| 1 uso de `var` | **0** (`let` / `const` exclusivamente) |

Reglas verificadas en el proyecto nuevo: `var` = 0 · `innerHTML` = 0 (sólo aparece en comentarios) ·
`alert()/confirm()/prompt()` = 0 (sólo en comentarios) · `preventDefault()` en todos los `submit` y `click`.

---

## 7. Estilos (SCSS)

| canastillasWep | cotizaciones |
|---|---|
| `_globales.scss`, `_pruebas.scss` | Reemplazados por `_tipografia.scss` y `_utilidades.scss` (sin hojas de prueba) |
| `_navbar.scss` (menú superior público) | **`_sidebar.scss`** + **`_bottomnav.scss`** (menú lateral + barra inferior móvil) |
| Estilos de página pública (`_nosotros`, `_contacto`, `_video`, `_novedades`, `_cobertura`, `_recibo`…) | Eliminados: **la nueva app no tiene sitio público**, sólo login/registro y panel |
| — | `_cards.scss`, `_badges.scss`, `_modal.scss`, `_auth.scss`, `_notas.scss`, **`_cotizador.scss`** (nuevos) |
| Paleta de canastillas | **Azul acero `#5A92C3` / `#4679A6` / `#EAF1F8`** tomado de `design/vanguard_commerce/DESIGN.md` y de las maquetas renderizadas |
| `build` sólo hacía `watch` | **`npm run build` compila de verdad** `css` y `js` (el `gulpfile.js` original no compilaba) |

**Uso compartido de estilos:** todas las alertas, botones, tablas, formularios, badges y
**todas las ventanas modales** pasan a compartir los mismos parámetros/estado visual.

---

## 8. Funcionalidad nueva (no existía en canastillasWep)

1. **Cotizador** — selector de cliente y de negocio, catálogo con imagen y cantidad, precio de
   lista **de solo lectura** + precio editable por línea con el checkbox *"No recordar este precio"*,
   descuento global del cliente, **IVA 16 % opcional**, condiciones de envío/compra y textarea de
   comentarios, resumen con totales y folio automático.
2. **Dos negocios** — `empresas` con RFC, régimen, domicilio, CP y logo rellenables desde el CRUD.
3. **Clientes con VIP** — ficha mínima (nombre, RFC, teléfono, email, ubicación, VIP) más empresa,
   alias, descuento global y notas; histórico de cotizaciones.
4. **Notas Personales** — bitácora interna privada por usuario: categorías (recordatorio, acuerdo de
   precios, tarea, general), fijado, búsqueda, filtros, referencia asociada y acciones
   (editar / fijar / marcar hecha / eliminar).
5. **Salida de la cotización** — envío por correo (**PHPMailer**) y exportación a **PDF (dompdf)**.
6. **Registro de pedidos por cliente** — histórico con filtro y paginación.
7. **Imágenes de producto** — sólo `input type="file"` + **Intervention Image** en formato cuadrado
   (1000×1000) con miniatura 300×300. *Las URLs externas de las maquetas no se copian.*
8. **Navegación móvil** — barra inferior fija con iconos y cajón lateral que **muestra el nombre de
   cada sección junto a su icono**; desplazamiento horizontal cuando algo no cabe en la línea.
9. **Salida de la cotización** — `App\Documentos` genera el PDF con **dompdf** desde
   `templates/pdfCotizaciones.php` y `App\Correos` lo adjunta con **PHPMailer**. En desarrollo
   (`CORREO_MODO=log`) el mensaje no se envía: queda en `storage/correos.log` y la pantalla lo
   advierte con una alerta de advertencia para que no se confunda con una entrega real.
10. **Historial de cotizaciones** — filtro por folio/cliente/alias/RFC, negocio, cliente, estado y
    rango de fechas, con estadísticas, paginación, botón **Buscar** y ayuda **Limpiar**.
11. **Folio robusto** — `siguienteFolio()` usa el **máximo numérico** de los folios del año, no un
    `COUNT(*)`: si se borra una cotización, un conteo dejaría de coincidir con lo realmente emitido
    y la siguiente alta intentaría reutilizar un folio ya ocupado.

---

## 9. Dependencias

| | canastillasWep | cotizaciones |
|---|---|---|
| Composer | `intervention/image ^3.11` | `intervention/image ^3.11` · **`dompdf ^3.1.6`** · **`phpmailer ^6.12`** |
| npm | gulp, gulp-sass, sass | iguales (mismo `gulpfile` corregido) |

---

## 10. Resumen en una tabla

| | canastillasWep | cotizaciones |
|---|---|---|
| Base de datos | `canastillas` (sin SQL en repo) | `cotizaciones` + `cotizaciones.sql` (8 tablas) |
| Entidades | usuarios, productos, pedidos, reportes | usuarios, empresas, **clientes**, productos, precios_cliente, cotizaciones, cotizacion_detalles, **notas** |
| Roles | admin, cliente, proveedor | sólo admin inicia sesión |
| SQL | concatenado | **preparado** |
| Feedback | alertas visuales | alertas + **modal único** con `confirmarAccion()` |
| JS | 1 archivo | **5 archivos** (app + 4 módulos) |
| CSS | sitio público + admin | **sólo app de cotizaciones**, paleta azul acero |
| Móvil | responsive básico | **barra inferior de iconos + cajón con nombres** |
| Nada en común | `var` ×1 | `var` ×0 |

---

## 11. Ronda de pulido: fotos de perfil, bitácora, fieldset y móvil

### 11.1 Fotos de perfil de usuarios y clientes

| | Antes | Ahora |
|---|---|---|
| Columna | no existía | `usuarios.foto` y `clientes.foto` (`VARCHAR(150) NULL`) |
| Formato | — | cuadrado **500×500** recortado al centro (Intervention `cover`) |
| Visualización | — | círculo de **5rem (80 px)** con `object-fit: cover` |
| Sin foto | — | `public/image/perfilSinfoto.jpg` (500×500, silueta azul acero) |

- `Imagenes::subir()` admite el modo **`retrato`**; `Imagenes::retrato($archivo)` devuelve siempre
  la URL correcta (usa `RETRATO_POR_DEFECTO` cuando no hay foto), así que las vistas no comprueban
  el campo en ningún punto.
- `Imagenes::subirRetrato()` centraliza las tres situaciones de un formulario: archivo nuevo,
  casilla **"Quitar foto"** y ningún cambio. **No borra el archivo anterior**: eso lo hace la vista
  sólo cuando la escritura en base de datos ya fue exitosa (mismo criterio que el logo).
- La subida se hace **después** de `validar()`: un envío con errores ya no deja archivos huérfanos.
- `Usuarios::eliminar()` y `Clientes::eliminar()` borran la foto junto con el registro.
- Campo compartido en `templates/campoRetrato.php` (perfil, CRUD de usuarios y de clientes).
- Clases nuevas: `.retrato` / `.retrato--sm` y `.perfil-fila` en `_utilidades.scss`, más
  `.carga-imagen--retrato` (previsualización redonda) en `_formularios.scss`.
- Se muestra en: ficha de cliente, primera columna del directorio y perfil del administrador.

### 11.2 Categorías de la bitácora

`enum('general','tarea','acuerdo','recordatorio')` → **`enum('recordatorio','cotizar','cambios','revisar','otro')`**
(defecto `otro`). La migración se hizo con un puente a `VARCHAR` porque MySQL rechaza cambiar un
`ENUM` mientras haya filas con valores antiguos; los existentes se remapearon
(`acuerdo`→`cambios`, `tarea`→`revisar`, `general`→`otro`).

`Notas::CATEGORIAS` + `datosCategoria()` dan icono, etiqueta y color; la tercera tarjeta de
estadísticas pasó de "Acuerdos" a **"Por cotizar"** (`categoria = 'cotizar'`).

### 11.3 Fieldset del cotizador

- El `<legend>` **ya no se apoya en la línea superior**: flota dentro del recuadro
  (`float: left`), se alinea con el contenido (`margin-left: 0`, sin relleno ni fondo) y todos los
  hermanos hacen `clear: both`, de modo que el título queda **dentro** del campo con 16px de aire.
- `.tarjeta--sin-relleno .formulario__acciones` recupera el margen lateral que la tarjeta no daba
  (16px; en móvil el padding de la tarjeta lo aporta ella).

### 11.4 Móvil: barra inferior y cajón

**Causa raíz encontrada:** la tabla del cotizador (896px) estaba recortada por
`.tabla-contenedor { overflow-x: auto }`, pero al ser un contenedor *estático* su desbordamiento
se sumaba al del documento. En móvil el navegador ensanchaba el viewport de diseño de 390 a **918px**,
con lo que cualquier elemento fijo (`left: 0; right: 0`) medía 918px: la barra inferior mostraba
2,5 iconos de 6 y el botón "Más" (que abre el cajón) quedaba fuera de pantalla.

**Arreglo:** `position: relative` en `.tabla-contenedor` (hace de bloque contenedor) → las nueve
pantallas quedan en `innerWidth = doc = body = barra = 390`, con 6 ítems de 65px y
`overflow-x: hidden`. `overflow-x: hidden` en `html`/`body` **no** servía.

Además la barra pasó de `padding: 0 $espacio-xs` + `overflow-x: auto` a `padding: 0` +
`overflow-x: hidden` (el redondeo subpixel de 6 × `flex: 1 1 0` abría 2px de scroll).

### 11.5 Verificación automatizada

`/tmp/opencode/navegador/` (Playwright 1.49.1, sin navegador de sistema):

| Script | Resultado |
|---|---|
| `probar_filtros.mjs` | **41/41** — Buscar/Limpiar en las 6 listas, desplegables, chips de bitácora y estado vacío |
| `viewport.mjs` | **9/9** — sin desbordamiento horizontal, barra inferior de 390px |
| `revisar.mjs` | fieldset, botones de acción y cajón medidos y capturados |
| `php -l` | **70/70** archivos correctos |

---

## 12. Ronda: logo amplio, duplicar cotización y ganancias por negocio

### 12.1 Vista previa del logo de negocio (30rem)

El logo de las empresas se guardaba en un cuadro fijo de `5rem × 5rem` con `object-fit: cover`,
de modo que un logotipo anchado (como el de Canastillas de la Baja) se veía minúsculo.

- `templates/formularioEmpresas.php` añade la clase `carga-imagen__preview--amplia`.
- `_formularios.scss` la define como `width/height: auto`, `max-width: 30rem`, `max-height: 30rem`,
  `object-fit: contain` y `flex-shrink: 1`: se ve completo hasta 480px y, si la caja es más
  estrecha, encoge en vez de desbordar. El `max-height` protege la previsualización en vivo del
  navegador, que todavía muestra el archivo original sin recortar.

### 12.2 Botón "Duplicar" en el histórico de cotizaciones

- **Ruta nueva:** `POST /admin/cotizaciones/duplicar` → `views/admin/cotizaciones/duplicar.php`
  (sólo POST, con `soloAdmin`), registrada en `index.php`.
- La copia nace con **folio nuevo, fecha de hoy y estado "pendiente"** y conserva negocio emisor,
  cliente, vigencia, IVA, descuento global, condiciones comerciales y **todos los renglones**.
  Se redirige a `/admin/cotizaciones/editar?id=<nuevo>` para que sólo se ajusten productos o
  cantidades y se guarde.
- **Detalle clave:** `validar()` y `guardarDetalles()` leen la *clave* del arreglo de renglones como
  `producto_id`, y `lineasDesdeDetalles()` devuelve una lista numerada. Por eso los renglones se
  reindexan antes de usarlos; sin eso se habrían guardado productos con id 0, 1, 2…
- El botón convive con "Ver", "Editar" y "Eliminar" en `.tabla__acciones` (sin confirmación: no
  destruye nada).

### 12.3 Ganancias por negocio (debajo de "Accesos rápidos")

Sección nueva en `views/admin/index.php` con **un panel por negocio activo** (hoy: Soluciones en
Acero y Canastillas de la Baja), cada uno con su logo, la cifra de ganancias y cuatro datos de
contexto (en cartera, total cotizado, cotizaciones, canceladas).

- **"Ganancias" = suma del `total` de las cotizaciones con estado `vendido`.** Las pendientes se
  muestran aparte como "En cartera" para no confundir lo vendido con lo cotizado.
- **Filtros:** `Año` (los que ya tienen cotizaciones + el actual + "Todos los años"),
  `Semestre` (1.º ene–jun / 2.º jul–dic) y `Mes`, con "Buscar" visible y "Limpiar" cuando el
  periodo no es el por defecto. Prioridad **mes > semestre > año**; sin año, histórico completo.
- **Sin JavaScript no se puede mezclar:** `app.js` bloquea mes y semestre cuando el año está en
  "Todos los años" (y los deselecciona); el servidor los manda igualmente `disabled` al pintar.
- Métodos nuevos en `Cotizaciones`: `anios()`, `rangoPeriodo()` y `ganancias()`; constantes
  `MESES`. Estilos nuevos `.ganancia{__logo,__etiqueta,__valor,__nota,__rejilla,__dato}` en
  `_cards.scss`.

### 12.4 Corrección: porcentaje de descuento global

Al abrir `/admin/cotizaciones/editar` la cotización FOL-2026-0001 mostraba **11,11 %** cuando el
descuento real era **10,00 %** (así lo decían el detalle y el PDF). Guardar sin tocar nada
habría cambiado el total de $3,946.32 a $3,897.65.

- **Causa:** la función local `descuentoGlobalDe()` dividía `descuento / (subtotal − descuento)`,
  es decir, calculaba el porcentaje sobre la base **ya descontada**, cuando `calcular()` lo aplica
  sobre `subtotal − descuentoDeLosRenglones`.
- **Arreglo:** nuevo `Cotizaciones::desgloseDescuento($cotizacion, $detalles)`, única fuente de
  verdad, que devuelve `renglones`, `global`, `base` y `pct`. Ya la usan `editar.php`,
  `duplicar.php`, `detalle.php` y `templates/pdfCotizaciones.php` (los dos últimos antes tenían
  el mismo bloque copiado a mano).

### 12.5 Verificación

| Comprobación | Resultado |
|---|---|
| `probar_panel.mjs` | **15/15** — logo 30rem, filtros de periodo, botón Duplicar |
| `probar_filtros.mjs` | **41/41** — los filtros existentes siguen intactos |
| `viewport.mjs` | **9/9** — sin desbordamiento horizontal (incluye el panel nuevo) |
| Duplicar → editar → guardar | copia idéntica ($3,946.32); con cantidad 5→10 los totales recalcularon $5,825.52 |
| `php -l` | **71/71** archivos correctos |
| PDF | 1 página, "Descuento global (10.00%)" |

Las cotizaciones de prueba creadas durante la verificación se eliminaron por id: la base sigue
con sus **4 cotizaciones** originales.

---

## 13. Ronda: encabezados centrados, Ventas, Inventario y Proveedores

### 13.1 Encabezados de tabla centrados (todas las columnas)

- `_tablas.scss` → `.tabla thead th { text-align: center; }`. La regla se aplica **antes** de la
  alineación a derecha (`.tabla--numerica`), que sólo afecta a las **celdas de datos**: los números
  siguen alineados a la derecha y sus títulos quedan centrados, como se pidió.
- Verificado por computado (`getComputedStyle(th).textAlign === 'center'`) en las 12 columnas de
  Inventario, 7 de Histórico, 6 de Productos/Ventas/Proveedores/Entradas.

### 13.2 Menú lateral: divisoria + tres secciones nuevas

- `templates/sidebar.php` documenta la bandera **`divisor`**: dibuja `<hr class="sidebar__divisor">`
  justo antes del enlace que la declara. Hoy sólo la declara **Ventas**, así que la línea separa
  *Operación* de *Ventas / Inventario / Proveedores*.
- Enlaces nuevos: **Ventas** (`point_of_sale`), **Inventario** (`warehouse`) y **Proveedores**
  (`local_shipping`), todos con `subrutas => true` y `soloEscritorio => false`: aparecen tanto en el
  escritorio como en el cajón de móvil **con su nombre junto al icono**. La barra inferior conserva
  sus **6 accesos** originales (las tres secciones nuevas no se repiten ahí).
- `_sidebar.scss` → `.sidebar__divisor`: `border-top`, `margin: 0.5rem 0`, `flex-shrink: 0`.

### 13.3 Base de datos

Migración aplicada en `mysql/migracion_ventas_inventario.sql` (respaldo previo en
`/tmp/opencode/backups/cotizaciones-antes-ventas-inventario.sql`) y reflejada en
`mysql/cotizaciones.sql` para instalaciones nuevas.

| Cambio | Detalle |
|---|---|
| `productos.stock` | `INT NOT NULL DEFAULT 0` |
| `movimientos_inventario` | bitácora: `producto_id`, `tipo` (`entrada`/`salida`/`ajuste`), `cantidad`, `saldo`, `cotizacion_id`, `usuario_id`, `notas` |
| `ventas` | **UNIQUE `cotizacion_id`** (máx. una venta por cotización), `estado_previo ENUM`, `fecha`, `ubicacion`, `ubicacion_otra`, `importe`, `foto`, `notas` |
| `proveedores` | directorio: `nombre`, `rfc`, `telefono`, `ubicacion`, `tipo_proveedor` |
| `proveedor_entradas` | registros de recepción con `fecha`, `numero_pedido`, `categoria`, `descripcion`, `foto`, `notas` y FK a `proveedores` (borrado en cascada) |

Las cuatro tablas nuevas se registran en `$clasesConectadas` (`includes/app.php`).

### 13.4 Motor de inventario (`clases/Inventario.php`)

- `stock()`, `faltantes($cotizacionId)` (devuelve `requerido` / `disponible` / `faltante` por
  producto) y `cantidadesAgrupadas()`.
- Movimientos privados `mover()` → `descontar()` / `devolver()`; **`ajustar()`** para la carga
  manual (escribe la bitácora con notas *"Carga manual desde Inventario"*).
- Listado paginado con filtros (`listar()` / `condiciones()` / `contar()`), `movimientos()` para la
  historial y **`paraPdf()`**, que agrupa por empresa y calcula `piezas` e `importe`.
- Constantes `TIPOS` (`entrada` / `salida` / `ajuste`).

Las **únicas dos fuentes** de cambio de stock son la carga manual y la venta: los proveedores **no**
mueven inventario (registran entradas de material como reporte).

### 13.5 Ventas (`clases/Ventas.php`)

- `UBICACIONES` (Loreto, Cd. Constitución, La Paz, Todos Santos, Barriles, Pescadero, Cabo San
  Lucas, San José del Cabo, Otro — "Otro" guarda el texto libre en `ubicacion_otra`).
- **`crearDesdeCotizacion()`** corre en **una sola transacción**: `UPDATE cotizaciones SET estado =
  'vendido'` + descuento de existencias + `INSERT` de la venta; si algo falla se revierte todo.
  También valida que la cotización **no tenga ya una venta**.
- **`eliminar()`** revierte: devuelve las piezas al almacén y regresa la cotización a
  `estado_previo`. El modal de confirmación advierte exactamente eso.
- Consultas `listar()`, `contar()`, `condiciones()`, `buscarPorCotizacion()`,
  `cotizacionesDisponibles()` y `ubicacionCompleta()`.

### 13.6 Estado de la cotización ligado al stock (`clases/Cotizaciones.php`)

- **`aplicarEstadoEnTransaccion($id, $estado, $usuarioId, $pdo)`**: con `SELECT … FOR UPDATE`,
  sólo actúa en transiciones reales (`pendiente → vendido` descuenta; `vendido → pendiente`
  devuelve) y evita el doble descuento.
- **`cambiarEstado()`** envuelve esa lógica en transacción y queda como puerta de los botones de
  estado del detalle.
- **`crear()`** descuenta si la cotización nace `vendido`; **`actualizar()`** resincroniza el stock
  según el estado anterior y el nuevo; **`eliminar()`** devuelve las piezas antes de borrar (y el
  modal avisa que "el inventario devolverá las piezas descontadas").

### 13.7 Inventario: pantalla y PDF

| Pieza | Contenido |
|---|---|
| `views/admin/inventario/index.php` | lista con miniatura, empresa, categoría, precio y **cuadro de cantidad** (`input[type=number]`) que sube o baja y guarda por producto; botón **Exportar Inventario** |
| `views/admin/inventario/{ajustar,pdf}.php` | carga manual con nota de bitácora · descarga del PDF |
| `templates/pdfInventario.php` + `Documentos::{htmlInventario,pdfInventario,descargarInventario}` | **una tabla por empresa** con encabezado (fecha de exportación, logo, nombre), columnas **Código · Producto · Precio de lista · Cantidad en inventario · Monto total en inventario** y fila de totales (`piezas` e `importe`) por negocio |
| `clases/Imagenes.php` | modo **`informe`** (`scaleDown(1600,1600)`, sin recorte ni reescalado) para las fotos de proveedor |

### 13.8 Proveedores y reporte fotográfico

- `views/admin/proveedores/{index,crear,editar,detalle,eliminar}.php` con
  `templates/formularioProveedores.php`.
- `views/admin/proveedores/entradas/{index,crear,editar,detalle,eliminar}.php` con
  `templates/formularioProveedorEntradas.php` y selector de proveedor.
- **`entradas/detalle.php` es el reporte**: encabezado (Nombre, RFC, Teléfono, Ubicación, Tipo de
  proveedor, Número de pedido, Categoría) + sección *Descripción* + *Foto de entrega del proveedor*
  + *Notas adicionales*, con la clase `.foto-acta` (y `--vacia` cuando no hay imagen).

### 13.9 Integración en el detalle de la cotización

- `Inventario::faltantes($id)` se consulta al abrir el detalle: si no alcanza el almacén se muestra
  siempre la nota `nota--advertencia` con el detalle («X pide 5 y hay 0 (faltan 5)») y el botón
  **Vendido** lleva `data-confirmacion-*`: el **modal** explica los faltantes y permite continuar
  (el inventario queda en negativo). Nunca se bloquea y nunca se usa `window.confirm`.
- Botón **"Registrar venta"** → `/admin/ventas/crear?cotizacion_id=N`; si ya existe la venta se
  muestra **"Ver venta"**.
- Plantillas nuevas: `templates/campoFoto.php` (foto de acta reutilizable),
  `formularioVentas.php`.

### 13.10 Rutas nuevas (`index.php`, todas `soloAdmin`)

```
/admin/inventario  /admin/inventario/ajustar  /admin/inventario/pdf
/admin/ventas      /admin/ventas/crear        /admin/ventas/editar
/admin/ventas/detalle                          /admin/ventas/eliminar
/admin/proveedores /admin/proveedores/crear   /admin/proveedores/editar
/admin/proveedores/detalle                     /admin/proveedores/eliminar
/admin/proveedores/entradas[/crear|/editar|/detalle|/eliminar]
```

### 13.11 JavaScript y estilos

- `src/js/app.js` → paso 8: al elegir *"Otro"* en la ubicación de la venta se abre
  `#campo-ubicacion-otra` (`data-ubicacion`).
- Estilos nuevos: `.sidebar__divisor`, `.campo--oculto`, `.inventario-cantidad{__campo,__campo--vacio}`,
  `.lista-simple`, `.foto-acta{--vacia}` y `th` centrados.

### 13.12 Verificación

| Comprobación | Resultado |
|---|---|
| `probar_stock.mjs` | **64/64** — divisoria, rutas nuevas, `th` centrados, carga manual + bitácora, PDF de inventario (totales por empresa cuadrados con la BD), modal de faltantes, venta atómica, 1 venta por cotización, reversión al borrar, alta de proveedor y reporte de entradas |
| `probar_cajon.mjs` | **21/21** — cajón móvil con los tres nombres visibles, divisoria antes de Ventas, bottomnav intacta (6), resaltado de sección y sin desborde a 390 px |
| `probar_filtros.mjs` | **41/41** (se hizo adaptable a los datos del día) |
| `probar_panel.mjs` | **15/15** (el contador de cotizaciones ya no está fijo) |
| `viewport.mjs` | **9/9** — sin desbordamiento horizontal |
| `php -l` | **98/98** archivos correctos · `node --check app.js` correcto · `gulp build` correcto |
| PDF de inventario | `pdfinfo`/`pdftotext`: encabezado con fecha y logo, una tabla por negocio y fila "Totales de …" con piezas e importe |

La verificación crea una cotización duplicada, la venta y el proveedor de prueba, y **los borra al
terminar** (restaurando stocks y bitácora): la base de datos queda exactamente como estaba.

---

## 14. Ronda: catálogo a PDF, logo ×2 y balance de proveedores

### 14.1 Exportar catálogo de productos

Botón **"Exportar catálogo de productos"** junto a *"Nuevo producto"* en
`/admin/productos`. Abre en pestaña nueva y genera **un solo PDF con una tabla
por negocio**.

| Archivo | Qué hace |
|---|---|
| `views/admin/productos/index.php` | El botón, dentro de `acciones` del encabezado (a la izquierda de *"Nuevo producto"*) |
| `GET /admin/productos/pdf` | Ruta nueva en `index.php` |
| `views/admin/productos/pdf.php` | Sólo llama a `Documentos::descargarCatalogo()` |
| `Documentos::htmlCatalogo()`, `pdfCatalogo()`, `descargarCatalogo()` | Composición, render con dompdf y envío al navegador |
| `Productos::paraPdf()` | Filas agrupadas por empresa |
| `templates/pdfCatalogo.php` | El documento HTML |

- **Columnas en horizontal:** `Imagen · Clave · Nombre · Costo`.
- **Leyenda al pie:** *"Los precios no incluyen iva (16%), las ventas son con
  pago de contado."*
- **Totales por negocio:** número de productos y suma de costos de esa empresa.
- **Las imágenes van en base64** (miniaturas 300×300): dompdf trabaja con
  `isRemoteEnabled = false`, así que nada sale a Internet.
- **No respeta los filtros de la pantalla:** el botón vive en la barra de
  acciones, fuera del formulario de búsqueda, y un catálogo es la oferta
  completa del negocio (incluidos agotados y dados de baja).

### 14.2 Logo del detalle y del PDF a doble tamaño

| Dónde | Antes (×1.6) | Ahora (×2) |
|---|---|---|
| `src/scss/layout/_cotizador.scss` | `14.4rem × 6.4rem` | `28.8rem × 12.8rem` |
| `templates/pdfCotizaciones.php` | `86.4px × 304px` | `172.8px × 608px` |

- Se agregó **`max-width: 100%`**: sin él, la caja de `28.8rem` desborda el
  viewport a 390 px y el menú inferior mide más que la pantalla.
- Medido: caja en pantalla **460.8 × 204.8 px**; logo dibujado en el PDF
  **129.6 pt** = 2 × los 64.8 pt anteriores (`pdfimages -list`, miniatura
  300 px con `x-ppi` 167).
- La cotización sigue saliendo en **1 página**.

### 14.3 `monto` y `pagado` en `proveedor_entradas`

```sql
ALTER TABLE proveedor_entradas
    ADD COLUMN monto  DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER categoria,
    ADD COLUMN pagado TINYINT(1)    NOT NULL DEFAULT 0    AFTER monto;
```

- **Archivo:** `mysql/migracion_entradas_monto.sql` (idempotente: sólo añade la
  columna si no existe) y el esquema regenerado en `mysql/cotizaciones.sql`.
- **`monto NOT NULL DEFAULT 0`:** así `SUM()` no necesita `COALESCE` y las
  entradas viejas cuentan como `$0.00` en vez de romper la suma.
- **`pagado` arranca en 0:** por defecto todo está *por pagar*. Asumir lo
  contrario haría que el contador mintiera hacia abajo.
- `ProveedorEntradas` suma `monto` y `pagado` a `$columnasDB`, al constructor
  (con `(float)` y `0/1`, porque `''` rompería una columna `DECIMAL` estricta)
  y a `validar()` (`El monto no puede ser negativo`).
- El formulario (`templates/formularioProveedorEntradas.php`) gana el campo
  **Monto** (`type="number" min="0" step="0.01"`).

### 14.4 Sección "Balance general" (`/admin/proveedores/detalle`)

Nueva tarjeta con las **entregas del proveedor y su estado de pago**:

| Columna | Contenido |
|---|---|
| Número de pedido | `numero_pedido` |
| Categoría | secundaria (se oculta en móvil) |
| Monto | `formatoMoneda()` |
| Imagen de entrega | miniatura 3×3 rem **visible también en móvil** |
| Pago | botón **verde** *"Pagado"* / botón *"Pendiente"* + badge |
| Acciones | ver detalle · editar · eliminar (modal) |

- Cuadro superior `rejilla-stats` con **Entregas · Total · Pagado ·
  Pendiente por pagar**.
- **`ProveedorEntradas::balance($filtros)`** calcula los tres importes **en una
  sola consulta**: separados en queries distintos podrían no cuadrar entre sí.
- **`ProveedorEntradas::alternarPagado($id, $pagado)`** recibe el estado deseado
  (no un *toggle* ciego): dos pantallas abiertas a la vez no terminan en
  contrario.
- **Sin `confirm()`:** es una acción reversible, no destructiva. Sí exige **POST**
  (`/admin/proveedores/entradas/pago`), porque un GET jamás cambia datos.
- El campo `retorno` sólo acepta rutas internas (empiezan por `/`, sin `//`);
  cualquier otra cosa cae al detalle del proveedor. Evita *open redirect*.
- Paginación de 15 filas conservando `?id=`.

### 14.5 Contador de pendientes y columna Monto (`/admin/proveedores/entradas`)

- Badge **"Pendiente por pagar $X"** en la barra de filtros, envuelto en
  `.tabla-toolbar__resumen` (con `justify-content: space-between` cada badge se
  iría a un extremo distinto y dejarían un hueco enorme entre los dos).
- `ProveedorEntradas::sumarMonto($filtros, true)` suma sólo las filas con
  `pagado = 0`. **Respeta los filtros aplicados:** sin filtros es la deuda total;
  filtrando por proveedor, la de ese proveedor.
- Nueva columna **Monto** en la tabla, para que la suma se pueda verificar
  renglón por renglón.

### 14.6 Exportar datos: reporte anual de proveedores

Botón **"Exportar datos"** a la izquierda de *"Entradas"* en
`/admin/proveedores`. Genera **un solo PDF con las entradas de todos los
proveedores del año activo**.

| Archivo | Qué hace |
|---|---|
| `GET /admin/proveedores/pdf` | Ruta nueva |
| `views/admin/proveedores/pdf.php` | `Documentos::descargarBalanceProveedores((int) date('Y'))` |
| `ProveedorEntradas::paraPdf($anio)` | Una sección por proveedor, `WHERE YEAR(fecha) = ?` |
| `templates/pdfProveedores.php` | El documento HTML |

- Por proveedor: ficha (RFC, teléfono, ubicación, tipo) y tabla
  `Fecha · Número de pedido · Categoría · Monto · Estado`, con fila resaltada
  según esté pagado o no.
- Fila de totales por proveedor y **tabla consolidada** al final
  (Entregas · Total del año · Pagado · Pendiente por pagar): es el número que
  decide a quién se le paga primero.

### 14.7 Refactor: `ordenPorCodigo()` compartido

El `ORDER BY` natural por código **se movió de `Inventario` a `Productos`** y
pasó a `public static`. `Inventario` sólo lo delega.

**Por qué:** desde esta ronda hay dos documentos que ordenan por código (inventario
y catálogo). Si cada uno tuviera su propia copia de la expresión, encontrar un
código "funcionaría" de pantalla en pantalla.

### 14.8 Estilos nuevos

| Clase | Para qué |
|---|---|
| `.btn--exito` | Botón verde de *"Pagado"* (`$color-exito` → `$color-exito-texto` en hover) |
| `.tabla-toolbar__resumen` | Agrupador de contadores a la derecha de la barra de filtros |
| `.tabla__pago` | Indicador + botón apilados y centrados en la celda de pago |

### 14.9 Rutas nuevas

```
GET  /admin/productos/pdf
GET  /admin/proveedores/pdf
POST /admin/proveedores/entradas/pago
```

### 14.10 Verificación

| Comprobación | Resultado |
|---|---|
| `probar_ronda.mjs` | **39/39** — botones y su posición, logo ×2 en pantalla y en el PDF, Balance general (columnas, botones verdes, acciones), contador de pendientes, campo Monto, los dos PDF con su leyenda/columna, y sin desbordamiento horizontal en 7 pantallas a 1440 y 390 px |
| `php -l` | **103/103** archivos correctos |
| `gulp build` | correcto (SCSS compilado con `.btn--exito`, `.tabla__pago`, `.tabla-toolbar__resumen` y el logo ×2) |
| Regresión | `/admin/inventario` conserva el orden natural (`C-1, C-3, RA-7 \| BB-1, RA-1, RA-3, … TM-3` / `TM-4` en la página 2) y el PDF de inventario sigue respondiendo |

La prueba crea una cotización y una entrada, las mide y **las borra al terminar**:
la base de datos queda exactamente como estaba.

---

## 15. Ronda: correcciones del catálogo, logo adaptativo y balance con categorías

Ajustes sobre lo entregado en la ronda anterior, más dos mejoras de fondo en
Proveedores.

### 15.1 Catálogo de productos: sin suma y leyenda por empresa

Tres correcciones pedidas a la exportación (`templates/pdfCatalogo.php`):

| Pedido | Cómo se resolvió |
|---|---|
| *"quita la suma total de los productos"* | Se borró el `<tfoot>` con la fila *"N productos · Suma de costos de …"*, sus reglas CSS (`tr.total`) y el acumulador `'total'` de `Productos::paraPdf()`. El catálogo es una lista de precios para consultar, no un inventario valorizado. |
| *"la nota del IVA debe ser por cada empresa, no por cada PDF"* | La leyenda se movió del final del documento a **dentro de cada `.seccion-empresa`**, justo debajo de su tabla. Ahora son N leyendas (una por negocio activo) y el lector nunca tiene que adivinar a qué empresa aplica el pie de la última página. |
| *"que la tabla sea más compacta en vertical"* | La miniatura pasó de 40 a **30 px**, el relleno de `0.3` a **`0.12 rem`** y el interlineado de `1.3` a `1.2`; el encabezado bajó a `0.18 rem`. Cada renglón ocupa ~26 px en vez de ~51, es decir, casi el doble de productos por página. |

### 15.2 Logo: `max-height: 20rem` con medidas automáticas

Se sustituyó el tamaño fijo por un **tope** con ancho y alto `auto`, de modo
que la proporción natural del archivo decida el dibujo:

```scss
// src/scss/layout/_cotizador.scss
.cotizador-detalle__logo {
  max-height: 20rem;   // 320 px: sólo manda si el logo es más alto
  max-width: 100%;     // en móvil la caja nunca desborda el contenedor
  width: auto;
  height: auto;
  object-fit: contain;
}
```

```php
<!-- templates/pdfCotizaciones.php -->
.logo { max-height: 320px; max-width: 700px; ... }
```

* **Por qué `auto`**: con `width: 28.8rem` la caja quedaba más ancha que alta
  y el logo no crecía, sólo se recortaba con `object-fit`. Con `auto`, una
  marca apaisada se encoge sin deformarse y una vertical conserva su forma.
* **Por qué `max-width: 100%`**: sin él, un archivo de gran ancho
  desbordaría el contenedor, el viewport se ensancharía y la barra
  inferior mediría más que la pantalla.
* **Medida real**: el archivo de logo se guarda en **300×300 px**, así que
  el tope de 320 px no llega a aplicarse y el logo se dibuja a **300 px**
  (antes 204.8 px en pantalla y 172.8 px / 129.6 pt en el PDF). Para
  aprovechar los 320 px basta con subir un logo más grande: `max-height`
  ya está ahí esperándolo.

> **Actualizado en la ronda 16 (§16.5):** el tope bajó a `10rem` / `160px` en
> pantalla **y se igualó el PDF** (`320px` → `160px`). Las cifras de arriba
> documentan lo que se entregó en esta ronda; el valor lo fija el usuario y la
> prueba lo lee de ambas fuentes, así que una discrepancia entre el `.scss` y la
> plantilla del PDF se detecta sola.

### 15.3 Balance general: estado visible antes y después de pagar

Antes sólo se marcaba *"Pagado"* cuando ya estaba pagado; la fila pendiente
sólo tenía el botón verde. Ahora **siempre hay un indicador** en la celda
*Pago*:

| Estado | Insignia | Botón |
|---|---|---|
| Sin pagar | `badge--pendiente` **Pendiente** | verde `btn--exito` **Pagado** (check_circle) |
| Pagado | `badge--aprobado` **Pagado** | secundario **Deshacer** (replay) |

### 15.4 Filtro por categoría en el Balance general

Nuevo formulario `GET` (con **Buscar** y **Limpiar**, como todo listado) en
la cabecera de la sección, sobre los indicadores.

* El parámetro `?categoria=N` entra en **el mismo arreglo** que alimenta
  `balance()` y `mostrarTodos()`, así que **la tabla y los cuatro números se
  reducen juntos**: si se filtrara sólo la lista, la suma diría una cosa y
  los renglones otra.
* Se conserva en la paginación y en el `retorno` del botón de pago
  (`$rutaBalance`), de modo que al pagar sigues en el filtro.
* Un id fuera de la lista se trata como *"sin filtro"*; una categoría sin
  pedidos muestra el aviso *"no tiene pedidos en la categoría …"* con
  enlace a **Ver todos**.

### 15.5 `proveedor_entradas.categoria` → entero con lista fija

```
0 Sin categoría · 1 Acero · 2 Canastillas · 3 Empaque
4 Mano de obra  · 5 Servicio · 6 Otro
```

La lista vive en `ProveedorEntradas::CATEGORIAS` (única fuente de verdad) y
se usa en el formulario, el filtro, las tablas, la ficha y los PDF.

> **Actualizado en la ronda 16:** esa constante ya no existe. El catálogo migró a
> la tabla `categorias_proveedor` con su CRUD en
> `/admin/proveedores/categorias` y su única fuente de verdad es
> `CategoriasProveedor::mapa()` (ver §16).

* **Migración** `mysql/migracion_entradas_categoria.sql` (idempotente: sólo
  convierte si la columna todavía es `VARCHAR`, así que una segunda pasada
  jamás pone en 0 las categorías ya elegidas). El esquema completo quedó
  actualizado en `mysql/cotizaciones.sql`.
* **Por qué entero**: un campo libre produce *"acero"*, *"Acero"* y *"mat."*
  como tres categorías distintas; con el id, filtrar es `categoria = 1` en
  vez de `LIKE '%acero%'`, y renombrar una categoría es un cambio en un
  arreglo, no un `UPDATE` de cientos de filas.
* **Búsqueda**: como en la tabla ya no está el texto, `q` compara contra
  `idPorNombre()` (escribe *"Acero"* y se busca por id) o contra el número
  si el término es numérico. Un texto que no corresponde a ninguna categoría
  no devuelve filas inventadas.
* **Validación**: `categoria` debe ser 0 o un id de la lista; lo que esté
  fuera produce el error *"La categoría seleccionada no es válida."* y no
  se da de alta.

> **Dato heredado**: las entradas que tenían texto libre (*"Fronteras"*,
> *"Registros"*) quedaron en `0 = Sin categoría` porque esa información no
> cabe en un entero. Se reasignan con el nuevo desplegable.

### 15.6 Verificación

| Comprobación | Resultado |
|---|---|
| `probar_ronda2.mjs` | **57/57** — catálogo sin suma y con una leyenda por empresa, separación entre renglones < 30 pt, logo `max-height: 320px` y cuadrado en pantalla, 225 pt en el PDF, el PDF sigue en 1 página, insignia *Pendiente/Pagado*, contador *"1 pedido pendiente"*, filtro por categoría (tabla + indicadores + select + vacío), búsqueda por nombre de categoría, columna `TINYINT`, select de 7 opciones, alta inválida rechazada, sin desbordamiento a 1440 y 390 px |
| `php -l` | **103/103** archivos correctos |
| `gulp build` | correcto (logo con `max-height: 20rem`) |
| Regresión | `/admin/proveedores/pdf`, `/admin/inventario/pdf` y `/admin/productos/pdf` siguen respondiendo |
| Limpieza | la prueba crea su **propia** cuenta de usuario, su proveedor y sus entradas, y los borra al final (el proveedor arrastra las entradas por FK en cascada) |

---

## 16. Ronda: CRUD de categorías de proveedores

Pedido exacto: *"genera un crud para las categorias de proveedores para poder
seleccionar. En /admin/proveedores un boton de lado izq que diga Crear Categorias y
dentro de categorias el poder crear las categorias. Estas categorias seran
disponibles para todos los proveedores."*

Antes de tocar código se aclaró la duda de fondo: se refiere a las **categorías de
las entradas** (la lista fija `1 Acero … 6 Otro` de la ronda anterior, que es el
único campo rotulado «Categoría» de la app), no al `ENUM tipo_proveedor` del
directorio. Y se confirma lo que venía implícito en el pedido: **un catálogo único,
compartido por todos los proveedores** — lo que se clasifica es el tipo de compra,
concepto de la empresa, no de quien surte.

### 16.1 Base de datos: de constante a tabla

| Pieza | Detalle |
|---|---|
| Tabla nueva | `categorias_proveedor(id TINYINT UNSIGNED, nombre VARCHAR(80) UNIQUE, creado_en)` |
| Siembra | ids **1–6 explícitos** con la lista existente, **sólo si la tabla está vacía** |
| Esquema | `mysql/cotizaciones.sql` (+13.ª tabla y línea de migración en la cabecera) |
| Migración | `mysql/migracion_categorias_proveedor.sql`, idempotente (`CREATE TABLE IF NOT EXISTS` + procedimiento guardado que sólo siembra con 0 filas) |
| `proveedor_entradas.categoria` | sigue siendo `TINYINT … DEFAULT 0`, ahora documentado como `Id en categorias_proveedor` |

* **Por qué ids explícitos**: cambiarlos reescribiría el significado de las
  entradas ya capturadas. MySQL deja el `auto_increment` en 7 solo, así que la
  próxima categoría creada desde el CRUD no choca con la siembra.
* **Por qué NO hay clave foránea** (decisión, no olvido): `categoria` admite el
  `0` = «Sin categoría» y el 0 no es una fila de la tabla (el auto_increment
  arranca en 1), así que la FK rechazaría toda entrada sin clasificar. La misma
  protección está en PHP: `CategoriasProveedor::eliminar()` cuenta el uso antes de
  borrar y devuelve un error explicativo. Se pierde sólo la garantía en una carrera
  entre dos administradores en la misma décima de segundo, riesgo asumible frente
  al coste de reescribir la lógica de `0` como `NULL`.

### 16.2 Modelo: `clases/CategoriasProveedor.php`

Nueva clase con el esqueleto estándar (`setDB`, `atributos`, `crear`, `actualizar`,
`eliminar`, `buscarID`, `mostrarTodos`, `contarTodos`, `validar`, `getErrores`) más
cuatro piezas propias:

| Método | Por qué existe |
|---|---|
| `mapa(): array` | **Única fuente de verdad** `id => nombre`. Cacheado en un `static` por petición: el Balance, la tabla de entradas y la ficha lo piden varias veces y un N+1 aquí sería gratuito. Se anula al crear, actualizar o eliminar. |
| `nombre(int $id): string` | Un id desconocido (borrado en otra pestaña) cae en «Sin categoría» en vez de imprimir un número. |
| `idPorNombre(string $texto): int` | Traduce *"Acero"* a `categoria = 1` para la búsqueda del listado, que ya no puede comparar texto. |
| `enUso(int $id): int` | **La barrera de borrado**: sin clave foránea, esto es lo que impide dejar entradas apuntando a un id inexistente. |

`ProveedorEntradas` **perdió** `CATEGORIAS` y `SIN_CATEGORIA` y conserva sólo dos
delegaciones (`nombreCategoria()`, `idPorNombre()`), porque es la clase que guarda
el id y quien pinte una entrada no debería tener que adivinar qué clase consultar.
Las vistas que iteraban el arreglo ahora leen `CategoriasProveedor::mapa()`.

### 16.3 Rutas y vistas

Siete rutas nuevas en `index.php`, todas con `soloAdmin`:
`GET/POST /admin/proveedores/categorias`, `GET/POST …/crear`, `GET/POST …/editar` y
`POST …/eliminar` (sólo POST: un `<a>` de borrado se dispara con el prefetch).

| Archivo | Rol |
|---|---|
| `views/admin/proveedores/categorias/index.php` | tabla *Categoría · Entradas · Acciones* con buscador **Buscar/Limpiar**, badge de conteo y paginación |
| `…/crear.php` · `…/editar.php` | comparten `templates/formularioCategorias.php` (un solo campo) |
| `…/eliminar.php` | sólo POST; si está en uso, redirige con alerta de error |
| `views/admin/proveedores/index.php` | **«Crear Categorías» es el primer botón** de la barra de acciones (lado izquierdo), antes de *Exportar datos*, *Entradas* y *Nuevo proveedor* |

Tres decisiones de interfaz:

1. **El contador de uso es el enlace.** La columna *Entradas* de cada fila muestra
   cuántas veces se usa esa categoría **y** lleva a `/admin/proveedores/entradas?q=<nombre>`,
   que es exactamente la búsqueda por nombre que ya existía. Se responde "¿por qué
   no puedo borrarla?" con un clic.
2. **El modal dice si va a doler.** `data-confirmacion-mensaje` cambia según el
   uso: *"la usan N entradas, así que no se podrá eliminar hasta liberarlas"* o
   *"no está en uso, así que ninguna entrada se ve afectada"*. El botón sigue
   visible aunque esté en uso: el servidor tiene la última palabra y el motivo se
   entiende al intentarlo, no escondiéndolo.
3. **Renombrar sí, borrar no.** Renombrar sólo cambia el texto de una fila y las
   entradas conservan su id — ésa es la razón entera de guardar el id y no el texto.

### 16.4 Bug detectado al releer el propio código

`buscarPorNombre()` devuelve `0` cuando no encuentra nada, **no `null`**, y la
condición `if ($existe !== null && $existe !== $this->id)` hacía que **renombrar
una categoría sin cambiar el nombre** devolviera *"Ya existe otra categoría con ese
nombre"*: `0 !== 3` es `true`. Corregido a `$existe > 0 && $existe !== (int) $this->id`
y documentado en `cambios/cambios.md` §22.

### 16.5 Cambio del usuario durante la ronda

Mientras se trabajaba, el usuario bajó el tope del logo de la cotización de
`20rem` a **`10rem`** en `src/scss/layout/_cotizador.scss` (y recompiló). **No se
revierte**: es su decisión de diseño. Lo que sí cambió fue la prueba —
`probar_ronda2.mjs` tenía el `320px` escrito a mano y empezó a acusar fallos que no
existían. Ahora **lee el `max-height` de la fuente `.scss`**, comprueba que el CSS
compilado coincida (eso sí detecta un build viejo) y valida el efecto: dibujo a
`min(tamaño natural del archivo, tope)`. La misma suite dejó de reutilizar la
primera cotización del histórico (con los 3 renglones del usuario "1 página" no
significaba nada) y ahora siempre crea la suya de un renglón y la borra.

> **Decidido con el usuario al cerrar la ronda:** el tope bajó a `10rem` en
> pantalla **y se igualó el PDF** (`templates/pdfCotizaciones.php` pasa de `320px`
> a `160px`, el mismo número en px porque dompdf no resuelve el rem como el
> navegador). La prueba ahora lee el tope de **ambas** fuentes y comprueba que
> coincidan, así que basta con cambiar un archivo para que falle la otra pieza.

### 16.6 Verificación

| Comprobación | Resultado |
|---|---|
| `probar_categorias.mjs` (nuevo) | **55/55** — botón izquierdo, listado alfabético con uso, alta que llega al select de **todos** los proveedores, nombre repetido rechazado, HTML escapado, renombrar conserva el id, borrado en uso bloqueado con aviso, borrado libre permitido, búsqueda + *Limpiar*, estado vacío, `eliminar` que no acepta GET, sección activa y desbordamiento a 1440/390 px |
| `probar_ronda2.mjs` | **66/66** (antes 62; se añadieron la sincronía fuente↔build del logo, la igualdad de tope pantalla↔PDF y la comprobación de dibujo a `min(natural, tope)`) |
| `php -l` | **109/109** archivos correctos |
| `npx gulp build` | correcto |
| Regresión | `/admin/proveedores/pdf`, `/admin/inventario/pdf` y `/admin/productos/pdf` siguen respondiendo como PDF |
| Limpieza | ambas suites crean su **propia** cuenta, proveedor, entradas y cotización, y los borran en el orden correcto (usuario el **último**, por el `RESTRICT` de `usuario_id`) |
| Base después de las pruebas | 2 usuarios · 1 proveedor · 15 entradas · **6 categorías** · 1 cotización · 51 movimientos |

> **Dato pendiente de decisión:** las 15 entradas del cliente quedaron en
> `0 = Sin categoría` desde la migración anterior (sus textos libres no cabe en un
> entero). Si el usuario quiere, se pueden asignar masivamente a una categoría
> (por ejemplo *Acero*) con un solo `UPDATE`.

---

## 17. Ronda: revisión de diseño, interfaz y accesibilidad

### 17.1 Contraste WCAG AA: la paleta se rehace

Una auditoría automática recorriendo la cadena de fondos (no sólo el color plano)
encontró **888 fallos de contraste**. Casi todos venían de tres decisiones viejas:
el azul y el gris elegidos «a ojo» y el gris repetido dentro de los PDF.

| Token | Antes | Ahora | Contraste |
|---|---|---|---|
| `$color-primario` (relleno) | `#5a92c3` | **`#4679a6`** | blanco encima **4.6:1** (antes 3.3) |
| `$color-primario-oscuro` (hover) | `#4679a6` | **`#38648c`** | **6.2:1** |
| `$color-primario-texto` (nuevo) | — | **`#3d6d99`** | **5.5:1** |
| `$color-texto-secundario` | `#8c8c90` | **`#64646a`** | **5.9:1** (antes 3.4) |
| `$color-rust-texto` (nuevo) | `#b46424` | **`#9a5220`** sobre `#faede3` | **5.1:1** |

- **Nace el tercer azul:** el mismo tono no sirve igual como fondo (donde el texto
  es blanco) que como texto. Se separaron 21 usos `color: $color-primario` a
  `$color-primario-texto` con un patrón negativo lookbehind para no tocar
  `background-color` ni `border-color`.
- El **chip activo** reemplazó el texto azul por una píldora blanca; la flecha del
  `<select>` pasó a SVG codificado (una ligadura de Material Symbols no se podía
  colorear por CSS).
- Las **cuatro plantillas de PDF** usaban `#8c8c90`; dompdf no lee `app.css`, así
  que hay que tocarlas aparte.
- **Resultado: 888 → 0** (`pruebas/auditar_profundo.mjs`).

### 17.2 Encabezados: un solo `<h1>` y un `<title>` con sentido

- `templates/header.php` gana el parámetro **`$mostrarEncabezado`** (por defecto
  `true`). `clientes/detalle` y `cotizaciones/detalle` lo ponen en `false` y
  pintan el suyo: así el nombre del cliente deja de aparecer **dos veces** y
  queda exactamente un `<h1>` por página.
- Las **6 vistas `editar`** (clientes, usuarios, empresas, notas, productos,
  cotizaciones) perdieron su `<h1>` duplicado y se quedaron sólo con la
  descripción, igual que ya hacían las `crear`.
- El `<title>` de `cotizaciones/detalle` decía «Cotizaciones · Cotizaciones»:
  ahora es **`FOL-2026-0001 · Cotizaciones`**, y el título de la pestaña sale del
  mismo `$titulo`.
- En ese detalle se eliminó además el **segundo botón «Editar»** duplicado y se
  movió el «Volver al Histórico» junto a las demás acciones.

### 17.3 Tablas, campos y objetivos táctiles

- **`<caption class="solo-lectores">`** en las 3 tablas que faltaban (histórico,
  detalle de cotización y `#tabla-renglones`): **13/13**.
- **`<label for>` + `id`** en los 3 campos de solo lectura que no lo tenían
  (`rol-fijo`, `alta-fija`, `folio-cotizacion`).
- **Objetivos táctiles ≥ 24×24 px** (WCAG 2.5.8): `.alerta__cerrar` de 1.5 a
  **2rem** y `a.badge`/`button.badge` de 22px a **24px**. La regla quedó
  *fuera* del bloque `.badge` porque Sass rechaza `a&`.
- Las auditorías aprendieron las **excepciones de la norma** (controles nativos
  con etiqueta, enlaces en línea, texto con `aria-hidden`) y arreglaron un
  falso positivo en `esquema.mjs` que marcaba `campoFoto`/`campoRetrato`.

### 17.4 Enlace de salto y foco

- **«Saltar al contenido»** al principio del `<body>` (`.saltar-contenido`,
  `z-index: 100`) con destino `id="contenido-principal"` en ambos `<main>`: es el
  primer elemento con Tab y se ve sólo con foco.
- `focus-visible` verificado en 9 puntos del CSS compilado.

### 17.5 Las auditorías y suites se mudan a `pruebas/`

`playwright@1.56.1` pasa a *devDependency* del proyecto y **todo el material de
prueba vive ya en `pruebas/`**, no en `/tmp`:

| Comando | Qué ejecuta | Resultado |
|---|---|---|
| `npm run pruebas` | `probar_ronda` + `probar_ronda2` + `probar_categorias` | **46 + 67 + 56 = 169** |
| `npm run auditorias` | `auditar_ui` + `auditar_profundo` + `auditar_consola` | **0 hallazgos** en 36 y 26 pantallas |
| `node pruebas/esquema.mjs <url>` | árbol de encabezados, `title`, regiones, etiquetas | 1 `<h1>` y captions correctos |

- Los scripts se hicieron **portables**: `RAIZ` se calcula desde
  `import.meta.url` (ya no hay ruta absoluta escrita a mano) y los PDF
  temporales van al `tmpdir()` del sistema.
- **`probar_ronda.mjs`** dejó de esperar medidas escritas a mano: ahora lee el
  tope del logo de `src/scss/layout/_cotizador.scss` y de
  `templates/pdfCotizaciones.php`, comprueba que lo compilado le siga la pista y
  que la imagen dibujada no lo rebase. **Fue la corrección de esta ronda:** al
  oscurecer la paleta y cambiar el tope, las tres comprobaciones fijas dejaron de
  coincidir (28.8rem / 12.8rem / 129.6pt) y ahora detectan un *build* viejo en
  lugar de un cambio legítimo del usuario.
- **Y los datos que el usuario administra también se leen de la base.** Las
  suites nacieron con «6 categorías» y los ids `1`/`2` escritos a mano: al
  rehacerse el catálogo (10 categorías, ids 18–27) la de ronda2 rechazó las
  altas y la de categorías quedó a un paso de **borrar la «Acero» del usuario**,
  porque esperaba que el servidor se negara a un id que ya no estaba en uso.
  Ahora catálogos, recuentos y órdenes salen de `SELECT … ORDER BY nombre`, la
  categoría protegida es una que la propia prueba pone en uso antes de
  intentar borrarla, y la preparación limpia también los restos de corridas
  interrumpidas (cotizaciones y proveedores de la cuenta de prueba, que las FK
  `RESTRICT` bloqueaban).
- `probar_ronda.mjs` **ya no usa `admin@cotizaciones.local`** (que fue borrada en
  rondas anteriores): crea `prueba.ronda14@cotizaciones.local` y la elimina el
  último, después de su cotización, porque `usuario_id` es `RESTRICT`.

### 17.6 Documentación

Se crea **`cambios/documentacion.md`** (760 líneas, 19 secciones): qué es la app,
stack, montaje desde cero, `.env`, mapa de carpetas, ciclo de la petición, las 13
tablas, roles, guía módulo por módulo, reglas de negocio, PDF y correo, sistema de
diseño con ratios de contraste, accesibilidad, JavaScript, cómo ejecutar las
pruebas, despliegue, solución de problemas, glosario y documentación relacionada.
Se corrige además el credencial obsoleto que `plan.md` seguía publicando.

### 17.7 Verificación

| Comprobación | Resultado |
|---|---|
| `npm run pruebas` | **169/169** |
| `npm run auditorias` | **0 hallazgos** en 36 pantallas (UI), contraste/táctiles limpios y **0** errores en consola |
| `pruebas/esquema.mjs` sobre 28 rutas | un solo `<h1>` en todas, `<title>` correcto y `<caption>` presente |
| `php -l` | 109/109 archivos correctos |
| `npx gulp build` | correcto |
| Base después de las pruebas | 2 usuarios · 13 clientes · 16 productos · 1 cotización · 1 proveedor · 15 entradas · **10 categorías** · 2 empresas · 51 movimientos |

---

## Ronda — Ventas directas y listado unificado (2026-10-17)

- **Ventas reemplazada.** Se elimina el registro de entrega ligado a cotización (tabla, clase, vistas, JS de ubicación). Sus datos no se migran.
- **Venta directa** (`ventas` + `venta_detalles`, folio `VEN-AAAA-NNNN`): mismo formulario, cálculo y *Resumen de liquidación* que la cotización (`templates/formularioCotizaciones.php` con `$documento = 'venta'`), PDF con la misma plantilla.
- **Inventario:** crear descuenta, editar devuelve y vuelve a descontar, cancelar/eliminar devuelve. Si no alcanza queda en negativo y se avisa (sin bloquear). `movimientos_inventario.venta_id` registra el origen.
- **Listado `/admin/ventas`:** une ventas directas y cotizaciones vendidas, de todos los negocios (filtros por origen, negocio, cliente y fechas).
- **Ganancias del panel:** suman también las ventas directas.
- **Correo eliminado** (cotizaciones y ventas): se borran `Correos.php`, `enviar.php`, la ruta y la sección del detalle.
- **Migración:** `mysql/migracion_ventas_directas.sql` (respaldar antes).

---

## Ronda — Una sola sección de facturación y cobro (2026-10-22)

- **Dos pantallas → una.** `/admin/ventas/detalle` y `/admin/contabilidad/ingresos/detalle` mostraban datos de la misma venta (cada venta tiene **un** ingreso: `uq_ingreso_venta`). El detalle de la venta pasa a tener la única sección **«Facturación y cobro»**, arriba: estado de facturación con subida del CFDI e historial, cobros («Ya me pagaron todo», tabla de cobros y pago parcial) y documentos adjuntos.
- **Plantillas:** `facturacionVenta.php` reescrita como sección unificada, nueva `cobrosIngreso.php` (compartida con el respaldo del detalle del ingreso) y `documentosOperacion.php` con el modo `interna` (bloque dentro de otra tarjeta, sin relleno propio).
- **`/admin/contabilidad/ingresos/detalle` redirige (302)** al detalle de su venta y conserva el fragmento original (`#cobros`, `#documentos`…); sólo renderiza la vista antigua si el ingreso no tiene venta (registros legados). El destino por defecto de `/admin/contabilidad/ingresos/accion` también apunta a la venta.
- **Enlaces unificados** con `Contabilidad::rutaDetalle($ingreso, $ancla)`: panel, lista de ingresos, «Pendientes de facturar», «Documentos fiscales», centro de seguimiento y lectura de facturas. `Panel` y `Seguimiento` suman `venta_id` a sus consultas.
- **Pruebas:** `probar_etapa12` usa los ids canónicos (`estado_facturacion`) y comprueba la redirección 302; regresión en verde (21 suites PHP, 19 de navegador; `auditar_ui` 0 hallazgos y `auditar_profundo` idéntico a la línea base).
- **Manual:** sección 8 renombrada a «Facturación y cobro de una venta» (`includes/manual.php` → `MANUAL_USUARIO.md`) y paso 2 de `GUIA.md` actualizados.

---

## Ronda — Estadísticas: productos y clientes separados por negocio (2026-10-23)

- **Rankings por negocio.** `Estadisticas::topProductos()` y `Estadisticas::topClientes()` ahora devuelven el top de **cada negocio** (una fila `ROW_NUMBER() PARTITION BY v.empresa_id`), con `empresa_id` y `negocio`; el negocio es el emisor de la venta, el mismo que usa «Ventas por negocio». `Analisis::menorMovimiento()` hace lo mismo. Nuevo helper `Estadisticas::agruparPorNegocio()`.
- **Código del producto.** Las etiquetas de las gráficas de productos encabezan el SKU (`RA-3 · Registro de ángulo 40x60 cm`), y las tablas de «Productos más vendidos» traen columnas **Negocio** y **Código** (antes el SKU iba en letra chica debajo del nombre). Los clientes salen por negocio con su nombre (no tienen código).
- **Una gráfica por negocio.** «Productos con mayor movimiento», «Productos con menor movimiento» y «Clientes con mayor volumen» generan una gráfica por negocio (id `productos-top-<empresa>`…, con el negocio en el título) cuando hay más de uno; con un solo negocio (o filtro elegido) conservan el título y el id anteriores. Cada gráfica trae su nombre corto de hoja en el Excel (`hoja`), y `libroExcel()` dedupe los nombres para que Excel no reciba dos hojas iguales.
- **Ventas por negocio con productos.** En la sección «Negocios y clientes» (pantalla y PDF), debajo de la comparación, cada negocio lista sus productos del periodo con código, piezas, importe y número de ventas.
- **Exportaciones.** El CSV marca el negocio en la sección (`Productos más vendidos (Soluciones en Acero)`); el PDF repite las tablas por negocio.
- **Pruebas y manual.** `pruebas/php/etapa7.php` verifica separación por negocio y SKU en productos, clientes y menor movimiento; `pruebas/probar_etapa7.mjs` comprueba una gráfica por negocio, las columnas Negocio/Código y el detalle por negocio en «Ventas por negocio» (39/39). Manual de usuario actualizado (`includes/manual.php` → `MANUAL_USUARIO.md`).

---

## Ronda — Panel: productos más vendidos y existencias en alerta (2026-10-23)

- **Dos secciones nuevas en `/admin`, justo debajo de `rejilla-stats`, con el mismo estilo de tarjeta:**
  - *Productos más vendidos · mes*: los **4** productos con más importe del mes, con **código (SKU), nombre, imagen** (miniatura 56×56 con carga diferida y marcador cuando no hay foto), el **monto de venta** del producto y el pie «N piezas en N ventas · importe de renglones sin IVA». Cada tarjeta abre la ficha del producto. El mes es el del filtro (mes + año) y, si no se eligió mes, el mes en curso, cortado al día de hoy; el encabezado enlaza a esa vista en Estadísticas.
  - *Productos con existencias bajas*: tarjetas con la misma estructura para los productos activos con **stock negativo, agotado (0) o en/bajo su mínimo**, ordenadas por urgencia (negativos → agotados → bajo mínimo). La cifra es la existencia y el pie explica el estado («Existencia negativa», «Agotado», «Bajo el mínimo · mínimo N»). Enlazan a `/admin/inventario?q=<SKU>` con el producto listo para ajustar; el encabezado muestra «Ver inventario (N)» con el total en alerta y, si hay más de 8, una nota indica cuántos se muestran.
- **`clases/Panel.php`:** nuevos métodos de solo lectura `productosMasVendidos($desde, $hasta, $limite = 4)`, `productosStockBajo($limite = 8)` y `contarStockBajo()`, con sus criterios documentados en el encabezado de la clase.
- **Estilos:** `src/scss/layout/_cards.scss` agrega `.stat--producto`, `.stat__imagen(--vacia)`, `.stat__codigo` y `.stat__nombre`; `build/css/app.css` recompilado con `npx gulp css`.
- **Color de la cifra:** el número de existencias sale en **rojo** (`--color-error`) cuando ya no queda nada (agotado o existencia negativa) y en **naranja** (`--color-advertencia`) cuando quedan pocas (en o debajo del mínimo). Nuevas clases `.stat--sin-existencias` y `.stat--pocas-existencias`; como usan las variables del tema, se ven bien en modo claro y oscuro.
- **Pruebas y manual:** `pruebas/probar_ronda.mjs` suma la sección «10. Panel» (tarjetas o estado vacío, enlaces y color de la cifra) → **50/50**; manual de usuario actualizado (`includes/manual.php` → `MANUAL_USUARIO.md`).

---

## Ronda — Estadísticas: las tarjetas de producto solo en el PDF (2026-10-23)

- **`templates/pdfEstadisticas.php` añade dos secciones debajo del bloque de filtros (periodo y filtros), que la pantalla NO muestra:**
  - **«Productos más vendidos · <periodo>»** (p. ej. *Octubre 2026*): hasta 4 tarjetas con **imagen, código (SKU), nombre, monto de venta** y «N piezas en N ventas · renglones sin IVA», del rango del reporte (`$r['periodo']`).
  - **«Productos con existencias bajas»**: hasta 8 tarjetas con el mismo formato; el número de existencia sale en **rojo** (`#e11d48`) cuando ya no queda nada y en **naranja** (`#d97706`) cuando está en o debajo del mínimo, más la nota de cuántos se muestran si hay más de 8.
  - Datos con `Panel::productosMasVendidos()` y `Panel::productosStockBajo()`; imágenes en base64 (miniatura 300×300 primero, luego la completa) porque dompdf no sale a Internet; maqueta con tablas anidadas (dompdf sólo entiende un subconjunto de CSS).
- **Solo el reporte:** ni Excel ni CSV ni la pantalla de `/admin/estadisticas` traen estas secciones (verificado en la suite: la pantalla no contiene «Productos con existencias bajas»).
- **Tabla de productos menos vendidos.** Debajo de «Productos con existencias bajas», el PDF añade **«Productos menos vendidos · <periodo>»** con columnas **Negocio · Código · Producto · Piezas · Importe (sin IVA)**, tomada de `analisis.menor_movimiento` (los que sí se vendieron, de menos a más piezas y separados por negocio; ya no hace falta otra consulta). Si el periodo no tiene ventas se explica con una nota en lugar de dejar la tabla vacía.
- **Pruebas y manual:** `pruebas/probar_etapa7.mjs` comprueba que el PDF trae las tres secciones y que la pantalla no → **42/42**; manual actualizado (`includes/manual.php` → `MANUAL_USUARIO.md`).

---

## Ronda — Centro de seguimiento: compras, proveedores y stock alineado (2026-10-23)

El centro de seguimiento cubría ventas, gastos, entregas e inventario de productos, pero no el lado de las compras (módulo agregado después) ni las materias primas. Ahora vigila ambos, con la misma mecánica de siempre: los avisos **no se guardan**, se calculan en vivo con clave estable (`tipo:id`) y huella, y lo único que se escribe es `seguimiento_lecturas`.

- **Categoría «Compras»** (`Seguimiento::CATEGORIAS`, icono `shopping_cart`) con cuatro tipos de aviso:
  - `compra_recibir` — órdenes en `solicitada`/`parcial` cuyas partidas **controladas** no se han recibido completo. La huella es la cantidad faltante, así que al recibir parcialmente el aviso vuelve a ser nuevo. Las compras de puros servicios/consumibles (sin control de inventario) no generan aviso: no hay nada que recibir.
  - `compra_cfdi` — compras con `estado_cfdi = 'pendiente_cfdi'`; el botón «No se facturará» manda `POST /admin/compras/comprobante` con `accion=estado_cfdi`.
  - `compra_saldo` — compras no canceladas cuyo total (esperado + IVA) supera sus **pagos vigentes**, calculado en centavos como en el resto de la contabilidad. Trae el saldo y el método sugerido (el último pago registrado); el desplegable «Registrar pago» va a `POST /admin/compras/pago` con `accion=registrar`.
  - `entrada` — pedidos directos a proveedor (`proveedor_entradas`) con `pagado = 0`, que no pasan por orden de compra. «Marcar como pagada» manda `POST /admin/proveedores/entradas/pago`.
- **Una compra en contabilidad sigue avisando su saldo.** `gasto_id` no se usa para deducir el aviso: la compra ya enviada a contabilidad genera «Gasto por pagar» **y** «Compra por pagar». Deducir uno del otro dejaría la deuda sin aviso si se paga el gasto desde Contabilidad (`Gastos::marcarPagado` no escribe en `pagos_compra`). Documentado en el docblock de `Seguimiento` y en el manual.
- **Stock de productos alineado con el Panel.** El aviso exigía `stock_minimo IS NOT NULL`, de modo que un producto **agotado o en negativo sin mínimo definido** se veía en el panel pero nunca en el seguimiento. El criterio pasó a ser el mismo que `Panel::productosStockBajo` (`stock <= 0` **o** `stock <= stock_minimo`); el detalle ya no inventa un mínimo cuando no existe.
- **Materias primas bajo el mínimo.** Nuevo aviso `material:<id>` sobre `materiales` (`existencia <= minimo`, urgente cuando `existencia <= 0`), que enlaza a `/admin/compras/recepciones#materiales`. Antes el mínimo de `materiales` no lo vigilaba nada.
- **Vista `views/admin/seguimiento.php`:** acciones rápidas para `compra_saldo` (pago parcial o total con importe y método), `compra_cfdi` («No se facturará») y `entrada` («Marcar como pagada»), con el mismo patrón de `details` + formulario compacto que ya usaban cobros y facturas.
- **Pruebas:** `pruebas/php/etapa25.php` (39 casos) cubre los cuatro avisos nuevos, la huella al recibir y al pagar parcial, la no deducción del gasto, materiales con y sin mínimo y el stock sin mínimo; se agregó al listado de `pruebas/ejecutar_todo.sh`. Regresión en verde (etapas 11, 15, 16, 18, 19, 20, 21).
- **Novedad 1.3.0** en `Seguimiento::NOVEDADES` y manual de usuario actualizado (`includes/manual.php` → `MANUAL_USUARIO.md`, sección 14: «Lo que vigila» y el consejo del doble aviso de una compra en contabilidad).

---

## Ronda — Calendario: recordatorios y eventos (2026-10-23)

Nueva herramienta del grupo «Herramientas personales»: un calendario mensual para anotar recordatorios y eventos sin salir del panel. Como Notas y el resto de herramientas, es **compartida** entre los administradores.

- **Base de datos** (`mysql/migraciones/028_calendario.sql`, con su `028_reversion.sql`): tabla **`calendario_eventos`** con `fecha`, `hora` (NULL = todo el día), `nombre`, `descripcion`, `empresa_id` (negocio opcional, `ON DELETE SET NULL`), `importancia ENUM('bajo','medio','alto')` y `usuario_id` (`ON DELETE RESTRICT`: borrar a un autor nunca borra el calendario). Índice sobre `(fecha, hora)`.
- **Modelo `clases/EventosCalendario.php`**: alta, edición, baja y validación (fecha real, nombre obligatorio ≤ 200, hora dentro de sus rangos, nivel válido), más las lecturas del módulo — `listarMes()`, `contarPorDia()` y `resumenMes()` — con `LEFT JOIN` a `empresas` y `usuarios` para traer negocio y autor. Registrado en `includes/app.php`. Detalle importante: **una hora vacía se guarda como NULL**; si se escribiera `''` MySQL la convertiría en `00:00:00` y el evento dejaría de ser «de todo el día» (detectado por la prueba).
- **Rutas** (`index.php`, todas `soloAdmin`): `GET /admin/calendario` (tablero), `POST /admin/calendario/crear`, `GET|POST /admin/calendario/editar` y `POST /admin/calendario/accion` (baja, sólo POST y con confirmación modal).
- **Vista `views/admin/calendario/index.php`:**
  - rejilla del mes activo (lunes primero) con **botones para navegar entre meses y años** y «Hoy»;
  - cada casilla es un enlace que selecciona el día y lleva su `aria-label` («lunes 12 de octubre, 2 eventos»); marca el día de hoy, el día elegido y un **punto de color** con el nivel más alto de ese día;
  - panel derecho con los eventos del día elegido (hora, negocio, importancia y acciones) y, debajo, **el formulario de alta con la fecha ya puesta**;
  - **agenda del mes** agrupada por día, con enlace a cada fecha;
  - tres tarjetas de resumen: eventos del mes, con hora e importancia alta.
- **Formulario `templates/formularioCalendario.php`** (compartido por alta y edición): fecha, hora (`input type="time"`), nombre, descripción (textarea opcional), negocio (`Empresas::paraSelector()`, opcional) e importancia (bajo/medio/alto), con el mismo patrón de errores `.campo--con-error` del resto de la app y botón «Guardar».
- **Estilos:** `src/scss/layout/_calendario.scss` (registrado en `app.scss` y compilado con `npm run build`).
- **Navegación:** enlace nuevo en el menú lateral, dentro de «Herramientas personales» y junto a «Notas personales»; marcado como activo también desde el cajón de la barra inferior del celular (`bottomnav.php`), y `modoMovil()` lo trata en **«completo»** para que en el teléfono se pueda capturar y no sólo informar.
- **Ayuda:** entrada en `AYUDAS_POR_RUTA` (`src/js/modulos/ayudas.js`).
- **Pruebas:** `pruebas/php/etapa27.php` (40 casos: migración, validaciones, altas con/sin negocio y con/sin hora, lecturas, edición, baja, menú y rutas) y `pruebas/probar_calendario.mjs` (31 casos de navegador: menú, calendario, navegación por mes y año, clic en un día, alta, validación, edición y baja con modal). Ambas quedaron en el listado de `pruebas/ejecutar_todo.sh`; **ambas en verde**.
- **Despliegue (23 oct 2026):** imagen **`gmr27/cotizaciones:19`**, revisión de Helm **21**. El init `migrar-base` aplicó **`027_categorias_adicionales.sql`** y **`028_calendario.sql`** sólo con cambios de esquema/`INSERT IGNORE`: los datos existentes (usuarios, clientes, cotizaciones, ventas) quedaron intactos y MySQL **no se reinició**.

---

## Ronda — Ajustes → Almacenamiento: ocupación de disco y copias de seguridad (2026-10-23)

Nueva pestaña **«Almacenamiento y respaldos»** en Ajustes (`/admin/ajustes/almacenamiento`, con ruta y vista propias como Papelera) y sección **18 del manual**. Se suma a las 5 secciones nuevas del manual por las herramientas personales (Notas, Redactor, SheetFlow, Editor y Archivo), que no estaban documentadas.

- **Ocupación (solo lectura, en vivo).** `Almacenamiento::ocupacion()` suma la base de datos (`information_schema`, datos + índices) y cada carpeta de archivos: `storage/documentos`, `storage/archivo`, `public/image` y la carpeta de respaldos. Sale una barra con el reparto y una tabla con peso, porcentaje, explicación y ruta de cada parte. Recorrer carpetas se limita a 200 000 archivos por carpeta para no colgar la página. `formatearBytes()` arreglado: el `rtrim(..., '0')` anterior convertía 1 MB en «1 MB».
- **Respaldos reutilizando los scripts ya probados.** No se reimplementa el volcado: la pestaña invoca `scripts/respaldar.sh` y `scripts/restaurar.sh`, que son los que valida `pruebas/probar_respaldo.sh`. Se comprueban antes `mysqldump`, `mysql`, `tar`, `gzip` y `sha256sum`; si falta alguno, la pestaña lo dice en lugar de fallar a mitad de un respaldo (en el contenedor Docker no se instala el cliente de MySQL).
- **Corrección de precedencia en los scripts.** `respaldar.sh` y `restaurar.sh` hacían `source` del `.env` **pisando** las variables del entorno: la aplicación podía respaldar una base distinta de la que estaba usando (en Docker, cron o pruebas, donde `DB_NAME` viene del entorno). Ahora el entorno manda y el `.env` sólo completa lo que falte. Verificado por prueba.
- **Respaldo automático (1 al día).** `Almacenamiento::respaldoDelDia()` se ejecuta al abrir la pestaña: si el automático está activo, ya pasó la hora (por defecto 23:00) y no se ha respaldado hoy, genera la copia y avisa en pantalla. Una sola por día, sin importar cuántos usuarios entren. Para quien lo quiera garantizado aunque nadie entre, la pestaña muestra la línea de cron calculada con la hora elegida.
- **Restaurar nunca pisa nada.** `restaurar()` verifica las huellas SHA-256, importa en una base **nueva** (`cotizaciones_restaurada_AAAAMMDD`, con sufijo si ya existe) y extrae los archivos a `storage/restauracion-…`. Se niega si la copia no tiene `SHA256SUMS`. Cada acción queda en la bitácora (`Auditoria::registrar('respaldo', …)`).
- **Configuración** en `configuracion`: `respaldo_automatico`, `respaldo_hora`, `respaldo_retener` (1–365, por defecto 14), `respaldo_carpeta` (sólo rutas absolutas) y `respaldo_ultimo`.
- **Pruebas:** `pruebas/php/etapa26.php` (59 casos: formateo, ocupación, carpeta configurable, los 4 archivos del respaldo con permisos 600, huellas verificables, **que respalda la base conectada y no la del `.env`**, retención, los cuatro estados del automático, restauración en base nueva con los mismos registros y rechazo sin huellas). Quedó en el listado de `pruebas/ejecutar_todo.sh`.

---

## Ronda — Registro inteligente: aviso de duplicado con los datos de la factura y botones con color (2026-10-23)

Al subir una factura con «Registro Venta Inteligente» o «Registro compra inteligente» aparecía el aviso *«Este documento ya tiene un registro con la misma información»* apoyado en la **fecha**: con una venta registrada ese día ya salía el aviso aunque fuera otra factura (p. ej. *Venta #9 · VEN-2026-0008 · 2026-10-22 · $64,815.40 · GRUPO DEMO · misma fecha*). Ahora el cotejo se hace con los datos que identifican al documento.

- **`CoincidenciasFactura::duplicados()` — cuatro criterios y un veto.** Sólo cuenta lo que identifica A LA FACTURA:
  1. **mismo archivo (sha256)** — el PDF/XML ya está adjunto en algún lado. El criterio existía en el docblock pero **nunca se consultaba**: ahora `Lecturas::propuesta()` manda la huella de la lectura.
  2. **folio fiscal (UUID)** — el timbre del CFDI es único (gasto o ingreso → su venta).
  3. **folio de factura + fecha o importe** — un folio suelto se repite entre proveedores, así que basta el folio sólo si además cuadra el día o el monto.
  4. **mismo importe + (misma fecha o mismo RFC)** — el mismo monto el mismo día, o el mismo monto del mismo tercero aunque la fecha cambie («a veces»). Va **sólo contra registros del mismo tipo**: una compra no se compara con una venta, son libros distintos.
  - **Veto:** si el registro ya trae **otro UUID, otro folio u otro RFC** que el documento, son facturas distintas aunque compartan fecha e importe. El RFC nunca señala por sí solo (un cliente o un proveedor facturan muchas veces): sirve para descartar y, con el importe, para convencer.
  - Un **gasto cancelado** ya no se señala como «el que existe» (`datosDeRegistro()` exige `estado = 'vigente'`).
- **Verificado con las facturas reales de `~/Descargas`.** Con `VEN-2026-0006` (19/10, $58,210.75, CLIENTE DEMO) subida: la CFE **3255** sí avisa y la CFE **3254** —mismo día, otro importe— ya no. Con una venta del **22/10 en $64,815.40 de GRUPO DEMO**: la GRB **3259** (22/10, $65,620.15) ya **no** avisa —ese era el caso reportado— y la GRB **3260** ($64,815.40) sí, por importe y RFC.
- **El gasto ya no pierde los datos del CFDI.** `templates/formularioGasto.php` trae ocultos `folio_comercial`, `rfc_emisor`, `rfc_receptor`, `moneda`, `forma_pago_sat` y `metodo_pago_sat`, que `gastos/crear.php` precarga desde la propuesta y también completa si se sube un XML directamente en el formulario. Antes se guardaban vacíos, y sin folio ni RFC el cotejo tenía menos con qué trabajar.
- **Los dos archivos quedan adjuntos.** `Lecturas::adjuntar()` pasó a ser pública y la usa `gastos/crear.php`: el XML **y el PDF** que se suben juntos se guardan en `documentos` (antes sólo el principal; el PDF se perdía). `probar_inteligente` sigue viendo 1 archivo cuando sólo se sube el XML.
- **Botones con color.** `templates/resumenReporte.php` acepta la clave `clase` por acción (por defecto sigue siendo `btn--primario`); Contabilidad manda `btn--exito` para **Registro Venta Inteligente** (verde) y `btn--rojo` para **Registro compra inteligente** (rojo). Nuevo modificador `.btn--rojo` en `src/scss/layout/_botones.scss`, gemelo sólido de `--exito` y con las variables del tema (se ve bien en claro y oscuro); compilado con `npx gulp css`.
- **Pruebas:**
  - `pruebas/php/etapa24.php` suma «Duplicados: qué NO lo es» (7 casos: misma fecha con otro importe, mismo importe con otra fecha con y sin RFC, folio con la misma fecha, folio con otra fecha, y el veto por otro folio fiscal).
  - `pruebas/probar_duplicados.mjs` reescrito a las nuevas reglas — **22/22**: mismo día e importe → aviso con «Ver la venta»; mismo día con **otro importe** → se va derecho al formulario; mismo importe y mismo RFC con **otra fecha** → aviso; sin coincidencia → formulario. Crea su propia venta de referencia (las otras pruebas de la suite borran y facturan ventas) y ya borra los archivos de `storage/documentos/lecturas` (antes dejaba sus filas huérfanas).
  - Nuevo `pruebas/probar_facturas_reales.mjs` — **60/60**: colores de los dos botones, los 10 XML + 10 PDF de `~/Descargas/ingresos`, el caso reportado de GRUPO DEMO, los 9 XML + 9 PDF de `~/Descargas/gastos`, el registro de la de un proveedor (XML + PDF) con sus relecturas («mismo archivo», «folio fiscal (UUID)») y las del mismo día de otro proveedor que **no** deben avisar. Crea las ventas de referencia que necesita, así que no depende de lo que otras pruebas dejen en la base; si no encuentra las carpetas termina en verde sin probar nada. Entró a `pruebas/ejecutar_todo.sh` y a `npm run pruebas`.
  - `pruebas/probar_inteligente.mjs` suma la comprobación del color calculado de cada botón — **38/38**.
- **Regresión completa (`pruebas/ejecutar_todo.sh --rapido`, base `cotizaciones_test`): 52/52 en verde.**

---

## Ronda — Gastos: uso del CFDI, método de pago y sus avisos en el seguimiento (2026-10-23)

La captura de un gasto registraba la factura (UUID, folio, RFC del emisor, forma de pago y moneda) pero no dos datos que el SAT pide en el CFDI: el **Uso del CFDI** y el **método de pago**. El método sólo se guardaba si venía de un XML leído (y sólo `PUE`/`PPD`), así que una factura «en parcialidades» o «por definir» no quedaba registrada ni se vigilaba en ninguna parte.

- **Dos campos nuevos en el formulario de gasto** (`templates/formularioGasto.php`, comunes a alta y edición) que aparecen **sólo cuando la respuesta es «Con factura o CFDI»** (misma alternancia que el resto; el servidor los limpia si el gasto termina siendo sin factura, porque no hay comprobante del que hablar):
  - **Uso del CFDI** — catálogo `Gastos::USOS_CFDI` (G01 adquisición de mercancías, G03 gastos en general, I01–I08, D01–D10 y S01), validado contra el catálogo; vacío si no se sabe.
  - **Método de pago del CFDI** — `Gastos::METODOS_CFDI`: **PPD · pago en parcialidades**, **PUE · pago en una sola exhibición** y **por definir** (la que queda cuando aún no se decide; por eso es el valor por defecto de una factura nueva). El CFDI leído sigue precargando `PUE`/`PPD` como antes.
  - **Al subir el XML en el mismo formulario** se completan solos los dos campos vacíos: `CfdiXml::leer()` pasó a leer también `UsoCFDI` del receptor, además del `MetodoPago` que ya leía.
- **Migración `029_uso_cfdi_gastos.sql`** (con su `029_reversion.sql`): `gastos.uso_cfdi CHAR(4)` y `metodo_pago_sat` acepta además `POR`. Los tres campos del CFDI que iban ocultos siguen ocultos (`folio_comercial`, `rfc_emisor`, `rfc_receptor`, `moneda`, `forma_pago_sat`); el método de pago salió de ahí y pasó a ser visible.
- **El detalle del gasto muestra ambos** en su lista de datos (`Uso del CFDI` y `Método de pago`), y la edición los guarda con auditoría de los cambios (junto a fecha, importes, estado de pago, revisión fiscal y concepto).
- **Centro de seguimiento: aviso `gasto_cfdi`** para toda factura vigente cuyo método **no** sea `PUE`: «Factura con pago en parcialidades» (con el recordatorio del complemento de pago) o «Factura con método de pago por definir» (incluye las facturas guardadas antes de esta ronda, que no traen método). Prioridad normal, huella con el método — si cambia, el aviso se vuelve nuevo— y categoría «Gastos». Un gasto sin factura nunca lo genera.
- **Acción rápida desde el aviso:** «Definir método de pago» guarda `PUE`, `PPD` o «por definir» sin abrir el gasto (`Gastos::metodoCfdi()`, con validación de catálogo, periodo abierto y bitácora; el POST va a `gastos/accion`). También se puede resolver desde «Editar gasto».
- **Manual de usuario** (secciones 5 «Anotar un gasto» y 20 «Centro de seguimiento», regenerado con `scripts/generar_manual.php`) y **Novedad 1.4.0** en `Seguimiento::NOVEDADES`.
- **Pruebas:** `pruebas/php/etapa28.php` (26 casos: la migración, el alta con los tres métodos y con uso válido, el rechazo de uso/método fuera de catálogo, la limpieza al capturar sin factura, la edición, `metodoCfdi`, la lectura del `UsoCFDI` del XML, y que el aviso sale en PPD y «por definir» —también sin método guardado—, no en PUE, desaparece al pasar a PUE y no aparece en un gasto sin factura). Quedó en el listado de `pruebas/ejecutar_todo.sh`; regresión completa en verde (PHP 2–28 y navegador: `etapa5`, `etapa13`, `categorias_gasto`, `inteligente`, `campana`, `duplicados` 22/22 y `compras`).

---

## Ronda — Ventas y cobros: dos tablas, lo que se factura y lo que no (2026-10-23)

La pestaña «Ventas y cobros» mezclaba en una sola tabla las ventas facturadas, las pendientes de facturar y las que ya se habían marcado como **«No facturada»**: para encontrar una venta sin factura había que pelear con el filtro, y lo que ya no se va a facturar tapaba lo que sí. Ahora son dos tablas en la misma pantalla, con los mismos filtros.

- **Tabla 1 «Ventas con factura o pendientes de facturar»** — `estado_facturacion` distinto de `no_facturada` (facturadas y pendientes). Conserva buscador, filtros de facturación/estado, conmutadores de facturación y cobro, y su propio total y paginación.
- **Tabla 2 «Ventas indicadas como sin factura (no se facturarán)»** — las `no_facturada`, con su propio conteo, su total al pie y su paginación. Lleva una nota que explica que estas ventas no cuentan como CFDI emitido y cómo regresarlas (el mismo interruptor de la columna «Facturación»).
- **Cada venta cae en una sola tabla y las dos suman el total del periodo.** El filtro de arriba (búsqueda, facturación, estado, periodo) aplica a las dos; si se elige «No facturada», la tabla de arriba queda vacía y la de abajo muestra sólo esa.
- **`Contabilidad::condicionesIngresos()`** acepta `facturacion_grupo` (`con_factura` | `sin_factura`), que se combina con el filtro `facturacion` existente; `listarIngresos`, `contarIngresos` y `totalesIngresos` se usan igual.
- **Paginación por tabla.** `paginacion()` ya tenía el parámetro opcional `$parametro`; la plantilla `templates/paginacion.php` lo respeta ahora (por defecto sigue siendo `pagina`, así que ningún otro listado cambia). La primera tabla pagina con `pagina` y la segunda con `pagina_sin`, de modo que cambiar de página en una no mueve a la otra.
- **Manual de usuario:** sección «Facturación y cobro de una venta» — «Ver todas tus ventas» describe las dos tablas (regenerado con `scripts/generar_manual.php`).
- **Pruebas:** `pruebas/php/etapa29.php` (20 casos: la clasificación de cada venta en una sola tabla, que las dos suman el total del periodo, los totales por tabla, el filtro combinado, los títulos de la vista, el doble parámetro de paginación y que el resto de los listados sigue usando `pagina`). Quedó en `pruebas/ejecutar_todo.sh`; regresión completa en verde (PHP 2–29, `probar_etapa5`, `probar_etapa12`, `probar_reportes` y auditorías de pantalla: 0 hallazgos de UI, 0 errores de consola y 0 desbordes en móvil).

---

## Ronda — Papelera: los gastos eliminados también se pueden recuperar (2026-10-23)

La papelera (Ajustes → Papelera) sólo guardaba ventas y cotizaciones. Un gasto eliminado desaparecía para siempre: ni sus datos fiscales (UUID, folio, uso y método del CFDI) ni su reparto entre negocios se podían recuperar. Ahora un gasto eliminado manda su copia a la papelera, igual que el resto, y se puede restaurar durante 90 días.

- **`Papelera::copiarGasto()`** guarda el gasto completo más su reparto entre negocios (`gasto_reparto`), dentro de la misma transacción que lo borra; si la copia no se guarda, **no se elimina nada** (mismo criterio que ventas y cotizaciones).
- **`Papelera::restaurarGasto()`** devuelve el gasto con su número, importes, datos fiscales y reparto. Dos cuidados:
  - `gastos.uuid_vigente` es una columna **generada** por la base (UUID único entre gastos vigentes) y no admite escritura: se quita de la copia antes del INSERT (si no, la restauración fallaba con «The value specified for generated column … is not allowed»; lo descubrió la prueba).
  - Si el UUID fiscal ya lo está usando **otro gasto vigente**, el restaurado vuelve sin UUID para no duplicar la factura.
- **Lo que NO vuelve:** los archivos adjuntos (`Gastos::eliminar` los borra del disco) y el enlace con la lectura inteligente que originó la captura. Un gasto restaurado sin su XML aparece como «Con factura: falta adjuntarla», y la compra de origen vuelve a «pendiente de factura». Está dicho en la propia pantalla de la papelera y en el manual.
- **`Gastos::eliminar()`** (el borrado definitivo, con motivo y hasta 200 gastos por vez desde la lista de Gastos) ahora copia a la papelera **antes de tocar nada** y se revierte entero si la copia falla.
- **Migración `030_papelera_gastos.sql`** (con su reversion): el `ENUM` de `papelera.tipo` pasa a `('venta','cotizacion','gasto')`.
- **Vista `views/admin/ajustes-papelera.php`:** columna «Tipo» con las tres clases (Venta, Cotización, Gasto) y textos actualizados, incluido el aviso de que un gasto restaurado vuelve sin sus archivos.
- **Pruebas:** `pruebas/php/etapa17.php` suma 15 casos (envío a la papelera al eliminar, etiqueta, total y reparto en la copia, vencimiento a 90 días, restauración completa, el caso del UUID ya ocupado y la vista). Regresión en verde: PHP 2–29 y navegador (`etapa5`, `compras`, `categorias_gasto`).

---

## Ronda — Impuestos: sólo entran facturas pagadas y gastos pagados (2026-10-23)

Una factura emitida se sumaba completa en «Facturado» aunque el cliente sólo hubiera dejado una seña —o nada—, y **todos** los gastos se restaban aunque no se hubiesen pagado: la estimación calculaba impuestos sobre dinero que ni había entrado ni había salido. Ahora hay una sola regla de pago y aplica en todos los cálculos.

- **Regla:** una factura entra al cálculo **únicamente cuando sus cobros vigentes cubren su total** («Pagada»); con pago **en parcialidades** o **sin cobro confirmado** se trata como **sin pagar** y no aporta ingreso ni IVA trasladado. Un gasto sólo descuenta con `estado_pago = 'pagado'`. Al confirmarse el pago, la factura se reconoce **en el mes de su fecha** y el gasto en el de su registro; los meses ya cerrados conservan su fotografía (`periodos_contables.resumen`).
- **`ResumenFiscal`** (motor de Fiscal → Resumen, del bloque de impuestos de Contabilidad, del cierre y de los PDF/Excel/CSV):
  - `anual()` suma sólo facturas pagadas al 100 % (subconsulta de cobros vigentes) y sólo gastos pagados; cada mes expone además **`facturas_sin_pagar[_n]`** y **`gastos_no_pagados[_n]`** (n.º e importe de lo excluido), acumulados en el total del año.
  - `movimientos()` añade el estado de cada fila al «Detalle» («Facturada · Pago parcial», «Sin tipo · Por pagar»…) y reparte los totales: **`ventas_facturadas_pagadas`** y **`gastos_pagados`** (lo que sí cuenta) junto a los totales registrados.
- **`MotorFiscal`** (IVA/ISR por flujo de efectivo): los cobros de una factura no liquidada se omiten del prorrateo —antes sí entraba lo cobrado en parcialidades—; lo excluido sale en **`facturas_excluidas`** (n.º e importe por cobrar) con el aviso «N factura(s) con pagos en parcialidades…».
- **Cierre (`CierrePeriodo`):** los avisos `cobros_pendientes` y `pagos_pendientes` ahora dicen que esas facturas y gastos **no entran en la estimación hasta pagarse** (siguen en nivel `info`: no cambia el flujo de cierre).
- **Pantallas:** `views/admin/fiscal/resumen.php` (nota con lo excluido y «¿Cómo se calcula?» al día), `fiscal/movimientos.php` (pies «Facturadas · ya cobradas» y «Pagados: sólo ellos descuentan», más la nota), `fiscal/exportar.php` y `templates/pdfFiscalMovimientos.php` (las mismas cifras y la nota en PDF, Excel y CSV), y el pie de «Impuestos» en `views/admin/contabilidad/index.php` («fuera: N factura(s) sin cobrar… y M gasto(s) por pagar»).
- **Manual:** sección «Impuestos» de `includes/manual.php`, regenerado con `scripts/generar_manual.php`.
- **Pruebas:**
  - `pruebas/php/etapa6.php` — bloque «una factura en parcialidades no se considera hasta liquidarse» (8 casos en mayo de 2026, para no mover las cifras ya calculadas a mano de enero–marzo).
  - **Nuevo `pruebas/php/etapa30.php`** (28 casos sobre un contribuyente propio `T30FISCAL01`): facturado sólo con cobro completo, excluidas por mes y por año, gasto pendiente fuera, estados y totales de `movimientos()`, `impuestosDelPeriodo()` y la fotografía del cierre. Registrada en `pruebas/ejecutar_todo.sh`.
  - Nota de la sesión: `pruebas/php/etapa28.php` (uso del CFDI y método de pago de los gastos) se **reconstruyó** el 23 de octubre tras chocar el nombre de archivo con esta suite; quedó con sus mismos 26 casos y en verde, y la de impuestos pasó a `etapa30`.

---

## Ronda — Comportamiento definitivo del cálculo de impuestos (2026-10-23)

Se revisó la hoja real **`~/Descargas/FISCAL 2026.xlsx`** (hoja `FISCAL` + una hoja por mes): «Facturamelo» son los ingresos, las demás hojas el registro de gastos, y la cuenta es `IVA facturado − IVA acreditable` e `ISR = (base ingresos − base gastos) × 32 %`, con el total como suma de ambos. A partir de ahí queda **una sola definición** para la estimación de la plataforma.

- **Qué entra:** sólo operaciones **pagadas**, por el mes de la **fecha de la operación** (como el Excel): facturas `facturada` cuyos cobros vigentes cubren el total y gastos con `estado_pago = 'pagado'`. Una factura en parcialidades o sin cobrar y un gasto por pagar quedan fuera (se muestran con su n.º e importe). No se filtra por `revision_fiscal` ni por comprobante: la deducción la decide el contador.
- **IVA real:** `ResumenFiscal` ya no divide todo entre `1.16`; toma el **IVA registrado en cada operación** (`SUM(iva)`) y la base es `total − IVA`. Con operaciones al 16 % da exactamente el mismo resultado que la hoja `FISCAL` (`total / 1.16`) y además es correcto con 0 %, 8 % u otra tasa. Se eliminó la constante `ResumenFiscal::IVA`.
- **PUE / PPD / por definir (informativos):** el método del CFDI no altera la cuenta; explica por qué una operación entra o no. `movimientos()` agrega el método al «Detalle» («Pagado · PPD · parcialidades», «Por pagar · Por definir (aún no se decide)») y nuevos totales `iva_ventas_pagadas` / `iva_gastos_pagados`; el PDF, Excel y CSV lo heredan. En ingresos el equivalente es el estado de cobro (Pagada / Pago parcial / Sin pagar).
- **Archivos adjuntos:** XML, PDF y fotos son sólo respaldo documental (consulta y descarga); no modifican importes ni el cálculo.
- **Textos corregidos** que prometían filtros inexistentes: avisos `gastos_sin_comprobante` y `gastos_por_validar` de `CierrePeriodo`, «¿Cómo se calcula?» de `fiscal/resumen.php`, notas de `fiscal/movimientos.php`, aviso de `fiscal/exportar.php`, guías del PDF fiscal, aviso de gastos sin factura de `contabilidad/index.php`, ayuda de `formularioGasto.php`, manual (`includes/manual.php` → `MANUAL_USUARIO.md`) y `GUIA.md`.
- **Pruebas:** `pruebas/php/etapa30.php` suma un bloque con factura al **0 %** (base completa, IVA 0), gastos **PPD** y **«por definir»** pendientes que quedan fuera, el PPD que entra al pagarse, la comprobación de las fórmulas, el detalle/totales con el método y los **tres métodos lado a lado**: PUE descuenta desde su registro, PPD al liquidarse y «por definir» sólo al marcarse pagado (el método explica, manda el pago). Sin migraciones.

---

## Ronda — Calendario: marcadores del negocio en cada casilla (2026-10-24)

El Calendario sólo mostraba los recordatorios capturados a mano. Ahora cada casilla marca, con un icono de color, lo que pasó ese día en los negocios: sirve para abrir el mes y ver de un vistazo dónde hubo movimiento y qué está pendiente.

- **Cinco marcadores**, en este orden y color:

  | Icono | Color | Qué marca | Fuente |
  |---|---|---|---|
  | `trending_up` | Verde | Venta | `ventas` con estado `registrada` |
  | `receipt_long` | Rojo | Gasto | `gastos` vigentes |
  | `inventory_2` | Azul | Recepción de proveedor | `recepciones` (por orden de compra) + `proveedor_entradas` |
  | `schedule` | Amarillo | Factura por pagar | `gastos` con `estado_pago = 'pendiente'` |
  | `request_quote` | Naranja | Factura por cobrar | `ingresos` facturados cuyo cobro no cubre el total |

  El criterio de «por cobrar» es el mismo que usa la estimación de impuestos, así que el calendario y Fiscal se ven consistentes. Un gasto deja de estar amarillo en cuanto se marca pagado y una factura deja de estar naranja en cuanto se cobra por completo.
- **`EventosCalendario::marcadoresNegocio()`** (clase `EventosCalendario`): cinco consultas con **rango de fechas** (`fecha >= inicio AND fecha < mes+1`) y no `YEAR()/MONTH()`, para que usen los índices existentes. Filtra por `contribuyente_id`; para ventas se llega por `empresas` y para recepciones por `ordenes_compra`. Los proveedores no tienen contribuyente (son de la aplicación entera), así que por ese lado sólo se filtra por fecha. Trae cantidad e importe por día, con **caché por mes** (`olvidarMarcadores()` la limpia en pruebas y procesos largos).
- **Sólo lectura:** no se guarda nada nuevo ni se puede editar ni borrar desde el calendario. Las dos fuentes de recepción que coinciden en un día se **suman**, no se pisan.
- **Pantalla:** iconos en la casilla junto al número (cada tipo es UN icono; la cantidad va en el tooltip), `aria-label` con el resumen legible («Viernes 23 de octubre, 1 venta, 1 gasto, sin eventos»), leyenda nueva con los cinco, y panel **«Lo que pasó ese día»** con cifras por tipo. Se distinguen de la importancia por la **forma** (icono vs punto), no sólo por el color, para que no se confundan con los puntos verde/amarillo/rojo de bajo/medio/alto.
- **Estilos:** `--color-naranja` y `--color-azul-marcador` en `_tema.scss` (los dos colores que faltaban), variables en `_variables.scss`, e iconos en `_calendario.scss`. Detalle resuelto: la hoja de Google Fonts trae `.material-symbols-outlined { font-size: 24px }` y **anula** la herencia, así que el tamaño va directo en el icono — por eso los cinco se salían de la casilla. Las casillas quedan de **altura uniforme** (en móvil `4rem`, antes `2.75rem`) para que la fila no se despareje cuando un día trae los cinco.
- **Migración `031_indice_recepciones.sql`** (con su reversion): índice `idx_recepcion_fecha (fecha)` en `recepciones`, que no tenía índice de fecha y esa consulta la recorre el calendario.
- **Manual:** sección «Calendario» nueva en `includes/manual.php`, regenerada con `scripts/generar_manual.php`.
- **Pruebas:** `etapa27.php` suma 14 casos (los cinco marcadores, importes, día vacío, desaparición al cancelar/pagar/cobrar y catálogo); `probar_calendario.mjs` suma 7 (los cinco iconos y sus colores, `aria-label`, leyenda, panel del día, «sólo lectura» y que no entran en la lista editable). Regresión en verde.

---

## Ronda — Limpieza de los restos del envío por correo (2026-10-24)

El envío de cotizaciones y ventas por correo ya se había **eliminado del código** en la ronda «Ventas directas y listado unificado (2026-10-17)» (se borraron `Correos.php`, `enviar.php`, la ruta y la sección del detalle), pero quedaban restos por todo el proyecto. Esta ronda los retira para que vuelva a ser coherente con lo que la aplicación hace hoy: **la única salida del documento es el PDF**.

- **Dependencia:** `phpmailer/phpmailer` fuera de `composer.json` y `composer.lock` (`composer remove`).
- **Despliegue (Helm):** bloque `correo:` fuera de `values.yaml` y `values.local.yaml`, las 7 llaves `SMTP_*` fuera de `secret.yaml` y las variables `CORREO_MODO` + `SMTP_*` fuera del `deployment.yaml`. El chart ya no inyecta nada de correo y `helm lint` pasa.
- **Entorno:** bloque `CORREO_MODO` / `SMTP_*` fuera de `.env` (`.env.example` ya no tenía nada); comentario del `Dockerfile` que mencionaba el log de correos corregido.
- **Archivos locales:** borrado `storage/correos.log`.
- **Comentarios de vistas:** `cotizaciones/pdf.php` dejaba de decir que la plantilla era «la misma que adjunta el correo», y el aviso de la aplicación de escritorio ya no lista el correo entre los servicios que deben seguir disponibles en el servidor (`desktop/src/main.js` y `desktop/README.md`).
- **Documentación:** paso «Enviarla» fuera del manual (`includes/manual.php` → `MANUAL_USUARIO.md` regenerado), «PDF y correo» → «PDF» y fila `CORREO_*` fuera de `GUIA.md`, y `cambios/documentacion.md` actualizada en 12 puntos (índice, características, tabla de tecnologías, carpetas, variables de entorno, árbol, rutas —se quitó `/enviar`—, título de la sección 11, borrado de la 11.2, secretos Helm y pasos de despliegue). En `OPENCODE.md` se actualizó la sección técnica «Salida de la cotización»; el **enunciado original** del proyecto se dejó intacto porque es el requerimiento de partida, no código.
- **Pruebas:** `probar_permisos.mjs` ya no apunta a `/storage/correos.log` (archivo inexistente) sino a `/storage/cache-version.json`, igualmente protegida por `.htaccess`.
- **Verificación:** `php -l` en 344 archivos correctos · `composer validate` · `helm lint` sin fallos · regresión en verde (permisos 10/10, gastos masivos 30/30 y el resto de suites `npm run pruebas`). `probar_facturas_reales.mjs` se mantiene fuera porque necesita facturas reales en `~/Descargas` (depende del equipo, no del código).
- **No se tocó:** los campos de correo de clientes, usuarios, proveedores y empresas (son datos de contacto, no envío), ni el texto «por WhatsApp o correo electrónico» de las condiciones de compra, que es copy del negocio.

---

## Ronda — Calendario: el alta de eventos se abre en ventana modal (2026-10-24)

El formulario «Nuevo recordatorio o evento» vivía fijo en el panel derecho y ocupaba la pantalla todo el mes aunque no se fuera a capturar nada. Ahora sólo aparece cuando se pide, en el modal de la aplicación.

- **Cuándo se abre:** haciendo clic una **segunda vez sobre la casilla ya seleccionada** (la primera vez la selecciona y recarga su panel, como siempre), o con el botón **«Nuevo recordatorio o evento»** del panel del día. El modal **no recarga la página**.
- **Se mueve, no se copia:** el formulario sigue servidor-renderizado en la página pero dentro de un contenedor oculto (`[data-alta-calendario-contenedor]`). Al abrir, `calendario.js` lo **mueve** al cuerpo del modal y al cerrar lo devuelve a su lugar. Así los `id` de los campos (`fecha`, `nombre`, `hora`…) siguen siendo únicos y la validación en cliente, que trabaja por `id`, no cambia.
- **Una sola pareja de botones:** dentro del modal se ocultan los `.formulario__acciones` propios del formulario, porque el pie ya trae Cancelar y Guardar.
- **Guarda con validación:** el botón Guardar del modal llama a `requestSubmit()`, que dispara el envío normal (con su token CSRF). Si la validación en cliente falla, **el modal se queda abierto** para corregir. Para eso se agregó el botón a mano en vez de usar `alConfirmar` del módulo `modal.js`: ese botón **cierra siempre**, y romperlo habría afectado a todos los diálogos de la aplicación.
- **Título con el día:** «Nuevo recordatorio o evento · sábado, 24 de octubre de 2026».
- **Pantalla:** el panel del día queda más limpio (resumen del negocio, eventos y el botón de alta).
- **Módulo nuevo:** `src/js/modulos/calendario.js` (se concatena con el resto en `build/js/app.js`). Sin migraciones: no cambia la base de datos.
- **Pruebas:** `probar_calendario.mjs` pasa de 38 a **47 casos**: formulario fuera del panel, botón presente, modal sin recarga, fecha y título correctos, botones propios ocultos, guardado desde el modal, modal que se queda abierto con error y formulario que regresa a su contenedor. `etapa27.php` sin cambios (sólo prueba rutas). Regresión completa en verde.

---

## Ronda — Calendario: iconos clásicos macizos y la Agenda sustituye al panel (2026-10-24)

Tres cambios de presentación pedidos juntos: iconos más grandes y rellenos, la Agenda convertida en el listado del mes y un botón «Ver» por día.

- **Iconos clásicos macizos.** Los cinco marcadores pasan a **Material Icons** (el set **clásico**, estilo **relleno**) en vez de Material Symbols Outlined: se piden macizos. La familia va cargada aparte en `templates/header.php` (`family=Material+Icons`), porque es distinta a Symbols y sólo la usan estos marcadores. Verifiqué antes que los cinco nombres existen en ese set, que está congelado desde 2022: `trending_up`, `receipt_long`, `inventory_2`, `schedule`, `request_quote`.
- **2.5 veces más grandes:** de 12 px a **30 px** en escritorio. Como una celda no cabe tanto, se escalan por ancho para que el calendario siga siendo usable: **30 px** desde 1200 px, **24 px** en tablet y **16 px** en celular. En todos los anchos las casillas quedan de **altura uniforme** (98 / 86 / 86 px).
- **El panel derecho desaparece.** Se quitaron las tarjetas «Lo que pasó ese día» y los eventos del día; el calendario ocupa **todo el ancho**, que además da más espacio a los iconos grandes. Se borraron del SCSS las clases muertas `calendario-panel`, `calendario-dia`, `calendario-negocio` y `resumen-dia__icono`. El botón de alta pasó a la cabecera de la página.
- **La Agenda lista el mes completo.** Une en un solo recorrido los días con operaciones del negocio y los que tienen recordatorios (antes sólo los segundos), en orden. Cada tarjeta trae la fecha, los marcadores con **cantidad e importe** (los mismos iconos clásicos y del mismo color) y los eventos capturados con su edición y baja. Los días sin nada no aparecen.
- **Botón «Ver» por día:** un botón con icono de ojo, **sin texto** (con `aria-label`), que abre el detalle de ese día en **ventana modal**: los marcadores con sus cifras y el conteo de recordatorios, más un botón «Ver en el calendario». Los datos viajan en el atributo `data-agenda-dia` de la propia tarjeta y el modal se construye con `createElement`, sin `innerHTML`, como mantiene el resto de la aplicación.
- **Sincronización:** al cargar con un día elegido, la página baja hasta su tarjeta de la Agenda y la resalta (sólo si no se ve completa, para no mover la pantalla debajo del usuario). El día elegido queda con `agenda-dia--elegido`.
- **Pruebas:** `probar_calendario.mjs` pasa de 47 a **56 casos** (icono clásico, tamaño, Agenda que lista y excluye días, botón «Ver», modal de detalle). Se corrigieron los selectores que aún apuntaban al panel eliminado y se hizo **idempotente**: la sección de marcadores limpiaba a medias, y al volver a correr chocaba con el índice único de folio y token. `etapa27.php` sin cambios. Regresión completa en verde. Sin migraciones.

---

## Ronda — Login: botón para mostrar la contraseña (2026-10-24)

En el formulario de acceso el campo de contraseña no tenía forma de revisar lo que se escribió: un signo de más o de menos pasaba desapercibido hasta que el servidor devolvía «El correo o la contraseña no son correctos».

- **Dónde:** en la tarjeta de `views/login.php`, un botón con icono de ojo al interior del campo (`visibility` / `visibility_off`, Material Symbols, ya cargado en `templates/header.php`).
- **Opt-in, no global:** el botón sólo se construye en los `<input type="password">` que llevan el atributo `data-ver-contrasena`; agregarlo a otro formulario es envolver el input en `.campo__caja` y poner el atributo, sin tocar el JavaScript.
- **Espacio:** nuevo envoltorio `.campo__caja` (posición relativa) con `padding-right` de 2.5rem en `.control`, para que el texto no quede debajo del icono; `.campo__ojo` con `overflow: hidden` por si falla la fuente de iconos.
- **Comportamiento:** sólo alterna el `type` del input entre `password` y `text`; **el valor escrito no se vuelve a tocar**, se conserva la posición del cursor (`selectionStart` / `selectionEnd`) y el foco regresa al campo. Botón `type="button"` sin `name`, así que nunca viaja en el envío. Etiqueta accesible y `title` que cambian con el estado, `aria-pressed` sincronizado e icono `aria-hidden`.
- **Módulo nuevo:** `src/js/modulos/verContrasena.js`, sin `innerHTML` y sin `alert()`; se concatena en `build/js/app.js` y se llama desde `inicializarAplicacion()`. Recompilados `build/css/app.css` y `build/js/app.js` con `npx gulp css js`.
- **Verificación:** con Chromium contra el servidor local, el botón aparece una sola vez, alterna tipo y etiqueta, conserva el texto escrito (`MiClave2026`), mantiene la posición del cursor, no se envía con el formulario y no hay errores de consola; el envío del formulario sigue funcionando. Sin migraciones.

---

## Ronda — El ojo de la contraseña llega al resto de los formularios (2026-10-24)

Extiende a toda la aplicación lo que se agregó al login. El comportamiento es idéntico: un ojo dentro del campo que alterna `password` ↔ `text`, conserva lo escrito, no se envía con el formulario y trae etiqueta accesible y `aria-pressed`.

- **Ahora lo tienen:** `/registro` (contraseña y repetir), `/admin/perfil` (nueva contraseña y repetir), `/admin/usuarios/crear` y `/editar` (contraseña), `/admin/fiscal/configuracion` y `/admin/contabilidad/cierre` («Tu contraseña» de la reapertura). En todos los casos se agregó el envoltorio `.campo__caja` y el atributo `data-ver-contrasena`; el texto de ayuda (`campo__ayuda`) y los mensajes de error siguen debajo del campo, sin moverse.
- **No se tocó** el campo de la firma electrónica del redactor (`rd-sello-pass`): ese panel tiene su propio estilo, tamaño de fuente e iconos (Font Awesome), y ahí el ojo exigiría un diseño propio; queda pendiente por separado.
- **Prueba permanente:** `pruebas/probar_ver_contrasena.mjs` (34 casos en 7 páginas). Crea un usuario temporal en la BD de pruebas, entra al panel y comprueba en cada formulario el número de botones, que alternan tipo y etiqueta, que el texto escrito no se pierde al ocultarlo y que el botón no tiene `name`. El periodo cerrado de `/admin/contabilidad/cierre` se busca en la BD y, si no hay ninguno, esa página se omite en vez de fallar. Se agregó a la lista de navegador de `pruebas/ejecutar_todo.sh`.
- **Verificación:** con `BASE_URL` apuntando a un servidor local contra `cotizaciones_test` → 34 correctas, 0 fallos y sin errores de consola. `php -l` limpio en las seis vistas. Sin migraciones y **sin desplegar**: sólo quedaron los cambios en el código fuente y los assets compilados en `build/`.

---

## Ronda — Fiscal: pestaña «Preliminar básico», el simulador del documento Fiscal 2026 (2026-10-25)

Pestaña nueva en Impuestos que replica el cálculo básico del documento «FISCAL 2026» (Excel), pero alimentada con las facturas reales de la base: sirve para tener una idea rápida de cuánto serían los impuestos por pagar, INDEPENDIENTE del MotorFiscal (la pestaña Impuestos sigue siendo la estimación formal con flujo de efectivo, tarifa art. 106 y catálogos versionados; ninguna de las dos toca a la otra).

- **Qué entra:** sólo ingresos vigentes con `estado_facturacion = 'facturada'` (su mes es el de la **fecha de facturación**, o el de la venta si no la tiene) y gastos vigentes con `comprobante_fiscal = 1` (su mes es el del gasto). Nada de cobros ni pagos: aquí la cuenta es por factura emitida, como el documento.
- **Importes reales, no ÷1.16:** bases con el subtotal real (respectando descuentos) e IVA real por fila (16 %, 8 % y 0 %: las exentas, como el agua potable, entran con IVA 0). IVA por pagar = IVA facturado − IVA acreditable; ISR = **32 % plano** sobre la utilidad del mes, sin acumular (puede salir negativo, como en el documento); impuestos = IVA + ISR; el año **suma los 12 meses**, no se recalcula sobre el total anual.
- **Clase nueva:** `clases/PreliminarFiscal.php` (`anual()`, `clientes()` y `compras()`), registrada en `includes/app.php`; dos consultas agrupadas por mes y los listados del mes con totales. Nada de migraciones: todo se lee de `ingresos` y `gastos`.
- **Pantalla:** `views/admin/fiscal/preliminar.php` (ruta `GET /admin/fiscal/preliminar`, chip «Preliminar básico» en `templates/tabsFiscal.php` junto a Impuestos, icono `calculate`). Selector año + mes, tarjetas del mes, la tabla fiscal con los 9 renglones del documento (columnas ENE–DIC + Año, mes elegido resaltado y encabezados clicables), «¿Cómo se calcula?» en `details`, y abajo los dos listados de ese mes: **Clientes facturados** y **Proveedores y compras facturadas** (fecha, folio/concepto, S/IVA, IVA, C/IVA + fila TOTAL), como las hojas mensuales del Excel.
- **PDF:** botón «Exportar PDF» arriba a la derecha (slot `acciones` del header, como Estadísticas). `tipo=preliminar` en `views/admin/fiscal/exportar.php` → `Documentos::descargarPreliminarFiscal()` + plantilla nueva `templates/pdfFiscalPreliminar.php` (horizontal: tabla fiscal del año + los dos listados del mes, con aviso de que es simulador). Sólo PDF, como se pidió. El signo negativo va con guion ASCII (Helvetica de dompdf no trae el U+2212 que usa la pantalla).
- **Pruebas:** `pruebas/php/etapa42.php` (27 casos, agregada a `pruebas/ejecutar_todo.sh`), con contribuyente propio para importes exactos: sólo facturadas vigentes entran (no facturada y cancelada fuera), la venta del 28-ene facturada el 2-feb cuenta en FEBRERO, descuentos respetados, exenta con IVA 0 y compra con IVA 8 % reales, mes negativo permitido, año = suma de meses (240 + 368 + 24 = 632), listados con totales, chip/ruta/exportar presentes y dompdf componiendo bytes `%PDF` reales (reflexión sobre `componer`). Idempotente (segunda corrida en verde). Smoke con servidor local: login → pantalla 200 con chip activo y tablas → PDF `application/pdf` de 16.5 KB.
- **Regresión:** `PUERTO=8042 pruebas/ejecutar_todo.sh --rapido` (puerto 8017 ocupado por el servidor de desarrollo de otra sesión) → 65 correctas · 3 fallos, los tres ajenos a esta ronda: `etapa27` (su «día sin operaciones» es el 25 del mes y hoy 25-oct la base real tiene operaciones del día), `etapa38` (la reversión `038_reversion.sql` del módulo de seguimiento de entregas en curso borra datos de `clientes_rfc`) y `probar_duplicados` (falta `~/Descargas/gastos/factura_prueba.pdf` en el entorno). Sin migraciones y **sin desplegar** (así se pidió: otra sesión trabaja en el repositorio).

---

## Ronda — Módulo de seguimiento de entregas (2026-10-25)

Después de la venta y de la «Pantalla de entrega» faltaba el paso de *qué pasó realmente con la entrega*: cuándo se entregó, qué observaciones trae y qué se hizo cuando no llegó todo (faltantes, piezas dañadas o incompletas, cambios y devoluciones), incluida la corrección de la factura cuando por lo entregado cobró distinto.

- **Pantalla nueva «Seguimiento de entregas»** (`/admin/entregas-seguimiento`, en el menú y como pestaña de la pantalla de entrega). El listado trae los filtros por estado, texto y negocio; el detalle de cada venta reúne fecha de entrega y observaciones (editables en el sitio), productos **vendido vs. entregado vs. afectado por incidencias**, incidencias con sus renglones, devoluciones y cambios, facturación del ingreso y la línea de tiempo del historial (entregas, devoluciones, incidencias, cambios y sustituciones de factura, todo desde `auditoria`).
- **Estados generales por venta** (`ventas.entrega_estado`, mantenidos en código): *Pendiente de entregar* mientras quede pieza por entregar; *Entregado con incidencias pendientes* cuando todo salió pero hay incidencias abiertas; *Entregado · incidencias resueltas*; y *Entregado*. Se recalcula al entregar, al devolver, al entregar un cambio y al abrir/cerrar incidencias; la migración 038 también deja en su sitio a las ventas previas. El detalle adquiere `fecha` y `observaciones` de entrega (`entrega_fecha`, `entrega_observaciones`).
- **Incidencias** (`entrega_incidencias` + `entrega_incidencia_renglones`, clase `IncidenciasEntrega`): motivo de cinco opciones —*producto faltante, producto dañado o defectuoso, producto incompleto, cambio o sustitución, devolución*—, piezas afectadas por producto y si se le debe un reemplazo al cliente. Al registrar, **el producto regresa al inventario en la misma transacción** (entrada con motivo `devolucion` ligada a la venta, que es lo que la venta descontó al registrarse) y se anota en `venta_devoluciones`, así el pendiente de entrega se ajusta solo y una entrega incompleta ya no queda «a deber». El reemplazo queda como deuda de cambio y se descuenta al entregarlo (flujo existente de cambios). La venta y su importe **no cambian**.
- **Sustitución de factura**: si la incidencia marca que hay que cancelar la factura y subir la correcta, al resolverla se suben el PDF y el XML nuevos (`Archivos`, con validación de duplicados por UUID), se dan de baja los documentos anteriores con motivo, y el ingreso se sincroniza con el XML: folio comercial, UUID, fecha de facturación, subtotal, descuento, IVA y total (leídos del CFDI), siempre que el periodo contable esté abierto. Los cobros ya registrados no se tocan: si quedaron de más que el nuevo total, la pantalla lo advierte con enlace a los cobros de la venta.
- **Centro de seguimiento**: aviso nuevo «Incidencias de entrega abiertas» (prioridad alta si alguna exige corregir la factura) con enlace directo al seguimiento de la venta.
- **Pruebas:** `pruebas/php/etapa43.php` (40 casos: listado y filtros, registro y devolución al inventario, estados, fecha y observaciones, rechazos, historial, sustitución de factura con baja de documentos y sincronía del ingreso, y limpieza al final; agregada a `pruebas/ejecutar_todo.sh`). Además, recorrido E2E contra el servidor local: registro y resolución de una incidencia desde las pantallas con sus alertas y estados en BD. Migración `038_seguimiento_entregas.sql` con su reversión. Recompilado `build/css/app.css`.

---

## Ronda — Registro inteligente: los cuatro datos del CFDI se autocompletan solos (2026-10-25)

Los botones «Registro Venta Inteligente» y «Registro compra inteligente» leen el documento con el mismo motor (`LectorDocumental` → `CoincidenciasFactura`) y sólo llevaban al formulario **forma y método de pago** —la forma, además, en un campo oculto—. El **uso del CFDI** se guardaba si venía de un XML subido en el propio formulario, pero no se leía del documento para proponerlo, y el **tipo de comprobante** no existía en ninguna pantalla ni en la base. En la venta no llegaba ninguno: al «Revisar y facturar», método y forma salían vacíos y el uso dependía de la ficha del cliente, no de la factura.

- **Se leen los cuatro aparte del cotejo (ese es el cambio de fondo):**
  - `LectorDocumental::CAMPOS` crece con `uso_cfdi` y `tipo_cfdi`, junto a `forma_pago` y `metodo_pago` que ya estaban. El XML los entrega con confianza «alta»; en PDF y OCR se leen junto a su etiqueta («Uso del CFDI: G03», «Tipo de comprobante: Ingreso») con `tipoDesdeTexto()`, que acepta la clave del SAT o su nombre en español y devuelve vacío en vez de adivinar. Al ser campos del motor, aparecen también en la pantalla de revisión de una lectura, con su etiqueta y su confianza.
  - `CoincidenciasFactura::analizar()` los devuelve en la propuesta (`uso_cfdi`, `tipo_cfdi`) junto a `forma_pago` y `metodo_pago`: **salen aunque el cruce no reconozca ni al cliente/proveedor ni a un solo producto**, porque son datos del comprobante y no del cotejo.
  - `Lecturas::datosCfdi()`: si la lectura guardada no trae los dos campos nuevos (se hizo con el motor anterior) se releen del XML original, sin tocar el resto de lo leído. Si ya se leyeron y salieron vacíos, no se reintenta: el documento no trae el dato.
- **Gasto: los cuatro, visibles y precargados** (`templates/formularioGasto.php`, alta y edición):
  - «Forma de pago del CFDI» deja de ir oculta y pasa a `select` con el catálogo `VentasFiscal::FORMAS_PAGO` (01 efectivo … 99 por definir); «Tipo de comprobante (CFDI)» es nuevo, con `Contabilidad::TIPOS_CFDI` (I, E, N, P, T), el **único** catálogo del tipo — lo comparten gasto y venta para no repetir dos listas que se desincronicen. Los cuatro viven junto a uso y método y se ocultan cuando el gasto es «Sin factura».
  - El alta los precarga desde la lectura; si subes el XML en el mismo formulario, completa los que vengan vacíos. `Gastos::normalizar()` valida los dos nuevos contra su catálogo y, si el gasto queda sin factura, limpia los cuatro (antes sólo uso y método); la edición los guarda con su bitácora. El detalle del gasto los muestra.
- **Venta: los cuatro pasan a «Facturación y cobro»:**
  - El formulario de venta conserva la lectura en su `action` (`?lectura=N`, igual que el de gasto) y, al guardar, `VentasFiscal::guardarDatosLeidos()` escribe método, forma, uso y tipo en el ingreso: **sólo lo que venga en el catálogo del SAT**, sin pisar lo que no se pide y con auditoría `datos_cfdi`.
  - La revisión previa a facturar trae ya elegidos uso, método, forma y **tipo de comprobante** (campo nuevo en el modal, con su `env-tipo`); `views/admin/ventas/fiscal.php` lo guarda. `VentasFiscal::validar()` prefiere el uso del CFDI del ingreso antes que el de la ficha del cliente: es el que declaró el comprobante de ESTA venta.
- **Migración `039_cfdi_comprobante.sql`** (con su `039_reversion.sql`, aplicada a la base real): `gastos.tipo_cfdi CHAR(1)`; `ingresos.uso_cfdi VARCHAR(4)` e `ingresos.tipo_cfdi CHAR(1)`. Las tres columnas son nulas: sin dato, la captura sigue siendo manual.
- **Manual:** «Uso del CFDI y método de pago» pasa a «Los cuatro datos del CFDI» y «Facturación y cobro» añade «Revisar y facturar» (`includes/manual.php` y su reflejo en `MANUAL_USUARIO.md`, editado a mano porque el archivo trae secciones que `generar_manual.php` todavía no genera).
- **Pruebas:**
  - `pruebas/php/etapa44.php` — 30 comprobaciones: las tres columnas nuevas; el XML y el PDF con los cuatro datos (y que sin la etiqueta no inventa nada); que llegan aunque el cruce no reconozca a nadie; la lectura antigua completada releyendo el XML; el gasto que los guarda, rechaza claves fuera de catálogo, los limpia sin factura y los edita; `guardarDatosLeidos()` que escribe lo válido y no toca lo inválido, con su bitácora; la validación que usa el uso del CFDI antes que el de la ficha; y las pantallas. Agregada a `pruebas/ejecutar_todo.sh`.
  - `pruebas/probar_inteligente.mjs` — de 38 a 47 comprobaciones: los cuatro campos precargados y visibles en el formulario de gasto y guardados en la fila, el `action` de la venta con su lectura, la venta registrada con los cuatro datos en el ingreso y la revisión previa que los trae elegidos (el cliente de prueba no trae uso en su ficha, así que lo que se ve sale del documento).
- **Regresión:**
  - `DB_NAME=cotizaciones_test php pruebas/php/etapa44.php` → **30/30 en verde** (corrida también al final, después del ajuste de `datosCfdi()`).
  - `pruebas/probar_inteligente.mjs` → **47/47** contra servidor local (`php -S 127.0.0.1:8017`), sin errores de consola.
  - Suite PHP completa (las 40 etapas de `ejecutar_todo.sh`) con esta ronda: fallan **3, 16, 27, 38 y 43** — y **exactamente las mismas cinco fallan en el baseline `HEAD`** con la misma base, así que no hay regresión: 27 (día sin operaciones) y 38 (reversión 038 vs `clientes_rfc`) ya se reportaron en rondas anteriores, y 3, 16 y 43 dependen del stock real de la base.
  - Suite de navegador (28 pruebas): **23 en verde** incluido `probar_inteligente`; las 4 caídas — `probar_etapa3`, `probar_devoluciones`, `probar_compra_catalogo` y `probar_duplicados` (falta `~/Descargas/gastos/…pdf`) — **se reproducen igual en `HEAD`**. La quinta, `probar_etapa4`, falla sólo en el árbol de trabajo actual, donde **otra sesión está refactorizando el módulo de entregas** (`views/admin/entregas/entregar.php` pasó a usar el nuevo `templates/formEntregaVenta.php`); esta ronda no toca entregas ni inventario.
  - Ojo con la base de pruebas: `ejecutar_todo.sh` vuelca la base **real**, que hoy trae 2 filas de `entrega_incidencias` (creadas a las 16:13 y 16:21 de hoy) y eso rompía con FK el `DELETE FROM ventas` de 16 pruebas. Quitando esas filas **sólo de `cotizaciones_test`** esas 16 vuelven a verde; en la base real no se tocó nada.
  - Migración `039` aplicada a la base real (`php scripts/migrar.php`). **Sin desplegar**.

---

## Ronda — Operación Comercial: un solo apartado para vender, entregar y dar seguimiento (2026-10-25)

La operación post-venta estaba repartida en **tres secciones del menú** —Pantalla de entrega, Seguimiento de entregas y Ventas y devoluciones— que son en realidad un mismo flujo: alistar pedidos, entregar, reportar lo que pasó, devolver o cambiar y, si hace falta, refacturar. Cada acción obligaba a saltar de una pantalla a otra, las devoluciones tenían dos vías (detalle de venta y seguimiento) y el detalle de la venta ni siquiera enlazaba al seguimiento. Ahora es **una sola sección, «Operación Comercial», con un hub por venta** y las reglas de inventario/factura claras por tipo de incidencia.

- **Un solo menú.** `templates/sidebar.php`: las tres entradas se reemplazan por **«Operación Comercial»** (`/admin/entregas` con `prefijos` hacia `/admin/entregas-seguimiento` y `/admin/ventas`); la barra inferior de móvil marca el mismo ícono para las tres familias de rutas (y `/admin/ventas` ya no vive en «Más»). `templates/tabsEntregas.php` pasa a **4 pestañas** —Preparación de pedidos · Entregas por cliente · Seguimiento de entregas · Ventas— impresas también en `/admin/ventas`. Las rutas no cambian: enlaces externos, Centro de seguimiento, pruebas y manual siguen igual.
- **El hub es el único lugar operativo.** La hoja `/admin/entregas-seguimiento/venta?id=N` (estado, productos vendido/entregado, incidencias, facturación, devoluciones, historial) gana los tres botones que antes obligaban a navegar, todos en **ventana modal** con el formulario real dentro:
  - **Registrar entrega** (antes página aparte y, desde seguimiento, con un enlace roto `?id=` que daba 404);
  - **Registrar incidencia** (antes página aparte);
  - **Registrar devolución** (antes sólo desde el detalle de la venta).
  Cada botón sigue siendo un `<a href>`: sin JavaScript se navega a la página suelta, que se conserva como respaldo. Nuevo módulo `src/js/modulos/operacionComercial.js` (mismo patrón «mover el formulario al modal» que ya usaba el Calendario), llamado desde `inicializarAplicacion()`. `modal.js` ya no intenta enfocar inputs ocultos (los formularios empiezan con campos `type="hidden"`).
- **Registrar entrega sólo desde el hub.** Las pantallas de preparación no registran a ciegas: `templates/vistaPreparacion.php` gana la variable `$entregaHaciaSeguimiento` y `/admin/entregas/ver` la activa, de modo que su botón **lleva a `/admin/entregas-seguimiento/venta?id=N`** en vez de a la página suelta `/admin/entregas/entregar` (que al confirmar regresaba a `/admin/entregas/clientes` y dejaba la venta sin incidencias ni historial si faltaba producto). Igual en `/admin/entregas/clientes`. `/admin/entregas/detalle` conserva el enlace directo con `&prep=` (flujo de la preparación guardada y su prueba de navegador).
- **Una sola vía de devoluciones.** El detalle de venta deja de duplicar el bloque de devoluciones: queda como documento de lectura con el botón «Operación de esta venta» (y un enlace en el cuerpo) hacia el hub. `Seguimiento::avisos()` enlaza el «Cambio por entregar» al hub (antes a `detalle#cambios`, ancla que ya no existe) —de paso se corrigió un **bug preexistente** de esa consulta (seleccionaba `v.id` pero el código leía `$f['venta_id']`: el aviso salía con enlace a `id=0` y con dos warnings).
- **Reglas de negocio por motivo (las acordadas con el negocio):**
  - **Producto faltante / incompleto** (p. ej. se vendieron 10 y se entregaron 8): las piezas que faltan **regresan al inventario** (entrada por devolución ligada a la venta) y, si la venta ya está facturada, la **corrección de factura se fuerza** aunque el formulario no marque la casilla (`IncidenciasEntrega::MOTIVOS_INVENTARIO` / `MOTIVOS_CORRECCION` + validación en `registrar()`). Al resolver, sustituir el CFDI actualiza folio, UUID, fecha y montos del ingreso (como ya lo hacía).
  - **Dañado / cambio / devolución**: **sólo notificación** — `IncidenciasEntrega::registrar()` ya no hace entrada de inventario para esos motivos, `Inventario::registrarDevolucion()` ya no mueve el almacén (sólo anota en `venta_devoluciones`, ajustando pendiente y «lo debido») y `Inventario::entregarCambio()` ya no descuenta pieza alguna al marcar el cambio entregado. El kardex sólo se toca en la venta (salida), en faltante/incompleto (entrada) y en ajustes manuales.
  - Formularios compartidos `templates/formEntregaVenta.php`, `formIncidenciaVenta.php` y `formDevolucionVenta.php` (usados por las páginas sueltas Y por los modales: una sola marca de campos), con textos que explican la regla en cada pantalla.
- **Contabilidad blindada (hallazgo de la auditoría).** La sustitución de factura sí actualizaba montos en `ingresos`, **pero una edición posterior de la venta los pisaba en silencio**: `Contabilidad::sincronizarIngreso()` copiaba `subtotal/descuento/iva/total` desde la venta siempre que difirieran. Ahora, si el ingreso tiene factura emitida (`factura_documento_id` o `facturada` con UUID), los montos quedan **congelados en los del CFDI** y se sincroniza sólo fecha/negocio/cliente/estado; la diferencia se muestra como **aviso no bloqueante** (`Contabilidad::getAvisos()`, drenado en `Ventas::editar` con `agregarAlerta`). Aviso persistente de «factura ≠ venta» en la hoja del hub, en `facturacionVenta` y como badge **«≠ venta»** en el listado de Ingresos (`listarIngresos` ahora trae `v.total`).
- **Pruebas:**
  - `etapa43` **hecha determinista**: antes reusaba una venta real y, sin 2 piezas disponibles, **omitía el caso de sustitución** (la aserción de montos no se ejecutaba); ahora crea su propia venta de 5 piezas y la borra al final. Suma: faltante fuerza `factura_requerida`, devolución notificada sin stock ni kardex, **editar la venta no revierte los montos de la factura** y deja el aviso correspondiente.
  - `etapa16` y `etapa3` (PHP) + `probar_devoluciones` y `probar_etapa3` (navegador) actualizados a la regla «sin inventario» y al flujo por el hub.
  - **Nueva** `pruebas/probar_operacion.mjs` (23 casos): un solo menú, 4 pestañas, detalle enlazando al hub, y los **tres modales de punta a punta** (faltante con corrección forzada por el servidor + inventario +2; devolución sin movimiento). Registrada en `ejecutar_todo.sh`, que además **deja fuera del volcado las `entrega_incidencias` reales** (FK RESTRICT rompía el `DELETE FROM ventas` de 16 pruebas).
  - `Seguimiento::NOVEDADES` agrega la **1.5.0** (Operación Comercial).
- **Documentación:** manual (`includes/manual.php` → `MANUAL_USUARIO.md` regenerado) con la sección «Operación Comercial» y sus 5 pasos (incluye la regla faltante vs. notificación y el criterio «la factura manda en contabilidad»), más `GUIA.md`. **Sin migraciones nuevas** de esta ronda (todo lo necesario ya existía).
- **Regresión:** `PUERTO=8017 pruebas/ejecutar_todo.sh --rapido` → **66 correctas · 5 con fallos**, de los cuales 2 fueron por corrida con archivo a medias y quedaron en verde al re-ejecutarlos: `php etapa3` (TODO OK con la aserción nueva) y `probar_etapa3` (16/16 con el flujo por el hub). Los 3 restantes son **preexistentes y ajenos**: `etapa27` (su «día sin operaciones» es el 25 y hoy la base real tiene operaciones), `etapa38` (su reversión borra `clientes_rfc`) y `probar_duplicados` (falta `~/Descargas/gastos/factura_prueba.pdf`). Destacan en verde: `etapa16`, `etapa43` (sustitución determinista), `probar_devoluciones` (16), la nueva `probar_operacion` (23) y `probar_etapa4`/`probar_etapa5` intactos. Sin desplegar.


---

## Ronda — Gastos: buscar el proveedor por nombre o RFC y darlo de alta sin salir del formulario (2026-10-25)

En «Pagado a» del gasto, elegir «Proveedor» abría un `<select>` plano con sólo los nombres: no se podía buscar por escrito, no se veía el RFC y, si el proveedor no existía, había que salir al directorio, crearlo y volver a empezar el gasto a mano. Ahora el campo es un **buscador por nombre y RFC** y ofrece **dar de alta el proveedor ahí mismo**, regresando al gasto con lo capturado.

- **Acción 1 · combobox por nombre y RFC** (`templates/formularioGasto.php`, alta y edición):
  - El `<select id="proveedor_id">` lleva `data-buscador` y `data-buscador-entidad="proveedor"`, de modo que `src/js/modulos/selectorBusqueda.js` —el mismo que ya usa el selector de Cliente del cotizador— lo convierte en un campo de búsqueda con lista filtrada. Cada `<option>` muestra ahora la `etiqueta` (`nombre · RFC`) y arrastra `data-rfc`; debajo del campo, el nuevo módulo imprime «RFC del proveedor: …» del elegido. Sin JavaScript sigue viéndose el `<select>` normal.
  - `Proveedores::paraSelector()` devuelve además `'rfc'` aparte (antes sólo lo incrustaba dentro de la etiqueta): cambio aditivo, lo consumen unas 15 vistas.
  - `selectorBusqueda.js` despacha `buscador:resultado` (`{total, texto}`) tras cada filtrado, y sus avisos de «sin coincidencia» ya no dicen «cliente» a secas: los dicta `data-buscador-entidad` (por defecto sigue siendo «cliente», así que el cotizador no cambia).
- **Acción 2 · alta que regresa con el gasto precargado:**
  - Botón `data-registrar-proveedor`, **sólo en el alta del gasto** (la edición no lo trae), colocado **encima** del campo —la lista desplegable se abre hacia abajo y lo taparía— y visible cuando lo escrito no coincide con ningún proveedor, o cuando el directorio está vacío. Su texto repite lo capturado («¿No existe «…»? Regístralo»).
  - Nuevo módulo `src/js/modulos/gastoProveedor.js` (llamado desde `inicializarAplicacion()` **después** de `prepararSelectoresBusqueda()`): guarda un borrador de todos los campos del gasto —menos `token` y los archivos— en `sessionStorage` con 30 minutos de vigencia, navega a `/admin/proveedores/crear?regresar=<url actual>&nombre=<texto>&rfc=<RFC emisor del CFDI>` y, al volver, restaura los campos, avisa si había archivos que volver a subir y borra el borrador. También lo borra al enviar el gasto.
  - `views/admin/proveedores/crear.php` acepta `regresar`, **validado contra la ruta exacta** de creación de gasto (con su `?lectura=N`) para que el parámetro no se convierta en una redirección abierta: precarga nombre y RFC (si lo escrito era un RFC, va al campo de RFC), «Volver» y «Cancelar» apuntan de vuelta al gasto y, al guardar, redirige a esa ruta con `?proveedor=<id>&proveedor_nuevo=1`. `templates/formularioProveedores.php` gana la variable `$cancelar`.
  - `views/admin/contabilidad/gastos/crear.php` lee ese parámetro y deja el proveedor recién creado **ya elegido** en «Pagado a» (después del bloque de la lectura inteligente, para que no lo pise). El resto de los campos los repone el módulo en el cliente.
- **Textos:** la ayuda de «Pagado a» ya no dice «sólo elige Proveedor si lo tienes en tu directorio», y el error de `Gastos::crear` invita a buscar por nombre/RFC o a registrar el proveedor.
- **Manual:** `includes/manual.php` (y su reflejo `MANUAL_USUARIO.md`) añade «¿A quién le pagaste? (proveedores)» dentro de «Anotar un gasto».
- **Sin migraciones y sin rutas nuevas:** se reutiliza `GET/POST /admin/proveedores/crear`. Se recompilaron `build/js/app.css` y `build/js/app.js` (más la utilidad `.mb-sm`, que faltaba en el par con `.mt-sm`).
- **Pruebas:** nueva `pruebas/probar_proveedor_gasto.mjs` registrada en `pruebas/ejecutar_todo.sh` y en `npm run pruebas`: buscador que filtra y muestra el RFC, sin botón cuando hay coincidencia, botón con el texto capturado cuando no la hay, alta con nombre y RFC precargados, vuelta con el proveedor elegido y con concepto/total/comprobante restaurados, borrador consumido, gasto guardado ligado a ese proveedor, directorio vacío y cero errores de JavaScript.
- **Regresión:** `PUERTO=8041 pruebas/ejecutar_todo.sh --rapido` → **69 correctas · 3 con fallos**, y los tres son **preexistentes y ajenos** (mismas causas ya documentadas): `php etapa27` (su «día sin operaciones» es el 25 y hoy la base real tiene operaciones), `php etapa38` (su reversión borra `clientes_rfc`) y `probar_duplicados` (falta `~/Descargas/gastos/factura_prueba.pdf`). La nueva `probar_proveedor_gasto` pasa con **28/28** dentro de la suite; en verde también `probar_categorias_gasto` (37), `probar_ronda` (50), `probar_ronda2` (67), `probar_inteligente` (47) y `probar_gastos_masivos` (30), que son los que ejercitan el buscador y el formulario de gasto que toca esta ronda. **Sin desplegar.**

---

## Ronda — Subir facturas en ventana modal y aviso de incidencias en el detalle de la venta (2026-10-25)

Dos pantallas enseñaban el `<input type="file">` pegado en el formulario, con un aspecto distinto al del resto de diálogos de la aplicación, y el detalle de la venta ni siquiera avisaba que una entrega seguía con incidencias sin resolver.

- **Ventana modal para subir archivos** (nuevo módulo `src/js/modulos/archivosModal.js`, llamado desde `inicializarAplicacion()`):
  - Cada botón `[data-archivo-modal="id-del-input"]` abre el **mismo `modal__panel`** que «Registro Venta Inteligente» (sobre-título, título, cuerpo y pie Cancelar / Listo) con el bloque `<div class="campo">` **real** dentro: al cerrar el diálogo el bloque vuelve a su hueco, de modo que los archivos elegidos siguen viajando con el formulario. Para eso el `<input type="file">` lleva el atributo `form="…"` —indispensable, porque el modal vive fuera del `<form>`— y así lo comprueba el servidor al adjuntar.
  - **Sin JavaScript no cambia nada:** el botón nace con `hidden` y el campo sigue visible en la página; el módulo sólo lo sustituye si existe `abrirModal`.
  - El `change` del campo deja junto al botón el resumen de lo elegido («1 archivo elegido: …»).
- **Dónde se usa:** `templates/facturacionVenta.php` («Subir factura (PDF y/o XML)», sección *Facturación y cobro* de `/admin/ventas/detalle`, con `id="form-facturacion"` en el propio template) y `templates/formularioGasto.php` («Archivo de la factura o comprobante (opcional)» de `/admin/contabilidad/gastos/crear`, con `id="form-gasto"` en la vista). El detalle del ingreso (`/admin/contabilidad/ingresos/detalle`) y `documentosOperacion` **conservan su campo en línea**.
- **Aviso de incidencias de entrega en `/admin/ventas/detalle`:** la página consulta `IncidenciasEntrega::deVenta()` y muestra, arriba, una nota con el número de incidencias **abiertas** y sus motivos; si alguna trae `factura_requerida` la nota sube a `nota--error` y lo dice. Siempre enlaza a `/admin/entregas-seguimiento/venta?id=N#incidencias`: aquí **sólo avisa**, se resuelven en el hub.
- **Manual:** `includes/manual.php` (y `MANUAL_USUARIO.md` regenerado con `scripts/generar_manual.php`, sólo cambiaron esas dos líneas) — «Subir la factura» y «Subir la factura de la venta» ahora explican que la subida ocurre en una ventana.
- **Verificación:** `probar_etapa5` **51/51** (la subida de `#doc-nuevo` desde el modal y la facturación de la venta siguen intactas), `probar_etapa4` **24/24** y `probar_operacion` **23/23**; además una corrida de navegador sobre los tres cambios (aviso con enlace, modal de la venta y modal del gasto, con la subida real del archivo desde el diálogo). `etapa28` y `etapa44` en verde; `etapa40` falla en «cada RFC existente quedó como principal de su ficha» por estado de la base de pruebas —`probar_etapa5` escribe `clientes.rfc` con SQL crudo y no toma `clientes_rfc`—, no por esta ronda.

---

## Ronda — Del «concepto sin producto equivalente» al catálogo del proveedor (2026-10-25)

Al leer la factura de un proveedor, los renglones que no coinciden con **tu** catálogo salían en «Conceptos sin producto equivalente» con un solo camino: buscarlos a mano o crearlos en tu catálogo. Y quien te vendió ese artículo **ya tenía su propio catálogo** (`proveedor_productos`), que el motor nunca consultaba. Ahora cada renglón trae **«Agregar al proveedor»** y, además, el cotejo aprende de él.

- **Acción 1 · alta rápida sin recargar:**
  - Nuevo partial compartido `templates/sinEquivalente.php` (extraído de las líneas 45-92 de `avisoLectura.php`) con la tabla de conceptos sin equivalente y sus DOS acciones: «Buscar en el catálogo» (la de siempre) y la nueva **«Agregar al proveedor»**, que sólo aparece si hay un proveedor **activo** reconocido como emisor. Se muestra el nombre del proveedor en la ayuda y en el `title` del botón.
  - Lo incluyen las dos pantallas que lo piden: `templates/avisoLectura.php` (formulario de gasto) y `views/admin/compras/inteligente-revision.php` (Compras inteligentes). En el formulario de gasto el proveedor se toma del que ya está elegido en «Pagado a» (`$gasto['proveedor_id']`), no sólo del que detectó el motor: así también funciona cuando acabas de registrar a Pablo con el botón «Regístralo» de la ronda anterior. En `/admin/ventas/crear` no se ofrece (ahí el emisor soy yo).
  - **Ruta JSON nueva** `POST /admin/compras/catalogo/agregar` → `views/admin/compras/catalogo/agregar.php` (`soloAdmin`, contesta `{ok, estado, id, nombre, activo, proveedor, ruta, ruta_listado}`). CSRF por cabecera `X-CSRF-Token` (lo acepta `verificarCsrf()`), **sin** token de un solo uso porque el botón se puede apretar varias veces. Sin migraciones.
  - `ProductosProveedor::agregarDesdeFactura()` arma `nombre` (descripción ≤150), `codigo` (el SKU del renglón), `costo_unitario` (el precio unitario del documento), `descripcion` (la nota/artículo del SAT) y `activo = 1`. **El duplicado no es un error**: contesta `creado` / `existente` comparando el nombre con `CoincidenciasFactura::normalizar()` (sin acentos ni mayúsculas) y también por código; `crear()` sigue poniendo la auditoría.
  - `src/js/modulos/inteligente.js` delega el `click` en `[data-agregar-proveedor]`, manda el `fetch` y **sustituye el botón en la misma celda** por «✓ Agregado al catálogo de Pablo» (+ enlace al catálogo) o «Ya estaba…», o un `.campo__error` si falla. **No recarga**: recargar perdería lo capturado en el formulario del gasto. `createElement`, sin `innerHTML`, sin `alert/confirm/prompt`.
- **Acción 2 · la 2ª pasada del cotejo (lo que lo hace útil la próxima vez):** hoy `CoincidenciasFactura::catalogo()` sólo leía `productos`, así que un concepto recién agregado al proveedor seguía saliendo en la lista. Ahora:
  - `analizar()` llama a `cotejarCatalogoDeProveedor()` cuando reconoció al emisor como proveedor: lo que no casó con el catálogo propio se coteja con `proveedor_productos` **con las mismas reglas** (SKU exacto, nombre exacto, similitud ≥ 85 % o cobertura de tokens ≥ 85 %). Se extrajo `buscarProducto()` para que los dos catálogos compartan el código.
  - Un concepto que así casa sale de `sin_equivalente` y entra a `lineas` con `origen = «catálogo del proveedor · …»`.
  - El renglón traído del catálogo del proveedor lleva `id = 0` **a propósito**: no es un producto propio y sus claves no significan lo mismo (se marca además con `proveedor_producto_id` dentro del `producto`).
  - `cotejarCatalogoDeProveedor()` es pública e idempotente y vuelve a armar `lineas`/`sin_equivalente`: la usa `ComprasIntel::analizar()`, que resuelve al proveedor por RFC **después** del motor (búsqueda que no filtra `activo = 1`).
  - `conceptos()` ahora entrega también `sku` (antes lo descartaba: hace falta para el `codigo`) y `confianza_lectura`; `CoincidenciasFactura::normalizar()` pasa a `public`.
  - ⚠️ **Cambio de comportamiento asumido:** `ComprasIntel::clasificar()` cuenta `lineas` para decidir «Posible compra de materiales o mercancía», así que una factura cuyos conceptos viven en el catálogo del proveedor ahora clasifica como material; su motivo ya dice «de tu catálogo o con los que ya diste de alta a este proveedor».
- **Texto corregido de paso:** el ayuda del partial ya no dice «a la gasto» (`$etiquetaDoc`: el gasto / la venta / la compra) y en la revisión de Compras inteligentes omite «entraron a…», donde no aplica.
- **Manual:** `includes/manual.php` (→ `MANUAL_USUARIO.md` regenerado con `php scripts/generar_manual.php`) añade el paso «Conceptos sin producto equivalente» en *Anotar un gasto* y un punto sobre «Agregar al proveedor» en *Catálogo de semiterminados por proveedor*.
- **Pruebas:**
  - Nueva **`pruebas/php/etapa45.php` (31/31)**, registrada en `ejecutar_todo.sh`: alta con nombre/código/costo/activo, duplicado normalizado y por código, descripción vacía, proveedor desactivado, la 2ª pasada (sale de «sin equivalente» con el criterio de procedencia y `id = 0`), los dos controles (sin proveedor / concepto que tampoco está en SU catálogo), la pasada tardía con el proveedor resuelto después, idempotencia, y las pantallas y la ruta.
  - Nueva **`pruebas/probar_catalogo_proveedor.mjs` (20/20)** registrada en `ejecutar_todo.sh` y en `npm run pruebas`: se lee la factura del proveedor → botón presente → click → fila en `proveedor_productos` con nombre, SKU y precio → el formulario del gasto sigue intacto → reenvío contesta `existente` → **al recargar la misma lectura el concepto ya no aparece en la lista** → y lo mismo desde la revisión de Compras inteligentes.
  - Pasa igual `probar_proveedor_gasto` (28/28), `probar_categorias_gasto` (37/37) y `etapa24`/`etapa44`.
- **Sin migraciones ni rutas nuevas** salvo la JSON: todo son tablas que ya existen.
- **Regresión:** `PUERTO=8042 pruebas/ejecutar_todo.sh --rapido` → **71 correctas · 3 con fallos**, y los tres son **preexistentes y ajenos** (mismas causas ya documentadas): `php etapa27` (su «día sin operaciones» es el 25 y hoy la base real tiene operaciones), `php etapa38` (su reversión borra `clientes_rfc`) y `probar_duplicados` (falta `~/Descargas/gastos/factura_prueba.pdf`). Dentro de esa corrida pasan **`php etapa45` (31/31)** y **`probar_catalogo_proveedor` (20/20)**, además de `probar_proveedor_gasto` (28), `probar_categorias_gasto` (37), `probar_compras` (61), `probar_catalogo` (29), `probar_compra_catalogo` (35), `probar_ronda` (50) y `probar_ronda2` (67). Fuera de la suite (no está en `ejecutar_todo.sh`), `probar_inteligente.mjs` → **47/47**. **Sin desplegar.**

⚠️ **Ajenos a esta ronda (trabajo en curso en el árbol):** durante la corrida `clases/Panel.php` se quedó sin `ventasPorCobrar()` y `/admin` devolvió 500 un rato; ya quedó corregido en `views/admin/index.php` (18:55) y la regresión salió limpia de eso. Los tres fallos de arriba son los de siempre.

---

## Ronda — Panel: el «Resumen general» en el orden en que se atiende (2026-10-25)

`/admin` abría con una fila de indicadores, seguida de los accesos rápidos, varias listas de actividad y las ganancias: para saber qué hacía falta había que leer la página entera y las acciones concretas (PDF, seguimiento, atender) no existían. Ahora el resumen va de arriba abajo como se atiende el día y cada bloque trae su acción para ir al detalle de lo que lo formó.

- **Orden nuevo de `/admin`** (el filtro de periodo se queda arriba del todo):
  1. **Ventas por negocio** — una sola cifra grande por tarjeta: **«Ventas del periodo (con IVA)»**, con el número de ventas en la nota (**«N ventas en el periodo · N cotizaciones vendidas · N cotizaciones pendientes»**) y debajo En cartera, Total cotizado, Cotizaciones y Canceladas. **«Ganancias» se quitó de la tarjeta**: era el mismo importe (`Ventas::totalesEmpresa()` → `ventas.total`) y las dos cifras iguales confundían. Cabecera con **«Ver ventas (N)»** → `/admin/ventas?empresa=N` con el `desde`/`hasta` del periodo.
  2. **Impuestos** — **«Preliminar de impuestos · periodo» e «Impuestos por pagar» en la MISMA tarjeta**: el total (o «Saldo a tu favor»), debajo «IVA x + ISR y», cuántas facturas de venta y cuántos comprobantes de compra lo generan, y un `dl` con ventas facturadas, gastos facturados, IVA por pagar e ISR. El periodo del panel se traduce al simulador: mes elegido → ese mes; semestre → sus 6 meses; sin mes → el año. Cabecera con **«Ver preliminar»** (`/admin/fiscal/preliminar`) y **«Ver impuestos»** (`/admin/fiscal`). Sólo lectura: no toca el MotorFiscal.
  3. **Pendientes de atender** — una rejilla con las cuatro listas:
     - *Ventas pendientes de pago o por facturar*: **una sola lista** con la unión de las dos (lo que tiene ambos avisos sale primero), cada fila con folio, **cliente por ALIAS si lo tiene** y **importe total con IVA**; los badges «Por cobrar»/«Por facturar» dicen de cuál se trata y, sólo si ya hubo un pago parcial, se avisa cuánto falta cobrar. La cabecera abre los dos listados (`cobro=pendiente` y `facturacion=sin_clasificar`).
     - *Gastos pendientes por pagar*: nombre del beneficiario, concepto y **total con IVA**, enlazando al gasto; la cabecera abre `/admin/contabilidad/gastos?pago=pendiente&todos=1` y el pie suma lo que se les debe.
     - *Ventas pendientes por entregar*: folio, cliente y «faltan N pzas», con **botón directo «Seguimiento»** a `/admin/entregas-seguimiento/venta?id=N`.
     - *Incidencias de logística o entrega*: las **abiertas**, con su motivo, el badge «Corregir factura» cuando aplica y **botón directo «Atender»** a `…/venta?id=N#incidencias`.
  4. **Productos más vendidos** y 5. **existencias bajas**: se quedan como estaban (mismos `aria-labelledby`, mismos enlaces).
  6. **Últimos documentos**: últimas **5** cotizaciones y últimas **5** ventas, cada fila con su botón **PDF** (`/admin/cotizaciones/pdf?id=N` y `/admin/ventas/pdf?id=N`, pestaña nueva).
  7. **Indicadores del periodo**: la fila de tarjetas que abría la página pasa al final, con su propio título y su enlace al centro de seguimiento.
- **Se retiran** «¿Qué quieres hacer hoy?» (accesos rápidos), «Devoluciones recientes» y «Cotizaciones vendidas sin factura»; los **indicadores del periodo se conservan**.
- **`clases/Panel.php`:** nuevos métodos de solo lectura `ventasPendientesPago()` + `contarVentasPendientesPago()` (unión cobro/facturación, con alias y total con IVA), `gastosPorPagar()` + `resumenGastosPorPagar()` (n y monto) e `incidenciasAbiertas()` + `contarIncidenciasAbiertas()`; sus criterios quedan documentados en el encabezado de la clase. Quedan sin uso y **se eliminan** `ventasPorCobrar()`, `ventasPorFacturar()`, `convertidasSinFactura()`, `contarConvertidasSinFactura()` y `devolucionesRecientes()`.
- **`Cotizaciones::ganancias()`** devuelve además `ventas` (mismo importe con IVA) y `ventas_n` (cuántas ventas lo suman), con el docblock actualizado.
- **Estilos:** `.actividad__item` (fila con su acción al lado: el enlace abre el detalle y el botón va directo a la acción) y `.tarjeta__cabecera > .fila` (cuando la cabecera se parte en dos líneas, la acción se queda a la derecha en lugar de colgarse del título). `build/css/app.css` recompilado con `npx gulp css`.
- **Accesibilidad:** en el teléfono el texto de esos botones se oculta (`.movil-oculto`), así que cada uno lleva `aria-label` y `title` con el mismo texto.
- **Manual:** `includes/manual.php` → el paso «El panel» explica el orden nuevo y las acciones; `MANUAL_USUARIO.md` regenerado con `php scripts/generar_manual.php`.
- **Pruebas:** `pruebas/probar_ronda.mjs` suma la sección **«11. Panel: resumen general»** (23 comprobaciones: el orden de las siete secciones, el título «Ventas por negocio» y que cada tarjeta traiga esa única cifra grande **sin ganancias**, su enlace, preliminar + impuestos por pagar en la misma tarjeta, los cuatro listados con sus enlaces y sus botones directos, el PDF de las últimas cinco cotizaciones y ventas, los 8 indicadores al final, la ausencia de las secciones retiradas y el sin-desborde a 1440 y 390 px); para no probar estados vacíos crea **un gasto pendiente y una incidencia abierta propios** que borra en cuanto los comprueba. **`probar_ronda` → 73/73.**
- **Regresión:** `pruebas/ejecutar_todo.sh --rapido` → **71 correctas · 3 con fallos**, y los tres son **preexistentes y ajenos** (mismas causas ya documentadas): `php etapa27`, `php etapa38` y `probar_duplicados`. Además `node pruebas/auditar_ui.mjs --solo=/admin` → **0 hallazgos en `/admin`** (los 10 que reporta el auditor son de otras pantallas: selectores sin label en los formularios de cotización/venta/gasto y el botón «Registra al proveedor»). **Sin desplegar.**

✅ **Resuelto tras la revisión:** «Ganancias» **ya no aparece** en la tarjeta ni en el título de la sección (ahora «Ventas por negocio»), porque era el mismo importe que «Ventas del periodo» y las dos cifras iguales confundían. «Ventas del periodo» = **todas las ventas que se hicieron** en el rango elegido: directas y las nacidas de una cotización, con estado `registrada` y **con IVA** (las canceladas no cuentan). `Cotizaciones::ganancias()` sigue devolviendo `ganancias` (lo usa `pruebas/php/etapa3.php`), pero la vista ya no lo lee: la variable local pasó a `$negociosResumen` y el `dl` quedó en cuatro datos (En cartera, Total cotizado, Cotizaciones, Canceladas). Se actualizó el manual y la comprobación de la sección 11.

---

## Ronda — Fotos del acta de entrega desde el modal (2026-10-25)

Quien entregaba dejaba el acta en papel o nada: la hoja de seguimiento guardaba piezas, fecha y observaciones, pero no había dónde dejar la prueba fotográfica de la entrega. El modal **«Registrar entrega»** del hub —y la página suelta `/admin/entregas/entregar` que lo respalda, porque el formulario es una sola marca de campos compartida— ahora admite **una o varias fotos**, que se guardan con la entrega y se ven en la propia hoja de seguimiento.

- **Migración `040_entrega_fotos.sql`** (+ `040_reversion.sql`, aplicada con `php scripts/migrar.php`): tabla `entrega_fotos (id, entrega_id, archivo, usuario_id, creado_en)` con FK a `entregas` (`ON DELETE CASCADE`) y a `usuarios` (`RESTRICT`). Cuelga de la **entrega** y no de la venta, porque una venta puede tener varias entregas parciales y cada una lleva las suyas. El archivo no vive en la tabla: se guarda en `public/image` con nombre generado por el servidor y aquí sólo se registra a qué entrega pertenece y quién lo subió.
- **`templates/formEntregaVenta.php`** (compartido por el modal y por la página suelta, como todo el resto del formulario): el `<form>` pasa a `enctype="multipart/form-data"` y trae el campo `fotos[]` con `multiple` y `accept="image/jpeg,image/png,image/webp"`. La ayuda (6 MB por archivo, 8 fotos y 20 MB en total) sale de `Imagenes::TAMANO_MAXIMO`, `Entregas::MAX_FOTOS` y `Entregas::MAX_FOTOS_TOTAL_MB`, así que no se desfasa si cambian los topes.
- **`Entregas::registrarEntrega(..., array $fotos = [])`**:
  - valida **antes** de tocar la base: número de fotos (≤ 8), peso total (≤ 20 MB, que es lo que dejan pasar el ingress de 20 m y el `post_max_size` de 25 M del despliegue) y tamaño/tipo de cada archivo (`Imagenes::validarArchivo`);
  - dentro de la **misma transacción** procesa cada imagen con `Imagenes::subir($foto, 'entrega', 'informe')` (reduce a 1600 px **sin recortar** y le pone nombre aleatorio) y anota la fila en `entrega_fotos`;
  - si una foto falla se revierte la entrega completa y el nuevo `borrarFotos()` **retira del disco** los archivos ya escritos: un solo punto de limpieza. El `catch (\mysqli_sql_exception)` va **antes** que el de `\RuntimeException`, porque `mysqli_sql_exception` hereda de `RuntimeException`;
  - `Auditoria::registrar()` anota ahora `fotos`, y el nuevo `Entregas::$fotosGuardadas` alimenta el mensaje de éxito («Se guardaron N fotos del acta»).
- **`views/admin/entregas/entregar.php`:** junta `$_FILES['fotos']` con `Archivos::listaDeSubidas()` y lo pasa a `registrarEntrega()`; además, si PHP descarta el cuerpo entero por `post_max_size` (llega un POST sin campos, ni el token), se contesta con un error claro en lugar de reabrir el formulario como si nada hubiera pasado.
- **`views/admin/entregas-seguimiento/venta.php`:** sección **«Fotos de la entrega»** (sólo se pinta si hay fotos): una fila por entrega con su fecha y quién la registró, miniatura por foto y enlace que abre el original en otra pestaña. `Entregas::entregasDeVenta()` devuelve ahora `fotos` en cada entrega (una consulta más).
- **Estilos:** `.seguimiento-fotos` en `src/scss/layout/_entregas-seguimiento.scss` (rejilla `auto-fill` desde 8.5 rem, `aspect-ratio: 4/3` con `object-fit: cover`, borde y foco al pasar el cursor); `build/css/app.css` recompilado con `npx gulp css`. **Sin JS nuevo**: el campo es nativo (`multiple`) y el modal ya envía el formulario con su pie.
- **Manual:** `includes/manual.php` → «Registrar una entrega» añade el punto de las fotos; `MANUAL_USUARIO.md` regenerado con `php scripts/generar_manual.php` (sólo cambiaron esas dos líneas).
- **Pruebas:**
  - `pruebas/probar_operacion.mjs` suma la sección **«5. Fotos del acta de entrega (modal de entrega)»** y, antes, comprueba que el modal trae `input[name="fotos[]"][multiple]` y que el formulario es `multipart/form-data`: sube dos JPG desde el modal → 2 filas en `entrega_fotos` → 2 archivos `entrega-*.jpg` en `public/image` → la galería aparece en el seguimiento → **sin errores de consola**. `limpiar()` ahora retira primero los archivos del disco y luego `entrega_fotos`, `entrega_renglones` y `entregas` (sin eso, la FK `RESTRICT` de `entregas` a `ventas` impedía borrar la venta de la prueba). **27/27.**
  - `php pruebas/php/etapa4.php` (entregas) y `etapa43.php` (seguimiento), en verde. ⚠️ Hay que correrlos **uno detrás de otro**: los dos usan `cotizaciones_test` y en paralelo se pisan (los «fallos» que daban eran de esa carrera, no del código).
  - Corrida ad-hoc sobre `cotizaciones_test` de los caminos nuevos: alta con 2 fotos (filas + archivos + `entregasDeVenta`), rechazo con un `.jpg` que no es imagen (**no crea la entrega, no deja filas y retira del disco la foto ya escrita**) y rechazo de más de 8 fotos.
- **Sin desplegar.**

---

## Ronda — «Documentos Adjuntos»: el comprobante de pago en el detalle de la venta (2026-10-25)

El detalle de la venta (`/admin/ventas/detalle`) ya permitía adjuntar el CFDI y ver la lista de documentos, pero **no había un campo para dejar la prueba del cobro**: la foto del ticket, la captura de la transferencia o el PDF del comprobante de pago. Ahí entra el nuevo input **«Documentos Adjuntos»**, de la propia sección *Facturación y cobro*.

- **`templates/facturacionVenta.php`:** bloque nuevo, colocado entre los cobros y la lista «Facturas y comprobantes» (justo donde se ven los archivos que sube), con `<input type="file" id="adj-archivo" name="archivo[]" required multiple accept=".pdf,.jpg,.jpeg,.png,.webp">` rotulado **«Documentos Adjuntos»** y botón *Subir archivos*. El formulario va a `/admin/contabilidad/documentos/subir` con `entidad=ingreso`, `tipo=comprobante_pago` y `retorno=/admin/ventas/detalle?id=N`, así que el archivo se guarda con el tipo **«Comprobante de pago»** y aparece en la lista de abajo (ahí se descarga o se da de baja).
  - La ayuda dice de forma explícita que **es sólo de carácter informativo**: adjuntarlo no cambia el estado de facturación, el de cobro ni los importes, y no se pide para facturar.
  - Si el periodo contable está cerrado o el ingreso está cancelado, en lugar del campo se pinta una nota con candado: la misma condición que ya usaba la lista de documentos, ahora centralizada en `$adjuntosBloqueados`.
- **`views/admin/contabilidad/documentos/subir.php`:** el `retorno` acepta además `/admin/ventas/detalle?id=N` (además del prefijo `/admin/contabilidad` y siempre sin `//`), igual que ya hacía `ingresos/accion.php`. Sin eso el archivo **sí se subía**, pero el usuario aterrizaba en `/admin/contabilidad/documentos` en lugar de volver a su venta.
- **Sin migración, sin JS nuevo y sin tocar `Archivos`:** se reutiliza el almacén de documentos de la operación (contenido real validado con `finfo`, SHA-256 contra duplicados, nombre aleatorio **fuera de `public/`**, descarga con sesión de administrador y baja con motivo). Ningún otro flujo cambia de comportamiento.
- **Pruebas:**
  - Corrida ad-hoc sobre `cotizaciones_test` (servidor propio en `:8041`) con **18 comprobaciones, todas en verde**: existe el `input[type=file]` con etiqueta «Documentos Adjuntos», formulario `multipart` con `entidad=ingreso` y `tipo=comprobante_pago`, subida de un PNG y un PDF a la vez → 2 filas en `documentos` + 2 archivos en el almacén, los dos nombres visibles en la lista de la propia página, descarga en 200, repetir el mismo PDF **no lo duplica** y la consola queda sin errores.
  - En verde además: `pruebas/probar_etapa12.mjs` (**13/13**, la sección de facturación y cobro), `pruebas/probar_etapa5.mjs` (**51/51**, documentos y periodo cerrado), `php pruebas/php/etapa5.php`, `etapa12.php`, `etapa44.php` y `pruebas/probar_operacion.mjs` (**27/27**).
- **Sin desplegar.**

---

## Ronda — Correcciones del recorrido completo como empleado (2026-10-25)

Se recorrieron los cuatro grupos de operación (Operación Comercial, Productos e Inventario, Compras y Abastecimiento, Administración y Finanzas) con las dos empresas, en la base local. Salieron cinco hallazgos y aquí quedan corregidos; además se endurecieron cuatro pruebas de la suite que dependían del entorno o de datos reales.

### 1. El precio acordado con el cliente no se aplicaba a los productos agregados después
Al elegir primero al cliente (el orden natural del formulario) y después los productos, los renglones nuevos nacían con el **precio de lista** y el acuerdo se perdía en silencio; sólo se actualizaban los renglones ya cargados.

- **`src/js/modulos/cotizador.js`:** nuevo caché `cotizadorPreciosCliente` (producto → precio acordado) y helper `precioCotizadorCliente()`. La consulta a `/admin/cotizaciones/precios-cliente` se movió a `cargarPreciosCliente(clienteId, aplicar)`: guarda siempre el caché y sólo reescribe los renglones existentes cuando cambia el cliente (`aplicar=true`).
- `agregarLineaCotizador()` nace con `precioCotizadorCliente(producto.id, producto.precio)`.
- Al abrir una cotización guardada (o con `?cliente=`) se traen los precios con `aplicar=false`: **no se pisan** los precios ya cotizados, pero un producto que se agregue después sí toma el acuerdo.
- `build/js/app.js` recompilado con `npm run build`.

### 2. Facturar una venta obligaba a un baile de dos pantallas sin explicarlo
El selector «Facturada» remitía a «Revisar y facturar»; éste declaraba método/forma de pago y terminaba en «Validación aprobada, pero sin PAC», sin decir que había que volver al selector para dejar la confirmación manual.

- **`views/admin/ventas/fiscal.php`:** el mensaje de `pendiente_pac` ahora dice **«La venta sigue PENDIENTE DE FACTURAR: marca «Facturada» en el selector «¿Ya se facturó?» de esta misma pantalla…»**. No se simula timbrado: sólo se explica el paso que falta.

### 3. El «corregir factura» de una incidencia sólo lo forzaba el navegador
La interfaz marca sola la casilla en *producto faltante/incompleto*, pero `IncidenciasEntrega::registrar()` sólo la forzaba si el ingreso tenía `uuid_fiscal`; en una venta facturada manualmente se podía desmarcar y no quedaba rastro de la corrección.

- **`clases/IncidenciasEntrega.php`:** se fuerza cuando el ingreso está `vigente` y `estado_facturacion = 'facturada'`, **con o sin UUID**, igual que la interfaz (el comentario del código ya lo decía así).

### 4. Gasto desde una lectura: aviso contradictorio y bucle en «Registrar de todos modos»
El formulario `/admin/contabilidad/gastos/crear?lectura=N` seguía ofreciéndose para una lectura ya confirmada (con la nota «nada se ha registrado todavía») y el botón «Registrar de todos modos» **recargaba la misma página**, así que el aviso de duplicado reaparecía sin registrar nada.

- **`views/admin/contabilidad/gastos/crear.php`:** si la lectura no está `pendiente`, se bloquea el formulario con un aviso claro («Esta lectura ya fue confirmada/descartada…») y enlaces a egresos/lectura; además el POST con una lectura usada se rechaza. El aviso de duplicado sólo se emite con la lectura pendiente y sin `continuar=1`; con `continuar=1` se muestra una advertencia y el servidor vuelve a comprobar el UUID (mensaje «Ese UUID ya está registrado en otro gasto»).
- **`src/js/modulos/inteligente.js`:** «Registrar de todos modos» navega a la ruta con `continuar=1` (sale del bucle). `build/js/app.js` recompilado.

### 5. Los PDF se servían `inline` y el CSV/Excel `attachment`
Los botones «Exportar PDF» abrían el visor mientras Excel/CSV descargaban.

- **`clases/Documentos.php`:** `Content-Disposition: attachment` en todos los PDF (+ `X-Content-Type-Options: nosniff`). Se quitaron los `target="_blank"` de los enlaces PDF que ya no tenían sentido (cotización, venta, catálogo, estadísticas, preliminar fiscal, inventario: fotos y movimientos).

### Pruebas
- **Verificación de las correcciones:** 23/23 (A/B del precio, edición que conserva precios, mensaje sin PAC y facturación posterior, incidencia forzada sin UUID, lectura usada bloqueada, `continuar=1` + rechazo de UUID repetido, y seis rutas PDF como descarga). Sin errores de consola.
- **Regresión completa `pruebas/ejecutar_todo.sh --rapido`: 73 suites, 0 fallos.**
- **Pruebas endurecidas (dependían del entorno o de datos reales):**
  - `pruebas/php/etapa10.php` y `pruebas/probar_etapa10.mjs`: el filtro «bajo el mínimo» ya no exige un número fijo de productos; se compara contra la base.
  - `pruebas/php/etapa27.php`: el «día sin operaciones» se busca (el 25 ya podía tener movimientos del día real); si el mes está lleno usa el día 1 del siguiente.
  - `pruebas/php/etapa38.php`: `clientes_rfc` entra en las tablas que la reversión de su migración elimina por diseño.
  - `pruebas/probar_duplicados.mjs`: genera su propio PDF con el folio y el UUID de prueba (antes dependía de un PDF descargado en `~/Descargas`).
  - `desktop/test/integracion.mjs`: busca el producto antes de comprobar el cambio de precio (en el catálogo completo podía caer en otra página).
- **Accesibilidad móvil (auditoría de 44 px):** `src/scss/layout/_movil.scss` sube a 44 × 44 px las casillas de selección de entregas (`#marcar-todas` y `.entregas-seleccion`) y el ojo de «ver contraseña» (`.campo__ojo`, con más espacio en el campo); `build/css/app.css` recompilado. La auditoría móvil queda en **0 hallazgos**.
- **Sin migraciones nuevas** (la última sigue siendo `040_entrega_fotos.sql`; esquema y datos intactos).

---

## Ronda — Formulario de gastos: pago tomado del CFDI y «Más detalles» depurado (2026-10-26)

Al cargar un gasto con **«Registro compra inteligente»** el formulario ya traía los cuatro datos del CFDI, pero el estado de pago siempre arrancaba en «pendiente» y «Más detalles» volvía a preguntar cosas que el comprobante ya dice.

- **`views/admin/contabilidad/gastos/crear.php`:**
  - **Forma de pago del CFDI:** se valida contra `VentasFiscal::FORMAS_PAGO` y se precarga la clave leída (01 efectivo, 03 transferencia…). Si el XML trae una clave fuera del catálogo, queda «Sin definir» para capturarla.
  - **¿Ya se pagó?:** se deduce del comprobante. `MetodoPago=PUE` (pago en una sola exhibición) deja el gasto **pagado** con la fecha del CFDI; `PPD` (parcialidades/diferido) queda **pendiente**. Si el XML no trae método pero sí una forma concreta distinta de `99 · Por definir`, se considera pagado. El selector sigue visible para corregirlo antes de guardar.
  - **Clasificación contable:** ya no se pregunta. Se deduce del uso del CFDI con el nuevo `Gastos::naturalezaDeUsoCfdi()`: `G01` → compra de mercancía, `I01–I08` → activo, el resto → gasto operativo.
- **`templates/formularioGasto.php`:** se quitan de «Más detalles» el selector **«¿Cómo lo pagaste?»** (repetía la forma de pago del CFDI), la **«Fecha en que lo pagaste»** y la **«Clasificación contable»**. El resumen del bloque ahora dice «folio fiscal, reparto entre negocios y notas».
- **`clases/Gastos.php`:** al editar se conservan `naturaleza`, `metodo_pago` y `fecha_pago` anteriores cuando el formulario ya no los envía; en un alta pagada sin fecha se sigue usando la fecha del gasto. No cambia el flujo fiscal: `fecha_pago` sigue alimentando el motor de flujo de efectivo.
- **Sin migración:** las columnas `metodo_pago`, `fecha_pago` y `naturaleza` se conservan (las usa el motor fiscal y los registros históricos); sólo dejan de pedirse en la interfaz.
- **Pruebas:** `pruebas/probar_inteligente.mjs` ahora comprueba que el gasto con PUE queda pagado, que los tres campos retirados no existen y que el gasto se guarda con `estado_pago=pagado` y la fecha del CFDI. Corrida ad-hoc con PUE, PPD, XML sin método y forma `99`: 28/28. `php pruebas/php/etapa44.php`: TODO OK.

---

## Ronda — Respaldos dentro del clúster, descarga y restauración externa (2026-10-27)

La pestaña Ajustes → Almacenamiento avisaba «faltan mysqldump/mysql» porque la imagen de la app no traía el cliente de MySQL y los scripts de respaldo ni siquiera viajaban dentro del contenedor. El respaldo de archivos dependía de un equipo con `kubectl`, y una copia externa no se podía volver a subir. Ahora todo el ciclo vive en el clúster y la copia se puede sacar y volver a entrar.

- **`Dockerfile`:** instala `default-mysql-client`, `gzip` y `tar`; sube `upload_max_filesize`/`post_max_size` a 512M (subida de una copia completa), `max_execution_time`/`max_input_time` a 600 s. Los scripts de respaldo se incluyen en la imagen (`scripts/respaldar.sh`, `scripts/restaurar.sh`; antes los excluía `.dockerignore`).
- **Helm:** nuevo PVC `cotizaciones-respaldos` (3Gi) montado en `/var/www/html/respaldos`; el CronJob `cotizaciones-respaldo` (02:30, activado por defecto) usa la MISMA imagen de la app y monta los PVC de imágenes, subidas, documentos, archivo y respaldos. Corre como `www-data` (33) para que las copias sean legibles/descargables por la app. Ingress: 512m de cuerpo y timeouts de 600 s. También se añadió el PVC `cotizaciones-archivo` para `storage/archivo` (antes vivía en la capa del contenedor): los archivos de la herramienta Archivo ahora persisten y entran en la copia.
- **`clases/Almacenamiento.php`:** `importarExterno()` / `importarPaquete()` verifican el `.tar.gz` (rutas, enlaces, `SHA256SUMS`) y lo guardan como una copia más del clúster; `rutaCopia()` valida el sello; la restauración extrae los archivos en `respaldos/restauraciones/…` (volumen persistente). El respaldo automático de la app ya no duplica el del CronJob si hay una copia del día.
- **`scripts/respaldar.sh`:** incluye `public/image`, `public/uploads`, `storage/documentos` y `storage/archivo`; excluye los `.deb` de escritorio; `--no-tablespaces` y detección de cliente MariaDB/MySQL para desactivar TLS en la red interna.
- **`scripts/restaurar.sh`:** usa `MYSQL_ROOT_PASSWORD` cuando existe (el usuario de la app no puede `CREATE DATABASE`); el Deployment le pasa ese secreto. Sin esa variable (desarrollo) sigue usando `DB_USER`.
- **UI:** botón «Descargar» por copia (un solo `.tar.gz` con base, archivos y huellas) y sección «Traer una copia externa» (subir `.tar.gz` → se verifica → aparece en la lista → se restaura igual que las demás). El «Último respaldo» muestra el del CronJob aunque la app no lo haya registrado.
- **Pruebas:** nueva `pruebas/probar_importar_respaldo.php` (8/8: importación, sello repetido, paquete alterado y ruta con `..`) integrada a `pruebas/ejecutar_todo.sh`; `pruebas/probar_respaldo.sh` 16/16. Verificado además con un MySQL 8.4 real y el cliente MariaDB de la imagen: respaldo y restauración completos.

---

## Ronda — Gastos inteligentes: CFDI del SAT «validado» y aviso fuerte de pago duplicado (2026-10-27)

Las facturas que se suben a gastos y compras inteligentes son XML descargados del portal del SAT: el comprobante ya es auténtico y no tenía sentido que quedaran «por validar» como si alguien dudara de ellas. La revisión de deducibilidad material la sigue haciendo el contador por fuera. Además, subir dos veces el mismo gasto ahora se avisa con un diálogo claro y sin la opción de «registrar de todos modos».

- **`clases/Gastos.php`:**
  - `normalizar()` marca `revision_fiscal = 'validado'` cuando el gasto trae **folio fiscal (UUID)**, venga de la lectura inteligente, del XML adjunto en el formulario o de una compra. Sin UUID (PDF, foto o captura manual) sigue en `por_validar`.
  - `no_deducible` nunca se cambia solo: si el contador ya lo marcó así, se respeta.
  - Nuevo `Gastos::marcarValidadoPorCfdi($gastoId)` para cuando un XML con timbre se adjunta después.
  - El duplicado de UUID ahora dice el número del gasto que ya lo tiene (`Gastos::$duplicadoId`) y el mensaje «un pago no se registra dos veces».
- **`clases/Archivos.php`:** al subir un XML con timbre a un gasto se llama a `marcarValidadoPorCfdi()` (un PDF o una foto no tocan la revisión).
- **`clases/CompraContable.php`:** una compra con `estado_cfdi = 'con_cfdi'` crea su gasto ya «validado»; sin CFDI queda «por validar».
- **`clases/Lecturas.php`:** al confirmar una lectura se guarda «validado» si trae UUID; al asociar el XML a un gasto existente también. `coincidencias()` ya no avisa de un posible duplicado cuando el UUID del registro es **distinto**: dos compras del mismo proveedor, del mismo servicio o por el mismo importe son facturas independientes.
- **`views/admin/contabilidad/gastos/crear.php`:**
  - La lectura inteligente precarga el gasto con revisión fiscal «validada» y la nota lo explica.
  - Si al guardar el UUID ya está vigente en otro gasto, **no se muestra el formulario**: aparece el aviso «Este pago ya está registrado» con enlace al gasto existente, y el módulo abre el mismo mensaje en un diálogo.
- **`src/js/modulos/inteligente.js`:** `avisarDuplicado()` distingue el duplicado por folio fiscal (UUID): título «Ese pago ya está registrado», sin botón «Registrar de todos modos»; el resto de coincidencias (misma fecha e importe, otro UUID) conserva el aviso suave. `build/js/app.js` recompilado.
- **Textos:** manual (`includes/manual.php` → `MANUAL_USUARIO.md`) y confirmación de «Enviar a contabilidad» de la compra actualizados: XML del SAT → «validado»; PDF o foto → «por validar».
- **Pruebas:** `etapa14` (XML adjunto deja «validado», imagen no), `etapa18` (CFDI asociado a compra), `etapa33` (lectura de gasto) y `probar_compras.mjs` (XML de compra) actualizados. `probar_duplicados.mjs` verifica el diálogo fuerte sin opción de duplicar. Corridas: `etapa5/14/18/30/33/36/37/38/41/42/44/45` TODO OK; `probar_compras` 62/62; `probar_duplicados` 22/22; `probar_etapa5` 51/51.
- **Sin migración:** `revision_fiscal` ya existía con sus tres estados.

---

## Ronda — Campos pendientes con borde rojo desde que se abre el formulario (2026-10-27)

En formularios largos —sobre todo el alta de gasto— no se veía qué faltaba llenar hasta intentar guardar, y el formulario de gasto ni siquiera tenía validación por campo (solo la nota general del servidor). Ahora los campos vacíos se identifican de un vistazo desde que se abre la pantalla.

- **`src/js/modulos/camposPendientes.js` (nuevo):** recorre todos los formularios y marca:
  - **obligatorios vacíos** con `.campo--pendiente` (borde rojo continuo);
  - **recomendados vacíos** con `.campo--sugerido` (borde rojo punteado).
  La marca desaparece al llenar el campo y se recalcula cuando otro campo cambia una condición (`input`/`change` en fase de burbuja, después de los módulos que muestran/ocultan campos). Ignora `hidden`, deshabilitados y `.campo--oculto`. Añade `aria-invalid` para no depender solo del color. En login/registro no se activa.
- **Reglas declarativas:** `data-obligatorio-si="#beneficiario_tipo=proveedor"`, `data-recomendado-si="#comprobante_fiscal=1"` y `data-recomendado`.
- **`templates/formularioGasto.php`:**
  - `proveedor_id` obligatorio cuando «Pagado a» = Proveedor; `beneficiario` obligatorio cuando = Otro.
  - `categoria_id` marcada como recomendada; `uuid_fiscal` recomendado cuando el gasto lleva factura.
  - Leyenda (solo con JS) que explica el borde continuo y el punteado.
- **`views/admin/contabilidad/gastos/crear.php` y `editar.php`:** el `<form>` lleva `data-validar`, así al guardar con faltantes se muestra el mensaje bajo cada campo y el foco va al primero (antes solo salía la nota superior).
- **`src/js/modulos/validacion.js`:** `mensajeDeError()` respeta `data-obligatorio-si`, no solo `required`.
- **`src/scss/layout/_formularios.scss`:** estilos `.campo--pendiente` y `.campo--sugerido` (rojo continuo y rojo punteado; el foco conserva el rojo).
- **Pruebas:** `pruebas/probar_etapa5.mjs` suma 6 comprobaciones (obligatorios desde el inicio, recomendado, quitar la marca al llenar, validación al guardar vacío y condición «Otro»/Proveedor). Corrida: 57/57. `probar_ronda` 77/77; `auditar_consola` sin hallazgos nuevos (el único 404 es una imagen real ausente en proveedores/entradas).
- **Assets recompilados:** `build/js/app.js` y `build/css/app.css`. Sin migraciones.

---

## Ronda — Cancelar un gasto equivocado lo elimina y deja de contar (2026-10-27)

Cancelar un gasto es para corregir una captura equivocada del usuario, no para conservar el registro: ahora «Cancelar» y «Eliminar» hacen la misma operación. El gasto desaparece con sus documentos, deja de sumar en contabilidad y fiscal, y queda una copia restaurable durante 90 días en la Papelera.

- **`clases/Gastos.php`:** `cancelar()` delega en `eliminar()`: exige motivo, respeta los periodos cerrados, borra las filas de `documentos` y sus archivos, desenlaza lecturas y compras, y guarda la copia en la Papelera. Se elimina el candado que impedía borrar un gasto ya cancelado.
- **`views/admin/contabilidad/gastos/detalle.php` y `accion.php`:** el botón «Cancelar gasto» pasa a «Eliminar gasto», con confirmación y motivo de eliminación. Al terminar vuelve a la lista (o al Centro de seguimiento si se abrió desde ahí), no al detalle de un registro que ya no existe.
- **`views/admin/contabilidad/gastos/index.php`:** el borrado masivo vuelve a aceptar cualquier gasto listado, incluidos los cancelados antiguos.
- **Registros cancelados anteriores:** siguen fuera de contabilidad y fiscal. Se mantienen las correcciones en `CierrePeriodo` (sus XML y archivos no disparan avisos de cierre y no cuentan como movimiento del mes anterior) y en `Contabilidad::aniosConMovimientos()` (no propone años con sólo cancelaciones).
- **Pruebas:**
  - `pruebas/php/etapa5.php`: cancelar elimina la fila y la excluye del resumen.
  - `pruebas/php/etapa38.php`: la cancelación borra el gasto y el cálculo fiscal vuelve a cero; se conservan los casos de años y XML de cancelados antiguos.
  - Regresión: `etapa5`, `etapa6`, `etapa30` y `etapa42` en verde.
- **Manual:** sección «Eliminar un gasto (captura equivocada)» en `MANUAL_USUARIO.md` y `includes/manual.php`. Sin migraciones.


---

## Vista previa de facturas y comprobantes en ventana modal (2026-10-27)

Los documentos adjuntos ya no obligan a descargarlos: cada archivo de «Facturas y comprobantes» tiene «Ver» y se abre en la misma ventana modal única de la aplicación.

- **`clases/Archivos.php`:** `tipoVista()` decide qué se puede previsualizar según el MIME real (PDF, XML e imágenes) y `ver()` sirve el archivo inline. El XML sale como `text/plain; charset=UTF-8` (nunca se interpreta), con `X-Frame-Options: SAMEORIGIN` y un CSP que permite incrustarlo solo en la propia app.
- **Ruta nueva:** `GET /admin/contabilidad/documentos/ver` → `views/admin/contabilidad/documentos/ver.php` (solo administradores).
- **`src/js/modulos/documentoPreview.js`:** botones `[data-ver-documento]` abren el modal ampliado. PDF con el visor del navegador (y enlace de respaldo para móvil); imágenes ampliadas; XML con resumen del CFDI (folio, UUID, fecha, emisor, receptor, importes, pago y conceptos) y el XML completo plegado. Incluye «Descargar» en el pie.
- **`src/js/modulos/modal.js`:** opción `amplio` (panel de 61rem) y el botón único de un diálogo informativo dice «Cerrar» en lugar de «Cancelar».
- **Botones «Ver»:** `templates/documentosOperacion.php` (ingresos y gastos), `views/admin/compras/detalle.php` y `views/admin/contabilidad/documentos/index.php`.
- **Estilos:** `src/scss/layout/_documentos.scss` (visor, resumen del CFDI y XML plegado; responsive).
- **Manual:** se explica el botón «Ver» en `MANUAL_USUARIO.md` e `includes/manual.php`. Assets recompilados. Sin migraciones.

---

## Ronda — Forma de pago del CFDI: tarjeta de débito (28/05) y «99 · Por definir» (2026-10-27)

La carga inteligente seguía dejando la forma de pago mal o vacía en dos casos reales: las facturas con clave **28** (tarjeta de débito) no se reconocían porque el catálogo la etiquetaba como otra cosa, y las facturas **PPD sin FormaPago** (o con la leyenda «99 - Por definir») quedaban en «Sin definir».

- **`clases/VentasFiscal.php`:** `FORMAS_PAGO['28']` pasa a **Tarjeta de débito** y se agrega `normalizarFormaPago()`: las claves 28 (factura) y 05 (sistema) se unifican en **05**, así una factura con 28 se guarda y se muestra como la tarjeta de débito que ya usa el sistema. La 99 sigue siendo una clave válida del catálogo.
- **`clases/LectorDocumental.php`:** la forma de pago se lee con un detector que acepta **«Forma de pago» / «Forma Pago» / «FormaPago»**, la clave junto al nombre («99 - Por definir», «28 - Tarjeta de débito»), el nombre solo («Tarjeta de débito») y, en XML, propone **99** cuando el CFDI es PPD y omite `FormaPago` (caso válido del SAT).
- **Normalización en todo el flujo:** `CoincidenciasFactura`, `Gastos`, `CompraContable`, `Lecturas`, `CargaLotes`, el alta/edición de gasto, el detalle del gasto y «Revisar y facturar» usan la misma equivalencia 28 → 05; el gasto creado desde un lote también propone 99 si es PPD sin forma. `ComplementosPago` y `ComplementosVenta` reconocen 28/29 como tarjeta.
- **Pruebas:** `etapa44` suma la lectura de 28 → 05, el PPD sin `FormaPago` → 99 y las tres variantes del PDF (clave con nombre, «Forma Pago» y solo el nombre). Corrida: `etapa44` TODO OK; regresión `etapa13`, `etapa28`, `etapa33`, `etapa34`, `etapa41` TODO OK. Sin migraciones.

---

## Ronda — Productos más comprados e historial de compras en la ficha del proveedor (2026-10-27)

La ficha del proveedor ya muestra, después de «Pagos», cuánto se le compra de cada producto.

- **`clases/OrdenesCompra.php`:** nuevo `productosComprados($proveedorId, $limite = 0)` que agrupa las partidas de compras **no canceladas** por producto del catálogo, material, producto del proveedor o descripción libre. Devuelve cantidad acumulada, importe unitario promedio, total sin IVA, número de compras y fecha de la última compra, ordenado de mayor a menor cantidad.
- **`views/admin/proveedores/detalle.php`:** dos secciones nuevas después de «Pagos»:
  - **Productos más comprados** (arriba): los 5 con mayor cantidad, con producto, cantidad, importe unitario y total.
  - **Historial de compras** (abajo): el mismo detalle para todos los productos comprados al proveedor.
  Las dos tablas salen del mismo cálculo, así que sus cifras coinciden; los importes son sin IVA y el importe unitario es el costo promedio de lo comprado.
- **Sin migración.**

---

## Ronda — Facturación de una venta en un solo formulario (2026-10-27)

La sección «Facturación y cobro» tenía dos formularios para la misma operación: el modal **«Revisar y facturar»** capturaba los datos fiscales pero, sin PAC, no facturaba (pedía bajar a marcar «Facturada»), y el formulario **«¿Ya se facturó?»** era el que realmente subía el CFDI y guardaba folio/UUID/fecha. Además, «Leer factura» era una tercera puerta que se saltaba la validación previa.

- **`templates/facturacionVenta.php`:** un solo formulario «Facturar y guardar» con datos fiscales del receptor, método/forma/uso/tipo, archivo (XML/PDF/foto), folio/UUID/fecha y confirmación manual cuando no hay CFDI. Se eliminan el modal, el segundo formulario y el enlace suelto «Leer factura». «No se facturará» y «Volver a pendiente» quedan como acciones aparte, sin captura. Una venta ya facturada con CFDI no muestra el formulario: su corrección va por una incidencia de entrega.
- **`views/admin/ventas/fiscal.php`:** una sola acción POST que guarda los datos fiscales, adjunta los documentos, revalida y marca la facturación en el mismo envío (con CFDI o confirmación manual). Las acciones de clasificación no capturan nada.
- **`views/admin/entregas-seguimiento/venta.php`:** la tarjeta de facturación del hub deja de repetir el bloque completo; muestra el resumen y enlaza a la sección única del detalle de la venta.
- **`clases/Lecturas.php`:** la lectura inteligente de una factura de venta ahora aplica `VentasFiscal::validar()` antes de marcar facturada, igual que el formulario.
- **`views/admin/contabilidad/ingresos/accion.php`:** el mensaje de validación apunta a «Facturación y cobro» en lugar del modal retirado.
- **Manual:** `includes/manual.php` y `MANUAL_USUARIO.md` describen el paso único y las opciones de no facturar/corregir.
- **Pruebas:** `pruebas/probar_inteligente.mjs` lee los cuatro datos del CFDI directamente en el formulario (ya no abre el modal). `etapa44`, `etapa40` y `etapa35` se corrieron contra `cotizaciones_test`; los fallos observados son los de la base de pruebas (RFC principal, periodo cerrado), no de este cambio. Sin migración.

---

## Ronda — Marca de agua en las fotos de entrega (2026-10-28)

Las fotos del acta se abren ahora con una marca de agua: **logo del negocio emisor arriba a la izquierda** y, abajo a la izquierda, **fecha, hora y ubicación** con sombra para resaltar sobre la imagen. La foto original **no se modifica**: la marca se dibuja al vuelo cuando se abre el visor, así que también salen marcadas las fotos anteriores y el archivo de disco queda intacto.

- **Migración `041_entrega_fotos_metadatos.sql`:** `entrega_fotos.tomada_en`, `latitud` y `longitud` (nulas). Guardan el EXIF leído **antes** de que Intervention re-codifique la foto y lo borre; con ellas la marca muestra la fecha, la hora y el GPS reales de la toma.
- **`clases/FotosEntrega.php`:** `metadatos()` lee `DateTimeOriginal`/`DateTime` y el GPS (grados decimales, con S/O negativos); `marcada()` dibuja logo y texto con Intervention/GD; `url()` arma el enlace al endpoint. El logo se recorta con `Imagenes::logoRecortado` y se limita a ~20 % del ancho; el texto va en blanco con sombra negra desplazada y se recorta con «…» si no cabe.
- **`clases/Entregas.php`:** `guardarFotos()` guarda los metadatos junto con la fila; nuevo `fotoDeEntrega($archivo)` que sólo devuelve fotos registradas en `entrega_fotos`, con su venta, empresa y cliente.
- **`views/admin/entregas/foto.php` + ruta `GET /admin/entregas/foto`:** sirve la foto ampliada con la marca (sólo administradores, `Cache-Control: private, max-age=86400`). Si el dibujo falla, devuelve el original para no romper el visor.
- **`views/admin/entregas-seguimiento/venta.php`:** las miniaturas siguen mostrando el original; `data-ampliar-imagen` apunta al endpoint, de modo que el visor existente abre la versión marcada sin tocar JavaScript. Se actualiza la leyenda de la sección.
- **Botones «Google Maps» y «Apple Maps»:** debajo de la galería, un único par de botones para toda la sección. Toman la ubicación de la **primera foto** (primer entrega, primera miniatura): su GPS si lo tiene y, si no, `clientes.ubicacion`. `FotosEntrega::urlsMapa()` arma los dos enlaces sin API key (`google.com/maps/search/?api=1&query=…` y `maps.apple.com/?q=…`), abren en pestaña nueva y no se pintan si no hay ni GPS ni dirección.
- **Respaldos:** si la foto no trae EXIF (todas las anteriores y algunas nuevas), la fecha/hora salen de `entregas.creado_en` y la ubicación de `clientes.ubicacion`; si tampoco hay dirección, sólo se omite esa línea.
- **Pruebas:** `pruebas/probar_operacion.mjs` comprueba que el visor apunta al endpoint, que responde una imagen válida y que la versión marcada no es el archivo original; además, que los botones de mapas no aparecen sin datos, que usan el GPS de la primera foto y que caen a la dirección del cliente cuando falta GPS. Corrida completa contra `cotizaciones_test`: **37/37**. También se verificó el camino real de subida con un JPEG con EXIF (fecha `2026-10-28 14:35:22`, latitud `24.1426667`, longitud `-110.3127500`): la fila guardó los tres datos y la limpieza dejó la base de prueba intacta.

---

## Ronda — Mapa histórico de ventas por GPS (2026-10-28)

Hasta abajo de `/admin/estadisticas` hay ahora un mapa de OpenStreetMap con un pin por lugar donde se ha entregado. El número del pin es la **cantidad de ventas** registradas a **50 m o menos** de ese punto; al tocarlo se ven los clientes y el rango de fechas.

- **`clases/Estadisticas.php`:** nuevo `mapaVentas($empresa, $cliente)` y constante `RADIO_MAPA = 50`. Toma la **primera foto con GPS de cada venta** (una entrega con varias fotos no infla el conteo), agrupa con distancia haversine contra el primer punto del lugar y devuelve `puntos`, `ventas_ubicadas` y `ventas_sin_ubicacion`.
- **Ventas incluidas:** solo `estado = 'registrada'`; las canceladas quedan fuera. Las ventas sin ninguna foto con GPS se cuentan aparte y se avisa cuántas no aparecen en el mapa. El mapa es **histórico completo** y respeta los filtros de negocio y cliente, no el periodo.
- **`views/admin/estadisticas/index.php`:** tarjeta «Mapa de ventas» al final, con resumen, JSON de los puntos en `data-mapa-ventas` y aviso cuando todavía no hay ubicaciones.
- **`src/js/modulos/mapaVentas.js`:** Leaflet se inicializa al acercarse el mapa a la pantalla; pines circulares numerados, popup con nodos del DOM y ajuste automático de la vista. La rueda no secuestra el scroll.
- **Leaflet 1.9.4:** nueva dependencia de npm y tarea `mapas` en `gulpfile.js`, que copia `leaflet.js`, `leaflet.css` e imágenes a `build/`. Solo la página de estadísticas los carga (`estilosPagina` nuevo en `templates/header.php`, `scriptsPagina` para el JS).
- **CSP:** `img-src` permite `https://tile.openstreetmap.org` para las teselas. La atribución de OpenStreetMap aparece en el mapa y en el pie de la figura.
- **Pruebas:** `pruebas/php/mapa_ventas.php` verifica los grupos 4/2/1, el radio de 50 m, la foto doble, la venta cancelada, la sin GPS y los filtros por negocio (TODO OK). `pruebas/probar_mapa_ventas.mjs` comprueba en navegador los 3 pines, sus números, las teselas, el globo y el resumen (**10/10**). `probar_etapa7.mjs` sigue en **42/42** y ambas pruebas quedaron en `pruebas/ejecutar_todo.sh`.

---

## Ronda — Impuestos: todo gasto pagado resta (2026-10-30)

El Resumen de Fiscal seguía mostrando «Gastos pagados que NO se descuentan: N pagado(s) sin validación fiscal» aunque la decisión del 23-oct era que **la deducción la decide el contador** y que el motor no filtrara por comprobante ni por revisión fiscal. El cambio de entonces sólo se aplicó al archivo `reglas_plataforma_2026.json`; el motor seguía leyendo la regla de la **versión activa en la base** (que tenía `gasto_requiere_validacion: true`) y la pantalla conservaba el bloque.

- **`clases/MotorFiscal.php`:** se eliminan los filtros por `revision_fiscal` y `comprobante_fiscal`. Todo gasto **pagado** resta; sólo quedan fuera los marcados **no deducible** y las naturalezas excluidas del catálogo (`activo`, `impuesto`). Se retiran los avisos «siguen por validar» y «no tienen factura». `gastos_excluidos` queda con `no_deducibles` y `naturaleza`.
- **`views/admin/fiscal/resumen.php`:** desaparece el pie «Gastos pagados que NO se descuentan». Si hay exclusiones reales se muestra una sola línea: «Gastos pagados fuera del cálculo por decisión fiscal: N no deducible(s), M por naturaleza excluida». En «Reglas aplicadas» el comprobante y la validación fiscal se declaran **informativos** (no cambian el cálculo).
- **`clases/ResumenFiscal.php`:** default de `gastos_excluidos` y comentarios alineados con el criterio.
- **Migración `043_gastos_cfdi_validado.sql`:** marca `revision_fiscal = 'validado'` en los gastos vigentes con `comprobante_fiscal = 1` y UUID (XML del SAT) que seguían en `por_validar` por ser anteriores a `marcarValidadoPorCfdi()`. No toca `no_deducible` ni los gastos sin UUID. Sin reversión automática: volver a «por validar» un CFDI auténtico sería un dato incorrecto.
- **Catálogo:** el JSON del repositorio ya trae `gasto_requiere_validacion: false`. La versión activa en una base que se sembró con el modo estricto puede seguir en `true`; el motor ya no la usa. Si se quiere coherencia visual, se importa y activa una v2 desde Fiscal → Catálogos.
- **Pruebas:** `etapa6` actualizada (el gasto «Sin validar» ahora cuenta: IVA acreditable 368, IVA a cargo 702, deducciones 4,500, ISR 404.33) y `etapa37` (gasto sin comprobante y «por validar» sí se descuentan, incluso con la regla estricta inyectada). `etapa14/30/33/38/41/44/45` en verde.
- **Efecto:** las estimaciones históricas suben sus deducciones (y bajan ISR/IVA a cargo) por los gastos que antes se excluían. Conviene avisar al contador.

---

## Ronda — «Revisar complemento» en la carga por lotes (2026-10-30)

El botón **Revisar complemento** de `/admin/contabilidad/lotes` mandaba el id de la lectura a `/admin/compras/complemento/revision`, pero esa lectura se guardó con destino `compra` y `ComplementosPago::analizar()` exigía destino `complemento`: devolvía `null` y la pantalla respondía 404. Al confirmar tampoco se cerraba por el mismo filtro.

- **`clases/ComplementosPago.php`:** `analizar()` acepta una lectura de cualquier destino cuando el CFDI es tipo `P` (manda el documento, no la bandeja). `cerrarLectura()` deja de exigir `destino = 'complemento'`.
- **`clases/ComplementosVenta.php`:** mismo criterio para el lado de clientes (`destino = 'complemento_venta'` o CFDI tipo `P`); `cerrarLectura()` ya no filtra por destino.
- **`clases/CargaLotes.php`:** la detección de CFDI tipo `P` se movió **antes** del carril, para que también el carril de ventas clasifique el complemento y el botón apunte al lado correcto (`lado_cliente`).
- **Pruebas:** `etapa41` agrega la regresión: un complemento tipo `P` subido al carril de gastos (destino `compra`) se clasifica como complemento, `analizar()` lo abre y `registrar()` cierra su lectura. `etapa32/34/36/38` en verde.
- **Efecto:** los complementos que ya estaban atorados en la bandeja se pueden abrir y registrar; al confirmarlos desaparecen de la lista de pendientes.
