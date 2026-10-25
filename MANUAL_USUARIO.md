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
8. Facturación y cobro de una venta
9. Resumen del mes y cierre
10. Impuestos (solo informativo)
11. Estadísticas
12. Notas personales
13. Calendario
14. Redactor
15. SheetFlow
16. Editor
17. Archivo
18. Aplicación de escritorio
19. Almacenamiento y respaldos
20. Cuidados con tu información
21. Centro de seguimiento
22. Acerca de Acero 360
23. Palabras que verás

---

## 1. Empezar

Acero 360 sirve para cotizar, vender, entregar, llevar tus gastos y saber más o menos cuánto vas a pagar de impuestos. Todo se hace desde el menú de la izquierda (en el celular, desde el botón de abajo a la derecha).

### Entrar

1. Abre la página e inicia sesión con tu correo y contraseña.
2. Si olvidaste la contraseña, cámbiala en «Usuarios y perfil».

### El panel

1. Arriba están los indicadores del periodo: ventas, cotizaciones, por cobrar, por facturar, entregas, devoluciones, gastos y avisos; cada tarjeta abre los registros que la forman.
2. Debajo, «Productos más vendidos» trae los 4 que más vendiste en el mes, con su código, su foto y el importe de venta; «Productos con existencias bajas» avisa cuáles están agotados, con existencia negativa o por debajo de su mínimo (los más urgentes primero). El número de la existencia sale en rojo cuando ya no queda nada y en naranja cuando quedan pocas.
3. Cada tarjeta de producto abre su ficha, y las de existencias te llevan al inventario con el producto listo para ajustarlo.

### Moverte por el menú

1. «Nueva cotización», «Histórico», «Clientes» y «Productos» son lo de todos los días.
2. Debajo de la línea están Ventas, Pantalla de entrega, Contabilidad, Impuestos, Inventario y Compras y proveedores (un solo apartado).

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

### Dar seguimiento a una entrega

1. Menú «Seguimiento de entregas» (también como pestaña dentro de la pantalla de entrega): lista cada venta con su estado general —*Pendiente de entregar*, *Entregado*, *Entregado con incidencias pendientes* o *Entregado · incidencias resueltas*— con filtros por estado, texto y negocio.
2. Al abrir una venta ves: la fecha de entrega y sus observaciones (se guardan ahí mismo, con «Guardar seguimiento»), los productos **vendidos vs. entregados vs. afectados por incidencias**, sus incidencias, las devoluciones y cambios, la facturación y el historial completo de todo lo que pasó (entregas, devoluciones, cambios y correcciones de factura).

### Registrar una incidencia de entrega

1. En el seguimiento de la venta, «Registrar incidencia». Elige el motivo: **producto faltante, producto dañado o defectuoso, producto incompleto, cambio o sustitución o devolución**, y escribe cuántas piezas de cada producto afecta.
2. Al guardar, esas piezas **regresan al inventario** y dejan de estar pendientes de entrega si nunca se entregaron: la venta no cambia de importe ni se toca su factura todavía.
3. Si el cliente debe recibir un reemplazo, márcalo como «requiere cambio» (en «Cambio o sustitución» se marca solo): queda como cambio pendiente y se descuenta del inventario cuando lo entregues con «Marcar cambio entregado» en el detalle de la venta.

### Corregir la factura de la entrega

1. Al registrar la incidencia, marca «hay que cancelar la factura y subir la correcta» (solo aparece si la venta ya está facturada).
2. Al resolver la incidencia, adjunta la factura correcta en **PDF y XML**: los documentos anteriores quedan dados de baja y el ingreso en Contabilidad toma folio, UUID, fecha, subtotal, IVA y total del XML nuevo (si el periodo contable está cerrado, te lo avisa).
3. Los cobros ya registrados no cambian: si quedaron por encima del nuevo total, la pantalla te avisa con enlace a los cobros de la venta para que los ajustes.

> **Consejos**
> - Se puede vender aunque el inventario esté en cero: queda en negativo y se corrige al recibir mercancía.
> - Mientras haya una incidencia abierta, la venta aparece como *Entregado con incidencias pendientes* y sale un aviso en el Centro de seguimiento; al resolverla o cancelarla el estado se actualiza solo.
> - La fecha de entrega y las observaciones no son fiscales: sirven para acordar y recordar qué se prometió al cliente.

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

Es un solo módulo con ocho pestañas: Resumen, Compras y pedidos, Recepciones, Proveedores, Catálogo, Facturas, Pagos y Categorías. Una compra se captura una sola vez y desde ahí se recibe, se factura y se paga, sin repetir datos en Contabilidad.

