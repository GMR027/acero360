# PLAN — App Web de Cotizaciones

> Documento generado a partir de las especificaciones de `OPENCODE.md`.
>
> **Estado:**
> - ✅ Fase 1 — Infraestructura: **completada**
> - ✅ Fase 1b — Ajustes de interfaz: **sección activa desde la URL, menú inferior móvil, scroll horizontal**
> - ✅ Fase 2 — Base de datos: **`mysql/cotizaciones.sql` creado e importado**
> - ✅ Fase 3 — CRUDs: **`Clientes`, `Usuarios`, `Empresas` y `Productos` completos**
> - ✅ Fase 3b — Sección nueva: **`Notas Personales`** (no estaba en `OPENCODE.md`)
> - ✅ Fase 4 — Cotizador: **catálogo visual, renglones, precios por cliente, totales en vivo, folio**
> - ✅ Fase 5 — Histórico, correo y PDF: **filtros + estadísticas, envío PHPMailer, exportación dompdf**
> - ✅ Rondas 6–12 — pulido de interfaz, filtros, fotos de perfil, duplicar y ganancias por negocio
> - ✅ Fase 6 — Ventas, Inventario y Proveedores: **stock, reporte de entradas y PDF de inventario**
>
> **Acceso:** `http://127.0.0.1:8017/login`
> (servidor local: `php -S 127.0.0.1:8017 index.php`)
> **Cuentas:** la antigua `admin@cotizaciones.local` ya no existe. Cómo entrar
> o crear un usuario nuevo está en [`cambios/documentacion.md`](cambios/documentacion.md).

---

## 1. Decisiones cerradas

| Tema | Decisión |
|---|---|
| Proyecto | Nuevo, en `/home/edgar/Documentos/programacion/cotizaciones` |
| Base de datos | Nueva: **`cotizaciones`** (MySQL 8 local) |
| Estructura / backend | Réplica del patrón de `/home/edgar/Documentos/programacion/canastillasWep` (Router + middlewares + clases + templates + Gulp/Sass) |
| Roles | **Solo admin (rol 1).** El "cliente" es una ficha en la tabla `clientes`; **no inicia sesión** |
| Campos mínimos del cliente | Nombre, RFC, teléfono, email, ubicación, VIP → tabla `clientes` |
| Campos nuevos solicitados | `nombre_empresa` y `alias` en `clientes` |
| Negocios | `empresas`: **Soluciones en Acero** y **Canastillas de la Baja** (datos fiscales se cargan desde el CRUD) |
| Diseño | `design/vanguard_commerce/DESIGN.md` + los 13 `code.html` re-maquetados a **Sass + rem + flexbox/grid** (sin Tailwind) |
| PDF / Correo | `dompdf/dompdf` + `phpmailer/phpmailer` |
| Imágenes de producto | `<input type="file">` + `intervention/image`, recorte cuadrado. **Sin URLs externas** |
| Registro | **Deshabilitado por defecto.** Se habilita con la env var `REGISTRO_ABIERTO=1` |
| Precio especial por cliente | Guardado en `precios_cliente`, con el **checkbox "No recordar este precio"** |
| Stock insuficiente al vender | **Permitir sólo con confirmación**: el modal de `data-confirmacion` lista los faltantes y deja continuar (el inventario queda en negativo). Nunca bloquea, nunca `confirm()` |
| Venta desde una cotización | **Todo automático en una transacción**: estado `vendido` + descuento de stock + alta de la venta. **Máx. 1 venta por cotización** (UNIQUE) |
| Tabla de proveedores | **Dos tablas**: `proveedores` (directorio) + `proveedor_entradas` (registros con foto) |
| Exportar inventario | **Un solo PDF con una tabla por empresa** (encabezado con fecha, logo y nombre; totales de piezas e importe por negocio) |
| Encabezados de tabla | **Todos centrados**, incluidos los numéricos (la alineación a derecha queda sólo en los datos) |

---

## 2. Estructura de archivos

