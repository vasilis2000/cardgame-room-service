FROM php:8.2-apache

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y \
        git unzip libzip-dev libssl-dev \
    && docker-php-ext-install zip sockets \
    && pecl install mongodb && docker-php-ext-enable mongodb

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-dev --optimize-autoloader

COPY . .

RUN mv vendor /var/www/vendor && \
    ln -s /var/www/vendor /var/www/html/vendor

ENV APACHE_DOCUMENT_ROOT /var/www/html/src
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80