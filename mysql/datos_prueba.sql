-- ===========================================================================
--  DATOS DE PRUEBA
--  --------------------------------------------------------------------------
--  Llena el panel con información ficticia para probar la aplicación:
--  negocios, clientes, productos, precios especiales y la bitácora de notas.
--
--  USO:   mysql -u root cotizaciones < mysql/datos_prueba.sql
--
--  IMPORTANTE:
--   * Es SOLO para desarrollo. Nada de esto es información real.
--   * Las empresas 1 y 2 ("Soluciones en Acero" y "Canastillas de la Baja")
--     son las semillas del esquema: sólo se ACTUALIZAN, nunca se borran.
--   * Es idempotente: se puede ejecutar más de una vez sin duplicar filas.
--   * Las imágenes de producto se generan aparte con
--         php scripts/imagenes_prueba.php
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. EMPRESAS — los dos negocios reales + otros para probar el filtro
-- ---------------------------------------------------------------------------
INSERT INTO empresas
  (id, nombre, rfc, regimen, direccion, ciudad, estado, cp, telefono, email,
   condiciones_envio, condiciones_compra, activa)
VALUES
  (1, 'Soluciones en Acero', 'SEA980415KJ2', '601 - General de Ley',
   'Av. Industria Metalúrgica 410', 'Monterrey', 'Nuevo León', '64000',
   '81 8123 4400', 'contacto@solucionesenacero.mx',
   'Entrega en obra a partir de 5 días hábiles sin costo en pedidos mayores a $15,000 MXN.',
   'Contado al 50% de anticipo y 50% contra entrega. Precios en pesos mexicanos.',
   1),

  (2, 'Canastillas de la Baja', 'CDB130722QT4', '601 - General de Ley',
   'Blvd. de las Industrias 87', 'Tijuana', 'Baja California', '22440',
   '66 000 0000', 'ventas@canastillasdelabaja.mx',
   'Envío a toda la república por paquetería. Gratis en pedidos de 20 piezas o más.',
   'Crédito a 30 días para clientes con historial. Anticipo del 30% en pedidos nuevos.',
   1),

  (3, 'Ferretera del Norte', 'FNO090518HJ3', '601 - General de Ley',
   'Calle Escobedo 233', 'Saltillo', 'Coahuila', '25000',
   '84 4412 0087', 'ventas@ferreteradelnorte.mx',
   'Recolección en sucursal sin costo.',
   'Contado o transferencia bancaria previa.',
   1),

  (4, 'Grupo Aceros del Bajío', 'GAB210904LM8', '601 - General de Ley',
   'Parque Industrial León II Km 4', 'León', 'Guanajuato', '37390',
   '47 7710 5566', 'cotizaciones@gruposacerosbajio.mx',
   'Flete propio en el Bajío. Paquetería nacional con cobro según destino.',
   'Facturación a 15 días con línea autorizada.',
   1),

  (5, 'Canastillas Industriales MX', 'CIM160211PR7', '601 - General de Ley',
   'Av. José López Portillo 1190', 'Querétaro', 'Querétaro', '76230',
   '44 2218 7340', 'info@canastillasindustriales.mx',
   'Entrega en 72 horas dentro de la zona metropolitana.',
   'Anticipo del 50%. Saldo contra entrega.',
   1),

  (6, 'Lámina y Tubos del Sur', 'LTS071129RT5', '601 - General de Ley',
   'Calzada de los Industriales 66', 'México', 'Ciudad de México', '07000',
   '55 5340 9922', 'ventas@laminaytubossur.mx',
   'Envío programado con 48 horas de aviso.',
   'Contado. Descuento por volumen desde 100 piezas.',
   0)

ON DUPLICATE KEY UPDATE
  nombre              = VALUES(nombre),
  rfc                 = VALUES(rfc),
  regimen             = VALUES(regimen),
  direccion           = VALUES(direccion),
  ciudad              = VALUES(ciudad),
  estado              = VALUES(estado),
  cp                  = VALUES(cp),
  telefono            = VALUES(telefono),
  email               = VALUES(email),
  condiciones_envio   = VALUES(condiciones_envio),
  condiciones_compra  = VALUES(condiciones_compra),
  activa              = VALUES(activa);

