#!/bin/bash
set -e
export PATH="/usr/local/bin:/usr/bin:/bin"

# Source environment variables (includes APP_ENV, DATABASE_URL, etc.)
[ -f /etc/environment ] && . /etc/environment

LOG_PREFIX="[article_discovery.sh]"
TIMESTAMP() { date '+%Y-%m-%d %H:%M:%S'; }

# Log start
echo "$(TIMESTAMP) $LOG_PREFIX STARTING article discovery cache update" >&2

# Run Symfony command
/usr/local/bin/php /var/www/html/bin/console app:cache_latest_articles
EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
    echo "$(TIMESTAMP) $LOG_PREFIX FINISHED successfully (exit code: $EXIT_CODE)" >&2
else
    echo "$(TIMESTAMP) $LOG_PREFIX ERROR (exit code: $EXIT_CODE)" >&2
fi
