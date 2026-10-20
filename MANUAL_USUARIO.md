# Manual de usuario — Acero 360

> También está dentro de la plataforma: menú **Ayuda**. Generado desde `includes/manual.php`.

## Contenido

1. Empezar
2. Clientes y productos
3. Cotizaciones
4. Ventas y entregas
5. Inventario
6. Compras y proveedores
7. Anotar un gasto y subir su factura
8. Ventas y cobros en Contabilidad
9. Resumen del mes y cierre
10. Impuestos (solo informativo)
11. Estadísticas
12. Aplicación de escritorio
13. Respaldos y cuidados
14. Centro de seguimiento
15. Acerca de Acero 360
16. Palabras que verás

---

## 1. Empezar

Acero 360 sirve para cotizar, vender, entregar, llevar tus gastos y saber más o menos cuánto vas a pagar de impuestos. Todo se hace desde el menú de la izquierda (en el celular, desde el botón de abajo a la derecha).

### Entrar

1. Abre la página e inicia sesión con tu correo y contraseña.
2. Si olvidaste la contraseña, cámbiala en «Usuarios y perfil».

### Moverte por el menú

1. «Nueva cotización», «Histórico», «Clientes» y «Productos» son lo de todos los días.
2. Debajo de la línea están Ventas, Pantalla de entrega, Contabilidad, Impuestos, Inventario y Compras y proveedores.

### Burbujas de ayuda

1. Deja el cursor sobre cualquier botón, pestaña o campo y, medio segundo después, aparece una burbuja que explica qué hace.
2. Con teclado funciona igual al llegar con la tecla Tab. Escape la cierra.

> **Consejos**
> - Si algo no se ve actualizado, recarga la página con Ctrl + Shift + R.

---

## 2. Clientes y productos

Antes de cotizar necesitas a quién y qué ofrecer.

### Dar de alta un cliente

1. Clientes → «Nuevo cliente». Escribe su nombre y, si quieres, correo, teléfono y RFC.
2. Guarda. Ya aparece al cotizar.

### Dar de alta un producto

1. Productos → «Nuevo producto». Pon nombre, precio de lista, unidad y una foto.
2. Las existencias (cuántas piezas tienes) se manejan en Inventario.

> **Consejos**
> - Un producto inactivo no se puede cotizar ni vender, pero se conserva su historial.

---

## 3. Cotizaciones

Una cotización es el precio que le das a un cliente antes de que compre.

### Crear una cotización

1. «Nueva cotización». Elige el negocio que la emite y el cliente.
2. Agrega productos del catálogo con su cantidad (puedes cambiar el precio o poner descuento).
3. Guarda. El PDF se descarga con el botón «PDF» del detalle y sale con el logotipo del negocio.

### Enviarla

- En el detalle de la cotización puedes mandarla por correo con el PDF adjunto.

### Cuando el cliente acepta

1. Cambia su estado a «Vendido». Eso crea la venta, descuenta del inventario y registra el ingreso en Contabilidad.
2. Si el cliente no acepta, márcala «Cancelada».

> **Consejos**
> - Estados: Pendiente (esperando respuesta), Vendido (ya es venta), Cancelado.
> - Para repetir una cotización parecida usa «Duplicar».

---

## 4. Ventas y entregas

Las ventas vienen de una cotización vendida, o las anotas directamente si el cliente compró sin cotización.

### Anotar una venta directa

1. Ventas → «Nueva venta». Elige negocio, cliente, fecha y los productos.
2. Al guardar se descuenta el inventario y nace el ingreso en Contabilidad.

### Ver lo que hay que entregar

1. Pantalla de entrega → marca los pedidos que quieras (o «seleccionar todas») y pulsa «Mostrar en pantalla».
2. Verás juntos los productos agrupados (por ejemplo 5 + 2 = 7 piezas del mismo producto) y los pedidos por cliente. No hay fases que controlar: es solo para ver y alistar.
3. Si quieres volver a abrir esa selección, «Guardar esta vista»; aparece en «Vistas guardadas».

### Registrar una entrega

1. En la lista de pedidos pulsa «Registrar entrega», escribe cuántas piezas entregaste (puede ser parcial) y confirma.
2. Si el cliente devuelve algo, la venta se cierra como devuelta pero el ingreso se conserva.

> **Consejos**
> - Se puede vender aunque el inventario esté en cero: queda en negativo y se corrige al recibir mercancía.

---

## 5. Inventario

Cuántas piezas tienes de cada producto y por qué cambió.

### Ver existencias

- Inventario lista cada producto con su cantidad. Cada venta, compra recibida o ajuste deja un movimiento con su motivo.

### Corregir una cantidad

- Haz un ajuste e indica el motivo (por ejemplo «conteo físico»). Siempre queda registrado quién y cuándo.

> **Consejos**
> - Puedes descargar el inventario en PDF o Excel.

---

## 6. Compras y proveedores

Es un solo módulo con dos pestañas: Órdenes de compra y Proveedores.

### Dar de alta un proveedor

- Compras y proveedores → pestaña «Proveedores» → «Nuevo proveedor».

### Comprar

