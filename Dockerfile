# syntax=docker/dockerfile:1
FROM php:8.3-cli

# Install deps and extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libsqlite3-dev supervisor \
    && docker-php-ext-install pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy and install deps
COPY composer.json /app/
RUN composer install --no-dev --no-interaction --prefer-dist

# Copy app
COPY . /app

# Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/alerts.conf

ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/alerts.conf"]
