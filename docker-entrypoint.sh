#!/bin/sh
set -e

# Ensure data directory exists and is writable by www-data
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data || true
chmod -R 777 /var/www/html/data || true

# If vendor/autoload.php is missing but composer is present, generate autoloader if vendor folder is missing
if [ -f "/var/www/html/composer.json" ] && [ ! -d "/var/www/html/vendor" ]; then
    echo "Initializing Composer autoloader..."
    composer dump-autoload --no-interaction --quiet || true
fi

exec "$@"