-- ---------------------------------------------------------------------------
-- 2. CLIENTES — fichas seleccionables en las cotizaciones
--    (nombre, empresa, alias, RFC, teléfono, email, ubicación, VIP,
--     descuento global y observaciones)
-- ---------------------------------------------------------------------------
INSERT INTO clientes
  (id, nombre, nombre_empresa, alias, rfc, telefono, email, ubicacion,
   vip, descuento_pct, notas)
VALUES
  (1,  'María Fernanda Ríos', 'Constructora Ríos y Hermanos', 'Ríos',
       'CRH850210MN4', '81 1122 3344', 'mrios@constructorarioshm.mx',
       'San Pedro Garza García, N.L.', 1, 8.00,
       'Paga contra entrega. Prefiere acero galvanizado.'),

  (2,  'José Luis Mendoza', 'Talleres Mendoza', 'Talleres Mendoza',
       'TME990405KL1', '81 5566 7788', 'jl.mendoza@talleresmendoza.mx',
       'Apodaca, N.L.', 0, 0.00, 'Cotizar siempre con IVA desglosado.'),

  (3,  'Ana Sofía Delgado', 'Bodegas Delgado', 'Bodegas',
       'BDG070718RT9', '22 1188 4477', 'ana@bodegasdelgado.com',
       'Querétaro, Qro.', 1, 12.00, 'Cliente frecuente de canastillas.'),

  (4,  'Roberto Vargas', 'Acabados Vargas', 'Acabados',
       'AVA120322HN6', '33 2244 6688', 'rvargas@acabadosvargas.mx',
       'Guadalajara, Jal.', 0, 0.00, ''),

  (5,  'Lucía Herrera', 'Distribuidora Herrera', 'Herrera',
       'DHE960819PL5', '55 3399 1122', 'compras@distribuidoraherrera.mx',
       'Coyoacán, CDMX', 1, 10.00, 'Solicita factura a 30 días.'),

  (6,  'Carlos Antonio Peña', 'Grupo Peña Logística', 'Peña Log',
       'GPL140128QR2', '44 2255 8899', 'cpena@grupopenalogistica.mx',
       'Celaya, Gto.', 0, 3.00, ''),

  (7,  'Verónica Sandoval', 'Refacciones Sanvi', 'Sanvi',
       'RSA030912KM8', '81 7733 5511', 'veronica@refaccionsanvi.com',
       'Guadalupe, N.L.', 0, 0.00, 'Pide por WhatsApp, pero confirmar por correo.'),

  (8,  'Eduardo Cortés', 'Ensambladora Cortés', 'Cortés',
       'ECO110517TJ3', '66 4422 9900', 'ecortes@ensambladoracortes.mx',
       'Tijuana, B.C.', 1, 5.00, ''),

  (9,  'Patricia Núñez', 'Mueblería Núñez', 'Mueblería',
       'MNU050711BN4', '99 8877 6655', 'patricia@mueblerianunez.mx',
       'Mérida, Yuc.', 0, 0.00, ''),

  (10, 'Héctor Almanza', 'Frigoríficos Almanza', 'Frigos',
       'FAL180423CD7', '44 9911 2233', 'halmanza@frigorificosalmanza.mx',
       'San Luis Potosí, S.L.P.', 0, 0.00, 'Producto: canastillas para tarimas.'),

  (11, 'Gabriela Ortega', 'Soluciones de Empaque GO', 'Empaque GO',
       'SEG100609JV1', '81 2299 3344', 'gabriela@solucioneseempaque.mx',
       'Santa Catarina, N.L.', 0, 4.00, ''),

  (12, 'Miguel Ángel Rentería', 'Herrería Rentería', 'Herrería',
       'HRE940915ML9', '45 6677 8899', 'miguel@herreria-renteria.mx',
       'Morelia, Mich.', 0, 0.00, ''),

  (13, 'Sandra Elizabeth Cruz', 'Almacenes Cruz', 'Almacenes',
       'ACR080102PL6', '99 1144 7788', 'scruz@almacenescruz.mx',
       'Cancún, Q. Roo.', 1, 6.00, 'Cliente VIP: descuento anual acordado.'),

  (14, 'Ricardo Solís', 'Transportes Solís', 'Transportes',
       'TSO130718NK2', '22 6655 4433', 'ricardo@transportessolis.mx',
       'Puebla, Pue.', 0, 0.00, ''),

  (15, 'Fernanda Zamudio', 'Comercializadora Fern', 'Comercial Fern',
       'CFE190305HQ4', '55 4477 8811', 'fzamudio@comercialfern.mx',
       'San Luis Potosí, S.L.P.', 0, 0.00, ''),

  (16, 'Ignacio Barrera', 'Lavandería Barrera', 'Lavandería',
       'LBA060811GN5', '66 3322 1100', 'ignacio@lavanderiabarrera.mx',
       'Ensenada, B.C.', 0, 0.00, '')