```
cotizaciones/
├── plan.md                       # este documento
├── cambios.md                    # diferencias con el proyecto base (canastillasWep)
├── OPENCODE.md                   # especificaciones originales
├── design/                       # maquetas de referencia (no se modifican)
│
├── index.php                     # front controller: todas las rutas declaradas
├── Router.php                    # clase con get()/post($url, $archivo, $middleware)
├── .htaccess                     # RewriteEngine → index.php [QSA]
├── composer.json                  # PSR-4 "App\": ./clases | intervention/image, phpmailer, dompdf
├── package.json + gulpfile.js    # gulp 5 + sass → build/css, copy → build/js
│
├── config/database.php           # mysqli + env DB_HOST/USER/PASSWORD/NAME (con fallbacks)
├── config/entorno.php            # cargador simple de .env (idempotente, no pisa el entorno real)
├── includes/
│   ├── app.php                   # funciones + database + autoload + setDB()
│   └── funciones.php             # template(), iniciarSesion(), soloAdmin(),
│                                 # soloRegistroAbierto(), escaparValores(),
│                                 # validarID(), paginacion(), paginaNoEncontrada(),
│                                 # numeroALetras(), formatoMoneda(), agregarAlerta()
│
├── clases/                       # namespace App\
│   ├── Usuarios.php              # patrón de canastillasWep + sentencias preparadas
│   ├── Clientes.php              #   $columnasDB, atributos(), crear(), actualizar(),
│   ├── Empresas.php              #   eliminar(), validar(), getErrores(),
│   ├── Productos.php             #   buscarID(), mostrarTodos(), contarTodos()
│   ├── Imagenes.php              #   subida/validación/borrado con Intervention Image
│   ├── Notas.php                 #   bitácora interna compartida (Notas Personales)
│   ├── PreciosCliente.php        #   motor de precio especial por cliente (UPSERT)
│   ├── Cotizaciones.php          #   encabezado + renglones, folio, totales, estados
│   ├── Inventario.php            #   existencias, movimientos (entrada/salida/ajuste) y PDF
│   ├── Ventas.php                #   venta atómica desde una cotización + reversión
│   ├── Proveedores.php           #   directorio de proveedores de material
│   ├── ProveedorEntradas.php     #   registros de recepción (reporte fotográfico)
│   ├── Documentos.php            #   HTML + PDF de una cotización y del inventario (dompdf)
│   └── Correos.php               #   envío por correo (PHPMailer + adjunto PDF)
│
├── templates/                    # header, sidebar, bottomnav, footer, modal, alertas,
│                                 # paginacion, campoFoto, formulario{Clientes,Usuarios,
│                                 # Empresas,Productos,Notas,Cotizaciones,Ventas,Proveedores,
│                                 # ProveedorEntradas}.php, pdfCotizaciones, pdfInventario
├── views/
│   ├── login.php  registro.php  inicio.php  perfil.php  404.php
│   └── admin/
│       ├── index.php
│       ├── usuarios/{index,crear,editar,eliminar}.php
│       ├── clientes/{index,crear,editar,detalle,eliminar}.php
│       ├── empresas/{index,crear,editar,eliminar}.php
│       ├── productos/{index,crear,editar,eliminar}.php
│       ├── notas/{index,crear,editar,accion}.php
│       ├── inventario/{index,ajustar,pdf}.php
│       ├── ventas/{index,crear,editar,detalle,eliminar}.php
│       ├── proveedores/{index,crear,editar,detalle,eliminar}.php
│       ├── proveedores/entradas/{index,crear,editar,detalle,eliminar}.php
│       └── cotizaciones/{index,crear,editar,detalle,eliminar,estado,duplicar,
│                        pdf,enviar,preciosCliente}.php
│
├── src/scss/
│   ├── app.scss
│   ├── base/    _variables  _mixins  _normalize  _tipografia  _index
│   └── layout/  _layout  _sidebar  _bottomnav  _modal  _alertas  _botones
│                _formularios  _tablas  _badges  _cards  _paginacion
│                _notas  _cotizador  _utilidades  _auth
│
├── src/js/
│   ├── app.js
│   └── modulos/ modal.js  alertas.js  validacion.js  filePreview.js  cotizador.js
│
├── build/css/app.css + build/js/app.js
├── public/image/                  # fotos subidas (cuadradas) + logos de negocio
├── storage/correos.log            # registro del envío en modo de desarrollo
├── scripts/imagenes_prueba.php    # genera las fotos de los productos semilla
└── mysql/
    ├── cotizaciones.sql               # esquema (12 tablas)
    ├── migracion_ventas_inventario.sql # stock + ventas + proveedores (aplicada)
    └── datos_prueba.sql               # datos de prueba idempotentes
```

