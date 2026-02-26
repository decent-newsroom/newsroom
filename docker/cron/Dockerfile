FROM php:8.2-cli

# Install cron and Redis PHP extension dependencies
RUN apt-get update && apt-get install -y \
    cron \
    libzip-dev \
    libicu-dev \
    libpq-dev \
    libonig-dev

# Install Redis PHP extension
RUN pecl install redis \
    && docker-php-ext-enable redis

RUN docker-php-ext-install pdo pdo_pgsql


# Set working directory
WORKDIR /var/www/html

# Install Symfony CLI tools (optional)
# RUN curl -sS https://get.symfony.com/cli/installer | bash

# Copy cron and script
COPY crontab /etc/cron.d/app-cron
COPY index_articles.sh /index_articles.sh
COPY article_discovery.sh /article_discovery.sh
COPY media_discovery.sh /media_discovery.sh

# Set permissions
RUN chmod 0644 /etc/cron.d/app-cron && \
    chmod +x /index_articles.sh && \
    chmod +x /article_discovery.sh && \
    chmod +x /media_discovery.sh

# Apply cron job
RUN crontab /etc/cron.d/app-cron

# Raise PHP memory limit for CLI cron jobs (default 128M is too low for Symfony console commands)
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Ensure PHP is in PATH for cron jobs
ENV PATH="/usr/local/bin:/usr/local/sbin:/usr/bin:/usr/sbin:/bin:/sbin"

# Create entrypoint script to export environment variables for cron
RUN echo '#!/bin/bash\n\
printenv | grep -E "^(APP_|DATABASE_|REDIS_|NOSTR_|MESSENGER_|ELASTICSEARCH_)" >> /etc/environment\n\
echo "PATH=/usr/local/bin:/usr/local/sbin:/usr/bin:/usr/sbin:/bin:/sbin" >> /etc/environment\n\
# Pre-create log files so tail -f can follow them from the start\n\
touch /var/log/cron-post-process.log /var/log/cron-backfill.log /var/log/cron-cache-articles.log \\\n\
      /var/log/cron-fetch-highlights.log /var/log/cron-cache-highlights.log \\\n\
      /var/log/cron-media-discovery.log /var/log/cron-magazine-projection.log \\\n\
      /var/log/cron-unfold-cache.log\n\
# Tail all log files to stdout so "docker compose logs cron" works\n\
tail -F /var/log/cron-*.log &\n\
cron -f' > /entrypoint.sh && chmod +x /entrypoint.sh

# Run cron in the foreground via entrypoint
CMD ["/entrypoint.sh"]
