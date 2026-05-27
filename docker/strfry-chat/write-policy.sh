#!/bin/sh
# =============================================================================
# Chat relay write policy — accept only chat / channel-management kinds
# =============================================================================
# Allowed kinds:
#   0        - metadata
#   40 - 44  - NIP-28 channel create / metadata / messages / hide / mute
#
# Implementation notes:
#  - dockurr/strfry does not ship jq, so parse with POSIX shell only.
#  - strfry's plugin protocol requires every response to echo the event id.
# =============================================================================

while IFS= read -r line; do
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
        printf '{"id":"","action":"reject","msg":"malformed event"}\n'
        continue
    fi

    case "$line" in
        *'"kind":0,'*|*'"kind": 0,'*|*'"kind":0}'*|*'"kind": 0}'*|*'"kind":40,'*|*'"kind": 40,'*|*'"kind":40}'*|*'"kind": 40}'*|*'"kind":41,'*|*'"kind": 41,'*|*'"kind":41}'*|*'"kind": 41}'*|*'"kind":42,'*|*'"kind": 42,'*|*'"kind":42}'*|*'"kind": 42}'*|*'"kind":43,'*|*'"kind": 43,'*|*'"kind":43}'*|*'"kind": 43}'*|*'"kind":44,'*|*'"kind": 44,'*|*'"kind":44}'*|*'"kind": 44}'*)
            printf '{"id":"%s","action":"accept"}\n' "$EVENT_ID"
            ;;
        *)
            printf '{"id":"%s","action":"reject","msg":"only chat events (kinds 0, 40-44) are accepted"}\n' "$EVENT_ID"
            ;;
    esac
done

