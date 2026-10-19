# documentacion.md — Documentación general del proyecto `cotizaciones`

> **Qué es este archivo.** La referencia completa de la aplicación: qué hace,
> cómo se monta, cómo está organizada el código, qué reglas de negocio aplica,
> cómo se ve y cómo se verifica. Está pensada para leerse de punta a punta
> cuando alguien (o tú mismo dentro de unos meses) abra el proyecto por
> primera vez.
>
> **No duplica los otros documentos:**
>
> | Documento | Qué responde |
> |---|---|
> | `OPENCODE.md` | El encargo original y las reglas de código. |
> | `plan.md` | El plan por fases y su estado. |
> | `cambios.md` | El *qué cambió* respecto a `canastillasWep`, ronda por ronda. |
> | `cambios/cambios.md` | El *por qué*: razonamientos, trampas y decisiones. |
> | **`cambios/documentacion.md`** | **El *cómo está*: referencia general del proyecto.** |

---

## Índice

1. [Qué es la aplicación](#1-qué-es-la-aplicación)
2. [Stack y versiones](#2-stack-y-versiones)
3. [Montaje desde cero](#3-montaje-desde-cero)
4. [Variables de entorno](#4-variables-de-entorno)
5. [Mapa del proyecto](#5-mapa-del-proyecto)
6. [Cómo funciona una petición](#6-cómo-funciona-una-petición)
7. [Base de datos: 13 tablas](#7-base-de-datos-13-tablas)
8. [Roles, sesión y permisos](#8-roles-sesión-y-permisos)
9. [Guía de módulos](#9-guía-de-módulos)
10. [Reglas de negocio](#10-reglas-de-negocio)
11. [Salidas: PDF y correo](#11-salidas-pdf-y-correo)
12. [Sistema de diseño](#12-sistema-de-diseño)
13. [Accesibilidad y reglas de código](#13-accesibilidad-y-reglas-de-código)
14. [JavaScript del cliente](#14-javascript-del-cliente)
15. [Verificación: pruebas y auditorías](#15-verificación-pruebas-y-auditorías)
16. [Despliegue](#16-despliegue)
17. [Solución de problemas](#17-solución-de-problemas)
18. [Glosario](#18-glosario)
19. [Documentación relacionada](#19-documentación-relacionada)

---

## 1. Qué es la aplicación

Una **aplicación web interna para cotizar productos** de dos negocios —
**Soluciones en Acero** y **Canastillas de la Baja** — y llevar el ciclo
completo de cada pedido: cotizar → enviar → vender → descontar inventario →
registrar la compra a proveedores.

No hay sitio público: la raíz `/` redirige al panel o a la pantalla de acceso.

**Alcance funcional:**

- Acceso con login/registro de administradores (registro configurable).
- CRUD de **usuarios, clientes, empresas (negocios), productos** y **notas**.
- **Cotizador** con catálogo visual, imagen y cantidad por producto.
- **Precio de lista** por producto con **precio especial por cliente**.
- Totales en vivo: descuentos (globales y por renglón) e **IVA del 16 %**.
- Envío de la cotización **por correo** y **exportación a PDF**.
- Histórico de cotizaciones por cliente con filtros, estados y folio.
- **Ventas** nacidas de una cotización, con descontado automático de stock.
- **Inventario** con stock manual, bitácora de movimientos y PDF de cierre.
- **Proveedores** con ficha tipo reporte fotográfico de entradas, balance de
  pagos y catálogo de **categorías de compra**.
- Panel de **ganancias** separado por negocio.

**Qué no es:** no es una tienda en línea, no tiene carrito público, no tiene
roles distintos (todos los usuarios son administradores) y no multi-empresa
por usuario: las dos empresas conviven en el mismo catálogo y se separan por
`empresa_id`.

---

## 2. Stack y versiones

| Capa | Tecnología | Versión comprobada |
|---|---|---|
| Lenguaje | PHP | ≥ 8.1 (probado en **8.3**, con GD) |
| Base de datos | MySQL | 8.x · `utf8mb4` |
| Front-end | HTML5 semántico + CSS3 | — |
| Hojas de estilo | **Sass** (Dart Sass) compilado con **Gulp** | sass 1.84 · gulp 5 |
| Lógica de cliente | JavaScript **vanilla** (sin frameworks) | Node 24 |
| PDF | **dompdf** | ^3.1 |
| Correo | **PHPMailer** | ^6.9 |
| Imágenes | **Intervention Image** v3 | ^3.11 |
| Autoload | Composer (PSR-4 `App\` → `clases/`) | — |
| Pruebas de interfaz | **Playwright** (sólo desarrollo) | 1.56.1 (chromium 1194) |

Herramientas de apoyo usadas por las pruebas: `mysql` CLI, `php` CLI,
`pdftotext` / `pdfimages` (poppler-utils).

---

## 3. Montaje desde cero

```bash
# 1. Código y dependencias de PHP
composer install

# 2. Configuración
cp .env.example .env
$EDITOR .env                      # ver sección 4

# 3. Base de datos
mysql -u root -e "CREATE DATABASE cotizaciones CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root cotizaciones < mysql/cotizaciones.sql

# 4. Estilos y JavaScript compilados
npm install
npm run build                     # genera build/css/app.css y build/js/app.js
npm run watch                     # (opcional) recompila al guardar

# 5. Servidor de desarrollo
php -S 127.0.0.1:8017 index.php
```

Abrí `http://127.0.0.1:8017/login`.

### 3.1 Primer usuario

Si `REGISTRO_ABIERTO=1` basta con entrar a `/registro`. Si está en `0`
(valor actual), dalo de alta a mano:

```bash
HASH=$(php -r 'echo password_hash("TuClave1234!", PASSWORD_BCRYPT);')
mysql -u root cotizaciones -e "INSERT INTO usuarios (nombre, email, contrasena, rol)
  VALUES ('Administrador', 'tu@correo.com', '$HASH', 1);"
```

> **Importante:** no se usa `mysql/datos_prueba.sql` ni
> `scripts/imagenes_prueba.php` sobre la base real: son material de desarrollo
> y reescribirían datos de producción.

### 3.2 Carpetas que deben existir y ser escribibles

| Ruta | Contenido |
|---|---|
| `public/image/` | Fotos de productos, retratos, logos, fotos de entradas. |
| `storage/` | `correos.log` (modo `CORREO_MODO=log`). |
| `build/` | CSS y JS compilados. Se genera con `npm run build`. |

---

## 4. Variables de entorno

Archivo `.env` en la raíz (nunca se sube al repositorio). Lo carga
`config/entorno.php` con `putenv()`/`getenv()`.

| Llave | Descripción | Ejemplo |
|---|---|---|
| `DB_HOST` | Servidor MySQL. | `localhost` |
| `DB_USER` / `DB_PASSWORD` | Credenciales. | `root` / vacío |
| `DB_NAME` | Base de datos. | `cotizaciones` |
| `REGISTRO_ABIERTO` | `1` = `/registro` abierto; `0` = sólo altas desde el panel. | `0` |
| `CORREO_MODO` | `log` (no envía, guarda en `storage/correos.log`) o `smtp` (envía). | `log` |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SEGURO` | Servidor de correo (`tls`, `ssl`, `none`). | `smtp…` / `587` / `tls` |
| `SMTP_USUARIO`, `SMTP_PASS` | Credenciales SMTP. | — |
| `SMTP_DESDE`, `SMTP_DESDE_NOMBRE` | Remitente visible. | `cotizaciones@…` |
| `PDF_FUENTES` | Ruta absoluta opcional de fuentes para dompdf. | `/usr/share/fonts/…` |

---

## 5. Mapa del proyecto

```
cotizaciones/
├── index.php            Front controller: 95 rutas y sus middlewares
├── Router.php           Enrutador GET/POST (normaliza URL, 404, middleware)
├── .htaccess            Reescritura para Apache
├── .env                 Configuración local (no se versiona; ver sección 4)
├── gulpfile.js          Compila SCSS y concatena JS
├── package.json         scripts: build · watch · pruebas · auditorias
├── composer.json        PSR-4 App\ → clases/ · dompdf, PHPMailer, Intervention
│
├── OPENCODE.md          Encargo original y reglas de código
├── plan.md              Plan por fases y su estado
├── cambios.md           Registro de rondas (qué cambió y cuándo)
│
├── config/
│   ├── database.php     Conexión mysqli (utf8mb4) leyendo de .env
│   └── entorno.php      Cargador de .env (idempotente)
│
├── includes/
│   ├── app.php          Bootstrap: helpers → BD → autoload → setDB()
│   └── funciones.php    17 funciones globales (plantillas, sesión, alertas…)
│
├── clases/              15 clases de negocio, namespace App\
├── views/               63 vistas (una carpeta por módulo)
├── templates/           23 plantillas reutilizables (header, sidebar, formularios, PDF)
├── src/
│   ├── scss/            Sistema de diseño (base/ y layout/) → build/css/app.css
│   └── js/              app.js + 5 módulos → build/js/app.js
├── build/               Salida compilada (no se edita a mano)
├── public/image/        Medios subidos
├── storage/             correos.log
├── mysql/               cotizaciones.sql, migraciones y datos de prueba
├── scripts/             Utilidades puntuales (imágenes y datos de prueba)
├── pruebas/             Suites de Playwright y auditorías (sección 15)
└── cambios/             cambios.md (manual) y documentacion.md (esta guía)
```

**Regla de oro:** las vistas **leen** y los **formularios** deciden. El HTML
vive en `views/` y `templates/`; la lógica de consulta en `clases/`; el estilo
en `src/scss/` y **nunca** se edita `build/` a mano.

---

## 6. Cómo funciona una petición

```
navegador
   │
   ├─ Apache:  .htaccess reescribe todo lo que no sea archivo real → index.php
   └─ php -S:  index.php devuelve false si el fichero estático existe
   ▼
index.php
   │  require includes/app.php      → helpers, BD, autoload, setDB() a 12 clases
   │  require Router.php            → $router = new Router()
   │  95 declaraciones              → $router->get|post(ruta, vista, middleware)
   ▼
Router::comprobarRutas()
   │  · normaliza barras finales y descarta la query string
   │  · si no coincide Y la ruta empieza por /admin → soloAdmin() (protege 404)
   │  · ejecuta el middleware declarado en esa ruta
   ▼
include views/…                     → la vista calcula datos y llama a
   ▼
template('header', [...])           → <head>, sidebar, topbar, <main> abierto
   … contenido de la vista …
template('footer')                  → cierra </main>, modales, alertas y <body>
   ▼
HTML (200) · o redirect · o views/404.php (404)
```

### 6.1 Registrar una ruta

```php
// index.php — GET muestra el formulario, POST lo procesa. Siempre los dos.
$router->get('/admin/clientes/crear', 'views/admin/clientes/crear.php', 'soloAdmin');
$router->post('/admin/clientes/crear','views/admin/clientes/crear.php','soloAdmin');
```

**Toda ruta bajo `/admin` debe llevar `'soloAdmin'`.** Si no existe, el
router aún aplica `soloAdmin()` por delante, pero declararla es obligatorio
por convención.

### 6.2 Crear una clase nueva

1. `clases/MiClase.php` con `namespace App;`.
2. Si consulta la BD: añadirla al array `$clasesConectadas` de
   `includes/app.php` para que reciba `setDB($db)`.
   **Sin esa línea, cualquier consulta falla en tiempo de ejecución.**

### 6.3 Funciones globales (`includes/funciones.php`)

| Función | Para qué |
|---|---|
| `template($nombre, $datos)` | Incluye `templates/$nombre.php` con `$datos` extraídos al ámbito. |
| `paginaNoEncontrada()` | Muestra la vista 404 **y corta** (`exit`). |
| `escaparValores($v)` | `htmlspecialchars` con UTF-8: **siempre** al imprimir datos. |
| `validarID($id, $ruta)` | Lee y valida `$_GET['id']`; si es inválido redirige. |
| `sesionActiva()` · `iniciarSesion()` | Arranque y alta de sesión. |
| `soloAdmin()` · `soloRegistroAbierto()` | Middlewares de protección. |
| `agregarAlerta($tipo, $texto)` · `obtenerAlertas()` | Flash de alertas (jamás `alert()`). |
| `redirigir($ruta, $tipo, $texto)` | Redirect con alerta en sesión. |
| `paginacion($porPagina, $total, $param)` | Bloque de paginación para listados. |
| `tablaExiste()` · `contarRegistros()` | Auxiliares para estados vacíos. |
| `formatoMoneda($monto)` · `etiquetaEstado($e)` · `numeroALetras($m)` | Formato de salida (moneda, distintivos, total en letras para el PDF). |

---

## 7. Base de datos: 13 tablas

Esquema completo en `mysql/cotizaciones.sql`. Las modificaciones posteriores
viven como migraciones sueltas (`mysql/migracion_*.sql`), no hay un migrador
automático: se aplican a mano en orden.

| Tabla | Qué guarda | Columnas clave |
|---|---|---|
| `usuarios` | Administradores. | `email` (único), `contrasena` (bcrypt), `rol` |
| `empresas` | Los dos negocios y sus datos fiscales. | `nombre`, `logo`, `condiciones_*`, **`activa`** |
| `clientes` | Directorio comercial. | `rfc`, `vip`, **`descuento_pct`**, `foto` |
| `productos` | Catálogo por negocio. | `empresa_id`, `sku`, **`precio`**, **`stock`**, `activo` |
| `precios_cliente` | Precio especial de un producto para un cliente. | `cliente_id` + `producto_id` + `precio` |
| `cotizaciones` | La cotización maestra. | `folio`, `estado`, `subtotal`, `descuento`, `aplica_iva`, `iva`, `total` |
| `cotizacion_detalles` | Sus renglones. | `cotizacion_id`, `producto_id`, `cantidad`, `precio_unitario`, `descuento_pct` |
| `ventas` | Entrega real nacida de una cotización. | `cotizacion_id`, `importe`, `estado_previo`, `ubicacion` |
| `movimientos_inventario` | Bitácora de stock (entrada / salida / ajuste). | `tipo`, `cantidad`, **`saldo`**, `cotizacion_id` |
| `proveedores` | Fichas de proveedores. | `nombre`, `tipo_proveedor` |
| `proveedor_entradas` | Reporte fotográfico de cada entrega. | `fecha`, `numero_pedido`, **`categoria`**, `monto`, `pagado`, `foto` |
| `categorias_proveedor` | Catálogo de categorías de compra. | `nombre` (único) |
| `notas` | Notas personales del panel. | `categoria`, `fijada`, `completada`, `fecha_recordatorio` |

### 7.1 Relaciones importantes

- `cotizaciones.cliente_id → clientes.id`, `.empresa_id → empresas.id`,
  `.usuario_id → usuarios.id` (todas **RESTRICT**: no borras un usuario con
  cotizaciones a su nombre).
- `cotizacion_detalles.cotizacion_id → cotizaciones.id` (borrado en cascada).
- `ventas.cotizacion_id` es **único**: una cotización se vende una sola vez.
- `movimientos_inventario.saldo` guarda el saldo **resultante** de cada
  movimiento, no una diferencia: sirve para auditar sin recalcular.
- `proveedor_entradas.categoria` guarda el **id** de
  `categorias_proveedor`, con `0` como «Sin categoría» y **sin llave foránea**
  a propósito: así una categoría borrada no arrastra entradas (en la ficha se
  muestra «Sin categoría»).

### 7.2 Poblar / migrar

```bash
mysql -u root cotizaciones < mysql/cotizaciones.sql                # instalación limpia
mysql -u root cotizaciones < mysql/migracion_categorias_proveedor.sql
```

---

## 8. Roles, sesión y permisos

- **Sesión PHP** convencional; el flag es `$_SESSION['login']`.
- **Un único rol aplicado: `usuarios.rol = 1` (administrador).** Todo lo que
  está bajo `/admin` exige sesión (`soloAdmin()`); no hay jerarquía de
  permisos dentro del panel.
- `soloRegistroAbierto()` deja pasar `/registro` sólo con
  `REGISTRO_ABIERTO=1`; en caso contrario redirige con alerta explicativa.
- Un usuario **no puede darse de baja a sí mismo** desde
  `/admin/usuarios/eliminar`.
- El **router** protege además las rutas inexistentes bajo `/admin`: si no hay
  sesión, una URL falsa devuelve el 404 sin filtrar nada.

---

## 9. Guía de módulos

### 9.1 Panel (`/admin`)
Resumen: estadísticas, ganancias separadas por negocio, accesos rápidos y
últimos movimientos. `/admin/perfil` edita nombre, teléfono, correo, contraseña
y foto del usuario actual.

### 9.2 Usuarios (`/admin/usuarios`)
Alta, edición y baja de administradores. El formulario muestra **Rol** y
**Alta** como campos de solo lectura etiquetados (siempre «Administrador» y la
fecha de registro). La foto usa `templates/campoRetrato.php` (cuadro 500×500).

### 9.3 Clientes (`/admin/clientes`)
Listado con búsqueda + filtro VIP, ficha (`/detalle`) con retrato, condición
comercial, **precios especiales**, contador de cotizaciones y acceso directo al
cotizador. `descuento_pct` es el descuento que arranca en cada cotización.

### 9.4 Empresas (`/admin/empresas`)
Los dos negocios: razón social, RFC, régimen, domicilio, logo, condiciones de
envío y de compra. La columna se llama **`activa`** (no `estado`). El logotipo
se muestra a tope `10rem` en pantalla y `max-height: 160px` en el PDF: ambas
medidas están en `_cotizador.scss` y `templates/pdfCotizaciones.php`.

### 9.5 Productos (`/admin/productos`)
Catálogo por negocio con SKU, unidad, categoría, descripción, precio de lista y
stock. Imágenes **sólo** por `<input type="file">`. Botones de cabecera:
*Exportar catálogo de productos* y *Nuevo producto*. El listado se ordena por
código (SKU).

### 9.6 Cotizaciones (`/admin/cotizaciones`)

| URL | Qué hace |
|---|---|
| `/crear` | Cotizador: catálogo visual, cantidad por producto, precios en vivo. |
| `/editar?id=` | Mismo formulario precargado; conserva el folio. |
| `/detalle?id=` | Ficha con folio, estado, fechas, renglones, resumen y acciones. |
| `/duplicar` (POST) | Copia una cotización para reutilizarla. |
| `/enviar` (POST) | Correo al cliente. |
| `/estado` (POST) | Cambia pendiente / vendido / cancelado. |
| `/pdf?id=` | Exporta el PDF. |
| `/precios-cliente` (GET) | **Endpoint JSON**: precios especiales para el cotizador. |

El detalle y el histórico comparten el encabezado: la vista pinta su propio
encabezado (`mostrarEncabezado = false` en `template('header', …)`) para que el
folio no aparezca dos veces ni el `<title>` quede duplicado.

### 9.7 Ventas (`/admin/ventas`)
Se crea **desde una cotización aprobada**: registra fecha, ubicación de
entrega, importe y foto. Al crear la venta se pone la cotización en `vendido`
y se descuenta el inventario. El detalle permite ver la venta y su origen.

### 9.8 Inventario (`/admin/inventario`)
Stock por producto con ajuste manual (`POST /admin/inventario/ajustar`),
bitácora de movimientos y botón **Exportar Inventario** (PDF de cierre). Las
existencias pueden quedar en negativo si no alcanza el material: la app lo
avisa en la cotización antes de guardar.

### 9.9 Proveedores (`/admin/proveedores`)

- **Directorio** con contador de entradas por proveedor (el contador es enlace
  a la lista filtrada) y botón *Exportar datos* / *Entradas*.
- **Ficha** (`/detalle`): badge de tipo, entradas registradas y **Balance
  general** con estado *Pendiente* / *Pagado* y botón de pago.
- **Entradas** (`/entradas`): reporte fotográfico — fecha, pedido, categoría,
  monto, descripción, foto y notas — con búsqueda y «Limpiar» cuando hay
  filtro.
- **Categorías** (`/categorias`): CRUD del catálogo compartido con botón
  *Crear Categorías*; no se puede borrar una categoría en uso.

### 9.10 Notas (`/admin/notas`)
**Una sola sección** compartida por toda la app: `recordatorio`, `cotizar`,
`cambios`, `revisar`, `otro`; se pueden fijar y marcar completadas. El chip de
categoría lleva contador de notas de esa categoría.

---

## 10. Reglas de negocio

### 10.1 Precio
```
precio_unitario = precios_cliente (cliente + producto)  si existe
                ↓ si no
                  productos.precio                       (precio de lista)
```

### 10.2 Descuentos
- **Global:** `clientes.descuento_pct` se aplica a toda la cotización.
- **Por renglón:** `cotizacion_detalles.descuento_pct`.
- El desglose se muestra separado (`Cotizaciones::desgloseDescuento()`); el
  porcentaje global que escribe el formulario es un único número, no se
  acumula con el de línea.

### 10.3 IVA
`cotizaciones.aplica_iva = 1` → `iva = base × 16 %`; `total = base + iva −
descuentos`. El PDF de catálogo incluye la leyenda de IVA **por empresa**.

### 10.4 Folio
`FOL-<año>-<NNNN>` (`FOL-2026-0001`), correlativo por año, se asigna al crear
y **se conserva** al editar.

### 10.5 Estados
`pendiente → vendido` · `pendiente → cancelado` · reversibles desde el detalle.
Un cambio a `vendido` dispara el descuento de inventario.

### 10.6 Inventario
- Las **únicas** fuentes de cambio de stock son: la venta (salida), el ajuste
  manual (entrada/salida) y la devolución de una venta (entrada).
- Cada cambio escribe un registro en `movimientos_inventario` con el `saldo`
  resultante.
- `descontar()` y `devolver()` **no** abren transacción: se usan dentro de la
  transacción que ya abrió la operación de mayor nivel.

### 10.7 Proveedores
Una entrada es un **reporte**: fecha, número de pedido, categoría, monto,
descripción, foto y notas. `pagado = 1` la saca del balance pendiente. El
balance agrupa por `numero_pedido`.

---

## 11. Salidas: PDF y correo

### 11.1 PDF (dompdf)
| Plantilla | Ruta | Qué exporta |
|---|---|---|
| `pdfCotizaciones.php` | `/admin/cotizaciones/pdf?id=` | Cotización con logo, renglones, totales, total en letras. |
| `pdfCatalogo.php` | `/admin/productos/pdf` | Catálogo: Imagen · Clave · Nombre · Costo, sin totales, filas compactas. |
| `pdfInventario.php` | `/admin/inventario/pdf` | Cierre de inventario. |
| `pdfProveedores.php` | `/admin/proveedores/pdf` | Entradas de proveedores con leyenda de IVA. |

Los estilos de estos PDF están **dentro de cada plantilla** (dompdf no lee el
CSS de la app), así que una mejora de contraste o de medida debe replicarse
ahí.

### 11.2 Correo (PHPMailer)
`clases/Correos.php`. Con `CORREO_MODO=log` no se envía nada: el mensaje queda
en `storage/correos.log` y la interfaz avisa que es modo de prueba. Con `smtp`
se envía de verdad con las llaves `SMTP_*`.

---

## 12. Sistema de diseño

Todo vive en `src/scss/base/_variables.scss` y se compila con
`npm run build`.

### 12.1 Paleta (contraste WCAG AA verificado)

La regla que gobierna la paleta: **texto de 14–18 px necesita 4.5:1**, texto
grande 3:1 y elementos gráficos 3:1. Por eso existen **dos azules** además del
relleno: el mismo tono no sirve igual como fondo (donde el texto es blanco) que
como texto (donde el fondo es claro).

| Variable | Valor | Uso | Contraste |
|---|---|---|---|
| `$color-primario` | `#4679a6` | Relleno de botones, menú activo, chips | blanco encima **4.6:1** |
| `$color-primario-oscuro` | `#38648c` | Hover/active del relleno | blanco encima **6.2:1** |
| `$color-primario-texto` | `#3d6d99` | El azul **como texto** sobre fondo claro | **5.5:1** |
| `$color-primario-claro` | `#eaf1f8` | Fondos suaves, fila activa | — |
| `$color-primario-borde` | `#c7dcef` | Bordes de contenedores azules | — |
| `$color-texto` | `#4d4c53` | Texto principal | **8.5:1** |
| `$color-texto-secundario` | `#64646a` | Etiquetas, ayudas, cabeceras de tabla | **5.9:1** |
| `$color-rust` / `$color-rust-texto` | `#b46424` / `#9a5220` | Acento / texto del badge VIP | texto **5.1:1** |
| `$color-exito-*` · `$color-error-*` · `$color-advertencia-*` | ver fuente | Estados de alerta y badge | ≥ 4.5:1 |
| `$color-fondo` / `$color-superficie` | `#ededed` / `#ffffff` | Fondo de la app / tarjetas | — |

> **Historial:** antes eran `#5a92c3` (azul primario, 3.3:1 con texto blanco)
> y `#8c8c90` (gris, 3.4:1) que no cumplían AA. Una auditoría encontró 888
> fallos; tras el ajuste quedan **0**.

### 12.2 Tipografía

- Títulos: **Plus Jakarta Sans** · Cuerpo: **Inter** (Google Fonts, con
  fallback `Segoe UI`, `system-ui`).
- Escala en rem: `0.75 · 0.875 · 1 · 1.125 · 1.25 · 1.5 · 1.75 · 2.25`.
- Interlineado 1.5 en cuerpo, 1.2 en títulos.

### 12.3 Espaciado, radios, sombras

| Espaciado | `xs .25` | `sm .5` | `md 1` | `lg 1.5` | `xl 2` | `2xl 3` (rem) |
|---|---|---|---|---|---|---|
| **Radio** | `sm 2` | `4` (botones/inputs) | `md 6` | `lg 8` (tarjetas) | `xl 12` (modales) | `full 9999px` (pills) |
| **Sombra** | `sombra-1` sombra de tarjeta | `sombra-2` hover | `sombra-3` modal | | | |

### 12.4 Medidas y breakpoints

- Sidebar `16.25rem` (rail de `4rem` en tableta) · topbar `4rem` ·
  control `2.25rem` (sm `2rem`) · modal `42.5rem` · contenedor `100rem`.
- Breakpoints: **390** (móvil) · **768** (tableta) · **1200** · **1400**.
- Transiciones: `150ms` (rápida) y `250ms`.

### 12.5 Capas (`z-index`)

| Capa | z-index |
|---|---|
| Topbar | 40 |
| Sidebar (móvil, fijo) | 70 · sidebar normal 50 |
| Fondo de modal | 60 |
| Menú inferior móvil | 65 |
| **Salto de contenido** (`.saltar-contenido`) | **100** |
| Modal | 80 |
| Alertas | 90 |

### 12.6 Componentes compartidos

| Componente | Clase | Notas |
|---|---|---|
| Botones | `.btn--primario · --secundario · --peligro · --exito · --texto · --sm · --icono · --bloque` | |
| Distintivos | `.badge--aprobado · --error · --info · --neutro · --pendiente · --sin-punto · --vip` | Punto de estado de 6 px; `a.badge` mide 24 px para cumplir WCAG 2.5.8. |
| Alertas | `.alerta--exito · --error · --advertencia · --info` | **Único** sistema de feedback: nada de `alert()`. |
| Modal | `#modal-principal`, `#modal-cuerpo`, `#modal-pie` | Botones `data-cerrar-modal` y `data-confirmar-modal`. |
| Tabla | `.tabla`, `.tabla-toolbar`, `.tabla__acciones`, `.tabla__col-secundaria` | `<caption class="solo-lectores">` obligatoria; `th` centrados. |
| Formularios | `.campo`, `.campo__etiqueta`, `.control`, `.campo__ayuda`, `.campo--oculto` | Etiqueta siempre con `for` + `id` en el control. |
| Estados vacíos | `.vacio` | Se muestra cuando no hay filas. |

---

## 13. Accesibilidad y reglas de código

### 13.1 Lo aplicado en la interfaz

- **Contraste AA** en toda la paleta (auditoría automática en verde).
- **Un solo `<h1>` por página**: el encabezado genérico de `templates/header.php`
  se omite con `'mostrarEncabezado' => false` cuando la vista pinta el suyo
  (fichas de detalle); las vistas `editar` sólo describen, el título lo da el
  header.
- **`<title>` descriptivo**: `titulo` + sufijo de la app.
- **Enlace «Saltar al contenido»** como primer elemento con tabulación,
  visible sólo con foco.
- **Etiquetas asociadas** en todos los controles, incluidos los de solo
  lectura (`<label for>` + `id`).
- **`<caption>`** en las 13 tablas de datos (`.solo-lectores`).
- **`aria-current="page"`** en el ítem activo del menú; `aria-live` en las
  alertas; `aria-hidden` en los iconos decorativos.
- **`prefers-reduced-motion`** desactiva animaciones y transiciones.
- **Foco visible** (`:focus-visible`) en todos los elementos navegables.
- **Objetivos táctiles ≥ 24×24 px** (botón de cerrar alerta a 32 px; badges
  interactivos a 24 px), con las excepciones de la norma para controles
  nativos con etiqueta y enlaces en línea.

### 13.2 Reglas de código (`OPENCODE.md`)

- Texto y comentarios **en español**.
- HTML **semántico**; `let`/`const`, nunca `var`.
- **Prohibido** `alert()`, `confirm()`, `prompt()` e `innerHTML`: se usa
  `crearAlerta`/`agregarAlerta`.
- **Siempre** `event.preventDefault()` en formularios manejados por JS.
- Medidas en **rem**, **flexbox/grid**, diseño **responsivo**.
- Código documentado; un **único** estilo para modales y alertas.

---

## 14. JavaScript del cliente

Sin frameworks. `npm run build` concatena `src/js/**` en `build/js/app.js`.

| Módulo | Responsabilidad |
|---|---|
| `src/js/app.js` | Punto de entrada: arranca los módulos al cargar el DOM. |
| `modulos/alertas.js` | `crearAlerta()` y sus variantes: pintan los toasts y los dejan cerrables. **Reemplaza a `alert`/`confirm`/`prompt`.** |
| `modulos/cotizador.js` | Cantidad por producto, renglones, cálculo en vivo, precios especiales vía `/admin/cotizaciones/precios-cliente`. |
| `modulos/modal.js` | Apertura/cierre de `#modal-principal` y confirmaciones con `data-confirmar-modal`. |
| `modulos/validacion.js` | Validación de formularios en cliente (`data-validar`), sin `preventDefault` olvidado. |
| `modulos/filePreview.js` | Vista previa de imágenes en `<input type="file">` (retratos, fotos de producto y de entradas). |

Si la JS no carga, la app sigue siendo usable: el marcado de alertas y
formularios ya está en el DOM.

---

## 15. Verificación: pruebas y auditorías

Las suites viven en **`pruebas/`** dentro del repositorio y dependen de
`playwright@1.56.1` (devDependency). **Necesitan el servidor levantado.**

```bash
php -S 127.0.0.1:8017 index.php &      # prerequisite

npm run pruebas      # 3 suites de regresión  → 169 comprobaciones
npm run auditorias   # 3 auditorías de interfaz → 0 hallazgos

node pruebas/esquema.mjs /admin/clientes/detalle?id=15   # árbol de encabezados
```

### 15.1 Suites de regresión (`npm run pruebas`)

| Archivo | Comprobaciones | Cubre |
|---|---|---|
| `probar_ronda.mjs` | **46** | Catálogo, logo de pantalla y PDF (medido contra el tope leído de la fuente), balance del proveedor, contador de pendientes, campo Monto, PDF de catálogo y proveedores, desbordamiento en 1440 y 390 px. |
| `probar_ronda2.mjs` | **67** | Leyenda de IVA por empresa, tabla de catálogo compacta, tope de logo en `.scss` **y** plantilla PDF (que coincidan), estado del balance, filtro por categoría, `categoria` como entero con catálogo fijo, otros PDF, desbordamiento. |
| `probar_categorias.mjs` | **56** | CRUD de categorías de proveedor, guardia de borrado, filtros, estado vacío, endpoints GET/POST, desbordamiento. |

**Principio de diseño de las suites:** cada una crea sus **propios** datos
(usuario, proveedor, entradas, cotización) con identificadores
`prueba.*@cotizaciones.local` y los borra al final — nunca reutilizan ni
modifican los datos reales. Las claves de logo y de contraste **se leen del
código fuente**, no están escritas a mano: así un *build* viejo se detecta y
un cambio de medida del usuario no rompe la prueba.

Y vale lo mismo para **los datos que el usuario administra**. Estas suites
nacieron con «6 categorías» y los ids `1` y `2` escritos a mano; un día el
usuario borró el catálogo viejo y creó diez categorías nuevas, y la prueba
cayó en cascada (el alta de entrada rechazaba el id 1). Peor: la de
categorías intentaba borrar «Acero» **esperando que el servidor se negara**,
y al no haber ninguna entrada apuntando a ese id, la respuesta fue *borrada*.
**Nada de lo que el usuario puede crear, renombrar o borrar se fija a mano:**
los catálogos se leen de la base (`SELECT … ORDER BY nombre`), los recuentos
se calculan y las categorías de prueba se apoyan en una que la propia prueba
pone en uso antes de intentar borrarla. Lo único fijo es la semilla que la
prueba se trae ella misma.

Si una suite deja restos porque se interrumpe, la siguiente debe poder
arrancar igual: por eso la preparación borra también las cotizaciones y los
proveedores **de la propia cuenta de prueba** antes de recrearlos (las FK son
`RESTRICT` y bloquearían el alta).

### 15.2 Auditorías (`npm run auditorias`)

| Archivo | Pantallas | Qué mide |
|---|---|---|
| `auditar_ui.mjs` | 36 × (1440 px, 390 px) + listados filtrados | `lang`, viewport, un solo `<h1>`, `title` no duplicado, contraste de `color` con `background-color`, iconos decorativos, *alt* de imágenes, **etiqueta en todos los campos**, `th scope`, `<caption>`, **`Buscar` siempre y `Limpiar` cuando hay filtro**, estado vacío, `aria-label` en botones sin texto, desbordamiento horizontal y objetivos táctiles. |
| `auditar_profundo.mjs` | 26 | Contraste real recorriendo la cadena de fondos, foco visible con Tab, `aria-current`, enlace de salto, `prefers-reduced-motion` en el CSS compilado y táctiles. |
| `auditar_consola.mjs` | 26 | Errores y avisos de consola, `pageerror`, peticiones caídas y respuestas ≥ 400. |

Resultado actual: **0 hallazgos** en las tres.

### 15.3 Comprobaciones manuales útiles

```bash
# Sintaxis PHP de todo el proyecto
find views templates clases includes -name '*.php' -print0 | xargs -0 -n1 php -l

# Recompilar y comprobar que no hay errores de Sass
npm run build

# Estructura de encabezados y etiquetas de una página
node pruebas/esquema.mjs /admin/cotizaciones/detalle?id=32
```

---

## 16. Despliegue

> **Runbook operativo (comandos listos para copiar):**
> [`DESPLIEGUE.md`](../DESPLIEGUE.md) en la raíz del proyecto.

### 16.1 Clúster (la forma real): `./deploy.sh`

El proyecto ya trae todo montado para el clúster MicroK8s que sirve
`*.iguzman.com.mx`:

| Pieza | Para qué |
|---|---|
| `Dockerfile` | Imagen PHP 8.3 + Apache con `mysqli`, `gd`, `zip` y `exif`; Composer en una etapa aparte; genera `cotizaciones.ini` con `upload_max_filesize = 20M`, `post_max_size = 25M`, `memory_limit = 256M`, `max_execution_time = 120`. |
| `.dockerignore` | Deja fuera `.env`, `vendor/`, `node_modules/`, `helm/`, `pruebas/`, `cambios/`, `*.md` y el dump SQL. |
| `helm/cotizaciones/` | Chart con la app, MySQL, Ingress (`https://cotizaciones.iguzman.com.mx`, TLS con cert-manager), Secret, ConfigMap con el dump, los dos PVC y el Job que importa la base. |
| `helm/cotizaciones/values.local.yaml` | Secretos reales (contraseñas DB y SMTP). **No va a git.** |
| `deploy.sh` | El proceso entero en un comando. |
| `.deploy-tag` | Último tag publicado (el script lo auto-incrementa). |

**Requisitos (sólo la primera vez):**

```bash
docker login                     # ya hay credencial en ~/.docker/config.json
kubectl config current-context   # debe ser "microk8s"
kubectl get ns cotizaciones      # namespace creado
```

**Para publicar cualquier cambio:**

```bash
cd ~/Documentos/programacion/cotizaciones
./deploy.sh          # tag automático = .deploy-tag + 1
./deploy.sh 7        # o un tag concreto
```

Lo que hace, en orden: compila `build/` (los assets **no** los compila la
imagen, que no lleva Node) → `docker build` → `docker push` a
`gmr27/cotizaciones:<tag>` y `:latest` → `helm upgrade --install` con
`values.yaml` + `values.local.yaml` y `--set image.tag=<tag>` →
`kubectl rollout status` de app y MySQL → resumen con pods, servicios e
ingress.

**Lo que NO se toca en un upgrade:** los datos. La base vive en el PVC
`mysql-data` (2Gi) y las fotos subidas en `cotizaciones-images` (1Gi). El Job
de importación sólo corre si la tabla `usuarios` no existe, así que un
`helm upgrade` jamás pisa lo que ya hay. Consecuencia: **las migraciones
nuevas hay que aplicarlas a mano**, una vez, contra el MySQL del clúster:

```bash
kubectl exec -i deploy/mysql -n cotizaciones -- \
  sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot cotizaciones' \
  < mysql/migracion_xxx.sql
```

### 16.2 Servidor tradicional (alternativa)

1. **Código**: subir todo excepto `.env`, `node_modules/`, `design/` y
   `storage/*.log`.
2. **`composer install --no-dev`** para obtener dompdf, PHPMailer e
   Intervention.
3. **`npm ci && npm run build`** (o subir `build/` ya compilado). **No** hace
   falta `node_modules` en el servidor.
4. **`.env`** con `CORREO_MODO=smtp` y las credenciales reales.
5. **Base de datos**: crearla e importar `mysql/cotizaciones.sql`; después, las
   migraciones pendientes de `mysql/migracion_*.sql`.
6. **Permisos**: escritura en `public/image/` y `storage/`.
7. **Apache**: `DocumentRoot` en la raíz del proyecto con
   `AllowOverride All` para que `.htaccess` funcione.
8. **nginx**: reescribir a `index.php` y desactivar la caché de `.php`.

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ { fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
```

9. **DNS/TLS** a cargo del servidor. Verificar `/login` y enviar un correo de
   prueba (modo `log` antes de pasar a `smtp`).

### 16.3 Límite de subida de archivos

Toda imagen pasa por cuatro filtros. **El que manda es el de la aplicación**:

| Capa | Dónde | Valor actual |
|---|---|---|
| **App (PHP)** | `clases/Imagenes.php` → `TAMANO_MAXIMO` | **6 MB** (`6291456`) |
| **App (JS)** | `src/js/modulos/validacion.js` → `TAMANO_MAXIMO_IMAGEN` | **6 MB** — avisa antes de subir |
| PHP | `Dockerfile` → `upload_max_filesize` / `post_max_size` | 20M / 25M |
| Ingress | `values.yaml` → `ingress.proxyBodySize` (`nginx.ingress.kubernetes.io/proxy-body-size`) | 20m |
| Apache | `LimitRequestBody` | sin límite |

Para permitir más que 6 MB hay que subir **el de la app** (y, si hace falta,
el de PHP y el del ingress, que ya tienen holgura). El texto de ayuda que se
ve en los formularios y el mensaje de error se derivan de esas constantes,
así que no queda nada escrito a mano. Cambio obligatorio: `npm run build` y
`./deploy.sh`.

---

## 17. Solución de problemas

### 17.1 Síntomas frecuentes

| Síntoma | Causa | Solución |
|---|---|---|
| CSS o JS desactualizados (tamaños raros) | Se editó el `.scss` sin compilar. | `npm run build`. |
| Página en blanco / 500 | Fallo de PHP. | `php -l` en el archivo; revisar `error_log`. |
| `Class "App\X" not found` | Clase nueva sin registrar. | Añadirla a `$clasesConectadas` en `includes/app.php`. |
| «Falta la clase X sin registrar» / consultas vacías | Falta `setDB()`. | Igual que arriba. |
| Sin correo saliente | `CORREO_MODO=log`. | Ver `storage/correos.log`; para enviar, `smtp`. |
| Iconos como texto | Sin conexión a Google Fonts. | La app funciona igual (fallback de fuente); habilitar la red. |
| Registro cerrado | `REGISTRO_ABIERTO=0`. | Dar de alta el usuario por SQL (sección 3.1). |
| 404 en todas las rutas con `php -S` | Falta `index.php` como router. | `php -S 127.0.0.1:8017 index.php`. |
| Prueba Playwright «Timeout waiting for `/admin`» | La cuenta de prueba no existe o credenciales mal. | Las suites crean la suya; no hace falta crearla a mano. |
| `DELETE FROM usuarios` → `fk_cotizacion_usuario` | Restos de una suite interrumpida (el usuario aún tiene cotizaciones). | Borrar primero las cotizaciones de esa cuenta; las preparaciones ya lo hacen solas. |
| `./deploy.sh` publica pero el cambio no se ve | Los assets no se recompilaron: la imagen **copia** `build/` y no lleva Node. | El script ya ejecuta `npm run build` antes del `docker build`; a mano, `npm run build`. |
| Subida rechazada con **413** | El ingress deja pasar menos de lo que la app acepta. | `values.yaml → ingress.proxyBodySize` (`20m`) y la anotación del Ingress. |
| «La imagen no puede pesar más de 6 MB» aunque PHP admite 20M | El tope real lo pone la aplicación, no PHP. | Subir `Imagenes::TAMANO_MAXIMO` y `TAMANO_MAXIMO_IMAGEN` (JS), `npm run build`, `./deploy.sh`. |
| La app sigue con una tabla o columna vieja tras desplegar | `helm upgrade` no aplica migraciones (el Job sólo importa la base vacía). | Aplicar el `mysql/migracion_*.sql` a mano contra el MySQL del clúster (sección 16.1). |
| Imagen subida no aparece | Permisos de `public/image/`. | `chmod` de escritura. |

### 17.2 Trampas de desarrollo conocidas

- **`template('404')` no existe**: usar `paginaNoEncontrada()` (corta con
  `exit`).
- **`bind_param` con número de argumentos distinto** al de la consulta → 500.
- Las funciones globales necesitan **`\`** delante dentro de un
  `namespace App` (`\escaparValores(...)`).
- Variables SCSS inexistentes: `$espacio-xxs`, `$color-texto-terciario`,
  `$sombra-sutil`, `$fuente-monoespaciada`, `$fuente-md`. Usar las de
  `_variables.scss`.
- **Formularios anidados**: el navegador descarta el interior. Los botones de
  acción de tabla van en formularios hermanos, no dentro del de filtros.
- Ocultar un `.campo` requiere la clase **`.campo--oculto`**.
- Una regla añadida al final del archivo **fuente** va a ganar siempre por
  orden: si no aplica, mirá la especificidad.
- La columna de empresas es **`activa`**, no `estado`.
- Una misma URL puede ser `GET` (mostrar) **y** `POST` (procesar): decláralas
  siempre las dos.
- Si una prueba mide el logo o un color, **léelo de la fuente**, no lo escribas
  a mano: el usuario cambia esas medidas.
- Los `waitForURL` resuelven inmediatamente si la URL ya coincide; medir con
  `getComputedStyle` devuelve siempre píxeles (nunca `auto`); una imagen recién
  insertada tiene `naturalHeight === 0` hasta que carga.
- Quitar `.material-symbols-outlined` antes de comparar etiquetas de botones:
  el icono es una ligadura y su texto es un nombre de glifo.
- Las pruebas **borran al final**; si una se interrumpe, puede quedar un
  usuario `prueba.*@`. Se eliminan solos al volver a ejecutar la suite.

---

## 18. Glosario

| Término | Significado |
|---|---|
| **Cotización** | Propuesta comercial con folio, vigencia y estado. |
| **Renglón** | Un producto con su cantidad y precio dentro de la cotización (`cotizacion_detalles`). |
| **Folio** | Identificador correlativo `FOL-AAAA-NNNN`. |
| **Venta** | Entrega realizada a partir de una cotización; descuenta stock. |
| **Entrada** | Registro de material recibido de un proveedor (reporte con foto). |
| **Balance** | Suma de entradas pagadas y pendientes de un proveedor. |
| **Precio de lista** | `productos.precio`, el precio normal. |
| **Precio especial** | Valor de `precios_cliente` que sustituye al de lista para ese cliente. |
| **Empresa / negocio** | Cada uno de los dos comercios (`empresas`). |
| **Catálogo** | Listado de productos de un negocio, exportable a PDF. |
| **Ajuste** | Movimiento manual de inventario (entrada o salida) con bitácora. |
| **Modo log** | `CORREO_MODO=log`: los correos no se envían, se guardan en `storage/correos.log`. |

---

## 19. Documentación relacionada

| Archivo | Contenido |
|---|---|
| `OPENCODE.md` | Encargo original y reglas de código. |
| `plan.md` | Plan de desarrollo por fases y su estado. |
| `cambios.md` | Diferencias frente a `canastillasWep`, ronda por ronda. |
| `cambios/cambios.md` | Manual funcional: arquitectura, motor de precios, inventario, checklist y trampas. |
| `cambios/documentacion.md` | Este archivo: referencia general. |
| `mysql/cotizaciones.sql` | Esquema completo de las 13 tablas. |

> **Última revisión:** ronda de diseño, interfaz y accesibilidad — contraste
> WCAG AA en toda la paleta (888 fallos → 0), encabezados sin duplicar,
> `<caption>` y etiquetas completos, enlace de salto, objetivos táctiles
> ≥ 24 px, suites de prueba trasladadas a `pruebas/` con
> `npm run pruebas` / `npm run auditorias`, y auditorías en 0 hallazgos.