---

## 3. Base de datos

```sql
CREATE DATABASE cotizaciones CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

usuarios
  id, nombre, email UNIQUE, contrasena (bcrypt), telefono,
  rol TINYINT DEFAULT 1 (1 = admin), creado_en

empresas                                   -- 2 filas semilla
  id, nombre, rfc, regimen, direccion, ciudad, estado, cp,
  telefono, email, logo,
  condiciones_envio TEXT, condiciones_compra TEXT,
  activa TINYINT DEFAULT 1, creado_en

clientes
  id, nombre,
  nombre_empresa,                          -- NUEVO
  alias,                                   -- NUEVO (búsqueda rápida)
  rfc, telefono, email, ubicacion,
  vip TINYINT DEFAULT 0,
  descuento_pct DECIMAL(5,2) DEFAULT 0,
  notas, creado_en

productos
  id, empresa_id → empresas,
  nombre, sku, categoria, unidad (pza, juego, kg, m2…),
  descripcion,
  precio DECIMAL(12,2)  → PRECIO DE LISTA (fijo, solo lectura en cotizaciones),
  stock INT NOT NULL DEFAULT 0,             -- NUEVO: existencias en almacén
  imagen (nombre de archivo en public/image/),
  activo TINYINT DEFAULT 1, creado_en

notas                                    -- sección "Notas Personales" (nueva)
  id, usuario_id → usuarios (sólo REGISTRA EL AUTOR; el tablero es
                          compartido, así que la relación es ON DELETE
                          RESTRICT: borrar un autor nunca borra la bitácora),
  titulo, cuerpo,
  categoria ENUM('recordatorio','cotizar','cambios','revisar','otro'),
  referencia, fecha_recordatorio VARCHAR(80),
  fijada TINYINT DEFAULT 0, completada TINYINT DEFAULT 0,
  creado_en, actualizado_en

precios_cliente                           -- precio especial recordado
  id, cliente_id → clientes, producto_id → productos,
  precio DECIMAL(12,2),
  creado_en, actualizado_en,
  UNIQUE (cliente_id, producto_id)

cotizaciones
  id, folio UNIQUE (FOL-2026-0001),
  empresa_id, cliente_id, usuario_id,
  fecha, vigencia_dias DEFAULT 15,
  estado ENUM('pendiente','vendido','cancelado'),
  subtotal, descuento, aplica_iva, iva, total,
  condiciones_envio TEXT, condiciones_compra TEXT, comentarios TEXT,
  creado_en

cotizacion_detalles
  id, cotizacion_id → cotizaciones, producto_id → productos,
  cantidad,
  precio_unitario  → snapshot: precio de lista O precio especial,
  descuento_pct, subtotal

movimientos_inventario                    -- bitácora de existencias (NUEVO)
  id, producto_id → productos (ON DELETE CASCADE),
  tipo ENUM('entrada','salida','ajuste'),
  cantidad INT, saldo INT,
  cotizacion_id → cotizaciones (SET NULL),
  usuario_id → usuarios, notas VARCHAR(160), creado_en

ventas                                    -- entrega de la cotización (NUEVO)
  id, cotizacion_id UNIQUE → cotizaciones,  -- MÁX. 1 VENTA POR COTIZACIÓN
  estado_previo ENUM('pendiente','vendido','cancelado'),
  fecha DATE, ubicacion VARCHAR(60), ubicacion_otra VARCHAR(80),
  importe DECIMAL(12,2), foto, notas TEXT,
  usuario_id → usuarios, creado_en

proveedores                               -- directorio (NUEVO, 2 tablas)
  id, nombre, rfc, telefono, ubicacion,
  tipo_proveedor ENUM('Material','Producto','Servicio','Otro'),
  notas, usuario_id → usuarios, creado_en

proveedor_entradas                        -- reporte fotográfico (NUEVO)
  id, proveedor_id → proveedores (ON DELETE CASCADE),
  fecha DATE, numero_pedido VARCHAR(40), categoria VARCHAR(80),
  descripcion TEXT, foto, notas TEXT,
  usuario_id → usuarios, creado_en
```

