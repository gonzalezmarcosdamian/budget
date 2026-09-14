# PHP 8.2 sobre Apache: lo más parecido a un cPanel de WNPower que se
# puede tener en local. La versión es deliberadamente más baja que la de
# desarrollo (8.4) para que cualquier sintaxis demasiado nueva explote
# acá y no en producción.
FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# El document root apunta a public/: todo lo demás (src, .env,
# migrations) queda fuera del alcance de cualquier request HTTP.
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf

COPY php.ini /usr/local/etc/php/conf.d/budget.ini

WORKDIR /var/www/html
