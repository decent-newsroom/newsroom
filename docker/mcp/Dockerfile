# Standalone MCP service image — independent of the newsroom app image.
FROM php:8.3.36-cli-alpine@sha256:337098ca8278d07fb9c80e92967df00a46e5982990e78b3905de4b4f97c2c655

RUN php -r 'if (PHP_VERSION_ID < 80336 || PHP_VERSION_ID >= 80400) { fwrite(STDERR, "PHP 8.3.36 or later on the 8.3 branch is required; found ".PHP_VERSION.".\n"); exit(1); }'

COPY conf.d/10-security.ini /usr/local/etc/php/conf.d/10-security.ini

RUN apk add --no-cache git unzip \
    && docker-php-ext-install opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first for better layer caching.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts

COPY . .

RUN chmod +x bin/mcp-stdio bin/mcp-http

# HTTP transport port (streamable HTTP). stdio clients use `docker compose run`.
EXPOSE 9000

CMD ["php", "bin/mcp-http"]
