FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libpq-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql pdo_pgsql pgsql zip opcache

# OPcache (tiempo de PHP por request) + gzip de las respuestas JSON (red).
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/deflate.conf /etc/apache2/conf-available/deflate.conf
RUN a2enmod rewrite deflate headers && a2enconf deflate

WORKDIR /var/www/html

COPY . .

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Añadimos un flag de seguridad para evitar errores de plataforma
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80

# Arranque:
#  - config:cache / event:cache: se hacen ACÁ (no en build) porque necesitan las
#    variables de entorno reales, que Render inyecta recién al correr el contenedor.
#    route:cache NO se puede (hay closures en routes/*.php).
#  - migraciones bajo demanda: poné RUN_MIGRATIONS=true en las env de Render para
#    UN deploy, revisá el log, y volvelo a false. (Útil en el plan free, que no
#    tiene shell.)
CMD ["sh", "-c", "if [ \"$RUN_MIGRATIONS\" = \"true\" ]; then echo '==> php artisan migrate --force'; php artisan migrate --force || exit 1; fi; php artisan config:cache || echo 'WARN: config:cache falló, sigo sin cache'; php artisan event:cache || true; chown -R www-data:www-data storage bootstrap/cache; exec apache2-foreground"]