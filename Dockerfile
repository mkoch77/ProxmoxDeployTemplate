FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libcurl4-openssl-dev \
        libssl-dev \
        unzip \
        git \
        openssh-client \
        cron \
    && docker-php-ext-install \
        pdo_sqlite \
        curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

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

# Create data directory for SQLite DB and set permissions
RUN mkdir -p data \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 750 /var/www/html/data

# Persistent volume for SQLite database
VOLUME ["/var/www/html/data"]

EXPOSE 80

COPY docker/crontab /etc/cron.d/proxmoxdeploy
RUN chmod 0644 /etc/cron.d/proxmoxdeploy

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
