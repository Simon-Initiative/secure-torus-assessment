FROM composer:2 AS composer
FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libxml2-dev libonig-dev unzip git gosu \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pgsql pdo_pgsql intl zip gd soap exif mbstring opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/moodle.ini
COPY docker/entrypoint.sh /usr/local/bin/moodle-entrypoint
RUN chmod +x /usr/local/bin/moodle-entrypoint \
    && a2enmod rewrite ssl \
    && git config --system --add safe.directory /var/www/html

WORKDIR /var/www/html
ENTRYPOINT ["moodle-entrypoint"]
CMD ["apache2-foreground"]
