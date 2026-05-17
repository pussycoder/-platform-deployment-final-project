# PHP-FPM base image
FROM php:8.3-fpm AS builder

WORKDIR /app

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    zlib1g-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

# Allow Composer to run as superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy Composer files first for dependency caching
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-interaction --no-scripts --optimize-autoloader

# Copy the rest of the project files
COPY . .

# Create .env file if it does not exist
RUN if [ ! -f /app/.env ]; then \
    echo "APP_ENV=${APP_ENV:-prod}" > /app/.env; \
    echo "APP_DEBUG=${APP_DEBUG:-false}" >> /app/.env; \
    echo "APP_SECRET=${APP_SECRET:-ChangeMe}" >> /app/.env; \
    fi

# Run Symfony commands
RUN composer install --no-interaction --optimize-autoloader --no-ansi || true

RUN php bin/console importmap:install --no-interaction

RUN php bin/console cache:warmup --env=prod --no-debug || true

# Runtime stage
FROM php:8.3-fpm AS runtime

WORKDIR /app

# Install required runtime packages and PHP extensions
RUN apt-get update && apt-get install -y \
    nginx \
    curl \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Copy application files from builder stage
COPY --from=builder /app /app

# Create required directories and set permissions
RUN mkdir -p /app/var && \
    chown -R www-data:www-data /app && \
    chmod -R 755 /app && \
    chmod -R 775 /app/var

# Copy Nginx main configuration
COPY nginx-main.conf /etc/nginx/nginx.conf

# Remove default Nginx site configuration
RUN rm -rf /etc/nginx/conf.d/* \
    /etc/nginx/sites-enabled/* \
    /etc/nginx/sites-available/*

# Copy custom Nginx server block
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copy Docker entrypoint script
COPY entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Make entrypoint script executable
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Healthcheck to verify the app is responding
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Expose HTTP port
EXPOSE 80

# Run the entrypoint script
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]