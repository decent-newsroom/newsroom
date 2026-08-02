# Standalone MCP service image — independent of the newsroom app image.
FROM php:8.3-cli-alpine

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
