FROM php:8.3-apache

# PHP's Apache module requires the prefork processing model. Ensure the
# threaded event module cannot be loaded alongside it in container builds.
RUN a2dismod mpm_event 2>/dev/null || true && a2enmod mpm_prefork
RUN apt-get update && apt-get install -y --no-install-recommends libssl-dev pkg-config \
    && docker-php-ext-install pdo_mysql \
    && pecl install mongodb-1.21.10 redis-6.3.0 \
    && docker-php-ext-enable mongodb redis \
    && a2enmod headers remoteip \
    && rm -rf /var/lib/apt/lists/*
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/security.ini
RUN printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/server-security.conf
WORKDIR /var/www/html
COPY assets/ assets/
COPY php/ php/
COPY *.html ./
