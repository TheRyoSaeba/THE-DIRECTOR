# Local development image: same PHP + extensions as production, but no caching,
# so edits to PHP files are picked up on the next request.
FROM dunglas/frankenphp:1-php8.4

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git \
    && install-php-extensions pdo_pgsql pcntl redis zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/dev/start.sh /usr/local/bin/start-dev
RUN chmod +x /usr/local/bin/start-dev

EXPOSE 8000
CMD ["start-dev"]
