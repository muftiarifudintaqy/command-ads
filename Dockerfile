FROM php:8.3-apache
COPY . /var/www/html/
RUN mkdir -p /var/www/html/logs && chown -R www-data:www-data /var/www/html/logs
CMD sed -i "s/80/${PORT:-80}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground