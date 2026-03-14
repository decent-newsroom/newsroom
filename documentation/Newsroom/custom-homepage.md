# Custom homepage

### Goal

Let a logged-in user curate their own DN front page by selecting **sources** (tags, publications/journals, follow packs, authors) and arranging **widgets** that render longform content matching those selections.

This turns the home page into a *personal newspaper*: stable layout + dynamic content.

---

## User Stories

### Reader

* As a reader, I can pick **tags** (e.g., `bitcoin`, `philosophy`) and see latest longform matching those tags.
* As a reader, I can add **publications/magazines** I like and always see new posts from them.
* As a reader, I can add **authors** and see their newest longform.
* As a reader, I can add a **follow pack** and instantly get a curated “channel” without manually selecting authors.
* As a reader, I can rearrange widgets and choose “what goes where” on my home page.

### Logged-out visitor

* Sees a default editorial/public home layout (no personalization).
* Optional: “preview personalization” with a generic pack (e.g., “DN picks”).

---

## Concepts

### Sources (what the feed is built from)

A user’s front page filter is the union of selected sources:

* **Tags**: freeform hashtags used in longform events
* **Publications/Magazines**: DN “magazine index” identity (30040 kind)
* **Authors**: pubkeys
* **Follow packs**: curated lists of pubkeys and/or journals and/or tags (a single object that expands into sources)

Each source resolves into a **query constraint** for the content index.

### Widgets (how content is presented)

Widgets are layout blocks that:

1. take a filter,
2. query the longform index,
3. render a specific view.

Examples:

* “Latest from tags”
* “From \<publication\>”
* “From \<author\>”

Widgets are **presentation**, not personalization. Personalization is the **inputs** + widget configuration.

---

## UX Spec

### Entry points

* Home page has **Customize** button (logged in).
* Dedicated route:  `/settings/home`

### Customize flow

1. **Select sources**

    * Tabs: Tags / Publications / Authors / Follow Packs
    * Each selection adds a “chip”
    * Follow pack expands to chips (with an “expanded view” / “keep pack reference” toggle)

2. **Configure layout**

    * Widget library (add widget)
    * Drag & drop reorder (columns/rows)
    * Each widget has settings:

        * Title (optional)
        * Filter: one of tags|publication|author|follow pack
        * Time window: 24h / 7d / 30d / all
        * Limit: 5/10/20

3. **Save**

    * “Save layout” persists config.

### Home rendering

* If user has no config: show DN default home (optionally with a nudge).
* If user has config: render widgets in their chosen arrangement.

---

## Data Model

### Store as Nostr event (app data kind)

Store a “Home Layout” event as a replaceable parameterized event:

* `d` tag: `dn-home-layout`
* content: empty
* tags: follow a schema that encodes:

    * version (for future migrations)
    * global sources (tags, publications, authors, follow packs)
    * widget definitions (type, filter, sort, window, limit)

---

## JSON Schema (v1)

```json
{
  "version": 1,
  "sources": {
    "tags": ["nostr", "science"],
    "authors": ["npub1...", "npub1..."],
    "publications": ["pub:dn:journal:xyz", "pub:nostr:..."],
    "followPacks": ["pack:dn:featured", "pack:nostr:..."]
  },
  "layout": {
    "columns": 2,
    "widgets": [
      {
        "id": "w1",
        "type": "top_stories",
        "title": "Top stories",
        "filterMode": "global",
        "sort": "score",
        "windowDays": 7,
        "limit": 8
      },
      {
        "id": "w2",
        "type": "latest",
        "title": "Latest in Nostr + Science",
        "filterMode": "custom",
        "sources": { "tags": ["nostr", "science"] },
        "sort": "newest",
        "windowDays": 3,
        "limit": 10
      }
    ]
  }
}
```


## Query Semantics

### Base content type

Widgets render “longform” events only (your Nostr kind for articles, e.g. 30023).

### Filter resolution

Global filter resolves to a set of constraints:

* **tags** → match `t` tags in event
* **authors** → match `pubkey`
* **publications** → match 30040 kind and resolve to their referenced articles
* **follow packs** → expand to their referenced tags/authors/publications, then apply union


### Ranking

Sort order is always newest on top.

---

## Backend Responsibilities (Symfony)

### Services

* `HomeLayoutResolver`

    * loads user layout event / DB row
    * validates schema version
    * expands follow packs into canonical source sets
    * returns normalized config

* `WidgetQueryService`

    * takes widget config + resolved sources
    * builds query against index (Elastic/Postgres/etc.)
    * returns list of content IDs + minimal metadata

* `HomePageAssembler`

    * orchestrates all widget queries
    * applies caching per widget
    * merges into view model for Twig

### Routes

* `GET /` home page
* `GET /home/customize` customize UI
* `POST /home/layout` save layout (or publish Nostr event + enqueue index)
* `POST /home/layout/reset`

### Caching strategy

* Cache **per widget** using a key derived from:

    * user_id (or layout event id)
    * widget hash (type + filter + sort + window)
    * index revision (or a short TTL)
* Use short TTL (e.g., 60–180s) for “latest”, longer for “top stories” (e.g., 5–15m)
* Apply stampede protection if you already have that pattern in DN.

---

## Permissions & Safety

* Private by default: a user’s home layout is not public unless they choose to publish it.
* As a Nostr event, have the user select specific relays and store in DB (can be db only, with an option to export/import as event)
* Prevent “relay injection” risks: follow packs can suggest relays, but DN should treat relay lists as *advisory* and keep indexing policy separate.

---

## Defaults (DN editorial homepage)

Provide a default layout that works without user input:

* Hero feature (editor picks or trending)
* Latest from “public journals”
* A few topical blocks (nostr, tech, culture)
* “Collections powered discovery” block (aligns with DN copy angle)

When a user first customizes, start from this default and let them modify.

---

## Open Questions (non-blocking, but document them)

* What’s the canonical identifier for a “publication” in DN/Nostr events?
* What Nostr kind represents follow packs (NIP-51 lists? DN-specific pack events?) and how do we expand them?
* Do we allow “negative sources” (mute tags/authors)?
* Do we allow per-widget relay scope, or keep relays strictly separate from homepage config?

