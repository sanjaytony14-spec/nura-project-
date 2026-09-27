FROM php:8.3-apache

# PHP's Apache module requires prefork. Force-disable both threaded MPMs:
# a normal a2dismod can refuse when a dependent module is present.
RUN set -eux; \
    a2dismod -f mpm_event || true; \
    a2dismod -f mpm_worker || true; \
    a2enmod mpm_prefork
RUN apt-get update && apt-get install -y --no-install-recommends libssl-dev pkg-config \
    && docker-php-ext-install pdo_mysql \
    && pecl install mongodb-1.21.10 redis-6.3.0 \
    && docker-php-ext-enable mongodb redis \
    && a2enmod headers remoteip \
    && rm -rf /var/lib/apt/lists/*

# Apache loads every module link in mods-enabled. Remove the incompatible
# threaded MPM links explicitly so only PHP's prefork MPM can load.
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load \
          /etc/apache2/mods-enabled/mpm_event.conf \
          /etc/apache2/mods-enabled/mpm_worker.load \
          /etc/apache2/mods-enabled/mpm_worker.conf \
    && a2enmod mpm_prefork
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/security.ini
RUN printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/server-security.conf
WORKDIR /var/www/html
COPY assets/ assets/
COPY php/ php/
COPY *.html ./
