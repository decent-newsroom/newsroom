# Decent Newsroom as a Nostr CMS

Decent Newsroom is a content management system built entirely on the Nostr protocol. Unlike traditional CMS platforms where content lives in a proprietary database under the platform's control, every piece of content here is a cryptographically signed Nostr event that belongs to its author — not to any server.

## The Core Idea

A traditional CMS stores your articles in its own database. If the platform shuts down, your content disappears. If you move to a new platform, you have to export and re-import everything and hope nothing breaks.

A Nostr CMS works differently. Your content is a signed message broadcast to a peer-to-peer network of relays. Any compatible client can read it. You hold the key — lose access to the platform, and your content is still out there, retrievable by any Nostr client.

Decent Newsroom is the editorial layer on top of that network: a structured, full-featured CMS experience backed by a decentralized, author-owned data layer.

## How Content Is Stored

### Events, Not Rows

Every piece of content in the Nostr ecosystem is an **event** — a JSON object with a defined structure:

```json
{
  "id": "<sha256 hash of the event>",
  "pubkey": "<author's public key (hex)>",
  "created_at": 1716890000,
  "kind": 30023,
  "tags": [
    ["d", "my-article-slug"],
    ["title", "My Article Title"],
    ["t", "technology"],
    ["published_at", "1716890000"]
  ],
  "content": "# My Article\n\nBody text in Markdown...",
  "sig": "<Schnorr signature>"
}
```

The `sig` field is a cryptographic signature over the entire event. It cannot be forged. This means:

- **Authorship is provable** — the public key identifies the author, the signature proves they wrote it
- **Content is tamper-evident** — any modification breaks the signature
- **No central authority needed** — any relay, or any reader, can verify the event independently

### Event Kinds as CMS Content Types

Nostr uses a `kind` number to distinguish event types. Decent Newsroom maps these directly to familiar CMS concepts:

| CMS Concept | Nostr Kind | NIP |
|-------------|------------|-----|
| Long-form article | 30023 | NIP-23 |
| Draft article | 30024 | NIP-23 |
| User profile | 0 | NIP-01 |
| Magazine / Publication index | 30040 | NKBIP-01 |
| Magazine content section | 30041 | NKBIP-01 |
| Article highlight / annotation | 9802 | NIP-84 |
| Comment | 1111 | — |
| Bookmark list | 10003 | NIP-51 |
| Reading list / Curation | 30004 | NIP-51 |
| Interest tags | 10015 | NIP-51 |
| Follow list | 3 | NIP-02 |
| Relay list | 10002 | NIP-65 |
| Media image | 20 | NIP-68 |
| Media video | 21/22 | NIP-71 |
| Reaction / Like | 7 | NIP-25 |
| Zap receipt | 9735 | NIP-57 |

Articles (kind 30023) are **replaceable addressable events**: the combination of `pubkey + kind + d-tag` uniquely identifies an article. Publishing a new version with the same `pubkey + kind + d-tag` automatically supersedes the previous one. There is no separate "update" API call — you simply broadcast a newer, re-signed event.

## The Content Lifecycle

### 1. Write

The author opens the editor (`/editor`). The article body is written in Markdown, with a rich-text editor (Quill) providing formatting tools. Images are uploaded to a Blossom or NIP-96 compatible media server, and the resulting URLs are embedded in the content.

The editor auto-saves drafts as kind 30024 events (or as local database records before publish). Drafts are only visible to the author.

### 2. Sign

When the author clicks "Publish", the browser constructs a kind 30023 event from the article content and metadata. The event is **not transmitted to the server** for signing — it is signed locally, in the browser, using the author's private key via one of two methods:

- **NIP-07** — A browser extension (Alby, nos2x, etc.) holds the private key. The editor calls `window.nostr.signEvent()` and the extension prompts for approval.
- **NIP-46** — A remote signer (Nsec Bunker) holds the key. The editor communicates with the bunker over a relay, which countersigns and returns the event.

In neither case does the server ever see the private key.

### 3. Broadcast

The signed event is broadcast from the browser (or via the server as a relay proxy) to the author's configured write relays (their NIP-65 relay list, kind 10002) plus the local instance relay. Multiple relays receive identical copies simultaneously.

### 4. Index

The local **strfry** relay receives the event via its router. A persistent subscription worker (`worker-relay`) detects the new event and triggers the `ArticleEventProjector`, which:

- Stores the event in PostgreSQL (full-text indexed)
- Updates the graph layer (`current_record` + `parsed_reference` tables)
- Populates the Redis view store for fast reads
- Optionally indexes into Elasticsearch

### 5. Read

Readers retrieve the article from the local PostgreSQL/Redis layer — fast, structured, cached. The canonical source remains the signed Nostr event; the database is a **projection** of the network state, not the source of truth.

## Identity Without Accounts

In a traditional CMS, your identity is an account: a username and password owned by the platform. Lose access to the platform, lose your identity.

In Nostr, your identity is a **keypair**:

- Your **public key** (`npub1…`) is your permanent, portable identity across every Nostr application
- Your **private key** (`nsec1…`) is yours alone — never shared with any server