ON DUPLICATE KEY UPDATE
  nombre         = VALUES(nombre),
  nombre_empresa = VALUES(nombre_empresa),
  alias          = VALUES(alias),
  rfc            = VALUES(rfc),
  telefono       = VALUES(telefono),
  email          = VALUES(email),
  ubicacion      = VALUES(ubicacion),
  vip            = VALUES(vip),
  descuento_pct  = VALUES(descuento_pct),
  notas          = VALUES(notas);

-- ---------------------------------------------------------------------------
-- 3. PRODUCTOS — catálogo de ambos negocios (precio de lista fijo)
--    Los SKU empiezan con "PR-" para que scripts/imagenes_prueba.php sepa
--    cuáles recibir una imagen cuadrada de prueba.
-- ---------------------------------------------------------------------------
INSERT INTO productos
  (id, empresa_id, nombre, sku, categoria, unidad, descripcion, precio, activo)
VALUES
  (1,  1, 'Barandal de acero inoxidable 1.20 m', 'PR-AC-001', 'Estructuras',
       'pza', 'Tubo calibre 18, acabado espejo.', 1850.00, 1),
  (2,  1, 'Escalera industrial de acero 6 escalones', 'PR-AC-002', 'Estructuras',
       'pza', 'Capacidad 150 kg, peldones antideslizantes.', 4290.00, 1),
  (3,  1, 'Estante metálico 5 niveles', 'PR-AC-003', 'Almacenamiento',
       'pza', 'Carga por nivel 175 kg.', 2340.00, 1),
  (4,  1, 'Mesa de trabajo con estructura de acero 1.80 m', 'PR-AC-004', 'Mobiliario',
       'pza', 'Tablero laminado de 18 mm.', 5100.00, 1),
  (5,  1, 'Rack para llantas (nivel 4)', 'PR-AC-005', 'Almacenamiento',
       'pza', 'Adecuado para llantas de 13" a 20".', 3780.00, 1),
  (6,  1, 'Puerta corredera industrial', 'PR-AC-006', 'Estructuras',
       'pza', 'Riel de acero con rodamientos sellados.', 7450.00, 1),
  (7,  1, 'Tarima metálica reforzada 1.20 x 1.00', 'PR-AC-007', 'Almacenamiento',
       'pza', 'Capacidad 1,000 kg.', 1290.00, 1),
  (8,  1, 'Soldadura de refuerzo 3/8" (costo por metro)', 'PR-AC-008', 'Insumos',
       'm', 'Varilla nervurada grado 60.', 42.50, 1),

  (9,  2, 'Canastilla plástica 600 x 400 mm', 'PR-CB-001', 'Canastillas',
       'pza', 'Polipropileno virgen, apilable.', 385.00, 1),
  (10, 2, 'Canastilla plegable 1200 x 800 mm', 'PR-CB-002', 'Canastillas',
       'pza', 'Carga hasta 500 kg.', 1690.00, 1),
  (11, 2, 'Canastilla para fruta con malla', 'PR-CB-003', 'Canastillas',
       'pza', 'Ventilación perimetral para producto fresco.', 540.00, 1),
  (12, 2, 'Caja container plegable 40"', 'PR-CB-004', 'Contenedores',
       'pza', 'Plegable reduce el espacio de retorno en 75%.', 8950.00, 1),
  (13, 2, 'Base plástica para tarima 1200 x 1000', 'PR-CB-005', 'Bases',
       'pza', 'Compatible con torno manual.', 920.00, 1),
  (14, 2, 'Canastilla de acero para estiba', 'PR-CB-006', 'Canastillas',
       'pza', 'Estructura soldada con pintura epóxica.', 2180.00, 1),
  (15, 2, 'Tapa impermeable para canastilla', 'PR-CB-007', 'Accesorios',
       'pza', 'Lonas PVC 550 g/m² con cordón elástico.', 265.00, 0),
  (16, 2, 'Ruedas direccionales 5" para canastilla', 'PR-CB-008', 'Accesorios',
       'juego', 'Juego de 4 ruedas con freno.', 480.00, 1)

