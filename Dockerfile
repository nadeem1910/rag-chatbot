# PHP 8.4 CLI
FROM php:8.4-cli

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App directory
WORKDIR /app

# Copy project files
COPY . .

# Install Laravel dependencies
RUN composer install --no-dev --optimize-autoloader

# SQLite database (Render-safe)
RUN mkdir -p storage/app \
    && touch storage/app/database.sqlite

# Permissions
RUN chmod -R 777 storage bootstrap/cache

# Render uses $PORT
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT
