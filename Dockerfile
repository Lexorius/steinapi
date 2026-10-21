# ================================
# Dockerfile
# ================================
# Sicherheitsfix 2026-10-21: PHP 8.4.27 behebt zwei kritische RCE-Luecken
# (CVE-2026-109618 / GHSA-w8hw-w9fx-j79w: OOM-Bailout-Korruption,
#  CVE-2026-109619 / GHSA-6wmf-4rv5-p3rp: veralteter globaler Request-State),
# die insbesondere Apache mod_php betreffen - also genau dieses Image.
# Version bewusst gepinnt, damit nicht unbemerkt ein alter 8.4-Tag aus dem
# lokalen Cache verwendet wird. Siehe https://www.php.net/archive/2026.php#2026-10-21-5
FROM php:8.4.27-apache

# Install system dependencies and additional PHP 8.4 requirements
RUN apt-get update 
RUN apt-get install -y libzip-dev zip unzip git 
RUN apt-get install -y curl cron 
RUN apt-get install -y supervisor 
RUN apt-get install -y libicu-dev 
RUN apt-get install -y libonig-dev default-mysql-client

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

# Produktions-php.ini aktivieren: ohne php.ini gilt PHP-Default display_errors=On,
# womit Fehlermeldungen (Pfade, SQL, Hostnamen) an den Client gehen.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

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

# Haertung gegen die Angriffspfade aus GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp:
# Die API verarbeitet ausschliesslich JSON ueber php://input, braucht also weder
# Datei-Uploads noch Session-Upload-Progress. Beides abschalten und die
# Multipart-/POST-Groesse klein halten (Defense in Depth - der eigentliche Fix
# ist die gepatchte PHP-Version oben).
RUN { \
    echo 'file_uploads=Off'; \
    echo 'upload_max_filesize=0'; \
    echo 'max_file_uploads=0'; \
    echo 'post_max_size=2M'; \
    echo 'max_input_vars=200'; \
    echo 'session.upload_progress.enabled=0'; \
    echo 'expose_php=Off'; \
    echo 'display_errors=Off'; \
    echo 'display_startup_errors=Off'; \
    echo 'log_errors=On'; \
    echo 'allow_url_include=Off'; \
} > /usr/local/etc/php/conf.d/zz-security-hardening.ini

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

ENV TZ=Europe/Berlin
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
RUN echo "date.timezone = Europe/Berlin" > /usr/local/etc/php/conf.d/timezone.ini

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh


#RUN echo "*/5 * * * * /usr/local/bin/php /var/www/html/cron.php >> /var/www/html/logs/cron.log 2>&1" > /sync-cron 
#RUN crontab /sync-cron

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