### Dar de alta un proveedor

1. Pestaña «Proveedores» → «Nuevo proveedor». Un proveedor puede venderte distintas cosas: el tipo de compra se elige en cada compra, no en el proveedor.
2. Un proveedor inactivo ya no aparece en compras nuevas, pero su historial se conserva. Si tiene compras no se puede eliminar: desactívalo.

### Catálogo de semiterminados por proveedor

1. Pestaña «Catálogo» → «Nuevo producto»: elige el proveedor y captura nombre, descripción, código y costo unitario de compra. Un proveedor puede tener muchos; el mismo artículo puede existir en otro proveedor con otro costo.
2. No se repiten nombre ni código dentro del mismo proveedor. No se borran: «Desactivar» deja de ofrecerlos en compras nuevas y se conservan en el historial (puedes reactivarlos).
3. Opcional: en la ficha del producto, «Relacionar» con tus productos propios, para saber qué semiterminado de qué proveedor se usa en cada uno (también se ve desde la edición del producto propio). Son cosas distintas: cada uno conserva su código y costo.
4. Cambiar el costo del catálogo no altera compras anteriores y queda en la bitácora.

### Comprar producto semiterminado para vender

1. «Nueva compra» → tipo «Producto semiterminado» → elige primero al proveedor. Cada partida ofrece solo los productos activos del catálogo de ese proveedor: al elegir uno se llenan solos el nombre, el código y el costo. Solo escribes la cantidad; el importe de cada partida (cantidad × costo) y el total se calculan solos.
2. Si el producto está relacionado con un único producto propio, se propone también. Si no lo está, elige a cuál corresponde o déjalo para después en el detalle de la compra. El nombre y el precio de venta de tu catálogo no cambian.
3. El costo viene bloqueado. Si de verdad esta vez pactaste otro precio, usa «Cambiar costo»: queda el precio final usado, se avisa cuál era el del catálogo y queda en la bitácora.
4. El IVA no se calcula solo: captúralo, o usa «Calcular 16 % del subtotal» y corrígelo si hace falta. El total de la compra es el subtotal más ese IVA.
5. ¿El producto no está en el catálogo? Elige «Otro / fuera del catálogo» y escríbelo, o agrégalo antes al catálogo.
6. Cada compra guarda una copia del nombre, código y costo usados: si luego cambias el costo en el catálogo o desactivas el producto, las compras anteriores no se alteran.

### Comprar materiales, insumos y consumibles

1. «Nueva compra» → tipo «Material, insumo o consumible» (pintura, thinner, discos, guantes, soldadura, varilla, lámina…).
2. No necesitas crear productos. Si es un consumible, déjalo en «Consumible (sin inventario)». Si quieres llevar existencias (varilla, lámina), elige «Controlar en inventario» y escribe el nombre del material: queda aparte de los productos para venta.

### Recibir la mercancía

1. Detalle de la compra → «Registrar recepción», o pestaña «Recepciones». Captura lo que realmente llegó; puedes recibir por partes.
2. Pedir no cambia las existencias: solo la recepción confirmada. Reenviar el formulario no suma dos veces.
3. Lo recibido de un semiterminado queda «por terminar» y todavía no se puede vender. Cuando lo termines, en «Recepciones → Semiterminados por terminar» usa «Pasar a disponibles». Si llegó ya terminado, márcalo al recibir.
4. No se acepta más de lo pendiente, salvo que marques «Llegó más de lo pendiente» (queda en la bitácora).

### Factura (CFDI) y comprobantes

1. En el detalle de la compra, «Factura y comprobantes» → adjunta el XML del CFDI, el PDF o una foto. La compra se envía a Contabilidad una sola vez como un gasto; si la factura llega después, se asocia al mismo gasto.
2. Solo un XML con estructura de CFDI y timbre la deja «Con CFDI». Un PDF o una foto no cuentan como CFDI. Si no hubo factura, marca «Sin factura».
3. Adjuntar un documento no vuelve deducible la compra: queda «por validar» para tu contador. El mismo UUID no se puede usar en dos compras.

### Pagos al proveedor

1. En el detalle de la compra, «Pagos al proveedor» → registra el pago, completo o parcial. Reduce el saldo pendiente y no crea otro gasto.
2. Si te equivocas, «Cancelar pago» con motivo: el pago queda en el historial y el saldo se recupera.

### Ver pendientes y reportes

