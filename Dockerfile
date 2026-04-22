# Imagen oficial de PHP con Apache
FROM php:8.2-apache

# Instalar dependencias del sistema y extensiones de PHP para Laravel y MySQL
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql

# Habilitar mod_rewrite para que las rutas de Laravel funcionen
RUN a2enmod rewrite

# Directorio de trabajo
WORKDIR /var/www/html

# Copiar el código del proyecto
COPY . .

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Permisos para storage y cache (vital para que no de error 500)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Apuntar Apache a la carpeta /public de Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80

CMD ["apache2-foreground"]