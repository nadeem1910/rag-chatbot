# ============================================
# Dockerfile for Laravel RAG Chatbot
# Optimized for Render.com deployment
# ============================================

# Stage 1: Build Frontend (if you have Vite/NPM assets)
# Skip this stage if you don't use Node/Vite
FROM node:18-alpine AS frontend
WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies (skip if no package.json)
RUN npm install || echo "No package.json found, skipping npm install"

# Copy all files
COPY . .

# Build assets (skip if no build script)
RUN npm run build || echo "No build script found, skipping"

# ============================================
# Stage 2: PHP Backend with Laravel
# ============================================
FROM php:8.2-fpm

# Set working directory
WORKDIR /var/www

# Install system dependencies for Laravel + PDF parsing
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libpq-dev \
    libonig-dev \
    libzip-dev \
    zip \
    nginx \
    supervisor \
    # PDF parsing dependencies
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    # For SQLite
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        mbstring \
        zip \
        bcmath \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . .

# Copy built frontend assets (if any)
COPY --from=frontend /app/public/build ./public/build 2>/dev/null || echo "No frontend build"
COPY --from=frontend /app/public/dist ./public/dist 2>/dev/null || echo "No frontend dist"

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist

# Create necessary directories and set permissions
RUN mkdir -p \
    storage/app/documents \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    database \
    && touch database/database.sqlite \
    && chmod -R 775 storage bootstrap/cache database \
    && chown -R www-data:www-data storage bootstrap/cache database

# Copy configuration files
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Laravel optimization
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Expose port
EXPOSE 8080

# Start supervisor (manages nginx + php-fpm + queue worker)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]