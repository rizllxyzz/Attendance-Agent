FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev default-libmysqlclient-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers expires \
    && a2dismod -f autoindex status || true

WORKDIR /var/www/html
COPY . /var/www/html
COPY docker/php-security.ini /usr/local/etc/php/conf.d/99-security.ini

RUN mkdir -p /var/www/html/database /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/database /var/www/html/uploads \
    && find /var/www/html/database -type f -name '*.sqlite*' -exec chmod 660 {} \; \
    && find /var/www/html/uploads -type d -exec chmod 750 {} \; \
    && find /var/www/html/uploads -type f -exec chmod 640 {} \; \
    && find /var/www/html -type f -name '*.env*' -exec chmod 600 {} \; \
    && rm -rf /var/www/html/.git /var/www/html/node_modules

EXPOSE 80
