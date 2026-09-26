FROM php:8.3-apache

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring

COPY bot.php /var/www/html/index.php

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
