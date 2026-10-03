NIPs on Nostr
=============

This NIP defines kind `30817` for community-authored NIPs. These events allow users to create, share, and maintain custom protocol specifications that extend or complement the official Nostr protocol.

## Custom NIP Event

Custom NIP events are addressable events of kind `30817` with the following structure:

```json
{
  "kind": 30817,
  "content": "<markdown content of the NIP>",
  "tags": [
    ["d", "<unique-identifier>"],
    ["title", "<human-readable title>"],
    ["k", "<kind-number>", "<kind-name>"],
    ["k", "<another-kind-number>", "<another-kind-name>"]
  ]
}
```

### Content

The `content` field SHOULD contain the full specification of the custom NIP in Markdown format.

### Tags

- `d` (required) - unique identifier for the NIP. MAY be auto-generated from the title using URL-safe slugification.
- `title` (required) - human-readable title for the NIP.
- `k` (optional) - Zero or more `k` tags MAY be included to specify event kinds that this NIP defines or concerns. The first value is the event kind number as a string; the second value is a human-readable name for that kind (e.g. `["k", "37516", "Geocache Listing"]`).

## Clients

Clients implementing custom NIP support SHOULD:

- Display custom NIPs distinctly from official NIPs
- Show authorship information prominently
- Provide search and filtering capabilities
- Support Markdown rendering for content

## Relays

Relays MAY index `k` tags of custom NIPs for discovery of NIPs that define custom kinds.

## Examples

### Basic Custom NIP

```json
{
  "kind": 30817,
  "content": "# Custom Event Kind for Gaming\n\nThis NIP defines event kind 30100 for gaming-related events...",
  "tags": [
    ["d", "gaming-events"],
    ["title", "Gaming Events Protocol"],
    ["k", "30100", "Game Session"]
  ]
}
```

### Multi-Kind Custom NIP

```json
{
  "kind": 30817,
  "content": "# Marketplace Protocol Extension\n\nThis NIP extends the marketplace protocol with additional event kinds...",
  "tags": [
    ["d", "marketplace-extension"],
    ["title", "Extended Marketplace Protocol"],
    ["k", "30200", "Marketplace Listing"],
    ["k", "30201", "Marketplace Order"],
    ["k", "30202", "Marketplace Review"]
  ]
}
```

## References

- [NIP frosty frost: NUDs](https://github.com/nostr-protocol/nips/pull/1214)
- [NUD: Nostr Unofficial Documents](https://github.com/nostr-protocol/nips/pull/1519)
