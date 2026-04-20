<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runtime guard that prevents the application from silently running with a
 * misconfigured MESSENGER_TRANSPORT_DSN. Checks once per process boot.
 *
 * Symfony's Redis transport treats the DSN path as the stream name, which
 * overrides the per-transport `stream:` option in messenger.yaml and
 * collapses all queues into one shared Redis stream.
 *
 * The companion compiler pass ({@see \App\DependencyInjection\Compiler\ValidateMessengerDsnPass})
 * warns at build time; this listener enforces the check at runtime where
 * the actual resolved DSN is available.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 2048)]
#[AsEventListener(event: ConsoleEvents::COMMAND, priority: 2048)]
class MessengerDsnHealthListener
{
    private bool $checked = false;

    public function __construct(
        private readonly string $messengerTransportDsn,
        private readonly LoggerInterface $logger,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->validate();
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        // Don't block the diagnostic command itself
        $name = $event->getCommand()?->getName();
        if ($name === 'app:messenger:reset-streams') {
            return;
        }

        // Only check for messenger-related commands to avoid blocking
        // unrelated CLI tools (migrations, cache:clear, etc.)
        if ($name !== null && (
            str_starts_with($name, 'messenger:')
            || str_starts_with($name, 'app:run-workers')
            || str_starts_with($name, 'app:run-profile-workers')
            || str_starts_with($name, 'app:run-relay-workers')
        )) {
            $this->validate();
        }
    }

    private function validate(): void
    {
        if ($this->checked) {
            return;
        }
        $this->checked = true;

        $dsn = $this->messengerTransportDsn;
        $parsed = parse_url($dsn);
        if ($parsed === false) {
            return;
        }

        $path = trim($parsed['path'] ?? '', '/');
        if ($path === '') {
            return;
        }

        $message = sprintf(
            'MESSENGER_TRANSPORT_DSN has a non-empty path "/%s". ' .
            'Symfony\'s Redis transport uses the DSN path as the stream name, ' .
            'which silently overrides per-transport "stream:" options in messenger.yaml ' .
            'and collapses all queues into one shared Redis stream. ' .
            'Remove the path from the DSN. For a specific DB index, use ?dbindex=%s instead.',
            $path,
            ctype_digit($path) ? $path : '0',
        );

        $this->logger->critical($message);

        throw new \RuntimeException($message);
    }
}

