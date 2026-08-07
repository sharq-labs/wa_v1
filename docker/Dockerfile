FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    libzip-dev \
    icu-dev \
    curl-dev \
    linux-headers \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql zip intl pcntl posix bcmath curl \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

CMD ["php-fpm"]