1. «Resumen» muestra lo que falta recibir, las piezas por terminar, las facturas pendientes y lo que debes. «Compras y pedidos» filtra por proveedor, fechas, categoría, tipo, recepción, factura y pago.
2. El expediente de cada proveedor (pestaña «Proveedores» → su nombre) reúne sus compras, recepciones, facturas, pagos y saldos.

### Corregir

- Una compra se edita o cancela mientras no tenga recepciones, pagos ni gasto vigente. Para corregir existencias usa «Corregir cantidad» en «Recepciones»: pide una razón y queda en la bitácora.

### Historial anterior

- Las «entregas anteriores» con foto que se registraban antes solo se muestran, como historial, en el expediente del proveedor que las tenga. Las compras nuevas se hacen con «Nueva compra».

> **Consejos**
> - El costo de cada compra queda guardado tal como se compró: si cambia el costo actual de un producto, las compras anteriores no se alteran.
> - Actualizar el costo actual del catálogo es una opción aparte al recibir; nunca cambia el precio de venta.
> - El IVA de la compra no se calcula: captúralo solo si lo conoces; si lo dejas vacío queda como «no especificado».

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

### Uso del CFDI y método de pago

1. Cuando el gasto es «Con factura», aparecen dos campos más: «Uso del CFDI» (para qué se usó: adquisición de mercancías, gastos en general, mobiliario…) y «Método de pago del CFDI».
2. El método de pago tiene tres opciones: «PPD · Pago en parcialidades», «PUE · Pago en una sola exhibición» y «Por definir (aún no se decide)», que es la que queda si todavía no sabes cómo se pagará.
3. Si el gasto es «Sin factura», esos campos no se guardan: no hay comprobante del que hablar.
4. Si subes el XML, el uso del CFDI y el método de pago se llenan solos con los que trae la factura.

### Más detalles

- «Más detalles (opcional)» tiene categoría, cómo pagaste, folio fiscal, repartir entre negocios y notas. No hace falta llenarlo.

### Pagarlo después

- Si lo dejaste como «lo debo todavía», ábrelo cuando lo pagues y cámbialo a pagado con su fecha.

### Que la plataforma lea la factura

1. Gastos → «Leer factura» (o desde una venta, «Leer factura»). Sube el XML (lo mejor: datos exactos), el PDF o una foto.
2. Aparece una pantalla de revisión con lo que se leyó, qué tan seguro es cada dato y el aviso de discrepancias o duplicados. Corrige lo necesario y marca «Revisé este dato» en lo dudoso.
3. Nada se guarda hasta que pulsas «Confirmar datos». Puedes asociar el documento a un gasto o venta que ya existe (sin duplicarlo) y queda registrado quién confirmó y cuándo.
4. El folio de la factura (p. ej. A-123) y el UUID son datos distintos. Si no se puede leer el archivo, captura los datos a mano en la misma pantalla.

> **Consejos**
> - Solo los gastos ya pagados se restan de tus impuestos; el archivo o el CFDI adjunto es el respaldo para tu contador.
> - Un ticket sin factura se puede guardar: la estimación lo resta si está pagado, pero tu contador decide si es deducible.
> - Si eliminas un gasto por error, tiene 90 días en Ajustes → Papelera y se puede restaurar con todos sus datos (sin los archivos adjuntos: esos sí se borraron).
> - Máximo por archivo: el tamaño que indica la pantalla.

---

## 8. Facturación y cobro de una venta

Cada venta genera automáticamente un ingreso. Toda la facturación y los cobros de esa venta se indican en una sola sección: «Facturación y cobro», arriba del detalle de la venta.

### Anotar un pago del cliente

1. Abre la venta (Ventas → su folio; también desde Contabilidad → Ingresos → «Abrir», desde los avisos del panel o desde «Pendientes de facturar») y busca «Facturación y cobro».
2. En «Pago»: pulsa «Ya me pagaron todo» si ya te pagaron completo, o despliega «Anotar un pago parcial» y escribe cuándo, cuánto y cómo te pagaron. Si paga en partes, anota cada pago: queda «Pago parcial» hasta completar.

### Decir si se facturó

1. En esa misma sección, «¿Ya se facturó?» → elige el estado: Pendiente de facturar, Facturada o No facturada.
2. Si hay factura, despliega «Folio, UUID y fecha (opcional)» y escribe el folio y el UUID (o súbelos: el XML los llena solos).

### Subir la factura de la venta

1. En «Subir factura (PDF y/o XML)», de la misma sección: elige el PDF y el XML juntos y pulsa «Guardar facturación».
2. Los archivos quedan listados en «Facturas y comprobantes», con su validación documental; ahí también se descargan o se dan de baja.

