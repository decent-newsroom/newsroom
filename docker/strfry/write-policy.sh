#!/bin/sh
# =============================================================================
# Default relay write policy — accept all events EXCEPT from blocked pubkeys
# =============================================================================
#
# Blocked pubkeys are stored one hex pubkey per line in:
#   /etc/strfry-blocked-pubkeys.txt
# Lines starting with # and blank lines are ignored by grep -F matching.
#
# To add a block: append the hex pubkey to the file and restart strfry.
# To remove a block: delete the line and restart strfry.
#
# To purge already-ingested events from a blocked pubkey, exec into the
# container and run:
#   ./strfry delete --filter '{"authors":["<hex-pubkey>"]}'
#
# Implementation notes:
#  - dockurr/strfry Alpine image does not ship jq; parse with POSIX shell only.
#  - strfry plugin protocol requires every response line to echo the event id.
#  - One JSON object per line on stdin; one response line per event.
# =============================================================================

BLOCKLIST="/etc/strfry-blocked-pubkeys.txt"

while IFS= read -r line; do
    # ── Extract event id ────────────────────────────────────────────────────
    case "$line" in
        *'"id":"'*)
            rest=${line#*'"id":"'}
            EVENT_ID=${rest%%'"'*}
            ;;
        *)
            EVENT_ID=""
            ;;
    esac

    if [ -z "$EVENT_ID" ]; then
        printf '{"id":"","action":"reject","msg":"malformed event: missing id"}\n'
        continue
    fi

    # ── Extract pubkey ──────────────────────────────────────────────────────
    case "$line" in
        *'"pubkey":"'*)
            rest=${line#*'"pubkey":"'}
            PUBKEY=${rest%%'"'*}
            ;;
        *)
            PUBKEY=""
            ;;
    esac

    # ── Blocklist check ─────────────────────────────────────────────────────
    # grep -qxF: quiet, exact full-line, fixed-string match (no regex escaping
    # needed for hex pubkeys). Falls through to accept if blocklist is absent.
    if [ -n "$PUBKEY" ] && [ -f "$BLOCKLIST" ] && \
       grep -qxF "$PUBKEY" "$BLOCKLIST" 2>/dev/null; then
        printf '{"id":"%s","action":"reject","msg":"blocked: pubkey is not permitted to write to this relay"}\n' "$EVENT_ID"
        continue
    fi

    printf '{"id":"%s","action":"accept"}\n' "$EVENT_ID"
done
