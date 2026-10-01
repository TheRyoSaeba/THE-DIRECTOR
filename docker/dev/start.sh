#!/usr/bin/env sh
set -e

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

php artisan package:discover --ansi
php artisan config:clear --ansi

# Classic (non-worker) mode: every request boots fresh, so code changes apply immediately.
exec frankenphp php-server --listen :8000 --root public/
