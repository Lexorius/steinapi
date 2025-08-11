# ================================
# Dockerfile
# ================================
FROM php:8.4-apache

# Install system dependencies and additional PHP 8.4 requirements
RUN apt-get update 
RUN apt-get install -y libzip-dev zip unzip git 
RUN apt-get install -y curl cron 
RUN apt-get install -y supervisor 
RUN apt-get install -y libicu-dev 
RUN apt-get install -y libonig-dev 

# Install PHP extensions with optimizations for PHP 8.4
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    zip \
    intl \
    mbstring \
    opcache \
    && docker-php-ext-enable pdo_mysql opcache

# Configure PHP 8.4 optimizations
RUN { \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.jit_buffer_size=100M'; \
    echo 'opcache.jit=1255'; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Set up working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

RUN mkdir -p /var/www/html/logs \
    && mkdir -p /var/www/html/cache \
    && mkdir -p /var/www/html/config \
    && mkdir -p /var/www/html/public

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/logs

# Create cron job for automatic sync

RUN echo "*/5 * * * * /usr/local/bin/php /var/www/html/cron.php >> /var/www/html/logs/cron.log 2>&1" > /sync-cron 
RUN crontab /sync-cron

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
