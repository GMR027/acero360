-- ===========================================================================
-- 056_distribuidores.sql — Distribuidores autorizados (puntos de venta).
--
-- Hasta ahora la página /distribuidores del sitio de Soluciones en Acero leía
-- una lista fija (data/distribuidores.php del sitio). Desde aquí se administran
-- en Acero 360 (menú «Distribuidores», debajo de «Pedidos») y el sitio los lee
-- de esta tabla.
--
-- • empresa_id: negocio al que pertenece el punto de venta. El sitio muestra
--   sólo los de su empresa (EMPRESA_ID) y con publicado = 1.
-- • municipio: agrupa las tarjetas y los filtros del sitio.
-- • orden: menor primero. Los municipios salen en el orden de su primer
--   distribuidor.
-- • imagen: foto de la fachada. Las que se suben aquí son archivos de
--   public/image (distribuidor-xxxxxxxxxx.jpg). Las heredadas del sitio son
--   rutas relativas a su carpeta de imágenes (distribuidores/lapaz/...): el
--   sitio las sigue sirviendo hasta que se reemplazan o se importan con
--   scripts/importar_imagenes_distribuidores.php.
--
-- Se cargan los 42 distribuidores que tenía la lista fija del sitio.
-- ===========================================================================

CREATE TABLE distribuidores (
  id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  empresa_id      INT UNSIGNED      NOT NULL,
  nombre          VARCHAR(120)      NOT NULL,
  sucursal        VARCHAR(120)      DEFAULT NULL,
  municipio       VARCHAR(80)       NOT NULL,
  direccion       VARCHAR(255)      NOT NULL,
  telefono        VARCHAR(30)       DEFAULT NULL,
  maps            VARCHAR(255)      DEFAULT NULL COMMENT 'Enlace de Google Maps («Cómo llegar»)',
  imagen          VARCHAR(255)      DEFAULT NULL COMMENT 'Fachada: archivo en public/image o ruta heredada del sitio (distribuidores/...)',
  publicado       TINYINT(1)        NOT NULL DEFAULT 1 COMMENT '1 = aparece en el sitio público',
  orden           SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Orden en el sitio (menor primero)',
  creado_en       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_distribuidores_sitio (empresa_id, publicado, orden),
  KEY idx_distribuidores_municipio (municipio),
  CONSTRAINT fk_distribuidores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lista que tenía el sitio, en el mismo orden. Se asigna a «Soluciones en
-- Acero»; si esa empresa no existe (bases de prueba), no se carga nada.
CREATE TEMPORARY TABLE tmp_distribuidores (
  nombre     VARCHAR(120) NOT NULL,
  sucursal   VARCHAR(120) DEFAULT NULL,
  municipio  VARCHAR(80)  NOT NULL,
  direccion  VARCHAR(255) NOT NULL,
  maps       VARCHAR(255) DEFAULT NULL,
  imagen     VARCHAR(255) DEFAULT NULL,
  orden      SMALLINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_distribuidores (nombre, sucursal, municipio, direccion, maps, imagen, orden) VALUES
    ('Ferreteria el Clavo (5 de mayo)', '5 de mayo', 'La Paz', 'Cl. 5 de Mayo 20, de noviembre, 23020 La Paz, B.C.S.', 'https://maps.app.goo.gl/5kEVhzBu5nMm1rs67', 'distribuidores/lapaz/elClavo-laPaz-5mayo.jpg', 1),
    ('Ferreteria el Clavo (Camino Real)', 'Camino Real', 'La Paz', 'Calz. El Camino Real El, Camino Real, 23088 La Paz, B.C.S.', 'https://maps.app.goo.gl/82RD2AE1ZSzeqs5n6', 'distribuidores/lapaz/elClavo-laPaz-caminoReal.jpg', 2),
    ('Ferreteria el Clavo (Colosio)', 'Luis Donaldo Colosio', 'La Paz', 'Carabineros, Indeco, Donceles 28, 23070 La Paz, B.C.S.', 'https://maps.app.goo.gl/hxmiBD3VEHktxEig7', 'distribuidores/lapaz/elClavo-laPaz-carabineros.jpg', 3),
    ('Ferreteria el Clavo (La fuente)', 'Del Agua', 'La Paz', 'Del Agua S/N, La Fuente, 23080 La Paz, B.C.S.', 'https://maps.app.goo.gl/PSkYLY1pQCEVbkdc7', 'distribuidores/lapaz/elClavo-laPaz-delAgua.jpg', 4),
    ('Ferreteria el Clavo (Felix Ortega)', 'Felix Ortega', 'La Paz', 'Gral Félix Ortega Aguilar 1110, Zona Central, 23000 La Paz, B.C.S.', 'https://maps.app.goo.gl/goUs79TYJ5qjhazh7', 'distribuidores/lapaz/elClavo-laPaz-felixOrtega.jpg', 5),
    ('Ferreteria el Clavo (Heroes Independencia)', 'Heroes de Independencia', 'La Paz', 'Morelos, Héroes de Independencia esquina, Zona Central, 23000 La Paz, B.C.S.', 'https://maps.app.goo.gl/ARUPDxgoN9ix5FecA', 'distribuidores/lapaz/elClavo-laPaz-heroesIndependencia.png', 6),
    ('Ferreteria el Clavo (Las Garzas)', 'Las Garzas', 'La Paz', 'Luis Donaldo Colosio e/ Palma y JO, Josefa Ortiz de Domínguez, Las Garzas, 23090 La Paz, B.C.S.', 'https://maps.app.goo.gl/FXqAATmeQZBWnsea6', 'distribuidores/lapaz/elClavo-laPaz-luisDonaldo.jpg', 7),
    ('Ferreteria el Clavo (Pino Payas)', 'Pino Payas', 'La Paz', 'Blvd Pino Payas entre villa ballena, Villa Del Sauce y, Villas del Encanto, 23085 La Paz, B.C.S.', 'https://maps.app.goo.gl/XsPACSWvBcVRy9ic9', 'distribuidores/lapaz/elClavo-laPaz-pinoPayas.jpg', 8),
    ('Ferreteria el Clavo (Miramar)', 'Miramar', 'La Paz', 'Blvd. Sta. Rosa 220, Miramar, 23086 La Paz, B.C.S.', 'https://maps.app.goo.gl/AqDqNYRTskmPbgfx7', 'distribuidores/lapaz/elClavo-laPaz-miramar.jpg', 9),
    ('Fymsa (Estadio)', 'Estadio', 'La Paz', 'Calle Lic. Primo Verdad 1016, Colina de la Cruz, 23060 La Paz, B.C.S.', 'https://maps.app.goo.gl/XjJegUqkC1eKLrMfA', 'distribuidores/lapaz/fymsa-lapaz-estadio.jpg', 10),
    ('Fymsa (Mezquitito)', 'Mezquitito', 'La Paz', 'Carretera al Sur Km8 a un costado del CUT, El Mezquitito, 23086 La Paz, B.C.S.', 'https://maps.app.goo.gl/HpjQRR5xBvQHu13s9', 'distribuidores/lapaz/fymsa-lapaz-mezquite.jpg', 11),
    ('Fymsa (Oaxaca)', 'Oaxaca', 'La Paz', 'Oaxaca #1395, Las Garzas, 23070 La Paz, B.C.S.', 'https://maps.app.goo.gl/Rx1ycErLN6fP98ts9', 'distribuidores/lapaz/fymsa-lapaz-mezquite.jpg', 12),
    ('Aceros del pacifico (Olachea)', 'La Paz', 'La Paz', 'Blvd. Gral. Agustín Olachea 4910, entre Tenochtitlán y Tuna, Las Garzas, 23070 La Paz, B.C.S.', 'https://maps.app.goo.gl/KWvRNB81wCsZZnwR8', 'distribuidores/lapaz/acerosPacifico-lapaz-olachea.png', 13),
    ('Construrama casillas (Santa Rosa)', 'Casillas', 'La Paz', 'Bulevar Santa Rosa, C. San Fernando Esquina, Santa Fe, 23085 La Paz, B.C.S.', 'https://maps.app.goo.gl/ALhjBqV1MUg8Dav9A', 'distribuidores/lapaz/casillas-laPaz-padreEusebio.jpg', 14),
    ('Risol (Cardenas)', 'Cárdenas', 'La Paz', 'Municipio Libre esq Juan Ma. de Salvatierra Colonia, Municipio Libre 737, Lázaro Cárdenas, 23034 La Paz, B.C.S.', 'https://maps.app.goo.gl/Gd1dPcmYeSWRhtdF6', 'distribuidores/lapaz/risol-lapaz-Cardenas.jpg', 15),
    ('Risol (Centenario)', 'Centenario', 'La Paz', 'AQUILES SERDAN, Norte, 23205 La Paz, B.C.S.', 'https://maps.app.goo.gl/JozhfVUUum1tH1nC8', 'distribuidores/lapaz/risol-lapaz-centenario.jpg', 16),
    ('Ferreteria el Alce', 'Margarita', 'La Paz', 'Blvrd Margarita Maza de Juárez 2820, Lic Benito Juárez, 23090 La Paz, B.C.S.', 'https://maps.app.goo.gl/Z4Dg8aFr7Q2Dp9UG7', 'distribuidores/lapaz/elAlce-lapaz-margaritaMaza.JPG', 17),
    ('Ferreteria el Arco (Abasolo)', 'Mariano Abasolo', 'La Paz', 'Mariano Abasolo, Pueblo Nuevo, 23060 La Paz, B.C.S.', 'https://maps.app.goo.gl/7otrRZsbejiNgPMV9', 'distribuidores/lapaz/elArco-lapaz-Abasolo.JPG', 18),
    ('Ferreteria el Gallo (Colosio)', 'Colosio', 'La Paz', 'Luis Donaldo Colosio entre Ignacio Ramirez y Guillermo Prieto, Sector Inalpa, 23098 La Paz, B.C.S.', 'https://maps.app.goo.gl/PKkvDvNg3sqJxULp6', 'distribuidores/lapaz/elgallo-laPaz-colosio.jpg', 19),
    ('Ferreteria el Gallo (Centro)', 'Centro', 'La Paz', '16 de Septiembre Esq. Aquiles Serdan S/N, Colonia Centro, 23000 La Paz, B.C.S.', 'https://maps.app.goo.gl/r6L2HFouQZDBG3f99', 'distribuidores/lapaz/elgallo-laPaz-elCentro.jpg', 20),
    ('Ferremateriales sur', 'Colegio Militar', 'La Paz', 'Heroico Colegio Militar 880, Ladrillera, 23020 La Paz, B.C.S.', 'https://maps.app.goo.gl/eu2gTf3SSgUEvU1T6', 'distribuidores/lapaz/ferrematerialessur-lapaz-heoricoColegiomitiliar.jpg', 21),
    ('Ferrequim', 'Lopez Mateos', 'La Paz', 'CALLE ADOLFO LOPEZ MATEOS # 2755 , ENTRE 5 DE FEBRERO, Antonio Navarro Y, Los Olivos, 23040 La Paz, B.C.S.', 'https://maps.app.goo.gl/h9ktfAbHZuUn6Cpa6', 'distribuidores/lapaz/ferrequim-lapaz-lopezMateos.jpg', 22),
    ('Ferreteria Hermosillo (Camino Real)', 'Camino Real', 'La Paz', 'Calz. El Camino Real s/n, Camino Real, 23088 La Paz, B.C.S.', 'https://maps.app.goo.gl/pcEZ7RuEXGR28i5r9', 'distribuidores/lapaz/ferrHermosillo-lapaz-caminoreal.jpg', 23),
    ('Ferreteria Hermosillo (Transpeninsular)', 'Transpeninsular', 'La Paz', 'Carr. Transpeninsular, Ayuntamiento, 23088 La Paz, B.C.S.', 'https://maps.app.goo.gl/Ee53PjxXeHpRaL7y5', 'distribuidores/lapaz/ferrHermosillo-lapaz-transpeninsular.jpg', 24),
    ('Idicom (Abasolo)', 'Abasolo', 'La Paz', 'Av. Constituyentes entre Politécnico y Mar Caribe, Cactus Harinera, 23090 La Paz, B.C.S.', 'https://maps.app.goo.gl/JWCCrRd149KunZCLA', 'distribuidores/lapaz/idicom-lapaz-abasolo.JPG', 25),
    ('Idicom (Josefa Ortiz)', 'Josefa Ortiz', 'La Paz', 'Josefa Ortiz de Domínguez 2845, Pueblo Nuevo, 23060 La Paz, B.C.S.', 'https://maps.app.goo.gl/TryGS211nuGh2BUD7', 'distribuidores/lapaz/idicom-lapaz-ortiz.JPG', 26),
    ('Ferreteria los Joaquines (Diana Laura)', 'Av Diana Laura (Transpeninsular)', 'La Paz', 'Diana Laura, 23084 La Paz, B.C.S.', 'https://maps.app.goo.gl/nZagPpdU42vxguuN7', 'distribuidores/lapaz/losJoaquines-lapaz-dianaLaura.jpg', 27),
    ('Ferreteria los Joaquines (Los Olivos)', 'Los Olivos', 'La Paz', 'Gral Manuel M. Dieguez 2190, Los Olivos, 23040 La Paz, B.C.S.', 'https://maps.app.goo.gl/cjtxFQebqN9hy8uFA', 'distribuidores/lapaz/losJoaquines-lapaz-losOlivos.jpg', 28),
    ('Ferreteria Orduño', 'Blvd. Constituyentes', 'La Paz', 'Blvd. Constituyentes de 1975 5155, La Esperanza I, 23090 La Paz, B.C.S.', 'https://maps.app.goo.gl/1oqhDFbeqHHQLxCd8', 'distribuidores/lapaz/ordunio-lapaz-constituyentes.jpeg', 29),
    ('Polibaños', '16 septiembre', 'La Paz', '16 de Septiembre 600, Zona Central, 23000 La Paz, B.C.S.', 'https://maps.app.goo.gl/o7Bnkvqx3uUA1nKDA', 'distribuidores/lapaz/polibanos-lapaz-16septiembre.jpg', 30),
    ('Fymsa (Lagunitas)', 'Lagunitas', 'Cabo San Lucas', 'Av. Leona Vicario e/Lagunitas l y Lagunitas ll, Col del Sol, 23477 Cabo San Lucas, B.C.S.', 'https://maps.app.goo.gl/XNeu8sWJeNvfH2zAA', 'distribuidores/sanLucas/fymsa-sanLucas-lagunitas.JPG', 31),
    ('Fymsa (El arenal)', 'El arenal', 'Cabo San Lucas', '16 de Septiembre, El Arenal, 23460 Cabo San Lucas, B.C.S.', 'https://maps.app.goo.gl/3hfUDmfVjc3gJpms6', 'distribuidores/sanLucas/fymsa-sanLucas-sanLucas.JPG', 32),
    ('Aceros del Pacifico (San Lucas)', 'Flor Pitahaya', 'Cabo San Lucas', 'Flor de Pitahaya Lote 25, Brisas del Pacifico, 23473 Cabo San Lucas, B.C.S.', 'https://maps.app.goo.gl/UDu1Z54yG5agP35e7', 'distribuidores/sanLucas/AcerosPacifico-sanLucas-florPita.png', 33),
    ('Aceros del Pacifico (Tamaral)', 'Tamaral', 'Cabo San Lucas', 'Blvd Palma Abanico, Padre Nicolás Tamaral Esq, 23477 Cabo San Lucas, B.C.S.', 'https://maps.app.goo.gl/c1nzBQEQ2w6G7FJPA', 'distribuidores/sanLucas/AcerosPacifico-sanLucas-tamaral.jpg', 34),
    ('Materiales Vicons', 'Av. Leona Vicario', 'Cabo San Lucas', 'Avenida Leona Vicario esq, Isla Cozumel mza 90-Lote 1, Invi Caribe, Cabo San Lucas, B.C.S.', 'https://maps.app.goo.gl/g8KWw86VTwLHRcQJ9', 'distribuidores/sanLucas/viconsMat-sanLucas.jpg', 35),
    ('Fymsa (San Jose Transpeninsular)', 'San Jose, transpeninsular', 'San Jose del Cabo', 'Carretera Transpeninsular Federal CSL-SJC, Predio la Sonoreña, Sta Rosa, 23428 San José del Cabo, B.C.S.', 'https://maps.app.goo.gl/aJUbSp2uHqi82eB88', 'distribuidores/sanJose/fymsa-sanJose-transpeninsular.jpg', 36),
    ('Fymsa (Las veredas)', 'San Jose, Las veredas', 'San Jose del Cabo', 'Av. la Floresta, Las Veredas, 23435 Las Veredas, B.C.S.', 'https://maps.app.goo.gl/y3FF9qX6VLZ2LTBN9', 'distribuidores/sanJose/fymsa-sanJose-lasVeredas.jpeg', 37),
    ('Aceros del Pacifico (San Jose)', 'San José Centro', 'San Jose del Cabo', 'Julio Pimentel Green manzana 7c lote s/n Colonia Las Veredas, 23435 San José del Cabo, B.C.S.', 'https://maps.app.goo.gl/SGfTSC9rdKZiMXpb9', 'distribuidores/sanJose/AcerosPacifico-sanjose-centro.jpg', 38),
    ('Romex', 'Mangos', 'Todos Santos', 'ESQUINA CALLE MANGOS, Del Huerto 10, La Poza, 23300 Todos Santos, B.C.S.', 'https://maps.app.goo.gl/xiJj5RwhWxeCHvcy5', 'distribuidores/todoSantos/romex.png', 39),
    ('Grupo reforma (Matriz)', 'Reforma', 'Ciudad Constitución', 'Lic. Antonio Álvarez Rico S/N, San Martín, 23600 Cdad. Constitución, B.C.S.', 'https://maps.app.goo.gl/8Qcq5UHv35GThkzFA', 'distribuidores/cdConstitucion/reforma-constitucion-reforma.jpg', 40),
    ('Grupo reforma (Madero)', 'Madero', 'Ciudad Constitución', 'Francisco I. Madero 412, Los Pinos, 23670 Cdad. Constitución, B.C.S.', 'https://maps.app.goo.gl/yz1PbXGYybDHkP8u5', 'distribuidores/cdConstitucion/reforma-constitucion-madero.jpg', 41),
    ('Grupo reforma (Centro)', 'Loreto', 'Loreto', 'Calle Ayuntamiento, Centro, 23880 Loreto, B.C.S.', 'https://maps.app.goo.gl/pxhvK3gocUXMCzVE6', 'distribuidores/loreto/reforma-loreto.jpg', 42);

INSERT INTO distribuidores (empresa_id, nombre, sucursal, municipio, direccion, maps, imagen, orden)
SELECT e.id, t.nombre, t.sucursal, t.municipio, t.direccion, t.maps, t.imagen, t.orden
  FROM tmp_distribuidores t
  JOIN (SELECT MIN(id) AS id FROM empresas WHERE nombre = 'Soluciones en Acero') e ON e.id IS NOT NULL
 ORDER BY t.orden;

DROP TEMPORARY TABLE tmp_distribuidores;