---

## 4. Motor de precios (núcleo del cotizador)

### Regla base
`productos.precio` = precio de **lista**, fijo y de **solo lectura** en la cotización.

### Checkbox "No recordar este precio"

```
                ┌────────────────────────────────────────┐
 precio ───────►│ ¿ es distinto de productos.precio ?     │
 escrito        └────────────────────────────────────────┘
                       │ SÍ                        │ NO
                       ▼                           ▼
              ¿checkbox "No recordar"      se usa el de LISTA
              está MARCADO?                (precios_cliente intacto)
                 │          │
                SÍ         NO  ◄── default
                 │          │
                 ▼          ▼
      NO escribe en   UPSERT en precios_cliente
      precios_cliente  (queda recordado para ESE cliente;
      (solo persiste    la próxima cotización viene prellenada
       el snapshot en   y el admin puede cambiarlo)
       cotizacion_detalles)
```

- El checkbox aparece **solo cuando `precio_escrito ≠ precio_lista`**; si son iguales está oculto/deshabilitado.
- El admin puede **cambiar el precio en cualquier cotización futura** aunque ya exista uno recordado: el campo viene precargado y se sobrescribe libremente.
- **Borrar un precio especial:** desde el módulo Clientes → ficha → lista de precios especiales → eliminar. Nunca se borra automáticamente desde la cotización.

### Recálculo automático del resumen final

```
Σ (cantidad × precio_unitario)                       → SUBTOTAL BRUTO
− Σ descuento por renglón (bruto × desc_linea/100)   ┐
− descuento global % (sobre el base tras los renglones,│ → DESCUENTO
   precargado con clientes.descuento_pct, editable)  ┘
subtotal − descuento                                  → BASE GRAVABLE
× 16% si el checkbox "Aplicar I.V.A." está activo    → I.V.A.
                                                    → TOTAL
                                                      + importe con letra
```

Este es exactamente el orden del mockup «Resumen de Liquidación»: el subtotal
que muestra la tabla es **bruto** (antes de los descuentos), y tanto el
descuento por renglón como el global aparecen como partidas negativas
separadas del total.

Montos con `font-variant-numeric: tabular-nums`, moneda MXN.

### Datos al cliente (PHP → JS, sin fetch)
El PHP renderiza cada producto con `data-precio-lista` y, si existe, `data-precio-especial`.
El JS lee los `dataset` — **cero `innerHTML`**.

---

## 5. Seguridad y protección de rutas

```php
iniciarSesion()         // session_start + redirect /login si no hay sesión
soloAdmin()             // sesión + rol === 1, si no → redirect
soloRegistroAbierto()   // lee getenv('REGISTRO_ABIERTO'); si no es "1" →
                        //   redirect /login?st=registro_cerrado
```

- Todas las rutas `/admin/*` llevan el middleware `soloAdmin`.
- `/registro` está declarada en el Router pero con `soloRegistroAbierto` → **cerrada por defecto**; muestra un *toast* visual (nunca `alert`).
- Con `REGISTRO_ABIERTO=1` el registro crea usuarios con **rol 1 (admin)**.
- Contraseñas con `password_hash()` / `password_verify()` (bcrypt).
- Consultas con `escape_string()` + `filter_var(FILTER_VALIDATE_INT)` en IDs.
- Salida escapada con `escaparValores()` (`htmlspecialchars`).