### Pendientes de facturar

- La pestaña «Pendientes de facturar» lista las ventas sin factura para que no se te pase ninguna y su botón «Clasificar» abre esta misma sección. Se puede exportar.

### Ver todas tus ventas

1. Contabilidad → «Ventas y cobros» muestra DOS tablas: arriba las ventas con factura o pendientes de facturar, y abajo las que ya marcaste como «No facturada» (esas ya no se van a facturar).
2. Cada tabla tiene su propio total y se pagina por su lado; los filtros de arriba (búsqueda, facturación y periodo) aplican a las dos.
3. El interruptor de la columna «Facturación» mueve una venta de una tabla a la otra: si un día llega la factura, márcala como facturada y sube a la tabla de arriba.

> **Consejos**
> - Cancelar un pago o una venta pide un motivo y queda registrado; nada se borra en silencio.
> - Facturar y cobrar son cosas distintas: una venta puede estar facturada sin cobrarse, y al revés.

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
2. Solo cuenta las facturas que ya te pagaron al 100 % y los gastos ya pagados.
3. Una factura en parcialidades o sin cobrar, o un gasto por pagar, NO se cuenta hasta que se confirme el pago: la misma pantalla te dice cuántos quedaron fuera y por cuánto.

### Qué significa el método de pago

1. PUE (una sola exhibición), PPD (parcialidades) y «por definir» son informativos: no cambian la cuenta, solo explican por qué una operación entra o no. Un gasto PPD entra cuando queda pagado por completo.
2. Los archivos que subes en ventas y gastos (XML, PDF o fotos) son solo respaldo para consultar y descargar: no modifican los importes ni el cálculo.

### Para tu contador

- «¿Cómo se calcula?» muestra paso a paso la cuenta, con el IVA real de cada CFDI, y «Comparar con lo que declaró mi contador» te deja anotar lo declarado.

### El preliminar básico (simulador con tus facturas)

1. Impuestos → pestaña «Preliminar básico»: cuánto serían tus impuestos del año usando sólo las facturas registradas (ventas facturadas y gastos con comprobante fiscal). Es el cálculo del documento Fiscal 2026, sin motor ni catálogos.
2. Cada mes: IVA real facturado − IVA real acreditable, y un ISR plano del 32 % sobre la utilidad del mes (puede salir a tu favor). El año suma los 12 meses tal cual.
3. A diferencia de «Impuestos», aquí no importa si te pagaron o no: cada factura cuenta en el mes de su fecha de facturación. Con IVA 0 entra con IVA 0; con 8 %, con su 8 % real.
4. Abajo del mes elegido ves quiénes generaron las cifras: tus clientes facturados y tus proveedores/compras con comprobante, cada uno con sus montos con y sin IVA.
5. «Exportar PDF» (arriba a la derecha) baja el reporte: tabla fiscal del año + los dos listados del mes.

> **Consejos**
> - Es una estimación: no sustituye la declaración. Compárala siempre con tu contador.
> - Los pagos provisionales se presentan a más tardar el día 17 del mes siguiente.
> - Facturar y cobrar siguen siendo cosas distintas: una factura emitida se cuenta cuando ya te la pagaron.

---

## 11. Estadísticas

Cómo van tus ventas, cobros, facturas y gastos, con indicadores y gráficas interactivas.

### Ver tus números

1. Arriba elige «Este mes», «Este año» o «Año pasado», o usa los filtros de periodo y negocio y pulsa «Ver estadísticas».
2. Ventas realizadas, cobros recibidos, ventas facturadas y gastos son cosas distintas: vender es cuando haces la venta, cobrar cuando te pagan y facturar cuando das factura.
3. Cada indicador dice cuánto subió o bajó (en pesos y en %) contra el mismo periodo del año anterior; en gastos, bajar es lo bueno.

### Las gráficas

1. Ventas por mes, año contra año y por semestre; ingresos acumulados; ventas contra cobros; cobrado y saldo pendiente; facturadas y pendientes de facturar; gastos por año y categoría; comparación entre negocios; productos de mayor y menor movimiento y clientes.
2. Productos y clientes salen SEPARADOS por negocio: cada negocio tiene su propia gráfica y sus propias filas, y cada producto trae su código (SKU) antes del nombre; en «Ventas por negocio» también ves los productos de cada negocio con su código.
3. Pasa el cursor para ver la cifra exacta y la variación contra el año anterior; toca un nombre de la leyenda para ocultar o mostrar esa serie; el icono de la esquina guarda la gráfica como imagen.

