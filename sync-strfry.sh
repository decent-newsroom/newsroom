#!/bin/bash
# Sync articles, comments, media, and profiles from upstream relays
# Event kinds: 30023 (articles), 30024 (drafts), 1111 (comments), 20 (pictures), 21 (videos), 22 (short videos), 0 (profiles), 9802 (highlights)

KINDS='{"kinds":[30023,30024,1111,20,21,22,0,9802],"limit":5000}'

echo "Starting relay sync at $(date)"

docker compose exec strfry ./strfry sync wss://theforest.nostr1.com --filter "$KINDS" --dir down
docker compose exec strfry ./strfry sync wss://relay.damus.io --filter "$KINDS" --dir down

echo "Sync completed at $(date)"
