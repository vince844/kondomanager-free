# ------------------------------------------------------
# Stage 1 – PHP dependencies (Composer + extensions)
# ------------------------------------------------------
FROM php:8.2-fpm AS php-deps

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        intl \
        zip \
        gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first (better cache)
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Copy application source (explicit, predictable)
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./

# Permissions
RUN chown -R www-data:www-data /var/www


# ------------------------------------------------------
# Stage 2 – Frontend build (Node + Vite)
# ------------------------------------------------------
FROM node:20-alpine AS frontend-build

WORKDIR /app

# Frontend dependencies
COPY package.json package-lock.json* ./
RUN npm ci

# Frontend source
COPY resources ./resources
COPY vite.config.* ./

# Build assets
RUN npm run build


# ------------------------------------------------------
# Stage 3 – Runtime (final image)
# ------------------------------------------------------
FROM php:8.2-fpm AS app-runtime

# Runtime system libs
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libicu-dev \
    libzip-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        intl \
        zip \
        gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# Copy app + vendor from PHP stage
COPY --from=php-deps /var/www /var/www

# Copy built frontend assets
COPY --from=frontend-build /app/public/build /var/www/public/build

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Permissions
RUN chown -R www-data:www-data /var/www

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