### Filtros y descargas

1. «Más filtros» permite elegir un cliente o un producto y cuántos años comparar.
2. «Ver las tablas con todos los números» muestra el detalle de cada gráfica. Arriba descargas PDF (con gráficas), Excel (una hoja por gráfica, con la gráfica dentro) o CSV; siempre salen con el periodo y los filtros que elegiste.
3. El PDF trae además tres secciones que la pantalla no muestra: los productos más vendidos del periodo (con su código, su foto y el importe), los de existencias bajas (el número en rojo si ya no queda nada y en naranja si quedan pocas) y la tabla de productos menos vendidos del periodo, por negocio y de menos a más piezas.

> **Consejos**
> - Si el periodo no ha terminado, la comparación usa el mismo avance del año anterior para ser justa.

---

## 12. Notas personales

La libreta del equipo: recordatorios, pendientes por cotizar, cambios y revisiones, en un solo lugar.

### Escribir una nota

1. Menú → «Notas personales» → «Nueva Nota».
2. Ponle título, escribe el contenido y elige una categoría: Recordatorio, Cotizar, Cambios, Revisar u Otro.
3. Si quieres un aviso, escribe en «Fecha y recordatorio» algo como «Mañana 9:00 AM». También puedes asociarle un cliente en «Referencia».

### Buscarlas y ordenarlas

1. Arriba hay un buscador por palabra, cliente o etiqueta, y chips para filtrar: Todas, Fijadas, Recordatorios, Cotizar, Cambios, Revisar y Otro.
2. Las tarjetas de arriba cuentan cuántas hay de «Notas Activas», «Fijadas» y «Por cotizar».
3. La lista trae 12 por página; las fijadas siempre salen arriba.

### Trabajar con ellas

1. Cada nota tiene «Editar», «Fijar» (la sube al principio), «Marcar como hecha» (después puedes «Reabrir») y «Eliminar», que pregunta antes.
2. Cada tarjeta dice quién la escribió y cuándo («Hoy, 10:42 AM», «Ayer», «18 Oct»).

> **Consejos**
> - Las notas son del equipo: lo que escribe uno lo leen todos los administradores.
> - El título puede tener hasta 200 caracteres y el texto de recordatorio 80; es un recordatorio escrito, la plataforma no manda correos.

---

## 13. Calendario

Un calendario del mes que junta dos cosas: lo que pasó en tus negocios cada día y los recordatorios y eventos del equipo.

### Moverte por el mes

1. Menú → «Calendario». Arriba están los botones ‹ › para cambiar de mes y «« »» para cambiar de año, más el botón «Hoy» para regresar al día de hoy.
2. El día de hoy viene marcado con un borde azul.

### Los marcadores del negocio

1. En cada casilla aparecen iconos grandes y macizos con lo que pasó ese día: en VERDE una venta, en ROJO un gasto, en AZUL una recepción de proveedor, en AMARILLO una factura de proveedor por pagar y en NARANJA una factura de venta por cobrar.
2. Pon el cursor sobre el icono para ver cuántas operaciones de ese tipo hay. Debajo del calendario está la leyenda con los cinco colores.
3. Son de SÓLO LECTURA: no se pueden editar ni borrar, porque son el reflejo de las operaciones ya registradas en Ventas, Gastos, Compras y Contabilidad. Un gasto deja de estar amarillo en cuanto lo marcas pagado, y una factura deja de estar naranja en cuanto se cobra por completo.

### La Agenda del mes

1. Debajo del calendario está la «Agenda»: un listado, día por día y en orden, de todos los días con movimiento. En cada tarjeta ves la fecha, los marcadores con sus cifras (cuántas ventas y por cuánto, los gastos, las recepciones, lo que queda por pagar y por cobrar) y los recordatorios capturados ese día.
2. El botón con forma de ojo («Ver») abre en una ventana el detalle completo de ese día.
3. Sólo aparecen los días que tienen algo: operaciones o recordatorios.

### Ver el detalle de un día

- Haz clic en el botón «Ver» de la tarjeta de ese día en la Agenda. Se abre una ventana con la fecha, cada tipo de marcador con su cantidad y su importe, y cuántos recordatorios hay. El botón «Ver en el calendario» te lleva a esa fecha.

### Recordatorios y eventos

