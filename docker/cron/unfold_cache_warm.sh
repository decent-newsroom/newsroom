#!/bin/bash
# Warm Unfold site caches
# This ensures subdomain magazines always load quickly

set -e

# Source environment
if [ -f /etc/environment ]; then
    . /etc/environment
fi

cd /app

echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting Unfold cache warm"

# Run the cache warm command
/usr/local/bin/php bin/console unfold:cache:warm --no-interaction

echo "$(date '+%Y-%m-%d %H:%M:%S') - Unfold cache warm completed"