Decent Newsroom never holds private keys. The platform knows your public key and your signed profile metadata (kind 0), but cannot impersonate you or revoke your identity.

Authentication works by signing a challenge:

```
Server presents challenge → Browser signs it → Server verifies signature → Session started
```

This means you can log in to any instance of Decent Newsroom (or any other Nostr client) with the same identity. Your articles follow you.

## The Relay Network as Storage

### Local Relay

Each Decent Newsroom instance runs a **strfry** relay — a local cache of the events it has indexed. This relay serves two purposes:

1. **Ingestion** — Receives events from authors publishing through the platform and from external relays via the gateway
2. **Subscription** — Background workers subscribe to it for event processing

The local relay is not the only source of truth. It is a cache of what the instance has seen from the wider network.

### External Relays

When a user logs in, Decent Newsroom fetches their NIP-65 relay list (kind 10002) and builds a personalized relay pool:

- **Read relays** — Where the user expects to receive events
- **Write relays** — Where the user publishes events

Content fetching uses this pool to discover articles from followed authors, even if those articles were never published to the local instance relay.

### The Gateway (Optional)

For instances that need persistent relay connections, the **relay gateway** service maintains a long-lived WebSocket connection pool to external relays with NIP-42 AUTH support. Events flowing in are forwarded to the local strfry relay and persisted to the database by background workers.

## Magazines as Structured Publications

Magazines are the CMS equivalent of a publication or a curated collection. They are implemented as **NKBIP-01** publication index events (kind 30040).

A magazine index event contains:
- Magazine metadata (title, description, image) in tags
- `a`-tag references to category section events (kind 30041)
- Each category section references articles by their coordinate (`30023:<pubkey>:<d-tag>`)

This creates a fully portable, self-describing publication structure:

```
Magazine (kind 30040)
└─ Category A (kind 30041)
   ├─ Article 1 (kind 30023)
   └─ Article 2 (kind 30023)
└─ Category B (kind 30041)
   └─ Article 3 (kind 30023)
```

Any Nostr client that understands NKBIP-01 can render this structure. The magazine is not locked to Decent Newsroom.

### Unfold: Subdomain Hosting

Magazines can be published at custom subdomains via **Unfold**. A magazine at `mypublication.newsroom.pub` is rendered entirely from the graph layer — no relay round-trips at request time. The underlying data is still Nostr events; the subdomain is a presentation layer.

## Content Ownership in Practice

### Portability

Because articles are signed Nostr events on public relays, an author can:

- Leave Decent Newsroom and import their articles into any other Nostr-compatible CMS
- Run their own relay and take full custody of their events
- Grant curation rights to a magazine operator without transferring authorship

### Deletion

Nostr defines event deletion via kind 5 (NIP-09). An author can publish a deletion request referencing an event ID, and compliant relays will remove it. Decent Newsroom respects these deletion requests during ingestion — deleted events are not re-stored.

Note that deletion on Nostr is a best-effort social convention, not a technical guarantee. Relays that have not seen the deletion request may still serve the original event. The author's signature remains valid regardless.

### Verification

Anyone can independently verify that an article was written by a given public key:

1. Take the raw event JSON (minus the `sig` field)
2. SHA-256 hash it
3. Verify the Schnorr signature over that hash using the `pubkey`

No trusted third party is involved in this verification. The math is the authority.

## What the Platform Adds

Nostr's event model is simple by design. Decent Newsroom adds the editorial and discovery layer that makes it a usable CMS:

| Raw Nostr | Decent Newsroom adds |
|-----------|---------------------|
| Signed events on relays | Full-text search and filtering |
| Public keys as identity | Profile display, NIP-05 verification badges |
| Kind 30023 events | Rich editor, draft management, publishing workflow |
| Kind 30040 index events | Magazine wizard, category management, Unfold hosting |
| Kind 9802 highlights | Text selection UI, highlight publishing, display on articles |
| Kind 42 chat messages | Structured NIP-28 chat rooms on subdomains |
| Kind 20/21/22 media | Media manager, discovery, curation |
| NIP-57 zap receipts | Lightning payment UI, zap display |
| Relay lists (kind 10002) | Relay list editor, health monitoring, gateway management |

## Summary

Decent Newsroom is a CMS where:

- **Content** is cryptographically signed Nostr events — portable, verifiable, author-owned
- **Identity** is a keypair — no accounts, no passwords, no platform lock-in  
- **Storage** is a distributed relay network — the local database is a cache and index, not the source of truth
- **Publications** are structured as NKBIP-01 magazine events — self-describing, renderable by any compatible client
- **The platform** provides the editorial UX, discovery, search, and hosting layer on top of the open protocol

The content you create here belongs to you. The platform is a tool, not a gatekeeper.

---

**Related:**
- [Features Overview](FEATURES.md)
- [Architecture Overview](ARCHITECTURE.md)
- [Getting Started](GETTING-STARTED.md)
- [Nostr Protocol Reference](../documentation/NIP/)