---

## 6. Componentes visuales unificados

> Requisitos: *"toda alerta o ventana modal debe tener el mismo estilo"* y
> *"las ventanas modales deben tener el mismo tipo de diseño"*.

| Componente | Archivo | Uso |
|---|---|---|
| Modal | `templates/modal.php` + `_modal.scss` | crear / editar / confirmar — **uno solo**, reutilizado por todos los CRUD |
| Toast / Alerta | `templates/alerta.php` + `_alertas.scss` | éxitos y errores (`?st=1/2/3`), inyectados con `createElement` |
| Botones | `_botones.scss` | primario, secundario, destructivo, texto |
| Badges | `_badges.scss` | VIP, estados (pendiente/vendido/cancelado), stock |
| Tablas | `_tablas.scss` | cabecera `#F8FAFC`, filas alternas, numérico a la derecha |
| Formularios | `_formularios.scss` | inputs 36px, foco índigo + halo, labels encima |
| Paginación | `_paginacion.scss` | misma que canastillasWep |
| Sidebar | `_sidebar.scss` | 260px fijo → 64px en tablet → off-canvas en móvil |

**Paleta** (tomada de las maquetas renderizadas de `design/`, que difieren del
`DESIGN.md` original):

- Azul acero `#5A92C3` / `#4679A6` / claro `#EAF1F8` — acciones primarias y acentos
- Fondo `#EDEDED`, texto `#4D4C53`, bordes hairline
- Óxido `#B46424` — destacados, advertencias
- Verde `#16A34A` — estados aprobados, VIP, éxitos
- Rojo `#E11D48` / `#DC2626` — errores y acciones destructivas

**Tipografía**: Plus Jakarta Sans (títulos) + Inter (datos) — todo en `rem`.

**Breakpoints**: `$telefono 390px · $tablet 768px · $desktop 1200px · $desktopXL 1400px`.

---

## 7. Reglas de código (OPENCODE.md)

- HTML semántico (`<header> <nav> <main> <section> <table> <form>`), textos en **español**.
- `let` / `const` — **nunca `var`**.
- **Sin** `alert()`, `confirm()`, `prompt()` → feedback visual en el DOM.
- **Sin** `innerHTML` → `document.createElement()` + `appendChild()`.
- `e.preventDefault()` en **todo** `submit` y en `click` de botones que no navegan.
- Medidas en **`rem`**, maquetación con **flexbox y grid**, 100% responsive.
- Código documentado con comentarios en español, nombres descriptivos.
- Dudas → revisar `OPENCODE.md`; si no está → **preguntar al usuario**.

---

## 8. Fases de implementación

### Fase 1 — Infraestructura ✅ completada
Estructura completa, `Router.php`, `.htaccess`, `composer.json`, `package.json` + `gulpfile.js`,
`config/database.php`, `includes/{app,funciones}.php`, SCSS con la paleta del diseño, JS modular,
templates base, `login`, `registro` (con middleware), `404`, rutas declaradas con `soloAdmin`,
build funcionando.
**Entregable:** app corriendo con login y panel protegido. ✔

### Fase 1b — Ajustes de interfaz ✅ completada
1. **Selección de sección:** el estado activo del menú se calcula desde la URL dentro de
   `templates/sidebar.php` y `templates/bottomnav.php` (antes dependía de que cada vista
   pasara `$rutaActiva` a mano y se podía olvidar). Cada pantalla marca exactamente un enlace.
2. **Menú móvil inferior (estilo Facebook):** `templates/bottomnav.php` — barra fija con 6
   iconos (Inicio · Histórico · **Nueva** · Clientes · **Notas** · Menú). El botón *Menú*
   abre el cajón lateral con Productos, Notas, Empresas y Usuarios, y queda resaltado cuando
   la pantalla activa vive dentro del cajón. Se eliminó el botón hamburguesa de la barra
   superior. **En móvil el cajón se despliega ancho y muestra el nombre de cada sección
   junto a su icono.**
