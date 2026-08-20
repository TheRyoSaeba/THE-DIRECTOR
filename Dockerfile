FROM node:20-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --legacy-peer-deps

COPY resources resources
COPY public public
COPY vite.config.js postcss.config.js tailwind.config.js tsconfig.json ./

COPY app/Models app/Models
RUN npm run build \
    && if [ -f public/build/.vite/manifest.json ]; then \
        mv public/build/.vite/manifest.json public/build/manifest.json; \
        rm -rf public/build/.vite; \
    fi


FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY app app
COPY bootstrap bootstrap
COPY config config
COPY database database
COPY routes routes
COPY artisan artisan
RUN composer dump-autoload --no-dev --optimize


FROM dunglas/frankenphp:1-php8.4

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && install-php-extensions pdo_pgsql opcache pcntl redis \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /app/vendor vendor
COPY . .
COPY --from=assets /app/public/build public/build

RUN rm -f .env \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
      } > /usr/local/etc/php/conf.d/opcache-production.ini \
    && php artisan package:discover --ansi \
    && php artisan route:cache \
    && php artisan view:cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

COPY docker/start-octane.sh /usr/local/bin/start-octane
RUN chmod +x /usr/local/bin/start-octane

CMD ["start-octane"]