ON DUPLICATE KEY UPDATE
  empresa_id  = VALUES(empresa_id),
  nombre      = VALUES(nombre),
  sku         = VALUES(sku),
  categoria   = VALUES(categoria),
  unidad      = VALUES(unidad),
  descripcion = VALUES(descripcion),
  precio      = VALUES(precio),
  activo      = VALUES(activo);

-- ---------------------------------------------------------------------------
-- 4. PRECIOS ESPECIALES POR CLIENTE
--    Se prueban dos formas de entrada:
--      * precio directo para un producto concreto (sin "recordar")
--      * acuerdo guardado desde una cotización con el precio recordado
-- ---------------------------------------------------------------------------
INSERT INTO precios_cliente (cliente_id, producto_id, precio, creado_en, actualizado_en) VALUES
  (1,  1,  1650.00, NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 40 DAY),
  (1,  9,   350.00, NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 40 DAY),
  (3,  9,   345.00, NOW() - INTERVAL 25 DAY, NOW() - INTERVAL 25 DAY),
  (3, 10,  1580.00, NOW() - INTERVAL 25 DAY, NOW() - INTERVAL 25 DAY),
  (5,  3,  2190.00, NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 12 DAY),
  (5, 12, 8400.00, NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 12 DAY),
  (8, 10, 1620.00, NOW() - INTERVAL 7 DAY,  NOW() - INTERVAL 7 DAY),
  (13, 9,  355.00, NOW() - INTERVAL 3 DAY,  NOW() - INTERVAL 3 DAY)

ON DUPLICATE KEY UPDATE
  precio          = VALUES(precio),
  actualizado_en  = VALUES(actualizado_en);

-- ---------------------------------------------------------------------------
-- 5. NOTAS — bitácora única, compartida por todos los administradores
--    usuario_id = 1 es el administrador semilla; cualquiera puede verlas
--    y editarlas desde el panel.
--
--    `notas` no tiene clave única alternativa, así que las filas de prueba
--    se reemplazan por título para que el archivo siga siendo repetible.
-- ---------------------------------------------------------------------------
DELETE FROM notas
 WHERE titulo IN (
  'Acuerdo de precio: Constructora Ríos',
  'Confirmar condiciones de envío a Tijuana',
  'Actualizar precios de estructuras',
  'Bodegas Delgado pide factura a 30 días',
  'Descuento anual con Almacenes Cruz',
  'Preparar muestra para Talleres Mendoza',
  'Vigilar crédito de Grupo Peña Logística',
  'Producto descontinuado: tapa impermeable',
  'Reunión con Comercializadora Fern',
  'Ajustar condiciones de compra del Bajío',
  'Nota general del tablero',
  'Cotización de Lavandería Barrera'
 );

INSERT INTO notas
  (usuario_id, titulo, cuerpo, categoria, referencia, fecha_recordatorio,
   fijada, completada, creado_en)