3. **Desplazamiento horizontal:** `.principal`, `.tabla-contenedor`, `.tabla-toolbar`,
   `.paginacion` y la propia barra inferior se deslizan si el contenido excede la línea
   (más las utilidades `.deslizar-x` y `.deslizar-x-silencioso`).
**Entregable:** navegación coherente en escritorio, tablet y móvil. ✔

### Fase 2 — Base de datos ✅ completada
`mysql/cotizaciones.sql` completo + 2 filas semilla en `empresas` + 1 usuario administrador.
**Entregable:** BD creada e importada en local. ✔ *(verificada en vivo por el CRUD de Clientes)*

### Fase 3 — CRUDs ✅ completada

| Módulo | Estado |
|---|---|
| `Clientes` | ✅ listado + búsqueda + paginación, alta, edición, detalle, baja con modal |
| `Usuarios` | ✅ listado, alta, edición, baja · protegido contra borrado propio y contra dejar el panel sin admin · contraseña opcional en la edición |
| `Empresas` | ✅ listado, alta, edición, baja · carga de **logo** con `input file` · cláusula `RESTRICT` si tiene productos/cotizaciones |
| `Productos` | ✅ listado con filtro por negocio y estado, alta, edición, baja · **`input file` + preview + Intervention Image** (cuadrado 1000×1000 + miniatura 300×300 en `public/image/`) |

`Clientes` quedó como **módulo de referencia**: la clase `clases/Clientes.php` (patrón de
canastillasWep + sentencias preparadas), la plantilla de formulario compartida entre alta y
edición, y las 5 vistas bajo `views/admin/clientes/`. Los otros tres módulos replican ese
mismo patrón (clase + `templates/formularioX.php` + vistas `index/crear/editar/eliminar`).

**Verificación:** 62/62 comprobaciones automatizadas (`/tmp/prueba_crud.py`) — altas válidas
e inválidas, edición, borrados con confirmación modal, subida/diagnóstico/borrado de imágenes,
dimensiones 1000×1000 y 300×300, huérfanos, 404 y protección de sesión.
**Entregable:** los 4 CRUD funcionando con modales y toasts unificados. ✔

### Fase 3b — Sección «Notas Personales» ✅ completada
Sección que **no estaba en `OPENCODE.md`** pero sí en las maquetas
`design/notas_personales/` y `design/notas_personales_m_vil/`.

- Tabla `notas` (privada por usuario: `usuario_id`), clase `clases/Notas.php`,
  4 rutas (`/admin/notas`, `/crear`, `/editar`, `POST /accion`) y
  `views/admin/notas/{index,crear,editar,accion}.php`.
- Tablero con resumen (Notas activas · Fijadas · Acuerdos), buscador, chips de filtro
  deslizables y tarjetas con categoría, contenido, referencia asociada y fecha.
- Acciones: **editar · fijar/desfijar · marcar hecha · eliminar** (la última con modal
  de confirmación; nunca `confirm()`).
- Entrada en el sidebar **después de Catálogo Productos** y en la barra inferior móvil.
- En móvil, el cajón lateral vuelve a ser ancho y **muestra el nombre de cada sección
  junto a su icono** (antes la regla de tablet ocultaba el texto).
**Entregable:** bitácora interna funcionando en escritorio y móvil. ✔

### Fase 4 — Cotizador ✅ completada

Selector de cliente/empresa, catálogo con fotos y stepper, campo precio cliente + checkbox
"No recordar", descuento global, I.V.A. 16% opcional, condiciones de envío/compra + textarea de
comentarios, resumen con totales en vivo, folio automático.

