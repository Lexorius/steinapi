#!/bin/bash
set -e

# Wait for MariaDB to be ready
echo "Waiting for MariaDB..."
while ! mariadb-admin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --silent; do
    sleep 1
done
echo "MariaDB is ready!"

# Run database migrations if needed
if [ ! -f /var/www/html/.initialized ]; then
    echo "Running initial setup..."
    mariadb -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < /var/www/html/install.sql
    touch /var/www/html/.initialized
    echo "Initial setup complete!"
fi

# Copy environment-aware config if it doesn't exist
if [ ! -f /var/www/html/config/config.php ]; then
    cp /var/www/html/docker-config.php /var/www/html/config/config.php
    echo "Config file created from environment variables"
fi

# Start cron
service cron start

# Execute the main command
exec "$@"