1. Pestaña «Órdenes de compra» → «Nueva orden». Elige proveedor y productos con cantidad y costo.
2. Cuando llegue la mercancía, abre la orden y usa «Recibir». Las piezas suman al inventario (puedes recibir por partes).

### Historial anterior

- En Proveedores, el botón «Entradas» guarda las entregas con foto que se registraban antes. Las compras nuevas se hacen con órdenes de compra.

> **Consejos**
> - Para registrar cuánto pagaste por una compra, anótalo como gasto en Contabilidad.

---

## 7. Anotar un gasto y subir su factura

Un gasto es cualquier cosa que pagas para trabajar: material, gasolina, renta, honorarios.

### Las 6 preguntas

1. Contabilidad → Gastos → «Anotar un gasto».
2. ¿En qué fue? · ¿A quién le pagaste? · ¿Cuándo fue? · ¿Cuánto pagaste en total (con IVA incluido)? · ¿Ya lo pagaste? · ¿Te dieron factura?
3. La plataforma separa sola el subtotal y el IVA. Si no tiene IVA, elige «No tiene IVA».

### Subir la factura

1. En el mismo formulario, «Factura o comprobante»: elige uno o varios archivos (PDF, XML, JPG, PNG o WebP). Se detecta solo qué es cada uno.
2. También puedes subirlos después desde el detalle del gasto, en «Facturas y comprobantes».
3. Si subes el XML de la factura, se leen sus datos (folio fiscal, importes) y avisa si algo no cuadra.

### Más detalles

- «Más detalles (opcional)» tiene categoría, cómo pagaste, folio fiscal, repartir entre negocios y notas. No hace falta llenarlo.

### Pagarlo después

- Si lo dejaste como «lo debo todavía», ábrelo cuando lo pagues y cámbialo a pagado con su fecha.

> **Consejos**
> - Solo los gastos pagados y con factura se restan de tus impuestos.
> - Un ticket sin factura se puede guardar, pero no baja tus impuestos.
> - Máximo por archivo: el tamaño que indica la pantalla.

---

## 8. Ventas y cobros en Contabilidad

Cada venta genera automáticamente un ingreso. Tú solo anotas si ya te pagaron y si se facturó.

### Anotar un pago del cliente

1. Contabilidad → «Ventas y cobros» → abre la venta → «Pagos recibidos del cliente».
2. Escribe cuándo y cuánto te pagaron y cómo. Si paga en partes, anota cada pago: queda «Pago parcial» hasta completar.

### Decir si se facturó

1. En la misma pantalla: «¿Se le dio factura al cliente?» → Sí, No o Sin indicar.
2. Si hay factura, escribe su folio y UUID (o sube el XML y se llenan solos).

### Subir la factura de la venta

- Abajo, «Facturas y comprobantes»: elige el PDF y el XML a la vez y pulsa «Subir archivos».

### Pendientes de facturar

- La pestaña «Pendientes de facturar» lista las ventas sin factura para que no se te pase ninguna. Se puede exportar.

> **Consejos**
> - Cancelar un pago o una venta pide un motivo y queda registrado; nada se borra en silencio.

---

## 9. Resumen del mes y cierre

La pantalla principal de Contabilidad te dice, sin tecnicismos, cómo vas.

### Leer el resumen

1. «Vendiste», «Gastaste» y «Te queda» (ventas menos gastos, antes de impuestos).
2. «Lo que te falta hacer» te avisa de ventas sin cobrar, sin indicar si se facturaron, gastos por pagar y gastos sin factura; cada aviso tiene un botón para ir a resolverlo.
3. «Ver el detalle completo» muestra el IVA y el desglose por negocio.

### Cambiar de mes o negocio

- Arriba elige año, mes y negocio y pulsa «Ver periodo».

### Más opciones

- Descarga las facturas del mes en un ZIP para tu contador, cierra el mes o consulta los meses cerrados.

### Cerrar el mes

- Cuando el mes está completo, «Revisar y cerrar el mes». La plataforma verifica pendientes y, al cerrar, nadie puede cambiar ventas, gastos ni archivos de ese mes sin reabrirlo (con motivo y contraseña).

> **Consejos**
> - Cierra el mes después de presentar tu declaración.

---

## 10. Impuestos (solo informativo)

Te muestra, de forma aproximada, cuánto deberías separar este mes para el SAT según lo que vendiste y gastaste.

### Leer la cifra

1. Impuestos → arriba: «deberías separar aproximadamente $X», con el IVA y el ISR por separado.
2. Solo cuenta lo que ya te pagaron y los gastos ya pagados con factura.

### Hacerla más exacta

1. Si ya pagaste ISR o IVA al SAT, anótalo en «Pagos al SAT y ajustes».
2. Marca como «No deducible» los gastos que sabes que no se restan.
3. Anota retenciones o saldo a favor si los tienes.

### Para tu contador

- «Ver cómo se calculó» muestra paso a paso la cuenta.

> **Consejos**
> - Es una estimación: no sustituye la declaración. Compárala siempre con tu contador.
> - Los pagos provisionales se presentan a más tardar el día 17 del mes siguiente.
> - Para que funcione, el administrador debe tener activas las reglas del SAT (Impuestos → Reglas del SAT).

