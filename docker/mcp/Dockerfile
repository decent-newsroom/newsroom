# Standalone MCP service image — independent of the newsroom app image.
FROM php:8.3.37-cli-alpine@sha256:6af812ec8035623727a7a9bfa41c6e8838e93dcad82e9119f33d9da130c5a472

RUN php -r 'if (PHP_VERSION_ID < 80337 || PHP_VERSION_ID >= 80400) { fwrite(STDERR, "PHP 8.3.37 or later on the 8.3 branch is required; found ".PHP_VERSION.".\n"); exit(1); }'

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