1. Para crear uno, haz clic una SEGUNDA vez sobre la casilla del día que ya está seleccionada (la primera vez sólo la selecciona): se abre una ventana con el formulario. También puedes usar el botón «Nuevo recordatorio o evento» de arriba.
2. La ventana trae la fecha ya cargada con el día elegido: nombre, hora opcional, descripción, negocio e importancia (Bajo, Medio o Alto). Pulsa «Guardar»; si falta algo, la ventana se queda abierta y avisa debajo del campo.
3. Los eventos se listan en su tarjeta de la Agenda con puntos de color según su importancia: verde Bajo, amarillo Medio, rojo Alto. Cada uno se puede editar o eliminar; la baja pide confirmación.

> **Consejos**
> - Lo que se vende, se gasta o se recibe se marca solo: no hay que capturarlo dos veces en el calendario.
> - Los recordatorios son del equipo: lo que registra uno lo leen todos los administradores.
> - El calendario no manda correos ni avisos: es una pantalla para consultar.
> - Los archivos que subes a ventas y gastos son sólo respaldo: no cambian las cifras que ves aquí.

---

## 14. Redactor

El procesador de textos de la plataforma: cartas, oficios, memorias, actas y procedimientos con formato profesional.

### Crear un documento

1. Menú → «Redactor» → «Nuevo documento». Dale nombre, fecha y elige carpeta.
2. Elige el corrector ortográfico (Español, English o ambos), el tamaño de hoja (Carta, A4, Oficio o Tabloide), la orientación y una plantilla.
3. Plantillas incluidas: Oficio/carta, Minuta, Informe de avance, Memoria técnica, RFI, Orden de cambio, Acta de entrega-recepción, Reporte de incidente y Lista de verificación. Pulsa «Crear y abrir».

### Escribir con formato

1. Las pestañas de arriba son Inicio, Insertar, Diseño, Revisión y Herramientas.
2. En «Insertar» hay tabla, imagen, figura, dibujo, vínculo, salto de página, índice, nota al pie, leyendas numeradas, referencia cruzada, ecuación, portada y bloques de obra (acta, minuta, bitácora, orden de cambio, lista de asistencia).
3. Guarda con «Guardar»; también se guarda solo cada 30 segundos.

### Control de cambios y firmas

1. En «Revisión» puedes activar cambios (control de versiones): al final, «Aceptar todos» o «Rechazar todos». Los comentarios tienen su propio panel.
2. «Versiones» guarda una copia automática cada 10 minutos y conserva las últimas 40.
3. En «Firmas» guardas tu firma manuscrita (hasta 5 por usuario) o firmas electrónicas con contraseña; se insertan en bloques de 1 a 4 firmantes.

### Descargar

1. Botón «Descargar» → Word (.docx), LibreOffice (.odt), PDF o Imprimir.
2. Al exportar puedes marcar «Mostrar cambios marcados», «Incluir comentarios al final» e «Incluir firmas electrónicas».

> **Consejos**
> - Los documentos son compartidos: cualquiera de los administradores puede abrirlos y editarlos. Lo único tuyo son tus firmas dibujadas y tu diccionario del corrector.
> - Puedes insertar una tabla de SheetFlow y esta se actualizará sola; los dibujos que hagas en el Editor también se pueden insertar como imágenes.

---

## 15. SheetFlow

La hoja de cálculo en línea para controles, presupuestos y listados que después puedes meter en un documento.

### Crear una tabla

1. Menú → «SheetFlow» → «Nueva tabla». Dale nombre y fecha.
2. Elige cómo empezar: «En blanco» (4 columnas × 10 renglones), «Tabla inteligente» (tú diseñas las columnas), una plantilla (Control de materiales, Presupuesto por conceptos, Registro de restricciones, Asistencia semanal) o «importar un archivo» (.sheetflow, .xlsx, .ods o .csv).

### Llenarla

1. Cada columna tiene un tipo: Texto, Número, Moneda, Porcentaje, Fecha, Lista desplegable, Casilla (sí/no) o Semáforo.
2. Puedes copiar y pegar directamente desde Excel (Ctrl+C, Ctrl+V).
3. Abajo se suman los totales (suma, promedio, mínimo, máximo o cuenta) y se puede ordenar de A a Z o de Z a A.

### Fórmulas

1. Escribe «=» para empezar una fórmula. Sirven las operaciones básicas (+ − * / ^) y estas funciones: SUMA, PROMEDIO, MIN, MAX, CONTAR y REDONDEAR.
2. Y estas condicionales: SI, Y, O, NO, SI.ERROR, SUMAR.SI, CONTAR.SI y PROMEDIO.SI.

### Descargar

1. «Descargar» → Excel (.xlsx), CSV, SheetFlow (.sheetflow), PDF o Imprimir.
2. El archivo .sheetflow guarda la tabla completa para volver a importarla después.

