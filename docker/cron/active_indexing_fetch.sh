#!/bin/bash
set -e
export PATH="/usr/local/bin:/usr/bin:/bin"

# Source environment variables
[ -f /etc/environment ] && . /etc/environment

# ========================================
# ACTIVE INDEXING CONTENT FETCH CRON
# ========================================
# Fetches content from active subscribers' relays
# Run hourly via cron
# ========================================

echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting active indexing content fetch..."

/usr/local/bin/php /app/bin/console active-indexing:fetch --since-hours=24

echo "$(date '+%Y-%m-%d %H:%M:%S') - Active indexing content fetch completed"
