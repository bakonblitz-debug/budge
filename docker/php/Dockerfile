FROM php:8.3-fpm

# ──────────────────────────────────────────────
# System dependencies
# ──────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libfcgi-bin \
    procps \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# ──────────────────────────────────────────────
# PHP extensions
# ──────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache

# ──────────────────────────────────────────────
# Xdebug (controlled via XDEBUG_MODE env var)
# ──────────────────────────────────────────────
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# ──────────────────────────────────────────────
# PHP-FPM healthcheck script
# ──────────────────────────────────────────────
RUN curl -o /usr/local/bin/php-fpm-healthcheck \
    https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck

# Enable status endpoint for healthcheck
RUN echo "pm.status_path = /status" >> /usr/local/etc/php-fpm.d/zz-docker.conf

# ──────────────────────────────────────────────
# PHP configuration
# ──────────────────────────────────────────────
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY php-custom.ini /usr/local/etc/php/conf.d/99-custom.ini

# ──────────────────────────────────────────────
# Composer
# ──────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ──────────────────────────────────────────────
# Working directory & permissions
# ──────────────────────────────────────────────
WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html

# ──────────────────────────────────────────────
# Entrypoint
# ──────────────────────────────────────────────
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
