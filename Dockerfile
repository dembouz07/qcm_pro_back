FROM dunglas/frankenphp:1-php8.3-bookworm

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions bcmath gd intl mbstring opcache pdo_pgsql xml zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && mkdir -p /config/caddy /data/caddy \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy

COPY Caddyfile /etc/caddy/Caddyfile

USER www-data
EXPOSE 10000

ENTRYPOINT ["sh", "-c"]
CMD ["exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile"]
