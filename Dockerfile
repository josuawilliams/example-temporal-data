# syntax=docker/dockerfile:1
FROM spiralscout/roadrunner:2025.1.5 AS roadrunner

FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    libpq-dev \
    oniguruma-dev \
    zlib-dev \
    $PHPIZE_DEPS \
    linux-headers \
    gcc \
    g++ \
    make

RUN docker-php-ext-install pdo pdo_pgsql bcmath mbstring sockets

# 128M default is too small for report queries that hydrate tens of
# thousands of rows into arrays before Eloquent even returns them.
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini

RUN --mount=type=cache,target=/tmp/pear \
    MAKEFLAGS="-j$(nproc)" pecl install grpc \
    && docker-php-ext-enable grpc

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
