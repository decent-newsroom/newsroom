#!/bin/bash
# Write policy for strfry-chat: accept chat messages and channel management events
# Accepted kinds: 0 (metadata), 40-42 (NIP-28 channel create/metadata/message),
#                 43-44 (NIP-28 hide/mute)

while read -r line; do
    KIND=$(echo "$line" | jq -r '.event.kind')

    case "$KIND" in
        0|40|41|42|43|44)
            echo '{"action":"accept"}'
            ;;
        *)
            echo '{"action":"reject","msg":"only chat events (kinds 0, 40-44) are accepted"}'
            ;;
    esac
done

