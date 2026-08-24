# Skill: Add Support for a New Nostr Event Kind

Use this skill whenever a NIP introduces a new event kind that needs to be ingested, persisted, and/or surfaced in the UI.

---

## 1. Register the kind in `KindsEnum`

File: `src/Enum/KindsEnum.php`

```php
case YOUR_KIND = 10012; // NIP-51, favorite relays list
```

Include the kind number, a concise name, and the relevant NIP in the inline comment. Keep cases in ascending numeric order.

---

## 2. Add to login sync

File: `src/MessageHandler/SyncUserEventsHandler.php`

Add the new `KindsEnum` constant to the `SYNC_KINDS` array constant. Also update the docblock at the top of the class listing all fetched kinds.

```php
private const SYNC_KINDS = [
    // ...existing kinds...
    KindsEnum::YOUR_KIND->value,
];
```

This ensures the kind is fetched from the user's NIP-65 relays at login and forwarded to local strfry.

---

## 3. Add to local user-context subscription worker

File: `src/Command/SubscribeLocalUserContextCommand.php`

Add the constant to `SUBSCRIBE_KINDS`:

```php
private const SUBSCRIBE_KINDS = [
    // ...existing kinds...
    KindsEnum::YOUR_KIND->value,
];
```

Update the class-level docblock to list the new kind.

This worker persists events from strfry to PostgreSQL so DB-first lookups in controllers work without hitting external relays.

---

## 4. Decide: generic projection or specialised projector?

| Criteria | Use `GenericEventProjector` | Create a specialised projector |
|---|---|---|
| Store raw event only | ✓ | |
| Needs a dedicated entity/table | | ✓ |
| Needs side-effects on ingestion | | ✓ |
| Already handled by an existing projector | ✓ | |

### 4a. Generic projection (most user-context kinds)

`GenericEventProjector::projectEventFromNostrEvent` already stores all kinds in the `event` table. No code change needed beyond steps 1–3.

### 4b. Specialised projector

Create `src/Service/Nostr/Projector/YourKindProjector.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Nostr\Projector;

use App\Entity\YourEntity;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class YourKindProjector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(object $event): bool
    {
        return (int) $event->kind === KindsEnum::YOUR_KIND->value;
    }

    public function project(object $event): void
    {
        // parse tags, upsert entity, flush
    }
}
```

Then wire it inside `GenericEventProjector::projectEventFromNostrEvent` (alongside the existing `$this->highlightProjector->...` call) or in `PersistGatewayEventsHandler::__invoke` — whichever hook fires for your transport path.

---

## 5. Wire a service to read the kind

Create `src/Service/YourKindService.php` that reads from the `event` table via `EventRepository`:

```php
public function getLatestForUser(string $pubkeyHex): ?Event
{
    return $this->eventRepository->findLatestByPubkeyAndKind(
        $pubkeyHex,
        KindsEnum::YOUR_KIND->value,
    );
}
```

---

## 6. Add a NIP feature spec

File: `tests/NIPs/NIP-XX.feature`

See skill **write-nip-feature-spec** for the Gherkin template.

---

## 7. Documentation

Create or update `documentation/Nostr/<feature>.md`. Add a one-liner to `CHANGELOG.md` under the current development version:

```
- [Feature] Added support for kind `10012` (NIP-51 favorite relays) — ingested at login and persisted via local relay subscription worker.
```

---

## Checklist

- [ ] `KindsEnum` constant added with NIP reference comment
- [ ] `SyncUserEventsHandler::SYNC_KINDS` updated + docblock updated
- [ ] `SubscribeLocalUserContextCommand::SUBSCRIBE_KINDS` updated + docblock updated
- [ ] Projector created/wired (if needed)
- [ ] Service to read the kind created (if needed)
- [ ] Gherkin feature spec added in `tests/NIPs/`
- [ ] Documentation file created/updated
- [ ] `CHANGELOG.md` entry added

