#!/bin/sh
# Starts ProxApps: prepares the persistent storage volume, the app key and the
# database, then hands over to supervisord (nginx, php-fpm, queue workers).
set -e
cd /app

# The storage volume may start empty (bind mount) – recreate Laravel's layout.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
         storage/framework/sessions storage/framework/views storage/logs

# APP_KEY encrypts the Proxmox token secrets and passwords. It must never be
# baked into the image and must survive container re-creation: keep it in the volume.
if [ -z "${APP_KEY}" ]; then
    if [ ! -s storage/app/private/app.key ]; then
        echo "==> Erzeuge neuen Anwendungsschlüssel"
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > storage/app/private/app.key
        chmod 600 storage/app/private/app.key
    fi
    APP_KEY="$(cat storage/app/private/app.key)"
    export APP_KEY
fi

# php-fpm and the queue workers read the environment from .env, not from the shell.
: > .env
for key in APP_NAME APP_ENV APP_DEBUG APP_URL APP_KEY APP_LOCALE LOG_CHANNEL LOG_LEVEL \
           DB_CONNECTION DB_DATABASE SESSION_DRIVER SESSION_LIFETIME SESSION_SECURE_COOKIE QUEUE_CONNECTION \
           DB_QUEUE_RETRY_AFTER CACHE_STORE PROXAPPS_CATALOG TRUSTED_PROXIES TZ \
           PK95_CORE_MODE PK95_CORE_URL PK95_CORE_TOKEN; do
    eval "value=\${$key-}"
    [ -n "$value" ] && printf '%s="%s"\n' "$key" "$value" >> .env
done
chmod 640 .env && chown root:www-data .env

[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"

php artisan package:discover --no-interaction >/dev/null
echo "==> Migrationen"
php artisan migrate --force --no-interaction
php artisan proxapps:recover --no-interaction || true
# First run (no account yet): one-time code for the setup page, file 0600 in the volume.
php artisan proxapps:setup-token --no-interaction
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan view:cache --no-interaction >/dev/null

chown -R www-data:www-data storage bootstrap/cache
chmod 600 storage/app/private/app.key 2>/dev/null || true
chmod 600 storage/app/private/setup.token 2>/dev/null || true

echo "==> ProxApps läuft auf Port 8080"
exec supervisord -c /etc/supervisord.conf