> **Consejos**
> - Límites: 30 columnas por 1 000 renglones, 500 caracteres por celda, hasta 20 opciones en una lista desplegable y 2 MB al importar un archivo (se usa la primera hoja y la primera fila son los encabezados).
> - Las tablas son compartidas: todos los administradores las ven y las editan.
> - Desde aquí el botón «Crear un documento con esta tabla» la manda al Redactor.

---

## 16. Editor

El editor de dibujo por páginas: planos, croquis, diagramas, portadas y fichas técnicas.

### Crear un documento

1. Menú → «Editor» → «Nuevo documento». Dale nombre, elige el tamaño de página (Carta, Oficio, Legal, Tabloide, A4, A3, A5 o Personalizado), la orientación y el fondo (Liso, Cuadrícula o Puntos).
2. Si eliges «Personalizado», cada lado va de 30 a 1500 milímetros.

### Dibujar

1. Las herramientas son: Seleccionar (V), Mover lienzo (H), Medir, Líneas (continua, discontinua, trazo y punto, punteada), Polilínea, Lápiz (P), Flecha (F), Rectángulo (R), rectángulo redondeado, Elipse (O), triángolo, rombo, zona translúcida, más formas (26 iconos), Texto (T), Tabla, círculo numerado, rombo de restricción e Imagen (I).
2. Abajo a la izquierda están las casillas Cuadrícula, Imán, Reglas, Guías y Ortogonal (F8).
3. El panel de la derecha cambia el color, el negrita/cursiva/subrayado/tachado, la alineación y las capas; ahí también puedes agrupar, desagrupar, alinear y distribuir.

### Páginas y descarga

1. Abajo: «Agregar lámina», «Duplicar esta lámina», mover y «Eliminar esta lámina».
2. Para bajarlo: «Exportar» → PDF, Imprimir o PNG de esta lámina.
3. Arriba hay Deshacer, Rehacer, zoom, maximizar y «Vista de lectura».

> **Consejos**
> - Los documentos son compartidos: cualquiera los ve y los edita.
> - El Redactor puede insertar los PNG que dibujes aquí como imágenes.

---

## 17. Archivo

El cajón de la oficina: carpetas virtuales para guardar y organizar los archivos de trabajo.

### Subir archivos

1. Menú → «Archivo». Sube con «Elegir archivos» + «Subir», o arrastrando los archivos a la zona que dice «Suelta aquí para subirlos».
2. Se admiten varios a la vez. El peso máximo por archivo lo marca el servidor y la propia pantalla lo indica abajo.

### Organizar

1. «Nueva carpeta» crea una subcarpeta dentro de la que estás.
2. Las migas de pan de arriba (Archivo › carpeta › subcarpeta) te dicen dónde estás y sirven para volver atrás.
3. Cada carpeta tiene lápiz para renombrarla y papelera para eliminarla.

### Buscar y mover

1. El buscador de arriba filtra por nombre o descripción.
2. Cada archivo tiene «Descargar», «Mover a…» (elige la carpeta destino) y «Eliminar».

> **Consejos**
> - Los formatos admitidos son 60: Office y LibreOffice, texto, PDF, imágenes, comprimidos (zip, rar, 7z, tar, gz) y modelos de Rhinoceros, Revit, AutoCAD, SketchUp, IFC, STEP y similares.
> - Puedes crear hasta 4 niveles de carpetas una dentro de otra.
> - No se admiten archivos repetidos en la misma carpeta ni archivos .xml: las facturas no se guardan aquí, se adjuntan en Compras → detalle de la compra → comprobante.
> - El Archivo es compartido: todos los administradores ven lo mismo.

---

## 18. Aplicación de escritorio

La misma plataforma en una ventana propia, sin abrir el navegador.

### Instalarla

1. Menú → «Ajustes» → pestaña «Aplicación de escritorio» → descarga el paquete .deb.
2. En una terminal: sudo apt install ./cotizaciones-escritorio_1.0.0_amd64.deb
3. Ábrela desde el menú de aplicaciones como «Acero 360».

> **Consejos**
> - Usa los mismos datos y la misma sesión que la web.

---

## 19. Almacenamiento y respaldos

Cuánto ocupa tu información y cómo guardar una copia de seguridad desde la propia plataforma.

### Dónde verlo

- Menú → «Ajustes» → pestaña «Almacenamiento y respaldos».

### Cuánto ocupa