VALUES
  (1, 'Acuerdo de precio: Constructora Ríos',
       'Canastilla plástica 600x400 a $350 para volúmenes desde 100 piezas. '
       'Vigente hasta fin de trimestre y aplicable a las cotizaciones de este cliente.',
       'cambios', 'Cliente: Constructora Ríos y Hermanos', 'Fin de trimestre',
       1, 0, NOW() - INTERVAL 6 DAY),

  (1, 'Confirmar condiciones de envío a Tijuana',
       'La paquetería subió 12% la tarifa a Baja California. Revisar con Canastillas '
       'de la Baja si absorbe el aumento o se traslada al cliente.',
       'recordatorio', 'Catálogo: Canastillas de la Baja', 'Esta semana',
       1, 0, NOW() - INTERVAL 5 DAY),

  (1, 'Actualizar precios de estructuras',
       'El proveedor de lámina notificó incremento del 9% a partir del mes entrante. '
       'Subir la lista de Barandales y Escaleras antes de cotizar de nuevo.',
       'revisar', 'Catálogo: Soluciones en Acero', 'Antes del 15',
       1, 0, NOW() - INTERVAL 4 DAY),

  (1, 'Bodegas Delgado pide factura a 30 días',
       'Cliente VIP con 12% de descuento global. Autorizado por dirección; '
       'registrar en cada cotización el plazo de crédito.',
       'recordatorio', 'Cliente: Bodegas Delgado', '',
       0, 0, NOW() - INTERVAL 4 DAY),

  (1, 'Descuento anual con Almacenes Cruz',
       'Acuerdo marco del 6% sobre lista para todo el ejercicio, '
       'renovable en enero. Aplicar automáticamente a sus cotizaciones.',
       'cambios', 'Cliente: Almacenes Cruz', 'Renovar en enero',
       0, 0, NOW() - INTERVAL 3 DAY),

  (1, 'Preparar muestra para Talleres Mendoza',
       'Enviar muestra de estante metálico de 5 niveles y agendar visita '
       'para cerrar el pedido de 20 piezas.',
       'revisar', 'Cliente: Talleres Mendoza', 'Pendiente',
       0, 0, NOW() - INTERVAL 3 DAY),

  (1, 'Vigilar crédito de Grupo Peña Logística',
       'Límite autorizado de $80,000 MXN. Antes de emitir una cotización mayor, '
       'revisar el saldo pendiente con contabilidad.',
       'recordatorio', 'Cliente: Grupo Peña Logística', '',
       0, 0, NOW() - INTERVAL 2 DAY),

  (1, 'Producto descontinuado: tapa impermeable',
       'El proveedor dejó de fabricar la tapa de PVC 550 g. Marcarla como inactiva '
       'y ofrecer la alternativa de lonina 600 g.',
       'revisar', 'Catálogo: Canastillas de la Baja', '',
       0, 1, NOW() - INTERVAL 2 DAY),

  (1, 'Reunión con Comercializadora Fern',
       'Revisar portafolio de contenedores plegables. Solicitan precios por volumen '
       'de 50, 100 y 250 piezas.',
       'recordatorio', 'Cliente: Comercializadora Fern', 'Próximo lunes',
       0, 0, NOW() - INTERVAL 1 DAY),

  (1, 'Ajustar condiciones de compra del Bajío',
       'Grupo Aceros del Bajío pide crédito a 45 días en lugar de 15. '
       'Confirmar con administración antes de reflejarlo en la plantilla.',
       'otro', 'Catálogo: Grupo Aceros del Bajío', '',
       0, 0, NOW() - INTERVAL 12 HOUR),

  (1, 'Nota general del tablero',
       'La bitácora es única: todo administrador ve y edita estas notas. '
       'Usarla para acuerdos de precios, recordatorios de clientes y tareas.',
       'otro', 'Bitácora', '',
       0, 1, NOW() - INTERVAL 6 HOUR),

  (1, 'Cotización de Lavandería Barrera',
       'Piden 30 canastillas con ruedas direccionales. Validar si conviene '
       'el juego completo o vender las ruedas aparte.',
       'revisar', 'Cliente: Lavandería Barrera', 'Pendiente',
       0, 0, NOW() - INTERVAL 2 HOUR)

ON DUPLICATE KEY UPDATE
  titulo           = VALUES(titulo),
  cuerpo           = VALUES(cuerpo),
  categoria        = VALUES(categoria),
  referencia       = VALUES(referencia),
  fecha_recordatorio = VALUES(fecha_recordatorio),
  fijada           = VALUES(fijada),
  completada       = VALUES(completada);

SET FOREIGN_KEY_CHECKS = 1;
