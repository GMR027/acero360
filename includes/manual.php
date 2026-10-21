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
        ['Moverte por el menú', ['«Nueva cotización», «Histórico», «Clientes» y «Productos» son lo de todos los días.', 'Debajo de la línea están Ventas, Pantalla de entrega, Contabilidad, Impuestos, Inventario y Compras y proveedores (un solo apartado).']],
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
        ['Enviarla', ['En el detalle de la cotización puedes mandarla por correo con el PDF adjunto.']],
        ['Cuando el cliente acepta', ['Cambia su estado a «Vendido». Eso crea la venta, descuenta del inventario y registra el ingreso en Contabilidad.', 'Si el cliente no acepta, márcala «Cancelada».']],
     ],
     'consejos' => ['Estados: Pendiente (esperando respuesta), Vendido (ya es venta), Cancelado.', 'Para repetir una cotización parecida usa «Duplicar».']],

    ['id' => 'ventas', 'titulo' => 'Ventas y entregas', 'icono' => 'point_of_sale',
     'intro' => 'Las ventas vienen de una cotización vendida, o las anotas directamente si el cliente compró sin cotización.',
     'pasos' => [
        ['Anotar una venta directa', ['Ventas → «Nueva venta». Elige negocio, cliente, fecha y los productos.', 'Al guardar se descuenta el inventario y nace el ingreso en Contabilidad.']],
        ['Ver lo que hay que entregar', ['Pantalla de entrega → marca los pedidos que quieras (o «seleccionar todas») y pulsa «Mostrar en pantalla».', 'Verás juntos los productos agrupados (por ejemplo 5 + 2 = 7 piezas del mismo producto) y los pedidos por cliente. No hay fases que controlar: es solo para ver y alistar.', 'Si quieres volver a abrir esa selección, «Guardar esta vista»; aparece en «Vistas guardadas».']],
        ['Registrar una entrega', ['En la lista de pedidos pulsa «Registrar entrega», escribe cuántas piezas entregaste (puede ser parcial) y confirma.', 'Si el cliente devuelve algo, la venta se cierra como devuelta pero el ingreso se conserva.']],
     ],
     'consejos' => ['Se puede vender aunque el inventario esté en cero: queda en negativo y se corrige al recibir mercancía.']],

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
        ['Catálogo de semiterminados por proveedor', ['Pestaña «Catálogo» → «Nuevo producto»: elige el proveedor y captura nombre, descripción, código y costo unitario de compra. Un proveedor puede tener muchos; el mismo artículo puede existir en otro proveedor con otro costo.', 'No se repiten nombre ni código dentro del mismo proveedor. No se borran: «Desactivar» deja de ofrecerlos en compras nuevas y se conservan en el historial (puedes reactivarlos).', 'Opcional: en la ficha del producto, «Relacionar» con tus productos propios, para saber qué semiterminado de qué proveedor se usa en cada uno (también se ve desde la edición del producto propio). Son cosas distintas: cada uno conserva su código y costo.', 'Cambiar el costo del catálogo no altera compras anteriores y queda en la bitácora.']],
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
        ['Subir la factura', ['En el mismo formulario, «Factura o comprobante»: elige uno o varios archivos (PDF, XML, JPG, PNG o WebP). Se detecta solo qué es cada uno.', 'También puedes subirlos después desde el detalle del gasto, en «Facturas y comprobantes».', 'Si subes el XML de la factura, se leen sus datos (folio fiscal, importes) y avisa si algo no cuadra.']],
        ['Más detalles', ['«Más detalles (opcional)» tiene categoría, cómo pagaste, folio fiscal, repartir entre negocios y notas. No hace falta llenarlo.']],
        ['Pagarlo después', ['Si lo dejaste como «lo debo todavía», ábrelo cuando lo pagues y cámbialo a pagado con su fecha.']],
        ['Que la plataforma lea la factura', ['Gastos → «Leer factura» (o desde una venta, «Leer factura»). Sube el XML (lo mejor: datos exactos), el PDF o una foto.', 'Aparece una pantalla de revisión con lo que se leyó, qué tan seguro es cada dato y el aviso de discrepancias o duplicados. Corrige lo necesario y marca «Revisé este dato» en lo dudoso.', 'Nada se guarda hasta que pulsas «Confirmar datos». Puedes asociar el documento a un gasto o venta que ya existe (sin duplicarlo) y queda registrado quién confirmó y cuándo.', 'El folio de la factura (p. ej. A-123) y el UUID son datos distintos. Si no se puede leer el archivo, captura los datos a mano en la misma pantalla.']],
     ],
     'consejos' => ['Solo los gastos pagados y con factura se restan de tus impuestos.', 'Un ticket sin factura se puede guardar, pero no baja tus impuestos.', 'Máximo por archivo: el tamaño que indica la pantalla.']],

    ['id' => 'cobros', 'titulo' => 'Ventas y cobros en Contabilidad', 'icono' => 'payments',
     'intro' => 'Cada venta genera automáticamente un ingreso. Tú solo anotas si ya te pagaron y si se facturó.',
     'pasos' => [
        ['Anotar un pago del cliente', ['Contabilidad → «Ventas y cobros» → abre la venta → «Pagos recibidos del cliente».', 'Escribe cuándo y cuánto te pagaron y cómo. Si paga en partes, anota cada pago: queda «Pago parcial» hasta completar.']],
        ['Decir si se facturó', ['En la misma pantalla: «¿Se le dio factura al cliente?» → Sí, No o Sin indicar.', 'Si hay factura, escribe su folio y UUID (o sube el XML y se llenan solos).']],
        ['Subir la factura de la venta', ['Abajo, «Facturas y comprobantes»: elige el PDF y el XML a la vez y pulsa «Subir archivos».']],
        ['Pendientes de facturar', ['La pestaña «Pendientes de facturar» lista las ventas sin factura para que no se te pase ninguna. Se puede exportar.']],
     ],
     'consejos' => ['Cancelar un pago o una venta pide un motivo y queda registrado; nada se borra en silencio.']],

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
        ['Leer la cifra', ['Impuestos → arriba: «deberías separar aproximadamente $X», con el IVA y el ISR por separado.', 'Solo cuenta lo que ya te pagaron y los gastos ya pagados con factura.']],
        ['Hacerla más exacta', ['Marca como «No deducible» los gastos que sabes que no se restan.']],
        ['Para tu contador', ['«¿Cómo se calcula?» muestra paso a paso la cuenta, y «Comparar con lo que declaró mi contador» te deja anotar lo declarado.']],
     ],
     'consejos' => ['Es una estimación: no sustituye la declaración. Compárala siempre con tu contador.', 'Los pagos provisionales se presentan a más tardar el día 17 del mes siguiente.']],

    ['id' => 'estadisticas', 'titulo' => 'Estadísticas', 'icono' => 'monitoring',
     'intro' => 'Cómo van tus ventas, cobros, facturas y gastos, con indicadores y gráficas interactivas.',
     'pasos' => [
        ['Ver tus números', ['Arriba elige «Este mes», «Este año» o «Año pasado», o usa los filtros de periodo y negocio y pulsa «Ver estadísticas».', 'Ventas realizadas, cobros recibidos, ventas facturadas y gastos son cosas distintas: vender es cuando haces la venta, cobrar cuando te pagan y facturar cuando das factura.', 'Cada indicador dice cuánto subió o bajó (en pesos y en %) contra el mismo periodo del año anterior; en gastos, bajar es lo bueno.']],
        ['Las gráficas', ['Ventas por mes, año contra año y por semestre; ingresos acumulados; ventas contra cobros; cobrado y saldo pendiente; facturadas y pendientes de facturar; gastos por año y categoría; comparación entre negocios; productos de mayor y menor movimiento y clientes.', 'Pasa el cursor para ver la cifra exacta y la variación contra el año anterior; toca un nombre de la leyenda para ocultar o mostrar esa serie; el icono de la esquina guarda la gráfica como imagen.']],
        ['Filtros y descargas', ['«Más filtros» permite elegir un cliente o un producto y cuántos años comparar.', '«Ver las tablas con todos los números» muestra el detalle de cada gráfica. Arriba descargas PDF (con gráficas), Excel (una hoja por gráfica, con la gráfica dentro) o CSV; siempre salen con el periodo y los filtros que elegiste.']],
     ],
     'consejos' => ['Si el periodo no ha terminado, la comparación usa el mismo avance del año anterior para ser justa.']],

    ['id' => 'escritorio', 'titulo' => 'Aplicación de escritorio', 'icono' => 'desktop_windows',
     'intro' => 'La misma plataforma en una ventana propia, sin abrir el navegador.',
     'pasos' => [['Instalarla', ['Menú → «Ajustes» → pestaña «Aplicación de escritorio» → descarga el paquete .deb.', 'En una terminal: sudo apt install ./cotizaciones-escritorio_1.0.0_amd64.deb', 'Ábrela desde el menú de aplicaciones como «Acero 360».']]],
     'consejos' => ['Usa los mismos datos y la misma sesión que la web.']],

    ['id' => 'seguridad', 'titulo' => 'Respaldos y cuidados', 'icono' => 'shield',
     'intro' => 'Tu información importa.',
     'pasos' => [
        ['Respaldos', ['Se hace un respaldo cada noche (2:30) en la computadora de administración. Copia esa carpeta también a un disco externo o a la nube.']],
        ['Cuidados', ['No compartas tu contraseña.', 'Cierra sesión en computadoras ajenas.', 'Los archivos fiscales no son públicos: solo se descargan con tu sesión.']],
     ],
     'consejos' => []],

    ['id' => 'seguimiento', 'titulo' => 'Centro de seguimiento', 'icono' => 'notifications',
     'intro' => 'Reúne lo que necesita tu atención: ventas sin cobrar, facturas por definir, gastos, entregas pendientes, productos con poco stock y novedades de la plataforma.',
     'pasos' => [
        ['Dónde verlo', ['La campana de arriba muestra cuántos avisos tienes sin leer. También está en el menú → «Centro de seguimiento».']],
        ['Prioridades', ['Alta, media o baja según los días que lleva la operación (por ejemplo, un cobro de más de 30 días es de prioridad alta). Los más urgentes salen primero.']],
        ['Abrir y marcar', ['«Abrir registro» te lleva a la venta, el gasto o el producto de origen.', '«Marcar como leído» solo oculta el aviso de tu lista: no cambia ninguna venta, cobro ni factura. Si la operación cambia (por ejemplo, un pago parcial), el aviso vuelve a aparecer como nuevo.']],
     ],
     'consejos' => ['Una venta pendiente de cobro y una pendiente de factura son avisos distintos: una venta ya facturada no aparece como «factura por definir».']],

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
        'ISR: impuesto sobre tu ganancia (lo que vendes menos lo que gastas con factura).',
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
