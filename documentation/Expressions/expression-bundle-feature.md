# ExpressionBundle

> **Added in:** v0.0.30

## Overview

The ExpressionBundle implements the NIP-EX + NIP-FX expression runner — a self-contained Symfony bundle at `src/ExpressionBundle/` that parses kind:30880 feed expression events into a pipeline, evaluates filter/sort/set/score operations against normalized Nostr events, and exposes an authenticated API endpoint for personalized feed serving.

## Architecture

### Data Flow

```
kind:30880 event (tags array)
       │
       ▼
  ExpressionParser  →  Pipeline (ordered list of Stage objects)
       │
       ▼
  ExpressionRunner  →  iterates stages in order
       │                    │
       │                    ├─ resolve inputs (SourceResolver)
       │                    ├─ apply operation (filter/sort/slice/set/score)
       │                    └─ store result → next stage input
       │
       ▼
  NormalizedItem[]  →  original Event objects preserved
```

### API Endpoint

**`GET /api/feed/{naddr}`** — Requires `ROLE_USER` (authenticated).

Parameters:
- `naddr` — NIP-19 encoded address of a kind:30880 expression event
- `offset` (query, optional) — Pagination offset (default: 0)
- `limit` (query, optional) — Results per page (default: 50, max: 500)

Response:
```json
{
    "expression": "30880:<pubkey>:<d>",
    "count": 20,
    "offset": 0,
    "limit": 50,
    "events": [
        { "id": "...", "pubkey": "...", "kind": 30023, "content": "...", "tags": [...], "created_at": 1712345678, "sig": "..." }
    ]
}
```

Error codes:
| HTTP | When |
|------|------|
| 400 | Structural errors (unknown op, invalid argument, arity, type) |
| 401 | Not authenticated |
| 404 | Referenced input not found |
| 422 | Circular reference or unknown variable |
| 501 | Unsupported feature |
| 504 | Execution timeout |

### Operations

| Operation | Type | Description |
|-----------|------|-------------|
| `all` | Filter | Keep items where ALL clauses match |
| `any` | Filter | Keep items where ANY clause matches |
| `none` | Filter | Keep items where NO clause matches |
| `sort` | Sort | Sort by prop/tag/derived field, num or alpha mode |
| `slice` | Slice | Offset + limit pagination |
| `distinct` | Dedup | Deduplicate by canonical identity |
| `union` | Set | Merge inputs (deduplicated) |
| `intersect` | Set | Keep shared items across inputs |
| `difference` | Set | Subtract later inputs from first |
| `score` | NIP-FX | Weighted scoring with normalizers |

### Normalizers (NIP-FX)

| Name | Formula |
|------|---------|
| `identity` | Raw numeric value |
| `recency` | `1/(1+(hours_since/half_life))` |
| `log` | `sign(v)*log10(1+abs(v))` |
| `in` | 1.0 if value ∈ set, else 0.0 |
| `contains-ci` | 1.0 if case-insensitive substring match |
| `count` | Count of referencing events by kind |

### Clause Types

| Type | Semantics |
|------|-----------|
| `match` | Item value ∈ value set (absent → false) |
| `not` | Item value ∉ value set (absent → true) |
| `cmp` | Numeric/string comparison (absent → false) |
| `text` | Text matching: contains-ci, eq-ci, prefix-ci (absent → false) |

### Runtime Variables

| Variable | Resolves to |
|----------|-------------|
| `$me` | Authenticated user's hex pubkey |
| `$contacts` | User's kind:3 follow list (may be empty) |
| `$interests` | User's kind:10015 interest tags (may be empty) |

### Configuration

Set in `config/packages/expression.yaml`:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `expression.cache_ttl` | 300 | Cache TTL in seconds |
| `expression.max_depth` | 5 | Max recursion depth |
| `expression.max_execution_time` | 10 | Max execution time in seconds |

### Source Resolution

Input references are resolved DB-first with relay fallback:

- **`e` (event ID)** → `EventIdSourceResolver` (DB → relay)
- **`a` (address)** → `AddressSourceResolver` dispatches by kind:
  - kind:30880 → recursive expression evaluation (with cycle detection)
  - kind:777 → spell execution (parse filter → query DB/relays)
  - kind:30003/30004/30005/30006/10003 → NIP-51 list expansion
  - other kinds → generic event lookup

### Event Kinds

| Kind | Name | Role |
|------|------|------|
| 777 | Spell (NIP-A7) | Portable query filter |
| 30880 | Feed Expression (NIP-EX) | Publishable feed definition |

