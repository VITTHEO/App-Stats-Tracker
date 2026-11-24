FROM php:8.3-apache

RUN a2enmod rewrite \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

COPY web/ /var/www/html/

RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data

ENV APP_STATS_DB=/var/www/html/data/stats.db

VOLUME ["/var/www/html/data"]
EXPOSE 80
