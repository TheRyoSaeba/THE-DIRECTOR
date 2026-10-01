#!/usr/bin/env sh
set -e

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

# Fresh clone: generate an app key once. compose's env_file already exported an
# empty APP_KEY into this process, so export the new value for this run too.
if ! grep -qE '^APP_KEY=.+' .env; then
    php artisan key:generate --ansi
fi
export APP_KEY="$(grep -E '^APP_KEY=' .env | cut -d= -f2-)"

php artisan package:discover --ansi
php artisan config:clear --ansi

# Classic (non-worker) mode: every request boots fresh, so code changes apply immediately.
exec frankenphp php-server --listen :8000 --root public/