| Pieza | Estado |
|---|---|
| `clases/Cotizaciones.php` | ✅ encabezado + renglones · `calcular()` · folio `FOL-<año>-<NNNN>` generado **con `MAX(folio)`** dentro de la transacción (3 reintentos ante duplicado) · transacciones con `rollBack` · `crear/actualizar/eliminar/cambiarEstado` |
| `clases/PreciosCliente.php` | ✅ motor de precios: `precioDe`, `todosPorCliente`, `precioAPlicar` y **UPSERT `guardar()`** que **no escribe nada si el precio coincide con el de lista** · los acuerdos jamás se borran solos |
| `templates/formularioCotizaciones.php` | ✅ una sola plantilla para alta y edición: encabezado, catálogo, renglones, términos, resumen y `#cotizador-estado` (JSON de partida) |
| `src/js/modulos/cotizador.js` | ✅ filtra el catálogo, agrega renglones con `createElement`, calcula totales en vivo, trae los precios del cliente por `fetch` (JSON, nunca HTML) |
| `src/scss/layout/_cotizador.scss` | ✅ secciones, cartas del catálogo, tabla de renglones, resumen de liquidación y vista de detalle |
| Vistas | ✅ `crear`, `editar`, `detalle` (documento de sólo lectura con cambio de estado) |

- **Renglones:** cantidad, precio unitario (lista por defecto, editable), descuento por renglón y
  la casilla **«No recordar este precio»**. Desmarcada y con un precio distinto al de lista →
  se guarda en `precios_cliente`; marcada → el importe sólo queda como instantánea del renglón.
- **Totales en el orden del mockup:** subtotal bruto → descuento por renglón + descuento global %
  (precargado con `clientes.descuento_pct`) → base gravable → I.V.A. 16 % opcional → total y
  **importe con letra** (`numeroALetras()` en `includes/funciones.php`, réplica `letaCotizador`
  en JavaScript).
- **Sin formularios anidados:** el buscador del catálogo es un `<div class="buscador">`, no un
  `<form>` dentro del formulario de guardado.
- Los precios del cliente se piden a `GET /admin/cotizaciones/precios-cliente?cliente=ID`
  (JSON puro: se reconstruye con `createElement`, nunca con `innerHTML`).

**Entregable:** crear / editar / ver cotización. ✔

### Fase 5 — Histórico, correo y PDF ✅ completada

Histórico por cliente (filtro + paginación) · enviar por correo (PHPMailer/SMTP)
· exportar PDF (dompdf).

| Pieza | Estado |
|---|---|
| `views/admin/cotizaciones/index.php` | ✅ tarjetas de estadísticas + filtros (búsqueda por folio/cliente/alias/RFC, negocio, cliente, estado, rango de fechas) con botón **Buscar** y ayuda **Limpiar** · paginación |
| `clases/Documentos.php` | ✅ `html()` + `pdf()` (dompdf) + `descargar()`; el HTML sale de `templates/pdfCotizaciones.php` |
| `templates/pdfCotizaciones.php` | ✅ documento completo: datos fiscales, cliente, partidas con miniatura, condiciones, resumen con letra y pie. **Imágenes en base64** y sólo tablas (sin grid/flex) para dompdf |
| `clases/Correos.php` | ✅ PHPMailer con la configuración del entorno · adjunta el PDF · **`CORREO_MODO=log` en desarrollo** deja el mensaje en `storage/correos.log` y la pantalla lo advierte con una alerta de advertencia |
| `views/admin/cotizaciones/{pdf,enviar}.php` | ✅ exportación y envío, ambos `soloAdmin` |
| `config/entorno.php` + `.env` | ✅ cargador de `.env` (no pisa el entorno real) · plantilla en `.env.example` |

- El PDF de la exportación y el adjunto del correo salen **de la misma plantilla**: son idénticos.
- Con `CORREO_MODO=smtp` sólo hacen falta `SMTP_HOST/PORT/USUARIO/PASS/DESDE` en `.env`.

**Entregable:** histórico filtrable, PDF descargable y correo adjunto. ✔

### Fase 6 — Ventas, Inventario y Proveedores ✅ completada

Encabezados de tabla centrados · divisoria en el menú + tres secciones nuevas · motor de
existencias · venta atómica desde la cotización · PDF de inventario · reporte fotográfico de
proveedores.

