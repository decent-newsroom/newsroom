# NIP-XX — Tabular Data (CSV)

`draft` `optional`

## Summary

Defines a **non-addressable event kind** for small CSV datasets shared directly on relays.  
Intended for compact tables (scores, polls, stats, research data) that clients can render as responsive tables.

---

## Event Kind

- **kind:** `1450`  
  (non-replaceable, small-data event)

---

## Format

### Content

Plain UTF-8 CSV text (RFC 4180-style):

- `,` separator  
- `"` quote  
- first row = header (required)  
- total content ≤ 64 KB  

Clients **must not** execute formulas or interpret data beyond plain text.

---

### Tags

| Tag | Purpose | Example |
|-----|----------|----------|
| `["title","Bitcoin Hashrate, 2025"]` | human-readable name |  |
| `["m","text/csv"]` | MIME type (short form) | required |
| `["M","text/csv; charset=utf-8"]` | full MIME type declaration | required |
| `["sep",";"]` | field separator override | optional |
| `["quote","'"]` | quote char override | optional |
| `["hdr","1"]` | header present (`1` = true, default) | optional |
| `["unit","col=3","TH/s"]` | units per column | optional |
| `["license","CC-BY-4.0"]` | license info | optional |

---

## Example

```json
{
  "kind": 1450,
  "content": "date,hashrate\n2025-10-01,795\n2025-10-02,802\n",
  "tags": [
    ["title","Bitcoin hashrate, October 2025"],
    ["m","text/csv"],
    ["M","text/csv; charset=utf-8"],
    ["unit","col=2","EH/s"],
    ["license","CC-BY-4.0"]
  ],
  "created_at": 1759992000,
  "pubkey": "…",
  "id": "…",
  "sig": "…"
}
````

---

## Client Behavior

1. **Parse** header row for column names.
2. **Render as responsive table**:
    * allow horizontal scroll on small screens;
    * hide low-priority columns if needed.
3. Provide **“Copy CSV” / “Download”** actions.
4. Show metadata
5. Sanitize text; never run formulas or embedded URIs

---

## Relay Behavior

* Treat as normal event (`kind:10500`).
* Apply a strict ≤ 64 KB limit.
* Larger or external datasets are out of scope.

---

## Rationale

This minimal NIP provides a safe, interoperable way to share small CSV tables directly on Nostr without versioning or external file complexity.
It’s designed to prevent confusion and data mutation.

---

