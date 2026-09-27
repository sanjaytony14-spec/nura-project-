FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends libssl-dev pkg-config \
    && docker-php-ext-install pdo_mysql \
    && pecl install mongodb-1.21.10 redis-6.3.0 \
    && docker-php-ext-enable mongodb redis \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/php.ini /usr/local/etc/php/conf.d/security.ini
WORKDIR /var/www/html
COPY assets/ assets/
COPY php/ php/
COPY *.html ./

# Railway supplies PORT at runtime. PHP serves the same static files and JSON
# API endpoints without Apache, avoiding its conflicting MPM configuration.
ENV PHP_CLI_SERVER_WORKERS=4
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-80} -t /var/www/html"]
