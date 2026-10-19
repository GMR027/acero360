-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: cotizaciones
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.24.04.4

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `cotizaciones`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `cotizaciones` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `cotizaciones`;

--
-- Table structure for table `categorias_proveedor`
--

DROP TABLE IF EXISTS `categorias_proveedor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorias_proveedor` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT COMMENT '0 = sin categoría (no existe como fila)',
  `nombre` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nombre visible de la categoría de compra',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categorias_proveedor_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Catálogo de categorías de compra, compartido por todos los proveedores';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categorias_proveedor`
--

LOCK TABLES `categorias_proveedor` WRITE;
/*!40000 ALTER TABLE `categorias_proveedor` DISABLE KEYS */;
INSERT INTO `categorias_proveedor` VALUES (18,'Acero','2026-10-05 18:25:04'),(19,'Redondos','2026-10-05 18:25:10'),(20,'Materia Prima','2026-10-05 18:25:19'),(21,'Producto','2026-10-05 18:25:34'),(22,'Equipo PP','2026-10-05 18:26:03'),(23,'Pintura','2026-10-05 18:26:13'),(24,'Fronteras','2026-10-05 18:26:18'),(25,'Registros','2026-10-05 18:26:30'),(26,'Insumos de corte','2026-10-05 18:26:37'),(27,'Insumos Soldadura','2026-10-05 18:26:43');
/*!40000 ALTER TABLE `categorias_proveedor` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clientes`
--

DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Persona de contacto',
  `nombre_empresa` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Razón social o nombre del negocio',
  `alias` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nombre corto para buscar rápido',
  `rfc` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ubicacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Foto de perfil redonda (archivo en public/image)',
  `vip` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Cliente frecuente',
  `descuento_pct` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'Descuento global aplicado a sus cotizaciones',
  `notas` text COLLATE utf8mb4_unicode_ci,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clientes_vip` (`vip`),
  KEY `idx_clientes_alias` (`alias`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clientes`
--

