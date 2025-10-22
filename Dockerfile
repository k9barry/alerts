FROM php:8.1-cli-alpine

# Install required extensions and dependencies
RUN apk add --no-cache \
    sqlite \
    sqlite-dev \
    curl \
    && docker-php-ext-install pdo pdo_sqlite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer.json ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application files
COPY . .

# Create necessary directories
RUN mkdir -p /app/data /app/logs && \
    chmod -R 755 /app/data /app/logs

# Set environment variables
ENV APP_ENV=production

# Default command (can be overridden in docker-compose)
CMD ["php", "src/app.php"]
