
#!/bin/bash
# docker-entrypoint.sh
set -e

echo "Starting Divera-Stein Sync Container..."
echo "========================================="

# Function to wait for database
wait_for_db() {
    echo "⏳ Waiting for database to be ready..."
    local max_attempts=30
    local attempt=0
    
    while [ $attempt -lt $max_attempts ]; do
        if mysql -h"${DB_HOST:-db}" -u"${DB_USER:-syncuser}" -p"${DB_PASSWORD:-syncpassword}" -e "SELECT 1" &>/dev/null; then
            echo "✅ Database connection successful!"
            return 0
        fi
        
        attempt=$((attempt + 1))
        echo "   Attempt $attempt/$max_attempts - Database not ready, waiting..."
        sleep 2
    done
    
    echo "❌ Database connection failed after $max_attempts attempts"
    exit 1
}

# Function to check if database is initialized
check_db_initialized() {
    mysql -h"${DB_HOST:-db}" -u"${DB_USER:-syncuser}" -p"${DB_PASSWORD:-syncpassword}" \
        "${DB_NAME:-divera_stein_sync}" -e "SELECT COUNT(*) FROM system_status" &>/dev/null
    return $?
}

# Function to initialize database
initialize_database() {
    echo "📦 Initializing database structure..."
    
    if [ -f /var/www/html/install.sql ]; then
        echo "   Found install.sql, importing..."
        
        # First create database if it doesn't exist
        mysql -h"${DB_HOST:-db}" -u"${DB_USER:-syncuser}" -p"${DB_PASSWORD:-syncpassword}" \
            -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME:-divera_stein_sync} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true
        
        # Import the install.sql
        if mysql -h"${DB_HOST:-db}" -u"${DB_USER:-syncuser}" -p"${DB_PASSWORD:-syncpassword}" \
            "${DB_NAME:-divera_stein_sync}" < /var/www/html/install.sql; then
            echo "✅ Database structure created successfully!"
            return 0
        else
            echo "❌ Failed to import install.sql"
            return 1
        fi
    else
        echo "❌ install.sql not found!"
        return 1
    fi
}

# Wait for database
wait_for_db

# Check and initialize database if needed
echo "🔍 Checking database initialization status..."
if ! check_db_initialized; then
    echo "   Database not initialized, setting up..."
    if initialize_database; then
        echo "✅ Database initialization complete!"
    else
        echo "⚠️  Database initialization failed, but continuing..."
    fi
else
    echo "✅ Database already initialized!"
fi

# Verify database structure
echo "🔍 Verifying database structure..."
TABLES_CHECK=$(mysql -h"${DB_HOST:-db}" -u"${DB_USER:-syncuser}" -p"${DB_PASSWORD:-syncpassword}" \
    "${DB_NAME:-divera_stein_sync}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME:-divera_stein_sync}' AND table_name IN ('sync_log', 'sync_config', 'system_status')" 2>/dev/null || echo "0")

if [ "$TABLES_CHECK" -eq "3" ]; then
    echo "✅ All required tables exist!"
else
    echo "⚠️  Warning: Not all required tables exist (found $TABLES_CHECK/3)"
    echo "   Attempting to recreate missing tables..."
    initialize_database
fi

# Create config directory if it doesn't exist
mkdir -p /var/www/html/config

# Generate PHP config file from environment variables
echo "⚙️  Generating config.php from environment variables..."
cat > /var/www/html/config/config.php << EOF
<?php
// Auto-generated config file from Docker environment variables
// Generated at: $(date)
// DO NOT EDIT - This file is regenerated on container restart

return [
    'database' => [
        'host' => '${DB_HOST:-db}',
        'name' => '${DB_NAME:-divera_stein_sync}',
        'user' => '${DB_USER:-syncuser}',
        'pass' => '${DB_PASSWORD:-syncpassword}'
    ],
    'divera' => [
        'accesskey' => '${DIVERA_ACCESS_KEY:-your_divera_access_key}'
    ],
    'stein' => [
        'buname' => ${STEIN_BU_ID:-12345},
        'apikey' => '${STEIN_API_KEY:-your_stein_api_key}'
    ],
    'sync' => [
        'auto_sync_interval' => ${SYNC_INTERVAL:-300},
        'log_retention_days' => ${LOG_RETENTION_DAYS:-30},
        'max_retries' => ${MAX_RETRIES:-3}
    ],
    'timezone' => '${TZ:-Europe/Berlin}'
];
EOF

