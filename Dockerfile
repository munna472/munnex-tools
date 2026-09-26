FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring curl \
    && rm -rf /var/lib/apt/lists/*

COPY bot.php /var/www/html/index.php

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
