FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql curl mbstring

ENV APACHE_DOCUMENT_ROOT=/var/www/html

COPY bot.php /var/www/html/index.php

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