1. Arriba está el total y una barra con el reparto: base de datos (ventas, clientes, productos, facturas), documentos fiscales (PDF y XML), Archivo compartido, imágenes y copias de seguridad.
2. Debajo, la tabla detalla cuánto pesa cada parte y en qué carpeta está. Se calcula al abrir la página.

### Respaldo automático

1. Actívalo y elige la hora (por defecto a las 23:00). La primera vez que alguien abra la plataforma después de esa hora se hace sola una copia al día.
2. Cuántas copias conservar: las más antiguas se borran solas al respaldar.
3. Carpeta de respaldos: la que trae por defecto o la que tú indiques. Usa un disco o servidor aparte si puedes.

### Hacer una copia ahora

- Botón «Hacer un respaldo ahora». Cada copia trae la base de datos y todos los archivos, con sus huellas de verificación y un manifiesto con la fecha.

### Restaurar

1. En la tabla «Copias guardadas», «Restaurar en base nueva» trae esa copia de vuelta.
2. Nunca se pisa lo que estás usando: la copia se importa en una base nueva y los archivos se extraen a una carpeta aparte. Después revisas y decides si conviene usarla.

> **Consejos**
> - Un respaldo dentro de la misma computadora no te salva si el disco se daña: copia esa carpeta también a un disco externo o a la nube.
> - Si entras desde varios equipos y prefieres que el respaldo ocurra aunque nadie entre, la pestaña trae una línea de cron para pegarla en el servidor.

---

## 20. Cuidados con tu información

Tu información importa.

### Cuidados

1. No compartas tu contraseña.
2. Cierra sesión en computadoras ajenas.
3. Los archivos fiscales no son públicos: solo se descargan con tu sesión.
4. Haz respaldos: la sección anterior explica cómo, desde Ajustes → Almacenamiento.

---

## 21. Centro de seguimiento

Reúne lo que necesita tu atención: ventas sin cobrar, facturas por definir, gastos, compras, proveedores, entregas, inventario y novedades de la plataforma.

### Dónde verlo

- La campana de arriba muestra cuántos avisos tienes sin leer. También está en el menú → «Centro de seguimiento».

### Lo que vigila

1. Cotizaciones sin respuesta y por vencer.
2. Ventas sin cobrar o sin decir si se facturan.
3. Gastos por pagar y sin comprobante fiscal.
4. Facturas de gasto con pago en parcialidades (PPD) o con el método de pago por definir.
5. Compras: por recibir, con la factura del proveedor pendiente y con saldo por pagar; y pedidos directos a proveedor sin pagar.
6. Entregas y cambios por entregar.
7. Productos y materias primas por debajo de su mínimo (los que ya no tienen nada son los más urgentes).

### Prioridades

- Urgente, alta, normal e informativa, calculadas con fechas y estados reales: lo más urgente sale primero.

### Abrir y marcar

1. «Abrir registro» te lleva a la venta, la compra, el gasto o el producto de origen.
2. En un aviso de factura con método de pago por definir o en parcialidades, «Definir método de pago» permite elegir PUE, PPD o por definir sin salir de ahí.
3. «Marcar como leído» solo oculta el aviso de tu lista: no cambia ninguna venta, cobro ni factura. Si la operación cambia (por ejemplo, un pago parcial), el aviso vuelve a aparecer como nuevo.

> **Consejos**
> - Una venta pendiente de cobro y una pendiente de factura son avisos distintos: una venta ya facturada no aparece como «factura por definir».
> - Una compra que ya mandaste a contabilidad aparece como «Compra por pagar» y también como «Gasto por pagar»: son dos pantallas distintas del mismo dinero y ninguna se deduce de la otra.

---

## 22. Acerca de Acero 360

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

## 23. Palabras que verás

Qué significan, en pocas palabras.

### Glosario

- IVA: impuesto de 16 % que cobras en las ventas y que pagas en tus compras; al SAT le entregas la diferencia.
- ISR: impuesto sobre tu ganancia (lo que cobras menos lo que pagas).
- CFDI / factura: el comprobante fiscal digital. Viene en PDF (para leer) y XML (para la computadora).
- UUID o folio fiscal: el número único de cada factura.
- Deducible: un gasto que puedes restar de tus ventas para pagar menos ISR. Necesita factura y estar pagado.
- Subtotal: precio antes de IVA. Total: precio con IVA.
- Pago provisional: el pago mensual a cuenta del ISR del año.
- Cerrar el mes: congelar las cifras de un mes para que nadie las cambie por accidente.
- Flujo de efectivo: contar las ventas cuando te pagan y los gastos cuando los pagas.

