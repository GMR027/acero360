# ===========================================================================
# Dockerfile — App de cotizaciones (PHP 8.3 + Apache)
#
# Multi-etapa:
#   1. composer  → instala dependencias de producción (vendor/)
#   2. runtime   → php:8.3-apache con mysqli/gd/zip y el código
#
# Build:   docker build -t gmr27/cotizaciones:<tag> .
# El DocumentRoot es la raíz del proyecto (el .htaccess ya enruta a index.php).
# ===========================================================================

# ---------------------------------------------------------------------------
# Etapa 1: dependencias de Composer (sin dev, autoloader optimizado)
# ---------------------------------------------------------------------------
FROM composer:2 AS composer
# ext-gd no existe en esta imagen de composer pero sí en el runtime (etapa 2); por eso se ignora aquí.

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-interaction \
      --no-scripts \
      --prefer-dist \
      --optimize-autoloader \
      --ignore-platform-req=ext-gd

# ---------------------------------------------------------------------------
# Etapa 2: runtime Apache + PHP
# ---------------------------------------------------------------------------
FROM php:8.3-apache

# Extensiones PHP:
#   mysqli → conexión a la base (config/database.php)
#   gd     → Intervention Image (recorte cuadrado de fotos de productos)
#   zip    → dompdf genera PDFs comprimidos
#   exif   → lectura de metadatos de imágenes subidas
# Herramientas del sistema:
#   default-mysql-client → mysqldump y mysql: los respaldos se hacen DENTRO del clúster.
#   gzip/tar/sha256sum   → empaquetado y verificación de cada copia (vienen en la base, se declaran por claridad).
#   poppler-utils        → pdftotext/pdftoppm para leer facturas.
#   tesseract-ocr        → OCR de comprobantes escaneados.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      default-mysql-client \
      gzip \
      tar \
      libpng-dev \
      libjpeg62-turbo-dev \
      libfreetype6-dev \
      libzip-dev \
      poppler-utils \
      tesseract-ocr \
      tesseract-ocr-spa \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" mysqli gd zip exif \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Enrutamiento del front controller (el proyecto trae su propio .htaccess).
RUN a2enmod rewrite headers

# Zona horaria y límites razonables para subida de fotos y generación de PDFs.
RUN { \
      echo 'date.timezone = America/Mexico_City'; \
      echo 'upload_max_filesize = 512M'; \
      echo 'post_max_size = 512M'; \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 600'; \
      echo 'max_input_time = 600'; \
    } > /usr/local/etc/php/conf.d/cotizaciones.ini

WORKDIR /var/www/html

# Código de la aplicación (lo que excluye .dockerignore no llega aquí).
COPY --chown=www-data:www-data . /var/www/html

# Dependencias generadas en la etapa 1 (machaca el vendor/ copiado, si hubiera).
COPY --from=composer --chown=www-data:www-data /app/vendor /var/www/html/vendor

# Datos de esta compilación (los pasa deploy.sh con --build-arg). La pantalla Ajustes → Versión los lee de aquí.
ARG ACERO_VERSION=desconocida
ARG ACERO_IMAGEN=gmr27/cotizaciones
ARG ACERO_TAG=
ARG ACERO_COMMIT=
ARG ACERO_FECHA=
RUN printf '{"version":"%s","imagen":"%s","tag":"%s","commit":"%s","compilado":"%s"}\n' \
      "$ACERO_VERSION" "$ACERO_IMAGEN" "$ACERO_TAG" "$ACERO_COMMIT" "$ACERO_FECHA" > /var/www/html/build-info.json

# Carpetas escribibles por Apache: imágenes subidas.
# NOTA: public/image se monta como volumen persistente en Kubernetes;
# este chown sólo aplica a la imagen base (precarga del initContainer).
RUN chown -R www-data:www-data /var/www/html/public/image /var/www/html/storage \
 && chmod -R ug+rwX /var/www/html/public/image /var/www/html/storage

EXPOSE 80
