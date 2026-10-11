FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        ca-certificates \
        openssh-client \
        python3 \
        python3-pip \
        python3-venv \
        libsqlite3-dev \
        libpq-dev \
        libzip-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libavif-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
        --with-avif \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo_sqlite \
        pdo_mysql \
        pdo_pgsql \
        opcache \
        zip \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.2.0.tar.gz \
        | tar xz -C /tmp \
    && cd /tmp/phpredis-6.2.0 \
    && phpize \
    && ./configure \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable redis \
    && cd / \
    && rm -rf /tmp/phpredis-6.2.0 \
    && pip3 install --break-system-packages --no-cache-dir curl_cffi \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && curl -fsSL https://bun.sh/install | bash \
    && cp /root/.bun/bin/bun /usr/local/bin/bun \
    && chmod 755 /usr/local/bin/bun \
    && ln -sf /usr/local/bin/bun /usr/local/bin/node \
    && bun --version \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/gereq.ini
COPY docker/php/zz-www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/gereq-entrypoint
RUN chmod +x /usr/local/bin/gereq-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["gereq-entrypoint"]
CMD ["php-fpm"]
