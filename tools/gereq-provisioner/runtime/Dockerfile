# Local-only runtime fixture. Use a reviewed pinned base for server promotion.
FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_sqlite mbstring gd zip opcache \
 && rm -rf /var/lib/apt/lists/*
COPY platform/ /app/
COPY frontend/ /frontend/
COPY runtime/ /opt/provision/
ENV GEREQ_PREBUILT_ASSETS=true
WORKDIR /app
# Rebuild optimized maps after removing modules from the source snapshot.
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/local/bin/composer
RUN composer dump-autoload --no-dev --no-scripts --optimize --no-interaction
EXPOSE 8080
# PHP's local server is deliberately a development adapter, not a production web tier.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/opt/provision/router.php"]
