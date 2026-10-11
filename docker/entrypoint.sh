#!/bin/sh
set -e

PORT="${PORT:-80}"

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/logs \
         /var/www/html/uploads/imports \
         /var/www/html/uploads/exports \
         /var/www/html/uploads/profiles \
         /var/www/html/backups

chown -R www-data:www-data /var/www/html/logs \
                            /var/www/html/uploads \
                            /var/www/html/backups 2>/dev/null || true
a2dismod -f mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true
echo "Starting Apache on port ${PORT}"
exec "$@"
