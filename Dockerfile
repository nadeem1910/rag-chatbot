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

# Storage + DB
RUN mkdir -p storage/app/documents \
    && mkdir -p storage/framework/sessions \
    && touch storage/app/database.sqlite

# Permissions
RUN chmod -R 777 storage bootstrap/cache

# Storage symlink (safe)
RUN php artisan storage:link || true

# 🔥 Clear & cache config (VERY IMPORTANT for Render ENV)
RUN php artisan config:clear \
 && php artisan config:cache \
 && php artisan route:clear \
 && php artisan view:clear

# Start queue + server
CMD php artisan migrate --force \
 && php artisan queue:work --sleep=3 --tries=1 & \
 php artisan serve --host=0.0.0.0 --port=$PORT
