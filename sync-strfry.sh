#!/bin/bash

docker compose exec strfry ./strfry sync wss://theforest.nostr1.com --filter '{"kinds":[9802,1111,30023,0]}' --dir down
docker compose exec strfry ./strfry sync wss://relay.damus.io --filter '{"kinds":[9802,1111,30023,0]}' --dir down
