FROM php:8.5-fpm

RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    nano \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html