echo "✅ Config file generated!"

# Validate config
if [ "${DIVERA_ACCESS_KEY}" = "your_divera_access_key" ] || [ -z "${DIVERA_ACCESS_KEY}" ]; then
    echo "⚠️  WARNING: DIVERA_ACCESS_KEY not set or using default value!"
fi

if [ "${STEIN_API_KEY}" = "your_stein_api_key" ] || [ -z "${STEIN_API_KEY}" ]; then
    echo "⚠️  WARNING: STEIN_API_KEY not set or using default value!"
fi

# Also create a shell script with exports for cron environment
echo "📝 Creating cron environment file..."
cat > /var/www/html/cron-env.sh << EOF
#!/bin/bash
# Environment variables for cron jobs
export DB_HOST="${DB_HOST:-db}"
export DB_NAME="${DB_NAME:-divera_stein_sync}"
export DB_USER="${DB_USER:-syncuser}"
export DB_PASSWORD="${DB_PASSWORD:-syncpassword}"
export DIVERA_ACCESS_KEY="${DIVERA_ACCESS_KEY:-your_divera_access_key}"
export STEIN_BU_ID="${STEIN_BU_ID:-12345}"
export STEIN_API_KEY="${STEIN_API_KEY:-your_stein_api_key}"
export TZ="${TZ:-Europe/Berlin}"
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
EOF

chmod +x /var/www/html/cron-env.sh

# Create cron job with environment variables
echo "⏰ Setting up cron jobs..."
cat > /etc/cron.d/sync-cron << EOF
# Divera-Stein Sync Cron Job
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Run sync every 5 minutes
*/5 * * * * www-data /bin/bash -c 'source /var/www/html/cron-env.sh && /usr/local/bin/php /var/www/html/cron.php >> /var/www/html/logs/cron.log 2>&1'

# Clean old logs daily at 2 AM
0 2 * * * www-data /bin/bash -c 'source /var/www/html/cron-env.sh && /usr/local/bin/php /var/www/html/cleanup.php >> /var/www/html/logs/cleanup.log 2>&1'

# Health check every hour
0 * * * * www-data /bin/bash -c 'source /var/www/html/cron-env.sh && curl -s http://localhost/api.php?action=health >> /var/www/html/logs/health.log 2>&1'
EOF

chmod 0644 /etc/cron.d/sync-cron

# Create logs directory and files
echo "📁 Setting up log directory..."
mkdir -p /var/www/html/logs
touch /var/www/html/logs/cron.log
touch /var/www/html/logs/cleanup.log
touch /var/www/html/logs/app.log
touch /var/www/html/logs/health.log

# Set proper permissions
echo "🔐 Setting permissions..."
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod -R 777 /var/www/html/logs
chown www-data:www-data /var/www/html/logs/*.log

# Test database connection from PHP
echo "🧪 Testing PHP database connection..."
php -r "
try {
    \$config = require '/var/www/html/config/config.php';
    \$pdo = new PDO(
        'mysql:host=' . \$config['database']['host'] . ';dbname=' . \$config['database']['name'],
        \$config['database']['user'],
        \$config['database']['pass']
    );
    echo '✅ PHP can connect to database successfully!' . PHP_EOL;
} catch (Exception \$e) {
    echo '❌ PHP database connection failed: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"

# Mark as initialized
touch /var/www/html/.initialized

# Start cron service
echo "🚀 Starting cron service..."
service cron start

# Show final status
echo ""
echo "========================================="
echo "✅ Container initialization complete!"
echo "========================================="
echo "📊 Dashboard: http://localhost:${APP_PORT:-8080}"
echo "🗄️  phpMyAdmin: http://localhost:${PHPMYADMIN_PORT:-8081}"
echo "📁 Logs: /var/www/html/logs/"
echo "========================================="
echo ""

# Start Apache in foreground
echo "🌐 Starting Apache web server..."
exec apache2-foreground
