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

# Rely on bind-mounted project (including vendor) at runtime; no build-time composer install
# Ensure entrypoint exists in the image
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN dos2unix /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php", "scripts/scheduler.php"]
