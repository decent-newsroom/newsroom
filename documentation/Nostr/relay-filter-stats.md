# Relay filter stats

Answers two operator questions:

1. **What filters do we typically send to relays?**
2. **Which filters take long to resolve?**

The relay gateway (`src/Command/RelayGatewayCommand.php`) records per-REQ stats
into Redis, keyed by a privacy-preserving "filter signature".

## Where it lives

- Service: `App\Service\Nostr\RelayFilterStatsStore`
- Storage: Redis hash `relay_filter_stats:{normalizedRelayUrl}` + index set `relay_filter_stats:_index`
- TTL: 7 days (configured relays) / 24 h (ad-hoc) — mirrors `RelayHealthStore`.

## Filter signature

A filter signature describes the shape of a NIP-01 filter without leaking
content the relay operator should not see. Built by
`RelayFilterStatsStore::signature(array $filter)`.

| Filter field | Signature contribution |
|---|---|
| `kinds` | `kinds=[1,30023]` — actual kinds, sorted, deduped |
| `authors` | `authors=N12` — count only, never hex pubkeys |
| `ids` | `ids=N3` — count only |
| `#e`, `#p`, `#t`, `#a`, `#d`, `#k`, … | `#t=N4` — tag key + count only, never values |
| `since`, `until` | presence flag only (`since`, `until`) — never timestamp |
| `limit` | `limit=50` — exact value (common limits form natural buckets) |
| `search` | `search` — presence flag only |

Examples:

```
kinds=[30023];authors=N12;limit=20;since
kinds=[1,1111];#e=N1
kinds=[0];authors=N50
kinds=[10002];authors=N1;limit=1
```

## Hooks

Recorded automatically by `RelayGatewayCommand`:

| Hook | Method called | When |
|---|---|---|
| REQ sent | `recordRequest($relay, $sig)` | At pendingQueries registration |
| EOSE received | `recordEose($relay, $sig, $latencyMs, $eventCount)` | In `completeQuery()` |
| Relay sent CLOSED | `recordTimeout($relay, $sig)` | In `completeQueryWithError()` |
| Deadline expired | `recordTimeout($relay, $sig)` | In `sweepTimedOutPending()` |

The latency stored here is the same REQ→EOSE measurement that feeds
`RelayHealthStore::recordSuccess($url, $ms)` — they originate from the same
`pendingQueries[$subId]['startedAt']` timestamp, so per-relay `avg_latency_ms`
in the relay health table and per-filter `avg_ms` here are directly comparable.

## Surfaces

### CLI

```bash
# Top 20 filter shapes globally, by frequency
docker compose exec php bin/console relay:filter-stats

# Slowest filter shapes by average latency
docker compose exec php bin/console relay:filter-stats --sort=avg --top=10

# Drill into one relay
docker compose exec php bin/console relay:filter-stats --relay=wss://relay.damus.io

# Wipe stats (e.g., after a behaviour change)
docker compose exec php bin/console relay:filter-stats --clear
```

Sort options: `count` (default), `avg`, `max`, `timeout`.

### Admin web UI

`/admin/relay/filters` — global table of top filter shapes plus a per-relay
summary. Click any relay URL to drill down into the per-signature breakdown
for that relay.

Latency columns are colour-graded:

- < 800 ms — neutral
- 800–1500 ms — amber
- \> 1500 ms — red

Timeouts are highlighted red whenever non-zero.

## Privacy guarantees

- No author pubkeys, event ids, or tag values ever enter Redis under this key.
- Signatures are derived purely from kind numbers (public, low-cardinality)
  and shape counts.
- Even an attacker with full Redis access could only learn what kinds and
  filter shapes the instance has been requesting — which is already
  observable to any relay operator.

## What this lets you tell a relay friend

If a relay operator asks "what are you typically asking my relay for?" — you
can pull `relay:filter-stats --relay=wss://their.relay --sort=count` and
share the top filter shapes plus their average resolution latency. The
signatures are safe to copy-paste in chat: they never identify a specific user.

If they ask "which queries are slow on my side?" — sort by `avg` or `max` to
see exactly which kinds + shapes are exceeding their EOSE budget.

