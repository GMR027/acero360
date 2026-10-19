# cambios.md — Manual funcional de `cotizaciones`

> **Qué es este archivo.** Es el manual para **reconstruir este proyecto desde cero**
> sin perder lo que se aprendió. Lista todas las diferencias **de funcionalidad**
> (no de estilos) con el proyecto base `canastillasWep` y documenta, componente por
> componente, **qué hace** y **por qué se hizo así**, para que en el próximo proyecto
> no se tenga que redescubrir la solución.
>
> - Si buscas el **registro de rondas** (qué cambió y cuándo), está en `../cambios.md`.
> - Si buscas el **plan y el estado de fases**, está en `../plan.md`.
> - Si buscas la **referencia general** del proyecto (montaje, módulos, base de datos,
>   sistema de diseño y pruebas), está en [`documentacion.md`](documentacion.md).
> - Este archivo es el **por qué**; los otros son el **qué**, el **cuándo** y el **cómo está**.

---

## Índice

1. [Resumen: `canastillasWep` → `cotizaciones`](#1-resumen-canastillaswep--cotizaciones)
2. [Arquitectura: cómo se monta](#2-arquitectura-cómo-se-monta)
3. [Base de datos: 13 tablas y por qué](#3-base-de-datos-13-tablas-y-por-qué)
4. [Seguridad, rutas y sesión](#4-seguridad-rutas-y-sesión)
5. [Motor de precios, IVA y descuentos](#5-motor-de-precios-iva-y-descuentos)
6. [Cotizador (crear / editar)](#6-cotizador-crear--editar)
7. [Folio, estados y transiciones de una cotización](#7-folio-estados-y-transiciones-de-una-cotización)
8. [Inventario y motor de stock](#8-inventario-y-motor-de-stock)
9. [Ventas: la entrega, nacida de la cotización](#9-ventas-la-entrega-nacida-de-la-cotización)
10. [Proveedores y entradas (reporte fotográfico)](#10-proveedores-y-entradas-reporte-fotográfico)
11. [Catálogos CRUD reutilizables](#11-catálogos-crud-reutilizables)
12. [Precio especial por cliente](#12-precio-especial-por-cliente)
13. [Notas: bitácora compartida](#13-notas-bitácora-compartida)
14. [Imágenes con Intervention Image](#14-imágenes-con-intervention-image)
15. [Salida: PDF y correo](#15-salida-pdf-y-correo)
16. [Panel de ganancias](#16-panel-de-ganancias)
17. [Funciones de ayuda del backend](#17-funciones-de-ayuda-del-backend)
18. [JavaScript modular](#18-javascript-modular)
19. [Componentes de interfaz compartidos](#19-componentes-de-interfaz-compartidos)
20. [Verificación automatizada](#20-verificación-automatizada)
21. [Checklist: proyecto desde cero](#21-checklist-proyecto-desde-cero)
22. [Trampas conocidas (léelas antes de empezar)](#22-trampas-conocidas-léelas-antes-de-empezar)

---

## 1. Resumen: `canastillasWep` → `cotizaciones`

### 1.1 Qué se reutilizó tal cual

La **forma** del proyecto, no el negocio:

| Se conserva | Detalle |
|---|---|
| Front controller | `index.php` único + `.htaccess`/guard de `php -S` |
| `Router.php` | `get()`, `post()`, tercer argumento = middleware |
| `includes/app.php` | bootstrap: funciones → BD → autoload Composer → `setDB()` |
| `includes/funciones.php` | `template()`, `paginaNoEncontrada()`, `escaparValores()`, `soloAdmin()`, `paginacion()`, `formatoMoneda()` |
| Estructura MVC ligera | `clases/` (modelo), `views/` (controlador+presentación), `templates/` (fragmentos) |
| Namespace PSR-4 | `App\` → `clases/` vía `composer.json` |
| SASS + gulp | `src/scss/` → `build/css/app.css`, `src/js/` → `build/js/app.js` |
| Intervention Image | ya era dependencia en el proyecto base |
| `Paginación` como plantilla | misma idea, versión con query string preservada |

### 1.2 Diferencias funcionales sustanciales

| Área | `canastillasWep` | `cotizaciones` |
|---|---|---|
| **Tablas** | 5 (`usuarios`, `productos`, `pedidos`, `reportes`, `ubicacion`) | **12** (catálogos + cotizaciones + inventario + ventas + proveedores) |
| **Dominio** | Pedidos y reportes de entrega | **Cotizaciones comerciales** con precios, IVA y descuentos |
| **Roles** | 3: admin / cliente / proveedor (`soloAdmin`, `soloCliente`, `soloProveedor`) con portales separados | **1**: el que inicia sesión es administrador; registro controlado por `REGISTRO_ABIERTO` |
| **Negocios** | Uno solo (`productos.empresa` texto suelto) | **Catálogo `empresas`** con ficha completa (RFC, domicilio, logo, condiciones); todo cuelga de `empresa_id` |
| **Clientes** | No existía como entidad | Ficha completa con VIP, alias, `nombre_empresa`, descuento global y **precios especiales recordados** |
| **Precio** | `productos` sin precio; el costo vivía en el pedido | `productos.precio` = **precio de lista fijo**, y `precios_cliente` = precio acordado por cliente |
| **Cálculo** | `costoSinIva` / `costoConIva` escritos a mano en el pedido | **`Cotizaciones::calcular()`**: subtotal → descuentos → base → IVA 16 % → total, con redondeo en cada paso |
| **Identificador** | `numeroPedido` entero | **Folio `FOL-<año>-<NNNN>`** asignado dentro de la transacción que guarda |
| **Documentos** | No generaba PDF ni correo | **dompdf** + **PHPMailer**, con **el mismo HTML** de origen; exportan inventario, catálogo de productos y balance anual de proveedores |
| **Inventario** | No había | `productos.stock` + bitácora `movimientos_inventario` |
| **Estados** | `estatus` entero en pedido | `ENUM('pendiente','vendido','cancelado')` con transiciones validadas |
| **Ventas** | No existía | Tabla `ventas` **1:1 con la cotización** (`UNIQUE`), con foto de entrega y ubicación |
| **Proveedores** | Portal público (`/proveedor`) | Directorio interno de **3 tablas** + reporte fotográfico + **Balance general** (monto, pagado, deuda) con **estado visible** y **filtro por categoría** (`TINYINT` → `categorias_proveedor`, con **CRUD propio** en `/admin/proveedores/categorias`) + reporte anual |
| **Notas** | No existía | Bitácora compartida por todos los administradores |
| **Estadísticas** | No existía | Panel de ganancias por negocio con filtros mes/semestre/año |
| **Feedback JS** | `var` y lógica suelta | Módulos con `let`/`const`, **sin `alert`/`confirm`/`prompt`** ni `innerHTML` |
| **Dependencias** | `intervention/image` | + `dompdf/dompdf`, `phpmailer/phpmailer`, PHP ≥ 8.1 |
| **Configuración** | valores fijos | **`.env`** (BD, registro, correo `CORREO_MODO=log`, fuentes PDF) |

### 1.3 Por qué se hizo cada cambio (la idea de fondo)

- **Una tabla `empresas` y no un campo de texto**: dos negocios comparten catálogo de
  clientes, usuarios y notas, pero sus productos, condiciones y ganancias son
  independientes. Relación > cadena repetida.
- **Precio de lista separado del precio acordado**: el precio de lista es un dato del
  producto (único y estable); el precio acordado es un **acuerdo con un cliente**
  (variable y que debe recordarse). Mezclarlos en una sola columna obliga a duplicar
  productos.
- **Todo el dinero se calcula en un solo método**: si el detalle, el PDF, el correo y
  la edición calculan aparte, inevitablemente divergen. Un solo `calcular()`.
- **El stock sólo se mueve por dos caminos** (carga manual y venta): cualquier tercer
  camino es un futuro bug de inventario.
- **1 venta por cotización (`UNIQUE`)**: una cotización vendida dos veces es un error
  de negocio, y la forma más barata de impedirlo es dejar que la base de datos lo diga.

---

## 2. Arquitectura: cómo se monta

### 2.1 Ciclo de una petición

```
navegador
   │  GET /admin/inventario?empresa=2
   ▼
.htaccess  (producción)  ─┐
                          ├─→  index.php
php -S … index.php  ──────┘        │
                                   ▼
                          includes/app.php
                            1. funciones.php  (helpers globales)
                            2. config/database.php → PDO/mysqli
                            3. vendor/autoload.php (App\)
                            4. $clase::setDB($db) para cada clase
                                   │
                                   ▼
                          $router->comprobarRutas()
                            • normaliza la URL (sin barra final)
                            • descarta la query string
                            • ¿no existe y empieza por /admin? → soloAdmin()
                            • ¿no existe? → 404 con views/404.php
                            • ejecuta el middleware declarado en la ruta
                                   │
                                   ▼
                          include 'views/admin/inventario/index.php'
                                   │
                                   ▼
                          template('layout', [...])  →  HTML
```

**Por qué así:** un solo punto de entrada permite aplicar seguridad, encoding y
`setDB()` **una sola vez**. En `canastillasWep` el `includes/app.php` usaba rutas
relativas que dependían del archivo que lo incluía; aquí todo va con `__DIR__`.

### 2.2 Registrar una ruta

```php
$router->get ('/admin/inventario',          'views/admin/inventario/index.php', 'soloAdmin');
$router->post('/admin/inventario/ajustar',  'views/admin/inventario/ajustar.php','soloAdmin');
```

**Reglas que no se negocian:**

1. Toda ruta bajo `/admin` lleva `soloAdmin` (el router también lo aplica por defecto
   si la URL no existe: evita que un desconocido descubra secciones nuevas).
2. **Todo borrado es `POST`.** Nunca un `<a href="…eliminar">`; el navegador, los
   crawlers y el botón "atrás" del prefetch ejecutan GET.
3. `GET` = pintar, `POST` = mutar. La vista que recibe ambos se protege con
   `if ($_SERVER['REQUEST_METHOD'] !== 'POST') paginaNoEncontrada();`.

### 2.3 Estructura de carpetas

```
config/       database.php (leer .env)
clases/       modelos: Usuarios, Empresas, Clientes, Productos, PreciosCliente,
              Cotizaciones, Inventario, Ventas, Proveedores, ProveedorEntradas,
              Notas, Documentos, Correos, Imagenes
includes/     app.php (bootstrap), funciones.php (helpers)
views/        404.php, login.php, registro.php, logout.php, perfil.php,
              admin/<sección>/{index,crear,editar,detalle,eliminar}.php
templates/    layout, sidebar, bottomnav, alertas, modal, paginacion,
              pdfCotizaciones.php, pdfInventario.php
src/scss/     base/ (variables, tipografía, mixins) + layout/ (un archivo por bloque)
src/js/       app.js + modulos/{alertas,modal,validacion,filePreview,cotizador}.js
mysql/        cotizaciones.sql (esquema completo) + migraciones puntuales
public/       imagen/, css/, js/ (salida compilada)
design/       maqueta HTML de referencia
```

**Por qué `templates/` separado de `views/`**: `views/` son *pantallas* (una URL, una
acción). `templates/` son *fragmentos reutilizados por varias pantallas* (el layout, la
paginación, el modal). Mezclarlos produce plantillas de 800 líneas con `if` de todo tipo.

---

## 3. Base de datos: 13 tablas y por qué

| Tabla | Qué guarda | Por qué existe (y qué pasaría sin ella) |
|---|---|---|
| `usuarios` | administradores (bcrypt, `rol`) | Único rol: quien entra es admin. `contarAdmins()` impide dejar el sistema sin salida. |
| `empresas` | los 2 negocios con su ficha y logo | Sin ella, cada negocio requeriría su propia instalación. Alimenta el encabezado del PDF y el correo. |
| `clientes` | ficha de cliente: nombre, empresa, alias, RFC, teléfono, email, ubicación, VIP, descuento | El alias y `nombre_empresa` existen porque **buscar por nombre completo es lento y falla**; el VIP ordena los listados. |
| `productos` | catálogo **por negocio**: `sku`, precio de lista, `stock`, imagen, `activo` | `precio` es de **lista** (fijo). `stock` vive aquí porque es una existencia física, no una transacción. |
| `precios_cliente` | `UNIQUE(cliente_id, producto_id)` → precio acordado | Persiste la promoción. Gracias a `UNIQUE`, un cliente no puede tener dos precios para el mismo producto. |
| `cotizaciones` | encabezado: folio, negocio, cliente, fechas, estado, importes, condiciones | Es el documento. Guarda los **totales calculados** (no se recalculan al leer) para que el PDF y el correo coincidan siempre. |
| `cotizacion_detalles` | renglones: producto, cantidad, `precio_unitario`, `descuento_pct`, `subtotal` | El precio y el descuento quedan **congelados** en el renglón: si mañana cambia el precio de lista, la cotización vieja no cambia. |
| `movimientos_inventario` | bitácora: tipo, cantidad, **saldo resultante**, quién, por qué | Responde "¿por qué tiene N piezas?". El `saldo` guardado permite detectar desajustes sin recorrer todo el historial. |
| `ventas` | entrega: fecha, ubicación, importe, foto, notas, `estado_previo`, `cotizacion_id UNIQUE` | `UNIQUE` = **máximo 1 venta por cotización**. `estado_previo` permite revertir al estado anterior al borrar. |
| `proveedores` | directorio: nombre, RFC, teléfono, ubicación, tipo | Separar **quién es** de **qué llegó** evita duplicar la ficha en cada compra. |
| `categorias_proveedor` | catálogo de categorías de compra: `id TINYINT`, `nombre` único | Migró de una constante en PHP a tabla con **CRUD**: añadir una categoría dejó de ser un cambio de código + deploy. Es **único para todos los proveedores**: lo que se clasifica es el tipo de compra, y ese concepto es de la empresa, no de quien surte. |
| `proveedor_entradas` | entrada con fecha, pedido, **categoría `TINYINT` → `categorias_proveedor`**, **monto**, **pagado**, descripción, **foto**, notas | Es el reporte fotográfico. `ON DELETE CASCADE`: al borrar el proveedor no quedan entradas huérfanas. `monto`/`pagado` alimentan el Balance general y el contador de deuda. `categoria` guarda un **id**, no texto: filtrar compara números y renombrar una categoría es `UPDATE` de una fila. |
| `notas` | bitácora compartida: título, cuerpo, categoría, fijada, completada | `usuario_id` **sólo registra el autor**; la relación es `ON DELETE RESTRICT` para que borrar un autor nunca borre el equipo. |

**Reglas de integridad usadas y por qué:**

- `cotizaciones.empresa_id → empresas` (RESTRICT): no se puede borrar un negocio que
  tenga cotizaciones. Se **desactiva** (`activo = 0`) en su lugar.
- `movimientos_inventario.cotizacion_id → cotizaciones` (SET NULL): si se borra la
  cotización, **el movimiento sigue siendo cierto** (las piezas salieron).
- `cotizacion_detalles.cotizacion_id` (CASCADE): sin encabezado, los renglones no
  significan nada.
- `movimientos_inventario.producto_id` (CASCADE): igual razón.
- `precios_cliente UNIQUE`: unicidad a nivel de base de datos, no en PHP (en PHP se
  "garantiza" con una consulta previa que puede perder una carrera).
- `proveedor_entradas.categoria` **no tiene** clave foránea, y es una decisión, no un
  olvido: la columna admite el `0` ("Sin categoría") y el 0 no es una fila de
  `categorias_proveedor` (el auto_increment arranca en 1), así que la clave foránea
  rechazaría toda entrada sin clasificar. La misma protección se consigue en PHP
  antes de borrar (`CategoriasProveedor::eliminar()` cuenta el uso), a costa de una
  carrera entre dos administradores que sólo puede darse en la misma décima de
  segundo.

---

## 4. Seguridad, rutas y sesión

| Pieza | Qué hace | Por qué |
|---|---|---|
| `soloAdmin()` | sin sesión activa → `redirigir('/login')` | **Primer** indicio de protección. Debe ir en toda ruta `/admin`. |
| `soloRegistroAbierto()` | lee `REGISTRO_ABIERTO` del `.env` | Abre/cierra el registro sin tocar código. |
| `sesionActiva()` / `iniciarSesion()` | arranca `session_start()` y fija los flags | Evita arrancar la sesión en cada archivo por su cuenta. |
| `validarID($id, $ruta = '/admin')` | devuelve el id sólo si es un entero > 0; si no, redirige a `$ruta` | Nada de `intval` disperso: un id inválido no llega a la BD. |
| `paginaNoEncontrada()` | `template('404')` + `http_response_code(404)` | Fallos previsibles → 404, no 500. |
| `escaparValores()` | `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` | **Toda** salida pasa por aquí. |
| Registro abierto | quien se registra queda con `rol = 1` (admin) | Decisión del negocio: no hay clientes ni proveedores con sesión propia. |

**Por qué el 404 antes que el middleware:** el router comprueba primero si la ruta
existe. Si no existe y empieza por `/admin`, ejecuta `soloAdmin()` **antes** de
devolver 404. Así un visitante sin sesión no puede distinguir "ruta protegida" de
"ruta inexistente".

---

## 5. Motor de precios, IVA y descuentos

### `Cotizaciones::calcular()`

```php
Cotizaciones::calcular(
    array $lineas,             // ['cantidad' => 3, 'precio' => 150, 'descuento_pct' => 5]
    float $descuentoGlobalPct = 0.0,
    bool  $aplicaIva = true
): array {
    subtotal, descuento_lineas, descuento_global, descuento,
    base, iva, total, renglones
}
```

**Orden exacto (no se puede alterar):**

```
subtotal bruto          = Σ(cantidad × precio)
 − descuento de renglones (por producto, %)
= base tras descuento
 − descuento GLOBAL %   (se calcula sobre la base YA con renglones descontados)
= base gravable
 × 16 %  IVA            (opcional)
= total
```

**Por qué este orden y no otro:** es el orden que fija la maqueta `design/`. Y el
descuento global **no** puede aplicarse sobre el subtotal bruto: si un producto ya
tiene 30 % de descuento, descontar otra vez el % global sobre su precio original
cobra de más. Redondear con `round(..., 2)` **en cada paso** (no sólo al final) es lo
que hace que el PDF, el correo y el detalle cuadren al céntimo.

### `Cotizaciones::desgloseDescuento()`

Recupera de una cotización **ya guardada** `{renglones, global, base, pct}`.

**Por qué existe:** con sólo el total del encabezado no se puede saber qué parte del
descuento vino de los renglones y cuál del global. Intentarlo arroja un % mayor y, al
volver a guardar sin tocar nada, **los importes cambian solos**. Es la fuente única de
verdad que usan el detalle, el PDF y el formulario de edición.

> **Regla de oro:** todo lo que muestre importes calculados sale de `calcular()`
> (lo nuevo) o de `desgloseDescuento()` (lo guardado). Nunca se reimplementa una
> fórmula en una vista ni en JavaScript.

---

## 6. Cotizador (crear / editar)

**Qué hace:** pantalla única para armar la cotización: elige negocio → cliente →
productos con foto → cantidad y precio por renglón → descuentos → IVA → condiciones →
resumen vivo.

**Por qué un solo formulario para crear y editar:** son la misma pantalla con datos
precargados; duplicarla duplica los bugs de validación y de recálculo.

**Flujo de datos (importante):**

1. PHP inyecta el catálogo (`Productos::paraCotizador()`), el cliente y sus precios
   especiales **dentro de la propia vista**. No hay `fetch` al cargar la página.
2. JavaScript (`src/js/modulos/cotizador.js`) pinta los renglones y recalcula en cada
   `input`.
3. Al enviar, el navegador manda **un array de renglones**; PHP vuelve a llamar a
   `calcular()`. **El total que se guarda es el que calcula PHP**, nunca el que
   calculó el navegador.

**Por qué doble cálculo:** JavaScript se dobla con un `NaN`, una coma decimal o un
cambio de versión; si el guardado dependiera de él, entraría basura a la base de datos.
El JS sólo sirve para dar sensación de inmediatez.

**`Cotizaciones::leerLineasPost()` / `lineasDesdePost()`** normalizan lo que llega del
formulario (cantidades enteras ≥ 1, precios ≥ 0, % acotados 0–100) **antes** de
validar y de calcular. **Por qué separarlo:** el mismo formato lo necesitan crear,
editar y duplicar.

---

## 7. Folio, estados y transiciones

### Folio: `FOL-<año>-<NNNN>`

```php
Cotizaciones::siguienteFolio(): string
```

Se calcula con **el folio más alto del año**, dentro de la misma transacción que
inserta la cotización.

**Por qué `MAX(folio)` y no un contador autoincremental:** si se borra la
cotización FOL-2026-0045, un contador seguiría en 46 (no hay problema) pero un
contador **por año reiniciado con `COUNT(*)`** daría un folio repetido. Y calcularlo
fuera de la transacción, en dos peticiones simultáneas, produce **dos cotizaciones con
el mismo folio**. Dentro de la transacción + `UNIQUE(folio)` = imposible.

### Estados y `cambiarEstado()`

```
pendiente ⇄ vendido
pendiente → cancelado
cancelado → pendiente      (reabrir)
vendido   → pendiente / cancelado  (con reversión de stock)
```

```php
Cotizaciones::cambiarEstado(int $id, string $estado, ?int $usuarioId = null): bool
```

**Qué hace:** dentro de **una sola transacción** — cambia el estado, descuenta o
devuelve el stock (`Inventario::descontar()` / `devolver()`) y registra el movimiento.

**Por qué una transacción:** si se cambia el estado y luego falla el descuento de
stock, queda un "vendido" con existencias intactas (o al revés). Cerrando la
transacción sólo cuando **todo** salió bien, el estado y el almacén son la misma
verdad. `aplicarEstadoEnTransaccion()` es el fragmento compartido por `cambiarEstado()`
y `Ventas::crearDesdeCotizacion()`, para que ambos hagan exactamente lo mismo.

**Reglas de la interfaz (detalle de la cotización):**

- Al pasar a "Vendido" con existencias insuficientes aparece **un modal** que lista los
  faltantes y ofrece **"Permitir sólo con confirmación"**. Nunca bloquea.
- **Por qué no bloquear:** la existencia en pantalla puede estar desactualizada o la
  pieza puede estar en camino; cortar la operación obliga a inventar un flujo paralelo.
  El modal deja constancia de que el usuario lo vio.
- **Por qué nunca `confirm()`:** el estilo queda fuera del sistema, no se puede
  probar, no es accesible y rompe la regla de proyecto.

---

## 8. Inventario y motor de stock

### Archivo: `clases/Inventario.php`

| Método | Qué hace |
|---|---|
| `stock($productoId)` | existencia actual de un producto |
| `faltantes($cotizacionId)` | renglones que no alcanzan para cubrir la cotización, con el déficit de cada uno |
| `cantidadesAgrupadas($detalles)` | suma las cantidades por producto (2 renglones del mismo producto = 1 movimiento) |
| `descontar($detalles, $cotizacionId, $usuarioId)` | **salida** por venta |
| `devolver($detalles, $cotizacionId, $usuarioId)` | **entrada** al revertir una venta |
| `ajustar($productoId, $nuevoStock, $usuarioId, $notas)` | carga/baja manual (escribe el valor absoluto) |
| `listar($filtros, $limite, $offset)` | listado con filtros y orden natural por código |
| `contar($filtros)` | total para la paginación |
| `movimientos($limite)` | última bitácora |
| `paraPdf()` | filas agrupadas por negocio para el PDF de inventario |
| `ordenPorCodigo()` *(privado)* | delega en `Productos::ordenPorCodigo()`: **un solo** `ORDER BY` para inventario y catálogo |

### Las dos únicas fuentes de cambio de stock

```
1. MANUAL      /admin/inventario  → Inventario::ajustar()
               "Fabricé 30 piezas" / "Ajuste por conteo"
2. AUTOMÁTICA  Cotización → Vendido → Inventario::descontar()
               Venta eliminada    → Inventario::devolver()
```

**Por qué sólo dos:** cada camino nuevo es una fuente de discrepancia entre lo que dice
el sistema y lo que hay en el almacén. Las entradas de proveedores **no** mueven
inventario: registrar que llegó material no dice cuántas piezas por producto se
fabricaron con él.

### `descontar()` / `devolver()` NO abren transacción

**Por qué:** tienen que correr **dentro** de la transacción que ya abrió
`Cotizaciones::cambiarEstado()` o `Ventas::crearDesdeCotizacion()`. Si cada una
abriera la suya, el estado podría confirmarse y el stock no (o viceversa). Es el mismo
criterio que `mover()`: el que manda es el llamador.

### Orden natural por código (`Productos::ordenPorCodigo()`)

La expresión vive en **`Productos`** y es `public static`: la consumen el listado
de inventario, el PDF de inventario y el PDF de catálogo.

```sql
ORDER BY e.nombre ASC,                                        -- negocio
         REGEXP_REPLACE(IFNULL(p.sku,''), '[0-9].*$', '') ASC, -- prefijo (RA-, TM-)
         CAST(REGEXP_REPLACE(IFNULL(p.sku,''), '^[^0-9]*', '') AS UNSIGNED) ASC,
         IFNULL(p.sku, '') ASC,
         p.nombre ASC
```

**Por qué no basta `ORDER BY sku`:** el orden alfabético pone `RA-10` **entre** `RA-1`
y `RA-3`, que es justo lo contrario de útil para buscar una pieza. Separando prefijo y
número se logra el orden que espera un humano. Se usa en la pantalla **y** en los PDF
para que todos coincidan.

**Por qué una sola copia:** desde que existe el catálogo exportable hay dos documentos
que ordenan por código. Si cada clase tuviera su propia versión de la expresión,
encontrar un código "funcionaría" de pantalla en pantalla. Requiere los alias
`p` (productos) y `e` (empresas) en la consulta.

### Filtros: el detalle que causó un bug real

```php
$donde[] = '(? = "" OR p.nombre LIKE ? OR IFNULL(p.sku, "") LIKE ? …)';
//                                     ▲ paréntesis OBLIGATORIOS
```

Al unirse con `implode(' AND ', $donde)`, sin paréntesis el `OR` del buscador vence al
`AND` de los demás filtros por precedencia: con la búsqueda vacía, **el filtro de
negocio y el de existencias se ignoraban por completo**.

**Regla:** toda cláusula compuesta de búsqueda se envuelve en paréntesis **en el array**,
antes de combinarse. Aplica a `Inventario`, `Productos`, `Ventas`, `Proveedores`,
`ProveedorEntradas`, `Clientes`, `Usuarios` y `Empresas`.

**Alternativa mejor (para el próximo proyecto):** no meter el "truco" del `? = ""`.
Construir siempre `1 = 1` como base y agregar cada condición sólo si aporta:

```php
$donde = ['1 = 1'];
if ($termino !== '') { $donde[] = '(nombre LIKE ? OR sku LIKE ?)'; … }
if ($empresaId > 0)  { $donde[] = 'empresa_id = ?';               … }
```

Así cada fragmento **nace aislado** y es imposible que un `OR` se escape.

---

## 9. Ventas: la entrega, nacida de la cotización

**Regla de negocio:** no se puede registrar una venta "sola"; **siempre** parte de una
cotización. Así el histórico de cotizaciones y el de ventas son la misma historia.

### `Ventas::crearDesdeCotizacion($datos, $cotizacionId, $usuarioId)`

Dentro de **una transacción**:

1. Comprueba que la cotización exista y que **no tenga ya una venta**
   (`UNIQUE cotizacion_id` como red de seguridad final).
2. Crea la venta con `estado_previo` = estado actual de la cotización.
3. Cambia la cotización a `vendido` **y** descuenta el stock
   (`Cotizaciones::aplicarEstadoEnTransaccion()`).
4. `commit`.

**Por qué `estado_previo`:** al eliminar la venta hay que volver a lo que era antes
(pendiente, vendido por otro motivo…). Guardarlo es más fiable que "asumir pendiente".

### `Ventas::eliminar($id)`

Borra la venta, **devuelve el stock** y **restaura `estado_previo`**. El modal de
confirmación avisa expresamente de que el stock se repone: es una operación que cambia
almacén y no debe tomarse a la ligera.

### Campos de la venta

- `fecha`, `ubicacion` (selector con opción **"Otro"** + texto libre
  `ubicacion_otra`), `importe`, `foto` de entrega, `notas`.
- `ubicacionCompleta()` devuelve "Otro" con el texto cuando aplica: evita que dos
  pantallas tengan que reconstruir la misma cadena.

**Por qué `ubicacion` y `ubicacion_otra` en vez de un solo campo libre:** un selector
da consistencia (para filtrar y contar); el texto libre cubre el caso imprevisto sin
romper el filtro.

---

## 10. Proveedores y entradas (reporte fotográfico)

### Dos tablas, no una

| | `proveedores` | `proveedor_entradas` |
|---|---|---|
| Relación | 1 | N |
| Contiene | nombre, RFC, teléfono, ubicación, tipo | fecha, número de pedido, categoría, **monto**, **pagado**, descripción, **foto**, notas |
| Se borra | `CASCADE` sobre sus entradas | — |

**Por qué dos:** una sola tabla repetiría RFC y teléfono en cada compra, y un cambio
de teléfono obligaría a editar todas las entradas. Es la relación "persona — visitas"
de siempre.

### Se renderiza como **reporte fotográfico**, no como tabla

`views/admin/proveedores/entradas/detalle.php` muestra encabezado con los campos, la
sección **Descripción de lo recibido**, la **Foto de entrega** y las **Notas**.

**Por qué:** el valor del registro es **la foto**. En una tabla, la foto se reduce a un
enlace; el formato "acta" es lo que se puede imprimir o enviar.

### Las entradas NO tocan el inventario

**Por qué:** "llegaron 500 placas de acero" no dice cuántas piezas acabadas hay. Sólo
la carga manual y la venta modifican `productos.stock`; mezclarlos haría que el
inventario dijera cosas que nadie fabricó.

### `tipo_proveedor`

`ENUM('Material','Producto','Servicio','Otro')` + filtro por tipo en el listado.
**Por qué un ENUM:** son categorías de negocio cerradas; un campo libre produciría
"material", "Material", "mat." y filtros que no cuadran.

### `categoria`: entero apuntando a `categorias_proveedor`

```sql
categoria TINYINT UNSIGNED NOT NULL DEFAULT 0
-- 0 = Sin categoría · 1..N = fila de categorias_proveedor
```

El catálogo **ya no es una constante**: vive en la tabla `categorias_proveedor` y se
gestiona desde `/admin/proveedores/categorias` (CRUD completo). La única fuente de
verdad en el código es `CategoriasProveedor::mapa()`, que la leen el formulario, el
filtro del Balance general, la tabla, la ficha de la entrada y los PDF.

**Por qué entero y no texto:** un campo libre convierte *"acero"*, *"Acero"* y
*"mat."* en tres categorías que en realidad son una, y hace que el filtro no cuadre
con lo que se capturó. Con el id, filtrar es `categoria = 1` (comparación entera,
índice) en vez de `LIKE '%acero%'`; y renombrar *"Mano de obra"* por *"Mano de obra
y servicios"* es un `UPDATE` de **una fila**, no de cientos de entradas — el texto
sólo se resuelve al pintar.

**Por qué `NOT NULL DEFAULT 0`:** toda fila tiene un valor legible («Sin
categoría») y ningún filtro ni `SUM()` tiene que lidiar con `NULL`. El 0 es válido
**siempre**, incluso con el catálogo vacío.

**Por qué una tabla y no una constante:**

- añadir una categoría dejó de ser un cambio de código + deploy: es un alta desde
  la pantalla;
- la lista ya no puede desincronizarse entre formulario, filtro, tabla, ficha y
  PDF: todos leen el mismo `mapa()`;
- hay una sola app, pero el catálogo queda listo si algún día hay más de una.

**Por qué UN catálogo para todos los proveedores** (y no uno por proveedor): si
cada proveedor tuviera los suyos, "Acero" existiría repetido con tres significados
y el filtro dejaría de servir para comparar entradas entre sí. Lo que se clasifica
es el **tipo de compra**, concepto de la empresa.

```php
CategoriasProveedor::mapa(): array                // [1 => 'Acero', …] (cacheado por petición)
CategoriasProveedor::nombre(int $id): string      // 1 → "Acero" · 0 o desconocido → "Sin categoría"
CategoriasProveedor::idPorNombre(string $texto): int  // "acero" → 1 (0 si no coincide)
CategoriasProveedor::enUso(int $id): int          // cuántas entradas la usan
ProveedorEntradas::nombreCategoria(int $id)       // delegación: quien guarda el id la pinta
ProveedorEntradas::idPorNombre(string $texto)     // delegación
```

`mapa()` se cachea en un `static`: el Balance, la tabla de entradas y la ficha lo
piden varias veces en la misma pantalla, y repetir la consulta por cada fila sería
un N+1 sin motivo. La caché se anula al crear, actualizar o eliminar.

`idPorNombre()` es lo que permite que la **búsqueda del listado** siga
funcionando: como en la tabla ya no está el texto, `?q=Acero` se convierte en
`e.categoria = 1`. Si el término es numérico se compara como id; si no
corresponde a nada, no se añade condición alguna (nunca se inventan filas).

**Dato heredado:** las entradas que traían texto libre quedaron en `0 = Sin
categoría` — la información no cabe en un entero — y se reasignan con el
desplegable.

Migraciones (ambas idempotentes):
`mysql/migracion_entradas_categoria.sql` sólo convierte si `information_schema`
dice que la columna todavía es `VARCHAR`, de modo que una segunda pasada **jamás**
pone en 0 las categorías ya elegidas; `mysql/migracion_categorias_proveedor.sql`
crea la tabla y siembra los ids **1–6 explícitos** sólo si está vacía, para no
cambiar el significado de ninguna fila escrita.

### El CRUD de categorías (`/admin/proveedores/categorias`)

| Pieza | Detalle |
|---|---|
| Botón de entrada | **Primer** botón de la barra de `/admin/proveedores`: «Crear Categorías» |
| `index.php` | tabla *Categoría · Entradas · Acciones*; el contador de entradas **es también el enlace** a las entradas filtradas por esa categoría |
| `crear.php` / `editar.php` | comparten `templates/formularioCategorias.php` (un solo campo: nombre, `maxlength="80"`) |
| `eliminar.php` | sólo POST; **no** hay cascada: el servidor se niega si la categoría está en uso |
| Rutas | 7 en `index.php`, todas con `soloAdmin` |

Tres decisiones que no son evidentes:

1. **Se puede renombrar pero no borrar en uso.** Renombrar sólo cambia el texto de
   la tabla y las entradas conservan su id; borrar una categoría en uso dejaría
   filas apuntando a un id inexistente que se leerían como «Sin categoría».
   `eliminar()` cuenta las entradas y devuelve el motivo como alerta de error.
2. **El borrador del modal dice si va a doler.** `data-confirmacion-mensaje`
   muestra "la usan N entradas, no se podrá eliminar" o "no está en uso, así que
   ninguna entrada se ve afectada", según el contador: el usuario descubre el
   conflicto **antes** de aceptar, pero el botón sigue visible para que la razón
   se entienda al intentarlo.
3. **El nombre no es opcional ni libre al 100%:** obligatorio, hasta 80 caracteres
   y único (validado en PHP con mensaje propio; la clave única de la base es el
   respaldo, no la experiencia).

---

### `monto` y `pagado`: el dinero de cada pedido

```sql
monto  DECIMAL(12,2) NOT NULL DEFAULT 0.00   -- importe en pesos
pagado TINYINT(1)    NOT NULL DEFAULT 0      -- 1 = liquidado
```

**Por qué `NOT NULL DEFAULT 0`:** `SUM()` no necesita `COALESCE` y las entradas
antiguas cuentan como `$0.00` en lugar de romper la suma. **Por qué `pagado`
arranca en 0:** por defecto todo está *por pagar*; asumir lo contrario haría que el
contador de deuda mintiera hacia abajo, que es el error más caro.

Migración idempotente en `mysql/migracion_entradas_monto.sql` (sólo añade la columna
si `information_schema` dice que no existe).

### Balance general (`/admin/proveedores/detalle`)

Lista las entregas del proveedor con **número de pedido, categoría, monto, imagen de
entrega, pago, acciones (detalle / editar / eliminar)** y encima cuatro indicadores:
Entregas · Total · Pagado · Pendiente por pagar.

```php
ProveedorEntradas::balance($filtros): array
// {entradas, pendientes, total, pagado, pendiente}  — TODO en una consulta
ProveedorEntradas::alternarPagado(int $id, bool $pagado): bool
ProveedorEntradas::sumarMonto($filtros, bool $soloPendientes = false): float
```

**Por qué `balance()` en una sola consulta:** los tres importes se calculan juntos o
no cuadran entre sí (uno leído antes de una escritura, otro después).

**Por qué `alternarPagado()` recibe el estado y no hace *toggle*:** dos pantallas
abiertas a la vez terminarían en contrario si cada clic simplemente invirtiera el
valor.

**Por qué sin `confirm()`:** la acción es reversible con el mismo clic y no borra
nada. **Por qué sí `POST`:** un GET jamás debe cambiar datos. La vista
`views/admin/proveedores/entradas/pago.php` sólo admite `retorno` interno (empieza
por `/`, sin `//`), para que el campo no se convierta en un *open redirect*.

**Por qué la imagen es columna visible y no secundaria:** `.tabla__col-secundaria`
se oculta en móvil, y la foto es justamente el valor del reporte. En su lugar la
tabla usa scroll horizontal, que es el patrón ya aceptado.

#### El estado siempre está a la vista

| Estado | Insignia | Botón |
|---|---|---|
| Sin pagar | `badge--pendiente` **Pendiente** | verde `btn--exito` **Pagado** |
| Pagado | `badge--aprobado` **Pagado** | secundario **Deshacer** |

**Por qué una insignia y no sólo el botón:** el botón dice la *acción*, no el
*estado*. Sin la insignia, una fila pendiente se leía como *"Pagado"* sólo porque
así se llamaba el botón verde. Ahora se puede responder «¿ya se pagó?» de un
vistazo, antes y después del clic.

#### Filtro por categoría

Formulario `GET` con **Buscar** y **Limpiar** (como todo listado) que añade
`?categoria=N` a la URL.

**Por qué el filtro entra en `$filtroProveedor`:** es el mismo arreglo que
alimenta `balance()` y `mostrarTodos()`, así que **la tabla y los cuatro
indicadores se reducen juntos**. Si se filtrara sólo la lista, la suma diría una
cosa y los renglones otra.

El parámetro viaja también en la paginación y en el `retorno` del botón de pago
(`$rutaBalance`), para que al pagar el usuario siga viendo el mismo filtro. Un id
que no esté en `CATEGORIAS` se trata como «sin filtro», y una categoría sin
pedidos muestra un aviso con enlace a **Ver todos**.

### Reporte anual (`Exportar datos`)

`GET /admin/proveedores/pdf` → `ProveedorEntradas::paraPdf($anio)` agrupa
`WHERE YEAR(fecha) = ?` por proveedor y `templates/pdfProveedores.php` pinta una
sección por uno con su tabla `Fecha · Número de pedido · Categoría · Monto · Estado`
y una **tabla consolidada** al final (Entregas · Total · Pagado · Pendiente).

**Por qué un solo archivo:** el objetivo es el balance anual completo; archivos
sueltos por proveedor se pierden y no se pueden comparar de un vistazo.

---

## 11. Catálogos CRUD reutilizables

Todos comparten el mismo esqueleto, lo que permite reescribir uno copiando otro:

```php
class X {
    public static function setDB($db): void;              // inyectada por app.php
    public function __construct(array $args = []);
    public function atributos(): array;                   // ← columna => valor
    public function guardar(): bool;                      // crear() o actualizar()
    public function crear(): bool;
    public function actualizar(): bool;
    public function eliminar(): bool;
    public static function buscarID($id): ?self;
    public static function mostrarTodos(int $limite, int $offset, string $termino): array;
    public static function contarTodos(string $termino): int;
    public static function paraSelector(): array;         // id => etiqueta
    public function validar(): array;
    public static function getErrores(): array;
}
```

**Por qué `atributos()`:** centraliza la lista de columnas. Si se agrega un campo, se
suma **una sola línea** y sirve para crear y para actualizar; el `UPDATE` se arma con
`implode(', ', array_map(fn($k) => "$k = ?", …))`.

**Por qué `getErrores()` "devuelve y limpia":** los errores se acumulan durante la
validación y la vista los lee **una vez**; si no se limpiaran, la siguiente petición
mostraría los del anterior.

**Excepciones reales al esqueleto** (para no buscar lo que no existe):

- `CategoriasProveedor` no tiene `paraSelector()` sino **`mapa()`**, que además de
  alimentar los `select` sirve de caché `id => nombre` para toda la petición; añade
  **`enUso()`**, que es la barrera que impide borrar una categoría en uso, y su
  `eliminar()` no es un simple `DELETE`: primero pregunta, y si hay uso devuelve un
  error explicativo en lugar de un 500 de clave foránea (no hay clave foránea, ver §3).
- `Productos::ordenPorCodigo()` no es CRUD sino una **expresión compartida** de
  orden: la usan el inventario, el catálogo y los PDF.

**Vista asociada (mismo patrón en todos):**

| Archivo | Rol |
|---|---|
| `index.php` | toolbar (buscador + filtros + **Buscar**/**Limpiar** visibles) + tabla + paginación. Recibe GET (lista) y POST (busca/elimina) |
| `crear.php` / `editar.php` | formulario; `editar` carga por `?id=` y hace 404 si no existe |
| `detalle.php` | ficha completa |
| `eliminar.php` | **sólo POST**, con el modal compartido `data-confirmacion` |

**Por qué "Buscar" y "Limpiar" siempre visibles:** esconderlos tras un ícono hace que
el usuario no descubra que hay filtros; y si hay filtros aplicados, el botón de
limpiarlos debe estar a un clic.

**Filtros y paginación siempre van juntos:** los filtros se guardan en `$rutaBase` de
`paginacion()` para que el enlace a la página 2 **los conserve**. Sin eso, pasar de
página reinicia la búsqueda y el usuario cree que se perdió todo.

---

## 12. Precio especial por cliente

```
PreciosCliente::precioDe($clienteId, $productoId): ?float
PreciosCliente::precioAPlicar($clienteId, $productoId, $precioLista): array{precio, especial}
PreciosCliente::guardar($clienteId, $productoId, $precio): bool
PreciosCliente::todosPorCliente($clienteId): array
```

**Reglas:**

| Situación | Resultado |
|---|---|
| Existe precio acordado | se usa **ese**, marcado `especial = true` |
| No existe | se usa el precio de lista |
| En la cotización se escribe un precio **distinto** de la lista y **no** se marca *"No recordar este precio"* | se guarda en `precios_cliente` para ese cliente |
| Se marca *"No recordar"* | el precio vale sólo para esa cotización |
| El precio escrito es **igual** a la lista | **no** se guarda: un precio igual a la lista no es un acuerdo |
| El cliente tiene descuento global en su ficha | se precarga en el `% global` de la cotización, editable en el momento |

**Por qué la casilla "No recordar":** hay promociones puntuales. Sin esa casilla,
cada precio puntual se convertiría en un acuerdo permanente y las próximas cotizaciones
saldrían mal sin que nadie lo note.

**Por qué un importe igual al de lista no se guarda:** si no, cada cotización normal
iría llenando `precios_cliente` de filas inútiles, y al subir el precio de lista el
cliente "congelaría" el precio viejo sin que nadie lo haya pedido.

**Por qué los precios especiales nunca se borran solos:** se pierde la historia de
acuerdos. Si un acuerdo ya no aplica, se borra **explícitamente** desde la ficha del
cliente.

---

## 13. Notas: bitácora compartida

- **Categorías** (`notas.categoria`): `recordatorio`, `cotizar`, `cambios`,
  `revisar`, `otro`.
- Cada nota: título, cuerpo, referencia, fecha de recordatorio, **fijada**,
  **completada**.
- `Notas::alternar($id, $campo)` cambia `fijada`/`completada` desde la tarjeta.
- `Notas::estadisticas()` alimenta el resumen del tablero.
- Borrar una nota va por `POST /admin/notas/accion` con modal de confirmación.

**Por qué una sola bitácora para todos:** es una nota del **equipo**, no personal.
Por eso `usuario_id` sólo **registra quién la escribió** y la relación con usuarios es
`ON DELETE RESTRICT`: despedir a alguien no debe borrar el conocimiento que dejó.

**Por qué categorías fijas y no un campo libre:** los filtros ("chips") tienen que
funcionar; con texto libre, cada usuario escribiría lo suyo y el filtro no encontraría
nada.

---

## 14. Imágenes con Intervention Image

### `Imagenes::subir($archivo, $prefijo, $modo)`

| Modo | Resultado | Uso |
|---|---|---|
| `cuadrada` | recorte centrado a cuadrado | fotos de producto |
| `logo` | se conserva la proporción, ancho máximo, **fondo transparente** | logos de negocio |
| `retrato` | cuadrado + recorte hacia arriba (rostro) | avatares |
| `informe` | proporción original, sólo se reduce | fotos de entrega / reportes |

Las envoltorias `subirRetrato()` y `subirInforme()` añaden la casilla **"Quitar foto"**
y acumulan errores en el array de la vista.

### `Imagenes::url($archivo)` / `urlMiniatura()` / `retrato()`

Devuelven la ruta pública; **si el archivo no existe** caen a
`perfilSinfoto.jpg` / el placeholder.

**Por qué centralizarlo:** si cada vista arma su ruta, un archivo borrado rompe
`<img>` en 15 pantallas. Un solo método devuelve siempre algo que se puede pintar.

**Por qué `validarArchivo()` antes de Intervention:** comprobar `is_uploaded_file`,
tipo MIME real y tamaño **antes** de procesar. Intervention con un archivo corrupto
tira un `Exception` que, sin manejar, se convierte en un 500.

**Por qué el nombre lo genera el servidor** (`prefijo` + aleatorio): un nombre
calculado desde el nombre original del archivo trae espacios, acentos y colisiones.

**Sólo `<input type="file">`** para subir. **Por qué:** el `drag & drop` se implementa
**encima** del input, nunca en su lugar; así si JS falla, la subida sigue funcionando.

---

## 15. Salida: PDF y correo

```
Documentos::html($cotizacion, $detalles)  → string
Documentos::pdf($cotizacion, $detalles)   → ?string   (dompdf)
Correos::enviarCotizacion(...)            → PHPMailer con ESE mismo PDF adjunto
```

- **El PDF y el correo nacen del mismo HTML** (`templates/pdfCotizaciones.php`).
  **Por qué:** dos generadores distintos = dos documentos que no coinciden; el cliente
  reclama por uno y ustedes responden con el otro.
- **`CORREO_MODO=log`** en `.env`: no envía, escribe en `storage/correos.log` y la
  pantalla lo avisa con una alerta de **advertencia**.
  **Por qué:** en desarrollo, un "correo enviado" exitoso que no llegó a ninguna parte
  es la peor mentira que puede mostrar el sistema.
- **404 si falta el registro** al abrir detalle/editar con `?id=` inexistente, en vez
  de un error de BD.
- **`Documentos::descargarInventario()`** produce **un solo PDF con una tabla por
  negocio**: fecha de exportación, logo, nombre, columnas `Código · Producto ·
  Precio de lista · Cantidad · Monto total`, y fila de totales.
  **Por qué no un PDF por negocio:** dos archivos sueltos se pierden; un documento con
  ambas tablas se compara de un vistazo.
- **`Documentos::descargarCatalogo()`** (`GET /admin/productos/pdf`) produce **un solo
  PDF con una tabla por negocio**: `Imagen · Clave · Nombre · Costo` y, **dentro de
  cada sección de empresa**, la leyenda *"Los precios no incluyen iva (16%), las
  ventas son con pago de contado."*
  **Por qué la leyenda va dentro del PDF y no en la pantalla:** el archivo viaja solo;
  al imprimirse o reenviarse, lo que no está en la hoja no existe. **Por qué una por
  empresa y no una global:** cada negocio fija sus propias condiciones, y una única
  nota al final obliga al lector a adivinar a qué empresa aplica. **Por qué no
  respeta los filtros:** el botón vive en la barra de acciones (fuera del formulario de
  búsqueda) y un catálogo es la oferta completa del negocio, incluidos agotados y dados
  de baja.
- **El catálogo no suma los costos.** Se quitó la fila de totales: es una lista de
  precios para consultar en el campo, no un inventario valorizado (una suma de costos
  invita a confundirla con lo que hay en almacén). A cambio, los renglones se
  **compactaron** (miniatura de 40 → 30 px, relleno de `0.3` → `0.12 rem`,
  interlineado `1.3` → `1.2`): ~26 px por fila en vez de ~51, es decir, casi el doble
  de productos por página.
- **El logo de la cotización usa un tope, no un tamaño fijo:** el **mismo número**
  en pantalla (`max-height` en `src/scss/layout/_cotizador.scss`, hoy `10rem` =
  160 px, con `width: auto; height: auto; max-width: 100%`) y en el documento
  (`max-height: 160px; max-width: 700px` en `templates/pdfCotizaciones.php`).
  **Por qué `auto`:** con un ancho fijo la caja quedaba más ancha que alta y el logo
  no crecía, sólo se recortaba con `object-fit`; así, la proporción natural del
  archivo decide el dibujo y una marca apaisada se encoge sin deformarse.
  **Por qué `max-width: 100%`:** sin él un archivo ancho desborda el contenedor, el
  viewport se ensancha y la barra inferior mide más que la pantalla.
  **Por qué el PDF va en px y no en `rem`:** dompdf resuelve el rem contra el tamaño
  de fuente raíz del motor y el resultado no coincide con el del navegador; `160px`
  es el equivalente exacto de `10rem` con `1rem = 16px`, y así la marca es idéntica
  en las dos salidas.
  **El valor lo fija el usuario y la prueba lo lee de ambas fuentes:** empezó en
  `20rem`/`320px` y hoy está en `10rem`/`160px`, decisión suya y **no se
  sobrescribe**. El test comprueba tres cosas sin tener el número escrito: que lo
  compilado coincida con el `.scss` (ahí se detecta un build viejo), que el PDF
  tenga **el mismo tope** que la pantalla, y que el dibujo sea
  `min(tamaño natural, tope)`.
  **Nota:** los logos se guardan en **300×300 px**, así que el archivo manda cuando
  es más chico que el tope; con 160 px el logo se imprime a **120 pt** (160 × 0.75,
  la conversión px → pt de dompdf).
- **Las tres plantillas de PDF** (`pdfCotizaciones`, `pdfInventario`, `pdfCatalogo`,
  `pdfProveedores`) usan **imágenes en base64** y CSS plano de tablas.
  **Por qué:** `dompdf` corre con `isRemoteEnabled = false`; una `<img src="http://…">`
  se dibuja en blanco y nadie se entera hasta que el cliente lo imprime.

---

## 16. Panel de ganancias

Ubicación: `/admin`, justo debajo de "Accesos rápidos", **separado por negocio**, con
filtros de mes / semestre / año.

```php
Cotizaciones::estadisticas(): array            // totales del histórico
Cotizaciones::anios(): array                   // años con cotizaciones (para el select)
Cotizaciones::rangoPeriodo($anio, $semestre, $mes): array{desde, hasta}
Cotizaciones::ganancias($empresaId, $desde, $hasta): array
```

**Por qué `rangoPeriodo()` en el backend:** el mismo criterio de "¿a qué periodo
pertenece esta fecha?" debe aplicarse al filtrar y al mostrar. Si se calcula en el
navegador y en el servidor por separado, los números no cuadran.

**Por qué por negocio:** mezclar los dos negocios da un número que no representa a
ninguno y no sirve para tomar decisiones.

---

## 17. Funciones de ayuda del backend

| Función | Qué hace | Por qué |
|---|---|---|
| `template($nombre, $datos)` | `extract()` + `include` de `templates/` | Un solo sitio para cargar fragmentos; sin `extract()` hay que pasar variables a mano y se olvidan. |
| `paginaNoEncontrada()` | 404 + plantilla | Error previsible → respuesta correcta. |
| `escaparValores($v)` | escape HTML de salida | Único escape necesario en la salida. |
| `validarID($id, $ruta)` | id válido o redirección/404 | Evita `intval()` suelto y SQL con basura. |
| `soloAdmin()` | middleware de sesión | Protección declarada en la **ruta**, no dentro de cada vista. |
| `agregarAlerta($tipo, $texto)` + `obtenerAlertas()` | alertas por **sesión** | Sobreviven a un `redirigir()` (que corta la petición). Un `echo` delante de un `header()` produce "headers already sent". |
| `redirigir($ruta, $tipo, $texto)` | alerta + `Location` + `exit` | Flujo estándar POST → guardar → volver a la lista. |
| `paginacion($porPagina, $total)` | lee `$_GET['pagina']` y devuelve `{limite, offset, paginaActual, totalPaginas}` | El offset se calcula en **un** sitio; con `$rutaBase` la paginación conserva los filtros. |
| `formatoMoneda($m)` | `$ 1,234.56` | Formato consistente en tablas, detalle y PDF. |
| `etiquetaEstado($e)` | `['texto' => 'Pendiente', 'clase' => 'pendiente']` | Estado y clase CSS juntos: añadir un estado es tocar **una** línea. |
| `numeroALetras($monto)` | "MIL DOSCIENTOS PESOS 00/100 M.N." | Requisito de los documentos en México; imposible de improvisar en la vista. |
| `tablaExiste()` / `contarRegistros()` | comprobaciones defensivas | Migraciones aplicadas a medias no tumban el panel. |

---

## 18. JavaScript modular

```
src/js/app.js          → cargador: monta cada módulo si encuentra su selector
src/js/modulos/
  alertas.js           → cierre automático de las alertas de sesión
  modal.js             → #modal-principal reutilizable (abrir, confirmar, cerrar)
  validacion.js        → data-validar en formularios (mensajes en el DOM)
  filePreview.js       → vista previa de <input type="file">
  cotizador.js         → el formulario de cotización (el más grande)
```

**Reglas del proyecto (y por qué):**

| Regla | Motivo |
|---|---|
| `let`/`const`, nunca `var` | `var` se eleva al ámbito de la función y produce fugas difíciles de ver |
| **Nunca** `alert()`, `confirm()`, `prompt()` | estilo fuera del sistema, no estandarizado, bloquea el hilo, no accesible, **imposible de automatizar** |
| **Nunca** `innerHTML` | sólo `document.createElement()` + `appendChild`; con `innerHTML` cualquier dato de la BD puede inyectar `<script>` |
| `preventDefault()` en `submit`/`click` interceptados | si se olvida, la página recarga y se pierde todo el trabajo en curso |
| Todo lo nuevo se compila con `npx gulp build` | `views/` carga `build/js/app.js`, no `src/js/` |

**`modal.js` — contrato de selectores:**

```js
abrir({ titulo, cuerpo, confirmar, alConfirmar })
// #modal-principal       contenedor
// #modal-cuerpo          zona de contenido (textContent, no innerHTML)
// #modal-pie [data-cerrar-modal]
// #modal-pie [data-confirmar-modal]
```

**Por qué un solo modal para todo:** si cada pantalla inventa su ventana de
confirmación, hay 5 comportamientos distintos que corregir y ninguna prueba posible.

---

## 19. Componentes de interfaz compartidos

| Componente | Archivo | Notas |
|---|---|---|
| Layout | `templates/layout.php` | `sidebar` en escritorio, `bottomnav` en móvil |
| Sidebar | `templates/sidebar.php` | marca el ítem activo **a partir de la URL**, lleva `<hr class="sidebar__divisor">` antes de Ventas/Inventario/Proveedores |
| Barra inferior | `templates/bottomnav.php` | **sólo iconos**, 6 ítems, siempre visible en móvil |
| Cajón (drawer) | — | muestra el **nombre** de la sección junto a su icono |
| Alertas | `templates/alertas.php` | un solo estilo; `alerta--advertencia` para avisos |
| Modal | `templates/modal.php` | idéntico en todas las secciones |
| Paginación | `templates/paginacion.php` | conserva la query string en `$rutaBase` |
| Badges | — | `aprobado`, `error`, `info`, `neutro`, `pendiente`, `sin-punto`, `vip` |
| Botones | `src/scss/layout/_botones.scss` | `primario`, `secundario`, `peligro`, **`exito`** (verde de "Pagado"), `texto`, `sm`, `icono`, `bloque` |
| Tablas | `.tabla` | **todas las cabeceras centradas** (incluidas las numéricas); celdas numéricas `.cifra` alineadas a la derecha |
| Contadores | `.tabla-toolbar__resumen` | agrupa los badges a la derecha de la barra de filtros |
| Celda de pago | `.tabla__pago` | **insignia de estado** (`Pendiente`/`Pagado`) + botón apilados y centrados (Balance general) |
| Filtro de categoría | `.tabla-toolbar` + `.buscador` | formulario `GET` con **Buscar** y **Limpiar**, igual que cualquier listado; vive dentro de la sección que filtra |
| Buscador de categorías | `.tabla-toolbar` + `.buscador` | mismo componente que cualquier listado: **Buscar** y **Limpiar** visibles, badge de conteo a la derecha |
| Formulario de categorías | `templates/formularioCategorias.php` | un solo campo `.campo` con etiqueta obligatoria, `.campo__error` y una nota `.texto-xs` que recuerda que el nombre se ofrece **a todos** los proveedores |
| Botones de la cabecera | `.encabezado-pagina__acciones` | el orden es el significado: «Crear Categorías» va **primero** porque es la entrada al catálogo que alimenta el resto de la sección |
| Logo de la cotización | `.cotizador-detalle__logo` | `max-height` **definido por el usuario en el `.scss`** (hoy `10rem`) con medidas `auto` y `max-width: 100%`; el PDF repite el mismo tope en px (`templates/pdfCotizaciones.php`, ver §15 y §20) |

**Por qué las cabeceras centradas y las cifras a la derecha:** la cabecera nombra la
columna; el número se compara por el último dígito. Son dos necesidades distintas y por
eso se separan en CSS.

**Movil:** el menú va **abajo** porque con el pulgar el pulgar alcanza el borde
inferior, no el superior izquierdo; el cajón conserva los ítems con texto para descubrir
qué hay detrás de cada icono. Las tablas que no caben usan **scroll horizontal**
contenedor, nunca `overflow` en `body` (que rompe el `position: fixed` de la barra).

**Contraste: por qué hay dos azules además del relleno.** El mismo tono no sirve
igual como fondo (donde el texto es blanco) que como texto (donde el fondo es claro);
con `#5a92c3` y texto blanco el contraste era **3.32:1** y los botones *Guardar* y el
ítem activo del menú no cumplían. La paleta quedó en tres piezas con responsabilidad
distinta — `#4679a6` de relleno (4.6:1 con blanco), `#38648c` de hover (6.2:1) y
`#3d6d99` como **texto** sobre blanco o azul claro (5.5:1) —, y el gris secundario
pasó de `#8c8c90` (3.4:1) a `#64646a` (5.9:1). El `#b46424` del badge VIP tampoco
llegaba a 4.5:1 sobre `#faede3`, así que el acento se queda como acento y el texto
usa `#9a5220`. Las cuatro plantillas de PDF repetían el mismo gris y se ajustaron a
la par, porque dompdf no lee `app.css`.

**Estructura de la página, por qué se tocó junto al color:**
- El `<h1>` lo pinta `templates/header.php`; las vistas `detalle` pintaban **el suyo
  encima**, así que salía el nombre del cliente dos veces seguidas. La solución es
  `'mostrarEncabezado' => false`: la ficha es dueña de su encabezado y la página
  conserva exactamente un `<h1>`. Las vistas `editar` hicieron lo contrario — se
  quedaron **sólo con la descripción**, como ya hacían las `crear`.
- Ese mismo solape hacía que el `<title>` de una cotización dijera
  «Cotizaciones · Cotizaciones»: el título de la pestaña sale del mismo `$titulo`.
- **`Limpiar` no se exige sin filtro activo**: limpiar algo que no existe deja un
  botón muerto. La auditoría lo pide sólo en el listado con `?q=…`, que es donde
  tiene sentido.

---

## 20. Verificación automatizada

Scripts en Node + Playwright, **dentro del repositorio en `pruebas/`** y
expuestos como scripts de npm:

```bash
php -S 127.0.0.1:8017 index.php &        # las suites necesitan el servidor

npm run pruebas      # 3 suites de regresión  → 169 comprobaciones
npm run auditorias   # 3 auditorías de interfaz → 0 hallazgos

node pruebas/esquema.mjs /admin/cotizaciones/detalle?id=32   # árbol de encabezados
```

`playwright@1.56.1` es *devDependency* del proyecto; el navegador vive en la
caché común de Playwright, no en `node_modules`. Detalle de cada comprobación
en [`documentacion.md` §15](documentacion.md).

**Las suites se mudaron aquí desde `/tmp/opencode/navegador/`, que se vacía con
cada reinicio.** **Por qué:** lo que se describe en un documento no puede vivir
en una carpeta temporal — el día que alguien siga estas instrucciones, los
scripts tendrían que reescribirse. Además, cada una **se crea su propio
usuario** (`prueba.ronda@`, `prueba.ronda14@`, `prueba.categorias@`) y lo borra
el último, en `finally`: nada de eso se toca nunca más.

### Suites de regresión

| Script | Comprobaciones | Qué comprueba y por qué |
|---|---|---|
| `probar_categorias.mjs` | **56** | **CRUD de categorías**: botón «Crear Categorías» primero en la cabecera, listado alfabético con contador de uso, alta que aparece en la lista **y en el select de entradas de todos los proveedores**, nombre repetido rechazado, HTML en el nombre escapado, renombrar sin cambiar el id, borrado **bloqueado con aviso** si está en uso y permitido si no, búsqueda con Limpiar, estado vacío, `eliminar` que no acepta GET, sección "Proveedores" activa y desbordamiento a 1440/390 px. Crea su usuario, proveedor y entrada y los borra en ese orden · El CRUD nuevo toca una lista que alimenta muchas pantallas: hay que verificar que crear, renombrar y borrar **no rompan** el filtro ni el formulario de entradas |
| `probar_ronda2.mjs` | **67** | Catálogo sin suma y con **una leyenda por empresa**, separación entre renglones < 30 pt, logo con el tope **leído de la fuente** y CSS compilado al día, **mismo tope pantalla↔PDF** y ~120 pt impresos, la cotización propia en 1 página, insignia *Pendiente/Pagado*, filtro por categoría (tabla + indicadores + select + estado vacío), búsqueda por nombre de categoría, columna `TINYINT`, select con todas las categorías del catálogo, alta inválida rechazada, regresión de los demás PDF y desbordamiento a 1440/390 px · Una sola prueba cubre todo lo de la ronda pasada sin depender de datos del cliente |
| `probar_ronda.mjs` | **46** | Botones de exportación y su posición, logo del detalle y del PDF **contra el tope leído de la fuente y de la plantilla**, Balance general, contador de pendientes, campo Monto, los dos PDF y desbordamiento · Es la referencia de la ronda previa. Dejó de usar `admin@cotizaciones.local` (que ya no existe) y de fijar números de medida escritos a mano: ahora los lee del código |

> **Lo mismo vale para los datos que el usuario administra.** Las suites nacieron
> con «6 categorías» y los ids `1` y `2` escritos a mano; al rehacerse el
> catálogo (10 categorías, ids 18–27) la de ronda2 rechazó las altas y la de
> categorías quedó a un paso de **borrar la «Acero» del usuario**, porque
> esperaba que el servidor se negara a un id que ya no estaba en uso. Ahora los
> catálogos, recuentos y órdenes salen de `SELECT … ORDER BY nombre`, la
> categoría que se intenta borrar es una que la propia prueba pone en uso antes,
> y la preparación limpia también los restos de corridas interrumpidas
> (cotizaciones y proveedores de la cuenta de prueba, que las FK `RESTRICT`
> bloqueaban). **Nada de lo que el usuario puede crear, renombrar o borrar se
> fija a mano**; lo único fijo es la semilla que trae la propia prueba.

### Auditorías de interfaz

| Script | Pantallas | Qué mide |
|---|---|---|
| `auditar_ui.mjs` | 36 × (1440 y 390 px) + listados con `?q=` | `lang`, viewport, un solo `<h1>`, `<title>` no duplicado, contraste `color`/`background-color`, iconos decorativos, **etiqueta de todos los campos**, `th scope`, `<caption>`, **Buscar siempre y Limpiar cuando hay filtro**, estado vacío, `aria-label` en botones sin texto, desbordamiento y objetivos táctiles |
| `auditar_profundo.mjs` | 26 | Contraste **recorriendo la cadena de fondos** (no sólo el color plano), foco visible con Tab, `aria-current`, enlace de salto, `prefers-reduced-motion` del CSS compilado y táctiles a 390 px |
| `auditar_consola.mjs` | 26 | Errores y avisos de consola, `pageerror`, peticiones caídas (sin contar las abortadas por navegar) y respuestas ≥ 400 |
| `esquema.mjs` | a demanda | Árbol de encabezados, `<title>`, regiones, `<caption>` y etiquetas rotas de una URL concreta |

Resultado actual: **0 hallazgos** en las tres auditorías.

> **Suites de rondas anteriores, descritas aquí pero ya no en disco:**
> `probar_stock.mjs` (inventario, orden por código, ventas, PDF de inventario),
> `probar_filtros.mjs` (filtros y paginación de todos los listados),
> `probar_panel.mjs` (accesos rápidos, duplicar, ganancias),
> `probar_cajon.mjs` (cajón móvil y desbordamiento a 390 px) y
> `viewport.mjs` (`scrollWidth === innerWidth` en todas las pantallas). Si hace
> falta alguna, se reescribe desde la tabla — pero **nunca** se le quita la
> limpieza en `finally`, que es la parte que evita dejar datos de prueba en la
> base.

**Patrón que hay que conservar en el próximo proyecto:**

1. **Esperar resultados calculados desde la base de datos en el momento**
   (`SELECT COUNT(*) …` justo antes de la petición), nunca números fijos: con datos
   reales en la tabla, un valor fijo se vuelve mentira.
2. **Comprobar la integración por la `Location` de la respuesta**, no por `MAX(id)`:
   si alguien crea un registro a la vez, `MAX(id)` puede señalar el suyo y **la prueba
   lo borraría**.
3. **Todo dentro de `try/finally` con `restaurar()`**: la base debe quedar exactamente
   como estaba, aunque la prueba falle a mitad.
4. **Medir, no mirar:** bounding boxes y `getComputedStyle` en vez de capturas
   (`read` de imágenes devuelve caché).
5. **La cuenta de prueba se crea y se borra.** La contraseña del administrador real
   no es un dato del proyecto y **no se resetea**: en vez de adivinarla, la suite da
   de alta un usuario `rol = 1` con `password_hash()` y lo borra **al final**, después
   de las entradas y las cotizaciones, porque `usuario_id` es `RESTRICT` y no deja
   huérfanos. Ninguna prueba debe tocar credenciales del cliente.
6. **Los índices de `pdfimages -list` se leen de la cabecera.** poppler cambia el
   número de columnas entre versiones (añade `object ID`); contar posiciones a mano
   devolvió `1661 pt` en vez de `225 pt`.
7. **Un valor de diseño lo fija el usuario, no la prueba: léelo de la fuente.** El
   `max-height` del logo estaba escrito en el test como `320px`; el usuario lo bajó
   a `10rem` y la prueba empezó a acusar un fallo que no existía. Ahora se lee del
   `.scss`, se comprueba que el CSS compilado coincida (eso sí detecta un build
   viejo) y se valida el efecto, no el número.
8. **Mide tu propio contenido, no el del cliente.** La prueba reutilizaba la primera
   cotización del histórico: con la de tres renglones del usuario, "el PDF cabe en 1
   página" dejó de significar nada. Ahora siempre crea la suya (un renglón) y la
   borra; el resultado depende de la prueba, no de quién haya entrado ayer.
9. **`waitForURL` no sirve si la URL ya es la destino.** El `POST` de borrado
   redirige al mismo listado desde el que se dispara, así que esperar por URL
   resuelve de inmediato antes de que llegue la respuesta: hace falta una espera
   real (o releer la página) antes de comparar.
10. **Los umbrales los fija la norma, no la costumbre.** Añadir una auditoría
    con las cifras de WCAG escritas en el propio script —4.5:1 para texto,
    3:1 para gráficos, 24×24 px para táctiles— convierte «se ve bien» en un
    número que se puede romper y arreglar. El resultado de la revisión fue
    **888 fallos de contraste** que ninguna mirada había visto; las
    excepciones de la norma (controles nativos con etiqueta, enlaces en línea,
    texto con `aria-hidden`) hay que escribirlas también, o la auditoría
    llena la salida de ruido y se deja de leer.

---

## 21. Checklist: proyecto desde cero

**Fase 0 — Esqueleto**
1. `composer init` con `App\` → `clases/`; `composer require intervention/image dompdf/dompdf phpmailer/phpmailer`.
2. `Router.php` con `get/post/comprobarRutas` + 404 + middleware.
3. `includes/app.php` con `__DIR__` y la lista `$clasesConectadas`.
4. `.env` + `.env.example` + `config/database.php`.
5. `views/login`, `logout`, `404` y `soloAdmin()`.
6. `gulp` con `src/scss` → `build/css` y `src/js` → `build/js`.

**Fase 1 — Catálogos**
7. `empresas` primero (todo lo demás cuelga de ella).
8. `usuarios` con `setDB()`, `atributos()`, `validar()`, `getErrores()`.
9. `clientes` y `productos`.
10. Plantillas base: `layout`, `sidebar`, `alertas`, `modal`, `paginacion`.

**Fase 2 — Cotizador**
11. Tablas `cotizaciones` y `cotizacion_detalles`.
12. `Cotizaciones::calcular()` **antes** que cualquier pantalla que muestre totales.
13. `siguienteFolio()` dentro de la transacción de inserción.
14. Formulario crear/editar con el catálogo inyectado en la vista y `cotizador.js`.
15. `desgloseDescuento()` para leer lo guardado.

**Fase 3 — Salidas**
16. `templates/pdfCotizaciones.php` → `Documentos::pdf()`.
17. `Correos::enviarCotizacion()` con `CORREO_MODO=log` **por defecto**.
18. Histórico con filtros + `paginacion()` preservando la query.
19. `Productos::paraPdf()` + `templates/pdfCatalogo.php` (catálogo: una tabla **y una
    leyenda** por empresa, sin suma de costos).

**Fase 4 — Operación**
20. `productos.stock` + `movimientos_inventario` + `Inventario`.
21. Transición a `vendido` **en transacción** con descuento de stock.
22. `ventas` (`UNIQUE cotizacion_id`, `estado_previo`) y su reversión.
23. `proveedores` + `proveedor_entradas` como reporte fotográfico.
24. `monto` + `pagado` en las entradas **antes** del Balance general.
25. `ProveedorEntradas::balance()` / `alternarPagado()` y su vista POST.
26. `categoria` como `TINYINT` + tabla `categorias_proveedor` sembrada con los ids
    1–6 **explícitos** + `mysql/migracion_entradas_categoria.sql` **antes** de
    cualquier filtro (todo lo demás lee del catálogo).
27. **CRUD de categorías** (`clases/CategoriasProveedor.php` + 7 rutas +
    `/admin/proveedores/categorias`): `mapa()` con caché, `enUso()` como barrera de
    borrado y el botón «Crear Categorías» **primero** en la cabecera de Proveedores.
    Se hace **después** del `TINYINT` y **antes** del filtro, porque el filtro es el
    que necesita que el catálogo ya se pueda tocar sin recompilar.
28. Filtro por categoría del Balance general: **el mismo mapa** que alimenta la
    tabla y `balance()`, para que los indicadores y los renglones concuerden.
29. `ProveedorEntradas::paraPdf($anio)` + `templates/pdfProveedores.php`.
30. Panel de ganancias.

**Fase 5 — Cierre**
31. `npx gulp build` + `php -l` en todo el árbol.
32. Suite Playwright con limpieza en `finally` **y cuenta de prueba propia**.
33. Revisar móvil a 390 px **en cada pantalla nueva**.

**Orden que no se debe alterar:** la base de datos antes que las pantallas, y
`calcular()` antes que cualquier total visible. Si se pinta un total que luego se
corrige, la primera impresión del usuario queda mal y el cliente desconfía del resto.

---

## 22. Trampas conocidas (léelas antes de empezar)

**PHP / vistas**
- `template('404')` **no** existe: la 404 vive en `views/404.php` y usa `paginaNoEncontrada()`.
- Dentro de `namespace App` toda función global necesita `\` (`\date()`, `\round()`, `\max()`…).
- `bind_param` exige **tipos y argumentos exactos**: un desajuste es un 500 sin mensaje.
- `header("Location: …")` antes de cualquier `echo` → *headers already sent*. Por eso
  las alertas viajan por **sesión**.
- `extract()` en `template()` inyecta variables: **no** meter `$datos` con claves que
  puedan pisar variables de la plantilla.
- Formularios **anidados**: el navegador descarta el interno. Se resuelve con
  `formaction`/`formmethod` en botones sueltos.
- Los deletes son **POST**: un `<a>` de borrado se dispara con el prefetch.
- **Toda acción que cambia datos es POST**, incluida la que sólo cambia un flag
  (`pagado`): un GET jamás debe escribir.
- Un campo `retorno` que viene del POST **es un open redirect**: sólo se acepta si
  empieza por `/` y no por `//`; cualquier otra cosa cae a una ruta fija.
- `SUM()` sobre una columna `DECIMAL` **nullable** devuelve `NULL` y contamina la suma
  entera: usa `NOT NULL DEFAULT 0` o `COALESCE`, nunca la esperanza.
- **La ligadura de Material Symbols cae en `textContent`**: `button.textContent` es
  `"check_circle Pagado"`, no `"Pagado"`. Antes de comparar una etiqueta, clona el
  nodo y quita los `.material-symbols-outlined`.
- **Un `select` con lista fija valida contra los `keys` del arreglo**, no contra el
  tipo: `TINYINT` acepta el `99` que mande el navegador; hay que rechazarlo en
  `validar()` antes de que llegue a la base.
- **`buscarPorNombre()` que devuelve `0` "no encontrado" no es `null`.** La
  condición `if ($existe !== null && $existe !== $this->id)` parecía inocua y
  hacía que **renombrar sin cambiar el nombre** devolviera "ya existe una
  categoría": `0 !== 3` es `true`. Comprueba `$existe > 0` (o devuelve `null`
  de verdad) y no dejes que un "no encontrado" se confunda con un id.

**SQL**
- Un `? = ""` como truco de "sin filtro" **debe ir entre paréntesis** si después se
  añaden condiciones con `AND` (ver §8).
- **Convertir texto a entero destruye lo que no sea número.** Si el campo es `VARCHAR`
  y tiene valores libres, la conversión deja `0`: hazlo así y **avísalo**, pero no
  adivines qué quería decir "Fronteras" — un dato inventado es peor que uno vacío.
- **Una migración que cambia el tipo debe comprobar el tipo antes** (`information_schema
  + `LIKE 'varchar%'`): si se ejecuta dos veces, la segunda **pone en 0** las categorías
  que el usuario ya había elegido.
- `MAX(folio)` sólo dentro de la transacción que inserta.
- `UNIQUE` para las reglas de negocio que no admiten excepción (`folio`,
  `cotizacion_id` en `ventas`, `(cliente_id, producto_id)` en `precios_cliente`).
- `usuario_id` es **`RESTRICT`**: un usuario de prueba no se puede borrar hasta que no
  se borren sus entradas y cotizaciones. El orden de la limpieza importa.
- **El `0` de "sin categoría" no puede ser clave foránea.** Si `categoria` admite `0`
  y el `auto_increment` empieza en 1, la FK rechaza toda entrada sin clasificar; o
  dejas la columna `NULL`, o pones la barrera en PHP antes de borrar. Aquí se eligió
  PHP (búsqueda de uso + mensaje propio) para no reescribir toda la lógica de `0`.
- Una columna con `DEFAULT 0` que además **es clave foránea** y una tabla que arranca
  en 1 son incompatibles: decídete antes de crear la tabla, no al insertar.
- Nunca `DELETE` sin `WHERE` confirmado: en este proyecto **sólo se borra por id
  explícito**, porque la base tiene datos reales del usuario.

**CSS / SCSS**
- Variables que **no** existen: `$espacio-xxs`, `$color-texto-terciario`,
  `$sombra-sutil`, `$fuente-monoespaciada`, `$fuente-md`.
- `.campo` necesita además `.campo--oculto`; el atributo `hidden` solo no basta.
- Una regla añadida al final del fichero **debe** ir al final: el orden en la fuente
  manda en la cascada.
- `th` centrado y `td.cifra` a la derecha son **clases distintas**.
- Un `<img>` con **ancho fijo y sin `max-width: 100%`** desborda el viewport en móvil:
  el `body` se ensancha y la barra inferior mide más que la pantalla.
- **Un `max-height` no agranda nada si el ancho va fijo**: con `width: 28.8rem` el
  `max-height` sólo recortaba la caja y el logo seguía midiendo lo mismo. Para que un
  tope mande de verdad hace falta `width: auto; height: auto`.
- Un `max-height` es un **techo**, no un tamaño: si el archivo de origen mide 300 px y
  el tope es 160 px, se dibuja a **160 px**; con un archivo de 100 px, a 100 px. Dilo
  en la documentación para que nadie crea que el CSS falló.
- Dos badges dentro de un `justify-content: space-between` **se van a los dos extremos**
  y dejan un hueco enorme: envolverlos en su propio contenedor flex.
- Una columna que **no** debe ocultarse en móvil (la foto, el monto) **no** puede llevar
  `.tabla__col-secundaria`; si hace la tabla muy ancha, resuelve con scroll horizontal.

**JavaScript**
- Sin `alert`/`confirm`/`prompt`, sin `innerHTML`, sin `var`.
- Siempre `preventDefault()` al interceptar `submit` o `click`.
- Recompilar con `npx gulp build`; `views/` carga `build/js/app.js`.
- **`getComputedStyle(img).width` devuelve el valor usado (px), nunca `auto`.** Para
  comprobar que un elemento usa medidas automáticas no se lea el estilo: compara el
  dibujo contra el tamaño **natural** del archivo (`naturalHeight`, que vale 0 hasta
  que carga) y el tope. Y si la URL de destino es la misma de la que partes,
  `waitForURL` resuelve al instante: espera por otra cosa.

**Entorno**
- Servidor: `php -S 127.0.0.1:8017 index.php` desde la raíz del proyecto (se cae de
  vez en cuando: comprobar con `curl …/login` antes de probar).
- **Login de pruebas: la cuenta `admin@cotizaciones.local` YA NO EXISTE** en la base
  (quedan sólo las cuentas reales del cliente, que no se tocan). Si un script falla en
  el `waitForURL`, no es la contraseña: **haz que la prueba cree su propio usuario**
  con `password_hash()` y bórrelo al final (POST `email` + `contrasena`, sin CSRF).
- MySQL local `mysql -u root`, PHP 8.3 con GD (sin Imagick).
- **No** volver a ejecutar `mysql/datos_prueba.sql` ni `scripts/imagenes_prueba.php`:
  la base contiene trabajo real.
