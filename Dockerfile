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

# Install Node.js (v24 LTS) and npm via NodeSource
RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install Composer globally from the official installer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
