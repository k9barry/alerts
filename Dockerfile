# syntax=docker/dockerfile:1
FROM php:8.3-cli

# Install deps and extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libsqlite3-dev dos2unix \
    && docker-php-ext-install pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy application files
COPY composer.json composer.lock* ./
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Install composer dependencies at build time
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && dos2unix /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy the rest of the application
COPY . .

ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php", "scripts/scheduler.php"]
