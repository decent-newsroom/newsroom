# Skill: Add a New Relay Purpose / Augment Relay Selection

Use this skill when a feature needs to read from additional relays beyond the global registry defaults — e.g. per-user favourite relays, per-community relay pools, per-publication relay hints.

---

## Architecture overview

```
RelayRegistry (global, config-driven)
    purpose: LOCAL   → internal strfry WebSocket
    purpose: PROFILE → purplepag.es + others
    purpose: CONTENT → public content relays
    purpose: PROJECT → relay.decentnewsroom.com
    purpose: SIGNER  → nsec.app, primal, damus
    purpose: CHAT    → chat relays
    purpose: USER    → (runtime, per-request, populated from NIP-65)
```

**Golden rule:** never mutate `CONTENT`, `PROFILE`, or any other global purpose with per-user data. Augment only the `USER` purpose, or build a local `RelaySetFactory` call with the merged set.

---

## 1. Reading a user's NIP-65 relay list

```php
use App\Service\Nostr\UserRelayListService;

$relayList = $this->userRelayListService->getRelayList($pubkeyHex);
// Returns UserRelayList|null with ->getReadRelays() and ->getWriteRelays()
```

`UserRelayListService` uses stale-while-revalidate: Redis → DB → network → fallback.

---

## 2. Building a per-request relay set via `RelaySetFactory`

```php
use App\Service\Nostr\RelaySetFactory;
use App\Enum\RelayPurpose;

// Merge user NIP-65 relays with content relays, capped and deduped:
$relays = $this->relaySetFactory->forUserContent(
    userRelayList: $relayList,
    maxUserRelays: 5,
);
```

If `RelaySetFactory` does not have a method for your use case, add one there rather than building ad-hoc merge logic in a service.

---

## 3. Adding a new global relay purpose

Only do this when the new purpose is system-wide, not per-user.

**Step 1** — Add to `RelayPurpose` enum:

File: `src/Enum/RelayPurpose.php`

```php
case FAVOURITE = 'favourite';
```

**Step 2** — Add parameter in `config/services.yaml`:

```yaml
parameters:
    relay_registry.favourite_relays:
        - 'wss://example.com'
```

**Step 3** — Wire in `RelayRegistry` constructor:

```php
public function __construct(
    // ...existing args...
    array $favouriteRelays = [],
) {
    // ...existing assignments...
    $this->relays[RelayPurpose::FAVOURITE->value] = $favouriteRelays;
}
```

**Step 4** — Inject the parameter in `services.yaml`:

```yaml
App\Service\Nostr\RelayRegistry:
    arguments:
        $favouriteRelays: '%relay_registry.favourite_relays%'
```

---

## 4. Per-user relay augmentation (runtime, never global)

Fetch the user's extra relays and merge them with the registry set **locally, within the request**:

```php
$baseRelays  = $this->relayRegistry->getRelays(RelayPurpose::CONTENT);
$userRelays  = $this->getFavouriteRelays($pubkeyHex); // your new service
$mergedUrls  = array_unique(array_merge($baseRelays, $userRelays));
$capped      = array_slice($mergedUrls, 0, 10); // hard cap
```

Do **not** write the merged set back to `RelayRegistry`.

---

## 5. Relay health ordering

When picking from a merged set, prefer healthy relays:

```php
use App\Service\Nostr\RelayHealthStore;

$ordered = $this->relayHealthStore->sortByHealth($capped);
```

---

## Guardrails

| Rule | Why |
|---|---|
| Never promote per-user relays to global registry | Fan-out growth, worker pressure |
| Cap added relays (5–10 max) | Avoid fan-out growth |
| Use `RelayUrlNormalizer` on any URL before storing | Ensures consistent deduplication |
| Never store `ws://` (plain WS) in user-facing relay lists | Security |

---

## Checklist

- [ ] New purpose added to `RelayPurpose` enum (if global)
- [ ] Parameter added to `config/services.yaml` (if global)
- [ ] `RelayRegistry` wired for the new purpose (if global)
- [ ] Per-user augmentation stays local to the request — never written back to registry
- [ ] `RelayUrlNormalizer` applied to any URL from user input
- [ ] Cap applied (max 5–10 relays for user-supplied sets)
- [ ] `CHANGELOG.md` entry added

