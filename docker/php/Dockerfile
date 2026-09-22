ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    HOME=/tmp

RUN mkdir -p /tmp/composer/cache && chmod -R 1777 /tmp/composer

WORKDIR /app
