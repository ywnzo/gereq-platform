FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libsqlite3-dev \
        libpq-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j$(nproc) \
        pdo_sqlite \
        pdo_mysql \
        pdo_pgsql \
        opcache \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/gereq.ini
COPY docker/php/entrypoint.sh /usr/local/bin/gereq-entrypoint
RUN chmod +x /usr/local/bin/gereq-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["gereq-entrypoint"]
CMD ["php-fpm"]
