FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libcurl4-openssl-dev \
        libssl-dev \
        unzip \
        git \
        openssh-client \
        cron \
        postgresql-client \
    && docker-php-ext-install \
        pdo_pgsql \
        curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/* \
    && { \
        echo 'upload_max_filesize = 20G'; \
        echo 'post_max_size = 20G'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 3600'; \
        echo 'max_input_time = 3600'; \
    } > /usr/local/etc/php/conf.d/uploads.ini \
    && { \
        echo 'display_errors = Off'; \
        echo 'display_startup_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /var/log/php_errors.log'; \
        echo 'output_buffering = 4096'; \
    } > /usr/local/etc/php/conf.d/errors.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure Apache: set DocumentRoot to public/
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' \
        /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/>|<Directory /var/www/html/public/>|g' \
        /etc/apache2/apache2.conf \
    && sed -i 's|AllowOverride None|AllowOverride All|g' \
        /etc/apache2/apache2.conf

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy project files
WORKDIR /var/www/html
COPY . .

# Install PHP dependencies (no dev packages)
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Create data directory for SSH keys, custom images and backups
RUN mkdir -p data data/images data/backups \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 750 /var/www/html/data

# Persistent volume for SSH keys and custom images
VOLUME ["/var/www/html/data"]

EXPOSE 80

COPY docker/crontab /etc/cron.d/pvedcm
RUN chmod 0644 /etc/cron.d/pvedcm

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
