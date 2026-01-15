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

# Ensure PHP is in PATH for cron jobs
ENV PATH="/usr/local/bin:/usr/local/sbin:/usr/bin:/usr/sbin:/bin:/sbin"

# Run cron in the foreground
CMD ["cron", "-f"]
