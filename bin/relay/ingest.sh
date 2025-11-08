#!/usr/bin/env bash
set -euo pipefail

echo "[$(date)] Starting relay ingest..."

# Config via env or defaults
UPSTREAMS=${UPSTREAMS:-"wss://relay.snort.social wss://relay.damus.io wss://relay.nostr.band"}
DAYS_ARTICLES=${DAYS_ARTICLES:-7}
DAYS_THREADS=${DAYS_THREADS:-3}

# These two should be programmatically generated from app DB; allow overrides:
ARTICLE_E_LIST=${ARTICLE_E_LIST:-'[]'}  # e.g. ["<eventid1>","<eventid2>"]
ARTICLE_A_LIST=${ARTICLE_A_LIST:-'[]'}  # e.g. ["30023:<authorhex>:<d>",...]

# Helper functions for date calculation
now_ts() { date +%s; }
since_days() {
  local days=$1
  if command -v date >/dev/null 2>&1; then
    # Try GNU date
    if date -d "-${days} days" +%s 2>/dev/null; then
      return 0
    # Try BSD date (macOS)
    elif date -v-${days}d +%s 2>/dev/null; then
      return 0
    fi
  fi
  # Fallback: rough calculation
  echo $(( $(date +%s) - (days * 86400) ))
}

# Build filters using jq if available, otherwise use basic JSON
if command -v jq >/dev/null 2>&1; then
  FILTER_ARTICLES=$(jq -nc --argjson kinds '[30023]' --arg since "$(since_days $DAYS_ARTICLES)" '
    {kinds:$kinds, since: ($since|tonumber)}')

  FILTER_REPLIES_E=$(jq -nc --argjson kinds '[1]' --argjson es "$ARTICLE_E_LIST" --arg since "$(since_days $DAYS_THREADS)" '
    {kinds:$kinds, "#e":$es, since: ($since|tonumber)}')

  FILTER_REPLIES_A=$(jq -nc --argjson kinds '[1]' --argjson as "$ARTICLE_A_LIST" --arg since "$(since_days $DAYS_THREADS)" '
    {kinds:$kinds, "#a":$as, since: ($since|tonumber)}')

  FILTER_REACTS=$(jq -nc --argjson kinds '[7]' --argjson es "$ARTICLE_E_LIST" '{kinds:$kinds, "#e":$es}')
  FILTER_ZAPS=$(jq -nc --argjson kinds '[9735]' --argjson es "$ARTICLE_E_LIST" '{kinds:$kinds, "#e":$es}')
  FILTER_HL=$(jq -nc --argjson kinds '[9802]' --argjson as "$ARTICLE_A_LIST" '{kinds:$kinds, "#a":$as}')
  FILTER_PROFILES=$(jq -nc --argjson kinds '[0]' '{kinds:$kinds}')
  FILTER_DELETES=$(jq -nc --argjson kinds '[5]' --arg since "$(since_days 30)" '{kinds:$kinds, since:($since|tonumber)}')
else
  # Fallback to basic JSON strings
  SINCE_ARTICLES=$(since_days $DAYS_ARTICLES)
  SINCE_THREADS=$(since_days $DAYS_THREADS)
  SINCE_DELETES=$(since_days 30)

  FILTER_ARTICLES="{\"kinds\":[30023],\"since\":${SINCE_ARTICLES}}"
  FILTER_REPLIES_E="{\"kinds\":[1],\"#e\":${ARTICLE_E_LIST},\"since\":${SINCE_THREADS}}"
  FILTER_REPLIES_A="{\"kinds\":[1],\"#a\":${ARTICLE_A_LIST},\"since\":${SINCE_THREADS}}"
  FILTER_REACTS="{\"kinds\":[7],\"#e\":${ARTICLE_E_LIST}}"
  FILTER_ZAPS="{\"kinds\":[9735],\"#e\":${ARTICLE_E_LIST}}"
  FILTER_HL="{\"kinds\":[9802],\"#a\":${ARTICLE_A_LIST}}"
  FILTER_PROFILES="{\"kinds\":[0]}"
  FILTER_DELETES="{\"kinds\":[5],\"since\":${SINCE_DELETES}}"
fi

run_sync() {
  local upstream=$1
  local filter=$2
  local label=$3
  echo "[$(date)] Syncing ${label} from ${upstream}..."

  # Write filter to temp file to avoid shell escaping nightmares
  local tmpfile="/tmp/strfry-filter-$$.json"
  echo "$filter" | docker compose exec strfry sh -c "cat > $tmpfile"

  # Run sync with filter file
  docker compose exec strfry sh -c "./strfry sync '$upstream' --filter=\$(cat $tmpfile) && rm $tmpfile" || echo "[$(date)] WARNING: sync failed for ${label} from ${upstream}"
}

# Sync from all upstream relays
for R in $UPSTREAMS; do
  echo "[$(date)] Processing relay: ${R}"
  run_sync "$R" "$FILTER_ARTICLES" "articles (30023)"
  run_sync "$R" "$FILTER_REPLIES_E" "replies by event-id"
  run_sync "$R" "$FILTER_REPLIES_A" "replies by a-tag"
  run_sync "$R" "$FILTER_REACTS" "reactions (7)"
  run_sync "$R" "$FILTER_ZAPS" "zap receipts (9735)"
  run_sync "$R" "$FILTER_HL" "highlights (9802)"
  run_sync "$R" "$FILTER_PROFILES" "profiles (0)"
  run_sync "$R" "$FILTER_DELETES" "deletes (5)"
done

echo "[$(date)] Relay ingest complete."