| Pieza | Estado |
|---|---|
| `templates/sidebar.php` | ✅ bandera `divisor` → `<hr class="sidebar__divisor">`; enlaces **Ventas**, **Inventario** y **Proveedores** (`subrutas`, `soloEscritorio => false`, visibles con nombre en el cajón móvil) |
| `clases/Inventario.php` | ✅ `stock()`, `faltantes()`, `descontar()/devolver()/ajustar()` con bitácora, listado + filtros + paginación, `movimientos()`, `paraPdf()` |
| `clases/Ventas.php` | ✅ `UBICACIONES` (9 + "Otro"), `crearDesdeCotizacion()` en transacción, `eliminar()` con reversión, `cotizacionesDisponibles()`, `validar()` |
| `clases/Cotizaciones.php` | ✅ `aplicarEstadoEnTransaccion()` (`FOR UPDATE`, sólo transiciones reales), `cambiarEstado()`; `crear()/actualizar()/eliminar()` sincronizan el stock |
| `clases/Proveedores.php` + `clases/ProveedorEntradas.php` | ✅ directorio y registros de recepción con filtros, validación y borrado en cascada |
| `clases/Imagenes.php` | ✅ modo `informe` (`scaleDown(1600,1600)` sin recorte) + `subirInforme()` |
| `clases/Documentos.php` | ✅ `htmlInventario()`, `pdfInventario()`, `descargarInventario()` |
| `templates/pdfInventario.php` | ✅ encabezado (fecha, logo, nombre) + **una tabla por empresa** + fila "Totales de …" con piezas e importe |
| `views/admin/inventario/*` | ✅ lista con cuadro de cantidad editable por producto, carga manual con bitácora y descarga del PDF |
| `views/admin/ventas/*` + `templates/formularioVentas.php` | ✅ alta en dos pasos (elegir cotización → datos de la entrega), detalle, edición, baja con reversión |
| `views/admin/proveedores/*` y `.../entradas/*` | ✅ CRUD completo + `detalle.php` como reporte fotográfico |
| `views/admin/cotizaciones/detalle.php` | ✅ nota de faltantes + modal de confirmación en el botón **Vendido** + **Registrar venta** / **Ver venta** + aviso de devolución de stock al eliminar |
| `mysql/migracion_ventas_inventario.sql` | ✅ aplicada (respaldo previo) y reflejada en `mysql/cotizaciones.sql` |
| `src/js/app.js` | ✅ paso 8: abre `#campo-ubicacion-otra` al elegir *"Otro"* |
| `_tablas.scss` / `_sidebar.scss` / `_cards.scss` / `_formularios.scss` | ✅ `th` centrados, `.sidebar__divisor`, `.inventario-cantidad`, `.lista-simple`, `.foto-acta`, `.campo--oculto` |

**Reglas cerradas de esta fase:**

1. El stock **sólo** cambia con la carga manual o con una venta (los proveedores no mueven almacén).
2. Al marcar **Vendido** con existencias insuficientes se **avisa en el modal** y se permite seguir.
3. Una cotización admite **como máximo una venta**; borrarla devuelve el stock y restaura
   `estado_previo`.
4. Todo cambio queda registrado en `movimientos_inventario` con su `saldo`.

**Entregable:** venta registrada desde la cotización, inventario valorizado en PDF y reporte
fotográfico de proveedores. ✔

---

## 9. Puntos a vigilar

1. **`gulpfile.js`** de canastillasWep solo *watch*-ea (la tarea `build` no compila).
   En el nuevo, `npm run build` debe compilar de verdad.
2. **`includes/app.php`** usa `require 'funciones.php'` con ruta relativa (rompe si cambia el cwd).
   Se resuelve con `__DIR__`.
3. **Rutas con `$_SERVER['REQUEST_URI']`**: endurecer con `parse_url` + `rtrim` de barra final.
4. Las maquetas de `design/` traen imágenes de `lh3.googleusercontent`: **no se copian**;
   se sustituyen por `productos.imagen` y un placeholder propio.
