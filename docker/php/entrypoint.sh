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

# Dev bind-mount: keep host UID; only grant PHP-FPM (www-data) write access.
chmod -R a+rwX storage 2>/dev/null || true

# Tunable FPM pool size (defaults suit ~4–8 CPU / 8+ GiB RAM).
if [ "${1:-}" = "php-fpm" ] || [ "${1:-}" = "php-fpm8.4" ]; then
    max_children="${PHP_FPM_MAX_CHILDREN:-32}"
    start_servers="${PHP_FPM_START_SERVERS:-8}"
    min_spare="${PHP_FPM_MIN_SPARE_SERVERS:-4}"
    max_spare="${PHP_FPM_MAX_SPARE_SERVERS:-16}"
    max_requests="${PHP_FPM_MAX_REQUESTS:-500}"

    # Keep start/spare within max_children.
    if [ "$start_servers" -gt "$max_children" ]; then
        start_servers="$max_children"
    fi
    if [ "$min_spare" -gt "$start_servers" ]; then
        min_spare="$start_servers"
    fi
    if [ "$max_spare" -gt "$max_children" ]; then
        max_spare="$max_children"
    fi
    if [ "$max_spare" -lt "$min_spare" ]; then
        max_spare="$min_spare"
    fi

    cat > /usr/local/etc/php-fpm.d/zz-gereq-pm.conf <<EOF
[www]
pm = dynamic
pm.max_children = ${max_children}
pm.start_servers = ${start_servers}
pm.min_spare_servers = ${min_spare}
pm.max_spare_servers = ${max_spare}
pm.max_spawn_rate = 32
pm.max_requests = ${max_requests}
pm.process_idle_timeout = 10s
EOF
    echo "PHP-FPM pool: max_children=${max_children} start=${start_servers} spare=${min_spare}-${max_spare}" >&2
fi

exec "$@"
