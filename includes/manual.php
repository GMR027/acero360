<?php
/**
 * includes/manual.php — Contenido del manual de usuario. Una sola fuente para la página /admin/ayuda
 * y para MANUAL_USUARIO.md (se regenera con: php scripts/generar_manual.php).
 *
 * Cada sección: ['id', 'titulo', 'icono', 'intro', 'pasos' => [[titulo, [pasos...]]...], 'consejos' => [...]]
 */
return [
    ['id' => 'inicio', 'titulo' => 'Empezar', 'icono' => 'rocket_launch',
     'intro' => 'La plataforma sirve para cotizar, vender, entregar, llevar tus gastos y saber más o menos cuánto vas a pagar de impuestos. Todo se hace desde el menú de la izquierda (en el celular, desde el botón de abajo a la derecha).',
     'pasos' => [
        ['Entrar', ['Abre la página e inicia sesión con tu correo y contraseña.', 'Si olvidaste la contraseña, cámbiala en «Usuarios y perfil».']],
        ['Moverte por el menú', ['«Nueva cotización», «Histórico», «Clientes» y «Productos» son lo de todos los días.', 'Debajo de la línea están Ventas, Pantalla de entrega, Contabilidad, Impuestos, Inventario y Compras y proveedores.']],
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
     'intro' => 'Es un solo módulo con dos pestañas: Órdenes de compra y Proveedores.',
     'pasos' => [
        ['Dar de alta un proveedor', ['Compras y proveedores → pestaña «Proveedores» → «Nuevo proveedor».']],
        ['Comprar', ['Pestaña «Órdenes de compra» → «Nueva orden». Elige proveedor y productos con cantidad y costo.', 'Cuando llegue la mercancía, abre la orden y usa «Recibir». Las piezas suman al inventario (puedes recibir por partes).']],
        ['Historial anterior', ['En Proveedores, el botón «Entradas» guarda las entregas con foto que se registraban antes. Las compras nuevas se hacen con órdenes de compra.']],
     ],
     'consejos' => ['Para registrar cuánto pagaste por una compra, anótalo como gasto en Contabilidad.']],

    ['id' => 'gastos', 'titulo' => 'Anotar un gasto y subir su factura', 'icono' => 'receipt',
     'intro' => 'Un gasto es cualquier cosa que pagas para trabajar: material, gasolina, renta, honorarios.',
     'pasos' => [
        ['Las 6 preguntas', ['Contabilidad → Gastos → «Anotar un gasto».', '¿En qué fue? · ¿A quién le pagaste? · ¿Cuándo fue? · ¿Cuánto pagaste en total (con IVA incluido)? · ¿Ya lo pagaste? · ¿Te dieron factura?', 'La plataforma separa sola el subtotal y el IVA. Si no tiene IVA, elige «No tiene IVA».']],
        ['Subir la factura', ['En el mismo formulario, «Factura o comprobante»: elige uno o varios archivos (PDF, XML, JPG, PNG o WebP). Se detecta solo qué es cada uno.', 'También puedes subirlos después desde el detalle del gasto, en «Facturas y comprobantes».', 'Si subes el XML de la factura, se leen sus datos (folio fiscal, importes) y avisa si algo no cuadra.']],
        ['Más detalles', ['«Más detalles (opcional)» tiene categoría, cómo pagaste, folio fiscal, repartir entre negocios y notas. No hace falta llenarlo.']],
        ['Pagarlo después', ['Si lo dejaste como «lo debo todavía», ábrelo cuando lo pagues y cámbialo a pagado con su fecha.']],
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
        ['Hacerla más exacta', ['Si ya pagaste ISR o IVA al SAT, anótalo en «Pagos al SAT y ajustes».', 'Marca como «No deducible» los gastos que sabes que no se restan.', 'Anota retenciones o saldo a favor si los tienes.']],
        ['Para tu contador', ['«Ver cómo se calculó» muestra paso a paso la cuenta.']],
     ],
     'consejos' => ['Es una estimación: no sustituye la declaración. Compárala siempre con tu contador.', 'Los pagos provisionales se presentan a más tardar el día 17 del mes siguiente.', 'Para que funcione, el administrador debe tener activas las reglas del SAT (Impuestos → Reglas del SAT).']],

    ['id' => 'estadisticas', 'titulo' => 'Estadísticas', 'icono' => 'monitoring',
     'intro' => 'Gráficas de ventas, ganancias y productos más vendidos.',
     'pasos' => [['Usarlas', ['Elige el periodo y el negocio. Puedes descargar el reporte.']]],
     'consejos' => []],

    ['id' => 'escritorio', 'titulo' => 'Aplicación de escritorio', 'icono' => 'desktop_windows',
     'intro' => 'La misma plataforma en una ventana propia, sin abrir el navegador.',
     'pasos' => [['Instalarla', ['Menú → «App de escritorio» → descarga el paquete .deb.', 'En una terminal: sudo apt install ./cotizaciones-escritorio_1.0.0_amd64.deb', 'Ábrela desde el menú de aplicaciones como «Cotizaciones».']]],
     'consejos' => ['Usa los mismos datos y la misma sesión que la web.']],

    ['id' => 'seguridad', 'titulo' => 'Respaldos y cuidados', 'icono' => 'shield',
     'intro' => 'Tu información importa.',
     'pasos' => [
        ['Respaldos', ['Se hace un respaldo cada noche (2:30) en la computadora de administración. Copia esa carpeta también a un disco externo o a la nube.']],
        ['Cuidados', ['No compartas tu contraseña.', 'Cierra sesión en computadoras ajenas.', 'Los archivos fiscales no son públicos: solo se descargan con tu sesión.']],
     ],
     'consejos' => []],

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
