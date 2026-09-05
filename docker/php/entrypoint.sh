#!/bin/sh
set -e

cd /var/www/html

for dir in \
    storage/cache \
    storage/logs \
    storage/sessions \
    storage/tmp \
    storage/tmp/queue \
    storage/uploads
do
    mkdir -p "$dir" 2>/dev/null || true
done

# Dev bind-mount: ponech UID hostitele, jen povol zápis PHP-FPM (www-data).
chmod -R a+rwX storage 2>/dev/null || true

exec "$@"
