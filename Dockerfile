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

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-interaction \
      --no-scripts \
      --prefer-dist \
      --optimize-autoloader

# ---------------------------------------------------------------------------
# Etapa 2: runtime Apache + PHP
# ---------------------------------------------------------------------------
FROM php:8.3-apache

# Extensiones PHP:
#   mysqli → conexión a la base (config/database.php)
#   gd     → Intervention Image (recorte cuadrado de fotos de productos)
#   zip    → dompdf genera PDFs comprimidos
#   exif   → lectura de metadatos de imágenes subidas
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libpng-dev \
      libjpeg62-turbo-dev \
      libfreetype6-dev \
      libzip-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" mysqli gd zip exif \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Enrutamiento del front controller (el proyecto trae su propio .htaccess).
RUN a2enmod rewrite headers

# Zona horaria y límites razonables para subida de fotos y generación de PDFs.
RUN { \
      echo 'date.timezone = America/Mexico_City'; \
      echo 'upload_max_filesize = 20M'; \
      echo 'post_max_size = 25M'; \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 120'; \
    } > /usr/local/etc/php/conf.d/cotizaciones.ini

WORKDIR /var/www/html

# Código de la aplicación (lo que excluye .dockerignore no llega aquí).
COPY --chown=www-data:www-data . /var/www/html

# Dependencias generadas en la etapa 1 (machaca el vendor/ copiado, si hubiera).
COPY --from=composer --chown=www-data:www-data /app/vendor /var/www/html/vendor

# Carpetas escribibles por Apache: imágenes subidas y log de correos.
# NOTA: public/image se monta como volumen persistente en Kubernetes;
# este chown sólo aplica a la imagen base (precarga del initContainer).
RUN chown -R www-data:www-data /var/www/html/public/image /var/www/html/storage \
 && chmod -R ug+rwX /var/www/html/public/image /var/www/html/storage

EXPOSE 80
