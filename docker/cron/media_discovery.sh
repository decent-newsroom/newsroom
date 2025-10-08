#!/bin/bash
set -e
export PATH="/usr/local/bin:/usr/bin:/bin"

# Run Symfony commands sequentially
php /var/www/html/bin/console app:cache-media-discovery
