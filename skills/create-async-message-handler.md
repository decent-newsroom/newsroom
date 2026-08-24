# Skill: Create an Async Messenger Message + Handler

Use this skill whenever work must be deferred from the request/response cycle or from a relay subscription worker. All messages ride Symfony Messenger with Redis transport.

---

## Transport lanes

| Transport | Queue | Use for |
|---|---|---|
| `async` | High priority | Content fetches, relay requests with user-visible impact |
| `async_low_priority` | Low priority | Gateway persistence, login warmup, background sync |
| `async_profiles` | Profile lane | Profile refresh, metadata batch updates |

Declare the routing in `config/packages/messenger.yaml` (already wired — just add a routing entry for your new message class).

---

## 1. Create the Message

File: `src/Message/YourActionMessage.php`

```php
<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when <describe when/why>.
 *
 * Processed by YourActionHandler on the async transport.
 */
final class YourActionMessage
{
    public function __construct(
        private readonly string $pubkey,
        private readonly ?int   $since = null,
    ) {}

    public function getPubkey(): string { return $this->pubkey; }
    public function getSince(): ?int    { return $this->since; }
}
```

**Rules:**
- Plain PHP object — no Doctrine entities as properties (entities may be detached by the time the handler runs).
- Carry only scalar identifiers; reload entities inside the handler.
- Mark `final` and use readonly constructor promotion.

---

## 2. Create the Handler

File: `src/MessageHandler/YourActionHandler.php`

```php
<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\YourActionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class YourActionHandler
{
    public function __construct(
        private readonly SomeDependency  $dependency,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(YourActionMessage $message): void
    {
        $this->logger->info('Processing YourAction', [
            'pubkey' => $message->getPubkey(),
        ]);

        try {
            $this->dependency->doWork($message->getPubkey(), $message->getSince());
        } catch (\Exception $e) {
            // Let Messenger retry via its retry strategy — just log and rethrow
            $this->logger->error('YourAction failed', [
                'pubkey' => $message->getPubkey(),
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

---

## 3. Register transport routing

File: `config/packages/messenger.yaml`

```yaml
framework:
    messenger:
        routing:
            App\Message\YourActionMessage: async   # or async_low_priority / async_profiles
```

---

## 4. Dispatch from a controller or service

```php
use App\Message\YourActionMessage;
use Symfony\Component\Messenger\MessageBusInterface;

// In constructor:
public function __construct(private readonly MessageBusInterface $bus) {}

// Dispatch:
$this->bus->dispatch(new YourActionMessage($pubkey, $since));
```

### Dispatch throttling

If the same message can be dispatched many times in quick succession (e.g. per-relay, per-login), gate it with `DispatchThrottle`:

```php
use App\Service\DispatchThrottle;

if ($this->throttle->allow("your_action:{$pubkey}", ttlSeconds: 60)) {
    $this->bus->dispatch(new YourActionMessage($pubkey));
}
```

---

## 5. Testing

Add a unit test in `tests/Unit/MessageHandler/YourActionHandlerTest.php`:

```php
public function test_it_processes_the_message(): void
{
    $dependency = $this->createMock(SomeDependency::class);
    $dependency->expects($this->once())->method('doWork');

    $handler = new YourActionHandler($dependency, new NullLogger());
    $handler(new YourActionMessage('deadbeef', null));
}
```

---

## Checklist

- [ ] `src/Message/YourActionMessage.php` — final, readonly, scalar properties only
- [ ] `src/MessageHandler/YourActionHandler.php` — `#[AsMessageHandler]`, logs, rethrows
- [ ] Routing entry in `config/packages/messenger.yaml`
- [ ] Unit test created
- [ ] `CHANGELOG.md` entry added

