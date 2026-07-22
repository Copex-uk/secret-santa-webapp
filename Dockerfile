# Secret Santa — PHP 8.3 + Apache
FROM php:8.3-apache

# Container timezone — override at build or run time (TZ in .env).
ARG TZ=Europe/London
ENV TZ=${TZ}

# Extensions: pdo_mysql for the DB, mbstring for length checks.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libonig-dev libjpeg62-turbo-dev libpng-dev curl tzdata \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install pdo_mysql mbstring gd exif \
 && apt-get purge -y --auto-remove libonig-dev libjpeg62-turbo-dev libpng-dev \
 && apt-get install -y --no-install-recommends libjpeg62-turbo libpng16-16 \
 && rm -rf /var/lib/apt/lists/*

# App public files -> webroot; private/ one level up (found by the
# pages' ascending lookup: /var/www/html -> /var/www/private).
COPY public_html/ /var/www/html/
COPY private/    /var/www/private/

# Writable dirs: wizard writes the config, users upload photos.
RUN mkdir -p /var/www/private/config /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/private/config /var/www/html/uploads \
 && a2enmod headers

# Production php.ini + sane upload limits (photos are capped at 2MB in code).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'upload_max_filesize = 40M'; \
      echo 'post_max_size = 45M'; \
      echo 'memory_limit = 512M'; \
      echo 'expose_php = Off'; \
    } > "$PHP_INI_DIR/conf.d/zz-app.ini"

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s \
  CMD curl -fsS http://localhost/login.php >/dev/null || exit 1

EXPOSE 80
