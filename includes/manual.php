<?php
/**
 * includes/manual.php — Contenido del manual de usuario. Una sola fuente para la página /admin/ayuda
 * y para MANUAL_USUARIO.md (se regenera con: php scripts/generar_manual.php).
 *
 * Cada sección: ['id', 'titulo', 'icono', 'intro', 'pasos' => [[titulo, [pasos...]]...], 'consejos' => [...]]
 */
return [
    ['id' => 'inicio', 'titulo' => 'Empezar', 'icono' => 'rocket_launch',
     'intro' => 'Acero 360 sirve para cotizar, vender, entregar, llevar tus gastos y saber más o menos cuánto vas a pagar de impuestos. Todo se hace desde el menú de la izquierda (en el celular, desde el botón de abajo a la derecha).',
     'pasos' => [
        ['Entrar', ['Abre la página e inicia sesión con tu correo y contraseña.', 'Si olvidaste la contraseña, cámbiala en «Usuarios y perfil».']],
        ['El panel', ['Arriba están «Ventas por negocio»: cada tarjeta trae las ventas que se hicieron en el periodo, con su IVA y con cuántas ventas, y «Ver ventas» abre ese listado.', 'Debajo va «Impuestos», con el preliminar del periodo y los impuestos por pagar en la misma tarjeta; «Ver preliminar» abre el desglose mes por mes.', '«Pendientes de atender» junta las cuatro listas del día: ventas por cobrar o por facturar (con el nombre o alias del cliente y el importe con IVA), gastos por pagar (con su nombre y su importe con IVA), ventas por entregar (con su botón de «Seguimiento») y incidencias de logística o entrega (con su botón de «Atender»).', '«Productos más vendidos» trae los 4 que más vendiste en el mes, con su código, su foto y el importe de venta; «Productos con existencias bajas» avisa cuáles están agotados, con existencia negativa o por debajo de su mínimo (los más urgentes primero). El número de la existencia sale en rojo cuando ya no queda nada y en naranja cuando quedan pocas.', '«Últimos documentos» lista las últimas 5 cotizaciones y las últimas 5 ventas, cada una con su botón de PDF; y al final «Indicadores del periodo» resume ventas, cotizaciones, cobros, facturación, entregas, devoluciones, gastos y avisos, cada uno abriendo los registros que lo forman.', 'Cada tarjeta de producto abre su ficha, y las de existencias te llevan al inventario con el producto listo para ajustarlo.']],
        ['Moverte por el menú', ['«Nueva cotización», «Histórico», «Clientes» y «Productos» son lo de todos los días.', 'Debajo de la línea están Operación Comercial, Contabilidad, Impuestos, Inventario y Compras y proveedores (un solo apartado). Operación Comercial reúne en un solo lugar la preparación de pedidos, el seguimiento de entregas y las ventas (pestañas arriba).']],
        ['Burbujas de ayuda', ['Deja el cursor sobre cualquier botón, pestaña o campo y, medio segundo después, aparece una burbuja que explica qué hace.', 'Con teclado funciona igual al llegar con la tecla Tab. Escape la cierra.']],
     ],
     'consejos' => ['Si algo no se ve actualizado, recarga la página con Ctrl + Shift + R.']],

    ['id' => 'clientes', 'titulo' => 'Clientes y productos', 'icono' => 'group',
     'intro' => 'Antes de cotizar necesitas a quién y qué ofrecer.',
     'pasos' => [
        ['Dar de alta un cliente', ['Clientes → «Nuevo cliente». Escribe su nombre y, si quieres, correo, teléfono y RFC.', 'Guarda. Ya aparece al cotizar.']],
        ['Dar de alta un producto', ['Productos → «Nuevo producto». Pon nombre, precio de lista, unidad y una foto.', 'Las existencias (cuántas piezas tienes) se manejan en Inventario.']],
     ],
     'consejos' => ['Un producto inactivo no se puede cotizar ni vender, pero se conserva su historial.']],

    ['id' => 'cotizaciones', 'titulo' => 'Cotizaciones', 'icono' => 'request_quote',
     'intro' => 'Una cotización es el precio que le das a un cliente antes de que compre.',
     'pasos' => [
        ['Crear una cotización', ['«Nueva cotización». Elige el negocio que la emite y el cliente.', 'Agrega productos del catálogo con su cantidad (puedes cambiar el precio o poner descuento).', 'Guarda. El PDF se descarga con el botón «PDF» del detalle y sale con el logotipo del negocio.']],
        ['Cuando el cliente acepta', ['Cambia su estado a «Vendido». Eso crea la venta, descuenta del inventario y registra el ingreso en Contabilidad.', 'Si el cliente no acepta, márcala «Cancelada».']],
     ],
     'consejos' => ['Estados: Pendiente (esperando respuesta), Vendido (ya es venta), Cancelado.', 'Para repetir una cotización parecida usa «Duplicar».']],

    ['id' => 'ventas', 'titulo' => 'Operación Comercial', 'icono' => 'point_of_sale',
     'intro' => 'Un solo apartado para vender, preparar, entregar y dar seguimiento: arriba tiene cuatro pestañas —Preparación de pedidos, Entregas por cliente, Seguimiento de entregas y Ventas— y cada venta tiene su propia hoja de seguimiento.',
     'pasos' => [
        ['Anotar una venta directa', ['Operación Comercial → pestaña «Ventas» → «Nueva venta». Elige negocio, cliente, fecha y los productos (el cliente se busca escribiendo: nombre, empresa, alias o RFC).', 'Al guardar se descuenta el inventario y nace el ingreso en Contabilidad.']],
        ['Ver lo que hay que entregar', ['Pestaña «Preparación de pedidos» → marca los pedidos que quieras (o «seleccionar todas») y pulsa «Mostrar en pantalla».', 'Verás juntos los productos agrupados (por ejemplo 5 + 2 = 7 piezas del mismo producto) y los pedidos por cliente. No hay fases que controlar: es solo para ver y alistar.', 'Si quieres volver a abrir esa selección, «Guardar esta vista»; aparece en «Vistas guardadas».']],
        ['Registrar una entrega', ['Desde la hoja de seguimiento de la venta pulsa «Registrar entrega» (se abre en ventana modal): escribe cuántas piezas entregaste, puede ser parcial, y confirma. Lo que quede en cero sigue pendiente.', 'Si quieres dejar constancia, adjunta una o varias FOTOS DEL ACTA (JPG, PNG o WebP, hasta 6 MB cada una y un máximo de 8): se guardan con la entrega y se ven en la hoja de seguimiento, en la sección «Fotos de la entrega», donde se abren en una ventana modal y se cierran con el botón X.']],
        ['Seguir una venta (incidencias y devoluciones)', ['En el detalle de la venta, «Operación de esta venta» abre su hoja: estado, productos vendido vs. entregado, incidencias, facturación, devoluciones e historial — todo en un lugar.', '«Producto faltante» o «incompleto» (p. ej. se vendieron 10 y se entregaron 8): las piezas que faltan REGRESAN AL INVENTARIO y, si ya hay factura, hay que cancelarla y subir la correcta sólo por lo entregado.', '«Producto dañado», «cambio» o «devolución»: sólo quedan notificados, NO mueven el inventario; el sistema lleva la cuenta de lo que se le debe al cliente y lo marca como entregado cuando lo entregues.', 'Si hay que corregir la factura, se hace desde la misma incidencia: se dan de baja los documentos actuales y los nuevos traen folio, UUID, fecha y montos a contabilidad (si el cobrado quedó de más, se avisa).']],
     ],
     'consejos' => ['Se puede vender aunque el inventario esté en cero: queda en negativo y se corrige al recibir mercancía.', 'La factura manda en contabilidad: si la venta y la factura quedaron con importes distintos, la hoja de seguimiento y el listado de ingresos lo marcan.']],

    ['id' => 'inventario', 'titulo' => 'Inventario', 'icono' => 'inventory_2',
     'intro' => 'Cuántas piezas tienes de cada producto y por qué cambió.',
     'pasos' => [
        ['Ver existencias', ['Inventario lista cada producto con su cantidad. Cada venta, compra recibida o ajuste deja un movimiento con su motivo.']],
        ['Corregir una cantidad', ['Haz un ajuste e indica el motivo (por ejemplo «conteo físico»). Siempre queda registrado quién y cuándo.']],
     ],
     'consejos' => ['Puedes descargar el inventario en PDF o Excel.']],

    ['id' => 'compras', 'titulo' => 'Compras y proveedores', 'icono' => 'shopping_cart',
     'intro' => 'Es un solo módulo con ocho pestañas: Resumen, Compras y pedidos, Recepciones, Proveedores, Catálogo, Facturas, Pagos y Categorías. Una compra se captura una sola vez y desde ahí se recibe, se factura y se paga, sin repetir datos en Contabilidad.',
     'pasos' => [
        ['Dar de alta un proveedor', ['Pestaña «Proveedores» → «Nuevo proveedor». Un proveedor puede venderte distintas cosas: el tipo de compra se elige en cada compra, no en el proveedor.', 'Un proveedor inactivo ya no aparece en compras nuevas, pero su historial se conserva. Si tiene compras no se puede eliminar: desactívalo.']],
        ['Catálogo de semiterminados por proveedor', ['Pestaña «Catálogo» → «Nuevo producto»: elige el proveedor y captura nombre, descripción, código y costo unitario de compra. Un proveedor puede tener muchos; el mismo artículo puede existir en otro proveedor con otro costo.', 'No se repiten nombre ni código dentro del mismo proveedor. No se borran: «Desactivar» deja de ofrecerlos en compras nuevas y se conservan en el historial (puedes reactivarlos).', 'Opcional: en la ficha del producto, «Relacionar» con tus productos propios, para saber qué semiterminado de qué proveedor se usa en cada uno (también se ve desde la edición del producto propio). Son cosas distintas: cada uno conserva su código y costo.', 'Cambiar el costo del catálogo no altera compras anteriores y queda en la bitácora.', 'Se puede llenar desde una factura: en «Compras inteligentes» (y en el formulario de gasto), la lista «Conceptos sin producto equivalente» trae el botón «Agregar al proveedor» y da de alta el renglón ahí mismo con el precio del documento.']],
        ['Comprar producto semiterminado para vender', ['«Nueva compra» → tipo «Producto semiterminado» → elige primero al proveedor. Cada partida ofrece solo los productos activos del catálogo de ese proveedor: al elegir uno se llenan solos el nombre, el código y el costo. Solo escribes la cantidad; el importe de cada partida (cantidad × costo) y el total se calculan solos.', 'Si el producto está relacionado con un único producto propio, se propone también. Si no lo está, elige a cuál corresponde o déjalo para después en el detalle de la compra. El nombre y el precio de venta de tu catálogo no cambian.', 'El costo viene bloqueado. Si de verdad esta vez pactaste otro precio, usa «Cambiar costo»: queda el precio final usado, se avisa cuál era el del catálogo y queda en la bitácora.', 'El IVA no se calcula solo: captúralo, o usa «Calcular 16 % del subtotal» y corrígelo si hace falta. El total de la compra es el subtotal más ese IVA.', '¿El producto no está en el catálogo? Elige «Otro / fuera del catálogo» y escríbelo, o agrégalo antes al catálogo.', 'Cada compra guarda una copia del nombre, código y costo usados: si luego cambias el costo en el catálogo o desactivas el producto, las compras anteriores no se alteran.']],
        ['Comprar materiales, insumos y consumibles', ['«Nueva compra» → tipo «Material, insumo o consumible» (pintura, thinner, discos, guantes, soldadura, varilla, lámina…).', 'No necesitas crear productos. Si es un consumible, déjalo en «Consumible (sin inventario)». Si quieres llevar existencias (varilla, lámina), elige «Controlar en inventario» y escribe el nombre del material: queda aparte de los productos para venta.']],
        ['Recibir la mercancía', ['Detalle de la compra → «Registrar recepción», o pestaña «Recepciones». Captura lo que realmente llegó; puedes recibir por partes.', 'Pedir no cambia las existencias: solo la recepción confirmada. Reenviar el formulario no suma dos veces.', 'Lo recibido de un semiterminado queda «por terminar» y todavía no se puede vender. Cuando lo termines, en «Recepciones → Semiterminados por terminar» usa «Pasar a disponibles». Si llegó ya terminado, márcalo al recibir.', 'No se acepta más de lo pendiente, salvo que marques «Llegó más de lo pendiente» (queda en la bitácora).']],
        ['Factura (CFDI) y comprobantes', ['En el detalle de la compra, «Factura y comprobantes» → adjunta el XML del CFDI, el PDF o una foto. La compra se envía a Contabilidad una sola vez como un gasto; si la factura llega después, se asocia al mismo gasto.', 'Solo un XML con estructura de CFDI y timbre la deja «Con CFDI». Un PDF o una foto no cuentan como CFDI. Si no hubo factura, marca «Sin factura».', 'Adjuntar un documento no vuelve deducible la compra: queda «por validar» para tu contador. El mismo UUID no se puede usar en dos compras.']],
        ['Pagos al proveedor', ['En el detalle de la compra, «Pagos al proveedor» → registra el pago, completo o parcial. Reduce el saldo pendiente y no crea otro gasto.', 'Si te equivocas, «Cancelar pago» con motivo: el pago queda en el historial y el saldo se recupera.']],
        ['Ver pendientes y reportes', ['«Resumen» muestra lo que falta recibir, las piezas por terminar, las facturas pendientes y lo que debes. «Compras y pedidos» filtra por proveedor, fechas, categoría, tipo, recepción, factura y pago.', 'El expediente de cada proveedor (pestaña «Proveedores» → su nombre) reúne sus compras, recepciones, facturas, pagos y saldos.']],
        ['Corregir', ['Una compra se edita o cancela mientras no tenga recepciones, pagos ni gasto vigente. Para corregir existencias usa «Corregir cantidad» en «Recepciones»: pide una razón y queda en la bitácora.']],
        ['Historial anterior', ['Las «entregas anteriores» con foto que se registraban antes solo se muestran, como historial, en el expediente del proveedor que las tenga. Las compras nuevas se hacen con «Nueva compra».']],
     ],
     'consejos' => ['El costo de cada compra queda guardado tal como se compró: si cambia el costo actual de un producto, las compras anteriores no se alteran.', 'Actualizar el costo actual del catálogo es una opción aparte al recibir; nunca cambia el precio de venta.', 'El IVA de la compra no se calcula: captúralo solo si lo conoces; si lo dejas vacío queda como «no especificado».']],

    ['id' => 'gastos', 'titulo' => 'Anotar un gasto y subir su factura', 'icono' => 'receipt',
     'intro' => 'Un gasto es cualquier cosa que pagas para trabajar: material, gasolina, renta, honorarios.',
     'pasos' => [
        ['Las 6 preguntas', ['Contabilidad → Gastos → «Anotar un gasto».', '¿En qué fue? · ¿A quién le pagaste? · ¿Cuándo fue? · ¿Cuánto pagaste en total (con IVA incluido)? · ¿Ya lo pagaste? · ¿Te dieron factura?', 'La plataforma separa sola el subtotal y el IVA. Si no tiene IVA, elige «No tiene IVA».']],
        ['¿A quién le pagaste? (proveedores)', ['En «Pagado a» elige «Proveedor» y escribe en el campo de búsqueda: filtra tu directorio por nombre o por RFC y muestra los dos datos en la lista. Al elegirlo, el campo queda con el nombre y el RFC del proveedor y el gasto queda ligado a esa ficha.', 'Si lo que escribes no corresponde a ningún proveedor, aparece el botón «¿No existe…? Regístralo». Te lleva al alta con ese nombre y con el RFC de la factura precargados y, al guardarlo, vuelves al gasto con todo lo que ya habías capturado y el proveedor nuevo ya elegido (los archivos adjuntos sí hay que volver a subirlos: el navegador no los conserva).', 'Si eliges «Estación de servicio» u «Otro», escribes a mano a quién pagaste; esos gastos no quedan ligados a una ficha del directorio.']],
        ['Conceptos sin producto equivalente', ['Si la factura la leíste con «Registro compra inteligente», arriba del formulario aparece la lista de renglones que NO coinciden con tu catálogo. Nada se ha guardado todavía.', 'Cuando el que emitió la factura ya es tu proveedor, cada renglón trae el botón «Agregar al proveedor»: da de alta ese producto en EL CATÁLOGO DE ESE PROVEEDOR con el nombre, el código (SKU) y el precio de la factura, sin salir de la pantalla (no se pierde lo que ya capturaste).', 'La próxima vez que leas una factura de esa misma persona, esos renglones ya se reconocen solos y dejan de aparecer en la lista. Si ya existía, el botón lo dice y no lo duplica.', 'El otro botón, «Buscar en el catálogo», sigue ahí para ligar el renglón a un producto propio si también lo vendes.']],
        ['Subir la factura', ['Pulsa «Archivo de la factura o comprobante», de la misma sección: se abre una ventana —igual que en «Registro Venta Inteligente»— donde eliges uno o varios archivos (PDF, XML, JPG, PNG o WebP). Se detecta solo qué es cada uno.', 'También puedes subirlos después desde el detalle del gasto, en «Facturas y comprobantes».', 'Si subes el XML de la factura, se leen sus datos (folio fiscal, importes) y avisa si algo no cuadra.']],
        ['Los cuatro datos del CFDI', ['Cuando el gasto es «Con factura», aparecen cuatro campos: «Uso del CFDI» (para qué se usó: adquisición de mercancías, gastos en general, mobiliario…), «Método de pago del CFDI», «Forma de pago del CFDI» (efectivo, transferencia, tarjeta…) y «Tipo de comprobante (CFDI)» (una factura normal es «Ingreso»).', 'El método de pago tiene tres opciones: «PPD · Pago en parcialidades», «PUE · Pago en una sola exhibición» y «Por definir (aún no se decide)», que es la que queda si todavía no sabes cómo se pagará.', 'Si el gasto es «Sin factura», esos cuatro campos no se guardan: no hay comprobante del que hablar.', 'Los cuatro se autocompletan con los que trae la factura —sube el XML en el mismo formulario o usa «Registro compra inteligente»— y se pueden corregir a mano. Si el documento no trae alguno, queda vacío para que lo captures.']],
        ['Más detalles', ['«Más detalles (opcional)» tiene categoría, cómo pagaste, folio fiscal, repartir entre negocios y notas. No hace falta llenarlo.']],
        ['Pagarlo después', ['Si lo dejaste como «lo debo todavía», ábrelo cuando lo pagues y cámbialo a pagado con su fecha.']],
        ['Que la plataforma lea la factura', ['Gastos → «Leer factura» (o desde una venta, «Leer factura»). Sube el XML (lo mejor: datos exactos), el PDF o una foto.', 'Aparece una pantalla de revisión con lo que se leyó, qué tan seguro es cada dato y el aviso de discrepancias o duplicados. Corrige lo necesario y marca «Revisé este dato» en lo dudoso.', 'Nada se guarda hasta que pulsas «Confirmar datos». Puedes asociar el documento a un gasto o venta que ya existe (sin duplicarlo) y queda registrado quién confirmó y cuándo.', 'El folio de la factura (p. ej. A-123) y el UUID son datos distintos. Si no se puede leer el archivo, captura los datos a mano en la misma pantalla.']],
     ],
     'consejos' => ['Solo los gastos ya pagados se restan de tus impuestos; el archivo o el CFDI adjunto es el respaldo para tu contador.', 'Un ticket sin factura se puede guardar: la estimación lo resta si está pagado, pero tu contador decide si es deducible.', 'Si eliminas un gasto por error, tiene 90 días en Ajustes → Papelera y se puede restaurar con todos sus datos (sin los archivos adjuntos: esos sí se borraron).', 'Máximo por archivo: el tamaño que indica la pantalla.']],

    ['id' => 'cobros', 'titulo' => 'Facturación y cobro de una venta', 'icono' => 'payments',
     'intro' => 'Cada venta genera automáticamente un ingreso. Toda la facturación y los cobros de esa venta se indican en una sola sección: «Facturación y cobro», arriba del detalle de la venta.',
     'pasos' => [
        ['Anotar un pago del cliente', ['Abre la venta (Ventas → su folio; también desde Contabilidad → Ingresos → «Abrir», desde los avisos del panel o desde «Pendientes de facturar») y busca «Facturación y cobro».', 'En «Pago»: pulsa «Ya me pagaron todo» si ya te pagaron completo, o despliega «Anotar un pago parcial» y escribe cuándo, cuánto y cómo te pagaron. Si paga en partes, anota cada pago: queda «Pago parcial» hasta completar.']],
        ['Decir si se facturó', ['En esa misma sección, «¿Ya se facturó?» → elige el estado: Pendiente de facturar, Facturada o No facturada.', 'Si hay factura, despliega «Folio, UUID y fecha (opcional)» y escribe el folio y el UUID (o súbelos: el XML los llena solos).']],
        ['Revisar y facturar', ['El botón «Revisar y facturar» abre la revisión previa con los datos fiscales del receptor y con método, forma de pago, uso y tipo de comprobante. Si la venta nació de «Registro Venta Inteligente» (subiste la factura que emitiste), esos cuatro campos vienen precargados con los que trae el CFDI; revísalos antes de continuar.', 'La validación previa te avisa qué falta y qué no cuadra; no se factura hasta que no quede ningún error obligatorio.']],
        ['Subir la factura de la venta', ['Pulsa «Subir factura (PDF y/o XML)», de la misma sección: se abre una ventana —igual que en «Registro Venta Inteligente»— donde eliges el PDF y el XML juntos; después pulsa «Guardar facturación».', 'Los archivos quedan listados en «Facturas y comprobantes», con su validación documental; ahí también se descargan o se dan de baja.']],
        ['Pendientes de facturar', ['La pestaña «Pendientes de facturar» lista las ventas sin factura para que no se te pase ninguna y su botón «Clasificar» abre esta misma sección. Se puede exportar.']],
        ['Ver todas tus ventas', ['Contabilidad → «Ventas y cobros» muestra DOS tablas: arriba las ventas con factura o pendientes de facturar, y abajo las que ya marcaste como «No facturada» (esas ya no se van a facturar).', 'Cada tabla tiene su propio total y se pagina por su lado; los filtros de arriba (búsqueda, facturación y periodo) aplican a las dos.', 'El interruptor de la columna «Facturación» mueve una venta de una tabla a la otra: si un día llega la factura, márcala como facturada y sube a la tabla de arriba.']],
     ],
     'consejos' => ['Cancelar un pago o una venta pide un motivo y queda registrado; nada se borra en silencio.', 'Facturar y cobrar son cosas distintas: una venta puede estar facturada sin cobrarse, y al revés.']],

    ['id' => 'resumen', 'titulo' => 'Resumen del mes y cierre', 'icono' => 'dashboard',
     'intro' => 'La pantalla principal de Contabilidad te dice, sin tecnicismos, cómo vas.',
     'pasos' => [
        ['Leer el resumen', ['«Vendiste», «Gastaste» y «Te queda» (ventas menos gastos, antes de impuestos).', '«Lo que te falta hacer» te avisa de ventas sin cobrar, sin indicar si se facturaron, gastos por pagar y gastos sin factura; cada aviso tiene un botón para ir a resolverlo.', '«Ver el detalle completo» muestra el IVA y el desglose por negocio.']],
        ['Cambiar de mes o negocio', ['Arriba elige año, mes y negocio y pulsa «Ver periodo».']],
        ['Más opciones', ['Descarga las facturas del mes en un ZIP para tu contador, cierra el mes o consulta los meses cerrados.']],
        ['Cerrar el mes', ['Cuando el mes está completo, «Revisar y cerrar el mes». La plataforma verifica pendientes y, al cerrar, nadie puede cambiar ventas, gastos ni archivos de ese mes sin reabrirlo (con motivo y contraseña).']],
     ],
     'consejos' => ['Cierra el mes después de presentar tu declaración.']],

    ['id' => 'impuestos', 'titulo' => 'Impuestos (solo informativo)', 'icono' => 'request_quote',
     'intro' => 'Te muestra, de forma aproximada, cuánto deberías separar este mes para el SAT según lo que vendiste y gastaste.',
     'pasos' => [
        ['Leer la cifra', ['Impuestos → arriba: «deberías separar aproximadamente $X», con el IVA y el ISR por separado.', 'Solo cuenta las facturas que ya te pagaron al 100 % y los gastos ya pagados.', 'Una factura en parcialidades o sin cobrar, o un gasto por pagar, NO se cuenta hasta que se confirme el pago: la misma pantalla te dice cuántos quedaron fuera y por cuánto.']],
        ['Qué significa el método de pago', ['PUE (una sola exhibición), PPD (parcialidades) y «por definir» son informativos: no cambian la cuenta, solo explican por qué una operación entra o no. Un gasto PPD entra cuando queda pagado por completo.', 'Los archivos que subes en ventas y gastos (XML, PDF o fotos) son solo respaldo para consultar y descargar: no modifican los importes ni el cálculo.']],
        ['Para tu contador', ['«¿Cómo se calcula?» muestra paso a paso la cuenta, con el IVA real de cada CFDI, y «Comparar con lo que declaró mi contador» te deja anotar lo declarado.']],
     ],
     'consejos' => ['Es una estimación: no sustituye la declaración. Compárala siempre con tu contador.', 'Los pagos provisionales se presentan a más tardar el día 17 del mes siguiente.', 'Facturar y cobrar siguen siendo cosas distintas: una factura emitida se cuenta cuando ya te la pagaron.']],

    ['id' => 'estadisticas', 'titulo' => 'Estadísticas', 'icono' => 'monitoring',
     'intro' => 'Cómo van tus ventas, cobros, facturas y gastos, con indicadores y gráficas interactivas.',
     'pasos' => [
        ['Ver tus números', ['Arriba elige «Este mes», «Este año» o «Año pasado», o usa los filtros de periodo y negocio y pulsa «Ver estadísticas».', 'Ventas realizadas, cobros recibidos, ventas facturadas y gastos son cosas distintas: vender es cuando haces la venta, cobrar cuando te pagan y facturar cuando das factura.', 'Cada indicador dice cuánto subió o bajó (en pesos y en %) contra el mismo periodo del año anterior; en gastos, bajar es lo bueno.']],
        ['Las gráficas', ['Ventas por mes, año contra año y por semestre; ingresos acumulados; ventas contra cobros; cobrado y saldo pendiente; facturadas y pendientes de facturar; gastos por año y categoría; comparación entre negocios; productos de mayor y menor movimiento y clientes.', 'Productos y clientes salen SEPARADOS por negocio: cada negocio tiene su propia gráfica y sus propias filas, y cada producto trae su código (SKU) antes del nombre; en «Ventas por negocio» también ves los productos de cada negocio con su código.', 'Pasa el cursor para ver la cifra exacta y la variación contra el año anterior; toca un nombre de la leyenda para ocultar o mostrar esa serie; el icono de la esquina guarda la gráfica como imagen.']],
        ['Filtros y descargas', ['«Más filtros» permite elegir un cliente o un producto y cuántos años comparar.', '«Ver las tablas con todos los números» muestra el detalle de cada gráfica. Arriba descargas PDF (con gráficas), Excel (una hoja por gráfica, con la gráfica dentro) o CSV; siempre salen con el periodo y los filtros que elegiste.', 'El PDF trae además tres secciones que la pantalla no muestra: los productos más vendidos del periodo (con su código, su foto y el importe), los de existencias bajas (el número en rojo si ya no queda nada y en naranja si quedan pocas) y la tabla de productos menos vendidos del periodo, por negocio y de menos a más piezas.']],
     ],
     'consejos' => ['Si el periodo no ha terminado, la comparación usa el mismo avance del año anterior para ser justa.']],

    ['id' => 'notas', 'titulo' => 'Notas personales', 'icono' => 'sticky_note_2',
     'intro' => 'La libreta del equipo: recordatorios, pendientes por cotizar, cambios y revisiones, en un solo lugar.',
     'pasos' => [
        ['Escribir una nota', ['Menú → «Notas personales» → «Nueva Nota».', 'Ponle título, escribe el contenido y elige una categoría: Recordatorio, Cotizar, Cambios, Revisar u Otro.', 'Si quieres un aviso, escribe en «Fecha y recordatorio» algo como «Mañana 9:00 AM». También puedes asociarle un cliente en «Referencia».']],
        ['Buscarlas y ordenarlas', ['Arriba hay un buscador por palabra, cliente o etiqueta, y chips para filtrar: Todas, Fijadas, Recordatorios, Cotizar, Cambios, Revisar y Otro.', 'Las tarjetas de arriba cuentan cuántas hay de «Notas Activas», «Fijadas» y «Por cotizar».', 'La lista trae 12 por página; las fijadas siempre salen arriba.']],
        ['Trabajar con ellas', ['Cada nota tiene «Editar», «Fijar» (la sube al principio), «Marcar como hecha» (después puedes «Reabrir») y «Eliminar», que pregunta antes.', 'Cada tarjeta dice quién la escribió y cuándo («Hoy, 10:42 AM», «Ayer», «18 Oct»).']],
     ],
     'consejos' => ['Las notas son del equipo: lo que escribe uno lo leen todos los administradores.', 'El título puede tener hasta 200 caracteres y el texto de recordatorio 80; es un recordatorio escrito, la plataforma no manda correos.']],

    ['id' => 'calendario', 'titulo' => 'Calendario', 'icono' => 'calendar_month',
     'intro' => 'Un calendario del mes que junta dos cosas: lo que pasó en tus negocios cada día y los recordatorios y eventos del equipo.',
     'pasos' => [
        ['Moverte por el mes', ['Menú → «Calendario». Arriba están los botones ‹ › para cambiar de mes y «« »» para cambiar de año, más el botón «Hoy» para regresar al día de hoy.', 'El día de hoy viene marcado con un borde azul.']],
        ['Los marcadores del negocio', ['En cada casilla aparecen iconos grandes y macizos con lo que pasó ese día: en VERDE una venta, en ROJO un gasto, en AZUL una recepción de proveedor, en AMARILLO una factura de proveedor por pagar y en NARANJA una factura de venta por cobrar.', 'Pon el cursor sobre el icono para ver cuántas operaciones de ese tipo hay. Debajo del calendario está la leyenda con los cinco colores.', 'Son de SÓLO LECTURA: no se pueden editar ni borrar, porque son el reflejo de las operaciones ya registradas en Ventas, Gastos, Compras y Contabilidad. Un gasto deja de estar amarillo en cuanto lo marcas pagado, y una factura deja de estar naranja en cuanto se cobra por completo.']],
        ['La Agenda del mes', ['Debajo del calendario está la «Agenda»: un listado, día por día y en orden, de todos los días con movimiento. En cada tarjeta ves la fecha, los marcadores con sus cifras (cuántas ventas y por cuánto, los gastos, las recepciones, lo que queda por pagar y por cobrar) y los recordatorios capturados ese día.', 'El botón con forma de ojo («Ver») abre en una ventana el detalle completo de ese día.', 'Sólo aparecen los días que tienen algo: operaciones o recordatorios.']],
        ['Ver el detalle de un día', ['Haz clic en el botón «Ver» de la tarjeta de ese día en la Agenda. Se abre una ventana con la fecha y cada tipo de marcador con su cantidad y su importe; debajo aparece el listado de recordatorios y eventos de ese día, cada uno con su icono, su nombre y un botón de ojo para ver su ficha completa.', 'En la ficha del evento ves su importancia, hora, negocio, autor y descripción; puedes volver al listado del día o pasar a «Editar evento». El botón «Ver en el calendario» te lleva a esa fecha.']],
        ['Recordatorios y eventos', ['Para crear uno, haz clic una SEGUNDA vez sobre la casilla del día que ya está seleccionada (la primera vez sólo la selecciona): se abre una ventana con el formulario. También puedes usar el botón «Nuevo recordatorio o evento» de arriba.', 'La ventana trae la fecha ya cargada con el día elegido: nombre, hora opcional, descripción, negocio e importancia (Bajo, Medio o Alto). Pulsa «Guardar»; si falta algo, la ventana se queda abierta y avisa debajo del campo.', 'Los eventos se listan en su tarjeta de la Agenda con puntos de color según su importancia: verde Bajo, amarillo Medio, rojo Alto. Cada uno se puede editar o eliminar; la baja pide confirmación.']],
     ],
     'consejos' => ['Lo que se vende, se gasta o se recibe se marca solo: no hay que capturarlo dos veces en el calendario.', 'Los recordatorios son del equipo: lo que registra uno lo leen todos los administradores.', 'El calendario no manda correos ni avisos: es una pantalla para consultar.', 'Los archivos que subes a ventas y gastos son sólo respaldo: no cambian las cifras que ves aquí.']],

    ['id' => 'redactor', 'titulo' => 'Redactor', 'icono' => 'edit_document',
     'intro' => 'El procesador de textos de la plataforma: cartas, oficios, memorias, actas y procedimientos con formato profesional.',
     'pasos' => [
        ['Crear un documento', ['Menú → «Redactor» → «Nuevo documento». Dale nombre, fecha y elige carpeta.', 'Elige el corrector ortográfico (Español, English o ambos), el tamaño de hoja (Carta, A4, Oficio o Tabloide), la orientación y una plantilla.', 'Plantillas incluidas: Oficio/carta, Minuta, Acta de entrega-recepción y Lista de verificación. Pulsa «Crear y abrir».']],
        ['Escribir con formato', ['Las pestañas de arriba son Inicio, Insertar, Diseño, Revisión y Herramientas.', 'En «Insertar» hay tabla, imagen, figura, dibujo, vínculo, salto de página, índice, nota al pie, leyendas numeradas, referencia cruzada, ecuación, portada y bloques de obra (acta, minuta, bitácora, orden de cambio, lista de asistencia).', 'Guarda con «Guardar»; también se guarda solo cada 30 segundos.']],
        ['Control de cambios y firmas', ['En «Revisión» puedes activar cambios (control de versiones): al final, «Aceptar todos» o «Rechazar todos». Los comentarios tienen su propio panel.', '«Versiones» guarda una copia automática cada 10 minutos y conserva las últimas 40.', 'En «Firmas» guardas tu firma manuscrita (hasta 5 por usuario) o firmas electrónicas con contraseña; se insertan en bloques de 1 a 4 firmantes.']],
        ['Descargar', ['Botón «Descargar» → Word (.docx), LibreOffice (.odt), PDF o Imprimir.', 'Al exportar puedes marcar «Mostrar cambios marcados», «Incluir comentarios al final» e «Incluir firmas electrónicas».']],
     ],
     'consejos' => ['Los documentos son compartidos: cualquiera de los administradores puede abrirlos y editarlos. Lo único tuyo son tus firmas dibujadas y tu diccionario del corrector.', 'Puedes insertar una tabla de SheetFlow y esta se actualizará sola; los dibujos que hagas en el Editor también se pueden insertar como imágenes.']],

    ['id' => 'sheetflow', 'titulo' => 'SheetFlow', 'icono' => 'table_chart',
     'intro' => 'La hoja de cálculo en línea para controles, presupuestos y listados que después puedes meter en un documento.',
     'pasos' => [
        ['Crear una tabla', ['Menú → «SheetFlow» → «Nueva tabla». Dale nombre y fecha.', 'Elige cómo empezar: «En blanco» (4 columnas × 10 renglones), «Tabla inteligente» (tú diseñas las columnas), la plantilla «Presupuesto por conceptos» o «importar un archivo» (.sheetflow, .xlsx, .ods o .csv).']],
        ['Llenarla', ['Cada columna tiene un tipo: Texto, Número, Moneda, Porcentaje, Fecha, Lista desplegable, Casilla (sí/no) o Semáforo.', 'Puedes copiar y pegar directamente desde Excel (Ctrl+C, Ctrl+V).', 'Abajo se suman los totales (suma, promedio, mínimo, máximo o cuenta) y se puede ordenar de A a Z o de Z a A.']],
        ['Fórmulas', ['Escribe «=» para empezar una fórmula. Sirven las operaciones básicas (+ − * / ^) y estas funciones: SUMA, PROMEDIO, MIN, MAX, CONTAR y REDONDEAR.', 'Y estas condicionales: SI, Y, O, NO, SI.ERROR, SUMAR.SI, CONTAR.SI y PROMEDIO.SI.']],
        ['Descargar', ['«Descargar» → Excel (.xlsx), CSV, SheetFlow (.sheetflow), PDF o Imprimir.', 'El archivo .sheetflow guarda la tabla completa para volver a importarla después.']],
     ],
     'consejos' => ['Límites: 30 columnas por 1 000 renglones, 500 caracteres por celda, hasta 20 opciones en una lista desplegable y 2 MB al importar un archivo (se usa la primera hoja y la primera fila son los encabezados).', 'Las tablas son compartidas: todos los administradores las ven y las editan.', 'Desde aquí el botón «Crear un documento con esta tabla» la manda al Redactor.']],

    ['id' => 'editor', 'titulo' => 'Editor', 'icono' => 'design_services',
     'intro' => 'El editor de dibujo por páginas: planos, croquis, diagramas, portadas y fichas técnicas.',
     'pasos' => [
        ['Crear un documento', ['Menú → «Editor» → «Nuevo documento». Dale nombre, elige el tamaño de página (Carta, Oficio, Legal, Tabloide, A4, A3, A5 o Personalizado), la orientación y el fondo (Liso, Cuadrícula o Puntos).', 'Si eliges «Personalizado», cada lado va de 30 a 1500 milímetros.']],
        ['Dibujar', ['Las herramientas son: Seleccionar (V), Mover lienzo (H), Medir, Líneas (continua, discontinua, trazo y punto, punteada), Polilínea, Lápiz (P), Flecha (F), Rectángulo (R), rectángulo redondeado, Elipse (O), triángolo, rombo, zona translúcida, más formas (26 iconos), Texto (T), Tabla, círculo numerado, rombo de restricción e Imagen (I).', 'Abajo a la izquierda están las casillas Cuadrícula, Imán, Reglas, Guías y Ortogonal (F8).', 'El panel de la derecha cambia el color, el negrita/cursiva/subrayado/tachado, la alineación y las capas; ahí también puedes agrupar, desagrupar, alinear y distribuir.']],
        ['Páginas y descarga', ['Abajo: «Agregar lámina», «Duplicar esta lámina», mover y «Eliminar esta lámina».', 'Para bajarlo: «Exportar» → PDF, Imprimir o PNG de esta lámina.', 'Arriba hay Deshacer, Rehacer, zoom, maximizar y «Vista de lectura».']],
     ],
     'consejos' => ['Los documentos son compartidos: cualquiera los ve y los edita.', 'El Redactor puede insertar los PNG que dibujes aquí como imágenes.']],

    ['id' => 'archivo', 'titulo' => 'Archivo', 'icono' => 'folder',
     'intro' => 'El cajón de la oficina: carpetas virtuales para guardar y organizar los archivos de trabajo.',
     'pasos' => [
        ['Subir archivos', ['Menú → «Archivo». Sube con «Elegir archivos» + «Subir», o arrastrando los archivos a la zona que dice «Suelta aquí para subirlos».', 'Se admiten varios a la vez. El peso máximo por archivo lo marca el servidor y la propia pantalla lo indica abajo.']],
        ['Organizar', ['«Nueva carpeta» crea una subcarpeta dentro de la que estás.', 'Las migas de pan de arriba (Archivo › carpeta › subcarpeta) te dicen dónde estás y sirven para volver atrás.', 'Cada carpeta tiene lápiz para renombrarla y papelera para eliminarla.']],
        ['Buscar y mover', ['El buscador de arriba filtra por nombre o descripción.', 'Cada archivo tiene «Descargar», «Mover a…» (elige la carpeta destino) y «Eliminar».']],
     ],
     'consejos' => ['Los formatos admitidos son 60: Office y LibreOffice, texto, PDF, imágenes, comprimidos (zip, rar, 7z, tar, gz) y modelos de Rhinoceros, Revit, AutoCAD, SketchUp, IFC, STEP y similares.', 'Puedes crear hasta 4 niveles de carpetas una dentro de otra.', 'No se admiten archivos repetidos en la misma carpeta ni archivos .xml: las facturas no se guardan aquí, se adjuntan en Compras → detalle de la compra → comprobante.', 'El Archivo es compartido: todos los administradores ven lo mismo.']],

    ['id' => 'escritorio', 'titulo' => 'Aplicación de escritorio', 'icono' => 'desktop_windows',
     'intro' => 'La misma plataforma en una ventana propia, sin abrir el navegador.',
     'pasos' => [['Instalarla', ['Menú → «Ajustes» → pestaña «Aplicación de escritorio» → descarga el paquete .deb.', 'En una terminal: sudo apt install ./cotizaciones-escritorio_1.0.0_amd64.deb', 'Ábrela desde el menú de aplicaciones como «Acero 360».']]],
     'consejos' => ['Usa los mismos datos y la misma sesión que la web.']],

    ['id' => 'almacenamiento', 'titulo' => 'Almacenamiento y respaldos', 'icono' => 'hard_drive',
     'intro' => 'Cuánto ocupa tu información y cómo guardar una copia de seguridad desde la propia plataforma.',
     'pasos' => [
        ['Dónde verlo', ['Menú → «Ajustes» → pestaña «Almacenamiento y respaldos».']],
        ['Cuánto ocupa', ['Arriba está el total y una barra con el reparto: base de datos (ventas, clientes, productos, facturas), documentos fiscales (PDF y XML), Archivo compartido, imágenes y copias de seguridad.', 'Debajo, la tabla detalla cuánto pesa cada parte y en qué carpeta está. Se calcula al abrir la página.']],
        ['Respaldo automático', ['Actívalo y elige la hora (por defecto a las 23:00). La primera vez que alguien abra la plataforma después de esa hora se hace sola una copia al día.', 'Cuántas copias conservar: las más antiguas se borran solas al respaldar.', 'Carpeta de respaldos: la que trae por defecto o la que tú indiques. Usa un disco o servidor aparte si puedes.']],
        ['Hacer una copia ahora', ['Botón «Hacer un respaldo ahora». Cada copia trae la base de datos y todos los archivos, con sus huellas de verificación y un manifiesto con la fecha.']],
        ['Restaurar', ['En la tabla «Copias guardadas», «Restaurar en base nueva» trae esa copia de vuelta.', 'Nunca se pisa lo que estás usando: la copia se importa en una base nueva y los archivos se extraen a una carpeta aparte. Después revisas y decides si conviene usarla.']],
     ],
     'consejos' => ['Un respaldo dentro de la misma computadora no te salva si el disco se daña: copia esa carpeta también a un disco externo o a la nube.', 'Si entras desde varios equipos y prefieres que el respaldo ocurra aunque nadie entre, la pestaña trae una línea de cron para pegarla en el servidor.']],

    ['id' => 'seguridad', 'titulo' => 'Cuidados con tu información', 'icono' => 'shield',
     'intro' => 'Tu información importa.',
     'pasos' => [
        ['Cuidados', ['No compartas tu contraseña.', 'Cierra sesión en computadoras ajenas.', 'Los archivos fiscales no son públicos: solo se descargan con tu sesión.', 'Haz respaldos: la sección anterior explica cómo, desde Ajustes → Almacenamiento.']],
     ],
     'consejos' => []],

    ['id' => 'seguimiento', 'titulo' => 'Centro de seguimiento', 'icono' => 'notifications',
     'intro' => 'Reúne lo que necesita tu atención: ventas sin cobrar, facturas por definir, gastos, compras, proveedores, entregas, inventario y novedades de la plataforma.',
     'pasos' => [
        ['Dónde verlo', ['La campana de arriba muestra cuántos avisos tienes sin leer. También está en el menú → «Centro de seguimiento».']],
        ['Lo que vigila', ['Cotizaciones sin respuesta y por vencer.', 'Ventas sin cobrar o sin decir si se facturan.', 'Gastos por pagar y sin comprobante fiscal.', 'Facturas de gasto con pago en parcialidades (PPD) o con el método de pago por definir.', 'Compras: por recibir, con la factura del proveedor pendiente y con saldo por pagar; y pedidos directos a proveedor sin pagar.', 'Entregas y cambios por entregar.', 'Productos y materias primas por debajo de su mínimo (los que ya no tienen nada son los más urgentes).']],
        ['Prioridades', ['Urgente, alta, normal e informativa, calculadas con fechas y estados reales: lo más urgente sale primero.']],
        ['Abrir y marcar', ['«Abrir registro» te lleva a la venta, la compra, el gasto o el producto de origen.', 'En un aviso de factura con método de pago por definir o en parcialidades, «Definir método de pago» permite elegir PUE, PPD o por definir sin salir de ahí.', '«Marcar como leído» solo oculta el aviso de tu lista: no cambia ninguna venta, cobro ni factura. Si la operación cambia (por ejemplo, un pago parcial), el aviso vuelve a aparecer como nuevo.']],
     ],
     'consejos' => ['Una venta pendiente de cobro y una pendiente de factura son avisos distintos: una venta ya facturada no aparece como «factura por definir».', 'Una compra que ya mandaste a contabilidad aparece como «Compra por pagar» y también como «Gasto por pagar»: son dos pantallas distintas del mismo dinero y ninguna se deduce de la otra.']],

    ['id' => 'acerca', 'titulo' => 'Acerca de Acero 360', 'icono' => 'info',
     'intro' => 'Acero 360 es la plataforma comercial, contable y administrativa de tu negocio de acero: antes se llamaba «Cotizaciones». Todo lo que ya tenías (clientes, productos, cotizaciones, ventas, inventario, gastos e impuestos) sigue igual y en el mismo lugar.',
     'pasos' => [
        ['Qué cambió', ['Nuevo nombre y logotipo (una viga en I dentro de un anillo de 360°) en el menú, el inicio de sesión, los PDF y la aplicación de escritorio.', 'La dirección web, tus datos y tu contraseña no cambian.']],
        ['Dónde está', ['Menú → «Ajustes». Ahí puedes permitir o no vender sin existencias, usar las pestañas: Configuración general, Usuarios y permisos, Perfil, Empresas y razones sociales, Datos fiscales, Aplicación de escritorio, Versión y actualizaciones, Novedades de la plataforma y Ayuda y manual.']],
        ['Aplicación de escritorio', ['Se instala igual y conserva tu sesión; el nuevo nombre y el icono aparecen al instalar la versión más reciente del paquete .deb.']],
     ],
     'consejos' => ['Si ves el logotipo anterior en la pestaña del navegador, recarga con Ctrl + Shift + R.']],

    ['id' => 'glosario', 'titulo' => 'Palabras que verás', 'icono' => 'menu_book',
     'intro' => 'Qué significan, en pocas palabras.',
     'pasos' => [['Glosario', [
        'IVA: impuesto de 16 % que cobras en las ventas y que pagas en tus compras; al SAT le entregas la diferencia.',
        'ISR: impuesto sobre tu ganancia (lo que cobras menos lo que pagas).',
        'CFDI / factura: el comprobante fiscal digital. Viene en PDF (para leer) y XML (para la computadora).',
        'UUID o folio fiscal: el número único de cada factura.',
        'Deducible: un gasto que puedes restar de tus ventas para pagar menos ISR. Necesita factura y estar pagado.',
        'Subtotal: precio antes de IVA. Total: precio con IVA.',
        'Pago provisional: el pago mensual a cuenta del ISR del año.',
        'Cerrar el mes: congelar las cifras de un mes para que nadie las cambie por accidente.',
        'Flujo de efectivo: contar las ventas cuando te pagan y los gastos cuando los pagas.',
     ]]],
     'consejos' => []],
];