LOCK TABLES `clientes` WRITE;
/*!40000 ALTER TABLE `clientes` DISABLE KEYS */;
INSERT INTO `clientes` VALUES (3,'Cliente Demo 1','Empresa Demo 1','','','','','','',1,0.00,'','2026-10-05 15:21:51'),(4,'Cliente Demo 2','N/A','','','','','','',0,0.00,'','2026-10-05 15:22:21'),(5,'Cliente Demo 3','N/A','','','','','','',1,0.00,'','2026-10-05 15:22:34'),(6,'Cliente Demo 4','Cliente Demo 4','','','','','','',1,0.00,'','2026-10-05 15:22:45'),(7,'Cliente Demo 5','Empresa Demo 2','','','','','','',0,0.00,'','2026-10-05 15:23:10'),(8,'Cliente Demo 6','Empresa Demo 3','','','','','','',0,0.00,'','2026-10-05 15:23:24'),(9,'Cliente Demo 7','Cliente Demo 7','','','','','','',0,0.00,'','2026-10-05 15:23:38'),(10,'Cliente Demo 8','N/A','','','','','','',0,0.00,'','2026-10-05 15:23:48'),(11,'Cliente Demo 9','Empresa Demo 4','','','','','','',0,0.00,'','2026-10-05 15:24:01'),(12,'Cliente Demo 10','N/A','','','','','','',0,0.00,'','2026-10-05 15:24:09'),(13,'Cliente Demo 11','Empresa Demo 5','','','','','','',0,0.00,'','2026-10-05 15:24:18'),(14,'Cliente Demo 12','Empresa Demo 6','','','','','','',0,0.00,'','2026-10-05 15:24:27'),(15,'Cliente Demo 13','Empresa Demo 7','','','','','','',0,0.00,'','2026-10-05 15:24:37');
/*!40000 ALTER TABLE `clientes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cotizacion_detalles`
--

DROP TABLE IF EXISTS `cotizacion_detalles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizacion_detalles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int unsigned NOT NULL,
  `producto_id` int unsigned NOT NULL,
  `cantidad` int NOT NULL DEFAULT '1',
  `precio_unitario` decimal(12,2) NOT NULL COMMENT 'Lista O precio especial',
  `descuento_pct` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'Descuento del renglón',
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_detalle_cotizacion` (`cotizacion_id`),
  KEY `fk_detalle_producto` (`producto_id`),
  CONSTRAINT `fk_detalle_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_detalle_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=138 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cotizacion_detalles`
--

LOCK TABLES `cotizacion_detalles` WRITE;
/*!40000 ALTER TABLE `cotizacion_detalles` DISABLE KEYS */;
/*!40000 ALTER TABLE `cotizacion_detalles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cotizaciones`
--

DROP TABLE IF EXISTS `cotizaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizaciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Ej. FOL-2026-0001',
  `empresa_id` int unsigned NOT NULL COMMENT 'Negocio emisor',
  `cliente_id` int unsigned NOT NULL COMMENT 'Cliente destino',
  `usuario_id` int unsigned NOT NULL COMMENT 'Admin que la creó',
  `fecha` date NOT NULL,
  `vigencia_dias` tinyint NOT NULL DEFAULT '15',
  `estado` enum('pendiente','vendido','cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `descuento` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Descuento global aplicado',
  `aplica_iva` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'IVA 16% activo o no',
  `iva` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `condiciones_envio` text COLLATE utf8mb4_unicode_ci COMMENT 'Indicaciones de entrega',
  `condiciones_compra` text COLLATE utf8mb4_unicode_ci COMMENT 'Indicaciones de compra',
  `comentarios` text COLLATE utf8mb4_unicode_ci COMMENT 'Comentarios adicionales',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cotizacion_folio` (`folio`),
  KEY `idx_cotizaciones_cliente` (`cliente_id`),
  KEY `idx_cotizaciones_empresa` (`empresa_id`),
  KEY `idx_cotizaciones_fecha` (`fecha`),
  KEY `fk_cotizacion_usuario` (`usuario_id`),
  CONSTRAINT `fk_cotizacion_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_cotizacion_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_cotizacion_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cotizaciones`
--

LOCK TABLES `cotizaciones` WRITE;
/*!40000 ALTER TABLE `cotizaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `cotizaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empresas`
--

DROP TABLE IF EXISTS `empresas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `empresas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rfc` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `regimen` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Régimen fiscal SAT',
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cp` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Archivo en public/image/',
  `condiciones_envio` text COLLATE utf8mb4_unicode_ci COMMENT 'Texto por defecto en las cotizaciones',
  `condiciones_compra` text COLLATE utf8mb4_unicode_ci COMMENT 'Texto por defecto en las cotizaciones',
  `activa` tinyint(1) NOT NULL DEFAULT '1',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empresas_activa` (`activa`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empresas`
--

LOCK TABLES `empresas` WRITE;
/*!40000 ALTER TABLE `empresas` DISABLE KEYS */;
INSERT INTO `empresas` VALUES (1,'Soluciones en Acero','DEMO000101A01','612','Av. Ejemplo 123','La Paz','Baja California Sur','','612 000 0000','contacto@ejemplo.com','logo-7ff6bf8ee1.png','Entregamos a través de nuestros distribuidores en los municipios de Baja California Sur: Loreto, Ciudad Insurgentes, La Paz, Todos Santos, Cabo San Lucas y San José del Cabo. *Consulta las restricciones de entrega y disponibilidad según tu solicitud.','Los precios están expresados en pesos mexicanos (MXN) y pueden modificarse sin previo aviso. Para confirmar tu pedido envía el comprobante de pago junto con la orden de compra por WhatsApp o correo electrónico.',1,'2026-10-03 23:46:04'),(2,'Canastillas de la Baja','DEMO000101A01','612','Av. Ejemplo 123','La Paz','Baja California Sur','23088','66 000 0000','contacto@ejemplo.com','logo-f88406d139.png','Envío a toda la república por paquetería. Gratis en pedidos de 20 piezas o más.','Crédito a 30 días para clientes con historial. Anticipo del 30% en pedidos nuevos.',1,'2026-10-03 23:25:19');
/*!40000 ALTER TABLE `empresas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `movimientos_inventario`
--

DROP TABLE IF EXISTS `movimientos_inventario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimientos_inventario` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` int unsigned NOT NULL,
  `tipo` enum('entrada','salida','ajuste') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'entrada = carga manual, salida = venta, ajuste = corrección',
  `cantidad` int NOT NULL COMMENT 'Siempre positiva; el signo lo define tipo',
  `saldo` int NOT NULL DEFAULT '0' COMMENT 'Existencias después del movimiento',
  `cotizacion_id` int unsigned DEFAULT NULL COMMENT 'Cotización que originó la salida',
  `usuario_id` int unsigned DEFAULT NULL COMMENT 'Administrador que registró el movimiento',
  `notas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movimiento_producto` (`producto_id`),
  KEY `idx_movimiento_cotizacion` (`cotizacion_id`),
  KEY `idx_movimiento_fecha` (`creado_en`),
  KEY `fk_movimiento_usuario` (`usuario_id`),
  CONSTRAINT `fk_movimiento_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_movimiento_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_movimiento_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `movimientos_inventario`
--

LOCK TABLES `movimientos_inventario` WRITE;
/*!40000 ALTER TABLE `movimientos_inventario` DISABLE KEYS */;
INSERT INTO `movimientos_inventario` VALUES (1,50,'entrada',2,2,NULL,NULL,'Carga manual desde Inventario','2026-10-04 15:36:39'),(2,50,'entrada',3,5,NULL,NULL,'Carga manual desde Inventario','2026-10-04 15:36:55'),(3,51,'entrada',2,2,NULL,NULL,'Carga manual desde Inventario','2026-10-04 15:37:02'),(4,49,'entrada',333,333,NULL,NULL,'Carga manual desde Inventario','2026-10-04 15:37:06'),(39,49,'salida',1,332,NULL,NULL,'Salida automática por la venta del folio FOL-2026-0008','2026-10-04 16:13:31'),(40,38,'salida',1,-1,NULL,NULL,'Salida automática por la venta del folio FOL-2026-0008','2026-10-04 16:13:31'),(41,39,'salida',1,-1,NULL,NULL,'Salida automática por la venta del folio FOL-2026-0008','2026-10-04 16:13:31'),(82,37,'salida',20,-20,NULL,3,'Salida automática por la venta del folio FOL-2026-0009','2026-10-04 19:23:27'),(83,38,'salida',10,-11,NULL,3,'Salida automática por la venta del folio FOL-2026-0009','2026-10-04 19:23:27'),(84,39,'salida',5,-6,NULL,3,'Salida automática por la venta del folio FOL-2026-0009','2026-10-04 19:23:27'),(85,38,'entrada',44,33,NULL,3,'Carga manual desde Inventario','2026-10-04 19:27:03'),(86,50,'salida',300,-295,NULL,3,'Salida automática por la venta del folio FOL-2026-0010','2026-10-05 11:10:07'),(87,37,'salida',10,-30,NULL,3,'Salida automática por la venta del folio FOL-2026-0011','2026-10-05 11:13:01'),(88,39,'salida',5,-11,NULL,3,'Salida automática por la venta del folio FOL-2026-0011','2026-10-05 11:13:01'),(89,42,'salida',3,-3,NULL,3,'Salida automática por la venta del folio FOL-2026-0011','2026-10-05 11:13:01'),(90,51,'entrada',1,3,NULL,3,'Carga manual desde Inventario','2026-10-05 11:20:19'),(91,49,'entrada',1,333,NULL,3,'Se devuelve el stock del folio FOL-2026-0008 (ya no está vendido)','2026-10-05 14:45:05'),(92,38,'entrada',1,34,NULL,3,'Se devuelve el stock del folio FOL-2026-0008 (ya no está vendido)','2026-10-05 14:45:05'),(93,39,'entrada',1,-10,NULL,3,'Se devuelve el stock del folio FOL-2026-0008 (ya no está vendido)','2026-10-05 14:45:05'),(94,37,'entrada',10,-20,NULL,3,'Se devuelve el stock del folio FOL-2026-0011 (cotización eliminada)','2026-10-05 15:20:20'),(95,39,'entrada',5,-5,NULL,3,'Se devuelve el stock del folio FOL-2026-0011 (cotización eliminada)','2026-10-05 15:20:20'),(96,42,'entrada',3,0,NULL,3,'Se devuelve el stock del folio FOL-2026-0011 (cotización eliminada)','2026-10-05 15:20:20'),(97,50,'entrada',300,5,NULL,3,'Se devuelve el stock del folio FOL-2026-0010 (cotización eliminada)','2026-10-05 15:20:22'),(98,37,'entrada',20,0,NULL,3,'Se devuelve el stock del folio FOL-2026-0009 (cotización eliminada)','2026-10-05 15:20:23'),(99,38,'entrada',10,44,NULL,3,'Se devuelve el stock del folio FOL-2026-0009 (cotización eliminada)','2026-10-05 15:20:23'),(100,39,'entrada',5,0,NULL,3,'Se devuelve el stock del folio FOL-2026-0009 (cotización eliminada)','2026-10-05 15:20:23'),(101,36,'entrada',10,10,NULL,3,'Se devuelve el stock del folio FOL-2026-0003 (cotización eliminada)','2026-10-05 15:20:29'),(102,42,'entrada',32,32,NULL,3,'Se devuelve el stock del folio FOL-2026-0003 (cotización eliminada)','2026-10-05 15:20:29'),(103,47,'entrada',2,2,NULL,3,'Se devuelve el stock del folio FOL-2026-0003 (cotización eliminada)','2026-10-05 15:20:29'),(104,36,'entrada',5,15,NULL,3,'Se devuelve el stock del folio FOL-2026-0001 (cotización eliminada)','2026-10-05 15:20:32'),(105,46,'entrada',1,1,NULL,3,'Se devuelve el stock del folio FOL-2026-0001 (cotización eliminada)','2026-10-05 15:20:32'),(106,49,'entrada',2,335,NULL,3,'Se devuelve el stock del folio FOL-2026-0001 (cotización eliminada)','2026-10-05 15:20:32'),(107,50,'entrada',3,8,NULL,3,'Carga manual desde Inventario','2026-10-05 15:25:18'),(108,50,'entrada',232,240,NULL,3,'Carga manual desde Inventario','2026-10-05 15:25:30'),(109,51,'ajuste',3,0,NULL,3,'Carga manual desde Inventario','2026-10-05 15:25:41'),(110,49,'entrada',2,337,NULL,3,'Carga manual desde Inventario','2026-10-05 15:25:54'),(111,50,'entrada',80,320,NULL,3,'Carga manual desde Inventario','2026-10-05 15:26:05'),(112,52,'entrada',100,100,NULL,3,'Carga manual desde Inventario','2026-10-05 15:26:17'),(113,37,'entrada',357,357,NULL,3,'Carga manual desde Inventario','2026-10-05 15:27:09'),(114,39,'entrada',181,181,NULL,3,'Carga manual desde Inventario','2026-10-05 15:27:21'),(115,40,'entrada',123,123,NULL,3,'Carga manual desde Inventario','2026-10-05 15:27:29'),(116,45,'entrada',60,60,NULL,3,'Carga manual desde Inventario','2026-10-05 15:27:52'),(117,46,'entrada',27,28,NULL,3,'Carga manual desde Inventario','2026-10-05 15:28:00'),(118,47,'entrada',18,20,NULL,3,'Carga manual desde Inventario','2026-10-05 15:28:14'),(119,48,'entrada',2,2,NULL,3,'Carga manual desde Inventario','2026-10-05 15:28:19'),(120,43,'entrada',9,9,NULL,3,'Carga manual desde Inventario','2026-10-05 15:28:34'),(121,42,'entrada',14,46,NULL,3,'Carga manual desde Inventario','2026-10-05 15:28:51'),(122,36,'ajuste',7,8,NULL,3,'Carga manual desde Inventario','2026-10-05 15:29:24'),(123,38,'ajuste',20,24,NULL,3,'Carga manual desde Inventario','2026-10-05 15:29:37'),(124,42,'ajuste',30,16,NULL,3,'Carga manual desde Inventario','2026-10-05 15:31:52'),(125,43,'ajuste',4,5,NULL,3,'Carga manual desde Inventario','2026-10-05 15:32:23');
/*!40000 ALTER TABLE `movimientos_inventario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notas`
--

DROP TABLE IF EXISTS `notas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int unsigned NOT NULL COMMENT 'Propietario de la nota',
  `titulo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cuerpo` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `categoria` enum('recordatorio','cotizar','cambios','revisar','otro') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'otro',
  `referencia` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cliente, contacto o catálogo asociado',
  `fecha_recordatorio` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Etiqueta de vencimiento mostrada en la tarjeta',
  `fijada` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Fijada arriba del tablero',
  `completada` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Hecha / archivada',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notas_usuario` (`usuario_id`),
  KEY `idx_notas_categoria` (`categoria`),
  KEY `idx_notas_fijada` (`fijada`),
  CONSTRAINT `fk_nota_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notas`
--

LOCK TABLES `notas` WRITE;
/*!40000 ALTER TABLE `notas` DISABLE KEYS */;
/*!40000 ALTER TABLE `notas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `precios_cliente`
--

DROP TABLE IF EXISTS `precios_cliente`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `precios_cliente` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int unsigned NOT NULL,
  `producto_id` int unsigned NOT NULL,
  `precio` decimal(12,2) NOT NULL COMMENT 'Precio acordado (MXN)',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_precio_cliente_producto` (`cliente_id`,`producto_id`),
  KEY `fk_precio_producto` (`producto_id`),
  CONSTRAINT `fk_precio_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_precio_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `precios_cliente`
--

LOCK TABLES `precios_cliente` WRITE;
/*!40000 ALTER TABLE `precios_cliente` DISABLE KEYS */;
/*!40000 ALTER TABLE `precios_cliente` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `productos`
--

DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `productos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` int unsigned NOT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoria` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unidad` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pza',
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `precio` decimal(12,2) NOT NULL COMMENT 'Precio de lista (MXN)',
  `stock` int NOT NULL DEFAULT '0' COMMENT 'Existencias en almacén; puede quedar negativo si se vendió antes de cargarlo',
  `imagen` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Archivo cuadrado en public/image/',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_productos_empresa` (`empresa_id`),
  CONSTRAINT `fk_productos_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `productos`
--

LOCK TABLES `productos` WRITE;
/*!40000 ALTER TABLE `productos` DISABLE KEYS */;
INSERT INTO `productos` VALUES (36,1,'Registro de ángulo 40x40 cm','RA-1','Registros de ángulo','pza','Registro compacto de ángulo.\r\nDimensión: 40x40 cm. Marco: 1 1/2\". Contramarco: 1 1/4\". Armado interior: Varillatec 1/4\". Acabado: primer rojo.\r\nPatas de anclaje en la parte posterior; división de armado: 13.3 cm.\r\nTipo de uso: ideal para instalaciones eléctricas o de telecomunicaciones.',400.00,8,'producto-ra1.jpg',1,'2026-10-04 07:57:34'),(37,1,'Registro de ángulo 40x60 cm','RA-3','Registros de ángulo','pza','Registro reforzado de ángulo.\r\nDimensión: 40x60 cm. Marco: 1 1/4\". Contramarco: 1\". Armado interior: Varillatec 1/4\". Acabado: primer rojo.\r\nArmado de largos: 13.3 cm, cortos: 15 cm.\r\nTipo de uso: ideal para instalaciones sanitarias.',400.00,357,'producto-ra3.jpg',1,'2026-10-04 07:57:34'),(38,1,'Registro de ángulo 40x60 cm con coladera','RA-4','Registros de ángulo','pza','Registro reforzado de ángulo con coladera.\r\nDimensión: 40x60 cm. Marco: 1 1/4\". Contramarco: 1\". Armado interior: Varillatec 1/4\". Acabado: primer rojo.\r\nColadera armada con varillatec; armado de largos: 13.3 cm, cortos: 15 cm.\r\nTipo de uso: ideal para áreas de desagüe de líquidos.',580.00,24,'producto-ra4.jpg',1,'2026-10-04 07:57:34'),(39,1,'Registro de ángulo 47x67 cm','RA-5','Registros de ángulo','pza','Registro grande de ángulo.\r\nDimensión: 47x67 cm. Marco: 1 1/2\". Contramarco: 1 1/4\". Armado interior: Varillatec 1/4\". Acabado: primer gris.\r\nArmado de largos: 15.5 cm, cortos: 13.4 cm.\r\nTipo de uso: ideal para instalaciones sanitarias donde el registro de block requiera una medida interior libre de 40x60 cm.',580.00,181,'producto-ra5.jpg',1,'2026-10-04 07:57:34'),(40,1,'Registro de ángulo 60x60 cm','RA-6','Registros de ángulo','pza','Registro cuadrado de ángulo.\r\nDimensión: 60x60 cm. Marco: 1 1/4\". Contramarco: 1\". Armado interior: Varillatec 1/4\". Acabado: primer rojo.\r\nSeparación de armado: 12 cm.\r\nTipo de uso: ideal para instalaciones sanitarias y para cisternas (no de la marca Rotoplas) con tráfico ligero.',487.00,123,'producto-ra6.jpg',1,'2026-10-04 07:57:34'),(42,1,'Registro de solera 40x60 cm','RS-1','Registros de solera','pza','Registro de solera.\r\nDimensión: 40x60 cm. Marco: 2\". Contramarco: 2\". Armado interior: Varillatec 1/4\". Acabado: primer rojo.\r\nArmado de largos: 13.3 cm, cortos: 15 cm.\r\nTipo de uso: ideal para instalaciones sanitarias.',400.00,16,'producto-rs1.jpg',1,'2026-10-04 07:57:34'),(43,1,'Registro de solera 65x65 cm','RS-2','Registros de solera','pza','Registro de solera para tráfico pesado.\r\nDimensión: 65x65 cm. Marco: 2\". Contramarco: 2\". Armado interior: Varillatec 5/16\". Acabado: primer rojo.\r\nSeparación de armado interior: 13 cm.\r\nTipo de uso: ideal para cisternas (no de la marca Rotoplas) e instalaciones sanitarias con tráfico pesado.',539.00,5,'producto-rs2.jpg',1,'2026-10-04 07:57:34'),(44,1,'Registro de solera 75x75 cm','RS-3','Registros de solera','pza','Registro de solera de gran tamaño.\r\nDimensión: 75x75 cm. Marco: 2\". Contramarco: 2\". Armado interior: Varillatec 5/16\". Acabado: primer gris.\r\nSeparación de armado: 12.5 cm.\r\nTipo de uso: ideal para cisternas de la marca Rotoplas e instalaciones sanitarias con tráfico pesado.',777.00,0,'producto-rs3.jpg',1,'2026-10-04 07:57:34'),(45,1,'Tapa metálica 60x60 cm sencilla','TM-1','Tapas metálicas','pza','Tapa metálica sencilla.\r\nDimensión: 60x60 cm. Color: gris. Acabado: primer gris.\r\nTipo de uso: ideal para cisternas (no de la marca Rotoplas) con tráfico ligero.',719.00,60,'producto-tm1.jpg',1,'2026-10-04 07:57:34'),(46,1,'Tapa metálica 60x60 cm reforzada','TM-2','Tapas metálicas','pza','Tapa metálica reforzada.\r\nDimensión: 60x60 cm. Color: negra. Acabado: primer negro.\r\nTipo de uso: ideal para cisternas (no de la marca Rotoplas) con tráfico pesado.',1218.00,28,'producto-tm2.jpg',1,'2026-10-04 07:57:34'),(47,1,'Tapa metálica 70x70 cm sencilla','TM-3','Tapas metálicas','pza','Tapa metálica sencilla.\r\nDimensión: 70x70 cm. Color: gris. Acabado: primer gris.\r\nTipo de uso: ideal para cisternas de la marca Rotoplas con tráfico ligero.',928.00,20,'producto-tm3.jpg',1,'2026-10-04 07:57:34'),(48,1,'Tapa metálica 70x70 cm reforzada','TM-4','Tapas metálicas','pza','Tapa metálica reforzada.\r\nDimensión: 70x70 cm. Color: negra. Acabado: primer negro.\r\nTipo de uso: ideal para cisternas de la marca Rotoplas con tráfico pesado.',1740.00,2,'producto-tm4.jpg',1,'2026-10-04 07:57:34'),(49,1,'Base para boiler 40x40 cm','BB-1','Bases','pza','Base de lámina para boiler y bombas hidráulicas.\r\nDimensión: 40x40 cm. Base de lámina; soportes de ángulo. Acabado: primer gris (color gris).\r\nTipo de uso: ideal para recibir bases de boiler y bombas.',423.00,337,'producto-bb1.jpg',1,'2026-10-04 07:57:34'),(50,2,'Canastilla para 15 a 20cm (5 barras)','C-1','Canastillas','pza','CANASTILLA PASAJUNTAS C/5 VARILLAS LISAS DE DE DIMETRO. LONGITUD DE LA PASAJUNTA DE 43 CM, SEPARADAS ENTRE SI A CADA 30 CM. PAVIMENTO DE 15. DE ESPESOR',310.00,320,'producto-a9a3fa33be.jpg',1,'2026-10-04 08:00:32'),(51,2,'Marco y contramarco para Vialidad de 50x70cm','RA-7','Registros de ángulo','pza','Marco y contramarco de perfil de angulo de 50x70cm con varillatec 5/16\"',870.00,0,'producto-2426ab103a.jpg',1,'2026-10-04 08:03:47'),(52,2,'Canastila de 15 a 20 cm (4 barras)','C-3','Canastillas','pza','CANASTILLA PASAJUNTAS C/4 VARILLAS LISAS DE DE DIMETRO. LONGITUD DE LA PASAJUNTA DE 43 CM, SEPARADAS ENTRE S A CADA 30 CM. PAVIMENTO DE 15cm. DE ESPESOR',250.00,100,'producto-5d9b6adcc2.jpg',1,'2026-10-05 14:34:41');
/*!40000 ALTER TABLE `productos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proveedor_entradas`
--

DROP TABLE IF EXISTS `proveedor_entradas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedor_entradas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `proveedor_id` int unsigned NOT NULL,
  `fecha` date NOT NULL COMMENT 'Fecha de la entrega',
  `numero_pedido` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Número de pedido o remisión',
  `categoria` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Id en categorias_proveedor: 0 = sin categoría',
  `monto` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'Importe del pedido en pesos mexicanos (MXN)',
  `pagado` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = pedido liquidado, 0 = pendiente de pago',
  `descripcion` text COLLATE utf8mb4_unicode_ci COMMENT 'Descripción de lo recibido',
  `foto` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Foto de la entrega en public/image',
  `notas` text COLLATE utf8mb4_unicode_ci COMMENT 'Notas adicionales',
  `usuario_id` int unsigned NOT NULL COMMENT 'Admin que registró la entrada',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entrada_proveedor` (`proveedor_id`),
  KEY `idx_entrada_fecha` (`fecha`),
  KEY `fk_entrada_usuario` (`usuario_id`),
  CONSTRAINT `fk_entrada_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_entrada_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proveedor_entradas`
--

LOCK TABLES `proveedor_entradas` WRITE;
/*!40000 ALTER TABLE `proveedor_entradas` DISABLE KEYS */;
INSERT INTO `proveedor_entradas` VALUES (10,10,'2026-10-05','8',0,56840.00,0,'Fronteras para canastillas','','Registros del sistema de inventario pasado',3,'2026-10-05 15:11:10'),(12,10,'2026-10-05','9',0,56840.00,0,'Fronteras para canastillas','','Respaldo de info inventario pasado',3,'2026-10-05 15:41:38'),(13,10,'2026-10-05','AGO #21',0,44022.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:43:12'),(14,10,'2026-10-05','AGO #23',0,44370.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:49:35'),(15,10,'2026-10-05','AGO #24',0,50257.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:49:59'),(16,10,'2026-10-05','AGO #25',0,47345.40,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:50:23'),(17,10,'2026-10-05','AGO #26',0,42630.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:50:44'),(18,10,'2026-10-05','AGO #27',0,40924.80,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:51:04'),(19,10,'2026-10-05','AGO #28',0,48488.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:51:23'),(20,10,'2026-10-05','AGO #29',0,45588.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:51:41'),(21,10,'2026-10-05','AGO #30',0,48256.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:52:02'),(22,10,'2026-10-05','AGO #31',0,45791.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:52:23'),(23,10,'2026-10-05','AGO #32',0,42456.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:52:48'),(24,10,'2026-10-05','AGO #33',25,44080.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:53:10'),(25,10,'2026-10-05','AGO #34',25,42920.00,0,'Registros, respaldo de productos de inventario pasado','','',3,'2026-10-05 15:53:25');
/*!40000 ALTER TABLE `proveedor_entradas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proveedores`
--

DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rfc` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ubicacion` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_proveedor` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Material' COMMENT 'Material, Producto, Servicio u Otro',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proveedores_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proveedores`
--

LOCK TABLES `proveedores` WRITE;
/*!40000 ALTER TABLE `proveedores` DISABLE KEYS */;
INSERT INTO `proveedores` VALUES (10,'Proveedor Demo','','5500000001','Ciudad De Mexico','Producto','2026-10-05 15:08:19');
/*!40000 ALTER TABLE `proveedores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contrasena` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Hash bcrypt (password_hash)',
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Foto de perfil redonda (archivo en public/image)',
  `rol` tinyint NOT NULL DEFAULT '1' COMMENT '1 = administrador',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (3,'Usuario Demo','admin@ejemplo.com','','6120000001','perfilSinfoto.jpg',1,'2026-10-04 15:16:27'),(4,'Usuario Demo 2','usuario@ejemplo.com','','5500000002','perfilSinfoto.jpg',1,'2026-10-04 15:19:20');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ventas`
--

DROP TABLE IF EXISTS `ventas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ventas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int unsigned NOT NULL COMMENT 'Llave foránea a la cotización origen',
  `estado_previo` enum('pendiente','vendido','cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente' COMMENT 'Estado de la cotización antes de crear la venta',
  `fecha` date NOT NULL COMMENT 'Fecha de la entrega',
  `ubicacion` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Población de entrega',
  `ubicacion_otra` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Texto libre cuando ubicacion = Otro',
  `importe` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Importe cobrado (MXN)',
  `foto` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Foto de entrega en public/image',
  `notas` text COLLATE utf8mb4_unicode_ci COMMENT 'Notas adicionales de la venta',
  `usuario_id` int unsigned NOT NULL COMMENT 'Admin que registró la venta',
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_venta_cotizacion` (`cotizacion_id`),
  KEY `idx_ventas_fecha` (`fecha`),
  KEY `fk_venta_usuario` (`usuario_id`),
  CONSTRAINT `fk_venta_cotizacion` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_venta_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ventas`
--

LOCK TABLES `ventas` WRITE;
/*!40000 ALTER TABLE `ventas` DISABLE KEYS */;
/*!40000 ALTER TABLE `ventas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'cotizaciones'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-06 18:45:54