---

## 11. Estadísticas

Cómo van tus ventas, con tres números grandes y cuatro gráficas fáciles de leer.

### Ver tus números

1. Arriba elige «Este mes», «Este año» o «Año pasado», o usa los filtros de periodo y negocio y pulsa «Ver estadísticas».
2. «Vendiste», «Cobraste» y «Facturaste» son tres cosas distintas: vender es cuando haces la venta, cobrar cuando te pagan y facturar cuando das factura.
3. Debajo de «Vendiste» dice cuánto subió o bajó contra el año anterior.

### Las gráficas

1. Ventas por mes (o por día, trimestre…), año contra año, productos que más vendes y clientes que más compran.
2. Pasa el cursor sobre una barra o punto para ver su cifra exacta.

### Filtros y descargas

1. «Más filtros» permite elegir un cliente o un producto y cuántos años comparar.
2. «Ver las tablas con todos los números» muestra el detalle, y arriba puedes descargar PDF, Excel o CSV.

> **Consejos**
> - Si el periodo no ha terminado, la comparación usa el mismo avance del año anterior para ser justa.

---

## 12. Aplicación de escritorio

La misma plataforma en una ventana propia, sin abrir el navegador.

### Instalarla

1. Menú → «Ajustes» → pestaña «Aplicación de escritorio» → descarga el paquete .deb.
2. En una terminal: sudo apt install ./cotizaciones-escritorio_1.0.0_amd64.deb
3. Ábrela desde el menú de aplicaciones como «Acero 360».

> **Consejos**
> - Usa los mismos datos y la misma sesión que la web.

---

## 13. Respaldos y cuidados

Tu información importa.

### Respaldos

- Se hace un respaldo cada noche (2:30) en la computadora de administración. Copia esa carpeta también a un disco externo o a la nube.

### Cuidados

1. No compartas tu contraseña.
2. Cierra sesión en computadoras ajenas.
3. Los archivos fiscales no son públicos: solo se descargan con tu sesión.

---

## 14. Centro de seguimiento

Reúne lo que necesita tu atención: ventas sin cobrar, facturas por definir, gastos, entregas pendientes, productos con poco stock y novedades de la plataforma.

### Dónde verlo

- La campana de arriba muestra cuántos avisos tienes sin leer. También está en el menú → «Centro de seguimiento».

### Prioridades

- Alta, media o baja según los días que lleva la operación (por ejemplo, un cobro de más de 30 días es de prioridad alta). Los más urgentes salen primero.

### Abrir y marcar

1. «Abrir registro» te lleva a la venta, el gasto o el producto de origen.
2. «Marcar como leído» solo oculta el aviso de tu lista: no cambia ninguna venta, cobro ni factura. Si la operación cambia (por ejemplo, un pago parcial), el aviso vuelve a aparecer como nuevo.

> **Consejos**
> - Una venta pendiente de cobro y una pendiente de factura son avisos distintos: una venta ya facturada no aparece como «factura por definir».

---

## 15. Acerca de Acero 360

Acero 360 es la plataforma comercial, contable y administrativa de tu negocio de acero: antes se llamaba «Cotizaciones». Todo lo que ya tenías (clientes, productos, cotizaciones, ventas, inventario, gastos e impuestos) sigue igual y en el mismo lugar.

### Qué cambió

1. Nuevo nombre y logotipo (una viga en I dentro de un anillo de 360°) en el menú, el inicio de sesión, los PDF y la aplicación de escritorio.
2. La dirección web, tus datos y tu contraseña no cambian.

### Dónde está

- Menú → «Ajustes». Ahí puedes permitir o no vender sin existencias, usar las pestañas: Configuración general, Usuarios y permisos, Perfil, Empresas y razones sociales, Datos fiscales, Aplicación de escritorio, Versión y actualizaciones, Novedades de la plataforma y Ayuda y manual.

### Aplicación de escritorio

- Se instala igual y conserva tu sesión; el nuevo nombre y el icono aparecen al instalar la versión más reciente del paquete .deb.

> **Consejos**
> - Si ves el logotipo anterior en la pestaña del navegador, recarga con Ctrl + Shift + R.

---

## 16. Palabras que verás

Qué significan, en pocas palabras.

### Glosario

- IVA: impuesto de 16 % que cobras en las ventas y que pagas en tus compras; al SAT le entregas la diferencia.
- ISR: impuesto sobre tu ganancia (lo que vendes menos lo que gastas con factura).
- CFDI / factura: el comprobante fiscal digital. Viene en PDF (para leer) y XML (para la computadora).
- UUID o folio fiscal: el número único de cada factura.
- Deducible: un gasto que puedes restar de tus ventas para pagar menos ISR. Necesita factura y estar pagado.
- Subtotal: precio antes de IVA. Total: precio con IVA.
- Pago provisional: el pago mensual a cuenta del ISR del año.
- Cerrar el mes: congelar las cifras de un mes para que nadie las cambie por accidente.
- Flujo de efectivo: contar las ventas cuando te pagan y los gastos cuando los pagas.

