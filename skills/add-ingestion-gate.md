# Skill: Implement an Ingestion-Time Event Gate

Use this skill whenever you need to silently drop (shadow-ban) events at ingestion before they touch any table — for author bans, coordinate bans, or any future hard-block policy.

The canonical example is `EventDeletionService` (NIP-09 tombstone suppression). A second layer (global admin suppression) follows the same two hook points.

---

## The two hook points

Every incoming Nostr event passes through exactly one of these two code paths:

| Source | Hook point |
|---|---|
| Subscription workers / relay push | `GenericEventProjector::projectEventFromNostrEvent()` |
| Gateway connection pool | `PersistGatewayEventsHandler::__invoke()` |

Both already contain the NIP-09 tombstone check. Any new gate must be added to **both**.

---

## Pattern: adding a new check

### 1. Write a predicate service

```php
<?php
declare(strict_types=1);

namespace App\Service;

class YourBlockPolicy
{
    public function __construct(
        private readonly YourBlockRepository $repository,
    ) {}

    /** Returns true when the event must be silently dropped. */
    public function isBlocked(object $nostrEvent): bool
    {
        return $this->repository->exists($nostrEvent->pubkey);
    }
}
```

### 2. Wire into `GenericEventProjector::projectEventFromNostrEvent()`

```php
// After existing deletion-tombstone check, before persist:
if ($this->yourBlockPolicy->isBlocked($event)) {
    $this->logger->debug('Event silently dropped by block policy', [
        'id'     => $event->id,
        'pubkey' => $event->pubkey,
        'kind'   => $event->kind,
    ]);
    // Return a stub or throw — match the existing NIP-09 flow exactly.
    // Currently the tombstone check throws \RuntimeException('event is deleted');
    // Use the same exception class so callers handle it uniformly.
    throw new \RuntimeException('event blocked by admin policy');
}
```

### 3. Wire into `PersistGatewayEventsHandler::__invoke()`

Apply the same check in the gateway handler, immediately after the existing tombstone check:

```php
if ($this->yourBlockPolicy->isBlocked($event)) {
    $this->logger->debug('Gateway event dropped by block policy', [
        'id'     => $event->id ?? null,
        'pubkey' => $event->pubkey ?? null,
    ]);
    return; // Gateway handler returns void, so just return early.
}
```

---

## Checklist for a new gate

- [ ] Predicate service created and injected into both hook points
- [ ] Drop is **silent** (no error to the client, just a log `debug` line)
- [ ] Drop is **idempotent** — calling it twice produces the same result
- [ ] Both `GenericEventProjector` and `PersistGatewayEventsHandler` updated
- [ ] Unit tests cover: event IS blocked, event is NOT blocked, edge cases (null pubkey)
- [ ] Gherkin `.feature` spec added in `tests/NIPs/` or `tests/` describing the expected drop behaviour
- [ ] `CHANGELOG.md` entry added

---

## Related reading

- `src/Service/EventDeletionService.php` — tombstone shadow-ban reference implementation
- `src/Service/GenericEventProjector.php` — primary ingestion hook
- `src/MessageHandler/PersistGatewayEventsHandler.php` — gateway ingestion hook
- Backlog item: **Global admin suppression** (see `AGENTS.md`) for the full design including `BannedPubkey` entity, reaper command, and admin CLI.

