# Skill: Add a Redis Read Model (View)

Use this skill when a page needs fast rendering from pre-computed data without hitting PostgreSQL on every request. Read models are written by cron/commands and read by controllers.

---

## When to use a Redis view vs direct DB query

| Use Redis view when | Use DB query when |
|---|---|
| Data is rendered on every page load (home feed, profile header) | Data is low-traffic or admin-only |
| The query is expensive (joins, aggregations) | Data must always be fresh |
| Content is rebuilt on a schedule (cron) | User-owned, session-specific data |

---

## 1. Create the view class

File: `src/ReadModel/RedisView/YourView.php`

```php
<?php

declare(strict_types=1);

namespace App\ReadModel\RedisView;

/**
 * Redis view model for <describe what this represents>.
 *
 * Property names intentionally match what Twig templates expect
 * so no mapping layer is needed between the view and the template.
 */
final class YourView
{
    public function __construct(
        public string  $id,
        public string  $pubkey,
        public string  $title,
        public ?\DateTimeImmutable $createdAt = null,
        public ?string $summary = null,
        public array   $topics  = [],
    ) {}
}
```

**Rules:**
- `final` class.
- All properties `public` and `readonly`-style (constructor promotion).
- Property names **must** match what Twig templates access (avoids mapping layers).
- Use `\DateTimeImmutable` for timestamps; Redis serialises them as ISO strings.

---

## 2. Add factory logic to `RedisViewFactory`

File: `src/ReadModel/RedisView/RedisViewFactory.php`

```php
public function fromYourEntity(YourEntity $entity): YourView
{
    return new YourView(
        id:        (string) $entity->getId(),
        pubkey:    $entity->getPubkey(),
        title:     $entity->getTitle() ?? '',
        createdAt: $entity->getCreatedAt(),
        summary:   $entity->getSummary(),
        topics:    $entity->getTopics() ?? [],
    );
}
```

---

## 3. Write views in `RedisViewStore`

File: `src/Service/Cache/RedisViewStore.php`

Add a write method:

```php
private const YOUR_LIST_KEY = 'your_list_v1';
private const YOUR_TTL      = 3600; // seconds

public function saveYourList(array $views): void
{
    $this->redis->set(
        self::YOUR_LIST_KEY,
        serialize($views),
        ['ex' => self::YOUR_TTL],
    );
}

public function getYourList(): array
{
    $raw = $this->redis->get(self::YOUR_LIST_KEY);
    if (!$raw) {
        return [];
    }
    return unserialize($raw, ['allowed_classes' => [YourView::class, \DateTimeImmutable::class]]);
}

public function invalidateYourList(): void
{
    $this->redis->del(self::YOUR_LIST_KEY);
}
```

**Key naming convention:** `{concept}_v{version}` — increment the version when the shape changes to avoid deserialising stale data.

---

## 4. Populate from a cron command

Create `src/Command/CacheYourListCommand.php` (see skill **add-console-command**):

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $entities = $this->repository->findLatest(limit: 20);
    $views    = array_map(
        fn($e) => $this->viewFactory->fromYourEntity($e),
        $entities,
    );
    $this->viewStore->saveYourList($views);

    $output->writeln(sprintf('Cached %d items.', count($views)));
    return Command::SUCCESS;
}
```

Register it in `docker/cron/crontab` at an appropriate interval.

---

## 5. Read in a controller

```php
public function index(): Response
{
    $items = $this->viewStore->getYourList();

    // Fallback to DB if cache is empty (stale-while-revalidate)
    if (empty($items)) {
        $entities = $this->repository->findLatest(limit: 20);
        $items    = array_map(fn($e) => $this->viewFactory->fromYourEntity($e), $entities);
    }

    return $this->render('your/index.html.twig', ['items' => $items]);
}
```

---

## 6. Invalidation

Call `$this->viewStore->invalidateYourList()` whenever content changes:
- After publishing a new item.
- Inside the relevant `MessageHandler` after processing.
- In the relevant admin action.

---

## Checklist

- [ ] `YourView` final class in `src/ReadModel/RedisView/` — properties match template expectations
- [ ] Factory method in `RedisViewFactory`
- [ ] `saveYourList` / `getYourList` / `invalidateYourList` in `RedisViewStore`
- [ ] Key includes a version suffix (`_v1`)
- [ ] Cron command writes the cache on schedule
- [ ] Controller reads cache with DB fallback
- [ ] Invalidation wired to write paths
- [ ] `CHANGELOG.md` entry added

