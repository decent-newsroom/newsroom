# Skill: Add a Console Command

Use this skill to add a new `bin/console` command — for cron jobs, admin utilities, data backfills, or long-running worker loops.

---

## Command types

| Type | Base pattern | Examples |
|---|---|---|
| **One-shot batch** | `execute()` returns `SUCCESS`/`FAILURE` | `CacheLatestArticlesCommand`, `QualityCheckArticlesCommand` |
| **Long-running worker** | `execute()` loops, processes messages/events | `RunWorkersCommand`, `SubscribeLocalRelayCommand` |
| **Admin utility** | Short lifecycle, `--dry-run` option | `ElevateUserCommand`, `DeletePubkeyEventsCommand` |
| **Backfill** | Paginated, idempotent | `BackfillArticlesFromLocalRelayCommand` |

---

## 1. Create the command class

File: `src/Command/YourCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:your-command',      // or admin:your-command, bans:reap, etc.
    description: 'One-line description shown in bin/console list',
)]
class YourCommand extends Command
{
    public function __construct(
        private readonly SomeDependency $dependency,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pubkey', InputArgument::REQUIRED, 'Hex pubkey to process')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without making changes')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum records to process', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $pubkey = $input->getArgument('pubkey');
        $dryRun = $input->getOption('dry-run');
        $limit  = (int) $input->getOption('limit');

        $io->title('Your Command');

        if ($dryRun) {
            $io->note('Dry-run mode — no changes will be made.');
        }

        // --- Your logic here ---
        $processed = 0;

        $io->success("Done. Processed: {$processed}");
        return Command::SUCCESS;
    }
}
```

---

## 2. Running the command

All commands must run **inside the Docker container**:

```bash
docker compose exec php bin/console app:your-command <pubkey> --dry-run
```

---

## 3. Long-running worker pattern

For commands that run indefinitely (relay subscriptions, Messenger consumers):

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io = new SymfonyStyle($input, $output);
    $io->title('Starting worker');

    // Signal handling — allows graceful shutdown
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });

    $running = true;
    while ($running) {
        pcntl_signal_dispatch();
        // Process one batch / tick
        $this->dependency->processNext();
        usleep(100_000); // 100ms back-off to avoid busy-loop
    }

    $io->success('Worker stopped cleanly.');
    return Command::SUCCESS;
}
```

---

## 4. Register as a cron job (optional)

File: `docker/cron/crontab`

```cron
*/15 * * * * /app/bin/console app:your-command >> /var/log/cron.log 2>&1
```

Document the schedule in `documentation/Cron/` and list it in the cron table in `AGENTS.md`.

---

## 5. Add to `app:run-workers` (for async workers)

If this is an additional worker process that should always run alongside the application, add it to `RunWorkersCommand` or create a dedicated runner command following the pattern in `RunRelayWorkersCommand`.

---

## Naming conventions

| Prefix | Use for |
|---|---|
| `app:` | General application commands |
| `admin:` | Admin-only utilities (`admin:ban-pubkey`) |
| `user-context:` | User context subscription workers |
| `events:` | Event manipulation (`events:replay-deletions`) |
| `bans:` | Ban management (`bans:reap`) |
| `relay:` | Relay pool / monitoring |

---

## Checklist

- [ ] `#[AsCommand]` attribute with clear `name` and `description`
- [ ] `configure()` declares all arguments and options with help text
- [ ] `--dry-run` option for any destructive command
- [ ] Uses `SymfonyStyle` for output (not `$output->writeln` directly)
- [ ] Runs cleanly inside Docker container
- [ ] Added to cron table if periodic (+ docs updated)
- [ ] `CHANGELOG.md` entry added

