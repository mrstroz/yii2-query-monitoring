ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli

# ext-mongodb from PECL; the supported minimum is 2.0.0 (spec 00 §7). Empty builds an image without it, as a
# SQL-only consumer has (YQM-39).
ARG MONGODB_VERSION=2.5.2

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && if [ -n "${MONGODB_VERSION}" ]; then pecl install mongodb-${MONGODB_VERSION} && docker-php-ext-enable mongodb; fi \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    HOME=/tmp

RUN mkdir -p /tmp/composer/cache && chmod -R 1777 /tmp/composer

WORKDIR /app
