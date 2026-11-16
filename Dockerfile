FROM dunglas/frankenphp:1-php8.4-alpine

WORKDIR /app

RUN install-php-extensions pdo_pgsql \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY Caddyfile /etc/caddy/Caddyfile
COPY database ./database
COPY public ./public
COPY src ./src
COPY templates ./templates

ENV PORT=80
ENV LOGIN_THROTTLE_FILE=/tmp/login-throttle.json

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
