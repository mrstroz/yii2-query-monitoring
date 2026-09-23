ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli

# ext-mongodb from PECL; yii2-mongodb 3.0.3 and later require 1.20.1 or newer.
ARG MONGODB_VERSION=2.5.2

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && pecl install mongodb-${MONGODB_VERSION} \
    && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    HOME=/tmp

RUN mkdir -p /tmp/composer/cache && chmod -R 1777 /tmp/composer

WORKDIR /app
